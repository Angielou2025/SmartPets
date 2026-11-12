<?php
/**
 * Paghingi ng pahintulot bago gumawa ng account gamit ang Google.
 * Dito lang napupunta ang bagong user (walang account pa sa email na iyon).
 * Ang Google profile ay galing sa google_login.php at napatunayan na doon.
 */
session_start();
require '../connection/database.php';
require_once '../connection/mailer.php';
require_once __DIR__ . '/registration_otp.php';
require __DIR__ . '/_login_helpers.php';

$pending = $_SESSION['google_pending'] ?? null;

function confirm_back_to_login(string $message = ''): void
{
    unset($_SESSION['google_pending']);
    if ($message !== '') {
        $_SESSION['login_error'] = $message;
    }
    header("Location: login.php");
    exit();
}

if (!$pending) {
    confirm_back_to_login();
}
if (time() > $pending['expires_at']) {
    confirm_back_to_login("Your Google sign-in took too long. Please try again.");
}

$error = '';
$username = $pending['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($pending['token'], $_POST['token'] ?? '')) {
        confirm_back_to_login("Something went wrong. Please sign in with Google again.");
    }

    // Tumanggi ang tao - walang gagawing account
    if (isset($_POST['cancel'])) {
        confirm_back_to_login();
    }

    $username = trim($_POST['username'] ?? '');

    if (empty($_POST['agree'])) {
        $error = "Please tick the box to allow Smart Pets to create your account.";
    } elseif (!preg_match('/^[A-Za-z0-9_.]{3,40}$/', $username)) {
        $error = "Username must be 3 to 40 characters: letters, numbers, dot or underscore only.";
    } else {
        $check = $pdo->prepare("SELECT username, LOWER(email) = ? AS same_email FROM users WHERE username = ? OR LOWER(email) = ? LIMIT 1");
        $check->execute([$pending['email'], $username, $pending['email']]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['same_email']) {
            // Nagawa na pala ang account (hal. dobleng pindot) - login na lang
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
            $stmt->execute([$pending['email']]);
            unset($_SESSION['google_pending']);
            finish_login($pdo, $stmt->fetch(PDO::FETCH_ASSOC));
        } elseif ($existing) {
            $error = "That username is already taken. Please choose another one.";
        } else {
            // Random na password na hindi mahuhulaan - puwede siyang gumawa ng sariling
            // password sa "Forgot Password" kung gusto rin niyang mag-login gamit ang email
            $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'Adopter')");
            if ($insert->execute([$username, $pending['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)])) {
                // Abiso sa Gmail na naka-register na ito sa Smart Pet (kapareho ng sa Sign Up).
                // Isang beses lang ito - sa paggawa ng account, hindi sa bawat login.
                sp_send_registered_notice($pending['email'], $username);

                $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ?");
                $stmt->execute([$pending['email']]);
                unset($_SESSION['google_pending']);
                finish_login($pdo, $stmt->fetch(PDO::FETCH_ASSOC));
            }
            $error = "We could not create your account. Please try again.";
        }
    }
}

$displayName = $pending['name'] !== '' ? $pending['name'] : $pending['email'];
$initial = strtoupper(substr($displayName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title>Smart Pets - Create Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="login.css">
</head>
<body>

    <div class="overlay"></div>

    <div class="login-container auth-single">
        <div class="brand">
            <div class="brand-logo">
                <img src="../Images/logo.png" alt="Smart Pets logo">
            </div>
            <span class="brand-name"><span class="brand-smart">Smart</span> Pets</span>
        </div>

        <h1 class="title confirm-title">Create your account?</h1>
        <p class="subtitle">There is no Smart Pets account for this Google account yet. Smart Pets would like to create one for you.</p>

        <div class="google-profile">
            <?php if (!empty($pending['picture'])): ?>
                <img src="<?php echo htmlspecialchars($pending['picture']); ?>" alt="" referrerpolicy="no-referrer">
            <?php else: ?>
                <span class="google-avatar"><?php echo htmlspecialchars($initial); ?></span>
            <?php endif; ?>
            <div>
                <strong><?php echo htmlspecialchars($displayName); ?></strong>
                <span><?php echo htmlspecialchars($pending['email']); ?></span>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($pending['token']); ?>">

            <label class="field-label" for="usernameInput">Username</label>
            <div class="input-wrapper">
                <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>
                </svg>
                <input type="text" name="username" id="usernameInput" maxlength="40" autocomplete="username"
                       value="<?php echo htmlspecialchars($username); ?>">
            </div>

            <p class="confirm-note">Smart Pets will only use your <strong>name</strong> and <strong>Gmail address</strong> from Google. Your Google password is never shared with us.</p>

            <label class="agree-row">
                <input type="checkbox" name="agree" value="1">
                <span>I allow Smart Pets to create an adopter account using my Google account.</span>
            </label>

            <button type="submit" name="create" class="btn-login">Create Account</button>
            <button type="submit" name="cancel" value="1" class="btn-cancel" formnovalidate>Cancel</button>
        </form>
    </div>

</body>
</html>
