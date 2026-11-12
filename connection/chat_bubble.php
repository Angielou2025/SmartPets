<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ang responsive layer (mobile sidebar, table scroll, atbp.) ay sumasakay dito
// dahil ito ang iisang include na nasa bawat page ng tatlong role.
require_once __DIR__ . '/responsive.php';
require_once __DIR__ . '/app_url.php';

if (!isset($_SESSION['user_id'])) {
    return;
}

// Kusang pag-approve: 24 oras pagkatapos ma-release ang certificate, kung kumpleto
// ang lahat ng hakbang at hindi pa na-approve ng staff (connection/application_flow.php).
// Walang cron sa XAMPP kaya dito ito sumasakay; isang beses lang bawat 5 minuto.
if (isset($pdo)) {
    require_once __DIR__ . '/application_flow.php';
    sp_auto_approve_due($pdo);
}

// Kasama rito ang pop-up ng role change request sa parehong dahilan ng
// responsive layer sa itaas: ito ang iisang include na nasa bawat page ng
// tatlong role, kaya nakikita ng user ang hiling saan man siya naroon.
require_once __DIR__ . '/role_change_prompt.php';

// Magandang confirmation popup (para sa mga form na may data-confirm)
require_once __DIR__ . '/confirm_dialog.php';

// Theme follows the role's own brand color already used elsewhere in the app:
// Adopter = the site's original green, Staff = the staff sidebar blue, Admin = orange.
$spRole = $_SESSION['role'] ?? 'Adopter';

// Global search bar sa navbar: inalis na sa lahat ng page (hiling ng user).
// Nandito pa ang connection/global_search.php kung sakaling ibalik:
// require_once __DIR__ . '/global_search.php';

// Bell ng notifications sa kaliwa ng profile (pinalitan ang nasa sidebar)
if ($spRole === 'Adopter' && isset($pdo)) {
    require_once __DIR__ . '/notif_bell.php';
}

// Account Settings modal ng Adopter: bumubukas kapag pinindot ang profile sa
// itaas ng page. Kapag naisama na ng page ang setup na bersyon (search_pets.php
// pagkatapos ng loading screen), hindi na ito uulitin.
if ($spRole === 'Adopter' && isset($pdo) && empty($GLOBALS['sp_profile_modal_rendered'])) {
    try {
        $psStmt = $pdo->prepare("SELECT username, email, recovery_email, profile_image FROM users WHERE user_id = ?");
        $psStmt->execute([$_SESSION['user_id']]);
        $psUser = $psStmt->fetch(PDO::FETCH_ASSOC);
        if ($psUser) {
            $psMode = 'edit';
            include __DIR__ . '/../Adopter_File/_profile_setup_modal.php';
        }
    } catch (Exception $e) {
        // Kapag may problema, ang dating Edit Profile page na lang ang bubuksan ng link
    }
}

// English / Tagalog sa buong panig ng Adopter at Staff (pinipili sa Account Settings)
if ($spRole === 'Adopter' || $spRole === 'Staff') {
    require_once __DIR__ . '/i18n.php';
}

// Header ng staff: bell (Reminders) + profile na kapareho ng sa Adopter, pero asul
if ($spRole === 'Staff') {
    require_once __DIR__ . '/staff_header.php';

    // Pindot sa profile = Account Settings modal (kapareho ng Adopter, pero asul)
    if (isset($pdo) && empty($GLOBALS['sp_profile_modal_rendered'])) {
        try {
            $psStmt = $pdo->prepare("SELECT username, email, recovery_email, profile_image FROM users WHERE user_id = ?");
            $psStmt->execute([$_SESSION['user_id']]);
            $psUser = $psStmt->fetch(PDO::FETCH_ASSOC);
            if ($psUser) {
                $psMode = 'edit';
                $psTheme = 'blue';
                include __DIR__ . '/../Adopter_File/_profile_setup_modal.php';
            }
        } catch (Exception $e) {
            // Kapag may problema, ang dating Edit Profile page na lang ang bubuksan ng link
        }
    }
}

// Header ng admin: profile na kapareho ng sa Adopter at Staff, may menu na
// Account Settings at Logout (wala na ang Logout sa sidebar)
if ($spRole === 'Admin') {
    require_once __DIR__ . '/admin_header.php';
    // Pagination ng lahat ng table sa admin (10 row bawat page, walang reload)
    require_once __DIR__ . '/admin_table_pager.php';

    // Account Settings modal (kapareho ng Adopter at Staff, pero orange)
    if (isset($pdo) && empty($GLOBALS['sp_profile_modal_rendered'])) {
        try {
            $psStmt = $pdo->prepare("SELECT username, email, recovery_email, profile_image FROM users WHERE user_id = ?");
            $psStmt->execute([$_SESSION['user_id']]);
            $psUser = $psStmt->fetch(PDO::FETCH_ASSOC);
            if ($psUser) {
                $psMode = 'edit';
                $psTheme = 'orange';
                include __DIR__ . '/../Adopter_File/_profile_setup_modal.php';
            }
        } catch (Exception $e) {
            // Kapag may problema, ang Edit Profile page ng admin na lang ang bubuksan ng menu
        }
    }
}

// Walang AI assistant sa Staff. Nasa ibaba na ng responsive require ang return
// para manatili pa rin ang mobile layout sa mga staff page.
if ($spRole === 'Staff') {
    return;
}

// Larawan at pangalan ng naka-login, para sa matching animation sa explanation:
// adopter sa kaliwa, pet sa kanan, paw loader sa gitna.
$spMe = ['name' => $_SESSION['username'] ?? 'You', 'photo' => null];
if (isset($pdo)) {
    try {
        $spMeStmt = $pdo->prepare('SELECT username, profile_image FROM users WHERE user_id = ?');
        $spMeStmt->execute([$_SESSION['user_id']]);
        $spMeRow = $spMeStmt->fetch(PDO::FETCH_ASSOC);
        if ($spMeRow) {
            if (!empty($spMeRow['username'])) { $spMe['name'] = $spMeRow['username']; }
            if (!empty($spMeRow['profile_image'])) { $spMe['photo'] = $spMeRow['profile_image']; }
        }
    } catch (Exception $e) { /* walang larawan, okay lang - may fallback na icon */ }
}

