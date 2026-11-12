<?php
session_start();
require '../connection/database.php';
require '../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pet_id = (int) ($_POST['pet_id'] ?? 0);

    if ($pet_id <= 0) {
        $_SESSION['error_message'] = "Invalid pet selected.";
        header("Location: manage_pets.php");
        exit();
    }

    // Get form data
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
    $status = trim($_POST['status'] ?? '');
    $rescue_location = trim($_POST['rescue_location'] ?? '');
    $rescue_date = trim($_POST['rescue_date'] ?? '');
    $rescue_time = trim($_POST['rescue_time'] ?? '');

    try {
        $existingStmt = $pdo->prepare("SELECT photo_url FROM pet_inventory WHERE pet_id = ?");
        $existingStmt->execute([$pet_id]);
        $existingPet = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingPet) {
            $_SESSION['error_message'] = "Pet not found.";
            header("Location: manage_pets.php");
            exit();
        }

        $photo_url = $existingPet['photo_url'];

        // Handle optional new photo upload
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
                    // Remove the old photo file now that the new one is saved
                    if (!empty($photo_url) && file_exists($upload_dir . $photo_url)) {
                        unlink($upload_dir . $photo_url);
                    }
                    $photo_url = $new_filename;
                }
            }
        }

        $stmt = $pdo->prepare("UPDATE pet_inventory SET name = ?, kind = ?, breed = ?, age_group = ?, gender = ?, size = ?, energy_level = ?, temperament = ?, shedding_level = ?, health_condition = ?, vaccination_status = ?, description = ?, rescue_location = ?, rescue_date = ?, rescue_time = ?, photo_url = ?, status = ? WHERE pet_id = ?");
        $stmt->execute([
            $name, $kind, $breed, $age_group, $gender, $size, $energy_level, $temperament,
            $shedding_level !== '' ? $shedding_level : null,
            $health_condition, $vaccination_status, $description,
            $rescue_location !== '' ? $rescue_location : null,
            $rescue_date !== '' ? $rescue_date : null,
            $rescue_time !== '' ? $rescue_time : null,
            $photo_url, $status, $pet_id,
        ]);

        $_SESSION['success_message'] = "Pet updated successfully!";
        log_activity($pdo, "Updated pet record: $name", "Pet ID #$pet_id, Kind: $kind, Breed: $breed, Status: $status");
        header("Location: manage_pets.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating pet: " . $e->getMessage();
        header("Location: manage_pets.php");
        exit();
    }
} else {
    header("Location: manage_pets.php");
    exit();
}
