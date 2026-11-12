<?php
/**
 * Maliit na JSON endpoint na tinatanong ni Xendit kung nabayaran na ang isang payment.
 * Tinatawag ito ng QR page tuwing ilang segundo, para kusang lumipat sa "Paid"
 * kapag natapos na ang bayad - hindi na kailangang mag-refresh ng adopter.
 */
session_start();
require_once '../connection/database.php';
require_once '../connection/xendit_config.php';
require_once '../connection/xendit_payments.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$payment_id = (int) ($_GET['payment_id'] ?? 0);

// Sa may-ari lang ng bayad, para hindi matingnan ng iba ang bayad ng iba
$stmt = $pdo->prepare("SELECT payment_id, application_id, status, xendit_request_id FROM payments WHERE payment_id = ? AND adopter_id = ?");
$stmt->execute([$payment_id, $user_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    http_response_code(404);
    echo json_encode(['error' => 'Payment not found']);
    exit();
}

// Tapos na - hindi na kailangang tanungin pa si Xendit
if ($payment['status'] !== 'Pending') {
    echo json_encode(['status' => $payment['status']]);
    exit();
}

if (!xendit_is_configured() || empty($payment['xendit_request_id'])) {
    echo json_encode(['status' => 'Pending']);
    exit();
}

// Lahat ng singil ng application ang tinitingnan (pati ang napalitan na),
// para kung doble ang bayad, ang sobra ay awtomatikong ire-refund
sp_xendit_sync_application($pdo, (int) $payment['application_id']);

$after = $pdo->prepare("SELECT status FROM payments WHERE payment_id = ?");
$after->execute([$payment_id]);

echo json_encode(['status' => (string) $after->fetchColumn()]);
