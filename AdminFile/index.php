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

// Count statistics from database
$total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'Admin'")->fetchColumn();
$staff_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Staff'")->fetchColumn();
$adopter_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Adopter'")->fetchColumn();

// For pets and adoptions, check if tables exist and count them
try {
    $available_pets = $pdo->query("SELECT COUNT(*) FROM pet_inventory WHERE status = 'Available'")->fetchColumn();
} catch (Exception $e) {
    $available_pets = 0;
}

try {
    // Isinasabay sa Manage Pets: approved lang ito kung Adopted pa rin ang pet sa ngayon.
    $successful_adoptions = $pdo->query(
        "SELECT COUNT(*) FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'"
    )->fetchColumn();
} catch (Exception $e) {
    $successful_adoptions = 0;
}

// Maliliit na detalye sa ilalim ng mga stat card
try {
    $total_pets = (int) $pdo->query("SELECT COUNT(*) FROM pet_inventory")->fetchColumn();
} catch (Exception $e) {
    $total_pets = 0;
}
try {
    $adoptions_this_month = (int) $pdo->query(
        "SELECT COUNT(*) FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
           AND YEAR(aa.updated_at) = YEAR(CURDATE()) AND MONTH(aa.updated_at) = MONTH(CURDATE())"
    )->fetchColumn();
} catch (Exception $e) {
    $adoptions_this_month = 0;
}

