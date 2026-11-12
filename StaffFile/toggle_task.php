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
            // Paalala: sunod-sunod na ini-evaluate ng MySQL ang SET clause, kaya ang
            // is_done dito ay yung BAGONG value na, hindi na yung luma.
            $stmt = $pdo->prepare(
                "UPDATE daily_tasks
                 SET is_done = NOT is_done,
                     completed_at = CASE WHEN is_done = 1 THEN NOW() ELSE NULL END
                 WHERE task_id = ? AND staff_id = ?"
            );
            $stmt->execute([$task_id, $staff_id]);
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error updating task: " . $e->getMessage();
        }
    }
}

header("Location: daily_schedule.php");
exit();
