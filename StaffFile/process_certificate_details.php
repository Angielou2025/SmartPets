<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

$application_id = (int) ($_POST['application_id'] ?? 0);
$back = '/Caps2/Adopter_File/certificate.php?application_id=' . $application_id;

// Galing sa inline na pag-edit sa mismong papel (fetch) - JSON ang sagot
$isAjax = ($_POST['ajax'] ?? '') === '1';

function respond(bool $ok, string $message, array $values = []): void
{
    global $isAjax, $back;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $message, 'values' => $values]);
        exit();
    }
    $_SESSION[$ok ? 'success_message' : 'error_message'] = $message;
    header('Location: ' . ($ok ? $back : 'certificates.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $application_id <= 0) {
    respond(false, 'Invalid request.');
}

// Tapos na adoption lang (Approved + Paid) ang may dokumento
$check = $pdo->prepare(
    "SELECT aa.full_name, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
     WHERE aa.application_id = ? AND (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))"
);
$check->execute([$application_id]);
$app = $check->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    respond(false, 'Certificate not found.');
}

$fields = [
    'owner_name'           => 150,
    'owner_address'        => 255,
    'valid_id_type'        => 100,
    'valid_id_number'      => 100,
    'pet_color'            => 100,
    'distinguishing_marks' => 255,
    'spayed_neutered'      => 20,
    'veterinarian_name'    => 150,
    'vet_license_no'       => 100,
    'witness_name'         => 150,
    'witness_position'     => 150,
];

// Mga field lang na talagang ipinadala ang binabago - hindi nabubura ang iba
$values = [];
foreach ($fields as $name => $max) {
    if (!array_key_exists($name, $_POST)) {
        continue;
    }
    $v = preg_replace('/\s+/u', ' ', trim((string) $_POST[$name]));
    $values[$name] = $v === '' ? null : mb_substr($v, 0, $max);
}

// Tina-type ang petsa sa papel mismo (hal. "October 15, 2026" o "10/15/2026")
$shown = [];
if (array_key_exists('signing_date', $_POST)) {
    $date = trim((string) $_POST['signing_date']);
    if ($date === '') {
        $values['signing_date'] = null;
        $shown['signing_date'] = '';
    } else {
        $ts = strtotime($date);
        if ($ts === false) {
            respond(false, 'Signing date is not a valid date. Try something like "October 15, 2026".');
        }
        $values['signing_date'] = date('Y-m-d', $ts);
        $shown['signing_date'] = date('F j, Y', $ts);
    }
}

if (!$values) {
    respond(true, 'Nothing to save.');
}

$cols = array_keys($values);
$sql = 'INSERT INTO adoption_documents (application_id, ' . implode(', ', $cols) . ', updated_by)
        VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $cols)) . ', updated_by = VALUES(updated_by)';

$pdo->prepare($sql)->execute(array_merge([$application_id], array_values($values), [(int) $_SESSION['user_id']]));

log_activity($pdo, "Updated adoption documents for {$app['pet_name']}", "Adopter: {$app['full_name']}");
respond(true, 'Document details saved.', $shown);
