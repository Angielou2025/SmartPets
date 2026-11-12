<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    header('Location: ../Login_Page/login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// Lampas sa post_max_size ng server ang kabuuang laki ng mga larawan: binubura ng PHP
// ang buong $_POST, kaya walang pet_id. Ibalik sa form na may malinaw na paliwanag.
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['error_message'] = 'Your photos are too large to send together. Please upload smaller photos (or take them again with a lower camera resolution) and submit again.';
    $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    header('Location: ' . (preg_match('#adoption_application\.php\?pet_id=\d+#', $back, $m) ? $m[0] : 'search_pets.php'));
    exit();
}

$pet_id = (int) ($_POST['pet_id'] ?? 0);

if (!$pet_id) {
    header('Location: search_pets.php');
    exit();
}

// I-verify na "Available" pa rin ang pet (baka na-adopt na habang nag-fifill up)
$petStmt = $pdo->prepare("SELECT pet_id, status FROM pet_inventory WHERE pet_id = ?");
$petStmt->execute([$pet_id]);
$pet = $petStmt->fetch(PDO::FETCH_ASSOC);

if (!$pet || $pet['status'] !== 'Available') {
    $_SESSION['error_message'] = 'This pet is no longer available for adoption.';
    header('Location: search_pets.php');
    exit();
}

// Isang adopter lang bawat alaga (connection/database.php). Sinusuri ulit ito sa ibaba
// habang naka-lock, para kapag sabay na nagpasa ang dalawa, ang nauna lang ang matatanggap.
function sp_reject_if_taken(PDO $pdo, int $pet_id, int $user_id, bool $locked): void
{
    $activeApp = sp_pet_active_application($pdo, $pet_id);
    if ($activeApp === null) {
        return;
    }
    if ($locked) {
        sp_interview_unlock($pdo);
    }
    if ($activeApp['adopter_id'] === $user_id) {
        $_SESSION['error_message'] = 'You already have an application for this pet. You can follow it in My Applications.';
        header('Location: my_applications.php');
    } else {
        $_SESSION['error_message'] = 'Sorry, another adopter applied for this pet first. Please choose another pet for now.';
        header('Location: search_pets.php');
    }
    exit();
}
require_once __DIR__ . '/../connection/interview_schedule.php';
sp_reject_if_taken($pdo, $pet_id, $user_id, false);

function computeAgeFromBirthdate(string $birthdate): int {
    $bd = new DateTime($birthdate);
    $today = new DateTime();
    return (int) $bd->diff($today)->y;
}

$scalarFields = [
    'last_name', 'first_name', 'middle_name', 'suffix',
    'house_no', 'street', 'subdivision', 'barangay', 'phone_number', 'email_address', 'birthdate',
    'occupation', 'social_media_link', 'civil_status',
    'guardian_name', 'guardian_relationship',
    'q_responsible_for_care', 'q_financial_responsibility', 'q_emergency_caregiver',
    'q_hours_left_alone', 'q_introduction_strategy',
    'q_family_support', 'q_current_pets', 'q_past_pet_ownership',
];

$data = [];
foreach ($scalarFields as $field) {
    $data[$field] = isset($_POST[$field]) ? trim($_POST[$field]) : '';
}

// Araw at oras na pinili ng adopter; ang staff pa ang mag-a-Accept o Decline
require_once __DIR__ . '/../connection/interview_schedule.php';
$interview_date = trim((string) ($_POST['interview_date'] ?? ''));
$interview_time = trim((string) ($_POST['interview_time'] ?? ''));

$errors = [];

$requiredScalars = [
    'last_name', 'first_name', 'middle_name',
    'house_no', 'street', 'subdivision', 'barangay',
    'phone_number', 'email_address', 'birthdate',
    'occupation', 'social_media_link', 'civil_status',
    'q_responsible_for_care', 'q_financial_responsibility', 'q_emergency_caregiver',
    'q_hours_left_alone', 'q_introduction_strategy',
    'q_family_support', 'q_current_pets', 'q_past_pet_ownership',
];
$fieldLabels = [
    'last_name' => 'Surname', 'house_no' => 'House/Lot Number', 'street' => 'Street Name',
    'subdivision' => 'Subdivision/Village/Building',
];
foreach ($requiredScalars as $field) {
    if ($data[$field] === '') {
        $errors[] = ($fieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field))) . ' is required.';
    }
}

