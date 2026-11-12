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

    try {
        $petStmt = $pdo->prepare("SELECT name, photo_url FROM pet_inventory WHERE pet_id = ?");
        $petStmt->execute([$pet_id]);
        $pet = $petStmt->fetch(PDO::FETCH_ASSOC);

        if (!$pet) {
            $_SESSION['error_message'] = "Pet not found.";
            header("Location: manage_pets.php");
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM pet_inventory WHERE pet_id = ?");
        $stmt->execute([$pet_id]);

        // Clean up the photo file only after the database row is gone
        if (!empty($pet['photo_url'])) {
            $photo_path = '../uploads/' . $pet['photo_url'];
            if (file_exists($photo_path)) {
                unlink($photo_path);
            }
        }

        $_SESSION['success_message'] = "Pet \"" . htmlspecialchars($pet['name']) . "\" was deleted successfully.";
        log_activity($pdo, "Deleted pet record: {$pet['name']}", "Pet ID #$pet_id");
    } catch (PDOException $e) {
        // FK constraint (e.g. existing adoption applications linked to this pet) blocks deletion
        if ($e->getCode() === '23000
        ') {
            $_SESSION['error_message'] = "Cannot delete this pet because it has related adoption records. Set its status to Unavailable instead.";
        } else {
            $_SESSION['error_message'] = "Error deleting pet: " . $e->getMessage();
        }
    }

    header("Location: manage_pets.php");
    exit();
} else {
    header("Location: manage_pets.php");
    exit();
}
