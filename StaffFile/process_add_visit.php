<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pet_monitoring.php');
    exit();
}

$application_id = (int) ($_POST['application_id'] ?? 0);
$visit_date = trim($_POST['visit_date'] ?? '');
$pet_condition = $_POST['pet_condition'] ?? '';
$living_condition = $_POST['living_condition'] ?? '';
$result = $_POST['result'] ?? '';
$remarks = trim($_POST['remarks'] ?? '');
// Nakikita ng adopter - kailangan kapag ibinalik o binawi ang pet
$return_reason = mb_substr(trim($_POST['return_reason'] ?? ''), 0, 1000);
$back = $_POST['back'] ?? '';
$backUrl = $back === 'history' ? 'pet_monitoring.php?application_id=' . $application_id : 'pet_monitoring.php';

function visit_fail(string $message, string $backUrl): void
{
    $_SESSION['error_message'] = $message;
    header('Location: ' . $backUrl);
    exit();
}

// Tapos na adoption lang (Approved + Paid) ang sinusubaybayan
$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.adopter_id, aa.full_name, aa.pet_id, p.name AS pet_name, pay.payment_date
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
     WHERE aa.application_id = ? AND aa.application_status = 'Approved'"
);
$stmt->execute([$application_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    visit_fail('Adoption record not found.', 'pet_monitoring.php');
}

// Tapos na ang monitoring kapag binawi o ibinalik na ang hayop
$last = $pdo->prepare("SELECT result FROM pet_visits WHERE application_id = ? ORDER BY visit_date DESC, visit_id DESC LIMIT 1");
$last->execute([$application_id]);
$lastResult = $last->fetchColumn();
if ($lastResult && $lastResult !== 'Stayed with Adopter') {
    visit_fail('Monitoring for this adoption has already ended.', $backUrl);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date) || strtotime($visit_date) === false) {
    visit_fail('Please enter a valid visit date.', $backUrl);
}
if ($visit_date > date('Y-m-d')) {
    visit_fail('The visit date cannot be in the future.', $backUrl);
}
if ($visit_date < date('Y-m-d', strtotime($app['payment_date']))) {
    visit_fail('The visit date cannot be before the adoption date.', $backUrl);
}
if (!in_array($pet_condition, ['Healthy', 'Needs Attention', 'Poor'], true)
    || !in_array($living_condition, ['Adequate', 'Inadequate'], true)
    || !in_array($result, ['Stayed with Adopter', 'Reclaimed by Office', 'Returned by Adopter'], true)) {
    visit_fail('Please fill out all required fields.', $backUrl);
}
if ($result !== 'Stayed with Adopter' && $return_reason === '') {
    visit_fail('Please explain why the pet is being returned or reclaimed. The adopter will see this reason.', $backUrl);
}

// Pag-update ng detalye ng pet batay sa obserbasyon sa visit (opsyonal).
// Ang mga talagang nagbago lang ang ina-update at itinatala.
$petUpdates = [];
$petChanges = [];
if (($_POST['update_pet'] ?? '0') === '1') {
    $cur = $pdo->prepare(
        "SELECT age_group, size, energy_level, temperament, shedding_level, health_condition, vaccination_status, description
         FROM pet_inventory WHERE pet_id = ?"
    );
    $cur->execute([$app['pet_id']]);
    $current = $cur->fetch(PDO::FETCH_ASSOC) ?: [];

    $petFields = [
        'age_group'          => ['label' => 'Age Group', 'options' => ['Puppy/Kitten', 'Young Adult', 'Adult', 'Senior']],
        'size'               => ['label' => 'Size', 'options' => ['Small', 'Medium', 'Large']],
        'energy_level'       => ['label' => 'Energy Level', 'options' => ['Low', 'Moderate', 'High']],
        'health_condition'   => ['label' => 'Health Condition', 'options' => ['Healthy', 'Elderly', 'Partially Blind/Deaf', 'Medical Condition', 'Recovering/Injured']],
        'vaccination_status' => ['label' => 'Vaccination Status', 'options' => ['Fully Vaccinated', 'Partially Vaccinated', 'Not Vaccinated']],
        'shedding_level'     => ['label' => 'Shedding Level', 'options' => ['', 'Low', 'Moderate', 'High']],
        'temperament'        => ['label' => 'Temperament', 'max' => 100],
        'description'        => ['label' => 'Description', 'max' => 2000],
    ];

    foreach ($petFields as $key => $cfg) {
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $new = trim((string) $_POST[$key]);
        if (isset($cfg['options'])) {
            if (!in_array($new, $cfg['options'], true)) {
                visit_fail('Please choose a valid ' . strtolower($cfg['label']) . '.', $backUrl);
            }
        } else {
            $new = mb_substr($new, 0, $cfg['max']);
            if ($key === 'temperament' && $new === '') {
                visit_fail('Temperament cannot be empty.', $backUrl);
            }
        }

        $old = trim((string) ($current[$key] ?? ''));
        if ($new !== $old) {
            $petUpdates[$key] = ($key === 'shedding_level' && $new === '') ? null : $new;
            // Ang description ay mahaba, kaya "updated" lang ang itinatala
            $petChanges[] = $key === 'description'
                ? 'Description updated'
                : $cfg['label'] . ': ' . ($old !== '' ? $old : 'Not set') . ' → ' . ($new !== '' ? $new : 'Not set');
        }
    }
}

