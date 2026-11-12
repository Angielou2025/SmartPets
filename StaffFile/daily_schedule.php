<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

// Check if staff user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Piniling petsa para sa Timeline (default: ngayong araw)
$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) || !strtotime($selected_date)) {
    $selected_date = date('Y-m-d');
}
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

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

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Personal na tasks ng staff na ito: kasama dito ang mga tasks ngayong araw
// PLUS ang mga lumang task na hindi pa natatapos - hindi ito nawawala kapag
// nagbago ang araw, manatili itong makikita hangga't hindi pa na-che-check.
// Ang tanging nawawala sa listahan ay ang mga LUMANG task na tapos na.
$tasks_list = [];
try {
    $tasksStmt = $pdo->prepare(
        "SELECT task_id, task_name, task_time, task_date, is_done, completed_at
         FROM daily_tasks
         WHERE staff_id = ? AND (task_date = CURDATE() OR is_done = 0)
         ORDER BY is_done ASC, task_date ASC, task_time ASC, task_id ASC"
    );
    $tasksStmt->execute([$user_id]);
    $tasks_list = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tasks_list = [];
}

// Counts para sa Stat Cards (totoong data)
$todays_tasks = count(array_filter($tasks_list, fn($t) => $t['task_date'] === date('Y-m-d')));
$completed_today = count(array_filter($tasks_list, fn($t) => (int) $t['is_done'] === 1));
$pending_tasks = count(array_filter($tasks_list, fn($t) => (int) $t['is_done'] === 0));
$interviews = safe_count(
    $pdo,
    "SELECT COUNT(*) AS c FROM adoption_applications
     WHERE preferred_interview_date = CURDATE() AND application_status IN ('Pending', 'Under Review')
       AND (schedule_status IS NULL OR schedule_status = 'Accepted')"
);

// Progress percentage computation
$progress_percent = $todays_tasks > 0 ? round(($completed_today / $todays_tasks) * 100) : 0;

// Timeline: totoong scheduled adoption interviews para sa piniling petsa
$timeline_events = [];
try {
    $interviewStmt = $pdo->prepare(
        "SELECT aa.application_id, aa.full_name, aa.preferred_interview_time, aa.application_status,
                p.name AS pet_name
         FROM adoption_applications aa
         JOIN pet_inventory p ON aa.pet_id = p.pet_id
         WHERE aa.preferred_interview_date = ?
           AND (aa.schedule_status IS NULL OR aa.schedule_status = 'Accepted')
         ORDER BY aa.preferred_interview_time ASC"
    );
    $interviewStmt->execute([$selected_date]);
    $timeline_events = $interviewStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $timeline_events = [];
}

