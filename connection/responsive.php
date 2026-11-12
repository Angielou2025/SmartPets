<?php
/**
 * Shared na responsive layer para sa Adopter, Staff, at Admin.
 *
 * Isinasama ito ng chat_bubble.php, na nasa bawat naka-login na page, kaya
 * kusang gumagana sa lahat nang hindi ginagalaw ang 37 na page.
 *
 * Sa telepono at tablet (900px pababa):
 *   - Nagiging off-canvas drawer ang sidebar ng tatlong role, may hamburger
 *     na button sa itaas at dilim sa likod kapag bukas.
 *   - Buong lapad ang main content, walang nakalaang puwang sa kaliwa.
 *   - Kusang nag-i-scroll pahalang ang mga table imbes na sumabog sa gilid.
 *   - Nagiging isang column ang mga grid, at kasya sa screen ang mga modal.
 *
 * Sa maliit na laptop at PROJECTOR (901-1366px ang lapad, o 820px pababa ang
 * taas - hal. 1024x768, 1280x720, 1366x768):
 *   - Mas makitid ang sidebar para mas maluwag ang laman.
 *   - Lumiliit o nawawala ang dekorasyong larawan sa sidebar kapag mababa ang
 *     screen, para hindi matakpan ang Logout.
 *   - Kusang bumababa sa susunod na linya ang mga stat card imbes na maipit.
 *
 * Sa LAHAT ng laki: mas madilim nang kaunti ang mapusyaw na maliliit na text,
 * dahil halos nawawala ang light gray sa projector.
 */
