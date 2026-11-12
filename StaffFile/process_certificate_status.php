<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: certificates.php');
    exit();
}

$application_id = (int) ($_POST['application_id'] ?? 0);
$action = $_POST['action'] ?? '';
$staff_id = (int) $_SESSION['user_id'];

// Tapos na adoption lang (Approved + Paid) ang may certificate
$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.adopter_id, aa.full_name, aa.cert_ready_at, aa.cert_released_at, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
     WHERE aa.application_id = ? AND (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))"
);
$stmt->execute([$application_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    $_SESSION['error_message'] = 'Certificate not found.';
    header('Location: certificates.php');
    exit();
}

$petName = $app['pet_name'];

if ($action === 'ready' && empty($app['cert_ready_at'])) {
    $pdo->prepare("UPDATE adoption_applications SET cert_ready_at = NOW(), cert_ready_by = ? WHERE application_id = ?")
        ->execute([$staff_id, $application_id]);
    sp_notify(
        $pdo,
        (int) $app['adopter_id'],
        'certificate',
        'Official certificate ready for pickup',
        "Your official Certificate of Adoption and Vaccination Certificate for $petName have been signed by the City Veterinarian and sealed. When you pick up $petName at the City Veterinary Office, you will sign the Pet Adoption Agreement and receive your hard copies. Please bring a valid ID.",
        'docs.php',
        'cert_pickup_' . $application_id
    );
    log_activity($pdo, "Marked certificate as ready for pickup for $petName", "Adopter: {$app['full_name']}");
    $_SESSION['success_message'] = "Certificate for $petName marked as ready for pickup. The adopter has been notified.";
} elseif ($action === 'release' && !empty($app['cert_ready_at']) && empty($app['cert_released_at'])) {
    $pdo->prepare("UPDATE adoption_applications SET cert_released_at = NOW(), cert_released_by = ? WHERE application_id = ?")
        ->execute([$staff_id, $application_id]);
    // Paalala sa adopter na kumpirmahin ("I Received") at mag-iwan ng feedback
    sp_notify(
        $pdo,
        (int) $app['adopter_id'],
        'certificate',
        'Did you receive your certificate?',
        "Our staff marked the official certificate for $petName as handed to you. Please tap \"I Received\" on your Certificate page to confirm, and tell us how we assisted you.",
        'docs.php',
        'cert_given_' . $application_id
    );
    log_activity($pdo, "Gave the official certificate for $petName", "Adopter: {$app['full_name']}");
    // Para sa "Nice job!" na popup sa certificates.php
    $_SESSION['cert_celebrate'] = ['pet' => $petName, 'adopter' => $app['full_name']];
} elseif ($action === 'undo') {
    // Isang hakbang pabalik: Released -> Ready, o Ready -> To Print
    if (!empty($app['cert_released_at'])) {
        $pdo->prepare("UPDATE adoption_applications SET cert_released_at = NULL, cert_released_by = NULL, cert_adopter_received_at = NULL WHERE application_id = ?")
            ->execute([$application_id]);
    } else {
        $pdo->prepare("UPDATE adoption_applications SET cert_ready_at = NULL, cert_ready_by = NULL WHERE application_id = ?")
            ->execute([$application_id]);
    }
    log_activity($pdo, "Reverted certificate status for $petName", "Adopter: {$app['full_name']}");
    $_SESSION['success_message'] = "Certificate status for $petName was reverted.";
}

header('Location: certificates.php');
exit();
