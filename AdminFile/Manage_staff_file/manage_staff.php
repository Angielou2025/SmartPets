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

// Kunin ang listahan ng mga staff account
try {
    $staff_stmt = $pdo->prepare("SELECT user_id, username, email, profile_image, status, created_at FROM users WHERE role = 'Staff' ORDER BY created_at DESC");
    $staff_stmt->execute();
    $staffList = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $staffList = [];
}

// Kunin ang listahan ng mga naka-register na adopter (pwedeng gawing staff)
try {
    $adopters_stmt = $pdo->prepare("SELECT user_id, username, email, profile_image, created_at FROM users WHERE role = 'Adopter' ORDER BY username ASC");
    $adopters_stmt->execute();
    $adoptersList = $adopters_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $adoptersList = [];
}

// Sinong may nakabinbing hiling na pagpapalit ng role. Hindi pa nagbabago ang
// role nila hangga't hindi sila sumasagot sa pop-up sa panig nila.
$pendingRoleRequests = sp_pending_role_requests($pdo);

// Mga bilang para sa summary cards
$staff_total = count($staffList);
$staff_active = count(array_filter($staffList, fn ($s) => ($s['status'] ?? '') !== 'On Leave'));
$staff_leave = $staff_total - $staff_active;
$pending_requests = count($pendingRoleRequests);

