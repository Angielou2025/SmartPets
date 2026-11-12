<?php
/**
 * Forgot Password, hakbang 2 at 3:
 *   verify_otp     - sinusuri ang 6-digit code. Kapag tama, saka lang bubukas
 *                    ang hakbang 3 (bagong password).
 *   reset_password - pinapalitan ang password. Gumagana lang kapag na-verify na
 *                    ang code sa hakbang 2.
 */
session_start();
require '../connection/database.php';

const RESET_OTP_MAX_ATTEMPTS = 5;   // ilang maling subok bago kailangang humingi ng bagong code
const RESET_VERIFIED_MINUTES = 10;  // gaano katagal bukas ang hakbang 3 pagka-verify

// Kapag galing sa OTP boxes (otp_boxes.js), JSON ang sagot para makapag-animate
// sa page bago lumipat: tamang code = nagsasamang bilog + check
$isAjax = !empty($_POST['ajax']);

function reset_fail(string $message, string $to = 'reset_password.php'): void
{
    global $isAjax;
    if ($isAjax) {
        // Kapag lilipat ng page (hal. expired), doon na ipapakita ang error
        if ($to !== 'reset_password.php') {
            $_SESSION['reset_error'] = $message;
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message, 'redirect' => $to === 'reset_password.php' ? '' : $to]);
        exit();
    }
    $_SESSION['reset_error'] = $message;
    header("Location: $to");
    exit();
}

function reset_done(string $to): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'error' => '', 'redirect' => $to]);
        exit();
    }
    header("Location: $to");
    exit();
}

function reset_clear_session(): void
{
    unset($_SESSION['reset_user_id'], $_SESSION['reset_otp_verified_at'], $_SESSION['reset_otp_attempts']);
}

if (!isset($_SESSION['reset_user_id'])) {
    reset_fail("Your reset session has ended. Please request a new code.", 'forgot_password.php');
}

$user_id = $_SESSION['reset_user_id'];

// ===== Hakbang 2: suriin ang code =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $otp = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));

    $stmt = $pdo->prepare("SELECT otp_code, otp_expiry FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data || empty($data['otp_code'])) {
        reset_clear_session();
        reset_fail("Your code is no longer valid. Please request a new one.", 'forgot_password.php');
    }

    if (strtotime($data['otp_expiry']) < time()) {
        reset_clear_session();
        reset_fail("OTP code has expired. Please request a new one.", 'forgot_password.php');
    }

    $attempts = (int) ($_SESSION['reset_otp_attempts'] ?? 0);
    if ($attempts >= RESET_OTP_MAX_ATTEMPTS) {
        // Sobra na ang maling subok - burahin ang code para hindi na mahulaan
        $pdo->prepare("UPDATE users SET otp_code = NULL, otp_expiry = NULL WHERE user_id = ?")->execute([$user_id]);
        reset_clear_session();
        reset_fail("Too many wrong attempts. Please request a new code.", 'forgot_password.php');
    }

    if (!hash_equals((string) $data['otp_code'], $otp)) {
        $_SESSION['reset_otp_attempts'] = ++$attempts;
        $left = RESET_OTP_MAX_ATTEMPTS - $attempts;
        reset_fail("Incorrect code." . ($left > 0 ? " You have {$left} attempt(s) left." : " Please request a new code."));
    }

    // Tama - bukas na ang hakbang 3
    $_SESSION['reset_otp_verified_at'] = time();
    unset($_SESSION['reset_otp_attempts']);
    reset_done('reset_password.php');
}

// ===== Hakbang 3: bagong password =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $verifiedAt = (int) ($_SESSION['reset_otp_verified_at'] ?? 0);
    if ($verifiedAt === 0) {
        reset_fail("Please verify your code first.");
    }
    if (time() - $verifiedAt > RESET_VERIFIED_MINUTES * 60) {
        reset_clear_session();
        reset_fail("Your reset session has ended. Please request a new code.", 'forgot_password.php');
    }

    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validate passwords
    if ($new_password !== $confirm_password) {
        reset_fail("Password and Confirm Password do not match.");
    }

    if (strlen($new_password) < 8) {
        reset_fail("Password must be at least 8 characters.");
    }

    if (!preg_match('/[A-Z]/', $new_password)) {
        reset_fail("Password must contain at least one UPPERCASE letter.");
    }

    if (!preg_match('/[a-z]/', $new_password)) {
        reset_fail("Password must contain at least one lowercase letter.");
    }

    if (!preg_match('/[0-9]/', $new_password)) {
        reset_fail("Password must contain at least one number.");
    }

    if (!preg_match('/[\W_]/', $new_password)) {
        reset_fail("Password must contain at least one special character (!@#$%^&* etc.).");
    }

    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // Update password in database
    $update = $pdo->prepare("UPDATE users SET password_hash = ?, otp_code = NULL, otp_expiry = NULL WHERE user_id = ?");
    $update->execute([$hashed_password, $user_id]);

    // Clear session
    reset_clear_session();

    $_SESSION['reset_success'] = "Password reset successfully! You can now login with your new password.";
    reset_done('login.php');
}

header("Location: reset_password.php");
exit();
