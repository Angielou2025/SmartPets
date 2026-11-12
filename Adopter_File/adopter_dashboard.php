<?php
session_start();
require '../connection/database.php';
require '../connection/match_scoring.php';

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

// Kunin ang preferences ng adopter para maging batayan ng AI recommendation (computeMatchScore),
// yung parehong scoring engine na ginagamit sa Staff side.
$adopterPrefs = null;
try {
    $prefStmt = $pdo->prepare("SELECT * FROM adopter_preferences WHERE user_id = ?");
    $prefStmt->execute([$user_id]);
    $adopterPrefs = $prefStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $adopterPrefs = null;
}

// Fetch available pets from pet_inventory table. Hindi kasama ang alagang may nauna
// nang nag-apply na ibang adopter (sp_pet_open_sql sa connection/database.php)
try {
    $pets_stmt = $pdo->prepare("SELECT pet_id, name, kind, breed, age_group, gender, size, energy_level, temperament, shedding_level, health_condition, vaccination_status, description, photo_url, status FROM pet_inventory WHERE status = 'Available' AND " . sp_pet_open_sql() . " ORDER BY created_at DESC");
    $pets_stmt->execute([(int) $_SESSION['user_id']]);
    $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pets = [];
}
// Mga alaga na may aplikasyon na siya: "Applied" ang button, hindi "Adopt Now"
$myApplied = sp_my_applied_pets($pdo, (int) $_SESSION['user_id']);

$hasRecommendations = $adopterPrefs !== null && count($pets) > 0;

if ($hasRecommendations) {
    foreach ($pets as &$pet) {
        $pet['match_score'] = computeMatchScore($adopterPrefs, $pet);
    }
    unset($pet);
    // Ang bagay lang sa preferences ang irerekomenda - parehong hangganan ng
    // AI Recommendation page, kaya hindi lumalabas dito ang hindi akmang pet
    $pets = array_values(array_filter($pets, function ($p) {
        return $p['match_score'] >= MATCH_RECOMMEND_MIN;
    }));
    usort($pets, function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });
}

// 6 lang ang ipapakita sa dashboard - pinakamataas na match kung meron preferences, kundi pinakabago
$pets = array_slice($pets, 0, 6);

// Count available pets
try {
    $available_count = $pdo->query("SELECT COUNT(*) FROM pet_inventory WHERE status = 'Available'")->fetchColumn();
} catch (Exception $e) {
    $available_count = 0;
}

// Kunin ang totoong bilang ng na-adopt na pets para sa "Pet Adopted" widget (Monthly/Yearly)
function buildAdoptedTrend($pdo, $sql) {
    $raw = [];
    try {
        $stmt = $pdo->query($sql);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raw[$row['period']] = (int) $row['c'];
        }
    } catch (Exception $e) {
        // walang data
    }

    $current = end($raw) ?: 0;
    $keys = array_keys($raw);
    $previousKey = $keys[count($keys) - 2] ?? null;
    $previous = $previousKey !== null ? $raw[$previousKey] : 0;

    if ($previous > 0) {
        $change = round((($current - $previous) / $previous) * 100, 1);
    } else {
        $change = $current > 0 ? 100.0 : 0.0;
    }

    $bars = array_values($raw);
    $bars = array_slice($bars, -4);
    while (count($bars) < 4) {
        array_unshift($bars, 0);
    }
    $maxBar = max($bars) ?: 1;
    $barHeights = array_map(function ($v) use ($maxBar) {
        return $v > 0 ? max(15, round(($v / $maxBar) * 100)) : 6;
    }, $bars);

    return [
        'total' => $current,
        'change' => $change,
        'bars' => $barHeights,
    ];
}

$adoptedYearly = buildAdoptedTrend($pdo,
    "SELECT YEAR(updated_at) AS period, COUNT(*) AS c
     FROM adoption_applications
     WHERE application_status = 'Approved' AND YEAR(updated_at) >= YEAR(CURDATE()) - 3
     GROUP BY period
     ORDER BY period ASC"
);

$adoptedMonthly = buildAdoptedTrend($pdo,
    "SELECT DATE_FORMAT(updated_at, '%Y-%m') AS period, COUNT(*) AS c
     FROM adoption_applications
     WHERE application_status = 'Approved' AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
     GROUP BY period
     ORDER BY period ASC"
);

// Fetch adopter's applications para sa "Pending Application" widget
$appStmt = $pdo->prepare(
    "SELECT aa.application_id, aa.application_status, aa.created_at, aa.rejection_reason,
            p.name AS pet_name, p.photo_url AS pet_photo
     FROM adoption_applications aa
     JOIN pet_inventory p ON aa.pet_id = p.pet_id
     WHERE aa.adopter_id = ?
     ORDER BY aa.created_at DESC"
);
$appStmt->execute([$user_id]);
$myApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingApplications = array_values(array_filter($myApplications, function ($a) {
    return $a['application_status'] !== 'Approved';
}));
$latestApp = $pendingApplications[0] ?? null;

