<?php
/**
 * Data Privacy Notice bago makita ang adoption application.
 *
 * Bago lumabas ang laman ng isang application (valid ID, barangay certificate,
 * larawan ng bahay, address, birthdate, at iba pang personal na impormasyon),
 * kailangan munang basahin at pagsang-ayunan ng Admin/Staff ang paalala:
 *   - hindi puwedeng gamitin ang impormasyon at valid ID ng adopter sa ibang layunin
 *   - ang batas at parusa kapag nilabag (RA 10173 at RA 10175)
 *
 * Sa SERVER ito pinipigilan, hindi lang tinatakpan sa browser: hangga't walang
 * pagsang-ayon, hindi ipinapadala ang laman ng application.
 *
 * Bawat pagsang-ayon ay itinatala (sino, anong application, kailan, IP) sa
 * data_access_acknowledgments at sa activity_logs - patunay kung may lumabag.
 *
 * Isang beses bawat application sa bawat pag-login: kapag nag-logout at bumalik,
 * o nagbukas ng ibang application, ipapakita ulit ang paalala.
 *
 * Gamit (pagkatapos ma-load ang $application):
 *   require_once __DIR__ . '/../connection/privacy_ack.php';
 *   sp_privacy_gate($pdo, $application, $backUrl);
 */

require_once __DIR__ . '/activity_logger.php';

