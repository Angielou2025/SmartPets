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

function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

function safe_sum($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (float) ($row['s'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// Isang approved application ang dapat lang bayaran ng adopter (adoption fee), kaya ang unpaid
// count ay simpleng: approved applications - meron nang payment record.
$total_collected = safe_sum($pdo, "SELECT SUM(amount) AS s FROM payments");
$paid_count = safe_count($pdo, "SELECT COUNT(*) AS c FROM payments");
$unpaid_count = safe_count($pdo, "
    SELECT COUNT(*) AS c FROM adoption_applications aa
    WHERE (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
      AND NOT EXISTS (SELECT 1 FROM payments pay WHERE pay.application_id = aa.application_id)
");
$collected_today = safe_sum($pdo, "SELECT SUM(amount) AS s FROM payments WHERE DATE(payment_date) = CURDATE()");

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Approved applications lang ang nagkakaroon ng adoption fee - isama ang payment record
// (kung meron na) para malaman kung Paid na o Unpaid pa.
$records = [];
try {
    $sql = "SELECT aa.application_id, aa.full_name AS applicant_name, aa.email_address AS email, aa.updated_at AS approved_at,
                   p.name AS pet_name, p.kind AS pet_kind,
                   pay.payment_id, pay.amount, pay.reference_number, pay.payment_date
            FROM adoption_applications aa
            JOIN pet_inventory p ON aa.pet_id = p.pet_id
            LEFT JOIN payments pay ON pay.application_id = aa.application_id
            WHERE (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))
            ORDER BY (pay.payment_id IS NULL) DESC, aa.updated_at DESC";
    $stmt = $pdo->query($sql);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $records = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Payments</title>
    <link rel="stylesheet" href="requests.css?v=7">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .badge-paid { background-color: #e6f4ea; color: #1e7e34; }
        .badge-unpaid { background-color: #fce8e6; color: #c5221f; }

        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(2,6,23,0.5);
            display: none; align-items: center; justify-content: center; padding: 24px; z-index: 9999;
        }
        .modal-backdrop.show { display: flex; }
        .modal-card {
            background: #fff; border-radius: 12px; max-width: 440px; width: 100%;
            padding: 22px; box-shadow: 0 20px 60px rgba(2,6,23,0.3);
        }
        .modal-card h3 { margin-bottom: 4px; color: var(--text-dark); }
        .modal-card .sub-text { margin-bottom: 16px; }
        .modal-card .form-group { margin-bottom: 14px; }
        .modal-card label { display: block; font-size: 13px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
        .modal-card input {
            width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;
        }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
        .btn-cancel {
            background: #f3f4f6; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;
        }
        .btn-submit {
            background: var(--primary-green); color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;
        }
    </style>
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
                <a href="payments.php" class="menu-item active">
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
                <div class="page-heading" style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h2>Payments</h2>
                        <p class="page-subtitle">Record and monitor GCash adoption fee payments</p>
                    </div>
                    <a href="/Caps2/StaffFile/export_report.php?type=payments&range=month" class="btn-export-records">
                        <i class="fa-solid fa-file-arrow-down"></i> Export Records
                    </a>
                </div>

                <?php if (!empty($success_message)): ?>
                    <div style="background:#e8f5e9; color:#2e7d32; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:6px; margin-bottom:15px;"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-card card-green">
                        <div>
                            <h1>&#8369;<?php echo number_format($total_collected, 2); ?></h1>
                            <p>Total Collected</p>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    </div>
                    <div class="summary-card card-white">
                        <div>
                            <h1><?php echo (int) $paid_count; ?></h1>
                            <p>Paid Adoptions</p>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="summary-card card-orange">
                        <div>
                            <h1><?php echo (int) $unpaid_count; ?></h1>
                            <p>Awaiting Payment</p>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                    </div>
                    <div class="summary-card card-black">
                        <div>
                            <h1>&#8369;<?php echo number_format($collected_today, 2); ?></h1>
                            <p>Collected Today</p>
                        </div>
                        <div class="summary-icon"><i class="fa-solid fa-calendar-day"></i></div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header-row">
                        <div>
                            <h3>Adoption Fee Payments</h3>
                            <p class="panel-caption">Every approved adoption, fixed &#8369;500.00 GCash fee</p>
                        </div>
                    </div>

                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search by applicant or pet name.." onkeyup="filterPayments()">
                    </div>

                    <div class="table-wrapper">
                        <table class="requests-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Pet</th>
                                    <th>Approved</th>
                                    <th>Amount</th>
                                    <th>Reference No.</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Paid On / Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($records) > 0): ?>
                                    <?php foreach ($records as $r):
                                        $isPaid = !empty($r['payment_id']);
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($r['applicant_name']); ?></strong>
                                                <div class="sub-text"><?php echo htmlspecialchars($r['email']); ?></div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($r['pet_name']); ?>
                                                <div class="sub-text"><?php echo htmlspecialchars($r['pet_kind']); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($r['approved_at']))); ?></td>
                                            <td><?php echo $isPaid ? '&#8369;' . number_format((float) $r['amount'], 2) : '&#8369;500.00'; ?></td>
                                            <td><?php echo $isPaid ? htmlspecialchars($r['reference_number']) : '&mdash;'; ?></td>
                                            <td><span class="badge <?php echo $isPaid ? 'badge-paid' : 'badge-unpaid'; ?>"><?php echo $isPaid ? 'Paid' : 'Unpaid'; ?></span></td>
                                            <td style="text-align: right;">
                                                <?php if ($isPaid): ?>
                                                    <?php echo htmlspecialchars(date('M j, Y', strtotime($r['payment_date']))); ?>
                                                    <div class="sub-text"><?php echo htmlspecialchars(date('g:i A', strtotime($r['payment_date']))); ?></div>
                                                <?php else: ?>
                                                    <button type="button" class="btn-view" onclick="openPaymentModal(<?php echo (int) $r['application_id']; ?>, '<?php echo htmlspecialchars(addslashes($r['applicant_name'])); ?>', '<?php echo htmlspecialchars(addslashes($r['pet_name'])); ?>')">Record Payment</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 30px; color: #666;">
                                            No approved adoptions yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResults" style="display: none; text-align: center; padding: 30px; color: #666;">
                            No payments found matching your search.
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div id="paymentModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
        <div class="modal-card" role="document">
            <h3>Record GCash Payment</h3>
            <p class="sub-text" id="paymentModalSubtitle"></p>
            <form method="POST" action="process_add_payment.php">
                <input type="hidden" name="application_id" id="payment_application_id">
                <div class="form-group">
                    <label>Amount *</label>
                    <input type="number" name="amount" id="payment_amount" step="0.01" min="0" value="500.00" required>
                </div>
                <div class="form-group">
                    <label>GCash Reference Number *</label>
                    <input type="text" name="reference_number" placeholder="e.g. 1234567890123" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Save Payment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterPayments() {
            const filter = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('.requests-table tbody tr');
            const noResults = document.getElementById('noResults');
            let visible = 0;

            rows.forEach(function (row) {
                const cells = row.getElementsByTagName('td');
                if (cells.length < 7) return;

                const text = (cells[0].textContent + ' ' + cells[1].textContent).toLowerCase();
                const matches = text.indexOf(filter) > -1;
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            });

            if (noResults) {
                noResults.style.display = (visible === 0 && filter !== '') ? 'block' : 'none';
            }
        }

        function openPaymentModal(applicationId, applicantName, petName) {
            document.getElementById('payment_application_id').value = applicationId;
            document.getElementById('paymentModalSubtitle').textContent = applicantName + ' — ' + petName;
            document.getElementById('payment_amount').value = '500.00';
            document.getElementById('paymentModal').classList.add('show');
        }
        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('show');
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closePaymentModal();
        });
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
