<?php
/**
 * Header ng mga Admin page - kapareho ng sa Adopter at Staff, pero sa kulay ng admin:
 *   - puting header na sumasakop sa buong lapad, bilog lang ang kaliwang itaas,
 *     at nananatili sa itaas habang nag-i-scroll
 *   - profile: bilog na larawan na may orange na singsing, tuldok na "naka-login",
 *     pangalan, at ⌄
 *   - pindot sa profile = menu na may Account Settings (orange na modal, gaya ng
 *     sa Adopter at Staff) at Logout (wala na ang Logout sa sidebar)
 * Isinasama ng connection/chat_bubble.php (ang iisang include na nasa bawat page),
 * kaya isinisingit sa header gamit ang JavaScript - hindi na binabago ang bawat page.
 */
$spAdminBase = function_exists('sp_base_url') ? sp_base_url() : '/Caps2';
$spAdminLinks = [
    'settings' => $spAdminBase . '/AdminFile/edit_profile.php',
    'logout' => $spAdminBase . '/Login_Page/logout.php',
];
?>
<style>
    /* ===== Walang nakikitang scrollbar sa mga admin page =====
       Nakatago lang ang bar - gumagana pa rin ang pag-scroll gamit ang mouse
       wheel, touchpad, keyboard at touch. Para sa lahat ng scroll area (buong
       page, sidebar, table, modal, chat). */
    html, body, * {
        scrollbar-width: none !important;     /* Firefox, bagong Chrome/Edge */
        -ms-overflow-style: none !important;  /* lumang Edge */
    }
    html::-webkit-scrollbar,
    body::-webkit-scrollbar,
    *::-webkit-scrollbar {
        display: none !important;             /* Chrome, Edge, Safari */
        width: 0 !important;
        height: 0 !important;
    }

    /* ===== Header: buong lapad, bilog ang kaliwang itaas, nakadikit sa itaas ===== */
    .main-wrapper > .top-header {
        position: sticky;
        top: 0;
        z-index: 900;
        height: 68px;
        padding: 0 32px;
        background: #fff;
        border-bottom: 1px solid #eef0f2;
        border-top-left-radius: 30px;
        box-shadow: 0 2px 10px rgba(27, 67, 50, 0.04);
    }

    /* ===== Profile (bilog na larawan, orange na singsing, tuldok na naka-login) ===== */
    .sp-admin-profile-wrap { position: relative; }
    .main-wrapper > .top-header .profile-section {
        position: relative;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 4px 8px 4px 4px;
        cursor: pointer;
        background: none;
    }
    .main-wrapper > .top-header .profile-section .avatar {
        width: 46px !important;
        height: 46px !important;
        border-radius: 50% !important;
        border: 3px solid transparent;
        box-sizing: border-box;
        flex-shrink: 0;
        background:
            linear-gradient(#f3f4f6, #f3f4f6) padding-box,
            conic-gradient(from 200deg, #cc5600, #ff8c1a, #ffd29e, #ffb15e, #cc5600) border-box !important;
        box-shadow: 0 4px 12px rgba(204, 86, 0, 0.22);
    }
    /* Tuldok: naka-login */
    .main-wrapper > .top-header .profile-section::before {
        content: "";
        position: absolute;
        left: 38px;
        top: 36px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #22c55e;
        border: 2.5px solid #fff;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
        z-index: 1;
    }
    .main-wrapper > .top-header .profile-name {
        color: #1b4332;
        font-size: 16px;
        font-weight: 700;
        max-width: 180px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .sp-admin-caret {
        font-size: 11px;
        color: #6b7280;
        transition: transform 0.2s ease;
    }
    .sp-admin-profile-wrap.open .sp-admin-caret { transform: rotate(180deg); }

    /* ===== Menu: Account Settings at Logout ===== */
    .sp-admin-menu {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        z-index: 3000;
        min-width: 210px;
        padding: 6px;
        background: #fff;
        border: 1px solid #ece4d6;
        border-radius: 12px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14);
        opacity: 0;
        transform: translateY(-6px);
        pointer-events: none;
        transition: opacity 0.18s ease, transform 0.18s ease;
    }
    .sp-admin-profile-wrap.open .sp-admin-menu {
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
    }
    .sp-admin-menu a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        color: #1f2933;
        text-decoration: none;
    }
    .sp-admin-menu a i { width: 16px; text-align: center; color: #2d6a4f; }
    .sp-admin-menu a:hover { background: #f4f8f5; }
    .sp-admin-menu .sp-menu-sep { height: 1px; margin: 4px 6px; background: #f1ece3; }
    .sp-admin-menu a.sp-logout,
    .sp-admin-menu a.sp-logout i { color: #c5221f; }
    .sp-admin-menu a.sp-logout:hover { background: #fdecea; }

    /* Maliit na laptop/projector: mas makitid ang sidebar (responsive.php), kaya
       hindi na hinahayaang tumupi sa dalawang linya ang "Manage Adopters" */
    @media (min-width: 901px) and (max-width: 1366px) {
        .sidebar .menu-item {
            white-space: nowrap;
            gap: 10px;
            padding-left: 14px;
            padding-right: 14px;
            font-size: 15px;
        }
    }

    /* Telepono/tablet: may sariling mobile bar (responsive.php), kaya ipinapatong
       ang profile sa kanang bahagi nito - iisang header lang, walang bakanteng
       puting kahon sa ilalim */
    @media (max-width: 900px) {
        .main-wrapper > .top-header {
            position: fixed;
            top: 0;
            right: 0;
            left: auto;
            z-index: 9995;
            height: 54px;
            padding: 0 10px;
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }
        .main-wrapper > .top-header .profile-section { gap: 8px; }
        .main-wrapper > .top-header .profile-section .avatar {
            width: 38px !important;
            height: 38px !important;
        }
        .main-wrapper > .top-header .profile-section::before { left: 32px; top: 30px; width: 10px; height: 10px; }
        .main-wrapper > .top-header .profile-name { font-size: 14px; max-width: 120px; }
        #spMobileBar .sp-role { display: none; }
    }
    @media (max-width: 600px) {
        .main-wrapper > .top-header .profile-name { display: none; }
    }
    @media (prefers-reduced-motion: reduce) {
        .sp-admin-menu, .sp-admin-caret { transition: none; }
    }
</style>
<script>
(function () {
    var LINKS = <?php echo json_encode($spAdminLinks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function init() {
        var header = document.querySelector('.main-wrapper > .top-header');
        var profile = header && header.querySelector('.profile-section');
        if (!profile || header.querySelector('.sp-admin-profile-wrap')) return;

        var wrap = document.createElement('div');
        wrap.className = 'sp-admin-profile-wrap';
        profile.parentNode.insertBefore(wrap, profile);
        wrap.appendChild(profile);

        var caret = document.createElement('i');
        caret.className = 'fa-solid fa-chevron-down sp-admin-caret';
        caret.setAttribute('aria-hidden', 'true');
        profile.appendChild(caret);

        var menu = document.createElement('div');
        menu.className = 'sp-admin-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML =
            '<a role="menuitem" class="sp-settings"><i class="fa-solid fa-user-gear"></i> Account Settings</a>' +
            '<div class="sp-menu-sep"></div>' +
            '<a role="menuitem" class="sp-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>';
        menu.querySelector('.sp-settings').href = LINKS.settings;
        menu.querySelector('.sp-logout').href = LINKS.logout;
        wrap.appendChild(menu);

        profile.setAttribute('aria-haspopup', 'menu');
        profile.setAttribute('aria-expanded', 'false');

        function setOpen(open) {
            wrap.classList.toggle('open', open);
            profile.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        profile.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setOpen(!wrap.classList.contains('open'));
        });
        // Account Settings: ang modal (orange); kung wala, ang Edit Profile page ng admin
        menu.querySelector('.sp-settings').addEventListener('click', function (e) {
            if (typeof window.spOpenProfile !== 'function') return;
            e.preventDefault();
            e.stopPropagation();
            setOpen(false);
            window.spOpenProfile();
        });
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) setOpen(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
