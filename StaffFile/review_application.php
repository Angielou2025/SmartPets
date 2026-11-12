<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/match_scoring.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'])) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

// Admin ang nagbukas (mula sa Manage Adopters o global search) - doon dapat bumalik
// (at makita ang Admin sidebar), hindi maiwan sa loob ng Staff section. Ang ?from=admin
// (na idinadagdag ng AdminFile/review_application.php) ang pangunahing pinagbabatayan dahil iyon
// mismo ang totoong pinanggalingan ng click - mas mapagkakatiwalaan pa ito kaysa sa
// $_SESSION['role'], na maaaring 'Staff' pa rin kahit galing sa Admin section (hal. habang
// tine-test ang app gamit ang isang Staff account).
//
// Ang page na ito ang laman ng dalawang URL: ang sariling page ng Admin na
// /Caps2/AdminFile/review_application.php (na nag-require nito at nagtatakda
// ng from=admin), at ang sarili nitong URL para sa Staff. Kaya absolute ang
// action ng mga form sa ibaba - dapat tumama sa StaffFile/process_review_
// application.php kahit AdminFile ang naka-address bar.
$cameFromAdmin = ($_GET['from'] ?? '') === 'admin' || ($_POST['from'] ?? '') === 'admin';
$isAdminViewer = $cameFromAdmin || ($_SESSION['role'] ?? '') === 'Admin';
$backUrl = $isAdminViewer ? '/Caps2/AdminFile/Manage_adopters_file/manage_adopters.php' : 'applications.php';
$backLabel = $isAdminViewer ? 'Back to Manage Adopters' : 'Back to Applications';

$user_id = $_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT username, profile_image FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
$username = $current_user['username'] ?? 'Staff Member';
$profile_image = $current_user['profile_image'] ?? '';
$user_initials = strtoupper(substr($username, 0, 2));

