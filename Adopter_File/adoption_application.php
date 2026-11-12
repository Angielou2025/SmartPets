<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$sp_unread_count = sp_unread_message_count($pdo, (int) $_SESSION['user_id']);

$pet_id = $_GET['pet_id'] ?? null;
if (!$pet_id) {
    header('Location: search_pets.php');
    exit();
}

$petStmt = $pdo->prepare("SELECT pet_id, name, photo_url, status FROM pet_inventory WHERE pet_id = ?");
$petStmt->execute([$pet_id]);
$pet = $petStmt->fetch(PDO::FETCH_ASSOC);

if (!$pet || $pet['status'] !== 'Available') {
    $_SESSION['error_message'] = 'This pet is no longer available for adoption.';
    header('Location: search_pets.php');
    exit();
}

// Isang adopter lang bawat alaga: may nauna nang nag-apply (o siya mismo ay may aplikasyon na)
$activeApp = sp_pet_active_application($pdo, (int) $pet_id);
if ($activeApp !== null) {
    if ($activeApp['adopter_id'] === (int) $user_id) {
        $_SESSION['error_message'] = 'You already have an application for ' . $pet['name'] . '. You can follow it in My Applications.';
        header('Location: my_applications.php');
    } else {
        $_SESSION['error_message'] = 'Another adopter has already applied for ' . $pet['name'] . '. Please choose another pet for now.';
        header('Location: search_pets.php');
    }
    exit();
}

// Kailangan munang naka-fill up ang reference bago mag-apply
$prefCheck = $pdo->prepare('SELECT 1 FROM adopter_preferences WHERE user_id = ? LIMIT 1');
$prefCheck->execute([$user_id]);
if (!$prefCheck->fetchColumn()) {
    header('Location: adopter_preferences.php');
    exit();
}

$userStmt = $pdo->prepare('SELECT username, email, profile_image FROM users WHERE user_id = ?');
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';
$user_email = $user['email'] ?? '';

$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

// Returning adopter: ang pinakabagong application na naipasa na niya (kahit Pending pa),
// para matanong kung gagamitin ulit ang Applicant, Household, at Photos & ID.
// Inuuna ang may kumpletong bagong format (hiwalay na pangalan + Barangay ID harap at likod).
$previous = null;
$prevStmt = $pdo->prepare(
    "SELECT aa.*, p.name AS prev_pet_name,
            (aa.application_status = 'Approved' AND EXISTS (
                SELECT 1 FROM payments pay WHERE pay.application_id = aa.application_id AND pay.status = 'Paid'
            )) AS prev_adopted
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.adopter_id = ?
     ORDER BY (aa.last_name IS NOT NULL AND aa.last_name <> '' AND aa.valid_id_back_url IS NOT NULL) DESC,
              aa.application_id DESC
     LIMIT 1"
);
$prevStmt->execute([$user_id]);
$prevRow = $prevStmt->fetch(PDO::FETCH_ASSOC);

if ($prevRow) {
    $prevFields = [
        'last_name', 'first_name', 'middle_name', 'suffix',
        'house_no', 'street', 'subdivision', 'barangay', 'phone_number', 'email_address', 'birthdate', 'occupation',
        'social_media_link', 'civil_status',
        'q_responsible_for_care', 'q_financial_responsibility', 'q_emergency_caregiver',
        'q_hours_left_alone', 'q_introduction_strategy', 'q_family_support',
        'q_current_pets', 'q_past_pet_ownership',
    ];
    // Pangalan ng file input sa form => column sa database
    $prevFileMap = [
        'photo_front_house' => 'photo_front_house', 'photo_living_room' => 'photo_living_room',
        'photo_bedrooms' => 'photo_bedrooms',
        'valid_id' => 'valid_id_url', 'valid_id_back' => 'valid_id_back_url', 'barangay_certificate' => 'barangay_certificate_url',
    ];

    $previous = ['application_id' => (int) $prevRow['application_id'], 'pet_name' => $prevRow['prev_pet_name'], 'adopted' => (bool) $prevRow['prev_adopted'], 'fields' => [], 'files' => []];
    foreach ($prevFields as $f) {
        $previous['fields'][$f] = (string) ($prevRow[$f] ?? '');
    }
    // Lumang application (bago nahati ang pangalan): kunin sa full_name.
    // Huling salita = apelyido, ang nauna = pangalan (puwedeng itama ng adopter).
    if ($previous['fields']['last_name'] === '' && $previous['fields']['first_name'] === '' && trim((string) ($prevRow['full_name'] ?? '')) !== '') {
        $parts = preg_split('/\s+/', trim($prevRow['full_name']));
        $previous['fields']['last_name'] = count($parts) > 1 ? array_pop($parts) : '';
        $previous['fields']['first_name'] = implode(' ', $parts);
    }
    // Ang mga file lang na nandoon pa sa uploads/ ang puwedeng gamitin ulit
    // Ang lumang "Valid ID" (bago kailanganin ang Barangay ID na may harap at likod)
    // ay hindi na puwedeng gamitin ulit - kailangang mag-upload ng Barangay ID
    if (empty($prevRow['valid_id_back_url'])) {
        unset($prevFileMap['valid_id'], $prevFileMap['valid_id_back']);
    }
    foreach ($prevFileMap as $input => $col) {
        $file = basename((string) ($prevRow[$col] ?? ''));
        if ($file !== '' && is_file(__DIR__ . '/../uploads/' . $file)) {
            $previous['files'][$input] = $file;
        }
    }
}

