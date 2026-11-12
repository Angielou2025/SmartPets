<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/xendit_config.php';

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
        return (int) ($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

function safe_sum($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (float) ($row['s'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// Ang naipong pera ay ang mga aktwal na nabayaran lamang (hindi kasama ang Pending)
$total_collected = safe_sum($pdo, "SELECT SUM(amount) AS s FROM payments WHERE status = 'Paid'");
$collected_today = safe_sum($pdo, "SELECT SUM(amount) AS s FROM payments WHERE status = 'Paid' AND DATE(payment_date) = CURDATE()");
$paid_count = safe_count($pdo, "SELECT COUNT(*) AS c FROM payments WHERE status = 'Paid'");
$unpaid_count = safe_count($pdo, "
    SELECT COUNT(*) AS c FROM adoption_applications aa
    WHERE (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
      AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.application_id = aa.application_id AND pay.status = 'Paid')
");

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Naka-on lang ang online GCash button kapag may nakalagay nang Xendit API key
$xenditReady = xendit_is_configured();

// Approved applications lang ang may adoption fee - isama ang payment record kung meron na
$records = [];
try {
    $sql = "SELECT aa.application_id, aa.full_name AS applicant_name, aa.email_address AS email, aa.updated_at AS approved_at,
                   p.name AS pet_name, p.kind AS pet_kind,
                   pay.payment_id, pay.amount, pay.reference_number, pay.payment_date,
                   pay.status AS pay_status, pay.method AS pay_method, pay.checkout_url, pay.xendit_request_id
            FROM adoption_applications aa
            JOIN pet_inventory p ON aa.pet_id = p.pet_id
            LEFT JOIN payments pay ON pay.application_id = aa.application_id
            WHERE (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
            ORDER BY (pay.payment_id IS NULL) DESC, aa.updated_at DESC";
    $stmt = $pdo->query($sql);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $records = [];
}

// Mga dobleng bayad na nire-refund o ni-refund na
$duplicates = [];
try {
    $duplicates = $pdo->query(
        "SELECT c.charge_id, c.amount, c.status, c.refund_error, c.xendit_payment_id, c.updated_at,
                aa.full_name AS applicant_name, p.name AS pet_name
         FROM xendit_charges c
         JOIN adoption_applications aa ON aa.application_id = c.application_id
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE c.status IN ('Refund Pending', 'Refunded', 'Refund Failed')
         ORDER BY (c.status = 'Refund Failed') DESC, c.updated_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $duplicates = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payments - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        .pay-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
            margin-top: 20px;
        }

        .pay-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .pay-search {
            width: 100%;
            max-width: 340px;
            padding: 10px 14px;
            border: 1px solid #e6e1d8;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            margin-bottom: 16px;
        }

        .pay-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            min-width: 820px;
        }

        .pay-table th {
            padding: 12px 10px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #f1f1f1;
        }

        .pay-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f8f8f8;
            vertical-align: middle;
            font-size: 14px;
            color: #102a2a;
        }

        .pay-table tr:hover td { background: #fbfaf7; }

        .pay-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }
        .pay-badge.is-paid { background: #e6f4ea; color: #1e7e34; }
        .pay-badge.is-unpaid { background: #fce8e6; color: #c5221f; }
        .pay-badge.is-pending { background: #fef3d6; color: #b25900; }

        .action-stack {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .btn-record, .btn-gcash, .btn-check {
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.14s ease, transform 0.14s ease;
        }

        .btn-record { background: #fff; color: #1f6b48; border: 1px solid #1f6b48; }
        .btn-record:hover { background: #1f6b48; color: #fff; transform: translateY(-1px); }

        /* Asul ang GCash brand color, kaya iyon ang ginamit para agad makilala */
        .btn-gcash { background: #0070e0; color: #fff; }
        .btn-gcash:hover { background: #005bb8; transform: translateY(-1px); }

        .btn-check { background: #f59c23; color: #fff; }
        .btn-check:hover { background: #e08b18; transform: translateY(-1px); }

        .btn-link-pay {
            font-size: 13px;
            font-weight: 700;
            color: #0070e0;
            text-decoration: none;
        }
        .btn-link-pay:hover { text-decoration: underline; }

        .sub-note { font-size: 12px; color: #9aa6a3; margin-top: 2px; }

        .pay-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .pay-form-grid .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #102a2a;
        }
        .pay-form-grid .form-group input {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            color: #102a2a;
        }

        /* Lumiliit ang malaking halaga para hindi lumampas sa card */
        .staff-dashboard-root .pay-cards .stat-card { min-width: 0; gap: 12px; }
        .staff-dashboard-root .pay-cards .stat-info { min-width: 0; }
        .staff-dashboard-root .pay-cards .stat-card .stat-info h1 {
            font-size: clamp(20px, 2.3vw, 32px);
            line-height: 1.15;
            overflow-wrap: anywhere;
        }
        .staff-dashboard-root .pay-cards .stat-icon { flex-shrink: 0; }

        /* ===================== Bagong itsura (kulay ng staff) ===================== */
        .pay-panel { border: 1px solid #eef0f3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05); scroll-margin-top: 16px; }
        .pay-panel.pt-flash { animation: ptFlash 1s ease; }
        @keyframes ptFlash { 0% { box-shadow: 0 0 0 0 rgba(47, 91, 196, 0.45); } 100% { box-shadow: 0 0 0 14px rgba(47, 91, 196, 0); } }
        .pt-title { display: flex; align-items: center; gap: 12px; }
        .pt-title-icon { width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .pt-title-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
        .pt-title h3 { margin: 0; font-size: 18px; font-weight: 800; color: #0f1b3d; }
        .pt-sub { font-size: 13px; color: #6b7280; }
        .staff-dashboard-root .btn-export-records { border-color: #dbe3f4; color: #1e3a8a; }
        .staff-dashboard-root .btn-export-records:hover { background: #eef2fb; color: #1e3a8a; }

        /* Toolbar */
        .pt-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 4px 0 14px; }
        .pt-search {
            display: flex; align-items: center; gap: 8px; flex: 1 1 280px; max-width: 400px; height: 40px; padding: 0 12px;
            border-radius: 10px; background: #f6f7f9; border: 1.5px solid transparent; color: #9aa3ad;
            transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
        }
        .pt-search:focus-within { background: #fff; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        .pt-search svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
        .pt-search input { flex: 1; min-width: 0; border: none; background: transparent; outline: none; font-family: inherit; font-size: 14px; color: #102a2a; }
        .pt-filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 4px; border-radius: 12px; background: #f3f5f8; }
        .pt-filter {
            display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border: none; border-radius: 9px;
            background: transparent; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 700; color: #5b6472;
            transition: background-color .2s ease, color .2s ease, box-shadow .2s ease;
        }
        .pt-filter span { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #e3e7ee; color: #475569; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; }
        .pt-filter:hover { color: #1e3a8a; }
        .pt-filter.active { background: #fff; color: #1e3a8a; box-shadow: 0 2px 6px rgba(15, 27, 61, 0.1); }
        .pt-filter.active span { background: #1e3a8a; color: #fff; }

        /* Table */
        .pt-table-wrap { overflow-x: auto; border: 1px solid #eef0f3; border-radius: 12px; }
        .pay-table th { background: #f8f9fb; color: #6b7280 !important; font-size: 11.5px !important; letter-spacing: .6px; padding: 12px 14px !important; border-bottom: 1px solid #eef0f3 !important; white-space: nowrap; }
        .pay-table td { padding: 12px 14px !important; border-bottom: 1px solid #f2f4f6 !important; }
        .pay-table tbody tr:last-child td { border-bottom: none !important; }
        .pay-table tr:hover td { background: #f7f9fe !important; }
        .pt-person { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .pt-avatar { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; color: #fff; background: linear-gradient(135deg, #1e40af, #3b6fe0); box-shadow: 0 3px 8px rgba(30, 64, 175, 0.25); }
        .pt-person-text { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; min-width: 0; }
        .pt-person-text strong { color: #0f1b3d; font-size: 14px; }
        .pt-kind { font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
        .pt-kind.is-dog { background: #fff4e5; color: #b45309; }
        .pt-kind.is-cat { background: #f3edff; color: #6d28d9; }
        .pt-date { color: #4b5563; font-size: 13px; white-space: nowrap; }
        .pt-amount { font-weight: 800; color: #0f1b3d; white-space: nowrap; }
        .pt-ref { font-family: 'Consolas', 'SFMono-Regular', monospace; font-size: 12.5px; color: #1e3a8a; background: #eef2fb; padding: 2px 8px; border-radius: 6px; }
        .pt-none { color: #b6bcc6; }
        .pt-empty { text-align: center; padding: 40px 10px !important; color: #9aa3ad; font-size: 14px; }

        /* Status badge na may tuldok */
        .pay-badge { display: inline-flex !important; align-items: center; gap: 6px; padding: 4px 10px 4px 9px !important; }
        .pay-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        /* Mga button sa Action */
        .btn-record, .btn-gcash, .btn-check { height: 34px; padding: 0 12px !important; border-radius: 9px !important; font-family: inherit; }
        .btn-gcash { background: linear-gradient(135deg, #2a62de, #1e3a8a) !important; box-shadow: 0 4px 10px rgba(30, 58, 138, 0.22); }
        .btn-gcash:hover { filter: brightness(1.08); }
        .btn-record { border: 1.5px solid #dbe3f4 !important; color: #1e3a8a !important; background: #fff !important; }
        .btn-record:hover { background: #eef2fb !important; color: #1e3a8a !important; }
        .btn-check { background: #fff7e6 !important; color: #b45309 !important; border: 1.5px solid #f5d999 !important; }
        .btn-check:hover { background: #fdedc6 !important; }
        .btn-link-pay { color: #1e3a8a !important; font-weight: 700; }

        @media (max-width: 560px) {
            .pay-form-grid { grid-template-columns: 1fr; }
        }

        /* Sa makitid na screen, nagiging card ang bawat row ng table */
        @media (max-width: 1100px) {
            .pay-table { min-width: 0; }
            .pay-table thead { display: none; }
            .pay-table, .pay-table tbody, .pay-table tr, .pay-table td { display: block; width: 100%; }
            .pay-table tr {
                border: 1px solid #eee9df;
                border-radius: 12px;
                padding: 6px 14px;
                margin-bottom: 12px;
            }
            .pay-table tr:hover td { background: transparent; }
            .pay-table td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 12px;
                padding: 10px 0;
                text-align: right !important;
                border-bottom: 1px solid #f4f1ea;
            }
            .pay-table tr td:last-child { border-bottom: none; }
            .pay-table td[data-label]::before {
                content: attr(data-label);
                flex-shrink: 0;
                color: #6b7280;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                text-align: left;
            }
            .pay-table td > * { min-width: 0; overflow-wrap: anywhere; }
            .pay-table td[colspan] { display: block; text-align: center !important; }
            /* Naka-block ang bawat hanay dito, kaya tahasang itago ang sinala */
            .pay-table tr[hidden] { display: none !important; }
            .pt-table-wrap { border: none; }
            .action-stack { justify-content: flex-end; }
        }
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
                        <rect class="ic-fill" x="2.5" y="5.5" width="19" height="13" rx="2.5"/>
                        <path d="M2.5 10h19M6.5 15h4"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Payments</h1>
                    <p>Record and monitor GCash adoption fee payments</p>
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

            <?php if (!$xenditReady): ?>
                <div style="background:#fff8e6;border:1px solid #f0d9a8;color:#8a6a2a;padding:14px 16px;border-radius:10px;margin-bottom:16px;font-size:14px;">
                    <strong>Online GCash payment is not active yet.</strong><br>
                    Add your Xendit API key in <code>connection/xendit_config.php</code> to enable the "Pay with GCash" button.
                    You can still record payments manually in the meantime.
                </div>
            <?php endif; ?>

            <?php if (!empty($duplicates)): ?>
                <div style="background:#ffffff;border:1px solid #eaeaea;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                    <strong style="display:block;margin-bottom:4px;">Duplicate payments</strong>
                    <p style="margin:0 0 10px 0;color:#6b7280;font-size:13px;">
                        These adopters were charged more than once for the same adoption. The system kept one payment and refunds the extra automatically.
                    </p>
                    <?php foreach ($duplicates as $d): ?>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px solid #f0f0f0;font-size:14px;">
                            <div>
                                <strong><?php echo htmlspecialchars($d['applicant_name']); ?></strong>
                                &middot; <?php echo htmlspecialchars($d['pet_name']); ?>
                                &middot; &#8369;<?php echo number_format((float) $d['amount'], 2); ?>
                                <?php if ($d['status'] === 'Refund Failed' && !empty($d['refund_error'])): ?>
                                    <div class="sub-note" style="color:#c5221f;"><?php echo htmlspecialchars($d['refund_error']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <?php
                                    $dupColor = ['Refunded' => '#1e7e34', 'Refund Pending' => '#8a6a2a', 'Refund Failed' => '#c5221f'][$d['status']];
                                ?>
                                <span style="font-weight:700;color:<?php echo $dupColor; ?>;"><?php echo htmlspecialchars($d['status']); ?></span>
                                <?php if ($d['status'] === 'Refund Failed' && $xenditReady): ?>
                                    <form method="POST" action="process_retry_refund.php" style="display:inline;">
                                        <input type="hidden" name="charge_id" value="<?php echo (int) $d['charge_id']; ?>">
                                        <button type="submit" class="btn-check">Retry refund</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Stat Cards -->
            <!-- Mga stat card: madilim hanggang maliwanag; sinasala ang table sa ibaba -->
            <div class="cards-grid pay-cards sp-cards">
                <a class="stat-card sp-card sp-c1" href="#payTable" data-pay-filter="paid" title="Show paid adoptions">
                    <div class="stat-info">
                        <h1>&#8369;<?php echo number_format($total_collected, 2); ?></h1>
                        <p>Total Collected</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Bangko -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M3 9.5L12 4l9 5.5z"/>
                            <path d="M3 9.5L12 4l9 5.5M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3.5 20.5h17"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c2" href="#payTable" data-pay-filter="paid" title="Show paid adoptions">
                    <div class="stat-info">
                        <h1>&#8369;<?php echo number_format($collected_today, 2); ?></h1>
                        <p>Collected Today</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Calendar na may piso -->
                        <svg viewBox="0 0 24 24">
                            <rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/>
                            <path d="M3.5 9.5h17M8 3v4M16 3v4"/>
                            <path d="M10.5 12.5h2a1.3 1.3 0 0 1 0 2.6h-2v-2.6zm0 0V18M9.6 13.6h4.4"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c3" href="#payTable" data-pay-filter="paid" title="Show paid adoptions">
                    <div class="stat-info">
                        <h1><?php echo str_pad($paid_count, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Paid Adoptions</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Resibo na may check -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M6 3h12v18l-2-1.4-2 1.4-2-1.4-2 1.4-2-1.4L6 21z"/>
                            <path d="M9 11.5l2 2 4-4"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card sp-card sp-c4" href="#payTable" data-pay-filter="unpaid" title="Show adoptions awaiting payment">
                    <div class="stat-info">
                        <h1><?php echo str_pad($unpaid_count, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Awaiting Payment</p>
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
            </div>

            <!-- Payments Table -->
            <div class="pay-panel" id="payTable">
                <div class="pay-panel-header">
                    <div class="pt-title">
                        <span class="pt-title-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                        </span>
                        <div>
                            <h3>Adoption Fee Payments</h3>
                            <span class="pt-sub">Fixed &#8369;500.00 GCash fee, paid after the pet meet-up and before approval</span>
                        </div>
                    </div>
                    <div>
                        <!-- Inline SVG ang icon dahil walang naka-load na icon
                             font sa page na ito -->
                        <a href="/Caps2/StaffFile/export_report.php?type=payments&range=month" class="btn-export-records">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v10m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                            Export Records
                        </a>
                    </div>
                </div>

                <?php
                // Bilang ayon sa katayuan ng bayad, para sa mga filter (wala sa "All")
                $payCounts = ['paid' => 0, 'pending' => 0, 'unpaid' => 0];
                foreach ($records as $rc) {
                    $k = !empty($rc['payment_id']) ? strtolower($rc['pay_status'] ?? 'paid') : 'unpaid';
                    $k = in_array($k, ['paid', 'pending'], true) ? $k : 'unpaid';
                    $payCounts[$k]++;
                }
                ?>
                <!-- Toolbar: search + filter (sa browser lang, walang reload) -->
                <div class="pt-toolbar">
                    <label class="pt-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
                        <input type="search" id="paySearch" placeholder="Search by adopter, pet, or reference no..." aria-label="Search payments" autocomplete="off">
                    </label>
                    <div class="pt-filters" role="group" aria-label="Filter by payment status">
                        <button type="button" class="pt-filter active" data-filter="all">All</button>
                        <button type="button" class="pt-filter" data-filter="paid">Paid <span><?php echo $payCounts['paid']; ?></span></button>
                        <button type="button" class="pt-filter" data-filter="pending">Pending <span><?php echo $payCounts['pending']; ?></span></button>
                        <button type="button" class="pt-filter" data-filter="unpaid">Unpaid <span><?php echo $payCounts['unpaid']; ?></span></button>
                    </div>
                </div>

                <div class="pt-table-wrap">
                    <table class="pay-table">
                        <thead>
                            <tr>
                                <th>Adopter</th>
                                <th>Pet</th>
                                <th>Ready On</th>
                                <th>Amount</th>
                                <th>Reference No.</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="payBody">
                            <?php if (count($records) > 0): ?>
                                <?php foreach ($records as $r):
                                    $hasRecord = !empty($r['payment_id']);
                                    $payStatus = $hasRecord ? ($r['pay_status'] ?? 'Paid') : 'Unpaid';
                                    $isPaid = $payStatus === 'Paid';
                                    $isPending = $payStatus === 'Pending';
                                    $badgeClass = $isPaid ? 'is-paid' : ($isPending ? 'is-pending' : 'is-unpaid');
                                    $statusKey = $isPaid ? 'paid' : ($isPending ? 'pending' : 'unpaid');
                                    $isCat = ($r['pet_kind'] ?? '') === 'Cat';
                                    $rowSearch = mb_strtolower(implode(' ', [$r['applicant_name'] ?? '', $r['email'] ?? '', $r['pet_name'] ?? '', $r['reference_number'] ?? '']));
                                ?>
                                    <tr class="pt-row" data-status="<?php echo $statusKey; ?>" data-search="<?php echo htmlspecialchars($rowSearch); ?>">
                                        <td data-label="Adopter">
                                            <div class="pt-person">
                                                <span class="pt-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($r['applicant_name'] ?? '?', 0, 1))); ?></span>
                                                <div class="pt-person-text">
                                                    <strong><?php echo htmlspecialchars($r['applicant_name']); ?></strong>
                                                    <span class="sub-note"><?php echo htmlspecialchars($r['email']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Pet">
                                            <div class="pt-person-text">
                                                <strong><?php echo htmlspecialchars($r['pet_name']); ?></strong>
                                                <?php if (!empty($r['pet_kind'])): ?>
                                                    <span class="pt-kind <?php echo $isCat ? 'is-cat' : 'is-dog'; ?>"><?php echo htmlspecialchars($r['pet_kind']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Ready On" class="pt-date"><div><?php echo htmlspecialchars(date('M j, Y', strtotime($r['approved_at']))); ?></div></td>
                                        <td data-label="Amount" class="pt-amount">
                                            <div><?php echo $hasRecord ? '&#8369;' . number_format((float) $r['amount'], 2) : '&#8369;500.00'; ?></div>
                                        </td>
                                        <td data-label="Reference No.">
                                            <div class="pt-person-text">
                                                <?php if (!empty($r['reference_number'])): ?>
                                                    <span class="pt-ref"><?php echo htmlspecialchars($r['reference_number']); ?></span>
                                                <?php else: ?>
                                                    <span class="pt-none">&mdash;</span>
                                                <?php endif; ?>
                                                <?php if ($hasRecord): ?>
                                                    <span class="sub-note"><?php echo htmlspecialchars($r['pay_method'] ?? 'Manual'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Status">
                                            <span class="pay-badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($payStatus); ?>
                                            </span>
                                        </td>
                                        <td data-label="Action" style="text-align:right;">
                                            <?php if ($isPaid): ?>
                                                <span class="sub-note"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['payment_date']))); ?></span>
                                            <?php elseif ($isPending): ?>
                                                <div class="action-stack">
                                                    <?php if (!empty($r['checkout_url'])): ?>
                                                        <a class="btn-link-pay" href="<?php echo htmlspecialchars($r['checkout_url']); ?>" target="_blank" rel="noopener">Open payment link</a>
                                                    <?php endif; ?>
                                                    <form method="POST" action="process_check_xendit_payment.php" style="display:inline;">
                                                        <input type="hidden" name="payment_id" value="<?php echo (int) $r['payment_id']; ?>">
                                                        <button type="submit" class="btn-check">Check Status</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <div class="action-stack">
                                                    <?php if ($xenditReady): ?>
                                                        <form method="POST" action="process_create_xendit_payment.php" style="display:inline;">
                                                            <input type="hidden" name="application_id" value="<?php echo (int) $r['application_id']; ?>">
                                                            <button type="submit" class="btn-gcash">Pay with GCash</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn-record"
                                                            onclick="openPaymentModal(<?php echo (int) $r['application_id']; ?>, '<?php echo htmlspecialchars(addslashes($r['applicant_name'])); ?>', '<?php echo htmlspecialchars(addslashes($r['pet_name'])); ?>')">
                                                        Record Manually
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="pt-empty">No adoptions are ready for payment yet.</td>
                                </tr>
                            <?php endif; ?>
                            <?php if (count($records) > 0): ?>
                                <tr id="payNoResults" hidden>
                                    <td colspan="7" class="pt-empty">No payments found matching your search.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Record Payment Modal -->
<div id="paymentModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:460px;">
        <div class="modal-header">
            <div class="modal-title">Record GCash Payment</div>
            <button type="button" class="btn btn-ghost" onclick="closePaymentModal()" aria-label="Close">✕</button>
        </div>
        <form method="POST" action="process_add_payment.php">
            <div class="modal-body">
                <p class="sub-note" id="paymentModalSubtitle" style="margin:0 0 12px 0;font-size:13px;"></p>
                <input type="hidden" name="application_id" id="payment_application_id">
                <div class="pay-form-grid">
                    <div class="form-group">
                        <label>Amount *</label>
                        <input type="number" name="amount" id="payment_amount" step="0.01" min="0" value="500.00" required>
                    </div>
                    <div class="form-group">
                        <label>GCash Reference Number *</label>
                        <input type="text" name="reference_number" placeholder="e.g. 1234567890123" required>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Search at filter ng table (sa browser lang) + mga card na nagsasala
    (function () {
        const search = document.getElementById('paySearch');
        const rows = Array.from(document.querySelectorAll('#payBody .pt-row'));
        const noResults = document.getElementById('payNoResults');
        const filters = Array.from(document.querySelectorAll('.pt-filter'));
        const panel = document.getElementById('payTable');
        let filter = 'all';

        function apply() {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            rows.forEach(function (r) {
                // "unpaid" sa card = lahat ng hindi pa bayad (Unpaid at Pending)
                const statusOk = filter === 'all' || r.dataset.status === filter || (filter === 'awaiting' && r.dataset.status !== 'paid');
                const ok = statusOk && (!q || r.dataset.search.indexOf(q) !== -1);
                r.hidden = !ok;
                if (ok) shown++;
            });
            if (noResults) noResults.hidden = shown > 0 || !rows.length;
        }
        function setFilter(f) {
            filter = f;
            filters.forEach(function (b) { b.classList.toggle('active', b.dataset.filter === f); });
            apply();
        }

        search.addEventListener('input', apply);
        filters.forEach(function (b) { b.addEventListener('click', function () { setFilter(b.dataset.filter); }); });

        document.querySelectorAll('.sp-card[data-pay-filter]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                search.value = '';
                // Awaiting Payment card: Unpaid at Pending
                setFilter(card.dataset.payFilter === 'unpaid' ? 'awaiting' : card.dataset.payFilter);
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
                panel.classList.remove('pt-flash');
                void panel.offsetWidth; // i-restart ang animation
                panel.classList.add('pt-flash');
            });
        });
    })();

    function openPaymentModal(applicationId, applicantName, petName) {
        document.getElementById('payment_application_id').value = applicationId;
        document.getElementById('paymentModalSubtitle').textContent = applicantName + ' — ' + petName;
        document.getElementById('payment_amount').value = '500.00';
        const modal = document.getElementById('paymentModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closePaymentModal() {
        const modal = document.getElementById('paymentModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePaymentModal();
    });
</script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
