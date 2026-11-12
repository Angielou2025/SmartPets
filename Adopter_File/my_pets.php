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

// Ang mga pet na inampon NG USER NA ITO lang ang dapat lumabas dito.
// Ang adoption_applications ang nagsasabi kung kanino napunta ang bawat pet -
// kung wala ang JOIN na ito, makikita ng bawat adopter ang mga alaga ng iba.
try {
    $pets_stmt = $pdo->prepare(
        "SELECT aa.application_id, p.pet_id, p.name, p.kind, p.breed, p.age_group, p.gender, p.size,
                p.energy_level, p.temperament, p.health_condition, p.vaccination_status,
                p.description, p.photo_url, p.status, aa.updated_at AS adopted_on
         FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.adopter_id = ?
           AND aa.application_status = 'Approved'
           AND p.status = 'Adopted'
           -- Binawi o ibinalik na: hindi na niya alaga, kahit ma-adopt ulit ng iba
           AND NOT EXISTS (
               SELECT 1 FROM pet_visits v
               WHERE v.application_id = aa.application_id
                 AND v.result IN ('Reclaimed by Office', 'Returned by Adopter')
           )
         ORDER BY aa.updated_at DESC"
    );
    $pets_stmt->execute([$user_id]);
    $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pets = [];
}

// Mga pet na ibinalik o binawi pagkatapos ng adoption, kasama ang dahilang
// isinulat ng staff sa Pet Monitoring - para alam ng adopter kung bakit
try {
    $ret_stmt = $pdo->prepare(
        "SELECT p.pet_id, p.name, p.breed, p.age_group, p.gender, p.photo_url,
                v.result, v.visit_date, v.return_reason
         FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         JOIN pet_visits v ON v.visit_id = (
             SELECT v2.visit_id FROM pet_visits v2
              WHERE v2.application_id = aa.application_id
                AND v2.result IN ('Reclaimed by Office', 'Returned by Adopter')
              ORDER BY v2.visit_date DESC, v2.visit_id DESC LIMIT 1
         )
         WHERE aa.adopter_id = ? AND aa.application_status = 'Approved'
         ORDER BY v.visit_date DESC"
    );
    $ret_stmt->execute([$user_id]);
    $returnedPets = $ret_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $returnedPets = [];
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - My Pets</title>
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
                <a href="my_pets.php" class="nav-item active"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
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
                <p>Your successfully adopted pets are shown here.</p>
            </section>

            <?php if ($success_message): ?>
                <div class="concern-flash ok"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="concern-flash bad"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <section class="featured-section">
                <h2>My Adopted Pets</h2>
                <div class="pets-grid">
                    <?php if (count($pets) > 0): ?>
                        <?php foreach ($pets as $pet): ?>
                            <div class="pet-card">
                                <div class="status-badge adopted">Adopted</div>
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
                                <div class="card-actions">
                                    <a href="pet_details.php?pet_id=<?php echo urlencode($pet['pet_id']); ?>" class="btn-orange">View Details</a>
                                    <button type="button" class="btn-text concern-open" data-app="<?php echo (int) $pet['application_id']; ?>" data-pet="<?php echo htmlspecialchars($pet['name']); ?>">Report a Concern</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #1b4d2e; background: #f8fafc; border-radius: 20px;">
                            <p>No adopted pets found yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Form: Report a Concern tungkol sa inampong pet -->
            <div class="concern-overlay" id="concernModal" aria-hidden="true">
                <form class="concern-modal" method="POST" action="process_pet_concern.php">
                    <div class="concern-modal-head">
                        <span class="concern-head-icon" aria-hidden="true"><i class="fa-solid fa-flag"></i></span>
                        <div>
                            <h3 id="concernTitle">Report a Concern</h3>
                            <p id="concernPetLabel">Tell the shelter what changed.</p>
                        </div>
                        <button type="button" class="concern-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                    </div>

                    <div class="concern-modal-body">
                        <input type="hidden" name="application_id" id="concernApp">

                        <fieldset class="concern-field">
                            <legend>What is this about? <span class="req">*</span></legend>
                            <div class="concern-options">
                                <?php
                                // Uri ng concern: [value, icon, kulay, pamagat, paliwanag]
                                $concernOptions = [
                                    ['Health change', 'fa-stethoscope', 'health', 'Health change', 'Sick, injured, or not eating'],
                                    ['Behavior change', 'fa-paw', 'behavior', 'Behavior change', 'Aggressive, anxious, or destructive'],
                                    ['Can no longer care for the pet', 'fa-house-chimney-crack', 'care', 'Can no longer care for the pet', 'You may need to return or rehome'],
                                    ['Other concern', 'fa-comment-dots', 'other', 'Other concern', 'Anything else the shelter should know'],
                                ];
                                foreach ($concernOptions as $i => [$val, $icon, $tone, $title, $desc]): ?>
                                <label class="concern-option">
                                    <input type="radio" name="concern_type" value="<?php echo htmlspecialchars($val); ?>" <?php echo $i === 0 ? 'required' : ''; ?>>
                                    <span class="concern-option-icon <?php echo $tone; ?>"><i class="fa-solid <?php echo $icon; ?>"></i></span>
                                    <span class="concern-option-text">
                                        <strong><?php echo $title; ?></strong>
                                        <small><?php echo $desc; ?></small>
                                    </span>
                                    <span class="concern-option-check" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <div class="concern-field">
                            <label for="concernDetails">What happened? <span class="req">*</span></label>
                            <textarea name="details" id="concernDetails" required minlength="10" maxlength="1500" placeholder="Describe what you noticed, when it started, and anything you have already tried."></textarea>
                            <div class="concern-hint"><span>At least 10 characters</span><span id="concernCount">0 / 1500</span></div>
                        </div>

                        <p class="concern-note"><i class="fa-solid fa-circle-info"></i> <span>This is sent to the shelter staff in Messages. If your pet needs urgent medical care, please go to the nearest veterinarian right away.</span></p>
                    </div>

                    <div class="concern-actions">
                        <button type="button" class="concern-cancel">Cancel</button>
                        <button type="submit" class="concern-send"><i class="fa-solid fa-paper-plane"></i> Send to Shelter</button>
                    </div>
                </form>
            </div>

            <style>
                .concern-flash { margin: 0 0 14px; padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 600; }
                .concern-flash.ok { background: #e3f6ea; color: #1b6b3a; }
                .concern-flash.bad { background: #fdecea; color: #a8322a; }
                button.btn-text.concern-open { cursor: pointer; font-family: inherit; }

                /* ---- Report a Concern modal ---- */
                .concern-overlay { position: fixed; inset: 0; background: rgba(15,30,22,0.55); backdrop-filter: blur(2px); display: none; align-items: center; justify-content: center; padding: 16px; z-index: 10050; }
                .concern-overlay.show { display: flex; animation: concernFade .2s ease; }
                .concern-modal { background: #fff; border-radius: 16px; width: 100%; max-width: 520px; max-height: calc(100vh - 32px); max-height: calc(100dvh - 32px); display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(0,0,0,0.28); overflow: hidden; animation: concernRise .25s cubic-bezier(.22,1,.36,1); }
                @keyframes concernFade { from { opacity: 0; } to { opacity: 1; } }
                @keyframes concernRise { from { opacity: 0; transform: translateY(12px) scale(.98); } to { opacity: 1; transform: none; } }

                .concern-modal-head { display: flex; align-items: center; gap: 12px; padding: 18px 20px; border-bottom: 1px solid #edf1ee; }
                .concern-head-icon { width: 40px; height: 40px; border-radius: 10px; background: #e8f3ec; color: #1f6b48; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
                .concern-modal-head > div { flex: 1; min-width: 0; }
                .concern-modal h3 { margin: 0; color: #1b4d2e; font-size: 18px; font-weight: 800; line-height: 1.25; }
                .concern-modal-head p { margin: 2px 0 0; color: #6b7a72; font-size: 13px; }
                .concern-close { width: 34px; height: 34px; border-radius: 8px; border: none; background: transparent; color: #6b7a72; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background-color .2s ease, color .2s ease; }
                .concern-close:hover { background: #f1f4f2; color: #1b4d2e; }

                .concern-modal-body { padding: 18px 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 18px; }
                .concern-field { border: none; margin: 0; padding: 0; min-width: 0; }
                .concern-field legend, .concern-field > label { display: block; padding: 0; margin-bottom: 8px; font-weight: 700; font-size: 13.5px; color: #2f3e36; }
                .concern-field .req { color: #c5392b; }

                /* Mga pagpipilian bilang card (kapalit ng dropdown) */
                .concern-options { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
                .concern-option { position: relative; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1.5px solid #e1e8e4; border-radius: 12px; background: #fff; cursor: pointer; transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease; }
                .concern-option:hover { border-color: #b9cdc1; background: #fafcfb; }
                .concern-option input { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
                .concern-option-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: #f1f3f2; color: #56635c; }
                .concern-option-icon.health { background: #fdecea; color: #c5392b; }
                .concern-option-icon.behavior { background: #fff4e0; color: #b26a00; }
                .concern-option-icon.care { background: #f1ecfb; color: #5b3fa3; }
                .concern-option-text { display: flex; flex-direction: column; min-width: 0; padding-right: 14px; }
                .concern-option-text strong { font-size: 13px; color: #1f2d26; line-height: 1.3; }
                .concern-option-text small { font-size: 11.5px; color: #7a8780; line-height: 1.35; margin-top: 2px; }
                .concern-option-check { position: absolute; top: 8px; right: 8px; width: 18px; height: 18px; border-radius: 50%; background: #1f6b48; color: #fff; font-size: 9px; display: flex; align-items: center; justify-content: center; opacity: 0; transform: scale(.6); transition: opacity .2s ease, transform .2s ease; }
                .concern-option:has(input:checked) { border-color: #1f6b48; background: #f2f8f4; box-shadow: 0 0 0 3px rgba(31,107,72,0.12); }
                .concern-option:has(input:checked) .concern-option-check { opacity: 1; transform: scale(1); }
                .concern-option:has(input:focus-visible) { outline: 2px solid #1f6b48; outline-offset: 2px; }

                .concern-modal textarea { display: block; width: 100%; box-sizing: border-box; min-height: 110px; resize: vertical; border: 1.5px solid #e1e8e4; border-radius: 12px; padding: 10px 12px; font-family: inherit; font-size: 14px; line-height: 1.5; color: #1f2d26; transition: border-color .2s ease, box-shadow .2s ease; }
                .concern-modal textarea::placeholder { color: #9aa6a0; }
                .concern-modal textarea:focus { outline: none; border-color: #1f6b48; box-shadow: 0 0 0 3px rgba(31,107,72,0.12); }
                .concern-hint { display: flex; justify-content: space-between; margin-top: 6px; font-size: 11.5px; color: #8a978f; }

                .concern-note { display: flex; gap: 8px; align-items: flex-start; margin: 0; padding: 10px 12px; border-radius: 10px; background: #fff8ec; border: 1px solid #fbe3bb; color: #7a5410; font-size: 12.5px; line-height: 1.45; }
                .concern-note i { margin-top: 2px; color: #d98a0b; }

                .concern-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid #edf1ee; background: #fafcfb; }
                .concern-cancel, .concern-send { height: 40px; border-radius: 10px; padding: 0 18px; font-weight: 700; font-size: 14px; font-family: inherit; cursor: pointer; transition: background-color .2s ease, border-color .2s ease; }
                .concern-cancel { background: #fff; border: 1.5px solid #dfe6e2; color: #2f3e36; }
                .concern-cancel:hover { background: #f4f7f5; }
                .concern-send { background: #1f6b48; border: 1.5px solid #1f6b48; color: #fff; display: inline-flex; align-items: center; gap: 8px; }
                .concern-send:hover { background: #185a3c; border-color: #185a3c; }
                .concern-send:disabled { opacity: .7; cursor: wait; }

                @media (max-width: 520px) {
                    .concern-options { grid-template-columns: 1fr; }
                    .concern-actions > button { flex: 1; justify-content: center; }
                }
            </style>

            <script>
            (function () {
                const modal = document.getElementById('concernModal');
                const form = modal.querySelector('form');
                function close() { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); }
                // Bilang ng letra sa "What happened?"
                const details = document.getElementById('concernDetails');
                const count = document.getElementById('concernCount');
                function updateCount() { count.textContent = details.value.length + ' / 1500'; }
                details.addEventListener('input', updateCount);
                document.querySelectorAll('.concern-open').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        form.reset();
                        updateCount();
                        form.querySelector('.concern-send').disabled = false;
                        document.getElementById('concernApp').value = btn.dataset.app;
                        document.getElementById('concernPetLabel').textContent = 'About ' + btn.dataset.pet + ' - tell the shelter what changed.';
                        modal.classList.add('show');
                        modal.setAttribute('aria-hidden', 'false');
                        form.querySelector('input[name="concern_type"]').focus();
                    });
                });
                modal.querySelector('.concern-close').addEventListener('click', close);
                modal.querySelector('.concern-cancel').addEventListener('click', close);
                modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
                // Iwas dobleng padala kapag napindot nang dalawang beses
                form.addEventListener('submit', function () { form.querySelector('.concern-send').disabled = true; });
            })();
            </script>

            <?php if (count($returnedPets) > 0): ?>
            <!-- Mga pet na bumalik sa shelter, kasama ang dahilan mula sa staff -->
            <section class="featured-section returned-section">
                <h2>Returned to the Shelter</h2>
                <p class="returned-intro">These pets went back to the shelter after your adoption. The reason recorded by the shelter staff is shown on each card.</p>
                <div class="pets-grid">
                    <?php foreach ($returnedPets as $rp): ?>
                        <div class="pet-card returned-card">
                            <div class="status-badge returned"><?php echo $rp['result'] === 'Returned by Adopter' ? 'Returned' : 'Reclaimed by Shelter'; ?></div>
                            <div class="card-top">
                                <div class="pet-img-container">
                                    <?php if (!empty($rp['photo_url'])): ?>
                                        <img src="../uploads/<?php echo htmlspecialchars($rp['photo_url']); ?>" alt="<?php echo htmlspecialchars($rp['name']); ?>" onerror="this.src='https://via.placeholder.com/150'">
                                    <?php else: ?>
                                        <img src="https://via.placeholder.com/150" alt="<?php echo htmlspecialchars($rp['name']); ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="pet-info">
                                    <h3><?php echo htmlspecialchars($rp['name']); ?></h3>
                                    <p class="breed"><?php echo htmlspecialchars($rp['breed']); ?></p>
                                    <p class="meta">Back in the shelter since<br><?php echo htmlspecialchars(date('M j, Y', strtotime($rp['visit_date']))); ?></p>
                                </div>
                            </div>
                            <div class="return-reason">
                                <span class="return-reason-label"><i class="fa-solid fa-circle-info"></i> Reason</span>
                                <?php echo !empty($rp['return_reason'])
                                    ? nl2br(htmlspecialchars($rp['return_reason']))
                                    : 'No reason was recorded. Please message the shelter for details.'; ?>
                            </div>
                            <div class="card-actions">
                                <a href="messages.php" class="btn-text">Message Shelter</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <style>
                .returned-section { margin-top: 28px; }
                .returned-intro { color: #56635c; font-size: 14px; margin: -6px 0 14px; }
                .status-badge.returned { background: #fdecea; color: #b3261e; }
                .returned-card .pet-img-container img { filter: grayscale(35%); }
                .return-reason {
                    margin: 12px 0 4px;
                    background: #fff6f5;
                    border: 1px solid #f3c7c2;
                    border-radius: 12px;
                    padding: 10px 12px;
                    font-size: 13.5px;
                    color: #5b1f1a;
                    line-height: 1.5;
                }
                .return-reason-label {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    font-size: 11.5px;
                    font-weight: 800;
                    text-transform: uppercase;
                    letter-spacing: .4px;
                    color: #b3261e;
                    margin-bottom: 4px;
                }
            </style>
            <?php endif; ?>
        </main>
    </div>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
