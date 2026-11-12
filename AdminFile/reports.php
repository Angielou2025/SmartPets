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

// --- Summary counts ---
try { $total_pets = (int) $pdo->query("SELECT COUNT(*) FROM pet_inventory")->fetchColumn(); } catch (Exception $e) { $total_pets = 0; }
try { $available_pets = (int) $pdo->query("SELECT COUNT(*) FROM pet_inventory WHERE status = 'Available'")->fetchColumn(); } catch (Exception $e) { $available_pets = 0; }
try { $total_applications = (int) $pdo->query("SELECT COUNT(*) FROM adoption_applications")->fetchColumn(); } catch (Exception $e) { $total_applications = 0; }
try {
    // Binibilang lang bilang "approved" kung Approved ang application AT Adopted pa rin ang pet sa ngayon -
    // kaya kapag binago ng admin ang status ng pet sa Manage Pets, sumasabay dito ang Reports.
    $approved_adoptions = (int) $pdo->query(
        "SELECT COUNT(*) FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'"
    )->fetchColumn();
} catch (Exception $e) {
    $approved_adoptions = 0;
}

// --- Applications broken down by status ---
$statusOrder = ['Pending', 'Under Review', 'Approved', 'Rejected'];
$appsByStatus = array_fill_keys($statusOrder, 0);
try {
    $statusStmt = $pdo->query("SELECT application_status, COUNT(*) AS c FROM adoption_applications GROUP BY application_status");
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $appsByStatus[$row['application_status']] = (int) $row['c'];
    }
} catch (Exception $e) {
    // panatilihin ang zero-filled na default
}

// --- Pets broken down by kind ---
try {
    $kindStmt = $pdo->query("SELECT kind, COUNT(*) AS c FROM pet_inventory GROUP BY kind ORDER BY c DESC");
    $petsByKind = $kindStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $petsByKind = [];
}

// --- Monthly adoption trend (huling 6 buwan, batay sa Approved applications) ---
$trendRaw = [];
try {
    $trendStmt = $pdo->query(
        "SELECT DATE_FORMAT(aa.updated_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
         GROUP BY ym"
    );
    foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trendRaw[$row['ym']] = (int) $row['c'];
    }
} catch (Exception $e) {
    // walang data
}

$monthLabels = [];
$monthData = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthLabels[] = date('M Y', strtotime($ym . '-01'));
    $monthData[] = $trendRaw[$ym] ?? 0;
}

// --- Pinakabagong na-approve na adoptions ---
try {
    $recentStmt = $pdo->query(
        "SELECT aa.full_name, p.name AS pet_name, aa.updated_at
         FROM adoption_applications aa
         JOIN pet_inventory p ON aa.pet_id = p.pet_id
         WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
         ORDER BY aa.updated_at DESC
         LIMIT 50"
    );
    $recentAdoptions = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentAdoptions = [];
}

