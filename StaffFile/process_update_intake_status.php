<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intake_id = (int) ($_POST['intake_id'] ?? 0);
    $stages = ['Assessment', 'Vaccination', 'Quarantine', 'Cleared'];

    if ($intake_id <= 0) {
        $_SESSION['error_message'] = "Invalid intake selected.";
        header("Location: medical_intake.php");
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT pet_name, status FROM pet_intakes WHERE intake_id = ?");
        $stmt->execute([$intake_id]);
        $intake = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$intake) {
            $_SESSION['error_message'] = "Intake record not found.";
            header("Location: medical_intake.php");
            exit();
        }

        $currentIndex = array_search($intake['status'], $stages, true);
        if ($currentIndex === false || $currentIndex >= count($stages) - 1) {
            $_SESSION['error_message'] = "This intake is already at its final stage.";
            header("Location: medical_intake.php");
            exit();
        }

        $nextStatus = $stages[$currentIndex + 1];
        $pdo->prepare("UPDATE pet_intakes SET status = ? WHERE intake_id = ?")
            ->execute([$nextStatus, $intake_id]);

        $_SESSION['success_message'] = "{$intake['pet_name']}'s intake moved to $nextStatus.";
        log_activity($pdo, "Advanced intake status for {$intake['pet_name']}", "New status: $nextStatus");
        header("Location: medical_intake.php");
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating intake: " . $e->getMessage();
        header("Location: medical_intake.php");
        exit();
    }
} else {
    header("Location: medical_intake.php");
    exit();
}
