<?php
/**
 * Adopter-initiated online payment (Xendit).
 * Pumipili ang adopter ng paraan ng pagbabayad: GCash, Maya, GrabPay, ShopeePay,
 * o QR Ph (maii-scan ng halos lahat ng bank app). Gumagawa ito ng Xendit charge
 * para sa SARILING approved application ng naka-login na adopter.
 */
session_start();
require_once '../connection/database.php';
require_once __DIR__ . '/../connection/application_flow.php';
require_once '../connection/activity_logger.php';
require_once '../connection/xendit_config.php';
require_once '../connection/xendit_payments.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function pay_fail(string $message): void
{
    $_SESSION['error_message'] = $message;
    header('Location: payments.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);
$channel = strtoupper(trim((string) ($_POST['channel'] ?? 'GCASH')));

if (!xendit_is_configured()) {
    pay_fail('Online payment is not available right now. Please visit the shelter to pay.');
}
if ($application_id <= 0) {
    pay_fail('Invalid payment selected.');
}
if (!isset(XENDIT_METHODS[$channel])) {
    pay_fail('Please choose a payment method.');
}
$methodLabel = XENDIT_METHODS[$channel]['label'];

// MAHALAGA: kailangang sa naka-login na adopter mismo ang application na babayaran,
// para hindi mabayaran ng isang user ang application ng iba.
$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.adopter_id, aa.pet_id, aa.full_name, aa.application_status, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON aa.pet_id = p.pet_id
     WHERE aa.application_id = ? AND aa.adopter_id = ?"
);
$stmt->execute([$application_id, $user_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    pay_fail('That payment does not belong to your account.');
}
if (!sp_app_payable_id($pdo, (int) $application_id)) {
    pay_fail('You can pay the adoption fee after your interview and once you choose a pet meet-up date.');
}

// Kapag na-double click dahil nag-lag, maghihintay ang pangalawa hanggang
// matapos ang una - hindi sila sabay na gagawa ng singil.
if (!sp_xendit_lock_application($pdo, $application_id)) {
    pay_fail('Your payment is still being prepared. Please wait a moment and try again.');
}
register_shutdown_function('sp_xendit_unlock_application', $pdo, $application_id);

// Bago gumawa ng bagong singil, tanungin muna si Xendit tungkol sa LAHAT ng
// naunang singil - baka nabayaran na pala ang isa habang nag-lag.
sp_xendit_sync_application($pdo, $application_id);

// May dati nang record (hal. nagpalit ng paraan ng pagbabayad)
$existing = $pdo->prepare("SELECT payment_id, status, xendit_request_id FROM payments WHERE application_id = ?");
$existing->execute([$application_id]);
$row = $existing->fetch(PDO::FETCH_ASSOC) ?: null;

if ($row && $row['status'] === 'Paid') {
    $_SESSION['success_message'] = 'This adoption fee is already paid.';
    header('Location: payments.php');
    exit();
}

// Kanselahin ang lumang singil para hindi na ito mabayaran kasabay ng bago.
// (Kung nabayaran pa rin ito, ire-refund ito ng system nang awtomatiko.)
sp_xendit_cancel_open_charges($pdo, $application_id);

$amount = 500.00;
$referenceId = 'adoption-' . $application_id . '-' . time();
$description = 'Adoption fee for ' . $application['pet_name'];

// Sa adopter side siya dapat bumalik pagkatapos magbayad, hindi sa staff dashboard
$result = xendit_create_payment_request(
    $referenceId,
    $amount,
    $description,
    XENDIT_BASE_URL . '/Adopter_File/payments.php?xendit=success',
    XENDIT_BASE_URL . '/Adopter_File/payments.php?xendit=failed',
    $channel
);

if (!$result['ok']) {
    pay_fail("We could not start the $methodLabel payment. Please try another payment method or try again later.");
}

$requestId = (string) ($result['data']['payment_request_id'] ?? $result['data']['id'] ?? '');
$checkoutUrl = xendit_extract_checkout_url($result['data']);
$qrString = xendit_extract_qr_string($result['data']);
$status = xendit_map_status((string) ($result['data']['status'] ?? 'PENDING'));

// Itala agad ang singil, para masubaybayan ito kahit mapalitan pa
sp_xendit_record_charge($pdo, $application_id, $requestId, $amount, $channel);

if ($checkoutUrl === '' && $qrString === '') {
    pay_fail("$methodLabel did not return a payment link. Please try another payment method.");
}

try {
    if ($row) {
        // Iisang payment record bawat application - pinapalitan lang ang singil
        $pdo->prepare(
            "UPDATE payments
             SET status = ?, method = ?, xendit_request_id = ?, checkout_url = ?, pay_channel = ?, qr_string = ?, recorded_by = ?
             WHERE payment_id = ?"
        )->execute([$status, 'Xendit ' . $methodLabel, $requestId, $checkoutUrl ?: null, $channel, $qrString ?: null, $user_id, $row['payment_id']]);
        $paymentId = (int) $row['payment_id'];
    } else {
        $pdo->prepare(
            "INSERT INTO payments (application_id, adopter_id, pet_id, amount, status, method, xendit_request_id, checkout_url, pay_channel, qr_string, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $application_id,
            $application['adopter_id'],
            $application['pet_id'],
            $amount,
            $status,
            'Xendit ' . $methodLabel,
            $requestId,
            $checkoutUrl ?: null,
            $channel,
            $qrString ?: null,
            $user_id,
        ]);
        $paymentId = (int) $pdo->lastInsertId();
    }
    log_activity($pdo, "Adopter started $methodLabel payment for application #{$application_id}", "Applicant: {$application['full_name']}, Amount: {$amount}");
} catch (PDOException $e) {
    pay_fail('We could not save your payment. Please contact the shelter.');
}

// Ipakita ang QR code (at ang "pay on this device" link para sa e-wallet)
header('Location: payments.php?pay=' . $paymentId);
exit();