$statusBadgeMap = [
    'Pending' => ['label' => 'Scheduled', 'class' => 'badge-grey', 'color' => '#6b7280'],
    'Under Review' => ['label' => 'Under Review', 'class' => 'badge-orange', 'color' => '#f57c00'],
    'Approved' => ['label' => 'Approved', 'class' => 'badge-green', 'color' => '#2e7d32'],
    'Rejected' => ['label' => 'Rejected', 'class' => 'badge-red', 'color' => '#c5221f'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Schedule - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        .schedule-grid-container {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 20px;
            margin-top: 20px;
        }

        @media (max-width: 1024px) {
            .schedule-grid-container {
                grid-template-columns: 1fr;
            }
        }

        /* Timeline Box Styles */
        .schedule-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
        }

        .date-navigator {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-date-nav {
            background: #f4f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-weight: bold;
            color: #4b5563;
            text-decoration: none;
        }

        .date-badge {
            background: #e6f4ea;
            color: #1e7e34;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 800;
            font-size: 13px;
        }

        /* Timeline Event Item */
        .timeline-item {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            align-items: flex-start;
        }

        .timeline-time {
            width: 80px;
            font-weight: 700;
            font-size: 13px;
            color: #9ca3af;
            padding-top: 10px;
        }

        .timeline-content {
            flex: 1;
            background: #fafafa;
            border-radius: 12px;
            padding: 14px 18px;
            border-left: 5px solid #1f6b48;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .timeline-title {
            font-weight: 800;
            font-size: 15px;
            color: #111827;
            margin-bottom: 4px;
        }

        .timeline-sub {
            font-size: 12px;
            color: #6b7280;
        }

        /* Badges */
        .status-pill {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-green { background: #e6f4ea; color: #1e7e34; }
        .badge-orange { background: #fef3d6; color: #b25900; }
        .badge-light-green { background: #e8f5e9; color: #2e7d32; }
        .badge-grey { background: #f3f4f6; color: #6b7280; }
        .badge-red { background: #fce8e6; color: #c5221f; }

        /* Task Progress Widget Right Side */
        .task-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .task-delete-btn {
            margin-left: auto;
            background: none;
            border: none;
            color: #d1d5db;
            font-size: 16px;
            cursor: pointer;
            line-height: 1;
            padding: 2px 6px;
        }

        .task-delete-btn:hover {
            color: #dc3545;
        }

        .add-task-form {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
        }

        .add-task-form input[type="text"] {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
        }

        .add-task-form input[type="time"] {
            width: 110px;
            padding: 8px 8px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
        }

        .add-task-form button {
            background: #1f6b48;
            color: #fff;
            border: none;
            border-radius: 8px;
            width: 36px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .add-task-form button:hover {
            background: #164e35;
        }

        .task-checkbox {
            width: 20px;
            height: 20px;
            border-radius: 6px;
            accent-color: #1f6b48;
            cursor: pointer;
            margin-top: 2px;
        }

        .progress-bar-bg {
            background: #e5e7eb;
            height: 8px;
            border-radius: 4px;
            width: 100%;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-bar-fill {
            background: #1f6b48;
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* ===================== Bagong itsura (kulay ng staff) ===================== */
        .schedule-grid-container { grid-template-columns: minmax(0, 1fr) 360px; align-items: start; }
        .schedule-card { border: 1px solid #eef0f3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05); scroll-margin-top: 16px; min-width: 0; }

        /* Header ng bawat panel: icon tile + pamagat */
        .sc-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .sc-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .sc-icon { width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .sc-icon svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .sc-title h3 { margin: 0; font-size: 17px; font-weight: 800; color: #0f1b3d; }
        .sc-sub { display: block; margin-top: 2px; font-size: 12.5px; color: #8a94a6; }

        /* Palit ng araw */
        .date-navigator { gap: 6px; }
        .btn-date-nav {
            width: 34px; height: 34px; border-radius: 10px; background: #fff; border: 1px solid #dbe3f4; color: #1e3a8a;
            transition: background-color .2s ease, border-color .2s ease;
        }
        .btn-date-nav:hover { background: #eef2fb; border-color: #c9d6f2; }
        .btn-date-nav svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
        .date-badge { background: #eef2fb; color: #1e3a8a; border-radius: 10px; padding: 8px 14px; letter-spacing: .3px; }

        /* Timeline */
        .timeline-time { color: #1e3a8a; }
        .timeline-content { background: #f8f9fc; border-radius: 12px; gap: 12px; }

        /* Walang laman */
        .sc-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 34px 12px; text-align: center; color: #8a94a6; font-size: 13.5px; }
        .sc-empty-sm { padding: 18px 8px; font-size: 13px; }
        .sc-empty-icon { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; background: #f3f5f9; color: #a3adbd; }
        .sc-empty-icon svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }

        /* Add task: kasya sa loob ng panel (hindi na lumalampas ang "+") */
        .add-task-form { display: grid; grid-template-columns: minmax(0, 1fr) 112px 40px; gap: 8px; }
        .add-task-form input[type="text"],
        .add-task-form input[type="time"] {
            width: 100%; min-width: 0; height: 40px; box-sizing: border-box; padding: 0 12px;
            border: 1.5px solid #e2e6ee; border-radius: 10px; font-family: inherit; font-size: 13.5px; color: #1f2937;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .add-task-form input[type="time"] { padding: 0 8px; }
        .add-task-form input:focus { outline: none; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        .add-task-form button {
            width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #2a62de, #1e3a8a); box-shadow: 0 6px 12px rgba(30, 58, 138, 0.25);
            transition: transform .15s ease, filter .2s ease;
        }
        .add-task-form button:hover { background: linear-gradient(135deg, #2a62de, #1e3a8a); filter: brightness(1.1); transform: translateY(-1px); }
        .add-task-form button svg { width: 18px; height: 18px; fill: none; stroke: #fff; stroke-width: 2.6; stroke-linecap: round; }

        /* Checklist */
        .task-item { align-items: center; padding: 11px 4px; border-radius: 10px; transition: background-color .15s ease; }
        .task-item:hover { background: #f8f9fc; }
        .task-checkbox { accent-color: #1e40af; margin-top: 0; }
        .task-delete-btn { width: 28px; height: 28px; border-radius: 8px; color: #c3cad6; transition: background-color .15s ease, color .15s ease; }
        .task-delete-btn:hover { background: #fdecea; color: #c5221f; }

        /* Progress */
        .progress-label { display: flex; justify-content: space-between; align-items: baseline; font-size: 12.5px; font-weight: 700; color: #6b7280; }
        .progress-label strong { font-size: 15px; color: #1e3a8a; }
        .progress-bar-bg { height: 10px; border-radius: 999px; background: #e8ecf3; }
        .progress-bar-fill { border-radius: 999px; background: linear-gradient(90deg, #1e3a8a, #3b82f6); transition: width .6s cubic-bezier(.22, 1, .36, 1); }

        @media (max-width: 1024px) {
            .schedule-grid-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="staff-dashboard-root">
<div class="app-container">
    <!-- Reusable Sidebar Component -->
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <!-- Top Header Navigation -->
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
                        <circle class="ic-fill" cx="12" cy="12" r="8.5"/>
                        <path d="M12 7.5V12l3 2"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Daily Schedule</h1>
                    <p>View today's shelter activities, appointments, and tasks</p>
                </div>
            </header>

            <?php if (!empty($success_message)): ?>
                <div style="background:#e8f5e9; color:#2e7d32; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <!-- Top Stat Cards (Base sa Mockup Design) -->
            <!-- Mga stat card: madilim hanggang maliwanag; dinadala sa kaugnay na panel -->
            <div class="cards-grid sp-cards">
                <a class="stat-card sp-card sp-c1" href="#tasks" title="See today's tasks">
                    <div class="stat-info">
                        <h1><?php echo str_pad($todays_tasks, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Today's Tasks<br><small>Scheduled</small></p>
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

                <a class="stat-card sp-card sp-c2" href="#tasks" title="See pending tasks">
                    <div class="stat-info">
                        <h1><?php echo str_pad($pending_tasks, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Pending Tasks<br><small>Including overdue</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Hourglass -->
                        <svg viewBox="0 0 24 24">
                            <path d="M6.5 3h11M6.5 21h11"/>
                            <path d="M7.5 3v3.5a4.5 4.5 0 0 0 9 0V3M7.5 21v-3.5a4.5 4.5 0 0 1 9 0V21"/>
                            <path class="ic-fill" d="M9.5 18.5a2.5 2.5 0 0 1 5 0V20h-5z"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c3" href="#schedule" title="See today's interviews">
                    <div class="stat-info">
                        <h1><?php echo str_pad($interviews, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Interviews<br><small>Adoption</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Dalawang tao na nag-uusap -->
                        <svg viewBox="0 0 24 24">
                            <circle class="ic-fill" cx="8.5" cy="8" r="3"/>
                            <path d="M3 19c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/>
                            <path d="M15 4.5h5a1 1 0 0 1 1 1V9a1 1 0 0 1-1 1h-2.5L15.5 11.5V10H15a1 1 0 0 1-1-1V5.5a1 1 0 0 1 1-1z"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c4" href="#tasks" title="See completed tasks">
                    <div class="stat-info">
                        <h1><?php echo str_pad($completed_today, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Completed<br><small>Today</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Bilog na may check -->
                        <svg viewBox="0 0 24 24">
                            <circle class="ic-fill" cx="12" cy="12" r="8.5"/>
                            <path d="M8.3 12.3l2.5 2.5 5-5.3"/>
                        </svg>
                    </div>
                </a>
            </div>

            <!-- Two Column Main Layout -->
            <div class="schedule-grid-container">
                
                <!-- Left Column: Today's Schedule Timeline -->
                <div class="schedule-card" id="schedule">
                    <div class="sc-head">
                        <div class="sc-title">
                            <span class="sc-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4"/></svg>
                            </span>
                            <div>
                                <h3><?php echo $selected_date === date('Y-m-d') ? "Today's Schedule" : 'Schedule'; ?></h3>
                                <span class="sc-sub"><?php echo date('l, F d, Y', strtotime($selected_date)); ?></span>
                            </div>
                        </div>

                        <div class="date-navigator">
                            <a href="?date=<?php echo $prev_date; ?>" class="btn-date-nav" aria-label="Previous day" title="Previous day">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                            </a>
                            <span class="date-badge"><?php echo strtoupper(date('M d, Y', strtotime($selected_date))); ?></span>
                            <a href="?date=<?php echo $next_date; ?>" class="btn-date-nav" aria-label="Next day" title="Next day">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                            </a>
                        </div>
                    </div>

                    <!-- Timeline Items -->
                    <div class="timeline-list">
                        <?php if (empty($timeline_events)): ?>
                            <div class="sc-empty">
                                <span class="sc-empty-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 9.5h17M8 3v4M16 3v4M9.5 14.5h5"/></svg>
                                </span>
                                <span>No adoption interviews scheduled for today.</span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($timeline_events as $event):
                            $statusInfo = $statusBadgeMap[$event['application_status']] ?? $statusBadgeMap['Pending'];
                            $interviewTime = !empty($event['preferred_interview_time'])
                                ? date('g:i A', strtotime($event['preferred_interview_time']))
                                : 'TBD';
                        ?>
                            <div class="timeline-item">
                                <div class="timeline-time"><?php echo htmlentities($interviewTime); ?></div>
                                <div class="timeline-content" style="border-left-color: <?php echo $statusInfo['color']; ?>;">
                                    <div>
                                        <div class="timeline-title">Adoption Interview</div>
                                        <div class="timeline-sub">Applicant: <?php echo htmlentities($event['full_name']); ?> &bull; Pet: <?php echo htmlentities($event['pet_name']); ?></div>
                                    </div>
                                    <div>
                                        <span class="status-pill <?php echo $statusInfo['class']; ?>">
                                            <?php echo htmlentities($statusInfo['label']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Right Column: Task Progress Widget -->
                <div class="schedule-card" id="tasks" style="height: fit-content;">
                    <div class="sc-head">
                        <div class="sc-title">
                            <span class="sc-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8.5 12.2l2.4 2.4 4.6-4.8"/></svg>
                            </span>
                            <div>
                                <h3>Task Progress</h3>
                                <span class="sc-sub">Daily Checklist</span>
                            </div>
                        </div>
                    </div>

                    <!-- Add Task -->
                    <form method="POST" action="add_task.php" class="add-task-form">
                        <input type="text" name="task_name" placeholder="Add a task..." maxlength="255" required aria-label="Task name">
                        <input type="time" name="task_time" aria-label="Task time (optional)">
                        <button type="submit" title="Add task" aria-label="Add task">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                    </form>

                    <!-- Checklist -->
                    <div style="margin-bottom: 24px;">
                        <?php if (empty($tasks_list)): ?>
                            <div class="sc-empty sc-empty-sm">
                                <span class="sc-empty-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 12h6"/></svg>
                                </span>
                                <span>No tasks yet for today. Add one above.</span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($tasks_list as $task): ?>
                            <?php $isDone = (int) $task['is_done'] === 1; ?>
                            <form method="POST" action="toggle_task.php" class="task-item">
                                <input type="hidden" name="task_id" value="<?php echo (int) $task['task_id']; ?>">
                                <input type="checkbox" class="task-checkbox" onchange="this.form.submit()" <?php echo $isDone ? 'checked' : ''; ?>>
                                <div>
                                    <div style="font-size: 14px; font-weight: 700; color: #111827; <?php echo $isDone ? 'text-decoration: line-through; color: #9ca3af;' : ''; ?>">
                                        <?php echo htmlentities($task['task_name']); ?>
                                    </div>
                                    <div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">
                                        <?php if ($isDone && !empty($task['completed_at'])): ?>
                                            Completed at <?php echo date('g:i A', strtotime($task['completed_at'])); ?>
                                        <?php elseif (!empty($task['task_time'])): ?>
                                            Scheduled at <?php echo date('g:i A', strtotime($task['task_time'])); ?>
                                        <?php else: ?>
                                            No specific time
                                        <?php endif; ?>
                                        <?php if (!$isDone && $task['task_date'] !== date('Y-m-d')): ?>
                                            <span style="color: #f57c00; font-weight: 700;">&bull; Pending since <?php echo date('M d', strtotime($task['task_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <button type="submit" formaction="delete_task.php" class="task-delete-btn" title="Delete task" onclick="return confirm('Delete this task?')">&times;</button>
                            </form>
                        <?php endforeach; ?>
                    </div>

                    <!-- Daily Progress Bar -->
                    <div>
                        <div class="progress-label">
                            <span>Daily Progress</span>
                            <strong><?php echo $progress_percent; ?>%</strong>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: <?php echo $progress_percent; ?>%;"></div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>