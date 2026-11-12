<?php
/**
 * Docs page ng adopter - dito laging makikita ang mga Certificate of Adoption,
 * kahit nabura na ang notification. Hinahango ito sa mismong records
 * (Approved na application + Paid na bayad), hindi sa notifications table.
 */
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../Login_Page/login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$sp_unread_count = sp_unread_message_count($pdo, $user_id);
$sp_notif_count = sp_unread_notification_count($pdo, $user_id);

$userStmt = $pdo->prepare('SELECT username, profile_image FROM users WHERE user_id = ?');
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';

$certStmt = $pdo->prepare(
    "SELECT aa.application_id, aa.cert_ready_at, aa.cert_released_at, aa.cert_adopter_received_at,
            p.name AS pet_name, p.kind, p.breed, p.photo_url, pay.payment_date, cf.rating AS fb_rating
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
     LEFT JOIN certificate_feedback cf ON cf.application_id = aa.application_id
     WHERE aa.adopter_id = ? AND (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
     ORDER BY pay.payment_date DESC"
);
$certStmt->execute([$user_id]);
$certificates = $certStmt->fetchAll(PDO::FETCH_ASSOC);

// Mensahe mula sa I Received / feedback
$docsSuccess = $_SESSION['docs_success'] ?? '';
$docsError = $_SESSION['docs_error'] ?? '';
unset($_SESSION['docs_success'], $_SESSION['docs_error']);

// Bubuksan agad ang feedback pagkatapos pindutin ang "I Received"
// (kapag kinumpirma na at wala pang naibigay na feedback)
$feedbackFor = null;
$feedbackId = (int) ($_GET['feedback'] ?? 0);
foreach ($certificates as $c) {
    if ((int) $c['application_id'] === $feedbackId && !empty($c['cert_adopter_received_at']) && $c['fb_rating'] === null) {
        $feedbackFor = $c;
    }
}

// Bilang ayon sa estado ng opisyal na kopya (para sa banner)
$certCounts = ['pending' => 0, 'ready' => 0, 'released' => 0];
// ready    = lahat ng napindot na ng staff ang "Ready for Pickup" (kasama ang
//            na-receive na)
// received = kinumpirma na ng adopter ("I Received")
// pending  = wala pang "Ready for Pickup"
foreach ($certificates as $c) {
    if (!empty($c['cert_ready_at']) || !empty($c['cert_released_at'])) {
        $certCounts['ready']++;
    } else {
        $certCounts['pending']++;
    }
    if (!empty($c['cert_adopter_received_at'])) {
        $certCounts['released']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Docs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        .doc-list { display: flex; flex-direction: column; gap: 12px; }
        .doc-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .doc-icon {
            width: 52px; height: 52px;
            border-radius: 12px;
            background: #fef3c7;
            color: #b45309;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            overflow: hidden;
        }
        .doc-icon img { width: 100%; height: 100%; object-fit: cover; }
        .doc-body { flex: 1; min-width: 180px; }
        .doc-title { font-size: 15.5px; font-weight: 800; color: #1b4d2e; }
        .doc-sub { font-size: 13px; color: #6b7280; margin-top: 3px; }
        .doc-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .doc-actions a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            border: 1.5px solid #cfe0d4;
            color: #1b4d2e;
            background: #fff;
        }
        .doc-actions a:hover { background: #f0f7f3; border-color: #2e5b32; }
        .doc-official { font-size: 12.5px; color: #6b7280; margin-top: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .official-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-weight: 700; font-size: 12px; }
        .official-badge.is-pending { background: #fef3c7; color: #b45309; }
        .official-badge.is-ready { background: #e0edff; color: #1a56db; }
        .official-badge.is-released { background: #d1fae5; color: #065f46; }
        .dl-form { display: flex; gap: 6px; align-items: center; margin: 0; }
        .dl-form select {
            padding: 7px 10px;
            border-radius: 999px;
            border: 1.5px solid #cfe0d4;
            font-size: 13px;
            font-weight: 700;
            color: #1b4d2e;
            background: #fff;
            cursor: pointer;
        }
        .dl-form button {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            background: #1b4d2e;
            color: #fff;
            border: 1.5px solid #1b4d2e;
            cursor: pointer;
        }
        .dl-form button:hover { background: #2e5b32; }

        .empty-docs {
            background: #ffffff;
            border-radius: 16px;
            padding: 50px 20px;
            text-align: center;
            color: #6b7280;
        }
        .empty-docs i { font-size: 36px; color: #cbd5e1; display: block; margin-bottom: 12px; }

        /* ===== I Received button (kaliwa ng View) ===== */
        .doc-actions { align-items: center; }
        .btn-received {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: 999px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            border: 1.5px solid #1b4d2e;
            background: #1b4d2e;
            color: #fff;
            cursor: pointer;
        }
        .btn-received:hover:not(:disabled) { background: #2e5b32; border-color: #2e5b32; }
        .btn-received:disabled { background: #f3f4f6; border-color: #e5e7eb; color: #9ca3af; cursor: not-allowed; }
        .btn-received.is-done { background: #d1fae5; border-color: #a7f3d0; color: #065f46; cursor: default; }
        .received-form { margin: 0; }
        .btn-rate {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 16px; border-radius: 999px;
            font-family: inherit; font-size: 13px; font-weight: 700;
            border: 1.5px solid #f59c23; background: #fff8eb; color: #b25900;
            cursor: pointer;
        }
        .btn-rate:hover { background: #fdebc8; }
        .doc-rated { display: inline-flex; gap: 2px; font-size: 13px; color: #d6d9dc; }
        .doc-rated .on { color: #f5a623; }

        .docs-flash {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 16px; margin-bottom: 14px; border-radius: 12px;
            font-size: 13.5px; font-weight: 600;
        }
        .docs-flash.is-success { background: #e6f4ea; color: #1e7e34; }
        .docs-flash.is-error { background: #fce8e6; color: #c5221f; }

        /* ===== Feedback popup (pagkatapos ng I Received) ===== */
        .fb-backdrop {
            position: fixed; inset: 0; z-index: 4000;
            display: none; align-items: center; justify-content: center;
            padding: 16px; background: rgba(10, 30, 20, 0.5);
        }
        .fb-backdrop.open { display: flex; animation: fbFade .3s ease; }
        .fb-box {
            width: 100%; max-width: 420px;
            padding: 28px 26px 22px; border-radius: 20px;
            background: #fff; text-align: center;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
            animation: fbPop .5s cubic-bezier(.34, 1.56, .64, 1);
        }
        .fb-icon {
            width: 64px; height: 64px; margin: 0 auto 12px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; color: #fff;
            background: linear-gradient(135deg, #3fae72, #1b4d2e);
            box-shadow: 0 0 0 7px #e6f4ea;
        }
        .fb-box h3 { margin: 0 0 6px; font-size: 21px; font-weight: 800; color: #1b4d2e; }
        .fb-box > p { margin: 0 0 16px; font-size: 13.5px; line-height: 1.5; color: #6b7280; }
        .fb-stars { display: flex; justify-content: center; gap: 6px; margin-bottom: 6px; direction: rtl; }
        .fb-stars input { position: absolute; opacity: 0; pointer-events: none; }
        .fb-stars label { font-size: 34px; color: #d6d9dc; cursor: pointer; transition: color .12s, transform .12s; }
        .fb-stars label:hover,
        .fb-stars label:hover ~ label,
        .fb-stars input:checked ~ label { color: #f5a623; }
        .fb-stars label:active { transform: scale(1.2); }
        .fb-stars input:focus-visible + label { outline: 2px solid #1b4d2e; outline-offset: 2px; border-radius: 4px; }
        .fb-rating-text { min-height: 18px; margin-bottom: 12px; font-size: 13px; font-weight: 700; color: #b25900; }
        .fb-box textarea {
            width: 100%; min-height: 84px; padding: 10px 12px;
            border: 1.5px solid #d6e3cc; border-radius: 12px;
            font-family: inherit; font-size: 13.5px; resize: vertical;
            box-sizing: border-box; background: #f8fbf5;
        }
        .fb-box textarea:focus { outline: none; border-color: #2e5b32; background: #fff; }
        .fb-actions { display: flex; gap: 10px; margin-top: 14px; }
        .fb-actions button {
            flex: 1; padding: 11px; border-radius: 10px;
            font-family: inherit; font-size: 14px; font-weight: 700; cursor: pointer;
        }
        .fb-skip { border: 1.5px solid #d1d5db; background: #fff; color: #4b5563; }
        .fb-send { border: none; background: #1b4d2e; color: #fff; }
        .fb-send:disabled { opacity: .5; cursor: not-allowed; }
        @keyframes fbFade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes fbPop { from { opacity: 0; transform: scale(.8) translateY(20px); } to { opacity: 1; transform: none; } }

        /* ===== Banner sa itaas ===== */
        .docs-hero {
            position: relative;
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 22px 26px;
            margin-bottom: 14px;
            border-radius: 20px;
            overflow: hidden;
            color: #fff;
            background:
                radial-gradient(circle at 92% 10%, rgba(255, 196, 107, 0.35), transparent 40%),
                linear-gradient(135deg, #1b4d2e 0%, #2e6b3f 60%, #4f8a3c 100%);
            box-shadow: 0 10px 24px rgba(27, 77, 46, 0.22);
        }
        .docs-hero-icon {
            width: 64px;
            height: 64px;
            flex-shrink: 0;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: #ffb547;
            background: rgba(255, 255, 255, 0.12);
            box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, 0.18);
        }
        .docs-hero-text { position: relative; z-index: 1; min-width: 0; }
        .docs-hero-text h1 {
            margin: 0 0 4px;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: .2px;
        }
        .docs-hero-text p {
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
            color: rgba(255, 255, 255, 0.85);
            max-width: 680px;
        }
        .docs-hero-text p strong { color: #ffd08a; }
        .docs-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .docs-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
        }
        .docs-chip b { font-weight: 800; }
        .docs-chip.is-pending { background: #fef3c7; color: #92400e; }
        .docs-chip.is-ready { background: #e0edff; color: #1a56db; }
        .docs-chip.is-released { background: #d1fae5; color: #065f46; }
        .docs-hero-paw {
            position: absolute;
            right: 22px;
            bottom: -18px;
            font-size: 110px;
            color: rgba(255, 255, 255, 0.07);
            transform: rotate(-18deg);
            pointer-events: none;
        }

        /* Paalala tungkol sa opisyal na kopya */
        .docs-note {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 16px;
            margin-bottom: 18px;
            border-radius: 12px;
            background: #fff8eb;
            border: 1px solid #f6dfb4;
            color: #6b4e16;
            font-size: 13px;
            line-height: 1.45;
        }
        .docs-note i {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fde6bd;
            color: #c2410c;
            font-size: 15px;
        }

        /* ===== Telepono: mas siksik at pantay-pantay ===== */
        @media (max-width: 600px) {
            /* Banner: maliit na icon sa tabi ng title; ang paliwanag at chips sa ilalim
               (display: contents = ang laman ng .docs-hero-text ang nagiging bahagi ng grid) */
            .docs-hero {
                display: grid; grid-template-columns: 42px 1fr; align-items: center;
                column-gap: 12px; row-gap: 8px; padding: 16px; border-radius: 16px;
            }
            .docs-hero-text { display: contents; }
            .docs-hero-icon { width: 42px; height: 42px; border-radius: 12px; font-size: 20px; }
            .docs-hero-text h1 { margin: 0; font-size: 18px; line-height: 1.25; }
            .docs-hero-text p, .docs-chips { grid-column: 1 / -1; }
            .docs-hero-text p { font-size: 13px; line-height: 1.5; }
            .docs-chips { gap: 6px; margin-top: 2px; }
            .docs-chip { font-size: 11.5px; padding: 4px 10px; gap: 5px; }
            .docs-hero-paw { font-size: 80px; right: 10px; }

            .docs-note { font-size: 12.5px; padding: 10px 12px; gap: 10px; }
            .docs-note i { width: 30px; height: 30px; font-size: 13px; }

            /* Card ng bawat adoption */
            .doc-card { padding: 14px; gap: 12px; align-items: flex-start; }
            .doc-icon { width: 48px; height: 48px; }
            .doc-body { min-width: 0; }
            .doc-title { font-size: 15px; line-height: 1.3; }
            .doc-sub { font-size: 12px; line-height: 1.45; }
            .doc-official { font-size: 12px; gap: 6px; }
            .official-badge { font-size: 11.5px; padding: 3px 9px; }

            /* Mga button: 2 magkasinlapad na column, pare-pareho ang taas (40px).
               Kapag 3 ang laman, buong lapad ang View sa ibaba para pantay pa rin. */
            .doc-actions {
                flex-basis: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
                padding-top: 12px; border-top: 1px solid #eef1ef;
            }
            .doc-actions > :last-child:nth-child(odd) { grid-column: 1 / -1; }
            .received-form { display: contents; }
            .doc-actions a, .btn-received, .btn-rate, .doc-rated {
                height: 40px; width: 100%; box-sizing: border-box; justify-content: center;
                padding: 0 10px; border-radius: 10px; font-size: 13px; white-space: nowrap; gap: 6px;
            }
            .doc-rated { align-items: center; background: #fff8eb; border: 1.5px solid #fde6bd; font-size: 14px; }
        }

        /* Sa laptop: ang listahan lang ang nag-i-scroll (may sariling scrollbar
           sa loob nito); hindi gumagalaw ang header at ang title */
        @media (min-width: 900px) {
            .docs-page .main-content {
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }
            .docs-page .main-content > .top-navbar,
            .docs-page .main-content > .docs-hero,
            .docs-page .main-content > .docs-note,
            .docs-page .main-content > .docs-flash {
                flex-shrink: 0;
            }
            .docs-page .pets-section {
                flex: 1;
                min-height: 0;
                display: flex;
                flex-direction: column;
                margin-bottom: 0;
            }
            .docs-page .doc-list {
                flex: 1;
                min-height: 0;
                overflow-y: auto;
                /* puwang para hindi maputol ang anino ng cards at para may agwat sa scrollbar */
                padding: 4px 14px 16px 4px;
                margin: -4px -14px 0 -4px;
                scrollbar-width: thin;
                scrollbar-color: #9cbf8a transparent;
            }
            .docs-page .doc-list::-webkit-scrollbar { width: 8px; }
            .docs-page .doc-list::-webkit-scrollbar-track { background: transparent; }
            .docs-page .doc-list::-webkit-scrollbar-thumb {
                background: #9cbf8a;
                border-radius: 999px;
            }
            .docs-page .doc-list::-webkit-scrollbar-thumb:active { background: #2e5b32; }
        }

        /* ===== Animation sa pagbukas ng page (pagpindot sa "Certificate" sa navbar) =====
           Sunod-sunod: banner (may kislap) -> icon -> chips -> paalala -> mga card.
           "backwards" lang ang fill, kaya pagkatapos ay normal ulit ang hover ng mga card. */
        .docs-hero { animation: docsHeroIn .7s cubic-bezier(.22, 1, .36, 1) backwards; }
        .docs-hero::after {
            /* kislap na dumadaan sa banner minsan */
            content: "";
            position: absolute;
            top: 0;
            bottom: 0;
            left: -40%;
            width: 35%;
            background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.18), transparent);
            transform: skewX(-18deg);
            pointer-events: none;
            animation: docsShine 1.1s ease .45s both;
        }
        .docs-hero-icon { animation: docsPop .6s cubic-bezier(.34, 1.56, .64, 1) .2s backwards; }
        .docs-hero-text h1 { animation: docsRise .55s cubic-bezier(.22, 1, .36, 1) .18s backwards; }
        .docs-hero-text p  { animation: docsRise .55s cubic-bezier(.22, 1, .36, 1) .26s backwards; }
        .docs-chip { animation: docsPop .45s cubic-bezier(.34, 1.56, .64, 1) backwards; }
        .docs-chip:nth-child(1) { animation-delay: .38s; }
        .docs-chip:nth-child(2) { animation-delay: .46s; }
        .docs-chip:nth-child(3) { animation-delay: .54s; }
        .docs-chip:nth-child(4) { animation-delay: .62s; }
        .docs-hero-paw { animation: docsPaw .9s cubic-bezier(.22, 1, .36, 1) .3s backwards; }
        .docs-note { animation: docsSlide .55s cubic-bezier(.22, 1, .36, 1) .35s backwards; }
        .docs-flash { animation: docsRise .5s cubic-bezier(.22, 1, .36, 1) .4s backwards; }
        .doc-card { animation: docsRise .6s cubic-bezier(.22, 1, .36, 1) backwards; }
        .doc-card:nth-child(1) { animation-delay: .45s; }
        .doc-card:nth-child(2) { animation-delay: .53s; }
        .doc-card:nth-child(3) { animation-delay: .61s; }
        .doc-card:nth-child(4) { animation-delay: .69s; }
        .doc-card:nth-child(5) { animation-delay: .77s; }
        .doc-card:nth-child(n+6) { animation-delay: .85s; }

        @keyframes docsHeroIn {
            from { opacity: 0; transform: translateY(18px) scale(.98); }
            to   { opacity: 1; transform: none; }
        }
        @keyframes docsRise {
            from { opacity: 0; transform: translateY(22px); filter: blur(4px); }
            to   { opacity: 1; transform: none; filter: none; }
        }
        @keyframes docsPop {
            from { opacity: 0; transform: scale(.6); }
            to   { opacity: 1; transform: scale(1); }
        }
        @keyframes docsSlide {
            from { opacity: 0; transform: translateX(-24px); }
            to   { opacity: 1; transform: none; }
        }
        @keyframes docsPaw {
            from { opacity: 0; transform: rotate(-50deg) scale(.6); }
            to   { opacity: 1; transform: rotate(-18deg); }
        }
        @keyframes docsShine {
            from { left: -40%; }
            to   { left: 120%; }
        }
        @media (prefers-reduced-motion: reduce) {
            .docs-hero, .docs-hero::after, .docs-hero-icon, .docs-hero-text h1, .docs-hero-text p,
            .docs-chip, .docs-hero-paw, .docs-note, .docs-flash, .doc-card { animation: none !important; }
        }
    </style>
</head>
<body class="docs-page" style="background-color: #1b4d2e;">

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
                    <a href="docs.php" class="active">Certificate</a>
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

            <section class="docs-hero">
                <div class="docs-hero-icon" aria-hidden="true">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div class="docs-hero-text">
                    <h1>Certificates of Adoption</h1>
                    <p>Your adoption documents, for reference only. Tap <strong>View</strong> to see your Certificate of Adoption, Vaccination Certificate and Pet Adoption Agreement.</p>
                    <div class="docs-chips">
                        <span class="docs-chip"><b><?php echo count($certificates); ?></b> <?php echo count($certificates) === 1 ? 'adoption' : 'adoptions'; ?></span>
                        <span class="docs-chip is-pending"><i class="fa-solid fa-hourglass-half"></i> <b><?php echo $certCounts['pending']; ?></b> being prepared</span>
                        <span class="docs-chip is-ready"><i class="fa-solid fa-box"></i> <b><?php echo $certCounts['ready']; ?></b> ready for pickup</span>
                        <span class="docs-chip is-released"><i class="fa-solid fa-circle-check"></i> <b><?php echo $certCounts['released']; ?></b> received</span>
                    </div>
                </div>
                <i class="fa-solid fa-paw docs-hero-paw" aria-hidden="true"></i>
            </section>

            <div class="docs-note">
                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                <span>You can only <strong>view</strong> your certificates here. You will receive the <strong>printed certificate</strong> at the City Veterinary Office before you take your pet home.</span>
            </div>

            <?php if ($docsSuccess !== ''): ?>
                <div class="docs-flash is-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($docsSuccess); ?></div>
            <?php endif; ?>
            <?php if ($docsError !== ''): ?>
                <div class="docs-flash is-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($docsError); ?></div>
            <?php endif; ?>

            <section class="pets-section">

                <?php if (count($certificates) > 0): ?>
                    <div class="doc-list">
                        <?php foreach ($certificates as $c):
                            $seq = date('Y', strtotime($c['payment_date'])) . '-' . str_pad((string) $c['application_id'], 5, '0', STR_PAD_LEFT);
                        ?>
                            <div class="doc-card">
                                <div class="doc-icon">
                                    <?php if (!empty($c['photo_url'])): ?>
                                        <img src="../uploads/<?php echo htmlspecialchars($c['photo_url']); ?>" alt="" onerror="this.replaceWith(Object.assign(document.createElement('i'), {className: 'fa-solid fa-certificate'}))">
                                    <?php else: ?>
                                        <i class="fa-solid fa-certificate"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="doc-body">
                                    <div class="doc-title">Adoption Documents: <?php echo htmlspecialchars($c['pet_name']); ?></div>
                                    <div class="doc-sub">
                                        Certificate of Adoption, Vaccination Certificate &amp; Pet Adoption Agreement
                                        &nbsp;|&nbsp; Adopted <?php echo date('M j, Y', strtotime($c['payment_date'])); ?>
                                        &nbsp;|&nbsp; No. <?php echo $seq; ?>
                                    </div>
                                    <div class="doc-official">
                                        <?php if (!empty($c['cert_adopter_received_at'])): ?>
                                            <span class="official-badge is-released"><i class="fa-solid fa-circle-check"></i> Official copy received</span>
                                            <?php echo date('M j, Y', strtotime($c['cert_adopter_received_at'])); ?>
                                        <?php elseif (!empty($c['cert_released_at'])): ?>
                                            <span class="official-badge is-ready"><i class="fa-solid fa-hand-holding"></i> Handed to you</span>
                                            Our staff gave you the printed certificate. Please tap <strong>I Received</strong> to confirm.
                                        <?php elseif (!empty($c['cert_ready_at'])): ?>
                                            <span class="official-badge is-ready"><i class="fa-solid fa-box"></i> Official copy ready for pickup</span>
                                            Signed and sealed, to be handed to you when you pick up your pet.
                                        <?php else: ?>
                                            <span class="official-badge is-pending"><i class="fa-solid fa-hourglass-half"></i> Official copy being prepared</span>
                                            Waiting for the City Veterinarian's signature and seal.
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="doc-actions">
                                    <!-- I Received: mapipindot kapag napindot na ng staff ang "Certificate Given".
                                         Pagkatapos, may feedback kung paano inasikaso ng staff. -->
                                    <?php if (!empty($c['cert_adopter_received_at'])): ?>
                                        <?php if ($c['fb_rating'] === null): ?>
                                            <button type="button" class="btn-rate" data-feedback="<?php echo (int) $c['application_id']; ?>" data-pet="<?php echo htmlspecialchars($c['pet_name']); ?>"><i class="fa-solid fa-star"></i> Rate service</button>
                                        <?php else: ?>
                                            <span class="doc-rated" title="Your rating"><?php for ($s = 1; $s <= 5; $s++): ?><i class="fa-solid fa-star<?php echo $s <= (int) $c['fb_rating'] ? ' on' : ''; ?>"></i><?php endfor; ?></span>
                                        <?php endif; ?>
                                        <span class="btn-received is-done"><i class="fa-solid fa-circle-check"></i> Received</span>
                                    <?php elseif (!empty($c['cert_released_at'])): ?>
                                        <form method="POST" action="process_cert_received.php" class="received-form"
                                              data-confirm="Did you receive the printed certificate for <?php echo htmlspecialchars($c['pet_name']); ?> from the City Veterinary Office?"
                                              data-confirm-title="Certificate received?"
                                              data-confirm-ok="Yes, I received it"
                                              data-confirm-icon="fa-hand-holding"
                                              data-confirm-tone="green">
                                            <input type="hidden" name="application_id" value="<?php echo (int) $c['application_id']; ?>">
                                            <button type="submit" class="btn-received"><i class="fa-solid fa-hand-holding"></i> I Received</button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn-received" disabled title="Available once our staff hands you the printed certificate"><i class="fa-solid fa-hand-holding"></i> I Received</button>
                                    <?php endif; ?>
                                    <!-- Pang-view lang ang adopter; ang opisyal na kopya ay iaabot sa pickup -->
                                    <a href="certificate.php?application_id=<?php echo (int) $c['application_id']; ?>"><i class="fa-solid fa-eye"></i> View</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-docs">
                        <i class="fa-regular fa-file-lines"></i>
                        <p>No documents yet.</p>
                        <p style="font-size:13px;">Your Certificate of Adoption, Vaccination Certificate, and Pet Adoption Agreement will appear here once your adoption is approved and the adoption fee has been paid.</p>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Feedback: kung paano inasikaso ng staff sa City Veterinary Office -->
    <div class="fb-backdrop<?php echo $feedbackFor ? ' open' : ''; ?>" id="fbModal" role="dialog" aria-modal="true" aria-labelledby="fbTitle">
        <form class="fb-box" method="POST" action="process_cert_feedback.php">
            <div class="fb-icon" aria-hidden="true"><i class="fa-solid fa-hand-holding-heart"></i></div>
            <h3 id="fbTitle">How did we do?</h3>
            <p>You received the certificate for <strong id="fbPet"><?php echo $feedbackFor ? htmlspecialchars($feedbackFor['pet_name']) : ''; ?></strong>. How did our staff at the City Veterinary Office assist you?</p>
            <input type="hidden" name="application_id" id="fbAppId" value="<?php echo $feedbackFor ? (int) $feedbackFor['application_id'] : ''; ?>">

            <!-- Nakabaligtad (rtl) para gumana ang "hover hanggang dito" gamit ang CSS lang -->
            <div class="fb-stars" role="radiogroup" aria-label="Rating">
                <?php for ($s = 5; $s >= 1; $s--): ?>
                    <input type="radio" name="rating" id="fbStar<?php echo $s; ?>" value="<?php echo $s; ?>">
                    <label for="fbStar<?php echo $s; ?>" title="<?php echo $s; ?> star<?php echo $s > 1 ? 's' : ''; ?>">&#9733;</label>
                <?php endfor; ?>
            </div>
            <div class="fb-rating-text" id="fbRatingText"></div>

            <textarea name="comment" maxlength="1000" placeholder="Tell us about your experience (optional)"></textarea>

            <div class="fb-actions">
                <button type="button" class="fb-skip" id="fbSkip">Later</button>
                <button type="submit" class="fb-send" id="fbSend" disabled>Send Feedback</button>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var modal = document.getElementById('fbModal');
            var send = document.getElementById('fbSend');
            var text = document.getElementById('fbRatingText');
            var words = { 1: 'Poor', 2: 'Fair', 3: 'Good', 4: 'Very good', 5: 'Excellent!' };

            function open(appId, pet) {
                document.getElementById('fbAppId').value = appId;
                document.getElementById('fbPet').textContent = pet;
                modal.querySelectorAll('input[name="rating"]').forEach(function (r) { r.checked = false; });
                modal.querySelector('textarea').value = '';
                text.textContent = '';
                send.disabled = true;
                modal.classList.add('open');
            }
            function close() { modal.classList.remove('open'); }

            modal.querySelectorAll('input[name="rating"]').forEach(function (r) {
                r.addEventListener('change', function () {
                    send.disabled = false;
                    text.textContent = words[r.value];
                });
            });
            document.getElementById('fbSkip').addEventListener('click', close);
            modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

            // "Rate service" para sa hindi pa nakapagbigay ng feedback
            document.querySelectorAll('.btn-rate').forEach(function (b) {
                b.addEventListener('click', function () { open(b.dataset.feedback, b.dataset.pet); });
            });

            // Alisin ang ?feedback=... sa address para hindi bumukas ulit sa refresh
            if (window.history.replaceState && location.search.indexOf('feedback=') !== -1) {
                history.replaceState(null, '', 'docs.php');
            }
        })();
    </script>

    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
