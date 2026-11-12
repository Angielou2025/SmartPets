<?php
session_start();
require '../connection/database.php';

// Siguraduhing naka-login ang user
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Kunin ang profile image ng kasalukuyang user
$user_id = $_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT username, profile_image FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$display_name = !empty($current_user['username']) ? $current_user['username'] : $_SESSION['username'];
$profile_img = !empty($current_user['profile_image']) ? '../uploads/' . $current_user['profile_image'] : null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Shelter Location</title>
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
                    <a href="shelter.php" class="active">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>

                <a href="edit_profile.php" class="user-profile-link" style="text-decoration: none; color: inherit;">
                    <div class="user-profile">
                        <div class="profile-avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            <?php if ($profile_img && file_exists($profile_img)): ?>
                                <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                            <?php endif; ?>
                        </div>
                        <span><?php echo htmlspecialchars($display_name); ?></span>
                    </div>
                </a>
            </header>

            <section class="hero-title">
                <p>Our shelter meeting point is at Caloocan City Hall. Find us on the map below.</p>
            </section>

            <?php // Palatandaan ng tunay na puwesto ng banner (para malaman kung nakadikit na) ?>
            <div id="bannerMark" aria-hidden="true"></div>
            <section class="highlight-banner is-sticky" id="locBanner">
                <div>
                    <span class="badge-highlight">Featured Location</span>
                    <h1>Caloocan City Hall</h1>
                    <p>Visit our shelter booth at Caloocan City Hall for adoption support and guidance.</p>
                </div>
                <div class="location-tag">
                    <i class="fa-solid fa-map-pin"></i>
                    <span>10th Ave, Grace Park West, Caloocan</span>
                </div>
            </section>

            <section class="shelter-section">
                <div class="shelter-grid">
                    <div class="map-card">
                        <div class="map-header">
                            <h2>Location Map</h2>
                            <p>Caloocan City Hall</p>
                        </div>
                        <div class="map-container">
                            <iframe
                                src="https://www.google.com/maps?q=Caloocan+City+Hall+Philippines&output=embed"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                title="Caloocan City Hall Map"
                            ></iframe>
                        </div>
                        <!-- Sariling mga button (ang "Open in Maps" sa loob ng mapa ay galing sa Google, hindi mababago) -->
                        <div class="map-actions">
                            <a class="map-action is-primary" href="https://www.google.com/maps/dir/?api=1&amp;destination=Caloocan+City+Hall+Philippines" target="_blank" rel="noopener">
                                <i class="fa-solid fa-diamond-turn-right"></i> Get Directions
                            </a>
                            <a class="map-action" href="https://www.google.com/maps/search/?api=1&amp;query=Caloocan+City+Hall+Philippines" target="_blank" rel="noopener">
                                <i class="fa-solid fa-map-location-dot"></i> Open in Google Maps
                            </a>
                        </div>
                        <div class="map-note">Tip: Use the map controls to explore the shelter area and nearby streets.</div>
                    </div>

                    <div class="info-card">
                        <h2>Address</h2>
                        <p class="location-name">Caloocan City Hall</p>
                        <p class="location-address">10th Ave, Grace Park West, Caloocan, 1400 Metro Manila, Philippines</p>
                        <div class="info-details">
                            <div>
                                <span>Phone</span>
                                <strong>(02) 361-0100</strong>
                            </div>
                            <div>
                                <span>Hours</span>
                                <strong>Mon–Fri · 8:00 AM – 5:00 PM</strong>
                            </div>
                            <div>
                                <span>Directions</span>
                                <strong>Near Caloocan City Hall, beside Victory Liner terminal.</strong>
                            </div>
                        </div>
                        <div class="location-card">
                            <span class="location-pill">Shelter Entrance</span>
                            <p>Drop by our shelter booth at the city hall grounds for animal care and adoption support.</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <script>
        // Telepono: liliit ang banner kapag nakadikit na sa itaas.
        // Ang #bannerMark (walang taas, nasa itaas ng banner) ang tinitingnan - hindi ito
        // gumagalaw kapag lumiit ang banner, kaya walang pabalik-balik na pagkislap.
        (function () {
            const banner = document.getElementById('locBanner');
            const mark = document.getElementById('bannerMark');
            const mq = window.matchMedia('(max-width: 900px)');
            let ticking = false;
            function update() {
                ticking = false;
                const stick = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sp-stick-top')) || 0;
                const stuck = mq.matches && mark.getBoundingClientRect().top < stick - 4;
                // spSetStuck (connection/responsive.php): walang pagkislap kapag lumiit ang banner
                if (window.spSetStuck) window.spSetStuck(banner, stuck); else banner.classList.toggle('is-stuck', stuck);
            }
            window.addEventListener('scroll', function () {
                if (!ticking) { ticking = true; requestAnimationFrame(update); }
            }, { passive: true });
            window.addEventListener('resize', update);
            update();
        })();
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
