<?php
/**
 * Ikalawang hakbang ng pag-register: ilalagay ang OTP na ipinadala sa Gmail.
 * Dito pa lang talaga ginagawa ang account, at saka magpapadala ng email na
 * "This email is registered to Smart Pet".
 */
session_start();
require '../connection/database.php';
require_once '../connection/mailer.php';
require_once 'registration_otp.php';

$pending = $_SESSION['pending_registration'] ?? null;
if (!$pending || empty($pending['otp_hash'])) {
    header("Location: register.php");
    exit();
}

$error = "";
$info = "";

// Kapag galing sa OTP boxes (otp_boxes.js), JSON ang sagot para makapag-animate
// sa page bago lumipat: tamang code = nagsasamang bilog + check
$isAjax = !empty($_POST['ajax']);
function verify_json(bool $ok, string $error = '', string $redirect = ''): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'error' => $error, 'redirect' => $redirect]);
    exit();
}

// Magpadala ulit ng bagong code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    $wait = SP_REG_OTP_RESEND_WAIT - (time() - (int) $pending['otp_sent']);
    if ($wait > 0) {
        $error = "Please wait {$wait} seconds before requesting a new code.";
    } elseif (sp_send_registration_otp($pending['email'], $pending['username'])) {
        $info = "A new code was sent to your Gmail.";
    } else {
        $error = "We could not send a new code. Please try again in a moment.";
    }
    $pending = $_SESSION['pending_registration'];
}

// I-verify ang code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify'])) {
    $code = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));

    if (time() > (int) $pending['otp_expiry']) {
        $error = "Your code has expired. Tap \"Send a new code\" below.";
    } elseif ((int) $pending['attempts'] >= SP_REG_OTP_MAX_ATTEMPTS) {
        $error = "Too many wrong attempts. Tap \"Send a new code\" below.";
    } elseif (!password_verify($code, $pending['otp_hash'])) {
        $_SESSION['pending_registration']['attempts'] = (int) $pending['attempts'] + 1;
        $left = SP_REG_OTP_MAX_ATTEMPTS - $_SESSION['pending_registration']['attempts'];
        $error = "Incorrect code." . ($left > 0 ? " You have {$left} attempt(s) left." : "");
    } else {
        // Baka may nakapag-register na gamit ang parehong email/username habang naghihintay
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$pending['email'], $pending['username']]);

        if ($stmt->rowCount() > 0) {
            unset($_SESSION['pending_registration']);
            $_SESSION['register_error'] = "Email or Username is already in use.";
            if ($isAjax) {
                verify_json(false, '', 'register.php');
            }
            header("Location: register.php");
            exit();
        }

        $insertStmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'Adopter')");
        if ($insertStmt->execute([$pending['username'], $pending['email'], $pending['password_hash']])) {
            // Abiso sa Gmail na naka-register na ito sa Smart Pet
            sp_send_registered_notice($pending['email'], $pending['username']);

            unset($_SESSION['pending_registration']);
            $_SESSION['register_success'] = "Your Gmail is verified and your account was created! Please login.";
            if ($isAjax) {
                verify_json(true, '', 'login.php');
            }
            header("Location: login.php");
            exit();
        }
        $error = "Something went wrong. Please try again.";
    }

    if ($isAjax) {
        verify_json(false, $error);
    }
}

// Itago ang gitna ng email: ju******@gmail.com
[$local, $domain] = explode('@', $pending['email'], 2);
$maskedEmail = substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
$resendWait = max(0, SP_REG_OTP_RESEND_WAIT - (time() - (int) $pending['otp_sent']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title>Smart Pets - Verify your Gmail</title>
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

    <div class="verify-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
    </div>

    <h1 class="title confirm-title">Verify your Gmail</h1>
    <p class="subtitle">We sent a 6-digit code to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>. Enter it below to finish creating your account.</p>

    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($info)): ?>
        <div class="success"><?php echo htmlspecialchars($info); ?></div>
    <?php endif; ?>

    <form method="POST" id="verifyForm" data-ajax-otp>
        <!-- Ang tunay na ipinapasa sa server; pinupuno ng 6 na kahon sa ibaba -->
        <input type="hidden" name="otp" id="otpValue">

        <div class="otp-boxes" data-target="otpValue" role="group" aria-label="6-digit verification code">
            <?php for ($i = 0; $i < 6; $i++): ?>
                <!-- maxlength 6: para kapag in-autofill ng phone ang buong code, maipamahagi ito sa 6 na kahon -->
                <input type="text" class="otp-box" inputmode="numeric" maxlength="6"
                       aria-label="Digit <?php echo $i + 1; ?>" <?php echo $i === 0 ? 'autocomplete="one-time-code" autofocus' : 'autocomplete="off"'; ?>>
            <?php endfor; ?>
        </div>

        <button type="submit" name="verify" class="btn-login" id="verifyBtn">Verify &amp; Create Account</button>
    </form>

    <p class="otp-hint">Code expires in <?php echo SP_REG_OTP_MINUTES; ?> minutes. Check your Spam folder if you can&rsquo;t find it.</p>

    <div class="verify-links">
        <form method="POST">
            <button type="submit" name="resend" id="resendBtn" class="link-btn" <?php echo $resendWait > 0 ? 'disabled' : ''; ?>>
                Send a new code<?php echo $resendWait > 0 ? " ({$resendWait}s)" : ''; ?>
            </button>
        </form>
        <span class="dot" aria-hidden="true">&middot;</span>
        <a href="register.php?restart=1">Wrong email? Go back</a>
    </div>
</div>

<script>
    // Bilang pababa bago pwedeng magpadala ulit ng code
    (function () {
        let wait = <?php echo (int) $resendWait; ?>;
        const btn = document.getElementById('resendBtn');
        if (wait <= 0) return;
        const t = setInterval(function () {
            wait--;
            if (wait <= 0) {
                clearInterval(t);
                btn.disabled = false;
                btn.textContent = 'Send a new code';
            } else {
                btn.textContent = 'Send a new code (' + wait + 's)';
            }
        }, 1000);
    })();
</script>
<script src="otp_boxes.js"></script>

<script src="keyboard_fix.js"></script>
</body>
</html>
