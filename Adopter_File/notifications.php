<?php
/**
 * Notifications page ng adopter.
 * Dito pumapasok ang lahat ng abiso para sa kanya: na-approve o hindi ang application,
 * may meeting link na para sa interview, natanggap na ang bayad, atbp.
 */
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// Mark as read - isa (?read=<id>) o lahat (?read=all). Pagkatapos, kung may link ang
// abiso, dadalhin ang adopter doon; kung wala, babalik lang sa listahan.
if (isset($_GET['read'])) {
    if ($_GET['read'] === 'all') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0' . sp_adopter_notif_filter())->execute([$user_id]);
        header('Location: notifications.php');
        exit();
    }

    $nid = (int) $_GET['read'];
    $linkStmt = $pdo->prepare('SELECT link FROM notifications WHERE notification_id = ? AND user_id = ?');
    $linkStmt->execute([$nid, $user_id]);
    $target = $linkStmt->fetchColumn();
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')->execute([$nid, $user_id]);

    // Rebooking (bago o lumang link): bumubukas ang rebooking popup sa Schedule
    $target = (string) $target;
    if (preg_match('/[?&]rebook=(\d+)/', $target, $rb)) {
        header('Location: schedule.php?rebook=' . (int) $rb[1]);
        exit();
    }

    // Panlabas na link (hal. meeting link) o pang-loob na page lang ang pinapayagan
    $isSafe = $target !== '' && (preg_match('#^https?://#i', $target) || preg_match('#^[a-z0-9_]+\.php(\?.*)?$#i', $target));
    header('Location: ' . ($isSafe ? $target : 'notifications.php'));
    exit();
}

// Lumang link na notifications.php?rebook=<id>: nasa Schedule na ang rebooking popup
if (isset($_GET['rebook'])) {
    header('Location: schedule.php?rebook=' . (int) $_GET['rebook']);
    exit();
}

// Tanggalin ang mga napiling abiso (Select -> lagyan ng check -> Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Sariling abiso lang ng adopter ang mabubura
        $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND notification_id IN ($in)" . sp_adopter_notif_filter())
            ->execute(array_merge([$user_id], $ids));
    }
    header('Location: notifications.php');
    exit();
}

// Tanggalin ang isang abiso
if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM notifications WHERE notification_id = ? AND user_id = ?')->execute([(int) $_GET['delete'], $user_id]);
    header('Location: notifications.php');
    exit();
}

$sp_unread_count = sp_unread_message_count($pdo, $user_id);
$sp_notif_count = sp_unread_notification_count($pdo, $user_id);

$userStmt = $pdo->prepare('SELECT username, profile_image FROM users WHERE user_id = ?');
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';

$listStmt = $pdo->prepare(
    'SELECT notification_id, type, title, message, link, is_read, created_at
     FROM notifications
     WHERE user_id = ?' . sp_adopter_notif_filter() . '
     ORDER BY is_read ASC, created_at DESC
     LIMIT 100'
);
$listStmt->execute([$user_id]);
$notifications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Icon at kulay bawat uri ng abiso
$typeMeta = [
    'application' => ['icon' => 'fa-file-lines',        'class' => 'type-application'],
    'interview'   => ['icon' => 'fa-video',             'class' => 'type-interview'],
    'payment'     => ['icon' => 'fa-money-bill-wave',   'class' => 'type-payment'],
    'certificate' => ['icon' => 'fa-certificate',       'class' => 'type-certificate'],
    'general'     => ['icon' => 'fa-bell',              'class' => 'type-general'],
];

