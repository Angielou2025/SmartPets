<?php
/**
 * Direktang tinatanong si Xendit kung nabayaran na ba ang isang payment request.
 *
 * Ito ang paraan na gumagana kahit naka-localhost ka pa lang: ang system mo ang
 * tumatawag palabas kay Xendit, kaya hindi kailangang maabot ni Xendit ang XAMPP mo.
 * (Ang webhook naman ang awtomatikong paraan kapag naka-deploy ka na sa public server.)
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';
require_once __DIR__ . '/../connection/xendit_config.php';
require_once __DIR__ . '/../connection/xendit_payments.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$payment_id = (int) ($_POST['payment_id'] ?? 0);

if (!xendit_is_configured()) {
    $_SESSION['error_message'] = 'Xendit is not set up yet. Add your API key in connection/xendit_config.php first.';
    header('Location: payments.php');
    exit();
}

$stmt = $pdo->prepare("SELECT payment_id, application_id, status, xendit_request_id FROM payments WHERE payment_id = ?");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment || empty($payment['xendit_request_id'])) {
    $_SESSION['error_message'] = 'No Xendit payment found for this record.';
    header('Location: payments.php');
    exit();
}

// Kasama ang lahat ng singil ng application (pati ang mga napalitan na), para
// makita kung doble ang bayad - ang sobra ay awtomatikong ire-refund
$outcomes = sp_xendit_sync_application($pdo, (int) $payment['application_id']);

$after = $pdo->prepare("SELECT status FROM payments WHERE payment_id = ?");
$after->execute([$payment_id]);
$newStatus = (string) $after->fetchColumn();

$messages = [];
if (in_array('paid', $outcomes, true)) {
    $messages[] = 'Payment confirmed by Xendit and marked as Paid.';
    log_activity($pdo, "Confirmed Xendit payment for application #{$payment['application_id']}", "Status: {$newStatus}");
} elseif ($newStatus === 'Paid') {
    $messages[] = 'This payment is already marked as paid.';
}
if (in_array('duplicate_refunded', $outcomes, true)) {
    $messages[] = 'A duplicate payment was detected and refunded to the adopter.';
    log_activity($pdo, "Refunded duplicate Xendit payment for application #{$payment['application_id']}", '');
}

if (in_array('duplicate_refund_failed', $outcomes, true)) {
    $_SESSION['error_message'] = 'A duplicate payment was detected but the automatic refund failed. Use "Retry refund" below.';
} elseif ($newStatus === 'Failed') {
    $_SESSION['error_message'] = 'Xendit reports this payment as failed or expired.';
} elseif ($newStatus === 'Pending') {
    $_SESSION['error_message'] = 'Still waiting for payment.';
}
if ($messages) {
    $_SESSION['success_message'] = implode(' ', $messages);
}

header('Location: payments.php');
exit();
