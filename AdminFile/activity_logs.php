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

// Kunin ang pinakabagong activity logs, kasama ang profile photo ng gumawa ng aksyon
try {
    $logs_stmt = $pdo->query(
        // Hanggang 1000 pinakabago - pero LAGING kasama ang lahat ng Pending/Failed,
        // para makita ang lahat ng binibilang ng "Needs Attention" card
        "SELECT al.log_id, al.actor_name, al.actor_role, al.action, al.details, al.status, al.created_at, u.profile_image
         FROM activity_logs al
         LEFT JOIN users u ON al.actor_id = u.user_id
         WHERE al.status IN ('Pending', 'Failed')
            OR al.log_id IN (SELECT log_id FROM (SELECT log_id FROM activity_logs ORDER BY created_at DESC, log_id DESC LIMIT 1000) recent)
         ORDER BY al.created_at DESC, al.log_id DESC"
    );
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $logs = [];
}

// Token para sa pagbura ng logs, at mensahe pagkatapos magbura
if (empty($_SESSION['al_delete_token'])) {
    $_SESSION['al_delete_token'] = bin2hex(random_bytes(16));
}
$al_flash = $_SESSION['al_flash'] ?? null;
unset($_SESSION['al_flash']);
$isAdmin = ($_SESSION['role'] ?? '') === 'Admin';

// Mga bilang para sa summary cards (buong talaan, hindi lang ang ipinapakita)
function al_count(PDO $pdo, string $where): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE $where")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
$count_total = al_count($pdo, '1');
$count_today = al_count($pdo, 'DATE(created_at) = CURDATE()');
$count_week = al_count($pdo, 'created_at >= CURDATE() - INTERVAL 6 DAY');
$count_attention = al_count($pdo, "status IN ('Pending', 'Failed')");

