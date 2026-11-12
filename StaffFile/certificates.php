<?php
/**
 * Certificates - ang opisyal na hard copy ng Certificate of Adoption at
 * Vaccination Certificate ng bawat natapos na adoption (Approved + Paid).
 * Daloy: ipi-print ng staff -> pipirmahan ng City Veterinarian at tatatakan
 * -> "Ready for Pickup" (may abiso sa adopter) -> "Released" kapag naiabot na.
 */
session_start();
require_once __DIR__ . '/../connection/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$user_stmt = $pdo->prepare("SELECT username, profile_image FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$username = $current_user['username'] ?? 'Staff Member';
$profile_image = $current_user['profile_image'] ?? '';
$user_initials = strtoupper(substr($username, 0, 2));

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
// "Nice job!" na popup pagkatapos pindutin ang Certificate Given
$celebrate = $_SESSION['cert_celebrate'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['cert_celebrate']);

$records = [];
try {
    $records = $pdo->query(
        "SELECT aa.application_id, aa.full_name, aa.phone_number,
                aa.cert_ready_at, aa.cert_released_at, aa.cert_adopter_received_at,
                p.name AS pet_name, p.kind AS pet_kind,
                pay.payment_date,
                ru.username AS ready_by_name, rl.username AS released_by_name,
                cf.rating AS fb_rating, cf.comment AS fb_comment, cf.created_at AS fb_date
         FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
         LEFT JOIN users ru ON ru.user_id = aa.cert_ready_by
         LEFT JOIN users rl ON rl.user_id = aa.cert_released_by
         LEFT JOIN certificate_feedback cf ON cf.application_id = aa.application_id
         WHERE (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
         ORDER BY (aa.cert_released_at IS NOT NULL) ASC, (aa.cert_ready_at IS NOT NULL) ASC, pay.payment_date DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $records = [];
}

$to_print = $ready = $released = 0;
$confirmed = $rated = 0;
$ratingSum = 0;
foreach ($records as $r) {
    if (!empty($r['cert_released_at'])) { $released++; }
    elseif (!empty($r['cert_ready_at'])) { $ready++; }
    else { $to_print++; }
    if (!empty($r['cert_adopter_received_at'])) { $confirmed++; }
    if ($r['fb_rating'] !== null) { $rated++; $ratingSum += (int) $r['fb_rating']; }
}
// Karaniwang rating ng adopters sa pag-asikaso ng staff (para sa banner)
$avgRating = $rated > 0 ? round($ratingSum / $rated, 1) : null;

// Lahat ng feedback ng adopters (para sa popup ng "Service rating"), pinakabago muna
$feedbacks = array_values(array_filter($records, function ($r) { return $r['fb_rating'] !== null; }));
usort($feedbacks, function ($a, $b) { return strcmp((string) $b['fb_date'], (string) $a['fb_date']); });
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificates - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        .cert-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
            margin-top: 0; /* ang puwang mula sa banner ay ang gap na ng page */
        }

        /* ===== Banner ng page ===== */
        .cert-hero {
            position: relative;
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 22px 26px;
            border-radius: 18px;
            overflow: hidden;
            color: #fff;
            /* Asul - kapareho ng staff sidebar (#1e3a8a) */
            background:
                radial-gradient(circle at 88% 0%, rgba(147, 197, 253, 0.35), transparent 42%),
                linear-gradient(135deg, #172f73 0%, #1e3a8a 50%, #2f5bc4 100%);
            box-shadow: 0 10px 24px rgba(30, 58, 138, 0.25);
        }
        .cert-hero-icon {
            width: 58px;
            height: 58px;
            flex-shrink: 0;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.13);
            box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, 0.2);
        }
        .cert-hero-icon svg {
            width: 30px;
            height: 30px;
            fill: none;
            stroke: #bfdbfe;
            stroke-width: 1.7;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .cert-hero-text { position: relative; z-index: 1; flex: 1; min-width: 220px; }
        .cert-hero-text h1 { margin: 0 0 4px; font-size: 25px; font-weight: 800; color: #fff; }
        .cert-hero-text p { margin: 0; font-size: 13.5px; line-height: 1.5; color: rgba(255, 255, 255, 0.85); max-width: 560px; }
        .cert-hero-stats { position: relative; z-index: 1; display: flex; gap: 10px; flex-wrap: wrap; }
        .cert-hero-stat {
            min-width: 92px;
            padding: 9px 14px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.12);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.15);
            text-align: center;
        }
        .cert-hero-stat strong { display: block; font-size: 20px; font-weight: 800; color: #fff; line-height: 1.2; }
        .cert-hero-stat span { display: block; margin-top: 2px; font-size: 11px; font-weight: 600; color: rgba(255, 255, 255, 0.8); }
        .cert-hero-paw {
            position: absolute;
            right: 18px;
            bottom: -26px;
            width: 120px;
            height: 120px;
            fill: rgba(255, 255, 255, 0.06);
            transform: rotate(-18deg);
            pointer-events: none;
        }
        @media (max-width: 760px) {
            .cert-hero { flex-wrap: wrap; padding: 20px; }
            .cert-hero-stats { width: 100%; }
            .cert-hero-stat { flex: 1; }
        }

        /* ===== Telepono: siksik na banner na sticky (buong lapad) ===== */
        @media (max-width: 600px) {
            .cert-hero {
                display: grid; grid-template-columns: 40px 1fr; align-items: center;
                column-gap: 12px; row-gap: 10px; padding: 14px 16px;
                /* buong lapad: may tig-12px na padding ang .main-wrapper at .dashboard-content */
                margin: 0 -24px 14px; border-radius: 0;
                position: sticky; top: var(--sp-stick-top, 0px); z-index: 40;
                transition: box-shadow .2s ease;   /* walang animation ng taas: iwas pagkislap (spSetStuck) */
            }
            .cert-hero-text { display: contents; }   /* title sa tabi ng icon; paliwanag sa ilalim */
            .cert-hero-icon { width: 40px; height: 40px; border-radius: 11px; }
            .cert-hero-icon svg { width: 22px; height: 22px; }
            .cert-hero-text h1 { margin: 0; font-size: 20px; line-height: 1.2; }
            .cert-hero-text p {
                grid-column: 1 / -1; font-size: 12.5px; line-height: 1.5;
                max-height: 80px; overflow: hidden; transition: opacity .2s ease;
            }
            .cert-hero-stats { grid-column: 1 / -1; display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .cert-hero-stat { min-width: 0; padding: 8px 6px; border-radius: 10px; }
            .cert-hero-stat strong { font-size: 17px; }
            .cert-hero-stat span { font-size: 10.5px; line-height: 1.25; }
            .cert-hero-stat-hint { font-size: 10px !important; margin-top: 2px !important; max-height: 20px; overflow: hidden; transition: opacity .2s ease; }
            .cert-hero-paw { width: 90px; height: 90px; right: 6px; }

            /* Nakadikit na: lumiliit - title at mga numero na lang ang natitira */
            .cert-hero.is-stuck { padding-top: 10px; padding-bottom: 10px; row-gap: 8px; box-shadow: 0 8px 14px -10px rgba(0, 0, 0, 0.45); }
            .cert-hero.is-stuck .cert-hero-text p,
            .cert-hero.is-stuck .cert-hero-stat-hint { max-height: 0; opacity: 0; margin: 0 !important; }
            .cert-hero.is-stuck .cert-hero-icon { width: 32px; height: 32px; }
            .cert-hero.is-stuck .cert-hero-text h1 { font-size: 17px; }
            .cert-hero.is-stuck .cert-hero-stat { padding: 5px 6px; }
            .cert-hero.is-stuck .cert-hero-stat strong { font-size: 15px; }

            /* "How to issue…" at mga hakbang */
            .cert-steps-toggle { width: 100%; height: 38px; justify-content: center; font-size: 12.5px; padding: 0 10px; }
            .cert-steps-inner { font-size: 12.5px; padding: 10px 12px 10px 10px; margin: 8px 0 10px; }
            .cert-steps-inner ol { padding-left: 18px; line-height: 1.6; }

            /* Tatlong tab: magkakasinlapad, kasya sa iisang hanay; bilang sa ilalim ng pangalan */
            .cert-toolbar { row-gap: 10px; }
            /* (may .cert-toolbar sa unahan: nasa ibaba ng file ang orihinal na .cert-tab,
               kaya kailangang mas malakas ang selector dito) */
            .cert-toolbar .cert-tabs { display: flex; width: 100%; height: auto; }
            .cert-toolbar .cert-tab {
                flex: 1 1 0; min-width: 0; min-height: 46px; padding: 6px 4px;
                justify-content: center; text-align: center; white-space: normal; font-size: 11px; line-height: 1.2;
            }
            .cert-toolbar .cert-tab .tab-inner { flex-direction: column; gap: 3px; }
            .cert-toolbar .cert-tab .tab-count { font-size: 10px; padding: 0 6px; min-width: 16px; line-height: 15px; }
        }
        /* "How to issue the official certificate": button sa kanan ng mga tab;
           lumalabas ang mga hakbang sa ilalim kapag pinindot */
        .cert-steps-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 40px;
            padding: 0 14px;
            border: 1px solid #d7e8dc;
            border-radius: 10px;
            background: #f6faf7;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            color: #1f3d2c;
            cursor: pointer;
        }
        .cert-steps-toggle:hover { color: #1f6b48; }
        .cert-steps-toggle[aria-expanded="true"] { background: #e8f3ec; border-color: #b9d8c4; }
        .cert-steps-icon {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #1f6b48;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            font-family: Georgia, serif;
        }
        .cert-steps-chevron {
            width: 7px;
            height: 7px;
            margin-left: 4px;
            border-right: 2px solid #1f6b48;
            border-bottom: 2px solid #1f6b48;
            transform: rotate(45deg) translateY(-2px);
        }
        .cert-steps-toggle[aria-expanded="true"] .cert-steps-chevron { transform: rotate(-135deg) translateY(-1px); }
        /* Kahon ng mga hakbang: buong lapad, sa pagitan ng "How to issue" at ng mga tab
           (kaya bumababa ang mga tab sa ilalim nito) */
        .cert-steps-panel {
            flex: 0 0 100%;
            overflow: hidden;
        }
        .cert-steps-panel[hidden] { display: none; }
        .cert-steps-inner {
            margin: 10px 0 12px;
            padding: 12px 18px 12px 14px;
            background: #f6faf7;
            border: 1px solid #d7e8dc;
            border-radius: 10px;
            font-size: 13.5px;
            color: #1f3d2c;
        }
        .cert-steps-inner ol { margin: 0; padding-left: 20px; line-height: 1.7; }

        /* Malambot na paglitaw ng mga row kapag nagpalit ng tab */
        .cert-table tbody tr.row-in { animation: rowIn .35s ease both; }
        @keyframes rowIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) {
            .cert-table tbody tr.row-in { animation: none; }
        }
        /* ===== Search (nasa loob ng toolbar) =====
           Sarado ang "How to issue": buong linya sa ilalim ng mga tab.
           Bukas: lumilipat sa kanan, kapantay ng mga tab. */
        .cert-search-box {
            position: relative;
            flex: 0 0 100%;
            margin-top: 12px;
        }
        .cert-toolbar.steps-open .cert-search-box {
            flex: 0 1 340px;
            margin-top: 0;
            margin-left: auto;
            /* Hindi dumidikit sa pinakagilid: hanggang 90% lang ng lapad */
            margin-right: 10%;
        }
        .cert-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            width: 18px;
            height: 18px;
            transform: translateY(-50%);
            fill: none;
            stroke: #4a64b0;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: none;
            transition: stroke .2s ease;
        }
        .cert-search-box:focus-within .cert-search-icon { stroke: #1e3a8a; }
        .cert-search {
            width: 100%;
            max-width: 340px;
            height: 40px;
            padding: 0 14px 0 40px;
            /* Light blue na background para mapansin agad */
            border: 1px solid #cfdcf5;
            border-radius: 10px;
            background: #eef3ff;
            box-shadow: inset 0 1px 2px rgba(30, 58, 138, 0.06);
            font-size: 14px;
            font-family: inherit;
            color: #102a2a;
            box-sizing: border-box;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .cert-search::placeholder { color: #6b7a99; }
        .cert-search:hover { border-color: #b4c7ee; }
        .cert-search:focus {
            outline: none;
            border-color: #1e3a8a;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.12);
        }
        .cert-toolbar.steps-open .cert-search { max-width: none; }

        .cert-table { width: 100%; border-collapse: collapse; text-align: left; min-width: 820px; }
        .cert-table th {
            padding: 12px 10px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #f1f1f1;
        }
        .cert-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f8f8f8;
            vertical-align: middle;
            font-size: 14px;
            color: #102a2a;
        }
        .cert-table tr:hover td { background: #fbfaf7; }

        .cert-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .cert-badge.is-print { background: #fef3d6; color: #b25900; }
        .cert-badge.is-ready { background: #e0edff; color: #1a56db; }
        .cert-badge.is-released { background: #e6f4ea; color: #1e7e34; }

        .sub-note { font-size: 12px; color: #9aa6a3; margin-top: 2px; }

        .action-stack { display: inline-flex; align-items: center; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        .btn-print, .btn-step, .btn-undo {
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            display: inline-block;
            transition: background 0.14s ease, transform 0.14s ease;
        }
        .btn-print { background: #fff; color: #1f6b48; border: 1px solid #1f6b48; }
        .btn-print:hover { background: #1f6b48; color: #fff; transform: translateY(-1px); }
        .btn-step { background: #1f6b48; color: #fff; }
        .btn-step:hover { background: #17573a; transform: translateY(-1px); }
        .btn-undo { background: transparent; color: #9aa6a3; padding: 7px 6px; font-weight: 600; }
        .btn-undo:hover { color: #c5221f; text-decoration: underline; }

        /* ===== Iisang linya: mga tab (kaliwa) + "How to issue" (kanan) ===== */
        .cert-toolbar {
            display: flex;
            align-items: center;
            justify-content: flex-start; /* magkatabi: "How to issue" at ang mga tab */
            flex-wrap: wrap;
            column-gap: 12px;
            row-gap: 0; /* ang puwang sa itaas/ibaba ng mga hakbang ay nasa .cert-steps-inner, para smooth */
            margin-bottom: 16px;
        }

        /* Mga tab. Ang madilim na highlight (.cert-tab-indicator) ay dumudulas
           papunta sa pinindot na tab at nagbabago ng kulay */
        .cert-tabs {
            position: relative;
            display: inline-flex;
            height: 40px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }
        .cert-tab {
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
        /* Mapusyaw na kulay ng bawat tab (nasa likod ng highlight) */
        .cert-tab::before { content: ""; position: absolute; inset: 0; z-index: 0; }
        .cert-tab.tab-print::before { background: #5bc85b; }
        .cert-tab.tab-ready::before { background: #6d8cf0; }
        .cert-tab.tab-released::before { background: #ffad5c; }
        .tab-inner { position: relative; z-index: 2; display: inline-flex; align-items: center; gap: 8px; }
        .tab-count {
            min-width: 20px;
            padding: 1px 7px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
            font-size: 11.5px;
            text-align: center;
            transition: transform .3s ease;
        }
        .cert-tab.active .tab-count { animation: countPop .4s cubic-bezier(.34, 1.56, .64, 1); }
        .cert-tab-indicator {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 0;
            z-index: 1;
            background: #1f7a2e;
            box-shadow: inset 0 -3px 0 rgba(0, 0, 0, 0.18);
            transition:
                left .4s cubic-bezier(.65, 0, .35, 1),
                width .4s cubic-bezier(.65, 0, .35, 1),
                background-color .4s ease;
        }
        .cert-tab-indicator.no-anim { transition: none; }
        .cert-tab:focus-visible { outline: 3px solid #102a2a; outline-offset: -3px; }
        @keyframes countPop { 0% { transform: scale(.6); } 100% { transform: scale(1); } }
        @media (prefers-reduced-motion: reduce) {
            .cert-tab-indicator { transition: none; }
            .cert-tab.active .tab-count { animation: none; }
        }

        /* Certificate Given: naiabot na ang hard copy */
        .btn-given {
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
            background: #f59c23;
            color: #fff;
            transition: background 0.14s ease, transform 0.14s ease;
        }
        .btn-given:hover:not(:disabled) { background: #e08b18; transform: translateY(-1px); }
        .btn-given:disabled { background: #eceff1; color: #a7b0ad; cursor: not-allowed; }

        /* Kumpirmasyon at feedback ng adopter */
        .adopter-confirm { margin-top: 3px; font-size: 12px; font-weight: 600; color: #b25900; line-height: 1.4; }
        .adopter-confirm.is-yes { color: #1e7e34; }

        /* Rating + komento sa iisang compact na kahon; puputulin ang mahabang komento */
        .fb-box {
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: 100%;
            margin-top: 6px;
            padding: 4px 10px;
            border-radius: 8px;
            background: #fffaf0;
            border: 1px solid #f6e3bd;
            box-sizing: border-box;
        }
        .fb-stars { flex-shrink: 0; font-size: 13px; letter-spacing: 1px; line-height: 1; color: #d6d9dc; }
        .fb-stars .on { color: #f5a623; }
        .fb-comment {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12px;
            font-style: italic;
            color: #4b5563;
        }

        /* Spacing lang ng table (hindi ginagalaw ang mga button) */
        .cert-table th { padding: 12px 14px; }
        .cert-table td { padding: 16px 14px; }
        .cert-table td .sub-note { margin-top: 3px; }
        .cert-table .cert-badge { white-space: nowrap; }
        /* Hindi nahahati sa dalawang linya ang text sa loob ng mga button */
        .cert-table td:last-child { white-space: nowrap; }
        /* Hindi lalapad nang sobra ang Status dahil sa mahabang komento */
        .cert-table .fb-box { max-width: 260px; }

        /* ===== "Nice job!" popup ===== */
        .celebrate-backdrop {
            position: fixed;
            inset: 0;
            z-index: 5000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(10, 30, 20, 0.45);
            animation: celFade .3s ease;
        }
        .celebrate-box {
            position: relative;
            width: 100%;
            max-width: 340px;
            padding: 30px 26px 24px;
            border-radius: 20px;
            background: #fff;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: celPop .55s cubic-bezier(.34, 1.56, .64, 1);
        }
        .celebrate-icon {
            width: 76px;
            height: 76px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            background: linear-gradient(135deg, #ffe2b0, #ffc46b);
            box-shadow: 0 0 0 8px #fff4e0;
            animation: celWiggle 1s ease .5s;
        }
        .celebrate-box h2 { margin: 0 0 6px; font-size: 24px; font-weight: 800; color: #1f6b48; }
        .celebrate-box p { margin: 0 0 18px; font-size: 14px; line-height: 1.5; color: #4b5563; }
        .celebrate-box p strong { color: #102a2a; }
        .celebrate-ok {
            width: 100%;
            padding: 11px;
            border: none;
            border-radius: 10px;
            background: #1f6b48;
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }
        .celebrate-ok:hover { background: #17573a; }
        /* Confetti */
        .confetti {
            position: absolute;
            top: -12px;
            width: 8px;
            height: 14px;
            border-radius: 2px;
            opacity: 0;
            animation: confettiFall 1.8s ease-in forwards;
        }
        @keyframes celFade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes celPop { from { opacity: 0; transform: scale(.7) translateY(20px); } to { opacity: 1; transform: none; } }
        @keyframes celWiggle { 0%, 100% { transform: rotate(0); } 25% { transform: rotate(-12deg); } 50% { transform: rotate(10deg); } 75% { transform: rotate(-6deg); } }
        @keyframes confettiFall {
            0% { opacity: 1; transform: translateY(0) rotate(0); }
            100% { opacity: 0; transform: translateY(380px) rotate(540deg); }
        }
        @media (prefers-reduced-motion: reduce) {
            .celebrate-backdrop, .celebrate-box, .celebrate-icon, .confetti { animation: none; }
            .confetti { display: none; }
        }

        /* ===== Sa laptop: walang scrollbar ang buong page - ang table lang ang
           nag-i-scroll (kapareho ng Certificate page ng adopter) ===== */
        @media (min-width: 901px) {
            html:has(body.cert-page) { overflow: hidden; scrollbar-gutter: auto; }
            body.cert-page { height: 100vh; overflow: hidden; }
            .cert-page .main-wrapper {
                height: 100vh;
                min-height: 0 !important;
                overflow: hidden;
            }
            .cert-page .main-wrapper > .top-header { flex-shrink: 0; }
            .cert-page .dashboard-content {
                flex: 1;
                min-height: 0;
                display: flex;
                flex-direction: column;
                padding-bottom: 16px !important;
            }
            .cert-page .cert-hero,
            .cert-page .dashboard-content > div:not(.cert-panel) { flex-shrink: 0; }
            .cert-page .cert-panel {
                flex: 1;
                min-height: 0;
                display: flex;
                flex-direction: column;
            }
            .cert-page .cert-toolbar { flex-shrink: 0; }
            .cert-page .cert-table-wrap {
                flex: 1;
                min-height: 0;
                overflow: auto !important;
                scrollbar-width: thin;
                scrollbar-color: #9fb3e6 transparent;
            }
            .cert-page .cert-table-wrap::-webkit-scrollbar { width: 8px; height: 8px; }
            .cert-page .cert-table-wrap::-webkit-scrollbar-track { background: transparent; }
            .cert-page .cert-table-wrap::-webkit-scrollbar-thumb { background: #9fb3e6; border-radius: 999px; }
            .cert-page .cert-table-wrap::-webkit-scrollbar-thumb:active { background: #1e3a8a; }
            /* Nakadikit sa itaas ang header ng table habang nag-i-scroll */
            .cert-page .cert-table thead th {
                position: sticky;
                top: 0;
                z-index: 2;
                background: #fff;
                box-shadow: inset 0 -1px 0 #f1f1f1;
            }
        }
    </style>
</head>
<body class="staff-dashboard-root cert-page">
<div class="app-container">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <div class="top-header">
            <div class="search-top"></div>
            <a href="/Caps2/StaffFile/edit_profile.php" class="profile-section" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <div class="avatar" style="width: 38px; height: 38px; border-radius: 50%; background: #1f6b48; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                        <?php echo htmlspecialchars($user_initials); ?>
                    </div>
                <?php endif; ?>
                <div class="profile-name" style="font-weight: 700; color: #102a2a;">
                    <?php echo htmlspecialchars($username); ?>
                </div>
            </a>
        </div>

        <div class="dashboard-content page-content" style="padding: 0 12px 24px 12px;">
            <!-- Banner ng page. #certHeroMark = palatandaan ng tunay na puwesto (para sa sticky sa telepono) -->
            <div id="certHeroMark" aria-hidden="true"></div>
            <section class="cert-hero" id="certHero">
                <div class="cert-hero-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v8"/><path d="M14 3v5h5"/><path d="M6 3v14h6"/><circle cx="17" cy="17" r="3.2"/><path d="M15.4 19.6 14.8 23l2.2-1.2 2.2 1.2-.6-3.4"/><path d="M9 9h4M9 12h3"/></svg>
                </div>
                <div class="cert-hero-text">
                    <h1>Certificates</h1>
                    <p>Print, sign, and hand over the official Certificate of Adoption, Vaccination Certificate and Pet Adoption Agreement.</p>
                </div>
                <div class="cert-hero-stats">
                    <div class="cert-hero-stat">
                        <strong><?php echo count($records); ?></strong>
                        <span>Adoptions</span>
                    </div>
                    <div class="cert-hero-stat">
                        <strong><?php echo $confirmed; ?></strong>
                        <span>Confirmed by adopter</span>
                    </div>
                    <!-- Napipindot: binubuksan ang lahat ng feedback ng adopters -->
                    <button type="button" class="cert-hero-stat is-link" id="fbOpen" aria-haspopup="dialog">
                        <strong><?php echo $avgRating !== null ? $avgRating . ' &#9733;' : '&ndash;'; ?></strong>
                        <span><?php echo $rated > 0 ? 'Service rating (' . $rated . ')' : 'No ratings yet'; ?></span>
                        <em class="cert-hero-stat-hint">View feedback &rsaquo;</em>
                    </button>
                </div>
                <svg class="cert-hero-paw" viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="6" cy="9" rx="2" ry="2.6"/><ellipse cx="10" cy="5.5" rx="2" ry="2.6"/><ellipse cx="14" cy="5.5" rx="2" ry="2.6"/><ellipse cx="18" cy="9" rx="2" ry="2.6"/><path d="M12 11c3 0 6 3.5 6 6.3 0 2-1.6 2.7-3 2.7-1.2 0-2-.6-3-.6s-1.8.6-3 .6c-1.4 0-3-.7-3-2.7C6 14.5 9 11 12 11z"/></svg>
            </section>

            <?php if (!empty($success_message)): ?>
                <div style="background:#e6f4ea;color:#1e7e34;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div style="background:#fce8e6;color:#c5221f;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <div class="cert-panel">
                <!-- Iisang linya: "How to issue" sa kaliwa, mga tab sa kanan (pareho ang taas) -->
                <div class="cert-toolbar">
                    <!-- Naka-tiklop para hindi kumain ng espasyo; pindutin para makita ang mga hakbang -->
                    <button type="button" class="cert-steps-toggle" id="certStepsToggle" aria-expanded="false" aria-controls="certStepsPanel">
                        <span class="cert-steps-icon" aria-hidden="true">i</span>
                        How to issue the official certificate
                        <span class="cert-steps-chevron" aria-hidden="true"></span>
                    </button>

                    <!-- Mga hakbang: lumalabas sa ilalim ng "How to issue"; bumababa ang mga tab sa ilalim nito -->
                    <div class="cert-steps-panel" id="certStepsPanel" hidden>
                        <div class="cert-steps-inner">
                            <ol>
                            <li>Click <strong>Edit / Print</strong>, click the highlighted blanks on the papers to fill them in (valid ID, pet color, veterinarian, witness, signing date), then open the generated PDF (Certificate of Adoption, Vaccination Certificate, and Pet Adoption Agreement) and print it.</li>
                            <li>Have the City Veterinarian sign it and apply the office dry seal or rubber stamp.</li>
                            <li>Click <strong>Ready for Pickup</strong>. The adopter will be notified.</li>
                            <li>On pickup day, have the adopter sign the Pet Adoption Agreement in front of a witness, hand over the hard copy, then click <strong>Certificate Given</strong>. The adopter will be asked to confirm with <strong>I Received</strong> and rate how they were assisted.</li>
                            </ol>
                        </div>
                    </div>

                    <div class="cert-tabs" role="tablist" aria-label="Certificate status">
                        <!-- Dumudulas na highlight ng bukas na tab (tingnan ang moveIndicator sa ibaba) -->
                        <span class="cert-tab-indicator" aria-hidden="true"></span>
                        <button type="button" class="cert-tab tab-print" role="tab" data-tab="print">
                            <span class="tab-inner">Edit / Print / Pickup <span class="tab-count"><?php echo $to_print; ?></span></span>
                        </button>
                        <button type="button" class="cert-tab tab-ready" role="tab" data-tab="ready">
                            <span class="tab-inner">Ready for Pickup <span class="tab-count"><?php echo $ready; ?></span></span>
                        </button>
                        <button type="button" class="cert-tab tab-released" role="tab" data-tab="released">
                            <span class="tab-inner">Certificate Given <span class="tab-count"><?php echo $released; ?></span></span>
                        </button>
                    </div>

                    <!-- Search: nasa ilalim kapag sarado ang "How to issue"; lumilipat sa
                         kanan, kapantay ng mga tab, kapag bukas -->
                    <div class="cert-search-box">
                        <svg class="cert-search-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"/>
                            <path d="M20 20l-3.6-3.6"/>
                        </svg>
                        <input type="search" id="certSearch" class="cert-search" placeholder="Search by adopter or pet name..." aria-label="Search by adopter or pet name" autocomplete="off" oninput="filterCerts()">
                    </div>
                </div>

                <div class="cert-table-wrap" style="overflow-x:auto;">
                    <table class="cert-table">
                        <thead>
                            <tr>
                                <th>Adopter</th>
                                <th>Pet</th>
                                <th>Certificate No.</th>
                                <th>Adopted</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($records) > 0): ?>
                                <?php foreach ($records as $r):
                                    $appId = (int) $r['application_id'];
                                    $seq = date('Y', strtotime($r['payment_date'])) . '-' . str_pad((string) $appId, 5, '0', STR_PAD_LEFT);
                                    if (!empty($r['cert_released_at'])) {
                                        $state = 'released';
                                    } elseif (!empty($r['cert_ready_at'])) {
                                        $state = 'ready';
                                    } else {
                                        $state = 'print';
                                    }
                                ?>
                                    <tr data-state="<?php echo $state; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($r['full_name']); ?></strong>
                                            <div class="sub-note"><?php echo htmlspecialchars($r['phone_number']); ?></div>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($r['pet_name']); ?>
                                            <div class="sub-note"><?php echo htmlspecialchars($r['pet_kind']); ?></div>
                                        </td>
                                        <td>
                                            CVO-<?php echo $seq; ?>
                                            <div class="sub-note">VAX-<?php echo $seq; ?></div>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($r['payment_date'])); ?></td>
                                        <td>
                                            <?php if ($state === 'released'): ?>
                                                <span class="cert-badge is-released">Certificate Given</span>
                                                <div class="sub-note"><?php echo date('M j, Y', strtotime($r['cert_released_at'])); ?><?php echo $r['released_by_name'] ? ' by ' . htmlspecialchars($r['released_by_name']) : ''; ?></div>
                                                <?php if (!empty($r['cert_adopter_received_at'])): ?>
                                                    <div class="adopter-confirm is-yes"><i aria-hidden="true">&#10003;</i> Adopter confirmed received</div>
                                                <?php else: ?>
                                                    <div class="adopter-confirm">Waiting for adopter to confirm</div>
                                                <?php endif; ?>
                                                <!-- Ang feedback ng adopter ay nasa "Service rating" sa banner -->
                                            <?php elseif ($state === 'ready'): ?>
                                                <span class="cert-badge is-ready">Ready for Pickup</span>
                                                <div class="sub-note">Signed <?php echo date('M j, Y', strtotime($r['cert_ready_at'])); ?><?php echo $r['ready_by_name'] ? ' by ' . htmlspecialchars($r['ready_by_name']) : ''; ?></div>
                                            <?php else: ?>
                                                <span class="cert-badge is-print">To Print &amp; Sign</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <div class="action-stack">
                                                <a class="btn-print" href="/Caps2/Adopter_File/certificate.php?application_id=<?php echo $appId; ?>">Edit / Print</a>
                                                <?php if ($state === 'print'): ?>
                                                    <form method="POST" action="process_certificate_status.php" style="display:inline;"
                                                          data-confirm="Has the City Veterinarian signed and sealed the certificate of <?php echo htmlspecialchars($r['pet_name']); ?>? The adopter will be notified that it is ready for pickup."
                                                          data-confirm-title="Ready for pickup?"
                                                          data-confirm-ok="Yes, it's signed"
                                                          data-confirm-icon="&#9997;&#65039;"
                                                          data-confirm-tone="green">
                                                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                                                        <input type="hidden" name="action" value="ready">
                                                        <button type="submit" class="btn-step">Ready for Pickup</button>
                                                    </form>
                                                    <!-- Mapipindot lang pagkatapos ng Ready for Pickup -->
                                                    <button type="button" class="btn-given" disabled title="Click Ready for Pickup first">Certificate Given</button>
                                                <?php elseif ($state === 'ready'): ?>
                                                    <form method="POST" action="process_certificate_status.php" style="display:inline;"
                                                          data-confirm="Confirm that you handed the printed certificate of <?php echo htmlspecialchars($r['pet_name']); ?> to <?php echo htmlspecialchars($r['full_name']); ?>."
                                                          data-confirm-title="Certificate given?"
                                                          data-confirm-ok="Yes, I gave it"
                                                          data-confirm-icon="&#129309;"
                                                          data-confirm-tone="orange">
                                                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                                                        <input type="hidden" name="action" value="release">
                                                        <button type="submit" class="btn-given">Certificate Given</button>
                                                    </form>
                                                    <form method="POST" action="process_certificate_status.php" style="display:inline;">
                                                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                                                        <input type="hidden" name="action" value="undo">
                                                        <button type="submit" class="btn-undo">Undo</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="process_certificate_status.php" style="display:inline;">
                                                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                                                        <input type="hidden" name="action" value="undo">
                                                        <button type="submit" class="btn-undo">Undo</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center;padding:30px;color:#9aa6a3;">
                                        No completed adoptions yet. Certificates appear here once an approved adoption has been paid.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div id="certNoResults" style="display:none;text-align:center;padding:30px;color:#9aa6a3;">
                        No certificates found matching your search.
                    </div>
                    <div id="certTabEmpty" style="display:none;text-align:center;padding:30px;color:#9aa6a3;"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Kasalukuyang tab: naaalala habang bukas ang browser tab (pagkatapos ng
    // pagpindot ng button, babalik sa parehong tab)
    let activeTab = 'print';
    try { activeTab = sessionStorage.getItem('certTab') || 'print'; } catch (e) {}

    const TAB_EMPTY = {
        print: 'No certificates to print and sign right now.',
        ready: 'No certificates waiting for pickup.',
        released: 'No certificates given yet.'
    };

    function filterCerts() {
        const filter = document.getElementById('certSearch').value.toLowerCase();
        const rows = document.querySelectorAll('.cert-table tbody tr[data-state]');
        let inTab = 0;
        let visible = 0;
        rows.forEach(function (row) {
            const cells = row.getElementsByTagName('td');
            const sameTab = row.dataset.state === activeTab;
            const matches = (cells[0].textContent + ' ' + cells[1].textContent).toLowerCase().indexOf(filter) > -1;
            row.style.display = sameTab && matches ? '' : 'none';
            if (sameTab) inTab++;
            if (sameTab && matches) visible++;
        });
        const empty = document.getElementById('certTabEmpty');
        empty.textContent = TAB_EMPTY[activeTab];
        empty.style.display = (rows.length > 0 && inTab === 0) ? 'block' : 'none';
        document.getElementById('certNoResults').style.display = (inTab > 0 && visible === 0 && filter !== '') ? 'block' : 'none';
    }

    // Madilim na kulay ng highlight ayon sa tab
    const TAB_COLOR = { print: '#1f7a2e', ready: '#1e2f8f', released: '#f07c12' };

    // Idulas ang highlight papunta sa bukas na tab
    function moveIndicator(animate) {
        const indicator = document.querySelector('.cert-tab-indicator');
        const btn = document.querySelector('.cert-tab[data-tab="' + activeTab + '"]');
        if (!indicator || !btn) return;
        indicator.classList.toggle('no-anim', !animate);
        indicator.style.left = btn.offsetLeft + 'px';
        indicator.style.width = btn.offsetWidth + 'px';
        indicator.style.backgroundColor = TAB_COLOR[activeTab];
        if (!animate) { void indicator.offsetWidth; indicator.classList.remove('no-anim'); }
    }

    function setTab(tab, animate) {
        activeTab = tab;
        try { sessionStorage.setItem('certTab', tab); } catch (e) {}
        document.querySelectorAll('.cert-tab').forEach(function (b) {
            const on = b.dataset.tab === tab;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        moveIndicator(animate);
        filterCerts();
    }
    window.addEventListener('resize', function () { moveIndicator(false); });
    window.addEventListener('load', function () { moveIndicator(false); });

    // Paglipat ng tab: dumudulas agad ang highlight; ang table ay naglalaho muna,
    // saka nagpapalit ang laman, saka lumilitaw ulit - kaya hindi nakikita ang
    // biglang pagbabago ng lapad ng mga column
    const tableWrap = document.querySelector('.cert-table-wrap');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let swapTimer = null;

    document.querySelectorAll('.cert-tab').forEach(function (b) {
        b.addEventListener('click', function () {
            if (b.dataset.tab === activeTab) return;
            const tab = b.dataset.tab;

            if (reduceMotion || !tableWrap || !tableWrap.animate) {
                setTab(tab, true);
                return;
            }

            // Highlight ng tab: agad
            activeTab = tab;
            try { sessionStorage.setItem('certTab', tab); } catch (e) {}
            document.querySelectorAll('.cert-tab').forEach(function (x) {
                const on = x.dataset.tab === tab;
                x.classList.toggle('active', on);
                x.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            moveIndicator(true);

            // Sa laptop, sakop na ng table ang natitirang espasyo (siya lang ang
            // nag-i-scroll) kaya fade lang; sa phone, inaayos din ang taas
            const fixedArea = window.matchMedia('(min-width: 901px)').matches;
            if (fixedArea) {
                clearTimeout(swapTimer);
                tableWrap.getAnimations().forEach(function (a) { a.cancel(); });
                tableWrap.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, easing: 'ease-in', fill: 'forwards' });
                swapTimer = setTimeout(function () {
                    filterCerts();
                    tableWrap.scrollTop = 0;
                    tableWrap.getAnimations().forEach(function (a) { a.cancel(); });
                    tableWrap.animate(
                        [{ opacity: 0, transform: 'translateY(8px)' }, { opacity: 1, transform: 'none' }],
                        { duration: 320, easing: 'cubic-bezier(.22, 1, .36, 1)' }
                    );
                }, 160);
                return;
            }

            // Table: fade out -> palit -> fade in, habang dahan-dahang nag-aadjust
            // ang taas (hindi biglang humahaba/umiikli ang page)
            clearTimeout(swapTimer);
            const startH = tableWrap.getBoundingClientRect().height;
            tableWrap.getAnimations().forEach(function (a) { a.cancel(); });
            tableWrap.style.height = startH + 'px';
            tableWrap.style.overflowY = 'hidden';
            tableWrap.animate(
                [{ opacity: 1 }, { opacity: 0 }],
                { duration: 160, easing: 'ease-in', fill: 'forwards' }
            );
            swapTimer = setTimeout(function () {
                filterCerts();
                tableWrap.style.height = 'auto';
                const endH = tableWrap.getBoundingClientRect().height;
                tableWrap.style.height = startH + 'px';
                tableWrap.getAnimations().forEach(function (a) { a.cancel(); });
                tableWrap.animate(
                    [{ height: startH + 'px', opacity: 0 }, { height: endH + 'px', opacity: 1 }],
                    { duration: 320, easing: 'cubic-bezier(.22, 1, .36, 1)' }
                ).onfinish = function () {
                    tableWrap.style.height = '';
                    tableWrap.style.overflowY = '';
                };
            }, 160);
        });
    });
    setTab(activeTab, false);

    // Telepono: liliit ang banner kapag nakadikit na sa itaas. Ang #certHeroMark (walang taas,
    // nasa itaas ng banner) ang tinitingnan - hindi ito gumagalaw kapag lumiit ang banner.
    (function () {
        const hero = document.getElementById('certHero');
        const mark = document.getElementById('certHeroMark');
        if (!hero || !mark) return;
        const mq = window.matchMedia('(max-width: 600px)');
        let ticking = false;
        function update() {
            ticking = false;
            const stick = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sp-stick-top')) || 0;
            const stuck = mq.matches && mark.getBoundingClientRect().top < stick - 4;
            // spSetStuck (connection/responsive.php): walang pagkislap kapag lumiit ang banner
            if (window.spSetStuck) window.spSetStuck(hero, stuck); else hero.classList.toggle('is-stuck', stuck);
        }
        window.addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(update); }
        }, { passive: true });
        window.addEventListener('resize', update);
        update();
    })();

    // "How to issue the official certificate": ipakita/itago ang mga hakbang
    // Pagbukas at pagsara ng "How to issue the official certificate".
    // Ang buong toolbar ang dahan-dahang lumalaki/lumiliit (kaya smooth din ang
    // table sa ilalim), habang dumudulas ang mga tab mula sa tabi ng "How to
    // issue" papunta sa ilalim ng mga hakbang - at pabalik kapag isinara.
    (function () {
        const toggle = document.getElementById('certStepsToggle');
        const panel = document.getElementById('certStepsPanel');
        const toolbar = document.querySelector('.cert-toolbar');
        const tabs = document.querySelector('.cert-tabs');
        const search = document.querySelector('.cert-search-box');
        if (!toggle || !panel || !toolbar || !tabs) return;
        // Mga gumagalaw kapag binuksan/isinara: ang mga tab at ang search bar
        const movers = [tabs, search].filter(Boolean);

        const DURATION = 450;
        const EASING = 'cubic-bezier(.4, 0, .2, 1)';
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches || !toolbar.animate;
        let running = [];

        // Ayos ng toolbar: bukas = may mga hakbang, at nasa kanan ng mga tab ang search
        function setLayout(open) {
            panel.hidden = !open;
            toolbar.classList.toggle('steps-open', open);
        }

        function stopAll() {
            running.forEach(function (a) { a.cancel(); });
            running = [];
            toolbar.style.overflow = '';
        }

        function rects() {
            return movers.map(function (el) { return el.getBoundingClientRect(); });
        }

        toggle.addEventListener('click', function () {
            const opening = panel.hidden;
            toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');

            if (reduce) {
                setLayout(opening);
                moveIndicator(false);
                return;
            }

            // Kunin ang kasalukuyang itsura (kahit nasa gitna pa ng animation)
            const startH = toolbar.getBoundingClientRect().height;
            const start = rects();
            stopAll();

            // Ilagay agad sa huling ayos para masukat ang dulo
            setLayout(opening);
            const endH = toolbar.getBoundingClientRect().height;
            const end = rects();

            // Pagsara: ibalik muna ang bukas na ayos habang gumagalaw; isasara sa dulo
            if (!opening) setLayout(true);
            const layout = rects();

            toolbar.style.overflow = 'hidden';
            const opts = { duration: DURATION, easing: EASING, fill: 'forwards' };

            running.push(toolbar.animate([{ height: startH + 'px' }, { height: endH + 'px' }], opts));
            movers.forEach(function (el, i) {
                running.push(el.animate([
                    { transform: 'translate(' + (start[i].left - layout[i].left) + 'px,' + (start[i].top - layout[i].top) + 'px)' },
                    { transform: 'translate(' + (end[i].left - layout[i].left) + 'px,' + (end[i].top - layout[i].top) + 'px)' }
                ], opts));
            });
            running.push(panel.firstElementChild.animate(
                opening
                    ? [{ opacity: 0, transform: 'translateY(-8px)' }, { opacity: 1, transform: 'none' }]
                    : [{ opacity: 1 }, { opacity: 0 }],
                { duration: opening ? DURATION : DURATION * 0.6, easing: 'ease', fill: 'forwards' }
            ));

            const main = running[0];
            main.onfinish = function () {
                if (main !== running[0]) return; // napalitan na ng bagong pindot
                // Sabay-sabay sa iisang sandali para walang talon
                if (!opening) setLayout(false);
                stopAll();
                moveIndicator(false);
            };
        });    })();</script>

<!-- ===== Feedback ng adopters (binubuksan ng "Service rating" sa banner) ===== -->
<div class="fbl-backdrop" id="fbList" role="dialog" aria-modal="true" aria-labelledby="fblTitle" hidden>
    <div class="fbl-box">
        <div class="fbl-head">
            <div>
                <h3 id="fblTitle">Adopter Feedback</h3>
                <p>How adopters rated the assistance they received at the City Veterinary Office</p>
            </div>
            <button type="button" class="fbl-close" id="fbClose" aria-label="Close">&#10005;</button>
        </div>

        <?php if ($rated > 0): ?>
            <div class="fbl-summary">
                <div class="fbl-avg"><?php echo $avgRating; ?></div>
                <div>
                    <div class="fbl-stars big">
                        <?php for ($s = 1; $s <= 5; $s++): ?><span class="<?php echo $s <= round($avgRating) ? 'on' : ''; ?>">&#9733;</span><?php endfor; ?>
                    </div>
                    <div class="fbl-count">Based on <?php echo $rated; ?> rating<?php echo $rated > 1 ? 's' : ''; ?></div>
                </div>
            </div>

            <ul class="fbl-items">
                <?php foreach ($feedbacks as $f): ?>
                    <li class="fbl-item">
                        <div class="fbl-item-top">
                            <div>
                                <strong><?php echo htmlspecialchars($f['full_name']); ?></strong>
                                <span class="fbl-pet"><?php echo htmlspecialchars($f['pet_name']); ?> &middot; <?php echo htmlspecialchars($f['pet_kind']); ?></span>
                            </div>
                            <div class="fbl-right">
                                <div class="fbl-stars">
                                    <?php for ($s = 1; $s <= 5; $s++): ?><span class="<?php echo $s <= (int) $f['fb_rating'] ? 'on' : ''; ?>">&#9733;</span><?php endfor; ?>
                                </div>
                                <span class="fbl-date"><?php echo $f['fb_date'] ? date('M j, Y', strtotime($f['fb_date'])) : ''; ?></span>
                            </div>
                        </div>
                        <?php if (!empty($f['fb_comment'])): ?>
                            <p class="fbl-comment">&ldquo;<?php echo htmlspecialchars($f['fb_comment']); ?>&rdquo;</p>
                        <?php else: ?>
                            <p class="fbl-comment is-empty">No comment</p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="fbl-empty">
                <div class="fbl-empty-icon" aria-hidden="true">&#9733;</div>
                <p>No feedback yet.</p>
                <span>Adopters can rate the service after they tap <strong>I Received</strong> on their certificate.</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    /* Napipindot na "Service rating" sa banner */
    .cert-hero-stat.is-link {
        font-family: inherit;
        color: inherit;
        border: none;
        cursor: pointer;
        transition: background .2s ease, transform .15s ease;
    }
    .cert-hero-stat.is-link:hover { background: rgba(255, 255, 255, 0.22); }
    .cert-hero-stat.is-link:active { transform: translateY(1px); }
    .cert-hero-stat.is-link:focus-visible { outline: 2px solid #bfdbfe; outline-offset: 2px; }
    .cert-hero-stat-hint {
        display: block;
        margin-top: 3px;
        font-size: 10.5px;
        font-style: normal;
        font-weight: 700;
        color: #bfdbfe;
    }

    /* Popup ng feedback */
    .fbl-backdrop {
        position: fixed;
        inset: 0;
        z-index: 5000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(12, 22, 52, 0.5);
        backdrop-filter: blur(3px);
        animation: fblFade .25s ease;
    }
    .fbl-backdrop[hidden] { display: none; }
    .fbl-box {
        width: 100%;
        max-width: 520px;
        max-height: calc(100vh - 32px);
        display: flex;
        flex-direction: column;
        border-radius: 20px;
        background: #fff;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
        animation: fblPop .4s cubic-bezier(.34, 1.56, .64, 1);
    }
    .fbl-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 20px 22px;
        color: #fff;
        background: linear-gradient(135deg, #172f73 0%, #1e3a8a 55%, #2f5bc4 100%);
    }
    .fbl-head h3 { margin: 0 0 3px; font-size: 19px; font-weight: 800; }
    .fbl-head p { margin: 0; font-size: 12.5px; color: rgba(255, 255, 255, 0.8); }
    .fbl-close {
        width: 32px;
        height: 32px;
        flex-shrink: 0;
        border: none;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
        font-size: 14px;
        cursor: pointer;
    }
    .fbl-close:hover { background: rgba(255, 255, 255, 0.28); }
    .fbl-summary {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px 22px;
        border-bottom: 1px solid #eef0f4;
        background: #f6f9ff;
    }
    .fbl-avg { font-size: 38px; font-weight: 800; color: #1e2a5a; line-height: 1; }
    .fbl-stars { display: inline-flex; gap: 2px; font-size: 14px; color: #d6d9dc; letter-spacing: 1px; }
    .fbl-stars.big { font-size: 20px; }
    .fbl-stars .on { color: #f5a623; }
    .fbl-count { margin-top: 3px; font-size: 12.5px; color: #6b7280; }
    .fbl-items {
        list-style: none;
        margin: 0;
        padding: 6px 22px 18px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #9fb3e6 transparent;
    }
    .fbl-item { padding: 14px 0; border-bottom: 1px solid #f1f2f5; }
    .fbl-item:last-child { border-bottom: none; }
    .fbl-item-top { display: flex; justify-content: space-between; gap: 12px; }
    .fbl-item-top strong { display: block; font-size: 14px; color: #102a2a; }
    .fbl-pet { display: block; margin-top: 2px; font-size: 12px; color: #9aa6a3; }
    .fbl-right { text-align: right; flex-shrink: 0; }
    .fbl-date { display: block; margin-top: 3px; font-size: 11.5px; color: #9aa6a3; }
    .fbl-comment {
        margin: 8px 0 0;
        padding: 9px 12px;
        border-radius: 10px;
        background: #fffaf0;
        border: 1px solid #f6e3bd;
        font-size: 13px;
        font-style: italic;
        line-height: 1.5;
        color: #4b5563;
    }
    .fbl-comment.is-empty { background: #f8f9fb; border-color: #eef0f4; color: #9aa6a3; }
    .fbl-empty { padding: 34px 22px 30px; text-align: center; color: #6b7280; }
    .fbl-empty-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 10px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        color: #f5a623;
        background: #fff4e0;
    }
    .fbl-empty p { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #102a2a; }
    .fbl-empty span { font-size: 13px; }
    @keyframes fblFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes fblPop { from { opacity: 0; transform: scale(.9) translateY(14px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
        .fbl-backdrop, .fbl-box { animation: none; }
    }
</style>
<script>
    (function () {
        const modal = document.getElementById('fbList');
        const openBtn = document.getElementById('fbOpen');
        if (!modal || !openBtn) return;
        function open() { modal.hidden = false; document.getElementById('fbClose').focus(); }
        function close() { modal.hidden = true; openBtn.focus(); }
        openBtn.addEventListener('click', open);
        document.getElementById('fbClose').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    })();
</script>

<?php if ($celebrate): ?>
    <!-- "Nice job!" pagkatapos pindutin ang Certificate Given -->
    <div class="celebrate-backdrop" id="celebrate" role="dialog" aria-modal="true" aria-labelledby="celebrateTitle">
        <div class="celebrate-box">
            <div class="celebrate-icon" aria-hidden="true">&#127881;</div>
            <h2 id="celebrateTitle">Nice job!</h2>
            <p>You handed the certificate of <strong><?php echo htmlspecialchars($celebrate['pet']); ?></strong> to <strong><?php echo htmlspecialchars($celebrate['adopter']); ?></strong>. Thank you for helping another pet find a home!</p>
            <button type="button" class="celebrate-ok" id="celebrateOk">Done</button>
        </div>
    </div>
    <script>
        (function () {
            var box = document.querySelector('.celebrate-box');
            var colors = ['#f59c23', '#1f6b48', '#3fae72', '#ffc46b', '#e53935', '#1a56db'];
            for (var i = 0; i < 26; i++) {
                var c = document.createElement('span');
                c.className = 'confetti';
                c.style.left = Math.random() * 100 + '%';
                c.style.background = colors[i % colors.length];
                c.style.animationDelay = (Math.random() * 0.6) + 's';
                c.style.transform = 'rotate(' + Math.random() * 360 + 'deg)';
                box.appendChild(c);
            }
            var backdrop = document.getElementById('celebrate');
            function close() { backdrop.remove(); }
            document.getElementById('celebrateOk').addEventListener('click', close);
            document.getElementById('celebrateOk').focus();
            backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.getElementById('celebrate')) close(); });
        })();
    </script>
<?php endif; ?>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
