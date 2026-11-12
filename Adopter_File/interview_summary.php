<?php
session_start();
require '../connection/database.php';
require_once '../connection/interview_summary.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$sp_unread_count = sp_unread_message_count($pdo, $user_id);
$userStmt = $pdo->prepare('SELECT username, profile_image FROM users WHERE user_id = ?');
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';

// Sariling application lang ng adopter ang makikita
$application_id = (int) ($_GET['id'] ?? 0);
$appStmt = $pdo->prepare(
    "SELECT aa.application_id, aa.preferred_interview_date, aa.preferred_interview_time,
            p.name AS pet_name, p.kind AS pet_kind, p.breed AS pet_breed
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.application_id = ? AND aa.adopter_id = ?"
);
$appStmt->execute([$application_id, $user_id]);
$app = $appStmt->fetch(PDO::FETCH_ASSOC);
$summary = $app ? sp_interview_summary($pdo, $application_id) : null;

if (!$app || !$summary) {
    $_SESSION['error_message'] = 'The interview summary is not available yet.';
    header('Location: my_applications.php');
    exit();
}

$interviewAt = strtotime($app['preferred_interview_date'] . ' ' . $app['preferred_interview_time']);

// Pop-up: ang page na pinanggalingan (Notifications, Messages, My Applications...)
// ang nasa likod, malabo; ang × ay babalik doon. Path lang ang kinukuha (walang
// ?read= atbp.) para hindi maulit ang aksyon ng link.
$fromPath = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
$backUrl = (preg_match('#^/Caps2/Adopter_File/[a-z_]+\.php$#', $fromPath) && basename($fromPath) !== 'interview_summary.php')
    ? $fromPath
    : '/Caps2/Adopter_File/my_applications.php';

$flashOk = $_SESSION['success_message'] ?? '';
$flashBad = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Mga bahagi ng report (pareho sa PDF); nilalaktawan ang walang laman
$sections = sp_summary_sections($summary);