$rsRole = $_SESSION['role'] ?? '';
?>
<style>
    /* Laging naka-off ang mobile bar at backdrop sa malalaking screen */
    #spMobileBar, #spNavBackdrop { display: none; }
    /* Balot ng laman ng table cell para sa card tables - walang epekto sa
       layout sa desktop, parang wala lang ito */
    .sp-cell-val { display: contents; }

    /* ---- Linaw sa projector (lahat ng laki): ang mapusyaw na gray na
            maliliit na text ay halos nawawala kapag naka-project, kaya
            pinadidilim nang kaunti at hindi pinapayagang sobrang liit ---- */
    body { text-rendering: optimizeLegibility; }
    .main-wrapper .panel-caption,
    .main-wrapper .page-subtitle,
    .main-wrapper .sub-text,
    .main-wrapper .small,
    .main-wrapper .activity-role,
    .main-wrapper .empty-note,
    .main-content .page-subtitle,
    .main-content .sub-text,
    .main-content .small {
        color: #56635c !important;
    }
    .main-wrapper .panel-caption,
    .main-wrapper .small,
    .main-wrapper .activity-role,
    .main-content .small {
        font-size: max(12px, 1em);
    }

    /* ---- Maliit na laptop at projector (901-1366px ang lapad) ---- */
    @media (min-width: 901px) and (max-width: 1366px) {
        /* Mas makitid na sidebar = mas maluwag ang laman */
        .sidebar { width: 210px !important; min-width: 210px !important; }
        body.staff-dashboard-root .sidebar { width: 200px !important; min-width: 200px !important; }
        body.staff-dashboard-root .main-wrapper { margin-left: 200px !important; }

        .main-wrapper .dashboard-content { padding: 22px !important; gap: 20px !important; }
        .main-wrapper .content-container { padding: 22px !important; }
        .main-content { --mc-pad: 22px; padding-left: 22px !important; padding-right: 22px !important; }

        /* Kusang bumababa ang stat cards kapag hindi na kasya, hindi naiipit */
        .cards-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) !important; gap: 14px !important; }
        .stat-info h1 { font-size: 30px !important; }
        .stat-card { padding: 16px !important; }

        /* Mga nakapatayong tab sa Reports: mas makitid na hanay ng mga box */
        .report-tabs-panel { grid-template-columns: 220px minmax(0, 1fr) !important; }
        body.staff-dashboard-root .staff-tabs-vertical { grid-template-columns: 230px minmax(0, 1fr) !important; }

        /* Malalapad na table: nag-i-scroll pahalang sa loob ng lalagyan nito */
        .table-container, .table-wrapper, .payment-table-wrap { overflow-x: auto; }
    }

    /* ---- Mababang screen (projector na 720-800px ang taas) ---- */
    @media (min-width: 901px) and (max-height: 820px) {
        .city-illustration img { max-height: 110px !important; }
        /* Adopter sidebar: pinupuno pa rin ang bakanteng espasyo; nakatutok sa aso at
           pusa kapag maliit ang espasyo (hindi lang sa damo sa ibaba) */
        .sidebar-illustration img { object-position: center 80% !important; }
        .sidebar-menu .menu-item, .sidebar .nav-item {
            padding-top: 9px !important;
            padding-bottom: 9px !important;
        }
        .main-wrapper .top-header { min-height: 56px; }
    }
    @media (min-width: 901px) and (max-height: 700px) {
        /* Sobrang baba na - unahin ang menu kaysa sa dekorasyon */
        .city-illustration { display: none !important; }
    }
    @media (min-width: 901px) and (max-height: 560px) {
        .sidebar-illustration { display: none !important; }
    }

    @media (max-width: 900px) {
        /* clip (hindi hidden): pareho ang hindi pag-scroll pakanan, pero hindi nito
           sinisira ang position: sticky ng hanay ng mga link sa ibaba */
        html, body { overflow-x: hidden; overflow-x: clip; max-width: 100%; }
        /* Walang kusang paggalaw ng scroll kapag lumiit ang sticky (iwas pagkislap) -
           tingnan ang spSetStuck sa ibaba */
        html { overflow-anchor: none; }
        /* Habang sinusukat ng spSetStuck: walang animation para tama ang sukat */
        .sp-measure, .sp-measure * { transition: none !important; animation: none !important; }

        /* ---- Mobile top bar na may hamburger ---- */
        #spMobileBar {
            display: flex;
            align-items: center;
            gap: 10px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 54px;
            padding: 0 12px;
            background: #ffffff;
            border-bottom: 1px solid #e3e7e4;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            z-index: 9990;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        #spMobileBar .sp-burger {
            width: 40px;
            height: 40px;
            border: none;
            background: none;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1b4d2e;
            -webkit-tap-highlight-color: transparent;
        }
        #spMobileBar .sp-burger:active { background: #eaf3ee; }
        #spMobileBar .sp-burger svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
        #spMobileBar .sp-brand { font-weight: 800; font-size: 17px; letter-spacing: 0.3px; }
        #spMobileBar .sp-brand .a { color: #ff8c00; }
        #spMobileBar .sp-brand .b { color: #1b4d2e; }
        #spMobileBar .sp-role {
            margin-left: auto;
            font-size: 11px;
            font-weight: 700;
            color: #6b7a72;
            background: #f1f4f2;
            border-radius: 999px;
            padding: 4px 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        /* ---- Off-canvas sidebar. Ang !important ay para talunin ang mga
                lumang mobile rule ng bawat role na nagpapatong ng sidebar sa
                itaas ng content. ---- */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            bottom: 0 !important;
            height: 100vh !important;
            height: 100dvh !important;
            width: min(280px, 84vw) !important;
            max-width: 84vw !important;
            margin: 0 !important;
            padding-top: 18px !important;
            border-radius: 0 !important;
            border-right: none !important;
            border-bottom: none !important;
            overflow-y: auto !important;
            z-index: 10001 !important;
            transform: translateX(-105%);
            transition: transform 0.28s ease;
            box-shadow: 6px 0 24px rgba(0, 0, 0, 0.18);
        }
        body.sp-nav-open .sidebar { transform: none; }
        .sidebar-illustration { display: none !important; }

        #spNavBackdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s;
        }
        body.sp-nav-open #spNavBackdrop { opacity: 1; visibility: visible; }
        body.sp-nav-open { overflow: hidden; }

        /* ---- Mga container: bumalik sa normal na daloy ng dokumento, para
                ang buong page ang nag-i-scroll at hindi isang kahon ---- */
        .dashboard-container, .app-container, .layout {
            display: block !important;
            height: auto !important;
            min-height: 100vh;
            overflow: visible !important;
        }
        .main-content, .main-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            height: auto !important;
            min-height: 100vh;
            border-radius: 0 !important;
            overflow: visible !important;
            padding-top: 66px !important;
            padding-left: 14px !important;
            padding-right: 14px !important;
            box-sizing: border-box;
        }

        /* ---- Mga table: pahalang na scroll sa loob, hindi sumasabog ---- */
        .main-content table, .main-wrapper table {
            display: block;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* ---- Larawan, form, at modal ---- */
        .main-content img, .main-wrapper img { max-width: 100%; }
        input, select, textarea { max-width: 100%; }
        .top-navbar, .top-header { flex-wrap: wrap; gap: 10px; }
        .modal-card, .modal-content, .modal-box, .modal-dialog {
            width: auto !important;
            max-width: calc(100vw - 24px) !important;
            max-height: calc(100vh - 24px);
            overflow-y: auto;
        }

        /* Ang toggle ng chat ay bahagyang itinataas para hindi tumapat sa mga
           mobile browser bar */
        #spChatToggle { bottom: 20px !important; }
    }

    @media (max-width: 900px) {
        /* Ang laman ng grid ay default na hindi lumiliit nang lampas sa sarili
           nitong lapad (min-width: auto), kaya sumasabog sa gilid ng screen.
           Pinapayagan itong lumiit para kasya sa telepono. */
        .main-content [class*="grid"] > *, .main-wrapper [class*="grid"] > * { min-width: 0; }
    }

    /* ---- Card tables: sa telepono, bawat row ng table ay nagiging card na
            may label sa bawat field (galing sa header ng table), para kita
            agad ang status at mga button nang hindi nag-i-scroll pahalang.
            Ilagay ang class na "no-cards" sa table na ayaw gawing card. ---- */
    @media (max-width: 640px) {
        table.sp-card-table {
            display: block !important;
            width: 100% !important;
            min-width: 0 !important;
            overflow: visible !important;
            border: none !important;
        }
        table.sp-card-table thead { display: none !important; }
        table.sp-card-table tbody { display: block !important; width: 100% !important; }
        /* Ang tbody na naka-hidden (hal. ibang page ng pagination sa Payments) ay
           dapat manatiling nakatago - mas tiyak ang selector na ito kaysa sa nasa itaas */
        table.sp-card-table tbody[hidden] { display: none !important; }
        /* Walang !important ang display ng row: kailangang manalo pa rin ang
           style="display:none" ng mga search filter at ang [hidden] ng "Show more" */
        table.sp-card-table tr {
            display: block;
            width: 100% !important;
            box-sizing: border-box;
            /* Transparent para sumunod sa kulay ng panel - may madidilim na table */
            background: transparent;
            border: 1px solid rgba(128, 140, 134, 0.28) !important;
            border-radius: 12px;
            padding: 8px 14px !important;
            margin-bottom: 10px;
        }
        table.sp-card-table tr[hidden] { display: none !important; }

        /* ===== Staff: pakaliwa/pakanan (swipe) ang mga card imbes na pababa =====
           Ang .sp-swipe ay idinadagdag ng script sa ibaba (table na may 2+ card).
           Ang tbody ang nag-i-scroll nang pahalang; humihinto sa gitna ang bawat card. */
        .staff-dashboard-root table.sp-card-table.sp-swipe > tbody {
            display: flex !important;
            gap: 12px;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            padding: 2px 2px 10px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .staff-dashboard-root table.sp-card-table.sp-swipe > tbody::-webkit-scrollbar { display: none; }
        .staff-dashboard-root table.sp-card-table.sp-swipe > tbody > tr {
            flex: 0 0 86%;
            width: 86% !important;      /* sumisilip ang susunod na card sa kanan */
            margin: 0 !important;
            scroll-snap-align: center;
        }
        /* Row na walang laman ("No records…"): buong lapad pa rin */
        .staff-dashboard-root table.sp-card-table.sp-swipe > tbody > tr.sp-swipe-full { flex-basis: 100%; width: 100% !important; }
        /* Ang lalagyan ng table (may sariling border/box): wala nang kahon sa paligid ng mga card */
        /* (idinadagdag ng script ang .sp-swipe-wrap sa mga lalagyan sa pagitan ng panel at table) */
        .staff-dashboard-root .sp-swipe-wrap {
            border: none !important; box-shadow: none !important; border-radius: 0 !important; overflow: visible !important;
        }
        .sp-swipe-count {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            margin: 4px 0 2px; font-size: 12px; font-weight: 700; color: #6b7280;
        }
        .sp-swipe-count[hidden] { display: none; }
        .sp-swipe-count b { color: #1e3a8a; }
        .sp-swipe-count .sp-swipe-hint { font-weight: 600; color: #9aa3ad; }

        /* ===== Staff: mga pop-up (.modal-backdrop > .modal-card) - buong screen sa telepono =====
           Header (pamagat + ×) sa itaas, nag-i-scroll ang laman, mga button sa ibaba.
           (Dati: width: auto mula sa itaas, kaya sumisikip ang pop-up sa lapad ng laman) */
        body.staff-dashboard-root .modal-backdrop { padding: 0 !important; z-index: 10020 !important; align-items: stretch !important; }
        body.staff-dashboard-root .modal-backdrop > .modal-card {
            position: fixed !important; inset: 0 !important;
            width: 100% !important; max-width: none !important;
            height: 100% !important; max-height: none !important;
            margin: 0 !important; padding: 0 !important; border-radius: 0 !important;
            display: flex !important; flex-direction: column; overflow: hidden !important;
        }
        body.staff-dashboard-root .modal-backdrop > .modal-card > form { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
        body.staff-dashboard-root .modal-backdrop .modal-header { flex: 0 0 auto; padding: 12px 14px !important; margin: 0 !important; border-bottom: 1px solid #eef0f3; }
        body.staff-dashboard-root .modal-backdrop .modal-title { font-size: 16px !important; }
        body.staff-dashboard-root .modal-backdrop .modal-header .btn[aria-label="Close"] {
            width: 36px !important; height: 36px !important; padding: 0 !important; border-radius: 50% !important; font-size: 15px !important;
        }
        body.staff-dashboard-root .modal-backdrop .modal-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 14px !important; }
        body.staff-dashboard-root .modal-backdrop .modal-body .form-grid-2 { grid-template-columns: 1fr !important; gap: 12px !important; }
        body.staff-dashboard-root .modal-backdrop .modal-body label { font-size: 12.5px !important; }
        body.staff-dashboard-root .modal-backdrop .modal-body :is(input:not([type="checkbox"]):not([type="radio"]):not([type="file"]), select, textarea) {
            width: 100% !important; box-sizing: border-box; font-size: 16px !important;   /* 16px: walang auto-zoom sa iPhone */
        }
        body.staff-dashboard-root .modal-backdrop .modal-actions {
            flex: 0 0 auto; display: flex; gap: 8px; padding: 10px 14px calc(10px + env(safe-area-inset-bottom)) !important;
            margin: 0 !important; border-top: 1px solid #eef0f3; background: #fff;
        }
        body.staff-dashboard-root .modal-backdrop .modal-actions > * { flex: 1 1 0; justify-content: center; height: 42px; }

        /* ===== Staff: puting panel na buong lapad (idinadagdag ng script ang .sp-bleed) ===== */
        .sp-bleed {
            border-radius: 0 !important;
            border-left: none !important;
            border-right: none !important;
            box-shadow: none !important;
        }
        /* ===== Staff: sticky na stat cards (buong lapad, puting likod) =====
           Idinadagdag ng script ang .sp-sticky-cards (at ang margin/padding para buong lapad).
           Kapag nakadikit na (.is-stuck): iisang hanay, numero at maikling label lang. */
        .sp-sticky-cards {
            background: #ffffff;
            padding-top: 8px !important;
            padding-bottom: 8px !important;
        }
        /* Ang compact na kopya: nakapirmi sa ilalim ng mga link, lumalabas kapag na-scroll
           na palayo ang totoong cards (hindi nagbabago ang taas ng page = walang puwang/kislap) */
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.sp-cards-float {
            position: fixed !important;
            top: var(--sp-stick-top, 0px) !important;
            left: 0 !important;
            right: 0 !important;
            width: auto !important;
            margin: 0 !important;
            z-index: 9970;
            visibility: hidden;
            opacity: 0;
            transform: translateY(-6px);
            transition: opacity .15s ease, transform .15s ease, visibility 0s linear .15s;
        }
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.sp-cards-float.is-shown {
            visibility: visible;
            opacity: 1;
            transform: none;
            transition: opacity .15s ease, transform .15s ease, visibility 0s;
        }
        @media (prefers-reduced-motion: reduce) {
            body.staff-dashboard-root .dashboard-content .sp-cards-float { transition: none !important; }
        }
        /* (body.staff-dashboard-root .dashboard-content sa unahan: mas malakas kaysa sa sariling
           CSS ng bawat page, hal. ".staff-dashboard-root .cards-grid.inv-cards { display: grid }") */
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.is-stuck {
            display: flex !important;
            gap: 6px !important;
            padding-top: 6px !important;
            padding-bottom: 6px !important;
            box-shadow: 0 8px 12px -10px rgba(0, 0, 0, 0.3);
        }
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.is-stuck > * {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            min-height: 0 !important;
            height: auto !important;
            padding: 6px 8px !important;
            border-radius: 10px !important;
            grid-column: auto !important;
        }
        /* Icon (anuman ang class, hal. .stat-icon o .pay-icon): itago - numero at label lang */
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.is-stuck > * > :not(.stat-info) { display: none !important; }
        /* Apat na card (hal. Payments na may ₱5,500.00): mas maliit na numero para kasya */
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.is-stuck:has(> :nth-child(4)) .stat-info h1 { font-size: 13.5px !important; }
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.is-stuck > * > .stat-info { min-width: 0; overflow: hidden; }
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.is-stuck .stat-info h1 { font-size: 17px !important; line-height: 1.1 !important; margin: 0 !important; }
        body.staff-dashboard-root .dashboard-content .sp-sticky-cards.is-stuck .stat-info p {
            font-size: 9.5px !important; line-height: 1.2 !important; margin: 2px 0 0 !important;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        /* Filter na may "All" + 3 iba pa (hal. Pet Inventory: Available / Adopted / Unavailable,
           Payments: Paid / Pending / Unpaid): "All" sa sariling hanay sa itaas, at ang tatlo
           ay magkakasinlapad sa ilalim. (:nth-child(4):last-child = eksaktong 4 na button) */
        .staff-dashboard-root [role="group"]:has(> [data-filter="all"]):has(> :nth-child(4):last-child) {
            display: flex !important; flex-wrap: wrap !important; gap: 4px !important;
        }
        .staff-dashboard-root [role="group"]:has(> [data-filter="all"]):has(> :nth-child(4):last-child) > * {
            flex: 1 1 0 !important; min-width: 0 !important; justify-content: center !important; text-align: center;
        }
        .staff-dashboard-root [role="group"]:has(> :nth-child(4):last-child) > [data-filter="all"] {
            flex: 0 0 100% !important;
        }
        /* Mas maliit na puwang sa itaas ng panel (dati ~80px: 18px sa ilalim ng mga card +
           20px na margin + 22px na padding + guhit) */
        .sp-bleed { margin-top: 0 !important; padding-top: 14px !important; border-top: none !important; }
        .dashboard-content > :has(+ .sp-bleed) { margin-bottom: 6px !important; padding-bottom: 0 !important; }
        /* Huling panel: walang sobrang espasyo at guhit sa ilalim (hanggang dulo ng screen) */
        .sp-bleed:last-child { margin-bottom: 0 !important; padding-bottom: 12px !important; border-bottom: none !important; }
        body.staff-dashboard-root .dashboard-content { padding-bottom: 0 !important; }
        body.staff-dashboard-root .main-wrapper { padding-bottom: 0 !important; }
        .sp-swipe-count { margin-bottom: 0; }
        /* Walang brown/beige na background kapag nag-scroll hanggang dulo */
        html:has(body.staff-dashboard-root),
        body.staff-dashboard-root,
        body.staff-dashboard-root .main-wrapper { background: #ffffff !important; }
        table.sp-card-table tr:hover td { background: transparent !important; }
        table.sp-card-table td {
            display: flex !important;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100% !important;
            box-sizing: border-box;
            padding: 7px 0 !important;
            border: none !important;
            border-bottom: 1px dashed rgba(128, 140, 134, 0.22) !important;
            text-align: right !important;
            white-space: normal !important;
            min-height: 0 !important;
        }
        table.sp-card-table td:last-child { border-bottom: none !important; }
        /* Mahabang email o pangalan: bumababa sa susunod na linya, hindi lumalampas sa card */
        table.sp-card-table td { flex-wrap: wrap; row-gap: 2px; }
        table.sp-card-table td, table.sp-card-table td * { overflow-wrap: anywhere; }
        table.sp-card-table td > * { min-width: 0; margin-left: auto; }
        table.sp-card-table td.sp-card-head > * { margin-left: 0; }
        table.sp-card-table td::before {
            content: attr(data-label);
            flex-shrink: 0;
            max-width: 42%;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            color: #8a968f;
        }
        table.sp-card-table td[data-label=""]::before { display: none; }
        /* Unang column = pamagat ng card; walang label, buong lapad */
        table.sp-card-table td.sp-card-head { justify-content: flex-start; text-align: left !important; font-size: 15px; }
        table.sp-card-table td.sp-card-head::before { display: none; }
        /* Row na isang cell lang (hal. "No records yet") */
        table.sp-card-table td[colspan] { display: block !important; text-align: center !important; }
        table.sp-card-table td[colspan]::before { display: none; }
        /* Cell na maraming laman (hal. pangalan + email) - nakasalansan pakanan */
        table.sp-card-table td > .sp-cell-val { display: block; text-align: right; min-width: 0; }
        table.sp-card-table td.sp-card-head > .sp-cell-val { text-align: left; }
        /* Mga button sa loob ng cell - kanan, puwedeng bumaba sa susunod na linya */
        table.sp-card-table td > * { max-width: 100%; }
        table.sp-card-table td .action-stack,
        table.sp-card-table td .action-buttons,
        table.sp-card-table td .actions { justify-content: flex-end; flex-wrap: wrap; }
    }

    @media (max-width: 640px) {
        /* Isang column na ang lahat ng grid sa telepono - MALIBAN sa calendar,
           na dapat manatiling pitong araw tulad ng sa desktop */
        .main-content [class*="grid"]:not(.cal-grid):not([class*="cal-"]):not(.cards-grid):not(.summary-grid):not(.pets-grid),
        .main-wrapper [class*="grid"]:not(.cal-grid):not([class*="cal-"]):not(.cards-grid):not(.summary-grid):not(.pets-grid) {
            grid-template-columns: 1fr !important;
        }
        .main-content, .main-wrapper { --mc-pad: 12px; padding-left: 12px !important; padding-right: 12px !important; }

        /* Admin pages: may sariling 30px na padding ang container at 25px ang
           card, dagdag pa sa padding ng page - masyadong kumikitid sa telepono */
        .main-wrapper .content-container { padding: 14px 0 !important; }
        .main-wrapper .card-panel { padding: 16px !important; }

        /* Mga search box na may nakapirming lapad (hal. 320px) */
        .main-content [class*="search"], .main-wrapper [class*="search"] { max-width: 100% !important; box-sizing: border-box; }
        h1 { font-size: 24px; }
        h2 { font-size: 20px; }
    }

    /* ==== Header sa telepono: iisang bar lang sa itaas ====
       Ang bell at profile ng Adopter at Staff ay ipinapatong sa kanan ng mobile
       bar (gaya ng sa Admin), kaya hindi na dalawang linya ang header. Ang mga
       link (Search Pets, Location, Certificate...) ay nagiging manipis na hanay
       sa ilalim na puwedeng i-swipe kapag hindi kasya. */
    @media (max-width: 900px) {
        /* (Inililipat ng script sa ibaba ang .sp-header-right / .sp-staff-right
           sa loob ng #spMobileBar) */
        #spMobileBar .sp-header-right,
        #spMobileBar .sp-staff-right {
            position: static !important;
            margin: 0 0 0 auto !important;
            gap: 4px !important;
            display: flex;
            align-items: center;
        }
        #spMobileBar .sp-header-divider, #spMobileBar .sp-staff-divider { display: none !important; }
        /* Habang hindi pa nailiipat sa mobile bar, huwag ipakita ang profile/bell sa
           hanay ng mga link - kung hindi, makikita itong nasa ibaba saglit bago umakyat */
        /* (Staff lang - .staff-dashboard-root; ang Admin ay hindi inililipat ang profile) */
        .top-navbar .user-profile-link,
        .top-navbar .user-profile,
        .top-navbar .sp-header-right,
        .staff-dashboard-root:not(.sp-admin-view) .top-header .profile-section,
        .staff-dashboard-root:not(.sp-admin-view) .top-header .sp-staff-right { display: none !important; }
        /* Avatar na lang (walang pangalan) para kasya sa bar */
        #spMobileBar .user-profile > span,
        #spMobileBar .profile-name { display: none !important; }
        #spMobileBar .profile-avatar,
        #spMobileBar .profile-section > img,
        #spMobileBar .profile-section > .avatar {
            width: 38px !important;
            height: 38px !important;
            border-width: 2px !important;
        }
        #spMobileBar .user-profile, #spMobileBar .profile-section { gap: 4px !important; }
        #spMobileBar .sp-profile-caret { margin-left: 2px; }
        #spMobileBar a { text-decoration: none; color: inherit; }
        /* Staff: ang header strip ay nasa ilalim ng mobile bar, hindi natatakpan */
        body.staff-dashboard-root .main-wrapper { padding-top: 62px !important; }
        /* Hindi lumalabas sa screen ang menu ng profile */
        .sp-profile-menu, .sp-staff-menu { right: 0 !important; max-width: calc(100vw - 24px); }

        /* Manipis na hanay ng mga link */
        /* Sticky: kapag nag-scroll pababa, dumidikit ang hanay ng mga link sa ilalim
           ng mobile bar (54px ang taas nito) at hindi nawawala sa screen */
        .top-navbar, .staff-dashboard-root .top-header {
            position: sticky !important;
            top: 54px !important;
            z-index: 9980 !important;
            flex-wrap: nowrap !important;
            min-height: 0 !important;
            height: auto !important;
            margin-top: -4px !important;
            margin-bottom: 14px !important;
            padding-top: 8px !important;
            padding-bottom: 8px !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: rgba(255, 255, 255, 0.96) !important;
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
            transition: box-shadow .2s ease;
        }
        /* May manipis na anino kapag nakadikit na (idinadagdag ng script sa ibaba) */
        .top-navbar.sp-stuck, .staff-dashboard-root .top-header.sp-stuck {
            box-shadow: 0 6px 12px -8px rgba(0, 0, 0, 0.18) !important;
        }
        .top-nav-links, .staff-dashboard-root .top-header .sp-staff-nav {
            display: flex;
            gap: 6px !important;
            width: 100%;
            margin: 0 !important;
            overflow-x: auto;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .top-nav-links::-webkit-scrollbar, .sp-staff-nav::-webkit-scrollbar { display: none; }
        .top-nav-links a, .staff-dashboard-root .top-header .sp-staff-nav a {
            flex-shrink: 0;
            white-space: nowrap;
            font-size: 13.5px !important;
            font-weight: 600;
            padding: 7px 14px !important;
            border-radius: 999px;
            background: #f1f4f2;
            border: 1px solid #e3e8e5;
        }
        .top-nav-links a::after, .staff-dashboard-root .top-header .sp-staff-nav a::after { display: none !important; }
        .top-nav-links a.active, .top-nav-links a[aria-current="page"] { background: #2e5b32; border-color: #2e5b32; color: #fff !important; }
        .staff-dashboard-root .top-header .sp-staff-nav a { background: #eef2fb; border-color: #dbe3f5; }
        .staff-dashboard-root .top-header .sp-staff-nav a.active { background: #1e3a8a; border-color: #1e3a8a; color: #fff !important; }

        /* Mas maliit na tagline sa itaas ng Adopter pages */
        .hero-title p { font-size: 15px !important; line-height: 1.45; margin-bottom: 14px !important; }

        /* AI bubble: mas maliit, at may puwang sa dulo ng page para hindi
           matakpan ang huling button */
        #spChatToggle { width: 48px !important; height: 58px !important; right: 12px !important; bottom: 14px !important; }
        .main-content, .main-wrapper { padding-bottom: 84px !important; }

        /* Messages: buong lapad, walang kanto/anino; ang script ang nag-aayos ng
           margin at taas (dikit sa ilalim ng mga link hanggang sa ibaba ng screen) */
        body.sp-chat-fill .sp-chat-shell {
            margin: 0 !important;
            width: auto !important;
            max-width: none !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            animation: none !important;
            background: #fff !important;
        }
        body.sp-chat-fill .main-content,
        body.sp-chat-fill .main-wrapper,
        body.sp-chat-fill .dashboard-content { padding-bottom: 0 !important; margin-bottom: 0 !important; }
        body.sp-chat-fill .top-navbar,
        body.sp-chat-fill .main-wrapper > .top-header { box-shadow: 0 1px 0 #eef0f3 !important; }
    }

    /* ==== Mga button sa telepono (Adopter, Staff, Admin) ====
       Mas maliit at pare-pareho ang laki ng text button: 13.5px na text at mas
       manipis na taas. Hindi ginagalaw ang pahalang na padding at ang mga
       bilog na icon button (may sariling lapad at taas). */
    @media (max-width: 640px) {
        :is(.main-content, .main-wrapper, [role="dialog"], .modal, .modal-content, .modal-card)
            :is(a, button, input[type="submit"], input[type="button"])[class*="btn"]:not([class*="icon"]):not([class*="close"]):not([class*="exit"]):not([class*="toggle"]):not(.fav-btn):not([class*="burger"]):not([class*="bell"]):not(.pets-grid .card-actions > *):not(.app-list .btn-pay-small):not(.rid-btn):not(.rid-example-btn):not(.btn-review),
        :is(.main-content, .main-wrapper) :is(.btn-orange, .btn-text, .btn-save, .btn-next, .btn-back):not(.pets-grid .card-actions > *) {
            /* (hindi kasama ang maliliit na pet card - may sariling sukat sa dashboard.css) */
            font-size: 13.5px !important;
            line-height: 1.25 !important;
            padding-top: 9px !important;
            padding-bottom: 9px !important;
            border-radius: 10px;
        }
        /* Mas magaan na anino sa telepono */
        :is(.main-content, .main-wrapper) [class*="btn"]:not(.fav-btn) { box-shadow: none; }
    }

    /* Export menu (Activity Logs, Manage Staff): nakagitna sa ilalim ng button
       sa telepono para hindi lumampas sa kaliwang gilid ng screen */
    @media (max-width: 640px) {
        .al-export-menu, .ms-export-menu {
            left: 50% !important;
            right: auto !important;
            margin-left: -125px;
            max-width: calc(100vw - 24px);
        }
    }

    /* Mga tab bar (Certificates, Review Application): sa telepono, magkakasinlapad
       ang mga tab at kasya lahat sa iisang hanay (walang naputol / walang i-swipe).
       Bumababa sa 2 linya ang mahabang pangalan sa loob ng sariling tab. */
    @media (max-width: 640px) {
        .cert-tabs[role="tablist"], .rv-tabs[role="tablist"] {
            display: flex !important;
            flex-wrap: nowrap !important;
            width: 100% !important;
            max-width: 100% !important;
            height: auto !important;
            overflow: hidden !important;
        }
        .cert-tabs[role="tablist"] > [role="tab"], .rv-tabs[role="tablist"] > [role="tab"] {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            white-space: normal !important;
            min-height: 44px;
            padding-left: 4px !important;
            padding-right: 4px !important;
        }
        .cert-tabs[role="tablist"] > [role="tab"] *, .rv-tabs[role="tablist"] > [role="tab"] * { white-space: normal !important; }
    }

    /* Stat cards: dalawa bawat hanay sa telepono (hindi isa-isang buong lapad) */
    @media (max-width: 640px) and (min-width: 341px) {
        .main-wrapper .cards-grid, .main-content .cards-grid,
        .main-wrapper .summary-grid, .main-wrapper .mp-cards, .main-wrapper .al-cards {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
        }
        .main-wrapper .cards-grid > *, .main-content .cards-grid > *,
        .main-wrapper .summary-grid > *, .main-wrapper .mp-cards > *, .main-wrapper .al-cards > * {
            min-width: 0 !important;
            padding: 14px !important;
        }
        /* Kasyang numero at icon sa makitid na card (hal. ₱4,500.00) */
        .main-wrapper :is(.cards-grid, .summary-grid, .mp-cards, .al-cards) > * :is(h1, h2),
        .main-content .cards-grid > * :is(h1, h2) {
            font-size: 21px !important;
            line-height: 1.15 !important;
            letter-spacing: -0.3px;
            white-space: nowrap;
        }
        .main-wrapper :is(.cards-grid, .summary-grid, .mp-cards, .al-cards) > * :is(p, span, small) {
            font-size: 12.5px !important;
            line-height: 1.3 !important;
        }
        .main-wrapper :is(.cards-grid, .summary-grid, .mp-cards, .al-cards) :is(.stat-icon, .summary-icon, .al-card-icon, .sp-icon, [class*="card-icon"]) {
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            font-size: 14px !important;
            border-radius: 10px !important;
            flex-shrink: 0;
        }
        .main-wrapper :is(.cards-grid, .summary-grid, .mp-cards, .al-cards) :is(.stat-icon, .summary-icon, .al-card-icon, .sp-icon, [class*="card-icon"]) svg {
            width: 17px !important;
            height: 17px !important;
        }
        /* Pare-pareho ang hanay: ang huling card na walang kapares ay buong lapad */
        .main-wrapper :is(.cards-grid, .summary-grid, .mp-cards, .al-cards) > :last-child:nth-child(odd) { grid-column: 1 / -1; }
    }

    /* ---- Calendar: parehong itsura sa telepono, pinaliit lang para kasya.
            Pitong column pa rin, may kulay pa rin ang mga event pill. ---- */
    @media (max-width: 900px) {
        .cal-grid {
            display: grid !important;
            grid-template-columns: repeat(7, minmax(0, 1fr)) !important;
            gap: 4px !important;
        }
        .cal-day {
            min-height: 66px !important;
            padding: 5px 4px !important;
            border-radius: 9px !important;
            gap: 3px !important;
            min-width: 0;
        }
        .cal-dow { font-size: 10px !important; padding-bottom: 5px !important; letter-spacing: 0 !important; }
        .cal-daynum { font-size: 11px !important; width: 20px !important; height: 20px !important; }
        /* Ipakita pa rin ang event pills tulad sa desktop, hindi tuldok lang */
        .cal-event { display: block !important; font-size: 9px !important; padding: 2px 4px !important; border-radius: 6px !important; }
        .cal-day.has-event::after { display: none !important; }
        .cal-nav { flex-wrap: wrap; gap: 6px; }
    }
    @media (max-width: 400px) {
        .cal-day { min-height: 58px !important; padding: 4px 3px !important; }
        .cal-daynum { font-size: 10px !important; width: 18px !important; height: 18px !important; }
        .cal-event { font-size: 8px !important; padding: 1px 3px !important; }
    }
</style>

<div id="spMobileBar" aria-hidden="true">
    <button type="button" class="sp-burger" id="spBurger" aria-label="Open menu">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <div class="sp-brand"><span class="a">SMART</span> <span class="b">PET</span></div>
    <?php // Wala nang role pill (Adopter/Staff/Admin) sa mobile bar ?>
</div>
<div id="spNavBackdrop"></div>

<script>
(function () {
    const burger = document.getElementById('spBurger');
    const backdrop = document.getElementById('spNavBackdrop');
    const sidebar = document.querySelector('.sidebar');
    if (!burger || !sidebar) { return; }

    function setNav(open) {
        document.body.classList.toggle('sp-nav-open', open);
        burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }

    burger.addEventListener('click', function () {
        setNav(!document.body.classList.contains('sp-nav-open'));
    });
    backdrop.addEventListener('click', function () { setNav(false); });

    // Isara pagkapili ng link, para diretso sa page at hindi naiiwang bukas
    sidebar.addEventListener('click', function (e) {
        if (e.target.closest('a')) { setNav(false); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('sp-nav-open')) { setNav(false); }
    });

    // Kapag lumaki ang window pabalik sa desktop, siguraduhing malinis ang state
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) { setNav(false); }
    });
})();

// Sa telepono: ilipat ang bell + profile (Adopter at Staff) sa kanan ng mobile
// bar, para iisang header lang sa itaas. Ibinabalik sa dating puwesto kapag
// lumaki ulit ang screen. Isinisingit ng ibang script ang mga ito, kaya
// hinihintay munang lumabas.
(function () {
    var bar = document.getElementById('spMobileBar');
    if (!bar) { return; }
    var mq = window.matchMedia('(max-width: 900px)');
    var moved = []; // [{el, parent, next}]

    function place() {
        var groups = document.querySelectorAll('.top-navbar .sp-header-right, .top-header .sp-staff-right');
        groups.forEach(function (el) {
            if (moved.some(function (m) { return m.el === el; })) { return; }
            moved.push({ el: el, parent: el.parentNode, next: el.nextSibling });
        });
        moved.forEach(function (m) {
            if (mq.matches) {
                if (m.el.parentNode !== bar) { bar.appendChild(m.el); }
            } else if (m.el.parentNode === bar) {
                m.parent.insertBefore(m.el, m.next && m.next.parentNode === m.parent ? m.next : null);
            }
        });
        bar.removeAttribute('aria-hidden');
    }

    function start() {
        place();
        // Ilipat AGAD kapag lumabas na ang bell+profile (isinisingit ng ibang script),
        // imbes na maghintay ng 150ms - walang saglit na nakikita ito sa maling puwesto
        if (!moved.length && window.MutationObserver) {
            var obs = new MutationObserver(function () {
                place();
                if (moved.length) { obs.disconnect(); }
            });
            obs.observe(document.body, { childList: true, subtree: true });
            setTimeout(function () { obs.disconnect(); }, 5000);
        }
        // Panangga lang (lumang browser na walang MutationObserver)
        var tries = 0;
        var timer = setInterval(function () {
            place();
            if (++tries > 20 || moved.length) { clearInterval(timer); }
        }, 150);
        if (mq.addEventListener) { mq.addEventListener('change', place); } else { mq.addListener(place); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

// ===== Sticky na lumiliit kapag nakadikit (stat cards, banner ng Certificates at Location) =====
// Kapag lumiit ang elemento, nababawasan ang taas ng page sa itaas ng nakikita, kaya kusang
// ginagalaw ng browser ang scroll (scroll anchoring) - bumabalik ito sa ibabaw ng palatandaan,
// lumalaki ulit, at paulit-ulit (pagkislap). Ang ayos: idinadagdag sa margin-bottom ang eksaktong
// nabawas na taas, kaya hindi nagbabago ang kabuuang taas ng page.
// Gamit: spSetStuck(elemento, true/false)
window.spSetStuck = function (el, stuck) {
    if (!el || el.classList.contains('is-stuck') === stuck) { return; }
    if (stuck) {
        // Sukatin isang beses (walang animation) ang buo at maliit na taas
        if (el._spShrink === undefined) {
            el.classList.add('sp-measure');
            var full = el.offsetHeight;
            el._spBaseMB = parseFloat(getComputedStyle(el).marginBottom) || 0;
            el.classList.add('is-stuck');
            var small = el.offsetHeight;
            el.classList.remove('is-stuck');
            void el.offsetHeight;
            el.classList.remove('sp-measure');
            el._spShrink = Math.max(0, full - small);
        }
        el.style.setProperty('margin-bottom', (el._spBaseMB + el._spShrink) + 'px', 'important');
        el.classList.add('is-stuck');
    } else {
        el.classList.remove('is-stuck');
        el.style.removeProperty('margin-bottom');
    }
};
// Nagbago ang lapad ng screen: susukatin ulit sa susunod na pagdikit
window.addEventListener('resize', function () {
    document.querySelectorAll('.is-stuck, .sp-sticky-cards, #certHero, #locBanner').forEach(function (el) {
        if (el._spShrink === undefined) { return; }
        delete el._spShrink;
        if (el.classList.contains('is-stuck')) { window.spSetStuck(el, false); }
    });
});

// Sticky na hanay ng mga link (telepono): nilalagyan ng .sp-stuck kapag nakadikit na
// sa ilalim ng mobile bar, para lumabas ang manipis na anino
(function () {
    var mq = window.matchMedia('(max-width: 900px)');
    var ticking = false;
    function update() {
        ticking = false;
        document.querySelectorAll('.top-navbar, .staff-dashboard-root .top-header').forEach(function (nav) {
            nav.classList.toggle('sp-stuck', mq.matches && nav.getBoundingClientRect().top <= 54.5 && window.scrollY > 0);
        });
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
    window.addEventListener('resize', update);

    // --sp-stick-top: kung saan dapat dumikit ang iba pang sticky na bahagi ng page
    // (hal. "Your Bills" + pagination) - sa ilalim ng mobile bar at ng hanay ng mga link.
    // Sa desktop: 0 (walang nakapatong na header).
    var stickNav = document.querySelector('.top-navbar, .staff-dashboard-root .top-header');
    function setStickTop() {
        var top = 0;
        if (mq.matches) {
            // -1: bahagyang nakapatong para walang manipis na puwang sa pagitan
            top = 54 + (stickNav ? stickNav.offsetHeight : 0) - 1;
        }
        document.documentElement.style.setProperty('--sp-stick-top', Math.max(0, top) + 'px');
    }
    setStickTop();
    window.addEventListener('resize', setStickTop);
    window.addEventListener('load', setStickTop);
    // Nagbabago ang taas ng hanay kapag inilipat ng script ang bell/profile sa mobile bar
    // (o kapag na-load ang font) - kinukuwenta ulit nang kusa
    if (stickNav && window.ResizeObserver) { new ResizeObserver(setStickTop).observe(stickNav); }
})();

// Card tables - nilalagyan ng label (galing sa header) ang bawat cell, para sa
// CSS na nagiging card ang bawat row sa telepono. Walang epekto sa desktop.
(function () {
    function labelTable(table) {
        var ths = table.querySelectorAll('thead th');
        if (!ths.length) { return; }
        var labels = Array.prototype.map.call(ths, function (th) { return th.textContent.replace(/\s+/g, ' ').trim(); });
        table.classList.add('sp-card-table');
        Array.prototype.forEach.call(table.tBodies, function (tbody) {
            Array.prototype.forEach.call(tbody.rows, function (row) {
                var col = 0;
                Array.prototype.forEach.call(row.cells, function (cell, i) {
                    if (!cell.hasAttribute('data-label')) {
                        cell.setAttribute('data-label', labels[col] || '');
                    }
                    cell.classList.toggle('sp-card-head', i === 0 && row.cells.length > 1);
                    col += cell.colSpan || 1;

                    // Higit sa isang bahagi ang laman - ibalot para nakasalansan sa card
                    if (cell.colSpan <= 1 && !(cell.childElementCount === 1 && cell.firstElementChild.classList.contains('sp-cell-val'))) {
                        var parts = Array.prototype.filter.call(cell.childNodes, function (n) {
                            return n.nodeType === 1 || (n.nodeType === 3 && n.textContent.trim() !== '');
                        });
                        if (parts.length > 1) {
                            var wrap = document.createElement('div');
                            wrap.className = 'sp-cell-val';
                            while (cell.firstChild) { wrap.appendChild(cell.firstChild); }
                            cell.appendChild(wrap);
                        }
                    }
                });
            });
        });
    }

    function scan(root) {
        (root || document).querySelectorAll('.main-content table, .main-wrapper table').forEach(function (table) {
            if (table.classList.contains('no-cards') || table.closest('.no-cards')) { return; }
            labelTable(table);
        });
    }

    function start() {
        scan();
        // Mga table na pinupuno ng JavaScript (hal. filter, pagination) - lagyan
        // din ng label ang mga bagong row
        var pending = false;
        new MutationObserver(function () {
            if (pending) { return; }
            pending = true;
            requestAnimationFrame(function () { pending = false; scan(); });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

// ===== Staff sa telepono: puting panel na buong lapad + swipe na mga card =====
(function () {
    if (!document.body.classList.contains('staff-dashboard-root')) { return; }
    var mq = window.matchMedia('(max-width: 600px)');

    // 1) Buong lapad: hinahanap ang mga puting panel na sakop ang buong lapad ng content
    //    (app-panel, pay-panel, inventory-panel, atbp. - iba-iba ang pangalan sa bawat
    //    page), saka hinihila palabas ng negative margin hanggang sa gilid ng screen.
    function bleed() {
        document.querySelectorAll('.sp-bleed').forEach(function (el) {
            el.classList.remove('sp-bleed');
            el.style.removeProperty('margin-left');
            el.style.removeProperty('margin-right');
        });
        if (!mq.matches) { return; }
        var dc = document.querySelector('.dashboard-content');
        if (!dc) { return; }
        var cs = getComputedStyle(dc);
        var inner = dc.getBoundingClientRect().width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        var vw = document.documentElement.clientWidth;
        dc.querySelectorAll(':scope > *, :scope > * > *, :scope > * > * > *').forEach(function (el) {
            if (el.closest('.sp-bleed') || el.closest('[role="dialog"], .modal, .modal-backdrop')) { return; }
            if (el.closest('.sp-chat-shell')) { return; }   // Messages: may sariling ayos sa ibaba
            var c = getComputedStyle(el);
            if (c.position === 'fixed' || c.position === 'absolute' || c.display === 'none') { return; }
            if (c.backgroundColor !== 'rgb(255, 255, 255)') { return; }
            var r = el.getBoundingClientRect();
            if (r.width < inner - 2 || r.height < 60 || r.left <= 0.5) { return; }   // maliit, o buong lapad na
            el.classList.add('sp-bleed');
            el.style.marginLeft = (-r.left) + 'px';
            el.style.marginRight = (-(vw - r.right)) + 'px';
        });
    }

    // 2) Swipe: ang mga card table (2+ card) ay nagiging pakaliwa/pakanan, may bilang sa ilalim
    function rowsOf(table) {
        return Array.prototype.filter.call(table.tBodies[0] ? table.tBodies[0].rows : [], function (tr) {
            return tr.cells.length > 1;    // ang may colspan na iisang cell = "No records…"
        });
    }
    function visibleRows(table) {
        return rowsOf(table).filter(function (tr) { return tr.offsetParent !== null; });
    }
    function updateCount(table, reset) {
        var box = table._spCount, body = table.tBodies[0];
        if (!box || !body) { return; }
        var vis = visibleRows(table);
        box.hidden = !mq.matches || vis.length < 2;
        if (box.hidden) { return; }
        if (reset) { body.scrollLeft = 0; }
        var b = body.getBoundingClientRect(), mid = b.left + body.clientWidth / 2, best = 0, dist = Infinity;
        vis.forEach(function (tr, i) {
            var r = tr.getBoundingClientRect(), d = Math.abs(r.left + r.width / 2 - mid);
            if (d < dist) { dist = d; best = i; }
        });
        box.querySelector('b').textContent = best + 1;
        box.querySelector('.sp-swipe-total').textContent = vis.length;
    }
    function setupSwipe() {
        document.querySelectorAll('.dashboard-content table.sp-card-table').forEach(function (table) {
            // May sariling swipe ang Pet Monitoring; hindi kasama ang mga table sa pop-up
            if (table.classList.contains('mon-table') || table.closest('[role="dialog"], .modal, .modal-backdrop')) { return; }
            var body = table.tBodies[0];
            if (!body || rowsOf(table).length < 2) { return; }
            Array.prototype.forEach.call(body.rows, function (tr) { tr.classList.toggle('sp-swipe-full', tr.cells.length <= 1); });
            if (table.classList.contains('sp-swipe')) { updateCount(table, false); return; }
            table.classList.add('sp-swipe');
            // Mga lalagyan sa pagitan ng table at ng puting panel: walang sariling kahon/border
            for (var p = table.parentElement; p && !p.classList.contains('dashboard-content') && !p.classList.contains('sp-bleed'); p = p.parentElement) {
                p.classList.add('sp-swipe-wrap');
            }

            var box = document.createElement('div');
            box.className = 'sp-swipe-count';
            box.setAttribute('aria-live', 'polite');
            box.innerHTML = '<span><b>1</b> / <span class="sp-swipe-total">0</span></span><span class="sp-swipe-hint">&middot; Swipe to see more &rarr;</span>';
            table.insertAdjacentElement('afterend', box);
            table._spCount = box;

            var raf = null;
            body.addEventListener('scroll', function () {
                if (raf) { return; }
                raf = requestAnimationFrame(function () { raf = null; updateCount(table, false); });
            }, { passive: true });
            // Filter/search ng page (nagtatago ng row): kapag nagbago ang bilang ng nakikitang
            // card, bumalik sa unang card; kung hindi, i-update lang ang bilang
            var lastCount = visibleRows(table).length;
            new MutationObserver(function () {
                var n = visibleRows(table).length;
                updateCount(table, n !== lastCount);
                lastCount = n;
            }).observe(body, { attributes: true, subtree: true, attributeFilter: ['style', 'hidden'] });
            updateCount(table, false);
        });
    }

    // 3) Sticky na stat cards: ang unang .cards-grid ng page.
    //    Hindi na pinapaliit ang mismong cards (nag-iiwan iyon ng blangkong puwang sa ilalim).
    //    Sa halip, may compact na KOPYA (.sp-cards-float) na nakapirmi sa ilalim ng mga link,
    //    na lumalabas lang kapag na-scroll na palayo ang totoong cards.
    var cards = document.querySelector('.dashboard-content > .cards-grid');
    var cardsFloat = null;
    function stickyCards() {
        if (!cards) { return; }
        cards.classList.remove('sp-sticky-cards', 'is-stuck');
        ['margin-left', 'margin-right', 'padding-left', 'padding-right', 'margin-bottom'].forEach(function (p) { cards.style.removeProperty(p); });
        if (cardsFloat) { cardsFloat.remove(); cardsFloat = null; }
        if (!mq.matches) { return; }
        // Buong lapad: hilahin hanggang sa gilid ng screen, saka ibalik ang cards sa dating puwesto
        var r = cards.getBoundingClientRect(), vw = document.documentElement.clientWidth;
        var left = r.left, right = vw - r.right;
        cards.classList.add('sp-sticky-cards');
        cards.style.setProperty('margin-left', (-left) + 'px', 'important');
        cards.style.setProperty('margin-right', (-right) + 'px', 'important');
        cards.style.setProperty('padding-left', left + 'px', 'important');
        cards.style.setProperty('padding-right', right + 'px', 'important');

        // Ang compact na kopya (parehong class, kaya gamit nito ang CSS ng .is-stuck)
        cardsFloat = cards.cloneNode(true);
        cardsFloat.removeAttribute('style');
        cardsFloat.querySelectorAll('[id]').forEach(function (el) { el.removeAttribute('id'); });
        cardsFloat.classList.add('is-stuck', 'sp-cards-float');
        cardsFloat.setAttribute('aria-hidden', 'true');
        cardsFloat.style.setProperty('padding-left', left + 'px', 'important');
        cardsFloat.style.setProperty('padding-right', right + 'px', 'important');
        // Pindot sa kopya = pindot sa totoong card (kung may sariling gawa ito, hal. filter)
        cardsFloat.addEventListener('click', function (e) {
            if (e.target.closest('a[href]')) { return; }
            var item = e.target.closest('.sp-cards-float > *');
            var real = item && cards.children[Array.prototype.indexOf.call(cardsFloat.children, item)];
            if (real) { real.click(); }
        });
        cards.parentNode.insertBefore(cardsFloat, cards.nextSibling);
        markCards();
    }
    function markCards() {
        if (!cardsFloat) { return; }
        var stick = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sp-stick-top')) || 0;
        // Lalabas kapag halos natakpan na ng kopya ang natitirang bahagi ng totoong cards
        cardsFloat.classList.toggle('is-shown', cards.getBoundingClientRect().bottom < stick + cardsFloat.offsetHeight);
    }
    var cardsTick = false;
    window.addEventListener('scroll', function () {
        if (cardsTick) { return; }
        cardsTick = true;
        requestAnimationFrame(function () { cardsTick = false; markCards(); });
    }, { passive: true });

    function run() { stickyCards(); bleed(); setupSwipe(); }
    window.addEventListener('load', run);
    window.addEventListener('resize', function () { stickyCards(); bleed(); document.querySelectorAll('table.sp-swipe').forEach(function (t) { updateCount(t, false); }); });
    if (mq.addEventListener) { mq.addEventListener('change', run); }
    setTimeout(run, 400);
})();

// ===== Messages sa telepono (Adopter, Staff, Admin): sakop ang buong lapad =====
// Dikit sa ilalim ng hanay ng mga link, at hanggang sa ibaba ng screen
(function () {
    var shell = document.querySelector('.messenger-container, .chat-main-container');
    if (!shell) { return; }
    shell.classList.add('sp-chat-shell');
    var mq = window.matchMedia('(max-width: 900px)');
    var nav = document.querySelector('.top-navbar, .main-wrapper > .top-header');

    function fit() {
        document.body.classList.toggle('sp-chat-fill', mq.matches);
        ['margin-left', 'margin-right', 'margin-top', 'height'].forEach(function (p) { shell.style.removeProperty(p); });
        if (!mq.matches) { return; }
        // Sinusukat mula sa itaas ng page (hindi apektado kung naka-scroll)
        var y = window.scrollY, r = shell.getBoundingClientRect(), vw = document.documentElement.clientWidth;
        var navBottom = nav && nav.offsetParent !== null ? nav.getBoundingClientRect().bottom + y : 54;
        shell.style.setProperty('margin-left', (-r.left) + 'px', 'important');
        shell.style.setProperty('margin-right', (-(vw - r.right)) + 'px', 'important');
        shell.style.setProperty('margin-top', (navBottom - (r.top + y)) + 'px', 'important');
        // Taas: mula sa aktuwal na itaas nito (pagkatapos ng margin) hanggang sa ibaba ng screen
        var top = shell.getBoundingClientRect().top + window.scrollY;
        shell.style.setProperty('height', Math.max(320, window.innerHeight - top) + 'px', 'important');
    }
    fit();
    window.addEventListener('load', fit);
    window.addEventListener('resize', fit);
    if (nav && window.ResizeObserver) { new ResizeObserver(fit).observe(nav); }
})();
</script>
