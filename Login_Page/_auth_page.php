<?php
/**
 * Iisang page para sa Login at Sign Up, para tuloy-tuloy (walang reload) ang
 * animation kapag nagpapalit. Isinasama ito ng login.php at register.php.
 *
 * Kailangang itakda bago i-include:
 *   $authMode        'login' o 'register' - alin ang unang ipapakita
 *   $loginError, $loginSuccess, $remainingSeconds, $loginEmail
 *   $registerError, $registerUsername, $registerEmail
 *
 * Sa login.php pa rin pumupunta ang login form, at sa register.php ang
 * register form - kaya hindi nagbago ang validation at OTP.
 */
$isRegister = $authMode === 'register';
$eyeIcon = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title><?php echo $isRegister ? 'Smart Pets - Sign Up' : 'Smart Pets - Login'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500&family=Fraunces:opsz,wght@9..144,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="login.css">
</head>
<?php
// Entrance animation kapag binuksan ang page - hindi kapag may error o mensahe
// (hal. maling password), para hindi paulit-ulit na gumagalaw ang card
$authAnimate = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && empty($loginError) && empty($loginSuccess) && empty($registerError);
?>
<body<?php echo $authAnimate ? ' class="auth-enter"' : ''; ?>>

    <div class="overlay"></div>

    <div class="login-container<?php echo $isRegister ? ' show-register' : ''; ?>" id="authCard">

        <!-- Welcome panel: sa laptop ay dumudulas ito sa ibabaw ng form na hindi ginagamit -->
        <div class="login-intro">
            <div class="brand">
                <div class="brand-logo">
                    <img src="../Images/logo.png" alt="Smart Pets logo">
                </div>
                <span class="brand-name"><span class="brand-smart">Smart</span> Pets</span>
            </div>

            <div class="intro-texts">
                <div class="intro-text intro-login" <?php echo $isRegister ? 'aria-hidden="true"' : ''; ?>>
                    <p class="tagline">Your adoption awaits.</p>
                    <h1 class="title">Welcome Back</h1>
                    <p class="subtitle">Sign in to continue to your account</p>
                </div>
                <div class="intro-text intro-register" <?php echo $isRegister ? '' : 'aria-hidden="true"'; ?>>
                    <p class="tagline">Your adoption awaits.</p>
                    <h2 class="title">Welcome</h2>
                    <p class="subtitle">Create an account to start your adoption journey</p>
                </div>
            </div>
        </div>

        <!-- ===== SIGN UP ===== -->
        <div class="login-main pane-register" <?php echo $isRegister ? '' : 'inert'; ?>>
            <?php if (!empty($registerError)): ?>
                <div class="error"><?php echo htmlspecialchars($registerError); ?></div>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <label class="field-label" for="regUsernameInput">Username</label>
                <div class="input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>
                    </svg>
                    <input type="text" name="username" id="regUsernameInput" placeholder="Username" autocomplete="username" required
                           value="<?php echo htmlspecialchars($registerUsername ?? ''); ?>">
                </div>

                <label class="field-label" for="regEmailInput">Gmail</label>
                <div class="input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="M3 7l9 6 9-6"/>
                    </svg>
                    <input type="email" name="email" id="regEmailInput" placeholder="example@gmail.com" autocomplete="email"
                           pattern="[A-Za-z0-9.+]+@[Gg][Mm][Aa][Ii][Ll]\.[Cc][Oo][Mm]" title="Please use your real Gmail account (ends with @gmail.com)" required
                           value="<?php echo htmlspecialchars($registerEmail ?? ''); ?>">
                </div>

                <label class="field-label" for="regPasswordInput">Password</label>
                <div class="input-wrapper password-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="5" y="10" width="14" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        <circle cx="12" cy="15.5" r="1.2"/>
                    </svg>
                    <input type="password" name="password" id="regPasswordInput" placeholder="Create a password" minlength="8" autocomplete="new-password" required
                           data-password-rules data-confirm="regConfirmPasswordInput">
                    <button type="button" class="toggle-password" data-target="regPasswordInput" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $eyeIcon; ?></svg>
                    </button>
                </div>

                <label class="field-label" for="regConfirmPasswordInput">Confirm Password</label>
                <div class="input-wrapper password-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="5" y="10" width="14" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        <path d="M9.5 15.5l1.8 1.8 3.2-3.4"/>
                    </svg>
                    <input type="password" name="confirm_password" id="regConfirmPasswordInput" placeholder="Confirm Password" autocomplete="new-password" required>
                    <button type="button" class="toggle-password" data-target="regConfirmPasswordInput" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $eyeIcon; ?></svg>
                    </button>
                </div>

                <button type="submit" name="register" class="btn-login">Register</button>
            </form>

            <p class="signup-row">Already have an account? <a href="login.php" data-auth-swap="login">Login</a></p>
        </div>

        <!-- ===== LOGIN ===== -->
        <div class="login-main pane-login" <?php echo $isRegister ? 'inert' : ''; ?>>
            <!-- Moving countdown timer will appear here when locked -->
            <div id="countdownBox" class="timer-box" style="display: none;">
                You can login again in: <span id="timerText">10</span>s
            </div>

            <?php if (!empty($loginSuccess)): ?>
                <div class="success"><?php echo htmlspecialchars($loginSuccess); ?></div>
            <?php endif; ?>

            <?php if (!empty($loginError)): ?>
                <div class="error"><?php echo htmlspecialchars($loginError); ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php" id="loginForm">
                <label class="field-label" for="loginEmailInput">Email</label>
                <div class="input-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>
                    </svg>
                    <input type="email" name="email" id="loginEmailInput" placeholder="example@gmail.com" autocomplete="email" required
                           value="<?php echo htmlspecialchars($loginEmail ?? ''); ?>">
                </div>

                <label class="field-label" for="loginPasswordInput">Password</label>
                <div class="input-wrapper password-wrapper">
                    <svg class="input-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="5" y="10" width="14" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        <circle cx="12" cy="15.5" r="1.2"/>
                    </svg>
                    <input type="password" name="password" id="loginPasswordInput" placeholder="Password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" data-target="loginPasswordInput" aria-label="Show password">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $eyeIcon; ?></svg>
                    </button>
                </div>

                <div class="forgot-row">
                    <a href="forgot_password.php">Forgot Password?</a>
                </div>

                <button type="submit" name="login" id="loginBtn" class="btn-login">Login</button>
            </form>

            <div class="divider"><span>or</span></div>

            <button type="button" id="googleBtn" class="btn-google">
                <svg viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/>
                    <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                    <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                    <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/>
                </svg>
                <span>login with google</span>
            </button>
            <div id="googleMsg" class="error" style="display:none;margin-top:12px;"></div>

            <p class="signup-row">Don&rsquo;t have an account? <a href="register.php" data-auth-swap="register">Sign Up</a></p>
        </div>
    </div>

    <!-- Ipinapasa ang Google token sa server para ma-verify -->
    <form method="POST" action="google_login.php" id="googleForm" style="display:none;">
        <input type="hidden" name="access_token" id="googleToken">
    </form>

    <script>
        // Moving countdown timer kapag naka-lock ang account
        let secondsLeft = <?php echo (int) ($remainingSeconds ?? 0); ?>;

        if (secondsLeft > 0) {
            const timerBox = document.getElementById('countdownBox');
            const timerText = document.getElementById('timerText');
            const loginBtn = document.getElementById('loginBtn');

            timerBox.style.display = 'block';
            loginBtn.disabled = true;

            const interval = setInterval(() => {
                secondsLeft--;
                timerText.innerText = secondsLeft;

                if (secondsLeft <= 0) {
                    clearInterval(interval);
                    timerBox.style.display = 'none';
                    loginBtn.disabled = false;
                }
            }, 1000);
        }

        // Password Toggle Functionality (lahat ng password field)
        const eyeShape = <?php echo json_encode($eyeIcon); ?>;
        const eyeSlashShape = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';

        document.querySelectorAll('.toggle-password').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const input = document.getElementById(this.dataset.target);
                const show = input.getAttribute('type') === 'password';
                input.setAttribute('type', show ? 'text' : 'password');
                this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                this.querySelector('svg').innerHTML = show ? eyeSlashShape : eyeShape;
            });
        });

        // Google Sign-In
        const googleClientId = <?php echo json_encode(GOOGLE_CLIENT_ID); ?>;
        const googleMsg = document.getElementById('googleMsg');
        let googleClient = null;

        function showGoogleMsg(text) {
            googleMsg.textContent = text;
            googleMsg.style.display = 'block';
        }

        document.getElementById('googleBtn').addEventListener('click', function () {
            if (!googleClientId) {
                showGoogleMsg('Google sign-in is not available yet. Please log in with your email and password.');
                return;
            }
            if (!googleClient) {
                showGoogleMsg('Google sign-in is still loading. Please try again in a moment.');
                return;
            }
            googleClient.requestAccessToken();
        });

        if (googleClientId) {
            const gsi = document.createElement('script');
            gsi.src = 'https://accounts.google.com/gsi/client';
            gsi.async = true;
            gsi.onload = function () {
                googleClient = google.accounts.oauth2.initTokenClient({
                    client_id: googleClientId,
                    scope: 'openid email profile',
                    callback: function (resp) {
                        if (resp.error || !resp.access_token) {
                            showGoogleMsg('Google sign-in was cancelled. Please try again.');
                            return;
                        }
                        document.getElementById('googleToken').value = resp.access_token;
                        document.getElementById('googleForm').submit();
                    }
                });
            };
            document.head.appendChild(gsi);
        }

        // Tapos na ang entrance animation: alisin, para malinis ang paglipat sa Login <-> Sign Up
        if (document.body.classList.contains('auth-enter')) {
            setTimeout(function () { document.body.classList.remove('auth-enter'); }, 1900);
        }

        // Kapag ibinalik ng browser ang page mula sa back/forward cache, i-reload
        // para ma-check ulit ng server kung naka-login pa.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) window.location.reload();
        });
    </script>
    <script src="password_rules.js"></script>
    <script src="auth_transition.js"></script>
    <script src="keyboard_fix.js"></script>
</body>
</html>
