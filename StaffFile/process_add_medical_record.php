<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pet_id = (int) ($_POST['pet_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $checkup_date = trim($_POST['checkup_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $staff_id = $_SESSION['user_id'];

    $allowed_statuses = ['Checkup', 'Treatment', 'Vaccines', 'Recovery'];

    if ($pet_id <= 0 || !in_array($status, $allowed_statuses, true) || $checkup_date === '') {
        $_SESSION['error_message'] = "Please fill out all required fields.";
        header("Location: medical_intake.php");
        exit();
    }

    try {
        $petStmt = $pdo->prepare("SELECT name FROM pet_inventory WHERE pet_id = ?");
        $petStmt->execute([$pet_id]);
        $petName = $petStmt->fetchColumn();

        if (!$petName) {
            $_SESSION['error_message'] = "Selected pet was not found.";
            header("Location: medical_intake.php");
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO medical_records (pet_id, status, checkup_date, notes, recorded_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$pet_id, $status, $checkup_date, $notes, $staff_id]);

        $_SESSION['success_message'] = "Medical record added for $petName.";
        log_activity($pdo, "Added medical record for $petName", "Status: $status, Date: $checkup_date");
        header("Location: medical_intake.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding medical record: " . $e->getMessage();
        header("Location: medical_intake.php");
        exit();
    }
} else {
    header("Location: medical_intake.php");
    exit();
}
