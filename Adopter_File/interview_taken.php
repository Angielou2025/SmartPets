<?php
/**
 * Mga oras ng interview na kinuha na ng ibang adopter (JSON), para sa calendar
 * ng adopter (interview_picker.js) habang bukas ang page.
 * ?exclude=<application_id> - huwag isama ang sariling request ng adopter.
 */
session_start();
require_once '../connection/database.php';
require_once '../connection/interview_schedule.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    http_response_code(403);
    echo json_encode(['error' => 'Not allowed.']);
    exit();
}

// Sariling application lang ang puwedeng hindi isama
$exclude = 0;
if (!empty($_GET['exclude'])) {
    $own = $pdo->prepare("SELECT application_id FROM adoption_applications WHERE application_id = ? AND adopter_id = ?");
    $own->execute([(int) $_GET['exclude'], (int) $_SESSION['user_id']]);
    $exclude = (int) $own->fetchColumn();
}

echo json_encode(['taken' => (object) sp_interview_taken($pdo, $exclude)]);