$recClass = [
    'Recommended' => 'rec-good',
    'Recommended with conditions' => 'rec-mid',
    'Needs follow-up' => 'rec-mid',
    'Not recommended' => 'rec-bad',
][$summary['recommendation']] ?? 'rec-mid';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Interview Summary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        .is-wrap { max-width: 860px; margin: 0 auto 30px; }
        /* Compact na card pero madaling basahin */
        .is-wrap { max-width: 740px; }
        .is-back { display: inline-flex; align-items: center; gap: 6px; color: #fff; font-weight: 700; text-decoration: none; margin-bottom: 12px; }
        .is-card { background: #fff; border-radius: 18px; padding: 26px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12); color: #1f2937; }
        .is-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; padding-bottom: 16px; border-bottom: 2px solid #eef2ee; }
        .is-head h1 { margin: 0 0 4px; font-size: 22px; color: #1b4d2e; }
        .is-head p { margin: 2px 0; font-size: 13.5px; color: #6b7280; }
        .is-head p strong { color: #1f2937; }
        .is-print { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border: none; border-radius: 10px; background: #1b4d2e; color: #fff; font-weight: 700; font-family: inherit; cursor: pointer; }
        .is-print:hover { filter: brightness(1.15); }
        .is-rec { margin: 18px 0 4px; padding: 14px 16px; border-radius: 12px; display: flex; align-items: center; gap: 12px; font-size: 14px; }
        .is-rec i { font-size: 20px; }
        .is-rec span { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; opacity: .8; }
        .is-rec strong { font-size: 16px; }
        .rec-good { background: #e6f4ea; color: #1e7e34; border-left: 4px solid #22a05a; }
        .rec-mid { background: #fff8e6; color: #8a5a00; border-left: 4px solid #f5b83d; }
        .rec-bad { background: #fdecea; color: #b3261e; border-left: 4px solid #e05545; }
        .is-section h2 { margin: 22px 0 10px; font-size: 14px; text-transform: uppercase; letter-spacing: .5px; color: #1b4d2e; }
        .is-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px 18px; }
        .is-field label { display: block; font-size: 11.5px; font-weight: 700; color: #8a94a6; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 3px; }
        .is-field p { margin: 0; font-size: 14.5px; line-height: 1.5; overflow-wrap: anywhere; }
        /* Paliwanag ng staff bilang bullet list */
        .sum-points { margin: 2px 0 0; padding-left: 20px; display: grid; gap: 6px; }
        .sum-points li { font-size: 14.5px; line-height: 1.55; overflow-wrap: anywhere; padding-left: 2px; }
        .sum-points li::marker { color: #2e5b32; }
        .is-field.wide { grid-column: 1 / -1; }
        <?php echo sp_summary_signatures_css(); ?>
        .is-flash { margin-bottom: 12px; padding: 12px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; }
        .is-flash.ok { background: #e6f4ea; color: #1e7e34; }
        .is-flash.bad { background: #fdecea; color: #b3261e; }
        .is-foot { margin-top: 24px; padding-top: 14px; border-top: 2px solid #eef2ee; font-size: 12.5px; color: #6b7280; }
        /* Mas siksik: mas maliit na padding at puwang, pero 13.5-14px pa rin ang text */
        .is-card { padding: 20px 22px; border-radius: 16px; }
        .is-head { padding-bottom: 12px; gap: 12px; }
        .is-head h1 { font-size: 19px; }
        .is-head p { font-size: 12.5px; margin: 1px 0; }
        .is-print { height: 36px; padding: 0 14px; font-size: 13px; }
        .is-rec { margin: 14px 0 2px; padding: 10px 14px; gap: 10px; }
        .is-rec i { font-size: 17px; }
        .is-rec span { font-size: 11px; }
        .is-rec strong { font-size: 15px; }
        .is-section h2 { margin: 16px 0 8px; font-size: 13px; padding-bottom: 5px; border-bottom: 1px solid #eef2ee; }
        .is-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px 16px; }
        .is-field label { font-size: 10.5px; margin-bottom: 2px; }
        .is-field p { font-size: 13.5px; line-height: 1.45; }
        .sum-points { gap: 3px; padding-left: 18px; }
        .sum-points li { font-size: 13.5px; line-height: 1.5; }
        .sum-sigs { margin-top: 18px; }
        .sum-sig-area { height: 62px; }
        .sum-sig-img { max-height: 60px; }
        .sum-sig-name { font-size: 13px; }
        .is-foot { margin-top: 16px; padding-top: 10px; font-size: 11.5px; }
        @media (max-width: 600px) { .is-card { padding: 16px; } .is-head h1 { font-size: 18px; } }
        @media print {
            body { background: #fff !important; }
            .sidebar, .top-navbar, .is-back, .is-print, .sp-chat-bubble, #spChatBubble { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
            .is-card { box-shadow: none; padding: 0; }
            .is-field, .is-rec, .sum-sigs { break-inside: avoid; }
        }
    </style>
</head>
<body style="background-color: #1b4d2e;">
<?php require_once __DIR__ . '/../connection/print_backdrop.php'; sp_print_backdrop($backUrl); ?>
<style>
    /* Pop-up: report lang ang nakikita, nasa gitna ng malabong page na pinanggalingan */
    html.sp-pb .sidebar, html.sp-pb .top-navbar, html.sp-pb .is-back,
    html.sp-pb #spMobileBar, html.sp-pb #spChatToggle { display: none !important; }
    html.sp-pb .dashboard-container, html.sp-pb .main-content {
        display: block !important; background: transparent !important; margin: 0 !important;
        width: 100% !important; max-width: none !important; min-height: 0 !important; height: auto !important;
        border-radius: 0 !important; box-shadow: none !important; overflow: visible !important;
    }
    html.sp-pb .main-content { padding: 40px 16px 30px !important; }
    html.sp-pb .is-wrap { margin: 0 auto; animation: isPop .3s cubic-bezier(.22, 1, .36, 1); }
    html.sp-pb .is-card { box-shadow: 0 24px 60px rgba(0, 0, 0, .35); }
    @keyframes isPop { from { opacity: 0; transform: translateY(14px) scale(.98); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { html.sp-pb .is-wrap { animation: none; } }
    @media (max-width: 600px) { html.sp-pb .main-content { padding: 64px 10px 20px !important; } }
</style>

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

            <div class="is-wrap">
                <a href="my_applications.php" class="is-back"><i class="fa-solid fa-arrow-left"></i> Back to My Applications</a>
                <?php if ($flashOk !== ''): ?><div class="is-flash ok"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($flashOk); ?></div><?php endif; ?>
                <?php if ($flashBad !== ''): ?><div class="is-flash bad"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($flashBad); ?></div><?php endif; ?>

                <article class="is-card">
                    <div class="is-head">
                        <div>
                            <h1><i class="fa-solid fa-file-signature"></i> Interview Summary Report</h1>
                            <p>Application <strong>APP-<?php echo str_pad((string) $app['application_id'], 3, '0', STR_PAD_LEFT); ?></strong>
                                for <strong><?php echo htmlspecialchars($app['pet_name']); ?></strong>
                                <?php if (!empty($app['pet_breed'])): ?>(<?php echo htmlspecialchars($app['pet_kind'] . ' - ' . $app['pet_breed']); ?>)<?php endif; ?></p>
                            <p>Interview: <strong><?php echo htmlspecialchars(date('F j, Y \a\t g:i A', $interviewAt)); ?></strong></p>
                            <p>Interviewed by: <strong><?php echo htmlspecialchars($summary['staff_name'] ?? 'SMART PET Staff'); ?></strong></p>
                        </div>
                        <button type="button" class="is-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
                    </div>

                    <div class="is-rec <?php echo $recClass; ?>">
                        <i class="fa-solid fa-clipboard-check"></i>
                        <div><span>Staff recommendation</span><strong><?php echo htmlspecialchars($summary['recommendation']); ?></strong></div>
                    </div>

                    <?php foreach ($sections as $title => $rows):
                        $rows = array_filter($rows, fn ($v) => $v !== null && $v !== '');
                        if (!$rows) continue; ?>
                        <section class="is-section">
                            <h2><?php echo htmlspecialchars($title); ?></h2>
                            <div class="is-grid">
                                <?php foreach ($rows as $label => $value): ?>
                                    <?php $isPoints = in_array($label, SP_SUMMARY_BULLET_FIELDS, true); ?>
                                    <div class="is-field<?php echo ($isPoints || strlen((string) $value) > 40) ? ' wide' : ''; ?>">
                                        <label><?php echo htmlspecialchars($label); ?></label>
                                        <?php echo sp_summary_value_html($label, $value); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>

                    <?php // Printed name at pirma ng staff na nag-interview (staff lang ang pumipirma) ?>
                    <section class="is-section" id="sign">
                        <?php echo sp_summary_signatures_html($summary); ?>
                    </section>

                    <p class="is-foot">
                        Last updated <?php echo htmlspecialchars(date('F j, Y g:i A', strtotime($summary['updated_at']))); ?>.
                        If something in this report is not correct, please tell the staff in <a href="messages.php">Messages</a>.
                    </p>
                </article>
            </div>
        </main>
    </div>

    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
