<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$sp_unread_count = sp_unread_message_count($pdo, (int) $_SESSION['user_id']);

// Fetch latest user data kasama ang recovery_email
$stmt = $pdo->prepare("SELECT username, email, recovery_email, profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$error = isset($_SESSION['profile_error']) ? $_SESSION['profile_error'] : '';
$success = isset($_SESSION['profile_success']) ? $_SESSION['profile_success'] : '';
$show_otp_modal = isset($_SESSION['pending_recovery_email']);

unset($_SESSION['profile_error'], $_SESSION['profile_success']);

$profile_img_path = !empty($user['profile_image']) ? '../uploads/' . $user['profile_image'] : null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Edit Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        .image-upload-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .avatar-preview {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            border: 3px solid #2e5b32;
            object-fit: cover;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .custom-file-btn {
            background-color: #2e5b32;
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.15s ease;
        }
        .custom-file-btn:hover {
            background-color: #1b4d2e;
            transform: translateY(-1px);
        }

        /* OTP MODAL STYLING */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6); display: flex; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-box {
            background: #fff; padding: 25px; border-radius: 12px; width: 350px; text-align: center; box-shadow: 0px 4px 15px rgba(0,0,0,0.2);
        }
        .otp-input {
            width: 80%; padding: 10px; font-size: 20px; letter-spacing: 5px; text-align: center; margin: 15px 0; border: 2px solid #2e5b32; border-radius: 8px;
        }
        .btn-verify { background: #2e5b32; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; transition: background-color 0.2s ease, transform 0.15s ease; }
        .btn-verify:hover { background: #1b4d2e; transform: translateY(-1px); }
    </style>
</head>
<body style="background-color: #1b4d2e;">

    <div class="dashboard-container">
        
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="../Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

            <nav class="nav-menu">
                <a href="adopter_dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="adopter_preferences.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i> My preference</a>
                <a href="ai_recommendation.php" class="nav-item"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Recommendation</a>
                <a href="#" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="#" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            
            <header class="top-navbar">
                <div class="top-nav-links">
                    <a href="search_pets.php">Search Pets</a>
                    <a href="shelter.php">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>
                <div class="user-profile">
                    <div class="profile-avatar" style="overflow: hidden;">
                        <?php if ($profile_img_path && file_exists($profile_img_path)): ?>
                            <img src="<?php echo htmlspecialchars($profile_img_path); ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                        <?php endif; ?>
                    </div>
                    <span><?php echo htmlspecialchars($user['username']); ?></span>
                </div>
            </header>

            <div class="preferences-form-container">
                <div class="form-header">
                    <h2>Account Settings</h2>
                    <p>Update your profile and recovery details</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div style="background:#ffe5e5; color:#c0392b; padding:10px; border-radius:8px; margin-bottom:15px; font-size:14px; text-align:center;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div style="background:#e5ffe8; color:#27ae60; padding:10px; border-radius:8px; margin-bottom:15px; font-size:14px; text-align:center;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- IMPORTANT: enctype="multipart/form-data" para sa image upload -->
                <form action="update_profile.php" method="POST" enctype="multipart/form-data">
                    
                    <!-- PROFILE IMAGE UPLOAD SECTION -->
                    <div class="image-upload-wrapper">
                        <div class="avatar-preview">
                            <?php if ($profile_img_path && file_exists($profile_img_path)): ?>
                                <img id="previewImg" src="<?php echo htmlspecialchars($profile_img_path); ?>" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <img id="previewImg" src="" style="width:100%; height:100%; object-fit:cover; display:none;">
                                <i id="defaultIcon" class="fa-solid fa-user" style="font-size: 45px; color: #888;"></i>
                            <?php endif; ?>
                        </div>
                        
                        <label for="profile_image" class="custom-file-btn">
                            <i class="fa-solid fa-camera"></i> Change Photo
                        </label>
                        <input type="file" name="profile_image" id="profile_image" accept="image/*" style="display: none;" onchange="previewFile()">
                    </div>

                    <div class="form-step">
                        <div class="form-group full-width">
                            <label>Username</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>

                        <div class="form-group full-width" style="margin-top: 15px;">
                            <label>Primary Email Address</label>
                            <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled style="background-color: #f3f4f6;">
                        </div>

                        <!-- RECOVERY EMAIL FIELD -->
                        <div class="form-group full-width" style="margin-top: 15px;">
                            <label>Recovery Email (Requires OTP Verification)</label>
                            <input type="email" name="recovery_email" value="<?php echo htmlspecialchars($user['recovery_email'] ?? ''); ?>" placeholder="Enter recovery email">
                        </div>

                        <div class="form-actions" style="margin-top: 25px;">
                            <button type="submit" name="update_profile" class="btn-save">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>

        </main>
    </div>

    <!-- OTP VERIFICATION MODAL -->
    <?php if ($show_otp_modal): ?>
    <div class="modal-overlay" id="otpModal">
        <div class="modal-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0;">Enter OTP Code</h3>
                <button type="button" onclick="closeOtpModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
            </div>
            <p style="font-size: 13px; color: #666; margin-top: 5px;">
                We sent a 6-digit code to <b><?php echo htmlspecialchars($_SESSION['pending_recovery_email']); ?></b>
            </p>
            <form action="update_profile.php" method="POST">
                <input type="text" name="otp_input" maxlength="6" class="otp-input" placeholder="123456" required autofocus autocomplete="off">
                <button type="submit" name="verify_otp" class="btn-verify">Verify & Save</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Live Image Preview Script -->
    <script>
        function previewFile() {
            const file = document.getElementById('profile_image').files[0];
            const preview = document.getElementById('previewImg');
            const icon = document.getElementById('defaultIcon');

            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    if (icon) icon.style.display = 'none';
                }
                reader.readAsDataURL(file);
            }
        }

        function closeOtpModal() {
            const modal = document.getElementById('otpModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        // Close modal when clicking outside the modal box
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('otpModal');
            if (modal) {
                const modalBox = modal.querySelector('.modal-box');
                if (modal && event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        });
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
