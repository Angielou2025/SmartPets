<?php
/**
 * Account Settings modal. Dalawang mode:
 *   setup - kusang lumalabas sa search_pets.php pagkatapos ng loading screen
 *           (welcome_intro.php) kapag kulang pa ang profile. Pagkasara (Submit
 *           o X), saka lalabas ang "Punan ang Reference" popup.
 *   edit  - nakatago; bumubukas kapag pinindot ang profile sa itaas ng kahit
 *           anong Adopter page (isinasama ng connection/chat_bubble.php).
 * Pagkasara ng setup, nagiging edit mode ito para mabuksan ulit sa profile.
 *
 * Kailangan: $psUser = ['username', 'email', 'recovery_email', 'profile_image']
 * Sa Adopter (berde) at Staff (asul), may pagpipilian din ng wika (English | Tagalog) - tingnan ang connection/i18n.php
 * Opsyonal:  $psMode = 'setup' (default) o 'edit'
 * Ang server side ay nasa profile_setup.php.
 */
if (!empty($GLOBALS['sp_profile_modal_rendered'])) {
    return; // isang beses lang sa bawat page
}
$GLOBALS['sp_profile_modal_rendered'] = true;

$psMode = ($psMode ?? 'setup') === 'edit' ? 'edit' : 'setup';
// Kulay: 'green' (Adopter), 'blue' (Staff) o 'orange' (Admin)
$psTheme = in_array($psTheme ?? 'green', ['blue', 'orange'], true) ? $psTheme : 'green';
// Saan ipapadala ang form - buong URL para gumana kahit nasa StaffFile ang page
require_once __DIR__ . '/../connection/app_url.php';
$psEndpoint = sp_base_url() . '/Adopter_File/profile_setup.php';
$psName = $psUser['username'] ?? '';
// Buong path, para gumana rin sa mga page na nasa subfolder (hal. AdminFile/Manage_staff_file)
$psPhoto = !empty($psUser['profile_image']) ? sp_base_url() . '/uploads/' . $psUser['profile_image'] : '';
$psInitial = strtoupper(substr($psName !== '' ? $psName : 'U', 0, 1));
// Wika (Adopter at Staff): English o Tagalog sa buong site
$psShowLang = in_array($psTheme, ['green', 'blue'], true);
$psLang = ($psShowLang && isset($pdo) && function_exists('sp_user_lang') && isset($_SESSION['user_id']))
    ? sp_user_lang($pdo, (int) $_SESSION['user_id'])
    : 'en';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<?php if ($psMode === 'setup'): ?>
<!-- Kapareho ng berdeng "kurtina" sa dulo ng loading screen (welcome_intro.php),
     kaya tuloy-tuloy ang paglipat: dahan-dahan itong naglalaho dito -->
<div class="ps-curtain" aria-hidden="true"></div>
<?php endif; ?>

