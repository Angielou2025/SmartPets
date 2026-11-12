<?php
session_start();
require '../connection/database.php';

// Siguraduhing naka-login ang adopter bago ma-access ang pahina
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch user profile data
$user_id = $_SESSION['user_id'];
$sp_unread_count = sp_unread_message_count($pdo, (int) $_SESSION['user_id']);
$stmt = $pdo->prepare("SELECT username, profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';

// Mga pet na pwedeng makita ng adopter na ito: ang bukas pa para sa adoption,
// at ang sarili niyang mga inampon. Ang naampon na ng IBANG adopter ay hindi na
// dapat lumabas kahit naka-favorite pa ito noong available pa.
try {
    $pets_stmt = $pdo->prepare(
        "SELECT p.pet_id, p.name, p.kind, p.breed, p.age_group, p.gender, p.size,
                p.energy_level, p.temperament, p.health_condition, p.vaccination_status,
                p.description, p.photo_url, p.status
         FROM pet_inventory p
         WHERE (p.status = 'Available' AND " . sp_pet_open_sql('p.pet_id') . ")
            OR EXISTS (
                SELECT 1 FROM adoption_applications aa
                WHERE aa.pet_id = p.pet_id
                  AND aa.adopter_id = ?
                  AND aa.application_status = 'Approved'
            )
         ORDER BY p.created_at DESC"
    );
    // Hindi rin ipinapakita ang alagang may aktibong aplikasyon ng IBANG adopter
    // (babalik ito kapag na-reject ang aplikasyong iyon) - sp_pet_open_sql
    $pets_stmt->execute([$user_id, $user_id]);
    $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pets = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Favorites</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
</head>
<body class="sp-list-scroll" style="background-color: #1b4d2e;">
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
                <a href="favorites.php" class="nav-item active"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
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
                <p>Your favorite pets are saved here for quick access.</p>
            </section>

            <section class="featured-section sp-scroll-host">
                <h2 class="sp-list-head"><span class="sp-head-ico"><i class="fa-solid fa-heart"></i></span>Favorites <span class="sp-count" data-word="pet"></span></h2>
                <div id="favoritesGrid" class="pets-grid"></div>
                <div id="favoritesEmpty" class="favorite-empty" style="display:none;">
                    <p>No favorites yet. Add pets to your favorites to see them here.</p>
                </div>
            </section>
        </main>
    </div>

    <script>
        const petData = <?php echo json_encode($pets, JSON_HEX_TAG); ?>;
        const favoritesKey = 'smartPetFavorites';
        const favoritesGrid = document.getElementById('favoritesGrid');
        const favoritesEmpty = document.getElementById('favoritesEmpty');

        function getFavoriteIds() {
            try {
                return JSON.parse(localStorage.getItem(favoritesKey)) || [];
            } catch (e) {
                return [];
            }
        }

        function renderFavoriteCard(pet) {
            const card = document.createElement('div');
            card.className = 'pet-card';
            card.innerHTML = `
                <div class="status-badge adopted">Favorite</div>
                <div class="card-top">
                    <div class="pet-img-container">
                        <img src="${pet.photo_url ? '../uploads/' + pet.photo_url : 'https://via.placeholder.com/150'}" alt="${pet.name}" onerror="this.src='https://via.placeholder.com/150'">
                    </div>
                    <div class="pet-info">
                        <h3>${pet.name}</h3>
                        <p class="breed">${pet.breed}</p>
                        <p class="meta">${pet.age_group}<br>${pet.gender}</p>
                    </div>
                </div>
                <div class="card-actions">
                    <a href="pet_details.php?pet_id=${pet.pet_id}" class="btn-orange">View Details</a>
                    <a href="#" class="btn-text" onclick="removeFavorite('${pet.pet_id}')">Remove</a>
                </div>
            `;
            favoritesGrid.appendChild(card);
        }

        function removeFavorite(petId) {
            const current = getFavoriteIds().filter(id => id !== String(petId));
            localStorage.setItem(favoritesKey, JSON.stringify(current));
            loadFavorites();
        }

        function loadFavorites() {
            favoritesGrid.innerHTML = '';
            const favoriteIds = getFavoriteIds();
            const favoritePets = petData.filter(pet => favoriteIds.includes(String(pet.pet_id)));

            if (favoritePets.length === 0) {
                favoritesEmpty.style.display = 'block';
                return;
            }

            favoritesEmpty.style.display = 'none';
            favoritePets.forEach(renderFavoriteCard);
        }

        document.addEventListener('DOMContentLoaded', loadFavorites);
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
<script src="list_count.js?v=<?php echo @filemtime(__DIR__ . '/list_count.js'); ?>"></script>
</body>
</html>
