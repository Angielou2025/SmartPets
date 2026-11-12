<?php
/**
 * "Read from Valid ID" button sa certificate page ng staff: binabasa ulit ng
 * AI ang Valid ID ng adopter at pinapalitan ang pangalan, address, at ID
 * details sa certificates (kahit may laman na ang mga ito).
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';
require_once __DIR__ . '/../connection/id_reader.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

$application_id = (int) ($_POST['application_id'] ?? 0);
$back = '/Caps2/Adopter_File/certificate.php?application_id=' . $application_id;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $application_id <= 0) {
    header('Location: certificates.php');
    exit();
}

// Ang may certificate: tapos na ang meet-up (bago ang approval) o Approved na
$check = $pdo->prepare("SELECT full_name FROM adoption_applications WHERE application_id = ? AND (application_status = 'Approved' OR (application_status IN ('Pending', 'Under Review') AND meetup_chosen_at IS NOT NULL AND interview_missed_at IS NULL))");
$check->execute([$application_id]);
$fullName = $check->fetchColumn();

if ($fullName === false) {
    $_SESSION['error_message'] = 'Certificate not found.';
    header('Location: certificates.php');
    exit();
}

$result = sp_read_valid_id($pdo, $application_id, (int) $_SESSION['user_id'], true);
if ($result['ok']) {
    log_activity($pdo, 'Read adoption document details from Valid ID', "Adopter: $fullName");
}
$_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
header('Location: ' . $back);
exit();
