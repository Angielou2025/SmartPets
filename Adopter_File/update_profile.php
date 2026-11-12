<?php
session_start();
require '../connection/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer/PHPMailer-master/src/Exception.php';
require_once 'PHPMailer/PHPMailer-master/src/PHPMailer.php';
require_once 'PHPMailer/PHPMailer-master/src/SMTP.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// ----------------------------------------------------
// HAKBANG A: VERIFY OTP CODE (Mula sa Modal)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $entered_otp = trim($_POST['otp_input']);

    $stmt = $pdo->prepare("SELECT otp_code, otp_expiry FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data && !empty($data['otp_code']) && $data['otp_code'] === $entered_otp) {
        if (strtotime($data['otp_expiry']) > time()) {
            
            // Kunin ang nakaimbak na pending email
            if (isset($_SESSION['pending_recovery_email'])) {
                $new_recovery = $_SESSION['pending_recovery_email'];
                
                // I-update sa DB ang bagong recovery email at i-clear ang OTP
                $update = $pdo->prepare("UPDATE users SET recovery_email = ?, otp_code = NULL, otp_expiry = NULL WHERE user_id = ?");
                $update->execute([$new_recovery, $user_id]);

                unset($_SESSION['pending_recovery_email']);
                $_SESSION['profile_success'] = "Recovery email successfully verified and updated!";
            } else {
                $_SESSION['profile_error'] = "Session expired. Please try entering your recovery email again.";
            }

        } else {
            $_SESSION['profile_error'] = "Expired OTP code. Please request a new one.";
            unset($_SESSION['pending_recovery_email']);
        }
    } else {
        $_SESSION['profile_error'] = "Invalid OTP code. Please try again.";
    }

    header("Location: edit_profile.php");
    exit();
}

// ----------------------------------------------------
// HAKBANG B: UPDATE PROFILE & SEND OTP
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_username = trim($_POST['username']);
    $new_recovery = trim($_POST['recovery_email']);

    if (empty($new_username)) {
        $_SESSION['profile_error'] = "Username cannot be empty.";
        header("Location: edit_profile.php");
        exit();
    }

    // 1. Update Username
    $updateStmt = $pdo->prepare("UPDATE users SET username = ? WHERE user_id = ?");
    $updateStmt->execute([$new_username, $user_id]);
    $_SESSION['username'] = $new_username;

    // 2. Upload Profile Image (kung may in-upload)
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['profile_image']['tmp_name'];
        $fileName = $_FILES['profile_image']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = 'user_' . $user_id . '_' . time() . '.' . $fileExtension;
            $uploadFileDir = '../uploads/';

            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }

            if (move_uploaded_file($fileTmpPath, $uploadFileDir . $newFileName)) {
                $imgStmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
                $imgStmt->execute([$newFileName, $user_id]);
            }
        }
    }

    // 3. Process Recovery Email OTP
    $stmt = $pdo->prepare("SELECT recovery_email FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $current_db_user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Kapag may bagong inilagay na recovery email na iba sa kasalukuyang nakatago sa DB
    if (!empty($new_recovery) && $new_recovery !== $current_db_user['recovery_email']) {
        
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Save OTP sa Database
        $otp_stmt = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expiry = ? WHERE user_id = ?");
        $otp_stmt->execute([$otp, $expiry, $user_id]);

        $_SESSION['pending_recovery_email'] = $new_recovery;

        // --- PHPMailer Configuration ---
        $mail = new PHPMailer(true);

        // ILAGAY DITO ANG TOTOONG GMAIL AT APP PASSWORD MO:
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
            // Relax OpenSSL peer verification for local/XAMPP development environments
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom($system_email, 'Smart Pet System');
            $mail->addAddress($new_recovery); // Ipapadala sa tinype na recovery email

            $mail->isHTML(true);
            $mail->Subject = 'Smart Pet - Recovery Email OTP Code';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                    <h2 style='color: #2e5b32;'>Smart Pet Verification</h2>
                    <p>Your OTP code to verify and set this as your recovery email is:</p>
                    <h1 style='color: #2e5b32; letter-spacing: 5px; font-size: 32px;'>$otp</h1>
                    <p style='color: #666;'>This code will expire in 10 minutes.</p>
                </div>
            ";

            $mail->send();
            $_SESSION['profile_success'] = "OTP Code sent to " . htmlspecialchars($new_recovery) . ". Please check your inbox!";
        } catch (Exception $e) {
            $_SESSION['profile_error'] = "Could not send OTP email. Error: {$mail->ErrorInfo} - " . $e->getMessage();
        }

        header("Location: edit_profile.php");
        exit();
    }

    $_SESSION['profile_success'] = "Profile updated successfully!";
    header("Location: edit_profile.php");
    exit();
}
?>
