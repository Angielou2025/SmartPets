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

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Helper function para sa safe query counts
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

// Counts para sa Stat Cards
$new_applications = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status = 'Pending'");
$under_review = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status = 'Under Review'");
$approved_this_month = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status = 'Approved' AND MONTH(updated_at) = MONTH(CURRENT_DATE()) AND YEAR(updated_at) = YEAR(CURRENT_DATE())");
$interviews_scheduled = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status IN ('Pending', 'Under Review') AND preferred_interview_date >= CURDATE()");

// Optional ?status=pending filter (ginagamit ng "View All Pending Matches" link sa smart_matches.php)
// para ipakita lang ang mga application na hindi pa nafi-finalize.
$statusFilter = $_GET['status'] ?? '';
$pendingOnly = ($statusFilter === 'pending');

// Fetch applications data mula sa database
$applications = [];
try {
    $sql = "SELECT aa.application_id, aa.full_name AS applicant_name, aa.email_address AS email, aa.created_at, aa.application_status AS status,
                   aa.match_score AS saved_match_score, aa.match_breakdown AS saved_match_breakdown,
                   p.name AS pet_name, p.breed AS pet_breed, p.kind AS pet_type,
                   ap.pref_kind, ap.pref_breed, ap.pref_temperament, ap.pref_energy_level, ap.pref_size, ap.pref_age_group, ap.pref_gender, ap.pref_health_condition,
                   ap.living_space, ap.activity_level, ap.maintenance_level, ap.allergies,
                   p.kind, p.breed AS breed, p.energy_level, p.size, p.age_group, p.gender, p.health_condition, p.temperament, p.shedding_level, p.description
            FROM adoption_applications aa
            JOIN pet_inventory p ON aa.pet_id = p.pet_id
            LEFT JOIN adopter_preferences ap ON ap.user_id = aa.adopter_id";
    if ($pendingOnly) {
        $sql .= " WHERE aa.application_status IN ('Pending', 'Under Review')";
    }
    $sql .= " ORDER BY aa.created_at DESC";
    $stmt = $pdo->query($sql);
    if ($stmt) {
        $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $applications = [];
}

// Match % na naka-freeze noong nag-submit ang adopter (hindi nagbabago sa bagong preferences)
foreach ($applications as &$app) {
    $app['match_score'] = sp_application_match($pdo, $app)['score'];
}
unset($app);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Applications - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        .app-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
            margin-top: 20px;
        }

        .app-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .app-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            min-width: 750px;
        }

        .app-table th {
            padding: 12px 10px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #f1f1f1;
        }

        .app-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f8f8f8;
            vertical-align: middle;
            font-size: 14px;
        }

        .applicant-pill {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #eef2f1;
            color: #1f6b48;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            margin-right: 10px;
        }

        /* Application Status Badges */
        .badge-app {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .badge-pending {
            background-color: #fef3d6;
            color: #b25900;
        }

        .badge-review {
            background-color: #e8f0fe;
            color: #1a73e8;
        }

        .badge-approved {
            background-color: #e6f4ea;
            color: #1e7e34;
        }

        .badge-rejected {
            background-color: #fce8e6;
            color: #c5221f;
        }

        .btn-action {
            display: inline-block;
            background: transparent;
            border: 1px solid #1f6b48;
            color: #1f6b48;
            padding: 6px 16px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.14s ease;
        }

        .btn-action:hover {
            background: #1f6b48;
            color: #fff;
        }

        .btn-action-view {
            border-color: #6b7280;
            color: #4b5563;
        }

        .btn-action-view:hover {
            background: #6b7280;
            color: #fff;
        }

        .match-info-btn {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 13px;
            cursor: pointer;
            padding: 0;
            vertical-align: middle;
            transition: color 0.14s ease;
        }
        .match-info-btn:hover {
            color: #1f6b48;
        }

        .match-criteria-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .match-criteria-table th {
            text-align: left;
            padding: 8px 10px;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f1f1;
        }
        .match-criteria-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f8f8f8;
        }

        /* ===================== Bagong itsura (kulay ng staff) ===================== */
        .app-panel { border: 1px solid #eef0f3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05); scroll-margin-top: 16px; }
        .app-panel.app-flash { animation: appFlash 1s ease; }
        @keyframes appFlash { 0% { box-shadow: 0 0 0 0 rgba(47, 91, 196, 0.45); } 100% { box-shadow: 0 0 0 14px rgba(47, 91, 196, 0); } }
        .app-title { display: flex; align-items: center; gap: 12px; }
        .app-title-icon { width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .app-title-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
        .app-title h3 { margin: 0; font-size: 18px; font-weight: 800; color: #0f1b3d; }
        .app-sub { font-size: 13px; color: #6b7280; }

        /* Toolbar */
        .app-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 4px 0 14px; }
        .app-search {
            display: flex; align-items: center; gap: 8px; flex: 1 1 280px; max-width: 400px; height: 40px; padding: 0 12px;
            border-radius: 10px; background: #f6f7f9; border: 1.5px solid transparent; color: #9aa3ad;
            transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
        }
        .app-search:focus-within { background: #fff; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        .app-search svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
        .app-search input { flex: 1; min-width: 0; border: none; background: transparent; outline: none; font-family: inherit; font-size: 14px; color: #102a2a; }
        .app-filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 4px; border-radius: 12px; background: #f3f5f8; }
        .app-filter {
            display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border: none; border-radius: 9px;
            background: transparent; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 700; color: #5b6472;
            transition: background-color .2s ease, color .2s ease, box-shadow .2s ease;
        }
        .app-filter span { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #e3e7ee; color: #475569; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; }
        .app-filter:hover { color: #1e3a8a; }
        .app-filter.active { background: #fff; color: #1e3a8a; box-shadow: 0 2px 6px rgba(15, 27, 61, 0.1); }
        .app-filter.active span { background: #1e3a8a; color: #fff; }

        /* Table */
        .app-table-wrap { overflow-x: auto; border: 1px solid #eef0f3; border-radius: 12px; }
        .app-table { min-width: 820px; }
        .app-table thead th { background: #f8f9fb; color: #6b7280; font-size: 11.5px; letter-spacing: .6px; padding: 12px 14px; border-bottom: 1px solid #eef0f3; white-space: nowrap; }
        .app-table tbody td { padding: 12px 14px; border-bottom: 1px solid #f2f4f6; }
        .app-table tbody tr:last-child td { border-bottom: none; }
        .app-table .app-row { transition: background-color .15s ease; }
        .app-table .app-row:hover { background: #f7f9fe; }
        .app-id { font-family: 'Consolas', 'SFMono-Regular', monospace; font-size: 12.5px; font-weight: 700; color: #1e3a8a; background: #eef2fb; padding: 3px 8px; border-radius: 6px; white-space: nowrap; }
        .app-person { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .applicant-pill { width: 38px; height: 38px; margin-right: 0; flex-shrink: 0; background: linear-gradient(135deg, #1e40af, #3b6fe0); color: #fff; box-shadow: 0 3px 8px rgba(30, 64, 175, 0.25); }
        .app-person-text { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; gap: 2px; }
        .app-name { font-weight: 800; color: #0f1b3d; font-size: 14px; }
        .app-meta { font-size: 12px; color: #8a94a3; }
        .app-kind { font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
        .app-kind.is-dog { background: #fff4e5; color: #b45309; }
        .app-kind.is-cat { background: #f3edff; color: #6d28d9; }
        .app-date { color: #4b5563; font-size: 13px; white-space: nowrap; }

        /* Status badge na may tuldok */
        .badge-app { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 9px; }
        .badge-app::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        /* Match % na may bar */
        .app-match { display: flex; flex-direction: column; gap: 5px; width: 90px; }
        .app-match strong { font-size: 14px; }
        .app-match-bar { height: 6px; border-radius: 999px; background: #e8ecf3; overflow: hidden; }
        .app-match-bar span { display: block; height: 100%; border-radius: 999px; }
        .match-high strong { color: #15803d; } .match-high .app-match-bar span { background: linear-gradient(90deg, #22c55e, #15803d); }
        .match-mid strong { color: #b45309; } .match-mid .app-match-bar span { background: linear-gradient(90deg, #fbbf24, #d97706); }
        .match-low strong { color: #b42318; } .match-low .app-match-bar span { background: linear-gradient(90deg, #f87171, #dc2626); }
        .app-match-none { color: #9aa3ad; font-weight: 700; font-size: 13px; }
        .match-info-btn { display: inline-flex; vertical-align: -2px; }
        .match-info-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
        .match-info-btn:hover { color: #1e3a8a; }

        /* Mga button */
        .btn-action { display: inline-flex; align-items: center; gap: 4px; height: 34px; padding: 0 12px 0 14px; border-radius: 9px; font-family: inherit; }
        .btn-action svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; transition: transform .2s ease; }
        .btn-action:hover svg { transform: translateX(2px); }
        .btn-action-primary { background: linear-gradient(135deg, #2a62de, #1e3a8a); border: none; color: #fff; box-shadow: 0 4px 10px rgba(30, 58, 138, 0.22); }
        .btn-action-primary:hover { background: linear-gradient(135deg, #2a62de, #1e3a8a); filter: brightness(1.1); color: #fff; }
        .btn-action-view { border: 1.5px solid #dbe3f4; color: #1e3a8a; background: #fff; }
        .btn-action-view:hover { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }

        .app-empty { text-align: center; padding: 40px 10px !important; color: #9aa3ad; font-size: 14px; }
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
                        <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/>
                        <rect class="ic-fill" x="9" y="2.5" width="6" height="3.5" rx="1"/>
                        <path d="M9 12.5l2 2 4-4"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1><?php echo $pendingOnly ? 'Pending Applications' : 'Applications'; ?></h1>
                    <p><?php echo $pendingOnly ? 'Showing applications that are still Pending or Under Review' : 'Review and manage pet adoption applications'; ?></p>
                </div>
                <?php if ($pendingOnly): ?>
                    <div class="sp-page-actions">
                        <a href="applications.php" class="sp-btn-outline">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                            Show All Applications
                        </a>
                    </div>
                <?php endif; ?>
            </header>

            <?php if (!empty($success_message)): ?>
                <div style="background:#e8f5e9; color:#2e7d32; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <!-- Top Stat Cards (Base sa Mockup Design) -->
            <!-- Mga stat card: madilim hanggang maliwanag. Sinasala ang table (o pumupunta
                 sa kaugnay na page kung wala rito ang hinahanap) -->
            <div class="cards-grid sp-cards">
                <a class="stat-card sp-card sp-c1" href="#appTable" data-app-filter="pending" title="Show new applications">
                    <div class="stat-info">
                        <h1><?php echo str_pad($new_applications, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>New Applications</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Inbox na may arrow -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M3.5 13.5h4.5l1.5 2.5h5l1.5-2.5h4.5V18a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>
                            <path d="M3.5 13.5L6 5.5h12l2.5 8"/>
                            <path d="M12 7v5.5M9.8 10.3L12 12.5l2.2-2.2"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c2" href="#appTable" data-app-filter="under review" title="Show applications under review">
                    <div class="stat-info">
                        <h1><?php echo str_pad($under_review, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Under Review Processing</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Magnifier sa dokumento -->
                        <svg viewBox="0 0 24 24">
                            <path d="M13 3.5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h4"/>
                            <path d="M8.5 8h6M8.5 11.5h3"/>
                            <circle class="ic-fill" cx="16" cy="15.5" r="3.5"/>
                            <path d="M18.6 18.1l2.4 2.4"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c3" href="<?php echo $pendingOnly ? 'applications.php?filter=approved#appTable' : '#appTable'; ?>" <?php echo $pendingOnly ? '' : 'data-app-filter="approved"'; ?> title="Show approved applications">
                    <div class="stat-info">
                        <h1><?php echo str_pad($approved_this_month, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Approved This Month</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Badge na may check -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M12 3l2.4 1.7 2.9-.1.9 2.8 2.4 1.7-1 2.8 1 2.8-2.4 1.7-.9 2.8-2.9-.1L12 21l-2.4-1.7-2.9.1-.9-2.8L3.4 15l1-2.8-1-2.8 2.4-1.7.9-2.8 2.9.1z"/>
                            <path d="M8.8 12.2l2.2 2.2 4.2-4.4"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c4" href="/Caps2/StaffFile/staff_availability.php#requests" title="Open Interview Availability">
                    <div class="stat-info">
                        <h1><?php echo str_pad($interviews_scheduled, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Interviews Scheduled</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Calendar na may orasan -->
                        <svg viewBox="0 0 24 24">
                            <path d="M11 20.5H6a2.5 2.5 0 0 1-2.5-2.5V7.5A2.5 2.5 0 0 1 6 5h12a2.5 2.5 0 0 1 2.5 2.5V11"/>
                            <path d="M3.5 9.5h17M8 3v4M16 3v4"/>
                            <circle class="ic-fill" cx="17" cy="17" r="4"/>
                            <path d="M17 15.3V17l1.2 1"/>
                        </svg>
                    </div>
                </a>
            </div>

            <!-- Main Adoption Applications Table Panel -->
            <div class="app-panel" id="appTable">
                <div class="app-header">
                    <div class="app-title">
                        <span class="app-title-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                        </span>
                        <div>
                            <h3>Adoption Applications</h3>
                            <span class="app-sub">Manage submitted adoption applications</span>
                        </div>
                    </div>
                </div>

                <?php
                // Bilang ayon sa status, para sa mga filter (wala sa "All")
                $appCounts = [];
                foreach ($applications as $a) {
                    $k = strtolower(trim($a['status'] ?? 'Pending'));
                    $appCounts[$k] = ($appCounts[$k] ?? 0) + 1;
                }
                $appFilters = $pendingOnly
                    ? ['pending' => 'Pending', 'under review' => 'Under Review']
                    : ['pending' => 'Pending', 'under review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected'];
                ?>
                <!-- Toolbar: search + filter ayon sa status (sa browser lang, walang reload) -->
                <div class="app-toolbar">
                    <label class="app-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
                        <input type="search" id="appSearch" placeholder="Search by applicant, pet, or Application ID..." aria-label="Search applications" autocomplete="off">
                    </label>
                    <div class="app-filters" role="group" aria-label="Filter by status">
                        <button type="button" class="app-filter active" data-filter="all">All</button>
                        <?php foreach ($appFilters as $fKey => $fLabel): ?>
                            <button type="button" class="app-filter" data-filter="<?php echo $fKey; ?>"><?php echo $fLabel; ?> <span><?php echo $appCounts[$fKey] ?? 0; ?></span></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="app-table-wrap">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Applicant</th>
                                <th>Pet</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Match <button type="button" class="match-info-btn" onclick="openMatchInfoModal()" aria-label="How is match % calculated?" title="How is match % calculated?">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                                </button></th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="appBody">
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="7" class="app-empty">No applications submitted yet.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($applications as $app):
                                $status = $app['status'] ?? 'Pending';
                                $statusKey = strtolower(trim($status));
                                $badgeClass = 'badge-pending';
                                if ($statusKey === 'under review') $badgeClass = 'badge-review';
                                if ($statusKey === 'approved') $badgeClass = 'badge-approved';
                                if ($statusKey === 'rejected') $badgeClass = 'badge-rejected';

                                $applicantName = htmlentities($app['applicant_name'] ?? 'N/A');
                                $initial = strtoupper(substr($applicantName, 0, 1));
                                $formattedDate = date('M d, Y', strtotime($app['created_at']));
                                $score = $app['match_score'];
                                $appNo = 'APP-' . str_pad($app['application_id'], 3, '0', STR_PAD_LEFT);
                                $isCat = ($app['pet_type'] ?? '') === 'Cat';
                                $matchTone = $score === null ? 'none' : ($score >= 85 ? 'high' : ($score >= 65 ? 'mid' : 'low'));
                                $appSearch = mb_strtolower(implode(' ', [$app['applicant_name'] ?? '', $app['email'] ?? '', $app['pet_name'] ?? '', $appNo, $app['application_id']]));
                            ?>
                                <tr class="app-row" data-status="<?php echo htmlspecialchars($statusKey); ?>" data-search="<?php echo htmlspecialchars($appSearch); ?>">
                                    <!-- Application ID -->
                                    <td><span class="app-id"><?php echo htmlentities($appNo); ?></span></td>

                                    <!-- Applicant Info -->
                                    <td>
                                        <div class="app-person">
                                            <span class="applicant-pill"><?php echo $initial; ?></span>
                                            <div class="app-person-text">
                                                <span class="app-name"><?php echo $applicantName; ?></span>
                                                <span class="app-meta"><?php echo htmlentities($app['email'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Pet Info -->
                                    <td>
                                        <div class="app-person-text">
                                            <span class="app-name"><?php echo htmlentities($app['pet_name'] ?? 'N/A'); ?></span>
                                            <?php if (!empty($app['pet_type'])): ?>
                                                <span class="app-kind <?php echo $isCat ? 'is-cat' : 'is-dog'; ?>"><?php echo htmlentities($app['pet_type']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Date -->
                                    <td class="app-date"><?php echo $formattedDate; ?></td>

                                    <!-- Status Badge -->
                                    <td>
                                        <span class="badge-app <?php echo $badgeClass; ?>"><?php echo htmlentities($status); ?></span>
                                    </td>

                                    <!-- Match % na may maliit na bar -->
                                    <td>
                                        <?php if ($score === null): ?>
                                            <span class="app-match-none">N/A</span>
                                        <?php else: ?>
                                            <div class="app-match match-<?php echo $matchTone; ?>">
                                                <strong><?php echo (int) $score; ?>%</strong>
                                                <span class="app-match-bar"><span style="width: <?php echo max(0, min(100, (int) $score)); ?>%;"></span></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Action Button -->
                                    <td style="text-align: right;">
                                        <?php if ($statusKey === 'pending' || $statusKey === 'under review'): ?>
                                            <a href="review_application.php?application_id=<?php echo (int) $app['application_id']; ?>" class="btn-action btn-action-primary">
                                                Review <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                                            </a>
                                        <?php else: ?>
                                            <a href="review_application.php?application_id=<?php echo (int) $app['application_id']; ?>" class="btn-action btn-action-view">
                                                View <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!empty($applications)): ?>
                                <tr id="appNoMatch" hidden>
                                    <td colspan="7" class="app-empty">No applications match your search.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Match % Explanation Modal -->
<div id="matchInfoModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:600px;">
        <div class="modal-header">
            <div class="modal-title">How is Match % calculated?</div>
            <button type="button" class="btn btn-ghost" onclick="closeMatchInfoModal()" aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px; color:#4b5563; margin:0 0 12px 0;">
                Ikinukumpara ng system ang mga sagot ng adopter sa kanilang <strong>Adopter Preferences</strong> laban sa aktwal na katangian ng pet.
                Bawat kategorya ay may sariling bigat (weight); kung tugma o malapit ang sagot, nakukuha ang buong puntos para doon. Idinaragdag ang lahat ng puntos para makuha ang final na Match %.
                Kung wala pang na-set na preferences ang adopter, "N/A" ang lalabas.
            </p>
            <table class="match-criteria-table">
                <thead><tr><th>Category</th><th style="text-align:right;">Weight</th></tr></thead>
                <tbody>
                    <tr><td>Pet Kind (Dog/Cat)</td><td style="text-align:right;">20%</td></tr>
                    <tr><td>Size</td><td style="text-align:right;">12%</td></tr>
                    <tr><td>Energy Level</td><td style="text-align:right;">12%</td></tr>
                    <tr><td>Age Group</td><td style="text-align:right;">10%</td></tr>
                    <tr><td>Health Condition</td><td style="text-align:right;">8%</td></tr>
                    <tr><td>Breed</td><td style="text-align:right;">8%</td></tr>
                    <tr><td>Gender</td><td style="text-align:right;">6%</td></tr>
                    <tr><td>Temperament</td><td style="text-align:right;">6%</td></tr>
                    <tr><td>Living Space Fit</td><td style="text-align:right;">6%</td></tr>
                    <tr><td>Activity Level Fit</td><td style="text-align:right;">6%</td></tr>
                    <tr><td>Maintenance Fit</td><td style="text-align:right;">3%</td></tr>
                    <tr><td>Allergy Fit</td><td style="text-align:right;">3%</td></tr>
                </tbody>
            </table>
            <p style="font-size:12px; color:#9ca3af; margin-top:12px;">
                Para makita ang detalyadong breakdown ng isang partikular na application, i-click ang "Review" at tignan ang "View Matching" section.
            </p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="closeMatchInfoModal()">Close</button>
        </div>
    </div>
</div>

<script>
    function openMatchInfoModal() {
        const modal = document.getElementById('matchInfoModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeMatchInfoModal() {
        const modal = document.getElementById('matchInfoModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMatchInfoModal();
    });

    // Search at filter ng table (sa browser lang, walang reload) + mga card na nagsasala
    (function () {
        const search = document.getElementById('appSearch');
        const rows = Array.from(document.querySelectorAll('#appBody .app-row'));
        const noMatch = document.getElementById('appNoMatch');
        const filters = Array.from(document.querySelectorAll('.app-filter'));
        const panel = document.getElementById('appTable');
        if (!search) return;
        let filter = 'all';

        function apply() {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            rows.forEach(function (r) {
                const ok = (filter === 'all' || r.dataset.status === filter) && (!q || r.dataset.search.indexOf(q) !== -1);
                r.hidden = !ok;
                if (ok) shown++;
            });
            if (noMatch) noMatch.hidden = shown > 0 || !rows.length;
        }
        function setFilter(f) {
            filter = filters.some(function (b) { return b.dataset.filter === f; }) ? f : 'all';
            filters.forEach(function (b) { b.classList.toggle('active', b.dataset.filter === filter); });
            apply();
        }
        function flash() {
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            panel.classList.remove('app-flash');
            void panel.offsetWidth; // i-restart ang animation
            panel.classList.add('app-flash');
        }

        search.addEventListener('input', apply);
        filters.forEach(function (b) { b.addEventListener('click', function () { setFilter(b.dataset.filter); }); });

        // Mga card: salain ang table, saka dumausdos papunta roon
        document.querySelectorAll('.sp-card[data-app-filter]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                search.value = '';
                setFilter(card.dataset.appFilter);
                flash();
            });
        });

        // Galing sa ibang page (?filter=approved): ilapat agad
        const initial = new URLSearchParams(window.location.search).get('filter');
        if (initial) { setFilter(initial); }
    })();
</script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>