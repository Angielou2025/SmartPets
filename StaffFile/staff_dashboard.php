<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/match_scoring.php';

// Check if staff user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user profile info
$user_stmt = $pdo->prepare("SELECT username, profile_image, role FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$username = $current_user['username'] ?? 'Staff Member';
$profile_image = $current_user['profile_image'] ?? '';
$user_initials = strtoupper(substr($username, 0, 2));

// Helper to safely count rows from an optional table
function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// Gather counts (use safe queries; if table doesn't exist, defaults to 0)
$pending_applications = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status IN ('Pending', 'Under Review')");
// Bukas pa lang na application ang may interview - hindi kasama ang na-Approve/Reject na
$interviews_today = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE DATE(preferred_interview_date) = CURDATE() AND application_status IN ('Pending', 'Under Review')");
$available_pets = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_inventory WHERE status = 'Available'");
// "Pending" na bayad dito ay isang Approved application na wala pang Paid na bayad.
// Kasama ang may GCash link na hindi pa nababayaran (status Pending/Failed) -
// pareho sa "Awaiting Payment" ng Payments page.
$pending_payments = safe_count($pdo, "
    SELECT COUNT(*) AS c FROM adoption_applications aa
    WHERE (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
      AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.application_id = aa.application_id AND pay.status = 'Paid')
");

// Total payments collected today
function safe_sum($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (float)($row['s'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}
// Aktwal na nabayaran lang ang kita - hindi kasama ang GCash link na hindi pa bayad
$revenue_today = safe_sum($pdo, "SELECT SUM(amount) AS s FROM payments WHERE status = 'Paid' AND DATE(payment_date) = CURDATE()");
$revenue_total = safe_sum($pdo, "SELECT SUM(amount) AS s FROM payments WHERE status = 'Paid'");
// Bilang ng nagbayad ngayong araw - ito ang nasa tab ng Payment Collection Today
$paid_today = safe_count($pdo, "SELECT COUNT(*) AS c FROM payments WHERE status = 'Paid' AND DATE(payment_date) = CURDATE()");

// Payment Collection Today: ang mga nagbayad ngayong araw lang
$payments_today = [];
try {
    $sql = "SELECT pay.payment_id, pay.amount, pay.reference_number, pay.payment_date,
                   u.username AS adopter_name, pt.name AS pet_name
            FROM payments pay
            JOIN users u ON pay.adopter_id = u.user_id
            JOIN pet_inventory pt ON pay.pet_id = pt.pet_id
            WHERE pay.status = 'Paid' AND DATE(pay.payment_date) = CURDATE()
            ORDER BY pay.payment_date DESC";
    $stmt = $pdo->query($sql);
    if ($stmt) $payments_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $payments_today = [];
}

// Recommendation engine: pending/under-review applications scored with the same
// computeMatchScore() engine used sa applications.php / smart_matches.php.
$recommendations = [];
$high_matches = 0;
try {
    $sql = "SELECT aa.application_id, aa.full_name AS adopter_name, u.profile_image AS adopter_photo,
                   aa.match_score AS saved_match_score, aa.match_breakdown AS saved_match_breakdown,
                   p.name AS pet_name, p.kind AS pet_type,
                   ap.pref_kind, ap.pref_breed, ap.pref_temperament, ap.pref_energy_level, ap.pref_size, ap.pref_age_group, ap.pref_gender, ap.pref_health_condition,
                   ap.living_space, ap.activity_level, ap.maintenance_level, ap.allergies,
                   p.kind, p.breed, p.energy_level, p.size, p.age_group, p.gender, p.health_condition, p.temperament, p.shedding_level, p.description
            FROM adoption_applications aa
            JOIN pet_inventory p ON aa.pet_id = p.pet_id
            LEFT JOIN users u ON u.user_id = aa.adopter_id
            LEFT JOIN adopter_preferences ap ON ap.user_id = aa.adopter_id
            WHERE aa.application_status IN ('Pending', 'Under Review')
            ORDER BY aa.created_at DESC";
    $stmt = $pdo->query($sql);
    if ($stmt) $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recommendations = [];
}

foreach ($recommendations as &$rec) {
    $rec['score'] = sp_application_match($pdo, $rec)['score']; // naka-freeze noong nag-submit
}
unset($rec);

usort($recommendations, function ($a, $b) {
    return ($b['score'] ?? -1) <=> ($a['score'] ?? -1);
});

$high_matches = count(array_filter(array_column($recommendations, 'score'), fn($s) => $s !== null && $s >= 80));
$recommendations = array_slice($recommendations, 0, 3);

// Today's Schedule: applications na may interview na naka-set ngayong araw
$schedule = [];
try {
    $sql = "SELECT aa.full_name AS adopter_name, aa.application_status, aa.preferred_interview_time,
                   p.name AS pet_name
            FROM adoption_applications aa
            JOIN pet_inventory p ON aa.pet_id = p.pet_id
            WHERE DATE(aa.preferred_interview_date) = CURDATE()
            ORDER BY aa.preferred_interview_time ASC
            LIMIT 5";
    $stmt = $pdo->query($sql);
    if ($stmt) $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $schedule = [];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Dashboard - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <!-- styles moved to StaffFile/staff_dashboard.css -->
    <style>
        /* ===== Kulay ng mga card sa Staff Dashboard ===== */
        .staff-dashboard-root .dash-cards .stat-card {
            color: #fff;
            border: none;
            box-shadow: 0 8px 18px rgba(16, 24, 40, 0.14);
        }
        .staff-dashboard-root .dash-cards .stat-card::after { display: none; } /* walang guhit sa gilid */
        .staff-dashboard-root .dash-cards .stat-card h1,
        .staff-dashboard-root .dash-cards .stat-card p { color: #fff; }
        /* Mula madilim (kaliwa) hanggang maliwanag (kanan), iisang kulay ng staff (asul).
           May bahagyang gradient ang bawat card para may lalim. */
        .staff-dashboard-root .dash-cards .dash-c1 { background: linear-gradient(135deg, #0f1b3d 0%, #172554 100%); box-shadow: 0 8px 18px rgba(15, 27, 61, 0.30); } /* High Matches */
        .staff-dashboard-root .dash-cards .dash-c2 { background: linear-gradient(135deg, #172f6e 0%, #1e3a8a 100%); box-shadow: 0 8px 18px rgba(30, 58, 138, 0.28); } /* Pending Applications */
        .staff-dashboard-root .dash-cards .dash-c3 { background: linear-gradient(135deg, #1e40af 0%, #2450c4 100%); box-shadow: 0 8px 18px rgba(36, 80, 196, 0.28); } /* Interviews Today */
        .staff-dashboard-root .dash-cards .dash-c4 { background: linear-gradient(135deg, #2457d6 0%, #2f6ae6 100%); box-shadow: 0 8px 18px rgba(47, 106, 230, 0.28); } /* Available Pets */
        .staff-dashboard-root .dash-cards .dash-c5 { background: linear-gradient(135deg, #3170e8 0%, #4a86f0 100%); box-shadow: 0 8px 18px rgba(74, 134, 240, 0.30); } /* Pending Payments */
        /* Limang card sa iisang hanay, pare-pareho ang lapad at taas */
        .staff-dashboard-root .cards-grid.dash-cards {
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            gap: 16px !important;
            align-items: stretch;
        }
        .staff-dashboard-root .dash-cards .stat-card { gap: 12px; padding: 18px 18px 18px 20px; min-height: 120px; }
        .staff-dashboard-root .dash-cards .stat-info { min-width: 0; }
        .staff-dashboard-root .dash-cards .stat-info p { line-height: 1.3; }
        @media (max-width: 1280px) {
            .staff-dashboard-root .cards-grid.dash-cards { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
        }
        @media (max-width: 760px) {
            .staff-dashboard-root .cards-grid.dash-cards { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 12px !important; }
        }
        /* Telepono: dalawa pa rin bawat hanay; ang pang-limang card ay buong lapad */
        @media (max-width: 760px) {
            .staff-dashboard-root .cards-grid.dash-cards > :last-child:nth-child(odd) { grid-column: 1 / -1; }
        }
        @media (max-width: 340px) {
            .staff-dashboard-root .cards-grid.dash-cards { grid-template-columns: 1fr !important; }
        }

        /* Napipindot ang bawat card: papunta sa kaugnay na page */
        .staff-dashboard-root .dash-cards .dash-card-link {
            position: relative;
            text-decoration: none;
            color: #fff;
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
        }
        .staff-dashboard-root .dash-cards .dash-card-link:hover {
            transform: translateY(-4px);
            filter: brightness(1.08);
            box-shadow: 0 16px 28px rgba(15, 27, 61, 0.28);
        }
        .staff-dashboard-root .dash-cards .dash-card-link:active { transform: translateY(-1px); }
        .staff-dashboard-root .dash-cards .dash-card-link:focus-visible {
            outline: 3px solid rgba(96, 165, 250, 0.7);
            outline-offset: 3px;
        }
        @media (prefers-reduced-motion: reduce) {
            .staff-dashboard-root .dash-cards .dash-card-link { transition: none; }
        }

        /* Mga icon: puting line icon sa loob ng malabong "salamin" na tile.
           (Pinapalitan ang puting bilog mula sa AdminFile/style.css) */
        .staff-dashboard-root .dash-cards .stat-icon {
            width: 54px !important;
            height: 54px !important;
            flex-shrink: 0;
            display: flex !important;
            align-items: center;
            justify-content: center;
            border-radius: 16px !important;
            background: rgba(255, 255, 255, 0.14) !important;
            border: 1px solid rgba(255, 255, 255, 0.22);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18), 0 6px 14px rgba(0, 0, 0, 0.12);
            color: #fff !important;
            transition: transform .25s ease, background-color .25s ease;
        }
        .staff-dashboard-root .dash-cards .stat-card:hover .stat-icon {
            transform: translateY(-2px) rotate(-4deg);
            background: rgba(255, 255, 255, 0.2) !important;
        }
        .staff-dashboard-root .dash-cards .stat-icon svg {
            width: 28px;
            height: 28px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            overflow: visible;
        }
        /* Bahagyang punong bahagi para may lalim ang icon */
        .staff-dashboard-root .dash-cards .stat-icon .ic-fill { fill: rgba(255, 255, 255, 0.22); }
        .staff-dashboard-root .dash-cards .stat-icon .ic-solid { fill: currentColor; stroke: none; }
        .staff-dashboard-root .dash-cards .stat-icon .ic-dots { stroke-width: 2.4; }
        .staff-dashboard-root .dash-cards .stat-icon .ic-spark { stroke: #fde68a; stroke-width: 2; }
        @media (max-width: 600px) {
            .staff-dashboard-root .dash-cards .stat-icon { width: 44px !important; height: 44px !important; border-radius: 13px !important; }
            .staff-dashboard-root .dash-cards .stat-icon svg { width: 23px; height: 23px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .staff-dashboard-root .dash-cards .stat-icon { transition: none; }
        }

        /* ===== Telepono: mas maliit na mga card, pareho pa rin ang puwesto =====
           (2 bawat hanay; buong lapad ang huli kapag odd ang bilang) */
        @media (max-width: 600px) {
            .staff-dashboard-root .cards-grid.dash-cards { gap: 8px !important; }
            .staff-dashboard-root .dash-cards .stat-card { padding: 12px 12px 12px 14px; min-height: 0; gap: 8px; border-radius: 14px; }
            .staff-dashboard-root .dash-cards .stat-info h1 { font-size: 21px; line-height: 1.15; }
            .staff-dashboard-root .dash-cards .stat-info p { font-size: 11.5px; line-height: 1.3; }
            .staff-dashboard-root .dash-cards .stat-icon { width: 34px !important; height: 34px !important; border-radius: 10px !important; }
            .staff-dashboard-root .dash-cards .stat-icon svg { width: 18px; height: 18px; }

            /* Ang mga card lang ang laman ng .dashboard-content dito, kaya hindi sila makakadikit
               nang lampas doon (hanggang sa sariling lalagyan lang ang sticky). display: contents =
               walang sariling kahon, kaya ang .main-wrapper na ang lalagyan ng sticky na cards. */
            .staff-dashboard-root .dashboard-content:has(> .dash-cards) { display: contents; }

            /* Sticky (connection/responsive.php): kapag nakadikit, HINDI nagiging iisang hanay -
               nananatili ang 2 bawat hanay, mas siksik lang (walang icon) */
            body.staff-dashboard-root .dashboard-content .sp-sticky-cards.dash-cards.is-stuck {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 6px !important;
            }
            body.staff-dashboard-root .dashboard-content .sp-sticky-cards.dash-cards.is-stuck > :last-child:nth-child(odd) {
                grid-column: 1 / -1 !important;
            }
            body.staff-dashboard-root .dashboard-content .sp-sticky-cards.dash-cards.is-stuck > * { padding: 7px 10px !important; }
            body.staff-dashboard-root .dashboard-content .sp-sticky-cards.dash-cards.is-stuck .stat-info { display: flex; align-items: baseline; gap: 8px; }
            body.staff-dashboard-root .dashboard-content .sp-sticky-cards.dash-cards.is-stuck .stat-info p { margin: 0 !important; font-size: 10.5px !important; }
        }

        /* ===== Panel na may tatlong tab ===== */
        .staff-dashboard-root .dash-tabs-panel {
            /* Kapantay ng mga card sa itaas (parehong puwang sa kaliwa at kanan) */
            /* Mga 20px lang ang puwang mula sa mga card (binabawi ang padding ng lalagyan nila) */
            margin: -18px 30px 24px;
            padding: 20px;
            border: none !important;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 12px 30px rgba(10, 10, 10, 0.04);
        }
        .staff-dashboard-root .dash-tabs-panel .dash-tabs {
            position: relative;
            display: inline-flex;
            width: max-content !important;
            max-width: 100%;
            flex-wrap: wrap;
            height: 40px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }
        /* Telepono: buong lapad ang panel; ang mga tab ay iisang hanay na
           puwedeng i-swipe (hindi nagpapatong-patong) */
        @media (max-width: 640px) {
            .staff-dashboard-root .dash-tabs-panel { margin: 4px 0 20px; padding: 14px; }
            .staff-dashboard-root .dash-tabs-panel .dash-tabs {
                display: flex;
                width: 100% !important;
                flex-wrap: nowrap;
                overflow-x: auto;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }
            .staff-dashboard-root .dash-tabs-panel .dash-tabs::-webkit-scrollbar { display: none; }
            .staff-dashboard-root .dash-tabs-panel .dash-tab { flex-shrink: 0; white-space: nowrap; height: 40px; padding: 0 14px; }
        }
        .dash-tab {
            position: relative;
            display: inline-flex;
            align-items: center;
            padding: 0 16px;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
        }
        .dash-tab::before { content: ""; position: absolute; inset: 0; z-index: 0; }
        .dash-tab.tab-matches::before { background: #5bc85b; }
        .dash-tab.tab-schedule::before { background: #6d8cf0; }
        .dash-tab.tab-payments::before { background: #ffad5c; }
        .dash-tab .tab-inner { position: relative; z-index: 2; display: inline-flex; align-items: center; gap: 8px; }
        .dash-tab .tab-count {
            min-width: 20px;
            padding: 1px 7px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
            font-size: 11.5px;
            text-align: center;
        }
        .dash-tab.active .tab-count { animation: dashCountPop .4s cubic-bezier(.34, 1.56, .64, 1); }
        .dash-tab:focus-visible { outline: 3px solid #102a2a; outline-offset: -3px; }
        /* Pinapatay ang hover ng AdminFile/style.css (.dash-tab:hover translateY(-2px)):
           umaangat ang tab palayo sa mouse, nawawala ang hover, bumabalik, at paulit-ulit
           (pagkislap). Dito, hindi na gumagalaw ang tab - lumiliwanag lang nang bahagya. */
        .staff-dashboard-root .dash-tabs-panel .dash-tab {
            transform: none !important;
            box-shadow: none !important;
            transition: color .2s ease;
        }
        .dash-tab::after {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 1;
            background: #fff;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease;
        }
        .dash-tab:not(.active):hover::after { opacity: .14; }
        .dash-tab-indicator {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 0;
            z-index: 1;
            background: #1f7a2e;
            box-shadow: inset 0 -3px 0 rgba(0, 0, 0, 0.18);
            transition: left .4s cubic-bezier(.65, 0, .35, 1), width .4s cubic-bezier(.65, 0, .35, 1), background-color .4s ease;
        }
        .dash-tab-indicator.no-anim { transition: none; }
        @keyframes dashCountPop { from { transform: scale(.6); } to { transform: scale(1); } }

        /* Mga tab + paliwanag: karaniwang nasa ilalim ng mga tab ang paliwanag.
           Kapag mahaba ang listahan (.info-inline), lumilipat ito sa tabi ng mga tab
           para may mas maraming espasyo ang listahan. */
        /* Ayos ng hanay:
             karaniwan:    [3 tab] ............ [button]
                           [paliwanag]
             info-inline:  [3 tab] [paliwanag]
                           ..................... [button]                       */
        .dash-top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            column-gap: 14px;
        }
        .dash-top .dash-tabs { flex-shrink: 0; order: 1; }
        .dash-action-wrap { order: 2; margin-left: auto; display: flex; justify-content: flex-end; }
        .dash-action-wrap[hidden] { display: none; }
        /* Paliwanag sa sariling linya; ang pill sa loob ay sakto sa text ang lapad */
        .dash-info-row { order: 3; flex-basis: 100%; margin: 14px 0 16px; }
        .dash-info-row .dash-info { margin: 0; }

        .dash-top.info-inline { row-gap: 12px; margin-bottom: 16px; }
        .dash-top.info-inline .dash-info-row { order: 2; flex-basis: auto; margin: 0; }
        .dash-top.info-inline .dash-action-wrap { order: 3; flex-basis: 100%; margin-left: 0; }
        .dash-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 40px;
            padding: 0 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
            transition: background-color .2s ease, box-shadow .2s ease, transform .15s ease;
            animation: dashInfoSlide .35s cubic-bezier(.22, 1, .36, 1);
        }
        .dash-action[hidden] { display: none; }
        .dash-action svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .dash-action .dash-action-arrow { width: 16px; height: 16px; transition: transform .2s ease; }
        .dash-action:hover .dash-action-arrow { transform: translateX(3px); }
        /* Manage Payments: emerald (kulay ng pera) na kumikinang para mapansin ng staff,
           at iba sa orange ng tab para hindi magsama ang dalawa */
        .dash-action-payments {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #10b981 0%, #059669 55%, #047857 100%);
            color: #fff !important;
            box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35), inset 0 -3px 0 rgba(0, 0, 0, 0.14);
            animation: dashInfoSlide .35s cubic-bezier(.22, 1, .36, 1), dashPayGlow 2.4s ease-in-out .4s infinite;
        }
        /* Kislap na dumadaan paminsan-minsan */
        .dash-action-payments::after {
            content: '';
            position: absolute;
            top: 0;
            left: -60%;
            width: 40%;
            height: 100%;
            background: linear-gradient(100deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.45) 50%, rgba(255,255,255,0) 100%);
            transform: skewX(-20deg);
            animation: dashPayShine 3.2s ease-in-out 1s infinite;
            pointer-events: none;
        }
        .dash-action-payments:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(5, 150, 105, 0.42), inset 0 -3px 0 rgba(0, 0, 0, 0.14);
        }
        @keyframes dashPayGlow {
            0%, 100% { box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35), 0 0 0 0 rgba(16, 185, 129, 0.45), inset 0 -3px 0 rgba(0, 0, 0, 0.14); }
            50% { box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35), 0 0 0 7px rgba(16, 185, 129, 0), inset 0 -3px 0 rgba(0, 0, 0, 0.14); }
        }
        @keyframes dashPayShine {
            0% { left: -60%; }
            40%, 100% { left: 130%; }
        }
        .dash-action:focus-visible { outline: 3px solid rgba(16, 185, 129, 0.4); outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) {
            .dash-action, .dash-action-payments { animation: none; }
            .dash-action-payments::after { display: none; }
        }
        /* Parehong lapad gaya ng nasa ilalim (sakto sa text, hindi pinipiga).
           Kapag walang sapat na espasyo sa tabi, kusang bababa sa ilalim. */
        .dash-top.info-inline .dash-info {
            flex: 0 0 auto;
            width: fit-content;
            margin: 0;
            animation: dashInfoSlide .35s cubic-bezier(.22, 1, .36, 1);
        }
        @keyframes dashInfoSlide { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: none; } }
        /* Makitid na screen: walang espasyo sa tabi, kaya sa ilalim pa rin */
        @media (max-width: 900px) {
            .dash-top.info-inline { margin-bottom: 0; row-gap: 0; }
            .dash-top.info-inline .dash-info-row { order: 3; flex-basis: 100%; margin: 14px 0 16px; }
            .dash-top.info-inline .dash-action-wrap { order: 2; flex-basis: auto; margin-left: auto; }
            .dash-top.info-inline .dash-info { animation: none; }
        }

        /* Info pill (paliwanag ng bukas na tab) */
        .dash-info {
            display: flex;
            align-items: center;
            gap: 10px;
            width: fit-content;
            max-width: 100%;
            margin: 14px 0 16px;
            padding: 8px 16px 8px 10px;
            border-radius: 999px;
            background: #e6f3f5;
            border: 1px solid #c9e3e8;
            font-size: 13px;
            font-weight: 600;
            color: #1f3d44;
        }
        .dash-info-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            border-radius: 50%;
            border: 1.5px solid #1f3d44;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: Georgia, serif;
            font-size: 12px;
            font-weight: 700;
        }
        #dashInfoText { transition: opacity .2s ease; }
        #dashInfoText.swap { opacity: 0; }

        .dash-pane[hidden] { display: none; }

        @media (prefers-reduced-motion: reduce) {
            .dash-tab-indicator { transition: none; }
            .dash-tab.active .tab-count { animation: none; }
        }

        /* ===== Telepono: mas maliit at pantay-pantay ===== */
        @media (max-width: 640px) {
            /* Tatlong tab na magkakasinlapad, sakop ang buong lapad (wala nang naputol).
               Ang bilang ay nasa ilalim ng pangalan ng tab. */
            .staff-dashboard-root .dash-tabs-panel .dash-tabs {
                display: flex; flex-wrap: nowrap; overflow: hidden; height: auto; width: 100% !important;
            }
            .staff-dashboard-root .dash-tabs-panel .dash-tab {
                flex: 1 1 0; min-width: 0; height: auto; min-height: 50px; padding: 6px 4px;
                justify-content: center; text-align: center; white-space: normal;
                font-size: 11px; line-height: 1.2;
            }
            .dash-tab .tab-inner { flex-direction: column; gap: 3px; }
            .dash-tab .tab-count { font-size: 10.5px; padding: 0 6px; min-width: 18px; line-height: 16px; }

            /* Manage Payments: sariling hanay sa ilalim ng mga tab, hindi nakapatong */
            .dash-action-wrap, .dash-top.info-inline .dash-action-wrap {
                order: 2; flex-basis: 100%; margin: 10px 0 0; justify-content: stretch;
            }
            .dash-action { width: 100%; justify-content: center; height: 38px; padding: 0 12px; font-size: 13px; gap: 6px; border-radius: 10px; }
            .dash-action svg { width: 16px; height: 16px; }

            /* Paliwanag (i) */
            .dash-info-row, .dash-top.info-inline .dash-info-row { margin: 12px 0 12px; }
            .dash-info { font-size: 12px; padding: 7px 12px 7px 8px; gap: 8px; border-radius: 12px; }
            .dash-info-icon { width: 18px; height: 18px; font-size: 11px; }

            /* Listahan ng mga match */
            .staff-dashboard-root .rec-header .small { font-size: 12px; }
            /* Iisang hanay: larawan | pangalan at pet | % at Review */
            .staff-dashboard-root .rec-row { padding: 10px; gap: 10px; flex-direction: row; align-items: center; }
            .staff-dashboard-root .rec-row:hover { transform: none; }
            .staff-dashboard-root .rec-left { width: auto; flex: 1; min-width: 0; gap: 10px; }
            .staff-dashboard-root .rec-row .avatar-pill { width: 44px; height: 44px; border-radius: 10px; font-size: 16px; flex-shrink: 0; }
            .staff-dashboard-root .rec-row > div:last-child { gap: 8px !important; flex-shrink: 0; }
            .staff-dashboard-root .rec-row .match-percent { padding: 0; }
            .staff-dashboard-root .rec-row .rec-info .name { font-size: 13.5px; }
            .staff-dashboard-root .rec-row .rec-info .pet { font-size: 11.5px; }
            .staff-dashboard-root .rec-row .match-percent { font-size: 14px; }
            .staff-dashboard-root .rec-row .btn-review { padding: 6px 12px !important; font-size: 12.5px !important; border-radius: 8px !important; }
            .staff-dashboard-root .view-all { font-size: 13px; }
        }
    </style>
</head>
<body class="staff-dashboard-root">
<div class="app-container">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <div class="top-header">
            <div class="search-top"></div>
            <a href="/Caps2/StaffFile/edit_profile.php" class="profile-section" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>"
                         alt="Profile"
                         style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e6e1d8;">
                <?php else: ?>
                    <div class="avatar"><?php echo $user_initials; ?></div>
                <?php endif; ?>
                <div class="profile-name" style="font-weight: 700; color: #102a2a;">
                    <?php echo htmlspecialchars($username); ?>
                </div>
            </a>
        </div>

        <div class="dashboard-content page-content">

            <div class="cards-grid dash-cards">
                <a class="stat-card white-card dash-c1 dash-card-link" href="/Caps2/StaffFile/smart_matches.php" title="Open Smart Matches">
                    <div class="stat-info">
                        <h1><?php echo htmlentities($high_matches); ?></h1>
                        <p>High Matches to Review</p>
                    </div>
                    <div class="stat-icon" aria-hidden="true">
                        <!-- High Matches: puso na may kislap (malakas na match) -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>
                            <path d="M9.2 11.2l1.9 1.9 3.7-3.8"/>
                            <path class="ic-spark" d="M19.5 2.5v3M18 4h3"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card black-card dash-c2 dash-card-link" href="/Caps2/StaffFile/applications.php?status=pending" title="Open pending applications">
                    <div class="stat-info">
                        <h1><?php echo htmlentities($pending_applications); ?></h1>
                        <p>Pending Applications</p>
                    </div>
                    <div class="stat-icon" aria-hidden="true">
                        <!-- Pending Applications: clipboard na may orasan (naghihintay) -->
                        <svg viewBox="0 0 24 24">
                            <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h4.5"/>
                            <path d="M15 4h2a2 2 0 0 1 2 2v4"/>
                            <rect class="ic-fill" x="9" y="2.5" width="6" height="3.5" rx="1"/>
                            <path d="M8.5 10h6M8.5 13.5h3.5"/>
                            <circle cx="17" cy="17" r="4"/>
                            <path d="M17 15.2V17l1.2 1"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card green-orange-card dash-c3 dash-card-link" href="/Caps2/StaffFile/staff_availability.php?date=<?php echo date('Y-m-d'); ?>#requests" title="Open today's interviews">
                    <div class="stat-info">
                        <h1><?php echo htmlentities($interviews_today); ?></h1>
                        <p>Interviews Today</p>
                    </div>
                    <div class="stat-icon" aria-hidden="true">
                        <!-- Interviews Today: calendar na may chat bubble (usapan ngayong araw) -->
                        <svg viewBox="0 0 24 24">
                            <path d="M11 20H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3"/>
                            <path d="M4 9.5h16M8.5 3v4M15.5 3v4"/>
                            <path class="ic-fill" d="M15.5 13h4a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-2l-2.5 2v-2a1.5 1.5 0 0 1-1-1.4v-3.1a1.5 1.5 0 0 1 1.5-1.5z"/>
                            <path class="ic-dots" d="M16 16h.01M17.5 16h.01M19 16h.01"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card solid-green-card dash-c4 dash-card-link" href="/Caps2/StaffFile/pet_inventory.php" title="Open Pet Inventory">
                    <div class="stat-info">
                        <h1><?php echo htmlentities($available_pets); ?></h1>
                        <p>Available Pets in Shelter</p>
                    </div>
                    <div class="stat-icon" aria-hidden="true">
                        <!-- Available Pets in Shelter: bahay na may paw print -->
                        <svg viewBox="0 0 24 24">
                            <path d="M3 11l9-7.5L21 11"/>
                            <path d="M5.5 9.2V20h13V9.2"/>
                            <g class="ic-solid">
                                <ellipse cx="9.6" cy="12.2" rx="1" ry="1.3"/>
                                <ellipse cx="12" cy="11.2" rx="1" ry="1.3"/>
                                <ellipse cx="14.4" cy="12.2" rx="1" ry="1.3"/>
                                <path d="M12 13.6c1.6 0 2.8 1.3 2.8 2.6 0 1-.9 1.4-2.8 1.4s-2.8-.4-2.8-1.4c0-1.3 1.2-2.6 2.8-2.6z"/>
                            </g>
                        </svg>
                    </div>
                </a>

                <a class="stat-card payment-card dash-c5 dash-card-link" href="/Caps2/StaffFile/payments.php" title="Open Payments">
                    <div class="stat-info">
                        <h1><?php echo htmlentities($pending_payments); ?></h1>
                        <p>Pending Payments</p>
                    </div>
                    <div class="stat-icon" aria-hidden="true">
                        <!-- Pending Payments: wallet na may ₱ at orasan (hinihintay ang bayad) -->
                        <svg viewBox="0 0 24 24">
                            <path d="M19 9V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h7"/>
                            <path d="M3 9h16"/>
                            <path d="M7 12.5h1.6a1.3 1.3 0 0 1 0 2.6H7v-2.6zm0 0V17M6.2 13.3h3.6M6.2 14.4h3.6"/>
                            <circle cx="17.5" cy="16.5" r="4"/>
                            <path d="M17.5 14.7v1.8l1.2 1"/>
                        </svg>
                    </div>
                </a>
                </div>
            </div>

        <!-- Iisang panel na may tatlong tab (kapareho ng istilo sa Certificates):
             Engine Recommendation, Today Schedule, Payment Collection Today. Walang search bar. -->
        <section class="panel dash-tabs-panel">
            <!-- Mga tab + paliwanag. Kapag mahaba ang listahan, lumilipat ang
                 paliwanag sa tabi ng mga tab (class na .info-inline) -->
            <div class="dash-top" id="dashTop">
            <div class="dash-tabs" role="tablist" aria-label="Dashboard sections">
                <span class="dash-tab-indicator" aria-hidden="true"></span>
                <button type="button" class="dash-tab tab-matches" role="tab" data-tab="matches" data-info="Adopters with a strong pet match to review.">
                    <span class="tab-inner">Engine Recommendation <span class="tab-count"><?php echo count($recommendations); ?></span></span>
                </button>
                <button type="button" class="dash-tab tab-schedule" role="tab" data-tab="schedule" data-info="Interviews scheduled for today, <?php echo date('M j, Y'); ?>.">
                    <span class="tab-inner">Today Schedule <span class="tab-count"><?php echo count($schedule); ?></span></span>
                </button>
                <button type="button" class="dash-tab tab-payments" role="tab" data-tab="payments" data-info="Payments collected today, <?php echo date('M j, Y'); ?>.">
                    <span class="tab-inner">Payment Collection Today <span class="tab-count" title="Paid today"><?php echo (int) $paid_today; ?></span></span>
                </button>
            </div>

            <!-- Maikling paliwanag ng bukas na tab -->
            <!-- Button sa kanan (Manage Payments: sa tab ng bayad lang). Karaniwan ay
                 kapantay ng tatlong tab; kapag umakyat ang paliwanag sa tabi ng mga tab,
                 bumababa ito sa susunod na linya (sa kanan pa rin). -->
            <div class="dash-action-wrap" data-for="payments" hidden>
                <a class="dash-action dash-action-payments" href="/Caps2/StaffFile/payments.php">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="5.5" width="19" height="13" rx="2.5"/><path d="M2.5 10h19"/><path d="M6.5 15h4"/></svg>
                    Manage Payments
                    <svg class="dash-action-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>

            <div class="dash-info-row">
            <div class="dash-info" role="note">
                <span class="dash-info-icon" aria-hidden="true">i</span>
                <span id="dashInfoText">Adopters with a strong pet match to review.</span>
            </div>
            </div>
            </div>

            <div class="dash-pane" data-pane="matches">
                <div class="rec-header">
                    <span></span>
                    <div class="small">Filter: <strong>All Pets</strong></div>
                </div>

                <div class="rec-inner">
                    <?php if (empty($recommendations)): ?>
                        <div class="small" style="text-align:center;padding:20px 0;color:#9aa6a3">No pending matches right now.</div>
                    <?php endif; ?>
                    <?php foreach($recommendations as $r): ?>
                        <?php $adopter = htmlentities($r['adopter_name'] ?? 'N/A'); $pet = htmlentities($r['pet_name'] ?? 'N/A'); $score = $r['score']; ?>
                        <div class="rec-row">
                            <div class="rec-left">
                                <?php if (!empty($r['adopter_photo'])): ?>
                                    <img class="avatar-pill" src="/Caps2/uploads/<?php echo htmlspecialchars($r['adopter_photo']); ?>" alt="<?php echo $adopter; ?>" style="object-fit:cover;">
                                <?php else: ?>
                                    <div class="avatar-pill"><?php echo strtoupper(substr($adopter,0,1)); ?></div>
                                <?php endif; ?>
                                <div class="rec-info">
                                    <div class="name"><?php echo $adopter; ?></div>
                                    <div class="pet"><?php echo $pet; ?></div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px">
                                <div class="match-percent"><?php echo $score === null ? 'N/A' : $score . '%'; ?></div>
                                <a class="btn-review" href="review_application.php?application_id=<?php echo (int) $r['application_id']; ?>">Review</a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div style="text-align:center;margin-top:6px">
                        <a class="view-all" href="smart_matches.php">View All Pending Matches</a>
                    </div>
                </div>
            </div>

            <div class="dash-pane" data-pane="schedule" hidden>
                <?php if (empty($schedule)): ?>
                    <div class="small" style="text-align:center;padding:20px 0;color:#9aa6a3">No interviews scheduled today.</div>
                <?php endif; ?>
                <?php foreach($schedule as $s): ?>
                    <div class="schedule-item">
                        <div style="flex:1">
                            <div style="font-weight:700">Interview: <?php echo htmlentities($s['adopter_name']); ?> &amp; <?php echo htmlentities($s['pet_name']); ?></div>
                            <div class="small"><?php echo htmlentities(date('g:i A', strtotime($s['preferred_interview_time']))); ?> • <?php echo htmlentities($s['application_status']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Payment Collection Today: ang mga nagbayad ngayong araw lang -->
            <div class="dash-pane" data-pane="payments" hidden>
            <div class="pay-period-summary">
                <span class="pay-period-total">&#8369;<?php echo number_format($revenue_today, 2); ?></span>
                <span class="pay-period-count">collected today from <?php echo count($payments_today); ?> payment<?php echo count($payments_today) === 1 ? '' : 's'; ?></span>
            </div>

            <div class="payment-table-wrap">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Adopter</th>
                            <th>Pet</th>
                            <th>Amount</th>
                            <th>GCash Reference No.</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments_today)): ?>
                            <tr><td colspan="6"><div class="pay-empty">
                                <span class="pay-empty-icon">&#129534;</span>
                                No payments collected today yet.
                            </div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($payments_today as $p): $payName = $p['adopter_name'] ?: 'N/A'; ?>
                            <tr class="pay-row">
                                <td><div class="pay-adopter"><span class="pay-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($payName, 0, 1))); ?></span><span><?php echo htmlspecialchars($payName); ?></span></div></td>
                                <td><?php echo htmlspecialchars($p['pet_name'] ?: 'N/A'); ?></td>
                                <td class="pay-amount">&#8369;<?php echo number_format((float) $p['amount'], 2); ?></td>
                                <td><?php if (!empty($p['reference_number'])): ?><span class="pay-ref" onclick="copyReference(this)" title="Click to copy"><?php echo htmlspecialchars($p['reference_number']); ?></span><?php else: ?>&mdash;<?php endif; ?></td>
                                <td><span class="payment-status status-paid">Paid</span></td>
                                <td><div class="pay-time"><?php echo htmlspecialchars(date('g:i A', strtotime($p['payment_date']))); ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </section>
        </div>
    </main>
</div>

<script>
    // Madalas ikinukumpara ng staff ang reference number sa GCash app, kaya click-to-copy
    function copyReference(el) {
        const ref = el.textContent.trim();
        const done = function () {
            const original = el.textContent;
            el.classList.add('copied');
            el.textContent = 'Copied!';
            setTimeout(function () {
                el.textContent = original;
                el.classList.remove('copied');
            }, 1200);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(ref).then(done).catch(function () {});
        }
    }

    // ===== Tatlong tab: Engine Recommendation, Today Schedule, Payment Collection Today =====
    (function () {
        const tabs = Array.from(document.querySelectorAll('.dash-tab'));
        const panes = Array.from(document.querySelectorAll('.dash-pane'));
        const indicator = document.querySelector('.dash-tab-indicator');
        const info = document.getElementById('dashInfoText');
        const top = document.getElementById('dashTop');
        const INLINE_AT = 3; // ilang item bago lumipat ang paliwanag sa tabi ng mga tab
        if (!tabs.length || !indicator) return;

        const COLOR = { matches: '#1f7a2e', schedule: '#1e2f8f', payments: '#f07c12' };
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let active = 'matches';
        try { active = sessionStorage.getItem('dashTab') || 'matches'; } catch (e) {}
        if (!tabs.some(function (t) { return t.dataset.tab === active; })) active = 'matches';

        function moveIndicator(animate) {
            const btn = tabs.find(function (t) { return t.dataset.tab === active; });
            indicator.classList.toggle('no-anim', !animate);
            indicator.style.left = btn.offsetLeft + 'px';
            indicator.style.width = btn.offsetWidth + 'px';
            indicator.style.backgroundColor = COLOR[active];
            if (!animate) { void indicator.offsetWidth; indicator.classList.remove('no-anim'); }
        }

        function show(tab, animate) {
            active = tab;
            try { sessionStorage.setItem('dashTab', tab); } catch (e) {}
            const btn = tabs.find(function (t) { return t.dataset.tab === tab; });
            tabs.forEach(function (t) {
                const on = t === btn;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            moveIndicator(animate);

            // Paliwanag: naglalaho muna, saka papalitan
            if (animate && !reduce) {
                info.classList.add('swap');
                setTimeout(function () { info.textContent = btn.dataset.info; info.classList.remove('swap'); }, 180);
            } else {
                info.textContent = btn.dataset.info;
            }

            // Kapag kumakain na ng espasyo ang laman ng bukas na tab, ilipat ang paliwanag sa tabi ng mga tab
            const pane = panes.find(function (p) { return p.dataset.pane === tab; });
            const items = pane ? pane.querySelectorAll('.rec-row, .schedule-item, .pay-row').length : 0;
            top.classList.toggle('info-inline', items >= INLINE_AT);
            // Button sa kanan ng paliwanag para sa bukas na tab lang (hal. Manage Payments)
            top.querySelectorAll('.dash-action-wrap').forEach(function (a) { a.hidden = a.dataset.for !== tab; });

            panes.forEach(function (p) {
                const on = p.dataset.pane === tab;
                p.hidden = !on;
                if (on && animate && !reduce && p.animate) {
                    p.animate([{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
                        { duration: 320, easing: 'cubic-bezier(.22, 1, .36, 1)' });
                }
            });
        }

        tabs.forEach(function (t) {
            t.addEventListener('click', function () { if (t.dataset.tab !== active) show(t.dataset.tab, true); });
        });
        window.addEventListener('resize', function () { moveIndicator(false); });
        window.addEventListener('load', function () { moveIndicator(false); });
        show(active, false);
    })();
</script>
<script src="../connection/sp_tabs.js"></script>

    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
