<?php
/**
 * Global search para sa Admin at Adopter (ang Staff ay may sariling
 * StaffFile/search_data.php). JSON ang sagot, naka-grupo ang mga resulta.
 *  - Admin: lahat ng pet, application, adopter, staff, at page ng Admin
 *  - Adopter: Available na pet, sariling application at inampon, at page ng Adopter
 */
session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/app_url.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$role = $_SESSION['role'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !in_array($role, ['Admin', 'Adopter'], true)) {
    http_response_code(401);
    echo json_encode(['groups' => []]);
    exit();
}

$q = trim(preg_replace('/\s+/u', ' ', (string) ($_GET['q'] ?? '')));
$q = mb_substr($q, 0, 60);
if ($q === '') {
    echo json_encode(['groups' => []]);
    exit();
}

$idOnly = preg_match('/^#?(\d+)$/', $q, $m) ? (int) $m[1] : 0;
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$base = sp_base_url();

function gs_rows(PDO $pdo, string $sql, array $params): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function gs_pages(array $pages, string $q, string $base): array
{
    $needle = mb_strtolower($q);
    $hits = array_values(array_filter($pages, function ($p) use ($needle) {
        return strpos(mb_strtolower($p[0] . ' ' . $p[2]), $needle) !== false;
    }));
    return array_map(function ($p) use ($base) {
        return ['icon' => '📌', 'title' => $p[0], 'detail' => 'Open page', 'url' => $base . '/' . $p[1]];
    }, array_slice($hits, 0, 4));
}

$groups = [];