// Icon at kulay ng bawat aksyon, ayon sa uri nito
function al_action_icon(string $action): array
{
    $a = strtolower($action);
    $map = [
        ['privacy', 'fa-user-shield', 'is-red'],
        ['reject', 'fa-circle-xmark', 'is-red'],
        ['delet', 'fa-trash-can', 'is-red'],
        ['approv', 'fa-circle-check', 'is-green'],
        ['payment', 'fa-wallet', 'is-amber'],
        ['paid', 'fa-wallet', 'is-amber'],
        ['meeting link', 'fa-video', 'is-blue'],
        ['interview', 'fa-calendar-check', 'is-blue'],
        ['schedule', 'fa-calendar-days', 'is-blue'],
        ['city hall', 'fa-clock', 'is-blue'],
        ['certificate', 'fa-certificate', 'is-amber'],
        ['ml ', 'fa-brain', 'is-purple'],
        ['model', 'fa-brain', 'is-purple'],
        ['pet', 'fa-paw', 'is-green'],
        ['medical', 'fa-notes-medical', 'is-red'],
        ['vaccin', 'fa-syringe', 'is-red'],
        ['role', 'fa-user-gear', 'is-purple'],
        ['staff', 'fa-user-gear', 'is-purple'],
        ['login', 'fa-right-to-bracket', 'is-gray'],
        ['logout', 'fa-right-from-bracket', 'is-gray'],
        ['profile', 'fa-id-card', 'is-gray'],
    ];
    foreach ($map as [$needle, $icon, $tone]) {
        if (strpos($a, $needle) !== false) {
            return [$icon, $tone];
        }
    }
    return ['fa-bolt', 'is-gray'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Activity Logs</title>
    <link rel="stylesheet" href="activity_logs.css?v=5">
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
                <a href="reports.php" class="menu-item">
                    <i class="fa-solid fa-chart-bar"></i> Reports
                </a>
                <a href="messages.php" class="menu-item">
                    <i class="fa-solid fa-comment-dots"></i> Messages<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?>
                </a>
                <a href="activity_logs.php" class="menu-item active">
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
                <!-- Summary cards: madilim hanggang maliwanag (kapareho ng Dashboard) -->
                <div class="al-cards">
                    <button type="button" class="al-card al-c1" data-card="all" aria-pressed="false" title="Show all activities">
                        <div>
                            <strong><?php echo number_format($count_total); ?></strong>
                            <span>Total Activities</span>
                            <small>All recorded actions</small>
                        </div>
                        <div class="al-card-icon"><i class="fa-solid fa-list-check"></i></div>
                    </button>
                    <button type="button" class="al-card al-c2" data-card="today" aria-pressed="false" title="Show today's activities">
                        <div>
                            <strong><?php echo number_format($count_today); ?></strong>
                            <span>Today</span>
                            <small><?php echo date('l, M j'); ?></small>
                        </div>
                        <div class="al-card-icon"><i class="fa-solid fa-calendar-day"></i></div>
                    </button>
                    <button type="button" class="al-card al-c3" data-card="week" aria-pressed="false" title="Show this week's activities">
                        <div>
                            <strong><?php echo number_format($count_week); ?></strong>
                            <span>This Week</span>
                            <small>Last 7 days</small>
                        </div>
                        <div class="al-card-icon"><i class="fa-solid fa-chart-line"></i></div>
                    </button>
                    <button type="button" class="al-card al-c4" data-card="attention" aria-pressed="false" title="Show pending and failed actions">
                        <div>
                            <strong><?php echo number_format($count_attention); ?></strong>
                            <span>Needs Attention</span>
                            <small>Pending or failed actions</small>
                        </div>
                        <div class="al-card-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </button>
                </div>

                <div class="card-panel">
                    <!-- Section Title -->
                    <div class="card-header">
                        <div class="al-title">
                            <span class="al-title-icon" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
                            <div>
                                <h2>Activity Logs</h2>
                                <p class="card-subtitle">Track important actions performed across the admin and staff panel</p>
                            </div>
                        </div>
                        <div class="al-head-actions">
                            <span class="al-count" id="logCount"><?php echo count($logs); ?> activities</span>
                            <div class="al-export" id="exportWrap">
                                <button type="button" class="al-btn" id="exportBtn" aria-haspopup="menu" aria-expanded="false">
                                    <i class="fa-solid fa-file-export"></i> Export <i class="fa-solid fa-chevron-down al-caret"></i>
                                </button>
                                <div class="al-export-menu" id="exportMenu" role="menu">
                                    <div class="al-export-hint" id="exportHint"></div>
                                    <button type="button" role="menuitem" data-export="print"><i class="fa-solid fa-print"></i><span><b>Print</b><small>Open the print dialog</small></span></button>
                                    <button type="button" role="menuitem" data-export="pdf"><i class="fa-solid fa-file-pdf"></i><span><b>Download PDF</b><small>A4 document with a table</small></span></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($al_flash): ?>
                        <div class="al-flash <?php echo $al_flash[0] === 'success' ? 'is-success' : 'is-error'; ?>" role="status">
                            <i class="fa-solid <?php echo $al_flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                            <?php echo htmlspecialchars($al_flash[1]); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Lumilitaw kapag may naka-check na log -->
                    <?php if ($isAdmin): ?>
                    <form method="POST" action="process_delete_logs.php" class="al-selbar" id="selBar" hidden
                          data-confirm="Delete the selected activity logs? This cannot be undone."
                          data-confirm-title="Delete Activity Logs?"
                          data-confirm-ok="Yes, delete"
                          data-confirm-icon="fa-trash-can"
                          data-confirm-tone="red">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['al_delete_token']); ?>">
                        <div id="selIds"></div>
                        <span class="al-selbar-text"><i class="fa-solid fa-square-check"></i> <b id="selCount">0</b> selected</span>
                        <button type="button" class="al-btn" id="selClear">Unselect all</button>
                        <button type="button" class="al-btn" id="selPrint"><i class="fa-solid fa-file-export"></i> Export selected</button>
                        <button type="submit" class="al-btn is-danger"><i class="fa-solid fa-trash-can"></i> Delete selected</button>
                    </form>
                    <?php endif; ?>

                    <!-- Search & Filters -->
                    <div class="filters-row">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="searchInput" placeholder="Search by user, action, or details.." oninput="filterLogs()">
                        </div>

                        <div class="filter-group">
                            <label for="filterPeriod">Period</label>
                            <select class="filter-select" id="filterPeriod" onchange="filterLogs()">
                                <option value="">All time</option>
                                <option value="0">Today</option>
                                <option value="6">Last 7 days</option>
                                <option value="29">Last 30 days</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Role</label>
                            <select class="filter-select" id="filterRole" onchange="filterLogs()">
                                <option value="">All</option>
                                <option value="Admin">Admin</option>
                                <option value="Staff">Staff</option>
                                <option value="Adopter">Adopter</option>
                                <option value="System">System</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>Status</label>
                            <select class="filter-select" id="filterStatus" onchange="filterLogs()">
                                <option value="">All</option>
                                <option value="Completed">Completed</option>
                                <option value="Pending">Pending</option>
                                <option value="Failed">Failed</option>
                                <option value="attention">Needs attention (Pending or Failed)</option>
                            </select>
                        </div>

                        <button type="button" class="al-clear" id="clearFilters" onclick="clearFilters()" hidden>
                            <i class="fa-solid fa-xmark"></i> Clear filters
                        </button>
                    </div>

                    <!-- Logs Table -->
                    <div class="table-wrapper">
                        <table class="logs-table">
                            <thead>
                                <tr>
                                    <?php if ($isAdmin): ?><th class="al-check-col"><input type="checkbox" id="checkAll" title="Select all logs shown by the filters" aria-label="Select all logs shown"></th><?php endif; ?>
                                    <th>Date &amp; Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($logs) > 0): ?>
                                    <?php foreach ($logs as $log): ?>
                                        <?php
                                        $statusClass = 'badge-completed';
                                        if ($log['status'] === 'Pending') $statusClass = 'badge-pending';
                                        if ($log['status'] === 'Failed') $statusClass = 'badge-failed';
                                        [$actIcon, $actTone] = al_action_icon((string) $log['action']);
                                        $ts = strtotime($log['created_at']);
                                        $initial = mb_strtoupper(mb_substr((string) $log['actor_name'], 0, 1));
                                        $roleKey = strtolower((string) $log['actor_role']);
                                        ?>
                                        <tr class="log-row"
                                            data-day="<?php echo date('Y-m-d', $ts); ?>"
                                            data-role="<?php echo htmlspecialchars($log['actor_role']); ?>"
                                            data-status="<?php echo htmlspecialchars($log['status']); ?>"
                                            data-id="<?php echo (int) $log['log_id']; ?>"
                                            data-role-label="<?php echo htmlspecialchars($log['actor_role']); ?>">
                                            <?php if ($isAdmin): ?><td class="al-check-col"><input type="checkbox" class="row-check" value="<?php echo (int) $log['log_id']; ?>" aria-label="Select this log"></td><?php endif; ?>
                                            <td class="date-cell">
                                                <?php echo htmlspecialchars(date('M j, Y', $ts)); ?>
                                                <span class="log-time"><?php echo htmlspecialchars(date('g:i A', $ts)); ?></span>
                                            </td>
                                            <td>
                                                <div class="al-user">
                                                    <div class="user-photo">
                                                        <?php if (!empty($log['profile_image'])): ?>
                                                            <img src="../uploads/<?php echo htmlspecialchars($log['profile_image']); ?>" alt="<?php echo htmlspecialchars($log['actor_name']); ?>" title="View photo" onclick="openPhotoLightbox('../uploads/<?php echo htmlspecialchars($log['profile_image']); ?>', '<?php echo htmlspecialchars(addslashes($log['actor_name'])); ?>', '<?php echo htmlspecialchars(addslashes($log['actor_role'])); ?>')">
                                                        <?php else: ?>
                                                            <span><?php echo htmlspecialchars($initial); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="al-user-text">
                                                        <strong class="log-user"><?php echo htmlspecialchars($log['actor_name']); ?></strong>
                                                        <span class="al-role role-<?php echo htmlspecialchars($roleKey); ?>"><?php echo htmlspecialchars($log['actor_role']); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="al-action">
                                                    <span class="al-action-icon <?php echo $actTone; ?>" aria-hidden="true"><i class="fa-solid <?php echo $actIcon; ?>"></i></span>
                                                    <span class="log-action"><?php echo htmlspecialchars($log['action']); ?></span>
                                                </div>
                                            </td>
                                            <td class="details-cell log-details"><?php echo htmlspecialchars($log['details'] !== '' && $log['details'] !== null ? $log['details'] : '—'); ?></td>
                                            <td><span class="badge <?php echo $statusClass; ?>"><span class="badge-dot"></span><?php echo htmlspecialchars($log['status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?php echo $isAdmin ? 6 : 5; ?>" class="al-empty">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                            <p>No activity recorded yet</p>
                                            <span>Actions by admin and staff will appear here.</span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div id="noResults" class="al-empty" style="display: none;">
                            <i class="fa-solid fa-filter-circle-xmark"></i>
                            <p>No logs match your filters</p>
                            <span>Try a different search, period, role, or status.</span>
                        </div>
                        <div class="table-empty-space"></div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Photo Lightbox -->
    <div id="photoLightbox" class="photo-lightbox" onclick="closePhotoLightbox(event)">
        <button type="button" class="lightbox-close" onclick="closePhotoLightbox(event)">&times;</button>
        <div class="lightbox-content">
            <img id="lightboxImg" src="" alt="">
            <p id="lightboxName"></p>
            <p id="lightboxRole"></p>
        </div>
    </div>

    <script>
        function openPhotoLightbox(src, name, role) {
            document.getElementById('lightboxImg').src = src;
            document.getElementById('lightboxName').textContent = name;
            document.getElementById('lightboxRole').textContent = role;
            document.getElementById('photoLightbox').classList.add('show');
        }

        function closePhotoLightbox(event) {
            if (event.target.id === 'photoLightbox' || event.target.classList.contains('lightbox-close')) {
                document.getElementById('photoLightbox').classList.remove('show');
            }
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.getElementById('photoLightbox').classList.remove('show');
            }
        });

        // Petsa ngayon (lokal) bilang YYYY-MM-DD, para sa filter na "Period"
        function dayKey(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        // Search (user, action, details) + Period + Role + Status. Itinatago ang
        // row gamit ang style.display - ito rin ang binabasa ng pagination.
        function filterLogs() {
            const searchText = document.getElementById('searchInput').value.trim().toLowerCase();
            const period = document.getElementById('filterPeriod').value;
            const roleFilter = document.getElementById('filterRole').value;
            const statusFilter = document.getElementById('filterStatus').value;

            let fromDay = '';
            if (period !== '') {
                const d = new Date();
                d.setDate(d.getDate() - parseInt(period, 10));
                fromDay = dayKey(d);
            }

            const rows = document.querySelectorAll('.logs-table tbody tr.log-row');
            const noResults = document.getElementById('noResults');
            let visible = 0;

            rows.forEach(function (row) {
                const text = [
                    row.querySelector('.log-user'),
                    row.querySelector('.log-action'),
                    row.querySelector('.log-details')
                ].map(function (el) { return el ? el.textContent : ''; }).join(' ').toLowerCase();

                const statusOk = statusFilter === ''
                    || (statusFilter === 'attention'
                        ? (row.dataset.status === 'Pending' || row.dataset.status === 'Failed')
                        : row.dataset.status === statusFilter);
                const ok = (searchText === '' || text.indexOf(searchText) > -1)
                    && (fromDay === '' || row.dataset.day >= fromDay)
                    && (roleFilter === '' || row.dataset.role === roleFilter)
                    && statusOk;

                row.style.display = ok ? '' : 'none';
                if (ok) visible++;
            });

            const hasFilter = searchText !== '' || period !== '' || roleFilter !== '' || statusFilter !== '';
            if (noResults) {
                noResults.style.display = (visible === 0 && hasFilter && rows.length > 0) ? 'block' : 'none';
            }
            document.getElementById('clearFilters').hidden = !hasFilter;
            document.getElementById('logCount').textContent = hasFilter
                ? visible + ' of ' + rows.length + ' shown'
                : rows.length + ' activities';
            syncCards(searchText, period, roleFilter, statusFilter);
            if (typeof updateSelection === 'function') updateSelection(true);
        }

        // ---- Mga summary card: pindutin para makita ang mga activity na iyon ----
        const CARD_FILTERS = {
            all: { period: '', status: '' },
            today: { period: '0', status: '' },
            week: { period: '6', status: '' },
            attention: { period: '', status: 'attention' }
        };

        // Naka-highlight ang card na tugma sa kasalukuyang filter
        function syncCards(searchText, period, role, status) {
            document.querySelectorAll('.al-card[data-card]').forEach(function (card) {
                const f = CARD_FILTERS[card.dataset.card];
                const on = card.dataset.card !== 'all'
                    && searchText === '' && role === '' && f.period === period && f.status === status;
                card.classList.toggle('is-active', on);
                card.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        document.querySelectorAll('.al-card[data-card]').forEach(function (card) {
            card.addEventListener('click', function () {
                const f = CARD_FILTERS[card.dataset.card];
                // Pindot ulit sa naka-highlight na card = ipakita ulit ang lahat
                const turnOff = card.classList.contains('is-active');
                document.getElementById('searchInput').value = '';
                document.getElementById('filterRole').value = '';
                document.getElementById('filterPeriod').value = turnOff ? '' : f.period;
                document.getElementById('filterStatus').value = turnOff ? '' : f.status;
                filterLogs();
                const panel = document.querySelector('.card-panel');
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (panel) panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            });
        });

        // ---- Pagpili ng mga log (para sa Delete at Print) ----
        function logRows() { return Array.prototype.slice.call(document.querySelectorAll('.logs-table tbody tr.log-row')); }
        function shownRows() { return logRows().filter(function (r) { return r.style.display !== 'none'; }); }
        function checkedRows() { return logRows().filter(function (r) { const c = r.querySelector('.row-check'); return c && c.checked; }); }

        // dropHidden: kapag nag-filter, inaalis ang check sa mga row na hindi na nakikita
        function updateSelection(dropHidden) {
            const bar = document.getElementById('selBar');
            if (!bar) return;
            if (dropHidden) {
                logRows().forEach(function (r) {
                    const c = r.querySelector('.row-check');
                    if (c && c.checked && r.style.display === 'none') c.checked = false;
                });
            }
            const picked = checkedRows();
            logRows().forEach(function (r) {
                const c = r.querySelector('.row-check');
                r.classList.toggle('is-selected', !!(c && c.checked));
            });
            bar.hidden = picked.length === 0;
            document.getElementById('selCount').textContent = picked.length;
            bar.dataset.confirm = 'Delete ' + picked.length + ' selected activity log' + (picked.length === 1 ? '' : 's') +
                '? This cannot be undone. The deletion itself will be recorded in the activity logs.';
            // Mga ID na ipapadala sa server
            document.getElementById('selIds').innerHTML = picked.map(function (r) {
                return '<input type="hidden" name="ids[]" value="' + parseInt(r.dataset.id, 10) + '">';
            }).join('');
            const all = document.getElementById('checkAll');
            const shown = shownRows();
            if (all) {
                const n = shown.filter(function (r) { return r.querySelector('.row-check').checked; }).length;
                all.checked = shown.length > 0 && n === shown.length;
                all.indeterminate = n > 0 && n < shown.length;
            }
        }

        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('row-check')) updateSelection(false);
            if (e.target.id === 'checkAll') {
                // Lahat ng log na tugma sa filter (lahat ng page), hindi lang ang nakikita sa page na ito
                shownRows().forEach(function (r) { r.querySelector('.row-check').checked = e.target.checked; });
                updateSelection(false);
            }
        });
        const selClear = document.getElementById('selClear');
        if (selClear) selClear.addEventListener('click', function () {
            logRows().forEach(function (r) { const c = r.querySelector('.row-check'); if (c) c.checked = false; });
            updateSelection(false);
        });

        // ---- Export: Print at PDF (may "Generated by" at "From") ----
        // Kung may naka-check, iyon lang; kung wala, lahat ng tugma sa filter.
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
            });
        }
        function filterSummary() {
            const parts = [];
            const sel = function (id) { const s = document.getElementById(id); return s.value === '' ? '' : s.options[s.selectedIndex].text; };
            if (sel('filterPeriod')) parts.push('Period: ' + sel('filterPeriod'));
            if (sel('filterRole')) parts.push('Role: ' + sel('filterRole'));
            if (sel('filterStatus')) parts.push('Status: ' + sel('filterStatus'));
            const q = document.getElementById('searchInput').value.trim();
            if (q) parts.push('Search: "' + q + '"');
            return parts.length ? parts.join(' · ') : 'All activities';
        }

        // Ang mga log na ie-export, bilang simpleng data
        function exportData() {
            const picked = checkedRows();
            const rows = picked.length ? picked : shownRows();
            const now = new Date();
            return {
                scope: picked.length ? 'Selected entries' : filterSummary(),
                stamp: now.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) + ' ' +
                    now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }),
                fileStamp: now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0'),
                by: <?php echo json_encode((string) $username); ?>,
                rows: rows.map(function (r) {
                    const text = function (sel) { const el = r.querySelector(sel); return el ? el.textContent.trim() : ''; };
                    const date = r.querySelector('.date-cell');
                    let day = '';
                    if (date) {
                        const copy = date.cloneNode(true);       // petsa lang, walang oras
                        copy.querySelectorAll('.log-time').forEach(function (t) { t.remove(); });
                        day = copy.textContent.trim();
                    }
                    return {
                        day: day, time: text('.log-time'), user: text('.log-user'), role: r.dataset.roleLabel || '',
                        action: text('.log-action'), details: text('.log-details'), status: r.dataset.status || ''
                    };
                })
            };
        }

        const REPORT_CSS =
            '*{box-sizing:border-box}.rp{font-family:"Segoe UI",Arial,sans-serif;color:#111827;font-size:10pt;background:#fff}' +
            '.rp .lh{display:flex;align-items:center;gap:12px;padding-bottom:10px;border-bottom:2px solid #1b4332}.rp .lh img{width:52px;height:52px;border-radius:50%}' +
            '.rp .n{font-size:15pt;font-weight:800;color:#1b4332}.rp .s{font-size:9pt;color:#4b5563}.rp .r{margin-left:auto;text-align:right;font-size:8.5pt;color:#6b7280}' +
            '.rp h1{margin:14px 0 2px;text-align:center;font-size:13pt;letter-spacing:1.5px;text-transform:uppercase}.rp .sub{text-align:center;font-size:9.5pt;color:#4b5563;margin-bottom:12px}' +
            '.rp table{width:100%;border-collapse:collapse;font-size:9pt}.rp thead{display:table-header-group}' +
            '.rp th{background:#f3f4f6;border-top:1px solid #111;border-bottom:1px solid #111;padding:5px 6px;text-align:left;font-size:7.5pt;text-transform:uppercase;letter-spacing:.5px}' +
            '.rp td{padding:5px 6px;border-bottom:1px solid #e5e7eb;vertical-align:top}.rp tr{break-inside:avoid}.rp small{color:#6b7280}.rp .c{text-align:center}.rp .nw{white-space:nowrap}' +
            '.rp .note{margin-top:12px;padding:6px 8px;border:1px solid #d1d5db;font-size:8pt;color:#4b5563}';

        function reportHtml(data) {
            const body = data.rows.map(function (x, i) {
                return '<tr><td class="c">' + (i + 1) + '</td><td class="nw">' + esc(x.day) + '<br><small>' + esc(x.time) + '</small></td>' +
                    '<td><b>' + esc(x.user) + '</b><br><small>' + esc(x.role) + '</small></td>' +
                    '<td>' + esc(x.action) + '</td><td>' + esc(x.details) + '</td><td class="nw">' + esc(x.status) + '</td></tr>';
            }).join('');
            return '<div class="rp">' +
                '<div class="lh"><img src="/Caps2/Images/caloocan_logo.jpg" alt=""><div><div class="n">SMART PET</div><div class="s">Pet Adoption Management System</div></div>' +
                '<div class="r">Generated: ' + esc(data.stamp) + '<br>Generated by: ' + esc(data.by) + '<br>From: ' + esc(data.from || '') + '</div></div>' +
                '<h1>Activity Logs Report</h1><div class="sub">' + esc(data.scope) + ' &middot; ' + data.rows.length + ' entr' + (data.rows.length === 1 ? 'y' : 'ies') + '</div>' +
                '<table><thead><tr><th class="c">No.</th><th>Date &amp; Time</th><th>User</th><th>Action</th><th>Details</th><th>Status</th></tr></thead><tbody>' + body + '</tbody></table>' +
                '<div class="note"><b>Confidential:</b> This report may contain personal information and is intended for official use only (Data Privacy Act of 2012, RA 10173).</div>' +
                '</div>';
        }

        // Print sa ibabaw mismo ng page (nakatagong iframe) - walang bagong tab,
        // kaya ang page na ito ang nasa likod ng print dialog
        function exportPrint(data) {
            spPrintHtml('<!doctype html><html><head><meta charset="utf-8"><title>Activity Logs Report</title><style>@page{size:A4 landscape;margin:12mm}body{margin:0}' +
                REPORT_CSS + '</style></head><body>' + reportHtml(data) + '</body></html>');
        }
        function spPrintHtml(html) {
            const old = document.getElementById('spPrintFrame');
            if (old) old.remove();
            const f = document.createElement('iframe');
            f.id = 'spPrintFrame';
            f.setAttribute('aria-hidden', 'true');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
            document.body.appendChild(f);
            const doc = f.contentWindow.document;
            doc.open(); doc.write(html); doc.close();
            const go = function () { f.contentWindow.focus(); f.contentWindow.print(); };
            const img = doc.querySelector('img');
            if (img && !img.complete) { img.onload = img.onerror = function () { setTimeout(go, 100); }; } else { setTimeout(go, 200); }
        }

        function downloadDataUrl(url, name) {
            const a = document.createElement('a');
            a.href = url;
            a.download = name;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }

        // Logo bilang data URL (para maisama sa PDF)
        function logoDataUrl() {
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

        // PDF: totoong text at table (nasesearch at nakokopya), A4 landscape
        async function exportPdf(data) {
            const jsPDF = window.jspdf && window.jspdf.jsPDF;
            if (!jsPDF) { alert('The PDF tool could not be loaded. Please check your internet connection.'); return; }
            const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
            const W = doc.internal.pageSize.getWidth();
            const logo = await logoDataUrl();
            if (logo) doc.addImage(logo, 'JPEG', 12, 9, 15, 15);
            doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.setTextColor(27, 67, 50);
            doc.text('SMART PET', 30, 15);
            doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(75, 85, 99);
            doc.text('Pet Adoption Management System', 30, 20);
            doc.setFontSize(8.5); doc.setTextColor(107, 114, 128);
            doc.text('Generated: ' + data.stamp, W - 12, 14, { align: 'right' });
            doc.text('Generated by: ' + data.by, W - 12, 19, { align: 'right' });
            doc.text('From: ' + (data.from || ''), W - 12, 24, { align: 'right' });
            doc.setDrawColor(27, 67, 50); doc.setLineWidth(0.6); doc.line(12, 28, W - 12, 28);
            doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(17, 24, 39);
            doc.text('ACTIVITY LOGS REPORT', W / 2, 36, { align: 'center' });
            doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5); doc.setTextColor(75, 85, 99);
            doc.text(data.scope.replace(/·/g, '-') + '  -  ' + data.rows.length + ' entr' + (data.rows.length === 1 ? 'y' : 'ies'), W / 2, 42, { align: 'center' });
            doc.autoTable({
                startY: 47,
                margin: { left: 12, right: 12 },
                head: [['No.', 'Date & Time', 'User', 'Action', 'Details', 'Status']],
                body: data.rows.map(function (x, i) {
                    return [i + 1, x.day + '\n' + x.time, x.user + '\n' + x.role, x.action, x.details, x.status];
                }),
                styles: { font: 'helvetica', fontSize: 8, cellPadding: 1.8, valign: 'top', textColor: [17, 24, 39], lineColor: [229, 231, 235], lineWidth: { bottom: 0.2 } },
                headStyles: { fillColor: [243, 244, 246], textColor: [17, 24, 39], fontStyle: 'bold', fontSize: 7.5 },
                columnStyles: { 0: { cellWidth: 11, halign: 'center' }, 1: { cellWidth: 30 }, 2: { cellWidth: 30 }, 3: { cellWidth: 62 }, 5: { cellWidth: 22 } },
                didDrawPage: function () {
                    const H = doc.internal.pageSize.getHeight();
                    doc.setFontSize(8); doc.setTextColor(107, 114, 128);
                    doc.text('Confidential - for official use only (Data Privacy Act of 2012, RA 10173)', 12, H - 7);
                    doc.text('Page ' + doc.internal.getNumberOfPages(), W - 12, H - 7, { align: 'right' });
                }
            });
            doc.save('activity-logs-' + data.fileStamp + '.pdf');
        }

        async function runExport(kind) {
            const data = exportData();
            if (!data.rows.length) { alert('There are no activity logs to export.'); return; }
            // Sino ang nag-print at kanino/saan galing ang papel
            const people = await spAskExportPeople(data.by);
            if (!people) return;
            data.by = people.by;
            data.from = people.from;
            const btn = document.getElementById('exportBtn');
            btn.disabled = true;
            btn.classList.add('is-busy');
            try {
                if (kind === 'print') exportPrint(data);
                else if (kind === 'pdf') await exportPdf(data);
                else return;
            } catch (e) {
                alert('Export failed. Please try again.');
            } finally {
                btn.disabled = false;
                btn.classList.remove('is-busy');
            }
        }

        // Export menu
        (function () {
            const wrap = document.getElementById('exportWrap');
            const btn = document.getElementById('exportBtn');
            const menu = document.getElementById('exportMenu');
            const hint = document.getElementById('exportHint');
            function setOpen(open) {
                wrap.classList.toggle('open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    const n = checkedRows().length;
                    hint.textContent = n ? 'Exports the ' + n + ' selected log' + (n === 1 ? '' : 's') : 'Exports the ' + shownRows().length + ' logs shown';
                }
            }
            btn.addEventListener('click', function (e) { e.stopPropagation(); setOpen(!wrap.classList.contains('open')); });
            menu.querySelectorAll('[data-export]').forEach(function (b) {
                b.addEventListener('click', function () { setOpen(false); runExport(b.dataset.export); });
            });
            document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) setOpen(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
            // "Export selected" sa selection bar: bubuksan ang parehong menu
            const selExport = document.getElementById('selPrint');
            if (selExport) selExport.addEventListener('click', function (e) {
                e.stopPropagation();
                wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setOpen(true);
            });
        })();

        document.addEventListener('DOMContentLoaded', function () { filterLogs(); });

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('filterPeriod').value = '';
            document.getElementById('filterRole').value = '';
            document.getElementById('filterStatus').value = '';
            filterLogs();
        }
    </script>
    <?php include '../connection/export_people.php'; ?>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