// Pangalan: letra, espasyo, tuldok, gitling at kudlit lang. Optional ang suffix.
foreach (['last_name' => 'Surname', 'first_name' => 'First Name', 'middle_name' => 'Middle Name'] as $field => $label) {
    if ($data[$field] !== '' && !preg_match("/^\p{L}[\p{L} .'\-]*$/u", $data[$field])) {
        $errors[] = $label . ' must contain letters only.';
    }
}
if (!in_array($data['suffix'], ['', 'Jr.', 'Sr.', 'II', 'III', 'IV', 'V'], true)) {
    $errors[] = 'Invalid suffix.';
}

// Phone Number: numero lang, 11 digits na nagsisimula sa 09. Inaalis muna ang espasyo/gitling,
// at ang "+63 9XX..." ay ginagawang "09XX..." (pareho ng ginagawa ng form).
$phoneDigits = preg_replace('/\D+/', '', $data['phone_number']);
if (preg_match('/^639\d{9}$/', $phoneDigits)) {
    $phoneDigits = '0' . substr($phoneDigits, 2);
}
if ($data['phone_number'] !== '') {
    if (!preg_match('/^09\d{9}$/', $phoneDigits) || preg_match('/[^\d\s+\-()]/', $data['phone_number'])) {
        $errors[] = 'Phone Number must be an 11-digit mobile number starting with 09 (numbers only).';
    } else {
        $data['phone_number'] = $phoneDigits;
    }
}

// Address: numero lang ang House/Lot; bawal ang N/A sa Street; N/A puwede sa Subdivision.
// Taga-City of Caloocan lang (Barangay 1 - 188).
$naPattern = '/^n\.?\s*\/?\s*a\.?$/i';
if ($data['house_no'] !== '' && !ctype_digit($data['house_no'])) {
    $errors[] = 'House/Lot Number must contain numbers only.';
}
if (preg_match($naPattern, $data['street'])) {
    $errors[] = 'Please enter your street name. N/A is not allowed.';
}
if (preg_match($naPattern, $data['subdivision'])) {
    $data['subdivision'] = 'N/A';
}
if ($data['barangay'] !== '' && !(preg_match('/^Barangay (\d{1,3})$/', $data['barangay'], $m) && $m[1] >= 1 && $m[1] <= 188)) {
    $errors[] = 'Only residents of the City of Caloocan can apply. Please choose a valid Caloocan barangay.';
}

// Binubuo ang full_name at address para sa ibang page (staff review, certificates, atbp.)
$data['full_name'] = implode(' ', array_filter([$data['first_name'], $data['middle_name'], $data['last_name'], $data['suffix']], 'strlen'));
$addressParts = array_filter([$data['house_no'], $data['street'], $data['subdivision']], function ($v) {
    return $v !== '' && $v !== 'N/A';
});
$addressParts[] = $data['barangay'];
$addressParts[] = 'City of Caloocan';
$data['address'] = implode(', ', $addressParts);

$scheduleError = sp_interview_request_error($pdo, $interview_date, $interview_time);
if ($scheduleError !== null) {
    $errors[] = $scheduleError;
}

// 18 taong gulang pataas lang ang puwedeng mag-adopt. Sinusuri ulit dito sa
// server - hindi umaasa sa JavaScript lang.
if ($data['birthdate'] !== '') {
    try {
        if (computeAgeFromBirthdate($data['birthdate']) < 18) {
            $errors[] = 'You must be at least 18 years old to adopt.';
        }
    } catch (Exception $e) {
        $errors[] = 'Invalid birthdate.';
    }
}
// Wala nang guardian (hindi na puwede ang menor de edad); nananatili lang ang mga column
$data['guardian_name'] = null;
$data['guardian_relationship'] = null;
// Multi-file upload
$upload_dir = '../uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
$max_bytes = 10 * 1024 * 1024;

// Required lahat maliban sa Bedroom (optional - kung papayagan ang pet sa kama).
// Wala na sa form ang Street View, Dining Area, Kitchen, Windows at Backyard
// (nananatili ang mga column para sa mga lumang application; null na sa bago).
$fileFieldMap = [
    'photo_front_house' => ['photo_front_house', true],
    'photo_living_room' => ['photo_living_room', true],
    'photo_bedrooms'    => ['photo_bedrooms', false],
    'valid_id'          => ['valid_id_url', true],
    'valid_id_back'     => ['valid_id_back_url', true],
    'barangay_certificate' => ['barangay_certificate_url', true],
];