// --- Medical & Intake (parehong logic ng Reports ng Staff) ---
try {
    $under_treatment = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT mr.pet_id
            FROM medical_records mr
            INNER JOIN (SELECT pet_id, MAX(checkup_date) AS max_date FROM medical_records GROUP BY pet_id) latest
                ON mr.pet_id = latest.pet_id AND mr.checkup_date = latest.max_date
            WHERE mr.status = 'Treatment'
            GROUP BY mr.pet_id
        ) t"
    )->fetchColumn();
} catch (Exception $e) {
    $under_treatment = 0;
}
try {
    $new_intakes_week = (int) $pdo->query("SELECT COUNT(*) FROM pet_intakes WHERE YEARWEEK(intake_date, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
} catch (Exception $e) {
    $new_intakes_week = 0;
}

$statusColors = [
    'Pending' => '#e9c46a',
    'Under Review' => '#4895ef',
    'Approved' => '#2e7d32',
    'Rejected' => '#e63946',
];

// Datos ng dalawang donut chart (Applications by Status at Pets by Kind)
$statusPie = [];
foreach ($statusOrder as $status) {
    $statusPie[] = ['label' => $status, 'value' => (int) $appsByStatus[$status], 'color' => $statusColors[$status]];
}
$kindPie = [];
foreach ($petsByKind as $row) {
    $kindPie[] = ['label' => $row['kind'], 'value' => (int) $row['c'], 'color' => $row['kind'] === 'Cat' ? '#7c3aed' : '#d97706'];
}
$pies = [
    ['id' => 'statusPie', 'title' => 'By Status', 'total' => $total_applications, 'unit' => 'applications', 'data' => $statusPie, 'empty' => 'No adoption applications submitted yet.'],
    ['id' => 'kindPie', 'title' => 'Pets by Kind', 'total' => $total_pets, 'unit' => 'pets', 'data' => $kindPie, 'empty' => 'No pets registered yet.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Reports</title>
    <link rel="stylesheet" href="reports.css?v=4">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <a href="manage_pets.php" class="menu-item">
                    <i class="fa-solid fa-paw"></i> Manage Pets
                </a>
                <a href="payments.php" class="menu-item">
                    <i class="fa-solid fa-money-bill-wave"></i> Payments
                </a>
                <a href="reports.php" class="menu-item active">
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
                <!-- Pamagat ng page (kapareho ng Reports ng Staff, kulay ng admin) -->
                <header class="sp-page-head">
                    <span class="sp-page-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <rect class="ic-fill" x="3.5" y="3.5" width="17" height="17" rx="3"/>
                            <path d="M8 16v-4M12 16V8M16 16v-6"/>
                        </svg>
                    </span>
                    <div class="sp-page-text">
                        <h1>Reports</h1>
                        <p>Overview of pets, adoption applications, and adoption trends</p>
                    </div>
                    <div class="sp-page-actions">
                        <button type="button" class="sp-btn-outline" id="openPrintModal">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/></svg>
                            Print Report
                        </button>
                    </div>
                </header>

                <!-- Mga stat card: madilim hanggang maliwanag -->
                <div class="sp-cards">
                    <a class="sp-card sp-c1" href="manage_pets.php" title="Open Manage Pets">
                        <div class="stat-info">
                            <h1><?php echo str_pad($total_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Total Pets</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Paw print -->
                            <svg viewBox="0 0 24 24">
                                <g class="ic-solid">
                                    <ellipse cx="6.5" cy="10" rx="1.9" ry="2.4" transform="rotate(-18 6.5 10)"/>
                                    <ellipse cx="10" cy="6" rx="1.8" ry="2.4"/>
                                    <ellipse cx="14" cy="6" rx="1.8" ry="2.4"/>
                                    <ellipse cx="17.5" cy="10" rx="1.9" ry="2.4" transform="rotate(18 17.5 10)"/>
                                </g>
                                <path class="ic-fill" d="M12 11c3 0 5.5 2.6 5.5 5.2 0 2-1.8 2.8-5.5 2.8s-5.5-.8-5.5-2.8C6.5 13.6 9 11 12 11z"/>
                            </svg>
                        </div>
                    </a>

                    <a class="sp-card sp-c2" href="manage_pets.php" title="Open Manage Pets">
                        <div class="stat-info">
                            <h1><?php echo str_pad($available_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Available Pets</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Bahay na may puso -->
                            <svg viewBox="0 0 24 24">
                                <path d="M3 11l9-7.5L21 11"/>
                                <path d="M5.5 9.2V20h13V9.2"/>
                                <path class="ic-fill" d="M12 17.5s-3.2-2-3.2-4.3a1.7 1.7 0 0 1 3.2-.9 1.7 1.7 0 0 1 3.2.9c0 2.3-3.2 4.3-3.2 4.3z"/>
                            </svg>
                        </div>
                    </a>

                    <a class="sp-card sp-c3" href="#reportTabs" data-open-tab="status" title="See applications by status">
                        <div class="stat-info">
                            <h1><?php echo str_pad($total_applications, 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Total Applications</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Clipboard na may listahan -->
                            <svg viewBox="0 0 24 24">
                                <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/>
                                <rect class="ic-fill" x="9" y="2.5" width="6" height="3.5" rx="1"/>
                                <path d="M9 11h6M9 14.5h6M9 18h3.5"/>
                            </svg>
                        </div>
                    </a>

                    <a class="sp-card sp-c4" href="#reportTabs" data-open-tab="recent" title="See recent approved adoptions">
                        <div class="stat-info">
                            <h1><?php echo str_pad($approved_adoptions, 2, '0', STR_PAD_LEFT); ?></h1>
                            <p>Approved Adoptions</p>
                        </div>
                        <div class="sp-icon" aria-hidden="true">
                            <!-- Badge na may check -->
                            <svg viewBox="0 0 24 24">
                                <path class="ic-fill" d="M12 3l2.4 1.7 2.9-.1.9 2.8 2.4 1.7-1 2.8 1 2.8-2.4 1.7-.9 2.8-2.9-.1L12 21l-2.4-1.7-2.9.1-.9-2.8L3.4 15l1-2.8-1-2.8 2.4-1.7.9-2.8 2.9.1z"/>
                                <path d="M8.8 12.2l2.2 2.2 4.2-4.4"/>
                            </svg>
                        </div>
                    </a>
                </div>

                <!-- Iisang container: nakapatayo ang mga box sa kaliwa, nasa kanan ang
                     napiling report. Iisa lang ang bukas (connection/sp_tabs.js). -->
                <div class="rpt-panel staff-tabs-vertical" id="reportTabs" data-sp-tabs>
                    <div class="staff-tabbar" role="tablist" aria-label="Report sections">
                        <button type="button" class="staff-tab" data-tab="status" aria-selected="false">
                            <span class="staff-tab-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 6h10M4 12h16M4 18h7"/></svg>
                            </span>
                            <span class="staff-tab-text">Applications by Status</span>
                        </button>
                        <button type="button" class="staff-tab" data-tab="trend" aria-selected="false">
                            <span class="staff-tab-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg>
                            </span>
                            <span class="staff-tab-text">Monthly Adoption Trend</span>
                        </button>
                        <button type="button" class="staff-tab" data-tab="recent" aria-selected="false">
                            <span class="staff-tab-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M8.3 12.3l2.5 2.5 5-5.3"/></svg>
                            </span>
                            <span class="staff-tab-text">Recent Approved Adoptions</span>
                        </button>
                    </div>

                    <div class="staff-tab-empty" data-tab-empty>
                        <span class="staff-tab-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                        </span>
                        <p>Choose a report to view.</p>
                    </div>

                    <div class="staff-pane" data-pane="status" role="tabpanel" hidden>
                        <div class="rp-head">
                            <span class="rp-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h10M4 12h16M4 18h7"/></svg></span>
                            <div>
                                <h3>Applications by Status</h3>
                                <span class="rp-caption">Distribution of all <?php echo (int) $total_applications; ?> adoption applications</span>
                            </div>
                        </div>
                        <div class="rp-pies">
                            <?php foreach ($pies as $pie): ?>
                                <figure class="rp-pie-card">
                                    <figcaption><?php echo htmlspecialchars($pie['title']); ?></figcaption>
                                    <?php if ($pie['total'] > 0): ?>
                                        <div class="rp-pie-body">
                                            <div class="rp-pie-canvas">
                                                <canvas id="<?php echo $pie['id']; ?>" role="img" aria-label="<?php echo htmlspecialchars($pie['title'] . ' pie chart'); ?>"></canvas>
                                                <!-- Kabuuan sa gitna ng donut -->
                                                <div class="rp-pie-center" aria-hidden="true">
                                                    <strong><?php echo $pie['total']; ?></strong>
                                                    <span><?php echo $pie['unit']; ?></span>
                                                </div>
                                            </div>
                                            <!-- Legend: kulay + pangalan + bilang + porsiyento -->
                                            <ul class="rp-legend">
                                                <?php foreach ($pie['data'] as $seg):
                                                    $pct = round($seg['value'] / $pie['total'] * 100);
                                                ?>
                                                    <li>
                                                        <span class="rp-swatch" style="background: <?php echo $seg['color']; ?>;" aria-hidden="true"></span>
                                                        <span class="rp-legend-label"><?php echo htmlspecialchars($seg['label']); ?></span>
                                                        <strong><?php echo $seg['value']; ?></strong>
                                                        <span class="rp-legend-pct"><?php echo $pct; ?>%</span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php else: ?>
                                        <p class="empty-note"><?php echo htmlspecialchars($pie['empty']); ?></p>
                                    <?php endif; ?>
                                </figure>
                            <?php endforeach; ?>
                        </div>

                        <div class="rp-subhead">
                            <h4>Medical &amp; Intake Snapshot</h4>
                        </div>
                        <div class="rp-mini-grid">
                            <div class="rp-mini is-red">
                                <span class="rp-mini-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M6 3.5H5a1 1 0 0 0-1 1V9a5 5 0 0 0 10 0V4.5a1 1 0 0 0-1-1h-1"/><path d="M9 14v1.5a5 5 0 0 0 10 0V13"/><circle cx="19" cy="11" r="2"/></svg>
                                </span>
                                <div>
                                    <strong><?php echo (int) $under_treatment; ?></strong>
                                    <span>Under Treatment</span>
                                </div>
                            </div>
                            <div class="rp-mini is-green">
                                <span class="rp-mini-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M3 11l9-7.5L21 11"/><path d="M5.5 9.2V20h13V9.2"/><path d="M12 11.5v6M9 14.5h6"/></svg>
                                </span>
                                <div>
                                    <strong><?php echo (int) $new_intakes_week; ?></strong>
                                    <span>New Intakes (This Week)</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="staff-pane" data-pane="trend" role="tabpanel" hidden>
                        <div class="rp-head">
                            <span class="rp-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/></svg></span>
                            <div>
                                <h3>Monthly Adoption Trend</h3>
                                <span class="rp-caption">Approved adoptions over the last 6 months &middot; <?php echo (int) array_sum($monthData); ?> total</span>
                            </div>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="adoptionTrendChart"></canvas>
                        </div>
                    </div>

                    <div class="staff-pane" data-pane="recent" role="tabpanel" hidden>
                        <div class="rp-head">
                            <span class="rp-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M8.3 12.3l2.5 2.5 5-5.3"/></svg></span>
                            <div>
                                <h3>Recent Approved Adoptions</h3>
                                <span class="rp-caption">Latest finalized adoptions</span>
                            </div>
                        </div>
                        <div class="rp-table-wrap">
                            <!-- Pagination (5 bawat page): connection/admin_table_pager.php -->
                            <table class="rpt-table" data-per-page="5">
                                <thead>
                                    <tr>
                                        <th>Adopter</th>
                                        <th>Pet</th>
                                        <th>Date Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentAdoptions) > 0): ?>
                                        <?php foreach ($recentAdoptions as $row): ?>
                                            <tr>
                                                <td>
                                                    <div class="rp-person">
                                                        <span class="rp-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($row['full_name'] ?? '?', 0, 1))); ?></span>
                                                        <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
                                                    </div>
                                                </td>
                                                <td class="rp-pet"><?php echo htmlspecialchars($row['pet_name']); ?></td>
                                                <td class="rp-date"><?php echo htmlspecialchars(date('M j, Y', strtotime($row['updated_at']))); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="rp-empty">No approved adoptions yet.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Monthly Adoption Trend - berdeng gradient (madilim sa ibaba, maliwanag sa itaas)
        new Chart(document.getElementById('adoptionTrendChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($monthLabels); ?>,
                datasets: [{
                    label: 'Approved Adoptions',
                    data: <?php echo json_encode($monthData); ?>,
                    backgroundColor: function (context) {
                        const chart = context.chart;
                        const area = chart.chartArea;
                        if (!area) return '#2d6a4f';
                        const g = chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
                        g.addColorStop(0, '#1b4332');
                        g.addColorStop(1, '#52b788');
                        return g;
                    },
                    hoverBackgroundColor: '#2d6a4f',
                    borderRadius: 8,
                    maxBarThickness: 44
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#0b2a1e', padding: 10, cornerRadius: 8, displayColors: false }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#6b7280', font: { weight: 600 } } },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#9aa3ad' },
                        grid: { color: '#eef0f3' },
                        border: { display: false }
                    }
                }
            }
        });

        // Mga donut chart: Applications by Status at Pets by Kind
        (function () {
            const PIES = <?php echo json_encode(array_map(fn ($p) => ['id' => $p['id'], 'total' => $p['total'], 'unit' => $p['unit'], 'data' => $p['data']], $pies), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            PIES.forEach(function (pie) {
                const canvas = document.getElementById(pie.id);
                if (!canvas || !pie.total) return;
                new Chart(canvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: pie.data.map(function (d) { return d.label; }),
                        datasets: [{
                            data: pie.data.map(function (d) { return d.value; }),
                            backgroundColor: pie.data.map(function (d) { return d.color; }),
                            borderColor: '#ffffff',
                            borderWidth: 2,
                            hoverOffset: 8,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '64%',
                        layout: { padding: 8 },
                        plugins: {
                            legend: { display: false }, // may sariling legend sa tabi (may bilang at %)
                            tooltip: {
                                backgroundColor: '#0b2a1e', padding: 10, cornerRadius: 8,
                                callbacks: {
                                    label: function (ctx) {
                                        const pct = Math.round(ctx.parsed / pie.total * 100);
                                        return ' ' + ctx.label + ': ' + ctx.parsed + ' ' + pie.unit + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            });
        })();
    </script>
    <script src="../connection/sp_tabs.js"></script>
    <script>
        // Mga card na nagbubukas ng report tab (hal. Approved Adoptions -> Recent Approved Adoptions)
        document.querySelectorAll('.sp-card[data-open-tab]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                const tab = document.querySelector('.staff-tab[data-tab="' + card.dataset.openTab + '"]');
                if (!tab) return;
                e.preventDefault();
                if (tab.getAttribute('aria-selected') !== 'true') tab.click();
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                document.getElementById('reportTabs').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            });
        });
    </script>

    <?php
    // Mga taong mapipili sa Print Report: mula sa pinakaunang application hanggang ngayong taon
    $printMaxYear = (int) date('Y');
    try {
        $printMinYear = (int) ($pdo->query("SELECT MIN(YEAR(created_at)) FROM adoption_applications")->fetchColumn() ?: $printMaxYear);
    } catch (Exception $e) {
        $printMinYear = $printMaxYear;
    }
    $printMinYear = min($printMinYear, $printMaxYear - 4);
    ?>
    <!-- Print Report: pipiliin ang Weekly / Monthly / Yearly, saka bubuksan ang
         printable na report (print_report.php) sa bagong tab -->
    <div class="pr-modal" id="printModal" hidden>
        <div class="pr-backdrop" data-close></div>
        <form class="pr-dialog" action="print_report.php" method="get" target="_blank" role="dialog" aria-modal="true" aria-labelledby="prTitle">
            <div class="pr-head">
                <span class="pr-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/></svg>
                </span>
                <div>
                    <h3 id="prTitle">Print Report</h3>
                    <p>Includes all reports for the selected period.</p>
                </div>
                <button type="button" class="pr-close" data-close aria-label="Close">&times;</button>
            </div>

            <div class="pr-field">
                <span class="pr-label">Report period</span>
                <div class="pr-seg" role="radiogroup" aria-label="Report period">
                    <label><input type="radio" name="type" value="weekly"><span>Weekly</span></label>
                    <label><input type="radio" name="type" value="monthly" checked><span>Monthly</span></label>
                    <label><input type="radio" name="type" value="yearly"><span>Yearly</span></label>
                </div>
            </div>

            <div class="pr-field" data-for="weekly" hidden>
                <label class="pr-label" for="prDate">Week of</label>
                <input type="date" id="prDate" name="date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                <small class="pr-hint">Pick any day &mdash; the report covers Monday to Sunday of that week.</small>
            </div>

            <div class="pr-field pr-row" data-for="monthly">
                <div>
                    <label class="pr-label" for="prMonth">Month</label>
                    <select id="prMonth" name="month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>"<?php echo $m === (int) date('n') ? ' selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="pr-label" for="prMonthYear">Year</label>
                    <select id="prMonthYear" name="year">
                        <?php for ($y = $printMaxYear; $y >= $printMinYear; $y--): ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="pr-field" data-for="yearly" hidden>
                <label class="pr-label" for="prYear">Year</label>
                <select id="prYear" name="year" disabled>
                    <?php for ($y = $printMaxYear; $y >= $printMinYear; $y--): ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <ul class="pr-includes">
                <li>Summary &amp; approval rate</li>
                <li>Applications by status</li>
                <li>Pets by kind</li>
                <li>Daily / weekly / monthly breakdown</li>
                <li>Approved adoptions list</li>
                <li>Adoption fee payments</li>
            </ul>

            <div class="pr-actions">
                <button type="button" class="pr-btn" data-close>Cancel</button>
                <button type="submit" class="pr-btn primary">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/></svg>
                    Generate &amp; Print
                </button>
            </div>
        </form>
    </div>

    <script>
        // Print Report modal: ipinapakita lang ang mga field ng napiling panahon.
        // Naka-disable ang mga field na hindi kasama para hindi maipadala.
        (function () {
            const modal = document.getElementById('printModal');
            const openBtn = document.getElementById('openPrintModal');
            if (!modal || !openBtn) return;
            const form = modal.querySelector('form');

            function sync() {
                const type = form.querySelector('input[name="type"]:checked').value;
                form.querySelectorAll('[data-for]').forEach(function (box) {
                    const on = box.dataset.for === type;
                    box.hidden = !on;
                    box.querySelectorAll('input, select').forEach(function (el) { el.disabled = !on; });
                });
            }
            function open() {
                modal.hidden = false;
                document.body.classList.add('modal-open');
                sync();
                form.querySelector('input[name="type"]:checked').focus();
            }
            function close() {
                modal.hidden = true;
                document.body.classList.remove('modal-open');
                openBtn.focus();
            }

            openBtn.addEventListener('click', open);
            form.querySelectorAll('input[name="type"]').forEach(function (r) { r.addEventListener('change', sync); });
            modal.querySelectorAll('[data-close]').forEach(function (el) { el.addEventListener('click', close); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) close();
            });
            // Pagkabukas ng report sa bagong tab, isara na ang modal
            form.addEventListener('submit', function () { setTimeout(close, 100); });
        })();
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
