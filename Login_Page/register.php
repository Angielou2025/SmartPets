<?php
session_start();
require '../connection/database.php';
require_once '../connection/mailer.php';
require_once 'registration_otp.php';
require_once '../connection/google_config.php';

$error = "";
$success = "";

// Bumalik mula sa verify page para palitan ang email
if (isset($_GET['restart'])) {
    unset($_SESSION['pending_registration']);
}
if (isset($_SESSION['register_error'])) {
    $error = $_SESSION['register_error'];
    unset($_SESSION['register_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = strtolower(trim($_POST['email']));
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // 0. Totoong Gmail account lang ang tinatanggap (pinapatunayan pa ng OTP sa susunod na hakbang)
    if ($username === '') {
        $error = "Username is required.";
    } elseif (!sp_is_gmail_address($email)) {
        $error = "Please use your real, personal Gmail account (example: juan.delacruz@gmail.com). Made-up or non-Gmail addresses are not accepted.";
    }
    // 1. Check if password and confirm password match
    elseif ($password !== $confirm_password) {
        $error = "Password and Confirm Password do not match.";
    } 
    // 2. Validate Password Rules (At least 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 special char)
    elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one UPPERCASE letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number.";
    } elseif (!preg_match('/[\W_]/', $password)) { // Looking for special character
        $error = "Password must contain at least one special character (!@#$%^&* etc.).";
    } else {
        // 3. Check if Email or Username already exists in database
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);
        
        if ($stmt->rowCount() > 0) {
            $error = "Email or Username is already in use.";
        } else {
            // 4. Hindi pa gagawin ang account - itatabi muna, saka magpapadala ng OTP
            //    sa Gmail para mapatunayang totoo at sa kanya talaga ang email
            $_SESSION['pending_registration'] = [
                'username'      => $username,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ];

            if (sp_send_registration_otp($email, $username)) {
                header("Location: verify_email.php");
                exit();
            }

            unset($_SESSION['pending_registration']);
            $error = "We could not send a verification code to that Gmail address. Please check that it is your real Gmail account and try again.";
        }
    }
}

// Iisang page na ang Login at Sign Up (para smooth ang animation) - Sign Up ang unang ipapakita
$authMode = 'register';
$registerError = $error;
$registerUsername = $_POST['username'] ?? '';
$registerEmail = $_POST['email'] ?? '';
$loginError = '';
$loginSuccess = '';
$remainingSeconds = 0;
$loginEmail = '';
include __DIR__ . '/_auth_page.php';