$returnPath = '/Caps2/AdminFile/Manage_staff_file/manage_staff.php';

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Staff Management</title>
    <link rel="stylesheet" href="manage_staff.css?v=4">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Export: PDF (jsPDF + AutoTable) at Print -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js" defer></script>
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
                    <a href="manage_staff.php" class="menu-item active">
                        <i class="fa-solid fa-user-gear"></i> Manage Staff
                    </a>
                    <a href="../Manage_adopters_file/manage_adopters.php" class="menu-item">
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
                <?php if (!empty($success_message)): ?>
                    <div style="background:#e8f5e9; color:#2e7d32; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-weight:600;">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div style="background:#fdecea; color:#c0392b; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-weight:600;">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Summary cards: madilim hanggang maliwanag (kapareho ng Dashboard) -->
                <div class="ms-cards">
                    <div class="ms-card ms-c1">
                        <div>
                            <strong><?php echo $staff_total; ?></strong>
                            <span>Total Staff</span>
                            <small>Accounts with staff access</small>
                        </div>
                        <div class="ms-card-icon"><i class="fa-solid fa-user-group"></i></div>
                    </div>
                    <div class="ms-card ms-c2">
                        <div>
                            <strong><?php echo $staff_active; ?></strong>
                            <span>Active</span>
                            <small><?php echo $staff_leave; ?> on leave</small>
                        </div>
                        <div class="ms-card-icon"><i class="fa-solid fa-user-check"></i></div>
                    </div>
                    <div class="ms-card ms-c3">
                        <div>
                            <strong><?php echo $pending_requests; ?></strong>
                            <span>Pending Role Requests</span>
                            <small>Waiting for the user to respond</small>
                        </div>
                        <div class="ms-card-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                    </div>
                </div>

                <div class="card-panel">
                    <!-- Section Title & Action Button -->
                    <div class="card-header">
                        <div class="ms-title">
                            <span class="ms-title-icon" aria-hidden="true"><i class="fa-solid fa-user-gear"></i></span>
                            <div>
                                <h2>Staff Accounts</h2>
                                <p>Manage who can access the staff panel. Role changes take effect once the user accepts.</p>
                            </div>
                        </div>
                        <div class="ms-head-actions">
                            <!-- Export ng listahan ng staff: Print / PDF -->
                            <div class="ms-export" id="exportWrap">
                                <button type="button" class="ms-btn-outline" id="exportBtn" aria-haspopup="menu" aria-expanded="false">
                                    <i class="fa-solid fa-file-export"></i> Export <i class="fa-solid fa-chevron-down ms-caret"></i>
                                </button>
                                <div class="ms-export-menu" id="exportMenu" role="menu">
                                    <div class="ms-export-hint" id="exportHint"></div>
                                    <button type="button" role="menuitem" data-export="print"><i class="fa-solid fa-print"></i><span><b>Print</b><small>Open the print dialog</small></span></button>
                                    <button type="button" role="menuitem" data-export="pdf"><i class="fa-solid fa-file-pdf"></i><span><b>Download PDF</b><small>A4 document with a table</small></span></button>
                                </div>
                            </div>
                            <button class="btn-add-staff" onclick="openAddStaffModal()">
                                <i class="fa-solid fa-plus"></i> Add New Staff
                            </button>
                        </div>
                    </div>

                    <!-- Search + bilang -->
                    <div class="ms-toolbar">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="staffSearchInput" placeholder="Search staff by name or email.." onkeyup="filterStaffTable()">
                        </div>
                        <span class="ms-count" id="staffCount"><?php echo $staff_total; ?> staff member<?php echo $staff_total === 1 ? '' : 's'; ?></span>
                    </div>

                    <!-- Staff Data Table -->
                    <div class="table-wrapper">
                        <table class="staff-table">
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th>Date Added</th>
                                    <th>Status</th>
                                    <th class="th-actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($staffList) > 0): ?>
                                    <?php foreach ($staffList as $staff): ?>
                                        <tr class="staff-row">
                                            <td>
                                                <div class="ms-person">
                                                    <div class="user-photo">
                                                        <?php if (!empty($staff['profile_image'])): ?>
                                                            <img src="../../uploads/<?php echo htmlspecialchars($staff['profile_image']); ?>" alt="Photo">
                                                        <?php else: ?>
                                                            <span><?php echo htmlspecialchars(mb_strtoupper(mb_substr($staff['username'], 0, 1))); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="ms-person-text">
                                                        <strong class="staff-name"><?php echo htmlspecialchars($staff['username']); ?></strong>
                                                        <a href="mailto:<?php echo htmlspecialchars($staff['email']); ?>" class="email-link staff-email"><?php echo htmlspecialchars($staff['email']); ?></a>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="ms-date"><?php echo htmlspecialchars(date('M j, Y', strtotime($staff['created_at']))); ?></td>
                                            <td>
                                                <?php $isLeave = ($staff['status'] === 'On Leave'); ?>
                                                <span class="ms-status <?php echo $isLeave ? 'is-leave' : 'is-active'; ?>">
                                                    <span class="ms-dot"></span><?php echo htmlspecialchars($staff['status']); ?>
                                                </span>
                                            </td>
                                            <td class="td-actions">
                                                <div class="action-cell">
                                                    <?php $pending = $pendingRoleRequests[(int) $staff['user_id']] ?? null; ?>
                                                    <?php if ($pending): ?>
                                                        <span class="role-pending" title="Waiting for this staff member to accept or decline">
                                                            <i class="fa-solid fa-hourglass-half"></i>
                                                            Pending &rarr; <?php echo htmlspecialchars($pending['to_role']); ?>
                                                        </span>
                                                        <form method="POST" action="/Caps2/connection/request_role_change.php" style="display:inline;"
                                                              data-confirm="Cancel the pending role change request for <?php echo htmlspecialchars($staff['username']); ?>? They will stay as Staff."
                                                              data-confirm-title="Cancel Request?"
                                                              data-confirm-ok="Yes, cancel request"
                                                              data-confirm-icon="fa-ban"
                                                              data-confirm-tone="orange">
                                                            <input type="hidden" name="action" value="cancel">
                                                            <input type="hidden" name="request_id" value="<?php echo (int) $pending['request_id']; ?>">
                                                            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnPath); ?>">
                                                            <button type="submit" class="role-cancel">Cancel</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" action="/Caps2/connection/request_role_change.php" style="display:inline;"
                                                              data-confirm="Ask <?php echo htmlspecialchars($staff['username']); ?> to move back to Adopter? They will lose access to the staff panel once they accept."
                                                              data-confirm-title="Move to Adopter?"
                                                              data-confirm-ok="Send request"
                                                              data-confirm-icon="fa-user-minus"
                                                              data-confirm-tone="red">
                                                            <input type="hidden" name="action" value="request">
                                                            <input type="hidden" name="user_id" value="<?php echo (int) $staff['user_id']; ?>">
                                                            <input type="hidden" name="to_role" value="Adopter">
                                                            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnPath); ?>">
                                                            <button type="submit" class="ms-btn-remove" title="Ask this staff member to move back to Adopter">
                                                                <i class="fa-solid fa-user-minus"></i> Move to Adopter
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="ms-empty">
                                            <i class="fa-solid fa-user-plus"></i>
                                            <p>No staff accounts yet.</p>
                                            <span>Click <b>Add New Staff</b> to invite a registered adopter.</span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noStaffResults" style="display:none; text-align:center; padding:30px; color:#666;">
                            No staff found matching your search.
                        </div>
                        <div class="table-empty-space"></div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add New Staff Modal -->
    <div id="addStaffModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Add New Staff</h3>
                <button class="close-btn" onclick="closeAddStaffModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color:#666; font-size:13px; margin-bottom:14px;">Select a registered adopter to invite them as staff. A request is sent to them first &mdash; their role only changes once they accept it.</p>
                <div class="search-box" style="width:100%; margin-bottom:16px;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="adopterSearchInput" placeholder="Search adopter by name or email.." onkeyup="filterAdopterList()">
                </div>
                <div id="adopterList" class="adopter-list">
                    <?php if (empty($adoptersList)): ?>
                        <p style="color:#666; text-align:center; padding:20px;">Walang naka-register na adopter sa ngayon.</p>
                    <?php else: ?>
                        <?php foreach ($adoptersList as $adopter): ?>
                            <div class="adopter-row" data-name="<?php echo htmlspecialchars(strtolower($adopter['username'])); ?>" data-email="<?php echo htmlspecialchars(strtolower($adopter['email'])); ?>">
                                <div class="adopter-row-info">
                                    <div class="user-photo" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                                        <?php if (!empty($adopter['profile_image'])): ?>
                                            <img src="../../uploads/<?php echo htmlspecialchars($adopter['profile_image']); ?>" alt="Photo" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                        <?php else: ?>
                                            <i class="fa-solid fa-user" style="color: #999; font-size: 16px;"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($adopter['username']); ?></strong>
                                        <div class="adopter-email"><?php echo htmlspecialchars($adopter['email']); ?></div>
                                    </div>
                                </div>
                                <?php $pending = $pendingRoleRequests[(int) $adopter['user_id']] ?? null; ?>
                                <?php if ($pending): ?>
                                    <span class="role-pending" title="Waiting for this adopter to accept or decline">
                                        <i class="fa-solid fa-hourglass-half"></i>
                                        Pending &rarr; <?php echo htmlspecialchars($pending['to_role']); ?>
                                    </span>
                                <?php else: ?>
                                    <form method="POST" action="/Caps2/connection/request_role_change.php"
                                          data-confirm="Invite <?php echo htmlspecialchars($adopter['username']); ?> to become Staff? Their account only becomes Staff once they accept the invitation."
                                          data-confirm-title="Send Staff Invite?"
                                          data-confirm-ok="Send invite"
                                          data-confirm-icon="fa-paper-plane">
                                        <input type="hidden" name="action" value="request">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $adopter['user_id']; ?>">
                                        <input type="hidden" name="to_role" value="Staff">
                                        <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnPath); ?>">
                                        <button type="submit" class="btn-submit ms-invite"><i class="fa-solid fa-paper-plane"></i> Send Invite</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <p id="noAdopterResults" style="display:none; color:#666; text-align:center; padding:20px;">No adopter found matching your search.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ---- Export ng listahan ng staff: Print at PDF (may "Generated by" at "From") ----
        // Lahat ng staff na tugma sa search (kapag walang search: lahat ng staff)
        function staffExportData() {
            const rows = Array.prototype.filter.call(document.querySelectorAll('.staff-table tbody tr.staff-row'),
                function (r) { return r.style.display !== 'none'; });
            const q = document.getElementById('staffSearchInput').value.trim();
            const now = new Date();
            return {
                scope: q ? 'Search: "' + q + '"' : 'All staff accounts',
                stamp: now.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) + ' ' +
                    now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }),
                fileStamp: now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0'),
                by: <?php echo json_encode((string) $username); ?>,
                rows: rows.map(function (r) {
                    const t = function (sel) { const el = r.querySelector(sel); return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; };
                    const pending = r.querySelector('.role-pending');
                    return {
                        name: t('.staff-name'), email: t('.staff-email'), added: t('.ms-date'),
                        status: t('.ms-status'), note: pending ? pending.textContent.replace(/\s+/g, ' ').trim() : ''
                    };
                })
            };
        }

        function msEsc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }

        const MS_REPORT_CSS =
            '*{box-sizing:border-box}.rp{font-family:"Segoe UI",Arial,sans-serif;color:#111827;font-size:10pt;background:#fff}' +
            '.rp .lh{display:flex;align-items:center;gap:12px;padding-bottom:10px;border-bottom:2px solid #1b4332}.rp .lh img{width:52px;height:52px;border-radius:50%}' +
            '.rp .n{font-size:15pt;font-weight:800;color:#1b4332}.rp .s{font-size:9pt;color:#4b5563}.rp .r{margin-left:auto;text-align:right;font-size:8.5pt;color:#6b7280}' +
            '.rp h1{margin:14px 0 2px;text-align:center;font-size:13pt;letter-spacing:1.5px;text-transform:uppercase}.rp .sub{text-align:center;font-size:9.5pt;color:#4b5563;margin-bottom:12px}' +
            '.rp table{width:100%;border-collapse:collapse;font-size:9.5pt}.rp thead{display:table-header-group}' +
            '.rp th{background:#f3f4f6;border-top:1px solid #111;border-bottom:1px solid #111;padding:6px 8px;text-align:left;font-size:7.5pt;text-transform:uppercase;letter-spacing:.5px}' +
            '.rp td{padding:6px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top}.rp tr{break-inside:avoid}.rp .c{text-align:center}.rp small{color:#6b7280}' +
            '.rp .note{margin-top:12px;padding:6px 8px;border:1px solid #d1d5db;font-size:8pt;color:#4b5563}';

        function staffReportHtml(d) {
            const body = d.rows.map(function (x, i) {
                return '<tr><td class="c">' + (i + 1) + '</td><td><b>' + msEsc(x.name) + '</b></td><td>' + msEsc(x.email) + '</td>' +
                    '<td>' + msEsc(x.added) + '</td><td>' + msEsc(x.status) + (x.note ? '<br><small>' + msEsc(x.note) + '</small>' : '') + '</td></tr>';
            }).join('');
            return '<div class="rp"><div class="lh"><img src="/Caps2/Images/caloocan_logo.jpg" alt=""><div><div class="n">SMART PET</div><div class="s">Pet Adoption Management System</div></div>' +
                '<div class="r">Generated: ' + msEsc(d.stamp) + '<br>Generated by: ' + msEsc(d.by) + '<br>From: ' + msEsc(d.from || '') + '</div></div>' +
                '<h1>Staff Accounts Report</h1><div class="sub">' + msEsc(d.scope) + ' &middot; ' + d.rows.length + ' staff member' + (d.rows.length === 1 ? '' : 's') + '</div>' +
                '<table><thead><tr><th class="c">No.</th><th>Name</th><th>Email</th><th>Date Added</th><th>Status</th></tr></thead><tbody>' + body + '</tbody></table>' +
                '<div class="note"><b>Confidential:</b> This report contains personal information and is intended for official use only (Data Privacy Act of 2012, RA 10173).</div></div>';
        }

        function msDownload(url, name) {
            const a = document.createElement('a');
            a.href = url; a.download = name;
            document.body.appendChild(a); a.click(); a.remove();
        }

        function msLogo() {
            return new Promise(function (resolve) {
                const img = new Image();
                img.onload = function () {
                    const c = document.createElement('canvas');
                    c.width = img.naturalWidth; c.height = img.naturalHeight;
                    c.getContext('2d').drawImage(img, 0, 0);
                    try { resolve(c.toDataURL('image/jpeg', 0.9)); } catch (e) { resolve(null); }
                };
                img.onerror = function () { resolve(null); };
                img.src = '/Caps2/Images/caloocan_logo.jpg';
            });
        }

        async function staffExport(kind) {
            const d = staffExportData();
            if (!d.rows.length) { alert('There are no staff accounts to export.'); return; }
            const name = 'staff-accounts-' + d.fileStamp;
            // Sino ang nag-print at kanino/saan galing ang papel
            const people = await spAskExportPeople(d.by);
            if (!people) return;
            d.by = people.by;
            d.from = people.from;

            if (kind === 'print') {
                // Print sa ibabaw mismo ng page (nakatagong iframe) - walang bagong tab
                const old = document.getElementById('spPrintFrame');
                if (old) old.remove();
                const f = document.createElement('iframe');
                f.id = 'spPrintFrame';
                f.setAttribute('aria-hidden', 'true');
                f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
                document.body.appendChild(f);
                const doc = f.contentWindow.document;
                doc.open();
                doc.write('<!doctype html><html><head><meta charset="utf-8"><title>Staff Accounts Report</title><style>@page{size:A4;margin:14mm}body{margin:0}' +
                    MS_REPORT_CSS + '</style></head><body>' + staffReportHtml(d) + '</body></html>');
                doc.close();
                const go = function () { f.contentWindow.focus(); f.contentWindow.print(); };
                const img = doc.querySelector('img');
                if (img && !img.complete) { img.onload = img.onerror = function () { setTimeout(go, 100); }; } else { setTimeout(go, 200); }
                return;
            }

            if (kind === 'pdf') {
                const jsPDF = window.jspdf && window.jspdf.jsPDF;
                if (!jsPDF) { alert('The PDF tool could not be loaded. Please check your internet connection.'); return; }
                const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const W = doc.internal.pageSize.getWidth();
                const logo = await msLogo();
                if (logo) doc.addImage(logo, 'JPEG', 14, 10, 15, 15);
                doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(27, 67, 50);
                doc.text('SMART PET', 32, 16);
                doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(75, 85, 99);
                doc.text('Pet Adoption Management System', 32, 21);
                doc.setFontSize(8.5); doc.setTextColor(107, 114, 128);
                doc.text('Generated: ' + d.stamp, W - 14, 15, { align: 'right' });
                doc.text('Generated by: ' + d.by, W - 14, 20, { align: 'right' });
                doc.text('From: ' + (d.from || ''), W - 14, 25, { align: 'right' });
                doc.setDrawColor(27, 67, 50); doc.setLineWidth(0.6); doc.line(14, 28, W - 14, 28);
                doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(17, 24, 39);
                doc.text('STAFF ACCOUNTS REPORT', W / 2, 37, { align: 'center' });
                doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5); doc.setTextColor(75, 85, 99);
                doc.text(d.scope + '  -  ' + d.rows.length + ' staff member' + (d.rows.length === 1 ? '' : 's'), W / 2, 43, { align: 'center' });
                doc.autoTable({
                    startY: 48,
                    margin: { left: 14, right: 14 },
                    head: [['No.', 'Name', 'Email', 'Date Added', 'Status']],
                    body: d.rows.map(function (x, i) { return [i + 1, x.name, x.email, x.added, x.status + (x.note ? '\n' + x.note : '')]; }),
                    styles: { font: 'helvetica', fontSize: 9, cellPadding: 2, valign: 'top', textColor: [17, 24, 39] },
                    headStyles: { fillColor: [243, 244, 246], textColor: [17, 24, 39], fontStyle: 'bold', fontSize: 8 },
                    columnStyles: { 0: { cellWidth: 12, halign: 'center' } },
                    didDrawPage: function () {
                        const H = doc.internal.pageSize.getHeight();
                        doc.setFontSize(8); doc.setTextColor(107, 114, 128);
                        doc.text('Confidential - for official use only (Data Privacy Act of 2012, RA 10173)', 14, H - 8);
                        doc.text('Page ' + doc.internal.getNumberOfPages(), W - 14, H - 8, { align: 'right' });
                    }
                });
                doc.save(name + '.pdf');
                return;
            }

        }

        // Export menu
        (function () {
            const wrap = document.getElementById('exportWrap');
            const btn = document.getElementById('exportBtn');
            const hint = document.getElementById('exportHint');
            function setOpen(open) {
                wrap.classList.toggle('open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    const n = staffExportData().rows.length;
                    hint.textContent = 'Exports ' + n + ' staff member' + (n === 1 ? '' : 's');
                }
            }
            btn.addEventListener('click', function (e) { e.stopPropagation(); setOpen(!wrap.classList.contains('open')); });
            wrap.querySelectorAll('[data-export]').forEach(function (b) {
                b.addEventListener('click', async function () {
                    setOpen(false);
                    btn.disabled = true;
                    try { await staffExport(b.dataset.export); } catch (e) { alert('Export failed. Please try again.'); }
                    btn.disabled = false;
                });
            });
            document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) setOpen(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
        })();

        function openAddStaffModal() {
            document.getElementById('addStaffModal').style.display = 'block';
            document.body.classList.add('modal-open');
            // Diretso sa paghahanap pagkabukas
            setTimeout(function () { document.getElementById('adopterSearchInput').focus(); }, 50);
        }

        function closeAddStaffModal() {
            document.getElementById('addStaffModal').style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        // Isinasara ang modal gamit ang Esc
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.getElementById('addStaffModal').style.display === 'block') {
                closeAddStaffModal();
            }
        });

        window.addEventListener('click', function (event) {
            const modal = document.getElementById('addStaffModal');
            if (event.target === modal) {
                closeAddStaffModal();
            }
        });

        function filterAdopterList() {
            const filter = document.getElementById('adopterSearchInput').value.toLowerCase();
            const rows = document.querySelectorAll('.adopter-row');
            let visible = 0;

            rows.forEach(function (row) {
                const matches = row.dataset.name.indexOf(filter) > -1 || row.dataset.email.indexOf(filter) > -1;
                row.style.display = matches ? 'flex' : 'none';
                if (matches) visible++;
            });

            const noResults = document.getElementById('noAdopterResults');
            if (noResults) {
                noResults.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
            }
        }

        function filterStaffTable() {
            const filter = document.getElementById('staffSearchInput').value.trim().toLowerCase();
            const rows = document.querySelectorAll('.staff-table tbody tr.staff-row');
            const noResults = document.getElementById('noStaffResults');
            const count = document.getElementById('staffCount');
            let visible = 0;

            rows.forEach(function (row) {
                const name = row.querySelector('.staff-name');
                const email = row.querySelector('.staff-email');
                const text = ((name ? name.textContent : '') + ' ' + (email ? email.textContent : '')).toLowerCase();
                const matches = text.indexOf(filter) > -1;
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            });

            if (noResults) {
                noResults.style.display = (visible === 0 && filter !== '') ? 'block' : 'none';
            }
            // Bilang ng lumalabas na resulta habang naghahanap
            if (count) {
                count.textContent = filter === ''
                    ? rows.length + ' staff member' + (rows.length === 1 ? '' : 's')
                    : visible + ' of ' + rows.length + ' shown';
            }
        }
    </script>
    <?php include '../../connection/export_people.php'; ?>
    <?php include '../../connection/chat_bubble.php'; ?>
</body>
</html>
