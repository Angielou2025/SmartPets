<?php
/**
 * Tinatanggap ang access token mula sa "login with google" button.
 * Sinisigurado muna sa Google na para sa app natin ang token, saka hinahanap
 * ang account na may parehong email. Kapag wala pang account, itatanong muna
 * sa tao (google_confirm.php) kung papayag siyang gawan ng Smart Pets account.
 */
session_start();
require '../connection/database.php';
require '../connection/google_config.php';
require __DIR__ . '/_login_helpers.php';

function google_fail(string $message): void
{
    $_SESSION['login_error'] = $message;
    header("Location: login.php");
    exit();
}

function google_get(string $url, ?string $bearer = null): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $bearer ? ["Authorization: Bearer $bearer"] : [],
    ]);

    // Sa localhost lang: sinisilip ng antivirus (Avast) ang HTTPS gamit ang sarili
    // nitong certificate, kaya idinagdag iyon sa local_cacert.pem. Sinusuri pa rin
    // ang certificate - hindi pinapatay ang check. Sa hosting, hindi ito ginagamit.
    $localCa = __DIR__ . '/../connection/local_cacert.pem';
    $host = strtolower($_SERVER['SERVER_NAME'] ?? '');
    if (in_array($host, ['localhost', '127.0.0.1'], true) && is_file($localCa)) {
        curl_setopt($ch, CURLOPT_CAINFO, $localCa);
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !google_login_enabled()) {
    header("Location: login.php");
    exit();
}

$token = trim($_POST['access_token'] ?? '');
if ($token === '') {
    google_fail("Google sign-in was cancelled. Please try again.");
}

// Para sa app natin dapat ang token - hindi tinatanggap ang token ng ibang app
$info = google_get('https://oauth2.googleapis.com/tokeninfo?access_token=' . urlencode($token));
if (!$info || ($info['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    google_fail("Unable to verify your Google account. Please try again.");
}

$profile = google_get('https://www.googleapis.com/oauth2/v3/userinfo', $token);
$email = strtolower(trim($profile['email'] ?? ''));
if ($email === '' || empty($profile['email_verified'])) {
    google_fail("Your Google account email is not verified.");
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// May account na: login agad
if ($user) {
    finish_login($pdo, $user);
}

// Wala pang account: hindi pa gagawa. Itatabi muna ang napatunayang Google
// profile, at hihingin muna ang pahintulot ng tao sa google_confirm.php.
$base = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $profile['name'] ?? ''));
if ($base === '') {
    $base = preg_replace('/[^A-Za-z0-9_]/', '', strstr($email, '@', true));
}
$base = substr($base ?: 'adopter', 0, 40);

// Mungkahing username na wala pang gumagamit (puwede niyang palitan)
$username = $base;
$taken = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
for ($n = 2; $taken->execute([$username]) && $taken->fetchColumn(); $n++) {
    $username = $base . $n;
}

session_regenerate_id(true);
$_SESSION['google_pending'] = [
    'email'      => $email,
    'name'       => trim($profile['name'] ?? ''),
    'picture'    => $profile['picture'] ?? '',
    'username'   => $username,
    'token'      => bin2hex(random_bytes(16)),
    'expires_at' => time() + 600, // 10 minuto para magpasya
];

header("Location: google_confirm.php");
exit();
