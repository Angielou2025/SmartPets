<?php
/**
 * Bell ng notifications sa itaas ng mga Adopter page, sa kaliwa ng profile.
 * Pinalitan nito ang "Notifications" sa sidebar. Dito rin ang menu ng profile
 * (Account Settings, Logout) - wala na ang Logout sa sidebar. Isinasama ng
 * connection/chat_bubble.php (ang iisang include na nasa bawat page), kaya
 * isinisingit ito sa header gamit ang JavaScript - hindi na kailangang
 * baguhin ang bawat page.
 *
 * Kailangan: $pdo at naka-login na Adopter.
 */
$spBellCount = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']);
$spBellActive = basename($_SERVER['PHP_SELF'] ?? '') === 'notifications.php';
?>
<style>
    /* Magkatabi sa kanan ng header: bell | profile */
    .sp-header-right {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-left: auto;
    }

    .sp-bell {
        position: relative;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #1f2933;
        text-decoration: none;
        border-radius: 10px;
    }
    .sp-bell:focus-visible {
        outline: 2px solid #2e5b32;
        outline-offset: 2px;
    }
    .sp-bell svg {
        width: 24px;
        height: 24px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.75;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    /* Nasa Notifications page: buong itim na bell */
    .sp-bell.active svg .bell-body {
        fill: currentColor;
    }

    /* Manipis na guhit sa pagitan ng bell at profile */
    .sp-header-divider {
        width: 1px;
        height: 30px;
        background: #e2e5e9;
        flex-shrink: 0;
    }

    /* Bilang ng hindi pa nababasa */
    .sp-bell-badge {
        position: absolute;
        top: 3px;
        right: 2px;
        min-width: 18px;
        height: 18px;
        padding: 0 4px;
        border-radius: 9px;
        background: #d93025;
        color: #fff;
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        font-size: 11px;
        font-weight: 600;
        line-height: 18px;
        text-align: center;
        letter-spacing: -0.2px;
        box-shadow: 0 0 0 2px #fff;
        box-sizing: border-box;
        animation: spBadgeIn .35s cubic-bezier(.34, 1.56, .64, 1) .3s both;
    }
    @keyframes spBadgeIn {
        from { transform: scale(0); }
        to { transform: scale(1); }
    }

    @media (prefers-reduced-motion: reduce) {
        .sp-bell-badge { animation: none; }
    }

    @media (max-width: 600px) {
        .sp-header-right { gap: 12px; }
        .sp-header-divider { display: none; }
    }

    /* Menu ng profile (Account Settings, Logout) - pinalitan ang Logout sa sidebar */
    .sp-profile-wrap { position: relative; }
    .sp-profile-wrap > .user-profile-link,
    .sp-profile-wrap > .user-profile { cursor: pointer; }
    .sp-profile-caret {
        margin-left: 6px;
        font-size: 11px;
        color: #6b7280;
        transition: transform 0.2s ease;
    }
    .sp-profile-wrap.open .sp-profile-caret { transform: rotate(180deg); }
    .sp-profile-menu {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        z-index: 3000;
        min-width: 200px;
        padding: 6px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14);
        opacity: 0;
        transform: translateY(-6px);
        pointer-events: none;
        transition: opacity 0.18s ease, transform 0.18s ease;
    }
    .sp-profile-wrap.open .sp-profile-menu {
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
    }
    .sp-profile-menu a {
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
    .sp-profile-menu a i { width: 16px; text-align: center; color: #6b7280; }
    .sp-profile-menu a:hover { background: #f3f6f4; }
    .sp-profile-menu .sp-menu-sep { height: 1px; margin: 4px 6px; background: #eef0f2; }
    .sp-profile-menu a.sp-logout,
    .sp-profile-menu a.sp-logout i { color: #c5221f; }
    .sp-profile-menu a.sp-logout:hover { background: #fdecea; }
    @media (prefers-reduced-motion: reduce) {
        .sp-profile-menu, .sp-profile-caret { transition: none; }
    }
</style>
<script>
(function () {
    var COUNT = <?php echo (int) $spBellCount; ?>;
    var ACTIVE = <?php echo $spBellActive ? 'true' : 'false'; ?>;

    function init() {
        var header = document.querySelector('.top-navbar');
        if (!header || header.querySelector('.sp-bell')) return;

        // Ang profile sa itaas (link sa karamihan ng page, div sa edit_profile.php)
        var profile = header.querySelector('.user-profile-link') || header.querySelector('.user-profile');
        if (!profile) return;

        var label = COUNT > 0 ? 'Notifications, ' + COUNT + ' unread' : 'Notifications';
        var bell = document.createElement('a');
        bell.href = 'notifications.php';
        bell.className = 'sp-bell' + (ACTIVE ? ' active' : '');
        bell.setAttribute('aria-label', label);
        if (ACTIVE) bell.setAttribute('aria-current', 'page');
        bell.title = label;
        bell.innerHTML =
            '<svg viewBox="0 0 24 24" aria-hidden="true">' +
                '<path class="bell-body" d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>' +
                '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>' +
            '</svg>' +
            (COUNT > 0 ? '<span class="sp-bell-badge" aria-hidden="true">' + (COUNT > 9 ? '9+' : COUNT) + '</span>' : '');

        var divider = document.createElement('span');
        divider.className = 'sp-header-divider';
        divider.setAttribute('aria-hidden', 'true');

        // Pagsamahin ang bell at profile sa iisang grupo sa kanan
        var group = document.createElement('div');
        group.className = 'sp-header-right';
        profile.parentNode.insertBefore(group, profile);
        group.appendChild(bell);
        group.appendChild(divider);
        group.appendChild(profileMenu(profile));
    }

    // Pindutin ang profile: lalabas ang menu na may Account Settings at Logout
    function profileMenu(profile) {
        var wrap = document.createElement('div');
        wrap.className = 'sp-profile-wrap';
        wrap.appendChild(profile);

        var inner = profile.querySelector('.user-profile') || profile;
        var caret = document.createElement('i');
        caret.className = 'fa-solid fa-chevron-down sp-profile-caret';
        caret.setAttribute('aria-hidden', 'true');
        inner.appendChild(caret);

        var menu = document.createElement('div');
        menu.className = 'sp-profile-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML =
            '<a href="edit_profile.php" role="menuitem"><i class="fa-solid fa-user-gear"></i> Account Settings</a>' +
            '<div class="sp-menu-sep"></div>' +
            '<a href="logout.php" class="sp-logout" role="menuitem"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>';
        wrap.appendChild(menu);

        profile.setAttribute('aria-haspopup', 'menu');
        profile.setAttribute('aria-expanded', 'false');
        if (profile.tagName !== 'A') profile.setAttribute('tabindex', '0');

        function setOpen(open) {
            wrap.classList.toggle('open', open);
            profile.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        profile.addEventListener('click', function (e) {
            e.preventDefault();
            // Huwag hayaang buksan agad ng _profile_setup_modal.php ang Account Settings;
            // menu muna ang lalabas
            e.stopPropagation();
            setOpen(!wrap.classList.contains('open'));
        });
        // Account Settings: ang modal (kung nasa page), kung wala ay ang edit_profile.php
        menu.querySelector('a[href="edit_profile.php"]').addEventListener('click', function (e) {
            if (typeof window.spOpenProfile !== 'function') return;
            e.preventDefault();
            e.stopPropagation();
            setOpen(false);
            window.spOpenProfile();
        });
        profile.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setOpen(!wrap.classList.contains('open')); }
        });
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) setOpen(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
        });
        return wrap;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
