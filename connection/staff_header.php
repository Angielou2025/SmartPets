<?php
/**
 * Header ng mga Staff page - kapareho ng sa Adopter pero kulay asul:
 *   - bell sa kaliwa ng profile, may bilang (ang Reminders ng staff ang binubuksan)
 *   - profile: bilog na larawan na may asul na singsing, tuldok na "naka-login",
 *     at pangalan
 * Isinasama ng connection/chat_bubble.php (ang iisang include na nasa bawat
 * page), kaya isinisingit sa header gamit ang JavaScript.
 *
 * Kasama rin ang mga link sa kaliwa ng header (hal. Pet Inventory - inilipat
 * mula sa sidebar).
 */
$spStaffSelf = basename($_SERVER['PHP_SELF'] ?? '');
$spStaffBase = function_exists('sp_base_url') ? sp_base_url() : '/Caps2';
$spStaffNavLinks = [
    ['label' => 'Home', 'href' => $spStaffBase . '/StaffFile/staff_dashboard.php', 'active' => $spStaffSelf === 'staff_dashboard.php'],
    ['label' => 'Pet Inventory', 'href' => $spStaffBase . '/StaffFile/pet_inventory.php', 'active' => $spStaffSelf === 'pet_inventory.php'],
    ['label' => 'Certificates', 'href' => $spStaffBase . '/StaffFile/certificates.php', 'active' => $spStaffSelf === 'certificates.php'],
    ['label' => 'Availability', 'href' => $spStaffBase . '/StaffFile/staff_availability.php', 'active' => $spStaffSelf === 'staff_availability.php'],
    ['label' => 'Daily Schedule', 'href' => $spStaffBase . '/StaffFile/daily_schedule.php', 'active' => $spStaffSelf === 'daily_schedule.php'],
    ['label' => 'Medical & Intake', 'href' => $spStaffBase . '/StaffFile/medical_intake.php', 'active' => $spStaffSelf === 'medical_intake.php'],
];
?>
<style>
    /* Walang nakikitang scrollbar ng page sa kanan (nag-i-scroll pa rin gamit ang
       mouse wheel/touchpad). Dahil wala na ito, hindi na rin gumagalaw ang lapad ng
       page kapag humaba o umikli ang laman. */
    html { scrollbar-width: none; -ms-overflow-style: none; }
    html::-webkit-scrollbar { display: none; }

    /* Walang search bar sa header ng staff - bell at profile lang (gaya ng
       Certificates page). Ang search sa loob ng bawat page ay nandoon pa rin. */
    .staff-dashboard-root .top-header .search-top > * {
        display: none !important;
    }

    /* Walang puwang sa itaas: dumidikit ang puting header sa itaas at gilid ng
       page. Ang staff_dashboard.css ay may padding sa .main-wrapper kapag
       1200px pababa ang screen - inaalis ito sa itaas at binabawi sa gilid. */
    .staff-dashboard-root .top-header { margin-top: 0; }

    /* Nasa ibabaw ng laman ng page ang header, para ang menu ng profile
       (Account Settings, Logout) ay hindi natatakpan ng mga card sa ilalim.
       Mas mababa pa rin sa sidebar (1000) at sa mga modal (9999). */
    .staff-dashboard-root .top-header { position: relative; z-index: 950; }
    @media (max-width: 1200px) {
        .staff-dashboard-root .main-wrapper { padding-top: 0 !important; }
        .staff-dashboard-root .top-header {
            margin-left: -18px;
            margin-right: -18px;
            padding-left: 30px !important;
            padding-right: 30px !important;
        }
    }
    @media (max-width: 900px) {
        .staff-dashboard-root .top-header {
            margin-left: -14px;
            margin-right: -14px;
            padding-left: 18px !important;
            padding-right: 18px !important;
        }
    }

    /* ===== Mga link sa kaliwa ng header (gaya ng sa Adopter) ===== */
    .staff-dashboard-root .top-header .sp-staff-nav {
        display: flex;
        align-items: center;
        gap: 28px;
        margin-right: auto;
        padding-right: 18px;
    }
    .staff-dashboard-root .top-header .sp-staff-nav a {
        position: relative;
        padding: 6px 0;
        color: #1e2a5a;
        font-size: 16px !important;
        font-weight: 700;
        text-decoration: none;
        display: inline-block;
        white-space: nowrap;
        transition: color .2s ease;
    }
    /* Anim na link na: lumiliit nang kaunti ang pagitan/font sa makitid na screen para hindi bumaba sa dalawang linya */
    @media (max-width: 1240px) {
        .staff-dashboard-root .top-header .sp-staff-nav { gap: 18px; }
        .staff-dashboard-root .top-header .sp-staff-nav a { font-size: 14.5px !important; }
    }
    @media (max-width: 1120px) {
        .staff-dashboard-root .top-header .sp-staff-nav { gap: 13px; }
        .staff-dashboard-root .top-header .sp-staff-nav a { font-size: 13.5px !important; }
    }
    .staff-dashboard-root .top-header .sp-staff-nav a::after {
        content: "";
        position: absolute;
        left: 0;
        bottom: -2px;
        width: 0;
        height: 2.5px;
        border-radius: 2px;
        background: #1e3a8a;
        transition: width .3s ease;
    }
    .staff-dashboard-root .top-header .sp-staff-nav a:hover { color: #1e3a8a; }
    .staff-dashboard-root .top-header .sp-staff-nav a:hover::after,
    .staff-dashboard-root .top-header .sp-staff-nav a.active::after { width: 100%; }
    .staff-dashboard-root .top-header .sp-staff-nav a.active { color: #1e3a8a; }

    /* ===== Profile sa itaas (asul) ===== */
    .staff-dashboard-root .top-header .profile-section {
        position: relative;
        gap: 12px !important;
    }
    .staff-dashboard-root .top-header .profile-section > img,
    .staff-dashboard-root .top-header .profile-section > .avatar {
        width: 48px !important;
        height: 48px !important;
        border-radius: 50% !important;
        border: 3px solid transparent !important;
        box-sizing: border-box;
        box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25);
        flex-shrink: 0;
    }
    .staff-dashboard-root .top-header .profile-section > img {
        object-fit: cover;
        background:
            linear-gradient(#fff, #fff) padding-box,
            conic-gradient(from 200deg, #1e3a8a, #3b82f6, #93c5fd, #60a5fa, #1e3a8a) border-box;
        padding: 2px;
    }
    .staff-dashboard-root .top-header .profile-section > .avatar {
        background:
            linear-gradient(135deg, #1e3a8a, #2f5bc4) padding-box,
            conic-gradient(from 200deg, #1e3a8a, #3b82f6, #93c5fd, #60a5fa, #1e3a8a) border-box !important;
        font-size: 15px;
    }
    /* Tuldok: naka-login */
    .staff-dashboard-root .top-header .profile-section::before {
        content: "";
        position: absolute;
        left: 35px;
        top: 35px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #22c55e;
        border: 2.5px solid #fff;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
        z-index: 1;
    }
    .staff-dashboard-root .top-header .profile-section .profile-name {
        display: block !important;
        color: #1e2a5a !important;
        font-family: 'Georgia', 'Times New Roman', serif;
        font-size: 18px;
        font-weight: 700 !important;
        max-width: 170px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* ===== Bell + guhit + profile, magkakatabi sa kanan ===== */
    .sp-staff-right {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-left: auto;
    }
    .sp-staff-bell {
        position: relative;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        padding: 0;
        border: none;
        border-radius: 10px;
        background: none;
        color: #1e2a5a;
        cursor: pointer;
    }
    .sp-staff-bell:focus-visible { outline: 2px solid #1e3a8a; outline-offset: 2px; }
    .sp-staff-bell svg {
        width: 24px;
        height: 24px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.75;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .sp-staff-bell[aria-expanded="true"] svg .bell-body { fill: currentColor; }
    .sp-staff-icons { display: flex; align-items: center; gap: 6px; }
    .sp-staff-bell-badge {
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
        box-shadow: 0 0 0 2px #fff;
        box-sizing: border-box;
    }
    .sp-staff-bell-badge.is-warning { background: #f59c23; }
    .sp-staff-bell-badge[hidden] { display: none; }
    .sp-staff-divider {
        width: 1px;
        height: 30px;
        background: #e2e5e9;
        flex-shrink: 0;
    }

    /* Ang Reminders panel ay lalabas sa ilalim ng bell kapag doon binuksan */
    .sp-rem-panel.from-header {
        top: 74px !important;
        right: 24px !important;
        left: auto !important;
    }

    @media (max-width: 600px) {
        .sp-staff-right { gap: 12px; }
        .sp-staff-divider { display: none; }
        .staff-dashboard-root .top-header .profile-section .profile-name { display: none !important; }
    }

    /* ===== Menu ng profile (Account Settings, Logout) - gaya ng sa Adopter.
       Pinalitan nito ang Logout sa sidebar. ===== */
    .sp-staff-profile-wrap { position: relative; }
    .sp-staff-profile-wrap > .profile-section { cursor: pointer; }
    .sp-staff-caret {
        margin-left: -4px;
        font-size: 11px !important;
        color: #6b7280;
        transition: transform 0.2s ease;
    }
    .sp-staff-profile-wrap.open .sp-staff-caret { transform: rotate(180deg); }
    .sp-staff-menu {
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
    .sp-staff-profile-wrap.open .sp-staff-menu {
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
    }
    .sp-staff-menu a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 14px !important;
        font-weight: 600;
        color: #1f2933;
        text-decoration: none;
    }
    .sp-staff-menu a i { width: 16px; text-align: center; font-size: 14px !important; color: #6b7280; }
    .sp-staff-menu a:hover { background: #f3f6f4; }
    .sp-staff-menu .sp-staff-menu-sep { height: 1px; margin: 4px 6px; background: #eef0f2; }
    .sp-staff-menu a.sp-staff-logout,
    .sp-staff-menu a.sp-staff-logout i { color: #c5221f; }
    .sp-staff-menu a.sp-staff-logout:hover { background: #fdecea; }
    @media (prefers-reduced-motion: reduce) {
        .sp-staff-menu, .sp-staff-caret { transition: none; }
    }
</style>
<script>
(function () {
    // Font Awesome para sa mga icon ng menu (wala ito sa ilang staff page)
    if (!document.querySelector('link[href*="font-awesome"]')) {
        var fa = document.createElement('link');
        fa.rel = 'stylesheet';
        fa.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
        document.head.appendChild(fa);
    }

    function init() {
        var header = document.querySelector('.top-header');
        var profile = header && header.querySelector('.profile-section');
        if (!profile || header.querySelector('.sp-staff-bell')) {
            if (header) header.classList.add('sp-ready');
            return;
        }

        var remBtn = document.getElementById('spRemBtn');      // Reminders sa sidebar
        var remBadge = document.getElementById('spRemBadge');
        var remPanel = document.getElementById('spRemPanel');

        var bell = document.createElement('button');
        bell.type = 'button';
        bell.className = 'sp-staff-bell';
        bell.setAttribute('aria-label', 'Reminders');
        bell.setAttribute('aria-expanded', 'false');
        bell.title = 'Reminders';
        bell.innerHTML =
            '<svg viewBox="0 0 24 24" aria-hidden="true">' +
                '<path class="bell-body" d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>' +
                '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>' +
            '</svg>' +
            '<span class="sp-staff-bell-badge" hidden></span>';
        var badge = bell.querySelector('.sp-staff-bell-badge');

        var icons = document.createElement('div');
        icons.className = 'sp-staff-icons';
        icons.appendChild(bell);

        var divider = document.createElement('span');
        divider.className = 'sp-staff-divider';
        divider.setAttribute('aria-hidden', 'true');

        // Mga link sa kaliwa ng header (hal. Pet Inventory)
        var NAV_LINKS = <?php echo json_encode($spStaffNavLinks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        if (NAV_LINKS.length && !header.querySelector('.sp-staff-nav')) {
            var nav = document.createElement('nav');
            nav.className = 'sp-staff-nav';
            nav.setAttribute('aria-label', 'Quick links');
            NAV_LINKS.forEach(function (l) {
                var a = document.createElement('a');
                a.href = l.href;
                a.textContent = l.label;
                if (l.active) { a.className = 'active'; a.setAttribute('aria-current', 'page'); }
                nav.appendChild(a);
            });
            header.insertBefore(nav, header.firstChild);
        }

        var group = document.createElement('div');
        group.className = 'sp-staff-right';
        profile.parentNode.insertBefore(group, profile);
        group.appendChild(icons);
        group.appendChild(divider);
        group.appendChild(profileMenu(profile));
        // Ayos na ang header (links, bell, profile): saka ito ipakita nang dahan-dahan
        header.classList.add('sp-ready');

        // Kapareho ng bilang sa Reminders ng sidebar (nag-a-update ito nang kusa)
        function syncBadge() {
            if (!remBadge) return;
            var n = parseInt(remBadge.textContent, 10) || 0;
            badge.hidden = n <= 0 || remBadge.classList.contains('is-empty');
            badge.textContent = n > 9 ? '9+' : String(n);
            badge.classList.toggle('is-warning', remBadge.classList.contains('has-warning') && !remBadge.classList.contains('has-urgent'));
            var label = n > 0 ? 'Reminders, ' + n + ' to do' : 'Reminders';
            bell.setAttribute('aria-label', label);
            bell.title = label;
        }
        syncBadge();
        if (remBadge && window.MutationObserver) {
            new MutationObserver(syncBadge).observe(remBadge, { childList: true, characterData: true, subtree: true, attributes: true });
        }

        // Huwag ituring na "click sa labas" ng panel (para sumara kapag pinindot ulit)
        bell.addEventListener('mousedown', function (e) { e.stopPropagation(); });

        // Pindot sa bell = buksan/isara ang Reminders, sa ilalim ng bell
        bell.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!remBtn || !remPanel) return;
            remPanel.classList.add('from-header');
            remBtn.click();
        });
        // Kapag sa sidebar binuksan, sa dating puwesto ito lalabas
        if (remBtn) {
            remBtn.addEventListener('mousedown', function () { if (remPanel) remPanel.classList.remove('from-header'); });
        }
        if (remPanel && window.MutationObserver) {
            new MutationObserver(function () {
                var open = remPanel.getAttribute('aria-hidden') === 'false';
                bell.setAttribute('aria-expanded', open && remPanel.classList.contains('from-header') ? 'true' : 'false');
            }).observe(remPanel, { attributes: true, attributeFilter: ['aria-hidden', 'class'] });
        }
    }

    // Pindutin ang profile: lalabas ang menu na may Account Settings at Logout
    // (gaya ng connection/notif_bell.php ng Adopter)
    function profileMenu(profile) {
        var wrap = document.createElement('div');
        wrap.className = 'sp-staff-profile-wrap';
        wrap.appendChild(profile);

        var caret = document.createElement('i');
        caret.className = 'fa-solid fa-chevron-down sp-staff-caret';
        caret.setAttribute('aria-hidden', 'true');
        profile.appendChild(caret);

        var settingsHref = profile.getAttribute('href') || <?php echo json_encode($spStaffBase . '/StaffFile/edit_profile.php'); ?>;

        // Kapareho ng menu ng Adopter: Account Settings at Logout lang
        var menu = document.createElement('div');
        menu.className = 'sp-staff-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML =
            '<a role="menuitem" class="sp-staff-settings"><i class="fa-solid fa-user-gear"></i> Account Settings</a>' +
            '<div class="sp-staff-menu-sep"></div>' +
            '<a role="menuitem" class="sp-staff-logout" href="' + <?php echo json_encode($spStaffBase . '/Login_Page/logout.php'); ?> + '">' +
                '<i class="fa-solid fa-right-from-bracket"></i> Logout</a>';
        menu.querySelector('.sp-staff-settings').href = settingsHref;
        wrap.appendChild(menu);

        profile.setAttribute('aria-haspopup', 'menu');
        profile.setAttribute('aria-expanded', 'false');
        if (profile.tagName !== 'A') profile.setAttribute('tabindex', '0');

        function setOpen(open) {
            wrap.classList.toggle('open', open);
            profile.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        // Menu muna ang lalabas, hindi diretso sa Account Settings
        profile.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setOpen(!wrap.classList.contains('open'));
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
        // Account Settings: ang asul na modal na kapareho ng sa Adopter
        // (Adopter_File/_profile_setup_modal.php, isinasama ng chat_bubble.php).
        // Kung wala ang modal sa page, ang edit_profile.php ang bubuksan.
        menu.querySelector('.sp-staff-settings').addEventListener('click', function (e) {
            if (typeof window.spOpenProfile !== 'function') return;
            e.preventDefault();
            e.stopPropagation();
            setOpen(false);
            window.spOpenProfile();
        });
        // Logout: burahin ang naalalang menu sa sidebar (gaya ng dating Logout button)
        menu.querySelector('.sp-staff-logout').addEventListener('click', function () {
            try { sessionStorage.removeItem('staff_selected_menu'); } catch (err) {}
        });
        return wrap;
    }

    // Galing sa lumang link ng Account Settings (StaffFile/edit_profile.php):
    // buksan agad ang pop-up, saka alisin ang ?account=settings sa address bar
    function openFromLink() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('account') !== 'settings') return;
        params.delete('account');
        var qs = params.toString();
        history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
        if (typeof window.spOpenProfile === 'function') window.spOpenProfile();
    }

    // Nasa dulo ng page ang script na ito, kaya nandito na ang header: ayusin agad
    // (bago pa ang unang paint) para walang sandaling hindi ayos na itsura
    if (document.querySelector('.top-header .profile-section') || document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
    // Pagkatapos ma-load ang lahat (kasama ang script ng pop-up)
    if (document.readyState === 'complete') openFromLink();
    else window.addEventListener('load', openFromLink);
})();
</script>