// Larawan (opsyonal) - larawan ng hayop na kuha sa pagbisita
$photo_url = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        visit_fail('The photo could not be uploaded. Please try again.', $backUrl);
    }
    if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
        visit_fail('The photo must be 5 MB or smaller.', $backUrl);
    }
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) || @getimagesize($_FILES['photo']['tmp_name']) === false) {
        visit_fail('Please upload an image file (JPG, PNG, GIF, or WEBP).', $backUrl);
    }

    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $filename = 'visit_' . uniqid() . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
        visit_fail('The photo could not be saved. Please try again.', $backUrl);
    }
    $photo_url = $filename;
}

// Gawing profile photo ng pet ang kuha sa visit (kapag pinili)
if ($photo_url !== null && ($_POST['use_as_pet_photo'] ?? '') === '1') {
    $petUpdates['photo_url'] = $photo_url;
    $petChanges[] = 'Profile photo updated from this visit';
}

if ($petUpdates) {
    $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($petUpdates)));
    $pdo->prepare("UPDATE pet_inventory SET $sets WHERE pet_id = ?")
        ->execute(array_merge(array_values($petUpdates), [$app['pet_id']]));
}

$pdo->prepare(
    "INSERT INTO pet_visits (application_id, pet_id, visit_date, pet_condition, living_condition, remarks, return_reason, result, photo_url, pet_changes, recorded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
)->execute([
    $application_id,
    $app['pet_id'],
    $visit_date,
    $pet_condition,
    $living_condition,
    $remarks !== '' ? $remarks : null,
    ($result !== 'Stayed with Adopter' && $return_reason !== '') ? $return_reason : null,
    $result,
    $photo_url,
    $petChanges ? implode("\n", $petChanges) : null,
    (int) $_SESSION['user_id'],
]);

if ($petChanges) {
    log_activity($pdo, "Updated details of {$app['pet_name']} after a surprise visit", implode('; ', $petChanges));
}

log_activity(
    $pdo,
    "Recorded surprise visit for {$app['pet_name']}",
    "Adopter: {$app['full_name']}, Date: $visit_date, Pet: $pet_condition, Home: $living_condition, Result: $result"
);

if ($result === 'Stayed with Adopter') {
    $_SESSION['success_message'] = "Surprise visit for {$app['pet_name']} recorded."
        . ($petChanges ? ' Pet details updated (' . count($petChanges) . ' change' . (count($petChanges) === 1 ? '' : 's') . ').' : '');
} else {
    // Bumalik na sa shelter ang hayop - pwede na ulit itong i-adopt ng iba
    $pdo->prepare("UPDATE pet_inventory SET status = 'Available' WHERE pet_id = ?")->execute([$app['pet_id']]);
    log_activity($pdo, "{$app['pet_name']} is back in the shelter and available for adoption", "Result: $result, Reason: $return_reason, Previous adopter: {$app['full_name']}");

    // Abisuhan ang adopter, kasama ang dahilan - makikita rin niya ito sa My Pets
    $noticeTitle = $result === 'Returned by Adopter'
        ? "{$app['pet_name']} has been returned to the shelter"
        : "{$app['pet_name']} has been reclaimed by the shelter";
    sp_notify(
        $pdo,
        (int) $app['adopter_id'],
        'monitoring',
        $noticeTitle,
        'Reason: ' . rtrim($return_reason, " .") . '. You can see the details under My Pets. If you have questions, please message the shelter.',
        'my_pets.php',
        'pet_returned_' . $application_id
    );
    $_SESSION['success_message'] = "Surprise visit for {$app['pet_name']} recorded. Monitoring has ended ($result), and {$app['pet_name']} is now Available for adoption again.";
}

header('Location: ' . $backUrl);
exit();