function sp_privacy_ack_table(PDO $pdo): void
{
    if (!empty($_SESSION['sp_privacy_table_ok'])) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS data_access_acknowledgments (
        ack_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        user_name VARCHAR(100) NOT NULL,
        user_role VARCHAR(20) NOT NULL,
        application_id INT NOT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        acknowledged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_app (application_id, acknowledged_at),
        INDEX idx_user (user_id, acknowledged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $_SESSION['sp_privacy_table_ok'] = true;
}

function sp_privacy_has_ack(int $applicationId): bool
{
    return !empty($_SESSION['sp_privacy_ack'][$applicationId]);
}

/**
 * Hinaharang ang page hangga't walang pagsang-ayon. Kapag pumayag (POST),
 * itinatala at ibinabalik sa parehong URL (para hindi maulit ang POST sa refresh).
 */
function sp_privacy_gate(PDO $pdo, array $application, string $backUrl): void
{
    $applicationId = (int) $application['application_id'];
    if ($applicationId <= 0 || sp_privacy_has_ack($applicationId)) {
        return;
    }

    if (empty($_SESSION['sp_privacy_token'])) {
        $_SESSION['sp_privacy_token'] = bin2hex(random_bytes(16));
    }
    $token = $_SESSION['sp_privacy_token'];
    $error = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sp_privacy_ack'])) {
        $validToken = hash_equals($token, (string) ($_POST['sp_privacy_token'] ?? ''));
        $agreed = ($_POST['sp_privacy_agree'] ?? '') === '1';
        $postedApp = (int) ($_POST['sp_privacy_app'] ?? 0);

        if ($validToken && $agreed && $postedApp === $applicationId) {
            try {
                sp_privacy_ack_table($pdo);
                $pdo->prepare(
                    "INSERT INTO data_access_acknowledgments
                        (user_id, user_name, user_role, application_id, ip_address, user_agent)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([
                    $_SESSION['user_id'] ?? null,
                    $_SESSION['username'] ?? 'Unknown',
                    $_SESSION['role'] ?? 'Unknown',
                    $applicationId,
                    substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
            } catch (Exception $e) {
                // Hindi pinapayagang magpatuloy kapag hindi naitala ang pagsang-ayon
                $error = 'Your acknowledgment could not be recorded. Please try again.';
            }

            if ($error === '') {
                log_activity(
                    $pdo,
                    'Acknowledged Data Privacy Notice',
                    'Application APP-' . str_pad((string) $applicationId, 3, '0', STR_PAD_LEFT)
                        . ' of ' . ($application['full_name'] ?? 'adopter')
                        . ' - agreed not to misuse personal information or valid ID (RA 10173)'
                );
                $_SESSION['sp_privacy_ack'][$applicationId] = time();
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit();
            }
        } elseif (!$agreed) {
            $error = 'Please tick the box to confirm that you have read and understood the notice.';
        } else {
            $error = 'Your session has expired. Please try again.';
        }
    }

    sp_privacy_render($application, $backUrl, $token, $error);
    exit();
}

function sp_privacy_render(array $application, string $backUrl, string $token, string $error): void
{
    $appNo = 'APP-' . str_pad((string) $application['application_id'], 3, '0', STR_PAD_LEFT);
    $viewer = $_SESSION['username'] ?? 'User';
    $role = $_SESSION['role'] ?? '';
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Data Privacy Notice - Smart Pet</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1f2937;
            background: #f5e7c6;
        }

        /* Sa likod: ang page na pinanggalingan (Requests / Applications), malabo
           at hindi napipindot - parang pop-up ang notice sa ibabaw nito. Hindi
           kasama rito ang laman ng application. */
        .pn-bg {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
            pointer-events: none;
        }
        .pn-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 20, 0.45);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            animation: pnFade .25s ease;
        }
        @keyframes pnFade { from { opacity: 0; } to { opacity: 1; } }

        /* Ang notice: nasa gitna, at sa loob nito ang scroll kapag mahaba */
        .pn-wrap {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 16px;
        }
        .pn-card {
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 760px;
            max-height: calc(100vh - 40px);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 30px 70px rgba(0, 0, 0, .35);
            overflow: hidden;
            animation: pnIn .35s cubic-bezier(.22, 1, .36, 1);
        }
        .pn-scroll {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            scrollbar-width: none;      /* walang nakikitang scrollbar, gaya ng ibang admin page */
        }
        .pn-scroll::-webkit-scrollbar { display: none; }
        .pn-head, .pn-foot { flex-shrink: 0; }
        .pn-foot { border-top: 1px solid #eef0f3; }
        @keyframes pnIn { from { opacity: 0; transform: translateY(14px) scale(.98); } to { opacity: 1; transform: none; } }

        .pn-head {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 22px 26px;
            background: linear-gradient(135deg, #7f1d1d 0%, #b42318 100%);
            color: #fff;
        }
        .pn-head-icon {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .25);
            font-size: 22px;
        }
        .pn-head small {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            opacity: .85;
        }
        .pn-head h1 { font-size: 21px; font-weight: 800; line-height: 1.25; }
        .pn-app {
            margin-left: auto;
            text-align: right;
            font-size: 12px;
            opacity: .9;
            white-space: nowrap;
        }
        .pn-app b { display: block; font-size: 14px; }

        .pn-body { padding: 22px 26px 8px; }
        .pn-lead { font-size: 14.5px; line-height: 1.6; color: #374151; }
        .pn-lead b { color: #111827; }

        .pn-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin: 18px 0;
        }
        .pn-box {
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .pn-box h2 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .4px;
            text-transform: uppercase;
        }
        .pn-box ul { list-style: none; display: flex; flex-direction: column; gap: 6px; }
        .pn-box li { position: relative; padding-left: 22px; font-size: 13.5px; line-height: 1.45; color: #374151; }
        .pn-box li i { position: absolute; left: 0; top: 3px; font-size: 13px; }
        .pn-dont { border-color: #f5c2c2; background: #fff7f7; }
        .pn-dont h2, .pn-dont li i { color: #b42318; }
        .pn-do { border-color: #cfe5d8; background: #f6fbf8; }
        .pn-do h2, .pn-do li i { color: #1f6b48; }

        .pn-law {
            border: 1px solid #fde2c4;
            border-left: 4px solid #d9822b;
            border-radius: 12px;
            background: #fffaf3;
            padding: 14px 16px;
        }
        .pn-law h2 {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .4px;
            text-transform: uppercase;
            color: #92400e;
        }
        .pn-law p { margin: 6px 0 10px; font-size: 13.5px; line-height: 1.5; color: #374151; }
        .pn-law table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .pn-law th {
            padding: 7px 8px;
            text-align: left;
            font-size: 11px;
            letter-spacing: .4px;
            text-transform: uppercase;
            color: #92400e;
            border-bottom: 1px solid #fcd9b0;
        }
        .pn-law td { padding: 7px 8px; border-bottom: 1px solid #fdecd6; vertical-align: top; color: #374151; }
        .pn-law tr:last-child td { border-bottom: none; }
        .pn-law td:first-child { font-weight: 700; color: #111827; white-space: nowrap; }
        .pn-law .note { margin: 10px 0 0; font-size: 11.5px; color: #6b7280; }

        .pn-foot { padding: 16px 26px 22px; }
        .pn-error {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #fdecea;
            color: #9b2226;
            font-size: 13.5px;
            font-weight: 600;
        }
        .pn-check {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 16px;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            cursor: pointer;
            transition: border-color .2s ease, background-color .2s ease;
        }
        .pn-check:hover { border-color: #9ca3af; }
        .pn-check input { width: 20px; height: 20px; margin-top: 1px; flex-shrink: 0; accent-color: #1f6b48; cursor: pointer; }
        .pn-check span { font-size: 13.5px; line-height: 1.5; color: #1f2937; }
        .pn-check.is-checked { border-color: #1f6b48; background: #f6fbf8; }

        .pn-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .pn-record { font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 6px; }
        .pn-btns { display: flex; gap: 10px; margin-left: auto; }
        .pn-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 44px;
            padding: 0 20px;
            border-radius: 11px;
            font-family: inherit;
            font-size: 14.5px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: 1.5px solid #d1d5db;
            background: #fff;
            color: #374151;
            transition: background-color .2s ease, filter .2s ease, opacity .2s ease;
        }
        .pn-btn:hover { background: #f3f4f6; }
        .pn-btn.primary {
            border-color: transparent;
            color: #fff;
            background: linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%);
            box-shadow: 0 8px 18px rgba(27, 67, 50, .25);
        }
        .pn-btn.primary:hover { filter: brightness(1.08); }
        .pn-btn.primary:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; filter: none; }
        .pn-btn:focus-visible, .pn-check input:focus-visible { outline: 3px solid rgba(45, 106, 79, .35); outline-offset: 2px; }

        @media (max-width: 680px) {
            .pn-grid { grid-template-columns: 1fr; }
            .pn-head { flex-wrap: wrap; }
            .pn-app { margin-left: 0; text-align: left; }
            .pn-btns { width: 100%; }
            .pn-btn { flex: 1; justify-content: center; }
            .pn-law td:first-child { white-space: normal; }
        }
        @media (prefers-reduced-motion: reduce) { .pn-card { animation: none; } }
    </style>
</head>
<body>
    <!-- Ang page na pinanggalingan, sa likod ng notice (hindi napipindot) -->
    <iframe class="pn-bg" src="<?php echo $h($backUrl); ?>" title="" aria-hidden="true" tabindex="-1"></iframe>
    <div class="pn-overlay" aria-hidden="true"></div>

    <div class="pn-wrap">
    <form class="pn-card" method="post" action="<?php echo $h($_SERVER['REQUEST_URI']); ?>" role="dialog" aria-modal="true" aria-labelledby="pnTitle">
        <header class="pn-head">
            <span class="pn-head-icon" aria-hidden="true"><i class="fa-solid fa-user-shield"></i></span>
            <div>
                <small>Confidential &middot; Restricted Access</small>
                <h1 id="pnTitle">Data Privacy Notice</h1>
            </div>
            <div class="pn-app">
                Application
                <b><?php echo $h($appNo); ?></b>
            </div>
        </header>

        <div class="pn-scroll">
        <div class="pn-body">
            <p class="pn-lead">
                You are about to view the adoption application of <b><?php echo $h($application['full_name'] ?? 'an adopter'); ?></b>.
                It contains <b>personal and sensitive personal information</b> &mdash; including a <b>government-issued valid ID</b>,
                barangay certificate, home photos, address, contact details, and birthdate. This information is entrusted to the
                shelter <b>only for evaluating this adoption application</b>.
            </p>

            <div class="pn-grid">
                <section class="pn-box pn-dont">
                    <h2><i class="fa-solid fa-ban"></i> Strictly prohibited</h2>
                    <ul>
                        <li><i class="fa-solid fa-xmark"></i> Using the adopter's valid ID, name, or details for any other purpose (e.g., loans, accounts, registrations, verification)</li>
                        <li><i class="fa-solid fa-xmark"></i> Taking screenshots, photos, downloading, or copying the ID and documents</li>
                        <li><i class="fa-solid fa-xmark"></i> Sharing, posting, or sending the information to anyone outside the review</li>
                        <li><i class="fa-solid fa-xmark"></i> Contacting the adopter for matters unrelated to the adoption</li>
                    </ul>
                </section>
                <section class="pn-box pn-do">
                    <h2><i class="fa-solid fa-circle-check"></i> Your responsibility</h2>
                    <ul>
                        <li><i class="fa-solid fa-check"></i> View the information only to evaluate this application</li>
                        <li><i class="fa-solid fa-check"></i> Keep it confidential, even after the application is decided</li>
                        <li><i class="fa-solid fa-check"></i> Do not leave the screen open or unattended</li>
                        <li><i class="fa-solid fa-check"></i> Report any suspected misuse or data leak to the administrator immediately</li>
                    </ul>
                </section>
            </div>

            <section class="pn-law">
                <h2><i class="fa-solid fa-scale-balanced"></i> Legal basis &amp; penalties</h2>
                <p>
                    Misuse of this information is a <b>criminal offense</b> under the <b>Data Privacy Act of 2012 (Republic Act No. 10173)</b>.
                    Government-issued IDs are classified as <b>sensitive personal information</b>, which carries heavier penalties.
                    Violators may be <b>held liable and charged in court</b>.
                </p>
                <table>
                    <thead>
                        <tr><th>Violation (RA 10173)</th><th>Penalty</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Sec. 28 &ndash; Processing for unauthorized purposes</td><td>2 to 7 years imprisonment and a fine of &#8369;500,000 to &#8369;2,000,000 (sensitive personal information, such as a government ID)</td></tr>
                        <tr><td>Sec. 29 &ndash; Unauthorized access or intentional breach</td><td>1 to 3 years imprisonment and a fine of &#8369;500,000 to &#8369;2,000,000</td></tr>
                        <tr><td>Sec. 31 &ndash; Malicious disclosure</td><td>1 year and 6 months to 5 years imprisonment and a fine of &#8369;500,000 to &#8369;1,000,000</td></tr>
                        <tr><td>Sec. 32 &ndash; Unauthorized disclosure</td><td>3 to 5 years imprisonment and a fine of &#8369;500,000 to &#8369;2,000,000 (sensitive personal information)</td></tr>
                    </tbody>
                </table>
                <p class="note">
                    Using another person's identity or ID without permission may also be prosecuted as <b>computer-related identity theft</b>
                    under the <b>Cybercrime Prevention Act of 2012 (RA 10175), Sec. 4(b)(3)</b>. This notice is a summary for awareness;
                    refer to the full text of the laws for complete provisions.
                </p>
            </section>
        </div>
        </div>

        <div class="pn-foot">
            <?php if ($error !== ''): ?>
                <div class="pn-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $h($error); ?></div>
            <?php endif; ?>

            <input type="hidden" name="sp_privacy_ack" value="1">
            <input type="hidden" name="sp_privacy_token" value="<?php echo $h($token); ?>">
            <input type="hidden" name="sp_privacy_app" value="<?php echo (int) $application['application_id']; ?>">

            <label class="pn-check" id="pnCheckBox">
                <input type="checkbox" name="sp_privacy_agree" value="1" id="pnAgree">
                <span>
                    I, <b><?php echo $h($viewer); ?></b><?php echo $role !== '' ? ' (' . $h($role) . ')' : ''; ?>, have read and understood this notice.
                    I will use this applicant's information and valid ID <b>solely for evaluating this adoption application</b>, and I
                    understand that I may be held <b>legally liable under RA 10173</b> if I misuse, copy, or disclose it.
                </span>
            </label>

            <div class="pn-actions">
                <span class="pn-record">
                    <i class="fa-solid fa-fingerprint"></i>
                    Your agreement is recorded with your account, the date and time, and your IP address.
                </span>
                <div class="pn-btns">
                    <a class="pn-btn" href="<?php echo $h($backUrl); ?>"><i class="fa-solid fa-arrow-left"></i> Go back</a>
                    <button type="submit" class="pn-btn primary" id="pnContinue" disabled>
                        <i class="fa-solid fa-lock-open"></i> I Agree &amp; Continue
                    </button>
                </div>
            </div>
        </div>
    </form>
    </div>

    <script>
        // Mapipindot lang ang "I Agree & Continue" kapag naka-check ang kahon
        (function () {
            var box = document.getElementById('pnAgree');
            var btn = document.getElementById('pnContinue');
            var wrap = document.getElementById('pnCheckBox');
            function sync() {
                btn.disabled = !box.checked;
                wrap.classList.toggle('is-checked', box.checked);
            }
            box.addEventListener('change', sync);
            sync();
        })();
    </script>
</body>
</html>
<?php
}
