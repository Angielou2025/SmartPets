<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$sp_unread_count = sp_unread_message_count($pdo, (int) $_SESSION['user_id']);
$userStmt = $pdo->prepare('SELECT username, profile_image FROM users WHERE user_id = ?');
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';

$appStmt = $pdo->prepare(
    "SELECT aa.application_id, aa.pet_id, aa.application_status, aa.preferred_interview_date,
            aa.preferred_interview_time, aa.meet_and_greet_availability, aa.meeting_link, aa.meeting_link_sent_at,
            aa.interview_missed_at, aa.rebook_sent_at,
            aa.schedule_status, aa.schedule_decline_reason,
            aa.meetup_sent_at, aa.meetup_note, aa.meetup_option_id,
            p.name AS pet_name, p.photo_url AS pet_photo
     FROM adoption_applications aa
     JOIN pet_inventory p ON aa.pet_id = p.pet_id
     WHERE aa.adopter_id = ?
     ORDER BY aa.preferred_interview_date ASC"
);
$appStmt->execute([$user_id]);
$applications = $appStmt->fetchAll(PDO::FETCH_ASSOC);

// Group scheduled interviews by date for the calendar grid
$eventsByDate = [];
foreach ($applications as $app) {
    if (!empty($app['preferred_interview_date'])) {
        $eventsByDate[$app['preferred_interview_date']][] = $app;
    }
}

// Which month to display (?month=YYYY-MM), defaults to the current month
$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$firstOfMonth = new DateTime($monthParam . '-01');
$daysInMonth = (int) $firstOfMonth->format('t');
$startWeekday = (int) $firstOfMonth->format('w'); // 0 = Sunday
$prevMonth = (clone $firstOfMonth)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $firstOfMonth)->modify('+1 month')->format('Y-m');
$monthLabel = $firstOfMonth->format('F Y');
$todayStr = date('Y-m-d');

function statusBadgeClass($status) {
    $map = [
        'Pending' => 'status-pending',
        'Under Review' => 'status-under-review',
        'Approved' => 'status-approved',
        'Rejected' => 'status-rejected',
    ];
    return $map[$status] ?? 'status-pending';
}

// Upcoming interviews (today onward), regardless of which month is shown
$upcoming = array_filter($applications, function ($app) use ($todayStr) {
    return !empty($app['preferred_interview_date']) && $app['preferred_interview_date'] >= $todayStr;
});

// Pet Meet-up: mga araw at oras na inalok ng staff pagkatapos ng interview,
// para pumunta ang adopter sa City Hall at makita ang pet nang personal
$meetups = array_filter($applications, function ($app) {
    return !empty($app['meetup_sent_at']) && in_array($app['application_status'], ['Pending', 'Under Review'], true);
});
$meetupOptions = [];
if ($meetups) {
    $ids = array_map(fn($m) => (int) $m['application_id'], array_values($meetups));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $optStmt = $pdo->prepare(
        "SELECT option_id, application_id, meet_date, meet_time, meet_end_time,
                TIMESTAMP(meet_date, COALESCE(meet_end_time, meet_time)) > NOW() AS is_future
         FROM pet_meetup_options WHERE application_id IN ($in) ORDER BY meet_date, meet_time"
    );
    $optStmt->execute($ids);
    foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $meetupOptions[(int) $o['application_id']][] = $o;
    }
}

// Mga interview na minarkahan ng staff na hindi nadaluhan - kailangang i-reschedule
$missed = array_filter($applications, function ($app) {
    return !empty($app['interview_missed_at']) && in_array($app['application_status'], ['Pending', 'Under Review'], true);
});

// Interview request na hindi tinanggap ng staff - pipili ulit ang adopter ng araw at oras
$declinedRequests = array_filter($applications, function ($app) {
    return ($app['schedule_status'] ?? null) === 'Declined' && in_array($app['application_status'], ['Pending', 'Under Review'], true);
});
// Hinihintay pa ang sagot ng staff - puwede pang palitan ng adopter
function isAwaitingStaff($app) {
    return ($app['schedule_status'] ?? null) === 'Pending' && in_array($app['application_status'], ['Pending', 'Under Review'], true);
}
// Calendar ng pagpili ng araw at oras (bagong request, reschedule at rebooking)
require_once __DIR__ . '/../connection/interview_schedule.php';

// Pwedeng i-reschedule ng adopter ang paparating na interview hanggang 1 oras bago
// ito magsimula (parehong cutoff ng process_reschedule_interview.php). Ang hindi pa
// tinatanggap na request ay pinapalitan gamit ang calendar, hindi ang mga slot.
function canRescheduleUpcoming($app) {
    if (!empty($app['interview_missed_at']) || !in_array($app['application_status'], ['Pending', 'Under Review'], true)) {
        return false;
    }
    if (in_array($app['schedule_status'] ?? null, ['Pending', 'Declined'], true)) {
        return false;
    }
    $start = strtotime($app['preferred_interview_date'] . ' ' . $app['preferred_interview_time']);
    return $start - time() >= 3600;
}

