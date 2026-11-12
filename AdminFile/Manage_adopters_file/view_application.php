<?php
session_start();
require '../../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

// Get adopter_id from URL parameter
$adopter_id = isset($_GET['adopter_id']) ? (int)$_GET['adopter_id'] : 0;

if ($adopter_id === 0) {
    header("Location: manage_adopters.php");
    exit();
}

// Fetch user data including profile image
$stmt = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$profile_image = $user['profile_image'] ?? '';
$profile_img_path = !empty($profile_image) ? '../../uploads/' . $profile_image : null;

// Fetch adopter information using user_id (assuming the URL parameter is user_id)
$adopter_stmt = $pdo->prepare("SELECT user_id, username, email, profile_image, created_at FROM users WHERE user_id = ? AND role = 'Adopter'");
$adopter_stmt->execute([$adopter_id]);
$adopter = $adopter_stmt->fetch(PDO::FETCH_ASSOC);

if (!$adopter) {
    header("Location: manage_adopters.php");
    exit();
}

// Fetch adopter preferences
$preferences = [];
try {
    $pref_stmt = $pdo->prepare("SELECT * FROM adopter_preferences WHERE user_id = ?");
    $pref_stmt->execute([$adopter_id]);
    $preferences = $pref_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Keep empty array if query fails
}

