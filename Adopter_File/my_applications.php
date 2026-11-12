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

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Isinasama ang payment status para malaman kung may babayaran pa sa approved na application
$appStmt = $pdo->prepare(
    "SELECT aa.*, p.name AS pet_name, p.photo_url AS pet_photo,
            pay.payment_id, pay.status AS pay_status, isum.summary_id
     FROM adoption_applications aa
     JOIN pet_inventory p ON aa.pet_id = p.pet_id
     LEFT JOIN payments pay ON pay.application_id = aa.application_id
     LEFT JOIN interview_summaries isum ON isum.application_id = aa.application_id
     WHERE aa.adopter_id = ?
     ORDER BY aa.created_at DESC"
);
$appStmt->execute([$user_id]);
$applications = $appStmt->fetchAll(PDO::FETCH_ASSOC);

function statusBadgeClass($status) {
    $map = [
        'Pending' => 'status-pending',
        'Under Review' => 'status-under-review',
        'Approved' => 'status-approved',
        'Rejected' => 'status-rejected',
    ];
    return $map[$status] ?? 'status-pending';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - My Applications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        /* ===== Header: title + filter (All / P / A / R) ===== */
        .app-head {
            position: relative;
            display: flex; align-items: center; justify-content: space-between;
            gap: 10px 14px; flex-wrap: wrap;
            margin: 0 0 18px; padding-bottom: 14px;
            border-bottom: 1px solid #e6eee8;
            background: #fff;
        }
        .app-head::after {   /* orange na accent sa ilalim (kapareho ng ibang listahan) */
            content: ""; position: absolute; left: 0; bottom: -2px;
            width: 72px; height: 3px; border-radius: 3px;
            background: linear-gradient(90deg, #f7941d, #ffc46b);
        }
        .app-head > h2.sp-list-head { margin: 0; padding: 0; border: 0; min-width: 0; }
        .app-head > h2.sp-list-head::after { display: none; }

        .app-filter { display: flex; gap: 8px; margin-left: auto; }
        .app-filter button {
            position: relative;
            min-width: 38px; height: 36px; padding: 0 10px;
            border: none; border-radius: 10px; cursor: pointer;
            font-family: inherit; font-size: 14px; font-weight: 800; color: #fff;
            transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
        }
        /* Kulay ng bawat status: puting text sa may kulay na background */
        .app-filter .f-all { background: #1b4d2e; }
        .app-filter .f-p   { background: #f59c23; }
        .app-filter .f-a   { background: #2e7d32; }
        .app-filter .f-r   { background: #d32f2f; }
        /* Hindi napiling filter: kupas nang kaunti; ang napili: may singsing */
        .app-filter button:not(.is-on) { opacity: .82; }
        .app-filter button:hover { opacity: 1; transform: translateY(-1px); }
        .app-filter button.is-on { box-shadow: 0 0 0 3px #fff, 0 0 0 5px currentColor; }
        .app-filter .f-all.is-on { box-shadow: 0 0 0 3px #fff, 0 0 0 5px #1b4d2e; }
        .app-filter .f-p.is-on   { box-shadow: 0 0 0 3px #fff, 0 0 0 5px #f59c23; }
        .app-filter .f-a.is-on   { box-shadow: 0 0 0 3px #fff, 0 0 0 5px #2e7d32; }
        .app-filter .f-r.is-on   { box-shadow: 0 0 0 3px #fff, 0 0 0 5px #d32f2f; }
        .app-filter button:focus-visible { outline: 3px solid #8fd3ae; outline-offset: 3px; }
        /* Maliit na bilang sa kanang-itaas ng button */
        .app-filter button b {
            position: absolute; top: -7px; right: -7px;
            min-width: 18px; height: 18px; padding: 0 4px; box-sizing: border-box;
            border-radius: 9px; background: #fff; border: 1.5px solid currentColor;
            font-size: 10.5px; line-height: 15px; font-weight: 800;
        }
        .app-filter .f-p b { color: #b25900; border-color: #f59c23; }
        .app-filter .f-a b { color: #1e7e34; border-color: #2e7d32; }
        .app-filter .f-r b { color: #c5221f; border-color: #d32f2f; }

        .app-empty {
            margin: 0 0 14px; padding: 26px 16px; text-align: center;
            color: #6b7280; font-size: 14px; border: 2px dashed #e5e7eb; border-radius: 14px;
        }
        .app-empty[hidden] { display: none; }
        /* May display: flex ang .pet-card sa dashboard.css - kailangang manalo ang [hidden] ng filter */
        .app-list > .pet-card[hidden] { display: none !important; }

        /* ===== Telepono ===== */
        @media (max-width: 900px) {
            /* Sticky na header, buong lapad (may 12px na padding ang .main-content) */
            .app-head {
                position: sticky;
                top: var(--sp-stick-top, 0px);
                z-index: 50;
                margin: 0 -12px 14px; padding: 10px 12px 12px;
                transition: box-shadow .2s ease;
            }
            .app-head::after { left: 12px; }
            .app-head.is-stuck { box-shadow: 0 8px 12px -10px rgba(0, 0, 0, 0.25); }
        }
        @media (max-width: 600px) {
            .app-head > h2.sp-list-head { font-size: 17px; gap: 8px; white-space: nowrap; }
            .app-head .sp-head-ico { width: 30px; height: 30px; border-radius: 9px; font-size: 13px; }
            .app-head .sp-count { display: none; }   /* ang bilang ay nasa mga button na */
            .app-filter { gap: 7px; }
            .app-filter button { min-width: 34px; height: 32px; padding: 0 8px; font-size: 13px; border-radius: 9px; }
            .app-filter button b { top: -6px; right: -6px; min-width: 16px; height: 16px; font-size: 9.5px; line-height: 13px; }

            /* Mga card: mas maayos na sukat ng text at button */
            .pets-grid.app-list > .pet-card { padding: 12px; }
            .pets-grid.app-list .pet-img-container { width: 68px; height: 68px; }
            .pets-grid.app-list .pet-info { gap: 3px; }
            .pets-grid.app-list .pet-info h3 { font-size: 15px; line-height: 1.25; }
            .pets-grid.app-list .pet-info .meta { font-size: 12px; }
            .pets-grid.app-list .pet-info .status-badge { font-size: 11px; padding: 3px 9px; }
            .pets-grid.app-list .pet-info .rejection-note { font-size: 12px; margin-top: 2px; }
            .pets-grid.app-list .pet-info .fee-note { font-size: 12px; }
            .pets-grid.app-list .pet-info .btn-pay-small {
                margin-top: 6px; padding: 7px 12px !important; font-size: 12px !important;
                line-height: 1.25 !important; border-radius: 8px !important;
            }
        }
    </style>
</head>
<body class="sp-list-scroll" style="background-color: #1b4d2e;">

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
                <p>Track the status of your adoption applications.</p>
            </section>

            <?php if (!empty($success_message)): ?>
                <div style="background:#e8f5e9; color:#2e7d32; padding:10px 15px; border-radius:6px; margin-bottom:15px;">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:6px; margin-bottom:15px;">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <section class="pets-section sp-scroll-host">
                <?php
                // Grupo ng bawat application para sa filter: P = Pending (kasama ang Under Review),
                // A = Approved, R = Rejected
                $appGroup = function (string $status): string {
                    if ($status === 'Approved') return 'A';
                    if ($status === 'Rejected') return 'R';
                    return 'P';
                };
                $groupCounts = ['P' => 0, 'A' => 0, 'R' => 0];
                foreach ($applications as $a) { $groupCounts[$appGroup($a['application_status'])]++; }
                ?>
                <!-- Title sa kaliwa, filter (All / P / A / R) sa kanan. Sticky sa telepono. -->
                <div class="app-head">
                    <h2 class="sp-list-head"><span class="sp-head-ico"><i class="fa-solid fa-file-lines"></i></span>My Applications <span class="sp-count" data-word="application"></span></h2>
                    <?php if (count($applications) > 0): ?>
                    <div class="app-filter" role="group" aria-label="Show applications by status">
                        <button type="button" class="f-all is-on" data-filter="all" aria-pressed="true" title="All applications">All</button>
                        <button type="button" class="f-p" data-filter="P" aria-pressed="false" title="Pending">P<b><?php echo $groupCounts['P']; ?></b></button>
                        <button type="button" class="f-a" data-filter="A" aria-pressed="false" title="Approved">A<b><?php echo $groupCounts['A']; ?></b></button>
                        <button type="button" class="f-r" data-filter="R" aria-pressed="false" title="Rejected">R<b><?php echo $groupCounts['R']; ?></b></button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (count($applications) > 0): ?>
                    <p class="app-empty" id="appEmpty" hidden></p>
                    <div class="pets-grid app-list">
                        <?php foreach ($applications as $app): ?>
                            <div class="pet-card" data-group="<?php echo $appGroup($app['application_status']); ?>">
                                <div class="card-top">
                                    <div class="pet-img-container">
                                        <?php if (!empty($app['pet_photo'])): ?>
                                            <img src="../uploads/<?php echo htmlspecialchars($app['pet_photo']); ?>" alt="<?php echo htmlspecialchars($app['pet_name']); ?>" onerror="this.src='https://via.placeholder.com/150'">
                                        <?php else: ?>
                                            <img src="https://via.placeholder.com/150" alt="<?php echo htmlspecialchars($app['pet_name']); ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div class="pet-info">
                                        <h3><?php echo htmlspecialchars($app['pet_name']); ?></h3>
                                        <p class="meta">Applied: <?php echo date('M d, Y', strtotime($app['created_at'])); ?></p>
                                        <span class="status-badge <?php echo statusBadgeClass($app['application_status']); ?>">
                                            <?php echo htmlspecialchars($app['application_status']); ?>
                                        </span>
                                        <?php if ($app['application_status'] === 'Rejected' && !empty($app['rejection_reason'])): ?>
                                            <p class="rejection-note">Reason: <?php echo htmlspecialchars($app['rejection_reason']); ?></p>
                                        <?php endif; ?>

                                        <?php // Buod ng interview na isinulat ng staff ?>
                                        <?php if (!empty($app['summary_id'])): ?>
                                            <a href="interview_summary.php?id=<?php echo (int) $app['application_id']; ?>" class="btn-pay-small">
                                                <i class="fa-solid fa-file-signature"></i> View Interview Summary
                                            </a>
                                        <?php endif; ?>

                                        <?php // Pet Meet-up: ipinadala na ng staff ang mga araw na bukas ang City Hall ?>
                                        <?php if (!empty($app['meetup_sent_at']) && in_array($app['application_status'], ['Pending', 'Under Review'], true)): ?>
                                            <?php if (!empty($app['meetup_option_id'])): ?>
                                                <p class="fee-note paid"><i class="fa-solid fa-paw"></i> Pet meet-up scheduled</p>
                                                <a href="schedule.php#meetup-<?php echo (int) $app['application_id']; ?>" class="btn-pay-small">
                                                    <i class="fa-solid fa-location-dot"></i> View Meet-up and Location
                                                </a>
                                            <?php else: ?>
                                                <p class="fee-note due"><i class="fa-solid fa-paw"></i> You can now meet <?php echo htmlspecialchars($app['pet_name']); ?> at Caloocan City Hall</p>
                                                <a href="schedule.php?meetup=<?php echo (int) $app['application_id']; ?>" class="btn-pay-small">
                                                    <i class="fa-solid fa-calendar-days"></i> Choose Your Meet-up Day
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php
                                            $isApproved = $app['application_status'] === 'Approved';
                                            $isFeePaid = ($app['pay_status'] ?? '') === 'Paid';
                                        ?>
                                        <?php if ($isApproved && $isFeePaid): ?>
                                            <p class="fee-note paid"><i class="fa-solid fa-circle-check"></i> Adoption fee paid</p>
                                        <?php elseif ($isApproved): ?>
                                            <p class="fee-note due"><i class="fa-solid fa-circle-exclamation"></i> &#8369;500.00 adoption fee due</p>
                                            <a href="payments.php" class="btn-pay-small">
                                                <i class="fa-solid fa-money-bill-wave"></i> Pay Now
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #fff;">
                        <p>You haven't submitted any adoption applications yet.</p>
                        <a href="search_pets.php" class="btn-orange">Search Pets</a>
                    </div>
                <?php endif; ?>
            </section>

        </main>
    </div>

    <?php if (!empty($_GET['submitted'])): ?>
    <script>
        // Na-submit na ang application - burahin ang naka-save na draft ng form
        (function () {
            const key = 'sp_app_draft_<?php echo (int) $user_id; ?>_<?php echo (int) $_GET['submitted']; ?>';
            const fileInputs = ['photo_front_house', 'photo_street', 'photo_living_room',
                'photo_bedrooms', 'photo_backyard', 'valid_id', 'valid_id_back', 'barangay_certificate'];
            try { localStorage.removeItem(key); } catch (e) {}
            // Tanggalin ang ?submitted sa address bar para hindi maulit
            history.replaceState(null, '', 'my_applications.php');
            // Naka-try/catch para hindi mag-error sa Private Browsing ng Safari (iPhone/iPad)
            try {
                if (!window.indexedDB) return;
                const req = indexedDB.open('sp_app_drafts', 1);
                req.onupgradeneeded = function () { req.result.createObjectStore('files'); };
                req.onsuccess = function () {
                    try {
                        const store = req.result.transaction('files', 'readwrite').objectStore('files');
                        fileInputs.forEach(function (name) { store.delete(key + '|' + name); });
                    } catch (e) {}
                };
                req.onerror = function () {};
            } catch (e) {}
        })();
    </script>
    <?php endif; ?>

    <script>
        // Filter ayon sa status: All / P (Pending) / A (Approved) / R (Rejected).
        // Itinatago (hidden) ang hindi tugmang card - kusa ring nag-a-update ang bilang
        // sa tabi ng title (list_count.js) dahil binabantayan nito ang [hidden].
        (function () {
            const buttons = document.querySelectorAll('.app-filter button');
            const cards = document.querySelectorAll('.app-list .pet-card');
            const empty = document.getElementById('appEmpty');
            if (!buttons.length) return;
            const WORDS = { P: 'pending', A: 'approved', R: 'rejected' };

            function apply(filter) {
                let shown = 0;
                cards.forEach(function (card) {
                    const on = filter === 'all' || card.dataset.group === filter;
                    card.hidden = !on;
                    if (on) shown++;
                });
                buttons.forEach(function (b) {
                    const on = b.dataset.filter === filter;
                    b.classList.toggle('is-on', on);
                    b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
                empty.hidden = shown > 0;
                if (!shown) empty.textContent = 'You have no ' + (WORDS[filter] || '') + ' applications.';
                try { sessionStorage.setItem('sp_app_filter', filter); } catch (e) {}
            }

            buttons.forEach(function (b) {
                b.addEventListener('click', function () {
                    apply(b.dataset.filter);
                    // Sa telepono: bumalik sa simula ng listahan (hindi natatakpan ng sticky header)
                    const head = document.querySelector('.app-head');
                    const list = document.querySelector('.app-list');
                    const stick = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sp-stick-top')) || 0;
                    const y = list.getBoundingClientRect().top + window.scrollY - stick - head.offsetHeight - 16;
                    if (window.scrollY > y) window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
                });
            });

            // Naaalala ang huling napiling filter (sa tab na ito)
            let saved = 'all';
            try { saved = sessionStorage.getItem('sp_app_filter') || 'all'; } catch (e) {}
            apply(['all', 'P', 'A', 'R'].indexOf(saved) !== -1 ? saved : 'all');

            // May anino kapag nakadikit na ang header sa telepono
            const head = document.querySelector('.app-head');
            window.addEventListener('scroll', function () {
                const stick = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sp-stick-top')) || 0;
                head.classList.toggle('is-stuck', window.scrollY > 0 && head.getBoundingClientRect().top <= stick + 0.5);
            }, { passive: true });
        })();
    </script>

    <?php include '../connection/chat_bubble.php'; ?>
<script src="list_count.js?v=<?php echo @filemtime(__DIR__ . '/list_count.js'); ?>"></script>
</body>
</html>
