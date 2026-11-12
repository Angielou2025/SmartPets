<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

// Staff at Admin lang ang puwedeng magdagdag ng alaga
if (!in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: pet_inventory.php");
    exit();
}

function add_pet_fail(string $message): void
{
    $_SESSION['error_message'] = $message;
    header("Location: pet_inventory.php");
    exit();
}

$name = trim($_POST['name'] ?? '');
$kind = trim($_POST['kind'] ?? '');
$breed = trim($_POST['breed'] ?? '');
$age_group = trim($_POST['age_group'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$size = trim($_POST['size'] ?? '');
$energy_level = trim($_POST['energy_level'] ?? '');
$temperament = trim($_POST['temperament'] ?? '');
$health_condition = trim($_POST['health_condition'] ?? '');
$vaccination_status = trim($_POST['vaccination_status'] ?? '');
$shedding_level = trim($_POST['shedding_level'] ?? '');
$description = trim($_POST['description'] ?? '');
$status = trim($_POST['status'] ?? 'Available');
$rescue_location = trim($_POST['rescue_location'] ?? '');
$rescue_date = trim($_POST['rescue_date'] ?? '');
$rescue_time = trim($_POST['rescue_time'] ?? '');

// Mga kailangang field (may "required" na sa form, pero sinusuri rin dito)
$required = [$name, $kind, $breed, $age_group, $gender, $size, $energy_level, $temperament, $health_condition, $vaccination_status, $shedding_level];
if (in_array('', $required, true)) {
    add_pet_fail('Please fill in all required fields marked with *.');
}
if (!in_array($kind, ['Dog', 'Cat'], true)) {
    add_pet_fail('Please choose Dog or Cat for the kind.');
}
if (!in_array($status, ['Available', 'Unavailable', 'Adopted'], true)) {
    $status = 'Available';
}
if ($rescue_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rescue_date)) {
    add_pet_fail('Please enter a valid rescue date.');
}
if ($rescue_time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $rescue_time)) {
    add_pet_fail('Please enter a valid rescue time.');
}

// Larawan (opsyonal): totoong larawan lang (JPG, PNG, GIF, WEBP), hanggang 5 MB
$photo_url = '';
if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        add_pet_fail('The photo could not be uploaded. Please try again with a smaller image (up to 5 MB).');
    }
    if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
        add_pet_fail('The photo must be 5 MB or less.');
    }
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    $info = @getimagesize($_FILES['photo']['tmp_name']);
    if (!$info || !isset($types[$info[2]])) {
        add_pet_fail('The photo must be a JPG, PNG, GIF, or WEBP image.');
    }

    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $new_filename = uniqid() . '_' . time() . '.' . $types[$info[2]];
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_filename)) {
        add_pet_fail('The photo could not be saved. Please try again.');
    }
    $photo_url = $new_filename;
}

try {
    $stmt = $pdo->prepare("INSERT INTO pet_inventory (name, kind, breed, age_group, gender, size, energy_level, temperament, shedding_level, health_condition, vaccination_status, description, rescue_location, rescue_date, rescue_time, photo_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $name, $kind, $breed, $age_group, $gender, $size, $energy_level, $temperament,
        $shedding_level,
        $health_condition, $vaccination_status, $description,
        $rescue_location !== '' ? $rescue_location : null,
        $rescue_date !== '' ? $rescue_date : null,
        $rescue_time !== '' ? $rescue_time : null,
        $photo_url, $status,
    ]);
} catch (PDOException $e) {
    error_log('process_add_pet: ' . $e->getMessage());
    add_pet_fail('The pet could not be saved. Please check the details and try again.');
}

$_SESSION['success_message'] = "$name was added to the Pet Inventory.";
log_activity($pdo, "Added new pet record: $name", "Kind: $kind, Breed: $breed, Status: $status");
header("Location: pet_inventory.php");
exit();
