<?php
/**
 * "I Received" sa Certificate page (docs.php): kinukumpirma ng adopter na
 * natanggap na niya ang naka-print na certificate. Puwede lang ito kapag
 * napindot na ng staff ang "Certificate Given" (cert_released_at).
 * Pagkatapos, bubuksan ang feedback (kung paano siya inasikaso ng staff).
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    header('Location: ../Login_Page/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: docs.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);

// Sariling adoption lang ng adopter, at tapos na (Approved + Paid)
$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.full_name, aa.cert_released_at, aa.cert_adopter_received_at, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
     WHERE aa.application_id = ? AND aa.adopter_id = ? AND (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))"
);
$stmt->execute([$application_id, $user_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    $_SESSION['docs_error'] = 'Certificate not found.';
    header('Location: docs.php');
    exit();
}

if (empty($app['cert_released_at'])) {
    $_SESSION['docs_error'] = "You can confirm once our staff has handed you the printed certificate for {$app['pet_name']}.";
    header('Location: docs.php');
    exit();
}

if (empty($app['cert_adopter_received_at'])) {
    $pdo->prepare("UPDATE adoption_applications SET cert_adopter_received_at = NOW() WHERE application_id = ? AND cert_adopter_received_at IS NULL")
        ->execute([$application_id]);
    log_activity($pdo, "Adopter confirmed receiving the certificate for {$app['pet_name']}", "Adopter: {$app['full_name']}");
}

// Buksan ang feedback sa docs.php
header('Location: docs.php?feedback=' . $application_id);
exit();
