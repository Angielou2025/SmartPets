<?php
/**
 * Pet Monitoring - buwanang surprise visit sa bahay ng adopter.
 * Listahan ng lahat ng natapos na adoption at ang status nila ngayong buwan,
 * at (kapag may ?application_id) ang buong kasaysayan ng mga visit sa isang pet.
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/_monitoring.php';

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
unset($_SESSION['success_message'], $_SESSION['error_message']);

$records = sp_monitoring_list($pdo);

$counts = ['due' => 0, 'visited' => 0, 'upcoming' => 0, 'ended' => 0];
foreach ($records as $r) {
    $counts[$r['state']]++;
}
$daysLeft = (int) date('t') - (int) date('j');

// Kasaysayan ng isang adoption
$detail = null;
$visits = [];
$viewId = (int) ($_GET['application_id'] ?? 0);
if ($viewId > 0) {
    foreach ($records as $r) {
        if ((int) $r['application_id'] === $viewId) {
            $detail = $r;
            break;
        }
    }
    if ($detail) {
        $vs = $pdo->prepare(
            "SELECT v.*, u.username AS recorded_by_name
             FROM pet_visits v
             LEFT JOIN users u ON u.user_id = v.recorded_by
             WHERE v.application_id = ?
             ORDER BY v.visit_date DESC, v.visit_id DESC"
        );
        $vs->execute([$viewId]);
        $visits = $vs->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Mas mahalaga muna: due (lalo na ang may na-miss), tapos upcoming, visited, ended
$order = ['due' => 0, 'upcoming' => 1, 'visited' => 2, 'ended' => 3];
usort($records, function ($a, $b) use ($order) {
    $cmp = $order[$a['state']] <=> $order[$b['state']];
    if ($cmp !== 0) { return $cmp; }
    return ($b['missed_last_month'] ? 1 : 0) <=> ($a['missed_last_month'] ? 1 : 0);
});

// Kasalukuyang detalye ng pet, para ma-prefill ang "Update pet details" sa form
function mon_pet_json(array $r): string
{
    $keys = ['age_group', 'size', 'energy_level', 'temperament', 'shedding_level', 'health_condition', 'vaccination_status', 'description'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = (string) ($r[$k] ?? '');
    }
    return htmlspecialchars(json_encode($out), ENT_QUOTES);
}

function mon_condition_class(?string $value): string
{
    switch ($value) {
        case 'Healthy':
        case 'Adequate':
        case 'Stayed with Adopter':
            return 'is-good';
        case 'Needs Attention':
            return 'is-warn';
        case 'Poor':
        case 'Inadequate':
        case 'Reclaimed by Office':
        case 'Returned by Adopter':
            return 'is-bad';
    }
    return '';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pet Monitoring - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        .mon-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
            margin-top: 20px;
        }
        .mon-note {
            background: #fff8e6;
            border: 1px solid #f0d9a8;
            color: #7a5a1a;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13.5px;
            margin-bottom: 16px;
            line-height: 1.55;
        }
        .mon-search {
            width: 100%;
            max-width: 340px;
            padding: 10px 14px;
            border: 1px solid #e6e1d8;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            margin-bottom: 16px;
        }
        .mon-table { width: 100%; border-collapse: collapse; text-align: left; min-width: 900px; }
        .mon-table th {
            padding: 12px 10px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #f1f1f1;
        }
        .mon-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f8f8f8;
            vertical-align: middle;
            font-size: 14px;
            color: #102a2a;
        }
        .mon-table tr:hover td { background: #fbfaf7; }

        .pet-cell { display: flex; align-items: center; gap: 10px; }
        .pet-thumb {
            width: 42px; height: 42px;
            border-radius: 10px;
            object-fit: cover;
            background: #eef2f0;
            flex-shrink: 0;
        }
        .sub-note { font-size: 12px; color: #9aa6a3; margin-top: 2px; }

        .mon-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .mon-badge.state-due { background: #fef3d6; color: #b25900; }
        .mon-badge.state-visited { background: #e6f4ea; color: #1e7e34; }
        .mon-badge.state-upcoming { background: #e0edff; color: #1a56db; }
        .mon-badge.state-ended { background: #f1f1f1; color: #6b7280; }
        .missed { display: block; margin-top: 4px; font-size: 11.5px; font-weight: 700; color: #c5221f; }

        .tag { display: inline-block; padding: 2px 9px; border-radius: 12px; font-size: 11.5px; font-weight: 700; background: #f1f5f9; color: #475569; }
        .tag.is-good { background: #e6f4ea; color: #1e7e34; }
        .tag.is-warn { background: #fef3d6; color: #b25900; }
        .tag.is-bad { background: #fce8e6; color: #c5221f; }

        .action-stack { display: inline-flex; align-items: center; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        .btn-visit, .btn-history {
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
        .btn-visit { background: #1f6b48; color: #fff; }
        .btn-visit:hover { background: #17573a; transform: translateY(-1px); }
        .btn-history { background: #fff; color: #1f6b48; border: 1px solid #1f6b48; }
        .btn-history:hover { background: #1f6b48; color: #fff; transform: translateY(-1px); }

        /* Kasaysayan */
        .back-link { display: inline-flex; align-items: center; gap: 7px; margin-bottom: 14px; height: 36px; padding: 0 14px 0 11px; border: 1px solid #c7d2fe; border-radius: 10px; background: #eef2ff; color: #1f6b48; font-weight: 700; font-size: 13px; text-decoration: none; transition: background .15s, border-color .15s, box-shadow .15s; }
        .back-link svg { width: 15px; height: 15px; flex: none; transition: transform .15s; }
        .back-link:hover { background: #e0e7ff; border-color: #a5b4fc; box-shadow: 0 2px 6px rgba(30, 58, 138, .12); }
        .back-link:hover svg { transform: translateX(-2px); }
        .back-link:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(59, 91, 219, .25); }
        .detail-head { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
        .detail-head .pet-thumb { width: 72px; height: 72px; border-radius: 14px; }
        .detail-head h2 { margin: 0 0 4px; font-size: 22px; font-weight: 800; }
        .detail-head .grow { flex: 1; min-width: 220px; }
        .detail-info { font-size: 13.5px; color: #475569; line-height: 1.6; }

        .visit-list { display: flex; flex-direction: column; gap: 12px; }
        .visit-card {
            display: flex;
            gap: 16px;
            border: 1px solid #eef0f3;
            border-left: 5px solid #1f6b48;
            border-radius: 12px;
            padding: 14px 16px;
            flex-wrap: wrap;
        }
        .visit-card.ended { border-left-color: #c5221f; }
        .visit-photo { width: 120px; height: 120px; border-radius: 10px; object-fit: cover; cursor: zoom-in; flex-shrink: 0; }
        .visit-body { flex: 1; min-width: 220px; }
        .visit-date { font-weight: 800; font-size: 15px; color: #102a2a; }
        .visit-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 8px 16px;
            margin: 10px 0;
            font-size: 13px;
        }
        .visit-grid .lbl { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #9aa6a3; margin-bottom: 2px; }
        .visit-remarks { font-size: 13.5px; color: #374151; line-height: 1.55; white-space: pre-line; }
        .empty-visits { text-align: center; padding: 34px 16px; color: #9aa6a3; }

        /* Form */
        .visit-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .visit-form-grid .full { grid-column: 1 / -1; }
        .visit-form-grid label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #102a2a; }
        .visit-form-grid input, .visit-form-grid select, .visit-form-grid textarea {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            color: #102a2a;
            box-sizing: border-box;
        }
        .visit-form-grid textarea { min-height: 90px; resize: vertical; }
        .inline-check { display: flex !important; align-items: center; gap: 8px; margin-top: 8px; font-weight: 600; font-size: 13px; cursor: pointer; }
        .inline-check input { width: auto !important; }
        .pet-update {
            margin-top: 16px;
            border: 1px solid #d7e8dc;
            border-radius: 12px;
            padding: 4px 14px;
            background: #f8fbf9;
        }
        .pet-update summary { cursor: pointer; padding: 10px 0; font-weight: 800; color: #1f6b48; font-size: 14px; }
        .pet-update summary span { font-weight: 400; color: #6b7280; font-size: 12.5px; }
        .pet-update[open] { padding-bottom: 14px; }
        .visit-changes { margin-top: 10px; font-size: 13px; color: #1f3d2c; background: #f0f7f3; border-radius: 8px; padding: 8px 12px; white-space: pre-line; }
        .result-warn { display: none; margin-top: 6px; font-size: 12.5px; color: #c5221f; font-weight: 600; }
        .return-reason-box { display: none; background: #fff6f5; border: 1px solid #f3c7c2; border-radius: 10px; padding: 10px 12px; }
        .return-reason-box.show { display: block; }
        .adopter-visible { display: inline-flex; align-items: center; gap: 4px; margin-left: 6px; font-size: 11px; font-weight: 700; color: #b3261e; background: #fdecea; padding: 2px 8px; border-radius: 999px; }
        .visit-return-reason { margin-top: 10px; font-size: 13px; color: #7a1d17; background: #fdecea; border-radius: 8px; padding: 8px 12px; white-space: pre-line; }

        .photo-viewer {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.8);
            display: none;
            align-items: center; justify-content: center;
            z-index: 10002;
            padding: 20px;
        }
        .photo-viewer.show { display: flex; }
        .photo-viewer img { max-width: 100%; max-height: 100%; border-radius: 10px; }

        /* ===================== Bagong itsura (kulay ng staff) ===================== */
        .mon-panel { border: 1px solid #eef0f3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05); scroll-margin-top: 16px; }
        .mon-panel.mon-flash { animation: monFlash 1s ease; }
        @keyframes monFlash { 0% { box-shadow: 0 0 0 0 rgba(47, 91, 196, 0.45); } 100% { box-shadow: 0 0 0 14px rgba(47, 91, 196, 0); } }
        .mon-panel-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .mon-title-icon { width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .mon-title-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
        .mon-panel-head h3 { margin: 0; font-size: 18px; font-weight: 800; color: #0f1b3d; }
        .mon-sub { font-size: 13px; color: #6b7280; }

        /* Paalala sa surprise visit */
        .mon-note {
            display: flex !important; gap: 12px; align-items: flex-start;
            background: #f5f8ff !important; border: 1px solid #dbe5f8 !important; border-left: 4px solid #2f5bc4 !important;
            color: #334155 !important; border-radius: 12px !important; padding: 12px 14px !important; font-size: 13.5px; line-height: 1.55;
        }
        .mon-note strong { color: #0f1b3d; }
        .mon-note-icon { flex-shrink: 0; width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; background: #e3ebfb; color: #1e3a8a; }
        .mon-note-icon svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
        .mon-days-left { display: inline-block; margin-left: 4px; padding: 1px 8px; border-radius: 999px; background: #fef3c7; color: #92400e; font-weight: 700; font-size: 12px; }

        /* Toolbar */
        .mon-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 14px 0; }
        .mon-search-box {
            display: flex; align-items: center; gap: 8px; flex: 1 1 280px; max-width: 400px; height: 40px; padding: 0 12px;
            border-radius: 10px; background: #f6f7f9; border: 1.5px solid transparent; color: #9aa3ad;
            transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
        }
        .mon-search-box:focus-within { background: #fff; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        .mon-search-box svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
        .mon-search-box input { flex: 1; min-width: 0; border: none; background: transparent; outline: none; font-family: inherit; font-size: 14px; color: #102a2a; }
        .mon-filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 4px; border-radius: 12px; background: #f3f5f8; }
        .mon-filter {
            display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border: none; border-radius: 9px;
            background: transparent; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 700; color: #5b6472;
            transition: background-color .2s ease, color .2s ease, box-shadow .2s ease;
        }
        .mon-filter span { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #e3e7ee; color: #475569; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; }
        .mon-filter:hover { color: #1e3a8a; }
        .mon-filter.active { background: #fff; color: #1e3a8a; box-shadow: 0 2px 6px rgba(15, 27, 61, 0.1); }
        .mon-filter.active span { background: #1e3a8a; color: #fff; }

        /* Table */
        .mon-table-wrap { overflow-x: auto; border: 1px solid #eef0f3; border-radius: 12px; }
        .mon-table th { background: #f8f9fb; color: #6b7280 !important; font-size: 11.5px !important; letter-spacing: .6px; padding: 12px 14px !important; border-bottom: 1px solid #eef0f3 !important; white-space: nowrap; }
        .mon-table td { padding: 12px 14px !important; border-bottom: 1px solid #f2f4f6 !important; }
        .mon-table tbody tr:last-child td { border-bottom: none !important; }
        .mon-table tr:hover td { background: #f7f9fe !important; }
        .mon-table td strong { color: #0f1b3d; }
        .pet-thumb { border-radius: 12px !important; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1); }
        .mon-empty { text-align: center; padding: 40px 10px !important; color: #9aa3ad; font-size: 14px; }
        .mon-row[hidden] { display: none !important; }

        /* Status badge na may tuldok */
        .mon-badge { display: inline-flex !important; align-items: center; gap: 6px; padding: 4px 10px 4px 9px !important; }
        .mon-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .mon-badge.state-due { background: #fef3c7 !important; color: #92400e !important; }
        .mon-badge.state-visited { background: #dcfce7 !important; color: #166534 !important; }
        .mon-badge.state-upcoming { background: #eef2fb !important; color: #1e3a8a !important; }

        /* Mga button */
        .btn-visit, .btn-history { height: 34px; display: inline-flex; align-items: center; padding: 0 14px !important; border-radius: 9px !important; font-family: inherit; }
        .btn-visit { background: linear-gradient(135deg, #2a62de, #1e3a8a) !important; color: #fff !important; border: none !important; box-shadow: 0 4px 10px rgba(30, 58, 138, 0.22); }
        .btn-visit:hover { filter: brightness(1.08); }
        .btn-history { background: #fff !important; color: #1e3a8a !important; border: 1.5px solid #dbe3f4 !important; }
        .btn-history:hover { background: #eef2fb !important; color: #1e3a8a !important; }

        /* History view ng isang alaga */
        .back-link { color: #1e3a8a !important; }
        .detail-head h2 { color: #0f1b3d; }
        .visit-card { border-left-color: #2f5bc4 !important; border-radius: 12px !important; }
        .visit-card.ended { border-left-color: #c5221f !important; }
        .visit-date { color: #0f1b3d !important; }
        .visit-changes { background: #f5f8ff !important; color: #1e3a8a !important; }

        @media (max-width: 560px) {
            .visit-form-grid { grid-template-columns: 1fr; }
        }

        /* ===== Telepono: maayos na sukat at pagkakaayos ng paalala, search at mga tab ===== */
        @media (max-width: 600px) {
            .mon-panel { padding: 14px; }
            .mon-panel-head { gap: 10px; margin-bottom: 12px; }
            .mon-title-icon { width: 34px; height: 34px; border-radius: 9px; }
            .mon-title-icon svg { width: 17px; height: 17px; }
            .mon-panel-head h3 { font-size: 16px; }
            .mon-sub { font-size: 12px; line-height: 1.4; }

            /* Paalala: mas maliit na text at icon; ang "days left" ay nasa sariling linya */
            .mon-note { gap: 10px; padding: 10px 12px !important; font-size: 12.5px; line-height: 1.5; }
            .mon-note-icon { width: 26px; height: 26px; border-radius: 8px; }
            .mon-note-icon svg { width: 14px; height: 14px; }
            .mon-days-left { display: table; margin: 6px 0 0; font-size: 11.5px; padding: 2px 9px; }

            /* Search: buong lapad */
            .mon-toolbar { gap: 10px; margin: 12px 0; }
            .mon-search-box { flex: 1 1 100%; max-width: none; height: 42px; }
            .mon-search-box input { font-size: 16px; } /* 16px: walang auto-zoom sa iPhone */

            /* Mga tab: sakop ang buong lapad - lumalaki ang bawat tab para punuin ang hanay,
               kaya pantay ang dalawang hanay (walang naputol o nakatagong tab) */
            .mon-filters { flex: 1 1 100%; gap: 4px; }
            /* flex-basis 30%: laging 3 sa itaas at 2 sa ibaba (lumalaki para punuin ang hanay) */
            .mon-filter { flex: 1 1 30%; justify-content: center; height: 34px; padding: 0 8px; font-size: 12.5px; white-space: nowrap; }
            .mon-filter span { min-width: 18px; height: 18px; font-size: 10.5px; padding: 0 5px; }

            /* Puting panel: buong lapad ng screen. May tig-12px na padding ang
               .main-wrapper at .dashboard-content (24px lahat), kaya hinihila palabas ng -24px */
            .mon-panel { margin-left: -24px; margin-right: -24px; border-radius: 0 !important; border-left: none !important; border-right: none !important; padding: 16px; }

            /* Mga card ng adopted pets: pakaliwa/pakanan (swipe) imbes na pababa.
               Ang tbody ang nag-i-scroll nang pahalang; bawat card ay humihinto sa gitna. */
            .mon-table-wrap { overflow: visible; border: none; border-radius: 0; }
            table.sp-card-table.mon-table tbody#monBody {
                display: flex !important;
                gap: 12px;
                overflow-x: auto;
                scroll-snap-type: x mandatory;
                scroll-padding: 0 16px;
                margin: 0 -16px;               /* hanggang sa gilid ng screen ang pag-swipe */
                padding: 2px 16px 10px;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }
            table.sp-card-table.mon-table tbody#monBody::-webkit-scrollbar { display: none; }
            table.sp-card-table.mon-table tbody#monBody > tr {
                flex: 0 0 86%;                 /* sumisilip ang susunod na card sa kanan */
                margin: 0 !important;
                scroll-snap-align: center;
                box-sizing: border-box;
            }
            /* Walang laman / walang tugma: buong lapad pa rin */
            table.sp-card-table.mon-table tbody#monBody > tr:not(.mon-row) { flex-basis: 100%; }

            /* Bilang at paalala sa ilalim: "2 / 6 · Swipe to see more" */
            .mon-swipe {
                display: flex; align-items: center; justify-content: center; gap: 8px;
                margin-top: 6px; font-size: 12px; font-weight: 700; color: #6b7280;
            }
            .mon-swipe[hidden] { display: none; }
            .mon-swipe b { color: #1e3a8a; }
            .mon-swipe .mon-swipe-hint { font-weight: 600; color: #9aa3ad; }
        }
        @media (min-width: 601px) { .mon-swipe { display: none !important; } }
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
            <header class="sp-page-head">
                <span class="sp-page-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 11l9-7.5L21 11"/>
                        <path d="M5.5 9.2V20h13V9.2"/>
                        <path class="ic-fill" d="M12 17.5s-3.2-2-3.2-4.3a1.7 1.7 0 0 1 3.2-.9 1.7 1.7 0 0 1 3.2.9c0 2.3-3.2 4.3-3.2 4.3z"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Pet Monitoring</h1>
                    <p>Monthly surprise visits to check on adopted pets</p>
                </div>
            </header>

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

            <?php if ($detail): ?>

                <!-- ===== Kasaysayan ng isang adoption ===== -->
                <div class="mon-panel">
                    <a class="back-link" href="pet_monitoring.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>Back to Pet Monitoring</a>

                    <div class="detail-head">
                        <img class="pet-thumb" src="/Caps2/uploads/<?php echo htmlspecialchars((string) $detail['photo_url']); ?>" alt="" onerror="this.style.visibility='hidden'">
                        <div class="grow">
                            <h2><?php echo htmlspecialchars($detail['pet_name']); ?></h2>
                            <div class="detail-info">
                                <?php echo htmlspecialchars(trim($detail['kind'] . ' · ' . $detail['breed'], ' ·')); ?><br>
                                Adopter: <strong><?php echo htmlspecialchars($detail['full_name']); ?></strong> · <?php echo htmlspecialchars($detail['phone_number']); ?><br>
                                Address: <?php echo htmlspecialchars($detail['address']); ?><br>
                                Adopted <?php echo date('M j, Y', strtotime($detail['payment_date'])); ?> · <?php echo (int) $detail['total_visits']; ?> visit<?php echo (int) $detail['total_visits'] === 1 ? '' : 's'; ?> recorded
                            </div>
                        </div>
                        <?php if ($detail['state'] !== 'ended'): ?>
                            <button type="button" class="btn-visit"
                                    data-app="<?php echo (int) $detail['application_id']; ?>"
                                    data-pet="<?php echo htmlspecialchars($detail['pet_name']); ?>"
                                    data-adopter="<?php echo htmlspecialchars($detail['full_name']); ?>"
                                    data-min="<?php echo date('Y-m-d', strtotime($detail['payment_date'])); ?>"
                                    data-details="<?php echo mon_pet_json($detail); ?>"
                                    data-back="history">+ Record Visit</button>
                        <?php else: ?>
                            <span class="mon-badge state-ended">Monitoring ended</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($visits): ?>
                        <div class="visit-list">
                            <?php foreach ($visits as $v): ?>
                                <div class="visit-card<?php echo $v['result'] !== 'Stayed with Adopter' ? ' ended' : ''; ?>">
                                    <?php if (!empty($v['photo_url'])): ?>
                                        <img class="visit-photo" src="/Caps2/uploads/<?php echo htmlspecialchars($v['photo_url']); ?>" alt="Photo taken during the visit">
                                    <?php endif; ?>
                                    <div class="visit-body">
                                        <div class="visit-date"><?php echo date('F j, Y', strtotime($v['visit_date'])); ?></div>
                                        <div class="sub-note">Recorded by <?php echo htmlspecialchars($v['recorded_by_name'] ?: 'Unknown'); ?></div>
                                        <div class="visit-grid">
                                            <div><span class="lbl">Pet Condition</span><span class="tag <?php echo mon_condition_class($v['pet_condition']); ?>"><?php echo htmlspecialchars($v['pet_condition']); ?></span></div>
                                            <div><span class="lbl">Living Condition</span><span class="tag <?php echo mon_condition_class($v['living_condition']); ?>"><?php echo htmlspecialchars($v['living_condition']); ?></span></div>
                                            <div><span class="lbl">Result</span><span class="tag <?php echo mon_condition_class($v['result']); ?>"><?php echo htmlspecialchars($v['result']); ?></span></div>
                                        </div>
                                        <?php if (!empty($v['return_reason'])): ?>
                                            <div class="visit-return-reason"><strong>Reason shared with the adopter:</strong>
<?php echo htmlspecialchars($v['return_reason']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($v['remarks'])): ?>
                                            <div class="visit-remarks"><span class="lbl" style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;color:#9aa6a3;margin-bottom:2px;">Staff Notes</span><?php echo htmlspecialchars($v['remarks']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($v['pet_changes'])): ?>
                                            <div class="visit-changes"><strong>Pet details updated:</strong>
<?php echo htmlspecialchars($v['pet_changes']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-visits">No visits recorded yet.</div>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <!-- ===== Listahan ===== -->
                <!-- Mga stat card: madilim hanggang maliwanag; sinasala ang table sa ibaba -->
                <div class="cards-grid sp-cards">
                    <a class="stat-card sp-card sp-c1" href="#monTable" data-mon-filter="due" title="Show pets due for a visit">
                        <div class="stat-info">
                            <h1><?php echo str_pad($counts['due'], 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Due This Month</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Bahay na may tandang padamdam -->
                            <svg viewBox="0 0 24 24">
                                <path d="M3 11l9-7.5L21 11"/>
                                <path class="ic-fill" d="M5.5 9.2V20h13V9.2L12 4z"/>
                                <path d="M12 10.5v4M12 17h.01"/>
                            </svg>
                        </div>
                    </a>
                    <a class="stat-card sp-card sp-c2" href="#monTable" data-mon-filter="visited" title="Show pets visited this month">
                        <div class="stat-info">
                            <h1><?php echo str_pad($counts['visited'], 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Visited This Month</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Clipboard na may check -->
                            <svg viewBox="0 0 24 24">
                                <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/>
                                <rect class="ic-fill" x="9" y="2.5" width="6" height="3.5" rx="1"/>
                                <path d="M9 12.5l2 2 4-4"/>
                            </svg>
                        </div>
                    </a>
                    <a class="stat-card sp-card sp-c3" href="#monTable" data-mon-filter="upcoming" title="Show pets starting next month">
                        <div class="stat-info">
                            <h1><?php echo str_pad($counts['upcoming'], 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Starts Next Month</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Calendar na may arrow -->
                            <svg viewBox="0 0 24 24">
                                <rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/>
                                <path d="M3.5 9.5h17M8 3v4M16 3v4"/>
                                <path d="M9 15h6M13 12.8l2.2 2.2-2.2 2.2"/>
                            </svg>
                        </div>
                    </a>
                    <a class="stat-card sp-card sp-c4" href="#monTable" data-mon-filter="ended" title="Show pets whose monitoring ended">
                        <div class="stat-info">
                            <h1><?php echo str_pad($counts['ended'], 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Monitoring Ended</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Folder na may check -->
                            <svg viewBox="0 0 24 24">
                                <path class="ic-fill" d="M3.5 7a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>
                                <path d="M9 13.8l2 2 4-4"/>
                            </svg>
                        </div>
                    </a>
                </div>

                <div class="mon-panel" id="monTable">
                    <div class="mon-panel-head">
                        <span class="mon-title-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                        </span>
                        <div>
                            <h3>Adopted Pets</h3>
                            <span class="mon-sub">One surprise visit every month, starting the month after the adoption</span>
                        </div>
                    </div>

                    <div class="mon-note">
                        <span class="mon-note-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                        </span>
                        <div>
                            <strong>Surprise visits are unannounced.</strong> Do not tell the adopter ahead of time.
                            Each adopted pet needs one visit every month, starting the month after the adoption.
                            <?php if ($counts['due'] > 0): ?>
                                <span class="mon-days-left"><?php echo $daysLeft === 0 ? 'Today is the last day of the month.' : $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left this month.'; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Toolbar: search + filter (sa browser lang, walang reload) -->
                    <div class="mon-toolbar">
                        <label class="mon-search-box">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
                            <input type="search" id="monSearch" placeholder="Search by adopter, pet, or address..." aria-label="Search adopted pets" autocomplete="off">
                        </label>
                        <div class="mon-filters" role="group" aria-label="Filter by visit status">
                            <button type="button" class="mon-filter active" data-filter="all">All</button>
                            <button type="button" class="mon-filter" data-filter="due">Visit Due <span><?php echo (int) $counts['due']; ?></span></button>
                            <button type="button" class="mon-filter" data-filter="visited">Visited <span><?php echo (int) $counts['visited']; ?></span></button>
                            <button type="button" class="mon-filter" data-filter="upcoming">Upcoming <span><?php echo (int) $counts['upcoming']; ?></span></button>
                            <button type="button" class="mon-filter" data-filter="ended">Ended <span><?php echo (int) $counts['ended']; ?></span></button>
                        </div>
                    </div>

                    <div class="mon-table-wrap">
                        <table class="mon-table">
                            <thead>
                                <tr>
                                    <th>Pet</th>
                                    <th>Adopter</th>
                                    <th>Adopted</th>
                                    <th>Last Visit</th>
                                    <th>This Month</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="monBody">
                                <?php if ($records): ?>
                                    <?php foreach ($records as $r):
                                        $monSearch = mb_strtolower(implode(' ', [$r['pet_name'] ?? '', $r['kind'] ?? '', $r['full_name'] ?? '', $r['address'] ?? '', $r['phone_number'] ?? '']));
                                    ?>
                                        <tr class="mon-row" data-status="<?php echo htmlspecialchars($r['state']); ?>" data-search="<?php echo htmlspecialchars($monSearch); ?>">
                                            <td>
                                                <div class="pet-cell">
                                                    <img class="pet-thumb" src="/Caps2/uploads/<?php echo htmlspecialchars((string) $r['photo_url']); ?>" alt="" onerror="this.style.visibility='hidden'">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($r['pet_name']); ?></strong>
                                                        <div class="sub-note"><?php echo htmlspecialchars($r['kind']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($r['full_name']); ?></strong>
                                                <div class="sub-note"><?php echo htmlspecialchars($r['address']); ?></div>
                                                <div class="sub-note"><?php echo htmlspecialchars($r['phone_number']); ?></div>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($r['payment_date'])); ?></td>
                                            <td>
                                                <?php if (!empty($r['last_visit_date'])): ?>
                                                    <?php echo date('M j, Y', strtotime($r['last_visit_date'])); ?>
                                                    <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">
                                                        <span class="tag <?php echo mon_condition_class($r['last_pet_condition']); ?>"><?php echo htmlspecialchars($r['last_pet_condition']); ?></span>
                                                        <span class="tag <?php echo mon_condition_class($r['last_living_condition']); ?>"><?php echo htmlspecialchars($r['last_living_condition']); ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="sub-note">No visits yet</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($r['state'] === 'due'): ?>
                                                    <span class="mon-badge state-due">Visit Due</span>
                                                    <?php if ($r['missed_last_month']): ?><span class="missed">No visit last month</span><?php endif; ?>
                                                <?php elseif ($r['state'] === 'visited'): ?>
                                                    <span class="mon-badge state-visited">Visited</span>
                                                <?php elseif ($r['state'] === 'upcoming'): ?>
                                                    <span class="mon-badge state-upcoming">Starts <?php echo date('M Y', strtotime($r['first_visit_month'] . '-01')); ?></span>
                                                <?php else: ?>
                                                    <span class="mon-badge state-ended">Ended</span>
                                                    <div class="sub-note"><?php echo htmlspecialchars($r['last_result']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <div class="action-stack">
                                                    <a class="btn-history" href="pet_monitoring.php?application_id=<?php echo (int) $r['application_id']; ?>">History</a>
                                                    <?php if ($r['state'] !== 'ended'): ?>
                                                        <button type="button" class="btn-visit"
                                                                data-app="<?php echo (int) $r['application_id']; ?>"
                                                                data-pet="<?php echo htmlspecialchars($r['pet_name']); ?>"
                                                                data-adopter="<?php echo htmlspecialchars($r['full_name']); ?>"
                                                                data-min="<?php echo date('Y-m-d', strtotime($r['payment_date'])); ?>"
                                    data-details="<?php echo mon_pet_json($r); ?>"
                                                                data-back="list">Record Visit</button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="mon-empty">No adopted pets to monitor yet. Pets appear here once an adoption is approved.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($records): ?>
                                    <tr id="monNoResults" hidden>
                                        <td colspan="6" class="mon-empty">No pets found matching your search.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php // Telepono lang: pang-ilang card at paalala na puwedeng i-swipe ?>
                    <div class="mon-swipe" id="monSwipe" hidden aria-live="polite">
                        <span><b id="monSwipeNow">1</b> / <span id="monSwipeTotal">0</span></span>
                        <span class="mon-swipe-hint">· Swipe to see more &rarr;</span>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Record Visit Modal -->
<div id="visitModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:560px;">
        <div class="modal-header">
            <div class="modal-title">Record Surprise Visit</div>
            <button type="button" class="btn btn-ghost" onclick="closeVisitModal()" aria-label="Close">✕</button>
        </div>
        <form method="POST" action="process_add_visit.php" enctype="multipart/form-data">
            <div class="modal-body">
                <p class="sub-note" id="visitModalSubtitle" style="margin:0 0 12px 0;font-size:13px;"></p>
                <input type="hidden" name="application_id" id="visit_application_id">
                <input type="hidden" name="back" id="visit_back" value="list">
                <div class="visit-form-grid">
                    <div>
                        <label for="visit_date">Visit Date *</label>
                        <input type="date" name="visit_date" id="visit_date" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <label for="pet_condition">Pet Condition *</label>
                        <select name="pet_condition" id="pet_condition" required>
                            <option value="">Select...</option>
                            <option value="Healthy">Healthy</option>
                            <option value="Needs Attention">Needs Attention</option>
                            <option value="Poor">Poor</option>
                        </select>
                    </div>
                    <div>
                        <label for="living_condition">Living Condition *</label>
                        <select name="living_condition" id="living_condition" required>
                            <option value="">Select...</option>
                            <option value="Adequate">Adequate</option>
                            <option value="Inadequate">Inadequate</option>
                        </select>
                    </div>
                    <div>
                        <label for="visit_result">Result *</label>
                        <select name="result" id="visit_result" required>
                            <option value="Stayed with Adopter">Stayed with Adopter</option>
                            <option value="Reclaimed by Office">Reclaimed by Office</option>
                            <option value="Returned by Adopter">Returned by Adopter</option>
                        </select>
                    </div>
                    <div class="full">
                        <div class="result-warn" id="resultWarn">This will end the monitoring for this adoption, and the pet will be set back to Available in Pet Inventory so it can be adopted again.</div>
                    </div>
                    <!-- Lumalabas lang kapag Returned / Reclaimed. Nakikita ito ng adopter. -->
                    <div class="full return-reason-box" id="returnReasonBox">
                        <label for="return_reason">Reason for return / reclaim * <span class="adopter-visible">👁 The adopter will see this</span></label>
                        <textarea name="return_reason" id="return_reason" maxlength="1000" placeholder="Explain clearly and respectfully why the pet is going back to the shelter. Example: The adopter is moving abroad and can no longer care for the pet."></textarea>
                    </div>
                    <div class="full">
                        <label for="visit_remarks">Staff Notes</label>
                        <textarea name="remarks" id="visit_remarks" placeholder="What did you observe during the visit?"></textarea>
                    </div>
                    <div class="full">
                        <label for="visit_photo">Photo (optional)</label>
                        <input type="file" name="photo" id="visit_photo" accept="image/*">
                        <div class="sub-note">A photo of the pet taken during the visit. Max 5 MB.</div>
                        <label class="inline-check" id="usePhotoWrap">
                            <input type="checkbox" name="use_as_pet_photo" value="1"> Use this photo as the pet's profile photo
                        </label>
                    </div>
                </div>

                <details class="pet-update" id="petUpdate">
                    <summary>Update pet details <span>(optional, based on what you observed)</span></summary>
                    <input type="hidden" name="update_pet" id="update_pet" value="0">
                    <p class="sub-note" style="margin:0 0 12px;">These are the pet's current details. Change only what is different now, for example if the pet has grown or its health has changed.</p>
                    <div class="visit-form-grid">
                        <div>
                            <label for="pu_age_group">Age Group</label>
                            <select name="age_group" id="pu_age_group">
                                <option value="Puppy/Kitten">Puppy/Kitten</option>
                                <option value="Young Adult">Young Adult</option>
                                <option value="Adult">Adult</option>
                                <option value="Senior">Senior</option>
                            </select>
                        </div>
                        <div>
                            <label for="pu_size">Size</label>
                            <select name="size" id="pu_size">
                                <option value="Small">Small</option>
                                <option value="Medium">Medium</option>
                                <option value="Large">Large</option>
                            </select>
                        </div>
                        <div>
                            <label for="pu_energy_level">Energy Level</label>
                            <select name="energy_level" id="pu_energy_level">
                                <option value="Low">Low</option>
                                <option value="Moderate">Moderate</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div>
                            <label for="pu_health_condition">Health Condition</label>
                            <select name="health_condition" id="pu_health_condition">
                                <option value="Healthy">Healthy</option>
                                <option value="Elderly">Elderly</option>
                                <option value="Partially Blind/Deaf">Partially Blind/Deaf</option>
                                <option value="Medical Condition">Medical Condition</option>
                                <option value="Recovering/Injured">Recovering/Injured</option>
                            </select>
                        </div>
                        <div>
                            <label for="pu_vaccination_status">Vaccination Status</label>
                            <select name="vaccination_status" id="pu_vaccination_status">
                                <option value="Fully Vaccinated">Fully Vaccinated</option>
                                <option value="Partially Vaccinated">Partially Vaccinated</option>
                                <option value="Not Vaccinated">Not Vaccinated</option>
                            </select>
                        </div>
                        <div>
                            <label for="pu_shedding_level">Shedding Level</label>
                            <select name="shedding_level" id="pu_shedding_level">
                                <option value="">Not set</option>
                                <option value="Low">Low</option>
                                <option value="Moderate">Moderate</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div class="full">
                            <label for="pu_temperament">Temperament</label>
                            <input type="text" name="temperament" id="pu_temperament" maxlength="100" placeholder="e.g. Friendly, Playful, Calm">
                        </div>
                        <div class="full">
                            <label for="pu_description">Description</label>
                            <textarea name="description" id="pu_description"></textarea>
                        </div>
                    </div>
                </details>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeVisitModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Visit</button>
            </div>
        </form>
    </div>
</div>

<div class="photo-viewer" id="photoViewer"><img src="" alt="Visit photo"></div>

<script>
    // Search at filter ng listahan (sa browser lang) + mga card na nagsasala
    (function () {
        const search = document.getElementById('monSearch');
        if (!search) return; // History view: walang listahan
        const rows = Array.from(document.querySelectorAll('#monBody .mon-row'));
        const noResults = document.getElementById('monNoResults');
        const filters = Array.from(document.querySelectorAll('.mon-filter'));
        const panel = document.getElementById('monTable');
        let filter = 'all';

        function apply() {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            rows.forEach(function (r) {
                const ok = (filter === 'all' || r.dataset.status === filter) && (!q || r.dataset.search.indexOf(q) !== -1);
                r.hidden = !ok;
                if (ok) shown++;
            });
            if (noResults) noResults.hidden = shown > 0 || !rows.length;
            updateSwipe(true);
        }

        // ===== Telepono: bilang ng card habang nag-i-swipe ("2 / 6") =====
        const body = document.getElementById('monBody');
        const swipe = document.getElementById('monSwipe');
        const swipeNow = document.getElementById('monSwipeNow');
        const swipeTotal = document.getElementById('monSwipeTotal');
        const mqPhone = window.matchMedia('(max-width: 600px)');
        function updateSwipe(reset) {
            if (!swipe || !body) return;
            const visible = rows.filter(function (r) { return !r.hidden; });
            swipe.hidden = !mqPhone.matches || visible.length < 2;
            if (swipe.hidden) return;
            if (reset) body.scrollLeft = 0;   // bagong filter/search: bumalik sa unang card
            // Ang card na pinakamalapit sa gitna ng nakikitang bahagi
            const mid = body.getBoundingClientRect().left + body.clientWidth / 2;
            let best = 0, bestDist = Infinity;
            visible.forEach(function (r, i) {
                const b = r.getBoundingClientRect();
                const d = Math.abs(b.left + b.width / 2 - mid);
                if (d < bestDist) { bestDist = d; best = i; }
            });
            swipeNow.textContent = best + 1;
            swipeTotal.textContent = visible.length;
        }
        if (body) {
            let raf = null;
            body.addEventListener('scroll', function () {
                if (raf) return;
                raf = requestAnimationFrame(function () { raf = null; updateSwipe(false); });
            }, { passive: true });
        }
        window.addEventListener('resize', function () { updateSwipe(false); });
        window.addEventListener('load', function () { updateSwipe(false); });
        function setFilter(f) {
            filter = f;
            filters.forEach(function (b) { b.classList.toggle('active', b.dataset.filter === f); });
            apply();
        }

        search.addEventListener('input', apply);
        filters.forEach(function (b) { b.addEventListener('click', function () { setFilter(b.dataset.filter); }); });

        document.querySelectorAll('.sp-card[data-mon-filter]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                search.value = '';
                setFilter(card.dataset.monFilter);
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
                panel.classList.remove('mon-flash');
                void panel.offsetWidth; // i-restart ang animation
                panel.classList.add('mon-flash');
            });
        });
    })();

    const visitModal = document.getElementById('visitModal');

    function openVisitModal(btn) {
        document.getElementById('visit_application_id').value = btn.dataset.app;
        document.getElementById('visit_back').value = btn.dataset.back || 'list';
        document.getElementById('visitModalSubtitle').textContent = btn.dataset.pet + ' — ' + btn.dataset.adopter;
        const date = document.getElementById('visit_date');
        date.min = btn.dataset.min;
        const today = '<?php echo date('Y-m-d'); ?>';
        date.value = today < btn.dataset.min ? btn.dataset.min : today;

        // Kasalukuyang detalye ng pet ang laman ng "Update pet details"
        let details = {};
        try { details = JSON.parse(btn.dataset.details || '{}'); } catch (e) {}
        Object.keys(details).forEach(function (key) {
            const field = document.getElementById('pu_' + key);
            if (field) { field.value = details[key]; }
        });
        document.getElementById('petUpdate').open = false;
        visitModal.classList.add('show');
        visitModal.setAttribute('aria-hidden', 'false');
    }

    function closeVisitModal() {
        visitModal.classList.remove('show');
        visitModal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('.btn-visit').forEach(function (btn) {
        btn.addEventListener('click', function () { openVisitModal(btn); });
    });

    // Ia-apply lang ang pet details kapag binuksan ng staff ang section
    document.querySelector('#visitModal form').addEventListener('submit', function () {
        document.getElementById('update_pet').value = document.getElementById('petUpdate').open ? '1' : '0';
    });

    // Babala at dahilan (kailangan) kapag tatapusin ng result ang monitoring
    const visitResult = document.getElementById('visit_result');
    function syncReturnReason() {
        const ending = visitResult.value !== 'Stayed with Adopter';
        document.getElementById('resultWarn').style.display = ending ? 'block' : 'none';
        document.getElementById('returnReasonBox').classList.toggle('show', ending);
        document.getElementById('return_reason').required = ending;
    }
    visitResult.addEventListener('change', syncReturnReason);
    syncReturnReason();

    // Palakihin ang larawan ng visit
    const viewer = document.getElementById('photoViewer');
    document.querySelectorAll('.visit-photo').forEach(function (img) {
        img.addEventListener('click', function () {
            viewer.querySelector('img').src = img.src;
            viewer.classList.add('show');
        });
    });
    viewer.addEventListener('click', function () { viewer.classList.remove('show'); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeVisitModal(); viewer.classList.remove('show'); }
    });
</script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
