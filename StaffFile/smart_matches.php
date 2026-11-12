<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/match_scoring.php';

// Check if staff user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user profile info
$user_stmt = $pdo->prepare("SELECT username, profile_image, role FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$username = $current_user['username'] ?? 'Staff Member';
$profile_image = $current_user['profile_image'] ?? '';
$user_initials = strtoupper(substr($username, 0, 2));

// Helper to safely count rows from an optional table
function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

$pending_applications = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status IN ('Pending', 'Under Review')");
$interviews_today = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE DATE(preferred_interview_date) = CURDATE()");
$available_pets = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_inventory WHERE status = 'Available'");

// Load pending/under-review applications together with the pet + adopter preference
// data needed by computeMatchScore() (same scoring engine used sa applications.php).
$applications = [];
try {
    $sql = "SELECT aa.application_id, aa.full_name AS adopter_name, aa.application_status AS status,
                   aa.match_score AS saved_match_score, aa.match_breakdown AS saved_match_breakdown,
                   u.profile_image AS adopter_photo,
                   p.name AS pet_name, p.kind AS pet_type,
                   ap.pref_kind, ap.pref_breed, ap.pref_temperament, ap.pref_energy_level, ap.pref_size, ap.pref_age_group, ap.pref_gender, ap.pref_health_condition,
                   ap.living_space, ap.activity_level, ap.maintenance_level, ap.allergies,
                   p.kind, p.breed, p.energy_level, p.size, p.age_group, p.gender, p.health_condition, p.temperament, p.shedding_level, p.description
            FROM adoption_applications aa
            JOIN pet_inventory p ON aa.pet_id = p.pet_id
            LEFT JOIN users u ON u.user_id = aa.adopter_id
            LEFT JOIN adopter_preferences ap ON ap.user_id = aa.adopter_id
            WHERE aa.application_status IN ('Pending', 'Under Review')
            ORDER BY aa.created_at DESC";
    $stmt = $pdo->query($sql);
    if ($stmt) $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $applications = [];
}

foreach ($applications as &$app) {
    $app['score'] = sp_application_match($pdo, $app)['score']; // naka-freeze noong nag-submit
}
unset($app);

// Rank best matches first (unscored applications sink to the bottom) for the recommendation table
usort($applications, function ($a, $b) {
    return ($b['score'] ?? -1) <=> ($a['score'] ?? -1);
});
$recommendations = array_slice($applications, 0, 6);

// Match Overview: bucket every scored application into Excellent / Good / Fair
$scored = array_values(array_filter(array_column($applications, 'score'), fn($s) => $s !== null));
$excellent_count = count(array_filter($scored, fn($s) => $s >= 85));
$good_count = count(array_filter($scored, fn($s) => $s >= 65 && $s < 85));
$fair_count = count(array_filter($scored, fn($s) => $s < 65));
$scored_total = count($scored) ?: 1; // avoid div-by-zero for the bar widths

$high_matches = count(array_filter($scored, fn($s) => $s >= 80));
$avg_score = $scored ? (int) round(array_sum($scored) / count($scored)) : 0;

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Smart Matches - Staff</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        /* ===== Smart Matches (kulay ng staff) ===== */
        .sm-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; margin-top: 20px; align-items: start; }
        @media (max-width: 1100px) { .sm-grid { grid-template-columns: 1fr; } }
        .sm-panel { background: #fff; border-radius: 16px; padding: 20px; border: 1px solid #eef0f3; box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05); min-width: 0; scroll-margin-top: 16px; }
        .sm-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .sm-icon { width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #eef2fb; color: #1e3a8a; }
        .sm-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .sm-head h3 { margin: 0; font-size: 17px; font-weight: 800; color: #0f1b3d; }
        .sm-sub { font-size: 12.5px; color: #6b7280; }

        /* Table */
        .sm-table-wrap { overflow-x: auto; border: 1px solid #eef0f3; border-radius: 12px; }
        .sm-table { width: 100%; border-collapse: collapse; min-width: 640px; text-align: left; }
        .sm-table th { background: #f8f9fb; color: #6b7280; font-size: 11.5px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; padding: 12px 14px; border-bottom: 1px solid #eef0f3; white-space: nowrap; }
        .sm-table td { padding: 12px 14px; border-bottom: 1px solid #f2f4f6; vertical-align: middle; font-size: 14px; }
        .sm-table tbody tr:last-child td { border-bottom: none; }
        .sm-table tbody tr { transition: background-color .15s ease; }
        .sm-table tbody tr:hover { background: #f7f9fe; }
        .sm-rank { width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 12.5px; font-weight: 800; background: #f1f4f8; color: #6b7280; }
        .sm-rank.top { background: linear-gradient(135deg, #1e40af, #3b6fe0); color: #fff; box-shadow: 0 3px 8px rgba(30, 64, 175, 0.25); }
        .sm-person { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .sm-person strong, .sm-pet strong { color: #0f1b3d; }
        .sm-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12); }
        .sm-avatar-initial { display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; background: linear-gradient(135deg, #1e40af, #3b6fe0); }
        .sm-pet { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
        .sm-kind { font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
        .sm-kind.is-dog { background: #fff4e5; color: #b45309; }
        .sm-kind.is-cat { background: #f3edff; color: #6d28d9; }
        .sm-match { display: flex; flex-direction: column; gap: 5px; width: 96px; }
        .sm-match strong { font-size: 14px; }
        .sm-bar { display: block; height: 6px; border-radius: 999px; background: #e8ecf3; overflow: hidden; }
        .sm-bar > span { display: block; height: 100%; border-radius: 999px; transition: width .6s cubic-bezier(.22, 1, .36, 1); }
        .match-high strong { color: #15803d; } .match-high .sm-bar > span, .match-high .sm-dot { background: linear-gradient(90deg, #22c55e, #15803d); }
        .match-mid strong { color: #b45309; } .match-mid .sm-bar > span, .match-mid .sm-dot { background: linear-gradient(90deg, #fbbf24, #d97706); }
        .match-low strong { color: #b42318; } .match-low .sm-bar > span, .match-low .sm-dot { background: linear-gradient(90deg, #f87171, #dc2626); }
        .sm-none { color: #9aa3ad; font-weight: 700; font-size: 13px; }
        .sm-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 9px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .sm-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .sm-status.is-pending { background: #fef3c7; color: #92400e; }
        .sm-status.is-review { background: #eef2fb; color: #1e3a8a; }
        .sm-review {
            display: inline-flex; align-items: center; gap: 4px; height: 34px; padding: 0 12px 0 14px; border-radius: 9px;
            background: linear-gradient(135deg, #2a62de, #1e3a8a); color: #fff; font-size: 13px; font-weight: 700; text-decoration: none;
            box-shadow: 0 4px 10px rgba(30, 58, 138, 0.22); transition: filter .2s ease;
        }
        .sm-review:hover { filter: brightness(1.08); color: #fff; }
        .sm-review svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; transition: transform .2s ease; }
        .sm-review:hover svg { transform: translateX(2px); }
        .sm-empty { text-align: center; padding: 40px 10px; color: #9aa3ad; }

        /* Bilog na chart */
        .sm-ring {
            --pct: 0;
            width: 168px; height: 168px; margin: 4px auto 20px; border-radius: 50%;
            background: conic-gradient(#2f5bc4 calc(var(--pct) * 1%), #e8ecf3 0);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 10px 24px rgba(30, 58, 138, 0.12);
        }
        .sm-ring-inner { width: 132px; height: 132px; border-radius: 50%; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .sm-ring-inner strong { font-size: 34px; font-weight: 900; color: #0f1b3d; line-height: 1; }
        .sm-ring-inner span { margin-top: 6px; font-size: 12px; font-weight: 700; color: #6b7280; }

        .sm-bars { display: flex; flex-direction: column; gap: 14px; }
        .sm-bar-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; font-size: 13.5px; font-weight: 700; color: #0f1b3d; }
        .sm-bar-top > span { display: inline-flex; align-items: center; gap: 8px; }
        .sm-bar-top em { font-style: normal; font-weight: 600; font-size: 12px; color: #8a94a6; }
        .sm-bar-top strong { font-size: 15px; }
        .sm-dot { width: 10px; height: 10px; border-radius: 3px; }
        .sm-bar-row .sm-bar { height: 8px; }
    </style>
</head>
<body class="staff-dashboard-root">
<div class="app-container">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <div class="top-header">
            <div class="search-top"></div>
            <a href="/Caps2/StaffFile/edit_profile.php" class="profile-section" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>" 
                         alt="Profile" 
                         style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <div class="avatar"><?php echo $user_initials; ?></div>
                <?php endif; ?>
                <div class="profile-name" style="font-weight: 700; color: #102a2a;">
                    <?php echo htmlspecialchars($username); ?>
                </div>
            </a>
        </div>

        <div class="dashboard-content page-content" style="padding: 0 12px 24px 12px;">
            <header class="sp-page-head">
                <span class="sp-page-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path class="ic-fill" d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>
                        <path d="M9.2 11.2l1.9 1.9 3.7-3.8"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Smart Matches</h1>
                    <p>Adopters ranked by how well they fit the pet they applied for</p>
                </div>
                <div class="sp-page-actions">
                    <a href="applications.php?status=pending" class="sp-btn-outline">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                        View All Pending Matches
                    </a>
                </div>
            </header>

            <!-- Mga stat card: madilim hanggang maliwanag -->
            <div class="cards-grid sp-cards">
                <a class="stat-card sp-card sp-c1" href="#recTable" title="See the top matches">
                    <div class="stat-info">
                        <h1><?php echo str_pad($high_matches, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>High Matches<br><small>80% and above</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Bituin -->
                        <svg viewBox="0 0 24 24"><path class="ic-fill" d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c2" href="applications.php?status=pending" title="Open pending applications">
                    <div class="stat-info">
                        <h1><?php echo str_pad($pending_applications, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Pending Matches<br><small>Pending or under review</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Hourglass -->
                        <svg viewBox="0 0 24 24">
                            <path d="M6.5 3h11M6.5 21h11"/>
                            <path d="M7.5 3v3.5a4.5 4.5 0 0 0 9 0V3M7.5 21v-3.5a4.5 4.5 0 0 1 9 0V21"/>
                            <path class="ic-fill" d="M9.5 18.5a2.5 2.5 0 0 1 5 0V20h-5z"/>
                        </svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c3" href="/Caps2/StaffFile/staff_availability.php#requests" title="Open today's interviews">
                    <div class="stat-info">
                        <h1><?php echo str_pad($interviews_today, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Interviews Today</p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Calendar na may chat bubble -->
                        <svg viewBox="0 0 24 24">
                            <path d="M11 20H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3"/>
                            <path d="M4 9.5h16M8.5 3v4M15.5 3v4"/>
                            <path class="ic-fill" d="M15.5 13h4a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-2l-2.5 2v-2a1.5 1.5 0 0 1-1-1.4v-3.1a1.5 1.5 0 0 1 1.5-1.5z"/>
                        </svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c4" href="/Caps2/StaffFile/pet_inventory.php" title="Open Pet Inventory">
                    <div class="stat-info">
                        <h1><?php echo str_pad($available_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Available Pets</p>
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
            </div>

            <div class="sm-grid">
                <!-- ===== Recommendation Engine ===== -->
                <section class="sm-panel" id="recTable">
                    <div class="sm-head">
                        <span class="sm-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/><circle cx="12" cy="12" r="3.2"/></svg>
                        </span>
                        <div>
                            <h3>Recommendation Engine</h3>
                            <span class="sm-sub">Top <?php echo count($recommendations); ?> pet/adopter matches, best first</span>
                        </div>
                    </div>

                    <div class="sm-table-wrap">
                        <table class="sm-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Adopter</th>
                                    <th>Pet</th>
                                    <th>Match</th>
                                    <th>Status</th>
                                    <th style="text-align:right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recommendations)): ?>
                                    <tr>
                                        <td colspan="6" class="sm-empty">No pending matches right now.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($recommendations as $i => $r):
                                    $adopter = htmlentities($r['adopter_name'] ?? 'N/A');
                                    $pet = htmlentities($r['pet_name'] ?? 'N/A');
                                    $score = $r['score'];
                                    $status = $r['status'] ?? 'Pending';
                                    $tone = $score === null ? 'none' : ($score >= 85 ? 'high' : ($score >= 65 ? 'mid' : 'low'));
                                    $isCat = ($r['pet_type'] ?? '') === 'Cat';
                                ?>
                                    <tr>
                                        <td><span class="sm-rank<?php echo $i < 3 ? ' top' : ''; ?>"><?php echo $i + 1; ?></span></td>
                                        <td>
                                            <div class="sm-person">
                                                <?php if (!empty($r['adopter_photo'])): ?>
                                                    <img class="sm-avatar" src="/Caps2/uploads/<?php echo htmlspecialchars($r['adopter_photo']); ?>" alt="">
                                                <?php else: ?>
                                                    <span class="sm-avatar sm-avatar-initial" aria-hidden="true"><?php echo strtoupper(substr($adopter, 0, 1)); ?></span>
                                                <?php endif; ?>
                                                <strong><?php echo $adopter; ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="sm-pet">
                                                <strong><?php echo $pet; ?></strong>
                                                <?php if (!empty($r['pet_type'])): ?>
                                                    <span class="sm-kind <?php echo $isCat ? 'is-cat' : 'is-dog'; ?>"><?php echo htmlentities($r['pet_type']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($score === null): ?>
                                                <span class="sm-none">N/A</span>
                                            <?php else: ?>
                                                <div class="sm-match match-<?php echo $tone; ?>">
                                                    <strong><?php echo (int) $score; ?>%</strong>
                                                    <span class="sm-bar"><span style="width: <?php echo max(0, min(100, (int) $score)); ?>%;"></span></span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="sm-status <?php echo strtolower($status) === 'under review' ? 'is-review' : 'is-pending'; ?>"><?php echo htmlentities($status); ?></span></td>
                                        <td style="text-align:right">
                                            <a class="sm-review" href="review_application.php?application_id=<?php echo (int) $r['application_id']; ?>">
                                                Review <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- ===== Match Overview ===== -->
                <aside class="sm-panel">
                    <div class="sm-head">
                        <span class="sm-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                        </span>
                        <div>
                            <h3>Match Overview</h3>
                            <span class="sm-sub"><?php echo count($scored); ?> scored application<?php echo count($scored) === 1 ? '' : 's'; ?></span>
                        </div>
                    </div>

                    <!-- Bilog na chart ng karaniwang match -->
                    <div class="sm-ring" style="--pct: <?php echo (int) $avg_score; ?>;">
                        <div class="sm-ring-inner">
                            <strong><?php echo (int) $avg_score; ?>%</strong>
                            <span>Average Match</span>
                        </div>
                    </div>

                    <div class="sm-bars">
                        <?php foreach ([
                            ['Excellent', '85%+', $excellent_count, 'high'],
                            ['Good', '65–84%', $good_count, 'mid'],
                            ['Fair', 'below 65%', $fair_count, 'low'],
                        ] as [$label, $range, $count, $tone]): ?>
                            <div class="sm-bar-row match-<?php echo $tone; ?>">
                                <div class="sm-bar-top">
                                    <span><span class="sm-dot" aria-hidden="true"></span><?php echo $label; ?> <em><?php echo $range; ?></em></span>
                                    <strong><?php echo (int) $count; ?></strong>
                                </div>
                                <span class="sm-bar"><span style="width: <?php echo round($count / $scored_total * 100); ?>%;"></span></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>
        </div>

    </main>
</div>

    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>