// Interview: pipili ang adopter ng araw (Lunes-Biyernes) at maglalagay ng oras;
// ang staff ang mag-a-Accept o Decline. Kasama ang mga oras na kinuha na ng iba.
require_once __DIR__ . '/../connection/interview_schedule.php';
$pickerConfig = sp_interview_picker_config($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Adoption Application</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        .reuse-backdrop {
            position: fixed; top: 0; right: 0; bottom: 0; left: 0; z-index: 2000;
            display: flex; align-items: center; justify-content: center;
            padding: 16px; background: rgba(0, 0, 0, 0.55);
        }
        .reuse-backdrop.hidden { display: none; }

        /* Wala pang 18: pulang paalala sa ilalim ng Birthdate */
        .age-error {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            color: #c62828;
            font-size: 12.5px;
            font-weight: 600;
        }
        .age-error[hidden] { display: none; }
        #birthdate.is-invalid { border-color: #e53935; box-shadow: 0 0 0 3px rgba(229, 57, 53, 0.12); }
        .reuse-card {
            width: 100%; max-width: 460px; max-height: calc(100vh - 32px); overflow-y: auto;
            background: #fff; border-radius: 16px; padding: 24px; text-align: center;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
        }
        .reuse-icon {
            width: 56px; height: 56px; margin: 0 auto 10px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #e7f5ea; color: #2e5b32; font-size: 24px;
        }
        .reuse-card h3 { margin: 0 0 8px; color: #1b4d2e; }
        .reuse-card p { margin: 0 0 12px; color: #374151; font-size: 14px; line-height: 1.5; }
        .reuse-card ul { list-style: none; padding: 0; margin: 0 0 12px; text-align: left; display: inline-block; }
        .reuse-card li { font-size: 14px; color: #1b4d2e; margin: 4px 0; }
        .reuse-card li i { color: #2e5b32; margin-right: 6px; }
        .reuse-card .reuse-hint { font-size: 12.5px; color: #6b7280; }
        .reuse-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .reuse-actions button { flex: 1 1 180px; }
        .draft-reset {
            margin-left: 6px; background: none; border: none; padding: 0; cursor: pointer;
            color: #1b4d2e; font-weight: 700; text-decoration: underline; font-size: 13.5px; font-family: inherit;
        }
        /* Title sa kaliwa, "Applying for" sa kanan (dito lang sa application form) */
        .app-form-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px 16px; flex-wrap: wrap; text-align: left;
        }
        .app-form-header h2, .app-form-header p { margin: 0; }
        .app-form-header p { text-align: right; }
        @media (max-width: 480px) {
            .app-form-header { flex-direction: column; align-items: flex-start; }
            .app-form-header p { text-align: left; }
        }

        /* Applicant Info: hinati sa 3 pahina (Name, Personal Details, Address) */
        .sub-pager { display: flex; gap: 8px; flex-wrap: wrap; margin: 4px 0 18px; }
        .sub-dot {
            display: inline-flex; align-items: center; gap: 6px;
            border: 1px solid #d1d5db; background: #fff; color: #4b5563;
            border-radius: 999px; padding: 5px 12px 5px 5px; font-size: 12.5px;
            font-family: inherit; cursor: pointer; transition: all 0.15s ease;
        }
        .sub-dot span {
            width: 20px; height: 20px; border-radius: 50%; background: #e5e7eb; color: #4b5563;
            display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;
        }
        .sub-dot.done span { background: #2e7d32; color: #fff; }
        .sub-dot.active { border-color: #f7941d; color: #1b4d2e; font-weight: 600; }
        .sub-dot.active span { background: #f7941d; color: #fff; }
        .sub-page { animation: subPageIn 0.25s ease-out; }
        @keyframes subPageIn {
            from { opacity: 0; transform: translateX(10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* Mas maliit na mga hakbang (1 Applicant Info - 2 Household - ...) */
        .app-steps { margin-bottom: 22px; }
        .app-steps .step-item { gap: 6px; font-size: 12.5px; }
        .app-steps .step-number { width: 24px; height: 24px; font-size: 11.5px; }
        .app-steps .step-item.current .step-number { box-shadow: 0 0 0 3px rgba(247, 148, 29, 0.2); }
        .app-steps .progress-line { height: 3px; margin: 0 10px; }
        @media (max-width: 640px) {
            .app-steps .step-number { width: 22px; height: 22px; font-size: 11px; }
            .app-steps .progress-line { margin: 0 5px; }
        }

        /* ===== Telepono: maayos na pagkakasunod-sunod at basa ===== */
        @media (max-width: 600px) {
            /* Hakbang 1-4: numero lang ang iba; ang kasalukuyang hakbang lang ang may pangalan,
               kaya hindi na lumalampas o napuputol sa gilid */
            .app-steps { justify-content: center; width: 100%; overflow: hidden; margin-bottom: 18px; }
            .app-steps .step-item { flex-shrink: 0; }
            .app-steps .step-item:not(.current) .step-label { display: none; }
            .app-steps .step-item.current .step-label { font-weight: 700; white-space: nowrap; }
            .app-steps .progress-line { flex: 1 1 18px; min-width: 14px; max-width: 46px; }

            /* Pamagat ng step at mga sub-page pill: iisang hanay */
            .form-step > h3 { font-size: 18px; margin-bottom: 12px; }
            .sub-pager { flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
            .sub-dot { flex-shrink: 0; white-space: nowrap; font-size: 12px; padding: 4px 10px 4px 4px; }
            .sub-dot span { width: 18px; height: 18px; font-size: 10.5px; }

            /* Mga tanong at sagot: may puwang sa pagitan, label sa itaas ng field */
            .form-step .form-grid { display: flex; flex-direction: column; gap: 14px; }
            .form-step .form-group { margin: 0 0 14px; }
            .form-step .form-grid > .form-group { margin-bottom: 0; }
            .form-step .form-group > label { display: block; font-size: 13.5px; line-height: 1.35; margin-bottom: 6px; }
            .form-step .form-group input:not([type="radio"]):not([type="checkbox"]):not([type="file"]),
            .form-step .form-group select,
            .form-step .form-group textarea { width: 100%; font-size: 16px; } /* 16px: walang auto-zoom sa iPhone */
            .form-step .choice-other-input { margin-top: 8px; }
            .form-step .radio-group { display: flex; gap: 22px; }
            .form-step .radio-group label { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; }
            .form-step textarea { min-height: 96px; }
            .form-step .field-hint { font-size: 12px; line-height: 1.45; margin: 4px 0 10px; }
            .form-step input[type="file"] { width: 100%; font-size: 13px; }

            /* Back / Next: magkasinlapad sa iisang hanay */
            /* Back: maliit; ang pangunahing button (Next / Submit): natitirang lapad, iisang linya */
            .form-actions.between { display: flex; gap: 10px; margin-top: 18px; }
            .form-actions.between > * { text-align: center; justify-content: center; white-space: nowrap; }
            .form-actions.between > .btn-back { flex: 0 0 auto; padding-left: 16px; padding-right: 16px; }
            .form-actions.between > :not(.btn-back) { flex: 1; min-width: 0; padding-left: 12px; padding-right: 12px; overflow: hidden; text-overflow: ellipsis; }
            .form-actions:not(.between) > * { width: 100%; text-align: center; }

            /* Mas malapad na lugar ng sagot: mas maliit na padding ng puting card */
            .preferences-form-container { padding: 20px 16px 22px; border-radius: 14px; }
            .app-form-header h2 { font-size: 22px; }
            .app-form-header p { font-size: 13.5px; }

            /* Maliliit na hakbang (Name / Personal Details / Address): laging iisang hanay,
               tatlong magkasinlapad na pill; bumababa sa 2 linya ang mahabang pangalan */
            .sub-pager { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
            .sub-dot {
                white-space: normal; text-align: left; line-height: 1.2; min-height: 34px; min-width: 0;
                padding: 4px 8px 4px 4px; font-size: 11.5px; border-radius: 12px;
            }
            .sub-dot span { flex-shrink: 0; }

            /* Mga green na paalala: mas siksik para hindi kumain ng espasyo */
            /* (mas tiyak na selector: nasa ibaba ng media query ang orihinal na .reuse-notice) */
            .preferences-form-container .reuse-notice { font-size: 12.5px; line-height: 1.45; padding: 9px 12px; margin-bottom: 10px; }
            .preferences-form-container .reuse-notice.rn-ready { padding-right: 38px; }
            .preferences-form-container .rn-close { top: 4px; right: 4px; }

            /* Next / Submit: buo ang nakasulat (hal. "Next: Barangay Certificate"), hindi napuputol */
            .form-actions.between > :not(.btn-back) { white-space: normal; overflow: visible; line-height: 1.25; padding-top: 10px; padding-bottom: 10px; }
        }

        .reuse-notice {
            background: #e7f5ea; color: #1b4d2e; border-radius: 10px;
            padding: 12px 14px; font-size: 13.5px; margin-bottom: 14px; line-height: 1.5;
        }
        /* Naisasara (×) at nabubuksan ulit ang mga green na paalala */
        .reuse-notice.rn-ready { position: relative; padding-right: 44px; }
        .rn-close {
            position: absolute; top: 8px; right: 8px; width: 28px; height: 28px;
            border: none; border-radius: 50%; background: transparent; color: #2e5b32;
            cursor: pointer; font-size: 14px; transition: background-color 0.15s ease;
        }
        .rn-close:hover { background: rgba(46, 91, 50, 0.12); }
        /* Iisang lugar ng mga paalala: bukas na paalala sa itaas, mga button sa ilalim */
        .rn-stack { display: flex; flex-direction: column; }
        .rn-pills { order: 99; display: flex; flex-wrap: wrap; gap: 8px; }
        .rn-pills:has(.rn-open:not([hidden])) { margin-bottom: 14px; }
        .rn-enter { animation: rnSlideDown 0.35s ease-out; }
        .rn-pop { animation: rnPop 0.3s ease-out; }
        @keyframes rnSlideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes rnPop {
            from { opacity: 0; transform: scale(0.85); }
            to { opacity: 1; transform: scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            .rn-enter, .rn-pop { animation: none; }
        }
        .rn-open {
            display: inline-flex; align-items: center; gap: 6px; margin: 0;
            padding: 6px 12px; border: 1px solid #b9dcc3; border-radius: 16px;
            background: #e7f5ea; color: #1b4d2e; font-size: 12.5px; font-weight: 600;
            font-family: inherit; cursor: pointer; transition: background-color 0.15s ease;
        }
        .rn-open:hover { background: #d6eedc; }
        .rn-open[hidden], .reuse-notice.rn-collapsed { display: none; }
        .reuse-file-note { display: block; margin-top: 6px; font-size: 12.5px; color: #2e5b32; }
        .reuse-file-note a { color: #1b4d2e; font-weight: 700; }

        /* ===== Residency ID (Barangay ID): harap at likod ===== */
        .rid-card {
            grid-column: 1 / -1; border: 1px solid #d7e5da; border-radius: 14px; background: #f7fbf8;
            padding: 16px; display: flex; flex-direction: column; gap: 14px;
        }
        .rid-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .rid-icon {
            width: 42px; height: 42px; border-radius: 11px; flex-shrink: 0; display: grid; place-items: center;
            background: linear-gradient(135deg, #1b4d2e, #3f8a55); color: #fff; font-size: 18px;
        }
        .rid-head-text { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 2px; }
        .rid-head-text strong { font-size: 15px; color: #1b4d2e; }
        .rid-head-text strong span { font-weight: 600; color: #4b6b55; }
        .rid-head-text small { font-size: 12.5px; color: #5b6b60; line-height: 1.45; }
        .rid-example-btn {
            border: 1px solid #2e5b32; background: #fff; color: #2e5b32; border-radius: 999px; padding: 8px 14px;
            font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            transition: background .15s ease, color .15s ease;
        }
        .rid-example-btn:hover, .rid-example-btn[aria-expanded="true"] { background: #2e5b32; color: #fff; }
        .rid-why {
            background: #fff8ec; border: 1px solid #f6d7a7; border-left: 4px solid #f39c12; border-radius: 10px;
        }
        .rid-why summary {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; list-style: none;
            user-select: none;
        }
        .rid-why summary::-webkit-details-marker { display: none; }
        .rid-why summary:focus-visible { outline: 2px solid #f39c12; outline-offset: 2px; border-radius: 8px; }
        .rid-why summary > .fa-circle-info { color: #e67e22; font-size: 17px; }
        .rid-why summary strong { flex: 1; font-size: 13.5px; color: #7a4a0c; }
        .rid-why-chev { color: #b7791f; font-size: 12px; transition: transform .2s ease; }
        .rid-why[open] .rid-why-chev { transform: rotate(180deg); }
        .rid-why-body { padding: 0 14px 12px 41px; animation: ridIn .2s ease; }
        .rid-why p { margin: 0; font-size: 12.5px; line-height: 1.5; color: #5b4630; }
        .rid-why .rid-why-privacy { margin-top: 6px; color: #2e5b32; font-weight: 600; }
        .rid-why .rid-why-privacy i { margin-right: 4px; }
        .rid-example { background: #fff; border: 1px dashed #b9d3c0; border-radius: 12px; padding: 14px; animation: ridIn .2s ease; }
        .rid-example[hidden] { display: none; }
        .rid-example-imgs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .rid-example-imgs figure { margin: 0; text-align: center; }
        .rid-example-imgs img { width: 100%; border-radius: 10px; box-shadow: 0 6px 16px rgba(0, 0, 0, .12); }
        .rid-example-imgs figcaption { margin-top: 6px; font-size: 12px; font-weight: 700; color: #2e5b32; text-transform: uppercase; letter-spacing: .5px; }
        .rid-tips { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 6px; font-size: 13px; color: #374151; }
        .rid-tips i { width: 18px; color: #2e7d32; }
        .rid-tips .fa-xmark { color: #c62828; }
        .rid-example-note { margin: 10px 0 0; font-size: 12px; color: #6b7280; font-style: italic; }
        .rid-slots { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .rid-slot { position: relative; margin: 0; }
        .rid-drop {
            position: relative; aspect-ratio: 1.586; border-radius: 12px; overflow: hidden; background: #fff;
            border: 2px dashed #b9d3c0; display: grid; place-items: center; cursor: pointer; transition: border-color .15s ease;
        }
        .rid-drop:hover { border-color: #2e5b32; }
        .rid-placeholder { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: .22; filter: grayscale(.3); }
        /* Napiling larawan: buong ID na nakikita sa loob ng parehong card */
        .rid-preview {
            position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain;
            padding: 8px; box-sizing: border-box; background: #f4f8f5;
        }
        .rid-preview[hidden] { display: none; }
        .rid-drop-label {
            position: relative; background: rgba(255, 255, 255, .92); color: #1b4d2e; font-weight: 700; font-size: 13px;
            padding: 7px 12px; border-radius: 999px; box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
            transition: all .2s ease;
        }
        .rid-done {
            position: absolute; top: 10px; right: 10px; width: 26px; height: 26px; border-radius: 50%;
            background: #2e7d32; color: #fff; font-size: 13px; display: none; align-items: center; justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
        }
        .rid-slot.has-file .rid-drop { border-color: #2e5b32; }
        .rid-slot.has-file .rid-placeholder { display: none; }
        .rid-slot.has-file .rid-drop-label { position: absolute; left: 10px; bottom: 10px; font-size: 12px; padding: 5px 10px; }
        .rid-slot.has-file .rid-done { display: flex; }
        .rid-slot.has-file .rid-drop:hover .rid-preview { filter: brightness(.97); }
        .rid-slot.is-error .rid-drop { border-color: #e53935; }
        .rid-slots[hidden] { display: none; }
        .rid-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .rid-btn {
            border: none; border-radius: 9px; padding: 10px 8px; font-size: 13px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: filter .15s ease;
        }
        .rid-btn:hover { filter: brightness(1.08); }
        .rid-camera { background: #2e5b32; color: #fff; }
        .rid-file { background: #e8f2ea; color: #1b4d2e; }
        /* Nakatago pero gumagana ang "required" na paalala ng browser */
        .rid-input { position: absolute; left: 50%; bottom: 40px; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .rid-status { min-height: 0; margin-top: 8px; font-size: 12.5px; font-weight: 600; display: flex; gap: 6px; align-items: flex-start; }
        .rid-status:empty { display: none; }
        .rid-status.checking { color: #475569; }
        .rid-status.ok { color: #2e7d32; }
        .rid-status.error { color: #c62828; background: #fdecea; border-radius: 8px; padding: 8px 10px; }
        .rid-status i { margin-top: 2px; }
        @keyframes ridIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
        @media (max-width: 640px) {
            .rid-slots, .rid-example-imgs { grid-template-columns: 1fr; }
            .rid-example-btn { width: 100%; justify-content: center; }

            /* Telepono: maayos at pare-parehong sukat ng text at button sa Residency ID */
            .rid-card { padding: 12px; gap: 12px; }
            .rid-head { gap: 10px; }
            .rid-icon { width: 34px; height: 34px; border-radius: 9px; font-size: 15px; }
            .rid-head-text { min-width: 0; }
            .rid-head-text strong { font-size: 14px; }
            .rid-head-text small { font-size: 12px; }
            .rid-example-btn { height: 38px; padding: 0 12px; font-size: 13px; border-radius: 10px; }

            .rid-why summary { gap: 8px; padding: 9px 12px; }
            .rid-why summary > .fa-circle-info { font-size: 15px; }
            .rid-why summary strong { font-size: 13px; line-height: 1.3; }
            .rid-why-body { padding: 0 12px 12px; }   /* dating 41px sa kaliwa: mas malapad na ang paliwanag */
            .rid-why p { font-size: 12.5px; line-height: 1.55; }

            .form-step .rid-slot > label { font-size: 13px; margin-bottom: 6px; }
            .rid-drop-label { font-size: 12px; padding: 6px 10px; }
            .rid-slot.has-file .rid-drop-label { font-size: 11px; padding: 4px 9px; left: 8px; bottom: 8px; }
            .rid-done { width: 24px; height: 24px; font-size: 12px; top: 8px; right: 8px; }
            .rid-actions { gap: 8px; margin-top: 8px; }
            .rid-btn { height: 40px; padding: 0 6px; font-size: 13px; border-radius: 10px; }
            .rid-status { font-size: 12px; }
        }

        /* ===== Camera: kuhanan ang ID sa loob ng frame ===== */
        .cam-overlay {
            position: fixed; inset: 0; z-index: 10060; display: flex; align-items: center; justify-content: center;
            padding: 16px; background: rgba(6, 12, 8, .72); backdrop-filter: blur(6px); animation: fvIn .2s ease;
        }
        .cam-overlay[hidden] { display: none; }
        .cam-box {
            width: min(720px, 100%); max-height: calc(100vh - 32px); background: #0f1712; border-radius: 18px; overflow: hidden;
            display: flex; flex-direction: column; box-shadow: 0 24px 70px rgba(0, 0, 0, .5);
        }
        .cam-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; color: #fff; }
        .cam-top strong { font-size: 15px; }
        .cam-close {
            width: 40px; height: 40px; border: none; border-radius: 50%; background: rgba(255, 255, 255, .95); color: #1f2937;
            font-size: 24px; line-height: 1; cursor: pointer;
        }
        .cam-stage { position: relative; container-type: size; aspect-ratio: 4 / 3; max-height: calc(100vh - 210px); background: #000; overflow: hidden; }
        .cam-stage video, .cam-stage .cam-shot { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .cam-stage .cam-shot { object-fit: contain; background: #000; }
        .cam-shot[hidden] { display: none; }
        .cam-frame {
            position: absolute; left: 50%; top: 46%; transform: translate(-50%, -50%);
            width: min(86cqw, 120cqh); aspect-ratio: 1.586; border-radius: 14px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, .5); pointer-events: none;
        }
        .cam-frame::before, .cam-frame::after { content: ""; position: absolute; inset: 0; border-radius: 14px; }
        .cam-frame::before { border: 2px solid rgba(255, 255, 255, .85); }
        .cam-stage.is-review .cam-frame, .cam-stage.is-review .cam-hint { display: none; }
        .cam-hint {
            position: absolute; left: 0; right: 0; bottom: 10px; margin: 0; text-align: center; color: #fff; font-size: 13px;
            padding: 0 16px; text-shadow: 0 1px 3px rgba(0, 0, 0, .8);
        }
        .cam-msg {
            position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px;
            padding: 24px; text-align: center; color: #f1f5f9; font-size: 14px; background: #0f1712;
        }
        .cam-msg[hidden] { display: none; }
        .cam-msg i { font-size: 34px; color: #f39c12; }
        .cam-controls { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 14px 16px 18px; }
        .cam-shutter {
            width: 66px; height: 66px; border-radius: 50%; border: 5px solid #fff; background: #f39c12; cursor: pointer;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, .35); transition: transform .12s ease;
        }
        .cam-shutter:active { transform: scale(.94); }
        .cam-shutter[hidden], .cam-review[hidden] { display: none; }
        .cam-review { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
        .cam-review button, .cam-msg button {
            border: none; border-radius: 10px; padding: 11px 18px; font-size: 14px; font-weight: 700; cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .cam-retake { background: rgba(255, 255, 255, .14); color: #fff; }
        .cam-use, .cam-msg button { background: #2e7d32; color: #fff; }
    </style>
</head>
<body style="background-color: #1b4d2e;">

    <div class="dashboard-container">

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="../Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

            <nav class="nav-menu">
                <a href="adopter_dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="adopter_preferences.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i> My preference</a>
                <a href="ai_recommendation.php" class="nav-item"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Recommendation</a>
                <a href="my_pets.php" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>

            <div class="sidebar-illustration">
                <img src="../Images/bg_design_pets.png" alt="Pets Illustration" onerror="this.style.display='none'">
            </div>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">

            <header class="top-navbar">
                <div class="top-nav-links">
                    <a href="search_pets.php">Search Pets</a>
                    <a href="shelter.php">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>
                <a href="edit_profile.php" class="user-profile-link" style="text-decoration: none; color: inherit;">
                    <div class="user-profile">
                        <div class="profile-avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            <?php if (!empty($profile_image)): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                            <?php endif; ?>
                        </div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </a>
            </header>

            <div class="preferences-form-container">

                <div class="form-header app-form-header">
                    <h2>Adoption Application</h2>
                    <p>Applying for: <strong><?php echo htmlspecialchars($pet['name']); ?></strong></p>
                </div>

                <?php if (!empty($error_message)): ?>
                    <div style="background:#ffebee; color:#c62828; padding:10px 15px; border-radius:6px; margin-bottom:15px;">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Step Progress Bar -->
                <div class="step-progress app-steps">
                    <div id="step1-indicator" class="step-item current">
                        <span class="step-number">1</span>
                        <span class="step-label">Applicant Info</span>
                    </div>
                    <div class="progress-line"><div id="progress-bar" class="progress-fill is-current"></div></div>
                    <div id="step2-indicator" class="step-item">
                        <span class="step-number">2</span>
                        <span class="step-label">Household</span>
                    </div>
                    <div class="progress-line"><div id="progress-bar2" class="progress-fill"></div></div>
                    <div id="step3-indicator" class="step-item">
                        <span class="step-number">3</span>
                        <span class="step-label">Photos & ID</span>
                    </div>
                    <div class="progress-line"><div id="progress-bar3" class="progress-fill"></div></div>
                    <div id="step4-indicator" class="step-item">
                        <span class="step-number">4</span>
                        <span class="step-label">Schedule</span>
                    </div>
                </div>

                <?php if ($previous): ?>
                <!-- Returning adopter: gagamitin ba ulit ang lumang details? -->
                <div id="reuseModal" class="reuse-backdrop" role="dialog" aria-modal="true" aria-labelledby="reuseTitle">
                    <div class="reuse-card">
                        <div class="reuse-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                        <h3 id="reuseTitle">Welcome back!</h3>
                        <p>You already <?php echo $previous['adopted'] ? 'adopted' : 'applied for'; ?> <strong><?php echo htmlspecialchars($previous['pet_name']); ?></strong> before. Do you want to use the details and documents from that application?</p>
                        <ul>
                            <li><i class="fa-solid fa-check"></i> Applicant Information</li>
                            <li><i class="fa-solid fa-check"></i> Household Questionnaire</li>
                            <li><i class="fa-solid fa-check"></i> Home Photos &amp; Residency ID</li>
                        </ul>
                        <p class="reuse-hint">You will only need to pick an interview schedule. You can still go back and change anything before you submit.</p>
                        <div class="reuse-actions">
                            <button type="button" class="btn-back" onclick="startFresh()">No, fill out a new form</button>
                            <button type="button" class="btn-next" onclick="usePrevious()">Yes, use my previous details</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <form action="process_adoption_application.php" method="POST" enctype="multipart/form-data" id="applicationForm">
                    <input type="hidden" name="pet_id" value="<?php echo htmlspecialchars($pet['pet_id']); ?>">
                    <input type="hidden" name="reuse_application_id" id="reuse_application_id" value="">

                    <!-- STEP 1: Applicant Info + Guardian -->
                    <div id="step-1" class="form-step">
                        <h3>Applicant Information</h3>
                        <?php // Pagination para hindi masikip: Pangalan -> Personal Details -> Address ?>
                        <div class="sub-pager" aria-label="Applicant Information pages">
                            <button type="button" class="sub-dot active" data-go="1"><span>1</span> Name</button>
                            <button type="button" class="sub-dot" data-go="2"><span>2</span> Personal Details</button>
                            <button type="button" class="sub-dot" data-go="3"><span>3</span> Address</button>
                        </div>

                        <div class="sub-page" data-page="1">
                        <div class="form-grid">
                            <?php // Buong pangalan: Surname, First, Middle - required; Suffix - optional ?>
                            <div class="form-group">
                                <label>Surname</label>
                                <input type="text" name="last_name" class="name-input" required maxlength="100" placeholder="e.g. Dela Cruz">
                            </div>
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" name="first_name" class="name-input" required maxlength="100" placeholder="e.g. Juan">
                            </div>
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input type="text" name="middle_name" class="name-input" required maxlength="100" placeholder="e.g. Santos">
                            </div>
                            <div class="form-group">
                                <label>Suffix <small>(optional)</small></label>
                                <select name="suffix">
                                    <option value="">None</option>
                                    <option value="Jr.">Jr.</option>
                                    <option value="Sr.">Sr.</option>
                                    <option value="II">II</option>
                                    <option value="III">III</option>
                                    <option value="IV">IV</option>
                                    <option value="V">V</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="button" onclick="subNext(1)" class="btn-next">Next: Personal Details &rarr;</button>
                        </div>
                        </div>

                        <div class="sub-page hidden" data-page="2">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" name="phone_number" id="phone_number" required placeholder="09XXXXXXXXX"
                                       inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" autocomplete="tel"
                                       title="11-digit mobile number starting with 09 (numbers only)">
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email_address" required value="<?php echo htmlspecialchars($user_email); ?>">
                            </div>
                            <div class="form-group">
                                <label>Birthdate</label>
                                <?php // 18 taong gulang pataas lang ang puwedeng mag-adopt ?>
                                <input type="date" name="birthdate" id="birthdate" required max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                                <small class="age-error" id="ageError" hidden><i class="fa-solid fa-circle-exclamation"></i> You must be at least 18 years old to adopt.</small>
                            </div>
                            <div class="form-group">
                                <label>Occupation</label>
                                <input type="text" name="occupation" required>
                            </div>
                            <div class="form-group">
                                <label>Civil Status</label>
                                <select name="civil_status" required>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                    <option value="Divorced">Divorced</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Social Media Link</label>
                                <input type="text" name="social_media_link" required placeholder="https://facebook.com/...">
                            </div>
                        </div>
                        <div class="form-actions between">
                            <button type="button" onclick="showSubPage(1)" class="btn-back">&larr; Back</button>
                            <button type="button" onclick="subNext(2)" class="btn-next">Next: Address &rarr;</button>
                        </div>
                        </div>

                        <div class="sub-page hidden" data-page="3">
                        <?php // Taga-Caloocan lang ang puwedeng mag-adopt. N/A kapag wala ang
                              // House/Lot No., Street o Subdivision sa tirahan. ?>
                        <p class="field-hint" style="margin-top:0;"><i class="fa-solid fa-location-dot"></i> City of Caloocan residents only.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>House/Lot Number</label>
                                <input type="text" name="house_no" id="house_no" required maxlength="10" inputmode="numeric" pattern="[0-9]+" title="Numbers only" placeholder="e.g. 25">
                            </div>
                            <div class="form-group">
                                <label>Street Name</label>
                                <input type="text" name="street" id="street" required maxlength="150" placeholder="e.g. Rizal St.">
                            </div>
                            <div class="form-group">
                                <label>Subdivision/Village/Building</label>
                                <input type="text" name="subdivision" class="na-input" required maxlength="150" placeholder="e.g. Camarin Village, or N/A">
                            </div>
                            <div class="form-group">
                                <label>Barangay</label>
                                <select name="barangay" required>
                                    <option value="">Select barangay</option>
                                    <?php for ($b = 1; $b <= 188; $b++): ?>
                                    <option value="Barangay <?php echo $b; ?>">Barangay <?php echo $b; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" value="City of Caloocan" readonly tabindex="-1" style="background:#f3f4f6; cursor:not-allowed;">
                            </div>
                        </div>
                        <p class="field-hint">House/Lot Number accepts numbers only. Type <strong>N/A</strong> if your address has no Subdivision/Village/Building.</p>

                        <div class="form-actions between">
                            <button type="button" onclick="showSubPage(2)" class="btn-back">&larr; Back</button>
                            <button type="button" onclick="nextStep(1)" class="btn-next">Next: Household &rarr;</button>
                        </div>
                        </div>
                    </div>

                    <!-- STEP 2: Household Questionnaire -->
                    <div id="step-2" class="form-step hidden">
                        <h3>Household Questionnaire</h3>
                        <div class="form-group full-width">
                            <label>Who will be responsible for the pet's daily care?</label>
                            <select class="choice-select" data-target="q_responsible_for_care" required>
                                <option value="">Select an answer</option>
                                <option value="Myself">Myself</option>
                                <option value="Spouse or Partner">Spouse or Partner</option>
                                <option value="Parent">Parent</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Other Family Member">Other Family Member</option>
                                <option value="Roommate">Roommate</option>
                                <option value="Other">Other (please specify)</option>
                            </select>
                            <input type="text" class="choice-other-input hidden" data-target="q_responsible_for_care" placeholder="Please specify">
                            <input type="hidden" name="q_responsible_for_care" id="hidden_q_responsible_for_care">
                        </div>
                        <div class="form-group full-width">
                            <label>Who will be financially responsible for the pet?</label>
                            <select class="choice-select" data-target="q_financial_responsibility" required>
                                <option value="">Select an answer</option>
                                <option value="Myself">Myself</option>
                                <option value="Spouse or Partner">Spouse or Partner</option>
                                <option value="Shared with Family">Shared with Family</option>
                                <option value="Parent">Parent</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Other Family Member">Other Family Member</option>
                                <option value="Other">Other (please specify)</option>
                            </select>
                            <input type="text" class="choice-other-input hidden" data-target="q_financial_responsibility" placeholder="Please specify">
                            <input type="hidden" name="q_financial_responsibility" id="hidden_q_financial_responsibility">
                        </div>
                        <div class="form-group full-width">
                            <label>Who will care for the pet in case of an emergency?</label>
                            <select class="choice-select" data-target="q_emergency_caregiver" required>
                                <option value="">Select an answer</option>
                                <option value="Myself">Myself</option>
                                <option value="Spouse or Partner">Spouse or Partner</option>
                                <option value="Parent">Parent</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Other Family Member">Other Family Member</option>
                                <option value="Neighbor">Neighbor</option>
                                <option value="Roommate">Roommate</option>
                                <option value="Other">Other (please specify)</option>
                            </select>
                            <input type="text" class="choice-other-input hidden" data-target="q_emergency_caregiver" placeholder="Please specify">
                            <input type="hidden" name="q_emergency_caregiver" id="hidden_q_emergency_caregiver">
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Hours the pet will be left alone per day</label>
                                <input type="number" name="q_hours_left_alone" min="0" max="24" required>
                            </div>
                            <div class="form-group">
                                <label>Do you currently have other pets?</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="q_current_pets" value="Yes" required> Yes</label>
                                    <label><input type="radio" name="q_current_pets" value="No"> No</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Have you owned a pet before?</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="q_past_pet_ownership" value="Yes" required> Yes</label>
                                    <label><input type="radio" name="q_past_pet_ownership" value="No"> No</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Does your family support this adoption?</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="q_family_support" value="Yes" required> Yes</label>
                                    <label><input type="radio" name="q_family_support" value="No"> No</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label>How will you introduce the pet to your home/other pets?</label>
                            <textarea name="q_introduction_strategy" required></textarea>
                        </div>

                        <div class="form-actions between">
                            <button type="button" onclick="prevStep(2)" class="btn-back">&larr; Back</button>
                            <button type="button" onclick="nextStep(2)" class="btn-next">Next: Photos & ID &rarr;</button>
                        </div>
                    </div>

                    <!-- STEP 3: Home Photos + Residency (Barangay) ID -->
                    <div id="step-3" class="form-step hidden">
                        <h3>Home Photos & Residency ID</h3>
                        <?php // Pagination para hindi masikip: Home Photos -> Residency ID -> Barangay Certificate ?>
                        <div class="sub-pager" aria-label="Photos and ID pages">
                            <button type="button" class="sub-dot active" data-go="1"><span>1</span> Home Photos</button>
                            <button type="button" class="sub-dot" data-go="2"><span>2</span> Residency ID</button>
                            <button type="button" class="sub-dot" data-go="3"><span>3</span> Barangay Certificate</button>
                        </div>

                        <div class="sub-page" data-page="1">
                        <p class="field-hint" style="margin-top:0;">Kailangan ang mga larawan na ito para ma-verify ang kondisyon ng iyong tahanan.</p>
                        <p class="field-hint">Maximum of 10MB per photo. Make sure each photo is clear and not blurry.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Front of House</label>
                                <input type="file" name="photo_front_house" accept="image/*" required>
                            </div>
                            <div class="form-group">
                                <label>Living Room</label>
                                <input type="file" name="photo_living_room" accept="image/*" required>
                            </div>
                            <?php // Optional: kung papayagan ng adopter na matulog/umakyat ang pet sa kama ?>
                            <div class="form-group">
                                <label>Bedroom <small>(optional)</small></label>
                                <input type="file" name="photo_bedrooms" accept="image/*">
                                <small class="field-hint">Upload only if you will allow the pet on your bed.</small>
                            </div>
                            <?php // Wala na ang Street View, Dining Area, Kitchen, Windows at Backyard ?>
                        </div>
                        <div class="form-actions between">
                            <button type="button" onclick="prevStep(3)" class="btn-back">&larr; Back</button>
                            <button type="button" onclick="subNext(1, 3)" class="btn-next">Next: Residency ID &rarr;</button>
                        </div>
                        </div>

                        <div class="sub-page hidden" data-page="2">
                            <?php // Residency ID (Barangay ID): harap at likod, kuha sa camera o file ?>
                            <div class="rid-card">
                                <div class="rid-head">
                                    <span class="rid-icon"><i class="fa-solid fa-id-card"></i></span>
                                    <div class="rid-head-text">
                                        <strong>Residency ID <span>(Barangay ID)</span></strong>
                                        <small>Upload a clear photo of the <b>front</b> and <b>back</b> of your Barangay ID issued in the City of Caloocan.</small>
                                    </div>
                                    <button type="button" class="rid-example-btn" id="ridExampleBtn" aria-expanded="false" aria-controls="ridExample">
                                        <i class="fa-regular fa-eye"></i> See example
                                    </button>
                                </div>

                                <?php // Nakasara muna; pindutin para makita ang paliwanag ?>
                                <details class="rid-why">
                                    <summary>
                                        <i class="fa-solid fa-circle-info"></i>
                                        <strong>Why do we need your Residency ID?</strong>
                                        <i class="fa-solid fa-chevron-down rid-why-chev" aria-hidden="true"></i>
                                    </summary>
                                    <div class="rid-why-body">
                                        <p>Only residents of the <b>City of Caloocan</b> can adopt from the City Veterinary Office. Your Barangay ID shows that you really live in Caloocan and that the name and address in your application are true.</p>
                                        <p class="rid-why-privacy"><i class="fa-solid fa-lock"></i> Your ID is used only to check your application and is seen only by authorized staff.</p>
                                    </div>
                                </details>

                                <div class="rid-example" id="ridExample" hidden>
                                    <div class="rid-example-imgs">
                                        <figure><img src="../Images/sample_barangay_id_front.svg" alt="Sample Barangay ID, front"><figcaption>Front</figcaption></figure>
                                        <figure><img src="../Images/sample_barangay_id_back.svg" alt="Sample Barangay ID, back"><figcaption>Back</figcaption></figure>
                                    </div>
                                    <ul class="rid-tips">
                                        <li><i class="fa-solid fa-check"></i> Use your original Barangay ID, not a photocopy or screenshot.</li>
                                        <li><i class="fa-solid fa-check"></i> Show all four corners of the card.</li>
                                        <li><i class="fa-solid fa-check"></i> Make sure the text is sharp and readable, with no glare.</li>
                                        <li><i class="fa-solid fa-xmark"></i> Blurry photos and other kinds of ID are not accepted.</li>
                                    </ul>
                                    <p class="rid-example-note">This is only a sample. The name and details are not real.</p>
                                </div>

                                <div class="rid-slots">
                                    <?php foreach (['valid_id' => ['front', 'Front of ID'], 'valid_id_back' => ['back', 'Back of ID']] as $ridName => [$ridSide, $ridLabel]): ?>
                                    <div class="form-group rid-slot" data-side="<?php echo $ridSide; ?>">
                                        <label><?php echo $ridLabel; ?></label>
                                        <div class="rid-drop">
                                            <img class="rid-placeholder" src="../Images/sample_barangay_id_<?php echo $ridSide; ?>.svg" alt="" aria-hidden="true">
                                            <img class="rid-preview" alt="<?php echo $ridLabel; ?> preview" hidden>
                                            <span class="rid-drop-label"><i class="fa-solid fa-<?php echo $ridSide === 'front' ? 'id-card' : 'id-card-clip'; ?>"></i> <?php echo ucfirst($ridSide); ?> side</span>
                                            <span class="rid-done" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                                        </div>
                                        <div class="rid-actions">
                                            <button type="button" class="rid-btn rid-camera"><i class="fa-solid fa-camera"></i> Take Photo</button>
                                            <button type="button" class="rid-btn rid-file"><i class="fa-solid fa-upload"></i> Upload File</button>
                                        </div>
                                        <input type="file" class="rid-input" name="<?php echo $ridName; ?>" accept="image/jpeg,image/png" required>
                                        <div class="rid-status" aria-live="polite"></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <div class="form-actions between">
                            <button type="button" onclick="showSubPage(1, 3)" class="btn-back">&larr; Back</button>
                            <button type="button" onclick="subNext(2, 3)" class="btn-next">Next: Barangay Certificate &rarr;</button>
                        </div>
                        </div>

                        <div class="sub-page hidden" data-page="3">
                        <p class="field-hint" style="margin-top:0;"><i class="fa-solid fa-location-dot"></i> Proof that you live in the City of Caloocan.</p>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Barangay Certificate</label>
                                <input type="file" name="barangay_certificate" accept="image/*" required>
                                <small class="field-hint">Upload a clear photo of your Barangay Certificate of Residency.</small>
                            </div>
                        </div>

                        <div class="form-actions between">
                            <button type="button" onclick="showSubPage(2, 3)" class="btn-back">&larr; Back</button>
                            <button type="button" onclick="nextStep(3)" class="btn-next">Next: Schedule &rarr;</button>
                        </div>
                        </div>
                    </div>

                    <!-- STEP 4: Interview Scheduling -->
                    <div id="step-4" class="form-step hidden">
                        <h3>Interview Scheduling</h3>
                        <div id="reuseNotice" class="reuse-notice hidden">
                            <i class="fa-solid fa-circle-info"></i>
                            Your previous details are filled in. Press <strong>Back</strong> to review or change them, or just pick a schedule below and submit.
                        </div>
                        <p class="field-hint">Tap the calendar icon, choose a day from Monday to Friday, then enter the time that works for you.</p>
                        <?php include __DIR__ . '/_interview_picker.php'; ?>

                        <div class="form-actions between">
                            <button type="button" onclick="prevStep(4)" class="btn-back">&larr; Back</button>
                            <button type="submit" class="btn-save" id="submitAppBtn">Submit Application</button>
                        </div>
                    </div>

                </form>
            </div>

        </main>
    </div>

    <!-- Camera para sa Barangay ID: kuhanan sa loob ng frame -->
    <div class="cam-overlay" id="ridCamera" role="dialog" aria-modal="true" aria-labelledby="camTitle" hidden>
        <div class="cam-box">
            <div class="cam-top">
                <strong id="camTitle">Front of your Barangay ID</strong>
                <button type="button" class="cam-close" title="Close (Esc)" aria-label="Close">&times;</button>
            </div>
            <div class="cam-stage">
                <video playsinline muted autoplay></video>
                <img class="cam-shot" alt="Captured ID photo" hidden>
                <div class="cam-frame" aria-hidden="true"></div>
                <p class="cam-hint"></p>
                <div class="cam-msg" hidden>
                    <i class="fa-solid fa-video-slash"></i>
                    <p></p>
                    <button type="button"><i class="fa-solid fa-upload"></i> Upload a photo instead</button>
                </div>
            </div>
            <div class="cam-controls">
                <button type="button" class="cam-shutter" aria-label="Take photo"></button>
                <div class="cam-review" hidden>
                    <button type="button" class="cam-retake"><i class="fa-solid fa-rotate-left"></i> Retake</button>
                    <button type="button" class="cam-use"><i class="fa-solid fa-check"></i> Use this photo</button>
                </div>
            </div>
        </div>
    </div>

    <script src="interview_picker.js?v=<?php echo @filemtime(__DIR__ . '/interview_picker.js'); ?>"></script>
    <script>
        // Pangalan: letra, espasyo, tuldok, gitling at kudlit lang (hal. "Dela Cruz", "O'Neil", "Ma. Luisa")
        // Hindi puwede ang numero o simbolo: tinatanggal agad habang nagta-type o nagpa-paste.
        // May pattern din ang field, kaya kahit galing sa draft ang numero, hindi makakapag-Next.
        document.querySelectorAll('.name-input').forEach(function (el) {
            el.setAttribute('pattern', "\\p{L}[\\p{L} .'\\-]*");
            el.setAttribute('title', 'Letters only (no numbers or symbols)');
            function check() {
                const ok = el.value.trim() === '' || /^[\p{L}][\p{L} .'\-]*$/u.test(el.value.trim());
                const msg = 'Please use letters only. Numbers and symbols are not allowed in a name.';
                el.setCustomValidity(ok ? '' : (window.spT ? window.spT(msg) : msg));
            }
            el.addEventListener('input', function () {
                const clean = el.value.replace(/[^\p{L} .'\-]+/gu, '');
                if (clean !== el.value) {
                    const pos = el.selectionStart - (el.value.length - clean.length);
                    el.value = clean;
                    try { el.setSelectionRange(pos, pos); } catch (e) {}
                }
                check();
            });
            el.addEventListener('change', check);
            el.addEventListener('blur', check);
        });
        // Subdivision: ang "na", "n/a", "N.A." ay ginagawang "N/A"
        document.querySelectorAll('.na-input').forEach(function (el) {
            el.addEventListener('blur', function () {
                if (/^n\.?\s*\/?\s*a\.?$/i.test(el.value.trim())) el.value = 'N/A';
            });
        });
        // Phone Number: numero lang, 11 digits na nagsisimula sa 09 (hal. 09171234567).
        // Tinatanggal agad ang letra, espasyo at simbolo; ang "+63 917..." ay ginagawang "0917...".
        (function () {
            const phone = document.getElementById('phone_number');
            function check() {
                const ok = phone.value === '' || /^09\d{9}$/.test(phone.value);
                const msg = 'Please enter an 11-digit mobile number starting with 09 (numbers only).';
                phone.setCustomValidity(ok ? '' : (window.spT ? window.spT(msg) : msg));
            }
            phone.addEventListener('input', function () {
                let digits = phone.value.replace(/\D+/g, '');
                if (/^639\d*$/.test(digits)) digits = '0' + digits.slice(2); // +63 9XX -> 09XX
                digits = digits.slice(0, 11);
                if (digits !== phone.value) phone.value = digits;
                check();
            });
            phone.addEventListener('change', check);
            phone.addEventListener('blur', check);
        })();
        // House/Lot Number: numero lang - tinatanggal agad ang hindi numero
        document.getElementById('house_no').addEventListener('input', function () {
            const digits = this.value.replace(/\D+/g, '');
            if (digits !== this.value) this.value = digits;
        });
        // Street Name: bawal ang N/A
        document.getElementById('street').addEventListener('input', function () {
            const isNa = /^n\.?\s*\/?\s*a\.?$/i.test(this.value.trim());
            const msg = 'Please enter your street name. N/A is not allowed.';
            this.setCustomValidity(isNa ? (window.spT ? window.spT(msg) : msg) : '');
        });

        const totalSteps = 4;

        // Household questionnaire "who" questions: dropdown + "Other (please specify)" fallback
        document.querySelectorAll('.choice-select').forEach(function (select) {
            const target = select.dataset.target;
            const otherInput = document.querySelector('.choice-other-input[data-target="' + target + '"]');
            const hiddenInput = document.getElementById('hidden_' + target);

            function sync() {
                if (select.value === 'Other') {
                    otherInput.classList.remove('hidden');
                    otherInput.required = true;
                    hiddenInput.value = otherInput.value;
                } else {
                    otherInput.classList.add('hidden');
                    otherInput.required = false;
                    otherInput.value = '';
                    hiddenInput.value = select.value;
                }
            }

            select.addEventListener('change', sync);
            otherInput.addEventListener('input', sync);
            sync();
        });

        // Kulay ng mga hakbang: orange = sinasagutan pa (current),
        // green = tapos na (active) - nagiging green lang pagkalipat sa susunod
        function updateProgress(current) {
            for (let i = 1; i < totalSteps; i++) {
                const bar = document.getElementById('progress-bar' + (i === 1 ? '' : i));
                if (!bar) continue;
                bar.style.width = (current > i) ? '100%' : (current === i ? '50%' : '0%');
                bar.classList.toggle('is-current', current === i);
            }
            for (let i = 1; i <= totalSteps; i++) {
                const indicator = document.getElementById('step' + i + '-indicator');
                if (!indicator) continue;
                indicator.classList.toggle('active', i < current);
                indicator.classList.toggle('current', i === current);
            }
        }

        let currentStep = 1;
        updateProgress(1); // tamang kulay/haba ng mga linya kahit bago pa gumalaw

        function showStep(step) {
            // Animation: papasok mula kanan kapag Next, mula kaliwa kapag Back
            const dir = step >= currentStep ? 'step-in-next' : 'step-in-back';
            for (let i = 1; i <= totalSteps; i++) {
                const el = document.getElementById('step-' + i);
                el.classList.toggle('hidden', i !== step);
                el.classList.remove('step-in-next', 'step-in-back');
                if (i === step && step !== currentStep) {
                    void el.offsetWidth; // i-restart ang animation
                    el.classList.add(dir);
                }
            }
            updateProgress(step);
            currentStep = step;
            if (window.AppDraft) window.AppDraft.save();
        }

        function nextStep(current) {
            const currentStep = document.getElementById('step-' + current);
            const inputs = currentStep.querySelectorAll('input, textarea, select');
            for (const input of inputs) {
                if (!input.reportValidity()) return;
            }
            showStep(current + 1);
        }

        function prevStep(current) {
            showStep(current - 1);
        }

        // ===== Maliliit na pahina sa loob ng step =====
        // Applicant Info (step 1): Name -> Personal Details -> Address
        // Photos & ID (step 3): Home Photos -> Residency ID -> Barangay Certificate
        const currentSubPages = {};
        function showSubPage(page, step) {
            step = step || 1;
            const root = document.getElementById('step-' + step);
            const prev = currentSubPages[step] || 1;
            root.querySelectorAll('.sub-page').forEach(function (el) {
                el.classList.toggle('hidden', parseInt(el.dataset.page, 10) !== page);
            });
            root.querySelectorAll('.sub-dot').forEach(function (dot) {
                const n = parseInt(dot.dataset.go, 10);
                dot.classList.toggle('active', n === page);
                dot.classList.toggle('done', n < page);
            });
            currentSubPages[step] = page;
            // Kapag lumipat ng pahina, ibalik sa itaas ng form para makita ang simula
            if (page !== prev) {
                const top = root.getBoundingClientRect().top;
                if (top < 0) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Pumasa muna ang mga field sa pahinang ito bago lumipat
        function subPageValid(page, step) {
            const inputs = document.querySelectorAll('#step-' + (step || 1) + ' .sub-page[data-page="' + page + '"] :is(input, select, textarea)');
            for (const input of inputs) {
                if (!input.reportValidity()) return false;
            }
            return true;
        }

        function subNext(page, step) {
            if (subPageValid(page, step)) showSubPage(page + 1, step);
        }

        // Pindot sa numero: pabalik ay malaya; pasulong ay kailangang pumasa ang mga naunang pahina
        document.querySelectorAll('.form-step .sub-dot').forEach(function (dot) {
            const step = parseInt(dot.closest('.form-step').id.replace('step-', ''), 10);
            dot.addEventListener('click', function () {
                const target = parseInt(dot.dataset.go, 10);
                for (let p = currentSubPages[step] || 1; p < target; p++) {
                    if (!subPageValid(p, step)) return;
                    showSubPage(p + 1, step);
                }
                showSubPage(target, step);
            });
        });

        // 18 taong gulang pataas lang ang puwedeng mag-adopt: kapag mas bata,
        // may pulang paalala at hindi makakapagpatuloy (setCustomValidity)
        function computeAge(birthdateStr) {
            const bd = new Date(birthdateStr);
            const today = new Date();
            let age = today.getFullYear() - bd.getFullYear();
            const m = today.getMonth() - bd.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < bd.getDate())) age--;
            return age;
        }

        function checkAdopterAge() {
            const input = document.getElementById('birthdate');
            const ageError = document.getElementById('ageError');
            const tooYoung = input.value !== '' && computeAge(input.value) < 18;
            const msg = 'You must be at least 18 years old to adopt.';
            input.setCustomValidity(tooYoung ? (window.spT ? window.spT(msg) : msg) : '');
            ageError.hidden = !tooYoung;
            input.classList.toggle('is-invalid', tooYoung);
        }
        document.getElementById('birthdate').addEventListener('change', checkAdopterAge);
        document.getElementById('birthdate').addEventListener('input', checkAdopterAge);

        // ===== Returning adopter: gamitin ulit ang lumang details =====
        // Pinupunan lang ang mga field; mananatili ang laman hangga't hindi
        // pinapalitan ng adopter. Ang mga larawan at ID na walang bagong file
        // ay kukunin ng server sa dating application.
        const previousApp = <?php echo json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        // Green na paalala: may × para isara. Ang mga sarado ay nagiging maliit na
        // button na magkakatabi sa iisang hanay sa itaas ng form; pindutin para buksan
        // ulit. Kapag may binuksan, bumababa nang dahan-dahan ang ibang button sa ilalim
        // ng paragraph. Naaalala kung sarado (sa tab na ito).
        function noticeStack() {
            let stack = document.getElementById('rnStack');
            if (!stack) {
                stack = document.createElement('div');
                stack.id = 'rnStack';
                stack.className = 'rn-stack';
                stack.innerHTML = '<div class="rn-pills"></div>';
                document.getElementById('applicationForm').insertAdjacentElement('beforebegin', stack);
            }
            return stack;
        }

        // FLIP: tandaan ang puwesto ng mga button, baguhin ang layout, saka i-animate
        // ang paglipat mula sa dating puwesto papunta sa bago
        function animatePills(change) {
            const pills = Array.prototype.slice.call(document.querySelectorAll('#rnStack .rn-open:not([hidden])'));
            const before = new Map(pills.map(function (p) { return [p, p.getBoundingClientRect()]; }));
            change();
            document.querySelectorAll('#rnStack .rn-open:not([hidden])').forEach(function (p) {
                const old = before.get(p);
                if (!old) {
                    p.classList.remove('rn-pop');
                    void p.offsetWidth;
                    p.classList.add('rn-pop'); // bagong labas na button
                    return;
                }
                const now = p.getBoundingClientRect();
                const dx = old.left - now.left, dy = old.top - now.top;
                if (!dx && !dy) return;
                p.style.transition = 'none';
                p.style.transform = 'translate(' + dx + 'px, ' + dy + 'px)';
                void p.offsetWidth;
                p.style.transition = 'transform 0.4s cubic-bezier(.2, .8, .2, 1)';
                p.style.transform = '';
            });
        }

        function makeNoticeCollapsible(notice, label, storeKey, order) {
            if (!notice || notice.classList.contains('rn-ready')) return;
            notice.classList.add('rn-ready');

            // Lahat ng paalala at button ay nasa iisang lugar sa itaas ng form
            const stack = noticeStack();
            const pills = stack.querySelector('.rn-pills');
            notice.style.order = order;
            stack.insertBefore(notice, pills);

            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'rn-close';
            close.setAttribute('aria-label', 'Close');
            close.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            notice.appendChild(close);

            const open = document.createElement('button');
            open.type = 'button';
            open.className = 'rn-open';
            open.style.order = order;
            open.hidden = true;
            open.innerHTML = notice.querySelector('i') ? notice.querySelector('i').outerHTML + ' ' + label : label;
            pills.appendChild(open);

            function setCollapsed(collapsed, animate) {
                const apply = function () {
                    notice.classList.toggle('rn-collapsed', collapsed);
                    open.hidden = !collapsed;
                };
                if (animate) {
                    animatePills(apply);
                    if (!collapsed) {
                        notice.classList.remove('rn-enter');
                        void notice.offsetWidth;
                        notice.classList.add('rn-enter');
                    }
                } else {
                    apply();
                }
                try { collapsed ? sessionStorage.setItem(storeKey, '1') : sessionStorage.removeItem(storeKey); } catch (e) {}
            }
            close.addEventListener('click', function () { setCollapsed(true, true); });
            open.addEventListener('click', function () { setCollapsed(false, true); });

            let wasClosed = false;
            try { wasClosed = sessionStorage.getItem(storeKey) === '1'; } catch (e) {}
            if (wasClosed) setCollapsed(true, false);
        }

        function closeReuseModal() {
            const modal = document.getElementById('reuseModal');
            if (modal) modal.classList.add('hidden');
        }

        function startFresh() {
            document.getElementById('reuse_application_id').value = '';
            closeReuseModal();
            showStep(1);
        }

        function usePrevious() {
            if (!previousApp) return startFresh();
            const form = document.getElementById('applicationForm');
            const fields = previousApp.fields || {};

            Object.keys(fields).forEach(function (name) {
                const value = fields[name];
                if (value === '' || value === null) return;

                // Mga "who" na tanong: dropdown + "Other (please specify)"
                const choice = document.querySelector('.choice-select[data-target="' + name + '"]');
                if (choice) {
                    const hasOption = Array.prototype.some.call(choice.options, function (o) { return o.value === value; });
                    const other = document.querySelector('.choice-other-input[data-target="' + name + '"]');
                    choice.value = hasOption ? value : 'Other';
                    if (!hasOption && other) other.value = value;
                    choice.dispatchEvent(new Event('change'));
                    return;
                }

                const el = form.elements[name];
                if (!el) return;
                el.value = value; // gumagana rin sa radio group (RadioNodeList)
                if (name === 'birthdate') el.dispatchEvent(new Event('change'));
                if (name === 'phone_number') el.dispatchEvent(new Event('input')); // linisin (hal. "+63 917...")
            });

            // Mga larawan at ID: hindi na required, may link para makita ang luma
            Object.keys(previousApp.files || {}).forEach(function (inputName) {
                const input = form.querySelector('input[type="file"][name="' + inputName + '"]');
                if (!input) return;
                input.required = false;
                // Naidagdag na (hal. galing sa draft at sa pop-up): huwag nang ulitin
                if (input.parentNode.querySelector('.reuse-file-note')) return;
                const note = document.createElement('span');
                note.className = 'reuse-file-note';
                const fileUrl = '../uploads/' + encodeURIComponent(previousApp.files[inputName]);
                note.innerHTML = '<i class="fa-solid fa-check"></i> Using your previous file (<a href="' + fileUrl
                    + '">view</a>). Choose a new file only if you want to replace it.';
                // Bubukas sa pop-up na may × (hindi na sa bagong tab na walang paraan isara)
                const group = input.closest('.form-group');
                const labelEl = group ? group.querySelector('label') : null;
                const title = labelEl ? labelEl.childNodes[0].textContent.trim() : 'Previous file';
                note.querySelector('a').addEventListener('click', function (e) {
                    e.preventDefault();
                    openFileViewer(fileUrl, title);
                });
                input.insertAdjacentElement('afterend', note);
                input.addEventListener('change', function () {
                    note.style.display = input.files.length ? 'none' : '';
                });
                if (input.classList.contains('rid-input') && window.RidUI) window.RidUI.showPrevious(input, fileUrl);
            });

            document.getElementById('reuse_application_id').value = previousApp.application_id;
            const reuseNotice = document.getElementById('reuseNotice');
            reuseNotice.classList.remove('hidden');
            makeNoticeCollapsible(reuseNotice, 'Using your previous details', 'sp_rn_reuse', 2);
            closeReuseModal();
            showStep(4);
        }

        // Kapag may kulang sa ibang step (hal. blangko sa lumang application),
        // ipakita ang step na iyon imbes na tahimik na ayaw mag-submit
        // Isang beses lang bawat pag-submit: sabay-sabay nagpapadala ng "invalid" ang lahat
        // ng kulang na field, kaya ang UNA lang ang ipapakita (iwas glitch sa paglipat-lipat)
        let invalidShown = false;
        document.getElementById('applicationForm').addEventListener('invalid', function (e) {
            if (invalidShown) return;
            invalidShown = true;
            setTimeout(function () { invalidShown = false; }, 400);
            const step = e.target.closest('.form-step');
            const subPage = e.target.closest('.sub-page');
            const stepHidden = step && step.classList.contains('hidden');
            const subHidden = subPage && subPage.classList.contains('hidden');
            if (stepHidden) showStep(parseInt(step.id.replace('step-', ''), 10));
            if (subHidden) showSubPage(parseInt(subPage.dataset.page, 10), parseInt(step.id.replace('step-', ''), 10));
            if (stepHidden || subHidden) {
                setTimeout(function () { e.target.reportValidity(); }, 0);
            }
        }, true);

        // ===== Larawan at ID: hanggang 10MB lang, at bawal ang malabo =====
        // Sinusukat ang linaw gamit ang "variance of Laplacian": mababa ang value
        // kapag malabo ang larawan (kaunti ang matatalas na gilid). Kapag hindi
        // pumasa, binubura ang napiling file para kailangang mag-upload ulit.
        const PHOTO_MAX_BYTES = 10 * 1024 * 1024;
        const BLUR_THRESHOLD = 60; // taasan kung may nakakalusot pang malabo
        let photoChecksPending = 0;

        function measureSharpness(file) {
            return new Promise(function (resolve) {
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = function () {
                    URL.revokeObjectURL(url);
                    // Pinapaliit para pare-pareho ang sukat at mabilis ang pag-compute
                    const scale = Math.min(1, 600 / Math.max(img.naturalWidth, img.naturalHeight));
                    const w = Math.max(3, Math.round(img.naturalWidth * scale));
                    const h = Math.max(3, Math.round(img.naturalHeight * scale));
                    const canvas = document.createElement('canvas');
                    canvas.width = w;
                    canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, w, h);
                    const px = ctx.getImageData(0, 0, w, h).data;

                    const gray = new Float32Array(w * h);
                    for (let i = 0; i < w * h; i++) {
                        gray[i] = 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2];
                    }

                    let sum = 0, sumSq = 0, n = 0;
                    for (let y = 1; y < h - 1; y++) {
                        for (let x = 1; x < w - 1; x++) {
                            const i = y * w + x;
                            const lap = gray[i - w] + gray[i + w] + gray[i - 1] + gray[i + 1] - 4 * gray[i];
                            sum += lap;
                            sumSq += lap * lap;
                            n++;
                        }
                    }
                    const mean = sum / n;
                    resolve(sumSq / n - mean * mean);
                };
                img.onerror = function () {
                    URL.revokeObjectURL(url);
                    resolve(null); // hindi mabasa ng browser (hal. HEIC) - ang server na ang bahala
                };
                img.src = url;
            });
        }

        // Bagong mas maliit na JPG kapag lampas 1.5MB, o null kung hindi kailangan / hindi kaya
        function shrinkPhoto(file) {
            return new Promise(function (resolve) {
                if (file.size <= 1.5 * 1024 * 1024) return resolve(null);
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = function () {
                    URL.revokeObjectURL(url);
                    const scale = Math.min(1, 1800 / Math.max(img.naturalWidth, img.naturalHeight));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(img.naturalWidth * scale);
                    canvas.height = Math.round(img.naturalHeight * scale);
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function (blob) {
                        if (!blob || blob.size >= file.size) return resolve(null);
                        const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                        resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
                    }, 'image/jpeg', 0.85);
                };
                img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
                img.src = url;
            });
        }

        function showPhotoError(input, message) {
            let box = input.parentNode.querySelector('.photo-error');
            if (!message) {
                if (box) box.remove();
                return;
            }
            if (!box) {
                box = document.createElement('span');
                box.className = 'photo-error';
                box.style.cssText = 'display:block; color:#c5221f; font-size:13px; margin-top:6px;';
                input.insertAdjacentElement('afterend', box);
            }
            box.textContent = message;
        }

        function rejectPhoto(input, message) {
            showPhotoError(input, message);
            input.value = '';
            input.dispatchEvent(new Event('change')); // para mabura rin sa naka-save na draft
        }

        // (Ang Barangay ID ay may sariling pagsuri sa ibaba - RidUI)
        document.querySelectorAll('#step-3 input[type="file"]:not(.rid-input)').forEach(function (input) {
            input.addEventListener('change', function () {
                const file = input.files[0];
                if (!file) return; // iniwan ang error message mula sa rejectPhoto
                showPhotoError(input, '');

                if (file.size > PHOTO_MAX_BYTES) {
                    rejectPhoto(input, 'This photo is larger than 10MB. Please upload a smaller photo.');
                    return;
                }

                photoChecksPending++;
                measureSharpness(file).then(function (sharpness) {
                    if (input.files[0] !== file) { photoChecksPending--; return; } // napalitan na habang sinusuri
                    if (sharpness !== null && sharpness < BLUR_THRESHOLD) {
                        photoChecksPending--;
                        rejectPhoto(input, 'This photo is blurry. Please retake a clear photo and upload it again.');
                        return;
                    }
                    // Pinapaliit ang malalaking larawan (max 1800px, JPG) para kasya ang
                    // lahat sa isang pag-submit - may limit ang server sa kabuuang laki
                    shrinkPhoto(file).then(function (small) {
                        photoChecksPending--;
                        if (small && input.files[0] === file) {
                            try {
                                const dt = new DataTransfer();
                                dt.items.add(small);
                                input.files = dt.files;
                            } catch (err) { /* lumang browser: orihinal na file ang ipapasa */ }
                        }
                    });
                });
            });
        });

        document.getElementById('applicationForm').addEventListener('submit', function (e) {
            if (photoChecksPending > 0) {
                e.preventDefault();
                alert('Please wait a moment, we are still checking your photos.');
                return;
            }
            // Kailangang may napiling araw at tamang oras sa calendar
            if (!InterviewPicker.validate(document.querySelector('#step-4 .ip-picker'))) {
                e.preventDefault();
                showStep(4);
                return;
            }
            // Nag-a-upload na: ipakita para hindi akalaing walang nangyayari (at iwas dobleng pindot)
            const btn = document.getElementById('submitAppBtn');
            if (btn.disabled) { e.preventDefault(); return; }
            setTimeout(function () {
                if (e.defaultPrevented) return;
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
            }, 0);
        });
        // Kapag bumalik sa page (Back ng browser), puwede ulit pindutin
        window.addEventListener('pageshow', function () {
            const btn = document.getElementById('submitAppBtn');
            btn.disabled = false;
            btn.textContent = 'Submit Application';
        });

        // ===== Residency ID (Barangay ID): harap at likod =====
        // Kuha sa camera (may frame na susundan) o pili ng file. Bawat larawan ay:
        //  1. pinapaliit (max 1800px, JPG) para mabilis i-upload at basahin,
        //  2. sinusuri kung malabo / madilim dito mismo sa browser,
        //  3. ipinapasuri sa server kung Barangay ID nga at tamang side.
        // Kapag hindi pumasa, binubura ang file at sinasabi kung bakit.
        window.RidUI = (function () {
            const ID_BLUR_THRESHOLD = 100; // malinaw na ID ~2000+, malabo ~20 (sinukat sa totoong ID)
            const ID_MAX_SIDE = 1800;
            const SIDE_TEXT = { front: 'front', back: 'back' };
            let applying = false; // ang file ay inilalagay ng code - huwag suriin ulit

            const exampleBtn = document.getElementById('ridExampleBtn');
            const example = document.getElementById('ridExample');
            const slots = document.querySelector('.rid-slots');
            // Habang bukas ang example, nakatago ang upload; babalik pag "Hide example"
            function setExample(open) {
                example.hidden = !open;
                slots.hidden = open;
                exampleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                exampleBtn.innerHTML = open
                    ? '<i class="fa-regular fa-eye-slash"></i> Hide example'
                    : '<i class="fa-regular fa-eye"></i> See example';
            }
            exampleBtn.addEventListener('click', function () { setExample(example.hidden); });

            function slotOf(input) { return input.closest('.rid-slot'); }

            function setStatus(input, state, message) {
                const slot = slotOf(input);
                const box = slot.querySelector('.rid-status');
                slot.classList.toggle('is-error', state === 'error');
                box.className = 'rid-status' + (state ? ' ' + state : '');
                if (!state) { box.innerHTML = ''; return; }
                const icon = state === 'checking' ? 'fa-solid fa-spinner fa-spin'
                    : (state === 'ok' ? 'fa-solid fa-circle-check' : 'fa-solid fa-triangle-exclamation');
                box.innerHTML = '<i class="' + icon + '"></i><span></span>';
                box.querySelector('span').textContent = message;
            }

            function setPreview(input, src) {
                const slot = slotOf(input);
                const img = slot.querySelector('.rid-preview');
                if (img.dataset.objectUrl) { URL.revokeObjectURL(img.dataset.objectUrl); delete img.dataset.objectUrl; }
                if (src instanceof Blob) {
                    const url = URL.createObjectURL(src);
                    img.dataset.objectUrl = url;
                    src = url;
                }
                img.hidden = !src;
                if (src) img.src = src; else img.removeAttribute('src');
                slot.classList.toggle('has-file', !!src);
            }

            function putFile(input, file) {
                applying = true;
                try {
                    const dt = new DataTransfer();
                    if (file) dt.items.add(file);
                    input.files = dt.files;
                } catch (e) { /* lumang browser: mananatili ang orihinal na file */ }
                input.dispatchEvent(new Event('change', { bubbles: true })); // para ma-save sa draft
                applying = false;
            }

            function reject(input, message) {
                putFile(input, null);
                setPreview(input, null);
                setStatus(input, 'error', message);
            }

            // Pinapaliit ang larawan at sinusukat ang linaw at liwanag
            function prepare(file, side) {
                return new Promise(function (resolve) {
                    const url = URL.createObjectURL(file);
                    const img = new Image();
                    img.onload = function () {
                        URL.revokeObjectURL(url);
                        const iw = img.naturalWidth, ih = img.naturalHeight;

                        // Linaw (variance of Laplacian) at liwanag, sa 600px
                        const s = Math.min(1, 600 / Math.max(iw, ih));
                        const w = Math.max(3, Math.round(iw * s)), h = Math.max(3, Math.round(ih * s));
                        const c = document.createElement('canvas');
                        c.width = w; c.height = h;
                        const ctx = c.getContext('2d');
                        ctx.drawImage(img, 0, 0, w, h);
                        const px = ctx.getImageData(0, 0, w, h).data;
                        const gray = new Float32Array(w * h);
                        let bright = 0;
                        for (let i = 0; i < w * h; i++) {
                            gray[i] = 0.299 * px[i * 4] + 0.587 * px[i * 4 + 1] + 0.114 * px[i * 4 + 2];
                            bright += gray[i];
                        }
                        bright /= w * h;
                        let sum = 0, sumSq = 0, n = 0;
                        for (let y = 1; y < h - 1; y++) {
                            for (let x = 1; x < w - 1; x++) {
                                const i = y * w + x;
                                const lap = gray[i - w] + gray[i + w] + gray[i - 1] + gray[i + 1] - 4 * gray[i];
                                sum += lap; sumSq += lap * lap; n++;
                            }
                        }
                        const mean = sum / n;
                        const sharp = sumSq / n - mean * mean;

                        // Bagong JPG na hanggang 1800px
                        const already = /^barangay_id_(front|back)\.jpg$/.test(file.name) && Math.max(iw, ih) <= ID_MAX_SIDE;
                        if (already) { resolve({ file: file, sharp: sharp, bright: bright }); return; }
                        const k = Math.min(1, ID_MAX_SIDE / Math.max(iw, ih));
                        const out = document.createElement('canvas');
                        out.width = Math.round(iw * k); out.height = Math.round(ih * k);
                        out.getContext('2d').drawImage(img, 0, 0, out.width, out.height);
                        out.toBlob(function (blob) {
                            const f = blob ? new File([blob], 'barangay_id_' + side + '.jpg', { type: 'image/jpeg' }) : file;
                            resolve({ file: f, sharp: sharp, bright: bright });
                        }, 'image/jpeg', 0.9);
                    };
                    img.onerror = function () {
                        URL.revokeObjectURL(url);
                        resolve(null); // hindi mabasang larawan (hal. HEIC)
                    };
                    img.src = url;
                });
            }

            function handle(input, file) {
                const side = slotOf(input).dataset.side;
                const label = SIDE_TEXT[side];
                if (file.size > PHOTO_MAX_BYTES) {
                    reject(input, 'This photo is larger than 10MB. Please upload a smaller photo.');
                    return;
                }
                if (!/^image\//.test(file.type)) {
                    reject(input, 'Please upload a photo (JPG or PNG) of your Barangay ID.');
                    return;
                }

                photoChecksPending++;
                setPreview(input, file);
                setStatus(input, 'checking', 'Checking your ID photo...');

                prepare(file, side).then(function (res) {
                    if (!res) {
                        reject(input, 'This photo could not be opened. Please use a JPG or PNG photo.');
                        return null;
                    }
                    if (res.sharp < ID_BLUR_THRESHOLD) {
                        reject(input, 'Your ID is blurred. Hold the camera steady, tap to focus, and take the photo again.');
                        return null;
                    }
                    if (res.bright < 45) {
                        reject(input, 'Your ID photo is too dark. Move to a brighter place and try again.');
                        return null;
                    }
                    if (res.bright > 235) {
                        reject(input, 'Your ID photo is too bright or has glare. Avoid direct light and try again.');
                        return null;
                    }
                    putFile(input, res.file);
                    setPreview(input, res.file);
                    setStatus(input, 'checking', 'Making sure this is the ' + label + ' of a Barangay ID...');

                    const fd = new FormData();
                    fd.append('side', side);
                    fd.append('id_image', res.file);
                    return fetch('process_check_valid_id.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .catch(function () { return { ok: true, checked: false }; })
                        .then(function (result) {
                            if (input.files[0] !== res.file) return; // napalitan na habang sinusuri
                            if (!result.ok) {
                                reject(input, result.message || 'This ID could not be accepted. Please try another photo.');
                            } else {
                                setStatus(input, 'ok', result.checked ? result.message : 'The ' + label + ' of your ID is clear.');
                            }
                        });
                }).finally(function () {
                    photoChecksPending--;
                });
            }

            document.querySelectorAll('.rid-input').forEach(function (input) {
                const slot = slotOf(input);
                input.addEventListener('change', function () {
                    if (applying) return;
                    const file = input.files[0];
                    if (!file) { setPreview(input, null); return; }
                    handle(input, file);
                });
                input.addEventListener('invalid', function () {
                    if (!example.hidden) setExample(false); // ipakita ang kulang na upload
                    setStatus(input, 'error', 'Please add the ' + SIDE_TEXT[slot.dataset.side] + ' of your Barangay ID.');
                });
                slot.querySelector('.rid-file').addEventListener('click', function () { input.click(); });
                // May larawan na: buksan nang malaki. Wala pa: pumili ng file.
                slot.querySelector('.rid-drop').addEventListener('click', function () {
                    const preview = slot.querySelector('.rid-preview');
                    if (slot.classList.contains('has-file') && preview.src) {
                        openFileViewer(preview.src, slot.querySelector('label').textContent.trim());
                    } else {
                        input.click();
                    }
                });
                slot.querySelector('.rid-camera').addEventListener('click', function () {
                    Camera.open(slot.dataset.side, function (file) { handle(input, file); }, function () { input.click(); });
                });
            });

            // ----- Camera na may frame (gaya ng pag-verify ng ID sa ibang app) -----
            const Camera = (function () {
                const box = document.getElementById('ridCamera');
                const stage = box.querySelector('.cam-stage');
                const video = box.querySelector('video');
                const shot = box.querySelector('.cam-shot');
                const frame = box.querySelector('.cam-frame');
                const msg = box.querySelector('.cam-msg');
                const shutter = box.querySelector('.cam-shutter');
                const review = box.querySelector('.cam-review');
                let stream = null, onDone = null, onFallback = null, lastBlob = null, side = 'front';

                function stop() {
                    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
                    stream = null;
                    video.srcObject = null;
                }

                function close() {
                    if (box.hidden) return;
                    stop();
                    box.hidden = true;
                    document.body.style.overflow = '';
                }

                function showMessage(text) {
                    msg.hidden = false;
                    msg.querySelector('p').textContent = text;
                    shutter.hidden = true;
                    review.hidden = true;
                }

                function live() {
                    stage.classList.remove('is-review');
                    shot.hidden = true;
                    shutter.hidden = false;
                    review.hidden = true;
                }

                function start() {
                    msg.hidden = true;
                    live();
                    navigator.mediaDevices.getUserMedia({
                        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
                        audio: false
                    }).then(function (s) {
                        if (box.hidden) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
                        stream = s;
                        video.srcObject = s;
                        video.play().catch(function () {});
                    }).catch(function (err) {
                        showMessage(err && err.name === 'NotAllowedError'
                            ? 'Camera access was blocked. Allow the camera in your browser settings, or upload a photo instead.'
                            : 'No camera was found on this device. You can upload a photo of your ID instead.');
                    });
                }

                // Kinukuha lang ang nasa loob ng frame (may kaunting palugit)
                function capture() {
                    const vw = video.videoWidth, vh = video.videoHeight;
                    if (!vw || !vh) return;
                    const sr = stage.getBoundingClientRect(), fr = frame.getBoundingClientRect();
                    const scale = Math.max(sr.width / vw, sr.height / vh); // object-fit: cover
                    const offX = (vw * scale - sr.width) / 2, offY = (vh * scale - sr.height) / 2;
                    const pad = 0.06;
                    let x = (fr.left - sr.left - fr.width * pad + offX) / scale;
                    let y = (fr.top - sr.top - fr.height * pad + offY) / scale;
                    let w = fr.width * (1 + pad * 2) / scale, h = fr.height * (1 + pad * 2) / scale;
                    x = Math.max(0, x); y = Math.max(0, y);
                    w = Math.min(vw - x, w); h = Math.min(vh - y, h);

                    const c = document.createElement('canvas');
                    c.width = Math.round(w); c.height = Math.round(h);
                    c.getContext('2d').drawImage(video, x, y, w, h, 0, 0, c.width, c.height);
                    c.toBlob(function (blob) {
                        if (!blob) return;
                        lastBlob = blob;
                        if (shot.dataset.objectUrl) URL.revokeObjectURL(shot.dataset.objectUrl);
                        shot.dataset.objectUrl = URL.createObjectURL(blob);
                        shot.src = shot.dataset.objectUrl;
                        shot.hidden = false;
                        stage.classList.add('is-review');
                        shutter.hidden = true;
                        review.hidden = false;
                    }, 'image/jpeg', 0.92);
                }

                shutter.addEventListener('click', capture);
                box.querySelector('.cam-retake').addEventListener('click', live);
                box.querySelector('.cam-use').addEventListener('click', function () {
                    if (!lastBlob) return;
                    const file = new File([lastBlob], 'barangay_id_' + side + '.jpg', { type: 'image/jpeg' });
                    const done = onDone;
                    close();
                    if (done) done(file);
                });
                box.querySelector('.cam-close').addEventListener('click', close);
                msg.querySelector('button').addEventListener('click', function () {
                    const fb = onFallback;
                    close();
                    if (fb) fb();
                });
                box.addEventListener('click', function (e) { if (e.target === box) close(); });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

                return {
                    open: function (whichSide, done, fallback) {
                        side = whichSide;
                        onDone = done;
                        onFallback = fallback;
                        // Walang camera API (hal. hindi HTTPS): gamitin ang camera app ng phone
                        if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
                            const tmp = document.createElement('input');
                            tmp.type = 'file';
                            tmp.accept = 'image/*';
                            tmp.setAttribute('capture', 'environment');
                            tmp.addEventListener('change', function () { if (tmp.files[0]) done(tmp.files[0]); });
                            tmp.click();
                            return;
                        }
                        document.getElementById('camTitle').textContent = (whichSide === 'back' ? 'Back' : 'Front') + ' of your Barangay ID';
                        box.querySelector('.cam-hint').textContent = 'Place the ' + whichSide
                            + ' of your ID inside the frame. Hold steady and avoid glare.';
                        box.hidden = false;
                        document.body.style.overflow = 'hidden';
                        start();
                    }
                };
            })();

            return {
                // Returning adopter: ipakita ang dating ID sa slot
                showPrevious: function (input, url) {
                    setPreview(input, url);
                    setStatus(input, 'ok', 'Using the ' + SIDE_TEXT[slotOf(input).dataset.side] + ' from your previous application.');
                    const note = slotOf(input).querySelector('.reuse-file-note');
                    if (note) note.remove(); // ang status sa itaas na ang paalala
                }
            };
        })();

        // ===== Draft: hindi nawawala ang nasagutan kapag na-refresh o nasara ang page =====
        // Ang mga sagot at ang step ay nasa localStorage; ang mga larawan at ID ay nasa
        // IndexedDB ng browser na ito lang. Binubura ang draft kapag matagumpay na
        // na-submit (my_applications.php) o kapag pinindot ang "Start over".
        window.AppDraft = (function () {
            const form = document.getElementById('applicationForm');
            const KEY = 'sp_app_draft_<?php echo (int) $user_id; ?>_<?php echo (int) $pet['pet_id']; ?>';
            const DB_NAME = 'sp_app_drafts';
            const STORE = 'files';
            let restoring = false;
            let timer = null;

            function readDraft() {
                try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
            }

            function writeDraft(data) {
                try { localStorage.setItem(KEY, JSON.stringify(data)); } catch (e) { /* puno o naka-block ang storage */ }
            }

            // May kilalang bug ang Safari (iPhone/iPad) kung saan minsan hindi sumasagot
            // ang IndexedDB. May time limit ito para hindi maipit ang form - ang mga
            // sagot ay nase-save pa rin kahit hindi ang mga larawan.
            function withTimeout(promise, ms) {
                return new Promise(function (resolve, reject) {
                    const t = setTimeout(function () { reject(new Error('timeout')); }, ms);
                    promise.then(function (v) { clearTimeout(t); resolve(v); }, function (e) { clearTimeout(t); reject(e); });
                });
            }

            let dbPromise = null;
            function openDb() {
                if (!dbPromise) {
                    dbPromise = withTimeout(new Promise(function (resolve, reject) {
                        try {
                            if (!window.indexedDB) { reject(new Error('no indexedDB')); return; }
                            const req = indexedDB.open(DB_NAME, 1);
                            req.onupgradeneeded = function () { req.result.createObjectStore(STORE); };
                            req.onsuccess = function () { resolve(req.result); };
                            req.onerror = function () { reject(req.error); };
                            req.onblocked = function () { reject(new Error('blocked')); };
                        } catch (e) {
                            reject(e); // hal. Private Browsing sa lumang iOS
                        }
                    }), 2000);
                    dbPromise.catch(function () { dbPromise = null; }); // subukan ulit sa susunod
                }
                return dbPromise;
            }

            function fileStore(mode, action) {
                return openDb().then(function (db) {
                    return withTimeout(new Promise(function (resolve, reject) {
                        try {
                            const tx = db.transaction(STORE, mode);
                            const req = action(tx.objectStore(STORE));
                            tx.oncomplete = function () { resolve(req ? req.result : undefined); };
                            tx.onerror = function () { reject(tx.error); };
                            tx.onabort = function () { reject(tx.error); };
                        } catch (e) {
                            reject(e); // hal. hindi ma-save ng lumang Safari ang file sa IndexedDB
                        }
                    }), 3000);
                });
            }

            function collect() {
                const data = {
                    step: currentStep,
                    fields: {},
                    choices: {},
                    reuse: document.getElementById('reuse_application_id').value,
                    interview: {
                        date: form.querySelector('.ip-date-value').value,
                        time: form.querySelector('.ip-time-input').value
                    },
                    savedAt: Date.now()
                };
                Array.prototype.forEach.call(form.elements, function (el) {
                    if (!el.name || ['file', 'hidden', 'submit', 'button'].indexOf(el.type) !== -1) return;
                    if (el.type === 'radio') {
                        if (el.checked) data.fields[el.name] = el.value;
                        return;
                    }
                    data.fields[el.name] = el.value;
                });
                form.querySelectorAll('.choice-select').forEach(function (select) {
                    const target = select.dataset.target;
                    const other = form.querySelector('.choice-other-input[data-target="' + target + '"]');
                    data.choices[target] = { sel: select.value, other: other ? other.value : '' };
                });
                return data;
            }

            function save() {
                if (restoring) return;
                clearTimeout(timer);
                timer = setTimeout(function () { writeDraft(collect()); }, 250);
            }

            function saveNow() {
                clearTimeout(timer);
                writeDraft(collect());
            }

            // Larawan o ID na pinili: itago rin, para hindi na kailangang piliin ulit
            form.querySelectorAll('input[type="file"]').forEach(function (input) {
                input.addEventListener('change', function () {
                    if (restoring) return;
                    const fileKey = KEY + '|' + input.name;
                    const file = input.files[0];
                    fileStore('readwrite', function (store) {
                        return file ? store.put(file, fileKey) : store.delete(fileKey);
                    }).catch(function () { /* hindi suportado - ang mga sagot lang ang maibabalik */ });
                    save();
                });
            });

            function restoreFiles() {
                if (typeof DataTransfer === 'undefined') return Promise.resolve();
                const inputs = Array.prototype.slice.call(form.querySelectorAll('input[type="file"]'));
                return Promise.all(inputs.map(function (input) {
                    return fileStore('readonly', function (store) { return store.get(KEY + '|' + input.name); })
                        .then(function (file) {
                            if (!file) return;
                            try {
                                const dt = new DataTransfer();
                                dt.items.add(file);
                                input.files = dt.files;
                                input.dispatchEvent(new Event('change')); // itinatago ang "Using your previous file"
                            } catch (e) { /* hindi maibalik sa browser na ito */ }
                        })
                        .catch(function () {});
                }));
            }

            function restore() {
                const data = readDraft();
                if (!data) return false;
                restoring = true;

                // Ginamit ang lumang details bago nag-refresh
                if (data.reuse && previousApp && String(previousApp.application_id) === String(data.reuse)) {
                    usePrevious();
                }

                Object.keys(data.fields || {}).forEach(function (name) {
                    const el = form.elements[name];
                    if (!el) return;
                    el.value = data.fields[name]; // gumagana rin sa radio group (RadioNodeList)
                    if (name === 'birthdate') el.dispatchEvent(new Event('change'));
                    if (name === 'phone_number') el.dispatchEvent(new Event('input'));
                });

                Object.keys(data.choices || {}).forEach(function (target) {
                    const select = form.querySelector('.choice-select[data-target="' + target + '"]');
                    const other = form.querySelector('.choice-other-input[data-target="' + target + '"]');
                    if (!select) return;
                    select.value = data.choices[target].sel;
                    select.dispatchEvent(new Event('change'));
                    if (other && data.choices[target].sel === 'Other') {
                        other.value = data.choices[target].other;
                        other.dispatchEvent(new Event('input'));
                    }
                });

                if (data.interview && data.interview.date) {
                    InterviewPicker.restore(form.querySelector('.ip-picker'), data.interview.date, data.interview.time);
                }

                closeReuseModal();
                showStep(Math.min(Math.max(parseInt(data.step, 10) || 1, 1), totalSteps));

                const banner = document.createElement('div');
                banner.className = 'reuse-notice';
                banner.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> We brought back the answers you already filled in. '
                    + '<button type="button" class="draft-reset">Start over</button>';
                banner.querySelector('.draft-reset').addEventListener('click', function () {
                    if (confirm('Clear all your answers and start the application over?')) {
                        clear().then(function () { location.reload(); });
                    }
                });
                form.insertAdjacentElement('beforebegin', banner);
                makeNoticeCollapsible(banner, 'Saved answers', 'sp_rn_draft', 1);

                return restoreFiles().then(function () { restoring = false; saveNow(); }), true;
            }

            function clear() {
                try { localStorage.removeItem(KEY); } catch (e) {}
                const inputs = Array.prototype.slice.call(form.querySelectorAll('input[type="file"]'));
                return fileStore('readwrite', function (store) {
                    inputs.forEach(function (input) { store.delete(KEY + '|' + input.name); });
                    return null;
                }).catch(function () {});
            }

            form.addEventListener('input', save);
            form.addEventListener('change', save);
            // Naka-save na bago umalis ang page (hal. submit na may error sa server)
            form.addEventListener('submit', saveNow);
            window.addEventListener('pagehide', function () { if (!restoring) saveNow(); });

            restore();
            return { save: save, clear: clear };
        })();
    </script>

    <!-- Pop-up na viewer ng dating na-upload na larawan / dokumento -->
    <div class="fv-overlay" id="fileViewer" role="dialog" aria-modal="true" aria-labelledby="fvTitle" hidden>
        <div class="fv-bar">
            <strong id="fvTitle"></strong>
            <button type="button" class="fv-close" id="fvClose" title="Close (Esc)" aria-label="Close">&times;</button>
        </div>
        <div class="fv-stage" id="fvStage"></div>
    </div>
    <style>
        .fv-overlay {
            position: fixed; inset: 0; z-index: 10050; display: flex; flex-direction: column;
            background: rgba(6, 10, 8, 0.9); backdrop-filter: blur(3px); animation: fvIn .2s ease;
        }
        .fv-overlay[hidden] { display: none; }
        .fv-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; color: #fff; }
        .fv-bar strong { font-size: 16px; font-weight: 700; }
        .fv-close {
            width: 44px; height: 44px; border: none; border-radius: 50%; background: rgba(255, 255, 255, 0.95);
            color: #1f2937; font-size: 26px; line-height: 1; cursor: pointer; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.3);
            transition: transform .15s ease;
        }
        .fv-close:hover { transform: scale(1.07); }
        .fv-close:focus-visible { outline: 3px solid #8fd3ae; outline-offset: 2px; }
        .fv-stage { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; padding: 0 20px 24px; }
        .fv-stage img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); }
        .fv-stage iframe { width: min(900px, 100%); height: 100%; border: 0; border-radius: 8px; background: #fff; }
        @keyframes fvIn { from { opacity: 0; } to { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { .fv-overlay { animation: none; } }
    </style>
    <script>
        // Pop-up viewer: larawan o PDF, may × / Esc / click sa labas para isara
        function openFileViewer(url, title) {
            const box = document.getElementById('fileViewer');
            const stage = document.getElementById('fvStage');
            document.getElementById('fvTitle').textContent = title;
            stage.innerHTML = '';
            if (/\.pdf($|\?)/i.test(decodeURIComponent(url))) {
                const f = document.createElement('iframe');
                f.src = url; f.title = title;
                stage.appendChild(f);
            } else {
                const img = document.createElement('img');
                img.src = url; img.alt = title;
                stage.appendChild(img);
            }
            box.hidden = false;
            document.body.style.overflow = 'hidden';
            document.getElementById('fvClose').focus();
        }
        function closeFileViewer() {
            const box = document.getElementById('fileViewer');
            if (box.hidden) return;
            box.hidden = true;
            document.getElementById('fvStage').innerHTML = '';
            document.body.style.overflow = '';
        }
        document.getElementById('fvClose').addEventListener('click', closeFileViewer);
        document.getElementById('fileViewer').addEventListener('click', function (e) {
            if (e.target === this || e.target.id === 'fvStage') closeFileViewer();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeFileViewer(); });
    </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