// Returning adopter na piniling gamitin ang lumang details: ang mga larawan at
// ID na hindi pinalitan ay kukunin sa dati niyang matagumpay na application.
// Sa adopter mismo dapat ito (alinmang application na naipasa na niya).
$reuseFiles = [];
$reuse_application_id = (int) ($_POST['reuse_application_id'] ?? 0);
if ($reuse_application_id > 0) {
    $reuseStmt = $pdo->prepare(
        "SELECT aa.* FROM adoption_applications aa
         WHERE aa.application_id = ? AND aa.adopter_id = ?"
    );
    $reuseStmt->execute([$reuse_application_id, $user_id]);
    $reuseFiles = $reuseStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    // Lumang Valid ID (walang likod) = hindi Barangay ID; kailangang mag-upload ng bago
    if (empty($reuseFiles['valid_id_back_url'])) {
        unset($reuseFiles['valid_id_url']);
    }
}

$uploaded = [
    // Tinanggal sa form: laging walang laman sa mga bagong application
    'photo_dining_area' => null,
    'photo_kitchen'     => null,
    'photo_windows'     => null,
    'photo_street'      => null,
    'photo_backyard'    => null,
];

require_once __DIR__ . '/../connection/residency_id_check.php';
$idSides = ['valid_id' => 'front', 'valid_id_back' => 'back'];
$fileLabels = [
    'valid_id' => 'Barangay ID (front)', 'valid_id_back' => 'Barangay ID (back)',
];

foreach ($fileFieldMap as $formField => $fieldInfo) {
    [$dbCol, $required] = $fieldInfo;
    $uploaded[$dbCol] = null;
    $fileLabel = $fileLabels[$formField] ?? ucwords(str_replace('_', ' ', $formField));

    if (!isset($_FILES[$formField]) || $_FILES[$formField]['error'] === UPLOAD_ERR_NO_FILE) {
        $oldFile = basename((string) ($reuseFiles[$dbCol] ?? ''));
        if ($oldFile !== '' && is_file($upload_dir . $oldFile)) {
            $uploaded[$dbCol] = $oldFile;
            continue;
        }
        if ($required) {
            $errors[] = $fileLabel . ' is required.';
        }
        continue;
    }

    if ($_FILES[$formField]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = $fileLabel . ' failed to upload.';
        continue;
    }

    if ($_FILES[$formField]['size'] > $max_bytes) {
        $errors[] = $fileLabel . ' exceeds the 10MB limit.';
        continue;
    }

    $ext = strtolower(pathinfo($_FILES[$formField]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_extensions)) {
        $errors[] = $fileLabel . ' must be a jpg, jpeg, png, or gif image.';
        continue;
    }

    // Barangay ID: tiyaking tamang ID, tamang side, at malinaw (laktaw kung nasuri na sa form)
    if (isset($idSides[$formField])) {
        $idCheck = sp_check_residency_id(
            (string) file_get_contents($_FILES[$formField]['tmp_name']),
            sp_image_mime($_FILES[$formField]['tmp_name']),
            $idSides[$formField]
        );
        if (!$idCheck['ok']) {
            $errors[] = 'Barangay ID (' . $idSides[$formField] . '): ' . $idCheck['message'];
            continue;
        }
    }

    $new_filename = uniqid() . '_' . time() . '_' . $formField . '.' . $ext;
    if (move_uploaded_file($_FILES[$formField]['tmp_name'], $upload_dir . $new_filename)) {
        $uploaded[$dbCol] = $new_filename;
    } else {
        $errors[] = $fileLabel . ' failed to save.';
    }
}

if (!empty($errors)) {
    $_SESSION['error_message'] = implode(' ', $errors);
    header('Location: adoption_application.php?pet_id=' . $pet_id);
    exit();
}

// Suriin ulit ang oras habang naka-lock: kapag may naunang adopter na nakakuha ng
// oras na ito habang nag-a-upload, siya ang may-ari at kailangang pumili ng bago.
// (Nananatili sa browser ang mga sagot at larawan, kaya hindi na uulitin ang form.)
sp_interview_lock($pdo);
$scheduleError = sp_interview_request_error($pdo, $interview_date, $interview_time);
if ($scheduleError !== null) {
    sp_interview_unlock($pdo);
    $_SESSION['error_message'] = $scheduleError;
    header('Location: adoption_application.php?pet_id=' . $pet_id);
    exit();
}
// Panghuling check habang naka-lock: baka may naunang nagpasa habang nag-a-upload
sp_reject_if_taken($pdo, $pet_id, $user_id, true);

