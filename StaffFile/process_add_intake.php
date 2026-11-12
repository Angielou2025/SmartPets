<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pet_name = trim($_POST['pet_name'] ?? '');
    $species = trim($_POST['species'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $intake_date = trim($_POST['intake_date'] ?? '');
    $status = trim($_POST['status'] ?? 'Assessment');
    $notes = trim($_POST['notes'] ?? '');
    $staff_id = $_SESSION['user_id'];

    $allowed_statuses = ['Assessment', 'Vaccination', 'Quarantine', 'Cleared'];

    if ($pet_name === '' || $species === '' || $intake_date === '' || !in_array($status, $allowed_statuses, true)) {
        $_SESSION['error_message'] = "Please fill out all required fields.";
        header("Location: medical_intake.php");
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO pet_intakes (pet_name, species, age, intake_date, status, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$pet_name, $species, $age, $intake_date, $status, $notes, $staff_id]);

        $_SESSION['success_message'] = "New intake recorded for $pet_name.";
        log_activity($pdo, "Recorded new pet intake: $pet_name", "Species: $species, Status: $status");
        header("Location: medical_intake.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error recording intake: " . $e->getMessage();
        header("Location: medical_intake.php");
        exit();
    }
} else {
    header("Location: medical_intake.php");
    exit();
}
