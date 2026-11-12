<?php
session_start();
require '../connection/database.php';
require '../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $name = trim($_POST['name']);
    $kind = trim($_POST['kind']);
    $breed = trim($_POST['breed']);
    $age_group = trim($_POST['age_group']);
    $gender = trim($_POST['gender']);
    $size = trim($_POST['size']);
    $energy_level = trim($_POST['energy_level']);
    $temperament = trim($_POST['temperament']);
    $health_condition = trim($_POST['health_condition']);
    $vaccination_status = trim($_POST['vaccination_status']);
    $shedding_level = trim($_POST['shedding_level'] ?? '');
    $description = trim($_POST['description']);
    $status = trim($_POST['status']);
    $rescue_location = trim($_POST['rescue_location'] ?? '');
    $rescue_date = trim($_POST['rescue_date'] ?? '');
    $rescue_time = trim($_POST['rescue_time'] ?? '');

    // Handle photo upload
    $photo_url = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $new_filename = uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                $photo_url = $new_filename;
            }
        }
    }
    
    // Insert into database
    try {
        $stmt = $pdo->prepare("INSERT INTO pet_inventory (name, kind, breed, age_group, gender, size, energy_level, temperament, shedding_level, health_condition, vaccination_status, description, rescue_location, rescue_date, rescue_time, photo_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $name, $kind, $breed, $age_group, $gender, $size, $energy_level, $temperament,
            $shedding_level !== '' ? $shedding_level : null,
            $health_condition, $vaccination_status, $description,
            $rescue_location !== '' ? $rescue_location : null,
            $rescue_date !== '' ? $rescue_date : null,
            $rescue_time !== '' ? $rescue_time : null,
            $photo_url, $status,
        ]);
        
        $_SESSION['success_message'] = "Pet added successfully!";
        log_activity($pdo, "Added new pet record: $name", "Kind: $kind, Breed: $breed, Status: $status");
        header("Location: manage_pets.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding pet: " . $e->getMessage();
        header("Location: manage_pets.php");
        exit();
    }
} else {
    header("Location: manage_pets.php");
    exit();
}
?>
