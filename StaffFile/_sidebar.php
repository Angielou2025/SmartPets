<?php
// Reusable sidebar include for staff pages
require_once __DIR__ . '/../connection/database.php';

// Helper to safely count rows from an optional table
function sb_safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// Parehong bilang sa mga card ng Staff Dashboard, para magkatugma ang dalawa
require_once __DIR__ . '/../connection/match_scoring.php';

$pending_applications = sb_safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status IN ('Pending', 'Under Review')");
$interviews_today = sb_safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE DATE(preferred_interview_date) = CURDATE() AND application_status IN ('Pending', 'Under Review')");
$available_pets = sb_safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_inventory WHERE status = 'Available'");

// High match = bukas na application na may score na 80 pataas, gamit ang parehong
// computeMatchScore() ng dashboard at ng Smart Matches
$high_matches = 0;
try {
    $sbApps = $pdo->query(
        "SELECT aa.application_id, aa.match_score AS saved_match_score, aa.match_breakdown AS saved_match_breakdown,
                ap.pref_kind, ap.pref_breed, ap.pref_temperament, ap.pref_energy_level, ap.pref_size, ap.pref_age_group, ap.pref_gender, ap.pref_health_condition,
                ap.living_space, ap.activity_level, ap.maintenance_level, ap.allergies,
                p.kind, p.breed, p.energy_level, p.size, p.age_group, p.gender, p.health_condition, p.temperament, p.shedding_level, p.description
         FROM adoption_applications aa
         JOIN pet_inventory p ON aa.pet_id = p.pet_id
         LEFT JOIN adopter_preferences ap ON ap.user_id = aa.adopter_id
         WHERE aa.application_status IN ('Pending', 'Under Review')"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sbApps as $sbApp) {
        if ((sp_application_match($pdo, $sbApp)['score'] ?? 0) >= 80) { // naka-freeze noong nag-submit
            $high_matches++;
        }
    }
} catch (Throwable $e) {
    $high_matches = 0;
}
// Bilang ng unread messages, para may makita agad sa sidebar kahit hindi pa
// binuksan ang Messages page mismo. Nawawala ito pagkatapos mabasa (is_read = 1).
$sp_unread_count = isset($_SESSION['user_id']) ? sp_unread_message_count($pdo, (int) $_SESSION['user_id']) : 0;
?>
<aside class="sidebar sb-init">
    <div class="brand">
        <div class="logo-circle">
            <img src="/Caps2/Images/caloocan_logo.jpg" alt="Logo">
        </div>
        <div>
            <h2>SMART <span>PET</span></h2>
        </div>
    </div>

    <?php include __DIR__ . '/_reminder_widget.php'; ?>
    <!-- Nakatago ang Reminders button sa sidebar: ang bell sa header na ang nagbubukas ng panel
         (pinipindot nito ang #spRemBtn), kaya nananatili ito sa page pero hindi nakikita.
         Tinanggal na rin ang Quick Stats. -->
    <style>
    .sidebar #spRemBtn { display: none !important; }

    /* Galaw na gaya ng sidebar ng Adopter (Adopter_File/dashboard.css .nav-item):
       malambot na paglipat ng kulay (0.25s), at sa hover ay bahagyang usog lang (3px)
       ng buong item - walang hiwalay na pagtalon ng icon. Asul pa rin ang kulay ng staff. */
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item {
        transition: background-color .25s ease, color .25s ease, transform .2s ease, box-shadow .25s ease;
    }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item .menu-icon {
        transition: background-color .25s ease, box-shadow .25s ease;
    }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item .menu-icon svg {
        transition: color .25s ease, fill .25s ease;
    }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item:hover .menu-icon { transform: none; }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item.active:hover {
        transform: none;
        background: rgba(255,255,255,0.12);
        color: #fff;
        box-shadow: none;
    }

    /* Walang scrollbar ng page sa kanan (nandito rin, maaga sa page, para hindi
       ito sandaling lumitaw bago ma-load ang connection/staff_header.php) */
    html { scrollbar-width: none; -ms-overflow-style: none; }
    html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; }

    /* ===== Ayos ng sidebar: walang scrollbar, pantay at mas siksik na pagitan ===== */
    .staff-dashboard-root .sidebar {
        scrollbar-width: none;           /* Firefox */
        -ms-overflow-style: none;
        padding: 20px 12px 14px !important;
    }
    .staff-dashboard-root .sidebar::-webkit-scrollbar { display: none; } /* Chrome, Edge */
    .staff-dashboard-root .sidebar .brand { margin-bottom: 18px; padding: 0 4px; }
    .staff-dashboard-root .sidebar .sidebar-menu { display: flex; flex-direction: column; gap: 4px; }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item {
        margin: 0 !important;
        padding: 8px 10px !important;
        gap: 12px;
        min-height: 48px;
        border-radius: 12px;
        font-size: 14.5px;
        font-weight: 600;
    }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item .menu-icon { width: 34px; height: 34px; flex-shrink: 0; }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item .menu-icon svg { width: 19px; height: 19px; }
    /* Isang linya lang ang pangalan (hal. "Staff Dashboard"), hindi nahahati */
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item .menu-text {
        flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2;
    }
    .staff-dashboard-root .sidebar .sidebar-menu > .menu-item .sp-unread-badge { flex-shrink: 0; }
    .staff-dashboard-root .sidebar .sidebar-footer { padding-top: 14px; }

    /* ===== Smooth na pagbukas ng page =====
       1) Walang animation ng highlight sa sidebar habang naglo-load (.sb-init),
          kaya hindi kumikislap ang napiling item sa bawat lipat ng page.
       2) Nakatago ang header hanggang maayos ito ng connection/staff_header.php
          (.sp-ready), saka dahan-dahang lilitaw - wala nang biglang paglipat.
          Kung hindi tumakbo ang script, kusa pa rin itong lilitaw pagkalipas ng 1s. */
    .staff-dashboard-root .sidebar.sb-init,
    .staff-dashboard-root .sidebar.sb-init * { transition: none !important; }
    .staff-dashboard-root .top-header:not(.sp-ready) {
        opacity: 0;
        animation: spHeaderFallback .2s ease 1s forwards;
    }
    .staff-dashboard-root .top-header.sp-ready { opacity: 1; animation: spHeaderIn .25s ease; }
    @keyframes spHeaderFallback { to { opacity: 1; } }
    @keyframes spHeaderIn { from { opacity: 0; } to { opacity: 1; } }
    .staff-dashboard-root .main-wrapper .dashboard-content { animation: spContentIn .3s ease both; }
    @keyframes spContentIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
        .staff-dashboard-root .top-header.sp-ready,
        .staff-dashboard-root .main-wrapper .dashboard-content { animation: none; }
    }

    /* ===== Lalagyan ng laman: puti, may pagitan mula sa sidebar, at bilugan
       ang kaliwang itaas na sulok (gaya ng sa Adopter). Light brown ang
       background sa likod (ang nakikita sa pagitan). ===== */
    @media (min-width: 901px) {
        html body.staff-dashboard-root { background: #e9dcc6; }
        html body.staff-dashboard-root .main-wrapper {
            margin-left: 232px !important;              /* 220px sidebar + 12px na pagitan */
            max-width: calc(100% - 232px) !important;
            background: #ffffff;
            border-top-left-radius: 32px;
            overflow: clip;                              /* para sumunod ang header sa bilog na sulok */
            box-shadow: -4px 0 18px rgba(90, 60, 20, 0.12);
            /* Asul na border sa kaliwa, sumusunod sa bilog na sulok hanggang sa itaas */
            border-left: 2px solid #2f5bc4;
            border-top: 2px solid #2f5bc4;
        }
        html body.staff-dashboard-root .top-header { border-top-left-radius: 30px; } /* 32px bawas ang 2px na border */
    }
    /* Maliit na laptop: 200px ang sidebar (connection/responsive.php) */
    @media (min-width: 901px) and (max-width: 1366px) {
        html body.staff-dashboard-root .main-wrapper {
            margin-left: 212px !important;
            max-width: calc(100% - 212px) !important;
        }
    }

    /* ===== Pamagat ng page (reusable): icon tile + breadcrumb + pamagat + paliwanag =====
       Gamit: <header class="sp-page-head"> ... (tingnan ang pet_inventory.php) */
    .staff-dashboard-root .sp-page-head {
        position: relative;
        display: flex;
        align-items: center;
        gap: 16px;
        margin: 4px 0 22px;
        padding-bottom: 18px;
        animation: spHeadIn .45s cubic-bezier(.22, 1, .36, 1) both;
    }
    /* Manipis na guhit sa ilalim na may asul na accent sa simula */
    .staff-dashboard-root .sp-page-head::after {
        content: '';
        position: absolute;
        left: 0; right: 0; bottom: 0;
        height: 1px;
        background: linear-gradient(90deg, #2f5bc4 0, #2f5bc4 64px, #e8ecf3 64px, #e8ecf3 100%);
    }
    .staff-dashboard-root .sp-page-icon {
        width: 56px; height: 56px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        border-radius: 16px;
        color: #fff;
        background: linear-gradient(135deg, #172554 0%, #1e40af 55%, #3b6fe0 100%);
        box-shadow: 0 10px 22px rgba(30, 58, 138, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.18);
    }
    .staff-dashboard-root .sp-page-icon svg { width: 28px; height: 28px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; overflow: visible; }
    .staff-dashboard-root .sp-page-icon .ic-solid { fill: currentColor; stroke: none; }
    .staff-dashboard-root .sp-page-icon .ic-fill { fill: rgba(255, 255, 255, 0.25); }
    .staff-dashboard-root .sp-page-text { min-width: 0; }
    .staff-dashboard-root .sp-crumb {
        display: flex; align-items: center; gap: 6px;
        margin-bottom: 4px;
        font-size: 12px; font-weight: 700; letter-spacing: .3px; text-transform: uppercase;
        color: #8a94a6;
    }
    .staff-dashboard-root .sp-crumb a { color: #8a94a6; text-decoration: none; transition: color .2s ease; }
    .staff-dashboard-root .sp-crumb a:hover { color: #2f5bc4; }
    .staff-dashboard-root .sp-crumb svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
    .staff-dashboard-root .sp-crumb span { color: #2f5bc4; }
    .staff-dashboard-root .sp-page-head h1 {
        margin: 0;
        font-size: 28px;
        font-weight: 800;
        letter-spacing: -0.4px;
        line-height: 1.15;
        color: #0f1b3d;
    }
    .staff-dashboard-root .sp-page-head p { margin: 4px 0 0; font-size: 14.5px; color: #6b7280; line-height: 1.5; }
    /* Button sa kanan ng pamagat (opsyonal): <div class="sp-page-actions">...</div> */
    .staff-dashboard-root .sp-page-actions { margin-left: auto; display: flex; gap: 10px; flex-wrap: wrap; }
    .staff-dashboard-root .sp-btn-outline {
        display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px;
        border-radius: 10px; border: 1.5px solid #dbe3f4; background: #fff; color: #1e3a8a;
        font-size: 14px; font-weight: 700; text-decoration: none; white-space: nowrap;
        transition: background-color .2s ease, border-color .2s ease;
    }
    .staff-dashboard-root .sp-btn-outline:hover { background: #eef2fb; border-color: #c9d6f2; }
    .staff-dashboard-root .sp-btn-outline svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    @media (max-width: 700px) { .staff-dashboard-root .sp-page-head { flex-wrap: wrap; } .staff-dashboard-root .sp-page-actions { margin-left: 0; } }
    @keyframes spHeadIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    @media (max-width: 600px) {
        .staff-dashboard-root .sp-page-icon { width: 46px; height: 46px; border-radius: 13px; }
        .staff-dashboard-root .sp-page-icon svg { width: 23px; height: 23px; }
        .staff-dashboard-root .sp-page-head h1 { font-size: 23px; }
    }
    @media (prefers-reduced-motion: reduce) { .staff-dashboard-root .sp-page-head { animation: none; } }

    /* ===== Mga stat card (reusable): madilim (kaliwa) hanggang maliwanag (kanan) na asul =====
       Gamit: <div class="cards-grid sp-cards"><a class="stat-card sp-card sp-c1" href="...">
       ... <div class="sp-icon"><svg>...</svg></div></a> (sp-c1 hanggang sp-c4) */
    .staff-dashboard-root .sp-cards .sp-card {
        position: relative;
        border: none;
        color: #fff;
        text-decoration: none;
        gap: 12px;
        transition: transform .2s ease, box-shadow .2s ease, filter .2s ease;
    }
    .staff-dashboard-root .sp-cards .sp-card::after { display: none; }
    .staff-dashboard-root .sp-cards .sp-card h1,
    .staff-dashboard-root .sp-cards .sp-card p { color: #fff !important; }
    .staff-dashboard-root .sp-cards .sp-card p { line-height: 1.3; }
    .staff-dashboard-root .sp-cards .sp-card small { opacity: .8; font-weight: 600; }
    .staff-dashboard-root .sp-cards a.sp-card { cursor: pointer; }
    .staff-dashboard-root .sp-cards a.sp-card:hover { transform: translateY(-4px); filter: brightness(1.08); box-shadow: 0 16px 28px rgba(15, 27, 61, 0.28); }
    .staff-dashboard-root .sp-cards a.sp-card:active { transform: translateY(-1px); }
    .staff-dashboard-root .sp-cards a.sp-card:focus-visible { outline: 3px solid rgba(96, 165, 250, 0.7); outline-offset: 3px; }
    .staff-dashboard-root .sp-cards .sp-c1 { background: linear-gradient(135deg, #0f1b3d 0%, #172554 100%); box-shadow: 0 8px 18px rgba(15, 27, 61, 0.30); }
    .staff-dashboard-root .sp-cards .sp-c2 { background: linear-gradient(135deg, #172f6e 0%, #1e40af 100%); box-shadow: 0 8px 18px rgba(30, 64, 175, 0.28); }
    .staff-dashboard-root .sp-cards .sp-c3 { background: linear-gradient(135deg, #1f4fc0 0%, #2a62de 100%); box-shadow: 0 8px 18px rgba(42, 98, 222, 0.28); }
    .staff-dashboard-root .sp-cards .sp-c4 { background: linear-gradient(135deg, #3170e8 0%, #4a86f0 100%); box-shadow: 0 8px 18px rgba(74, 134, 240, 0.30); }
    .staff-dashboard-root .sp-icon {
        width: 54px; height: 54px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.22);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18), 0 6px 14px rgba(0, 0, 0, 0.12);
        color: #fff;
        transition: transform .25s ease, background-color .25s ease;
    }
    .staff-dashboard-root .sp-card:hover .sp-icon { transform: translateY(-2px) rotate(-4deg); background: rgba(255, 255, 255, 0.2); }
    .staff-dashboard-root .sp-icon svg { width: 28px; height: 28px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; overflow: visible; }
    .staff-dashboard-root .sp-icon .ic-fill { fill: rgba(255, 255, 255, 0.22); }
    .staff-dashboard-root .sp-icon .ic-solid { fill: currentColor; stroke: none; }
    @media (max-width: 600px) {
        .staff-dashboard-root .sp-icon { width: 44px; height: 44px; border-radius: 13px; }
        .staff-dashboard-root .sp-icon svg { width: 23px; height: 23px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .staff-dashboard-root .sp-cards .sp-card, .staff-dashboard-root .sp-icon { transition: none; }
    }

    /* ===== Malambot na paglipat ng page (View Transitions) =====
       Sa paglipat sa ibang staff page, hindi na kumikislap: nananatiling nakatigil
       ang sidebar at header, at ang laman lang ang dahan-dahang napapalitan.
       Sa browser na hindi ito suportado, ang animation sa itaas pa rin ang gamit. */
    @view-transition { navigation: auto; }
    @supports (view-transition-name: none) {
        .staff-dashboard-root .sidebar { view-transition-name: sp-sidebar; }
        .staff-dashboard-root .top-header { view-transition-name: sp-header; }
        /* Ang crossfade na ang bahala - huwag nang doblehin ang animation */
        .staff-dashboard-root .top-header.sp-ready,
        .staff-dashboard-root .main-wrapper .dashboard-content { animation: none; }
        /* Hindi na naglalaho ang lumang page - nananatili ito sa ilalim habang
           lumilitaw ang bago sa ibabaw. Kapag sabay silang nag-fade, sandaling
           sumisilip ang plain na background (hal. Pet Monitoring -> Messages). */
        ::view-transition-old(root) { animation: none; }
        ::view-transition-new(root) { animation: spVtIn .22s ease both; }
        /* Sidebar at header: palitan agad, walang galaw */
        ::view-transition-group(sp-sidebar),
        ::view-transition-group(sp-header) { animation-duration: 0s; }
        ::view-transition-old(sp-sidebar), ::view-transition-old(sp-header) { display: none; }
        ::view-transition-new(sp-sidebar), ::view-transition-new(sp-header) { animation: none; }
    }
    @keyframes spVtIn { from { opacity: 0; } to { opacity: 1; } }
    /* Ang pinakalikod ng page (html) ay kakulay ng background ng staff - hindi
       na puti ang sumisilip habang naglo-load o lumilipat ng page */
    html:has(> body.staff-dashboard-root) { background: #e9dcc6; }
    @media (prefers-reduced-motion: reduce) {
        ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation: none !important; }
    }
    </style>
    <!-- Mas mabilis na pagbukas: kapag tinutukan ng mouse ang link sa sidebar o navbar,
         inihahanda na ng browser ang page. Hindi kasama ang Messages (nagma-mark ito
         ng nabasa pagbukas) at Logout. -->
    <script type="speculationrules">
    {
        "prefetch": [{
            "where": { "and": [
                { "selector_matches": ".sidebar .sidebar-menu a, .sp-staff-nav a" },
                { "not": { "href_matches": "*/messages.php*" } },
                { "not": { "href_matches": "*logout*" } }
            ] },
            "eagerness": "moderate"
        }]
    }
    </script>

    <nav class="sidebar-menu">
        <a class="menu-item" href="/Caps2/StaffFile/staff_dashboard.php">
            <span class="menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z"/></svg></span>
            <span class="menu-text">Staff Dashboard</span>
        </a>

        <a class="menu-item" href="/Caps2/StaffFile/smart_matches.php">
            <span class="menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s-7-4.35-9-7c-1.5-1.95-1-5 1.5-5 2 0 3.5 2 3.5 2S9.5 6 12 6s4 2 4 2 1.5-2 3.5-2c2.5 0 3 3.05 1.5 5-2 2.65-9 7-9 7z"/></svg></span>
            <span class="menu-text">Smart Matches</span>
        </a>

        <!-- Pet Inventory: nasa navbar na (tingnan ang connection/staff_header.php) -->

        <a class="menu-item" href="/Caps2/StaffFile/applications.php">
            <span class="menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM8 8h8v2H8V8zM8 11h8v2H8v-2z"/></svg></span>
            <span class="menu-text">Applications</span>
        </a>

        <a class="menu-item" href="/Caps2/StaffFile/payments.php">
            <span class="menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M2 6h20v12H2V6zm2 2v8h16V8H4zm8 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM5 9.5h2v5H5v-5zm12 0h2v5h-2v-5z"/></svg></span>
            <span class="menu-text">Payments</span>
        </a>

        <!-- Certificates: nasa navbar na (tingnan ang connection/staff_header.php) -->

        <a class="menu-item" href="/Caps2/StaffFile/pet_monitoring.php">
            <span class="menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3 2 11h3v9h6v-6h2v6h6v-9h3L12 3z"/></svg></span>
            <span class="menu-text">Pet Monitoring</span>
        </a>

        <!-- Daily Schedule at Medical & Intake: nasa navbar na (tingnan ang connection/staff_header.php) -->

        <!-- Availability: nasa navbar na (tingnan ang connection/staff_header.php) -->

        <a class="menu-item" href="/Caps2/StaffFile/messages.php">
            <span class="menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg></span>
            <span class="menu-text">Messages</span>
            <?php if ($sp_unread_count > 0): ?>
                <span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span>
            <?php endif; ?>
        </a>

        <a class="menu-item" href="/Caps2/StaffFile/reports.php">
            <span class="menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg></span>
            <span class="menu-text">Reports</span>
        </a>
    </nav>

    <!-- Wala na ang Logout dito: nasa menu na ng profile sa itaas (connection/staff_header.php) -->
    <div class="sidebar-footer">
        <div class="city-illustration"><img src="/Caps2/Images/caloocan_image.png" alt="City"></div>
    </div>
</aside>

<script>
// Highlight ng sidebar: nakabatay sa kasalukuyang page, gaya ng navbar.
// - Kapag nasa page na nasa sidebar: iyon ang naka-highlight.
// - Kapag nasa page na nasa navbar (Home, Pet Inventory, atbp.): walang highlight sa sidebar.
// - Kapag nasa sub-page (hal. review_application.php galing Applications): ang huling pinindot
//   sa sidebar ang naka-highlight. Nasa sessionStorage lang ito at binubura sa logout,
//   kaya pag-login ulit ay Dashboard ang naka-highlight.
(function(){
    const STORAGE_KEY = 'staff_selected_menu';
    const NAVBAR_PAGES = ['pet_inventory.php', 'certificates.php', 'staff_availability.php', 'daily_schedule.php', 'medical_intake.php'];

    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {} // lumang paraan, linisin

    function pathOf(href) {
        try { return new URL(href, window.location.origin).pathname; } catch (e) { return ''; }
    }
    function fileOf(path) { return (path.split('/').pop() || '').toLowerCase(); }

    function menuItems() { return Array.from(document.querySelectorAll('.sidebar .sidebar-menu > .menu-item')); }

    function setActive(pathname) {
        const items = menuItems();
        items.forEach(el => el.classList.remove('active'));
        const match = pathname ? items.find(a => pathOf(a.href) === pathname) : null;
        if (match) match.classList.add('active');
        return match || null;
    }

    document.addEventListener('click', function(e){
        const side = e.target.closest('.sidebar .menu-item');
        if (side) {
            try { sessionStorage.setItem(STORAGE_KEY, pathOf(side.href)); } catch (err) {}
            setActive(pathOf(side.href)); // agad lilipat (may malambot na fade ng kulay)
            return;
        }
        // Pinindot ang link sa navbar: dahan-dahang mawawala ang highlight sa sidebar
        if (e.target.closest('.sp-staff-nav a')) {
            try { sessionStorage.removeItem(STORAGE_KEY); } catch (err) {}
            setActive('');
        }
        // Logout: burahin ang naalala para Dashboard ulit pag-login
        if (e.target.closest('.logout-btn, a[href*="logout.php"]')) {
            try { sessionStorage.removeItem(STORAGE_KEY); } catch (err) {}
        }
    }, true);

    function apply() {
        const here = window.location.pathname;
        const direct = setActive(here);
        if (direct) {
            try { sessionStorage.setItem(STORAGE_KEY, here); } catch (e) {}
            return direct;
        }
        if (NAVBAR_PAGES.indexOf(fileOf(here)) !== -1) {
            try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
            return setActive('');
        }
        let stored = '';
        try { stored = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
        return setActive(stored);
    }
    // Agad na (nandito na ang sidebar sa itaas ng script), para naka-highlight na
    // ang tamang item bago pa makita ang page - walang kislap
    apply();
    // Pagkatapos ng unang paint, ibalik ang mga animation (para sa hover at pagpindot)
    const sb = document.querySelector('.sidebar.sb-init');
    if (sb) requestAnimationFrame(function () { requestAnimationFrame(function () { sb.classList.remove('sb-init'); }); });
})();
</script>
