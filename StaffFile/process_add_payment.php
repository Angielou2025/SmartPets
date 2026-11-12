<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/application_flow.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$recorded_by = $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);
$amount = (float) ($_POST['amount'] ?? 0);
$reference_number = trim($_POST['reference_number'] ?? '');

if ($application_id <= 0 || $amount <= 0 || $reference_number === '') {
    $_SESSION['error_message'] = 'Application, amount, and GCash reference number are all required.';
    header('Location: payments.php');
    exit();
}

$stmt = $pdo->prepare(
    "SELECT application_id, adopter_id, pet_id, full_name, application_status FROM adoption_applications WHERE application_id = ?"
);
$stmt->execute([$application_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    $_SESSION['error_message'] = 'Application not found.';
    header('Location: payments.php');
    exit();
}

if (!sp_app_payable_id($pdo, (int) $application['application_id'])) {
    $_SESSION['error_message'] = SP_NOT_PAYABLE_MSG;
    header('Location: payments.php');
    exit();
}

try {
    $insert = $pdo->prepare(
        "INSERT INTO payments (application_id, adopter_id, pet_id, amount, reference_number, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $insert->execute([
        $application_id,
        $application['adopter_id'],
        $application['pet_id'],
        $amount,
        $reference_number,
        $recorded_by,
    ]);
    sp_notify_payment_paid($pdo, (int) $pdo->lastInsertId());
    $_SESSION['success_message'] = 'Payment recorded for ' . $application['full_name'] . '.';
    log_activity($pdo, "Recorded GCash payment for application #{$application_id}", "Applicant: {$application['full_name']}, Reference: {$reference_number}, Amount: {$amount}");
} catch (PDOException $e) {
    // Duplicate application_id (UNIQUE constraint) - meron nang payment record ito
    $_SESSION['error_message'] = 'This application already has a recorded payment.';
}

header('Location: payments.php');
exit();
