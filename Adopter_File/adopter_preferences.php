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
$sp_unread_count = sp_unread_message_count($pdo, (int) $_SESSION['user_id']);
$user_stmt = $pdo->prepare("SELECT username, profile_image FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$display_name = !empty($current_user['username']) ? $current_user['username'] : $_SESSION['username'];
$profile_img = !empty($current_user['profile_image']) ? '../uploads/' . $current_user['profile_image'] : null;

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Kunin ang mga breed na naka-store sa mga available pets, para gamitin sa Preferred Breed dropdown
try {
    $breedStmt = $pdo->query("SELECT DISTINCT breed FROM pet_inventory WHERE status = 'Available' AND breed IS NOT NULL AND breed <> '' ORDER BY breed ASC");
    $availableBreeds = $breedStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $availableBreeds = [];
}

// Katangian ng bawat available pet - dito kinukuha ng Step 2 ang mga pagpipilian,
// para ang lalabas lang ay ang talagang meron (hal. Dog -> mga breed ng aso lang)
try {
    $availablePets = $pdo->query(
        "SELECT kind, breed, temperament, energy_level, size, age_group, gender, health_condition
         FROM pet_inventory WHERE status = 'Available'"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $availablePets = [];
}

// Lahat ng iba't ibang value ng isang column sa mga available na alaga (walang blangko).
// Ang temperament ay listahan ("Playful, Curious"), kaya hinahati sa bawat ugali.
const SP_PREF_ORDER = [
    'kind'         => ['Dog', 'Cat'],
    'energy_level' => ['Low', 'Moderate', 'High'],
    'size'         => ['Small', 'Medium', 'Large'],
    'age_group'    => ['Puppy/Kitten', 'Young Adult', 'Adult', 'Senior'],
    'gender'       => ['Male', 'Female'],
];
function sp_pref_values(array $pets, string $col): array
{
    $values = [];
    foreach ($pets as $p) {
        $raw = trim((string) ($p[$col] ?? ''));
        if ($raw === '') continue;
        foreach ($col === 'temperament' ? explode(',', $raw) : [$raw] as $v) {
            $v = trim($v);
            if ($v !== '') $values[$v] = true;
        }
    }
    $values = array_keys($values);
    $order = SP_PREF_ORDER[$col] ?? [];
    usort($values, function ($a, $b) use ($order) {
        $ia = array_search($a, $order, true);
        $ib = array_search($b, $order, true);
        if ($ia !== false || $ib !== false) return ($ia === false ? 999 : $ia) <=> ($ib === false ? 999 : $ib);
        return strcasecmp($a, $b);
    });
    return $values;
}
// <select>: "Any" lang kapag higit sa isa ang pagpipilian; kapag iisa, iyon na ang napili
function sp_pref_select(string $name, array $values, ?string $preferred = null): string
{
    $html = '<select name="' . $name . '">';
    if (count($values) !== 1) {
        $anySelected = !($preferred !== null && in_array($preferred, $values, true));
        $html .= '<option value="Any"' . ($anySelected ? ' selected' : '') . '>Any</option>';
    }
    foreach ($values as $v) {
        $sel = (count($values) === 1 || $v === $preferred) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($v) . '"' . $sel . '>' . htmlspecialchars($v) . '</option>';
    }
    return $html . '</select>';
}

// Wala na ang Schedule step dito. Ang lumang rebooking link (email/abiso) ay
// dinadala na sa Schedule, kung saan lumalabas ang rebooking popup.
if (isset($_GET['rebook'])) {
    header('Location: schedule.php?rebook=' . (int) $_GET['rebook']);
    exit();
}
if (($_GET['step'] ?? '') === 'schedule') {
    header('Location: search_pets.php');
    exit();
}
$showSchedule = false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Adopter Preferences</title>
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        .avail-head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 16px; }
        .avail-head > i {
            width: 42px; height: 42px; min-width: 42px; border-radius: 50%;
            background: #eaf3ee; color: #1b4d2e; display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .avail-head h3 { font-size: 17px; color: #1b4d2e; margin: 0 0 3px; }
        .avail-head p { font-size: 13px; color: #6b7280; margin: 0; }
        .avail-list { display: flex; flex-direction: column; gap: 10px; max-height: 320px; overflow-y: auto; padding-right: 4px; }
        .avail-day { display: flex; gap: 14px; align-items: center; background: #f8faf8; border: 1px solid #e5ece7; border-radius: 12px; padding: 10px 14px; }
        .avail-date {
            min-width: 50px; text-align: center; border-radius: 10px; padding: 6px 4px; color: #fff;
            background: linear-gradient(160deg, #2e5b32, #1b4d2e);
        }
        .avail-date .d { display: block; font-size: 18px; font-weight: 800; line-height: 1; }
        .avail-date .m { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .avail-times { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .avail-dow { width: 100%; font-size: 12.5px; font-weight: 700; color: #1b4d2e; }
        .avail-chip {
            font-size: 12px; font-weight: 600; color: #1b4d2e; background: #fff;
            border: 1px solid #cfe3d6; border-radius: 16px; padding: 4px 10px;
        }
        /* Rebooking: napipindot na oras */
        .avail-chip.pickable { padding: 0; border: none; background: none; cursor: pointer; }
        .avail-chip.pickable input { position: absolute; opacity: 0; pointer-events: none; }
        .avail-chip.pickable span {
            display: inline-block; font-size: 12.5px; font-weight: 700; color: #1b4d2e; background: #fff;
            border: 1.5px solid #cfe3d6; border-radius: 16px; padding: 6px 12px; transition: all 0.14s ease;
        }
        .avail-chip.pickable:hover span { border-color: #2e5b32; }
        .avail-chip.pickable input:checked + span { background: #2e5b32; border-color: #2e5b32; color: #fff; }
        .avail-chip.pickable input:focus-visible + span { box-shadow: 0 0 0 3px rgba(46, 91, 50, 0.25); }
        .rebook-banner {
            display: flex; gap: 12px; align-items: flex-start; background: #fff4e5; border: 1px solid #f6d6a8;
            border-radius: 12px; padding: 12px 14px; margin-bottom: 14px;
        }
        .rebook-banner > i { color: #d9822b; font-size: 18px; margin-top: 2px; }
        .rebook-banner strong { display: block; color: #1b4d2e; font-size: 14px; }
        .rebook-banner span { display: block; font-size: 13px; color: #555; margin-top: 2px; }
        .rebook-label { display: block; font-size: 13px; font-weight: 700; color: #1b4d2e; margin: 10px 0 6px; }
        .rebook-reason {
            width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px;
            font-family: inherit; font-size: 13.5px; resize: vertical;
        }
        .avail-empty { text-align: center; color: #888; border: 2px dashed #e0e0e0; border-radius: 12px; padding: 24px 16px; }
        .avail-empty i { font-size: 26px; color: #cbd5cf; margin-bottom: 8px; display: block; }
        .avail-empty p { margin: 0; font-size: 13px; }
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
                <a href="adopter_preferences.php" class="nav-item active"><i class="fa-solid fa-clipboard-list"></i> My preference</a>
                <a href="ai_recommendation.php" class="nav-item"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Recommendation</a>
                <a href="my_pets.php" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>

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

            <!-- ADOPTER PREFERENCES FORM -->
            <div class="preferences-form-container">
                
                <div class="form-header">
                    <h2>Adopter Preferences</h2>
                    <p>Set up your profile and pet preferences for better matching</p>
                </div>

                <?php if (!empty($success_message)): ?>
                    <div style="background:#e8f5e9; color:#2e7d32; padding:10px 15px; border-radius:6px; margin-bottom:15px;">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:6px; margin-bottom:15px;">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Step Progress Bar -->
                <div class="step-progress">
                    <div id="step1-indicator" class="step-item<?php echo $showSchedule ? ' active' : ' current'; ?>">
                        <span class="step-number">1</span>
                        <span class="step-label">Lifestyle</span>
                    </div>
                    <div class="progress-line">
                        <div id="progress-bar" class="progress-fill<?php echo $showSchedule ? '' : ' is-current'; ?>"<?php echo $showSchedule ? ' style="width:100%"' : ''; ?>></div>
                    </div>
                    <div id="step2-indicator" class="step-item">
                        <span class="step-number">2</span>
                        <span class="step-label">Pet Criteria</span>
                    </div>
                </div>


                <!-- Main Form -->
                <form action="save_preferences.php" method="POST" id="preferencesForm">
                    
                    <!-- STEP 1: Adopter Lifestyle -->
                    <div id="step-1" class="form-step">
                        <h3>Lifestyle & Living Space</h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Living Space</label>
                                <select name="living_space" required>
                                    <option value="Apartment">Apartment</option>
                                    <option value="Condo">Condo</option>
                                    <option value="House with Small Space">House with Small Space</option>
                                    <option value="House with Yard" selected>House with Yard</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Time Commitment</label>
                                <select name="time_commitment" required>
                                    <option value="Less than 2 Hours">Less than 2 Hours</option>
                                    <option value="2-4 Hours" selected>2-4 Hours</option>
                                    <option value="5-8 Hours">5-8 Hours</option>
                                    <option value="Almost Whole Day">Almost Whole Day</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Activity Level</label>
                                <select name="activity_level" required>
                                    <option value="Low/Chill">Low/Chill</option>
                                    <option value="Moderate" selected>Moderate</option>
                                    <option value="Active">Active</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Pet Experience</label>
                                <select name="pet_experience" required>
                                    <option value="Beginner" selected>Beginner</option>
                                    <option value="Intermediate">Intermediate</option>
                                    <option value="Experienced">Experienced</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Allergies</label>
                                <select name="allergies" required>
                                    <option value="None" selected>None</option>
                                    <option value="Fur Allergy">Fur Allergy</option>
                                    <option value="Sensitive to Shedding">Sensitive to Shedding</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Household Composition</label>
                                <select name="household_composition" required>
                                    <option value="Lives Alone" selected>Lives Alone</option>
                                    <option value="With Children">With Children</option>
                                    <option value="With Elderly">With Elderly</option>
                                    <option value="With Other Pets">With Other Pets</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Noise Tolerance</label>
                                <select name="noise_tolerance" required>
                                    <option value="Low">Low</option>
                                    <option value="Moderate" selected>Moderate</option>
                                    <option value="High">High</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Maintenance Level</label>
                                <select name="maintenance_level" required>
                                    <option value="Low Maintenance">Low Maintenance</option>
                                    <option value="Moderate" selected>Moderate</option>
                                    <option value="High Maintenance">High Maintenance</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label>Adoption Intent</label>
                            <select name="adoption_intent" required>
                                <option value="Companion" selected>Companion</option>
                                <option value="Guard Pet">Guard Pet</option>
                                <option value="Emotional Support">Emotional Support</option>
                                <option value="Family Pet">Family Pet</option>
                            </select>
                        </div>

                        <div class="form-actions">
                            <button type="button" onclick="nextStep()" class="btn-next">Next: Pet Criteria &rarr;</button>
                        </div>
                    </div>

                    <!-- STEP 2: Pet Preferences -->
                    <div id="step-2" class="form-step hidden">
                        <h3>Pet Criteria & Preferences</h3>

                        <div class="form-grid">
                            <?php
                            // Lahat ng pagpipilian ay galing sa mga available na alaga lang
                            // (sp_pref_select sa itaas). Ang JS sa ibaba ang nagsasala pa
                            // ayon sa mga naunang napili.
                            $prefFields = [
                                ['Preferred Kind', 'pref_kind', 'kind'],
                                ['Preferred Breed', 'pref_breed', 'breed'],
                                ['Preferred Temperament', 'pref_temperament', 'temperament'],
                                ['Preferred Energy Level', 'pref_energy_level', 'energy_level'],
                                ['Preferred Size', 'pref_size', 'size'],
                                ['Preferred Age Group', 'pref_age_group', 'age_group'],
                                ['Preferred Gender', 'pref_gender', 'gender'],
                                ['Preferred Health Condition', 'pref_health_condition', 'health_condition'],
                            ];
                            foreach ($prefFields as [$label, $name, $col]): ?>
                            <div class="form-group">
                                <label><?php echo $label; ?></label>
                                <?php echo sp_pref_select($name, sp_pref_values($availablePets, $col), $col === 'health_condition' ? 'Healthy' : null); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-actions between">
                            <button type="button" onclick="prevStep()" class="btn-back">&larr; Back</button>
                            <button type="submit" name="save_preferences" class="btn-save">Save Preferences</button>
                        </div>
                    </div>

                </form>
            </div>

        </main>
    </div>
   
    <!-- Step Navigation JavaScript -->
    <script>
        // Kulay ng mga hakbang (kapareho ng Adoption Application):
        // orange = sinasagutan pa (current), green = tapos na (active).
        // Step 1 ay nagiging green lang kapag napuno na ang linya papuntang Step 2.
        function showStep(step) {
            const dir = step === 2 ? 'step-in-next' : 'step-in-back';
            for (let i = 1; i <= 2; i++) {
                const el = document.getElementById('step-' + i);
                el.classList.toggle('hidden', i !== step);
                el.classList.remove('step-in-next', 'step-in-back');
                if (i === step) {
                    void el.offsetWidth; // i-restart ang animation
                    el.classList.add(dir);
                }
            }

            const bar = document.getElementById('progress-bar');
            const step1 = document.getElementById('step1-indicator');
            const step2 = document.getElementById('step2-indicator');

            if (step === 2) {
                // Tumatakbo muna ang orange na linya hanggang dulo...
                bar.style.width = '100%';
                step1.classList.remove('current');
                const finish = () => {
                    // ...saka pa lang nagiging green ang Step 1 at ang linya
                    bar.classList.remove('is-current');
                    step1.classList.add('active');
                    step2.classList.add('current');
                };
                // .55s ang transition ng haba sa dashboard.css
                clearTimeout(bar._finishTimer);
                bar._finishTimer = setTimeout(finish, 560);
            } else {
                clearTimeout(bar._finishTimer);
                bar.classList.add('is-current');
                bar.style.width = '50%';
                step2.classList.remove('current', 'active');
                step1.classList.remove('active');
                step1.classList.add('current');
            }
        }

        function nextStep() { showStep(2); }
        function prevStep() { showStep(1); }

        // Step 2 filtering: ang bawat dropdown ay nakadepende sa mga naunang pinili.
        // Hal. kapag Dog ang Kind, mga breed lang ng aso ang lalabas. Kapag iisa
        // lang ang pagpipilian, iyon na lang ang ipapakita - wala nang "Any".
        (function () {
            const pets = <?php echo json_encode($availablePets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            if (!pets.length) return; // walang available na alaga: "Any" lang ang laman ng bawat dropdown

            const fields = [
                { name: 'pref_kind', col: 'kind', order: ['Dog', 'Cat'] },
                { name: 'pref_breed', col: 'breed' },
                // Listahan ang temperament (hal. "Playful, Curious") - bawat ugali ay hiwalay na pagpipilian
                { name: 'pref_temperament', col: 'temperament', multi: true },
                { name: 'pref_energy_level', col: 'energy_level', order: ['Low', 'Moderate', 'High'] },
                { name: 'pref_size', col: 'size', order: ['Small', 'Medium', 'Large'] },
                { name: 'pref_age_group', col: 'age_group', order: ['Puppy/Kitten', 'Young Adult', 'Adult', 'Senior'] },
                { name: 'pref_gender', col: 'gender', order: ['Male', 'Female'] },
                { name: 'pref_health_condition', col: 'health_condition', order: ['Healthy'], preferred: 'Healthy' }
            ];
            fields.forEach(function (f) { f.el = document.querySelector('select[name="' + f.name + '"]'); });

            // Mga halaga ng isang pet sa isang column (hinahati ang listahan kapag multi)
            function valuesOf(p, f) {
                const raw = (p[f.col] || '').trim();
                if (!raw) return [];
                return f.multi ? raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [raw];
            }

            function sortValues(values, order) {
                return values.sort(function (a, b) {
                    const ia = order ? order.indexOf(a) : -1;
                    const ib = order ? order.indexOf(b) : -1;
                    if (ia !== -1 || ib !== -1) {
                        return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
                    }
                    return a.localeCompare(b);
                });
            }

            function rebuild(fromIndex) {
                for (let i = fromIndex; i < fields.length; i++) {
                    const f = fields[i];
                    if (!f.el) continue;

                    // Mga pet na tugma sa lahat ng naunang napili
                    const matching = pets.filter(function (p) {
                        for (let j = 0; j < i; j++) {
                            const prev = fields[j];
                            if (prev.el && prev.el.value !== 'Any' && valuesOf(p, prev).indexOf(prev.el.value) === -1) return false;
                        }
                        return true;
                    });

                    const values = sortValues(Array.from(new Set(
                        [].concat.apply([], matching.map(function (p) { return valuesOf(p, f); }))
                    )), f.order);

                    const current = f.el.value;
                    f.el.innerHTML = '';
                    if (values.length !== 1) {
                        f.el.add(new Option('Any', 'Any'));
                    }
                    values.forEach(function (v) { f.el.add(new Option(v, v)); });

                    if (values.indexOf(current) !== -1) {
                        f.el.value = current;
                    } else if (values.length === 1) {
                        f.el.value = values[0];
                    } else if (f.preferred && values.indexOf(f.preferred) !== -1 && current !== 'Any') {
                        f.el.value = f.preferred;
                    } else {
                        f.el.value = 'Any';
                    }
                }
            }

            fields.forEach(function (f, i) {
                if (f.el) f.el.addEventListener('change', function () { rebuild(i + 1); });
            });
            rebuild(0);
        })();
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
