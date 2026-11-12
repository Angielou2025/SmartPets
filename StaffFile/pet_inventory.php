<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

// Staff (at Admin) lang ang puwedeng pumasok dito
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user profile info
$user_stmt = $pdo->prepare("SELECT username, profile_image, role FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$username = $current_user['username'] ?? 'Staff Member';
$profile_image = $current_user['profile_image'] ?? '';
$user_initials = strtoupper(substr($username, 0, 2));

// Helper function para sa ligtas na pag-count ng rows
function safe_count($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// Kunin ang mga kinakailangang counts para sa Pet Inventory Stat Cards
$total_pets = safe_count($pdo, "SELECT COUNT(*) AS c FROM pet_inventory");
// Kasama ang Under Review, gaya ng Staff Dashboard at sidebar
$pending_pets = safe_count($pdo, "SELECT COUNT(*) AS c FROM adoption_applications WHERE application_status IN ('Pending', 'Under Review')");
// Ginagamot: ang pinakahuling medical record ng alaga ay "Treatment"
// (kaparehong bilang ng "Under Treatment" sa Medical & Intake page)
$medical_pets = safe_count($pdo, "
    SELECT COUNT(*) AS c FROM (
        SELECT mr.pet_id
        FROM medical_records mr
        INNER JOIN (SELECT pet_id, MAX(checkup_date) AS max_date FROM medical_records GROUP BY pet_id) latest
            ON mr.pet_id = latest.pet_id AND mr.checkup_date = latest.max_date
        WHERE mr.status = 'Treatment'
        GROUP BY mr.pet_id
    ) t
");

// Load pets list galing sa database
$pets = [];
try {
    $sql = "SELECT pet_id, name, kind AS species, breed, age_group AS age, gender, size, energy_level, temperament, shedding_level, health_condition, vaccination_status, description, rescue_location, rescue_date, rescue_time, status, photo_url, rescue_video, created_at FROM pet_inventory ORDER BY pet_id DESC";
    $stmt = $pdo->query($sql);
    if ($stmt) {
        $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Hindi mabasa ang listahan: walang ipapakitang alaga (may mensahe sa table)
    $pets = [];
}

// Bilang ayon sa status, para sa mga filter sa itaas ng table (wala sa "All";
// nasa card na "Total Pets Registered" na ang kabuuan)
$statusCounts = [];
foreach ($pets as $p) {
    $k = strtolower($p['status'] ?? 'available');
    $statusCounts[$k] = ($statusCounts[$k] ?? 0) + 1;
}?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pet Inventory - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        /* Specific Styles para sa Pet Inventory Table at Badges */
        .inventory-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
            margin-top: 20px;
        }

        .inventory-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn-add-pet {
            background: #f59c23;
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.14s ease, background 0.14s ease;
        }

        .btn-add-pet:hover {
            background: #e08b18;
            transform: translateY(-2px);
        }

        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            min-width: 680px;
        }

        .inventory-table th {
            padding: 12px 10px;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #f1f1f1;
        }

        .inventory-table td {
            padding: 14px 10px;
            border-bottom: 1px solid #f8f8f8;
            vertical-align: middle;
            font-size: 14px;
        }

        /* Status Badges */
        .badge-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .badge-available {
            background-color: #e6f4ea;
            color: #1e7e34;
        }

        .badge-pending {
            background-color: #fef3d6;
            color: #b25900;
        }

        .badge-medical {
            background-color: #fce8e6;
            color: #c5221f;
        }

        .badge-adopted {
            background-color: #e8eaf6;
            color: #3949ab;
        }

        .badge-unavailable {
            background-color: #f1f1f1;
            color: #6b7280;
        }

        .btn-view-pet {
            background: transparent;
            border: 1px solid #1f6b48;
            color: #1f6b48;
            padding: 6px 16px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.14s ease, color 0.14s ease;
        }

        .btn-view-pet:hover {
            background: #1f6b48;
            color: #fff;
        }

        .pet-avatar-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #fef3d6;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-size: 16px;
        }

        /* Add Pet Modal Form */
        .add-pet-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .add-pet-grid .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #102a2a;
        }

        .add-pet-grid .form-group input,
        .add-pet-grid .form-group select,
        .add-pet-grid .form-group textarea {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            color: #102a2a;
            font-family: inherit;
        }

        .add-pet-grid .form-group textarea {
            resize: vertical;
        }

        @media (max-width: 560px) {
            .add-pet-grid {
                grid-template-columns: 1fr;
            }
        }

        /* View Pet Modal */
        .pet-view-header {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 18px;
            margin-bottom: 20px;
            border-radius: 14px;
            background: #1e3a8a;
        }

        .pet-view-photo-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .pet-view-photo, .pet-view-photo-fallback {
            width: 96px;
            height: 96px;
            border-radius: 16px;
            object-fit: cover;
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
            border: 3px solid #fff;
        }

        .pet-view-photo-fallback {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            background: #fff;
        }

        .pet-view-heading {
            min-width: 0;
        }

        .pet-view-name {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .pet-view-section-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #9aa3a3;
            margin: 0 0 10px 2px;
        }

        .pet-view-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }

        .pet-view-tile {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: linear-gradient(145deg, #ffffff, #f0f1f2);
            border: 1px solid #eef0ef;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 1px 0 rgba(255,255,255,0.8) inset,
                        0 6px 14px rgba(16,42,42,0.08),
                        0 2px 4px rgba(16,42,42,0.06);
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }

        .pet-view-tile:hover {
            transform: translateY(-3px);
            box-shadow: 0 1px 0 rgba(255,255,255,0.8) inset,
                        0 12px 20px rgba(16,42,42,0.12),
                        0 4px 8px rgba(16,42,42,0.08);
        }

        .pet-view-tile-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: linear-gradient(145deg, #ffffff, #eceded);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            box-shadow: 0 1px 0 rgba(255,255,255,0.9) inset,
                        0 3px 8px rgba(16,42,42,0.12);
        }

        .pet-view-tile-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #9aa3a3;
            margin-bottom: 3px;
        }

        .pet-view-tile-value {
            font-size: 14px;
            font-weight: 700;
            color: #102a2a;
            word-break: break-word;
        }

        .pet-view-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 18px;
        }

        .pet-view-tag {
            background: #eaf3ee;
            color: #1f6b48;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
        }

        .pet-view-description {
            background: #f7f8f7;
            border: 1px solid #eef0ef;
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 14px;
            line-height: 1.6;
            color: #445;
        }

        @media (max-width: 560px) {
            .pet-view-info-grid {
                grid-template-columns: 1fr 1fr;
            }
            .pet-view-header {
                flex-wrap: wrap;
            }
        }

        /* ===== View Pet sa telepono: buong screen, 3 bahagi na i-swipe pakaliwa/pakanan ===== */
        .pv-tabs { display: none; }
        @media (max-width: 600px) {
            /* Buong screen (natatakpan ang mobile bar); × sa itaas para lumabas */
            #viewPetModal { z-index: 10020 !important; padding: 0 !important; }
            #viewPetModal .modal-card {
                position: fixed !important; inset: 0 !important;
                width: 100% !important; max-width: none !important;
                height: 100% !important; max-height: none !important;
                margin: 0 !important; padding: 0 !important; border-radius: 0 !important;
                display: flex !important; flex-direction: column; overflow: hidden !important;
            }
            #viewPetModal .modal-header { flex-shrink: 0; padding: 12px 14px; margin: 0; border-bottom: 1px solid #eef0f3; }
            #viewPetModal .modal-title { font-size: 16px; }
            #viewPetModal .modal-header .btn {
                width: 36px; height: 36px; padding: 0 !important; border-radius: 50% !important;
                font-size: 16px !important; display: inline-flex; align-items: center; justify-content: center;
            }
            #viewPetModal .modal-actions { display: none !important; }   /* may × na sa itaas */

            /* Mga tab: Info / Rescue / Video (sumusunod sa pag-swipe; napipindot din) */
            .pv-tabs { display: flex; gap: 6px; padding: 8px 14px; flex-shrink: 0; border-bottom: 1px solid #eef0f3; }
            .pv-tab {
                flex: 1; height: 34px; border: none; border-radius: 9px; cursor: pointer;
                background: #f1f4f9; color: #475569; font-family: inherit; font-size: 12.5px; font-weight: 700;
                transition: background-color .2s ease, color .2s ease;
            }
            .pv-tab.is-on { background: #1e3a8a; color: #fff; }

            /* Ang laman: magkakatabing bahagi na humihinto sa bawat isa (scroll-snap) */
            #pvSlides {
                flex: 1; min-height: 0; display: flex !important;
                overflow-x: auto; overflow-y: hidden; padding: 0 !important; max-height: none !important;
                scroll-snap-type: x mandatory; scrollbar-width: none; -webkit-overflow-scrolling: touch;
            }
            #pvSlides::-webkit-scrollbar { display: none; }
            .pv-slide {
                flex: 0 0 100%; min-width: 0; box-sizing: border-box;
                padding: 14px; overflow-y: auto; scroll-snap-align: start;
            }

            /* Mas maliit na font para kasya ang bawat bahagi sa screen */
            .pet-view-header { padding: 12px; gap: 12px; margin-bottom: 12px; flex-wrap: nowrap; }
            .pet-view-photo, .pet-view-photo-fallback { width: 70px; height: 70px; border-radius: 13px; }
            .pet-view-name { font-size: 18px; }
            .pet-view-section-title { font-size: 11px; margin-bottom: 8px; }
            .pet-view-info-grid { grid-template-columns: repeat(3, 1fr) !important; gap: 6px; margin-bottom: 12px; }
            .pet-view-tile { flex-direction: column; gap: 4px; padding: 8px; }
            .pet-view-tile:hover { transform: none; }
            .pet-view-tile-icon { width: 26px; height: 26px; border-radius: 7px; font-size: 13px; }
            .pet-view-tile-label { font-size: 9.5px; margin-bottom: 1px; }
            .pet-view-tile-value { font-size: 12px; }
            .pet-view-tags { gap: 6px; margin-bottom: 12px; }
            .pet-view-tag { font-size: 11.5px; padding: 5px 11px; }
            .pet-view-description { font-size: 13px; line-height: 1.55; padding: 10px 12px; }
        }

        /* ===================== Bagong itsura (professional) ===================== */

        /* --- Mga stat card: madilim (kaliwa) hanggang maliwanag (kanan) na asul --- */
        .staff-dashboard-root .inv-cards .inv-card {
            position: relative;
            border: none;
            color: #fff;
            overflow: hidden;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .staff-dashboard-root .inv-cards .inv-card::after { display: none; }
        .staff-dashboard-root .inv-cards .inv-card h1,
        .staff-dashboard-root .inv-cards .inv-card p { color: #fff !important; }
        .staff-dashboard-root .inv-cards .inv-card p { opacity: .92; }
        .staff-dashboard-root .inv-cards .inv-card:hover { transform: translateY(-3px); }
        .staff-dashboard-root .inv-cards .inv-c1 { background: linear-gradient(135deg, #0f1b3d 0%, #172554 100%); box-shadow: 0 8px 18px rgba(15, 27, 61, 0.30); }
        .staff-dashboard-root .inv-cards .inv-c3 { background: linear-gradient(135deg, #1f4fc0 0%, #2a62de 100%); box-shadow: 0 8px 18px rgba(42, 98, 222, 0.28); }
        .staff-dashboard-root .inv-cards .inv-c4 { background: linear-gradient(135deg, #3170e8 0%, #4a86f0 100%); box-shadow: 0 8px 18px rgba(74, 134, 240, 0.30); }

        /* Napipindot ang mga card */
        .staff-dashboard-root .inv-cards .inv-card-link { text-decoration: none; cursor: pointer; }
        .staff-dashboard-root .inv-cards .inv-card-link:hover { transform: translateY(-4px); filter: brightness(1.08); box-shadow: 0 16px 28px rgba(15, 27, 61, 0.28); }
        .staff-dashboard-root .inv-cards .inv-card-link:active { transform: translateY(-1px); }
        .staff-dashboard-root .inv-cards .inv-card-link:focus-visible { outline: 3px solid rgba(96, 165, 250, 0.7); outline-offset: 3px; }
        .inventory-panel { scroll-margin-top: 16px; }
        /* Saglit na asul na liwanag sa table kapag galing sa card */
        .inventory-panel.inv-flash { animation: invFlash 1s ease; }
        @keyframes invFlash { 0% { box-shadow: 0 0 0 0 rgba(47, 91, 196, 0.45); } 100% { box-shadow: 0 0 0 14px rgba(47, 91, 196, 0); } }

        /* Icon sa malabong "salamin" na tile */
        .inv-icon {
            width: 54px; height: 54px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.22);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18), 0 6px 14px rgba(0, 0, 0, 0.12);
            color: #fff;
            transition: transform .25s ease, background-color .25s ease;
        }
        .inv-card:hover .inv-icon { transform: translateY(-2px) rotate(-4deg); background: rgba(255, 255, 255, 0.2); }
        .inv-icon svg { width: 28px; height: 28px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; overflow: visible; }
        .inv-icon .ic-fill { fill: rgba(255, 255, 255, 0.22); }
        .inv-icon .ic-solid { fill: currentColor; stroke: none; }

        /* --- Panel ng table --- */
        .inventory-panel { padding: 22px 22px 10px; border: 1px solid #eef0f3; }
        .inv-title { display: flex; align-items: center; gap: 12px; }
        .inv-title-icon {
            width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: #eef2fb; color: #1e3a8a;
        }
        .inv-title-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
        .inv-title h3 { margin: 0; font-size: 18px; font-weight: 800; color: #0f1b3d; }
        .inv-sub { font-size: 13px; color: #6b7280; }

        .btn-add-pet { background: linear-gradient(135deg, #2a62de, #1e3a8a); box-shadow: 0 6px 14px rgba(30, 58, 138, 0.25); height: 40px; padding: 0 18px; border-radius: 10px; }
        .btn-add-pet:hover { background: linear-gradient(135deg, #1e40af, #172554); }

        /* Toolbar: search + filter */
        .inv-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .inv-search {
            display: flex; align-items: center; gap: 8px; flex: 1 1 280px; max-width: 380px;
            height: 40px; padding: 0 12px; border-radius: 10px;
            background: #f6f7f9; border: 1.5px solid transparent; color: #9aa3ad;
            transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
        }
        .inv-search:focus-within { background: #fff; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        .inv-search svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
        .inv-search input { flex: 1; min-width: 0; border: none; background: transparent; outline: none; font-family: inherit; font-size: 14px; color: #102a2a; }
        .inv-filters { display: flex; gap: 6px; flex-wrap: wrap; padding: 4px; border-radius: 12px; background: #f3f5f8; }
        .inv-filter {
            display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px;
            border: none; border-radius: 9px; background: transparent; cursor: pointer;
            font-family: inherit; font-size: 13px; font-weight: 700; color: #5b6472;
            transition: background-color .2s ease, color .2s ease, box-shadow .2s ease;
        }
        .inv-filter span { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #e3e7ee; color: #475569; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; }
        .inv-filter:hover { color: #1e3a8a; }
        .inv-filter.active { background: #fff; color: #1e3a8a; box-shadow: 0 2px 6px rgba(15, 27, 61, 0.1); }
        .inv-filter.active span { background: #1e3a8a; color: #fff; }

        /* Table */
        .inv-table-wrap { overflow-x: auto; border: 1px solid #eef0f3; border-radius: 12px; }
        .inventory-table { min-width: 760px; }
        .inventory-table thead th {
            background: #f8f9fb; color: #6b7280; font-size: 11.5px; letter-spacing: .6px;
            padding: 12px 14px; border-bottom: 1px solid #eef0f3; white-space: nowrap;
        }
        .inventory-table tbody td { padding: 12px 14px; border-bottom: 1px solid #f2f4f6; }
        .inventory-table tbody tr:last-child td { border-bottom: none; }
        .inventory-table tbody .inv-row { transition: background-color .15s ease; }
        .inventory-table tbody .inv-row:hover { background: #f7f9fe; }
        .inv-id { font-family: 'Consolas', 'SFMono-Regular', monospace; font-size: 12.5px; font-weight: 700; color: #1e3a8a; background: #eef2fb; padding: 3px 8px; border-radius: 6px; white-space: nowrap; }
        .inv-pet { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .inv-pet-photo { width: 42px; height: 42px; border-radius: 12px; object-fit: cover; flex-shrink: 0; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1); }
        .inv-pet-fallback { display: inline-flex; align-items: center; justify-content: center; background: #eef2fb; font-size: 20px; }
        .inv-pet-text { display: flex; flex-direction: column; min-width: 0; }
        .inv-pet-name { font-weight: 800; color: #0f1b3d; font-size: 14px; }
        .inv-pet-meta { font-size: 12px; color: #8a94a3; margin-top: 1px; }
        .inv-muted { color: #4b5563; }
        .inv-species { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; padding: 4px 10px; border-radius: 8px; }
        .inv-species.is-dog { background: #fff4e5; color: #b45309; }
        .inv-species.is-cat { background: #f3edff; color: #6d28d9; }

        /* Status badge na may tuldok */
        .badge-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 9px; }
        .badge-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        .btn-view-pet {
            display: inline-flex; align-items: center; gap: 4px; height: 34px; padding: 0 12px 0 14px;
            border: 1.5px solid #dbe3f4; border-radius: 9px; color: #1e3a8a; background: #fff; font-family: inherit;
        }
        .btn-view-pet svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; transition: transform .2s ease; }
        .btn-view-pet:hover { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
        .btn-view-pet:hover svg { transform: translateX(2px); }

        .inv-empty { text-align: center; padding: 40px 10px !important; color: #9aa3ad; font-size: 14px; }

        @media (max-width: 600px) {
            .inv-search { max-width: none; }
            .inv-icon { width: 44px; height: 44px; border-radius: 13px; }
            .inv-icon svg { width: 23px; height: 23px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .inv-icon, .staff-dashboard-root .inv-cards .inv-card { transition: none; }
        }
    </style>
</head>
<body class="staff-dashboard-root">
<div class="app-container">
    <!-- Reusable Sidebar -->
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <!-- Top Navigation Header -->
        <div class="top-header">
            <div class="search-top"></div>
            <a href="/Caps2/StaffFile/edit_profile.php" class="profile-section" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>" 
                         alt="Profile" 
                         style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <div class="avatar" style="width: 38px; height: 38px; border-radius: 50%; background: #1f6b48; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                        <?php echo htmlspecialchars($user_initials); ?>
                    </div>
                <?php endif; ?>
                
                <div class="profile-name" style="font-weight: 700; color: #102a2a;">
                    <?php echo htmlspecialchars($username); ?>
                </div>
            </a>
        </div>

        <div class="dashboard-content page-content" style="padding: 0 12px 24px 12px;">
            <!-- Page Title -->
            <header class="sp-page-head">
                <span class="sp-page-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <g class="ic-solid">
                            <ellipse cx="6.5" cy="10" rx="1.9" ry="2.4" transform="rotate(-18 6.5 10)"/>
                            <ellipse cx="10" cy="6" rx="1.8" ry="2.4"/>
                            <ellipse cx="14" cy="6" rx="1.8" ry="2.4"/>
                            <ellipse cx="17.5" cy="10" rx="1.9" ry="2.4" transform="rotate(18 17.5 10)"/>
                        </g>
                        <path class="ic-fill" d="M12 11c3 0 5.5 2.6 5.5 5.2 0 2-1.8 2.8-5.5 2.8s-5.5-.8-5.5-2.8C6.5 13.6 9 11 12 11z"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Pet Inventory</h1>
                    <p>Manage and monitor all pets currently registered in the shelter</p>
                </div>
            </header>

            <?php if (!empty($_SESSION['success_message'])): ?>
                <div style="background:#e6f4ea;color:#1e7e34;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
                    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error_message'])): ?>
                <div style="background:#fce8e6;color:#c5221f;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Top Stat Cards -->
            <!-- Mga stat card: mula madilim (kaliwa) hanggang maliwanag (kanan) na asul -->
            <div class="cards-grid inv-cards">
                <a class="stat-card inv-card inv-c1 inv-card-link" href="#invTable" data-inv-filter="all" title="Show all pets">
                    <div class="stat-info">
                        <h1><?php echo str_pad($total_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Total Pets Registered</p>
                    </div>
                    <div class="inv-icon" aria-hidden="true">
                        <!-- Paw print -->
                        <svg viewBox="0 0 24 24">
                            <g class="ic-solid">
                                <ellipse cx="6.5" cy="10" rx="1.9" ry="2.4" transform="rotate(-18 6.5 10)"/>
                                <ellipse cx="10" cy="6" rx="1.8" ry="2.4"/>
                                <ellipse cx="14" cy="6" rx="1.8" ry="2.4"/>
                                <ellipse cx="17.5" cy="10" rx="1.9" ry="2.4" transform="rotate(18 17.5 10)"/>
                            </g>
                            <path class="ic-fill" d="M12 11c3 0 5.5 2.6 5.5 5.2 0 2-1.8 2.8-5.5 2.8s-5.5-.8-5.5-2.8C6.5 13.6 9 11 12 11z"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card inv-card inv-c3 inv-card-link" href="/Caps2/StaffFile/applications.php?status=pending" title="Open pending applications">
                    <div class="stat-info">
                        <h1><?php echo str_pad($pending_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Pending Applications</p>
                    </div>
                    <div class="inv-icon" aria-hidden="true">
                        <!-- Clipboard na may orasan -->
                        <svg viewBox="0 0 24 24">
                            <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h4.5"/>
                            <path d="M15 4h2a2 2 0 0 1 2 2v4"/>
                            <rect class="ic-fill" x="9" y="2.5" width="6" height="3.5" rx="1"/>
                            <path d="M8.5 10h6M8.5 13.5h3.5"/>
                            <circle cx="17" cy="17" r="4"/>
                            <path d="M17 15.2V17l1.2 1"/>
                        </svg>
                    </div>
                </a>

                <a class="stat-card inv-card inv-c4 inv-card-link" href="/Caps2/StaffFile/medical_intake.php" title="Open Medical &amp; Intake">
                    <div class="stat-info">
                        <h1><?php echo str_pad($medical_pets, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Medical Care Under Treatment</p>
                    </div>
                    <div class="inv-icon" aria-hidden="true">
                        <!-- Stethoscope -->
                        <svg viewBox="0 0 24 24">
                            <path d="M6 3.5H5a1 1 0 0 0-1 1V9a5 5 0 0 0 10 0V4.5a1 1 0 0 0-1-1h-1"/>
                            <path d="M9 14v1.5a5 5 0 0 0 10 0V13"/>
                            <circle class="ic-fill" cx="19" cy="11" r="2"/>
                        </svg>
                    </div>
                </a>
            </div>

            <!-- Main Inventory Table Section -->
            <div class="inventory-panel" id="invTable">
                <div class="inventory-header">
                    <div class="inv-title">
                        <span class="inv-title-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                        </span>
                        <div>
                            <h3>Pet Inventory</h3>
                            <span class="inv-sub">Complete list of shelter pets</span>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <!-- Inline SVG ang icon dahil walang naka-load na icon
                             font sa page na ito -->
                        <a href="/Caps2/StaffFile/export_report.php?type=pets&range=month" class="btn-export-records">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v10m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                            Export Records
                        </a>
                        <button type="button" class="btn-add-pet" onclick="openAddPetModal()">+ Add New Pet</button>
                    </div>
                </div>

                <!-- Toolbar: search + filter ayon sa status (sa browser lang, walang reload) -->
                <div class="inv-toolbar">
                    <label class="inv-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></svg>
                        <input type="search" id="invSearch" placeholder="Search by name, breed, or Pet ID..." aria-label="Search pets" autocomplete="off">
                    </label>
                    <div class="inv-filters" role="group" aria-label="Filter by status">
                        <button type="button" class="inv-filter active" data-filter="all">All</button>
                        <?php foreach (['available' => 'Available', 'adopted' => 'Adopted', 'unavailable' => 'Unavailable'] as $fKey => $fLabel): ?>
                            <button type="button" class="inv-filter" data-filter="<?php echo $fKey; ?>"><?php echo $fLabel; ?> <span><?php echo $statusCounts[$fKey] ?? 0; ?></span></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="inv-table-wrap">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Pet ID</th>
                                <th>Pet</th>
                                <th>Species</th>
                                <th>Breed</th>
                                <th>Age</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="invBody">
                            <?php if (empty($pets)): ?>
                                <tr>
                                    <td colspan="7" class="inv-empty">No pets registered yet.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pets as $pet):
                                $status = strtolower($pet['status'] ?? 'available');
                                $badgeClass = 'badge-available';
                                if ($status === 'pending') $badgeClass = 'badge-pending';
                                if ($status === 'medical' || $status === 'treatment') $badgeClass = 'badge-medical';
                                if ($status === 'adopted') $badgeClass = 'badge-adopted';
                                if ($status === 'unavailable') $badgeClass = 'badge-unavailable';
                                $isCat = ($pet['species'] ?? '') === 'Cat';
                                $petCode = 'PET-' . str_pad((string) $pet['pet_id'], 4, '0', STR_PAD_LEFT);
                                $meta = implode(' · ', array_filter([$pet['gender'] ?? '', $pet['size'] ?? '']));
                                $searchText = mb_strtolower(implode(' ', [$pet['name'] ?? '', $pet['breed'] ?? '', $pet['species'] ?? '', $pet['pet_id'], $petCode]));
                            ?>
                                <tr class="inv-row" data-status="<?php echo htmlspecialchars($status); ?>" data-search="<?php echo htmlspecialchars($searchText); ?>">
                                    <td><span class="inv-id"><?php echo htmlspecialchars($petCode); ?></span></td>
                                    <td>
                                        <div class="inv-pet">
                                            <?php if (!empty($pet['photo_url'])): ?>
                                                <img class="inv-pet-photo" src="/Caps2/uploads/<?php echo htmlspecialchars($pet['photo_url']); ?>" alt="" loading="lazy">
                                            <?php else: ?>
                                                <span class="inv-pet-photo inv-pet-fallback" aria-hidden="true"><?php echo $isCat ? '🐱' : '🐶'; ?></span>
                                            <?php endif; ?>
                                            <div class="inv-pet-text">
                                                <span class="inv-pet-name"><?php echo htmlspecialchars($pet['name']); ?></span>
                                                <?php if ($meta !== ''): ?><span class="inv-pet-meta"><?php echo htmlspecialchars($meta); ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="inv-species <?php echo $isCat ? 'is-cat' : 'is-dog'; ?>"><?php echo htmlspecialchars($pet['species']); ?></span></td>
                                    <td class="inv-muted"><?php echo htmlspecialchars($pet['breed']); ?></td>
                                    <td class="inv-muted"><?php echo htmlspecialchars($pet['age']); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo $badgeClass; ?>"><?php echo ucfirst($status); ?></span>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" class="btn-view-pet" onclick="openViewPetModal('<?php echo htmlspecialchars($pet['pet_id'], ENT_QUOTES); ?>')">
                                            View
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!empty($pets)): ?>
                                <tr id="invNoMatch" hidden>
                                    <td colspan="7" class="inv-empty">No pets found matching your search.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Add Pet Modal -->
<div id="addPetModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:640px;">
        <div class="modal-header">
            <div class="modal-title">Add New Pet</div>
            <button type="button" class="btn btn-ghost" onclick="closeAddPetModal()" aria-label="Close">✕</button>
        </div>
        <form action="process_add_pet.php" method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="add-pet-grid">
                    <div class="form-group">
                        <label>Pet Name *</label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-group">
                        <label>Kind *</label>
                        <select name="kind" required>
                            <option value="">Select Kind</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Breed *</label>
                        <input type="text" name="breed" required>
                    </div>
                    <div class="form-group">
                        <label>Age Group *</label>
                        <select name="age_group" required>
                            <option value="">Select Age Group</option>
                            <option value="Puppy/Kitten">Puppy/Kitten</option>
                            <option value="Young Adult">Young Adult</option>
                            <option value="Adult">Adult</option>
                            <option value="Senior">Senior</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Size *</label>
                        <select name="size" required>
                            <option value="">Select Size</option>
                            <option value="Small">Small</option>
                            <option value="Medium">Medium</option>
                            <option value="Large">Large</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Energy Level *</label>
                        <select name="energy_level" required>
                            <option value="">Select Energy Level</option>
                            <option value="Low">Low</option>
                            <option value="Moderate">Moderate</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Health Condition *</label>
                        <select name="health_condition" required>
                            <option value="">Select Health Condition</option>
                            <option value="Healthy">Healthy</option>
                            <option value="Elderly">Elderly</option>
                            <option value="Partially Blind/Deaf">Partially Blind/Deaf</option>
                            <option value="Medical Condition">Medical Condition</option>
                            <option value="Recovering/Injured">Recovering/Injured</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Vaccination Status *</label>
                        <select name="vaccination_status" required>
                            <option value="">Select Vaccination Status</option>
                            <option value="Fully Vaccinated">Fully Vaccinated</option>
                            <option value="Partially Vaccinated">Partially Vaccinated</option>
                            <option value="Not Vaccinated">Not Vaccinated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Shedding Level *</label>
                        <select name="shedding_level" required>
                            <option value="">Select Shedding Level</option>
                            <option value="Low">Low (hypoallergenic / barely sheds)</option>
                            <option value="Moderate">Moderate</option>
                            <option value="High">High (very furry)</option>
                        </select>
                        <small style="display:block;margin-top:4px;color:#9aa6a3;font-size:11px;">
                            Used to protect adopters with fur allergies
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" required>
                            <option value="Available">Available</option>
                            <option value="Unavailable">Unavailable</option>
                            <option value="Adopted">Adopted</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Temperament *</label>
                        <input type="text" name="temperament" placeholder="e.g., Friendly, Playful, Calm" required>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Rescue Location</label>
                        <input type="text" name="rescue_location" placeholder="e.g., Brgy. Bagong Silang, Caloocan City">
                    </div>
                    <div class="form-group">
                        <label>Rescue Date</label>
                        <input type="date" name="rescue_date">
                    </div>
                    <div class="form-group">
                        <label>Rescue Time</label>
                        <input type="time" name="rescue_time">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Photo</label>
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small style="display:block;margin-top:4px;color:#9aa6a3;font-size:11px;">JPG, PNG, GIF, or WEBP, up to 5 MB.</small>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeAddPetModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Pet</button>
            </div>
        </form>
    </div>
</div>

<!-- View Pet Modal -->
<div id="viewPetModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:680px;">
        <div class="modal-header">
            <div class="modal-title">🐾 Pet Details</div>
            <button type="button" class="btn btn-ghost" onclick="closeViewPetModal()" aria-label="Close">✕</button>
        </div>
        <?php // Telepono lang: mga tab ng tatlong bahagi (i-swipe pakaliwa/pakanan ang laman) ?>
        <nav class="pv-tabs" aria-label="Pet details sections">
            <button type="button" class="pv-tab is-on" data-go="0">Info</button>
            <button type="button" class="pv-tab" data-go="1">Rescue</button>
            <button type="button" class="pv-tab" data-go="2">Video</button>
        </nav>
        <div class="modal-body pv-slides" id="pvSlides">
            <section class="pv-slide" aria-label="Info">
            <div class="pet-view-header">
                <div class="pet-view-photo-wrap">
                    <img id="view_photo" class="pet-view-photo" src="" alt="Pet Photo" style="display:none;">
                    <span id="view_photo_fallback" class="pet-view-photo-fallback">🐾</span>
                </div>
                <div class="pet-view-heading">
                    <div id="view_name" class="pet-view-name"></div>
                    <div><span id="view_status" class="badge-status"></span></div>
                </div>
            </div>

            <div class="pet-view-section-title">Pet Information</div>
            <div class="pet-view-info-grid">
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">🐕</div>
                    <div><div class="pet-view-tile-label">Species</div><div id="view_species" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">🧬</div>
                    <div><div class="pet-view-tile-label">Breed</div><div id="view_breed" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">🎂</div>
                    <div><div class="pet-view-tile-label">Age Group</div><div id="view_age" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">⚧</div>
                    <div><div class="pet-view-tile-label">Gender</div><div id="view_gender" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">📏</div>
                    <div><div class="pet-view-tile-label">Size</div><div id="view_size" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">⚡</div>
                    <div><div class="pet-view-tile-label">Energy Level</div><div id="view_energy" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">💊</div>
                    <div><div class="pet-view-tile-label">Health Condition</div><div id="view_health" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">💉</div>
                    <div><div class="pet-view-tile-label">Vaccination</div><div id="view_vaccination" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">🧴</div>
                    <div><div class="pet-view-tile-label">Shedding</div><div id="view_shedding" class="pet-view-tile-value">-</div></div>
                </div>
            </div>

            </section>

            <section class="pv-slide" aria-label="Rescue">
            <div class="pet-view-section-title">Rescue History</div>
            <div class="pet-view-info-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">📍</div>
                    <div><div class="pet-view-tile-label">Rescued At</div><div id="view_rescue_location" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">📅</div>
                    <div><div class="pet-view-tile-label">Rescue Date</div><div id="view_rescue_date" class="pet-view-tile-value">-</div></div>
                </div>
                <div class="pet-view-tile">
                    <div class="pet-view-tile-icon">🕒</div>
                    <div><div class="pet-view-tile-label">Rescue Time</div><div id="view_rescue_time" class="pet-view-tile-value">-</div></div>
                </div>
            </div>

            <div class="pet-view-section-title">Temperament</div>
            <div id="view_temperament" class="pet-view-tags"></div>

            <div class="pet-view-section-title">Description</div>
            <div id="view_description" class="pet-view-description">-</div>
            </section>

            <section class="pv-slide" aria-label="Video">
            <?php // Rescue Story Video: makikita ng adopter sa "Why this pet?" ?>
            <div class="pet-view-section-title">Rescue Story Video</div>
            <div class="pet-video-box">
                <video id="view_video" class="pet-video-player" controls playsinline preload="metadata" hidden></video>
                <p id="view_video_empty" class="pet-video-empty"><i class="fa-solid fa-film"></i> No rescue story video yet. Adopters see this video in "Why this pet?".</p>
                <div class="pet-video-actions">
                    <form method="POST" action="process_pet_video.php" enctype="multipart/form-data" class="pet-video-form">
                        <input type="hidden" name="pet_id" id="video_pet_id">
                        <input type="hidden" name="action" value="upload">
                        <label class="pet-video-pick">
                            <input type="file" name="rescue_video" accept="video/mp4,video/webm,video/quicktime" onchange="if (this.files.length) this.form.submit()">
                            <i class="fa-solid fa-upload"></i> <span id="view_video_btn">Upload Video</span>
                        </label>
                    </form>
                    <form method="POST" action="process_pet_video.php" id="videoRemoveForm" hidden
                          data-confirm="Remove the rescue story video of this pet?" data-confirm-title="Remove video?" data-confirm-ok="Remove" data-confirm-tone="red" data-confirm-icon="fa-trash-can">
                        <input type="hidden" name="pet_id" id="video_remove_pet_id">
                        <input type="hidden" name="action" value="remove">
                        <button type="submit" class="pet-video-remove"><i class="fa-solid fa-trash-can"></i> Remove</button>
                    </form>
                </div>
                <small class="pet-video-hint">MP4, WEBM, or MOV, up to 10 MB (about 30 seconds).</small>
            </div>
            <style>
                .pet-video-box { display: flex; flex-direction: column; gap: 8px; margin-bottom: 6px; }
                .pet-video-player { width: 100%; max-height: 300px; border-radius: 12px; background: #000; }
                .pet-video-empty { margin: 0; padding: 14px; border: 1px dashed #c9d4ee; border-radius: 12px; color: #64748b; font-size: 13px; text-align: center; }
                .pet-video-actions { display: flex; gap: 8px; flex-wrap: wrap; }
                .pet-video-form { margin: 0; }
                .pet-video-pick { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 9px; background: #1e3a8a; color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; }
                .pet-video-pick input { display: none; }
                .pet-video-remove { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 9px; border: 1px solid #f3c4c4; background: #fff; color: #b91c1c; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit; }
                .pet-video-hint { color: #8a94a6; font-size: 11.5px; }
            </style>
            </section>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="closeViewPetModal()">Close</button>
        </div>
    </div>
</div>

<script>
    const petsData = <?php echo json_encode($pets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    // "2026-09-05" -> "Sep 5, 2026 (Saturday)" para makita agad kung anong araw na-rescue
    function formatRescueDate(value) {
        if (!value) return 'Not recorded';
        const d = new Date(value + 'T00:00:00');
        if (isNaN(d)) return value;
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
            + ' (' + d.toLocaleDateString('en-US', { weekday: 'long' }) + ')';
    }

    // "14:30:00" -> "2:30 PM"
    function formatRescueTime(value) {
        if (!value) return 'Not recorded';
        const parts = value.split(':');
        if (parts.length < 2) return value;
        let hour = parseInt(parts[0], 10);
        const minute = parts[1];
        const suffix = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12 || 12;
        return hour + ':' + minute + ' ' + suffix;
    }

    function openViewPetModal(petId) {
        const pet = petsData.find(p => String(p.pet_id) === String(petId));
        if (!pet) return;

        document.getElementById('view_name').textContent = pet.name || '-';
        document.getElementById('view_species').textContent = pet.species || '-';
        document.getElementById('view_breed').textContent = pet.breed || '-';
        document.getElementById('view_age').textContent = pet.age || '-';
        document.getElementById('view_gender').textContent = pet.gender || '-';
        document.getElementById('view_size').textContent = pet.size || '-';
        document.getElementById('view_energy').textContent = pet.energy_level || '-';
        document.getElementById('view_health').textContent = pet.health_condition || '-';
        document.getElementById('view_vaccination').textContent = pet.vaccination_status || '-';
        document.getElementById('view_shedding').textContent = pet.shedding_level || 'Not set';
        document.getElementById('view_description').textContent = pet.description || 'No description provided.';

        document.getElementById('view_rescue_location').textContent = pet.rescue_location || 'Not recorded';
        document.getElementById('view_rescue_date').textContent = formatRescueDate(pet.rescue_date);
        document.getElementById('view_rescue_time').textContent = formatRescueTime(pet.rescue_time);
        // Rescue Story Video
        const vid = document.getElementById('view_video');
        const hasVideo = !!pet.rescue_video;
        vid.pause();
        if (hasVideo) { vid.src = '../uploads/videos/' + encodeURIComponent(pet.rescue_video); } else { vid.removeAttribute('src'); vid.load(); }
        vid.hidden = !hasVideo;
        document.getElementById('view_video_empty').hidden = hasVideo;
        document.getElementById('view_video_btn').textContent = hasVideo ? 'Replace Video' : 'Upload Video';
        document.getElementById('video_pet_id').value = pet.pet_id;
        document.getElementById('video_remove_pet_id').value = pet.pet_id;
        document.getElementById('videoRemoveForm').hidden = !hasVideo;

        const tempEl = document.getElementById('view_temperament');
        tempEl.innerHTML = '';
        const traits = (pet.temperament || '').split(',').map(t => t.trim()).filter(Boolean);
        if (traits.length) {
            traits.forEach(trait => {
                const pill = document.createElement('span');
                pill.className = 'pet-view-tag';
                pill.textContent = trait;
                tempEl.appendChild(pill);
            });
        } else {
            tempEl.innerHTML = '<span class="pet-view-tag">-</span>';
        }

        const status = (pet.status || 'available').toLowerCase();
        const statusEl = document.getElementById('view_status');
        statusEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        statusEl.className = 'badge-status badge-' + (['pending', 'medical', 'treatment', 'adopted', 'unavailable'].includes(status) ? (status === 'treatment' ? 'medical' : status) : 'available');

        const photoImg = document.getElementById('view_photo');
        const photoFallback = document.getElementById('view_photo_fallback');
        if (pet.photo_url) {
            photoImg.src = '/Caps2/uploads/' + pet.photo_url;
            photoImg.style.display = 'block';
            photoFallback.style.display = 'none';
        } else {
            photoImg.style.display = 'none';
            photoFallback.style.display = 'flex';
            photoFallback.textContent = (pet.species === 'Cat') ? '🐱' : '🐶';
        }

        const modal = document.getElementById('viewPetModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        // Telepono: laging magsimula sa unang bahagi (Info)
        const slides = document.getElementById('pvSlides');
        slides.scrollLeft = 0;
        slides.querySelectorAll('.pv-slide').forEach(function (s) { s.scrollTop = 0; });
        setPvTab(0);
    }

    // ===== View Pet sa telepono: Info / Rescue / Video na i-swipe pakaliwa/pakanan =====
    function setPvTab(i) {
        document.querySelectorAll('.pv-tab').forEach(function (t) {
            t.classList.toggle('is-on', parseInt(t.dataset.go, 10) === i);
        });
    }
    (function () {
        const slides = document.getElementById('pvSlides');
        // Pindot sa tab: dumulas papunta sa bahaging iyon
        document.querySelectorAll('.pv-tab').forEach(function (t) {
            t.addEventListener('click', function () {
                slides.scrollTo({ left: slides.clientWidth * parseInt(t.dataset.go, 10), behavior: 'smooth' });
            });
        });
        // Habang nag-i-swipe: sumusunod ang tab sa bahaging nakikita
        let raf = null;
        slides.addEventListener('scroll', function () {
            if (raf) return;
            raf = requestAnimationFrame(function () {
                raf = null;
                if (slides.clientWidth) setPvTab(Math.round(slides.scrollLeft / slides.clientWidth));
            });
        }, { passive: true });
    })();

    function closeViewPetModal() {
        document.getElementById('view_video').pause();
        const modal = document.getElementById('viewPetModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    function openAddPetModal() {
        const modal = document.getElementById('addPetModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeAddPetModal() {
        const modal = document.getElementById('addPetModal');
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAddPetModal();
            closeViewPetModal();
        }
    });

    // Pag-click sa labas ng modal card (sa madilim na bahagi) ay pagsasara rin,
    // para hindi na kailangang hanapin ang maliit na ✕ button.
    ['addPetModal', 'viewPetModal'].forEach(function (id) {
        const backdrop = document.getElementById(id);
        if (!backdrop) return;
        backdrop.addEventListener('mousedown', function (e) {
            if (e.target === backdrop) {
                backdrop.classList.remove('show');
                backdrop.setAttribute('aria-hidden', 'true');
            }
        });
    });

    // Search at filter ng table (sa browser lang, walang reload)
    (function () {
        const search = document.getElementById('invSearch');
        const rows = Array.from(document.querySelectorAll('#invBody .inv-row'));
        const noMatch = document.getElementById('invNoMatch');
        const filters = Array.from(document.querySelectorAll('.inv-filter'));
        let filter = 'all';

        function apply() {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            rows.forEach(function (r) {
                const ok = (filter === 'all' || r.dataset.status === filter) && (!q || r.dataset.search.indexOf(q) !== -1);
                r.hidden = !ok;
                if (ok) shown++;
            });
            if (noMatch) noMatch.hidden = shown > 0 || !rows.length;
        }

        search.addEventListener('input', apply);
        filters.forEach(function (b) {
            b.addEventListener('click', function () {
                filter = b.dataset.filter;
                filters.forEach(function (x) { x.classList.toggle('active', x === b); });
                apply();
            });
        });

        // Card na "Total Pets" / "Available": salain ang table, saka dumausdos papunta roon
        const panel = document.getElementById('invTable');
        document.querySelectorAll('.inv-card-link[data-inv-filter]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                search.value = '';
                const btn = filters.find(function (b) { return b.dataset.filter === card.dataset.invFilter; });
                if (btn) btn.click();
                const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
                panel.classList.remove('inv-flash');
                void panel.offsetWidth; // i-restart ang animation
                panel.classList.add('inv-flash');
            });
        });
    })();

    // Galing sa search bar (?view=<pet_id>): buksan agad ang detalye ng pet
    (function () {
        const viewId = new URLSearchParams(window.location.search).get('view');
        if (!viewId) return;
        try { openViewPetModal(viewId); } catch (e) { /* wala na ang pet sa listahan */ }
    })();
</script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>