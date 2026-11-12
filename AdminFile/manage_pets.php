<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$sp_unread_count = sp_unread_message_count($pdo, (int) $_SESSION['user_id']);
$username = $_SESSION['username'] ?? '';

// Fetch user data including profile image
$stmt = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$profile_image = $user['profile_image'] ?? '';
$profile_img_path = !empty($profile_image) ? '../uploads/' . $profile_image : null;

// Fetch all pets from database
try {
    $pets_stmt = $pdo->prepare("SELECT pet_id, name, kind, breed, age_group, gender, size, energy_level, temperament, shedding_level, health_condition, vaccination_status, description, rescue_location, rescue_date, rescue_time, photo_url, status, created_at FROM pet_inventory ORDER BY created_at DESC");
    $pets_stmt->execute();
    $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pets = [];
}

// Mga bilang para sa summary cards
$pet_total = count($pets);
$pet_by_status = ['Available' => 0, 'Adopted' => 0, 'Unavailable' => 0];
$pet_dogs = 0;
foreach ($pets as $p) {
    if (isset($pet_by_status[$p['status']])) {
        $pet_by_status[$p['status']]++;
    }
    if ($p['kind'] === 'Dog') {
        $pet_dogs++;
    }
}
$pet_cats = $pet_total - $pet_dogs;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Pet Inventory System</title>
    <link rel="stylesheet" href="manage_pets.css?v=4">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="../Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

            <nav class="sidebar-menu">
                <a href="index.php" class="menu-item">
                    <i class="fa-solid fa-house"></i> Dashboard
                </a>
                <a href="Manage_staff_file/manage_staff.php" class="menu-item">
                    <i class="fa-solid fa-user-gear"></i> Manage Staff
                </a>
                <a href="Manage_adopters_file/manage_adopters.php" class="menu-item">
                    <i class="fa-solid fa-users"></i> Manage Adopters
                </a>
                <a href="manage_pets.php" class="menu-item active">
                    <i class="fa-solid fa-paw"></i> Manage Pets
                </a>
                <a href="payments.php" class="menu-item">
                    <i class="fa-solid fa-money-bill-wave"></i> Payments
                </a>
                <a href="reports.php" class="menu-item">
                    <i class="fa-solid fa-chart-bar"></i> Reports
                </a>
                <a href="messages.php" class="menu-item">
                    <i class="fa-solid fa-comment-dots"></i> Messages<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?>
                </a>
                <a href="activity_logs.php" class="menu-item">
                    <i class="fa-solid fa-clock-rotate-left"></i> Activity Logs
                </a>
                <a href="ml_model.php" class="menu-item"><i class="fa-solid fa-brain"></i> ML Model</a>
            </nav>

            <div class="sidebar-footer">
                <div class="city-illustration">
                    <img src="../Images/caloocan_image.png" alt="Caloocan Arch Landmark" width="300">
                </div>
            </div>
        </aside>

        <!-- Main Workspace -->
        <div class="main-wrapper">
            <!-- Top Header -->
            <header class="top-header">
                <a href="edit_profile.php" class="profile-section" style="text-decoration: none; color: inherit;">
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
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-error">
                        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Summary cards: napipindot para i-filter ang table ayon sa status -->
                <div class="mp-cards">
                    <button type="button" class="mp-card mp-c1" data-status="" title="Show all pets">
                        <div>
                            <strong><?php echo $pet_total; ?></strong>
                            <span>Total Pets</span>
                            <small><?php echo $pet_dogs; ?> dogs &middot; <?php echo $pet_cats; ?> cats</small>
                        </div>
                        <div class="mp-card-icon"><i class="fa-solid fa-paw"></i></div>
                    </button>
                    <button type="button" class="mp-card mp-c2" data-status="Available" title="Show available pets">
                        <div>
                            <strong><?php echo $pet_by_status['Available']; ?></strong>
                            <span>Available</span>
                            <small>Ready for adoption</small>
                        </div>
                        <div class="mp-card-icon"><i class="fa-solid fa-house-chimney"></i></div>
                    </button>
                    <button type="button" class="mp-card mp-c3" data-status="Adopted" title="Show adopted pets">
                        <div>
                            <strong><?php echo $pet_by_status['Adopted']; ?></strong>
                            <span>Adopted</span>
                            <small>Found a home</small>
                        </div>
                        <div class="mp-card-icon"><i class="fa-solid fa-heart"></i></div>
                    </button>
                    <button type="button" class="mp-card mp-c4" data-status="Unavailable" title="Show unavailable pets">
                        <div>
                            <strong><?php echo $pet_by_status['Unavailable']; ?></strong>
                            <span>Unavailable</span>
                            <small>Not open for adoption</small>
                        </div>
                        <div class="mp-card-icon"><i class="fa-solid fa-ban"></i></div>
                    </button>
                </div>

                <div class="card-panel">
                    <!-- Section Title & Action Buttons -->
                    <div class="card-header">
                        <div class="mp-title">
                            <span class="mp-title-icon" aria-hidden="true"><i class="fa-solid fa-paw"></i></span>
                            <div>
                                <h2>Pet Inventory</h2>
                                <p>All pets in the shelter, their profile, health, and adoption status.</p>
                            </div>
                        </div>
                        <div class="mp-head-actions">
                            <a href="/Caps2/StaffFile/export_report.php?type=pets&range=month" class="mp-btn-outline">
                                <i class="fa-solid fa-file-arrow-down"></i> Export Records
                            </a>
                            <button class="btn-add-pet" onclick="openAddPetModal()">
                                <i class="fa-solid fa-plus"></i> Add New Pet
                            </button>
                        </div>
                    </div>

                    <!-- Search + filter toggle + bilang -->
                    <div class="mp-toolbar">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="searchInput" placeholder="Search by name, breed, or temperament.." oninput="filterTable()">
                        </div>
                        <button type="button" class="mp-btn-outline" id="toggleFilters" aria-expanded="false" aria-controls="filterSection">
                            <i class="fa-solid fa-sliders"></i> Filters <span class="mp-filter-count" id="filterCount" hidden>0</span>
                        </button>
                        <button type="button" class="mp-clear" id="clearFilters" onclick="clearFilters()" hidden>
                            <i class="fa-solid fa-xmark"></i> Clear
                        </button>
                        <span class="mp-count" id="petCount"><?php echo $pet_total; ?> pets</span>
                    </div>

                    <!-- Filter Options (nakatago hangga't hindi binubuksan) -->
                    <div class="filter-section" id="filterSection" hidden>
                        <div class="filter-group">
                            <label for="filterKind">Kind</label>
                            <select class="filter-select" id="filterKind" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Dog">Dog</option>
                                <option value="Cat">Cat</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterAge">Age Group</label>
                            <select class="filter-select" id="filterAge" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Puppy/Kitten">Puppy/Kitten</option>
                                <option value="Young Adult">Young Adult</option>
                                <option value="Adult">Adult</option>
                                <option value="Senior">Senior</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterGender">Gender</label>
                            <select class="filter-select" id="filterGender" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterSize">Size</label>
                            <select class="filter-select" id="filterSize" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Small">Small</option>
                                <option value="Medium">Medium</option>
                                <option value="Large">Large</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterEnergy">Energy Level</label>
                            <select class="filter-select" id="filterEnergy" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Low">Low</option>
                                <option value="Moderate">Moderate</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterHealth">Health Condition</label>
                            <select class="filter-select" id="filterHealth" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Healthy">Healthy</option>
                                <option value="Elderly">Elderly</option>
                                <option value="Partially Blind/Deaf">Partially Blind/Deaf</option>
                                <option value="Medical Condition">Medical Condition</option>
                                <option value="Recovering/Injured">Recovering/Injured</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterVaccination">Vaccination</label>
                            <select class="filter-select" id="filterVaccination" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Fully Vaccinated">Fully Vaccinated</option>
                                <option value="Partially Vaccinated">Partially Vaccinated</option>
                                <option value="Not Vaccinated">Not Vaccinated</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterStatus">Status</label>
                            <select class="filter-select" id="filterStatus" onchange="filterTable()">
                                <option value="">All</option>
                                <option value="Available">Available</option>
                                <option value="Adopted">Adopted</option>
                                <option value="Unavailable">Unavailable</option>
                            </select>
                        </div>
                    </div>

                    <!-- Pets Data Table -->
                    <div class="table-wrapper">
                        <table class="pets-table">
                            <thead>
                                <tr>
                                    <th>Pet</th>
                                    <th>Profile</th>
                                    <th>Energy</th>
                                    <th>Temperament</th>
                                    <th>Health &amp; Vaccination</th>
                                    <th>Status</th>
                                    <th class="th-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($pets) > 0): ?>
                                    <?php foreach ($pets as $pet): ?>
                                        <?php
                                        $vacc = (string) $pet['vaccination_status'];
                                        $vaccClass = $vacc === 'Fully Vaccinated' ? 'is-good' : ($vacc === 'Not Vaccinated' ? 'is-bad' : 'is-warn');
                                        $healthClass = $pet['health_condition'] === 'Healthy' ? 'is-good' : 'is-warn';
                                        $energy = (string) $pet['energy_level'];
                                        $energyLevel = $energy === 'High' ? 3 : ($energy === 'Moderate' ? 2 : 1);
                                        ?>
                                        <tr class="pet-row"
                                            data-search="<?php echo htmlspecialchars(mb_strtolower($pet['name'] . ' ' . $pet['breed'] . ' ' . $pet['temperament'])); ?>"
                                            data-kind="<?php echo htmlspecialchars($pet['kind']); ?>"
                                            data-age="<?php echo htmlspecialchars($pet['age_group']); ?>"
                                            data-gender="<?php echo htmlspecialchars($pet['gender']); ?>"
                                            data-size="<?php echo htmlspecialchars($pet['size']); ?>"
                                            data-energy="<?php echo htmlspecialchars($energy); ?>"
                                            data-health="<?php echo htmlspecialchars($pet['health_condition']); ?>"
                                            data-vaccination="<?php echo htmlspecialchars($vacc); ?>"
                                            data-status="<?php echo htmlspecialchars($pet['status']); ?>">
                                            <td>
                                                <div class="mp-pet">
                                                    <div class="pet-photo">
                                                        <?php if (!empty($pet['photo_url'])): ?>
                                                            <img src="../uploads/<?php echo htmlspecialchars($pet['photo_url']); ?>" alt="<?php echo htmlspecialchars($pet['name']); ?>">
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-paw"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mp-pet-text">
                                                        <strong><?php echo htmlspecialchars($pet['name']); ?> <span class="mp-id">#<?php echo (int) $pet['pet_id']; ?></span></strong>
                                                        <span>
                                                            <i class="fa-solid <?php echo $pet['kind'] === 'Cat' ? 'fa-cat' : 'fa-dog'; ?>"></i>
                                                            <?php echo htmlspecialchars($pet['kind']); ?> &middot; <?php echo htmlspecialchars($pet['breed']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="mp-profile">
                                                <span><?php echo htmlspecialchars($pet['age_group']); ?></span>
                                                <small>
                                                    <i class="fa-solid <?php echo $pet['gender'] === 'Female' ? 'fa-venus' : 'fa-mars'; ?>"></i>
                                                    <?php echo htmlspecialchars($pet['gender']); ?> &middot; <?php echo htmlspecialchars($pet['size']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="mp-energy lvl-<?php echo $energyLevel; ?>" title="<?php echo htmlspecialchars($energy); ?> energy">
                                                    <span class="mp-bars"><i></i><i></i><i></i></span><?php echo htmlspecialchars($energy); ?>
                                                </span>
                                            </td>
                                            <td class="mp-temper">
                                                <?php foreach (array_filter(array_map('trim', explode(',', (string) $pet['temperament']))) as $trait): ?>
                                                    <span class="mp-chip"><?php echo htmlspecialchars($trait); ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td class="mp-health">
                                                <span class="mp-tag <?php echo $healthClass; ?>"><i class="fa-solid fa-heart-pulse"></i> <?php echo htmlspecialchars($pet['health_condition']); ?></span>
                                                <span class="mp-tag <?php echo $vaccClass; ?>"><i class="fa-solid fa-syringe"></i> <?php echo htmlspecialchars($vacc); ?></span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo strtolower($pet['status']); ?>">
                                                    <span class="mp-dot"></span><?php echo htmlspecialchars($pet['status']); ?>
                                                </span>
                                            </td>
                                            <td class="td-actions">
                                                <div class="action-cell">
                                                    <button type="button" class="btn-icon edit" onclick="openEditPetModal(<?php echo (int) $pet['pet_id']; ?>)" title="Edit <?php echo htmlspecialchars($pet['name']); ?>" aria-label="Edit <?php echo htmlspecialchars($pet['name']); ?>"><i class="fa-solid fa-pen"></i></button>
                                                    <form method="POST" action="process_delete_pet.php" style="display:inline;"
                                                          data-confirm="Delete <?php echo htmlspecialchars($pet['name']); ?> from the pet inventory? This cannot be undone."
                                                          data-confirm-title="Delete Pet?"
                                                          data-confirm-ok="Yes, delete"
                                                          data-confirm-icon="fa-trash-can"
                                                          data-confirm-tone="red">
                                                        <input type="hidden" name="pet_id" value="<?php echo (int) $pet['pet_id']; ?>">
                                                        <button type="submit" class="btn-icon delete" title="Delete <?php echo htmlspecialchars($pet['name']); ?>" aria-label="Delete <?php echo htmlspecialchars($pet['name']); ?>"><i class="fa-solid fa-trash-can"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="mp-empty">
                                            <i class="fa-solid fa-paw"></i>
                                            <p>No pets in inventory yet</p>
                                            <span>Click <b>Add New Pet</b> to add the first one.</span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResults" class="mp-empty" style="display: none;">
                            <i class="fa-solid fa-filter-circle-xmark"></i>
                            <p>No pets match your filters</p>
                            <span>Try a different search or clear the filters.</span>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Pet Modal -->
    <div id="addPetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add New Pet</h3>
                <button class="close-btn" onclick="closeAddPetModal()">&times;</button>
            </div>
            <form action="process_add_pet.php" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Pet Name *</label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-group">
                        <label>Kind *</label>
                        <select name="kind" required>
                            <option value="">Select Kind</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Breed *</label>
                        <input type="text" name="breed" required>
                    </div>
                    <div class="form-group">
                        <label>Age Group *</label>
                        <select name="age_group" required>
                            <option value="">Select Age Group</option>
                            <option value="Puppy/Kitten">Puppy/Kitten</option>
                            <option value="Young Adult">Young Adult</option>
                            <option value="Adult">Adult</option>
                            <option value="Senior">Senior</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Size *</label>
                        <select name="size" required>
                            <option value="">Select Size</option>
                            <option value="Small">Small</option>
                            <option value="Medium">Medium</option>
                            <option value="Large">Large</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Energy Level *</label>
                        <select name="energy_level" required>
                            <option value="">Select Energy Level</option>
                            <option value="Low">Low</option>
                            <option value="Moderate">Moderate</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Temperament *</label>
                        <input type="text" name="temperament" placeholder="e.g., Friendly, Playful, Calm" required>
                    </div>
                    <div class="form-group">
                        <label>Health Condition *</label>
                        <select name="health_condition" required>
                            <option value="">Select Health Condition</option>
                            <option value="Healthy">Healthy</option>
                            <option value="Elderly">Elderly</option>
                            <option value="Partially Blind/Deaf">Partially Blind/Deaf</option>
                            <option value="Medical Condition">Medical Condition</option>
                            <option value="Recovering/Injured">Recovering/Injured</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Vaccination Status *</label>
                        <select name="vaccination_status" required>
                            <option value="">Select Vaccination Status</option>
                            <option value="Fully Vaccinated">Fully Vaccinated</option>
                            <option value="Partially Vaccinated">Partially Vaccinated</option>
                            <option value="Not Vaccinated">Not Vaccinated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Shedding Level *</label>
                        <select name="shedding_level" required>
                            <option value="">Select Shedding Level</option>
                            <option value="Low">Low (hypoallergenic / barely sheds)</option>
                            <option value="Moderate">Moderate</option>
                            <option value="High">High (very furry)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Rescue Location</label>
                        <input type="text" name="rescue_location" placeholder="e.g., Brgy. Bagong Silang, Caloocan City">
                    </div>
                    <div class="form-group">
                        <label>Rescue Date</label>
                        <input type="date" name="rescue_date">
                    </div>
                    <div class="form-group">
                        <label>Rescue Time</label>
                        <input type="time" name="rescue_time">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Photo</label>
                        <input type="file" name="photo" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" required>
                            <option value="Available">Available</option>
                            <option value="Unavailable">Unavailable</option>
                            <option value="Adopted">Adopted</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeAddPetModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Add Pet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Pet Modal -->
    <div id="editPetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Pet</h3>
                <button class="close-btn" onclick="closeEditPetModal()">&times;</button>
            </div>
            <form action="process_edit_pet.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="pet_id" id="edit_pet_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Pet Name *</label>
                        <input type="text" name="name" id="edit_name" required>
                    </div>
                    <div class="form-group">
                        <label>Kind *</label>
                        <select name="kind" id="edit_kind" required>
                            <option value="">Select Kind</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Breed *</label>
                        <input type="text" name="breed" id="edit_breed" required>
                    </div>
                    <div class="form-group">
                        <label>Age Group *</label>
                        <select name="age_group" id="edit_age_group" required>
                            <option value="">Select Age Group</option>
                            <option value="Puppy/Kitten">Puppy/Kitten</option>
                            <option value="Young Adult">Young Adult</option>
                            <option value="Adult">Adult</option>
                            <option value="Senior">Senior</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" id="edit_gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Size *</label>
                        <select name="size" id="edit_size" required>
                            <option value="">Select Size</option>
                            <option value="Small">Small</option>
                            <option value="Medium">Medium</option>
                            <option value="Large">Large</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Energy Level *</label>
                        <select name="energy_level" id="edit_energy_level" required>
                            <option value="">Select Energy Level</option>
                            <option value="Low">Low</option>
                            <option value="Moderate">Moderate</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Temperament *</label>
                        <input type="text" name="temperament" id="edit_temperament" placeholder="e.g., Friendly, Playful, Calm" required>
                    </div>
                    <div class="form-group">
                        <label>Health Condition *</label>
                        <select name="health_condition" id="edit_health_condition" required>
                            <option value="">Select Health Condition</option>
                            <option value="Healthy">Healthy</option>
                            <option value="Elderly">Elderly</option>
                            <option value="Partially Blind/Deaf">Partially Blind/Deaf</option>
                            <option value="Medical Condition">Medical Condition</option>
                            <option value="Recovering/Injured">Recovering/Injured</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Vaccination Status *</label>
                        <select name="vaccination_status" id="edit_vaccination_status" required>
                            <option value="">Select Vaccination Status</option>
                            <option value="Fully Vaccinated">Fully Vaccinated</option>
                            <option value="Partially Vaccinated">Partially Vaccinated</option>
                            <option value="Not Vaccinated">Not Vaccinated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Shedding Level *</label>
                        <select name="shedding_level" id="edit_shedding_level" required>
                            <option value="">Select Shedding Level</option>
                            <option value="Low">Low (hypoallergenic / barely sheds)</option>
                            <option value="Moderate">Moderate</option>
                            <option value="High">High (very furry)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Rescue Location</label>
                        <input type="text" name="rescue_location" id="edit_rescue_location" placeholder="e.g., Brgy. Bagong Silang, Caloocan City">
                    </div>
                    <div class="form-group">
                        <label>Rescue Date</label>
                        <input type="date" name="rescue_date" id="edit_rescue_date">
                    </div>
                    <div class="form-group">
                        <label>Rescue Time</label>
                        <input type="time" name="rescue_time" id="edit_rescue_time">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Current Photo</label>
                        <div id="edit_current_photo" style="width:70px; height:70px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; background:#f1f1f1; margin-bottom:8px;">
                            <i class="fa-solid fa-paw" style="color:#999;"></i>
                        </div>
                        <label>Replace Photo</label>
                        <input type="file" name="photo" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" id="edit_status" required>
                            <option value="Available">Available</option>
                            <option value="Unavailable">Unavailable</option>
                            <option value="Adopted">Adopted</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeEditPetModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const petsData = <?php echo json_encode($pets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function openAddPetModal() {
            document.getElementById('addPetModal').style.display = 'block';
        }

        function closeAddPetModal() {
            document.getElementById('addPetModal').style.display = 'none';
        }

        function openEditPetModal(petId) {
            const pet = petsData.find(p => parseInt(p.pet_id, 10) === petId);
            if (!pet) return;

            document.getElementById('edit_pet_id').value = pet.pet_id;
            document.getElementById('edit_name').value = pet.name || '';
            document.getElementById('edit_kind').value = pet.kind || '';
            document.getElementById('edit_breed').value = pet.breed || '';
            document.getElementById('edit_age_group').value = pet.age_group || '';
            document.getElementById('edit_gender').value = pet.gender || '';
            document.getElementById('edit_size').value = pet.size || '';
            document.getElementById('edit_energy_level').value = pet.energy_level || '';
            document.getElementById('edit_temperament').value = pet.temperament || '';
            document.getElementById('edit_health_condition').value = pet.health_condition || '';
            document.getElementById('edit_vaccination_status').value = pet.vaccination_status || '';
            document.getElementById('edit_shedding_level').value = pet.shedding_level || '';
            document.getElementById('edit_description').value = pet.description || '';
            document.getElementById('edit_rescue_location').value = pet.rescue_location || '';
            document.getElementById('edit_rescue_date').value = pet.rescue_date || '';
            document.getElementById('edit_rescue_time').value = pet.rescue_time || '';
            document.getElementById('edit_status').value = pet.status || 'Available';

            const photoBox = document.getElementById('edit_current_photo');
            if (pet.photo_url) {
                photoBox.innerHTML = '<img src="../uploads/' + pet.photo_url + '" alt="Pet" style="width:100%; height:100%; object-fit:cover;">';
            } else {
                photoBox.innerHTML = '<i class="fa-solid fa-paw" style="color:#999;"></i>';
            }

            document.getElementById('editPetModal').style.display = 'block';
        }

        function closeEditPetModal() {
            document.getElementById('editPetModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const addModal = document.getElementById('addPetModal');
            const editModal = document.getElementById('editPetModal');
            if (event.target == addModal) {
                addModal.style.display = 'none';
            }
            if (event.target == editModal) {
                editModal.style.display = 'none';
            }
        }

        // Search + 8 filter + summary cards. Itinatago ang row gamit ang
        // style.display - ito rin ang binabasa ng pagination.
        const PET_FILTERS = {
            filterKind: 'kind', filterAge: 'age', filterGender: 'gender', filterSize: 'size',
            filterEnergy: 'energy', filterHealth: 'health', filterVaccination: 'vaccination', filterStatus: 'status'
        };

        function filterTable() {
            const searchText = document.getElementById('searchInput').value.trim().toLowerCase();
            const active = {};
            Object.keys(PET_FILTERS).forEach(function (id) {
                const v = document.getElementById(id).value;
                if (v !== '') active[PET_FILTERS[id]] = v;
            });
            const activeCount = Object.keys(active).length;

            const rows = document.querySelectorAll('.pets-table tbody tr.pet-row');
            let visible = 0;
            rows.forEach(function (row) {
                let ok = searchText === '' || (row.dataset.search || '').indexOf(searchText) > -1;
                Object.keys(active).forEach(function (key) {
                    if (ok && row.dataset[key] !== active[key]) ok = false;
                });
                row.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });

            const anyFilter = searchText !== '' || activeCount > 0;
            document.getElementById('noResults').style.display = (visible === 0 && anyFilter && rows.length > 0) ? 'block' : 'none';
            document.getElementById('petCount').textContent = anyFilter
                ? visible + ' of ' + rows.length + ' pets'
                : rows.length + ' pets';
            const badge = document.getElementById('filterCount');
            badge.textContent = activeCount;
            badge.hidden = activeCount === 0;
            document.getElementById('clearFilters').hidden = !anyFilter;

            // Naka-highlight ang card na tugma sa Status filter (kapag status lang ang filter)
            const onlyStatus = searchText === '' && activeCount <= 1 && (activeCount === 0 || active.status);
            document.querySelectorAll('.mp-card').forEach(function (card) {
                const on = card.dataset.status !== '' && onlyStatus && active.status === card.dataset.status;
                card.classList.toggle('is-active', on);
                card.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            Object.keys(PET_FILTERS).forEach(function (id) { document.getElementById(id).value = ''; });
            filterTable();
        }

        // Ipakita / itago ang mga filter
        (function () {
            const btn = document.getElementById('toggleFilters');
            const box = document.getElementById('filterSection');
            btn.addEventListener('click', function () {
                box.hidden = !box.hidden;
                btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
                btn.classList.toggle('is-open', !box.hidden);
            });
        })();

        // Summary cards: pindot = filter ayon sa status; pindot ulit = lahat
        document.querySelectorAll('.mp-card').forEach(function (card) {
            card.addEventListener('click', function () {
                const turnOff = card.classList.contains('is-active');
                clearFilters();
                document.getElementById('filterStatus').value = turnOff ? '' : card.dataset.status;
                filterTable();
                const panel = document.querySelector('.card-panel');
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (panel) panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            });
        });    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