// Ilang segundo pa bago mapindot ang Join Meeting (0 = oras na ng interview)
function joinOpensIn($app) {
    $start = strtotime($app['preferred_interview_date'] . ' ' . ($app['preferred_interview_time'] ?: '00:00'));
    return max(0, $start - time());
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Quick stats for the top strip
$totalScheduled = count($eventsByDate) > 0 ? array_sum(array_map('count', $eventsByDate)) : 0;
$thisMonthCount = 0;
foreach ($eventsByDate as $d => $evs) {
    if (strpos($d, $monthParam) === 0) { $thisMonthCount += count($evs); }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Schedule</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        @keyframes schedFadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .sched-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
        .sched-stat {
            background: linear-gradient(135deg, #2e5b32, #1b4d2e);
            color: #fff; border-radius: 16px; padding: 18px 20px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 6px 16px rgba(27, 77, 46, 0.25);
            animation: schedFadeUp 0.5s ease-out both;
        }
        .sched-stat.accent { background: linear-gradient(135deg, #f39c12, #d97706); box-shadow: 0 6px 16px rgba(217, 119, 6, 0.25); }
        .sched-stat.neutral { background: linear-gradient(135deg, #37474f, #22292d); box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2); }
        .sched-stat:nth-child(1) { animation-delay: 0s; }
        .sched-stat:nth-child(2) { animation-delay: 0.12s; }
        .sched-stat:nth-child(3) { animation-delay: 0.24s; }
        .sched-stat h1 { font-size: 28px; line-height: 1; margin-bottom: 4px; }
        .sched-stat p { font-size: 12.5px; opacity: 0.9; font-weight: 600; }
        .sched-stat i { font-size: 26px; opacity: 0.85; }
        button.sched-stat {
            border: none; font-family: inherit; text-align: left; width: 100%; cursor: pointer;
            transition: transform 0.2s ease, filter 0.2s ease;
        }
        button.sched-stat:hover { transform: translateY(-3px); filter: brightness(1.08); }
        button.sched-stat:focus-visible { outline: 3px solid #f7c873; outline-offset: 2px; }

        /* Kumikislap sandali ang section na pinuntahan mula sa card */
        .cal-section.stat-flash { animation: statFlash 1.4s ease; }
        @keyframes statFlash {
            0%, 100% { box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06); }
            30% { box-shadow: 0 0 0 4px rgba(243, 156, 18, 0.55), 0 4px 18px rgba(0, 0, 0, 0.06); }
        }

        /* Popup ng lahat ng interview */
        .stat-modal {
            position: fixed; inset: 0; z-index: 2500; padding: 16px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(15, 30, 20, 0.55);
        }
        .stat-modal[hidden] { display: none; }
        .stat-modal-box {
            width: 100%; max-width: 620px; max-height: calc(100vh - 32px); overflow-y: auto;
            background: #fff; border-radius: 18px; padding: 22px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: schedFadeUp 0.3s ease-out both;
        }
        /* Mas malapad: may Join Meeting, Reschedule at calendar ng pagpili ng oras */
        #upcomingInterviews .stat-modal-box { max-width: 820px; }
        .stat-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .stat-modal-head h3 { color: #1b4d2e; font-size: 17px; }
        .stat-modal-close {
            width: 34px; height: 34px; border: none; border-radius: 10px; cursor: pointer;
            background: #f1f3f1; font-size: 22px; line-height: 1; color: #444;
        }
        .stat-modal-close:hover { background: #e3e7e3; }

        .cal-section {
            background: #fff; border-radius: 18px; padding: 22px; margin-bottom: 20px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
            animation: schedFadeUp 0.5s ease-out both;
        }
        .cal-section:nth-of-type(1) { animation-delay: 0.32s; }
        .cal-section:nth-of-type(2) { animation-delay: 0.4s; }
        .cal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .cal-header h3 { font-size: 19px; color: #1b4d2e; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .cal-nav { display: flex; align-items: center; gap: 8px; }
        .cal-nav a {
            width: 34px; height: 34px; border-radius: 50%; background: #f0f4f1; color: #2e5b32;
            display: flex; align-items: center; justify-content: center; text-decoration: none;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .cal-nav a:hover { background: #dcebe0; transform: translateY(-1px); }
        .cal-nav .cal-today {
            width: auto; border-radius: 20px; padding: 0 16px; font-size: 12.5px; font-weight: 700;
            background: #f39c12; color: #fff;
        }
        .cal-nav .cal-today:hover { background: #d97706; }

        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
        .cal-dow { text-align: center; font-size: 11.5px; font-weight: 700; color: #9aa6a3; text-transform: uppercase; letter-spacing: 0.4px; padding-bottom: 8px; }
        .cal-day {
            min-height: 92px; border-radius: 12px; background: #f8f9fa; padding: 8px;
            display: flex; flex-direction: column; gap: 4px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .cal-day:not(.empty):hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(0, 0, 0, 0.08); }
        .cal-day.empty { background: transparent; }
        .cal-day.today { background: #eaf3ee; box-shadow: inset 0 0 0 2px #2e5b32; }
        .cal-daynum {
            font-size: 12px; font-weight: 700; color: #556; width: 22px; height: 22px;
            display: flex; align-items: center; justify-content: center;
        }
        .cal-day.today .cal-daynum { color: #fff; background: #2e5b32; border-radius: 50%; }
        .cal-event {
            font-size: 10.5px; padding: 3px 7px; border-radius: 8px; font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .cal-legend { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .cal-legend span {
            display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #555;
            background: #f4f6f5; padding: 5px 12px; border-radius: 20px;
        }
        .cal-legend .dot { width: 9px; height: 9px; min-width: 9px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

        .agenda-list { display: flex; flex-direction: column; gap: 12px; }
        .agenda-item {
            display: flex; align-items: center; gap: 14px; background: #f8f9fa; border-radius: 14px; padding: 12px 16px;
            transition: transform 0.15s ease, box-shadow 0.15s ease; animation: schedFadeUp 0.4s ease-out both;
        }
        .agenda-item:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(0, 0, 0, 0.07); }
        .agenda-item:nth-child(1) { animation-delay: 0.45s; }
        .agenda-item:nth-child(2) { animation-delay: 0.53s; }
        .agenda-item:nth-child(3) { animation-delay: 0.61s; }
        .agenda-item:nth-child(4) { animation-delay: 0.69s; }
        .agenda-item:nth-child(5) { animation-delay: 0.77s; }
        .agenda-item:nth-child(n+6) { animation-delay: 0.85s; }
        .agenda-date {
            min-width: 56px; text-align: center; background: linear-gradient(160deg, #2e5b32, #1b4d2e);
            color: #fff; border-radius: 12px; padding: 8px 4px;
        }
        .agenda-date .d { font-size: 19px; font-weight: 800; line-height: 1; }
        .agenda-date .m { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .agenda-photo {
            width: 44px; height: 44px; border-radius: 50%; overflow: hidden; flex-shrink: 0;
            background: #e9ece8; display: flex; align-items: center; justify-content: center;
        }
        .agenda-photo img { width: 100%; height: 100%; object-fit: cover; }
        .agenda-info { flex: 1; min-width: 0; }
        .agenda-info h4 { font-size: 14.5px; margin-bottom: 2px; color: #222; }
        .agenda-info p { font-size: 12px; color: #666; margin: 0; }
        .agenda-info .agenda-meet-note { font-size: 11.5px; color: #2e5b32; font-weight: 600; margin-top: 3px; }
        .btn-join {
            display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
            background: #2e5b32; color: #fff; text-decoration: none; font-size: 12.5px; font-weight: 700;
            padding: 8px 14px; border-radius: 20px; transition: background-color 0.2s ease;
        }
        .btn-join:hover { background: #1b4d2e; }
        button.btn-join { border: none; font-family: inherit; cursor: pointer; }
        .btn-join:disabled, .btn-join:disabled:hover { background: #b9c4bc; color: #f4f6f4; cursor: not-allowed; }

        .sched-empty {
            text-align: center; padding: 34px 20px; color: #888; border: 2px dashed #e0e0e0; border-radius: 14px;
        }
        .sched-empty i { font-size: 30px; color: #cbd5cf; margin-bottom: 10px; display: block; }

        .sched-flash { padding: 11px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13.5px; font-weight: 600; }
        .sched-flash.ok { background: #e8f5e9; color: #2e7d32; }
        .sched-flash.err { background: #ffebee; color: #c62828; }

        .missed-card {
            background: #fff; border-radius: 18px; padding: 22px; margin-bottom: 20px;
            border: 2px solid #f5c2bd; box-shadow: 0 4px 18px rgba(197, 34, 31, 0.08);
            animation: schedFadeUp 0.5s ease-out both;
        }
        .meetup-card {
            background: #fff; border-radius: 18px; padding: 22px; margin-bottom: 20px;
            border: 2px solid #b9dcc3; box-shadow: 0 4px 18px rgba(46, 91, 50, 0.08);
            animation: schedFadeUp 0.5s ease-out both;
        }
        .meetup-card .missed-head > i { background: #e7f5ea; color: #2e5b32; }
        .meetup-card .missed-head h3 { color: #1b4d2e; }
        .meetup-note { font-size: 13px; color: #374151; background: #f6faf7; border-radius: 10px; padding: 10px 12px; margin: 0 0 8px; }
        .meetup-location {
            display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;
            background: #f6faf7; border: 1px solid #dcebe0; border-radius: 12px; padding: 12px 14px;
        }
        .meetup-location strong { display: block; color: #1b4d2e; font-size: 14px; }
        .meetup-location span { display: block; font-size: 12.5px; color: #555; margin-top: 2px; }
        .meetup-location .btn-reschedule { margin-top: 0; }
        .missed-head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 16px; }
        .missed-head > i {
            width: 44px; height: 44px; min-width: 44px; border-radius: 50%; background: #fdecea; color: #c5221f;
            display: flex; align-items: center; justify-content: center; font-size: 19px;
        }
        .missed-head h3 { font-size: 17px; color: #b3261e; margin-bottom: 3px; }
        .missed-head p { font-size: 13px; color: #666; margin: 0; }
        .missed-form > label { display: block; font-size: 13px; font-weight: 700; color: #1b4d2e; margin: 12px 0 6px; }
        .missed-form textarea {
            width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px;
            font-family: inherit; font-size: 13.5px; resize: vertical;
        }
        .missed-form textarea:focus { outline: none; border-color: #2e5b32; box-shadow: 0 0 0 3px rgba(46, 91, 50, 0.12); }
        .slot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; }
        .slot-option { cursor: pointer; }
        .slot-option input { position: absolute; opacity: 0; pointer-events: none; }
        .slot-option span {
            display: block; border: 1.5px solid #dcebe0; border-radius: 10px; padding: 9px 12px;
            font-size: 12.5px; color: #444; background: #f8faf8; transition: all 0.15s ease;
        }
        .slot-option span b { display: block; color: #1b4d2e; font-size: 13px; }
        .slot-option:hover span { border-color: #2e5b32; }
        .slot-option input:checked + span { border-color: #2e5b32; background: #2e5b32; color: #fff; }
        .slot-option input:checked + span b { color: #fff; }
        .slot-option input:focus-visible + span { box-shadow: 0 0 0 3px rgba(46, 91, 50, 0.25); }
        .btn-reschedule {
            margin-top: 16px; display: inline-flex; align-items: center; gap: 8px;
            background: #f39c12; color: #fff; border: none; border-radius: 22px;
            padding: 10px 22px; font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: inherit;
            transition: background-color 0.2s ease;
        }
        .btn-reschedule:hover { background: #d97706; }
        .slot-day-label { font-size: 12.5px; font-weight: 800; color: #1b4d2e; margin: 12px 0 6px; }
        .btn-change {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff; color: #b45309; border: 1.5px solid #f3c07a; border-radius: 20px;
            padding: 7px 14px; font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit;
        }
        .btn-change:hover { background: #fff7eb; }
        .change-form { background: #fffaf2; border: 1px solid #f6dfb8; border-radius: 12px; padding: 14px 16px; margin: -4px 0 12px; }
        .change-note { font-size: 13px; color: #7a5a1a; margin: 0 0 4px; }
        .decline-reason { font-size: 13px; color: #374151; background: #fdf3f2; border: 1px solid #f5d0cc; border-radius: 10px; padding: 10px 12px; margin: 0 0 14px; }
        .request-form .ip-picker { margin: 0; max-width: 520px; }
        .agenda-info .agenda-wait-note { font-size: 11.5px; color: #b45309; font-weight: 600; margin-top: 3px; }
        .agenda-info .agenda-declined-note { font-size: 11.5px; color: #c5221f; font-weight: 600; margin-top: 3px; }

        @media (max-width: 700px) {
            .sched-stats { grid-template-columns: 1fr; }
            .cal-day { min-height: 60px; }
            .cal-event { display: none; }
            .cal-day.has-event::after { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #2e5b32; margin: 0 auto; }
        }
    </style>
</head>
<body style="background-color: #1b4d2e;">

    <div class="dashboard-container">

        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="../Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

            <nav class="nav-menu">
                <a href="adopter_dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="adopter_preferences.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i> My preference</a>
                <a href="ai_recommendation.php" class="nav-item"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Recommendation</a>
                <a href="my_pets.php" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>

            <div class="sidebar-illustration">
                <img src="../Images/bg_design_pets.png" alt="Pets Illustration" onerror="this.style.display='none'">
            </div>
        </aside>

        <main class="main-content">
            <header class="top-navbar">
                <div class="top-nav-links">
                    <a href="search_pets.php">Search Pets</a>
                    <a href="shelter.php">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>
                <a href="edit_profile.php" class="user-profile-link" style="text-decoration: none; color: inherit;">
                    <div class="user-profile">
                        <div class="profile-avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            <?php if (!empty($profile_image)): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                            <?php endif; ?>
                        </div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </a>
            </header>

            <section class="hero-title">
                <p>Your adoption interview schedule at a glance.</p>
            </section>

            <?php if ($success_message !== ''): ?>
                <div class="sched-flash ok"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message !== '' && !isset($_GET['rebook'])): /* sa popup na lalabas kapag rebooking */ ?>
                <div class="sched-flash err"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <?php foreach ($declinedRequests as $dr):
                $drId = (int) $dr['application_id'];
                $drAt = strtotime($dr['preferred_interview_date'] . ' ' . $dr['preferred_interview_time']);
                $pickerConfig = sp_interview_picker_config($pdo, $drId);
                $pickerExclude = $drId;
                $pickerDate = '';
                $pickerTime = '';
            ?>
                <section class="missed-card" id="request-<?php echo $drId; ?>">
                    <div class="missed-head">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <div>
                            <h3>Please choose a new interview date for <?php echo htmlspecialchars($dr['pet_name']); ?></h3>
                            <p>The staff could not accept your interview on <?php echo date('F j, Y \a\t g:i A', $drAt); ?>.</p>
                        </div>
                    </div>
                    <?php if (!empty($dr['schedule_decline_reason'])): ?>
                        <p class="decline-reason"><i class="fa-solid fa-comment-dots"></i> <strong>Reason from the staff:</strong> <?php echo nl2br(htmlspecialchars($dr['schedule_decline_reason'])); ?></p>
                    <?php endif; ?>
                    <form method="POST" action="process_request_interview.php" class="request-form">
                        <input type="hidden" name="application_id" value="<?php echo $drId; ?>">
                        <?php include __DIR__ . '/_interview_picker.php'; ?>
                        <button type="submit" class="btn-reschedule"><i class="fa-solid fa-paper-plane"></i> Send New Schedule</button>
                    </form>
                </section>
            <?php endforeach; ?>

            <?php foreach ($missed as $m):
                $missedAt = strtotime($m['preferred_interview_date'] . ' ' . $m['preferred_interview_time']);
            ?>
                <section class="missed-card" id="missed-<?php echo (int) $m['application_id']; ?>">
                    <div class="missed-head">
                        <i class="fa-solid fa-calendar-xmark"></i>
                        <div>
                            <h3>You did not attend the meeting for <?php echo htmlspecialchars($m['pet_name']); ?></h3>
                            <?php if (empty($m['rebook_sent_at'])): ?>
                            <p>It was scheduled on <?php echo date('F j, Y \a\t g:i A', $missedAt); ?>. Please contact the staff and tell them what happened. They will send you a rebooking link so you can choose a new schedule.</p>
                            <?php else: ?>
                            <p>It was scheduled on <?php echo date('F j, Y \a\t g:i A', $missedAt); ?>. The staff sent you a rebooking link. Tap Rebook Interview to choose a new schedule.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (empty($m['rebook_sent_at'])): ?>
                    <a href="messages.php" class="btn-reschedule" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none;"><i class="fa-solid fa-comment-dots"></i> Message the Staff</a>
                    <?php else: ?>
                    <?php // Bubukas ang rebooking popup (Adopter_File/_rebook_popup.php) ?>
                    <a href="schedule.php?rebook=<?php echo (int) $m['application_id']; ?>" class="btn-reschedule" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none;"><i class="fa-solid fa-rotate"></i> Rebook Interview</a>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php foreach ($meetups as $mu):
                $appId = (int) $mu['application_id'];
                $opts = $meetupOptions[$appId] ?? [];
                $chosen = null;
                foreach ($opts as $o) {
                    if ((int) $o['option_id'] === (int) $mu['meetup_option_id']) $chosen = $o;
                }
                $futureOpts = array_filter($opts, fn($o) => (int) $o['is_future'] === 1);
            ?>
                <section class="meetup-card" id="meetup-<?php echo $appId; ?>">
                    <div class="missed-head">
                        <i class="fa-solid fa-paw"></i>
                        <div>
                            <?php if ($chosen): ?>
                                <h3>Your meet-up with <?php echo htmlspecialchars($mu['pet_name']); ?> is set</h3>
                                <p>See you on <strong><?php echo date('l, F j, Y', strtotime($chosen['meet_date'])); ?></strong>,
                                    <?php if (!empty($chosen['meet_end_time'])): ?>
                                        anytime from <strong><?php echo date('g:i A', strtotime($chosen['meet_time'])); ?> to <?php echo date('g:i A', strtotime($chosen['meet_end_time'])); ?></strong>.
                                    <?php else: ?>
                                        at <strong><?php echo date('g:i A', strtotime($chosen['meet_time'])); ?></strong>.
                                    <?php endif; ?>
                                    Please bring a valid ID.</p>
                            <?php else: ?>
                                <h3>Meet <?php echo htmlspecialchars($mu['pet_name']); ?> at the shelter</h3>
                                <p>Thank you for attending your interview! The staff invites you to visit the shelter at Caloocan City Hall and meet <?php echo htmlspecialchars($mu['pet_name']); ?> in person. Choose the day you will come. You can visit anytime while the shelter is open on that day.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($mu['meetup_note'])): ?>
                        <p class="meetup-note"><i class="fa-solid fa-note-sticky"></i> <strong>Note from the staff:</strong> <?php echo htmlspecialchars($mu['meetup_note']); ?></p>
                    <?php endif; ?>

                    <?php if ($chosen): ?>
                        <div class="meetup-location">
                            <div>
                                <strong><i class="fa-solid fa-location-dot"></i> Caloocan City Hall</strong>
                                <span>10th Ave, Grace Park West, Caloocan, 1400 Metro Manila, Philippines</span>
                            </div>
                            <a href="https://www.google.com/maps/search/?api=1&amp;query=Caloocan+City+Hall+Philippines" target="_blank" rel="noopener" class="btn-reschedule" style="text-decoration:none;"><i class="fa-solid fa-map-location-dot"></i> Open in Google Maps</a>
                        </div>
                    <?php elseif ($futureOpts): ?>
                        <?php // Bubukas ang calendar pop-up (Adopter_File/_meetup_popup.php) ?>
                        <a href="schedule.php?meetup=<?php echo $appId; ?>" class="btn-reschedule" data-meetup-open="<?php echo $appId; ?>" style="display:inline-flex; align-items:center; gap:8px; text-decoration:none;"><i class="fa-solid fa-calendar-days"></i> Choose Your Meet-up Day</a>
                    <?php else: ?>
                        <p class="meetup-note">The offered schedules have already passed. Please <a href="messages.php">message the staff</a> for a new schedule.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <section class="sched-stats">
                <?php // Total = listahan ng lahat ng interview; Upcoming at This Month = pupunta sa section nito ?>
                <button type="button" class="sched-stat" data-stat-open="allInterviews">
                    <div><h1><?php echo $totalScheduled; ?></h1><p>Total Interviews</p></div>
                    <i class="fa-solid fa-calendar-check"></i>
                </button>
                <button type="button" class="sched-stat accent" data-stat-open="upcomingInterviews">
                    <div><h1><?php echo count($upcoming); ?></h1><p>Upcoming</p></div>
                    <i class="fa-solid fa-clock"></i>
                </button>
                <button type="button" class="sched-stat neutral" data-stat-goto="calendarSection">
                    <div><h1><?php echo $thisMonthCount; ?></h1><p>This Month</p></div>
                    <i class="fa-solid fa-paw"></i>
                </button>
            </section>

            <?php // Lahat ng interview (bumubukas sa "Total Interviews") ?>
            <div class="stat-modal" id="allInterviews" role="dialog" aria-modal="true" aria-labelledby="allInterviewsTitle" hidden>
                <div class="stat-modal-box">
                    <div class="stat-modal-head">
                        <h3 id="allInterviewsTitle"><i class="fa-solid fa-calendar-check"></i> All Interviews</h3>
                        <button type="button" class="stat-modal-close" aria-label="Close">&times;</button>
                    </div>
                    <?php
                    $allInterviews = array_filter($applications, fn($a) => !empty($a['preferred_interview_date']));
                    ?>
                    <?php if ($allInterviews): ?>
                        <div class="agenda-list">
                            <?php foreach ($allInterviews as $ai):
                                $aiDt = new DateTime($ai['preferred_interview_date']); ?>
                                <div class="agenda-item">
                                    <div class="agenda-date">
                                        <div class="d"><?php echo $aiDt->format('d'); ?></div>
                                        <div class="m"><?php echo $aiDt->format('M'); ?></div>
                                    </div>
                                    <div class="agenda-photo">
                                        <?php if (!empty($ai['pet_photo'])): ?>
                                            <img src="../uploads/<?php echo htmlspecialchars($ai['pet_photo']); ?>" alt="<?php echo htmlspecialchars($ai['pet_name']); ?>">
                                        <?php else: ?>
                                            <i class="fa-solid fa-paw" style="color:#9aa6a3;"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="agenda-info">
                                        <h4><?php echo htmlspecialchars($ai['pet_name']); ?></h4>
                                        <p>
                                            <?php echo $aiDt->format('F j, Y'); ?>
                                            <?php if (!empty($ai['preferred_interview_time'])): ?>
                                                &middot; <?php echo date('g:i A', strtotime($ai['preferred_interview_time'])); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="status-badge <?php echo statusBadgeClass($ai['application_status']); ?>">
                                        <?php echo htmlspecialchars($ai['application_status']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sched-empty">
                            <i class="fa-regular fa-calendar"></i>
                            You have no interviews yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <section class="cal-section" id="calendarSection">
                <div class="cal-header">
                    <h3><i class="fa-solid fa-calendar-days"></i> <?php echo htmlspecialchars($monthLabel); ?></h3>
                    <div class="cal-nav">
                        <a href="?month=<?php echo urlencode($prevMonth); ?>" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></a>
                        <a href="?month=<?php echo urlencode(date('Y-m')); ?>" class="cal-today">Today</a>
                        <a href="?month=<?php echo urlencode($nextMonth); ?>" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></a>
                    </div>
                </div>

                <div class="cal-grid">
                    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                        <div class="cal-dow"><?php echo $dow; ?></div>
                    <?php endforeach; ?>

                    <?php for ($i = 0; $i < $startWeekday; $i++): ?>
                        <div class="cal-day empty"></div>
                    <?php endfor; ?>

                    <?php for ($day = 1; $day <= $daysInMonth; $day++):
                        $dateStr = $monthParam . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                        $isToday = ($dateStr === $todayStr);
                        $dayEvents = $eventsByDate[$dateStr] ?? [];
                    ?>
                        <div class="cal-day<?php echo $isToday ? ' today' : ''; ?><?php echo count($dayEvents) > 0 ? ' has-event' : ''; ?>">
                            <span class="cal-daynum"><?php echo $day; ?></span>
                            <?php foreach ($dayEvents as $ev): ?>
                                <span class="cal-event status-badge <?php echo statusBadgeClass($ev['application_status']); ?>" title="<?php echo htmlspecialchars($ev['pet_name'] . ' - ' . $ev['application_status']); ?>">
                                    <?php echo htmlspecialchars($ev['pet_name']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="cal-legend">
                    <span><span class="dot" style="background:#b25900;"></span> Pending</span>
                    <span><span class="dot" style="background:#1a73e8;"></span> Under Review</span>
                    <span><span class="dot" style="background:#1e7e34;"></span> Approved</span>
                    <span><span class="dot" style="background:#c5221f;"></span> Rejected</span>
                </div>
            </section>

            <?php // Paparating na interview: popup na bumubukas sa "Upcoming" card ?>
            <div class="stat-modal" id="upcomingInterviews" role="dialog" aria-modal="true" aria-labelledby="upcomingInterviewsTitle" hidden>
                <div class="stat-modal-box">
                    <div class="stat-modal-head">
                        <h3 id="upcomingInterviewsTitle"><i class="fa-solid fa-list-check"></i> Upcoming Interviews</h3>
                        <button type="button" class="stat-modal-close" aria-label="Close">&times;</button>
                    </div>

                <?php if (count($upcoming) > 0): ?>
                    <div class="agenda-list">
                        <?php foreach ($upcoming as $ev):
                            $dt = new DateTime($ev['preferred_interview_date']);
                        ?>
                            <div class="agenda-item">
                                <div class="agenda-date">
                                    <div class="d"><?php echo $dt->format('d'); ?></div>
                                    <div class="m"><?php echo $dt->format('M'); ?></div>
                                </div>
                                <div class="agenda-photo">
                                    <?php if (!empty($ev['pet_photo'])): ?>
                                        <img src="../uploads/<?php echo htmlspecialchars($ev['pet_photo']); ?>" alt="<?php echo htmlspecialchars($ev['pet_name']); ?>">
                                    <?php else: ?>
                                        <i class="fa-solid fa-paw" style="color:#9aa6a3;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="agenda-info">
                                    <h4><?php echo htmlspecialchars($ev['pet_name']); ?></h4>
                                    <p>
                                        <?php echo $dt->format('F j, Y'); ?>
                                        <?php if (!empty($ev['preferred_interview_time'])): ?>
                                            &middot; <?php echo date('g:i A', strtotime($ev['preferred_interview_time'])); ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (isAwaitingStaff($ev)): ?>
                                        <p class="agenda-wait-note"><i class="fa-solid fa-hourglass-half"></i> Waiting for the staff to accept this schedule.</p>
                                    <?php elseif (($ev['schedule_status'] ?? null) === 'Declined' && in_array($ev['application_status'], ['Pending', 'Under Review'])): ?>
                                        <p class="agenda-declined-note"><i class="fa-solid fa-calendar-xmark"></i> Not accepted. Please choose a new date above.</p>
                                    <?php elseif (!empty($ev['meeting_link']) && in_array($ev['application_status'], ['Pending', 'Under Review'])): ?>
                                        <p class="agenda-meet-note">
                                            <?php echo !empty($ev['meeting_link_sent_at'])
                                                ? 'The meeting link was sent to your email.'
                                                : 'Online interview. The meeting link will be emailed to you 10 minutes before it starts.'; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <?php if (isAwaitingStaff($ev)): ?>
                                    <button type="button" class="btn-change" data-target="request-change-<?php echo (int) $ev['application_id']; ?>">
                                        <i class="fa-regular fa-calendar"></i> Change Date
                                    </button>
                                <?php elseif (!empty($ev['meeting_link']) && in_array($ev['application_status'], ['Pending', 'Under Review']) && ($ev['schedule_status'] ?? null) !== 'Declined'):
                                    $joinWait = joinOpensIn($ev); ?>
                                    <?php if ($joinWait > 0): ?>
                                        <?php // Hindi pa oras: walang link sa page, kusang magre-reload pagdating ng oras ?>
                                        <button type="button" class="btn-join" disabled data-join-in="<?php echo (int) $joinWait; ?>"
                                                title="You can join at <?php echo date('g:i A', strtotime($ev['preferred_interview_time'] ?: '00:00')); ?> on <?php echo $dt->format('F j, Y'); ?>">
                                            <i class="fa-solid fa-lock"></i> Join Meeting
                                        </button>
                                    <?php else: ?>
                                        <a class="btn-join" href="<?php echo htmlspecialchars($ev['meeting_link']); ?>" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-video"></i> Join Meeting
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (canRescheduleUpcoming($ev)): ?>
                                    <button type="button" class="btn-change" data-target="change-<?php echo (int) $ev['application_id']; ?>">
                                        <i class="fa-solid fa-rotate"></i> Reschedule
                                    </button>
                                <?php endif; ?>
                                <span class="status-badge <?php echo statusBadgeClass($ev['application_status']); ?>">
                                    <?php echo htmlspecialchars($ev['application_status']); ?>
                                </span>
                            </div>
                            <?php if (isAwaitingStaff($ev)):
                                $pickerConfig = sp_interview_picker_config($pdo, (int) $ev['application_id']);
                                $pickerExclude = (int) $ev['application_id'];
                                $pickerDate = '';
                                $pickerTime = '';
                            ?>
                                <form method="POST" action="process_request_interview.php" class="missed-form change-form request-form" id="request-change-<?php echo (int) $ev['application_id']; ?>" hidden>
                                    <input type="hidden" name="application_id" value="<?php echo (int) $ev['application_id']; ?>">
                                    <p class="change-note">Want a different day or time? Choose a new one below. The staff has not answered your current request yet.</p>
                                    <?php include __DIR__ . '/_interview_picker.php'; ?>
                                    <button type="submit" class="btn-reschedule"><i class="fa-solid fa-paper-plane"></i> Send New Schedule</button>
                                </form>
                            <?php endif; ?>
                            <?php if (canRescheduleUpcoming($ev)): ?>
                                <form method="POST" action="process_reschedule_interview.php" class="missed-form change-form request-form" id="change-<?php echo (int) $ev['application_id']; ?>" hidden>
                                    <input type="hidden" name="application_id" value="<?php echo (int) $ev['application_id']; ?>">
                                    <p class="change-note">Busy at that time? Pick a new day and time below. The staff will need to approve it again. You can reschedule until 1 hour before your interview.</p>

                                    <label for="change-reason-<?php echo (int) $ev['application_id']; ?>">Why do you need to reschedule?</label>
                                    <textarea id="change-reason-<?php echo (int) $ev['application_id']; ?>" name="missed_reason" rows="2" maxlength="1000" required placeholder="e.g., I have a work meeting at that time."></textarea>

                                    <label>Choose a new schedule</label>
                                    <?php
                                    $pickerConfig = sp_interview_picker_config($pdo, (int) $ev['application_id']);
                                    $pickerExclude = (int) $ev['application_id'];
                                    $pickerDate = '';
                                    $pickerTime = '';
                                    include __DIR__ . '/_interview_picker.php';
                                    ?>
                                    <button type="submit" class="btn-reschedule"><i class="fa-solid fa-rotate"></i> Send New Schedule</button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sched-empty">
                        <i class="fa-solid fa-paw"></i>
                        <p>No upcoming interviews scheduled.<br>Once you apply to adopt a pet, your interview date will show up here.</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>

        </main>
    </div>

    <script src="interview_picker.js?v=<?php echo @filemtime(__DIR__ . '/interview_picker.js'); ?>"></script>
    <script>
        // Buksan/isara ang reschedule form sa ilalim ng interview
        document.querySelectorAll('.btn-change').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const form = document.getElementById(btn.dataset.target);
                if (!form) return;
                form.hidden = !form.hidden;
                const ta = form.querySelector('textarea');
                if (!form.hidden && ta) { ta.focus(); }
            });
        });

        // Hinihintay ang buong page (kasama ang rebooking popup sa ibaba)
        document.addEventListener('DOMContentLoaded', function () {
            // Bagong araw at oras: kailangang kumpleto sa calendar bago ipadala
            document.querySelectorAll('.request-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    if (!InterviewPicker.validate(form.querySelector('.ip-picker'))) e.preventDefault();
                });
            });

            // Galing sa abiso o mensahe (#request-ID): buksan agad ang calendar
            const target = /^#request-\d+$/.test(location.hash) && document.querySelector(location.hash + ' .ip-toggle');
            if (target) target.click();
        });

        // Inaalis sa pagpipilian ang oras na na-book na ng ibang adopter habang
        // bukas ang page, para hindi na ito mapili sa pag-reschedule.
        (function () {
            if (!document.querySelector('.slot-option input[name="slot_id"]')) return;

            function removeTakenSlots(openIds) {
                const open = new Set(openIds.map(String));
                document.querySelectorAll('.slot-option input[name="slot_id"]').forEach(function (input) {
                    if (open.has(input.value)) return;
                    if (input.checked) {
                        alert('The time you picked was just booked by another applicant. Please choose another time.');
                    }
                    input.closest('.slot-option').remove();
                });
                document.querySelectorAll('.slot-grid').forEach(function (grid) {
                    if (grid.querySelector('.slot-option')) return;
                    const label = grid.previousElementSibling;
                    if (label && label.classList.contains('slot-day-label')) label.remove();
                    grid.remove();
                });
            }

            function checkSlots() {
                fetch('open_slots.php', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) { if (data && Array.isArray(data.open)) removeTakenSlots(data.open); })
                    .catch(function () { /* susubukan ulit sa susunod */ });
            }

            setInterval(checkSlots, 10000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) checkSlots();
            });
        })();

        // Mga card sa itaas: Total = popup ng lahat ng interview,
        // Upcoming / This Month = pupunta at kikislap ang section nito
        (function () {
            document.querySelectorAll('[data-stat-goto]').forEach(function (card) {
                card.addEventListener('click', function () {
                    const section = document.getElementById(card.dataset.statGoto);
                    if (!section) return;
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    section.classList.remove('stat-flash');
                    void section.offsetWidth;
                    section.classList.add('stat-flash');
                });
            });

            document.querySelectorAll('[data-stat-open]').forEach(function (card) {
                const modal = document.getElementById(card.dataset.statOpen);
                if (!modal) return;
                document.body.appendChild(modal); // nasa ibabaw ng lahat
                function close() {
                    modal.hidden = true;
                    document.body.style.overflow = '';
                    card.focus();
                }
                card.addEventListener('click', function () {
                    modal.hidden = false;
                    document.body.style.overflow = 'hidden';
                    modal.querySelector('.stat-modal-close').focus();
                });
                modal.querySelector('.stat-modal-close').addEventListener('click', close);
                modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && !modal.hidden) close();
                });
            });
        })();

        // Naka-lock ang Join Meeting hanggang oras ng interview. Pagdating ng oras,
        // ire-reload ang page para lumabas ang totoong link (galing sa server).
        (function () {
            const locked = Array.from(document.querySelectorAll('.btn-join[data-join-in]'));
            if (!locked.length) return;
            const loadedAt = Date.now();
            const soonest = Math.min.apply(null, locked.map(function (b) { return parseInt(b.dataset.joinIn, 10) || 0; }));
            function check() {
                if (Date.now() - loadedAt >= soonest * 1000) location.reload();
            }
            // Bawat 15 segundo (gumagana kahit natulog ang computer o nasa ibang tab)
            setInterval(check, 15000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) check();
            });
        })();
    </script>
    <?php // Rebooking popup: bumubukas kapag may ?rebook=<id> (View sa abiso o Rebook Interview)
    include __DIR__ . '/_rebook_popup.php'; ?>
    <?php // Pet meet-up popup: bumubukas kapag may ?meetup=<id> (button sa message ng staff) o "Choose Your Meet-up Day"
    include __DIR__ . '/_meetup_popup.php'; ?>

    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
