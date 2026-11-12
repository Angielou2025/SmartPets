<?php
/**
 * Listahan ng mga interview slot na bukas pa (Open at hindi pa lumilipas).
 * Tinatawag ng application form at ng reschedule form kada ilang segundo,
 * para maalis agad sa pagpipilian ang oras na na-book na ng ibang adopter
 * habang nagsasagot pa ang user.
 */
session_start();
require '../connection/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['open' => []]);
    exit();
}

$ids = $pdo->query(
    "SELECT slot_id FROM appointment_slots
     WHERE status = 'Open' AND TIMESTAMP(slot_date, end_time) > NOW()"
)->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(['open' => array_map('intval', $ids)]);
