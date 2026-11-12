<?php
session_start();
require '../connection/database.php';

$error = isset($_SESSION['forgot_error']) ? $_SESSION['forgot_error'] : '';
$success = isset($_SESSION['forgot_success']) ? $_SESSION['forgot_success'] : '';

// Galing sa reset_password_handler.php kapag nag-expire ang code
if ($error === '' && isset($_SESSION['reset_error'])) {
    $error = $_SESSION['reset_error'];
}

unset($_SESSION['forgot_error'], $_SESSION['forgot_success'], $_SESSION['reset_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title>Smart Pets - Forgot Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="login.css">
</head>
<body>
    <div class="overlay"></div>

    <div class="login-container auth-single">
        <div class="brand">
            <div class="brand-logo">
                <img src="../Images/logo.png" alt="Smart Pets logo">
            </div>
            <span class="brand-name"><span class="brand-smart">Smart</span> Pets</span>
        </div>

        <?php $currentStep = 1; include __DIR__ . '/_reset_steps.php'; ?>

        <div class="verify-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2"/><circle cx="12" cy="15.5" r="1.2"/></svg>
        </div>

        <h1 class="title confirm-title">Forgot Password</h1>
        <p class="subtitle">Enter the email of your Smart Pets account. We&rsquo;ll send a 6-digit code to your Gmail so you can set a new password.</p>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form action="forgot_password_handler.php" method="POST" id="forgotForm">
            <label class="field-label" for="forgotEmailInput">Email</label>
            <div class="input-wrapper">
                <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="M3 7l9 6 9-6"/>
                </svg>
                <input type="email" name="email" id="forgotEmailInput" placeholder="example@gmail.com" autocomplete="email" required autofocus>
            </div>

            <button type="submit" name="send_otp" class="btn-login" id="sendOtpBtn">Send OTP Code</button>
        </form>

        <div class="verify-links">
            <a href="login.php">&larr; Back to Login</a>
        </div>
    </div>

    <script>
        // Habang ipinapadala ang email (ilang segundo), ipakitang may nangyayari
        // at huwag hayaang mapindot nang paulit-ulit
        document.getElementById('forgotForm').addEventListener('submit', function () {
            const btn = document.getElementById('sendOtpBtn');
            setTimeout(function () {
                btn.disabled = true;
                btn.textContent = 'Sending code...';
            }, 0);
        });
    </script>
    <script src="keyboard_fix.js"></script>
</body>
</html>
