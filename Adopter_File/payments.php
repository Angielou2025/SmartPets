<?php
/**
 * Adopter-facing Payments page.
 * Dito nakikita ng adopter kung magkano ang babayaran niya sa na-approve na adoption,
 * at dito rin siya makakabayad gamit ang GCash (Xendit).
 */
session_start();
require '../connection/database.php';
require_once '../connection/xendit_config.php';
require_once '../connection/xendit_payments.php';

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

$xenditReady = xendit_is_configured();
$adoptionFee = 500.00;

// Kakabalik lang ng adopter galing sa GCash checkout. Tanungin agad si Xendit kung
// natapos na ang bayad, para hindi pa rin "Pending" ang makita niya kahit bayad na.
// (Sa naka-deploy na server, ang webhook ang awtomatikong gumagawa nito.)
if (($_GET['xendit'] ?? '') === 'success' && $xenditReady) {
    try {
        // Lahat ng hindi pa tapos na singil (pati ang napalitan na), para kung
        // nag-lag at dalawang beses nabayaran, maibalik agad ang sobra
        $appStmt = $pdo->prepare(
            "SELECT DISTINCT c.application_id
             FROM xendit_charges c
             JOIN adoption_applications aa ON aa.application_id = c.application_id
             WHERE aa.adopter_id = ? AND c.status IN ('Pending', 'Cancelled', 'Refund Pending')"
        );
        $appStmt->execute([$user_id]);

        foreach ($appStmt->fetchAll(PDO::FETCH_COLUMN) as $appId) {
            $outcomes = sp_xendit_sync_application($pdo, (int) $appId);
            if (in_array('duplicate_refunded', $outcomes, true) || in_array('duplicate_refund_failed', $outcomes, true)) {
                $success_message = 'Your adoption fee is already paid. You were charged twice, so the extra payment is being refunded to you.';
            } elseif (in_array('paid', $outcomes, true)) {
                $success_message = 'Thank you! Your adoption fee payment was received.';
            } elseif (in_array('failed', $outcomes, true)) {
                $error_message = 'Your payment did not go through. Please try again.';
            }
        }
    } catch (Exception $e) {
        // Kung nabigo ang pag-check, may Check Status pa rin ang staff sa kanilang page
    }
} elseif (($_GET['xendit'] ?? '') === 'failed') {
    $error_message = 'Your payment was cancelled or did not go through. You can try again below.';
}

// Ang bayarin ay lumalabas kapag tapos na ang interview at may napiling meet-up (bago ang approval)
$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.updated_at AS approved_at,
            p.name AS pet_name, p.kind AS pet_kind, p.breed AS pet_breed, p.photo_url AS pet_photo,
            pay.payment_id, pay.amount, pay.status AS pay_status, pay.method AS pay_method,
            pay.reference_number, pay.payment_date, pay.checkout_url
     FROM adoption_applications aa
     JOIN pet_inventory p ON aa.pet_id = p.pet_id
     LEFT JOIN payments pay ON pay.application_id = aa.application_id
     WHERE aa.adopter_id = ? AND (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
     ORDER BY aa.updated_at DESC"
);
$stmt->execute([$user_id]);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalDue = 0.0;
$totalPaid = 0.0;
foreach ($bills as $b) {
    if (($b['pay_status'] ?? '') === 'Paid') {
        $totalPaid += (float) $b['amount'];
    } else {
        $totalDue += $adoptionFee;
    }
}

// Table ng bills na may pagination: ang hindi pa bayad (Unpaid/Pending) muna sa
// unahan para hindi matabunan, saka ang mga bayad na (pinakabago muna)
usort($bills, function ($a, $b) {
    $aPaid = ($a['pay_status'] ?? '') === 'Paid' ? 1 : 0;
    $bPaid = ($b['pay_status'] ?? '') === 'Paid' ? 1 : 0;
    return $aPaid <=> $bPaid ?: strcmp((string) $b['approved_at'], (string) $a['approved_at']);
});
const BILLS_PER_PAGE = 5; // ang paglipat ng page ay nasa JavaScript (walang reload)

