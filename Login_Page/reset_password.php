<?php
/**
 * Forgot Password, hakbang 2 at 3 (ang hakbang 1 ay forgot_password.php):
 *   2. Verify Code   - 6 na kahon; kapag tama, nagsasamang bilog + check
 *   3. New Password  - lalabas lang kapag na-verify na ang code
 */
session_start();
require '../connection/database.php';

// Check if user has a valid reset session
if (!isset($_SESSION['reset_user_id'])) {
    header("Location: forgot_password.php");
    exit();
}

$error = isset($_SESSION['reset_error']) ? $_SESSION['reset_error'] : '';
$success = isset($_SESSION['reset_success']) ? $_SESSION['reset_success'] : '';

// "OTP Code sent to ..." mula sa forgot_password_handler.php
if ($success === '' && isset($_SESSION['forgot_success'])) {
    $success = $_SESSION['forgot_success'];
}

unset($_SESSION['reset_error'], $_SESSION['reset_success'], $_SESSION['forgot_success']);

// Nasa hakbang 3 lang kapag na-verify na ang code (at hindi pa lumilipas ang 10 minuto)
$verifiedAt = (int) ($_SESSION['reset_otp_verified_at'] ?? 0);
$step = ($verifiedAt > 0 && time() - $verifiedAt <= 600) ? 3 : 2;

$eyeIcon = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title><?php echo $step === 3 ? 'Smart Pets - New Password' : 'Smart Pets - Verify Code'; ?></title>
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

        <?php $currentStep = $step; include __DIR__ . '/_reset_steps.php'; ?>

        <?php if ($step === 2): ?>
            <!-- ===== Hakbang 2: Verify Code ===== -->
            <div class="verify-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
            </div>

            <h1 class="title confirm-title">Enter your code</h1>
            <p class="subtitle">We sent a 6-digit code to your Gmail. Enter it below to continue.</p>

            <?php if (!empty($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form action="reset_password_handler.php" method="POST" data-ajax-otp>
                <!-- Ang tunay na ipinapasa sa server; pinupuno ng 6 na kahon sa ibaba -->
                <input type="hidden" name="otp" id="otpValue">

                <div class="otp-boxes" data-target="otpValue" role="group" aria-label="6-digit OTP code">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text" class="otp-box" inputmode="numeric" maxlength="6"
                               aria-label="Digit <?php echo $i + 1; ?>" <?php echo $i === 0 ? 'autocomplete="one-time-code" autofocus' : 'autocomplete="off"'; ?>>
                    <?php endfor; ?>
                </div>

                <button type="submit" name="verify_otp" value="1" class="btn-login">Verify Code</button>
            </form>

            <p class="otp-hint">The code expires in 10 minutes. Check your Spam folder if you can&rsquo;t find it.</p>

            <div class="verify-links">
                <a href="forgot_password.php">Didn&rsquo;t get a code? Send again</a>
                <span class="dot" aria-hidden="true">&middot;</span>
                <a href="login.php">Back to Login</a>
            </div>

        <?php else: ?>
            <!-- ===== Hakbang 3: New Password ===== -->
            <div class="verify-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.2"/></svg>
            </div>

            <h1 class="title confirm-title">Create new password</h1>
            <p class="subtitle">Your code is verified. Choose a strong password you haven&rsquo;t used before.</p>

            <?php if (!empty($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form action="reset_password_handler.php" method="POST" id="newPasswordForm">
                <label class="field-label" for="newPasswordInput">New Password</label>
                <div class="input-wrapper password-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="5" y="10" width="14" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        <circle cx="12" cy="15.5" r="1.2"/>
                    </svg>
                    <input type="password" name="new_password" id="newPasswordInput" placeholder="Create a new password" minlength="8" autocomplete="new-password" required autofocus
                           data-password-rules data-confirm="confirmPasswordInput">
                    <button type="button" class="toggle-password" data-target="newPasswordInput" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $eyeIcon; ?></svg>
                    </button>
                </div>

                <label class="field-label" for="confirmPasswordInput">Confirm Password</label>
                <div class="input-wrapper password-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="5" y="10" width="14" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        <path d="M9.5 15.5l1.8 1.8 3.2-3.4"/>
                    </svg>
                    <input type="password" name="confirm_password" id="confirmPasswordInput" placeholder="Confirm Password" autocomplete="new-password" required>
                    <button type="button" class="toggle-password" data-target="confirmPasswordInput" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $eyeIcon; ?></svg>
                    </button>
                </div>

                <button type="submit" name="reset_password" value="1" class="btn-login">Reset Password</button>
            </form>

            <div class="verify-links">
                <a href="login.php">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Ipakita/itago ang password
        const eyeShape = <?php echo json_encode($eyeIcon); ?>;
        const eyeSlashShape = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';

        document.querySelectorAll('.toggle-password').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const input = document.getElementById(this.dataset.target);
                const show = input.getAttribute('type') === 'password';
                input.setAttribute('type', show ? 'text' : 'password');
                this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                this.querySelector('svg').innerHTML = show ? eyeSlashShape : eyeShape;
            });
        });
    </script>
    <?php if ($step === 2): ?>
        <script src="otp_boxes.js"></script>
    <?php else: ?>
        <script src="password_rules.js"></script>
    <?php endif; ?>
    <script src="keyboard_fix.js"></script>
</body>
</html>