// Fetch applications for this adopter using user_id
$applications = [];
try {
    $sql = "SELECT aa.application_id, aa.application_status, aa.created_at, aa.rejection_reason,
                   p.name AS pet_name, p.breed AS pet_breed, p.kind AS pet_kind, p.photo_url AS pet_photo
            FROM adoption_applications aa
            JOIN pet_inventory p ON aa.pet_id = p.pet_id
            WHERE aa.adopter_id = ?
            ORDER BY aa.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$adopter_id]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $applications = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - View Applications</title>
    <link rel="stylesheet" href="manage_adopters.css">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .application-details {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .application-details h3 {
            margin-top: 0;
            color: #1b4d2e;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-review { background: #dbeafe; color: #1e40af; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #f3f4f6;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: #374151;
            margin-bottom: 20px;
        }
        .back-btn:hover {
            background: #e5e7eb;
        }
        .adopter-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .adopter-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .adopter-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .adopter-info h2 {
            margin: 0 0 5px 0;
            color: #1b4d2e;
        }
        .adopter-info p {
            margin: 0;
            color: #6b7280;
        }
        .preferences-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .preferences-section h3 {
            margin-top: 0;
            color: #1b4d2e;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }
        .preferences-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .preference-item {
            background: #f9fafb;
            padding: 12px;
            border-radius: 6px;
        }
        .preference-item label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .preference-item span {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
        }
        .no-preferences {
            text-align: center;
            padding: 30px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="../../Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

                <nav class="sidebar-menu">
                    <a href="../index.php" class="menu-item">
                        <i class="fa-solid fa-house"></i> Dashboard
                    </a>
                    <a href="../Manage_staff_file/manage_staff.php" class="menu-item">
                        <i class="fa-solid fa-user-gear"></i> Manage Staff
                    </a>
                    <a href="manage_adopters.php" class="menu-item active">
                        <i class="fa-solid fa-users"></i> Manage Adopters
                    </a>
                    <a href="../manage_pets.php" class="menu-item">
                        <i class="fa-solid fa-paw"></i> Manage Pets
                    </a>
                    <a href="../payments.php" class="menu-item">
                        <i class="fa-solid fa-money-bill-wave"></i> Payments
                    </a>
                    <a href="../activity_logs.php" class="menu-item">
                        <i class="fa-solid fa-clock-rotate-left"></i> Activity Logs
                    </a>
                <a href="../ml_model.php" class="menu-item"><i class="fa-solid fa-brain"></i> ML Model</a>
                </nav>

                <div class="sidebar-footer">
                    <div class="city-illustration">
                        <img src="../../Images/caloocan_image.png" alt="Caloocan Arch Landmark" width="300">
                    </div>
                </div>
        </aside>

        <!-- Main Workspace -->
        <div class="main-wrapper">
            <!-- Top Header -->
            <header class="top-header">
                <a href="../edit_profile.php" class="profile-section" style="text-decoration: none; color: inherit;">
                    <div class="avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                        <?php if ($profile_img_path && file_exists($profile_img_path)): ?>
                            <img src="<?php echo htmlspecialchars($profile_img_path); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                        <?php endif; ?>
                    </div>
                    <span class="profile-name"><?php echo htmlspecialchars($username); ?></span>
                </a>
            </header>

            <!-- Main Content Canvas -->
            <main class="content-container">
                <div class="card-panel">
                    <!-- Back Button -->
                    <a href="manage_adopters.php" class="back-btn">
                        <i class="fa-solid fa-arrow-left"></i> Back to Adopters
                    </a>

                    <!-- Adopter Information -->
                    <div class="adopter-header">
                        <div class="adopter-avatar">
                            <?php if (!empty($adopter['profile_image'])): ?>
                                <img src="../../uploads/<?php echo htmlspecialchars($adopter['profile_image']); ?>" alt="Profile">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="color: #666; font-size: 32px;"></i>
                            <?php endif; ?>
                        </div>
                        <div class="adopter-info">
                            <h2><?php echo htmlspecialchars($adopter['username']); ?></h2>
                            <p><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($adopter['email']); ?></p>
                            <p><i class="fa-solid fa-calendar"></i> Member since: <?php echo date('F j, Y', strtotime($adopter['created_at'])); ?></p>
                        </div>
                    </div>

                    <!-- Adopter Preferences Section -->
                    <div class="preferences-section">
                        <h3><i class="fa-solid fa-heart"></i> Adopter Preferences</h3>
                        <?php if ($preferences): ?>
                            <div class="preferences-grid">
                                <div class="preference-item">
                                    <label>Living Space</label>
                                    <span><?php echo htmlspecialchars($preferences['living_space'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Time Commitment</label>
                                    <span><?php echo htmlspecialchars($preferences['time_commitment'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Activity Level</label>
                                    <span><?php echo htmlspecialchars($preferences['activity_level'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Pet Experience</label>
                                    <span><?php echo htmlspecialchars($preferences['pet_experience'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Allergies</label>
                                    <span><?php echo htmlspecialchars($preferences['allergies'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Household Composition</label>
                                    <span><?php echo htmlspecialchars($preferences['household_composition'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Noise Tolerance</label>
                                    <span><?php echo htmlspecialchars($preferences['noise_tolerance'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Maintenance Level</label>
                                    <span><?php echo htmlspecialchars($preferences['maintenance_level'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Adoption Intent</label>
                                    <span><?php echo htmlspecialchars($preferences['adoption_intent'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Kind</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_kind'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Breed</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_breed'] ?? 'Any'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Temperament</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_temperament'] ?? 'Any'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Energy Level</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_energy_level'] ?? 'Any'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Size</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_size'] ?? 'Any'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Age Group</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_age_group'] ?? 'Any'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Gender</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_gender'] ?? 'Any'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Preferred Health Condition</label>
                                    <span><?php echo htmlspecialchars($preferences['pref_health_condition'] ?? 'Healthy'); ?></span>
                                </div>
                                <div class="preference-item">
                                    <label>Last Updated</label>
                                    <span><?php echo $preferences['updated_at'] ? date('F j, Y g:i A', strtotime($preferences['updated_at'])) : 'N/A'; ?></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-preferences">
                                <i class="fa-solid fa-clipboard-list" style="font-size: 32px; margin-bottom: 10px;"></i>
                                <p>No preferences submitted yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Adoption Applications Section -->
                    <div class="preferences-section">
                        <h3><i class="fa-solid fa-file-lines"></i> Adoption Applications</h3>
                        <?php if (count($applications) > 0): ?>
                            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                                <thead>
                                    <tr style="text-align:left; border-bottom:2px solid #e5e7eb;">
                                        <th style="padding:10px;">Pet</th>
                                        <th style="padding:10px;">Date</th>
                                        <th style="padding:10px;">Status</th>
                                        <th style="padding:10px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $app):
                                        $statusKey = strtolower(trim($app['application_status']));
                                        $badgeClass = 'status-pending';
                                        if ($statusKey === 'under review') $badgeClass = 'status-review';
                                        if ($statusKey === 'approved') $badgeClass = 'status-approved';
                                        if ($statusKey === 'rejected') $badgeClass = 'status-rejected';
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f1f1;">
                                            <td style="padding:10px;"><?php echo htmlspecialchars($app['pet_name']); ?> <span style="color:#9aa6a3; font-size:12px;">(<?php echo htmlspecialchars($app['pet_breed']); ?>)</span></td>
                                            <td style="padding:10px;"><?php echo date('M d, Y', strtotime($app['created_at'])); ?></td>
                                            <td style="padding:10px;"><span class="status-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($app['application_status']); ?></span></td>
                                            <td style="padding:10px; text-align:right;">
                                                <a href="../review_application.php?application_id=<?php echo (int) $app['application_id']; ?>" class="back-btn" style="margin:0;">View Full Application</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="no-preferences">
                                <i class="fa-solid fa-file-lines" style="font-size: 32px; margin-bottom: 10px;"></i>
                                <p>No applications submitted yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <?php include '../../connection/chat_bubble.php'; ?>
</body>
</html>