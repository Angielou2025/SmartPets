<?php
/**
 * Account Settings pagkatapos mag-login (ang modal sa search_pets.php).
 * JSON ang sagot ng bawat action:
 *   save     - pangalan, photo, wika (Adopter) at recovery email. Kapag may bagong recovery
 *              email, nagpapadala ng 6-digit code doon (step: "otp").
 *   verify   - sinusuri ang code ng recovery email (5 subok, 10 minuto)
 *   resend   - bagong code sa parehong recovery email (60 segundong hintay)
 *   finish   - tapos na o nilaktawan ang modal; hindi na ito lalabas ulit
 *              ngayong login
 */
session_start();
require '../connection/database.php';
require_once '../connection/mailer.php';
require_once '../connection/app_url.php';

header('Content-Type: application/json');

const PS_OTP_MINUTES = 10;
const PS_OTP_MAX_ATTEMPTS = 5;
const PS_OTP_RESEND_WAIT = 60;
const PS_MAX_PHOTO_BYTES = 5 * 1024 * 1024;

function ps_json(array $data): void
{
    echo json_encode($data);
    exit();
}

function ps_fail(string $message): void
{
    ps_json(['ok' => false, 'error' => $message]);
}

// Gamit ng Adopter, Staff at Admin (parehong Account Settings modal)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Adopter', 'Staff', 'Admin'], true)) {
    http_response_code(403);
    ps_fail('Please log in again.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ps_fail('Invalid request.');
}

$user_id = (int) $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare("SELECT username, email, recovery_email, profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$me) {
    ps_fail('Account not found.');
}

function ps_send_code(string $email, string $name): bool
{
    $otp = sprintf('%06d', random_int(0, 999999));
    $sent = sp_send_email(
        $email,
        $name,
        'Smart Pet - Verify your recovery email',
        'Verify your recovery email',
        [
            'Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',',
            'Use this code to add this email as the recovery email of your Smart Pet account:',
            "<span style='display:inline-block; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #2e5b32;'>$otp</span>",
            'This code expires in ' . PS_OTP_MINUTES . ' minutes.',
            "<span style='font-size: 12px; color: #666;'>If you did not request this, you can ignore this email.</span>",
        ]
    );
    if ($sent) {
        // Hash lang ng code ang itinatabi
        $_SESSION['recovery_pending'] = [
            'email'    => $email,
            'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
            'expiry'   => time() + PS_OTP_MINUTES * 60,
            'sent'     => time(),
            'attempts' => 0,
        ];
    }
    return $sent;
}

function ps_mask(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
}

