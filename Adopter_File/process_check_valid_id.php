<?php
/**
 * Sinusuri agad ang Barangay ID (harap o likod) pagkapili ng adopter, bago pa
 * i-submit ang application. JSON ang sagot: {ok, checked, message}.
 */
session_start();
require '../connection/database.php';
require_once __DIR__ . '/../connection/residency_id_check.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'checked' => true, 'message' => 'Please log in again.']);
    exit();
}

$side = ($_POST['side'] ?? '') === 'back' ? 'back' : 'front';
$file = $_FILES['id_image'] ?? null;

if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    echo json_encode(['ok' => false, 'checked' => true, 'message' => 'The photo could not be uploaded. Please try again.']);
    exit();
}
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'checked' => true, 'message' => 'This photo is larger than 10MB. Please upload a smaller photo.']);
    exit();
}

$mime = sp_image_mime($file['tmp_name']);
echo json_encode(sp_check_residency_id((string) file_get_contents($file['tmp_name']), $mime, $side));
