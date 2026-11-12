<?php
// JSON endpoint na tinatawag ng reminder bell kada ilang minuto, para hindi na
// kailangang mag-refresh ng page bago makita ang bagong paalala.
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/_reminders.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

try {
    $reminders = staff_get_reminders($pdo, (int) $_SESSION['user_id']);
    echo json_encode([
        'counts' => staff_reminder_counts($reminders),
        'reminders' => $reminders,
        'generated_at' => date('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load reminders']);
}
