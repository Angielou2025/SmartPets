<?php
session_start();
require '../../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../Login_Page/login.php");
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
$profile_img_path = !empty($profile_image) ? '../../uploads/' . $profile_image : null;

// Fetch all adopters from database
$adopters_stmt = $pdo->prepare("SELECT user_id, username, email, profile_image, created_at FROM users WHERE role = 'Adopter' ORDER BY created_at DESC");
$adopters_stmt->execute();
$adopters = $adopters_stmt->fetchAll(PDO::FETCH_ASSOC);

// Sinong may nakabinbing hiling na pagpapalit ng role. Hangga't nakabinbin,
// Adopter pa rin siya rito - hindi pa nagbabago ang account niya.
$pendingRoleRequests = sp_pending_role_requests($pdo);

$returnPath = '/Caps2/AdminFile/Manage_adopters_file/manage_adopters.php';

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Adopters Management</title>
    <link rel="stylesheet" href="manage_adopters.css?v=2">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <a href="../reports.php" class="menu-item">
                        <i class="fa-solid fa-chart-bar"></i> Reports
                    </a>
                    <a href="../messages.php" class="menu-item">
                        <i class="fa-solid fa-comment-dots"></i> Messages<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?>
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
                    <!-- Section Title -->
                    <div class="card-header">
                        <h2>Adopters Management</h2>
                    </div>

                    <?php if ($success_message !== ''): ?>
                        <div class="flash-msg flash-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?></div>
                    <?php endif; ?>
                    <?php if ($error_message !== ''): ?>
                        <div class="flash-msg flash-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>

                    <!-- Search Input -->
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search adopters by name or email.." onkeyup="filterTable()">
                    </div>

                    <!-- Adopters Data Table -->
                    <div class="table-wrapper">
                        <table class="adopters-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Photo</th>
                                    <th>Name</th>
                                    <th>Contact Details</th>
                                    <th>Role</th>
                                    <th>Application</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($adopters) > 0): ?>
                                    <?php foreach ($adopters as $adopter): ?>
                                        <tr>
                                            <td class="date-cell"><?php echo date('M j, Y', strtotime($adopter['created_at'])); ?></td>
                                            <td>
                                                <div class="user-photo" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                                                    <?php if (!empty($adopter['profile_image'])): ?>
                                                        <img src="../../uploads/<?php echo htmlspecialchars($adopter['profile_image']); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-user" style="color: #666; font-size: 16px;"></i>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($adopter['username']); ?></strong></td>
                                            <td><a href="mailto:<?php echo htmlspecialchars($adopter['email']); ?>" class="email-link"><?php echo htmlspecialchars($adopter['email']); ?></a></td>
                                            <td>
                                                <?php $pending = $pendingRoleRequests[(int) $adopter['user_id']] ?? null; ?>
                                                <?php if ($pending): ?>
                                                    <div class="role-cell">
                                                        <span class="role-pending" title="Waiting for the adopter to accept or decline">
                                                            <i class="fa-solid fa-hourglass-half"></i>
                                                            Pending &rarr; <?php echo htmlspecialchars($pending['to_role']); ?>
                                                        </span>
                                                        <form method="POST" action="/Caps2/connection/request_role_change.php"
                                                              data-confirm="Cancel the pending role change request for <?php echo htmlspecialchars($adopter['username']); ?>? Their role will stay as Adopter."
                                                              data-confirm-title="Cancel Request?"
                                                              data-confirm-ok="Yes, cancel request"
                                                              data-confirm-icon="fa-ban"
                                                              data-confirm-tone="orange">
                                                            <input type="hidden" name="action" value="cancel">
                                                            <input type="hidden" name="request_id" value="<?php echo (int) $pending['request_id']; ?>">
                                                            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnPath); ?>">
                                                            <button type="submit" class="role-cancel">Cancel</button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <!-- Ang mensahe ng confirm ay pinapalitan ng script sa ibaba ayon sa napiling role -->
                                                    <form method="POST" action="/Caps2/connection/request_role_change.php" class="role-cell role-form"
                                                          data-user="<?php echo htmlspecialchars($adopter['username']); ?>"
                                                          data-confirm="Send a request to change <?php echo htmlspecialchars($adopter['username']); ?>'s role to Staff? Their account only changes once they accept it."
                                                          data-confirm-title="Change Role?"
                                                          data-confirm-ok="Send request"
                                                          data-confirm-icon="fa-user-gear">
                                                        <input type="hidden" name="action" value="request">
                                                        <input type="hidden" name="user_id" value="<?php echo (int) $adopter['user_id']; ?>">
                                                        <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnPath); ?>">
                                                        <select name="to_role" class="role-select" aria-label="New role for <?php echo htmlspecialchars($adopter['username']); ?>">
                                                            <option value="Adopter" selected>Adopter</option>
                                                            <option value="Staff">Staff</option>
                                                        </select>
                                                        <button type="submit" class="role-apply" disabled title="Choose a different role first">Apply</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="view_application.php?adopter_id=<?php echo urlencode($adopter['user_id']); ?>" class="app-link">
                                                    <i class="fa-solid fa-file-lines"></i> View Application
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px; color: #666;">
                                            No adopters registered yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResults" style="display: none; text-align: center; padding: 30px; color: #666;">
                            No adopters found matching your search.
                        </div>
                        <div class="table-empty-space"></div>
                    </div>
                </div>
            </main>
        </div>
    </div>


    <script>
        // Role: naka-disable ang Apply hangga't "Adopter" (kasalukuyang role) ang napili,
        // at sinasabi ng confirm dialog kung anong role ang hinihiling
        document.querySelectorAll('.role-form').forEach(function (form) {
            const select = form.querySelector('.role-select');
            const apply = form.querySelector('.role-apply');
            function sync() {
                const changed = select.value !== 'Adopter';
                apply.disabled = !changed;
                apply.title = changed ? '' : 'Choose a different role first';
                form.dataset.confirm = 'Send a request to change ' + form.dataset.user + "'s role to " + select.value +
                    '? Their account only changes once they accept it.';
            }
            select.addEventListener('change', sync);
            sync();
        });

        function filterTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const table = document.querySelector('.adopters-table');
            const rows = table.getElementsByTagName('tr');
            const noResults = document.getElementById('noResults');
            let visibleRows = 0;

            for (let i = 1; i < rows.length; i++) {
                const nameCell = rows[i].getElementsByTagName('td')[2];
                const emailCell = rows[i].getElementsByTagName('td')[3];
                
                if (nameCell && emailCell) {
                    const nameText = nameCell.textContent || nameCell.innerText;
                    const emailText = emailCell.textContent || emailCell.innerText;
                    
                    if (nameText.toLowerCase().indexOf(filter) > -1 || emailText.toLowerCase().indexOf(filter) > -1) {
                        rows[i].style.display = '';
                        visibleRows++;
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
            }

            // Show/hide no results message
            if (visibleRows === 0 && filter !== '') {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }
    </script>
    <?php include '../../connection/chat_bubble.php'; ?>
</body>
</html>
