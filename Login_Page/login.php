<?php

session_start();

// Huwag i-cache ang login page para kapag pinindot ang Back, babalik sa server
// at hindi magpapakita ng lumang kopya.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Kung naka-login pa, huwag ipakita ang login page - ibalik sa sariling dashboard.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'Admin') {
        header("Location: ../AdminFile/index.php");
    } elseif ($_SESSION['role'] === 'Staff') {
        header("Location: ../StaffFile/staff_dashboard.php");
    } else {
        header("Location: ../Adopter_File/search_pets.php");
    }
    exit();
}

require '../connection/database.php';
require '../connection/google_config.php';
require __DIR__ . '/_login_helpers.php';

$error = "";
$success_msg = "";
$remaining_seconds = 0; // Used for JavaScript countdown timer

// Get success message from Register page when new registration
if (isset($_SESSION['register_success'])) {
    $success_msg = $_SESSION['register_success'];
    unset($_SESSION['register_success']);
}

// Get success message from Password Reset page
if (isset($_SESSION['reset_success'])) {
    $success_msg = $_SESSION['reset_success'];
    unset($_SESSION['reset_success']);
}

// Error mula sa Google sign-in
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // 1. Find user by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $now = new DateTime();
        $lockout_until = $user['lockout_until'] ? new DateTime($user['lockout_until']) : null;

        // Check if account is currently locked
        if ($lockout_until && $lockout_until > $now) {
            $remaining_seconds = $lockout_until->getTimestamp() - $now->getTimestamp();
            $error = "Too many failed attempts. Please wait.";
        } else {
            // If lockout time is over, reset attempts in database
            if ($lockout_until && $lockout_until <= $now) {
                $resetStmt = $pdo->prepare("UPDATE users SET login_attempts = 0, lockout_until = NULL WHERE user_id = ?");
                $resetStmt->execute([$user['user_id']]);
                $user['login_attempts'] = 0;
            }

            // 2. Check if password is correct
            if (password_verify($password, $user['password_hash'])) {
                finish_login($pdo, $user);
            } else {
                // Password is incorrect: Increment login_attempts
                $attempts = $user['login_attempts'] + 1;

                if ($attempts >= 3) {
                    // Lockout account for 10 seconds
                    $lockTime = new DateTime();
                    $lockTime->modify('+10 seconds');

                    $lockStmt = $pdo->prepare("UPDATE users SET login_attempts = ?, lockout_until = ? WHERE user_id = ?");
                    $lockStmt->execute([$attempts, $lockTime->format('Y-m-d H:i:s'), $user['user_id']]);

                    $remaining_seconds = 10;
                    $error = "Too many failed attempts. Please wait.";
                } else {
                    $updateStmt = $pdo->prepare("UPDATE users SET login_attempts = ? WHERE user_id = ?");
                    $updateStmt->execute([$attempts, $user['user_id']]);

                    // Generic error message for security
                    $error = "Invalid email or password.";
                }
            }
        }
    } else {
        // If email does not exist in database: Generic error still
        $error = "Invalid email or password.";
    }
}


// Iisang page na ang Login at Sign Up (para smooth ang animation) - Login ang unang ipapakita
$authMode = 'login';
$loginError = $error;
$loginSuccess = $success_msg;
$remainingSeconds = $remaining_seconds;
$loginEmail = $_POST['email'] ?? '';
$registerError = '';
include __DIR__ . '/_auth_page.php';