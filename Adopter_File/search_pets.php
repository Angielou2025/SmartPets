<?php
session_start();
require '../connection/database.php';
require_once '../connection/match_scoring.php';

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

// Check kung meron nang naka-fill up na reference/preferences
$prefStmt = $pdo->prepare("SELECT * FROM adopter_preferences WHERE user_id = ? LIMIT 1");
$prefStmt->execute([$user_id]);
$prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);
$hasPrefs = (bool) $prefs;

$showPrefsPopup = !$hasPrefs && !isset($_SESSION['prefs_prompt_skipped']);

// Account Settings modal: lumalabas pagkatapos ng loading screen kapag kakalogin
// lang at kulang pa ang profile (walang photo o walang recovery email).
// Habang bukas ito, naghihintay muna ang "Punan ang Reference" popup.
$showProfileSetup = false;
if (!empty($_SESSION['profile_setup_pending'])) {
    $psStmt = $pdo->prepare("SELECT username, email, recovery_email, profile_image FROM users WHERE user_id = ?");
    $psStmt->execute([$user_id]);
    $psUser = $psStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $showProfileSetup = empty($psUser['profile_image']) || empty($psUser['recovery_email']);
    if (!$showProfileSetup) {
        unset($_SESSION['profile_setup_pending']);
    }
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Fetch available pets from pet_inventory table. Hindi kasama ang alagang may nauna
// nang nag-apply na ibang adopter (sp_pet_open_sql sa connection/database.php)
try {
    $pets_stmt = $pdo->prepare("SELECT pet_id, name, kind, breed, age_group, gender, size, energy_level, temperament, health_condition, vaccination_status, description, photo_url, status FROM pet_inventory WHERE status = 'Available' AND " . sp_pet_open_sql() . " ORDER BY created_at DESC");
    $pets_stmt->execute([(int) $_SESSION['user_id']]);
    $allPets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allPets = [];
}
// Mga alaga na may aplikasyon na siya: "Applied" ang button, hindi "Adopt Now"
$myApplied = sp_my_applied_pets($pdo, (int) $_SESSION['user_id']);

if ($hasPrefs) {
    // Matched mode: itago ang mga pets na mababa ang match %, isort by highest match
    $pets = [];
    foreach ($allPets as $pet) {
        $score = computeMatchScore($prefs, $pet);
        if ($score >= MATCH_THRESHOLD) {
            $pet['match_score'] = $score;
            $pets[] = $pet;
        }
    }
    usort($pets, function ($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });
} else {
    $pets = $allPets;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Search Pets</title>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
</head>
<body class="sp-list-scroll" style="background-color: #1b4d2e;">

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
                    <a href="search_pets.php" class="active">Search Pets</a>
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
                <p>Find your perfect companion from our available pets</p>
            </section>

            <?php if (!empty($success_message)): ?>
                <div id="spFlashOk" style="background:#e8f5e9; color:#2e7d32; padding:10px 15px; border-radius:6px; margin-bottom:15px; overflow:hidden;">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
                <script>
                    // Nawawala ang mensahe sa unang galaw ng scroll (dahan-dahang lumiliit)
                    (function () {
                        var box = document.getElementById('spFlashOk');
                        var gone = false;
                        function hide() {
                            if (gone) return;
                            gone = true;
                            ['wheel', 'touchmove', 'keydown'].forEach(function (ev) { window.removeEventListener(ev, hide, true); });
                            document.removeEventListener('scroll', hide, true);
                            var h = box.offsetHeight;
                            var anim = box.animate ? box.animate([
                                { height: h + 'px', opacity: 1, marginBottom: '15px', paddingTop: '10px', paddingBottom: '10px' },
                                { height: '0px', opacity: 0, marginBottom: '0px', paddingTop: '0px', paddingBottom: '0px' }
                            ], { duration: 300, easing: 'cubic-bezier(.4, 0, .2, 1)' }) : null;
                            if (anim) anim.onfinish = function () { box.remove(); };
                            else box.remove();
                        }
                        // Scroll ng listahan/page, mouse wheel, daliri, o arrow keys.
                        // Nagsisimula lang pagkatapos mag-load, para hindi mahuli ang mga
                        // kusang scroll ng page habang naglo-load (hindi galaw ng user).
                        function listen() {
                            document.addEventListener('scroll', hide, { capture: true, passive: true });
                            window.addEventListener('wheel', hide, { capture: true, passive: true });
                            window.addEventListener('touchmove', hide, { capture: true, passive: true });
                            window.addEventListener('keydown', function (e) {
                                if (['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Space', 'End', 'Home'].indexOf(e.code) !== -1) hide();
                            }, true);
                        }
                        window.addEventListener('load', function () { setTimeout(listen, 800); });
                    })();
                </script>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div style="background:#fce8e6; color:#c5221f; padding:10px 15px; border-radius:6px; margin-bottom:15px;">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php // Kapag may naka-fill up nang preference, wala na ang mga filter:
                  // ang preference na ang pumipili at nag-aayos ng mga pet. ?>
            <?php if (!$hasPrefs): ?>
            <!-- FILTER SECTION -->
            <section class="search-filter-section">
                <div class="filter-container">
                    <div class="filter-group">
                        <label>Kind</label>
                        <select id="filterKind" onchange="filterPets()">
                            <option value="">All</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Age Group</label>
                        <select id="filterAge" onchange="filterPets()">
                            <option value="">All</option>
                            <option value="Puppy/Kitten">Puppy/Kitten</option>
                            <option value="Young Adult">Young Adult</option>
                            <option value="Adult">Adult</option>
                            <option value="Senior">Senior</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Gender</label>
                        <select id="filterGender" onchange="filterPets()">
                            <option value="">All</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Size</label>
                        <select id="filterSize" onchange="filterPets()">
                            <option value="">All</option>
                            <option value="Small">Small</option>
                            <option value="Medium">Medium</option>
                            <option value="Large">Large</option>
                        </select>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- PETS GRID SECTION -->
            <section class="pets-section sp-scroll-host">
                <!-- Iisang hanay: title sa kaliwa, search bar + bilog (i) sa kanan.
                     Pindot sa bilog: lumalabas ang paalala sa ilalim ng hanay;
                     ✕: natitiklop at bumabalik sa tabi ng search bar. -->
                <div class="search-zone sp-head-row<?php echo $hasPrefs ? ' has-note' : ''; ?>" id="searchZone">
                    <h2 class="sp-list-head"><span class="sp-head-ico"><i class="fa-solid fa-paw"></i></span>Available Pets <span class="sp-count" data-word="pet"></span></h2>

                    <div class="search-row">
                        <!-- label: kahit saan pindutin sa kahon, diretso sa pag-type -->
                        <label class="search-container" for="searchInput">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="searchInput" placeholder="Search by name or breed.." onkeyup="filterPets()">
                        </label>
                    </div>

                    <?php if ($hasPrefs): ?>
                        <?php // Naaalala sa browser; bukas ulit kapag binago ang preference.
                              $prefNoteKey = 'sp_pref_note_closed_' . (int) $user_id . '_' . substr(md5(json_encode($prefs)), 0, 8); ?>
                        <div class="pref-note" id="prefNote" data-key="<?php echo htmlspecialchars($prefNoteKey); ?>">
                            <script>try { if (localStorage.getItem(document.getElementById('prefNote').dataset.key) === '1') document.getElementById('prefNote').classList.add('is-dot', 'no-anim'); } catch (e) {}</script>
                            <span class="pref-note-text">Ipinapakita ang mga pets na tugma sa iyong reference, naka-sort mula sa pinakamataas na match.</span>
                            <button type="button" class="pref-note-close" aria-label="Close">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                            </button>
                            <button type="button" class="pref-note-dot" aria-label="Show note" title="Show note">
                                <i class="fa-solid fa-info"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="pets-grid">
                    <?php if (count($pets) > 0): ?>
                        <?php foreach ($pets as $pet): ?>
                            <div class="pet-card" data-pet-id="<?php echo htmlspecialchars($pet['pet_id']); ?>" data-kind="<?php echo htmlspecialchars($pet['kind']); ?>" data-age="<?php echo htmlspecialchars($pet['age_group']); ?>" data-gender="<?php echo htmlspecialchars($pet['gender']); ?>" data-size="<?php echo htmlspecialchars($pet['size']); ?>" data-name="<?php echo htmlspecialchars($pet['name']); ?>" data-breed="<?php echo htmlspecialchars($pet['breed']); ?>">
                                <button class="fav-btn" type="button" data-pet-id="<?php echo htmlspecialchars($pet['pet_id']); ?>" aria-label="Favorite"><i class="fa-regular fa-heart"></i></button>
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
                            <p>No available pets at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        </main>
    </div>

    <?php if ($showProfileSetup) include __DIR__ . '/_profile_setup_modal.php'; ?>

    <?php if ($showPrefsPopup): ?>
    <div id="prefsPromptOverlay" class="prefs-prompt-overlay"<?php echo $showProfileSetup ? ' style="display:none"' : ''; ?>>
        <div class="prefs-prompt-box">
            <?php // Walang sariling English/Tagalog toggle: sumusunod sa wikang pinili sa
                  // Account Settings (isinasalin ng connection/i18n.php) ?>
            <h3 id="prefsPromptTitle">Fill up your reference</h3>
            <p id="prefsPromptBody">Answer your lifestyle and pet preferences so we can show you the pets that best match you. You can also skip this and come back to it later in "My preference".</p>
            <div class="prefs-prompt-actions">
                <button type="button" id="skipPrefsBtn" class="btn-text">Skip</button>
                <a href="adopter_preferences.php" class="btn-orange" id="prefsPromptFillBtn">Fill Up Reference</a>
            </div>
        </div>
    </div>
    <style>
        .prefs-prompt-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .prefs-prompt-box {
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .prefs-prompt-box h3 {
            margin-bottom: 12px;
            color: #1b4d2e;
        }
        .prefs-prompt-box p {
            color: #555;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .prefs-prompt-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        .match-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: #1f6b48;
            color: #fff;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            z-index: 2;
        }
    </style>
    <script>
        const skipBtn = document.getElementById('skipPrefsBtn');
        if (skipBtn) {
            skipBtn.addEventListener('click', function() {
                fetch('dismiss_prefs_prompt.php', { method: 'POST' })
                    .finally(() => {
                        document.getElementById('prefsPromptOverlay').style.display = 'none';
                    });
            });
        }

    </script>
    <?php endif; ?>

    <script>
        // Paalala: sarado = bilog (i) sa kanan ng search bar.
        // Pindot sa bilog = lumalabas ang paalala sa ilalim, mula itaas pababa;
        // ✕ = bilog agad, may maliit na talbog (taas, tapos balik sa normal).
        (function () {
            const note = document.getElementById('prefNote');
            const zone = document.getElementById('searchZone');
            if (!note || !zone) return;
            const search = zone.querySelector('.search-container');
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let busy = false;

            // Para nasa tabi ng search bar ang bilog, nasa gitna ng taas nito
            function syncSearchHeight() {
                const z = zone.getBoundingClientRect(), s = search.getBoundingClientRect();
                zone.style.setProperty('--dot-top', (s.top - z.top + (s.height - 38) / 2) + 'px');
            }
            syncSearchHeight();
            window.addEventListener('resize', syncSearchHeight);
            window.addEventListener('load', syncSearchHeight);   // pagkatapos mag-load ang fonts/larawan
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(syncSearchHeight);

            // Kung sarado na noong nag-load: bilog agad, walang animation
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { note.classList.remove('no-anim'); });
            });

            function setDot(on) {
                if (busy) return;
                syncSearchHeight();
                const zh0 = zone.offsetHeight;
                note.classList.toggle('is-dot', on);
                const zh1 = zone.offsetHeight;

                if (!on && !reduce && note.animate) {
                    // Pagbukas: walang buklat - lumalabas lang ang paalala mula itaas pababa
                    busy = true;
                    const opts = { duration: 380, easing: 'cubic-bezier(.22, 1, .36, 1)' };
                    Promise.all([
                        note.animate([
                            { clipPath: 'inset(0 0 100% 0 round 10px)', transform: 'translateY(-6px)' },
                            { clipPath: 'inset(0 0 0 0 round 10px)', transform: 'none' }
                        ], opts).finished,
                        zone.animate([{ height: zh0 + 'px' }, { height: zh1 + 'px' }], opts).finished
                    ]).then(done, done);
                } else if (!reduce && note.animate) {
                    // Pagsara: walang tiklop - bilog agad, may maliit na talbog (taas, tapos balik)
                    busy = true;
                    Promise.all([
                        note.animate([
                            { transform: 'translateY(0)' },
                            { transform: 'translateY(-6px)', offset: .4 },
                            { transform: 'translateY(0)' }
                        ], { duration: 340, easing: 'ease-out' }).finished,
                        // Para hindi biglang tumalon ang listahan sa ilalim
                        zone.animate([{ height: zh0 + 'px' }, { height: zh1 + 'px' }],
                            { duration: 220, easing: 'cubic-bezier(.22, 1, .36, 1)' }).finished
                    ]).then(done, done);
                }

                try {
                    if (on) localStorage.setItem(note.dataset.key, '1');
                    else localStorage.removeItem(note.dataset.key);
                } catch (e) {}
                (on ? note.querySelector('.pref-note-dot') : note.querySelector('.pref-note-close'))
                    .focus({ preventScroll: true, focusVisible: false });
            }
            function done() { busy = false; }
            note.querySelector('.pref-note-close').addEventListener('click', function () { setDot(true); });
            note.querySelector('.pref-note-dot').addEventListener('click', function () { setDot(false); });
        })();

        function filterPets() {
            // Wala ang mga filter kapag may preference na - ituring na "All"
            const val = id => { const el = document.getElementById(id); return el ? el.value.toLowerCase() : ''; };
            const searchInput = val('searchInput');
            const kindFilter = val('filterKind');
            const ageFilter = val('filterAge');
            const genderFilter = val('filterGender');
            const sizeFilter = val('filterSize');
            
            const petCards = document.querySelectorAll('.pet-card');
            
            petCards.forEach(card => {
                const name = card.dataset.name.toLowerCase();
                const breed = card.dataset.breed.toLowerCase();
                const kind = card.dataset.kind.toLowerCase();
                const age = card.dataset.age.toLowerCase();
                const gender = card.dataset.gender.toLowerCase();
                const size = card.dataset.size.toLowerCase();
                
                const matchesSearch = name.includes(searchInput) || breed.includes(searchInput);
                const matchesKind = kindFilter === '' || kind === kindFilter;
                const matchesAge = ageFilter === '' || age === ageFilter;
                const matchesGender = genderFilter === '' || gender === genderFilter;
                const matchesSize = sizeFilter === '' || size === sizeFilter;
                
                if (matchesSearch && matchesKind && matchesAge && matchesGender && matchesSize) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        const favoritesKey = 'smartPetFavorites';

        function getFavoriteIds() {
            try {
                return JSON.parse(localStorage.getItem(favoritesKey)) || [];
            } catch (e) {
                return [];
            }
        }

        function saveFavoriteIds(ids) {
            localStorage.setItem(favoritesKey, JSON.stringify(ids));
        }

        function toggleFavorite(petId) {
            const ids = getFavoriteIds();
            const index = ids.indexOf(petId);
            if (index === -1) {
                ids.push(petId);
            } else {
                ids.splice(index, 1);
            }
            saveFavoriteIds(ids);
            updateFavoriteButtons();
        }

        function updateFavoriteButtons() {
            const favoriteIds = getFavoriteIds();
            document.querySelectorAll('.fav-btn').forEach(btn => {
                const petId = btn.dataset.petId;
                const icon = btn.querySelector('i');
                if (favoriteIds.includes(petId)) {
                    icon.classList.remove('fa-regular');
                    icon.classList.add('fa-solid');
                    btn.classList.add('favorited');
                } else {
                    icon.classList.remove('fa-solid');
                    icon.classList.add('fa-regular');
                    btn.classList.remove('favorited');
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateFavoriteButtons();
            document.querySelectorAll('.fav-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    toggleFavorite(btn.dataset.petId);
                });
            });
        });
    </script>

    <?php include '../connection/chat_bubble.php'; ?>
<script src="list_count.js?v=<?php echo @filemtime(__DIR__ . '/list_count.js'); ?>"></script>
</body>
</html>
