<?php
/**
 * Feedback ng adopter kung paano siya inasikaso ng staff sa City Veterinary
 * Office. Puwede lang pagkatapos niyang pindutin ang "I Received". Isa bawat
 * adoption; makikita ito ng staff sa StaffFile/certificates.php.
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
$rating = (int) ($_POST['rating'] ?? 0);
$comment = trim((string) ($_POST['comment'] ?? ''));

if ($rating < 1 || $rating > 5) {
    $_SESSION['docs_error'] = 'Please choose a rating from 1 to 5 stars.';
    header('Location: docs.php?feedback=' . $application_id);
    exit();
}
if (mb_strlen($comment) > 1000) {
    $comment = mb_substr($comment, 0, 1000);
}

// Sariling adoption lang, at kinumpirma na niyang natanggap ang certificate
$stmt = $pdo->prepare(
    "SELECT aa.full_name, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.application_id = ? AND aa.adopter_id = ? AND aa.cert_adopter_received_at IS NOT NULL"
);
$stmt->execute([$application_id, $user_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    $_SESSION['docs_error'] = 'Please confirm that you received your certificate first.';
    header('Location: docs.php');
    exit();
}

// Isa lang bawat adoption - hindi na papalitan kapag nakapagbigay na
$insert = $pdo->prepare(
    "INSERT IGNORE INTO certificate_feedback (application_id, adopter_id, rating, comment) VALUES (?, ?, ?, ?)"
);
$insert->execute([$application_id, $user_id, $rating, $comment !== '' ? $comment : null]);

if ($insert->rowCount() > 0) {
    log_activity($pdo, "Adopter rated the certificate pickup service ($rating/5) for {$app['pet_name']}", "Adopter: {$app['full_name']}");
    $_SESSION['docs_success'] = 'Thank you for your feedback! It helps our staff serve adopters better.';
} else {
    $_SESSION['docs_success'] = 'You already sent feedback for this adoption. Thank you!';
}

header('Location: docs.php');
exit();