function sp_time_ago(string $datetime): string
{
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) { return 'Just now'; }
    if ($diff < 3600) { $m = (int) floor($diff / 60); return $m . ' min' . ($m > 1 ? 's' : '') . ' ago'; }
    if ($diff < 86400) { $h = (int) floor($diff / 3600); return $h . ' hour' . ($h > 1 ? 's' : '') . ' ago'; }
    if ($diff < 604800) { $d = (int) floor($diff / 86400); return $d . ' day' . ($d > 1 ? 's' : '') . ' ago'; }
    return date('M j, Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Notifications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        .notif-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .notif-head h2 { margin: 0; }
        .notif-head .count {
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }
        .btn-mark-all {
            background: #ffffff;
            color: #1b4d2e;
            border: 1.5px solid #cfe0d4;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .btn-mark-all:hover { background: #f0f7f3; border-color: #2e5b32; }

        .notif-list { display: flex; flex-direction: column; gap: 12px; }

        .notif-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            position: relative;
            border-left: 5px solid transparent;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .notif-card:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(0,0,0,0.12); }
        .notif-card.unread { border-left-color: #f39c12; background: #fffdf7; }

        .notif-icon {
            width: 46px; height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .type-application .notif-icon { background: #e0edff; color: #1a56db; }
        .type-interview   .notif-icon { background: #ede9fe; color: #6d28d9; }
        .type-payment     .notif-icon { background: #d1fae5; color: #065f46; }
        .type-certificate .notif-icon { background: #fef3c7; color: #b45309; }
        .type-general     .notif-icon { background: #f3f4f6; color: #374151; }

        .notif-body { flex: 1; min-width: 0; }
        .notif-title {
            font-size: 15.5px;
            font-weight: 800;
            color: #1b4d2e;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .notif-new {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            background: #f39c12;
            color: #fff;
            padding: 2px 8px;
            border-radius: 999px;
        }
        .notif-msg { font-size: 14px; color: #374151; margin-top: 4px; line-height: 1.5; word-break: break-word; }
        .notif-meta {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 12.5px;
            color: #9ca3af;
        }
        .notif-meta a {
            color: #2e5b32;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .notif-meta a:hover { text-decoration: underline; }
        .notif-meta a.danger { color: #b91c1c; }

        /* ===== Pagpili at pagbura ng maraming abiso ===== */
        .notif-head-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        button.btn-mark-all { cursor: pointer; font-family: inherit; }

        .notif-bulkbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            padding: 10px 14px;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
            animation: bulkIn .25s ease;
        }
        .notif-bulkbar[hidden] { display: none; }
        @keyframes bulkIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        .notif-selectall {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13.5px;
            font-weight: 700;
            color: #1b4d2e;
            cursor: pointer;
        }
        .notif-selcount { font-size: 13px; font-weight: 600; color: #6b7280; }
        .notif-bulk-actions { display: flex; gap: 8px; margin-left: auto; }
        .btn-bulk-cancel, .btn-bulk-delete {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 999px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-bulk-cancel { border: 1.5px solid #d1d5db; background: #fff; color: #374151; }
        .btn-bulk-cancel:hover { background: #f3f4f6; }
        .btn-bulk-delete { border: none; background: #dc2626; color: #fff; }
        .btn-bulk-delete:hover:not(:disabled) { background: #b91c1c; }
        .btn-bulk-delete:disabled { background: #f3c4c4; cursor: not-allowed; }

        /* Bilog na checkbox */
        .notif-pick { display: none; align-self: center; flex-shrink: 0; cursor: pointer; }
        .notif-list.selecting .notif-pick { display: inline-flex; }
        .notif-pick input, .notif-selectall input { position: absolute; opacity: 0; pointer-events: none; }
        .notif-check {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 2px solid #c5ccd6;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: transparent;
            font-size: 11px;
            transition: background .15s, border-color .15s, color .15s, transform .15s;
        }
        input:checked + .notif-check { background: #dc2626; border-color: #dc2626; color: #fff; transform: scale(1.08); }
        input:focus-visible + .notif-check { outline: 2px solid #1b4d2e; outline-offset: 2px; }
        .notif-list.selecting .notif-card { cursor: pointer; }
        .notif-list.selecting .notif-card:hover { transform: none; }
        .notif-card.is-picked { box-shadow: 0 0 0 2px #fca5a5, 0 4px 10px rgba(0,0,0,0.08); background: #fff7f7; }

        /* Paalala at right-click menu */
        .notif-tip { margin: -6px 0 12px; font-size: 12.5px; color: #9ca3af; }
        .notif-tip i { margin-right: 5px; }
        .notif-ctx {
            position: fixed;
            z-index: 4000;
            min-width: 210px;
            padding: 6px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2), 0 0 0 1px rgba(0, 0, 0, 0.05);
            animation: ctxIn .14s ease;
        }
        .notif-ctx[hidden] { display: none; }
        @keyframes ctxIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: none; } }
        .notif-ctx button {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 9px 12px;
            border: none;
            border-radius: 8px;
            background: none;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            color: #1f2937;
            text-align: left;
            cursor: pointer;
        }
        .notif-ctx button i { width: 16px; text-align: center; color: #2e5b32; }
        .notif-ctx button:hover, .notif-ctx button:focus-visible { background: #f0f7f3; outline: none; }
        .notif-ctx button.danger { color: #b91c1c; }
        .notif-ctx button.danger i { color: #b91c1c; }
        .notif-ctx button.danger:hover { background: #fef2f2; }
        .notif-ctx hr { margin: 4px 6px; border: none; border-top: 1px solid #eef0f3; }

        /* Paalala: "Right-click" sa computer, "Press and hold" sa phone/tablet */
        .notif-tip .tip-touch { display: none; }
        @media (hover: none) and (pointer: coarse) {
            .notif-tip .tip-mouse { display: none; }
            .notif-tip .tip-touch { display: inline; }
        }
        /* Long-press: walang text selection o callout ng browser habang pinipindot */
        .notif-card { -webkit-touch-callout: none; }
        .notif-card.pressing { transform: scale(0.98); transition: transform .3s ease; }

        /* Mahabang mensahe: tatlong linya muna, may "Show more" */
        .notif-msg.clamped {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .notif-more {
            margin-top: 2px;
            padding: 0;
            border: none;
            background: none;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 700;
            color: #2e5b32;
            cursor: pointer;
        }
        .notif-more[hidden] { display: none; }

        /* ===== Telepono ===== */
        @media (max-width: 600px) {
            .notif-head { margin-bottom: 10px; }
            .notif-head h2 { font-size: 22px; }
            .btn-mark-all { padding: 7px 12px; font-size: 12.5px; }
            .notif-tip { margin: -2px 0 10px; font-size: 12px; }
            .notif-list { gap: 10px; }
            .notif-card {
                padding: 12px 12px 12px 10px;
                gap: 10px;
                border-radius: 14px;
                border-left-width: 4px;
            }
            .notif-card:hover { transform: none; }
            .notif-icon { width: 34px; height: 34px; font-size: 14px; }
            .notif-title { font-size: 14.5px; gap: 6px; line-height: 1.3; }
            .notif-msg { font-size: 13.5px; line-height: 1.45; }
            .notif-meta { gap: 6px 14px; font-size: 12px; margin-top: 8px; }
            .notif-meta a { padding: 4px 0; }
            .notif-bulkbar { top: 62px; gap: 8px 10px; padding: 10px 12px; }
            .notif-bulk-actions { margin-left: 0; width: 100%; }
            .notif-bulk-actions button { flex: 1; justify-content: center; }
            .notif-ctx { min-width: 200px; }
        }
        .notif-card.ctx-target { box-shadow: 0 0 0 2px #9cc8a8, 0 4px 10px rgba(0,0,0,0.08); }

        .empty-notif {
            background: #ffffff;
            border-radius: 16px;
            padding: 50px 20px;
            text-align: center;
            color: #6b7280;
        }
        .empty-notif i { font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px; }
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
                <p>Updates about your applications, interviews, and payments.</p>
            </section>

            <section class="pets-section">
                <div class="notif-head">
                    <div>
                        <h2>Notifications</h2>
                        <div class="count">
                            <?php echo $sp_notif_count > 0 ? $sp_notif_count . ' unread' : 'You are all caught up'; ?>
                        </div>
                    </div>
                    <div class="notif-head-actions">
                        <?php if ($sp_notif_count > 0): ?>
                            <a class="btn-mark-all" href="notifications.php?read=all">
                                <i class="fa-solid fa-check-double"></i> Mark all as read
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (count($notifications) > 0): ?>
                    <!-- Pagpili at pagbura ng maraming abiso -->
                    <form method="POST" id="notifBulkForm"
                          data-confirm="Delete the selected notifications?"
                          data-confirm-title="Delete notifications?"
                          data-confirm-ok="Yes, delete"
                          data-confirm-icon="fa-trash-can"
                          data-confirm-tone="red">
                    <input type="hidden" name="delete_selected" value="1">

                    <div class="notif-bulkbar" id="notifBulkBar" hidden>
                        <label class="notif-selectall">
                            <input type="checkbox" id="notifSelectAll">
                            <span class="notif-check" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                            Select all
                        </label>
                        <span class="notif-selcount" id="notifSelCount">0 selected</span>
                        <div class="notif-bulk-actions">
                            <button type="button" class="btn-bulk-cancel" id="notifCancelBtn">Cancel</button>
                            <button type="submit" class="btn-bulk-delete" id="notifDeleteBtn" disabled>
                                <i class="fa-solid fa-trash-can"></i> Delete
                            </button>
                        </div>
                    </div>

                    <p class="notif-tip">
                        <span class="tip-mouse"><i class="fa-solid fa-computer-mouse"></i> Right-click a notification to select or delete it.</span>
                        <span class="tip-touch"><i class="fa-solid fa-hand-pointer"></i> Press and hold a notification to select or delete it.</span>
                    </p>

                    <!-- Menu kapag nag-right-click (o long-press sa phone) sa isang abiso -->
                    <div class="notif-ctx" id="notifCtx" role="menu" hidden>
                        <button type="button" role="menuitem" data-act="select"><i class="fa-regular fa-square-check"></i> Select</button>
                        <button type="button" role="menuitem" data-act="all"><i class="fa-solid fa-list-check"></i> Select all</button>
                        <hr>
                        <button type="button" role="menuitem" data-act="delete" class="danger"><i class="fa-solid fa-trash-can"></i> Delete this notification</button>
                    </div>

                    <div class="notif-list">
                        <?php foreach ($notifications as $n):
                            $meta = $typeMeta[$n['type']] ?? $typeMeta['general'];
                            $isUnread = !(int) $n['is_read'];
                        ?>
                            <div class="notif-card <?php echo $meta['class']; ?><?php echo $isUnread ? ' unread' : ''; ?>">
                                <!-- Lumalabas lang kapag naka-Select mode -->
                                <label class="notif-pick" title="Select">
                                    <input type="checkbox" name="ids[]" value="<?php echo (int) $n['notification_id']; ?>" class="notif-pick-input">
                                    <span class="notif-check" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                                </label>
                                <div class="notif-icon"><i class="fa-solid <?php echo $meta['icon']; ?>"></i></div>
                                <div class="notif-body">
                                    <div class="notif-title">
                                        <?php echo htmlspecialchars($n['title']); ?>
                                        <?php if ($isUnread): ?><span class="notif-new">New</span><?php endif; ?>
                                    </div>
                                    <div class="notif-msg"><?php echo nl2br(htmlspecialchars($n['message'])); ?></div>
                                    <div class="notif-meta">
                                        <span><i class="fa-regular fa-clock"></i> <?php echo sp_time_ago($n['created_at']); ?></span>
                                        <?php if (!empty($n['link'])): ?>
                                            <a href="notifications.php?read=<?php echo (int) $n['notification_id']; ?>">
                                                <i class="fa-solid fa-arrow-right"></i> View
                                            </a>
                                        <?php elseif ($isUnread): ?>
                                            <a href="notifications.php?read=<?php echo (int) $n['notification_id']; ?>">
                                                <i class="fa-solid fa-check"></i> Mark as read
                                            </a>
                                        <?php endif; ?>
                                        <a class="danger notif-remove" href="notifications.php?delete=<?php echo (int) $n['notification_id']; ?>">
                                            <i class="fa-solid fa-trash-can"></i> Remove
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </form>
                <?php else: ?>
                    <div class="empty-notif">
                        <i class="fa-regular fa-bell-slash"></i>
                        <p>No notifications yet.</p>
                        <p style="font-size:13px;">Updates about your applications, interviews, and payments will appear here.</p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script>
        // Select mode (binubuksan sa right-click menu): may checkbox ang bawat abiso;
        // puwedeng piliin at burahin nang sabay-sabay
        (function () {
            const form = document.getElementById('notifBulkForm');
            if (!form) return;
            const bar = document.getElementById('notifBulkBar');
            const list = form.querySelector('.notif-list');
            const all = document.getElementById('notifSelectAll');
            const count = document.getElementById('notifSelCount');
            const del = document.getElementById('notifDeleteBtn');
            const boxes = Array.from(form.querySelectorAll('.notif-pick-input'));

            function update() {
                const n = boxes.filter(function (b) { return b.checked; }).length;
                boxes.forEach(function (b) { b.closest('.notif-card').classList.toggle('is-picked', b.checked); });
                count.textContent = n + ' selected';
                del.disabled = n === 0;
                del.innerHTML = '<i class="fa-solid fa-trash-can"></i> Delete' + (n > 0 ? ' (' + n + ')' : '');
                all.checked = n > 0 && n === boxes.length;
                form.dataset.confirm = n === 1
                    ? 'Delete the selected notification? This cannot be undone.'
                    : 'Delete the ' + n + ' selected notifications? This cannot be undone.';
            }

            function setSelecting(on) {
                list.classList.toggle('selecting', on);
                bar.hidden = !on;
                if (!on) boxes.forEach(function (b) { b.checked = false; });
                update();
            }

            // ---- Right-click menu ----
            const ctx = document.getElementById('notifCtx');
            let ctxCard = null;

            function closeCtx() {
                ctx.hidden = true;
                if (ctxCard) ctxCard.classList.remove('ctx-target');
                ctxCard = null;
            }

            function openCtx(card, x, y) {
                closeCtx();
                ctxCard = card;
                card.classList.add('ctx-target');
                ctx.hidden = false;
                // Huwag lumampas sa screen
                const w = ctx.offsetWidth, h = ctx.offsetHeight;
                ctx.style.left = Math.min(x, window.innerWidth - w - 8) + 'px';
                ctx.style.top = Math.min(y, window.innerHeight - h - 8) + 'px';
                ctx.querySelector('button').focus();
            }

            list.addEventListener('contextmenu', function (e) {
                const card = e.target.closest('.notif-card');
                if (!card) return;
                e.preventDefault();
                openCtx(card, e.clientX, e.clientY);
            });

            ctx.addEventListener('click', function (e) {
                const btn = e.target.closest('button[data-act]');
                if (!btn || !ctxCard) return;
                const box = ctxCard.querySelector('.notif-pick-input');
                const act = btn.dataset.act;
                closeCtx();

                if (act === 'select') {
                    if (!list.classList.contains('selecting')) {
                        boxes.forEach(function (b) { b.checked = false; });
                    }
                    setSelecting(true);
                    box.checked = true;
                    update();
                } else if (act === 'all') {
                    setSelecting(true);
                    boxes.forEach(function (b) { b.checked = true; });
                    update();
                } else if (act === 'delete') {
                    // Isang abiso lang ang ipapadala, gamit ang parehong form at confirmation
                    setSelecting(false);
                    box.checked = true;
                    update();
                    if (form.requestSubmit) form.requestSubmit(); else form.submit();
                }
            });

            document.addEventListener('mousedown', function (e) {
                if (!ctx.hidden && !ctx.contains(e.target)) closeCtx();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !ctx.hidden) closeCtx();
            });
            window.addEventListener('scroll', closeCtx, true);
            window.addEventListener('resize', closeCtx);

            document.getElementById('notifCancelBtn').addEventListener('click', function () { setSelecting(false); });
            all.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = all.checked; });
                update();
            });
            boxes.forEach(function (b) { b.addEventListener('change', update); });

            // Sa Select mode, pindot kahit saan sa abiso = piliin (maliban sa mga link)
            list.addEventListener('click', function (e) {
                if (!list.classList.contains('selecting')) return;
                const card = e.target.closest('.notif-card');
                if (!card || e.target.closest('a, label, button')) return;
                const box = card.querySelector('.notif-pick-input');
                box.checked = !box.checked;
                update();
            });
            // Sa Select mode, huwag sundan ang mga link (View, Remove) para hindi magkamali
            list.addEventListener('click', function (e) {
                if (list.classList.contains('selecting') && e.target.closest('.notif-meta a')) {
                    e.preventDefault();
                    const box = e.target.closest('.notif-card').querySelector('.notif-pick-input');
                    box.checked = !box.checked;
                    update();
                }
            });

            // Remove: parehong pop-up na kumpirmasyon (hindi native na confirm ng browser)
            list.addEventListener('click', function (e) {
                const rm = e.target.closest('a.notif-remove');
                if (!rm || list.classList.contains('selecting')) return;
                e.preventDefault();
                const box = rm.closest('.notif-card').querySelector('.notif-pick-input');
                boxes.forEach(function (b) { b.checked = false; });
                box.checked = true;
                update();
                if (form.requestSubmit) form.requestSubmit(); else form.submit();
            });

            // Phone/tablet: pindutin nang matagal ang abiso = parehong menu ng right-click
            // (walang right-click sa touch screen; hindi rin nagpapadala ng contextmenu ang iPhone)
            let pressTimer = null, pressCard = null, startX = 0, startY = 0, longPressed = false;
            function cancelPress() {
                clearTimeout(pressTimer);
                pressTimer = null;
                if (pressCard) pressCard.classList.remove('pressing');
                pressCard = null;
            }
            list.addEventListener('touchstart', function (e) {
                const card = e.target.closest('.notif-card');
                if (!card || e.touches.length !== 1 || list.classList.contains('selecting')) return;
                const t = e.touches[0];
                startX = t.clientX; startY = t.clientY;
                longPressed = false;
                pressCard = card;
                card.classList.add('pressing');
                pressTimer = setTimeout(function () {
                    longPressed = true;
                    const c = pressCard;
                    cancelPress();
                    if (navigator.vibrate) { try { navigator.vibrate(15); } catch (err) {} }
                    openCtx(c, startX, startY);
                }, 550);
            }, { passive: true });
            list.addEventListener('touchmove', function (e) {
                if (!pressTimer) return;
                const t = e.touches[0];
                if (Math.abs(t.clientX - startX) > 10 || Math.abs(t.clientY - startY) > 10) cancelPress();
            }, { passive: true });
            list.addEventListener('touchend', function (e) {
                cancelPress();
                // Pagkatapos ng long-press, huwag ituloy ang tap (hal. pagbukas ng View)
                if (longPressed) { e.preventDefault(); longPressed = false; }
            });
            list.addEventListener('touchcancel', cancelPress);
            // Android: may sariling contextmenu pagkatapos ng long-press - huwag doblehin
            list.addEventListener('contextmenu', function (e) {
                if (!ctx.hidden) { e.preventDefault(); e.stopImmediatePropagation(); }
            }, true);
            document.addEventListener('touchstart', function (e) {
                if (!ctx.hidden && !ctx.contains(e.target)) closeCtx();
            }, { passive: true });
        })();

        // Mahabang mensahe: tatlong linya muna sa telepono, may "Show more"
        (function () {
            const mq = window.matchMedia('(max-width: 600px)');
            const msgs = Array.from(document.querySelectorAll('.notif-msg'));
            msgs.forEach(function (msg) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'notif-more';
                btn.hidden = true;
                btn.textContent = 'Show more';
                msg.insertAdjacentElement('afterend', btn);
                btn.addEventListener('click', function () {
                    const open = msg.classList.toggle('clamped') === false;
                    btn.textContent = open ? 'Show less' : 'Show more';
                });
            });
            function apply() {
                msgs.forEach(function (msg) {
                    const btn = msg.nextElementSibling;
                    msg.classList.remove('clamped');
                    const full = msg.getBoundingClientRect().height;
                    msg.classList.toggle('clamped', mq.matches);
                    const cut = msg.getBoundingClientRect().height;
                    // "Show more" lang kapag talagang may natabas (higit sa tatlong linya)
                    const overflow = mq.matches && full > cut + 4;
                    if (mq.matches && !overflow) msg.classList.remove('clamped');
                    btn.hidden = !overflow;
                    btn.textContent = 'Show more';
                });
            }
            apply();
            // Sukatin ulit kapag na-load na ang font at ang page (iba ang lapad ng text bago iyon)
            window.addEventListener('load', apply);
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(apply);
            let resizeTimer = null;
            window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(apply, 150); });
            if (mq.addEventListener) mq.addEventListener('change', apply); else mq.addListener(apply);
        })();
    </script>



    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
