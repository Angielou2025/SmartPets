<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../Login_Page/login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$fields = [
    'living_space',
    'time_commitment',
    'activity_level',
    'pet_experience',
    'allergies',
    'household_composition',
    'noise_tolerance',
    'maintenance_level',
    'adoption_intent',
    'pref_kind',
    'pref_breed',
    'pref_temperament',
    'pref_energy_level',
    'pref_size',
    'pref_age_group',
    'pref_gender',
    'pref_health_condition'
];

$data = [];
foreach ($fields as $field) {
    $data[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
}

// Pet Criteria: tinatanggap lang ang value na meron talaga sa mga available na
// alaga (o "Any"). Ang iba ay ginagawang "Any".
$prefCols = [
    'pref_kind' => 'kind', 'pref_breed' => 'breed', 'pref_temperament' => 'temperament',
    'pref_energy_level' => 'energy_level', 'pref_size' => 'size', 'pref_age_group' => 'age_group',
    'pref_gender' => 'gender', 'pref_health_condition' => 'health_condition',
];
try {
    $availRows = $pdo->query("SELECT " . implode(', ', array_unique(array_values($prefCols))) . " FROM pet_inventory WHERE status = 'Available'")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prefCols as $field => $col) {
        $allowed = [];
        foreach ($availRows as $r) {
            $raw = trim((string) ($r[$col] ?? ''));
            foreach ($col === 'temperament' ? explode(',', $raw) : [$raw] as $v) {
                if (trim($v) !== '') $allowed[trim($v)] = true;
            }
        }
        if ($data[$field] === '' || ($data[$field] !== 'Any' && !isset($allowed[$data[$field]]))) {
            $data[$field] = 'Any';
        }
    }
} catch (Exception $e) {
    // hindi mabasa ang pets: hayaan ang naipasang value
}

try {
    $check = $pdo->prepare('SELECT preference_id FROM adopter_preferences WHERE user_id = ?');
    $check->execute([$user_id]);

    if ($check->fetch()) {
        $sql = 'UPDATE adopter_preferences SET ' . implode(', ', array_map(fn($field) => "$field = ?", $fields)) . ' WHERE user_id = ?';
        $params = array_values($data);
        $params[] = $user_id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO adopter_preferences (user_id, ' . implode(', ', $fields) . ') VALUES (?, ' . $placeholders . ')';
        $params = array_merge([$user_id], array_values($data));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Pagkatapos mag-save: diretso sa mga na-match na pets (wala nang Schedule step)
    $_SESSION['success_message'] = 'Preferences saved successfully.';
    header('Location: search_pets.php');
    exit();
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Failed to save preferences: ' . $e->getMessage();
    header('Location: adopter_preferences.php');
    exit();
}
