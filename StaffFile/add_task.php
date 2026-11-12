<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_id = $_SESSION['user_id'];
    $task_name = trim($_POST['task_name'] ?? '');
    $task_time = trim($_POST['task_time'] ?? '');

    if ($task_name !== '') {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO daily_tasks (staff_id, task_name, task_date, task_time) VALUES (?, ?, CURDATE(), ?)"
            );
            $stmt->execute([$staff_id, $task_name, $task_time !== '' ? $task_time : null]);
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error adding task: " . $e->getMessage();
        }
    }
}

header("Location: daily_schedule.php");
exit();
