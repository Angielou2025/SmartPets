<?php
/**
 * Inuulit ang refund ng dobleng bayad kapag nabigo ang awtomatikong refund
 * (hal. hindi naabot si Xendit noong oras na iyon).
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

$charge_id = (int) ($_POST['charge_id'] ?? 0);

if ($charge_id > 0 && sp_xendit_refund_charge($pdo, $charge_id)) {
    $_SESSION['success_message'] = 'Refund sent to Xendit. The adopter has been notified.';
    log_activity($pdo, "Retried refund for duplicate Xendit charge #{$charge_id}", '');
} else {
    $err = $pdo->prepare("SELECT refund_error FROM xendit_charges WHERE charge_id = ?");
    $err->execute([$charge_id]);
    $_SESSION['error_message'] = 'Refund failed: ' . ((string) $err->fetchColumn() ?: 'charge not found or not refundable.');
}

header('Location: payments.php');
exit();