// Kapag may ?pay=<id>, ipapakita ang QR code para sa bayad na iyon
$showQrFor = null;
if (!empty($_GET['pay'])) {
    $qrStmt = $pdo->prepare(
        "SELECT pay.payment_id, pay.amount, pay.checkout_url, pay.qr_string, pay.pay_channel, pay.status, p.name AS pet_name
         FROM payments pay
         JOIN pet_inventory p ON p.pet_id = pay.pet_id
         WHERE pay.payment_id = ? AND pay.adopter_id = ? AND pay.status = 'Pending'"
    );
    $qrStmt->execute([(int) $_GET['pay'], $user_id]);
    $showQrFor = $qrStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Ano ang laman ng QR: ang QR Ph string mismo (i-scan ng bank app), o ang
// checkout link ng e-wallet (i-scan ng camera para mabuksan ang checkout)
$qrMethod = null;
$qrText = '';
$qrIsBankQr = false;
if ($showQrFor) {
    $channel = $showQrFor['pay_channel'] ?: 'GCASH';
    $qrMethod = XENDIT_METHODS[$channel] ?? XENDIT_METHODS['GCASH'];
    $qrIsBankQr = !empty($showQrFor['qr_string']);
    $qrText = $qrIsBankQr ? $showQrFor['qr_string'] : (string) $showQrFor['checkout_url'];
    if ($qrText === '') {
        $showQrFor = null;
    }
}

// Payment history - lahat ng naitalang bayad ng adopter na ito
$history = [];
try {
    $hStmt = $pdo->prepare(
        "SELECT pay.payment_id, pay.amount, pay.status, pay.method, pay.reference_number, pay.payment_date,
                p.name AS pet_name
         FROM payments pay
         JOIN pet_inventory p ON p.pet_id = pay.pet_id
         WHERE pay.adopter_id = ?
         ORDER BY pay.payment_date DESC"
    );
    $hStmt->execute([$user_id]);
    $history = $hStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $history = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Payments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        .pay-summary-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 26px;
        }
        .pay-sum-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        .pay-sum-card .label {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 6px;
        }
        .pay-sum-card .value { font-size: 28px; font-weight: 900; color: #1b4d2e; }
        .pay-sum-card.due .value { color: #c5221f; }

        /* Total Paid card opens the payment history modal */
        .pay-sum-card.clickable {
            cursor: pointer;
            position: relative;
            border: 1.5px solid transparent;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .pay-sum-card.clickable:hover,
        .pay-sum-card.clickable:focus-visible {
            transform: translateY(-2px);
            border-color: #2e5b32;
            box-shadow: 0 8px 18px rgba(27, 77, 46, 0.18);
            outline: none;
        }
        .pay-sum-card .hint {
            position: absolute;
            top: 18px;
            right: 20px;
            font-size: 12px;
            font-weight: 700;
            color: #2e5b32;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .bill-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .bill-photo {
            width: 74px; height: 74px;
            border-radius: 14px;
            object-fit: cover;
            background: #f1f1f1;
            flex-shrink: 0;
        }
        .bill-info { flex: 1; min-width: 180px; }
        .bill-pet { font-size: 18px; font-weight: 800; color: #1b4d2e; }
        .bill-meta { font-size: 13px; color: #6b7280; margin-top: 2px; }
        .bill-amount { font-size: 22px; font-weight: 900; color: #1b4d2e; }
        .bill-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .bill-badge {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
        }
        .bill-badge.paid { background: #d1fae5; color: #065f46; }
        .bill-badge.unpaid { background: #fee2e2; color: #991b1b; }
        .bill-badge.pending { background: #fef3c7; color: #92400e; }

        /* Your Bills: table na may pagination */
        .bills-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); overflow: hidden; }
        /* Kasya sa lapad ng card - walang scroll sa gilid */
        .bills-wrap { overflow: hidden; }
        .bills-table { width: 100%; border-collapse: collapse; }
        .bills-table .history-ref { word-break: break-all; }
        @media (max-width: 900px) {
            .bills-table .col-approved { display: none; }
        }
        @media (max-width: 700px) {
            .bills-table .col-ref, .bills-table .col-paid { display: none; }
            .bills-table th, .bills-table td { padding: 10px; }
            .bt-actions { white-space: normal; }
        }
        .bills-table th {
            text-align: left; padding: 14px 16px; font-size: 11px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.5px; color: #6b7280; background: #f9fafb; border-bottom: 1px solid #eef0f2; white-space: nowrap;
        }
        .bills-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13.5px; color: #1f2937; vertical-align: middle; }
        .bills-table tbody tr:hover td { background: #fafcfb; }
        .bills-table tr.is-due td { background: #fffdf7; }
        .bt-pet { display: flex; align-items: center; gap: 12px; }
        .bt-pet img, .bt-pet-ph {
            width: 44px; height: 44px; border-radius: 10px; object-fit: cover; flex-shrink: 0;
            background: #f1f1f1; display: flex; align-items: center; justify-content: center; color: #9ca3af;
        }
        .bt-pet-name { font-weight: 800; color: #1b4d2e; font-size: 14.5px; }
        .bt-pet-sub { font-size: 12px; color: #6b7280; margin-top: 1px; }
        .bt-amount { font-weight: 800; color: #1b4d2e; white-space: nowrap; }
        .bt-actions { white-space: nowrap; text-align: right; }
        .bt-actions .btn-continue { margin-right: 6px; }
        .btn-pay-toggle {
            display: inline-flex; align-items: center; gap: 6px; border: none; border-radius: 18px; cursor: pointer;
            padding: 7px 14px; font-size: 12.5px; font-weight: 700; font-family: inherit; color: #fff; background: #2e5b32;
            transition: background-color 0.15s ease;
        }
        .btn-pay-toggle:hover, .btn-pay-toggle[aria-expanded="true"] { background: #1b4d2e; }
        .pay-row td { background: #f8faf9 !important; padding: 16px 18px; }
        .pay-row .pay-methods { border-top: none; padding-top: 0; margin-top: 0; }
        .bills-head { display: flex; align-items: center; justify-content: space-between; gap: 10px 16px; flex-wrap: wrap; margin-bottom: 14px; }
        /* Sticky: dumidikit ang "Your Bills" + pagination habang nag-i-scroll sa mga bill.
           --sp-stick-top = taas ng header sa itaas (galing sa connection/responsive.php) */
        .bills-head {
            position: sticky;
            top: var(--sp-stick-top, 0px);
            z-index: 50;
            padding: 10px 0;
            margin-top: -10px;
            background: #ffffff;
            transition: box-shadow 0.2s ease;
        }
        .bills-head.is-stuck { box-shadow: 0 8px 12px -10px rgba(0, 0, 0, 0.25); }
        /* Telepono: sakop ang buong lapad ng screen. May 12px na padding ang .main-content,
           kaya hinihila palabas ng -12px na margin, saka ibinabalik ang 12px sa loob */
        @media (max-width: 900px) {
            .bills-head { margin-left: -12px; margin-right: -12px; padding-left: 12px; padding-right: 12px; }
        }
        .bills-head h2 { margin: 0; }
        .bills-head-right { display: flex; align-items: center; gap: 12px; }
        .bills-count { font-size: 13px; color: #6b7280; }
        .pager { display: flex; gap: 4px; }
        .pager:empty { display: none; }
        .pager button {
            font-family: inherit; cursor: pointer; transition: background-color 0.15s ease, color 0.15s ease;
        }
        .pager button:disabled { cursor: default; }
        .bill-group[hidden] { display: none; }
        .bill-group.bill-in { animation: billIn 0.3s ease-out; }
        @keyframes billIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (prefers-reduced-motion: reduce) { .bill-group.bill-in { animation: none; } }
        .pager button {
            min-width: 32px; height: 32px; padding: 0 8px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; text-decoration: none; color: #2e5b32; border: 1px solid #dfe6e2; background: #fff;
            box-sizing: border-box;
        }
        .pager button:not(:disabled):not(.current):hover { background: #f0f7f2; }
        .pager .current { background: #2e5b32; border-color: #2e5b32; color: #fff; }
        .pager button:disabled:not(.current) { color: #cbd5e1; border-color: #eef0f2; }

        /* Pagpili ng paraan ng pagbabayad (GCash, Maya, GrabPay, ShopeePay, QR Ph) */
        .bill-card { flex-wrap: wrap; }
        .pay-methods {
            flex-basis: 100%; border-top: 1px dashed #e5e7eb; padding-top: 14px; margin-top: 2px;
        }
        .pay-methods-title { font-size: 13px; font-weight: 800; color: #1b4d2e; margin-bottom: 10px; }
        .pay-method-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; margin-bottom: 12px;
        }
        .pay-method { cursor: pointer; position: relative; }
        .pay-method input { position: absolute; opacity: 0; pointer-events: none; }
        .pay-method span {
            display: flex; flex-direction: column; gap: 2px; height: 100%; box-sizing: border-box;
            border: 1.5px solid #dfe6e2; border-radius: 12px; padding: 10px 12px; background: #fff;
            transition: border-color 0.14s ease, background 0.14s ease;
        }
        .pay-method span i { color: #2e5b32; font-size: 16px; margin-bottom: 2px; }
        .pay-method span b { font-size: 13.5px; color: #111827; }
        .pay-method span small { font-size: 11.5px; color: #6b7280; line-height: 1.35; }
        .pay-method:hover span { border-color: #2e5b32; }
        .pay-method input:checked + span { border-color: #2e5b32; background: #f0f7f2; box-shadow: 0 0 0 2px rgba(46, 91, 50, 0.15); }
        .pay-method input:focus-visible + span { box-shadow: 0 0 0 3px rgba(46, 91, 50, 0.3); }
        .qr-method { margin: 0 0 8px; font-size: 13.5px; color: #374151; }
        @media (max-width: 600px) {
            .pay-method-grid { grid-template-columns: 1fr 1fr; }
            .pay-methods .btn-pay-gcash { width: 100%; }
        }

        .btn-pay-gcash {
            background: #0070e0;
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 10px;
            font-weight: 800;
            font-size: 14px;
            cursor: pointer;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.14s ease, transform 0.14s ease;
        }
        .btn-pay-gcash:hover { background: #005bb8; transform: translateY(-1px); }

        .btn-continue {
            background: #f59c23;
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 10px;
            font-weight: 800;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-continue:hover { background: #e08b18; }

        .pay-note {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.25);
            color: #f3f4f6;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .empty-bills {
            background: #ffffff;
            border-radius: 16px;
            padding: 46px 20px;
            text-align: center;
            color: #6b7280;
        }
        .empty-bills i { font-size: 34px; color: #cbd5e1; display: block; margin-bottom: 12px; }

        /* ===== QR PAYMENT PANEL ===== */
        .qr-panel {
            display: flex;
            gap: 30px;
            background: #ffffff;
            border-radius: 18px;
            padding: 28px 32px;
            margin-bottom: 26px;
            box-shadow: 0 8px 22px rgba(0,0,0,0.16);
            flex-wrap: wrap;
            align-items: center;
        }
        .qr-left { flex: 1; min-width: 260px; }
        .qr-left h2 { margin: 0 0 6px 0; color: #1b4d2e; font-size: 24px; }
        .qr-amount { font-size: 36px; font-weight: 900; color: #0070e0; margin: 0 0 2px 0; }
        .qr-for { color: #6b7280; font-size: 14px; margin: 0 0 16px 0; }

        .qr-steps {
            margin: 0 0 18px 0;
            padding-left: 20px;
            color: #374151;
            font-size: 14px;
            line-height: 1.9;
        }

        .qr-open-btn {
            background: #0070e0;
            color: #fff;
            padding: 11px 22px;
            border-radius: 10px;
            font-weight: 800;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .qr-open-btn:hover { background: #005bb8; }

        .qr-cancel {
            margin-left: 14px;
            color: #9ca3af;
            font-size: 14px;
            text-decoration: none;
        }
        .qr-cancel:hover { text-decoration: underline; }

        .qr-right {
            text-align: center;
            padding: 18px;
            background: #f9fafb;
            border: 2px dashed #d1d5db;
            border-radius: 16px;
        }
        .qr-right #qrcode img, .qr-right #qrcode canvas { display: block; }
        .qr-hint {
            margin: 12px 0 0 0;
            font-size: 12px;
            font-weight: 800;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .qr-code-holder { position: relative; display: inline-block; }

        .qr-expired-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.94);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 8px;
            color: #991b1b;
            font-weight: 800;
            font-size: 14px;
        }
        .qr-expired-overlay i { font-size: 30px; }
        .qr-expired-overlay[hidden] { display: none; }

        .qr-timer {
            margin: 8px 0 0 0;
            font-size: 13px;
            color: #6b7280;
        }
        .qr-timer.urgent { color: #c5221f; }

        .qr-regen {
            display: inline-block;
            margin-top: 10px;
            background: #1b4d2e;
            color: #fff;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }
        .qr-regen:hover { background: #143a22; }
        .qr-regen[hidden] { display: none; }

        .qr-panel.is-expired .qr-open-btn {
            opacity: 0.45;
            pointer-events: none;
        }

        /* ===== PAYMENT HISTORY (modal) ===== */
        .history-modal {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 36, 22, 0.6);
            backdrop-filter: blur(3px);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }
        .history-modal.open { opacity: 1; visibility: visible; }
        .history-dialog {
            background: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-width: 900px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            transform: translateY(16px) scale(0.98);
            transition: transform 0.22s cubic-bezier(0.34, 1.3, 0.64, 1);
        }
        .history-modal.open .history-dialog { transform: translateY(0) scale(1); }
        .history-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 22px;
            border-bottom: 1px solid #eef0f2;
        }
        .history-head h2 { margin: 0; font-size: 20px; color: #1b4d2e; }
        .history-head .sub { font-size: 13px; color: #6b7280; margin-top: 2px; }
        .history-close {
            width: 36px; height: 36px;
            border: none;
            border-radius: 50%;
            background: #f3f4f6;
            color: #374151;
            font-size: 16px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .history-close:hover { background: #e5e7eb; }
        .history-body { padding: 6px 4px 10px; overflow: auto; }
        .history-wrap { overflow-x: auto; }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 640px;
        }
        .history-table th {
            text-align: left;
            padding: 14px 16px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            border-bottom: 2px solid #f3f4f6;
        }
        .history-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f6f7f8;
            font-size: 14px;
            color: #1f2937;
        }
        .history-table tr:last-child td { border-bottom: none; }
        .history-time { font-size: 12px; color: #9ca3af; margin-top: 2px; }
        .history-ref {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12.5px;
            color: #4b5563;
        }

        @media (max-width: 700px) {
            .pay-summary-row { grid-template-columns: 1fr; }
            .qr-panel { justify-content: center; }
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
                <a href="payments.php" class="nav-item active"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
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
                <p>Your adoption fee payments.</p>
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

            <?php if ($showQrFor): ?>
                <!-- QR CODE PANEL: i-scan gamit ang GCash app para magbayad -->
                <section class="qr-panel">
                    <div class="qr-left">
                        <h2>Scan to Pay</h2>
                        <p class="qr-amount">&#8369;<?php echo number_format((float) $showQrFor['amount'], 2); ?></p>
                        <p class="qr-for">Adoption fee for <strong><?php echo htmlspecialchars($showQrFor['pet_name']); ?></strong></p>

                        <p class="qr-method">Paying with <strong><?php echo htmlspecialchars($qrMethod['label']); ?></strong></p>

                        <?php if ($qrIsBankQr): ?>
                        <ol class="qr-steps">
                            <li>Open your <strong>bank or e-wallet app</strong> (BDO, BPI, Landbank, UnionBank, Metrobank, GCash, Maya, and more)</li>
                            <li>Choose <strong>Scan QR</strong> or <strong>QR Ph</strong> and scan this code</li>
                            <li>Check the amount, then <strong>confirm the payment</strong></li>
                            <li>Come back here &mdash; your payment updates automatically</li>
                        </ol>
                        <?php else: ?>
                        <ol class="qr-steps">
                            <li>Open your phone's <strong>camera</strong> and point it at this code</li>
                            <li>Tap the link that appears to open the payment page</li>
                            <li>Pay with <strong><?php echo htmlspecialchars($qrMethod['label']); ?></strong>, check the amount, then <strong>confirm and send</strong></li>
                            <li>Come back here &mdash; your payment updates automatically</li>
                        </ol>

                        <a class="qr-open-btn" href="<?php echo htmlspecialchars($showQrFor['checkout_url']); ?>">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Or pay on this device
                        </a>
                        <?php endif; ?>
                        <a class="qr-cancel" href="payments.php">Cancel or choose another way to pay</a>
                    </div>

                    <div class="qr-right">
                        <div class="qr-code-holder">
                            <div id="qrcode"></div>
                            <div id="qrExpiredOverlay" class="qr-expired-overlay" hidden>
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                <span>QR expired</span>
                            </div>
                        </div>
                        <p class="qr-hint"><?php echo $qrIsBankQr ? 'Scan with your bank or e-wallet app' : 'Scan with your camera'; ?></p>
                        <p class="qr-timer" id="qrTimer">Expires in <strong><?php echo $qrIsBankQr ? '15:00' : '3:00'; ?></strong></p>
                        <a class="qr-regen" id="qrRegen" href="payments.php" hidden>Get a new QR code</a>
                    </div>
                </section>
            <?php endif; ?>

            <section class="pets-section">
                <div class="pay-summary-row">
                    <div class="pay-sum-card due">
                        <div class="label">Total Due</div>
                        <div class="value">&#8369;<?php echo number_format($totalDue, 2); ?></div>
                    </div>
                    <div class="pay-sum-card clickable" id="totalPaidCard" role="button" tabindex="0" title="View payment history">
                        <div class="label">Total Paid</div>
                        <div class="value">&#8369;<?php echo number_format($totalPaid, 2); ?></div>
                        <span class="hint"><i class="fa-solid fa-clock-rotate-left"></i> View history</span>
                    </div>
                </div>

                <?php if (!$xenditReady && count($bills) > 0): ?>
                    <div class="pay-note">
                        <i class="fa-solid fa-circle-info"></i>
                        Online payment is not available yet. Please visit the shelter to pay your adoption fee,
                        and our staff will record it for you.
                    </div>
                <?php endif; ?>

                <!-- Pamagat sa kaliwa, pagination sa kanan (binubuo ng JS kapag lampas sa isang page) -->
                <div class="bills-head">
                    <h2>Your Bills</h2>
                    <div class="bills-head-right">
                        <span class="bills-count" id="billsCount"></span>
                        <nav class="pager" id="billsPager" aria-label="Bills pages"></nav>
                    </div>
                </div>

                <?php if (count($bills) > 0): ?>
                    <div class="bills-card">
                        <div class="bills-wrap">
                            <table class="bills-table">
                                <thead>
                                    <tr>
                                        <th>Pet</th>
                                        <th class="col-approved">Ready On</th>
                                        <th>Amount</th>
                                        <th class="col-ref">Reference No.</th>
                                        <th class="col-paid">Paid On</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <?php foreach ($bills as $billIndex => $b):
                                        $status = $b['pay_status'] ?? 'Unpaid';
                                        if (empty($b['payment_id'])) { $status = 'Unpaid'; }
                                        $isPaid = $status === 'Paid';
                                        $isPending = $status === 'Pending';
                                        $badge = $isPaid ? 'paid' : ($isPending ? 'pending' : 'unpaid');
                                        $appId = (int) $b['application_id'];
                                    ?>
                                    <!-- Isang bill = isang tbody (kasama ang pagpili ng bayad), para sabay itago sa pagination -->
                                    <tbody class="bill-group" data-page="<?php echo intdiv($billIndex, BILLS_PER_PAGE) + 1; ?>">
                                        <tr class="<?php echo $isPaid ? '' : 'is-due'; ?>">
                                            <td>
                                                <div class="bt-pet">
                                                    <?php if (!empty($b['pet_photo'])): ?>
                                                        <img src="../uploads/<?php echo htmlspecialchars($b['pet_photo']); ?>" alt="<?php echo htmlspecialchars($b['pet_name']); ?>">
                                                    <?php else: ?>
                                                        <span class="bt-pet-ph"><i class="fa-solid fa-paw"></i></span>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="bt-pet-name"><?php echo htmlspecialchars($b['pet_name']); ?></div>
                                                        <div class="bt-pet-sub"><?php echo htmlspecialchars($b['pet_kind'] . ' · ' . $b['pet_breed']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="col-approved"><?php echo htmlspecialchars(date('M j, Y', strtotime($b['approved_at']))); ?></td>
                                            <td class="bt-amount">&#8369;<?php echo number_format($isPaid ? (float) $b['amount'] : $adoptionFee, 2); ?></td>
                                            <td class="history-ref col-ref"><?php echo $isPaid && !empty($b['reference_number']) ? htmlspecialchars($b['reference_number']) : '&mdash;'; ?></td>
                                            <td class="col-paid">
                                                <?php if ($isPaid && !empty($b['payment_date'])): ?>
                                                    <?php echo htmlspecialchars(date('M j, Y', strtotime($b['payment_date']))); ?>
                                                    <div class="history-time"><?php echo htmlspecialchars(date('g:i A', strtotime($b['payment_date']))); ?></div>
                                                <?php else: ?>
                                                    &mdash;
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="bill-badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                                            <td class="bt-actions">
                                                <?php if ($isPending && !empty($b['payment_id'])): ?>
                                                    <a class="btn-continue" href="payments.php?pay=<?php echo (int) $b['payment_id']; ?>">
                                                        <i class="fa-solid fa-qrcode"></i> Continue
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!$isPaid && $xenditReady): ?>
                                                    <button type="button" class="btn-pay-toggle" data-target="pay-row-<?php echo $appId; ?>" aria-expanded="false">
                                                        <i class="fa-solid fa-wallet"></i> <?php echo $isPending ? 'Other way' : 'Pay now'; ?>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if (!$isPaid && $xenditReady): ?>
                                            <!-- Pagpili ng paraan ng pagbabayad (bumubukas sa "Pay now") -->
                                            <tr class="pay-row" id="pay-row-<?php echo $appId; ?>" hidden>
                                                <td colspan="7">
                                                    <form method="POST" action="process_pay_now.php" class="pay-methods">
                                                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                                                        <div class="pay-methods-title"><?php echo $isPending ? 'Or choose another way to pay' : 'Choose how you want to pay'; ?></div>
                                                        <div class="pay-method-grid">
                                                            <?php foreach (XENDIT_METHODS as $code => $m): ?>
                                                                <label class="pay-method">
                                                                    <input type="radio" name="channel" value="<?php echo htmlspecialchars($code); ?>" required<?php echo $code === 'GCASH' && !$isPending ? ' checked' : ''; ?>>
                                                                    <span>
                                                                        <i class="fa-solid <?php echo $m['type'] === 'qr' ? 'fa-building-columns' : 'fa-wallet'; ?>"></i>
                                                                        <b><?php echo htmlspecialchars($m['label']); ?></b>
                                                                        <small><?php echo htmlspecialchars($m['hint']); ?></small>
                                                                    </span>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <button type="submit" class="btn-pay-gcash">
                                                            <i class="fa-solid fa-lock"></i> Pay &#8369;<?php echo number_format($adoptionFee, 2); ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-bills">
                        <i class="fa-solid fa-receipt"></i>
                        <p>You have no adoption fees to pay right now.</p>
                        <p style="font-size:13px;">A fee appears here after your interview, once you choose a pet meet-up date.</p>
                    </div>
                <?php endif; ?>

            </section>
        </main>
    </div>

    <!-- PAYMENT HISTORY: lumalabas lang kapag na-click ang Total Paid card -->
    <div class="history-modal" id="historyModal" aria-hidden="true">
        <div class="history-dialog" role="dialog" aria-modal="true" aria-labelledby="historyTitle">
            <div class="history-head">
                <div>
                    <h2 id="historyTitle">Payment History</h2>
                    <div class="sub">Total paid: <strong>&#8369;<?php echo number_format($totalPaid, 2); ?></strong></div>
                </div>
                <button type="button" class="history-close" id="historyClose" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="history-body">
                <?php if (count($history) > 0): ?>
                    <div class="history-wrap">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Pet</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference No.</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $h):
                                    $hb = $h['status'] === 'Paid' ? 'paid' : ($h['status'] === 'Pending' ? 'pending' : 'unpaid');
                                ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars(date('M j, Y', strtotime($h['payment_date']))); ?>
                                            <div class="history-time"><?php echo htmlspecialchars(date('g:i A', strtotime($h['payment_date']))); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($h['pet_name']); ?></td>
                                        <td><strong>&#8369;<?php echo number_format((float) $h['amount'], 2); ?></strong></td>
                                        <td><?php echo htmlspecialchars($h['method']); ?></td>
                                        <td class="history-ref"><?php echo !empty($h['reference_number']) ? htmlspecialchars($h['reference_number']) : '&mdash;'; ?></td>
                                        <td><span class="bill-badge <?php echo $hb; ?>"><?php echo htmlspecialchars($h['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-bills" style="box-shadow:none;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <p>No payments yet.</p>
                        <p style="font-size:13px;">Your completed payments will be listed here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Pagination ng Your Bills: walang reload, may maikling fade ang mga row
        (function () {
            const groups = Array.prototype.slice.call(document.querySelectorAll('.bill-group'));
            const pager = document.getElementById('billsPager');
            const count = document.getElementById('billsCount');
            if (!groups.length || !pager) return;
            const PER = <?php echo (int) BILLS_PER_PAGE; ?>;
            const pages = Math.ceil(groups.length / PER);
            let current = 1;

            function btn(html, page, opts) {
                const b = document.createElement('button');
                b.type = 'button';
                b.innerHTML = html;
                if (opts && opts.label) b.setAttribute('aria-label', opts.label);
                if (opts && opts.current) { b.className = 'current'; b.setAttribute('aria-current', 'page'); }
                b.disabled = !!(opts && (opts.disabled || opts.current));
                b.addEventListener('click', function () { show(page, true); toListTop(); });
                return b;
            }

            function show(page, animate) {
                current = Math.min(pages, Math.max(1, page));
                groups.forEach(function (g) {
                    const on = parseInt(g.dataset.page, 10) === current;
                    g.hidden = !on;
                    g.classList.remove('bill-in');
                    if (on && animate) { void g.offsetWidth; g.classList.add('bill-in'); }
                });
                const from = (current - 1) * PER + 1;
                const to = Math.min(groups.length, current * PER);
                count.textContent = 'Showing ' + from + '–' + to + ' of ' + groups.length;

                pager.innerHTML = '';
                if (pages <= 1) return;
                pager.appendChild(btn('<i class="fa-solid fa-chevron-left"></i>', current - 1, { label: 'Previous page', disabled: current === 1 }));
                for (let p = 1; p <= pages; p++) pager.appendChild(btn(String(p), p, { current: p === current }));
                pager.appendChild(btn('<i class="fa-solid fa-chevron-right"></i>', current + 1, { label: 'Next page', disabled: current === pages }));
            }

            // Paglipat ng page habang nasa ibaba: umakyat nang smooth sa simula ng listahan
            // (hindi natatakpan ng sticky na header ang unang bill)
            const head = document.querySelector('.bills-head');
            const card = document.querySelector('.bills-card');
            function toListTop() {
                const stick = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sp-stick-top')) || 0;
                // Ang card ang sukatan (gumagalaw ang puwesto ng header kapag nakadikit)
                const y = card.getBoundingClientRect().top + window.scrollY - stick - head.offsetHeight - 6;
                if (window.scrollY > y) window.scrollTo({ top: y, behavior: 'smooth' });
            }

            // May anino kapag nakadikit na ang header
            function markStuck() {
                const stick = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sp-stick-top')) || 0;
                head.classList.toggle('is-stuck', head.getBoundingClientRect().top <= stick + 0.5 && window.scrollY > 0);
            }
            window.addEventListener('scroll', markStuck, { passive: true });

            show(1, false);
        })();

        // "Pay now": bubukas sa ilalim ng row ang pagpili ng paraan ng pagbabayad
        document.querySelectorAll('.btn-pay-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const row = document.getElementById(btn.dataset.target);
                if (!row) return;
                row.hidden = !row.hidden;
                btn.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
            });
        });

        (function () {
            const card  = document.getElementById('totalPaidCard');
            const modal = document.getElementById('historyModal');
            const close = document.getElementById('historyClose');

            function openHistory() {
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                close.focus();
            }
            function closeHistory() {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                card.focus();
            }

            card.addEventListener('click', openHistory);
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openHistory(); }
            });
            close.addEventListener('click', closeHistory);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) { closeHistory(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('open')) { closeHistory(); }
            });
        })();
    </script>

    <?php if ($showQrFor): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            // E-wallet: ang QR ay ang checkout link (bubuksan ng camera ng phone).
            // QR Ph: ang QR ay ang mismong QR Ph code na ini-scan ng bank app.
            new QRCode(document.getElementById('qrcode'), {
                text: <?php echo json_encode($qrText); ?>,
                width: 210,
                height: 210,
                colorDark: '#1b4d2e',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });

            (function () {
                const paymentId = <?php echo (int) $showQrFor['payment_id']; ?>;
                // 3 minuto para sa e-wallet link; mas matagal sa QR Ph dahil kailangan pang buksan ang bank app
                let secondsLeft = <?php echo $qrIsBankQr ? 900 : 180; ?>;
                let pollTimer = null;

                const timerEl   = document.getElementById('qrTimer');
                const overlayEl = document.getElementById('qrExpiredOverlay');
                const regenEl   = document.getElementById('qrRegen');
                const panelEl   = document.querySelector('.qr-panel');

                function render() {
                    const m = Math.floor(secondsLeft / 60);
                    const s = String(secondsLeft % 60).padStart(2, '0');
                    timerEl.innerHTML = 'Expires in <strong>' + m + ':' + s + '</strong>';
                    // Pulahan ang timer sa huling 30 segundo bilang babala
                    timerEl.classList.toggle('urgent', secondsLeft <= 30);
                }

                function expire() {
                    clearInterval(countdown);
                    if (pollTimer) clearInterval(pollTimer);
                    overlayEl.hidden = false;
                    regenEl.hidden = false;
                    timerEl.innerHTML = '<strong>This QR code has expired.</strong>';
                    timerEl.classList.add('urgent');
                    if (panelEl) panelEl.classList.add('is-expired');
                }

                const countdown = setInterval(function () {
                    secondsLeft--;
                    if (secondsLeft <= 0) { expire(); return; }
                    render();
                }, 1000);

                // Tanungin si Xendit kada 5 segundo kung bayad na, para hindi na
                // kailangang maghintay ng adopter hanggang matapos ang 3 minuto.
                pollTimer = setInterval(function () {
                    fetch('check_payment_status.php?payment_id=' + paymentId, { cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.status === 'Paid') {
                                window.location.href = 'payments.php?xendit=success';
                            } else if (data.status === 'Failed') {
                                window.location.href = 'payments.php?xendit=failed';
                            }
                        })
                        .catch(function () { /* patuloy lang ang pag-check */ });
                }, 5000);

                render();
            })();
        </script>
    <?php endif; ?>

    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