// Recent Activities: pinakabagong entries mula sa activity_logs (parehong source ng
// Activity Logs page). Lima bawat page (pagination: connection/admin_table_pager.php).
$activities_limit = 20;
try {
    $activities = $pdo->query(
        "SELECT actor_name, actor_role, action, status, created_at
         FROM activity_logs
         ORDER BY created_at DESC, log_id DESC
         LIMIT $activities_limit"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activities = [];
}

// Payment Collection Today (kapareho ng Staff Dashboard): ang mga nagbayad
// ngayong araw lang. Aktwal na bayad lang - hindi kasama ang GCash link na
// hindi pa nababayaran.
$revenue_today = 0.0;
$payments_today = [];
try {
    $revenue_today = (float) $pdo->query(
        "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'Paid' AND DATE(payment_date) = CURDATE()"
    )->fetchColumn();

    $payments_today = $pdo->query(
        "SELECT pay.payment_id, pay.amount, pay.reference_number, pay.payment_date,
                u.username AS adopter_name, pt.name AS pet_name
           FROM payments pay
           JOIN users u ON pay.adopter_id = u.user_id
           JOIN pet_inventory pt ON pay.pet_id = pt.pet_id
          WHERE pay.status = 'Paid' AND DATE(pay.payment_date) = CURDATE()
          ORDER BY pay.payment_date DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $payments_today = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMART PET - Dashboard</title>
  <link rel="stylesheet" href="style.css?v=14">
  <!-- Font Awesome para sa mga icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Chart.js para sa analytics graph -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="admin-dash">

  <div class="app-container">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="brand">
        <div class="logo-circle">
          <img src="../Images/caloocan_logo.jpg" alt="City Logo">
        </div>
        <h2>SMART <span>PET</span></h2>
      </div>

      <nav class="sidebar-menu">
        <a href="index.php" class="menu-item active">
          <i class="fa-solid fa-house"></i> Dashboard
        </a>
        <a href="Manage_staff_file/manage_staff.php" class="menu-item">
          <i class="fa-solid fa-user-gear"></i> Manage Staff
        </a>
        <a href="Manage_adopters_file/manage_adopters.php" class="menu-item">
          <i class="fa-solid fa-users"></i> Manage Adopters
        </a>
        <a href="manage_pets.php" class="menu-item">
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
          <!-- Lagay ng Caloocan landmark image dito -->
          <img src="../Images/caloocan_image.png" alt="Caloocan Arch Landmark" width="300">
        </div>
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="main-wrapper">
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

      <!-- Dashboard Content -->
      <section class="dashboard-content">

        <!-- Summary Cards (napipindot papunta sa kaugnay na page) -->
        <div class="cards-grid">
          <a class="stat-card white-card" href="Manage_adopters_file/manage_adopters.php" title="Open Manage Adopters">
            <div class="stat-info">
              <h1><?php echo $total_users; ?></h1>
              <p>Total Users</p>
              <small><?php echo (int) $adopter_count; ?> adopters &middot; <?php echo (int) $staff_count; ?> staff</small>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
          </a>

          <a class="stat-card black-card" href="Manage_staff_file/manage_staff.php" title="Open Manage Staff">
            <div class="stat-info">
              <h1><?php echo $staff_count; ?></h1>
              <p>Staff Members</p>
              <small>Shelter team accounts</small>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-user-group"></i></div>
          </a>

          <a class="stat-card green-orange-card" href="manage_pets.php" title="Open Manage Pets">
            <div class="stat-info">
              <h1><?php echo $available_pets; ?></h1>
              <p>Available Pets</p>
              <small>of <?php echo $total_pets; ?> pets in the shelter</small>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-paw"></i></div>
          </a>

          <a class="stat-card solid-green-card" href="reports.php" title="Open Reports">
            <div class="stat-info">
              <h1><?php echo $successful_adoptions; ?></h1>
              <p>Successful Adoptions</p>
              <small><?php echo $adoptions_this_month; ?> this month</small>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
          </a>
        </div>

        <!-- Dalawang container na magkatabi: mga tab sa kaliwa, ang Successful
             Adoptions chart sa kanan (laging nakikita) -->
        <div class="dash-row">

        <!-- Recent Activities: laging nakikita (iisa na lang ang laman ng kaliwa) -->
        <section class="panel dash-tabs-panel activities-panel">
          <div class="panel-header">
            <div class="panel-title">
              <span class="panel-icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
              <div>
                <h3>Recent Activities</h3>
                <p class="chart-sub">Latest actions by admin and staff.</p>
              </div>
            </div>
            <span class="panel-meta"><span class="panel-meta-dot" aria-hidden="true"></span><?php echo count($activities); ?> latest</span>
          </div>

          <div class="activities-body" id="activitiesBody">
            <div class="table-container">
              <?php if (empty($activities)): ?>
                <p class="activities-empty">No activity recorded yet. Actions by admin and staff will appear here.</p>
              <?php else: ?>
                <!-- Pagination (8 bawat page): connection/admin_table_pager.php -->
                <table data-per-page="8">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>User</th>
                      <th>Action</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($activities as $i => $log):
                      $badgeClass = strtolower($log['status']); // completed | pending | failed
                    ?>
                      <tr class="activity-row">
                        <td title="<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))); ?>">
                          <?php echo htmlspecialchars(date('M j', strtotime($log['created_at']))); ?>
                        </td>
                        <td>
                          <strong><?php echo htmlspecialchars($log['actor_name']); ?></strong>
                          <span class="activity-role"><?php echo htmlspecialchars($log['actor_role']); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                        <td><span class="status-badge <?php echo htmlspecialchars($badgeClass); ?>"><?php echo htmlspecialchars($log['status']); ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>

            <div class="panel-footer">
              <a href="activity_logs.php" class="show-more-btn view-all-btn" id="viewAllBtn">View all activity logs</a>
            </div>
          </div>
        </section>

        <!-- Kanan: Successful Adoptions chart, at sa ilalim nito ang Payment Collection Today -->
        <div class="dash-side">

        <!-- Successful Adoptions (chart) - sariling container, laging nakikita -->
        <section class="panel dash-chart-card chart-panel">
          <div class="panel-header">
            <div class="panel-title">
              <span class="panel-icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
              <div>
                <h3>Successful Adoptions</h3>
                <p class="chart-sub">Click a point to drill down into months, weeks, and days.</p>
              </div>
            </div>
          </div>

          <!-- Toolbar: saklaw ng chart sa kaliwa, buod (na legend na rin) sa kanan -->
          <div class="chart-toolbar">
            <div class="chart-nav">
              <div class="chart-seg" role="group" aria-label="Chart range">
                <button type="button" class="chart-seg-btn active" id="yearBtn" aria-pressed="true">Year</button>
                <button type="button" class="chart-seg-btn" id="monthBtn" aria-pressed="false">Month</button>
              </div>
              <button type="button" class="chart-back" id="backBtn" hidden>
                <i class="fa-solid fa-chevron-left"></i> Back
              </button>
              <span class="chart-scope" id="chartScopeTitle">All years</span>
            </div>
            <div class="chart-stats">
              <div class="chart-stat">
                <span class="chart-swatch is-line is-green" aria-hidden="true"></span>
                <div>
                  <strong id="statAdoptions">&mdash;</strong>
                  <small>Adoptions</small>
                </div>
              </div>
              <div class="chart-stat">
                <span class="chart-swatch is-line" aria-hidden="true"></span>
                <div>
                  <strong id="statApplications">&mdash;</strong>
                  <small>Applications</small>
                </div>
              </div>
            </div>
          </div>

          <div class="chart-wrapper">
            <canvas id="adoptionChart"></canvas>
            <p class="chart-empty" id="chartEmpty" hidden>No applications or adoptions in this period.</p>
          </div>
        </section>

        <!-- Payment Collection Today (kapareho ng Staff Dashboard): ang mga nagbayad ngayong araw lang -->
        <section class="panel dash-pay-card">
          <div class="panel-header">
            <div class="panel-title">
              <span class="panel-icon is-amber" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span>
              <div>
                <h3>Payment Collection Today</h3>
                <p class="chart-sub">GCash adoption fees received today</p>
              </div>
            </div>
            <a href="payments.php" class="pay-view-all">View all <i class="fa-solid fa-arrow-right"></i></a>
          </div>

          <div class="pay-period-summary">
            <span class="pay-period-total">&#8369;<?php echo number_format($revenue_today, 2); ?></span>
            <span class="pay-period-count">collected today from <?php echo count($payments_today); ?> payment<?php echo count($payments_today) === 1 ? '' : 's'; ?></span>
          </div>

          <?php if (empty($payments_today)): ?>
            <div class="pay-empty">
              <i class="fa-solid fa-receipt"></i>
              <p>No payments collected today yet.</p>
            </div>
          <?php else: ?>
            <div class="table-container pay-today-table">
              <!-- Pagination (5 bawat page): connection/admin_table_pager.php -->
              <table data-per-page="5">
                <thead>
                  <tr>
                    <th>Adopter</th>
                    <th>Amount</th>
                    <th>Reference No.</th>
                    <th>Time</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($payments_today as $p): $payName = $p['adopter_name'] ?: 'N/A'; ?>
                    <tr>
                      <td>
                        <div class="pay-adopter">
                          <span class="pay-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($payName, 0, 1))); ?></span>
                          <div>
                            <strong><?php echo htmlspecialchars($payName); ?></strong>
                            <span class="activity-role"><?php echo htmlspecialchars($p['pet_name'] ?: 'N/A'); ?></span>
                          </div>
                        </div>
                      </td>
                      <td class="pay-amount">&#8369;<?php echo number_format((float) $p['amount'], 2); ?></td>
                      <td>
                        <?php if (!empty($p['reference_number'])): ?>
                          <span class="pay-ref" onclick="copyReference(this)" title="Click to copy"><?php echo htmlspecialchars($p['reference_number']); ?></span>
                        <?php else: ?>&mdash;<?php endif; ?>
                      </td>
                      <td class="pay-time"><?php echo htmlspecialchars(date('g:i A', strtotime($p['payment_date']))); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        </div>

        </div>
      </section>
    </main>
  </div>

  <script src="script.js?v=8"></script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