switch ($spRole) {
    case 'Staff':
        $spPrimary = '#2952c9';
        $spPrimaryLight = '#5b83e8';
        $spDark = '#142a63';
        $spLight = '#e8edfb';
        break;
    case 'Admin':
        $spPrimary = '#ff8c1a';
        $spPrimaryLight = '#ffb15e';
        $spDark = '#cc5600';
        $spLight = '#fff1e6';
        break;
    default: // Adopter
        $spPrimary = '#2d6a45';
        $spPrimaryLight = '#3fae72';
        $spDark = '#1b4d2e';
        $spLight = '#eaf3ee';
        break;
}
?>
<style>
    :root {
        --sp-primary: <?php echo $spPrimary; ?>;
        --sp-primary-light: <?php echo $spPrimaryLight; ?>;
        --sp-dark: <?php echo $spDark; ?>;
        --sp-light: <?php echo $spLight; ?>;
    }

    body.sp-no-select { user-select: none; }

    @keyframes spFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-6px); }
    }
    @keyframes spMsgIn {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes spWaterRing {
        0% { transform: translateX(-50%) scale(0.3); opacity: 0.65; }
        35% { transform: translateX(-50%) scale(2.6); opacity: 0; }
        100% { transform: translateX(-50%) scale(2.6); opacity: 0; }
    }

    #spChatToggle {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 60px;
        height: 72px;
        background: transparent;
        border: none;
        cursor: grab;
        z-index: 9998;
        padding: 0;
        touch-action: none;
        -webkit-tap-highlight-color: transparent;
        animation: spFloat 2.6s ease-in-out infinite;
    }
    #spChatToggle.dragging,
    #spChatToggle.sp-panel-open { animation-play-state: paused; }
    #spChatToggle.dragging { cursor: grabbing; }
    #spChatToggle::before,
    #spChatToggle::after {
        content: '';
        position: absolute;
        left: 50%;
        bottom: 0px;
        width: 34px;
        height: 11px;
        border-radius: 50%;
        background: var(--sp-primary);
        transform: translateX(-50%) scale(0.3);
        opacity: 0;
        animation: spWaterRing 2.6s ease-out infinite;
        pointer-events: none;
        z-index: -1;
    }
    #spChatToggle::after { animation-delay: 0.4s; }
    #spChatToggle.dragging::before,
    #spChatToggle.dragging::after,
    #spChatToggle.sp-panel-open::before,
    #spChatToggle.sp-panel-open::after { animation-play-state: paused; }
    #spChatToggle svg {
        width: 100%;
        height: 100%;
        display: block;
        filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.26));
        transition: transform 0.15s ease;
    }
    #spChatToggle:hover svg { transform: scale(1.06); }
    #spChatToggle.dragging svg { transform: scale(1.06); }

    #spChatPanel {
        position: fixed;
        bottom: 92px;
        right: 24px;
        width: 360px;
        max-width: calc(100vw - 32px);
        height: 540px;
        max-height: calc(100vh - 120px);
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18), 0 2px 6px rgba(0, 0, 0, 0.06);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        z-index: 9999;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translateY(12px) scale(0.95);
        transform-origin: bottom right;
        transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
    }
    #spChatPanel.open {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    /* Header na parang messaging app: may kulay ng role, avatar na may
       "online" na tuldok, pangalan, at status sa ilalim */
    #spChatHeader {
        background: linear-gradient(135deg, var(--sp-primary) 0%, var(--sp-dark) 100%);
        color: #fff;
        padding: 14px 14px 14px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-top-left-radius: 18px;
        border-top-right-radius: 18px;
    }
    #spChatHeader .sp-title { display: flex; align-items: center; gap: 11px; min-width: 0; }
    .sp-head-avatar {
        position: relative;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #fff;
        color: var(--sp-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }
    .sp-head-avatar svg { width: 22px; height: 22px; fill: currentColor; }
    .sp-online-dot {
        position: absolute;
        right: -1px;
        bottom: -1px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #31d07b;
        border: 2px solid #fff;
    }
    .sp-head-text { min-width: 0; }
    .sp-head-name { font-weight: 700; font-size: 15px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    /* Orange na "SMART", kapareho ng kulay ng brand sa ibang bahagi ng site.
       Medyo maliwanag na orange at may kaunting anino para kita sa header. */
    .sp-head-name .sp-word-smart { color: #ff9a1f; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25); }
    .sp-head-status { font-size: 11.5px; opacity: 0.85; margin-top: 2px; }
    #spChatHeader button {
        background: none;
        border: none;
        color: rgba(255, 255, 255, 0.9);
        font-size: 22px;
        cursor: pointer;
        line-height: 1;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease;
    }
    #spChatHeader button:hover { background-color: rgba(255, 255, 255, 0.16); }

    #spChatMessages {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 14px 12px;
        background: #f3f5f4;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .sp-msg { max-width: 80%; padding: 10px 13px; border-radius: 18px; font-size: 13.5px; line-height: 1.45; word-wrap: break-word; white-space: pre-wrap; animation: spMsgIn 0.25s ease forwards; }
    .sp-msg.bot { background: #fff; color: #2d3748; border-bottom-left-radius: 6px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06); }
    .sp-msg.user { align-self: flex-end; background: linear-gradient(135deg, var(--sp-primary-light), var(--sp-primary)); color: #fff; border-bottom-right-radius: 6px; }
    /* Oras sa ilalim ng bawat mensahe, gaya sa mga chat app */
    .sp-time { font-size: 10px; color: #9aa5a0; margin: 0 4px 4px; }
    .sp-time.user { align-self: flex-end; }
    .sp-time.bot { align-self: flex-start; margin-left: 34px; }
    .sp-msg-image { display: block; max-width: 100%; border-radius: 10px; margin-bottom: 6px; }

    /* Paw avatar sa tabi ng bawat sagot ng bot - parehong favicon icon at
       kulay ng header logo, kaya konsistent sa buong widget */
    .sp-msg-row { align-self: flex-start; display: flex; align-items: flex-end; gap: 6px; max-width: 100%; animation: spMsgIn 0.25s ease forwards; }
    .sp-msg-row .sp-msg { animation: none; }
    .sp-msg-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--sp-primary-light), var(--sp-primary));
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .sp-msg-avatar svg { width: 15px; height: 15px; fill: currentColor; }

    /* Mabilisang tanong: bumababa sa susunod na linya ang mga button para lahat
       kita (walang naputol sa gilid). May toggle para maitago kapag nag-uusap
       na, para hindi kainin ang espasyo ng mensahe. */
    #spChatQuick {
        padding: 6px 12px 10px;
        background: #f3f5f4;
        border-top: 1px solid #e6ebe8;
    }
    .sp-quick-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        background: none;
        border: none;
        padding: 2px 2px 0;
        font-family: inherit;
        font-size: 11.5px;
        font-weight: 700;
        color: #6b7a72;
        cursor: pointer;
    }
    .sp-quick-toggle svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.2;
        stroke-linecap: round;
        stroke-linejoin: round;
        transition: transform 0.2s ease;
    }
    #spChatQuick.collapsed .sp-quick-toggle svg { transform: rotate(180deg); }
    .sp-chip-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 7px;
    }
    #spChatQuick.collapsed .sp-chip-list { display: none; }
    .sp-chip {
        background: #fff;
        border: 1px solid #dde5e0;
        color: var(--sp-dark);
        border-radius: 999px;
        padding: 6px 11px;
        white-space: nowrap;
        font-size: 12px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        transition: background-color 0.2s ease, border-color 0.2s ease;
    }
    .sp-chip:hover { background: var(--sp-light); border-color: var(--sp-primary); }

    .sp-msg-link {
        align-self: flex-start;
        margin-left: 34px;
        background: var(--sp-primary);
        color: #fff;
        border-radius: 999px;
        padding: 6px 13px;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        animation: spMsgIn 0.25s ease forwards;
    }
    .sp-msg-link:hover { background: var(--sp-dark); }

    /* "Nag-iisip pa" habang hinihintay ang sagot ni Claude */
    @keyframes spDot {
        0%, 60%, 100% { transform: translateY(0); opacity: 0.35; }
        30% { transform: translateY(-4px); opacity: 1; }
    }
    .sp-typing { display: flex; align-items: center; gap: 4px; padding: 12px 14px; }
    .sp-typing span {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #8a9a92;
        animation: spDot 1.2s infinite ease-in-out;
    }
    .sp-typing span:nth-child(2) { animation-delay: 0.15s; }
    .sp-typing span:nth-child(3) { animation-delay: 0.3s; }

    #spChatInput:disabled { background: #f4f5f4; cursor: not-allowed; }
    #spChatSend:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }
    .sp-attach-btn:disabled { opacity: 0.45; cursor: not-allowed; }

    #spComposer { border-top: 1px solid #edf0ee; background: #fff; }
    #spChatInputRow { display: flex; align-items: center; padding: 10px; gap: 6px; background: #fff; }

    /* Chip ng nakalakip na larawan - lumalabas sa itaas ng input, pero
       PAGKATAPOS lang talaga mapili/ma-paste ang isang file (hindi agad,
       hindi stale - nililinis ito sa clearPendingImage() kapag isinara ang
       chat o nag-new chat, para walang matirang lumang attachment). */
    .sp-attach-preview {
        display: flex;
        align-items: center;
        gap: 9px;
        margin: 8px 8px 0;
        padding: 6px 8px;
        border: 1px solid #dcdcdc;
        border-radius: 12px;
        background: #f8f9fa;
    }
    /* Kailangan ito dahil mas malakas ang `display: flex` sa itaas kaysa sa
       `display: none` na ibinibigay ng browser sa [hidden]. Kung wala ito,
       lumalabas ang chip kahit walang naka-attach na file. */
    .sp-attach-preview[hidden] { display: none; }
    .sp-attach-thumb { width: 34px; height: 34px; border-radius: 8px; overflow: hidden; flex-shrink: 0; background: #e6ebe8; }
    .sp-attach-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .sp-attach-info { flex: 1; min-width: 0; }
    .sp-attach-name { font-size: 12px; font-weight: 700; color: #1d2a24; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sp-attach-size { font-size: 10.5px; color: #6b7a72; }
    .sp-attach-remove {
        background: none;
        border: none;
        color: #8a8a8a;
        font-size: 18px;
        cursor: pointer;
        line-height: 1;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease;
    }
    .sp-attach-remove:hover { background: #eee; }
    .sp-attach-btn {
        background: none;
        border: none;
        color: #8a9a92;
        cursor: pointer;
        flex-shrink: 0;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    .sp-attach-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .sp-attach-btn:hover { background: var(--sp-light); color: var(--sp-primary); }
    #spChatInput {
        flex: 1;
        min-width: 0;
        border: 1px solid transparent;
        background: #f1f4f2;
        border-radius: 22px;
        padding: 10px 15px;
        font-size: 13.5px;
        outline: none;
        font-family: inherit;
        transition: border-color 0.2s ease, background-color 0.2s ease;
    }
    #spChatInput:focus { border-color: var(--sp-primary); background: #fff; }
    #spChatSend {
        background: var(--sp-primary);
        color: #fff;
        border: none;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease, transform 0.15s ease;
    }
    #spChatSend svg { width: 18px; height: 18px; fill: currentColor; margin-left: 2px; }
    #spChatSend:hover { background: var(--sp-dark); transform: translateY(-1px); }

    /* Maliliit na line icon sa header: search at sidebar */
    .sp-head-actions { display: flex; align-items: center; gap: 2px; margin-left: auto; }
    #spChatHeader button.sp-icon-btn svg {
        width: 17px;
        height: 17px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    #spChatHeader .sp-title { font-size: 14px; }

    /* Sidebar ng mga usapan - drawer mula sa kaliwa, may dilim sa likod */
    #spDrawerBackdrop {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.38);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.22s ease, visibility 0.22s;
        z-index: 4;
    }
    #spDrawerBackdrop.open { opacity: 1; visibility: visible; }
    #spHistory {
        position: absolute;
        top: 0;
        bottom: 0;
        left: 0;
        width: 84%;
        max-width: 270px;
        background: #fff;
        display: flex;
        flex-direction: column;
        z-index: 5;
        transform: translateX(-103%);
        transition: transform 0.25s ease;
        box-shadow: 4px 0 18px rgba(0, 0, 0, 0.16);
    }
    #spHistory.open { transform: none; }
    .sp-hi-head { display: flex; align-items: center; padding: 12px 10px 8px 14px; }
    .sp-hi-title { flex: 1; font-size: 14px; font-weight: 800; color: #1d2a24; }
    .sp-hi-close {
        background: none;
        border: none;
        color: #8a8a8a;
        font-size: 22px;
        cursor: pointer;
        line-height: 1;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease;
    }
    .sp-hi-close:hover { background: #f0f0f0; }
    .sp-hi-search {
        display: flex;
        align-items: center;
        gap: 7px;
        margin: 0 10px 8px;
        padding: 7px 10px;
        border: 1px solid #dcdcdc;
        border-radius: 10px;
        background: #f8f9fa;
        cursor: text;
        transition: border-color 0.2s ease;
    }
    .sp-hi-search:focus-within { border-color: var(--sp-primary); }
    .sp-hi-search svg { width: 14px; height: 14px; fill: none; stroke: #8a9a92; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
    .sp-hi-search input {
        flex: 1;
        min-width: 0;
        border: none;
        background: none;
        outline: none;
        font-size: 12.5px;
        font-family: inherit;
        color: #1d2a24;
    }
    .sp-hi-new {
        margin: 0 10px 8px;
        background: var(--sp-primary);
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 8px 11px;
        font-size: 12px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.2s ease;
    }
    .sp-hi-new:hover { background: var(--sp-dark); }
    .sp-hi-list { flex: 1; overflow-y: auto; padding: 0 10px 8px; display: flex; flex-direction: column; gap: 6px; }
    .sp-hi-foot { border-top: 1px solid #eee; padding: 8px 10px; display: flex; flex-direction: column; gap: 6px; }
    .sp-hi-theme {
        display: flex;
        align-items: center;
        gap: 9px;
        background: none;
        border: 1px solid #e3e7e4;
        border-radius: 10px;
        padding: 8px 11px;
        font-family: inherit;
        font-size: 12px;
        font-weight: 700;
        color: #1d2a24;
        cursor: pointer;
        text-align: left;
        transition: background-color 0.2s ease;
    }
    .sp-hi-theme:hover { background: var(--sp-light); }
    .sp-hi-item {
        position: relative;
        background: #fff;
        border: 1px solid #e3e7e4;
        border-radius: 10px;
        padding: 9px 36px 9px 11px;
        cursor: pointer;
        text-align: left;
        font-family: inherit;
        width: 100%;
        box-sizing: border-box;
        transition: background-color 0.2s ease;
    }
    .sp-hi-item:hover { background: var(--sp-light); }
    .sp-hi-del {
        position: absolute;
        top: 7px;
        right: 7px;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        border: none;
        background: none;
        color: #94a29a;
        font-size: 13px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    .sp-hi-del:hover { background: #fdecea; color: #c0392b; }
    .sp-hi-clear {
        background: none;
        border: 1px solid #e3c4c1;
        color: #c0392b;
        font-size: 12px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        padding: 8px 11px;
        border-radius: 10px;
        text-align: left;
        transition: background-color 0.2s ease;
    }
    .sp-hi-clear:hover { background: #fdecea; }

    /* Maliit na tanda sa itaas ng ibinalik na usapan: kailan ito naganap */
    .sp-note {
        align-self: center;
        background: var(--sp-light);
        color: var(--sp-dark);
        border-radius: 999px;
        padding: 4px 11px;
        font-size: 11px;
        font-weight: 700;
        animation: spMsgIn 0.25s ease forwards;
    }
    .sp-hi-when { font-size: 10.5px; color: #94a29a; font-weight: 700; }
    .sp-hi-prev { font-size: 12.5px; color: #2d3748; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sp-hi-meta { font-size: 10.5px; color: #94a29a; margin-top: 4px; }
    .sp-hi-empty { text-align: center; color: #94a29a; font-size: 12px; padding: 26px 14px; line-height: 1.5; }

    /* ---- Dark mode. Ang accent ay galing pa rin sa kulay ng role, kaya
           tama ang kulay sa Adopter, Staff, at Admin. ---- */
    #spChatPanel.sp-dark { background: #1b2320; }
    #spChatPanel.sp-dark #spChatHeader { background: linear-gradient(135deg, var(--sp-dark) 0%, #111a16 100%); }
    #spChatPanel.sp-dark .sp-head-avatar { background: #1f2a25; color: var(--sp-primary-light); }
    #spChatPanel.sp-dark .sp-online-dot { border-color: #1b2320; }
    #spChatPanel.sp-dark .sp-time { color: #6d7f77; }
    #spChatPanel.sp-dark .sp-msg.bot { box-shadow: none; }

    #spChatPanel.sp-dark #spChatMessages,
    #spChatPanel.sp-dark #spChatQuick { background: #141b18; }
    #spChatPanel.sp-dark .sp-msg.bot { background: #27332d; color: #dfe8e3; }
    #spChatPanel.sp-dark #spChatQuick { border-top-color: #2d3a35; }
    #spChatPanel.sp-dark .sp-quick-toggle { color: #93a49c; }
    #spChatPanel.sp-dark .sp-chip { background: #1f2a25; border-color: #2f3d37; color: var(--sp-primary-light); }
    #spChatPanel.sp-dark .sp-chip:hover { background: #27332d; }

    #spChatPanel.sp-dark .sp-typing span { background: #6d7f77; }
    #spChatPanel.sp-dark .sp-msg-link { color: #fff; }

    #spChatPanel.sp-dark #spComposer { background: #1b2320; border-top-color: #2d3a35; }
    #spChatPanel.sp-dark #spChatInputRow { background: #1b2320; }
    #spChatPanel.sp-dark .sp-attach-preview { background: #27332d; border-color: #3a4842; }
    #spChatPanel.sp-dark .sp-attach-thumb { background: #2a3630; }
    #spChatPanel.sp-dark .sp-attach-name { color: #e4ece8; }
    #spChatPanel.sp-dark .sp-attach-size { color: #93a49c; }
    #spChatPanel.sp-dark .sp-attach-remove { color: #9aa8a2; }
    #spChatPanel.sp-dark .sp-attach-remove:hover { background: #2d3a35; }
    #spChatPanel.sp-dark .sp-attach-btn { color: #9aa8a2; }
    #spChatPanel.sp-dark .sp-attach-btn:hover { background: #27332d; color: var(--sp-primary-light); }
    #spChatPanel.sp-dark #spChatInput { background: #27332d; border-color: #3a4842; color: #e4ece8; }
    #spChatPanel.sp-dark #spChatInput::placeholder { color: #8d9c95; }
    #spChatPanel.sp-dark #spChatInput:disabled { background: #212b26; }

    #spChatPanel.sp-dark #spHistory { background: #1b2320; }
    #spChatPanel.sp-dark .sp-hi-close { color: #9aa8a2; }
    #spChatPanel.sp-dark .sp-hi-close:hover { background: #27332d; }
    #spChatPanel.sp-dark .sp-hi-search { background: #27332d; border-color: #3a4842; }
    #spChatPanel.sp-dark .sp-hi-search input { color: #e4ece8; }
    #spChatPanel.sp-dark .sp-hi-search input::placeholder { color: #8d9c95; }
    #spChatPanel.sp-dark .sp-hi-foot { border-top-color: #2d3a35; }
    #spChatPanel.sp-dark .sp-hi-theme { border-color: #2f3d37; color: #e4ece8; }
    #spChatPanel.sp-dark .sp-hi-theme:hover { background: #27332d; }
    #spChatPanel.sp-dark .sp-hi-title { color: #e4ece8; }

    #spChatPanel.sp-dark .sp-hi-item { background: #1f2a25; border-color: #2f3d37; }
    #spChatPanel.sp-dark .sp-hi-item:hover { background: #27332d; }
    #spChatPanel.sp-dark .sp-hi-del { color: #7d8d86; }
    #spChatPanel.sp-dark .sp-hi-del:hover { background: #3a2624; color: #f0938c; }
    #spChatPanel.sp-dark .sp-hi-clear { border-color: #5a3532; color: #f0938c; }
    #spChatPanel.sp-dark .sp-hi-clear:hover { background: #3a2624; }
    #spChatPanel.sp-dark .sp-note { background: #2a3630; color: var(--sp-primary-light); }
    #spChatPanel.sp-dark .sp-hi-prev { color: #dfe8e3; }
    #spChatPanel.sp-dark .sp-hi-when,
    #spChatPanel.sp-dark .sp-hi-meta,
    #spChatPanel.sp-dark .sp-hi-empty { color: #7d8d86; }

    /* Mababang screen (hal. laptop na naka-zoom, o tablet na pahiga): pinapaliit
       ang taas para hindi lumampas sa screen */
    @media (max-height: 640px) and (min-width: 601px) {
        #spChatPanel { height: calc(100vh - 32px); max-height: calc(100vh - 32px); }
    }

    /* Cellphone: buong screen ang chat, gaya ng Messenger. May !important ang
       posisyon dahil nilalagyan ng positionPanel() ng inline left/top ang panel
       sa desktop. */
    @media (max-width: 600px) {
        #spChatToggle { right: 16px; bottom: 16px; }
        #spChatPanel {
            inset: 0 !important;
            width: 100% !important;
            max-width: none;
            height: 100vh;
            height: 100dvh;
            max-height: none;
            border-radius: 0;
            transform: translateY(24px);
            transform-origin: bottom center;
        }
        #spChatPanel.open { transform: none; }
        #spChatHeader {
            border-radius: 0;
            padding-top: calc(14px + env(safe-area-inset-top));
        }
        #spComposer { padding-bottom: env(safe-area-inset-bottom); }
        /* 16px para hindi mag-auto-zoom ang iPhone kapag tinapik ang input */
        #spChatInput { font-size: 16px; }
        .sp-msg { max-width: 85%; font-size: 14.5px; }
        .sp-chip { font-size: 13px; padding: 8px 14px; }
        #spChatHeader button { width: 36px; height: 36px; }
        /* Natatakpan na ng buong-screen na chat ang button, kaya itago muna */
        #spChatToggle.sp-panel-open { opacity: 0; pointer-events: none; }
        /* Huwag gumalaw ang page sa likod habang nag-i-scroll sa chat */
        body.sp-chat-lock { overflow: hidden; }
    }
</style>

<button id="spChatToggle" type="button" aria-label="Open chat assistant">
    <svg viewBox="0 0 100 120" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <linearGradient id="spPinGrad" x1="15%" y1="0%" x2="85%" y2="100%">
                <stop offset="0%" stop-color="<?php echo $spPrimaryLight; ?>" />
                <stop offset="100%" stop-color="<?php echo $spDark; ?>" />
            </linearGradient>
            <radialGradient id="spCircleGrad" cx="35%" cy="30%" r="75%">
                <stop offset="0%" stop-color="<?php echo $spPrimary; ?>" />
                <stop offset="100%" stop-color="<?php echo $spDark; ?>" />
            </radialGradient>
        </defs>
        <path d="M50 6 C27 6 8 25 8 48 C8 70 32 96 45.5 112 a6 6 0 0 0 9 0 C68 96 92 70 92 48 C92 25 73 6 50 6 Z"
              fill="url(#spPinGrad)" stroke="#ffffff" stroke-width="3.2" stroke-linejoin="round" />
        <ellipse cx="35" cy="25" rx="17" ry="9.5" fill="#ffffff" opacity="0.18" />
        <circle cx="50" cy="47" r="25" fill="#ffffff" />
        <circle cx="50" cy="47" r="20.5" fill="url(#spCircleGrad)" />
        <text x="50.6" y="54.6" text-anchor="middle" font-family="'Segoe UI', Tahoma, Verdana, sans-serif" font-size="20" font-weight="800" letter-spacing="1.2" fill="#ffffff">AI</text>
        <circle id="spPinUnderline" cx="50" cy="79" r="4.6" fill="#ffffff" />
    </svg>
</button>

<div id="spChatPanel">
    <div id="spChatHeader">
        <div class="sp-title">
            <div class="sp-head-avatar">
                <svg viewBox="0 0 64 64" aria-hidden="true">
                    <ellipse cx="11" cy="26" rx="7" ry="9" transform="rotate(-24 11 26)"/>
                    <ellipse cx="24" cy="14" rx="6.5" ry="8.5" transform="rotate(-8 24 14)"/>
                    <ellipse cx="40" cy="14" rx="6.5" ry="8.5" transform="rotate(8 40 14)"/>
                    <ellipse cx="53" cy="26" rx="7" ry="9" transform="rotate(24 53 26)"/>
                    <path d="M32 30c8 0 16 7 16 16s-8 12-16 12-16-3-16-12 8-16 16-16z"/>
                </svg>
                <span class="sp-online-dot"></span>
            </div>
            <div class="sp-head-text">
                <div class="sp-head-name"><span class="sp-word-smart">SMART</span> PET Assistant</div>
                <div class="sp-head-status" id="spHeadStatus">Online · Karaniwang sumasagot agad</div>
            </div>
        </div>
        <div class="sp-head-actions">
            <button type="button" class="sp-icon-btn" id="spHistoryBtn" aria-label="Chat history" title="Chat history">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9.5 4v16"/></svg>
            </button>
            <button type="button" id="spChatClose" aria-label="Close chat">&times;</button>
        </div>
    </div>
    <div id="spChatMessages"></div>
    <div id="spChatQuick">
        <button type="button" class="sp-quick-toggle" id="spQuickToggle" aria-expanded="true">
            <span>Quick questions</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
        </button>
        <div class="sp-chip-list" id="spChipList">
        <?php if ($spRole === 'Staff'): ?>
            <button type="button" class="sp-chip" data-q="What needs my attention today?">What needs my attention today?</button>
            <button type="button" class="sp-chip" data-q="How do I set up interview slots?">How do I set up interview slots?</button>
            <button type="button" class="sp-chip" data-q="Tips for reviewing applications">Tips for reviewing applications</button>
            <button type="button" class="sp-chip" data-q="Help">Help</button>
        <?php elseif ($spRole === 'Admin'): ?>
            <button type="button" class="sp-chip" data-q="How is the shelter doing this month?">How is the shelter doing this month?</button>
            <button type="button" class="sp-chip" data-q="How do I add a new staff member?">How do I add a new staff member?</button>
            <button type="button" class="sp-chip" data-q="Tips for keeping pet records clean">Tips for keeping pet records clean</button>
            <button type="button" class="sp-chip" data-q="Help">Help</button>
        <?php else: ?>
            <button type="button" class="sp-chip" data-q="Paano mag-adopt ng pet dito?">🐶 How to adopt</button>
            <button type="button" class="sp-chip" data-q="Ano na ang status ng application ko?">📋 Status ng application</button>
            <button type="button" class="sp-chip" data-q="Anong pet ang bagay sa akin?">💚 Bagay na pet sa akin</button>
            <button type="button" class="sp-chip" data-q="Paano magbayad ng adoption fee?">💳 Paano magbayad?</button>
            <button type="button" class="sp-chip" data-q="Paano ko makakausap ang shelter staff?">💬 Kausapin ang staff</button>
            <button type="button" class="sp-chip" data-q="Help">❓ Help</button>
        <?php endif; ?>
        </div>
    </div>
    <div id="spComposer">
        <div id="spAttachPreview" class="sp-attach-preview" hidden>
            <div class="sp-attach-thumb"><img id="spAttachImg" src="" alt=""></div>
            <div class="sp-attach-info">
                <div class="sp-attach-name" id="spAttachName"></div>
                <div class="sp-attach-size" id="spAttachSize"></div>
            </div>
            <button type="button" class="sp-attach-remove" id="spAttachRemove" aria-label="Remove attachment" title="Remove attachment">&times;</button>
        </div>
        <div id="spChatInputRow">
            <input type="file" id="spChatFile" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
            <button type="button" class="sp-icon-btn sp-attach-btn" id="spChatAttachBtn" aria-label="Attach image" title="Attach image">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a5.5 5.5 0 0 1-7.78-7.78l9.19-9.19a3.5 3.5 0 0 1 4.95 4.95l-9.19 9.19a1.5 1.5 0 0 1-2.12-2.12l8.49-8.48"/></svg>
            </button>
            <input type="text" id="spChatInput" placeholder="Mag-type ng mensahe..." autocomplete="off">
            <button type="button" id="spChatSend" aria-label="Send">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
            </button>
        </div>
    </div>

    <!-- Sidebar ng mga naunang usapan - lumalabas mula sa kaliwa, katulad ng
         mga AI chat app. May search, listahan, at ang light/dark mode sa ibaba. -->
    <div id="spDrawerBackdrop"></div>
    <div id="spHistory" aria-hidden="true">
        <div class="sp-hi-head">
            <div class="sp-hi-title">Chats</div>
            <button type="button" class="sp-hi-close" id="spHiBack" aria-label="Close">&times;</button>
        </div>
        <label class="sp-hi-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
            <input type="text" id="spHiSearch" placeholder="Search chats…" autocomplete="off">
        </label>
        <button type="button" class="sp-hi-new" id="spHiNew">+ New chat</button>
        <div class="sp-hi-list" id="spHiList"></div>
        <div class="sp-hi-foot">
            <button type="button" class="sp-hi-theme" id="spThemeBtn">
                <span id="spThemeIcon">🌙</span><span id="spThemeText">Dark mode</span>
            </button>
            <button type="button" class="sp-hi-clear" id="spHiClear" hidden>Delete all chats</button>
        </div>
    </div>
</div>

<script>
(function () {
    // Galing sa sp_url() para tama ang path sa localhost (/Caps2/...) man o sa domain
    // Walang "chat" sa pangalan ng file: hinaharang ng InfinityFree (403) ang
    // anumang URL na may salitang "chat"
    const API_URL = <?php echo json_encode(sp_url('connection/assistant_api.php')); ?>;
    const UPLOADS_URL = <?php echo json_encode(sp_url('uploads/')); ?>;
    const HISTORY_KEY = 'spChatHistory';
    const OPEN_KEY = 'spChatOpen';
    const POS_KEY = 'spChatPos';
    const THEME_KEY = 'spChatTheme';
    const ROLE_KEY = 'spChatRole';
    const SP_FIRST_NAME = String(<?php echo json_encode($spMe['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> || '').split(' ')[0];
    const GREETING = <?php echo json_encode($spRole === 'Admin'); ?>
        ? "Hi " + SP_FIRST_NAME + "! Ako ang SMART PET Assistant. Ano ang gusto mong asikasuhin sa shelter ngayon?"
        : "Hi " + SP_FIRST_NAME + "! 👋 Ako ang SMART PET Assistant. Nandito ako para samahan ka sa paghahanap ng bagong kaibigan. Pumili ka lang sa mga button sa ibaba, o itanong mo sa akin kahit ano, parang nagkukuwentuhan lang tayo.";
    const PAW_ICON_SVG = '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="11" cy="26" rx="7" ry="9" transform="rotate(-24 11 26)"/><ellipse cx="24" cy="14" rx="6.5" ry="8.5" transform="rotate(-8 24 14)"/><ellipse cx="40" cy="14" rx="6.5" ry="8.5" transform="rotate(8 40 14)"/><ellipse cx="53" cy="26" rx="7" ry="9" transform="rotate(24 53 26)"/><path d="M32 30c8 0 16 7 16 16s-8 12-16 12-16-3-16-12 8-16 16-16z"/></svg>';
    const SP_ROLE = <?php echo json_encode($spRole); ?>;
    const ME = <?php echo json_encode($spMe, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    const toggleBtn = document.getElementById('spChatToggle');
    const closeBtn = document.getElementById('spChatClose');
    const panel = document.getElementById('spChatPanel');
    const messagesEl = document.getElementById('spChatMessages');
    const input = document.getElementById('spChatInput');
    const sendBtn = document.getElementById('spChatSend');
    const fileInput = document.getElementById('spChatFile');
    const attachBtn = document.getElementById('spChatAttachBtn');
    const attachPreview = document.getElementById('spAttachPreview');
    const attachImg = document.getElementById('spAttachImg');
    const attachName = document.getElementById('spAttachName');
    const attachSize = document.getElementById('spAttachSize');
    const attachRemove = document.getElementById('spAttachRemove');

    const historyBtn = document.getElementById('spHistoryBtn');
    const backdrop = document.getElementById('spDrawerBackdrop');
    const historyView = document.getElementById('spHistory');
    const hiBack = document.getElementById('spHiBack');
    const hiSearch = document.getElementById('spHiSearch');
    const hiNew = document.getElementById('spHiNew');
    const hiList = document.getElementById('spHiList');
    const hiClear = document.getElementById('spHiClear');
    const themeBtn = document.getElementById('spThemeBtn');
    const themeIcon = document.getElementById('spThemeIcon');
    const themeText = document.getElementById('spThemeText');

    // --- Dark / light mode. Nananatili ang pinili sa lahat ng page. ---
    let darkMode = false;
    try { darkMode = localStorage.getItem(THEME_KEY) === 'dark'; } catch (e) { /* light na lang */ }

    function applyTheme() {
        panel.classList.toggle('sp-dark', darkMode);
        // Ang nakasulat ay ang mode na pupuntahan, hindi ang kasalukuyan
        themeIcon.textContent = darkMode ? '☀️' : '🌙';
        themeText.textContent = darkMode ? 'Light mode' : 'Dark mode';
    }

    applyTheme();

    function loadHistory() {
        try {
            return JSON.parse(sessionStorage.getItem(HISTORY_KEY)) || [];
        } catch (e) {
            return [];
        }
    }

    function saveHistory(history) {
        try {
            sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history));
        } catch (e) { /* storage unavailable, ignore */ }
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // --- Image attachment: pumili/mag-paste ng screenshot, i-resize sa client
    // muna bago i-preview at i-send, para maliit lang ang laman ng request at
    // ng sessionStorage. ---
    const ALLOWED_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    const MAX_IMAGE_DIMENSION = 1400;
    let pendingImage = null; // { dataUrl, mediaType, name, size }

    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return Math.round(n / 1024) + ' KB';
        return (n / 1024 / 1024).toFixed(1) + ' MB';
    }

    // Binabawasan lang ang sukat kung mas malaki sa limit - hindi ginagalaw ang
    // GIF dahil nawawala ang animation kapag pinasa ito sa canvas.
    function resizeImageIfNeeded(file) {
        return new Promise(function (resolve) {
            if (file.type === 'image/gif') {
                const reader = new FileReader();
                reader.onload = function () { resolve({ dataUrl: reader.result, mediaType: file.type }); };
                reader.readAsDataURL(file);
                return;
            }

            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = function () {
                URL.revokeObjectURL(url);
                const scale = Math.min(1, MAX_IMAGE_DIMENSION / Math.max(img.width, img.height));
                const w = Math.round(img.width * scale);
                const h = Math.round(img.height * scale);
                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                const mediaType = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
                resolve({ dataUrl: canvas.toDataURL(mediaType, 0.85), mediaType: mediaType });
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                const reader = new FileReader();
                reader.onload = function () { resolve({ dataUrl: reader.result, mediaType: file.type }); };
                reader.readAsDataURL(file);
            };
            img.src = url;
        });
    }

    // Lumalabas ang preview chip pagkatapos lang talaga mapili/ma-paste ang
    // file - kailanman hindi bago iyon. Nililinis din ito (clearPendingImage)
    // kapag isinara ang chat o nag-new chat, para hindi na matira ang lumang
    // attachment sa susunod na pagbukas.
    function clearPendingImage() {
        pendingImage = null;
        attachPreview.hidden = true;
        attachImg.src = '';
        attachName.textContent = '';
        attachSize.textContent = '';
        fileInput.value = '';
    }

    async function setPendingImage(file) {
        if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
            addMessage('bot', 'Pasensya na po, hindi ko pa mabuksan ang ganitong file. Puwede po bang PNG, JPEG, WEBP, o GIF na larawan ang i-attach mo?');
            return;
        }
        if (file.size > MAX_IMAGE_BYTES) {
            addMessage('bot', 'Medyo malaki po ang larawang ito. Puwede po bang mas maliit sa 5MB ang i-attach mo? Salamat po!');
            return;
        }

        const resized = await resizeImageIfNeeded(file);
        pendingImage = { dataUrl: resized.dataUrl, mediaType: resized.mediaType, name: file.name, size: file.size };
        attachImg.src = resized.dataUrl;
        attachName.textContent = file.name;
        attachSize.textContent = formatBytes(file.size);
        attachPreview.hidden = false;
    }

    attachBtn.addEventListener('click', function () { fileInput.click(); });
    attachRemove.addEventListener('click', clearPendingImage);
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) { setPendingImage(fileInput.files[0]); }
        fileInput.value = '';
    });
    input.addEventListener('paste', function (e) {
        const items = (e.clipboardData || {}).items || [];
        for (let i = 0; i < items.length; i++) {
            if (items[i].type && items[i].type.indexOf('image/') === 0) {
                const file = items[i].getAsFile();
                if (file) {
                    e.preventDefault();
                    setPendingImage(file);
                }
                break;
            }
        }
    });

    // animate: totoo kapag bagong dating ang resulta - isa-isang lalabas ang
    // mga card. Huwad kapag ibinabalik lang mula sa history - agad na nakikita.
    function formatClock(ms) {
        return new Date(ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    // time: oras (ms) kung kailan ipinadala - wala ito sa mga lumang usapan
    // mula sa history, kaya doon ay hindi na lang ipinapakita ang oras.
    function renderMessage(role, text, extra, animate, time) {
        // Tanda lang ito (hal. "Chat from Sep 8"), hindi bahagi ng usapan
        if (role === 'note') {
            messagesEl.appendChild(el('div', 'sp-note', text));
            messagesEl.scrollTop = messagesEl.scrollHeight;
            return;
        }

        const bubble = document.createElement('div');
        bubble.className = 'sp-msg ' + (role === 'user' ? 'user' : 'bot');

        if (extra && extra.image) {
            const img = el('img', 'sp-msg-image');
            img.src = extra.image.indexOf('data:') === 0 ? extra.image : (UPLOADS_URL + encodeURIComponent(extra.image));
            img.alt = 'Attached image';
            bubble.appendChild(img);
        }
        if (text) {
            bubble.appendChild(document.createTextNode(text));
        }

        if (role === 'user') {
            messagesEl.appendChild(bubble);
        } else {
            const row = el('div', 'sp-msg-row');
            const avatar = el('span', 'sp-msg-avatar');
            avatar.innerHTML = PAW_ICON_SVG;
            row.appendChild(avatar);
            row.appendChild(bubble);
            messagesEl.appendChild(row);
        }

        if (extra && extra.link) {
            const cta = el('a', 'sp-msg-link', extra.link.label);
            cta.href = extra.link.url;
            messagesEl.appendChild(cta);
        }

        if (time) {
            messagesEl.appendChild(el('div', 'sp-time ' + (role === 'user' ? 'user' : 'bot'), formatClock(time)));
        }

        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function addMessage(role, text, extra) {
        const time = Date.now();
        renderMessage(role, text, extra, true, time);
        const history = loadHistory();
        history.push({ role: role, text: text, extra: extra || null, time: time });
        saveHistory(history);
    }

    // --- Mga naunang usapan ---

    function formatWhen(sqlTime) {
        if (!sqlTime) { return ''; }
        const when = new Date(String(sqlTime).replace(' ', 'T'));
        if (isNaN(when.getTime())) { return sqlTime; }

        const clock = when.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        if (when.toDateString() === new Date().toDateString()) {
            return 'Today, ' + clock;
        }
        return when.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ' + clock;
    }

    // Ang laman ng request ay JSON na naka-base64 sa form field na "p". Sa free
    // hosting (InfinityFree), hinaharang ng firewall ang POST na may raw JSON o
    // mahabang text ("Error 403"); hindi nito binabasa ang base64, kaya dumadaan.
    const FORM_HEADERS = { 'Content-Type': 'application/x-www-form-urlencoded' };
    function encodeBody(payload) {
        const json = JSON.stringify(payload);
        return 'p=' + encodeURIComponent(btoa(unescape(encodeURIComponent(json))));
    }

    function askServer(payload) {
        return fetch(API_URL, {
            method: 'POST',
            headers: FORM_HEADERS,
            body: encodeBody(payload)
        }).then(function (r) { return r.json(); });
    }

    // Ang buong listahan mula sa server; ang search ay sinasala lang ito dito sa
    // browser, kaya walang bagong tawag sa bawat titik na itinatype.
    let allSessions = [];

    function openHistory(focusSearch) {
        historyView.classList.add('open');
        historyView.setAttribute('aria-hidden', 'false');
        backdrop.classList.add('open');
        if (focusSearch) { setTimeout(function () { hiSearch.focus(); }, 260); }

        hiList.textContent = '';
        const waiting = el('div', 'sp-typing');
        waiting.appendChild(el('span'));
        waiting.appendChild(el('span'));
        waiting.appendChild(el('span'));
        hiList.appendChild(waiting);

        askServer({ action: 'history' })
            .then(function (data) {
                allSessions = data.sessions || [];
                applySearch();
            })
            .catch(function () { renderHistory(null); });
    }

    function closeHistory() {
        historyView.classList.remove('open');
        historyView.setAttribute('aria-hidden', 'true');
        backdrop.classList.remove('open');
    }

    function applySearch() {
        const q = hiSearch.value.trim().toLowerCase();
        if (!q) { renderHistory(allSessions); return; }
        renderHistory(allSessions.filter(function (s) {
            return (s.first_message || '').toLowerCase().indexOf(q) !== -1;
        }), true);
    }

    // filtered: totoo kapag resulta ito ng search - iba ang empty message
    function renderHistory(sessions, filtered) {
        hiList.textContent = '';
        hiClear.hidden = !allSessions.length;

        if (sessions === null) {
            hiList.appendChild(el('div', 'sp-hi-empty', 'Your past chats could not be loaded right now.'));
            return;
        }

        if (!sessions.length) {
            hiList.appendChild(el('div', 'sp-hi-empty', filtered
                ? 'No chats match your search.'
                : 'No past chats yet. Anything you ask here will show up in this list.'));
            return;
        }

        sessions.forEach(function (s) {
            // div at hindi button, dahil may button (delete) sa loob nito
            const item = el('div', 'sp-hi-item');
            item.setAttribute('role', 'button');
            item.tabIndex = 0;
            item.appendChild(el('div', 'sp-hi-when', formatWhen(s.started_at)));
            item.appendChild(el('div', 'sp-hi-prev', s.first_message || 'Untitled chat'));

            const count = Number(s.message_count) || 0;
            const closing = s.ended_at ? ('ended ' + formatWhen(s.ended_at)) : 'still open';
            item.appendChild(el('div', 'sp-hi-meta',
                count + ' message' + (count === 1 ? '' : 's') + ' · ' + closing));

            const del = el('button', 'sp-hi-del', '🗑');
            del.type = 'button';
            del.title = 'Delete this chat';
            del.setAttribute('aria-label', 'Delete this chat');
            del.addEventListener('click', function (e) {
                e.stopPropagation();   // huwag buksan ang usapan, burahin lang
                deleteSession(s.session_id, item);
            });
            item.appendChild(del);

            item.addEventListener('click', function () { openTranscript(s.session_id); });
            item.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openTranscript(s.session_id);
                }
            });
            hiList.appendChild(item);
        });
    }

    function deleteSession(sessionId, item) {
        if (!window.confirm('Delete this chat? This cannot be undone.')) { return; }
        askServer({ action: 'delete', session_id: sessionId })
            .then(function (data) {
                if (!data.ok) { return; }
                allSessions = allSessions.filter(function (s) { return s.session_id !== sessionId; });
                item.remove();
                // Wala nang natira sa nakikita - ipakita ang empty state
                if (!hiList.querySelector('.sp-hi-item')) { applySearch(); }
                hiClear.hidden = !allSessions.length;
            })
            .catch(function () { /* mananatili ang item, walang nabura */ });
    }

    function deleteAllSessions() {
        if (!window.confirm('Delete all your chats? This cannot be undone.')) { return; }
        askServer({ action: 'delete_all' })
            .then(function (data) {
                if (!data.ok) { return; }
                allSessions = [];
                hiSearch.value = '';
                renderHistory([]);
                // Kasama sa binura ang nasa screen, kaya malinis na simula rin doon
                resetChat();
            })
            .catch(function () {});
    }

    function openTranscript(sessionId) {
        askServer({ action: 'transcript', session_id: sessionId })
            .then(function (data) {
                if (!data.ok || !data.messages || !data.messages.length) { return; }

                // Ang lumang usapan ang ipinapalit sa screen - kasama ang pet cards
                // na lumabas noon - at ito rin ang magiging alaala ng assistant
                // kapag nagpatuloy ang pagtatanong.
                const restored = [
                    { role: 'note', text: 'Chat from ' + formatWhen(data.started_at), extra: null }
                ];
                data.messages.forEach(function (m) {
                    restored.push({
                        role: m.sender === 'user' ? 'user' : 'bot',
                        text: m.message,
                        extra: m.extra || null
                    });
                });

                saveHistory(restored);
                messagesEl.textContent = '';
                restored.forEach(function (m) { renderMessage(m.role, m.text, m.extra, false); });
                setQuickCollapsed(true);
                closeHistory();

                // Doon idudugtong ang mga susunod na mensahe, hindi sa bagong session
                askServer({ action: 'resume', session_id: sessionId }).catch(function () {});
            })
            .catch(function () { /* iwanang bukas ang listahan */ });
    }

    function resetChat() {
        saveHistory([]);
        messagesEl.textContent = '';
        clearPendingImage();
        addMessage('bot', GREETING);
        setQuickCollapsed(false);
    }

    function startNewChat() {
        endChatSession();
        resetChat();
        closeHistory();
    }

    function clamp(val, min, max) {
        return Math.min(Math.max(val, min), max);
    }

    // Keeps the bubble's dragged position, shared across every page via localStorage
    function applyStoredPosition() {
        try {
            const pos = JSON.parse(localStorage.getItem(POS_KEY));
            if (!pos) return;
            const maxLeft = window.innerWidth - toggleBtn.offsetWidth - 4;
            const maxTop = window.innerHeight - toggleBtn.offsetHeight - 4;
            toggleBtn.style.left = clamp(pos.left, 4, Math.max(4, maxLeft)) + 'px';
            toggleBtn.style.top = clamp(pos.top, 4, Math.max(4, maxTop)) + 'px';
            toggleBtn.style.right = 'auto';
            toggleBtn.style.bottom = 'auto';
        } catch (e) { /* ignore, keep default corner position */ }
    }

    function positionPanel() {
        const btnRect = toggleBtn.getBoundingClientRect();
        const panelW = panel.offsetWidth || 320;
        const panelH = panel.offsetHeight || 440;

        let left = btnRect.left + btnRect.width / 2 - panelW / 2;
        let top = btnRect.top - panelH - 10;
        if (top < 8) { top = btnRect.bottom + 10; }
        if (top + panelH > window.innerHeight - 8) { top = Math.max(8, window.innerHeight - panelH - 8); }
        left = clamp(left, 8, Math.max(8, window.innerWidth - panelW - 8));

        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
    }

    // Sinasabi sa server na tapos na ang usapan, para tiyak ang oras ng pagtatapos
    // sa history. Beacon ito, kaya nakakarating kahit paalis na sa page.
    function endChatSession() {
        try {
            const body = encodeBody({ action: 'end' });
            if (navigator.sendBeacon) {
                navigator.sendBeacon(API_URL, new Blob([body], { type: 'application/x-www-form-urlencoded' }));
            } else {
                fetch(API_URL, {
                    method: 'POST',
                    headers: FORM_HEADERS,
                    body: body,
                    keepalive: true
                });
            }
        } catch (e) { /* kung mabigo, may 30-minutong timeout pa rin sa server */ }
    }

    function setOpen(isOpen) {
        const wasOpen = panel.classList.contains('open');
        if (!isOpen && wasOpen) {
            endChatSession();
            clearPendingImage();
        }
        panel.classList.toggle('open', isOpen);
        toggleBtn.classList.toggle('sp-panel-open', isOpen);
        document.body.classList.toggle('sp-chat-lock', isOpen);
        if (isOpen) {
            positionPanel();
            // Sa cellphone, huwag agad buksan ang keyboard - tatakpan nito ang
            // kalahati ng chat bago pa mabasa ang pagbati at mga button
            if (window.innerWidth > 600) { input.focus(); }
        }
        const underline = document.getElementById('spPinUnderline');
        if (underline) { underline.setAttribute('fill', isOpen ? '#90ee90' : '#ffffff'); }
    }

    // --- Drag to move the bubble anywhere on screen ---
    let dragState = null;

    toggleBtn.addEventListener('pointerdown', function (e) {
        const rect = toggleBtn.getBoundingClientRect();
        dragState = { startX: e.clientX, startY: e.clientY, origLeft: rect.left, origTop: rect.top, moved: false };
        toggleBtn.setPointerCapture(e.pointerId);
    });

    toggleBtn.addEventListener('pointermove', function (e) {
        if (!dragState) return;
        const dx = e.clientX - dragState.startX;
        const dy = e.clientY - dragState.startY;

        if (!dragState.moved && (Math.abs(dx) > 5 || Math.abs(dy) > 5)) {
            dragState.moved = true;
            toggleBtn.classList.add('dragging');
            document.body.classList.add('sp-no-select');
        }

        if (dragState.moved) {
            const maxLeft = window.innerWidth - toggleBtn.offsetWidth - 4;
            const maxTop = window.innerHeight - toggleBtn.offsetHeight - 4;
            const left = clamp(dragState.origLeft + dx, 4, Math.max(4, maxLeft));
            const top = clamp(dragState.origTop + dy, 4, Math.max(4, maxTop));
            toggleBtn.style.left = left + 'px';
            toggleBtn.style.top = top + 'px';
            toggleBtn.style.right = 'auto';
            toggleBtn.style.bottom = 'auto';
            if (panel.classList.contains('open')) positionPanel();
        }
    });

    function endDrag() {
        if (!dragState) return false;
        const wasMoved = dragState.moved;
        toggleBtn.classList.remove('dragging');
        document.body.classList.remove('sp-no-select');
        if (wasMoved) {
            const rect = toggleBtn.getBoundingClientRect();
            try { localStorage.setItem(POS_KEY, JSON.stringify({ left: rect.left, top: rect.top })); } catch (e) {}
        }
        dragState = null;
        return wasMoved;
    }

    toggleBtn.addEventListener('pointerup', function () {
        const wasMoved = endDrag();
        if (!wasMoved) { setOpen(!panel.classList.contains('open')); }
    });
    toggleBtn.addEventListener('pointercancel', endDrag);

    window.addEventListener('resize', function () {
        applyStoredPosition();
        if (panel.classList.contains('open')) positionPanel();
    });

    applyStoredPosition();

    // Habang naghihintay ng sagot, naka-lock ang input para hindi magkapatong
    let busy = false;

    function setBusy(isBusy) {
        busy = isBusy;
        input.disabled = isBusy;
        sendBtn.disabled = isBusy;
        attachBtn.disabled = isBusy;
        // Sa cellphone, huwag ibalik ang keyboard pagkatapos ng sagot, para
        // mabasa muna nang buo ang mensahe
        if (!isBusy && window.innerWidth > 600) input.focus();
    }

    const headStatus = document.getElementById('spHeadStatus');
    const HEAD_STATUS_IDLE = headStatus.textContent;

    // Typing dots na may avatar sa tabi, at "typing..." sa header - gaya ng
    // karaniwang chat app habang hinihintay ang sagot
    function showTyping() {
        const row = el('div', 'sp-msg-row');
        const avatar = el('span', 'sp-msg-avatar');
        avatar.innerHTML = PAW_ICON_SVG;
        const dots = el('div', 'sp-msg bot sp-typing');
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        row.appendChild(avatar);
        row.appendChild(dots);
        messagesEl.appendChild(row);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        headStatus.textContent = 'typing...';
        return {
            remove: function () {
                row.remove();
                headStatus.textContent = HEAD_STATUS_IDLE;
            }
        };
    }

    async function sendMessage(text) {
        text = text.trim();
        if ((!text && !pendingImage) || busy) return;

        // Ang naunang usapan ang nagbibigay ng memorya sa assistant. Hindi kasama
        // ang mga tanda (note) - hindi 'yon sinabi ng kahit sino.
        const priorTurns = loadHistory().filter(function (m) {
            return m.role !== 'note';
        }).map(function (m) {
            return { role: m.role === 'user' ? 'user' : 'assistant', text: m.text };
        }).slice(-10);

        const imageToSend = pendingImage;
        clearPendingImage();
        setQuickCollapsed(true);

        addMessage('user', text, imageToSend ? { image: imageToSend.dataUrl } : null);
        input.value = '';
        setBusy(true);

        const typing = showTyping();
        const typingStarted = Date.now();

        let reply = "Ay, pasensya na po! Parang nagkaproblema sa koneksyon ko ngayon. Puwede mo po bang subukan ulit maya-maya? Nandito lang ako. 😊";
        let extra = null;

        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: FORM_HEADERS,
                body: encodeBody({
                    message: text,
                    history: priorTurns,
                    image: imageToSend ? { data: imageToSend.dataUrl, media_type: imageToSend.mediaType } : undefined
                })
            });
            // Binabasa bilang text muna: sa online hosting, minsan may PHP warning
            // o HTML bago ang JSON. Kinukuha ang mismong JSON sa loob ng sagot.
            const raw = await res.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                const start = raw.indexOf('{"');
                const end = raw.lastIndexOf('}');
                if (start !== -1 && end > start) {
                    try { data = JSON.parse(raw.slice(start, end + 1)); } catch (e2) { data = null; }
                }
            }
            if (data) {
                reply = data.reply || "Pasensya na po, may maliit na aberya sa side ko. Puwede mo po bang ulitin ang tanong mo?";
                extra = { link: data.link };
                if (data.debug) { console.warn('[SMART PET Assistant] ' + data.debug); }
            } else {
                console.warn('[SMART PET Assistant] HTTP ' + res.status + ' - hindi JSON ang sagot ng server:', raw.slice(0, 500));
                reply += ' (Error ' + res.status + ')';
            }
        } catch (e) {
            console.warn('[SMART PET Assistant] Hindi maabot ang server:', e);
        }

        // Kapag sobrang bilis ng sagot (hal. mga nakahandang sagot), sandaling
        // ipakita pa rin ang typing para hindi biglang sumulpot ang mensahe
        const MIN_TYPING_MS = 900;
        const elapsed = Date.now() - typingStarted;
        if (elapsed < MIN_TYPING_MS) {
            await new Promise(function (r) { setTimeout(r, MIN_TYPING_MS - elapsed); });
        }

        typing.remove();
        addMessage('bot', reply, extra);
        setBusy(false);
    }

    closeBtn.addEventListener('click', function () { setOpen(false); });
    sendBtn.addEventListener('click', function () { sendMessage(input.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { sendMessage(input.value); }
    });
    // Nakabukas ang mabilisang tanong sa bagong usapan; itinatago kapag
    // nagsimula nang magtanong para maluwag ang mensahe. Puwedeng buksan ulit.
    const quickBox = document.getElementById('spChatQuick');
    const quickToggle = document.getElementById('spQuickToggle');

    function setQuickCollapsed(collapsed) {
        quickBox.classList.toggle('collapsed', collapsed);
        quickToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    quickToggle.addEventListener('click', function () {
        setQuickCollapsed(!quickBox.classList.contains('collapsed'));
        messagesEl.scrollTop = messagesEl.scrollHeight;
    });

    document.querySelectorAll('.sp-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            setQuickCollapsed(true);
            sendMessage(chip.dataset.q);
        });
    });

    historyBtn.addEventListener('click', function () { openHistory(false); });
    backdrop.addEventListener('click', closeHistory);
    hiBack.addEventListener('click', closeHistory);
    hiNew.addEventListener('click', startNewChat);
    hiClear.addEventListener('click', deleteAllSessions);
    hiSearch.addEventListener('input', applySearch);
    themeBtn.addEventListener('click', function () {
        darkMode = !darkMode;
        try { localStorage.setItem(THEME_KEY, darkMode ? 'dark' : 'light'); } catch (e) {}
        applyTheme();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (historyView.classList.contains('open')) {
            closeHistory();
        }
    });

    // Nagpalit ng role ang naka-login sa tab na ito, kaya linisin ang nasa screen.
    // Hindi dapat makita ng Staff ang naiwang usapan niya noong Adopter pa siya.
    try {
        if (sessionStorage.getItem(ROLE_KEY) !== SP_ROLE) {
            sessionStorage.removeItem(HISTORY_KEY);
            sessionStorage.setItem(ROLE_KEY, SP_ROLE);
        }
    } catch (e) { /* storage unavailable, ipagpatuloy lang */ }

    // Laging walang laman ang attachment sa bagong page load. Kahit ibalik ng
    // browser ang dating laman ng file input pagka-reload, wala itong dalang
    // pendingImage - kaya sa pagpili o pag-paste lang talaga lumalabas ang chip.
    clearPendingImage();

    // Restore history/state from this browser tab's session (persists across page navigation)
    const history = loadHistory();
    if (history.length === 0) {
        addMessage('bot', GREETING);
    } else {
        history.forEach(function (m) { renderMessage(m.role, m.text, m.extra, false, m.time); });
    }
    // May usapan na (may tanong na ang user) - itago muna ang mabilisang tanong
    setQuickCollapsed(history.some(function (m) { return m.role === 'user'; }));

    // Laging sarado ang chat pagbukas ng page, kahit naiwang bukas sa
    // naunang page. Naaalala pa rin ang usapan (history) pagbukas ulit.
    try { sessionStorage.removeItem(OPEN_KEY); } catch (e) {}
})();
</script>
