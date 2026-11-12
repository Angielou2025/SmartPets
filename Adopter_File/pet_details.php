<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pet_id = $_GET['pet_id'] ?? null;
if (!$pet_id) {
    header('Location: search_pets.php');
    exit();
}

$stmt = $pdo->prepare('SELECT pet_id, name, kind, breed, age_group, gender, size, energy_level, temperament, health_condition, vaccination_status, description, photo_url, status FROM pet_inventory WHERE pet_id = ?');
$stmt->execute([$pet_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pet) {
    header('Location: search_pets.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Kahit alam ng isang adopter ang pet_id sa URL, hindi niya dapat makita ang
// detalye ng pet na naampon na ng IBA. Ang may-ari lang ang pwedeng tumingin
// sa naampon nang pet; ang iba ay ibinabalik sa listahan ng mga available.
if (($pet['status'] ?? '') === 'Adopted') {
    $ownerStmt = $pdo->prepare(
        'SELECT 1 FROM adoption_applications
         WHERE pet_id = ? AND adopter_id = ? AND application_status = \'Approved\'
         LIMIT 1'
    );
    $ownerStmt->execute([$pet_id, $user_id]);

    if (!$ownerStmt->fetchColumn()) {
        $_SESSION['error_message'] = 'This pet has already been adopted and is no longer available to view.';
        header('Location: search_pets.php');
        exit();
    }
}

// May nauna nang nag-apply sa alagang ito: hindi na ito ipapakita sa ibang adopter.
// Kung siya mismo ang nag-apply, makikita pa rin niya, pero hindi na siya makakapag-apply ulit.
$activeApp = ($pet['status'] ?? '') === 'Available' ? sp_pet_active_application($pdo, (int) $pet_id) : null;
if ($activeApp !== null && $activeApp['adopter_id'] !== (int) $user_id) {
    $_SESSION['error_message'] = 'Another adopter has already applied for ' . $pet['name'] . '. Please choose another pet for now.';
    header('Location: search_pets.php');
    exit();
}
$myActiveApp = ($activeApp !== null && $activeApp['adopter_id'] === (int) $user_id) ? $activeApp : null;

$userStmt = $pdo->prepare('SELECT username, profile_image FROM users WHERE user_id = ?');
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';

// Kailangan munang naka-fill up ang reference bago makita ang detalye ng pet
$prefCheck = $pdo->prepare('SELECT 1 FROM adopter_preferences WHERE user_id = ? LIMIT 1');
$prefCheck->execute([$user_id]);
$hasPrefs = (bool) $prefCheck->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Pet Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
</head>
<body style="background-color: #1b4d2e;">
    <div class="dashboard-container">
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
                <a href="my_pets.php" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="#" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message</a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>
            <div class="sidebar-illustration">
                <img src="../Images/bg_design_pets.png" alt="Pets Illustration" onerror="this.style.display='none'">
            </div>
        </aside>

        <main class="main-content">
            <header class="top-navbar">
                <div class="top-nav-links">
                    <a href="search_pets.php">Search Pets</a>
                    <a href="shelter.php">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>
                <a href="edit_profile.php" class="user-profile-link" style="text-decoration: none; color: inherit;">
                    <div class="user-profile">
                        <div class="profile-avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            <?php if (!empty($profile_image)): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                            <?php endif; ?>
                        </div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </a>
            </header>

            <section class="hero-title">
                <p>More information about your selected pet.</p>
            </section>

            <?php if (!$hasPrefs): ?>
            <section class="pet-detail-section">
                <div class="pet-detail-card" style="justify-content:center; text-align:center; padding:50px 30px;">
                    <div>
                        <?php // Walang sariling English/Tagalog toggle: sumusunod sa wikang pinili sa
                              // Account Settings (isinasalin ng connection/i18n.php) ?>
                        <h2 id="prefsGateTitle" style="color:#1b4d2e; margin-bottom:12px;">Before you proceed</h2>
                        <p id="prefsGateBody" style="color:#555; margin-bottom:20px;">You need to fill up your reference first before you can see the details of this pet.</p>
                        <a href="adopter_preferences.php" class="btn-orange" id="prefsGateFillBtn">Fill Up Reference</a>
                        <a href="search_pets.php" class="btn-text" id="prefsGateBackBtn">Back to Search</a>
                    </div>
                </div>
            </section>
            <?php else: ?>
            <section class="pet-detail-section">
                <div class="pet-detail-card">
                    <div class="pet-detail-left">
                        <div class="pet-detail-image">
                            <?php if (!empty($pet['photo_url'])): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($pet['photo_url']); ?>" alt="<?php echo htmlspecialchars($pet['name']); ?>" onerror="this.src='https://via.placeholder.com/400'">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/400" alt="<?php echo htmlspecialchars($pet['name']); ?>">
                            <?php endif; ?>
                        </div>
                        <div class="detail-block about-block">
                            <h3>About</h3>
                            <p><?php echo htmlspecialchars($pet['description']); ?></p>
                        </div>
                    </div>

                    <div class="pet-detail-info">
                        <div class="pet-detail-header">
                            <div>
                                <span class="badge-highlight">Pet Details</span>
                                <h1><?php echo htmlspecialchars($pet['name']); ?></h1>
                                <p class="pet-subtitle"><?php echo htmlspecialchars($pet['breed']); ?> &middot; <?php echo htmlspecialchars($pet['kind']); ?></p>
                            </div>
                            <span class="status-pill"><?php echo htmlspecialchars($pet['status']); ?></span>
                        </div>

                        <div class="detail-grid">
                            <div>
                                <h3>Age</h3>
                                <p><?php echo htmlspecialchars($pet['age_group']); ?></p>
                            </div>
                            <div>
                                <h3>Gender</h3>
                                <p><?php echo htmlspecialchars($pet['gender']); ?></p>
                            </div>
                            <div>
                                <h3>Size</h3>
                                <p><?php echo htmlspecialchars($pet['size']); ?></p>
                            </div>
                            <div>
                                <h3>Energy</h3>
                                <p><?php echo htmlspecialchars($pet['energy_level']); ?></p>
                            </div>
                        </div>

                        <div class="detail-block">
                            <h3>Condition</h3>
                            <p><?php echo htmlspecialchars($pet['health_condition']); ?></p>
                        </div>

                        <div class="detail-block">
                            <h3>Vaccination Status</h3>
                            <p><?php echo htmlspecialchars($pet['vaccination_status']); ?></p>
                        </div>

                        <div class="detail-actions">
                            <?php if ($myActiveApp !== null): ?>
                                <!-- May aplikasyon na siya sa alagang ito: walang dobleng pag-apply -->
                                <a href="my_applications.php" class="btn-orange">View My Application</a>
                            <?php elseif ($pet['status'] === 'Available'): ?>
                                <a href="adoption_application.php?pet_id=<?php echo urlencode($pet['pet_id']); ?>" class="btn-orange">Adopt Now</a>
                            <?php else: ?>
                                <span class="btn-text" style="display:inline-block; cursor: default;">Already Adopted</span>
                            <?php endif; ?>
                            <a href="my_pets.php" class="btn-text">Back to My Pets</a>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