// ===== save =====
if ($action === 'save') {
    $username = trim($_POST['username'] ?? '');
    $recovery = strtolower(trim($_POST['recovery_email'] ?? ''));

    if ($username === '') {
        ps_fail('Name cannot be empty.');
    }

    // Sinusuri lang ang format kapag binago - para hindi ma-block ang dating username
    if ($username !== $me['username']) {
        if (!preg_match('/^[A-Za-z0-9_. ]{3,40}$/', $username)) {
            ps_fail('Name must be 3 to 40 characters: letters, numbers, space, dot or underscore.');
        }
        $taken = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND user_id <> ?");
        $taken->execute([$username, $user_id]);
        if ($taken->fetchColumn()) {
            ps_fail('That name is already taken. Please choose another one.');
        }
    }

    if ($recovery !== '') {
        if (!filter_var($recovery, FILTER_VALIDATE_EMAIL)) {
            ps_fail('Please enter a valid recovery email.');
        }
        if ($recovery === strtolower($me['email'])) {
            ps_fail('Your recovery email must be different from your Gmail account.');
        }
    }

    // Photo (kung may pinili)
    $newPhoto = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['profile_image'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > PS_MAX_PHOTO_BYTES) {
            ps_fail('Photo must be an image of 5 MB or less.');
        }
        $info = @getimagesize($file['tmp_name']);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($types[$info[2]])) {
            ps_fail('Photo must be a JPG, PNG, GIF or WEBP image.');
        }
        $dir = __DIR__ . '/../uploads/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $newPhoto = 'user_' . $user_id . '_' . time() . '.' . $types[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . $newPhoto)) {
            ps_fail('We could not save your photo. Please try again.');
        }
    }

    $pdo->prepare("UPDATE users SET username = ? WHERE user_id = ?")->execute([$username, $user_id]);
    $_SESSION['username'] = $username;

    // Wika (Adopter at Staff): English o Tagalog sa buong site
    $language = $_POST['language'] ?? '';
    if (in_array($_SESSION['role'] ?? '', ['Adopter', 'Staff'], true) && in_array($language, ['en', 'tl'], true)) {
        try {
            $pdo->prepare("UPDATE users SET language = ? WHERE user_id = ?")->execute([$language, $user_id]);
            $_SESSION['lang'] = $language;
        } catch (Exception $e) {
            // wala pa ang column (hindi pa tumakbo ang setup): hindi na ito haharang sa pag-save
        }
    }
    if ($newPhoto) {
        $pdo->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?")->execute([$newPhoto, $user_id]);
    }

    $result = [
        'ok'       => true,
        'username' => $username,
        // Buong path: gumagana rin sa mga page na nasa subfolder (hal. AdminFile/Manage_staff_file)
        'photo'    => $newPhoto ? sp_base_url() . '/uploads/' . $newPhoto : null,
        'language' => $_SESSION['lang'] ?? 'en',
    ];

    // Bagong recovery email: kailangan munang patunayan gamit ang code
    if ($recovery !== '' && $recovery !== strtolower((string) $me['recovery_email'])) {
        if (!ps_send_code($recovery, $username)) {
            ps_fail('We could not send a code to that email. Please check it and try again.');
        }
        $result['step'] = 'otp';
        $result['masked'] = ps_mask($recovery);
        ps_json($result);
    }

    unset($_SESSION['profile_setup_pending']);
    $result['step'] = 'done';
    ps_json($result);
}

// ===== verify =====
if ($action === 'verify') {
    $pending = $_SESSION['recovery_pending'] ?? null;
    if (!$pending) {
        ps_fail('Please enter your recovery email again.');
    }

    $code = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));

    if (time() > $pending['expiry']) {
        ps_fail('Your code has expired. Tap "Send again" for a new one.');
    }
    if ($pending['attempts'] >= PS_OTP_MAX_ATTEMPTS) {
        ps_fail('Too many wrong attempts. Tap "Send again" for a new code.');
    }
    if (!password_verify($code, $pending['otp_hash'])) {
        $_SESSION['recovery_pending']['attempts']++;
        $left = PS_OTP_MAX_ATTEMPTS - $_SESSION['recovery_pending']['attempts'];
        ps_fail('Incorrect code.' . ($left > 0 ? " You have {$left} attempt(s) left." : ' Tap "Send again" for a new code.'));
    }

    $pdo->prepare("UPDATE users SET recovery_email = ? WHERE user_id = ?")->execute([$pending['email'], $user_id]);
    unset($_SESSION['recovery_pending'], $_SESSION['profile_setup_pending']);
    ps_json(['ok' => true, 'error' => '', 'redirect' => '']);
}

// ===== resend =====
if ($action === 'resend') {
    $pending = $_SESSION['recovery_pending'] ?? null;
    if (!$pending) {
        ps_fail('Please enter your recovery email again.');
    }
    $wait = PS_OTP_RESEND_WAIT - (time() - $pending['sent']);
    if ($wait > 0) {
        ps_fail("Please wait {$wait} seconds before requesting a new code.");
    }
    if (!ps_send_code($pending['email'], $me['username'])) {
        ps_fail('We could not send a new code. Please try again in a moment.');
    }
    ps_json(['ok' => true, 'message' => 'A new code was sent.']);
}

// ===== finish (tapos o nilaktawan) =====
if ($action === 'finish') {
    unset($_SESSION['profile_setup_pending'], $_SESSION['recovery_pending']);
    ps_json(['ok' => true]);
}

ps_fail('Invalid request.');
