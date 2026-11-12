<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

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

// --- Summary counts ---
$total_pets = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_inventory");
$available_pets = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_inventory WHERE status = 'Available'");
$total_applications = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications");

// Binibilang lang bilang "approved" kung Approved ang application AT Adopted pa rin ang pet sa ngayon -
// kaya kapag binago ng admin/staff ang status ng pet sa Pet Inventory, sumasabay dito ang Reports.
$approved_adoptions = safe_count($pdo, "
    SELECT COUNT(*) AS c FROM adoption_applications aa
    JOIN pet_inventory p ON p.pet_id = aa.pet_id
    WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
");

// Medical & Intake mini-stats (tugma sa parehong logic na ginamit sa Medical & Intakes page)
$under_treatment = safe_count($pdo, "
    SELECT COUNT(*) AS c FROM (
        SELECT mr.pet_id
        FROM medical_records mr
        INNER JOIN (SELECT pet_id, MAX(checkup_date) AS max_date FROM medical_records GROUP BY pet_id) latest
            ON mr.pet_id = latest.pet_id AND mr.checkup_date = latest.max_date
        WHERE mr.status = 'Treatment'
        GROUP BY mr.pet_id
    ) t
");
$new_intakes_week = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_intakes WHERE YEARWEEK(intake_date, 1) = YEARWEEK(CURDATE(), 1)");

// --- Applications broken down by status ---
$statusOrder = ['Pending', 'Under Review', 'Approved', 'Rejected'];
$appsByStatus = array_fill_keys($statusOrder, 0);
try {
    $statusStmt = $pdo->query("SELECT application_status, COUNT(*) AS c FROM adoption_applications GROUP BY application_status");
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $appsByStatus[$row['application_status']] = (int) $row['c'];
    }
} catch (Exception $e) {
    // panatilihin ang zero-filled na default
}

// --- Pets broken down by kind ---
try {
    $petsByKind = $pdo->query("SELECT kind, COUNT(*) AS c FROM pet_inventory GROUP BY kind ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $petsByKind = [];
}

// --- Monthly adoption trend (huling 6 buwan, batay sa Approved applications na Adopted pa rin) ---
$trendRaw = [];
try {
    $trendStmt = $pdo->query("
        SELECT DATE_FORMAT(aa.updated_at, '%Y-%m') AS ym, COUNT(*) AS c
        FROM adoption_applications aa
        JOIN pet_inventory p ON p.pet_id = aa.pet_id
        WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
        GROUP BY ym
    ");
    foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trendRaw[$row['ym']] = (int) $row['c'];
    }
} catch (Exception $e) {
    // walang data
}

$monthLabels = [];
$monthData = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthLabels[] = date('M Y', strtotime($ym . '-01'));
    $monthData[] = $trendRaw[$ym] ?? 0;
}

// --- Pinakabagong na-approve na adoptions ---
try {
    $recentStmt = $pdo->query("
        SELECT aa.full_name, p.name AS pet_name, aa.updated_at
        FROM adoption_applications aa
        JOIN pet_inventory p ON aa.pet_id = p.pet_id
        WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
        ORDER BY aa.updated_at DESC
        LIMIT 100 -- may pagination na sa page (5 bawat page)
    ");
    $recentAdoptions = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentAdoptions = [];
}

$statusColors = [
    'Pending' => '#e9c46a',
    'Under Review' => '#4895ef',
    'Approved' => '#2e7d32',
    'Rejected' => '#e63946',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .reports-grid-container {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        @media (max-width: 1024px) {
            .reports-grid-container {
                grid-template-columns: 1fr;
            }
        }

        .rpt-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
        }

        .rpt-panel h3 {
            margin: 0 0 4px 0;
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rpt-panel .panel-caption {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 16px;
            display: block;
        }

        .breakdown-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .breakdown-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .breakdown-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
            min-width: 110px;
        }

        .breakdown-label .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .breakdown-bar-track {
            flex: 1;
            height: 8px;
            background: #f1f1f1;
            border-radius: 4px;
            overflow: hidden;
        }

        .breakdown-bar-fill {
            height: 100%;
            border-radius: 4px;
        }

        .breakdown-count {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            min-width: 20px;
            text-align: right;
        }

        .panel-subheading {
            margin-top: 24px !important;
            margin-bottom: 12px !important;
            font-size: 14px !important;
        }

        .chart-wrapper {
            position: relative;
            height: 260px;
        }

        .rpt-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .rpt-table th {
            padding: 10px 8px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border-bottom: 1px solid #f1f1f1;
        }

        .rpt-table td {
            padding: 12px 8px;
            border-bottom: 1px solid #f8f8f8;
            font-size: 14px;
        }

        .empty-note {
            color: #9ca3af;
            font-size: 13px;
            padding: 10px 0;
        }

        /* ===================== Bagong itsura (kulay ng staff) ===================== */
        .rpt-panel { border: 1px solid #eef0f3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05); scroll-margin-top: 16px; }

        /* Mga tab sa kaliwa: asul sa halip na berde */
        .staff-dashboard-root #reportTabs .staff-tab { border-color: #e6eaf1; font-size: 14px; color: #0f1b3d; }
        .staff-dashboard-root #reportTabs .staff-tab:hover { border-color: #c9d6f2; }
        .staff-dashboard-root #reportTabs .staff-tab-icon { background: #eef2fb; color: #1e3a8a; }
        .staff-dashboard-root #reportTabs .staff-tab-icon svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 2.1; stroke-linecap: round; stroke-linejoin: round; }
        .staff-dashboard-root #reportTabs .staff-tab.active {
            background: linear-gradient(135deg, #1e40af 0%, #172f6e 100%); border-color: #1e3a8a; color: #fff;
            box-shadow: 0 4px 0 #0f1b3d, 0 10px 22px rgba(30, 58, 138, 0.24);
        }
        .staff-dashboard-root #reportTabs .staff-tab.active .staff-tab-icon { background: rgba(255, 255, 255, 0.16); color: #fff; }
        .staff-dashboard-root #reportTabs .staff-tab-empty { border-color: #dfe6f2; background: #fafbfd; }
        .staff-dashboard-root #reportTabs .staff-tab-empty-icon { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .staff-dashboard-root #reportTabs .staff-tab-empty-icon svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

        /* Header ng bawat report */
        .rp-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .rp-icon { width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .rp-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2.1; stroke-linecap: round; stroke-linejoin: round; }
        .rp-head h3 { margin: 0 !important; font-size: 17px !important; color: #0f1b3d; }
        .rp-head .panel-caption { margin: 2px 0 0 !important; font-size: 12.5px !important; color: #6b7280 !important; }
        .rp-subhead { display: flex; align-items: baseline; justify-content: space-between; margin: 24px 0 12px; padding-top: 18px; border-top: 1px solid #eef0f3; }
        .rp-subhead h4 { margin: 0; font-size: 14px; font-weight: 800; color: #0f1b3d; }
        .rp-subhead span { font-size: 12px; color: #8a94a6; font-weight: 600; }

        /* Mga bar */
        .breakdown-list { gap: 14px; }
        .breakdown-label { min-width: 130px; font-weight: 600; color: #374151; }
        .breakdown-label .dot { width: 10px; height: 10px; border-radius: 3px; }
        .breakdown-bar-track { height: 10px; border-radius: 999px; background: #eef1f6; }
        .breakdown-bar-fill { border-radius: 999px; transition: width .6s cubic-bezier(.22, 1, .36, 1); }
        .breakdown-count { min-width: 74px; font-size: 14px; color: #0f1b3d; }
        .breakdown-pct { display: inline-block; min-width: 38px; margin-left: 4px; font-size: 11.5px; font-weight: 700; color: #8a94a6; }

        /* Pie (donut) charts */
        .rp-pies { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .rp-pie-card { margin: 0; padding: 16px; border-radius: 14px; border: 1px solid #eef0f3; background: #fcfdff; min-width: 0; }
        .rp-pie-card figcaption { font-size: 14px; font-weight: 800; color: #0f1b3d; margin-bottom: 10px; }
        .rp-pie-body { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .rp-pie-canvas { position: relative; width: 190px; height: 190px; flex-shrink: 0; margin: 0 auto; }
        .rp-pie-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
        .rp-pie-center strong { font-size: 28px; font-weight: 900; color: #0f1b3d; line-height: 1; }
        .rp-pie-center span { margin-top: 4px; font-size: 11.5px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
        .rp-legend { list-style: none; margin: 0; padding: 0; flex: 1; min-width: 170px; display: flex; flex-direction: column; gap: 8px; }
        .rp-legend li { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 10px; background: #fff; border: 1px solid #eef0f3; font-size: 13.5px; }
        .rp-swatch { width: 12px; height: 12px; border-radius: 4px; flex-shrink: 0; }
        .rp-legend-label { flex: 1; min-width: 0; font-weight: 600; color: #374151; }
        .rp-legend strong { color: #0f1b3d; font-size: 14px; }
        .rp-legend-pct { min-width: 40px; text-align: right; font-size: 12px; font-weight: 700; color: #6b7280; }
        @media (max-width: 1280px) { .rp-pies { grid-template-columns: 1fr; } }

        /* Medical & Intake: maliliit na tile */
        .rp-mini-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .rp-mini { display: flex; align-items: center; gap: 12px; padding: 14px; border-radius: 12px; text-decoration: none; border: 1px solid #eef0f3; background: #fafbfc; transition: transform .15s ease, box-shadow .2s ease; }
        .rp-mini:hover { transform: translateY(-2px); box-shadow: 0 8px 18px rgba(15, 27, 61, 0.08); }
        .rp-mini-icon { width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .rp-mini-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .rp-mini.is-red .rp-mini-icon { background: #fdecea; color: #c5221f; }
        .rp-mini.is-blue .rp-mini-icon { background: #eef2fb; color: #1e3a8a; }
        .rp-mini strong { display: block; font-size: 22px; font-weight: 900; color: #0f1b3d; line-height: 1.1; }
        .rp-mini span { font-size: 12.5px; color: #6b7280; font-weight: 600; }
        @media (max-width: 560px) { .rp-mini-grid { grid-template-columns: 1fr; } }

        .chart-wrapper { height: 300px; padding: 10px 6px 0; }

        /* Table */
        .rp-table-wrap { overflow-x: auto; border: 1px solid #eef0f3; border-radius: 12px; }
        .rpt-table th { background: #f8f9fb; color: #6b7280; font-size: 11.5px; letter-spacing: .6px; padding: 12px 14px; border-bottom: 1px solid #eef0f3; }
        .rpt-table td { padding: 12px 14px; border-bottom: 1px solid #f2f4f6; }
        .rpt-table tbody tr:last-child td { border-bottom: none; }
        .rpt-table tbody tr:hover td { background: #f7f9fe; }
        .rp-person { display: flex; align-items: center; gap: 10px; }
        .rp-person strong { color: #0f1b3d; }
        .rp-avatar { width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; background: linear-gradient(135deg, #1e40af, #3b6fe0); }
        .rp-pet { font-weight: 700; color: #1f2937; }
        .rp-date { color: #4b5563; font-size: 13px; white-space: nowrap; }
        .rp-empty { text-align: center; padding: 40px 10px !important; color: #9aa3ad; }
        .rp-row[hidden] { display: none; }

        /* Pagination */
        .rp-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
        .rp-pager[hidden] { display: none; }
        .rp-pager-info { font-size: 13px; color: #6b7280; font-weight: 600; }
        .rp-pager-btns { display: flex; align-items: center; gap: 6px; }
        .rp-page-btn {
            min-width: 34px; height: 34px; padding: 0 10px; border-radius: 9px; border: 1.5px solid #e2e8f0; background: #fff;
            color: #1e3a8a; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: background-color .2s ease, border-color .2s ease;
        }
        .rp-page-btn:hover:not(:disabled):not(.current) { background: #eef2fb; border-color: #c9d6f2; }
        .rp-page-btn.current { background: linear-gradient(135deg, #2a62de, #1e3a8a); border-color: transparent; color: #fff; cursor: default; box-shadow: 0 4px 10px rgba(30, 58, 138, 0.22); }
        .rp-page-btn:disabled { opacity: .4; cursor: not-allowed; }
        .rp-page-btn:focus-visible { outline: 2px solid #2f5bc4; outline-offset: 2px; }
        .rp-page-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
        .rp-page-gap { min-width: 20px; text-align: center; color: #9aa3ad; font-weight: 700; }
    </style>
</head>
<body class="staff-dashboard-root">
<div class="app-container">
    <!-- Reusable Sidebar -->
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <!-- Top Navigation Header -->
        <div class="top-header">
            <div class="search-top"></div>
            <a href="/Caps2/StaffFile/edit_profile.php" class="profile-section" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>"
                         alt="Profile"
                         style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
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
            <!-- Page Title -->
            <header class="sp-page-head">
                <span class="sp-page-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect class="ic-fill" x="3.5" y="3.5" width="17" height="17" rx="3"/>
                        <path d="M8 16v-4M12 16V8M16 16v-6"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Reports</h1>
                    <p>Overview of pets, adoption applications, and adoption trends</p>
                </div>
                <div class="sp-page-actions">
                    <button type="button" class="sp-btn-outline" id="openPrintModal">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/></svg>
                        Print Report
                    </button>
                </div>
            </header>

            <!-- Mga stat card: madilim hanggang maliwanag -->
            <div class="cards-grid sp-cards">
                <a class="stat-card sp-card sp-c1" href="/Caps2/StaffFile/pet_inventory.php" title="Open Pet Inventory">
                    <div class="stat-info">
                        <h1><?php echo str_pad($total_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Total Pets</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Paw print -->
                        <svg viewBox="0 0 24 24">
                            <g class="ic-solid">
                                <ellipse cx="6.5" cy="10" rx="1.9" ry="2.4" transform="rotate(-18 6.5 10)"/>
                                <ellipse cx="10" cy="6" rx="1.8" ry="2.4"/>
                                <ellipse cx="14" cy="6" rx="1.8" ry="2.4"/>
                                <ellipse cx="17.5" cy="10" rx="1.9" ry="2.4" transform="rotate(18 17.5 10)"/>
                            </g>
                            <path class="ic-fill" d="M12 11c3 0 5.5 2.6 5.5 5.2 0 2-1.8 2.8-5.5 2.8s-5.5-.8-5.5-2.8C6.5 13.6 9 11 12 11z"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c2" href="/Caps2/StaffFile/pet_inventory.php" title="Open Pet Inventory">
                    <div class="stat-info">
                        <h1><?php echo str_pad($available_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Available Pets</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Bahay na may puso -->
                        <svg viewBox="0 0 24 24">
                            <path d="M3 11l9-7.5L21 11"/>
                            <path d="M5.5 9.2V20h13V9.2"/>
                            <path class="ic-fill" d="M12 17.5s-3.2-2-3.2-4.3a1.7 1.7 0 0 1 3.2-.9 1.7 1.7 0 0 1 3.2.9c0 2.3-3.2 4.3-3.2 4.3z"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c3" href="#reportTabs" data-open-tab="status" title="See applications by status">
                    <div class="stat-info">
                        <h1><?php echo str_pad($total_applications, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Total Applications</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Clipboard na may listahan -->
                        <svg viewBox="0 0 24 24">
                            <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/>
                            <rect class="ic-fill" x="9" y="2.5" width="6" height="3.5" rx="1"/>
                            <path d="M9 11h6M9 14.5h6M9 18h3.5"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c4" href="#reportTabs" data-open-tab="recent" title="See recent approved adoptions">
                    <div class="stat-info">
                        <h1><?php echo str_pad($approved_adoptions, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Approved Adoptions</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Badge na may check -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M12 3l2.4 1.7 2.9-.1.9 2.8 2.4 1.7-1 2.8 1 2.8-2.4 1.7-.9 2.8-2.9-.1L12 21l-2.4-1.7-2.9.1-.9-2.8L3.4 15l1-2.8-1-2.8 2.4-1.7.9-2.8 2.9.1z"/>
                            <path d="M8.8 12.2l2.2 2.2 4.2-4.4"/>
                        </svg>
                    </div>
                </a>
            </div>

            <!-- Iisang container: nakapatayo ang mga box sa kaliwa, nasa kanan ang
                 napiling report. Iisa lang ang bukas; ang pagpili ng bago ay
                 nagsasara ng nakabukas. -->
            <div class="rpt-panel staff-tabs staff-tabs-vertical" id="reportTabs" data-sp-tabs>
                <div class="staff-tabbar" role="tablist" aria-label="Report sections">
                    <button type="button" class="staff-tab" data-tab="status" aria-selected="false">
                        <span class="staff-tab-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 6h10M4 12h16M4 18h7"/></svg>
                        </span>
                        <span class="staff-tab-text">Applications by Status</span>
                    </button>
                    <button type="button" class="staff-tab" data-tab="trend" aria-selected="false">
                        <span class="staff-tab-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg>
                        </span>
                        <span class="staff-tab-text">Monthly Adoption Trend</span>
                    </button>
                    <button type="button" class="staff-tab" data-tab="recent" aria-selected="false">
                        <span class="staff-tab-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M8.3 12.3l2.5 2.5 5-5.3"/></svg>
                        </span>
                        <span class="staff-tab-text">Recent Approved Adoptions</span>
                    </button>
                </div>

                <div class="staff-tab-empty" data-tab-empty>
                    <span class="staff-tab-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                    </span>
                    <p>Choose a report to view.</p>
                </div>

                <div class="staff-pane" data-pane="status" role="tabpanel" hidden>
                    <div class="rp-head">
                        <span class="rp-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h10M4 12h16M4 18h7"/></svg></span>
                        <div>
                            <h3>Applications by Status</h3>
                            <span class="panel-caption">Distribution of all <?php echo (int) $total_applications; ?> adoption applications</span>
                        </div>
                    </div>
                    <?php
                    // Datos ng dalawang pie chart (Chart.js sa ibaba)
                    $statusPie = [];
                    foreach ($statusOrder as $status) {
                        $statusPie[] = ['label' => $status, 'value' => (int) $appsByStatus[$status], 'color' => $statusColors[$status]];
                    }
                    $kindPie = [];
                    foreach ($petsByKind as $row) {
                        $kindPie[] = ['label' => $row['kind'], 'value' => (int) $row['c'], 'color' => $row['kind'] === 'Cat' ? '#7c3aed' : '#d97706'];
                    }
                    $pies = [
                        ['id' => 'statusPie', 'title' => 'By Status', 'total' => (int) $total_applications, 'unit' => 'applications', 'data' => $statusPie, 'empty' => 'No adoption applications submitted yet.'],
                        ['id' => 'kindPie', 'title' => 'Pets by Kind', 'total' => (int) $total_pets, 'unit' => 'pets', 'data' => $kindPie, 'empty' => 'No pets registered yet.'],
                    ];
                    ?>
                    <div class="rp-pies">
                        <?php foreach ($pies as $pie): ?>
                            <figure class="rp-pie-card">
                                <figcaption><?php echo htmlspecialchars($pie['title']); ?></figcaption>
                                <?php if ($pie['total'] > 0): ?>
                                    <div class="rp-pie-body">
                                        <div class="rp-pie-canvas">
                                            <canvas id="<?php echo $pie['id']; ?>" role="img" aria-label="<?php echo htmlspecialchars($pie['title'] . ' pie chart'); ?>"></canvas>
                                            <!-- Kabuuan sa gitna ng donut -->
                                            <div class="rp-pie-center" aria-hidden="true">
                                                <strong><?php echo $pie['total']; ?></strong>
                                                <span><?php echo $pie['unit']; ?></span>
                                            </div>
                                        </div>
                                        <!-- Legend: kulay + pangalan + bilang + porsiyento (hindi kulay lang) -->
                                        <ul class="rp-legend">
                                            <?php foreach ($pie['data'] as $seg):
                                                $pct = round($seg['value'] / $pie['total'] * 100);
                                            ?>
                                                <li>
                                                    <span class="rp-swatch" style="background: <?php echo $seg['color']; ?>;" aria-hidden="true"></span>
                                                    <span class="rp-legend-label"><?php echo htmlspecialchars($seg['label']); ?></span>
                                                    <strong><?php echo $seg['value']; ?></strong>
                                                    <span class="rp-legend-pct"><?php echo $pct; ?>%</span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <p class="empty-note"><?php echo htmlspecialchars($pie['empty']); ?></p>
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>

                    <div class="rp-subhead">
                        <h4>Medical &amp; Intake Snapshot</h4>
                    </div>
                    <div class="rp-mini-grid">
                        <a class="rp-mini is-red" href="/Caps2/StaffFile/medical_intake.php">
                            <span class="rp-mini-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M6 3.5H5a1 1 0 0 0-1 1V9a5 5 0 0 0 10 0V4.5a1 1 0 0 0-1-1h-1"/><path d="M9 14v1.5a5 5 0 0 0 10 0V13"/><circle cx="19" cy="11" r="2"/></svg>
                            </span>
                            <div>
                                <strong><?php echo (int) $under_treatment; ?></strong>
                                <span>Under Treatment</span>
                            </div>
                        </a>
                        <a class="rp-mini is-blue" href="/Caps2/StaffFile/medical_intake.php">
                            <span class="rp-mini-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M3 11l9-7.5L21 11"/><path d="M5.5 9.2V20h13V9.2"/><path d="M12 11.5v6M9 14.5h6"/></svg>
                            </span>
                            <div>
                                <strong><?php echo (int) $new_intakes_week; ?></strong>
                                <span>New Intakes (This Week)</span>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="staff-pane" data-pane="trend" role="tabpanel" hidden>
                    <div class="rp-head">
                        <span class="rp-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg></span>
                        <div>
                            <h3>Monthly Adoption Trend</h3>
                            <span class="panel-caption">Approved adoptions over the last 6 months &middot; <?php echo (int) array_sum($monthData); ?> total</span>
                        </div>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="adoptionTrendChart"></canvas>
                    </div>
                </div>

                <div class="staff-pane" data-pane="recent" role="tabpanel" hidden>
                    <div class="rp-head">
                        <span class="rp-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M8.3 12.3l2.5 2.5 5-5.3"/></svg></span>
                        <div>
                            <h3>Recent Approved Adoptions</h3>
                            <span class="panel-caption">Latest finalized adoptions</span>
                        </div>
                    </div>
                    <div class="rp-table-wrap">
                        <table class="rpt-table">
                            <thead>
                                <tr>
                                    <th>Adopter</th>
                                    <th>Pet</th>
                                    <th>Date Approved</th>
                                </tr>
                            </thead>
                            <tbody id="recentBody">
                                <?php if (count($recentAdoptions) > 0): ?>
                                    <?php foreach ($recentAdoptions as $row): ?>
                                        <tr class="rp-row">
                                            <td>
                                                <div class="rp-person">
                                                    <span class="rp-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($row['full_name'] ?? '?', 0, 1))); ?></span>
                                                    <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
                                                </div>
                                            </td>
                                            <td class="rp-pet"><?php echo htmlspecialchars($row['pet_name']); ?></td>
                                            <td class="rp-date"><?php echo htmlspecialchars(date('M j, Y', strtotime($row['updated_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="rp-empty">No approved adoptions yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination (pinupuno ng JavaScript sa ibaba; nakatago kapag iisa lang ang page) -->
                    <nav class="rp-pager" id="recentPager" aria-label="Recent approved adoptions pages" hidden>
                        <span class="rp-pager-info" id="recentPagerInfo"></span>
                        <div class="rp-pager-btns" id="recentPagerBtns"></div>
                    </nav>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
    const ctx = document.getElementById('adoptionTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($monthLabels); ?>,
            datasets: [{
                label: 'Approved Adoptions',
                data: <?php echo json_encode($monthData); ?>,
                // Asul na gradient (madilim sa ibaba, maliwanag sa itaas)
                backgroundColor: function (context) {
                    const chart = context.chart;
                    const area = chart.chartArea;
                    if (!area) return '#2f5bc4';
                    const g = chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
                    g.addColorStop(0, '#1e3a8a');
                    g.addColorStop(1, '#4a86f0');
                    return g;
                },
                hoverBackgroundColor: '#1e40af',
                borderRadius: 8,
                maxBarThickness: 44
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: '#0f1b3d', padding: 10, cornerRadius: 8, displayColors: false }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { weight: 600 } } },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, color: '#9aa3ad' },
                    grid: { color: '#eef0f3' },
                    border: { display: false }
                }
            }
        }
    });
</script>
<script>
    // Mga pie (donut) chart: Applications by Status at Pets by Kind
    (function () {
        const PIES = <?php echo json_encode(array_map(fn ($p) => ['id' => $p['id'], 'total' => $p['total'], 'unit' => $p['unit'], 'data' => $p['data']], $pies), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        PIES.forEach(function (pie) {
            const canvas = document.getElementById(pie.id);
            if (!canvas || !pie.total) return;
            new Chart(canvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: pie.data.map(function (d) { return d.label; }),
                    datasets: [{
                        data: pie.data.map(function (d) { return d.value; }),
                        backgroundColor: pie.data.map(function (d) { return d.color; }),
                        borderColor: '#ffffff',   // 2px na puwang sa pagitan ng mga bahagi
                        borderWidth: 2,
                        hoverOffset: 8,
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    layout: { padding: 8 },
                    plugins: {
                        legend: { display: false }, // may sariling legend sa tabi (may bilang at %)
                        tooltip: {
                            backgroundColor: '#0f1b3d', padding: 10, cornerRadius: 8,
                            callbacks: {
                                label: function (ctx) {
                                    const pct = Math.round(ctx.parsed / pie.total * 100);
                                    return ' ' + ctx.label + ': ' + ctx.parsed + ' ' + pie.unit + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        });
    })();
</script>
<script>
    // Pagination ng Recent Approved Adoptions (5 bawat page, walang reload)
    (function () {
        const PER_PAGE = 5;
        const rows = Array.from(document.querySelectorAll('#recentBody .rp-row'));
        const pager = document.getElementById('recentPager');
        const info = document.getElementById('recentPagerInfo');
        const btns = document.getElementById('recentPagerBtns');
        const pages = Math.ceil(rows.length / PER_PAGE);
        if (pages <= 1) return;
        pager.hidden = false;
        let page = 1;

        function btn(label, target, opts) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'rp-page-btn' + (opts && opts.current ? ' current' : '');
            b.innerHTML = label;
            if (opts && opts.aria) b.setAttribute('aria-label', opts.aria);
            if (opts && opts.current) b.setAttribute('aria-current', 'page');
            b.disabled = target < 1 || target > pages;
            b.addEventListener('click', function () { if (target !== page) show(target); });
            return b;
        }

        // Mga numero ng page: laging may una at huli; "…" kapag marami
        function pageList() {
            const list = [];
            for (let i = 1; i <= pages; i++) {
                if (i === 1 || i === pages || Math.abs(i - page) <= 1) list.push(i);
                else if (list[list.length - 1] !== '…') list.push('…');
            }
            return list;
        }

        function show(p) {
            page = p;
            rows.forEach(function (r, i) { r.hidden = Math.floor(i / PER_PAGE) + 1 !== page; });
            const from = (page - 1) * PER_PAGE + 1;
            const to = Math.min(page * PER_PAGE, rows.length);
            info.textContent = 'Showing ' + from + '–' + to + ' of ' + rows.length;
            btns.innerHTML = '';
            btns.appendChild(btn('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>', page - 1, { aria: 'Previous page' }));
            pageList().forEach(function (n) {
                if (n === '…') {
                    const s = document.createElement('span');
                    s.className = 'rp-page-gap';
                    s.textContent = '…';
                    btns.appendChild(s);
                } else {
                    btns.appendChild(btn(String(n), n, { current: n === page }));
                }
            });
            btns.appendChild(btn('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>', page + 1, { aria: 'Next page' }));
        }
        show(1);
    })();
</script>
<script src="../connection/sp_tabs.js"></script>
<script>
    // Mga card na nagbubukas ng report tab (hal. Approved Adoptions -> Recent Approved Adoptions)
    document.querySelectorAll('.sp-card[data-open-tab]').forEach(function (card) {
        card.addEventListener('click', function (e) {
            const tab = document.querySelector('.staff-tab[data-tab="' + card.dataset.openTab + '"]');
            if (!tab) return;
            e.preventDefault();
            if (tab.getAttribute('aria-selected') !== 'true') tab.click();
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            document.getElementById('reportTabs').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        });
    });
</script>
    <?php
    // Mga taong mapipili sa Print Report: mula sa pinakaunang application hanggang ngayong taon
    $printMaxYear = (int) date('Y');
    try {
        $printMinYear = (int) ($pdo->query("SELECT MIN(YEAR(created_at)) FROM adoption_applications")->fetchColumn() ?: $printMaxYear);
    } catch (Exception $e) {
        $printMinYear = $printMaxYear;
    }
    $printMinYear = min($printMinYear, $printMaxYear - 4);
    ?>
    <!-- Print Report: pipiliin ang Weekly / Monthly / Yearly, saka bubuksan ang
         printable na report (kapareho ng sa Admin: AdminFile/print_report.php) -->
    <div class="pr-modal" id="printModal" hidden>
        <div class="pr-backdrop" data-close></div>
        <form class="pr-dialog" action="/Caps2/StaffFile/print_report.php" method="get" target="_blank" role="dialog" aria-modal="true" aria-labelledby="prTitle">
            <div class="pr-head">
                <span class="pr-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/></svg>
                </span>
                <div>
                    <h3 id="prTitle">Print Report</h3>
                    <p>Includes all reports for the selected period.</p>
                </div>
                <button type="button" class="pr-close" data-close aria-label="Close">&times;</button>
            </div>

            <div class="pr-field">
                <span class="pr-label">Report period</span>
                <div class="pr-seg" role="radiogroup" aria-label="Report period">
                    <label><input type="radio" name="type" value="weekly"><span>Weekly</span></label>
                    <label><input type="radio" name="type" value="monthly" checked><span>Monthly</span></label>
                    <label><input type="radio" name="type" value="yearly"><span>Yearly</span></label>
                </div>
            </div>

            <div class="pr-field" data-for="weekly" hidden>
                <label class="pr-label" for="prDate">Week of</label>
                <input type="date" id="prDate" name="date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                <small class="pr-hint">Pick any day &mdash; the report covers Monday to Sunday of that week.</small>
            </div>

            <div class="pr-field pr-row" data-for="monthly">
                <div>
                    <label class="pr-label" for="prMonth">Month</label>
                    <select id="prMonth" name="month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>"<?php echo $m === (int) date('n') ? ' selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="pr-label" for="prMonthYear">Year</label>
                    <select id="prMonthYear" name="year">
                        <?php for ($y = $printMaxYear; $y >= $printMinYear; $y--): ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="pr-field" data-for="yearly" hidden>
                <label class="pr-label" for="prYear">Year</label>
                <select id="prYear" name="year" disabled>
                    <?php for ($y = $printMaxYear; $y >= $printMinYear; $y--): ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <ul class="pr-includes">
                <li>Summary &amp; approval rate</li>
                <li>Applications by status</li>
                <li>Pets by kind</li>
                <li>Daily / weekly / monthly breakdown</li>
                <li>Approved adoptions list</li>
                <li>Adoption fee payments</li>
            </ul>

            <div class="pr-actions">
                <button type="button" class="pr-btn" data-close>Cancel</button>
                <button type="submit" class="pr-btn primary">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/></svg>
                    Generate &amp; Print
                </button>
            </div>
        </form>
    </div>

    <style>
        /* ---- Print Report modal (kulay ng Staff: asul) ---- */
        body.modal-open { overflow: hidden; }
        .pr-modal { position: fixed; inset: 0; z-index: 10050; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .pr-modal[hidden] { display: none; }
        .pr-backdrop { position: absolute; inset: 0; background: rgba(15, 27, 61, 0.55); animation: prFade .2s ease; }
        .pr-dialog {
            position: relative; width: min(460px, 100%); max-height: calc(100vh - 40px); overflow-y: auto;
            padding: 22px; border-radius: 16px; background: #fff; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            animation: prPop .25s cubic-bezier(.22, 1, .36, 1);
        }
        .pr-head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid #eef0f3; }
        .pr-icon {
            width: 42px; height: 42px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 12px;
            color: #fff; background: linear-gradient(135deg, #172554 0%, #1e40af 55%, #3b6fe0 100%); box-shadow: 0 6px 14px rgba(30, 58, 138, 0.22);
        }
        .pr-icon svg, .pr-btn svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .pr-head h3 { margin: 0; font-size: 17px; font-weight: 800; color: #0f1b3d; }
        .pr-head p { margin: 2px 0 0; font-size: 12.5px; color: #6b7280; }
        .pr-close { margin-left: auto; width: 34px; height: 34px; border: none; border-radius: 9px; background: transparent; color: #6b7280; font-size: 22px; line-height: 1; cursor: pointer; }
        .pr-close:hover { background: #f3f4f6; color: #0f1b3d; }
        .pr-field { margin-bottom: 14px; }
        .pr-field[hidden] { display: none; }
        .pr-row { display: grid; grid-template-columns: 1.4fr 1fr; gap: 10px; }
        .pr-label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; color: #6b7280; }
        .pr-field select, .pr-field input[type="date"] {
            width: 100%; height: 42px; padding: 0 12px; border: 1.5px solid #e2e6ee; border-radius: 10px; background: #fff;
            color: #0f1b3d; font-family: inherit; font-size: 14px; font-weight: 600; box-sizing: border-box;
        }
        .pr-field select:focus, .pr-field input[type="date"]:focus { outline: none; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.15); }
        .pr-hint { display: block; margin-top: 6px; font-size: 12px; color: #6b7280; }
        .pr-seg { display: grid; grid-template-columns: repeat(3, 1fr); padding: 4px; border-radius: 12px; background: #eef2fb; }
        .pr-seg label { position: relative; cursor: pointer; }
        .pr-seg input { position: absolute; opacity: 0; pointer-events: none; }
        .pr-seg span { display: flex; align-items: center; justify-content: center; height: 36px; border-radius: 9px; font-size: 14px; font-weight: 700; color: #4b5563; transition: background-color .2s ease, color .2s ease, box-shadow .2s ease; }
        .pr-seg label:hover span { color: #1e3a8a; }
        .pr-seg input:checked + span { background: #fff; color: #1e3a8a; box-shadow: 0 1px 4px rgba(16, 24, 40, 0.14); }
        .pr-seg input:focus-visible + span { outline: 2px solid #2a62de; outline-offset: 1px; }
        .pr-includes {
            display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; margin: 4px 0 18px; padding: 12px 14px; list-style: none;
            border: 1px solid #eef0f3; border-radius: 12px; background: #fafbfd;
        }
        .pr-includes li { position: relative; padding-left: 18px; font-size: 12.5px; color: #374151; }
        .pr-includes li::before {
            content: ""; position: absolute; left: 2px; top: 4px; width: 8px; height: 4px;
            border-left: 2px solid #1e3a8a; border-bottom: 2px solid #1e3a8a; transform: rotate(-45deg);
        }
        .pr-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .pr-btn {
            display: inline-flex; align-items: center; gap: 8px; height: 42px; padding: 0 18px; border: 1.5px solid #e2e6ee; border-radius: 10px;
            background: #fff; color: #374151; font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer; transition: background-color .2s ease, filter .2s ease;
        }
        .pr-btn:hover { background: #f3f4f6; }
        .pr-btn.primary { border-color: transparent; color: #fff; background: linear-gradient(135deg, #2a62de 0%, #1e3a8a 100%); box-shadow: 0 6px 14px rgba(30, 58, 138, 0.25); }
        .pr-btn.primary:hover { filter: brightness(1.08); background: linear-gradient(135deg, #2a62de 0%, #1e3a8a 100%); }
        .pr-btn svg { width: 17px; height: 17px; }
        @keyframes prFade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes prPop { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
        @media (max-width: 480px) { .pr-row, .pr-includes { grid-template-columns: 1fr; } }
        @media (prefers-reduced-motion: reduce) { .pr-backdrop, .pr-dialog { animation: none; } }
    </style>
    <script>
        // Print Report modal: ipinapakita lang ang mga field ng napiling panahon.
        // Naka-disable ang mga field na hindi kasama para hindi maipadala.
        (function () {
            const modal = document.getElementById('printModal');
            const openBtn = document.getElementById('openPrintModal');
            if (!modal || !openBtn) return;
            const form = modal.querySelector('form');

            function sync() {
                const type = form.querySelector('input[name="type"]:checked').value;
                form.querySelectorAll('[data-for]').forEach(function (box) {
                    const on = box.dataset.for === type;
                    box.hidden = !on;
                    box.querySelectorAll('input, select').forEach(function (el) { el.disabled = !on; });
                });
            }
            function open() {
                modal.hidden = false;
                document.body.classList.add('modal-open');
                sync();
                form.querySelector('input[name="type"]:checked').focus();
            }
            function close() {
                modal.hidden = true;
                document.body.classList.remove('modal-open');
                openBtn.focus();
            }

            openBtn.addEventListener('click', open);
            form.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', sync); });
            modal.querySelectorAll('[data-close]').forEach(function (el) { el.addEventListener('click', close); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) close();
            });
            form.addEventListener('submit', function () { setTimeout(close, 100); });
        })();
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