<div class="ps-overlay ps-theme-<?php echo $psTheme; ?> <?php echo $psMode === 'setup' ? 'open setup' : 'edit'; ?>" id="psOverlay" data-mode="<?php echo $psMode; ?>"
     data-endpoint="<?php echo htmlspecialchars($psEndpoint); ?>"
     aria-hidden="<?php echo $psMode === 'setup' ? 'false' : 'true'; ?>">
    <div class="ps-modal" role="dialog" aria-modal="true" aria-labelledby="psTitle">
        <div class="ps-head">
            <!-- Palamuting paw prints na dahan-dahang lumulutang -->
            <svg class="ps-paws" viewBox="0 0 440 150" aria-hidden="true">
                <defs>
                    <g id="psPaw">
                        <ellipse cx="-9" cy="-6" rx="3.6" ry="5" transform="rotate(-18 -9 -6)"/>
                        <ellipse cx="-3" cy="-12" rx="3.3" ry="4.6"/>
                        <ellipse cx="4" cy="-12" rx="3.3" ry="4.6"/>
                        <ellipse cx="10" cy="-6" rx="3.6" ry="5" transform="rotate(18 10 -6)"/>
                        <path d="M0.5,-3 C6,-4 11,1 9,7 C7,11 -6,11 -8,7 C-10,1 -5,-4 0.5,-3 Z"/>
                    </g>
                </defs>
                <use href="#psPaw" class="pw p1" transform="translate(300 40) rotate(20) scale(1.6)"/>
                <use href="#psPaw" class="pw p2" transform="translate(360 95) rotate(-15) scale(2.2)"/>
                <use href="#psPaw" class="pw p3" transform="translate(410 35) rotate(35) scale(1.2)"/>
                <use href="#psPaw" class="pw p4" transform="translate(60 110) rotate(-25) scale(1.4)"/>
                <use href="#psPaw" class="pw p5" transform="translate(150 125) rotate(10) scale(1)"/>
            </svg>
            <div class="ps-head-text">
                <h2 id="psTitle">Account Settings</h2>
                <p id="psSubtitle"><?php echo $psMode === 'setup' ? 'Set up your profile before you start adopting' : 'Update your profile and recovery details'; ?></p>
            </div>
            <button type="button" class="ps-close" id="psClose" aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="ps-sheet">
            <!-- Photo: i-click para pumili ng larawan -->
            <label class="ps-photo" for="psPhotoInput" title="Change photo">
                <?php if ($psPhoto !== ''): ?>
                    <img src="<?php echo htmlspecialchars($psPhoto); ?>" alt="" id="psPhotoImg">
                    <span class="ps-photo-empty" id="psPhotoEmpty" hidden><?php echo htmlspecialchars($psInitial); ?></span>
                <?php else: ?>
                    <img src="" alt="" id="psPhotoImg" hidden>
                    <span class="ps-photo-empty" id="psPhotoEmpty">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8.5" r="4"/><path d="M4.5 20c0-4.1 3.4-6.6 7.5-6.6s7.5 2.5 7.5 6.6"/></svg>
                        <span>Add photo</span>
                    </span>
                <?php endif; ?>
                <span class="ps-photo-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                </span>
            </label>

            <!-- ===== Hakbang 1: profile ===== -->
            <div class="ps-step" id="psStepForm">
                <div class="ps-name">
                    <span id="psNameText"><?php echo htmlspecialchars($psName); ?></span>
                    <input type="text" id="psNameInput" maxlength="40" value="<?php echo htmlspecialchars($psName); ?>" aria-label="Your name" hidden>
                    <button type="button" class="ps-edit" id="psEditName" aria-label="Edit name">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M13.5 8.5l3 3"/><path d="M14 4H6a2 2 0 0 0-2 2v12"/></svg>
                    </button>
                </div>

                <form id="psForm" novalidate>
                    <div class="ps-error" id="psError" hidden></div>
                    <input type="file" id="psPhotoInput" name="profile_image" accept="image/png,image/jpeg,image/gif,image/webp" hidden>

                    <label class="ps-label" for="psGmail">Your Gmail account</label>
                    <div class="ps-field">
                        <svg class="ps-field-icon gmail" viewBox="0 0 24 24" aria-hidden="true">
                            <path class="g-red" d="M3 7l9 6.8L21 7v-.5A1.5 1.5 0 0 0 18.6 5.3L12 10.3 5.4 5.3A1.5 1.5 0 0 0 3 6.5z"/>
                            <path class="g-blue" d="M3 7v10.5A1.5 1.5 0 0 0 4.5 19H7v-9.2z"/>
                            <path class="g-green" d="M21 7v10.5a1.5 1.5 0 0 1-1.5 1.5H17v-9.2z"/>
                        </svg>
                        <input class="ps-input" type="email" id="psGmail" value="<?php echo htmlspecialchars($psUser['email'] ?? ''); ?>" readonly>
                        <span class="ps-badge">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>
                            Verified
                        </span>
                    </div>

                    <label class="ps-label" for="psRecovery">Add recovery Email</label>
                    <div class="ps-field">
                        <svg class="ps-field-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></svg>
                        <input class="ps-input" type="email" id="psRecovery" name="recovery_email" placeholder="example@gmail.com" autocomplete="email"
                               value="<?php echo htmlspecialchars($psUser['recovery_email'] ?? ''); ?>">
                    </div>
                    <p class="ps-hint">Used to recover your account if you can&rsquo;t open your Gmail. Optional.</p>

                    <?php if ($psShowLang): ?>
                    <!-- Wika ng buong site para sa adopter (dropdown). data-no-i18n: hindi isinasalin ang mga pangalan ng wika -->
                    <label class="ps-label" for="psLang">Language</label>
                    <div class="ps-field ps-lang-field" data-no-i18n>
                        <svg class="ps-field-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/></svg>
                        <select class="ps-input ps-select" id="psLang" name="language">
                            <option value="en"<?php echo $psLang === 'en' ? ' selected' : ''; ?>>English</option>
                            <option value="tl"<?php echo $psLang === 'tl' ? ' selected' : ''; ?>>Tagalog</option>
                        </select>
                        <svg class="ps-select-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                    </div>
                    <?php endif; ?>

                    <div class="ps-actions">
                        <button type="submit" class="ps-submit" id="psSubmit">Submit</button>
                    </div>
                </form>
            </div>

            <!-- ===== Hakbang 2: code ng recovery email ===== -->
            <div class="ps-step" id="psStepOtp" hidden>
                <div class="ps-otp-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                </div>
                <h3 class="ps-otp-title">Verify your recovery email</h3>
                <p class="ps-otp-text">We sent a 6-digit code to <strong id="psMasked"></strong>.</p>

                <form action="<?php echo htmlspecialchars($psEndpoint); ?>" method="POST" id="psOtpForm" data-ajax-otp data-otp-event>
                    <input type="hidden" name="action" value="verify">
                    <input type="hidden" name="otp" id="psOtpValue">
                    <div class="otp-boxes" data-target="psOtpValue" role="group" aria-label="6-digit code">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" class="otp-box" inputmode="numeric" maxlength="6"
                                   aria-label="Digit <?php echo $i + 1; ?>" autocomplete="<?php echo $i === 0 ? 'one-time-code' : 'off'; ?>">
                        <?php endfor; ?>
                    </div>
                    <button type="submit" class="ps-submit ps-wide" name="verify_btn">Verify</button>
                </form>

                <div class="ps-links">
                    <button type="button" class="ps-link" id="psResend">Send again</button>
                    <span aria-hidden="true">&middot;</span>
                    <button type="button" class="ps-link" id="psChangeEmail">Change email</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .ps-curtain {
        position: fixed;
        inset: 0;
        z-index: 10021;
        background: #1b4d2e; /* kapareho ng --green-dark sa welcome_intro.php */
        pointer-events: none;
        animation: psFadeOut .8s ease .05s forwards;
    }

    /* ===== Account Settings modal ===== */
    .ps-overlay {
        --ps-green: #3f6f2c;
        --ps-green-dark: #1f5410;
        --ps-orange: #f7921e;
        position: fixed;
        inset: 0;
        z-index: 10020; /* nasa ibabaw ng mobile bar (9990) at sidebar (10001) */
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 30, 20, 0.55);
        backdrop-filter: blur(4px);
        opacity: 0;
        animation: psFadeIn .5s ease .15s forwards;
        font-family: 'Inter', 'Segoe UI', sans-serif;
    }
    .ps-overlay.open { display: flex; }
    .ps-overlay.closing { animation: psFadeOut .45s ease forwards; }

    /* Edit mode (pinindot ang profile): mas mabilis bumukas */
    .ps-overlay.edit { animation-delay: 0s; animation-duration: .3s; }
    .ps-overlay.edit .ps-modal { animation-delay: .05s; animation-duration: .55s; }
    .ps-overlay.edit .ps-photo { animation-delay: .3s; }

    .ps-modal {
        position: relative;
        width: 100%;
        max-width: 440px;
        max-height: calc(100vh - 32px);
        max-height: calc(100dvh - 32px);
        overflow-y: auto;
        overscroll-behavior: contain;
        border-radius: 24px;
        background: #fff;
        box-shadow: 0 30px 80px rgba(10, 40, 15, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08);
        opacity: 0;
        transform: translateY(40px) scale(.95);
        animation: psRise .7s cubic-bezier(.22, 1, .36, 1) .3s forwards;
    }
    .ps-overlay.closing .ps-modal { animation: psSink .45s cubic-bezier(.65, 0, .35, 1) forwards; }

    /* Berdeng header na may lumulutang na paw prints */
    .ps-head {
        position: relative;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        padding: 18px 18px 96px 22px;
        overflow: hidden;
        background:
            radial-gradient(circle at 85% 0%, rgba(247, 146, 30, 0.45), transparent 45%),
            radial-gradient(circle at 0% 100%, rgba(150, 210, 110, 0.35), transparent 50%),
            linear-gradient(135deg, #1f5410 0%, #3f6f2c 55%, #5f8f35 100%);
    }
    .ps-paws {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
    }
    .ps-paws .pw { fill: rgba(255, 255, 255, 0.13); }
    .ps-paws .p2 { fill: rgba(255, 196, 120, 0.22); }
    .ps-paws .p1, .ps-paws .p4 { animation: psPawFloat 6s ease-in-out infinite; }
    .ps-paws .p2, .ps-paws .p5 { animation: psPawFloat 7s ease-in-out -2s infinite; }
    .ps-paws .p3 { animation: psPawFloat 5s ease-in-out -1s infinite; }
    .ps-paws use { transform-box: fill-box; transform-origin: center; }

    .ps-head-text { position: relative; }
    .ps-head h2 {
        margin: 0;
        font-size: 17px;
        font-weight: 700;
        color: #fff;
        letter-spacing: .2px;
    }
    .ps-head-text p {
        margin: 3px 0 0;
        font-size: 12.5px;
        color: rgba(255, 255, 255, 0.78);
    }
    .ps-close {
        position: relative;
        width: 32px; height: 32px;
        margin: -4px -4px 0 0;
        border: none; border-radius: 10px;
        background: rgba(255, 255, 255, 0.14);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: background .2s, transform .2s;
    }
    .ps-close:hover { background: rgba(255, 255, 255, 0.26); transform: rotate(90deg); }
    .ps-close svg { width: 16px; height: 16px; fill: none; stroke: #fff; stroke-width: 2.6; stroke-linecap: round; }

    .ps-sheet {
        position: relative;
        margin-top: -26px;
        background:
            radial-gradient(circle at 50% 0%, #f4f8f0 0%, rgba(244, 248, 240, 0) 55%),
            #fff;
        border-radius: 26px 26px 0 0;
        padding: 74px 30px 30px;
        box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.08);
    }

    /* Photo na may orange-berdeng singsing, nakapatong sa gilid ng sheet */
    .ps-photo {
        position: absolute;
        top: -62px;
        left: 50%;
        width: 124px;
        height: 124px;
        margin-left: -62px;
        border-radius: 50%;
        padding: 4px;
        background: conic-gradient(from 200deg, #f7921e, #ffc46b, #7fbf5a, #3f6f2c, #f7921e);
        box-shadow: 0 10px 28px rgba(63, 111, 44, 0.35), 0 0 0 6px #fff;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transform: scale(.4);
        opacity: 0;
        animation: psPop .6s cubic-bezier(.34, 1.56, .64, 1) .65s forwards;
    }
    .ps-photo img,
    .ps-photo-empty {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        border: 3px solid #fff;
        box-sizing: border-box;
    }
    .ps-photo img { object-fit: cover; background: #fff; }
    .ps-photo-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        background: linear-gradient(160deg, #f1f7ec, #dcebd1);
        color: var(--ps-green);
        font-family: 'Fraunces', Georgia, serif;
        font-size: 26px;
        font-weight: 600;
    }
    .ps-photo-empty[hidden] { display: none; }
    .ps-photo-empty svg { width: 40px; height: 40px; fill: none; stroke: var(--ps-green); stroke-width: 1.6; stroke-linecap: round; }
    .ps-photo-empty span { font-family: 'Inter', sans-serif; font-size: 11px; font-weight: 600; letter-spacing: .3px; }
    .ps-photo-badge {
        position: absolute;
        right: 2px; bottom: 6px;
        width: 34px; height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, #ffad4a, #f07a12);
        border: 3px solid #fff;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 4px 10px rgba(240, 122, 18, 0.45);
        transition: transform .2s;
    }
    .ps-photo:hover .ps-photo-badge { transform: scale(1.12) rotate(-8deg); }
    .ps-photo-badge svg { width: 16px; height: 16px; fill: none; stroke: #fff; stroke-width: 2; stroke-linejoin: round; }

    .ps-step { animation: psStepIn .45s cubic-bezier(.22, 1, .36, 1); }
    .ps-step[hidden] { display: none; }

    .ps-name {
        display: flex; align-items: center; justify-content: center;
        gap: 10px;
        margin-bottom: 24px;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 25px;
        font-weight: 600;
        color: var(--ps-green-dark);
    }
    .ps-name input {
        width: 200px;
        font: inherit;
        color: inherit;
        text-align: center;
        border: none;
        border-bottom: 2px solid var(--ps-orange);
        outline: none;
        padding: 2px 4px;
        background: transparent;
    }
    .ps-edit {
        width: 34px; height: 34px;
        border: none; border-radius: 10px;
        background: #fff4e6;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        transition: background .2s, transform .2s;
    }
    .ps-edit:hover { background: #ffe6c7; transform: rotate(-8deg); }
    .ps-edit svg { width: 19px; height: 19px; fill: none; stroke: #e07a10; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    .ps-label {
        display: block;
        margin: 0 0 8px 4px;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 15px;
        font-weight: 500;
        color: #2e4a22;
    }

    .ps-field { position: relative; margin-bottom: 18px; }
    .ps-field-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        width: 20px;
        height: 20px;
        transform: translateY(-50%);
        fill: none;
        stroke: var(--ps-green);
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
        pointer-events: none;
    }
    .ps-field-icon.gmail { stroke: none; }
    .ps-field-icon .g-red { fill: #ea4335; }
    .ps-field-icon .g-blue { fill: #4285f4; }
    .ps-field-icon .g-green { fill: #34a853; }

    .ps-input {
        width: 100%;
        height: 46px;
        margin: 0;
        padding: 0 16px 0 44px;
        border: 1.5px solid #d6e3cc;
        border-radius: 12px;
        font-family: inherit;
        font-size: 13.5px;
        color: #1c2b17;
        background: #f8fbf5;
        box-sizing: border-box;
        transition: border-color .2s, box-shadow .2s, background .2s;
    }
    .ps-input::placeholder { color: #9aab90; }
    .ps-input[readonly] { color: #4c5f44; background: #eef5e8; padding-right: 96px; cursor: default; }
    .ps-input:focus { outline: none; border-color: var(--ps-green); background: #fff; box-shadow: 0 0 0 4px rgba(63, 111, 44, .14); }

    .ps-badge {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 9px;
        border-radius: 999px;
        background: var(--ps-green);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
    }
    .ps-badge svg { width: 12px; height: 12px; fill: none; stroke: #fff; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }

    .ps-hint { margin: -10px 0 18px 4px; font-size: 12px; color: #8a9a80; }

    .ps-actions { display: flex; justify-content: flex-end; margin-top: 18px; }
    .ps-submit {
        min-width: 150px;
        height: 46px;
        padding: 0 24px;
        border: none;
        border-radius: 12px;
        background: linear-gradient(135deg, #ffad4a 0%, #f7921e 50%, #e8740c 100%);
        color: #fff;
        font-family: inherit;
        font-size: 15px;
        font-weight: 700;
        letter-spacing: .2px;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(240, 122, 18, 0.38), inset 0 1px 0 rgba(255, 255, 255, 0.35);
        transition: transform .15s, box-shadow .2s, filter .2s;
    }
    .ps-submit:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(240, 122, 18, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.35); filter: saturate(1.1); }
    .ps-submit:active { transform: translateY(0); }
    .ps-submit:disabled { opacity: .8; cursor: default; transform: none; }
    .ps-submit.is-saved, .ps-submit.is-verified {
        background: linear-gradient(135deg, #5f8f35, #3f6f2c);
        box-shadow: 0 8px 20px rgba(63, 111, 44, 0.38);
    }
    .ps-wide { width: 100%; }

    .ps-error {
        margin-bottom: 16px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #fff0ee;
        border: 1px solid #f6c8c1;
        color: #c0392b;
        font-size: 13px;
        text-align: center;
    }
    .ps-error[hidden] { display: none; }

    .ps-otp-icon {
        width: 56px; height: 56px;
        margin: 0 auto 12px;
        border-radius: 18px;
        background: linear-gradient(135deg, #fff4e6, #ffe2bd);
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 6px 16px rgba(240, 122, 18, 0.2);
    }
    .ps-otp-icon svg { width: 28px; height: 28px; fill: none; stroke: #e8740c; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ps-otp-title { margin: 0 0 6px; text-align: center; font-family: 'Fraunces', Georgia, serif; font-size: 21px; font-weight: 600; color: var(--ps-green-dark); }
    .ps-otp-text { margin: 0 0 18px; text-align: center; font-size: 13px; color: #6b7a63; }
    .ps-otp-text strong { color: #e8740c; }    .ps-links { display: flex; justify-content: center; gap: 10px; margin-top: 14px; font-size: 13px; color: #bbb; }
    .ps-link { border: none; background: none; padding: 0; font: inherit; font-weight: 600; color: var(--ps-green); cursor: pointer; }
    .ps-link:hover:not(:disabled) { text-decoration: underline; }
    .ps-link:disabled { color: #9a9a9a; cursor: default; }

    /* OTP boxes (kapareho ng nasa Login_Page/login.css) */
    #psOverlay .otp-boxes { position: relative; display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-bottom: 18px; }
    #psOverlay .otp-box {
        width: 100%; min-width: 0; height: 50px;
        border: 1px solid #9a9a9a; border-radius: 10px; background: #fff;
        text-align: center; font-size: 22px; font-weight: 600; color: var(--ps-green-dark);
        transition: border-color .15s, box-shadow .15s, background .15s;
    }
    #psOverlay .otp-box:focus { outline: none; border-color: var(--ps-green); box-shadow: 0 0 0 3px rgba(63, 111, 44, .15); }
    #psOverlay .otp-box.filled { border-color: var(--ps-green); background: #f4f8f0; }
    #psOverlay .otp-boxes.error .otp-box { border-color: #e05545; background: #fff3f1; color: #c0392b; }
    #psOverlay .otp-boxes.shake { animation: psShake .35s ease; }
    #psOverlay .otp-boxes.checking .otp-box { animation: psBob .9s ease-in-out infinite; }
    #psOverlay .otp-boxes.checking .otp-box:nth-child(2) { animation-delay: .08s; }
    #psOverlay .otp-boxes.checking .otp-box:nth-child(3) { animation-delay: .16s; }
    #psOverlay .otp-boxes.checking .otp-box:nth-child(4) { animation-delay: .24s; }
    #psOverlay .otp-boxes.checking .otp-box:nth-child(5) { animation-delay: .32s; }
    #psOverlay .otp-boxes.checking .otp-box:nth-child(6) { animation-delay: .40s; }
    #psOverlay .otp-boxes.merging .otp-box {
        transform: translateX(var(--to, 0)) scale(.62);
        border-radius: 50%; border-color: var(--ps-green); background: var(--ps-green); color: transparent;
        transition: transform .5s cubic-bezier(.65, 0, .35, 1), border-radius .35s, background .3s, color .2s;
    }
    #psOverlay .otp-boxes.merged .otp-box { opacity: 0; transition: opacity .15s; }
    #psOverlay .otp-check {
        position: absolute; left: 50%; top: 50%;
        width: 64px; height: 64px; margin: -32px 0 0 -32px;
        border-radius: 50%; background: var(--ps-green);
        box-shadow: 0 10px 24px rgba(63, 111, 44, .4);
        transform: scale(0); pointer-events: none;
    }
    #psOverlay .otp-boxes.merged .otp-check { animation: psCheckPop .55s cubic-bezier(.34, 1.56, .64, 1) forwards; }
    #psOverlay .otp-check::before, #psOverlay .otp-check::after {
        content: ""; position: absolute; inset: 0; border-radius: 50%; border: 3px solid var(--ps-green); opacity: 0;
    }
    #psOverlay .otp-boxes.merged .otp-check::before { animation: psRipple .9s ease-out .15s forwards; }
    #psOverlay .otp-boxes.merged .otp-check::after { animation: psRipple .9s ease-out .35s forwards; }
    #psOverlay .otp-check svg { position: relative; width: 100%; height: 100%; }
    #psOverlay .otp-check-circle { fill: none; }
    #psOverlay .otp-check-mark { fill: none; stroke: #fff; stroke-width: 4.5; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 40; stroke-dashoffset: 40; }
    #psOverlay .otp-boxes.merged .otp-check-mark { animation: psDraw .4s cubic-bezier(.65, 0, .35, 1) .3s forwards; }
    #psOverlay .otp-verified { margin: -4px 0 14px; text-align: center; font-family: 'Fraunces', Georgia, serif; font-size: 17px; color: var(--ps-green-dark); opacity: 0; transition: opacity .4s; }
    #psOverlay .otp-verified.show { opacity: 1; }
    /* Ang error ng otp_boxes.js ay inilalagay bilang .error */
    #psOverlay .error { margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; background: #ffe5e5; color: #c0392b; font-size: 13px; text-align: center; }

    @keyframes psFadeIn { to { opacity: 1; } }
    @keyframes psPawFloat { 0%, 100% { translate: 0 0; } 50% { translate: 0 -6px; } }
    @keyframes psFadeOut { from { opacity: 1; } to { opacity: 0; } }
    @keyframes psRise { to { opacity: 1; transform: none; } }
    @keyframes psSink { from { opacity: 1; transform: none; } to { opacity: 0; transform: translateY(30px) scale(.96); } }
    @keyframes psPop { to { opacity: 1; transform: scale(1); } }
    @keyframes psStepIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
    @keyframes psShake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-6px); } 75% { transform: translateX(6px); } }
    @keyframes psBob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
    @keyframes psCheckPop { from { transform: scale(.35); } to { transform: scale(1); } }
    @keyframes psRipple { 0% { opacity: .7; transform: scale(1); } 100% { opacity: 0; transform: scale(1.9); } }
    @keyframes psDraw { to { stroke-dashoffset: 0; } }

    @media (max-width: 480px) {
        /* Buong modal na kita sa telepono: mas maliit na header at larawan,
           at sumusunod ang taas sa nakikitang screen (pati kapag bukas ang keyboard) */
        .ps-overlay { padding: 12px; align-items: center; }
        .ps-modal { max-height: calc(var(--ps-vh, 100dvh) - 24px); border-radius: 20px; }
        .ps-head { padding: 14px 14px 84px 18px; }
        .ps-sheet { padding: 54px 18px 20px; border-radius: 22px 22px 0 0; }
        .ps-photo { top: -46px; width: 92px; height: 92px; margin-left: -46px; }
        /* Naka-lock na Gmail: kasya ang buong address sa tabi ng "Verified" */
        #psGmail { font-size: 14px !important; }
        .ps-name { font-size: 20px; }
        /* 16px: hindi mag-zo-zoom ang iPhone kapag tinapik ang input */
        .ps-input, .ps-select, .ps-name input { font-size: 16px !important; }
        .ps-actions { margin-top: 14px; }
        .ps-submit {
            min-width: 0;
            width: 100%;
            height: 42px;
            padding: 0 18px;
            font-size: 14.5px;
            border-radius: 11px;
            box-shadow: 0 4px 12px rgba(240, 122, 18, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }
        #psOverlay .otp-boxes { gap: 5px; }
        #psOverlay .otp-box { height: 44px; font-size: 18px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .ps-overlay, .ps-modal, .ps-photo, .ps-step { animation-duration: .01s !important; animation-delay: 0s !important; }
    }

    /* ===== Wika: dropdown (English / Tagalog) ===== */
    .ps-lang-field { margin-bottom: 6px; }
    .ps-select {
        appearance: none;
        -webkit-appearance: none;
        padding-right: 40px;
        cursor: pointer;
        font-weight: 600;
    }
    .ps-select-caret {
        position: absolute;
        right: 14px;
        top: 50%;
        width: 18px;
        height: 18px;
        transform: translateY(-50%);
        fill: none;
        stroke: var(--ps-green);
        stroke-width: 2.2;
        stroke-linecap: round;
        stroke-linejoin: round;
        pointer-events: none;
        transition: transform .25s ease;
    }
    .ps-select:focus + .ps-select-caret { transform: translateY(-50%) rotate(180deg); }

    /* ===== Asul na bersyon (Staff) - kapareho ng kulay ng staff sidebar ===== */
    .ps-overlay.ps-theme-blue {
        --ps-green: #1e3a8a;
        --ps-green-dark: #172f73;
        --ps-orange: #2f5bc4;
        background: rgba(12, 22, 52, 0.55);
    }
    .ps-theme-blue .ps-modal { box-shadow: 0 30px 80px rgba(12, 22, 52, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08); }
    .ps-theme-blue .ps-head {
        background:
            radial-gradient(circle at 85% 0%, rgba(147, 197, 253, 0.45), transparent 45%),
            radial-gradient(circle at 0% 100%, rgba(96, 165, 250, 0.35), transparent 50%),
            linear-gradient(135deg, #172f73 0%, #1e3a8a 55%, #2f5bc4 100%);
    }
    .ps-theme-blue .ps-paws .p2 { fill: rgba(191, 219, 254, 0.25); }
    .ps-theme-blue .ps-sheet {
        background:
            radial-gradient(circle at 50% 0%, #eef4ff 0%, rgba(238, 244, 255, 0) 55%),
            #fff;
    }
    .ps-theme-blue .ps-photo {
        background: conic-gradient(from 200deg, #1e3a8a, #3b82f6, #93c5fd, #60a5fa, #1e3a8a);
        box-shadow: 0 10px 28px rgba(30, 58, 138, 0.35), 0 0 0 6px #fff;
    }
    .ps-theme-blue .ps-photo-empty { background: linear-gradient(160deg, #eef4ff, #d6e4ff); }
    .ps-theme-blue .ps-photo-badge {
        background: linear-gradient(135deg, #3b82f6, #1e3a8a);
        box-shadow: 0 4px 10px rgba(30, 58, 138, 0.45);
    }
    .ps-theme-blue .ps-edit { background: #eef4ff; }
    .ps-theme-blue .ps-edit:hover { background: #dbe7ff; }
    .ps-theme-blue .ps-edit svg { stroke: #1e3a8a; }
    .ps-theme-blue .ps-name input { border-bottom-color: #3b82f6; }
    .ps-theme-blue .ps-label { color: #1e2a5a; }
    .ps-theme-blue .ps-input { border-color: #cfdcf5; background: #f6f9ff; }
    .ps-theme-blue .ps-input::placeholder { color: #94a3c4; }
    .ps-theme-blue .ps-input[readonly] { color: #3b4a6b; background: #eaf0fc; }
    .ps-theme-blue .ps-input:focus { border-color: #1e3a8a; background: #fff; box-shadow: 0 0 0 4px rgba(30, 58, 138, .14); }
    .ps-theme-blue .ps-hint { color: #7d8aa8; }
    .ps-theme-blue .ps-submit {
        background: linear-gradient(135deg, #3b82f6 0%, #2f5bc4 50%, #1e3a8a 100%);
        box-shadow: 0 8px 20px rgba(30, 58, 138, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.3);
    }
    .ps-theme-blue .ps-submit:hover { box-shadow: 0 12px 26px rgba(30, 58, 138, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
    .ps-theme-blue .ps-submit.is-saved,
    .ps-theme-blue .ps-submit.is-verified {
        background: linear-gradient(135deg, #22c55e, #15803d);
        box-shadow: 0 8px 20px rgba(21, 128, 61, 0.35);
    }
    .ps-theme-blue .ps-otp-icon { background: linear-gradient(135deg, #eef4ff, #d6e4ff); box-shadow: 0 6px 16px rgba(30, 58, 138, 0.18); }
    .ps-theme-blue .ps-otp-icon svg { stroke: #1e3a8a; }
    .ps-theme-blue .ps-otp-text strong { color: #2f5bc4; }
    .ps-theme-blue .ps-otp-text { color: #5b6b8c; }
    #psOverlay.ps-theme-blue .otp-box { color: #172f73; }
    #psOverlay.ps-theme-blue .otp-box.filled { background: #eef4ff; }

    /* ===== Orange na bersyon (Admin) - kapareho ng kulay ng admin ===== */
    .ps-overlay.ps-theme-orange {
        --ps-green: #cc5600;
        --ps-green-dark: #a84600;
        --ps-orange: #ff8c1a;
        background: rgba(48, 24, 4, 0.55);
    }
    .ps-theme-orange .ps-modal { box-shadow: 0 30px 80px rgba(48, 24, 4, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08); }
    .ps-theme-orange .ps-head {
        background:
            radial-gradient(circle at 85% 0%, rgba(255, 210, 158, 0.5), transparent 45%),
            radial-gradient(circle at 0% 100%, rgba(255, 177, 94, 0.4), transparent 50%),
            linear-gradient(135deg, #a84600 0%, #cc5600 55%, #ff8c1a 100%);
    }
    .ps-theme-orange .ps-paws .p2 { fill: rgba(255, 224, 189, 0.25); }
    .ps-theme-orange .ps-sheet {
        background:
            radial-gradient(circle at 50% 0%, #fff4e8 0%, rgba(255, 244, 232, 0) 55%),
            #fff;
    }
    .ps-theme-orange .ps-photo {
        background: conic-gradient(from 200deg, #cc5600, #ff8c1a, #ffd29e, #ffb15e, #cc5600);
        box-shadow: 0 10px 28px rgba(204, 86, 0, 0.35), 0 0 0 6px #fff;
    }
    .ps-theme-orange .ps-photo-empty { background: linear-gradient(160deg, #fff4e8, #ffe2c2); }
    .ps-theme-orange .ps-photo-badge {
        background: linear-gradient(135deg, #ff8c1a, #cc5600);
        box-shadow: 0 4px 10px rgba(204, 86, 0, 0.45);
    }
    .ps-theme-orange .ps-edit { background: #fff4e8; }
    .ps-theme-orange .ps-edit:hover { background: #ffe6cc; }
    .ps-theme-orange .ps-edit svg { stroke: #cc5600; }
    .ps-theme-orange .ps-name input { border-bottom-color: #ff8c1a; }
    .ps-theme-orange .ps-label { color: #5a2e0a; }
    .ps-theme-orange .ps-input { border-color: #f5dcc2; background: #fffaf4; }
    .ps-theme-orange .ps-input::placeholder { color: #c4a184; }
    .ps-theme-orange .ps-input[readonly] { color: #6b4a2e; background: #fcefe1; }
    .ps-theme-orange .ps-input:focus { border-color: #cc5600; background: #fff; box-shadow: 0 0 0 4px rgba(204, 86, 0, .14); }
    .ps-theme-orange .ps-hint { color: #a8896d; }
    .ps-theme-orange .ps-submit {
        background: linear-gradient(135deg, #ffb15e 0%, #ff8c1a 50%, #cc5600 100%);
        box-shadow: 0 8px 20px rgba(204, 86, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.3);
    }
    .ps-theme-orange .ps-submit:hover { box-shadow: 0 12px 26px rgba(204, 86, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
    .ps-theme-orange .ps-submit.is-saved,
    .ps-theme-orange .ps-submit.is-verified {
        background: linear-gradient(135deg, #22c55e, #15803d);
        box-shadow: 0 8px 20px rgba(21, 128, 61, 0.35);
    }
    .ps-theme-orange .ps-otp-icon { background: linear-gradient(135deg, #fff4e8, #ffe2c2); box-shadow: 0 6px 16px rgba(204, 86, 0, 0.18); }
    .ps-theme-orange .ps-otp-icon svg { stroke: #cc5600; }
    .ps-theme-orange .ps-otp-text strong { color: #cc5600; }
    .ps-theme-orange .ps-otp-text { color: #7a5c45; }
    #psOverlay.ps-theme-orange .otp-box { color: #a84600; }
    #psOverlay.ps-theme-orange .otp-box.filled { background: #fff4e8; }

    /* ===== Buong larawan: pindot sa photo (hindi sa camera) ===== */
    .ps-photo.has-photo img { cursor: zoom-in; }
    .ps-photo.has-photo:hover .ps-photo-badge { transform: none; }
    .ps-photo.has-photo .ps-photo-badge:hover { transform: scale(1.12) rotate(-8deg); cursor: pointer; }
    .ps-viewer {
        position: fixed;
        inset: 0;
        z-index: 10100; /* nasa ibabaw ng Account Settings modal (10020/10021) */
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(8, 12, 24, 0.82);
        backdrop-filter: blur(4px);
        opacity: 0;
        visibility: hidden;
        transition: opacity .25s ease, visibility 0s linear .25s;
        cursor: zoom-out;
    }
    .ps-viewer.open { opacity: 1; visibility: visible; transition: opacity .25s ease; }
    .ps-viewer img {
        max-width: min(92vw, 720px);
        max-height: 82vh;
        border-radius: 16px;
        object-fit: contain;
        background: #fff;
        box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5);
        transform: scale(.88);
        transition: transform .32s cubic-bezier(.22, 1, .36, 1);
        cursor: default;
    }
    .ps-viewer.open img { transform: scale(1); }
    .ps-viewer-close {
        position: absolute;
        top: 18px;
        right: 18px;
        width: 42px;
        height: 42px;
        border: none;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.16);
        color: #fff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background .2s ease;
    }
    .ps-viewer-close:hover { background: rgba(255, 255, 255, 0.28); }
    .ps-viewer-close svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
    @media (prefers-reduced-motion: reduce) {
        .ps-viewer, .ps-viewer img { transition: none; }
    }
</style>

<script>
(function () {
    var overlay = document.getElementById('psOverlay');
    if (!overlay) return;

    var form = document.getElementById('psForm');
    var errorBox = document.getElementById('psError');
    var submitBtn = document.getElementById('psSubmit');
    var nameText = document.getElementById('psNameText');
    var nameInput = document.getElementById('psNameInput');
    var photoInput = document.getElementById('psPhotoInput');
    var photoImg = document.getElementById('psPhotoImg');
    var photoEmpty = document.getElementById('psPhotoEmpty');
    var stepForm = document.getElementById('psStepForm');
    var stepOtp = document.getElementById('psStepOtp');
    var otpForm = document.getElementById('psOtpForm');
    var resendBtn = document.getElementById('psResend');
    var mode = overlay.dataset.mode;          // 'setup' o 'edit'
    var isOpen = overlay.classList.contains('open');
    var closing = false;

    // ===== Buong larawan =====
    // Pindot sa photo = makita ito nang buo. Ang camera button pa rin ang pampalit.
    // Kapag wala pang photo, ang pindot sa bilog ay pipili pa rin ng larawan.
    var photoBox = overlay.querySelector('.ps-photo');
    var viewer = document.createElement('div');
    viewer.className = 'ps-viewer';
    viewer.setAttribute('role', 'dialog');
    viewer.setAttribute('aria-label', 'Profile photo');
    viewer.innerHTML = '<button type="button" class="ps-viewer-close" aria-label="Close">' +
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button><img alt="Profile photo">';
    document.body.appendChild(viewer);
    var viewerImg = viewer.querySelector('img');

    function hasPhoto() { return !photoImg.hidden && !!photoImg.getAttribute('src'); }
    function syncPhotoState() {
        var on = hasPhoto();
        photoBox.classList.toggle('has-photo', on);
        photoBox.title = on ? 'View photo' : 'Add photo';
    }
    function openViewer() {
        viewerImg.src = photoImg.src;
        viewer.classList.add('open');
        viewer.querySelector('.ps-viewer-close').focus({ preventScroll: true });
    }
    function closeViewer() { viewer.classList.remove('open'); }

    if (photoBox) {
        syncPhotoState();
        photoBox.addEventListener('click', function (e) {
            if (e.target.closest('.ps-photo-badge')) { photoBox.title = 'Change photo'; return; } // camera: pumili ng bago
            if (!hasPhoto()) return;                                     // wala pang photo: pumili
            e.preventDefault();
            openViewer();
        });
        new MutationObserver(syncPhotoState).observe(photoImg, { attributes: true, attributeFilter: ['src', 'hidden'] });
    }
    viewer.addEventListener('click', function (e) {
        if (e.target !== viewerImg) closeViewer(); // pindot sa labas ng larawan o sa ✕
    });
    // Esc: isara muna ang larawan lang, hindi ang buong Account Settings
    window.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && viewer.classList.contains('open')) {
            e.stopPropagation();
            closeViewer();
        }
    }, true);

    if (isOpen) document.body.style.overflow = 'hidden';

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.hidden = false;
    }

    function post(data) {
        return fetch(overlay.dataset.endpoint, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    // Pagkatapos ng setup: ipakita ang "Punan ang Reference" popup
    function showPrefsPrompt() {
        var prefs = document.getElementById('prefsPromptOverlay');
        if (!prefs) return;
        prefs.style.display = 'flex';
        if (prefs.animate) {
            prefs.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 450, easing: 'ease' });
            var box = prefs.querySelector('.prefs-prompt-box');
            if (box && box.animate) {
                box.animate(
                    [{ opacity: 0, transform: 'translateY(30px) scale(.95)' }, { opacity: 1, transform: 'none' }],
                    { duration: 600, easing: 'cubic-bezier(.22,1,.36,1)' }
                );
            }
        }
    }

    // Ibalik sa simula ang modal (form step, walang error) bago buksan ulit
    function resetModal() {
        errorBox.hidden = true;
        stepOtp.hidden = true;
        stepForm.hidden = false;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit';
        submitBtn.classList.remove('is-saved');
        var otpErr = stepOtp.querySelector('.error');
        if (otpErr) otpErr.remove();

        // Ibalik ang 6 na kahon ng OTP mula sa "Verified" animation
        var group = stepOtp.querySelector('.otp-boxes');
        group.classList.remove('merging', 'merged', 'error', 'checking');
        group.querySelectorAll('.otp-check').forEach(function (c) { c.remove(); });
        group.querySelectorAll('.otp-box').forEach(function (b) {
            b.value = '';
            b.classList.remove('filled');
            b.style.removeProperty('--to');
            b.style.transitionDelay = '';
        });
        stepOtp.querySelectorAll('.otp-verified').forEach(function (l) { l.remove(); });
        var verifyBtn = stepOtp.querySelector('.ps-submit');
        verifyBtn.disabled = false;
        verifyBtn.textContent = 'Verify';
        verifyBtn.classList.remove('is-verified');
    }

    // Buksan (edit mode) - mula sa pagpindot ng profile sa itaas
    function openModal() {
        if (isOpen) return;
        resetModal();
        isOpen = true;
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    window.spOpenProfile = openModal;

    // Isara nang dahan-dahan. Kapag setup, saka ipakita ang reference popup
    // at gawing edit mode para mabuksan ulit sa profile.
    function closeModal() {
        if (!isOpen || closing) return;
        closing = true;
        var wasSetup = mode === 'setup';
        if (wasSetup) {
            var fd = new FormData();
            fd.append('action', 'finish');
            post(fd).catch(function () {});
        }

        overlay.classList.add('closing');
        setTimeout(function () {
            overlay.classList.remove('open', 'closing', 'setup');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            var curtain = document.querySelector('.ps-curtain');
            if (curtain) curtain.remove();
            isOpen = false;
            closing = false;
            if (wasSetup) {
                mode = 'edit';
                overlay.dataset.mode = 'edit';
                overlay.classList.add('edit');
                document.getElementById('psSubtitle').textContent = 'Update your profile and recovery details';
                showPrefsPrompt();
            }
        }, 450);
    }

    document.getElementById('psClose').addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) closeModal();
    });

    // Pindot sa labas ng modal = isara (edit mode lang, para hindi aksidenteng
    // malaktawan ang setup)
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay && mode === 'edit') closeModal();
    });

    // Pindot sa profile sa itaas ng page = buksan ang modal imbes na lumipat ng page
    document.addEventListener('click', function (e) {
        // Adopter: .user-profile-link / .top-navbar .user-profile; Staff: .top-header .profile-section
        var trigger = e.target.closest('.user-profile-link, .top-navbar .user-profile, .top-header .profile-section');
        if (!trigger || e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        openModal();
    });

    // Ipakita agad sa header ang bagong pangalan at larawan
    function updateHeader(username, photo) {
        document.querySelectorAll('.top-navbar .user-profile span, .top-header .profile-name, .profile-name, .user-name').forEach(function (el) {
            el.textContent = username;
        });
        if (photo) {
            // Staff: larawan (img) o initials (.avatar) na diretso sa .profile-section
            document.querySelectorAll('.top-header .profile-section').forEach(function (ps) {
                var current = ps.querySelector(':scope > img, :scope > .avatar');
                if (!current) return;
                // Admin: ang larawan ay nasa loob ng .avatar (may orange na singsing) - doon papalitan
                if (ps.closest('.sp-admin-profile-wrap') && current.classList.contains('avatar')) {
                    var inner = current.querySelector('img');
                    if (!inner) {
                        current.innerHTML = '';
                        inner = document.createElement('img');
                        inner.alt = 'Profile';
                        inner.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
                        current.appendChild(inner);
                    }
                    inner.src = photo;
                    return;
                }
                var img = current.tagName === 'IMG' ? current : document.createElement('img');
                img.src = photo;
                img.alt = 'Profile';
                if (img !== current) current.replaceWith(img);
            });
            document.querySelectorAll('.top-navbar .profile-avatar').forEach(function (av) {
                var img = av.querySelector('img');
                if (!img) {
                    av.innerHTML = '';
                    img = document.createElement('img');
                    img.alt = 'Profile';
                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
                    av.appendChild(img);
                }
                img.src = photo;
            });
        }
    }

    // ---- Pangalan: i-click ang lapis para baguhin ----
    function setEditing(on) {
        nameText.hidden = on;
        nameInput.hidden = !on;
        if (on) { nameInput.focus(); nameInput.select(); }
        else if (nameInput.value.trim() !== '') { nameText.textContent = nameInput.value.trim(); }
    }
    document.getElementById('psEditName').addEventListener('click', function () { setEditing(nameInput.hidden); });
    nameInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); setEditing(false); }
    });
    nameInput.addEventListener('blur', function () { setEditing(false); });

    // ---- Photo: makikita agad ang napiling larawan ----
    photoInput.addEventListener('change', function () {
        var file = photoInput.files[0];
        if (!file) return;
        if (!/^image\/(png|jpe?g|gif|webp)$/.test(file.type)) {
            photoInput.value = '';
            showError('Photo must be a JPG, PNG, GIF or WEBP image.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            photoInput.value = '';
            showError('Photo must be 5 MB or less.');
            return;
        }
        errorBox.hidden = true;
        photoImg.src = URL.createObjectURL(file);
        photoImg.hidden = false;
        if (photoEmpty) photoEmpty.hidden = true;
        if (photoImg.animate) {
            photoImg.animate([{ transform: 'scale(.6)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }],
                { duration: 450, easing: 'cubic-bezier(.34,1.56,.64,1)' });
        }
    });

    // ---- Submit ----
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errorBox.hidden = true;
        setEditing(false);

        var fd = new FormData(form);
        fd.append('action', 'save');
        fd.append('username', nameInput.value.trim());

        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';

        post(fd).then(function (res) {
            if (!res.ok) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit';
                showError(res.error || 'Something went wrong. Please try again.');
                return;
            }
            nameText.textContent = res.username;

            // Na-update rin ang larawan at pangalan sa header ng page
            updateHeader(res.username, res.photo);
            window.dispatchEvent(new CustomEvent('sp:profile-updated', {
                detail: { username: res.username, photo: res.photo }
            }));

            // Bagong wika: agad na isinasalin ang buong page (connection/i18n.php)
            if (res.language && typeof window.spSetLang === 'function') window.spSetLang(res.language);
            photoInput.value = ''; // para hindi na ma-upload ulit sa susunod na save

            if (res.step === 'otp') {
                document.getElementById('psMasked').textContent = res.masked;
                stepForm.hidden = true;
                stepOtp.hidden = false;
                startResendWait(60);
                var first = stepOtp.querySelector('.otp-box');
                if (first) first.focus();
                return;
            }

            submitBtn.textContent = 'Saved ✓';
            submitBtn.classList.add('is-saved');
            setTimeout(closeModal, 700);
        }).catch(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit';
            showError('Something went wrong. Please try again.');
        });
    });

    // ---- Code ng recovery email ----
    otpForm.addEventListener('otp:verified', closeModal);

    var waitTimer = null;
    function startResendWait(seconds) {
        clearInterval(waitTimer);
        var left = seconds;
        resendBtn.disabled = true;
        resendBtn.textContent = 'Send again (' + left + 's)';
        waitTimer = setInterval(function () {
            left--;
            if (left <= 0) {
                clearInterval(waitTimer);
                resendBtn.disabled = false;
                resendBtn.textContent = 'Send again';
            } else {
                resendBtn.textContent = 'Send again (' + left + 's)';
            }
        }, 1000);
    }

    resendBtn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('action', 'resend');
        resendBtn.disabled = true;
        post(fd).then(function (res) {
            if (res.ok) {
                startResendWait(60);
            } else {
                resendBtn.disabled = false;
                alert(res.error);
            }
        }).catch(function () { resendBtn.disabled = false; });
    });

    document.getElementById('psChangeEmail').addEventListener('click', function () {
        stepOtp.hidden = true;
        stepForm.hidden = false;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit';
        document.getElementById('psRecovery').focus();
    });
})();

// Telepono: kapag lumabas ang keyboard, lumiliit ang nakikitang screen. Sinusundan
// ito ng modal (--ps-vh), at inii-scroll sa gitna ang field na tina-type-an.
(function () {
    var overlay = document.getElementById('psOverlay');
    if (!overlay) return;
    var vv = window.visualViewport;
    function fit() {
        if (!vv) return;
        overlay.style.setProperty('--ps-vh', vv.height + 'px');
        overlay.style.height = vv.height + 'px';
        overlay.style.top = vv.offsetTop + 'px';
    }
    if (vv) {
        vv.addEventListener('resize', fit);
        vv.addEventListener('scroll', fit);
        fit();
    }
    overlay.addEventListener('focusin', function (e) {
        if (!e.target.matches('input, select, textarea')) return;
        setTimeout(function () {
            try { e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (err) { e.target.scrollIntoView(); }
        }, 300);
    });
})();
</script>
<script src="../Login_Page/otp_boxes.js"></script>
