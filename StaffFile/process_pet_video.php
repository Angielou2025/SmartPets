<?php
/**
 * Upload o pagtanggal ng Rescue Story Video ng isang pet (Staff at Admin).
 * POST: pet_id, action = upload | remove, rescue_video (file)
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';
require_once __DIR__ . '/../connection/pet_video.php';

$back = ($_SESSION['role'] ?? '') === 'Admin' ? '../AdminFile/manage_pets.php' : 'pet_inventory.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header('Location: ' . $back);
    exit();
}

$petId = (int) ($_POST['pet_id'] ?? 0);
$st = $pdo->prepare('SELECT name, rescue_video FROM pet_inventory WHERE pet_id = ?');
$st->execute([$petId]);
$pet = $st->fetch(PDO::FETCH_ASSOC);
if (!$pet) {
    $_SESSION['error_message'] = 'Pet not found.';
    header('Location: ' . $back);
    exit();
}

if (($_POST['action'] ?? '') === 'remove') {
    sp_delete_pet_video($pet['rescue_video']);
    $pdo->prepare('UPDATE pet_inventory SET rescue_video = NULL WHERE pet_id = ?')->execute([$petId]);
    log_activity($pdo, 'Removed rescue story video: ' . $pet['name']);
    $_SESSION['success_message'] = 'The rescue story video of ' . $pet['name'] . ' was removed.';
    header('Location: ' . $back);
    exit();
}

$saved = sp_save_pet_video($_FILES['rescue_video'] ?? [], $petId);
if (isset($saved['error'])) {
    $_SESSION['error_message'] = $saved['error'];
    header('Location: ' . $back);
    exit();
}
sp_delete_pet_video($pet['rescue_video']);
$pdo->prepare('UPDATE pet_inventory SET rescue_video = ? WHERE pet_id = ?')->execute([$saved['file'], $petId]);
log_activity($pdo, 'Uploaded rescue story video: ' . $pet['name']);
$_SESSION['success_message'] = 'The rescue story video of ' . $pet['name'] . ' was saved. Adopters will see it in "Why this pet?".';
header('Location: ' . $back);
exit();