try {
    $sql = "INSERT INTO adoption_applications (
        adopter_id, pet_id,
        full_name, address, phone_number, email_address, birthdate, occupation, social_media_link, civil_status,
        last_name, first_name, middle_name, suffix, house_no, street, subdivision, barangay,
        guardian_name, guardian_relationship,
        q_responsible_for_care, q_financial_responsibility, q_emergency_caregiver, q_hours_left_alone,
        q_introduction_strategy, q_family_support, q_current_pets, q_past_pet_ownership,
        photo_front_house, photo_street, photo_living_room, photo_dining_area, photo_kitchen,
        photo_bedrooms, photo_windows, photo_backyard, valid_id_url, valid_id_back_url, barangay_certificate_url,
        preferred_interview_date, preferred_interview_time, schedule_status
    ) VALUES (
        ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?, 'Pending'
    )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $user_id, $pet_id,
        $data['full_name'], $data['address'], $data['phone_number'], $data['email_address'], $data['birthdate'], $data['occupation'], $data['social_media_link'], $data['civil_status'],
        $data['last_name'], $data['first_name'], $data['middle_name'], $data['suffix'] !== '' ? $data['suffix'] : null,
        $data['house_no'], $data['street'], $data['subdivision'], $data['barangay'],
        $data['guardian_name'], $data['guardian_relationship'],
        $data['q_responsible_for_care'], $data['q_financial_responsibility'], $data['q_emergency_caregiver'], (int) $data['q_hours_left_alone'],
        $data['q_introduction_strategy'], $data['q_family_support'], $data['q_current_pets'], $data['q_past_pet_ownership'],
        $uploaded['photo_front_house'], $uploaded['photo_street'], $uploaded['photo_living_room'], $uploaded['photo_dining_area'], $uploaded['photo_kitchen'],
        $uploaded['photo_bedrooms'], $uploaded['photo_windows'], $uploaded['photo_backyard'], $uploaded['valid_id_url'], $uploaded['valid_id_back_url'], $uploaded['barangay_certificate_url'],
        $interview_date, $interview_time . ':00',
    ]);

    $new_application_id = $pdo->lastInsertId();
    sp_interview_unlock($pdo);

    // I-freeze ang match % sa oras ng pag-submit: ito na ang makikita ng staff
    // sa application, kahit baguhin pa ng adopter ang preferences pagkatapos
    try {
        require_once __DIR__ . '/../connection/match_scoring.php';
        $prefRow = $pdo->prepare('SELECT * FROM adopter_preferences WHERE user_id = ?');
        $prefRow->execute([$user_id]);
        $prefs = $prefRow->fetch(PDO::FETCH_ASSOC);
        $petRow = $pdo->prepare('SELECT * FROM pet_inventory WHERE pet_id = ?');
        $petRow->execute([$pet_id]);
        $petFull = $petRow->fetch(PDO::FETCH_ASSOC);
        if ($prefs && $petFull) {
            $snap = sp_match_snapshot($prefs, $petFull);
            $pdo->prepare('UPDATE adoption_applications SET match_score = ?, match_breakdown = ? WHERE application_id = ?')
                ->execute([$snap['score'], $snap['breakdown_json'], (int) $new_application_id]);
        }
    } catch (Throwable $e) {
        error_log('Match snapshot failed for application ' . $new_application_id . ': ' . $e->getMessage());
    }

    $petNameStmt = $pdo->prepare("SELECT name FROM pet_inventory WHERE pet_id = ?");
    $petNameStmt->execute([$pet_id]);
    $petName = $petNameStmt->fetchColumn() ?: 'your chosen pet';
    $interviewWhen = date('M j, Y', strtotime($interview_date)) . ' at ' . date('g:i A', strtotime($interview_time));
    sp_notify(
        $pdo,
        (int) $user_id,
        'application',
        'Application received',
        "We received your adoption application for $petName. You asked for an interview on $interviewWhen. The staff will check this schedule and let you know if it is accepted.",
        'my_applications.php',
        'app_submitted_' . $new_application_id
    );

    $_SESSION['success_message'] = 'Your application has been submitted successfully! Please wait for approval.';
    // Ang ?submitted ang nagsasabi sa My Applications na burahin ang naka-save na draft
    header('Location: my_applications.php?submitted=' . $pet_id);
    exit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = 'Failed to submit application: ' . $e->getMessage();
    header('Location: adoption_application.php?pet_id=' . $pet_id);
    exit();
}
