<?php
session_start();
require '../connection/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../Adopter_File/PHPMailer/PHPMailer-master/src/Exception.php';
require_once '../Adopter_File/PHPMailer/PHPMailer-master/src/PHPMailer.php';
require_once '../Adopter_File/PHPMailer/PHPMailer-master/src/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $_SESSION['forgot_error'] = "Email is required.";
        header("Location: forgot_password.php");
        exit();
    }

    // Check if email exists in database (primary email)
    $stmt = $pdo->prepare("SELECT user_id, username, email, recovery_email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $target_email = null;
    $user_id = null;

    if ($user) {
        // Email exists in database, use it
        $target_email = $user['email'];
        $user_id = $user['user_id'];
    } else {
        // Email doesn't exist, check if it's a recovery email
        $stmt = $pdo->prepare("SELECT user_id, username, email, recovery_email FROM users WHERE recovery_email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Recovery email exists, use the primary email for OTP
            $target_email = $user['email'];
            $user_id = $user['user_id'];
        } else {
            $_SESSION['forgot_error'] = "Email not found in our system.";
            header("Location: forgot_password.php");
            exit();
        }
    }

    // Generate 6-digit OTP
    $otp = sprintf("%06d", mt_rand(1, 999999));
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Save OTP to database
    $otp_stmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expiry = ? WHERE user_id = ?");
    $otp_stmt->execute([$otp, $expiry, $user_id]);

    // Store user_id in session for password reset. Bagong code = balik sa
    // hakbang 2 (verify), at bagong bilang ng maling subok.
    $_SESSION['reset_user_id'] = $user_id;
    unset($_SESSION['reset_otp_verified_at'], $_SESSION['reset_otp_attempts']);

    // Send OTP via PHPMailer
    $mail = new PHPMailer(true);

    $system_email = 'angielou2024@gmail.com';
    $app_password = 'xyqbrcbwivlahgmw';

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $system_email;
        $mail->Password   = $app_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        // Workaround for local/dev environments where OpenSSL CA bundle
        // verification may fail (Windows XAMPP). This relaxes peer checks.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom($system_email, 'Smart Pet System');
        $mail->addAddress($target_email);

        $mail->isHTML(true);
        $mail->Subject = 'Smart Pet - Password Reset OTP Code';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                <h2 style='color: #2e5b32;'>Smart Pet Password Reset</h2>
                <p>Your OTP code to reset your password is:</p>
                <h1 style='color: #2e5b32; letter-spacing: 5px; font-size: 32px;'>$otp</h1>
                <p style='color: #666;'>This code will expire in 10 minutes.</p>
                <p style='color: #666; font-size: 12px;'>If you did not request this, please ignore this email.</p>
            </div>
        ";

        $mail->send();
        $_SESSION['forgot_success'] = "OTP Code sent to " . htmlspecialchars($target_email) . ". Please check your inbox!";
        header("Location: reset_password.php");
        exit();
    } catch (Exception $e) {
        $_SESSION['forgot_error'] = "Could not send OTP email. Error: {$mail->ErrorInfo} - " . $e->getMessage();
        header("Location: forgot_password.php");
        exit();
    }
}
?>
