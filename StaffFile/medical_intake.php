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

// Safe count helper function
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

// Stat Card Counts - lahat base na sa totoong data sa pet_inventory / medical_records / pet_intakes
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
$checkups_week = safe_count($pdo, "SELECT COUNT(*) AS c FROM medical_records WHERE status = 'Checkup' AND YEARWEEK(checkup_date, 1) = YEARWEEK(CURDATE(), 1)");
$healthy_pets = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_inventory WHERE health_condition = 'Healthy'");
$new_intakes = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_intakes WHERE YEARWEEK(intake_date, 1) = YEARWEEK(CURDATE(), 1)");

// Medical Records List - pinakahuling record ng bawat pet na may medical history
$medical_records = [];
try {
    $sql = "SELECT mr.record_id, mr.status, mr.checkup_date, pi.name AS pet_name, pi.kind, pi.pet_id, pi.photo_url
            FROM medical_records mr
            INNER JOIN (SELECT pet_id, MAX(checkup_date) AS max_date FROM medical_records GROUP BY pet_id) latest
                ON mr.pet_id = latest.pet_id AND mr.checkup_date = latest.max_date
            JOIN pet_inventory pi ON pi.pet_id = mr.pet_id
            ORDER BY mr.checkup_date DESC
            LIMIT 10";
    $stmt = $pdo->query($sql);
    if ($stmt) {
        $medical_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $medical_records = [];
}

$badgeClassMap = [
    'Treatment' => 'badge-treatment',
    'Checkup' => 'badge-checkup',
    'Vaccines' => 'badge-vaccines',
    'Recovery' => 'badge-recovery',
];

// Recent Intakes List
$recent_intakes = [];
try {
    $sql = "SELECT intake_id, pet_name, species, age, intake_date, status FROM pet_intakes ORDER BY intake_date DESC, intake_id DESC LIMIT 5";
    $stmt = $pdo->query($sql);
    if ($stmt) {
        $recent_intakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $recent_intakes = [];
}

// Pet list para sa "Add Checkup" modal dropdown
$pet_options = [];
try {
    $pet_options = $pdo->query("SELECT pet_id, name, kind, breed FROM pet_inventory ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pet_options = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medical & Intakes - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        .medical-grid-container {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 12px 20px;
            margin-top: 6px;
            padding: 0 8px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .medical-grid-container {
                grid-template-columns: 1fr;
            }
        }

        /* Nasa itaas ng Recent Intakes ang mga button; magkapantay ang dalawang panel (taas at haba) */
        .med-side { display: contents; }
        .med-side-actions { display: flex; gap: 10px; grid-column: 2; grid-row: 1; }
        #medRecords { grid-column: 1; grid-row: 2; }
        #recentIntakes { grid-column: 2; grid-row: 2; }
        #medRecords, #recentIntakes { align-self: stretch; }
        @media (max-width: 1024px) {
            .med-side-actions, #medRecords, #recentIntakes { grid-column: auto; grid-row: auto; }
        }
        .med-side-actions .btn-med { flex: 1; justify-content: center; height: 40px; }

        /* ===== Mga panel (kapareho ng Pet Monitoring) ===== */
        .med-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 22px 24px;
            border: 1px solid #eef0f3;
            box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05);
            scroll-margin-top: 16px;
            min-width: 0;
        }
        .med-panel.med-flash { animation: medFlash 1s ease; }
        @keyframes medFlash { 0% { box-shadow: 0 0 0 0 rgba(47, 91, 196, 0.45); } 100% { box-shadow: 0 0 0 14px rgba(47, 91, 196, 0); } }

        .med-panel-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
        .med-panel-head .grow { flex: 1; min-width: 160px; }
        .med-title-icon {
            width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: #eef2fb; color: #1e3a8a;
        }
        .med-title-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .med-panel-head h3 { margin: 0; font-size: 18px; font-weight: 800; color: #0f1b3d; }
        .med-sub { font-size: 13px; color: #6b7280; }

        /* Mga button (asul ng staff) */
        .btn-med {
            height: 36px; padding: 0 14px; border-radius: 9px;
            display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
            font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
            transition: filter .2s ease, background-color .2s ease, transform .15s ease;
        }
        .btn-med svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; }
        .btn-med:hover { transform: translateY(-1px); }
        .btn-med-primary { border: none; color: #fff; background: linear-gradient(135deg, #2a62de, #1e3a8a); box-shadow: 0 4px 10px rgba(30, 58, 138, 0.22); }
        .btn-med-primary:hover { filter: brightness(1.08); }
        .btn-med-outline { background: #fff; color: #1e3a8a; border: 1.5px solid #dbe3f4; }
        .btn-med-outline:hover { background: #eef2fb; }

        /* Salaan ng Medical Records */
        .med-filters { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .med-chip {
            border: 1.5px solid #e3e8f2; background: #fff; color: #4b5563;
            padding: 5px 12px; border-radius: 999px; font-family: inherit; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: background-color .2s ease, color .2s ease, border-color .2s ease;
        }
        .med-chip:hover { border-color: #c9d6f2; color: #1e3a8a; }
        .med-chip.active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
        .med-chip .n { opacity: .7; margin-left: 2px; }

        /* Isang medical record */
        .med-record-item {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 12px 10px; margin: 0 -10px;
            border-bottom: 1px solid #f1f3f6; border-radius: 10px;
            transition: background-color .15s ease;
        }
        .med-record-item:last-child { border-bottom: none; }
        .med-record-item:hover { background: #f8faff; }
        .med-record-item[hidden] { display: none; }
        .med-record-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .med-record-name { font-weight: 800; font-size: 15px; color: #111827; }
        .med-record-meta { font-size: 12.5px; color: #8a94a6; }
        .med-record-meta strong { color: #4b5563; font-weight: 700; }

        .pet-avatar {
            width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            background: #eef2fb; color: #1e3a8a;
        }
        .pet-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .pet-avatar svg { width: 22px; height: 22px; fill: currentColor; }

        /* Status badges (may tuldok, gaya ng Pet Monitoring) */
        .badge-med, .badge-intake-status {
            display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
            padding: 4px 10px 4px 9px; border-radius: 20px; font-size: 12px; font-weight: 700;
        }
        .badge-med::before, .badge-intake-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge-treatment { background: #fee2e2; color: #b91c1c; }
        .badge-checkup { background: #e0ebff; color: #1e40af; }
        .badge-vaccines { background: #fef3c7; color: #92400e; }
        .badge-recovery { background: #dcfce7; color: #166534; }
        .badge-intake-status { background: #fef3c7; color: #92400e; }
        .badge-intake-status.st-cleared { background: #dcfce7; color: #166534; }
        .badge-intake-status.st-quarantine { background: #fee2e2; color: #b91c1c; }
        .badge-intake-status.st-vaccination { background: #e0ebff; color: #1e40af; }

        /* Recent Intakes */
        .intake-card {
            background: #fafbfd; border: 1px solid #eef0f3; border-radius: 12px;
            padding: 14px; margin-bottom: 12px;
        }
        .intake-card:last-child { margin-bottom: 0; }
        .intake-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }
        .intake-id { font-size: 11px; font-weight: 800; color: #2f5bc4; letter-spacing: .3px; display: block; }
        .intake-name { font-size: 15px; font-weight: 800; color: #111827; }
        .intake-details-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
            background: #fff; padding: 8px 12px; border-radius: 9px; border: 1px solid #eef0f3;
        }
        .intake-detail-box span { display: block; font-size: 10px; color: #9aa3ad; text-transform: uppercase; font-weight: 700; letter-spacing: .3px; }
        .intake-detail-box strong { font-size: 12.5px; color: #111827; }
        .intake-footer { display: flex; justify-content: flex-end; margin-top: 10px; }
        .btn-advance-intake { height: 30px; font-size: 12px; padding: 0 12px; }

        /* Walang laman */
        .empty-note {
            text-align: center; padding: 34px 12px; color: #9aa3ad; font-size: 13.5px;
            border: 1.5px dashed #e3e8f2; border-radius: 12px;
        }
        .empty-note svg { display: block; width: 34px; height: 34px; margin: 0 auto 10px; fill: none; stroke: #c3cbe0; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }

        @media (max-width: 560px) {
            .med-panel { padding: 18px 16px; }
            .med-record-item { flex-wrap: wrap; }
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .form-grid-2 .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #102a2a;
        }

        .form-grid-2 .form-group input,
        .form-grid-2 .form-group select,
        .form-grid-2 .form-group textarea {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            color: #102a2a;
            font-family: inherit;
        }

        @media (max-width: 560px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }
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
            <header class="sp-page-head">
                <span class="sp-page-icon" aria-hidden="true">
                    <!-- Clipboard na may krus (medical) -->
                    <svg viewBox="0 0 24 24">
                        <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/>
                        <rect class="ic-fill" x="9" y="2.5" width="6" height="3.5" rx="1"/>
                        <path d="M12 10.5v6M9 13.5h6"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Medical &amp; Intakes</h1>
                    <p>Monitor pet health records and manage new shelter intakes</p>
                </div>
            </header>

            <?php if (!empty($_SESSION['success_message'])): ?>
                <div style="background:#e6f4ea;color:#1e7e34;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
                    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error_message'])): ?>
                <div style="background:#fce8e6;color:#c5221f;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <?php
            // Bilang ng bawat uri sa Medical Records (para sa salaan)
            $recCounts = ['Treatment' => 0, 'Checkup' => 0, 'Vaccines' => 0, 'Recovery' => 0];
            foreach ($medical_records as $rec) {
                if (isset($recCounts[$rec['status']])) { $recCounts[$rec['status']]++; }
            }
            ?>

            <!-- Mga stat card: madilim hanggang maliwanag na asul (kapareho ng ibang staff page) -->
            <div class="cards-grid sp-cards">
                <a class="stat-card sp-card sp-c1" href="#medRecords" data-med-filter="Treatment" title="Show pets under treatment">
                    <div class="stat-info">
                        <h1><?php echo str_pad($under_treatment, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Under Treatment<br><small>Needs attention</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Puso na may pulso -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.3a4.3 4.3 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>
                            <path d="M3.5 12.5h4l1.6-2.6 2.4 4.6 1.6-2h4.4"/>
                        </svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c2" href="#medRecords" data-med-filter="Checkup" title="Show checkups">
                    <div class="stat-info">
                        <h1><?php echo str_pad($checkups_week, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Checkups<br><small>This week</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Stethoscope -->
                        <svg viewBox="0 0 24 24">
                            <path d="M6 3.5v5a4.5 4.5 0 0 0 9 0v-5"/>
                            <path d="M10.5 13v2.5a4.5 4.5 0 0 0 9 0V13"/>
                            <circle class="ic-fill" cx="19.5" cy="11" r="2"/>
                        </svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c3" href="pet_inventory.php" title="Open Pet Inventory">
                    <div class="stat-info">
                        <h1><?php echo str_pad($healthy_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Healthy Pets<br><small>Cleared</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Shield na may check -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/>
                            <path d="M9 12l2.2 2.2L15.5 10"/>
                        </svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c4" href="#recentIntakes" data-med-goto="recentIntakes" title="Show recent intakes">
                    <div class="stat-info">
                        <h1><?php echo str_pad($new_intakes, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>New Intakes<br><small>This week</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Paw -->
                        <svg viewBox="0 0 24 24">
                            <ellipse cx="6" cy="10" rx="1.9" ry="2.4"/>
                            <ellipse cx="9.8" cy="6.2" rx="1.9" ry="2.4"/>
                            <ellipse cx="14.2" cy="6.2" rx="1.9" ry="2.4"/>
                            <ellipse cx="18" cy="10" rx="1.9" ry="2.4"/>
                            <path class="ic-fill" d="M12 11.5c3 0 5.5 3.3 5.5 5.6 0 1.8-1.5 2.6-3 2.6-1 0-1.6-.5-2.5-.5s-1.5.5-2.5.5c-1.5 0-3-.8-3-2.6 0-2.3 2.5-5.6 5.5-5.6z"/>
                        </svg>
                    </div>
                </a>
            </div>

            <!-- Two-Column Main Layout Grid -->
            <div class="medical-grid-container">

                <!-- Left Column: Medical Records -->
                <div class="med-panel" id="medRecords">
                    <div class="med-panel-head">
                        <span class="med-title-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M12 10.5v6M9 13.5h6"/></svg>
                        </span>
                        <div class="grow">
                            <h3>Medical Records</h3>
                            <span class="med-sub">Latest record of each pet</span>
                        </div>
                    </div>

                    <?php if (count($medical_records) > 0): ?>
                        <div class="med-filters" role="group" aria-label="Filter records">
                            <button type="button" class="med-chip active" data-filter="all">All <span class="n"><?php echo count($medical_records); ?></span></button>
                            <?php foreach ($recCounts as $st => $n): ?>
                                <button type="button" class="med-chip" data-filter="<?php echo $st; ?>"><?php echo $st; ?> <span class="n"><?php echo $n; ?></span></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Medical Records List -->
                    <div class="med-records-list">
                        <?php if (count($medical_records) > 0): ?>
                            <?php foreach ($medical_records as $rec): ?>
                                <div class="med-record-item" data-status="<?php echo htmlspecialchars($rec['status']); ?>">
                                    <div class="med-record-left">
                                        <div class="pet-avatar">
                                            <?php if (!empty($rec['photo_url'])): ?>
                                                <img src="/Caps2/uploads/<?php echo htmlspecialchars($rec['photo_url']); ?>" alt="" onerror="this.remove()">
                                            <?php else: ?>
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="6" cy="10" rx="1.9" ry="2.4"/><ellipse cx="9.8" cy="6.2" rx="1.9" ry="2.4"/><ellipse cx="14.2" cy="6.2" rx="1.9" ry="2.4"/><ellipse cx="18" cy="10" rx="1.9" ry="2.4"/><path d="M12 11.5c3 0 5.5 3.3 5.5 5.6 0 1.8-1.5 2.6-3 2.6-1 0-1.6-.5-2.5-.5s-1.5.5-2.5.5c-1.5 0-3-.8-3-2.6 0-2.3 2.5-5.6 5.5-5.6z"/></svg>
                                            <?php endif; ?>
                                        </div>
                                        <div style="min-width: 0;">
                                            <div class="med-record-name"><?php echo htmlspecialchars($rec['pet_name']); ?></div>
                                            <div class="med-record-meta">
                                                <?php echo htmlspecialchars($rec['kind'] ?? 'Pet'); ?> &middot;
                                                <strong>PET-<?php echo str_pad((string) $rec['pet_id'], 3, '0', STR_PAD_LEFT); ?></strong> &middot;
                                                Last checkup: <?php echo htmlspecialchars(date('M j, Y', strtotime($rec['checkup_date']))); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="badge-med <?php echo $badgeClassMap[$rec['status']] ?? 'badge-checkup'; ?>">
                                        <?php echo htmlspecialchars($rec['status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <p class="empty-note" id="medFilterEmpty" hidden>No pets with this record type right now.</p>
                        <?php else: ?>
                            <p class="empty-note">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M12 10.5v6M9 13.5h6"/></svg>
                                No medical records yet. Click "Add Checkup" to log the first one.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: mga button + Recent Intakes -->
                <div class="med-side">
                <div class="med-side-actions">
                    <button type="button" class="btn-med btn-med-outline" onclick="openAddIntakeModal()">
                        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> New Intake
                    </button>
                    <button type="button" class="btn-med btn-med-primary" onclick="openAddCheckupModal()">
                        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg> Add Checkup
                    </button>
                </div>
                <div class="med-panel" id="recentIntakes">
                    <div class="med-panel-head">
                        <span class="med-title-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M3 11l9-7.5L21 11"/><path d="M5.5 9.2V20h13V9.2"/><path d="M12 11v6M9 14h6"/></svg>
                        </span>
                        <div class="grow">
                            <h3>Recent Intakes</h3>
                            <span class="med-sub">Newly admitted shelter animals</span>
                        </div>
                    </div>

                    <!-- Intakes List Cards -->
                    <div>
                        <?php if (count($recent_intakes) > 0): ?>
                            <?php foreach ($recent_intakes as $intake): ?>
                                <div class="intake-card">
                                    <div class="intake-header">
                                        <div>
                                            <span class="intake-id">INT-<?php echo str_pad((string) $intake['intake_id'], 3, '0', STR_PAD_LEFT); ?></span>
                                            <span class="intake-name"><?php echo htmlspecialchars($intake['pet_name']); ?></span>
                                        </div>
                                        <span class="badge-intake-status st-<?php echo strtolower(htmlspecialchars($intake['status'])); ?>">
                                            <?php echo htmlspecialchars($intake['status']); ?>
                                        </span>
                                    </div>

                                    <div class="intake-details-grid">
                                        <div class="intake-detail-box">
                                            <span>SPECIES</span>
                                            <strong><?php echo htmlspecialchars($intake['species']); ?></strong>
                                        </div>
                                        <div class="intake-detail-box">
                                            <span>AGE</span>
                                            <strong><?php echo htmlspecialchars($intake['age'] ?: '—'); ?></strong>
                                        </div>
                                        <div class="intake-detail-box">
                                            <span>DATE</span>
                                            <strong><?php echo htmlspecialchars(date('M d', strtotime($intake['intake_date']))); ?></strong>
                                        </div>
                                    </div>

                                    <?php if ($intake['status'] !== 'Cleared'): ?>
                                        <div class="intake-footer">
                                            <form method="POST" action="process_update_intake_status.php">
                                                <input type="hidden" name="intake_id" value="<?php echo (int) $intake['intake_id']; ?>">
                                                <button type="submit" class="btn-med btn-med-outline btn-advance-intake">Advance Status &rarr;</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="empty-note">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7.5L21 11"/><path d="M5.5 9.2V20h13V9.2"/><path d="M12 11v6M9 14h6"/></svg>
                                No recent intakes yet. Click "New Intake" to log the first one.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                </div>

            </div>

        </div>
    </main>
</div>

<!-- Add Checkup Modal -->
<div id="addCheckupModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:560px;">
        <div class="modal-header">
            <div class="modal-title">Add Medical Checkup</div>
            <button type="button" class="btn btn-ghost" onclick="closeAddCheckupModal()" aria-label="Close">✕</button>
        </div>
        <form action="process_add_medical_record.php" method="POST">
            <div class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Pet *</label>
                        <select name="pet_id" required>
                            <option value="">Select Pet</option>
                            <?php foreach ($pet_options as $p): ?>
                                <option value="<?php echo (int) $p['pet_id']; ?>">
                                    <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['kind']); ?> - <?php echo htmlspecialchars($p['breed']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Record Type *</label>
                        <select name="status" required>
                            <option value="Checkup">Checkup</option>
                            <option value="Treatment">Treatment</option>
                            <option value="Vaccines">Vaccines</option>
                            <option value="Recovery">Recovery</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="checkup_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Diagnosis, medication, observations..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeAddCheckupModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Record</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Intake Modal -->
<div id="addIntakeModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:560px;">
        <div class="modal-header">
            <div class="modal-title">New Pet Intake</div>
            <button type="button" class="btn btn-ghost" onclick="closeAddIntakeModal()" aria-label="Close">✕</button>
        </div>
        <form action="process_add_intake.php" method="POST">
            <div class="modal-body">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>Pet Name *</label>
                        <input type="text" name="pet_name" required>
                    </div>
                    <div class="form-group">
                        <label>Species *</label>
                        <select name="species" required>
                            <option value="">Select Species</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Age</label>
                        <input type="text" name="age" placeholder="e.g., 2 yrs">
                    </div>
                    <div class="form-group">
                        <label>Intake Date *</label>
                        <input type="date" name="intake_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Initial Status *</label>
                        <select name="status" required>
                            <option value="Assessment">Assessment</option>
                            <option value="Vaccination">Vaccination</option>
                            <option value="Quarantine">Quarantine</option>
                            <option value="Cleared">Cleared</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Condition on arrival, source, remarks..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeAddIntakeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Intake</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddCheckupModal() {
        const modal = document.getElementById('addCheckupModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeAddCheckupModal() {
        const modal = document.getElementById('addCheckupModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    function openAddIntakeModal() {
        const modal = document.getElementById('addIntakeModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeAddIntakeModal() {
        const modal = document.getElementById('addIntakeModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAddCheckupModal();
            closeAddIntakeModal();
        }
    });

    // Salaan ng Medical Records + pindot sa stat card (pupunta at kikislap ang panel)
    (function () {
        const chips = document.querySelectorAll('.med-chip');
        const items = document.querySelectorAll('.med-record-item');
        const emptyNote = document.getElementById('medFilterEmpty');

        function applyFilter(status) {
            let shown = 0;
            chips.forEach(function (c) { c.classList.toggle('active', c.dataset.filter === status); });
            items.forEach(function (it) {
                const show = status === 'all' || it.dataset.status === status;
                it.hidden = !show;
                if (show) shown++;
            });
            if (emptyNote) emptyNote.hidden = shown > 0;
        }

        function flash(panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            panel.classList.remove('med-flash');
            void panel.offsetWidth;
            panel.classList.add('med-flash');
        }

        chips.forEach(function (c) {
            c.addEventListener('click', function () { applyFilter(c.dataset.filter); });
        });

        document.querySelectorAll('[data-med-filter]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                if (chips.length) applyFilter(card.dataset.medFilter);
                flash(document.getElementById('medRecords'));
            });
        });
        document.querySelectorAll('[data-med-goto]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                flash(document.getElementById(card.dataset.medGoto));
            });
        });
    })();
</script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
