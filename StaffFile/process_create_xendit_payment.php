<?php
/**
 * Gumagawa ng online GCash charge sa Xendit para sa isang approved na adoption.
 * Nag-iiwan ito ng "Pending" na payment row na may checkout link; magiging "Paid"
 * ito kapag nakumpirma na ang bayad (sa pamamagitan ng webhook o Check Status).
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/application_flow.php';
require_once __DIR__ . '/../connection/activity_logger.php';
require_once __DIR__ . '/../connection/xendit_config.php';
require_once __DIR__ . '/../connection/xendit_payments.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$recorded_by = $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);

if (!xendit_is_configured()) {
    $_SESSION['error_message'] = 'Xendit is not set up yet. Add your API key in connection/xendit_config.php first.';
    header('Location: payments.php');
    exit();
}

if ($application_id <= 0) {
    $_SESSION['error_message'] = 'Invalid application selected.';
    header('Location: payments.php');
    exit();
}

$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.adopter_id, aa.pet_id, aa.full_name, aa.application_status, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON aa.pet_id = p.pet_id
     WHERE aa.application_id = ?"
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

// Kapag na-double click dahil nag-lag, maghihintay ang pangalawa hanggang matapos ang una
if (!sp_xendit_lock_application($pdo, $application_id)) {
    $_SESSION['error_message'] = 'A payment link is still being created for this adoption. Please wait a moment.';
    header('Location: payments.php');
    exit();
}
register_shutdown_function('sp_xendit_unlock_application', $pdo, $application_id);

// Iwasan ang dobleng singil sa iisang adoption
$existing = $pdo->prepare("SELECT payment_id, status FROM payments WHERE application_id = ?");
$existing->execute([$application_id]);
if ($row = $existing->fetch(PDO::FETCH_ASSOC)) {
    $_SESSION['error_message'] = $row['status'] === 'Paid'
        ? 'This adoption is already paid.'
        : 'A payment link was already created for this adoption. Use Check Status instead.';
    header('Location: payments.php');
    exit();
}

$amount = 500.00;
// Ang reference_id ay kailangang unique kada singil, kaya isinama ang timestamp
$referenceId = 'adoption-' . $application_id . '-' . time();
$description = 'Adoption fee for ' . $application['pet_name'];

// Staff ang gumawa ng link, kaya sa staff payments page ang balikan
$result = xendit_create_payment_request(
    $referenceId,
    $amount,
    $description,
    XENDIT_BASE_URL . '/StaffFile/payments.php?xendit=success',
    XENDIT_BASE_URL . '/StaffFile/payments.php?xendit=failed'
);

if (!$result['ok']) {
    $_SESSION['error_message'] = 'Xendit error: ' . $result['error'];
    header('Location: payments.php');
    exit();
}

$requestId = (string) ($result['data']['payment_request_id'] ?? $result['data']['id'] ?? '');
$checkoutUrl = xendit_extract_checkout_url($result['data']);
$status = xendit_map_status((string) ($result['data']['status'] ?? 'PENDING'));

// Itala agad ang singil, para masubaybayan ito kahit mapalitan pa
sp_xendit_record_charge($pdo, $application_id, $requestId, $amount, 'GCASH');

try {
    $insert = $pdo->prepare(
        "INSERT INTO payments (application_id, adopter_id, pet_id, amount, status, method, xendit_request_id, checkout_url, recorded_by)
         VALUES (?, ?, ?, ?, ?, 'Xendit GCash', ?, ?, ?)"
    );
    $insert->execute([
        $application_id,
        $application['adopter_id'],
        $application['pet_id'],
        $amount,
        $status,
        $requestId,
        $checkoutUrl,
        $recorded_by,
    ]);

    $_SESSION['success_message'] = 'GCash payment link created for ' . $application['full_name'] . '. Send the link to the adopter, then use Check Status once they have paid.';
    log_activity($pdo, "Created Xendit GCash charge for application #{$application_id}", "Applicant: {$application['full_name']}, Amount: {$amount}, Reference: {$referenceId}");
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Payment link was created at Xendit but could not be saved locally.';
}

header('Location: payments.php');
exit();