// May approved na adoption bang hindi pa bayad? Ito ang ipapaalala sa adopter
// pagka-login niya, para hindi niya kailangang maghanap sa sidebar.
$unpaidAdoptions = [];
try {
    $unpaidStmt = $pdo->prepare(
        "SELECT aa.application_id, p.name AS pet_name, p.photo_url AS pet_photo
         FROM adoption_applications aa
         JOIN pet_inventory p ON aa.pet_id = p.pet_id
         LEFT JOIN payments pay ON pay.application_id = aa.application_id
         WHERE aa.adopter_id = ?
           AND aa.application_status = 'Approved'
           AND (pay.payment_id IS NULL OR pay.status <> 'Paid')
         ORDER BY aa.updated_at DESC"
    );
    $unpaidStmt->execute([$user_id]);
    $unpaidAdoptions = $unpaidStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $unpaidAdoptions = [];
}

function dashboardStatusBadgeClass($status) {
    $map = [
        'Pending' => 'status-pending',
        'Under Review' => 'status-under-review',
        'Approved' => 'status-approved',
        'Rejected' => 'status-rejected',
    ];
    return $map[$status] ?? 'status-pending';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Adopter Dashboard</title>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
</head>
<body class="sp-list-scroll" style="background-color: #1b4d2e;">

<!-- Invisible SVG ClipPath Definition -->
<svg width="0" height="0" style="position: absolute;">
  <defs>
    <clipPath id="wavy-card-clip" clipPathUnits="objectBoundingBox">
      <path d="M 0,0.1 C 0.2,0.1 0.25,0 0.45,0 C 0.65,0 0.7,0.1 0.85,0.1 L 1,0.1 L 1,0.9 C 1,0.95 0.95,1 0.9,1 L 0.1,1 C 0.05,1 0,0.95 0,0.9 Z" />
    </clipPath>
  </defs>
</svg>

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
                <a href="adopter_dashboard.php" class="nav-item active"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="adopter_preferences.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i> My preference</a>
                <a href="ai_recommendation.php" class="nav-item"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Recommendation</a>
                <a href="my_pets.php" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>

            <!-- Bottom Illustration Placeholder -->
            <div class="sidebar-illustration">
                <img src="../Images/bg_design_pets.png" alt="Pets Illustration" onerror="this.style.display='none'">
            </div>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            
            <!-- TOP NAVBAR -->
            <header class="top-navbar">
                <div class="top-nav-links">
                    <a href="search_pets.php">Search Pets</a>
                    <a href="shelter.php">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>
                <!-- USER PROFILE LINK -->
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

            <!-- SLOGAN / SUBTITLE -->
            <section class="hero-title">
                <p>A smart pet adoption Matching system for animal shelter Using Reccommender System</p>
            </section>

            <!-- PAYMENT REMINDER: lumalabas lang kapag may approved adoption na hindi pa bayad -->
            <?php if (count($unpaidAdoptions) > 0): ?>
                <section class="payment-alert">
                    <div class="payment-alert-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
                    <div class="payment-alert-body">
                        <h3>
                            <?php if (count($unpaidAdoptions) === 1): ?>
                                Your adoption of <?php echo htmlspecialchars($unpaidAdoptions[0]['pet_name']); ?> is approved!
                            <?php else: ?>
                                You have <?php echo count($unpaidAdoptions); ?> approved adoptions awaiting payment
                            <?php endif; ?>
                        </h3>
                        <p>
                            Please settle the &#8369;500.00 adoption fee
                            <?php echo count($unpaidAdoptions) > 1 ? 'for each pet ' : ''; ?>
                            to complete your adoption.
                        </p>
                    </div>
                    <a href="payments.php" class="payment-alert-btn">
                        <i class="fa-solid fa-money-bill-wave"></i> Pay Now
                    </a>
                </section>
            <?php endif; ?>

            <!-- STATS CARDS ROW -->
            <section class="stats-grid">
                
                <!-- Card 1: Available Pets (napipindot ang buong card - tingnan ang script sa ibaba) -->
                <div class="stat-card yellow-card stat-link" data-href="search_pets.php" role="link" tabindex="0" aria-label="Available Pets: view all available pets">
                    <div class="stat-number"><?php echo $available_count; ?></div>
                    <div class="stat-label">Available Pets</div>
                </div>

                <!-- Card 2: Pet Adopted Graph -->
                <div class="stat-card graph-card">
                    <div class="graph-header">
                        <span>Pet Adopted</span>
                        <div class="toggle-pills">
                            <span class="pill" id="pillMonthly" onclick="setAdoptedView('monthly')">Monthly</span>
                            <span class="pill active" id="pillYearly" onclick="setAdoptedView('yearly')">Yearly</span>
                        </div>
                    </div>
                    <?php foreach (['yearly' => $adoptedYearly, 'monthly' => $adoptedMonthly] as $view => $data): ?>
                        <div class="graph-body adopted-view" id="adoptedView-<?php echo $view; ?>" style="<?php echo $view === 'monthly' ? 'display:none;' : ''; ?>">
                            <div class="graph-stats">
                                <span class="big-num"><?php echo number_format($data['total']); ?></span>
                                <span class="badge <?php echo $data['change'] < 0 ? 'down' : ''; ?>">
                                    <i class="fa-solid fa-arrow-<?php echo $data['change'] < 0 ? 'down' : 'up'; ?>"></i>
                                    <?php echo abs($data['change']); ?>%
                                </span>
                            </div>
                            <div class="mock-chart">
                                <?php foreach ($data['bars'] as $i => $h): ?>
                                    <div class="bar<?php echo $i === count($data['bars']) - 1 ? ' bar-current' : ''; ?>" style="height: <?php echo $h; ?>%;"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Card 3: Pending Applications -->
                <div class="stat-card green-card stat-link" data-href="my_applications.php" role="link" tabindex="0" aria-label="Pending Application: view all applications">
                    <div class="pending-content">
                        <h3>Pending Application</h3>
                        <?php if ($latestApp): ?>
                            <div class="application-widget">
                                <?php if (!empty($latestApp['pet_photo'])): ?>
                                    <img class="app-pet-thumb" src="../uploads/<?php echo htmlspecialchars($latestApp['pet_photo']); ?>" alt="<?php echo htmlspecialchars($latestApp['pet_name']); ?>" onerror="this.src='https://via.placeholder.com/46'">
                                <?php else: ?>
                                    <img class="app-pet-thumb" src="https://via.placeholder.com/46" alt="<?php echo htmlspecialchars($latestApp['pet_name']); ?>">
                                <?php endif; ?>
                                <div class="app-widget-info">
                                    <p class="app-pet-name"><?php echo htmlspecialchars($latestApp['pet_name']); ?></p>
                                    <p class="app-date">Applied: <?php echo date('M d, Y', strtotime($latestApp['created_at'])); ?></p>
                                    <span class="status-badge <?php echo dashboardStatusBadgeClass($latestApp['application_status']); ?>">
                                        <?php echo htmlspecialchars($latestApp['application_status']); ?>
                                    </span>
                                    <?php if ($latestApp['application_status'] === 'Rejected' && !empty($latestApp['rejection_reason'])): ?>
                                        <p class="rejection-note"><?php echo htmlspecialchars($latestApp['rejection_reason']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="pending-body">
                                <div class="icon-circle">
                                    <i class="fa-solid fa-id-card"></i>
                                </div>
                                <div class="app-count">
                                    <h2>0</h2>
                                    <p>Applications</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <a href="my_applications.php" class="view-link">View all applications <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>

            </section>

            <!-- FEATURED PETS SECTION -->
            <section class="featured-section sp-scroll-host">
                <h2 class="sp-list-head"><span class="sp-head-ico"><i class="fa-solid <?php echo $hasRecommendations ? 'fa-wand-magic-sparkles' : 'fa-paw'; ?>"></i></span><?php echo $hasRecommendations ? 'Recommended For You' : 'Available Pets'; ?> <span class="sp-count" data-word="pet"></span></h2>
                <?php if (!$hasRecommendations): ?>
                    <p style="color:#e5e7eb; margin:-8px 0 16px 0; font-size:14px;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <a href="adopter_preferences.php" style="color:#ffd166; font-weight:600;">Set your preferences</a> to get personalized recommendations based on our available pets.
                    </p>
                <?php endif; ?>

                <div class="pets-grid">
                    <?php if (count($pets) > 0): ?>
                        <?php foreach ($pets as $pet): ?>
                            <div class="pet-card">
                                <button class="fav-btn" type="button" data-pet-id="<?php echo htmlspecialchars($pet['pet_id']); ?>" aria-label="Add <?php echo htmlspecialchars($pet['name']); ?> to favorites" aria-pressed="false"><i class="fa-regular fa-heart"></i></button>
                                <!-- Nakaayos pa rin ayon sa pagkakabagay (pinakabagay ang una), pero
                                     hindi na ipinapakita ang porsyento para hindi makita ng adopter
                                     ang loob ng matching -->
                                <!-- Top Portion: Image + Info -->
                                <div class="card-top">
                                    <div class="pet-img-container">
                                        <?php if (!empty($pet['photo_url'])): ?>
                                            <img src="../uploads/<?php echo htmlspecialchars($pet['photo_url']); ?>" alt="<?php echo htmlspecialchars($pet['name']); ?>" onerror="this.src='https://via.placeholder.com/150'">
                                        <?php else: ?>
                                            <img src="https://via.placeholder.com/150" alt="<?php echo htmlspecialchars($pet['name']); ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div class="pet-info">
                                        <h3><?php echo htmlspecialchars($pet['name']); ?></h3>
                                        <p class="breed"><?php echo htmlspecialchars($pet['breed']); ?></p>
                                        <p class="meta"><?php echo htmlspecialchars($pet['age_group']); ?><br><?php echo htmlspecialchars($pet['gender']); ?></p>
                                    </div>
                                </div>

                                <!-- Bottom Portion: Action Buttons -->
                                <div class="card-actions">
                                    <a href="pet_details.php?pet_id=<?php echo urlencode($pet['pet_id']); ?>" class="btn-orange">View Details</a>
                                    <?php if (isset($myApplied[(int) $pet['pet_id']])): ?>
                                        <span class="btn-text is-applied" title="You already applied for <?php echo htmlspecialchars($pet['name']); ?> (<?php echo htmlspecialchars($myApplied[(int) $pet['pet_id']]); ?>)"><i class="fa-solid fa-check"></i> Applied</span>
                                    <?php else: ?>
                                        <a href="adoption_application.php?pet_id=<?php echo urlencode($pet['pet_id']); ?>" class="btn-text">Adopt Now</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #fff;">
                            <?php if ($hasRecommendations): ?>
                                <p>None of our available pets match your preferences closely yet.</p>
                                <p style="margin-top:8px;"><a href="adopter_preferences.php" style="color:#ffd166; font-weight:600;">Update your preferences</a> or <a href="search_pets.php" style="color:#ffd166; font-weight:600;">browse all pets</a>.</p>
                            <?php else: ?>
                                <p>No available pets at the moment.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        </main>
    </div>

    <script>
        function setAdoptedView(view) {
            document.getElementById('adoptedView-yearly').style.display = view === 'yearly' ? 'flex' : 'none';
            document.getElementById('adoptedView-monthly').style.display = view === 'monthly' ? 'flex' : 'none';
            document.getElementById('pillYearly').classList.toggle('active', view === 'yearly');
            document.getElementById('pillMonthly').classList.toggle('active', view === 'monthly');
        }

        // Favorites: kapareho ng sa Search Pets (naka-save sa browser, 'smartPetFavorites'),
        // kaya lumalabas din sa Favorites page ang mga pinusuan dito
        (function () {
            const favoritesKey = 'smartPetFavorites';

            function getFavoriteIds() {
                try { return (JSON.parse(localStorage.getItem(favoritesKey)) || []).map(String); }
                catch (e) { return []; }
            }

            function saveFavoriteIds(ids) {
                try { localStorage.setItem(favoritesKey, JSON.stringify(ids)); } catch (e) {}
            }

            function paint() {
                const ids = getFavoriteIds();
                document.querySelectorAll('.fav-btn[data-pet-id]').forEach(function (btn) {
                    const on = ids.indexOf(btn.dataset.petId) !== -1;
                    const icon = btn.querySelector('i');
                    icon.classList.toggle('fa-solid', on);
                    icon.classList.toggle('fa-regular', !on);
                    btn.classList.toggle('favorited', on);
                    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            }

            document.querySelectorAll('.fav-btn[data-pet-id]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const ids = getFavoriteIds();
                    const i = ids.indexOf(btn.dataset.petId);
                    if (i === -1) ids.push(btn.dataset.petId); else ids.splice(i, 1);
                    saveFavoriteIds(ids);
                    paint();
                    // Maliit na "pop" kapag pinusuan
                    if (i === -1 && btn.animate) {
                        btn.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.3)' }, { transform: 'scale(1)' }], { duration: 320, easing: 'ease-out' });
                    }
                });
            });

            paint();
            // Kapag binago sa ibang tab (hal. Favorites page), i-update din dito
            window.addEventListener('storage', function (e) { if (e.key === favoritesKey) paint(); });
        })();

        // Napipindot ang buong stat card. Hindi kasama ang Monthly/Yearly at ang
        // mga link sa loob ng card (gumagana pa rin sila nang mag-isa).
        document.querySelectorAll('.stat-link').forEach(function (card) {
            function go(e) {
                if (e.target.closest('.toggle-pills, a, button')) return;
                if (e.ctrlKey || e.metaKey) {
                    window.open(card.dataset.href, '_blank');
                } else {
                    window.location.href = card.dataset.href;
                }
            }
            card.addEventListener('click', go);
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    go(e);
                }
            });
        });
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
<script src="list_count.js?v=<?php echo @filemtime(__DIR__ . '/list_count.js'); ?>"></script>
</body>
</html>
