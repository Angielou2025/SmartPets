<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_id = $_SESSION['user_id'];
    $task_id = (int) ($_POST['task_id'] ?? 0);

    if ($task_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM daily_tasks WHERE task_id = ? AND staff_id = ?");
            $stmt->execute([$task_id, $staff_id]);
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error deleting task: " . $e->getMessage();
        }
    }
}

header("Location: daily_schedule.php");
exit();