$application_id = (int) ($_GET['application_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT aa.*, aa.match_score AS saved_match_score, aa.match_breakdown AS saved_match_breakdown,
            p.name AS pet_name, p.breed AS pet_breed, p.kind AS pet_kind, p.photo_url AS pet_photo,
            p.kind, p.breed, p.size, p.energy_level, p.age_group, p.gender, p.health_condition, p.temperament, p.shedding_level, p.description,
            u.username AS adopter_username, u.email AS adopter_email,
            ap.pref_kind, ap.pref_breed, ap.pref_temperament, ap.pref_energy_level, ap.pref_size, ap.pref_age_group, ap.pref_gender, ap.pref_health_condition,
            ap.living_space, ap.activity_level, ap.maintenance_level, ap.allergies
     FROM adoption_applications aa
     JOIN pet_inventory p ON aa.pet_id = p.pet_id
     JOIN users u ON aa.adopter_id = u.user_id
     LEFT JOIN adopter_preferences ap ON ap.user_id = aa.adopter_id
     WHERE aa.application_id = ?"
);
$stmt->execute([$application_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header('Location: ' . $backUrl);
    exit();
}

// Data Privacy Notice: hindi ipapakita ang laman ng application (valid ID, mga
// dokumento, personal na impormasyon) hangga't hindi pumapayag ang Admin/Staff
// na hindi ito gagamitin sa ibang layunin (RA 10173). Itinatala ang pagsang-ayon.
require_once __DIR__ . '/../connection/privacy_ack.php';
sp_privacy_gate($pdo, $application, $backUrl);

// Match % at breakdown na naka-freeze noong nag-submit ang adopter - hindi
// nagbabago kahit baguhin pa niya ang preferences pagkatapos
$frozenMatch = sp_application_match($pdo, $application);
$matchScore = $frozenMatch['score'];
$matchBreakdown = $frozenMatch['breakdown'];

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$photoFields = [
    'photo_front_house' => 'Front of House',
    'photo_street' => 'Street View',
    'photo_living_room' => 'Living Room',
    'photo_dining_area' => 'Dining Area',
    'photo_kitchen' => 'Kitchen',
    'photo_bedrooms' => 'Bedroom',
    'photo_windows' => 'Windows',
    'photo_backyard' => 'Backyard',
];

$isDecided = in_array($application['application_status'], ['Approved', 'Rejected']);

// Mga hakbang bago ma-approve (connection/application_flow.php) at kung puwede na
require_once __DIR__ . '/../connection/application_flow.php';
require_once __DIR__ . '/../connection/interview_summary.php';
require_once __DIR__ . '/../connection/signature_pad.php';
$flowSteps = sp_app_flow_steps($pdo, $application);
$canApprove = sp_app_can_approve($flowSteps);
$nextStep = sp_app_next_step($flowSteps);
// Pinilit i-approve kahit hindi pa tapos: buksan agad ang gabay
$approveBlocked = !empty($_SESSION['approve_blocked']);
unset($_SESSION['approve_blocked']);

// Badge ng unread messages sa sidebar. Kailangan ito para kapareho ng sidebar
// sa ibang Admin page - doon ay dala ito ng bawat page mismo.
$sp_unread_count = sp_unread_message_count($pdo, (int) $user_id);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review Application - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <!-- Icons: kailangan ng Staff at Admin (section headers, carousel, attendance) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .review-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
            margin-top: 20px;
        }
        .review-section {
            margin-bottom: 24px;
        }
        .review-section h3 {
            font-size: 15px;
            font-weight: 800;
            color: #102a2a;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f1f1f1;
        }
        .review-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .review-field label {
            display: block;
            font-size: 12px;
            color: #9aa6a3;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .review-field p {
            font-size: 14px;
            color: #102a2a;
            margin: 0;
            overflow-wrap: anywhere;
        }
        .review-link {
            color: #1f6b48;
            font-weight: 600;
            text-decoration: none;
        }
        .review-link:hover {
            text-decoration: underline;
        }
        .review-link i {
            font-size: 11px;
            margin-left: 4px;
            opacity: .75;
        }
        .photo-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 14px;
        }
        .photo-gallery figure {
            margin: 0;
        }
        .photo-gallery img {
            width: 100%;
            height: 110px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #eee;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .photo-gallery img:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 14px rgba(0,0,0,0.15);
        }
        /* ---- Photo viewer: i-click/scroll para mag-zoom, i-drag para gumalaw ---- */
        .pv-overlay {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            background: rgba(6, 10, 8, 0.97);
            color: #fff;
            animation: pvFade .2s ease;
        }
        .pv-overlay[hidden] { display: none; }
        @keyframes pvFade { from { opacity: 0; } to { opacity: 1; } }
        .pv-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 18px;
            background: rgba(0, 0, 0, 0.35);
            flex-shrink: 0;
        }
        .pv-info { display: flex; align-items: baseline; gap: 10px; min-width: 0; }
        .pv-info strong { font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pv-info span { font-size: 13px; opacity: .7; white-space: nowrap; }
        .pv-tools { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
        .pv-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 38px;
            height: 38px;
            padding: 0 10px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color .15s ease;
        }
        .pv-btn:hover { background: rgba(255, 255, 255, 0.18); }
        .pv-btn:focus-visible { outline: 2px solid #8fd3ae; outline-offset: 2px; }
        .pv-btn.pv-close { margin-left: 6px; background: rgba(255, 255, 255, 0.14); }
        .pv-zoom-level { min-width: 52px; text-align: center; font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .pv-stage {
            position: relative;
            flex: 1;
            min-height: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 16px 72px;
            touch-action: none;
            cursor: zoom-in;
        }
        .pv-overlay.is-zoomed .pv-stage { cursor: grab; }
        .pv-overlay.is-dragging .pv-stage { cursor: grabbing; }
        .pv-stage img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 6px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            transform-origin: center center;
            transition: transform .18s ease;
            user-select: none;
            -webkit-user-drag: none;
        }
        .pv-overlay.is-dragging .pv-stage img { transition: none; }
        .pv-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 46px;
            height: 46px;
            border-radius: 50%;
            border: none;
            background: rgba(255, 255, 255, 0.9);
            color: #102a2a;
            font-size: 17px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
            z-index: 2;
        }
        .pv-nav:hover { background: #fff; }
        .pv-nav.prev { left: 14px; }
        .pv-nav.next { right: 14px; }
        .pv-nav[hidden] { display: none; }
        .pv-hint {
            flex-shrink: 0;
            padding: 10px 16px 14px;
            text-align: center;
            font-size: 12.5px;
            opacity: .7;
        }
        @media (max-width: 640px) {
            .pv-bar { flex-wrap: wrap; padding: 10px 12px; }
            .pv-stage { padding: 10px; }
            .pv-nav { width: 38px; height: 38px; }
            .pv-btn .pv-btn-text { display: none; }
        }
        .photo-gallery figcaption {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
            text-align: center;
        }

        /* ---- Mga section na bumubukas kapag pinindot ---- */
        .rv-collapse {
            border: 1.5px solid #ece6d9;
            border-radius: 14px;
            margin-bottom: 14px;
            background: #fff;
            overflow: hidden;
            transition: box-shadow .2s ease, border-color .2s ease;
        }
        .rv-collapse[open] {
            border-color: #cfe3d6;
            box-shadow: 0 10px 24px rgba(16, 24, 40, 0.06);
        }
        .rv-summary {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 18px;
            cursor: pointer;
            list-style: none;
            user-select: none;
            transition: background .2s ease;
        }
        .rv-summary::-webkit-details-marker { display: none; }
        .rv-summary::marker { content: ''; }
        .rv-summary:hover { background: #fbf8f1; }
        .rv-collapse[open] > .rv-summary {
            background: linear-gradient(135deg, #2a8a5e 0%, #1f6b48 100%);
            color: #fff;
        }
        .rv-icon {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border-radius: 11px;
            background: #eaf3ee;
            color: #1f6b48;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }
        .rv-collapse[open] .rv-icon { background: rgba(255, 255, 255, 0.18); color: #fff; }
        .rv-heading { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .rv-title { font-size: 15.5px; font-weight: 800; color: #102a2a; }
        .rv-hint { font-size: 12.5px; color: #6b7280; }
        .rv-collapse[open] .rv-title { color: #fff; }
        .rv-collapse[open] .rv-hint { color: rgba(255, 255, 255, 0.82); }
        .rv-chev { color: #8a9794; transition: transform .25s ease; }
        .rv-collapse[open] .rv-chev { transform: rotate(180deg); color: #fff; }
        .rv-body {
            padding: 18px;
            animation: rvBodyIn .25s ease;
        }
        @keyframes rvBodyIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: none; }
        }

        /* ---- Carousel ng mga larawan ---- */
        .rv-carousel { max-width: 920px; margin: 0 auto; }
        .rv-car-stage {
            position: relative;
            overflow: hidden;
            border-radius: 14px;
            background: #111814;
            outline: none;
        }
        .rv-car-stage:focus-visible { box-shadow: 0 0 0 3px #f59c23; }
        .rv-car-track {
            display: flex;
            transition: transform .35s ease;
            touch-action: pan-y;
        }
        .rv-car-slide {
            flex: 0 0 100%;
            margin: 0;
            position: relative;
        }
        .rv-car-slide img {
            width: 100%;
            height: 480px;
            object-fit: contain;
            display: block;
            cursor: zoom-in;
            user-select: none;
            -webkit-user-drag: none;
        }
        .rv-car-slide figcaption {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 26px 16px 12px;
            background: linear-gradient(0deg, rgba(0,0,0,0.7), rgba(0,0,0,0));
            color: #fff;
            font-weight: 700;
            font-size: 14px;
        }
        .rv-car-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: none;
            background: rgba(255, 255, 255, 0.92);
            color: #102a2a;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            transition: background .2s ease, transform .15s ease;
            z-index: 2;
        }
        .rv-car-nav:hover { background: #fff; transform: translateY(-50%) scale(1.08); }
        .rv-car-nav.prev { left: 12px; }
        .rv-car-nav.next { right: 12px; }
        .rv-car-count,
        .rv-car-zoom {
            position: absolute;
            top: 12px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.55);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            z-index: 2;
            pointer-events: none;
        }
        .rv-car-count { right: 12px; }
        .rv-car-zoom { left: 12px; font-weight: 600; }
        .rv-car-thumbs {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            overflow-x: auto;
            padding: 2px 2px 6px;
            scrollbar-width: thin;
        }
        /* Thumbnail na may pangalan ng larawan sa ilalim */
        .rv-car-thumb {
            flex: 0 0 auto;
            width: 118px;
            padding: 0;
            border: 2px solid transparent;
            border-radius: 11px;
            overflow: hidden;
            cursor: pointer;
            background: #f3f4f6;
            opacity: 0.7;
            text-align: left;
            font-family: inherit;
            transition: opacity .2s ease, border-color .2s ease;
        }
        .rv-car-thumb img { width: 100%; height: 72px; object-fit: cover; display: block; }
        .rv-car-thumb span {
            display: block;
            padding: 5px 8px 6px;
            font-size: 11.5px;
            font-weight: 700;
            color: #374151;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .rv-car-thumb:hover { opacity: 0.95; }
        .rv-car-thumb.active { opacity: 1; border-color: #1f6b48; background: #fff; }

        @media (max-width: 640px) {
            .rv-summary { padding: 14px; gap: 12px; }
            .rv-body { padding: 14px; }
            .rv-car-slide img { height: 260px; }
            .rv-car-nav { width: 36px; height: 36px; }
            .rv-car-zoom { display: none; }
        }
        .badge-app {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-pending { background-color: #fef3d6; color: #b25900; }
        .badge-review { background-color: #e8f0fe; color: #1a73e8; }
        .badge-approved { background-color: #e6f4ea; color: #1e7e34; }
        .badge-rejected { background-color: #fce8e6; color: #c5221f; }
        .review-actions {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .review-actions form {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .review-actions textarea {
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            min-width: 240px;
            min-height: 40px;
            font-family: inherit;
        }
        .btn-action {
            display: inline-block;
            background: #1f6b48;
            border: 1px solid #1f6b48;
            color: #fff;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-reject {
            background: transparent;
            color: #c5221f;
            border: 1px solid #c5221f;
        }

        .meeting-link-box {
            margin-top: 16px;
            padding: 14px 16px;
            background: #f6faf7;
            border: 1px solid #dcebe0;
            border-radius: 10px;
        }
        .meeting-link-box > label {
            display: block;
            font-size: 12px;
            color: #9aa6a3;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .meeting-link-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .meeting-link-form input[name="meeting_link"] {
            flex: 1 1 240px;
            min-width: 0;
            max-width: 100%;
            box-sizing: border-box;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
        }
        .meeting-link-box .review-link {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        @media (max-width: 600px) {
            .meeting-link-box { padding: 12px; }
            .meeting-link-form { flex-direction: column; }
            .meeting-link-form input[name="meeting_link"] { flex: none; width: 100%; font-size: 16px; }
            .meeting-link-form .btn-action { width: 100%; }
        }
        .meeting-link-note {
            margin: 8px 0 0;
            font-size: 12.5px;
            color: #6b7280;
        }
        .meeting-link-note.sent {
            color: #1f6b48;
            font-weight: 600;
        }

        .attendance-box {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            margin-top: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
        }
        .attendance-box i { font-size: 18px; margin-top: 2px; }
        .attendance-box p { margin: 2px 0 0; color: #4b5563; }
        .attendance-box.missed { background: #fdecea; border: 1px solid #f5c2bd; color: #b3261e; }
        .attendance-box.rescheduled { background: #eef6ff; border: 1px solid #c9dffb; color: #1a5fb4; }
        .attendance-form {
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .attendance-form .meeting-link-note { margin: 0; flex: 1; min-width: 220px; }

        /* Interview request ng adopter (Accept / Decline) */
        .schedule-request-box { margin-top: 14px; padding: 14px 16px; border-radius: 10px; font-size: 13px; }
        .schedule-request-box.pending { background: #fff8e6; border: 1px solid #f6d58a; color: #8a5a00; }
        .schedule-request-box.declined { background: #fdecea; border: 1px solid #f5c2bd; color: #b3261e; }
        .schedule-request-box.accepted { background: #e6f4ea; border: 1px solid #b7dfc3; color: #1e7e34; }
        .srb-head { display: flex; gap: 12px; align-items: flex-start; }
        .srb-head i { font-size: 18px; margin-top: 2px; }
        .srb-head p { margin: 2px 0 0; color: #4b5563; }
        .srb-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .srb-actions form { margin: 0; }
        .srb-decline { margin-top: 12px; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .srb-decline[hidden] { display: none; }
        .srb-decline label { font-weight: 700; color: #374151; }
        .srb-decline label span { font-weight: 400; color: #6b7280; }
        .srb-decline textarea {
            width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 13px; font-family: inherit; resize: vertical;
        }

        .match-total-banner {
            background: linear-gradient(135deg, #1f6b48, #165236);
            color: #fff;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .match-total-banner .score { font-size: 28px; font-weight: 800; }
        .match-breakdown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .match-breakdown-table th {
            text-align: left;
            padding: 8px 10px;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f1f1;
        }
        .match-breakdown-table td {
            padding: 9px 10px;
            border-bottom: 1px solid #f8f8f8;
            vertical-align: middle;
        }
        .fit-bar-track {
            width: 80px;
            height: 6px;
            background: #f1f1f1;
            border-radius: 3px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
            margin-right: 8px;
        }
        .fit-bar-fill {
            height: 100%;
            background: #1f6b48;
            border-radius: 3px;
        }
        .fit-bar-fill.low { background: #c5221f; }
        .fit-bar-fill.mid { background: #b25900; }
<?php if ($isAdminViewer): ?>

        /* ---------------------------------------------------------------
           Admin ang nanonood, pero nananatili sa <body> ang klaseng
           staff-dashboard-root dahil naka-scope doon ang Match Breakdown
           modal (nasa labas ito ng .app-container, kaya hindi pwedeng
           ilipat ang klase sa <main>).

           Ang kaso: mas malakas ang `.staff-dashboard-root .sidebar` (dalawang
           class) kaysa sa `.sidebar` ng AdminFile/style.css (isang class),
           kaya asul at nakapirmi ang sidebar kahit Admin ang laman nito.
           Ibinabalik ng block na ito ang itsurang Admin - `body.sp-admin-view`
           ang selector, mas espesipiko pa sa staff, at huli pang nakasulat.
           Walang naaapektuhan sa panig ng Staff: kapag Staff ang nanonood,
           hindi man lang naipi-print ang block na ito.
           --------------------------------------------------------------- */
        body.sp-admin-view .app-container { display: flex; min-height: 100vh; }

        body.sp-admin-view .sidebar {
            width: 260px;
            min-width: 260px;
            /* Kailangan talagang banggitin ang max-width: may media query sa
               staff_dashboard.css (min-width: 901px) na nagtatakda ng
               max-width: 220px. Kung hindi ito babaguhin, 220px pa rin ang
               lumalabas kahit 260px ang width - kaya lumiliit ang sidebar
               tuwing bubuksan ang page na ito mula sa Requests. */
            max-width: none;
            background: var(--sidebar-bg, #ffffff);
            color: inherit;
            margin-right: 12px;
            padding: 20px 15px;
            border-top-right-radius: 40px;
            box-shadow: none;
            position: sticky;
            top: 0;
            left: auto;
            bottom: auto;
            height: 100vh;
            align-self: flex-start;
            overflow-y: auto;
            z-index: auto;
        }

        body.sp-admin-view .sidebar .brand { margin-bottom: 30px; }

        body.sp-admin-view .sidebar .logo-circle {
            background: transparent;
            margin: 3px;
            padding: 2px;
            border: 1px solid green;
            box-shadow: none;
        }

        body.sp-admin-view .sidebar h2 { font-size: 20px; color: #000; letter-spacing: normal; }
        body.sp-admin-view .sidebar h2 span { color: var(--accent-orange, #f4a261); }

        body.sp-admin-view .sidebar a {
            gap: 12px;
            padding: 12px 18px;
            margin: 0;
            color: var(--primary-green, #2d6a4f);
            font-weight: 600;
            /* Walang font-size ang .menu-item ng admin, kaya 16px ang mana
               nito. Kailangang isulat nang tahasan para matabunan ang 15px
               ng staff - kung hindi, mas maliit ang teksto rito kaysa sa
               ibang admin page. */
            font-size: 16px;
            border-radius: 20px;
            box-shadow: none;
            transform: none;
        }

        body.sp-admin-view .sidebar a:hover {
            background: #f0f0f0;
            color: var(--primary-green, #2d6a4f);
            transform: none;
            box-shadow: none;
        }

        body.sp-admin-view .sidebar a.active,
        body.sp-admin-view .sidebar a.menu-item.active {
            background: var(--bg-main, #f5e7c6);
            color: #000;
            font-weight: 600;
        }

        body.sp-admin-view .sidebar-menu { display: flex; flex-direction: column; gap: 8px; }
        body.sp-admin-view .sidebar-footer { margin-top: auto; display: flex; flex-direction: column; gap: 6px; }
        body.sp-admin-view .city-illustration { line-height: 0; }
        body.sp-admin-view .city-illustration img { width: 100%; height: auto; max-height: 170px; border-radius: 10px; object-fit: contain; }

        body.sp-admin-view .main-wrapper {
            flex: 1;
            min-width: 0;
            margin-left: 0;
            /* Katulad ng sidebar: inaalis ang calc(100% - 220px) na itinatakda
               ng staff media query, dahil wala nang fixed na sidebar dito. */
            max-width: 100%;
            padding: 0;
            background: var(--bg-main, #f5e7c6);
            border-top-left-radius: 30px;
        }

        body.sp-admin-view .top-header {
            background: #ffffff;
            height: 65px;
            padding: 0 40px;
            margin-bottom: 0;
            border-bottom: 2px solid rgba(0, 0, 0, 0.05);
            border-top-left-radius: 30px;
        }
<?php endif; ?>

        /* ===== Application Progress (mga hakbang bago ma-approve) ===== */
        .flow-panel {
            margin-top: 14px; padding: 12px 16px 14px; border-radius: 14px; background: #fff;
            border: 1px solid #e8ecf3; box-shadow: 0 6px 16px rgba(15, 27, 61, 0.04);
        }
        .flow-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
        .flow-head h3 { margin: 0 0 1px; font-size: 14px; font-weight: 800; color: #0f1b3d; }
        .flow-head p { margin: 0; font-size: 12px; color: #5b6472; line-height: 1.45; max-width: 760px; }
        .flow-head p strong { color: #1e3a8a; }
        .flow-next-btn {
            display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border-radius: 8px; flex-shrink: 0;
            background: linear-gradient(135deg, #2a62de, #1e3a8a); color: #fff; font-size: 12.5px; font-weight: 700; text-decoration: none;
            box-shadow: 0 4px 10px rgba(30, 58, 138, 0.22); transition: transform .15s ease, filter .2s ease;
        }
        .flow-next-btn:hover { transform: translateY(-1px); filter: brightness(1.08); color: #fff; }
        .flow-next-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

        .flow-steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; counter-reset: none; }
        .flow-step { position: relative; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 3px; padding: 0 2px; }
        /* Guhit na nagdudugtong sa mga hakbang */
        .flow-step + .flow-step::before {
            content: ''; position: absolute; top: 12px; right: calc(50% + 15px); width: calc(100% - 24px); height: 2px;
            border-radius: 2px; background: #e3e8f0;
        }
        .flow-step.is-done + .flow-step::before { background: #22a05a; }
        .flow-dot {
            position: relative; z-index: 1; width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; background: #f1f4f8; color: #8a94a6; border: 2px solid #e3e8f0;
        }
        .flow-dot svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.8; stroke-linecap: round; stroke-linejoin: round; }
        .flow-label { font-size: 11px; font-weight: 700; color: #4b5563; line-height: 1.25; }
        .flow-state { font-size: 9.5px; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; color: #a3adbd; }
        .flow-step.is-done .flow-dot { background: #22a05a; border-color: #22a05a; color: #fff; }
        .flow-step.is-done .flow-state { color: #15803d; }
        .flow-step.is-current .flow-dot { background: #1e3a8a; border-color: #1e3a8a; color: #fff; box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15); animation: flowPulse 2s ease-in-out infinite; }
        .flow-step.is-current .flow-label { color: #0f1b3d; }
        .flow-step.is-current .flow-state { color: #1e3a8a; }
        .flow-step.is-waiting .flow-dot { background: #fff7e6; border-color: #f5b83d; color: #b45309; }
        .flow-step.is-waiting .flow-state { color: #b45309; }
        .flow-step.is-blocked .flow-dot { background: #fdecea; border-color: #f19a91; color: #c5221f; }
        .flow-step.is-blocked .flow-state { color: #c5221f; }
        @keyframes flowPulse { 0%, 100% { box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15); } 50% { box-shadow: 0 0 0 6px rgba(30, 58, 138, 0.06); } }
        @media (max-width: 900px) {
            .flow-steps { grid-template-columns: 1fr; gap: 10px; }
            .flow-step { flex-direction: row; text-align: left; gap: 12px; }
            .flow-step + .flow-step::before { display: none; }
            .flow-state { margin-left: auto; }
        }
        @media (prefers-reduced-motion: reduce) { .flow-step.is-current .flow-dot { animation: none; } }

        /* Approve na naka-lock (hindi pa tapos ang mga hakbang) */
        .btn-approve-locked { display: inline-flex; align-items: center; gap: 6px; opacity: .75; }
        .btn-approve-locked svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

        /* ===== Pop-up na gabay sa pag-approve ===== */
        .ag-overlay { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(2px); animation: agFade .2s ease; }
        .ag-overlay[hidden] { display: none; }
        .ag-card { position: relative; width: 100%; max-width: 560px; max-height: calc(100vh - 32px); overflow-y: auto; background: #fff; border-radius: 18px; padding: 26px 24px 20px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28); animation: agRise .25s cubic-bezier(.22, 1, .36, 1); }
        @keyframes agFade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes agRise { from { opacity: 0; transform: translateY(12px) scale(.98); } to { opacity: 1; transform: none; } }
        .ag-close { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border-radius: 50%; border: none; background: #f3f4f6; color: #4b5563; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .ag-close:hover { background: #e5e7eb; }
        .ag-close svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; }
        .ag-icon { width: 56px; height: 56px; border-radius: 16px; margin-bottom: 12px; display: flex; align-items: center; justify-content: center; background: #fff4e5; color: #d97706; }
        .ag-icon svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .ag-card h3 { margin: 0 0 6px; font-size: 19px; font-weight: 800; color: #0f1b3d; }
        .ag-lead { margin: 0 0 16px; font-size: 13.5px; color: #5b6472; line-height: 1.5; }
        .ag-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .ag-step { display: flex; align-items: flex-start; gap: 12px; padding: 10px 12px; border-radius: 12px; border: 1px solid #edf0f4; background: #fafbfc; }
        .ag-step.is-current { border-color: #c9d6f2; background: #f3f6fd; }
        .ag-step.is-blocked { border-color: #f6cbc6; background: #fff6f5; }
        .ag-num { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; background: #e8ecf3; color: #6b7280; }
        .ag-num svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.8; stroke-linecap: round; stroke-linejoin: round; }
        .ag-step.is-done .ag-num { background: #22a05a; color: #fff; }
        .ag-step.is-current .ag-num { background: #1e3a8a; color: #fff; }
        .ag-step.is-waiting .ag-num { background: #fde7b0; color: #92400e; }
        .ag-step.is-blocked .ag-num { background: #fbd5d1; color: #b42318; }
        .ag-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .ag-text strong { font-size: 13.5px; color: #0f1b3d; }
        .ag-text span { font-size: 12.5px; color: #6b7280; line-height: 1.45; }
        .ag-step.is-done .ag-text strong { color: #6b7280; text-decoration: line-through; text-decoration-color: rgba(107, 114, 128, .5); }
        .ag-tag { flex-shrink: 0; font-size: 10.5px; font-weight: 800; padding: 3px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: .3px; }
        .ag-tag.done { background: #dcfce7; color: #166534; }
        .ag-tag.current { background: #1e3a8a; color: #fff; }
        .ag-tag.waiting { background: #fef3c7; color: #92400e; }
        .ag-tag.blocked { background: #fde2df; color: #b42318; }
        .ag-note { display: flex; gap: 8px; align-items: flex-start; margin: 14px 0 0; padding: 10px 12px; border-radius: 10px; background: #eef2fb; color: #1e3a8a; font-size: 12.5px; line-height: 1.45; }
        .ag-note svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
        .ag-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        .ag-btn { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 10px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; text-decoration: none; }
        .ag-btn.ghost { background: #fff; border: 1.5px solid #e2e5ea; color: #374151; }
        .ag-btn.ghost:hover { background: #f3f4f6; }
        .ag-btn.primary { background: linear-gradient(135deg, #2a62de, #1e3a8a); border: none; color: #fff; }
        .ag-btn.primary:hover { filter: brightness(1.08); color: #fff; }
        .ag-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

        /* ===================== Propesyonal na itsura (kulay ng staff) ===================== */
        .review-panel { padding: 18px; border: 1px solid #eef0f3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05); }

        /* Mga section na bumubukas */
        .rv-collapse { border-color: #e6eaf1 !important; border-radius: 14px !important; overflow: hidden; transition: box-shadow .2s ease, border-color .2s ease; }
        .rv-collapse:hover { border-color: #d3dcec !important; }
        .rv-collapse[open] { border-color: #c9d6f2 !important; box-shadow: 0 10px 24px rgba(30, 58, 138, 0.08) !important; }
        .rv-summary:hover { background: #f7f9fe !important; }
        .rv-collapse[open] > .rv-summary { background: linear-gradient(135deg, #172f6e 0%, #1e40af 60%, #2f5bc4 100%) !important; }
        .rv-icon { background: #eef2fb !important; color: #1e3a8a !important; border-radius: 11px !important; }
        .rv-collapse[open] .rv-icon { background: rgba(255, 255, 255, 0.16) !important; color: #fff !important; }
        .rv-title { color: #0f1b3d !important; }
        .rv-collapse[open] .rv-title { color: #fff !important; }
        .rv-body { background: #fff; }
        .review-field label { color: #8a94a6 !important; letter-spacing: .4px; font-size: 11px !important; }
        .review-field p { font-weight: 600; }
        .rv-car-thumb.active { border-color: #2f5bc4 !important; }
        .rv-car-stage:focus-visible { box-shadow: 0 0 0 3px #2f5bc4 !important; }

        /* Hiling na iskedyul / tinanggap / tinanggihan */
        .schedule-request-box { border-radius: 12px !important; padding: 14px 16px !important; }
        .schedule-request-box.pending { background: #fffaf0 !important; border: 1px solid #f5d999 !important; border-left: 4px solid #f5b83d !important; color: #8a5a00 !important; }
        .schedule-request-box.accepted { background: #f0fbf4 !important; border: 1px solid #b7e3c6 !important; border-left: 4px solid #22a05a !important; color: #166534 !important; }
        .schedule-request-box.declined { background: #fff6f5 !important; border: 1px solid #f6cbc6 !important; border-left: 4px solid #e05545 !important; color: #b42318 !important; }
        .srb-head strong { font-size: 14px; }
        .srb-head p { color: #4b5563 !important; }

        /* Mga button sa loob ng page */
        .btn-action {
            display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px !important; border-radius: 10px !important;
            background: linear-gradient(135deg, #2a62de, #1e3a8a) !important; border: none !important; color: #fff !important;
            font-weight: 700 !important; box-shadow: 0 4px 10px rgba(30, 58, 138, 0.2); transition: transform .15s ease, filter .2s ease !important;
        }
        .btn-action:hover { transform: translateY(-1px); filter: brightness(1.08); }
        .btn-action.btn-reject { background: #fff !important; border: 1.5px solid #f3b4ae !important; color: #c5221f !important; box-shadow: none; }
        .btn-action.btn-reject:hover { background: #fdecea !important; filter: none; }

        /* Meeting link */
        .meeting-link-box { background: #f8faff !important; border: 1px solid #dfe7f6 !important; border-radius: 12px !important; padding: 14px 16px !important; }
        .meeting-link-box > label { color: #1e3a8a !important; letter-spacing: .4px; }
        .meeting-link-form input[name="meeting_link"] {
            height: 40px; border: 1.5px solid #dbe3f4 !important; border-radius: 10px !important; background: #fff !important;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .meeting-link-form input[name="meeting_link"]:focus { outline: none; border-color: #2a62de !important; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        .meeting-link-note { color: #6b7280 !important; }
        .meeting-link-note.sent { color: #15803d !important; }
        .meeting-link-note.default { color: #1e3a8a !important; overflow-wrap: anywhere; }
        .btn-meet-new {
            height: 40px; padding: 0 14px; border-radius: 10px; border: 1.5px solid #dbe3f4; background: #fff; color: #1e3a8a;
            display: inline-flex; align-items: center; justify-content: center; gap: 7px; white-space: nowrap;
            font-size: 13px; font-weight: 700; text-decoration: none; box-sizing: border-box; transition: background .15s, border-color .15s;
        }
        .btn-meet-new svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linejoin: round; }
        .btn-meet-new:hover { background: #eef2fb; border-color: #c7d2fe; }
        .meet-default-check { flex-basis: 100%; display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #374151; cursor: pointer; }
        .meet-default-check input { width: 16px; height: 16px; accent-color: #2a62de; margin: 0; }
        body.sp-admin-view .btn-meet-new { color: #1f6b48; border-color: #cfe5d8; }
        body.sp-admin-view .meet-default-check input { accent-color: #1f6b48; }
        @media (max-width: 600px) { .btn-meet-new { width: 100%; } }

        /* ===== Desisyon ===== */
        .decision-card { margin-top: 18px; padding: 18px; border-radius: 14px; border: 1px solid #e6eaf1; background: #fff; }
        .decision-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .decision-icon { width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .decision-icon svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .decision-head h3 { margin: 0; font-size: 16px; font-weight: 800; color: #0f1b3d; border: none !important; padding: 0 !important; }
        .decision-head p { margin: 2px 0 0; font-size: 12.5px; color: #6b7280; }
        .decision-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .decision-box { display: flex; flex-direction: column; gap: 10px; padding: 14px; border-radius: 12px; border: 1px solid #e6eaf1; background: #fafbfc; margin: 0; }
        .decision-box.approve.is-ready { background: #f0fbf4; border-color: #b7e3c6; }
        .decision-box.reject { background: #fffafa; border-color: #f6dcd8; }
        .decision-box-text { display: flex; flex-direction: column; gap: 3px; }
        .decision-box-text strong { font-size: 14px; color: #0f1b3d; }
        .decision-box-text span { font-size: 12.5px; color: #6b7280; line-height: 1.45; }
        .dc-label { font-size: 12px; font-weight: 700; color: #374151; margin-bottom: -4px; }
        .decision-box textarea {
            width: 100%; box-sizing: border-box; min-height: 74px; resize: vertical; padding: 10px 12px; border-radius: 10px;
            border: 1.5px solid #ecd2ce; background: #fff; font-family: inherit; font-size: 13.5px; color: #1f2937;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .decision-box textarea:focus { outline: none; border-color: #e05545; box-shadow: 0 0 0 3px rgba(224, 85, 69, 0.12); }
        .dc-btn {
            align-self: flex-start; display: inline-flex; align-items: center; gap: 7px; height: 40px; padding: 0 18px;
            border-radius: 10px; font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer; margin-top: auto;
            transition: transform .15s ease, filter .2s ease, background-color .2s ease;
        }
        .dc-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
        .dc-approve { border: none; background: linear-gradient(135deg, #22a05a, #15803d); color: #fff; box-shadow: 0 6px 14px rgba(21, 128, 61, 0.25); }
        .dc-approve:hover { transform: translateY(-1px); filter: brightness(1.06); }
        .dc-approve.btn-approve-locked { background: #e5e9f0; color: #6b7280; box-shadow: none; opacity: 1; }
        .dc-approve.btn-approve-locked:hover { background: #dbe1ea; transform: none; filter: none; }
        .dc-reject { border: 1.5px solid #f3b4ae; background: #fff; color: #c5221f; }
        .dc-reject:hover { background: #fdecea; }
        @media (max-width: 760px) { .decision-grid { grid-template-columns: 1fr; } }

        /* ===== Header ng application (Back link + title card) ===== */
        .ah-back {
            display: inline-flex; align-items: center; justify-content: center; align-self: flex-start;
            width: 36px; height: 36px; flex: 0 0 36px; box-sizing: border-box; margin: 4px 0 12px; padding: 0;
            border-radius: 10px; background: #fff; border: 1px solid #e3e8f0; color: #1e3a8a;
            text-decoration: none; box-shadow: 0 2px 6px rgba(15, 27, 61, 0.05); transition: background .2s ease, transform .15s ease, box-shadow .2s ease;
        }
        .ah-back i { font-size: 14px; transition: transform .2s ease; }
        .ah-back:hover { background: #f3f6fd; box-shadow: 0 4px 12px rgba(30, 58, 138, 0.12); }
        .ah-back:hover i { transform: translateX(-3px); }

        .ah-card {
            position: relative; display: flex; align-items: center; gap: 18px; padding: 18px 20px; margin-bottom: 14px;
            border-radius: 16px; background: #fff; border: 1px solid #e8ecf3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.06); overflow: hidden;
        }
        .ah-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: linear-gradient(180deg, #2a62de, #1e3a8a); }
        .ah-photo {
            width: 72px; height: 72px; flex-shrink: 0; border-radius: 16px; overflow: hidden; background: #eef2fb; color: #1e3a8a;
            display: flex; align-items: center; justify-content: center; font-size: 28px; border: 3px solid #fff; box-shadow: 0 4px 12px rgba(15, 27, 61, 0.12);
        }
        .ah-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ah-main { flex: 1; min-width: 0; }
        .ah-title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .ah-title-row h1 { margin: 0; font-size: 22px; font-weight: 800; color: #0f1b3d; letter-spacing: -.2px; }
        .ah-sub { margin: 4px 0 8px; font-size: 14px; color: #5b6472; }
        .ah-sub strong { color: #0f1b3d; }
        .ah-meta { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px 8px; }
        .ah-meta li {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: #f4f6fa;
            font-size: 12px; font-weight: 600; color: #4b5563; max-width: 100%; overflow-wrap: anywhere;
        }
        .ah-meta i { color: #6b7fae; font-size: 11px; }
        .ah-side { flex-shrink: 0; }
        .ah-match {
            display: flex; align-items: center; gap: 12px; padding: 10px 14px 10px 10px; border-radius: 14px; cursor: pointer;
            background: #f6f8fe; border: 1px solid #dfe7f6; font-family: inherit; text-align: left; transition: background .2s ease, box-shadow .2s ease, transform .15s ease;
        }
        .ah-match:hover { background: #eef2fb; box-shadow: 0 6px 16px rgba(30, 58, 138, 0.12); transform: translateY(-1px); }
        .ah-ring {
            --p: 0; width: 54px; height: 54px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
            background: conic-gradient(#22a05a calc(var(--p) * 1%), #e3e8f0 0);
        }
        .ah-ring span {
            width: 42px; height: 42px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 800; color: #15803d;
        }
        .ah-match-text { display: flex; flex-direction: column; gap: 2px; }
        .ah-match-text strong { font-size: 13.5px; color: #0f1b3d; }
        .ah-match-text span { font-size: 12px; font-weight: 600; color: #2a62de; }
        .ah-match-text i { font-size: 10px; }
        .ah-nomatch { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 10px; background: #f4f6fa; font-size: 12px; color: #6b7280; }

        .ah-flash { display: flex; align-items: flex-start; gap: 10px; padding: 11px 14px; border-radius: 12px; margin-bottom: 14px; font-size: 13.5px; font-weight: 600; }
        .ah-flash i { margin-top: 2px; }
        .ah-flash.ok { background: #f0fbf4; border: 1px solid #b7e3c6; border-left: 4px solid #22a05a; color: #166534; }
        .ah-flash.err { background: #fff6f5; border: 1px solid #f6cbc6; border-left: 4px solid #e05545; color: #b42318; }

        @media (max-width: 700px) {
            .ah-card { flex-wrap: wrap; padding: 16px; gap: 14px; }
            .ah-photo { width: 60px; height: 60px; font-size: 24px; }
            .ah-main { flex-basis: calc(100% - 80px); }
            .ah-side { width: 100%; }
            .ah-match { width: 100%; }
            .ah-title-row h1 { font-size: 19px; }
        }

        /* ===== Interview Summary Report (form ng staff) ===== */
        .sf-group { margin: 18px 0 10px; font-size: 13px; font-weight: 800; color: #1e3a8a; text-transform: uppercase; letter-spacing: .4px; }
        .sf-tip { margin: -4px 0 10px; font-size: 12.5px; color: #64748b; }
        .sf-tip i { margin-right: 5px; color: #2a62de; }
        /* Paliwanag ng staff bilang bullet list (naka-lock na Interview Summary) */
        .review-field .sum-points { margin: 0; padding: 10px 14px 10px 30px; display: grid; gap: 6px; border: 1px solid #e6eaf1; border-radius: 10px; background: #f8faff; }
        .review-field .sum-points li { font-size: 14px; line-height: 1.5; font-weight: 500; overflow-wrap: anywhere; }
        .summary-form .sf-group:first-of-type { margin-top: 0; }
        .sf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .sf-field { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; font-weight: 700; color: #374151; }
        .sf-field em { color: #c5221f; font-style: normal; }
        .sf-wide { grid-column: 1 / -1; }
        .sf-field input, .sf-field select, .sf-field textarea {
            width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1.5px solid #dbe3f4; border-radius: 10px;
            background: #fff; font-family: inherit; font-size: 13.5px; font-weight: 400; color: #1f2937;
        }
        .sf-field textarea { resize: vertical; }
        .sf-field input:focus, .sf-field select:focus, .sf-field textarea:focus { outline: none; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        @media (max-width: 600px) { .sf-field input, .sf-field select, .sf-field textarea { font-size: 16px; } }

        /* ---------------------------------------------------------------
           Mga sagot ng adopter - parang application form: bawat sagot ay nasa
           sariling naka-box na field, may label sa itaas at malinaw na teksto.
           Tatlong column sa desktop (hindi na kumakalat sa malapad na screen),
           dalawa sa tablet, isa sa telepono.
           --------------------------------------------------------------- */
        .rv-body .review-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px 18px;
        }
        .rv-body .review-field label {
            margin-bottom: 6px;
            font-size: 11.5px !important;
            font-weight: 700;
            letter-spacing: .5px;
            color: #6b7280 !important;
        }
        .rv-body .review-field p {
            min-height: 46px;
            padding: 12px 14px;
            border: 1px solid #e3e7e4;
            border-radius: 10px;
            background: #f9fafb;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.5;
            color: #111827;
            overflow-wrap: anywhere;
        }
        .rv-body .review-field p .review-link { word-break: break-all; }
        @media (max-width: 1100px) {
            .rv-body .review-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .rv-body .review-grid { grid-template-columns: minmax(0, 1fr); }
        }

        /* ---------------------------------------------------------------
           Itsurang "Adoption Application" form (kapareho ng nakikita ng adopter)
           para sa mga sagot niya: pamagat, mga hakbang, berdeng heading na may
           guhit sa ilalim, dalawang column na field, at naka-label na larawan.
           --------------------------------------------------------------- */
        .rv-form-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin: 4px 4px 16px;
        }
        .rv-form-head h2 { margin: 0; font-size: 26px; font-weight: 800; color: #1b4d2e; }
        .rv-form-head span { font-size: 14.5px; color: #6b7280; }
        .rv-form-head b { color: #374151; }

        /* ---- Card ng Adoption Application at ng Decision ----
           Hiwalay na card ang bawat isa (gaya ng form ng adopter), kaya
           transparent na lang ang dating malaking puting panel. */
        .review-panel:has(.rv-app-card) {
            padding: 0;
            background: transparent;
            box-shadow: none;
        }
        .rv-app-card {
            margin-bottom: 20px;
            padding: 26px 28px 6px;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(16, 24, 40, 0.06);
        }
        .review-panel:has(.rv-app-card) .decision-card {
            margin: 0;
            padding: 22px 24px;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(16, 24, 40, 0.06);
        }
        @media (max-width: 640px) {
            .rv-app-card { padding: 18px 16px 4px; }
        }

        /* ---- Mga tab (kapareho ng Staff Dashboard): magkakadikit, may kulay ---- */
        .rv-tabs {
            display: inline-flex;
            flex-wrap: wrap;
            max-width: 100%;
            margin: 0 4px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }
        .rv-tab {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 40px;
            padding: 0 18px;
            border: none;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            transition: background-color .25s ease, box-shadow .25s ease;
        }
        .rv-tab.tab-applicant { background: #5bc85b; }
        .rv-tab.tab-household { background: #6d8cf0; }
        .rv-tab.tab-photos { background: #ffad5c; }
        .rv-tab.tab-schedule { background: #4fb8b0; }
        /* Bukas na tab: mas madilim na kulay ng sariling tab */
        .rv-tab.active { box-shadow: inset 0 -3px 0 rgba(0, 0, 0, 0.18); }
        .rv-tab.tab-applicant.active { background: #1f7a2e; }
        .rv-tab.tab-household.active { background: #1e2f8f; }
        .rv-tab.tab-photos.active { background: #f07c12; }
        .rv-tab.tab-schedule.active { background: #0f766e; }
        .rv-tab::after {
            content: "";
            position: absolute;
            inset: 0;
            background: #fff;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease;
        }
        .rv-tab:not(.active):hover::after { opacity: .14; }
        .rv-tab:focus-visible { outline: 3px solid #102a2a; outline-offset: -3px; }
        .rv-tab-count {
            min-width: 20px;
            padding: 1px 7px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
            font-size: 11.5px;
            text-align: center;
        }

        /* Info pill: paliwanag ng bukas na tab */
        .rv-info {
            display: flex;
            align-items: center;
            gap: 10px;
            width: fit-content;
            max-width: 100%;
            margin: 14px 4px 22px;
            padding: 8px 16px 8px 10px;
            border: 1px solid #c9e3e8;
            border-radius: 999px;
            background: #e6f3f5;
            color: #1f3d44;
            font-size: 13px;
            font-weight: 600;
        }
        .rv-info-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1.5px solid #1f3d44;
            border-radius: 50%;
            font-family: Georgia, serif;
            font-size: 12px;
            font-weight: 700;
        }
        #rvInfoText { transition: opacity .15s ease; }
        #rvInfoText.swap { opacity: 0; }

        /* Iisang tab lang ang nakikita */
        .review-panel [data-step].rv-slide-hidden { display: none; }
        .review-panel [data-step].rv-slide-in { animation: rvSlideIn .3s ease; }
        @keyframes rvSlideIn { from { opacity: 0; transform: translateX(18px); } to { opacity: 1; transform: none; } }
        .review-panel [data-step].rv-slide-in.from-left { animation-name: rvSlideInLeft; }
        @keyframes rvSlideInLeft { from { opacity: 0; transform: translateX(-18px); } to { opacity: 1; transform: none; } }
        @media (max-width: 640px) {
            /* Pamagat: mas maliit sa telepono */
            .rv-form-head { gap: 4px; }
            .rv-form-head h2 { font-size: 20px; }
            .rv-form-head span { font-size: 13px; }

            /* Apat na tab na magkakasinlapad (flex: 1 1 0), kasya sa iisang hanay - wala nang
               naputol na "Schedule". Ang bilang ng larawan ay nasa ilalim ng pangalan. */
            .rv-tabs { display: flex; flex-wrap: nowrap; width: auto; margin: 0; }
            .rv-tab {
                flex: 1 1 0; min-width: 0; height: auto; min-height: 44px; padding: 6px 4px;
                flex-direction: column; justify-content: center; gap: 2px;
                font-size: 11px; line-height: 1.2; text-align: center; white-space: normal;
            }
            .rv-tab-count { font-size: 10px; padding: 0 6px; min-width: 16px; line-height: 15px; }

            .rv-info { border-radius: 12px; margin: 12px 0 16px; font-size: 12px; padding: 7px 12px 7px 8px; gap: 8px; }
            .rv-info-icon { width: 18px; height: 18px; font-size: 11px; }
            .rv-app-card .rv-form .rv-title, .rv-app-card .rv-form[open] .rv-title { font-size: 16px; }
            .rv-app-card .rv-hint { font-size: 12px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .review-panel [data-step].rv-slide-in { animation: none; }
            .rv-tab, .rv-tab::after, #rvInfoText { transition: none; }
        }

        /* Section: walang madilim na header - berdeng pamagat na may guhit */
        .rv-form.rv-collapse,
        .rv-form.rv-collapse[open] {
            border: none !important;
            box-shadow: none !important;
            background: transparent;
            overflow: visible;
            margin-bottom: 26px;
            scroll-margin-top: 90px;
        }
        .rv-form > .rv-summary,
        .rv-form[open] > .rv-summary {
            padding: 0 4px 10px !important;
            margin-bottom: 4px;
            background: none !important;
            border-bottom: 2px solid #e5e7eb;
            border-radius: 0;
        }
        .rv-form > .rv-summary:hover { background: none !important; }
        .rv-form .rv-icon { display: none !important; }
        .rv-form .rv-title,
        .rv-form[open] .rv-title { font-size: 19px; font-weight: 700; color: #1b4d2e !important; }
        .rv-form .rv-hint,
        .rv-form[open] .rv-hint { color: #6b7280 !important; font-size: 13px; }
        .rv-form .rv-chev,
        .rv-form[open] .rv-chev { color: #6b7280 !important; }
        .rv-form > .rv-body { padding: 16px 4px 0; background: transparent; }

        .rv-form .review-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 22px; }
        .rv-form .review-field label {
            margin-bottom: 7px;
            font-size: 14px !important;
            font-weight: 700;
            letter-spacing: 0;
            text-transform: none;
            color: #374151 !important;
        }
        .rv-form .review-field p {
            min-height: 46px;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f9fafb;
            font-size: 15px;
            font-weight: 500;
            color: #111827;
        }

        /* Mga larawan: naka-label na grid, gaya ng upload fields */
        .rv-photo-note {
            margin: 0 0 14px;
            font-size: 13px;
            color: #6b7280;
        }
        .rv-photo-note i { color: #1b4d2e; margin-right: 4px; }
        .rv-photo-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px 22px;
        }
        .rv-photo-card { margin: 0; }
        .rv-photo-card figcaption {
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 700;
            color: #374151;
        }
        .rv-photo-btn {
            position: relative;
            display: block;
            width: 100%;
            padding: 0;
            border: 1px dashed #d1d5db;
            border-radius: 10px;
            background: #f3f4f6;
            overflow: hidden;
            cursor: zoom-in;
        }
        .rv-photo-btn img {
            display: block;
            width: 100%;
            height: 300px;
            object-fit: contain;
            transition: transform .25s ease;
        }
        .rv-photo-zoom {
            position: absolute;
            right: 10px;
            bottom: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 11px;
            border-radius: 999px;
            background: rgba(17, 24, 39, 0.75);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            opacity: .9;
            transition: opacity .2s ease, background-color .2s ease;
        }
        .rv-photo-btn:hover img { transform: scale(1.03); }
        .rv-photo-btn:hover .rv-photo-zoom { opacity: 1; background: #1b4d2e; }
        .rv-photo-btn:focus-visible { outline: 3px solid rgba(27, 77, 46, .35); outline-offset: 2px; }

        @media (max-width: 760px) {
            .rv-form .review-grid,
            .rv-photo-grid { grid-template-columns: minmax(0, 1fr); }
            .rv-photo-btn img { height: 220px; }
        }
<?php if ($isAdminViewer): ?>

        /* ---------------------------------------------------------------
           Kulay ng ADMIN (berde) sa halip na asul ng Staff. Admin lang ang
           nakakakita nito - walang nagbabago sa panig ng Staff.
             asul na madilim  #0f1b3d -> #0b2a1e
             asul             #1e3a8a -> #1b4332
             asul na mas mapusyaw #2a62de / #2f5bc4 -> #2d6a4f
             mapusyaw na asul na tint #eef2fb -> #e8f3ed
           --------------------------------------------------------------- */
        /* Header ng application */
        body.sp-admin-view .ah-card::before { display: none; } /* walang kulay na guhit sa kaliwa ng header card */
        body.sp-admin-view .ah-back { color: #1b4332; border-color: #e3e8e5; }
        body.sp-admin-view .ah-back:hover { background: #f1f8f4; box-shadow: 0 4px 12px rgba(27, 67, 50, 0.12); }
        body.sp-admin-view .ah-photo { background: #e8f3ed; color: #1b4332; box-shadow: 0 4px 12px rgba(11, 42, 30, 0.12); }
        body.sp-admin-view .ah-title-row h1,
        body.sp-admin-view .ah-sub strong,
        body.sp-admin-view .ah-match-text strong { color: #0b2a1e; }
        body.sp-admin-view .ah-match:hover { background: #e8f3ed; box-shadow: 0 6px 16px rgba(27, 67, 50, 0.12); }
        body.sp-admin-view .ah-match-text span { color: #2d6a4f; }

        /* Application Progress */
        body.sp-admin-view .flow-head h3 { color: #0b2a1e; }
        body.sp-admin-view .flow-head p strong { color: #1b4332; }
        body.sp-admin-view .flow-next-btn,
        body.sp-admin-view .ag-btn.primary { background: linear-gradient(135deg, #2d6a4f, #1b4332); box-shadow: 0 4px 10px rgba(27, 67, 50, 0.22); }
        body.sp-admin-view .flow-step.is-current .flow-dot { background: #1b4332; border-color: #1b4332; box-shadow: 0 0 0 3px rgba(27, 67, 50, 0.15); animation-name: flowPulseAdmin; }
        body.sp-admin-view .flow-step.is-current .flow-label { color: #0b2a1e; }
        body.sp-admin-view .flow-step.is-current .flow-state { color: #1b4332; }
        @keyframes flowPulseAdmin { 0%, 100% { box-shadow: 0 0 0 3px rgba(27, 67, 50, 0.15); } 50% { box-shadow: 0 0 0 6px rgba(27, 67, 50, 0.06); } }

        /* Gabay bago ma-approve */
        body.sp-admin-view .ag-card h3,
        body.sp-admin-view .ag-text strong { color: #0b2a1e; }
        body.sp-admin-view .ag-step.is-current { border-color: #cfe5d8; background: #f1f8f4; }
        body.sp-admin-view .ag-step.is-current .ag-num,
        body.sp-admin-view .ag-tag.current { background: #1b4332; }
        body.sp-admin-view .ag-note { background: #e8f3ed; color: #1b4332; }

        /* Mga section (Applicant Information, Household, Photos & ID, Interview) */
        body.sp-admin-view .rv-collapse[open] { border-color: #cfe5d8 !important; box-shadow: 0 10px 24px rgba(27, 67, 50, 0.08) !important; }
        body.sp-admin-view .rv-summary:hover { background: #f6faf8 !important; }
        body.sp-admin-view .rv-collapse[open] > .rv-summary { background: linear-gradient(135deg, #0b2a1e 0%, #1b4332 60%, #2d6a4f 100%) !important; }
        body.sp-admin-view .rv-icon { background: #e8f3ed !important; color: #1b4332 !important; }
        body.sp-admin-view .rv-title { color: #0b2a1e !important; }
        body.sp-admin-view .rv-car-thumb.active { border-color: #2d6a4f !important; }
        body.sp-admin-view .rv-car-stage:focus-visible { box-shadow: 0 0 0 3px #2d6a4f !important; }

        /* Mga button at input sa loob ng mga section */
        body.sp-admin-view .btn-action { background: linear-gradient(135deg, #2d6a4f, #1b4332) !important; box-shadow: 0 4px 10px rgba(27, 67, 50, 0.2); }
        body.sp-admin-view .meeting-link-box > label { color: #1b4332 !important; }
        body.sp-admin-view .meeting-link-form input[name="meeting_link"] { border-color: #cfe5d8 !important; }
        body.sp-admin-view .meeting-link-form input[name="meeting_link"]:focus,
        body.sp-admin-view .sf-field input:focus,
        body.sp-admin-view .sf-field select:focus,
        body.sp-admin-view .sf-field textarea:focus { border-color: #2d6a4f !important; box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.14); }
        body.sp-admin-view .sf-group { color: #1b4332; }
        body.sp-admin-view .sf-field input,
        body.sp-admin-view .sf-field select,
        body.sp-admin-view .sf-field textarea { border-color: #d7e6dd; }

        /* Decision */
        body.sp-admin-view .decision-icon { background: #e8f3ed; color: #1b4332; }
        body.sp-admin-view .decision-head h3,
        body.sp-admin-view .decision-box-text strong { color: #0b2a1e; }
<?php endif; ?>

        /* ---- Kulay ng STAFF (asul) para sa card ng application at mga sagot.
                Admin lang ang berde (body.sp-admin-view). ---- */
        body:not(.sp-admin-view) .rv-form-head h2 { color: #0f1b3d; }
        body:not(.sp-admin-view) .rv-app-card .rv-form .rv-title { color: #1e3a8a !important; }
        body:not(.sp-admin-view) .rv-photo-note i { color: #1e3a8a; }
        body:not(.sp-admin-view) .rv-photo-btn:hover .rv-photo-zoom { background: #1e3a8a; }
        body:not(.sp-admin-view) .rv-photo-btn:focus-visible { outline-color: rgba(30, 58, 138, .35); }
        body:not(.sp-admin-view) .rv-form .review-field p { border-color: #e6eaf1; background: #f8faff; }
        body:not(.sp-admin-view) .rv-app-card,
        body:not(.sp-admin-view) .review-panel:has(.rv-app-card) .decision-card { border-color: #e6eaf1; box-shadow: 0 8px 24px rgba(15, 27, 61, 0.06); }

        /* Form-style na section: talo ang madilim na header ng Staff/Admin */
        body.staff-dashboard-root .rv-form.rv-collapse[open] { border: none !important; box-shadow: none !important; }
        body.staff-dashboard-root .rv-form.rv-collapse[open] > .rv-summary,
        body.staff-dashboard-root .rv-form.rv-collapse > .rv-summary:hover { background: none !important; }
        body.staff-dashboard-root .rv-form.rv-collapse .rv-title { color: #1e3a8a !important; }          /* Staff: asul */
        body.staff-dashboard-root.sp-admin-view .rv-form.rv-collapse .rv-title { color: #1b4d2e !important; } /* Admin: berde */
    </style>
</head>
<body class="staff-dashboard-root<?php echo $isAdminViewer ? ' sp-admin-view' : ''; ?>">
<div class="app-container">
    <?php if ($isAdminViewer): ?>
        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="/Caps2/Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

            <nav class="sidebar-menu">
                <a href="/Caps2/AdminFile/index.php" class="menu-item">
                    <i class="fa-solid fa-house"></i> Dashboard
                </a>
                <a href="/Caps2/AdminFile/Manage_staff_file/manage_staff.php" class="menu-item">
                    <i class="fa-solid fa-user-gear"></i> Manage Staff
                </a>
                <a href="/Caps2/AdminFile/Manage_adopters_file/manage_adopters.php" class="menu-item active">
                    <i class="fa-solid fa-users"></i> Manage Adopters
                </a>
                <a href="/Caps2/AdminFile/manage_pets.php" class="menu-item">
                    <i class="fa-solid fa-paw"></i> Manage Pets
                </a>
                <a href="/Caps2/AdminFile/payments.php" class="menu-item">
                    <i class="fa-solid fa-money-bill-wave"></i> Payments
                </a>
                <a href="/Caps2/AdminFile/reports.php" class="menu-item">
                    <i class="fa-solid fa-chart-bar"></i> Reports
                </a>
                <a href="/Caps2/AdminFile/messages.php" class="menu-item">
                    <i class="fa-solid fa-comment-dots"></i> Messages<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?>
                </a>
                <a href="/Caps2/AdminFile/activity_logs.php" class="menu-item">
                    <i class="fa-solid fa-clock-rotate-left"></i> Activity Logs
                </a>
                <a href="/Caps2/AdminFile/ml_model.php" class="menu-item">
                    <i class="fa-solid fa-brain"></i> ML Model
                </a>
            </nav>

            <!-- Kapareho ng ibang admin page: nasa profile menu na ang Logout -->
            <div class="sidebar-footer">
                <div class="city-illustration">
                    <img src="/Caps2/Images/caloocan_image.png" alt="Caloocan Arch Landmark" width="300">
                </div>
            </div>
        </aside>
    <?php else: ?>
        <?php include __DIR__ . '/_sidebar.php'; ?>
    <?php endif; ?>

    <main class="main-wrapper">
        <div class="top-header">
            <div class="search-top"></div>
            <a href="<?php echo $isAdminViewer ? '/Caps2/AdminFile/edit_profile.php' : '/Caps2/StaffFile/edit_profile.php'; ?>" class="profile-section" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <div class="avatar" style="width: 38px; height: 38px; border-radius: 50%; background: #1f6b48; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                        <?php echo htmlspecialchars($user_initials); ?>
                    </div>
                <?php endif; ?>
                <div class="profile-name" style="font-weight: 700; color: #102a2a;"><?php echo htmlspecialchars($username); ?></div>
            </a>
        </div>

        <div class="dashboard-content page-content" style="padding: 0 12px 24px 12px;">
            <?php
                $statusKey = strtolower(trim($application['application_status']));
                $badgeClass = 'badge-pending';
                if ($statusKey === 'under review') $badgeClass = 'badge-review';
                if ($statusKey === 'approved') $badgeClass = 'badge-approved';
                if ($statusKey === 'rejected') $badgeClass = 'badge-rejected';
                $petMeta = trim(implode(' - ', array_filter([$application['pet_kind'] ?? '', $application['pet_breed'] ?? ''])));
            ?>
            <!-- Pagbalik sa listahan: nasa itaas ng title, hindi na sa top nav -->
            <a href="<?php echo htmlspecialchars($backUrl); ?>" class="ah-back" title="<?php echo htmlspecialchars($backLabel); ?>" aria-label="<?php echo htmlspecialchars($backLabel); ?>">
                <i class="fa-solid fa-arrow-left"></i>
            </a>

            <header class="ah-card">
                <div class="ah-photo">
                    <?php if (!empty($application['pet_photo'])): ?>
                        <img src="/Caps2/uploads/<?php echo htmlspecialchars($application['pet_photo']); ?>" alt="<?php echo htmlspecialchars($application['pet_name']); ?>" onerror="this.replaceWith(Object.assign(document.createElement('i'), {className: 'fa-solid fa-paw'}))">
                    <?php else: ?>
                        <i class="fa-solid fa-paw"></i>
                    <?php endif; ?>
                </div>
                <div class="ah-main">
                    <div class="ah-title-row">
                        <h1>Application APP-<?php echo str_pad($application['application_id'], 3, '0', STR_PAD_LEFT); ?></h1>
                        <span class="badge-app <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($application['application_status']); ?></span>
                    </div>
                    <p class="ah-sub"><strong><?php echo htmlspecialchars($application['full_name']); ?></strong> applying for <strong><?php echo htmlspecialchars($application['pet_name']); ?></strong></p>
                    <ul class="ah-meta">
                        <?php if ($petMeta !== ''): ?>
                            <li><i class="fa-solid fa-paw"></i> <?php echo htmlspecialchars($petMeta); ?></li>
                        <?php endif; ?>
                        <li><i class="fa-regular fa-calendar"></i> Applied <?php echo htmlspecialchars(date('M j, Y', strtotime($application['created_at']))); ?></li>
                        <li><i class="fa-regular fa-envelope"></i> <?php echo htmlspecialchars($application['email_address']); ?></li>
                        <li><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($application['phone_number']); ?></li>
                    </ul>
                </div>
                <div class="ah-side">
                    <?php if ($matchScore !== null): ?>
                        <button type="button" class="ah-match" onclick="openMatchBreakdownModal()" title="View match breakdown">
                            <span class="ah-ring" style="--p: <?php echo (int) $matchScore; ?>;"><span><?php echo (int) $matchScore; ?>%</span></span>
                            <span class="ah-match-text"><strong>Match Score</strong><span>View breakdown <i class="fa-solid fa-chevron-right"></i></span></span>
                        </button>
                    <?php else: ?>
                        <span class="ah-nomatch"><i class="fa-solid fa-circle-info"></i> No adopter preferences set - match not available</span>
                    <?php endif; ?>
                </div>
            </header>

            <?php if (!empty($success_message)): ?>
                <div class="ah-flash ok"><i class="fa-solid fa-circle-check"></i> <span><?php echo htmlspecialchars($success_message); ?></span></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="ah-flash err"><i class="fa-solid fa-circle-exclamation"></i> <span><?php echo htmlspecialchars($error_message); ?></span></div>
            <?php endif; ?>

            <?php if ($application['application_status'] !== 'Rejected'): ?>
            <!-- ===== Daloy ng application: kung nasaan na at ano ang susunod na gagawin ===== -->
            <section class="flow-panel" aria-label="Application progress">
                <div class="flow-head">
                    <div>
                        <h3>Application Progress</h3>
                        <p>
                            <?php if ($application['application_status'] === 'Approved'): ?>
                                All steps are complete. This application is approved.
                            <?php elseif ($nextStep !== null): ?>
                                <!-- Hiwalay na span ang bawat bahagi para maisalin (connection/i18n.php) -->
                                <strong>Next:</strong> <span><?php echo htmlspecialchars($nextStep['title']); ?></span> &mdash; <span><?php echo htmlspecialchars($nextStep['detail']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($nextStep !== null && !empty($nextStep['url']) && !$isDecided): ?>
                        <a class="flow-next-btn" href="<?php echo htmlspecialchars($nextStep['url']); ?>">
                            <?php echo htmlspecialchars($nextStep['action']); ?>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    <?php endif; ?>
                </div>
                <ol class="flow-steps">
                    <?php foreach ($flowSteps as $i => $step): ?>
                        <li class="flow-step is-<?php echo $step['state']; ?>" title="<?php echo htmlspecialchars($step['detail']); ?>">
                            <span class="flow-dot" aria-hidden="true">
                                <?php if ($step['state'] === 'done'): ?>
                                    <svg viewBox="0 0 24 24"><path d="M6 12.5l4 4 8-9"/></svg>
                                <?php elseif ($step['state'] === 'blocked'): ?>
                                    <svg viewBox="0 0 24 24"><path d="M12 7v6M12 16.5h.01"/></svg>
                                <?php else: ?>
                                    <?php echo $i + 1; ?>
                                <?php endif; ?>
                            </span>
                            <span class="flow-label"><?php echo htmlspecialchars($step['title']); ?></span>
                            <span class="flow-state"><?php echo ['done' => 'Done', 'current' => 'Your turn', 'waiting' => 'Waiting for adopter', 'blocked' => 'Needs attention', 'todo' => 'Not yet'][$step['state']]; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <?php endif; ?>

            <div class="review-panel">

                <!-- Card ng Adoption Application (pamagat, hakbang, slides, Back/Next) -->
                <div class="rv-app-card">
                <!-- Kapareho ng Adoption Application form ng adopter: pamagat at
                     mga hakbang sa itaas (napipindot para tumalon sa section) -->
                <div class="rv-form-head">
                    <h2>Adoption Application</h2>
                    <span>Applying for: <b><?php echo htmlspecialchars($application['pet_name']); ?></b></span>
                </div>
                <?php
                // Bilang ng larawan para sa badge ng "Photos & ID" na tab
                $tabPhotoCount = 0;
                foreach (array_merge(array_keys($photoFields), ['valid_id_url', 'valid_id_back_url', 'barangay_certificate_url']) as $col) {
                    if (!empty($application[$col])) { $tabPhotoCount++; }
                }
                ?>
                <!-- Mga tab (kapareho ng Staff Dashboard): magkakadikit at may kulay,
                     at may paliwanag (info pill) ng bukas na tab sa ilalim -->
                <div class="rv-tabs" role="tablist" aria-label="Application sections">
                    <button type="button" class="rv-step rv-tab tab-applicant" role="tab" data-goto="1" data-info="Name, contact details, and address of the applicant.">Applicant Info</button>
                    <button type="button" class="rv-step rv-tab tab-household" role="tab" data-goto="2" data-info="Care plan, finances, and pet history.">Household</button>
                    <button type="button" class="rv-step rv-tab tab-photos" role="tab" data-goto="3" data-info="Home photos, valid ID, and barangay certificate. Click a photo to zoom.">Photos &amp; ID <span class="rv-tab-count"><?php echo $tabPhotoCount; ?></span></button>
                    <button type="button" class="rv-step rv-tab tab-schedule" role="tab" data-goto="4" data-info="Interview schedule and meeting link.">Schedule</button>
                </div>
                <div class="rv-info" role="note">
                    <span class="rv-info-icon" aria-hidden="true">i</span>
                    <span id="rvInfoText"></span>
                </div>
                <details class="review-section rv-collapse rv-form" id="rv-applicant" data-step="1" open>
                    <summary class="rv-summary">
                        <span class="rv-icon"><i class="fa-solid fa-user"></i></span>
                        <span class="rv-heading"><span class="rv-title">Applicant Information</span><span class="rv-hint">Name, contact details, and address</span></span>
                        <i class="fa-solid fa-chevron-down rv-chev" aria-hidden="true"></i>
                    </summary>
                    <div class="rv-body">
                    <div class="review-grid">
                        <div class="review-field"><label>Full Name</label><p><?php echo htmlspecialchars($application['full_name']); ?></p></div>
                        <div class="review-field"><label>Email</label><p><?php echo htmlspecialchars($application['email_address']); ?></p></div>
                        <div class="review-field"><label>Phone</label><p><?php echo htmlspecialchars($application['phone_number']); ?></p></div>
                        <div class="review-field"><label>Birthdate</label><p><?php echo htmlspecialchars($application['birthdate']); ?></p></div>
                        <div class="review-field"><label>Occupation</label><p><?php echo htmlspecialchars($application['occupation']); ?></p></div>
                        <div class="review-field"><label>Civil Status</label><p><?php echo htmlspecialchars($application['civil_status']); ?></p></div>
                        <?php
                            $socialLink = trim((string)($application['social_media_link'] ?? ''));
                            // Karamihan sa adopter ay walang https:// na inilalagay, kaya idinadagdag natin para gumana ang link
                            $socialHref = ($socialLink !== '' && !preg_match('#^https?://#i', $socialLink))
                                ? 'https://' . $socialLink
                                : $socialLink;
                            $socialIsUrl = $socialLink !== '' && filter_var($socialHref, FILTER_VALIDATE_URL) !== false;
                        ?>
                        <div class="review-field"><label>Social Media</label>
                            <?php if ($socialIsUrl): ?>
                                <p><a class="review-link" href="<?php echo htmlspecialchars($socialHref); ?>" target="_blank" rel="noopener noreferrer" title="Open profile in a new tab"><?php echo htmlspecialchars($socialLink); ?><i class="fa-solid fa-arrow-up-right-from-square"></i></a></p>
                            <?php else: ?>
                                <p><?php echo htmlspecialchars($socialLink !== '' ? $socialLink : 'N/A'); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="review-field" style="grid-column: 1 / -1;"><label>Address</label><p><?php echo nl2br(htmlspecialchars($application['address'])); ?></p></div>
                    </div>
                    </div>
                </details>

                <?php if (!empty($application['guardian_name'])): ?>
                <details class="review-section rv-collapse rv-form" id="rv-guardian" data-step="1" open>
                    <summary class="rv-summary">
                        <span class="rv-icon"><i class="fa-solid fa-user-shield"></i></span>
                        <span class="rv-heading"><span class="rv-title">Guardian Information</span><span class="rv-hint">Guardian name and relationship</span></span>
                        <i class="fa-solid fa-chevron-down rv-chev" aria-hidden="true"></i>
                    </summary>
                    <div class="rv-body">
                    <div class="review-grid">
                        <div class="review-field"><label>Guardian Name</label><p><?php echo htmlspecialchars($application['guardian_name']); ?></p></div>
                        <div class="review-field"><label>Relationship</label><p><?php echo htmlspecialchars($application['guardian_relationship'] ?? ''); ?></p></div>
                    </div>
                    </div>
                </details>
                <?php endif; ?>

                <details class="review-section rv-collapse rv-form" id="rv-household" data-step="2" open>
                    <summary class="rv-summary">
                        <span class="rv-icon"><i class="fa-solid fa-house-chimney"></i></span>
                        <span class="rv-heading"><span class="rv-title">Household Questionnaire</span><span class="rv-hint">Care plan, finances, and pet history</span></span>
                        <i class="fa-solid fa-chevron-down rv-chev" aria-hidden="true"></i>
                    </summary>
                    <div class="rv-body">
                    <div class="review-grid">
                        <div class="review-field" style="grid-column: 1 / -1;"><label>Responsible for Care</label><p><?php echo nl2br(htmlspecialchars($application['q_responsible_for_care'])); ?></p></div>
                        <div class="review-field" style="grid-column: 1 / -1;"><label>Financial Responsibility</label><p><?php echo nl2br(htmlspecialchars($application['q_financial_responsibility'])); ?></p></div>
                        <div class="review-field" style="grid-column: 1 / -1;"><label>Emergency Caregiver</label><p><?php echo nl2br(htmlspecialchars($application['q_emergency_caregiver'])); ?></p></div>
                        <div class="review-field"><label>Hours Left Alone</label><p><?php echo (int) $application['q_hours_left_alone']; ?> hrs/day</p></div>
                        <div class="review-field"><label>Family Support</label><p><?php echo htmlspecialchars($application['q_family_support']); ?></p></div>
                        <div class="review-field"><label>Has Other Pets</label><p><?php echo htmlspecialchars($application['q_current_pets']); ?></p></div>
                        <div class="review-field"><label>Past Pet Ownership</label><p><?php echo htmlspecialchars($application['q_past_pet_ownership']); ?></p></div>
                        <div class="review-field" style="grid-column: 1 / -1;"><label>Introduction Strategy</label><p><?php echo nl2br(htmlspecialchars($application['q_introduction_strategy'])); ?></p></div>
                    </div>
                    </div>
                </details>

                <?php
                // Lahat ng larawan sa iisang listahan para sa carousel
                $galleryPhotos = [];
                foreach ($photoFields as $col => $label) {
                    if (!empty($application[$col])) {
                        $galleryPhotos[] = ['src' => '../uploads/' . $application[$col], 'label' => $label];
                    }
                }
                if (!empty($application['valid_id_url'])) {
                    $galleryPhotos[] = ['src' => '../uploads/' . $application['valid_id_url'], 'label' => !empty($application['valid_id_back_url']) ? 'Barangay ID (Front)' : 'Valid ID'];
                }
                if (!empty($application['valid_id_back_url'])) {
                    $galleryPhotos[] = ['src' => '../uploads/' . $application['valid_id_back_url'], 'label' => 'Barangay ID (Back)'];
                }
                if (!empty($application['barangay_certificate_url'])) {
                    $galleryPhotos[] = ['src' => '../uploads/' . $application['barangay_certificate_url'], 'label' => 'Barangay Certificate'];
                }
                ?>
                <details class="review-section rv-collapse rv-form" id="rv-photos" data-step="3" open>
                    <summary class="rv-summary">
                        <span class="rv-icon"><i class="fa-solid fa-images"></i></span>
                        <span class="rv-heading"><span class="rv-title">Home Photos &amp; Valid ID</span><span class="rv-hint"><?php echo count($galleryPhotos); ?> photo<?php echo count($galleryPhotos) === 1 ? '' : 's'; ?> uploaded by the adopter</span></span>
                        <i class="fa-solid fa-chevron-down rv-chev" aria-hidden="true"></i>
                    </summary>
                    <div class="rv-body">
                    <?php if ($galleryPhotos): ?>
                    <p class="rv-photo-note"><i class="fa-solid fa-magnifying-glass-plus"></i> Click a photo to open it full screen and zoom in.</p>
                    <!-- Naka-label na grid, gaya ng upload fields sa application form.
                         I-click ang larawan para buksan sa viewer na may zoom. -->
                    <div class="rv-photo-grid">
                        <?php foreach ($galleryPhotos as $i => $photo): ?>
                            <figure class="rv-photo-card">
                                <figcaption><?php echo htmlspecialchars($photo['label']); ?></figcaption>
                                <button type="button" class="rv-photo-btn" onclick="openPhotoPreview(<?php echo (int) $i; ?>)" aria-label="Open <?php echo htmlspecialchars($photo['label']); ?> full screen">
                                    <img src="<?php echo htmlspecialchars($photo['src']); ?>" alt="<?php echo htmlspecialchars($photo['label']); ?>" loading="lazy">
                                    <span class="rv-photo-zoom"><i class="fa-solid fa-magnifying-glass-plus"></i> View &amp; zoom</span>
                                </button>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                        <p class="meeting-link-note">No photos were uploaded.</p>
                    <?php endif; ?>
                    </div>
                </details>

                <details class="review-section rv-collapse rv-form" id="rv-interview" data-step="4"<?php echo ($application['schedule_status'] ?? '') === 'Pending' ? ' open' : ''; ?>>
                    <summary class="rv-summary">
                        <span class="rv-icon"><i class="fa-solid fa-calendar-days"></i></span>
                        <span class="rv-heading"><span class="rv-title">Interview Scheduling</span><span class="rv-hint"><?php echo htmlspecialchars(date('M j, Y', strtotime($application['preferred_interview_date'])) . ' at ' . date('g:i A', strtotime($application['preferred_interview_time']))
                            . ([ 'Pending' => ' - waiting for your answer', 'Declined' => ' - declined', 'Accepted' => ' - accepted' ][$application['schedule_status'] ?? ''] ?? '')); ?></span></span>
                        <i class="fa-solid fa-chevron-down rv-chev" aria-hidden="true"></i>
                    </summary>
                    <div class="rv-body">
                    <div class="review-grid">
                        <div class="review-field"><label>Preferred Date</label><p><?php echo htmlspecialchars(date('M j, Y', strtotime($application['preferred_interview_date']))); ?></p></div>
                        <div class="review-field"><label>Preferred Time</label><p><?php echo htmlspecialchars(date('g:i A', strtotime($application['preferred_interview_time']))); ?></p></div>
                        <?php if (!empty($application['meet_and_greet_availability'])): ?>
                        <div class="review-field" style="grid-column: 1 / -1;"><label>Meet & Greet Availability</label><p><?php echo nl2br(htmlspecialchars($application['meet_and_greet_availability'])); ?></p></div>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Interview request: ang adopter ang pumili ng araw at oras; ang staff ang magpapasya
                    $scheduleStatus = $application['schedule_status'] ?? null;
                    ?>
                    <?php if ($scheduleStatus === 'Pending'): ?>
                    <div class="schedule-request-box pending">
                        <div class="srb-head">
                            <i class="fa-solid fa-calendar-plus"></i>
                            <div>
                                <strong>The adopter chose this schedule</strong>
                                <p><?php echo htmlspecialchars(date('l, F j, Y', strtotime($application['preferred_interview_date'])) . ' at ' . date('g:i A', strtotime($application['preferred_interview_time']))); ?>. Accept it, or decline and tell the adopter why.</p>
                            </div>
                        </div>
                        <?php if (!$isDecided): ?>
                        <div class="srb-actions">
                            <form method="POST" action="/Caps2/StaffFile/process_review_application.php">
                                <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                                <input type="hidden" name="action" value="accept_schedule">
                                <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>
                                <button type="submit" class="btn-action"><i class="fa-solid fa-check"></i> Accept Schedule</button>
                            </form>
                            <button type="button" class="btn-action btn-reject" onclick="this.style.display = 'none'; document.getElementById('srbDecline').hidden = false; document.getElementById('srbReason').focus();">
                                <i class="fa-solid fa-xmark"></i> Decline
                            </button>
                        </div>
                        <form method="POST" action="/Caps2/StaffFile/process_review_application.php" id="srbDecline" class="srb-decline" hidden>
                            <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                            <input type="hidden" name="action" value="decline_schedule">
                            <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>
                            <label for="srbReason">Why can't the adopter have the interview on this day? <span>(sent to the adopter)</span></label>
                            <textarea id="srbReason" name="decline_reason" rows="3" maxlength="1000" required placeholder="e.g., The office is closed on this day because of a holiday. Please choose another date."></textarea>
                            <button type="submit" class="btn-action btn-reject"><i class="fa-solid fa-paper-plane"></i> Decline and Send Reason</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($scheduleStatus === 'Declined'): ?>
                    <div class="schedule-request-box declined">
                        <div class="srb-head">
                            <i class="fa-solid fa-calendar-xmark"></i>
                            <div>
                                <strong>Schedule declined - waiting for the adopter to choose a new date</strong>
                                <p>Reason sent: <?php echo nl2br(htmlspecialchars((string) $application['schedule_decline_reason'])); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($scheduleStatus === 'Accepted'): ?>
                    <div class="schedule-request-box accepted">
                        <div class="srb-head">
                            <i class="fa-solid fa-calendar-check"></i>
                            <div>
                                <strong>Schedule accepted</strong>
                                <?php if (!empty($application['schedule_decided_at'])): ?>
                                <p>Accepted on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($application['schedule_decided_at']))); ?>.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Meeting link: awtomatikong ipapadala sa email ng adopter 10 minuto bago ang interview -->
                    <div class="meeting-link-box">
                        <label for="meeting_link">Online Meeting Link</label>
                        <?php if (!$isDecided):
                            require_once __DIR__ . '/../connection/interview_schedule.php';
                            $defaultMeetLink = sp_default_meeting_link($pdo);
                            $ownMeetLink = (string) ($application['meeting_link'] ?? '');
                            // Walang sariling link pa: ilalagay agad ang naka-save na link para sa lahat
                            $meetPrefill = $ownMeetLink !== '' ? $ownMeetLink : $defaultMeetLink;
                            $isDefaultLink = $defaultMeetLink !== '' && $meetPrefill === $defaultMeetLink;
                        ?>
                        <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="meeting-link-form">
                            <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                            <input type="hidden" name="action" value="set_meeting_link">
                            <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>
                            <input type="text" inputmode="url" autocapitalize="off" autocorrect="off" spellcheck="false" id="meeting_link" name="meeting_link" placeholder="meet.google.com/abc-defg-hij" value="<?php echo htmlspecialchars($meetPrefill); ?>" maxlength="500">
                            <a class="btn-meet-new" href="https://meet.google.com/new" target="_blank" rel="noopener" title="Opens Google Meet and creates a new meeting. Copy its link and paste it here.">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="M15.5 10.5 21 7v10l-5.5-3.5"/></svg> Create Google Meet
                            </a>
                            <button type="submit" class="btn-action">Save Link</button>
                            <label class="meet-default-check">
                                <input type="checkbox" name="make_default" value="1" <?php echo $isDefaultLink || $defaultMeetLink === '' ? 'checked' : ''; ?>>
                                Use this link for all interviews
                            </label>
                        </form>
                        <?php if ($isDefaultLink && $ownMeetLink === ''): ?>
                        <p class="meeting-link-note default">This is the saved link for all interviews. Click <b>Save Link</b> to use it for this interview.</p>
                        <?php elseif ($defaultMeetLink !== ''): ?>
                        <p class="meeting-link-note default">Saved link for all interviews: <?php echo htmlspecialchars($defaultMeetLink); ?></p>
                        <?php endif; ?>
                        <?php elseif (!empty($application['meeting_link'])): ?>
                        <p><a class="review-link" href="<?php echo htmlspecialchars($application['meeting_link']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($application['meeting_link']); ?></a></p>
                        <?php else: ?>
                        <p class="meeting-link-note">No meeting link was set.</p>
                        <?php endif; ?>

                        <?php if (!empty($application['meeting_link_sent_at'])): ?>
                        <p class="meeting-link-note sent">Link emailed to the adopter on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($application['meeting_link_sent_at']))); ?>.</p>
                        <?php elseif (!empty($application['meeting_link'])): ?>
                        <p class="meeting-link-note">The link will be emailed to the adopter automatically 10 minutes before the scheduled interview.</p>
                        <?php elseif (!$isDecided): ?>
                        <p class="meeting-link-note">Paste the Google Meet or Zoom link for this interview. It will be emailed to the adopter automatically 10 minutes before the scheduled time.</p>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Attendance: pwedeng markahan ng "Did Not Attend" kapag lumipas na ang oras ng interview
                    $interviewAt = strtotime($application['preferred_interview_date'] . ' ' . $application['preferred_interview_time']);
                    $interviewPassed = $interviewAt <= time();
                    ?>
                    <?php if (!empty($application['interview_missed_at'])): ?>
                    <div class="attendance-box missed">
                        <i class="fa-solid fa-user-xmark"></i>
                        <div>
                            <strong>Did not attend</strong>
                            <?php if (empty($application['rebook_sent_at'])): ?>
                            <p>Marked on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($application['interview_missed_at']))); ?>. The adopter was asked to contact you. Once they do, send them a rebooking link so they can choose a new schedule.</p>
                            <?php else: ?>
                            <p>Rebooking link sent on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($application['rebook_sent_at']))); ?>. Waiting for the adopter to choose a new schedule.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$isDecided): ?>
                    <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="attendance-form">
                        <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                        <input type="hidden" name="action" value="send_rebook_link">
                        <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>
                        <button type="submit" class="btn-action">
                            <i class="fa-solid fa-link"></i> <?php echo empty($application['rebook_sent_at']) ? 'Send Rebooking Link' : 'Resend Rebooking Link'; ?>
                        </button>
                        <span class="meeting-link-note">The adopter will get the link by notification, message, and email, then pick a new interview schedule.</span>
                    </form>
                    <?php endif; ?>
                    <?php elseif ((int) ($application['reschedule_count'] ?? 0) > 0): ?>
                    <div class="attendance-box rescheduled">
                        <i class="fa-solid fa-calendar-check"></i>
                        <div>
                            <strong>Rescheduled by the adopter<?php echo (int) $application['reschedule_count'] > 1 ? ' (' . (int) $application['reschedule_count'] . ' times)' : ''; ?></strong>
                            <?php if (!empty($application['missed_reason'])): ?>
                            <p>Reason for missing the previous interview: <?php echo nl2br(htmlspecialchars($application['missed_reason'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$isDecided && empty($application['interview_missed_at']) && $interviewPassed): ?>
                    <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="attendance-form">
                        <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                        <input type="hidden" name="action" value="mark_no_show">
                        <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>
                        <button type="submit" class="btn-action btn-reject" onclick="return confirm('Mark this adopter as did not attend? They will be notified and asked to contact you for a rebooking link.');">
                            <i class="fa-solid fa-user-xmark"></i> Mark as Did Not Attend
                        </button>
                        <span class="meeting-link-note">Use this if the adopter did not show up. They will be told to contact you, and you can then send them a rebooking link.</span>
                    </form>
                    <?php endif; ?>
                    </div>
                </details>

                <?php
                // Interview Summary Report: isusulat pagkatapos ng interview, makikita ng adopter
                $summary = sp_interview_summary($pdo, (int) $application['application_id']);
                $canWriteSummary = !$isDecided && empty($application['interview_missed_at']) && $interviewPassed;
                ?>
                <?php if ($summary || $canWriteSummary): ?>
                <?php $sv = $summary ?: sp_summary_defaults($application); ?>
                <details class="review-section rv-collapse rv-form" id="rv-summary" data-step="4"<?php echo (!$summary && $canWriteSummary) ? ' open' : ''; ?>>
                    <summary class="rv-summary">
                        <span class="rv-icon"><i class="fa-solid fa-file-signature"></i></span>
                        <span class="rv-heading"><span class="rv-title">Interview Summary Report</span><span class="rv-hint"><?php echo $summary
                            ? htmlspecialchars('Sent to the adopter - ' . $summary['recommendation'] . ' (last updated ' . date('M j, Y g:i A', strtotime($summary['updated_at'])) . ')')
                            : 'Not written yet - summarize the interview for the adopter'; ?></span></span>
                        <i class="fa-solid fa-chevron-down rv-chev" aria-hidden="true"></i>
                    </summary>
                    <div class="rv-body">
                    <?php if ($canWriteSummary): ?>
                    <p class="meeting-link-note" style="margin:0 0 14px;">Write down what you and the adopter talked about. When you save, the adopter receives this report by notification, message, and email. Details from the application are filled in already - correct them if the adopter said otherwise.</p>
                    <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="summary-form">
                        <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                        <input type="hidden" name="action" value="save_interview_summary">
                        <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>

                        <h4 class="sf-group">Personal Information</h4>
                        <div class="sf-grid">
                            <label class="sf-field">Name <em>*</em><input type="text" name="adopter_name" maxlength="100" required value="<?php echo htmlspecialchars((string) $sv['adopter_name']); ?>"></label>
                            <label class="sf-field">Age<input type="number" name="adopter_age" min="0" max="120" value="<?php echo htmlspecialchars((string) $sv['adopter_age']); ?>"></label>
                            <label class="sf-field">Civil Status<input type="text" name="civil_status" maxlength="30" value="<?php echo htmlspecialchars((string) $sv['civil_status']); ?>"></label>
                            <label class="sf-field">Occupation<input type="text" name="occupation" maxlength="100" value="<?php echo htmlspecialchars((string) $sv['occupation']); ?>"></label>
                            <label class="sf-field">Contact Number<input type="text" name="contact_number" maxlength="30" value="<?php echo htmlspecialchars((string) $sv['contact_number']); ?>"></label>
                            <label class="sf-field sf-wide">Address<textarea name="address" rows="2" maxlength="500"><?php echo htmlspecialchars((string) $sv['address']); ?></textarea></label>
                        </div>

                        <h4 class="sf-group">Home and Household</h4>
                        <div class="sf-grid">
                            <label class="sf-field">Type of Residence
                                <select name="residence_type">
                                    <option value="">- Select -</option>
                                    <?php foreach (SP_SUMMARY_RESIDENCE_TYPES as $opt): ?>
                                        <option<?php echo $sv['residence_type'] === $opt ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="sf-field">Landlord Allows Pets?
                                <select name="landlord_allows_pets">
                                    <option value="">- Select -</option>
                                    <?php foreach (SP_SUMMARY_LANDLORD as $opt): ?>
                                        <option<?php echo $sv['landlord_allows_pets'] === $opt ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="sf-field sf-wide">Household Members<textarea name="household_members" rows="2" placeholder="e.g., Lives with spouse and 2 children (ages 8 and 12)"><?php echo htmlspecialchars((string) $sv['household_members']); ?></textarea></label>
                            <label class="sf-field sf-wide">Other Pets at Home<textarea name="other_pets" rows="2" placeholder="e.g., 1 vaccinated cat, 3 years old"><?php echo htmlspecialchars((string) $sv['other_pets']); ?></textarea></label>
                        </div>

                        <h4 class="sf-group">Pet Care</h4>
                        <div class="sf-grid">
                            <label class="sf-field sf-wide">Experience with Pets<textarea name="pet_experience" rows="2" placeholder="e.g., Had a dog for 10 years"><?php echo htmlspecialchars((string) $sv['pet_experience']); ?></textarea></label>
                            <label class="sf-field sf-wide">Reason for Adopting<textarea name="reason_for_adopting" rows="2" placeholder="One point per line"><?php echo htmlspecialchars((string) $sv['reason_for_adopting']); ?></textarea></label>
                            <label class="sf-field sf-wide">Daily Care Plan (who takes care of the pet)<textarea name="care_plan" rows="2"><?php echo htmlspecialchars((string) $sv['care_plan']); ?></textarea></label>
                            <label class="sf-field">Hours the Pet Is Left Alone (per day)<input type="number" name="hours_alone" min="0" max="24" value="<?php echo htmlspecialchars((string) $sv['hours_alone']); ?>"></label>
                            <label class="sf-field">Monthly Budget for the Pet<input type="text" name="monthly_budget" maxlength="100" placeholder="e.g., PHP 2,000 for food and vet" value="<?php echo htmlspecialchars((string) $sv['monthly_budget']); ?>"></label>
                        </div>

                        <h4 class="sf-group">Discussion</h4>
                        <p class="sf-tip"><i class="fa-solid fa-list-ul"></i>Write one point per line. Each line is shown to the adopter as a bullet. You can write in Tagalog or Taglish: the notes are saved in clear, professional English.</p>
                        <div class="sf-grid">
                            <label class="sf-field sf-wide">Summary of the Discussion <em>*</em><textarea name="discussion_notes" rows="5" maxlength="5000" required placeholder="One point per line, for example:&#10;Discussed the application and the household questions&#10;Adopter can provide for the pet&#10;Barangay certificate must be replaced"><?php echo htmlspecialchars((string) $sv['discussion_notes']); ?></textarea></label>
                            <label class="sf-field sf-wide">Questions or Concerns of the Adopter<textarea name="adopter_questions" rows="2" placeholder="One point per line: the question, then how you answered it"><?php echo htmlspecialchars((string) $sv['adopter_questions']); ?></textarea></label>
                            <label class="sf-field">Recommendation <em>*</em>
                                <select name="recommendation" required>
                                    <option value="">- Select -</option>
                                    <?php foreach (SP_SUMMARY_RECOMMENDATIONS as $opt): ?>
                                        <option<?php echo $sv['recommendation'] === $opt ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="sf-field sf-wide">Remarks / Next Steps<textarea name="remarks" rows="2" placeholder="One point per line, e.g. Prepare a leash and a cage before the pet meet-up"><?php echo htmlspecialchars((string) $sv['remarks']); ?></textarea></label>
                        </div>

                        <?php // Printed name at pirma ng staff na nag-interview (draw o larawan na tinatanggalan ng background) ?>
                        <h4 class="sf-group">Interviewer Signature</h4>
                        <p class="sf-tip"><i class="fa-solid fa-signature"></i>Draw your signature with your finger or mouse, or upload a photo of it on white paper. The background is removed automatically.</p>
                        <div class="sf-grid">
                            <label class="sf-field">Printed Name <em>*</em>
                                <input type="text" name="staff_printed_name" maxlength="150" required
                                       value="<?php echo htmlspecialchars((string) (($summary['staff_printed_name'] ?? '') !== '' ? $summary['staff_printed_name'] : ($_SESSION['username'] ?? ''))); ?>"
                                       placeholder="e.g., Juan P. Dela Cruz">
                            </label>
                            <div class="sf-field sf-wide">Signature <em>*</em>
                                <?php sp_signature_pad('staff_signature', sp_signature_url($summary['staff_signature'] ?? ''), 'blue', empty($summary['staff_signature'])); ?>
                            </div>
                        </div>

                        <button type="submit" class="btn-action" style="margin-top:14px;">
                            <i class="fa-solid fa-paper-plane"></i> <?php echo $summary ? 'Update and Send to Adopter' : 'Save and Send to Adopter'; ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <?php
                    // Naka-lock na (decided na ang application): basahin lang
                    $summaryRows = [
                        'Name' => $summary['adopter_name'], 'Age' => $summary['adopter_age'],
                        'Civil Status' => $summary['civil_status'], 'Occupation' => $summary['occupation'],
                        'Contact Number' => $summary['contact_number'], 'Address' => $summary['address'],
                        'Type of Residence' => $summary['residence_type'], 'Landlord Allows Pets?' => $summary['landlord_allows_pets'],
                        'Household Members' => $summary['household_members'], 'Other Pets at Home' => $summary['other_pets'],
                        'Experience with Pets' => $summary['pet_experience'], 'Reason for Adopting' => $summary['reason_for_adopting'],
                        'Daily Care Plan' => $summary['care_plan'],
                        'Hours Left Alone' => $summary['hours_alone'] !== null ? $summary['hours_alone'] . ' hrs/day' : null,
                        'Monthly Budget' => $summary['monthly_budget'], 'Summary of the Discussion' => $summary['discussion_notes'],
                        'Questions or Concerns' => $summary['adopter_questions'], 'Recommendation' => $summary['recommendation'],
                        'Remarks / Next Steps' => $summary['remarks'],
                    ];
                    ?>
                    <div class="review-grid">
                        <?php foreach ($summaryRows as $label => $value): if ($value === null || $value === '') continue; ?>
                            <div class="review-field"<?php echo (in_array($label, SP_SUMMARY_BULLET_FIELDS, true) || strlen((string) $value) > 40) ? ' style="grid-column: 1 / -1;"' : ''; ?>><label><?php echo htmlspecialchars($label); ?></label><?php echo sp_summary_value_html($label, $value); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="meeting-link-note" style="margin-top:12px;">Written by <?php echo htmlspecialchars($summary['staff_name'] ?? 'Staff'); ?>.</p>
                    <?php endif; ?>
                    <?php if ($summary): ?>
                        <?php // Pirma ng staff na nag-interview (pareho sa report ng adopter) ?>
                        <style><?php echo sp_summary_signatures_css(); ?></style>
                        <?php echo sp_summary_signatures_html($summary); ?>
                    <?php endif; ?>
                    </div>
                </details>
                <?php endif; ?>

                </div><!-- /.rv-app-card -->
                <?php if ($application['application_status'] === 'Rejected' && !empty($application['rejection_reason'])): ?>
                <div class="review-section">
                    <h3>Rejection Reason</h3>
                    <p><?php echo nl2br(htmlspecialchars($application['rejection_reason'])); ?></p>
                </div>
                <?php endif; ?>

                <?php if (!$isDecided): ?>
                <!-- ===== Desisyon: Approve (kapag tapos na ang lahat ng hakbang) o Reject ===== -->
                <section class="decision-card" aria-labelledby="decisionTitle">
                    <div class="decision-head">
                        <span class="decision-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></svg>
                        </span>
                        <div>
                            <h3 id="decisionTitle">Decision</h3>
                            <p>Approve once every step is complete, or reject and tell the adopter why.</p>
                        </div>
                    </div>
                    <div class="decision-grid">
                        <!-- Approve -->
                        <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="decision-box approve<?php echo $canApprove ? ' is-ready' : ' is-locked'; ?>">
                            <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>
                            <div class="decision-box-text">
                                <strong>Approve application</strong>
                                <span>
                                    <?php if ($canApprove): ?>
                                        All steps are done. The pet will be marked as Adopted and the adopter will be notified.
                                    <?php else: ?>
                                        Locked until all steps are done<?php echo $nextStep !== null ? '. Next: ' . htmlspecialchars($nextStep['title']) . '.' : '.'; ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($canApprove): ?>
                                <button type="submit" class="dc-btn dc-approve" onclick="return confirm('Approve this application? The pet will be marked as Adopted.');">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12.5l4 4 8-9"/></svg>
                                    Approve
                                </button>
                            <?php else: ?>
                                <!-- Hindi pa tapos ang mga hakbang: ipakita ang gabay sa halip na i-approve -->
                                <button type="button" class="dc-btn dc-approve btn-approve-locked" onclick="openApproveGuide()" title="Complete all steps first">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                                    Approve
                                </button>
                            <?php endif; ?>
                        </form>

                        <!-- Reject -->
                        <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="decision-box reject">
                            <input type="hidden" name="application_id" value="<?php echo $application['application_id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <?php if ($isAdminViewer): ?><input type="hidden" name="from" value="admin"><?php endif; ?>
                            <div class="decision-box-text">
                                <strong>Reject application</strong>
                                <span>The reason is sent to the adopter, and the pet becomes available to others again.</span>
                            </div>
                            <label class="dc-label" for="rejectReason">Reason for rejection</label>
                            <textarea id="rejectReason" name="rejection_reason" required rows="3" maxlength="1000" placeholder="e.g., The home is not safe for a large dog."></textarea>
                            <button type="submit" class="dc-btn dc-reject" onclick="return confirm('Reject this application?');">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                Reject
                            </button>
                        </form>
                    </div>
                </section>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<?php if (!$canApprove && !$isDecided): ?>
<!-- Gabay kapag pinindot ang Approve pero hindi pa tapos ang mga hakbang -->
<div class="ag-overlay" id="approveGuide" role="dialog" aria-modal="true" aria-labelledby="agTitle" hidden>
    <div class="ag-card">
        <button type="button" class="ag-close" onclick="closeApproveGuide()" aria-label="Close">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
        <div class="ag-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        </div>
        <h3 id="agTitle">This application can't be approved yet</h3>
        <p class="ag-lead">Finish these steps first. Approval unlocks once the certificate is released to the adopter.</p>
        <ol class="ag-steps">
            <?php foreach ($flowSteps as $i => $step): if ($step['key'] === 'approve') continue; ?>
                <li class="ag-step is-<?php echo $step['state']; ?>">
                    <span class="ag-num" aria-hidden="true">
                        <?php if ($step['state'] === 'done'): ?>
                            <svg viewBox="0 0 24 24"><path d="M6 12.5l4 4 8-9"/></svg>
                        <?php else: ?>
                            <?php echo $i + 1; ?>
                        <?php endif; ?>
                    </span>
                    <div class="ag-text">
                        <strong><?php echo htmlspecialchars($step['title']); ?></strong>
                        <span><?php echo htmlspecialchars($step['detail']); ?></span>
                    </div>
                    <?php if ($step['state'] === 'done'): ?>
                        <span class="ag-tag done">Done</span>
                    <?php elseif ($step['state'] === 'current'): ?>
                        <span class="ag-tag current">Next</span>
                    <?php elseif ($step['state'] === 'waiting'): ?>
                        <span class="ag-tag waiting">Waiting</span>
                    <?php elseif ($step['state'] === 'blocked'): ?>
                        <span class="ag-tag blocked">Needs attention</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="ag-note">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/></svg>
            Once the certificate is released, the application is approved automatically after <?php echo SP_AUTO_APPROVE_HOURS; ?> hours if you don't approve it first.
        </p>
        <div class="ag-actions">
            <button type="button" class="ag-btn ghost" onclick="closeApproveGuide()">Got it</button>
            <?php if ($nextStep !== null && !empty($nextStep['url'])): ?>
                <a class="ag-btn primary" href="<?php echo htmlspecialchars($nextStep['url']); ?>" onclick="closeApproveGuide()">
                    <?php echo htmlspecialchars($nextStep['action']); ?>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    function openApproveGuide() {
        const g = document.getElementById('approveGuide');
        if (!g) return;
        g.hidden = false;
        document.body.style.overflow = 'hidden';
        g.querySelector('.ag-close').focus();
    }
    function closeApproveGuide() {
        const g = document.getElementById('approveGuide');
        if (!g) return;
        g.hidden = true;
        document.body.style.overflow = '';
    }
    document.getElementById('approveGuide').addEventListener('click', function (e) { if (e.target === this) closeApproveGuide(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeApproveGuide(); });
    <?php if ($approveBlocked): ?>
    // Pinilit i-approve kahit hindi pa tapos (hal. galing sa ibang tab): ipakita agad ang gabay
    window.addEventListener('load', openApproveGuide);
    <?php endif; ?>
</script>
<?php endif; ?>

<script>
    // Mga link na papunta sa #rv-interview: buksan ang section at dumausdos doon
    (function () {
        function openTarget() {
            const id = window.location.hash.slice(1);
            const el = id ? document.getElementById(id) : null;
            if (el && el.tagName === 'DETAILS') {
                el.open = true;
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        window.addEventListener('hashchange', openTarget);
        document.querySelectorAll('a[href="#rv-interview"]').forEach(function (a) {
            a.addEventListener('click', function () { setTimeout(openTarget, 0); });
        });
        if (window.location.hash) openTarget();
    })();
</script>

<!-- Photo Preview Overlay -->
<!-- Photo viewer: i-click o i-scroll para mag-zoom, i-drag para gumalaw,
     ‹ › para lumipat sa ibang larawan, Esc para isara -->
<div id="photoPreviewOverlay" class="pv-overlay" role="dialog" aria-modal="true" aria-label="Photo viewer" hidden>
    <div class="pv-bar">
        <div class="pv-info">
            <strong id="photoPreviewCaption"></strong>
            <span id="pvCount"></span>
        </div>
        <div class="pv-tools">
            <button type="button" class="pv-btn" id="pvZoomOut" title="Zoom out (-)" aria-label="Zoom out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <span class="pv-zoom-level" id="pvZoomLevel">100%</span>
            <button type="button" class="pv-btn" id="pvZoomIn" title="Zoom in (+)" aria-label="Zoom in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button type="button" class="pv-btn" id="pvReset" title="Reset zoom (0)"><i class="fa-solid fa-expand"></i><span class="pv-btn-text">Reset</span></button>
            <button type="button" class="pv-btn pv-close" id="pvClose" title="Close (Esc)" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>
    <div class="pv-stage" id="pvStage">
        <img id="photoPreviewImg" src="" alt="" draggable="false">
        <button type="button" class="pv-nav prev" id="pvPrev" aria-label="Previous photo"><i class="fa-solid fa-chevron-left"></i></button>
        <button type="button" class="pv-nav next" id="pvNext" aria-label="Next photo"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
    <div class="pv-hint"><i class="fa-solid fa-computer-mouse"></i>&nbsp; Click or scroll to zoom &middot; Drag to move &middot; &larr; &rarr; to switch photos &middot; Esc to close</div>
</div>

<?php if ($matchBreakdown !== null): ?>
<!-- Match Breakdown Modal -->
<div id="matchBreakdownModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:680px;">
        <div class="modal-header">
            <div class="modal-title">Match Breakdown</div>
            <button type="button" class="btn btn-ghost" onclick="closeMatchBreakdownModal()" aria-label="Close">&#10005;</button>
        </div>
        <div class="modal-body">
            <div class="match-total-banner">
                <div>
                    <div style="font-size:12px; opacity:0.85; text-transform:uppercase; letter-spacing:0.4px;">Overall Match</div>
                    <div>Between <?php echo htmlspecialchars($application['full_name']); ?>'s preferences and <?php echo htmlspecialchars($application['pet_name']); ?></div>
                </div>
                <div class="score"><?php echo $matchScore; ?>%</div>
            </div>
            <div style="overflow-x:auto;">
                <table class="match-breakdown-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Adopter Prefers</th>
                            <th>This Pet</th>
                            <th>Fit</th>
                            <th style="text-align:right;">Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matchBreakdown as $row):
                            $fitPct = round($row['fit'] * 100);
                            $barClass = $fitPct >= 80 ? '' : ($fitPct >= 40 ? 'mid' : 'low');
                        ?>
                            <tr>
                                <td style="font-weight:700; color:#102a2a;"><?php echo htmlspecialchars($row['label']); ?></td>
                                <td><?php echo htmlspecialchars((string) $row['pref']); ?></td>
                                <td><?php echo htmlspecialchars((string) $row['pet']); ?></td>
                                <td>
                                    <span class="fit-bar-track"><span class="fit-bar-fill <?php echo $barClass; ?>" style="width: <?php echo $fitPct; ?>%;"></span></span>
                                    <?php echo $fitPct; ?>%
                                </td>
                                <td style="text-align:right; font-weight:700;">
                                    <?php if (!empty($row['gate'])): ?>
                                        <?php echo $row['factor'] >= 1.0 ? 'Passed' : ($row['factor'] <= 0.0 ? 'Not safe' : 'Lowers score'); ?>
                                    <?php else: ?>
                                        <?php echo $row['points']; ?> / <?php echo $row['weight']; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size:12px; color:#9ca3af; margin-top:12px;">
                Ang "Fit" ay kung gaano kalapit ang katangian ng pet sa gustong-gusto ng adopter para sa kategoryang iyon (100% = eksaktong tugma o "Any" ang preference). Ang "Points" ay ang natamo laban sa buong weight ng kategorya (60% pet preferences, 40% lifestyle). Ang Allergy Safety at Pet Kind ay "must-have": walang puntos, pero kapag hindi tugma ay binababa nila ang buong Overall Match %.
            </p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="closeMatchBreakdownModal()">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // ---- Mga tab ng application (kapareho ng Staff Dashboard) ----
    // Applicant Info (+ Guardian), Household, Photos & ID, Schedule (+ Summary).
    // Iisang tab lang ang bukas; may paliwanag ang bukas na tab sa info pill.
    (function () {
        const sections = document.querySelectorAll('.review-panel [data-step]');
        const tabs = document.querySelectorAll('.rv-tab');
        const info = document.getElementById('rvInfoText');
        if (!sections.length || !tabs.length) return;

        const KEY = 'rvStep-' + <?php echo (int) $application['application_id']; ?>;
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let current = 0;

        function go(step, opts) {
            opts = opts || {};
            step = Math.min(tabs.length, Math.max(1, step));
            if (step === current && !opts.instant) return;   // bukas na ang tab na ito
            const forward = step >= current;
            current = step;
            try { sessionStorage.setItem(KEY, String(step)); } catch (e) {}

            sections.forEach(function (s) {
                const on = parseInt(s.dataset.step, 10) === step;
                s.classList.toggle('rv-slide-hidden', !on);
                s.classList.remove('rv-slide-in', 'from-left');
                if (on) {
                    s.open = true;               // laging bukas ang laman ng tab
                    if (!opts.instant) {
                        void s.offsetWidth;      // para maulit ang animation
                        s.classList.add('rv-slide-in');
                        if (!forward) s.classList.add('from-left');
                    }
                }
            });
            tabs.forEach(function (b) {
                const on = parseInt(b.dataset.goto, 10) === step;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on && info) {
                    if (opts.instant || reduce) {
                        info.textContent = b.dataset.info;
                    } else {
                        info.classList.add('swap');
                        setTimeout(function () { info.textContent = b.dataset.info; info.classList.remove('swap'); }, 150);
                    }
                }
            });
        }

        tabs.forEach(function (b) {
            b.addEventListener('click', function () { go(parseInt(b.dataset.goto, 10)); });
        });

        // Mga link papunta sa isang section (hal. "#rv-interview"): buksan ang tab nito
        function stepOf(hash) {
            const el = hash && hash.length > 1 ? document.getElementById(hash.slice(1)) : null;
            return el && el.dataset.step ? parseInt(el.dataset.step, 10) : null;
        }
        document.addEventListener('click', function (e) {
            const a = e.target.closest('a[href^="#"]');
            const s = a ? stepOf(a.getAttribute('href')) : null;
            if (s) {
                e.preventDefault();
                go(s);
                const card = document.querySelector('.rv-app-card');
                if (card) card.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            }
        });

        let start = stepOf(location.hash);
        if (!start) { try { start = parseInt(sessionStorage.getItem(KEY), 10) || 1; } catch (e) { start = 1; } }
        go(start, { instant: true });
    })();
    // ---- Photo viewer na may zoom ----
    // Kinukuha ang listahan ng larawan mula sa carousel (parehong pagkakasunod).
    const PhotoViewer = (function () {
        const overlay = document.getElementById('photoPreviewOverlay');
        const stage = document.getElementById('pvStage');
        const img = document.getElementById('photoPreviewImg');
        const caption = document.getElementById('photoPreviewCaption');
        const counter = document.getElementById('pvCount');
        const level = document.getElementById('pvZoomLevel');
        const prevBtn = document.getElementById('pvPrev');
        const nextBtn = document.getElementById('pvNext');
        const MIN = 1, MAX = 5, STEP = 1.25, CLICK_ZOOM = 2.5;
        let photos = [];
        let index = 0, scale = 1, tx = 0, ty = 0;
        let drag = null, moved = false;

        function loadList() {
            photos = Array.prototype.map.call(document.querySelectorAll('.rv-photo-card img'), function (el) {
                return { src: el.getAttribute('src'), label: el.getAttribute('alt') || '' };
            });
        }

        function clampPan() {
            // Hindi pinapayagang mailayo ang larawan sa labas ng stage
            const maxX = Math.max(0, (img.offsetWidth * scale - stage.clientWidth) / 2);
            const maxY = Math.max(0, (img.offsetHeight * scale - stage.clientHeight) / 2);
            tx = Math.min(maxX, Math.max(-maxX, tx));
            ty = Math.min(maxY, Math.max(-maxY, ty));
        }

        function apply() {
            img.style.transform = 'translate(' + tx + 'px, ' + ty + 'px) scale(' + scale + ')';
            level.textContent = Math.round(scale * 100) + '%';
            overlay.classList.toggle('is-zoomed', scale > 1);
        }

        // Zoom na hindi gumagalaw ang bahaging nasa ilalim ng mouse (cx, cy mula sa gitna ng stage)
        function zoomTo(next, cx, cy) {
            next = Math.min(MAX, Math.max(MIN, next));
            cx = cx || 0; cy = cy || 0;
            tx = cx - (cx - tx) * (next / scale);
            ty = cy - (cy - ty) * (next / scale);
            scale = next;
            if (scale === 1) { tx = 0; ty = 0; }
            clampPan();
            apply();
        }

        function pointFromEvent(e) {
            const r = stage.getBoundingClientRect();
            return { x: e.clientX - (r.left + r.width / 2), y: e.clientY - (r.top + r.height / 2) };
        }

        function reset() { scale = 1; tx = 0; ty = 0; apply(); }

        function show(i) {
            if (!photos.length) return;
            index = (i + photos.length) % photos.length;
            img.src = photos[index].src;
            img.alt = photos[index].label;
            caption.textContent = photos[index].label;
            counter.textContent = (index + 1) + ' of ' + photos.length;
            prevBtn.hidden = nextBtn.hidden = photos.length < 2;
            reset();
        }

        function open(i) {
            loadList();
            overlay.hidden = false;
            document.body.style.overflow = 'hidden';
            show(typeof i === 'number' ? i : 0);
            document.getElementById('pvClose').focus();
        }

        function close() {
            if (overlay.hidden) return;
            overlay.hidden = true;
            document.body.style.overflow = '';
            img.src = '';
        }

        // Click: zoom sa pinindot na bahagi; click ulit para bumalik
        stage.addEventListener('click', function (e) {
            if (e.target !== img && e.target !== stage) return;
            if (moved) { moved = false; return; }
            if (e.target === stage && scale === 1) { close(); return; } // click sa labas ng larawan
            if (scale > 1) { reset(); return; }
            const p = pointFromEvent(e);
            zoomTo(CLICK_ZOOM, p.x, p.y);
        });

        // Scroll wheel: zoom in/out sa puwesto ng mouse
        stage.addEventListener('wheel', function (e) {
            e.preventDefault();
            const p = pointFromEvent(e);
            zoomTo(e.deltaY < 0 ? scale * STEP : scale / STEP, p.x, p.y);
        }, { passive: false });

        // Drag para gumalaw habang naka-zoom (mouse at touch)
        stage.addEventListener('pointerdown', function (e) {
            if (scale <= 1 || e.target.closest('.pv-nav')) return;
            drag = { x: e.clientX, y: e.clientY, tx: tx, ty: ty };
            moved = false;
            overlay.classList.add('is-dragging');
            stage.setPointerCapture(e.pointerId);
        });
        stage.addEventListener('pointermove', function (e) {
            if (!drag) return;
            const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
            if (Math.abs(dx) + Math.abs(dy) > 3) moved = true;
            tx = drag.tx + dx;
            ty = drag.ty + dy;
            clampPan();
            apply();
        });
        function endDrag() { drag = null; overlay.classList.remove('is-dragging'); }
        stage.addEventListener('pointerup', endDrag);
        stage.addEventListener('pointercancel', endDrag);

        document.getElementById('pvZoomIn').addEventListener('click', function () { zoomTo(scale * STEP); });
        document.getElementById('pvZoomOut').addEventListener('click', function () { zoomTo(scale / STEP); });
        document.getElementById('pvReset').addEventListener('click', reset);
        document.getElementById('pvClose').addEventListener('click', close);
        prevBtn.addEventListener('click', function (e) { e.stopPropagation(); show(index - 1); });
        nextBtn.addEventListener('click', function (e) { e.stopPropagation(); show(index + 1); });

        document.addEventListener('keydown', function (e) {
            if (overlay.hidden) return;
            if (e.key === 'ArrowLeft') { e.preventDefault(); show(index - 1); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); show(index + 1); }
            else if (e.key === '+' || e.key === '=') { zoomTo(scale * STEP); }
            else if (e.key === '-') { zoomTo(scale / STEP); }
            else if (e.key === '0') { reset(); }
        });
        window.addEventListener('resize', function () { if (!overlay.hidden) { clampPan(); apply(); } });

        return { open: open, close: close };
    })();

    function openPhotoPreview(i) { PhotoViewer.open(i); }
    function closePhotoPreview() { PhotoViewer.close(); }
    function openMatchBreakdownModal() {
        const modal = document.getElementById('matchBreakdownModal');
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeMatchBreakdownModal() {
        const modal = document.getElementById('matchBreakdownModal');
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closePhotoPreview();
            closeMatchBreakdownModal();
        }
    });

</script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
