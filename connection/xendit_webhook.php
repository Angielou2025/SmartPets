<?php
/**
 * Xendit webhook receiver.
 *
 * Dito tumatawag si Xendit kapag may nangyari sa isang bayad (payment.capture,
 * payment.failure). Awtomatiko nitong ina-update ang payments table - walang
 * kailangang pindutin ang staff.
 *
 * PAANO I-SETUP (kapag naka-deploy na sa public server):
 *   Xendit Dashboard -> Settings -> Developers -> Webhooks
 *   URL: https://<domain-mo>/Caps2/connection/xendit_webhook.php
 *
 * HABANG LOCALHOST KA PA:
 *   Hindi kayang abutin ni Xendit ang localhost, kaya gamitin muna ang
 *   "Check Status" button sa Payments page. Kung gusto mong subukan ang
 *   webhook nang lokal, pwedeng gumamit ng tunnel tulad ng ngrok.
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/xendit_config.php';
require_once __DIR__ . '/xendit_payments.php';

header('Content-Type: application/json');

// 1. Verify na si Xendit talaga ang tumawag - hindi random na tao sa internet.
$receivedToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';
if (XENDIT_WEBHOOK_TOKEN === '' || !hash_equals(XENDIT_WEBHOOK_TOKEN, $receivedToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid callback token']);
    exit();
}

// 2. Basahin ang payload
$raw = file_get_contents('php://input');
$event = json_decode($raw, true);

if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit();
}

$eventName = (string) ($event['event'] ?? '');
$data = $event['data'] ?? [];

// 3. Hanapin ang payment record gamit ang payment request id
$requestId = (string) ($data['payment_request_id'] ?? $data['reference_id'] ?? '');
if ($requestId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment_request_id']);
    exit();
}

// 4. I-update ang status base sa event
if ($eventName === 'payment.capture') {
    $newStatus = 'Paid';
} elseif ($eventName === 'payment.failure') {
    $newStatus = 'Failed';
} else {
    $newStatus = xendit_map_status((string) ($data['status'] ?? 'PENDING'));
}

$reference = (string) ($data['payment_id'] ?? $data['reference_id'] ?? '');

// Dito rin nalalaman kung doble ang bayad - kung oo, awtomatikong ire-refund
$outcome = sp_xendit_apply_status($pdo, $requestId, $newStatus, $reference);

if ($outcome === 'unknown') {
    // Sagutin pa rin ng 200 para hindi paulit-ulit i-retry ni Xendit ang hindi natin record
    echo json_encode(['status' => 'ignored', 'reason' => 'unknown payment_request_id']);
    exit();
}

echo json_encode(['status' => 'ok', 'payment_status' => $newStatus, 'outcome' => $outcome]);