if ($role === 'Admin') {
    $pets = gs_rows($pdo,
        "SELECT pet_id, name, kind, breed, status FROM pet_inventory
         WHERE pet_id = ? OR name LIKE ? OR breed LIKE ? OR kind LIKE ?
         ORDER BY (pet_id = ?) DESC, name ASC LIMIT 6",
        [$idOnly, $like, $like, $like, $idOnly]);
    if ($pets) {
        $groups[] = ['label' => 'Pets', 'items' => array_map(function ($p) use ($base) {
            return ['icon' => '🐾', 'title' => $p['name'] . ' (Pet #' . $p['pet_id'] . ')',
                'detail' => trim($p['kind'] . ' · ' . $p['breed'] . ' · ' . $p['status'], ' ·'),
                'url' => $base . '/AdminFile/manage_pets.php?q=' . rawurlencode($p['name'])];
        }, $pets)];
    }

    $apps = gs_rows($pdo,
        "SELECT aa.application_id, aa.full_name, aa.application_status, p.name AS pet_name
         FROM adoption_applications aa JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.application_id = ? OR aa.full_name LIKE ? OR aa.email_address LIKE ? OR p.name LIKE ?
         ORDER BY (aa.application_id = ?) DESC, aa.application_id DESC LIMIT 6",
        [$idOnly, $like, $like, $like, $idOnly]);
    if ($apps) {
        $groups[] = ['label' => 'Adoption Requests', 'items' => array_map(function ($a) use ($base) {
            return ['icon' => '📄', 'title' => $a['full_name'] . ' - ' . $a['pet_name'],
                'detail' => 'Application #' . $a['application_id'] . ' · ' . $a['application_status'],
                'url' => $base . '/AdminFile/review_application.php?application_id=' . (int) $a['application_id']];
        }, $apps)];
    }

    $people = gs_rows($pdo,
        "SELECT user_id, username, email, role FROM users
         WHERE role IN ('Adopter', 'Staff') AND (user_id = ? OR username LIKE ? OR email LIKE ?)
         ORDER BY role ASC, username ASC LIMIT 6",
        [$idOnly, $like, $like]);
    if ($people) {
        $groups[] = ['label' => 'Adopters and Staff', 'items' => array_map(function ($u) use ($base) {
            $isStaff = $u['role'] === 'Staff';
            return ['icon' => $isStaff ? '🧑‍💼' : '👤', 'title' => $u['username'],
                'detail' => $u['role'] . ' · ' . $u['email'],
                'url' => $isStaff
                    ? $base . '/AdminFile/Manage_staff_file/manage_staff.php?q=' . rawurlencode($u['username'])
                    : $base . '/AdminFile/Manage_adopters_file/view_application.php?adopter_id=' . (int) $u['user_id']];
        }, $people)];
    }

    $pages = gs_pages([
        ['Dashboard', 'AdminFile/index.php', 'home overview dashboard'],
        ['Staff Management', 'AdminFile/Manage_staff_file/manage_staff.php', 'staff add staff promote'],
        ['Adopters Management', 'AdminFile/Manage_adopters_file/manage_adopters.php', 'adopters users'],
        ['Manage Pets', 'AdminFile/manage_pets.php', 'pets inventory add pet'],
        ['Payments', 'AdminFile/payments.php', 'payments gcash maya fee paid'],
        ['Reports', 'AdminFile/reports.php', 'reports charts'],
        ['Messages', 'AdminFile/messages.php', 'messages chat'],
        ['Activity Logs', 'AdminFile/activity_logs.php', 'activity logs history'],
        ['ML Model', 'AdminFile/ml_model.php', 'machine learning model train'],
        ['Account Settings', 'AdminFile/edit_profile.php', 'profile account settings'],
    ], $q, $base);
} else {
    // Adopter: Available na pet lang, at sariling data
    $pets = gs_rows($pdo,
        "SELECT pet_id, name, kind, breed, age_group FROM pet_inventory
         WHERE status = 'Available' AND (name LIKE ? OR breed LIKE ? OR kind LIKE ?)
         ORDER BY name ASC LIMIT 6",
        [$like, $like, $like]);
    if ($pets) {
        $groups[] = ['label' => 'Available Pets', 'items' => array_map(function ($p) use ($base) {
            return ['icon' => '🐾', 'title' => $p['name'],
                'detail' => trim($p['kind'] . ' · ' . $p['breed'] . ' · ' . $p['age_group'], ' ·'),
                'url' => $base . '/Adopter_File/pet_details.php?pet_id=' . (int) $p['pet_id']];
        }, $pets)];
    }

    $apps = gs_rows($pdo,
        "SELECT aa.application_id, aa.application_status, p.name AS pet_name
         FROM adoption_applications aa JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.adopter_id = ? AND (aa.application_id = ? OR p.name LIKE ? OR aa.application_status LIKE ?)
         ORDER BY aa.application_id DESC LIMIT 5",
        [$userId, $idOnly, $like, $like]);
    if ($apps) {
        $groups[] = ['label' => 'My Applications', 'items' => array_map(function ($a) use ($base) {
            return ['icon' => '📄', 'title' => 'Application for ' . $a['pet_name'],
                'detail' => 'Application #' . $a['application_id'] . ' · ' . $a['application_status'],
                'url' => $base . '/Adopter_File/my_applications.php'];
        }, $apps)];
    }

    $mine = gs_rows($pdo,
        "SELECT p.pet_id, p.name, p.breed
         FROM adoption_applications aa JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.adopter_id = ? AND aa.application_status = 'Approved' AND p.status = 'Adopted'
           AND (p.name LIKE ? OR p.breed LIKE ?)
         LIMIT 4",
        [$userId, $like, $like]);
    if ($mine) {
        $groups[] = ['label' => 'My Pets', 'items' => array_map(function ($p) use ($base) {
            return ['icon' => '❤️', 'title' => $p['name'], 'detail' => $p['breed'] . ' · Adopted',
                'url' => $base . '/Adopter_File/my_pets.php'];
        }, $mine)];
    }

    $pages = gs_pages([
        ['Dashboard', 'Adopter_File/adopter_dashboard.php', 'home dashboard'],
        ['Search Pets', 'Adopter_File/search_pets.php', 'search pets adopt available'],
        ['My Preference', 'Adopter_File/adopter_preferences.php', 'preference preferences lifestyle'],
        ['AI Recommendation', 'Adopter_File/ai_recommendation.php', 'ai recommendation match'],
        ['My Applications', 'Adopter_File/my_applications.php', 'applications status'],
        ['Schedule', 'Adopter_File/schedule.php', 'schedule interview meet-up meetup'],
        ['My Pets', 'Adopter_File/my_pets.php', 'my pets adopted concern report'],
        ['Favorites', 'Adopter_File/favorites.php', 'favorites saved'],
        ['Messages', 'Adopter_File/messages.php', 'messages chat staff'],
        ['Notifications', 'Adopter_File/notifications.php', 'notifications'],
        ['Payments', 'Adopter_File/payments.php', 'payments pay fee gcash maya qr'],
        ['Docs', 'Adopter_File/docs.php', 'docs certificate documents'],
        ['Shelter', 'Adopter_File/shelter.php', 'shelter location city hall map'],
        ['Edit Profile', 'Adopter_File/edit_profile.php', 'profile account settings'],
    ], $q, $base);
}

if ($pages) {
    $groups[] = ['label' => 'Pages', 'items' => $pages];
}

echo json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE);
