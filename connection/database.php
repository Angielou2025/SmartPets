<?php

// Oras ng Pilipinas sa buong system. Kung wala ito, ang default ng server ang
// gamit (Europe/Berlin sa XAMPP, oras ng US sa InfinityFree), kaya mali ang
// "ilang minuto pa bago ang interview", ang pagsara ng lumipas na slot, atbp.
date_default_timezone_set('Asia/Manila');

// ---- Database settings ----
// Sa XAMPP (localhost), ito ang ginagamit. Kapag naka-upload na sa hosting
// (InfinityFree, atbp.), ang laman ng connection/db_config.php ang gagamitin
// - kaya parehong code ang tumatakbo sa dalawa, walang papalitan tuwing upload.
$host = "localhost";
$dbname = "a_smart_pet";
$username = "root";
$password = "";
$charset = "utf8mb4";

$spHttpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$spIsLocal = PHP_SAPI === 'cli'
    || $spHttpHost === ''
    || strpos($spHttpHost, 'localhost') === 0
    || strpos($spHttpHost, '127.0.0.1') === 0;

if (!$spIsLocal) {
    if (!is_file(__DIR__ . '/db_config.php')) {
        http_response_code(500);
        exit('Database settings for this server are missing. Fill in connection/db_config.php with the MySQL details from your hosting panel.');
    }
    require __DIR__ . '/db_config.php';
    if ($password === 'PALITAN_ITO' || strpos($host, 'XXX') !== false) {
        http_response_code(500);
        exit('Database settings are not filled in yet. Open connection/db_config.php and enter the MySQL details from your hosting panel.');
    }
}

// ---- Bantay sa role ng bawat folder ----
// Lahat ng page sa StaffFile, AdminFile at Adopter_File ay dumadaan dito, kaya
// iisang lugar lang ang nagsisigurong tamang role ang nakakapasok. Kung wala ito,
// kapag nag-Back ang adopter sa staff page na nabuksan dati sa parehong browser,
// lalabas ang staff page gamit ang session ng adopter.
sp_guard_role_folder();

function sp_guard_role_folder(): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $folder = basename(dirname($script));
    $page = basename($script);

    // Aling role ang pwede sa bawat folder
    $allowed = [
        'StaffFile'    => ['Staff', 'Admin'], // ginagamit din ng Admin ang ilang staff page
        'AdminFile'    => ['Admin'],
        'Adopter_File' => ['Adopter'],
    ];
    if (!isset($allowed[$folder])) {
        return;
    }
    // Mga page na pwede sa iba pang role
    if ($folder === 'Adopter_File' && $page === 'logout.php') {
        return;
    }
    if ($folder === 'Adopter_File' && $page === 'certificate.php') {
        $allowed[$folder] = ['Adopter', 'Staff', 'Admin']; // dito nagpi-print ang staff
    }
    if ($folder === 'Adopter_File' && $page === 'profile_setup.php') {
        $allowed[$folder] = ['Adopter', 'Staff', 'Admin']; // Account Settings modal ng tatlong role
    }

    // Huwag i-cache ang mga page na ito: kapag pinindot ang Back, babalik sa server
    // at muling susuriin dito - hindi ipapakita ang lumang kopya ng ibang user
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $role = (string) ($_SESSION['role'] ?? '');
    if (!isset($_SESSION['user_id']) || $role === '') {
        $target = '/Login_Page/login.php';
    } elseif (in_array($role, $allowed[$folder], true)) {
        return;
    } elseif ($role === 'Admin') {
        $target = '/AdminFile/index.php';
    } elseif ($role === 'Staff') {
        $target = '/StaffFile/staff_dashboard.php';
    } else {
        $target = '/Adopter_File/search_pets.php';
    }

    // Ugat ng system (hal. /Caps2 sa localhost, o blangko kung nasa ugat ng domain)
    $base = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')))), '/');

    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'Not allowed for your account.']));
    }

    header('Location: ' . $base . $target);
    exit();
}

/**
 * Magdagdag ng column kung wala pa. Gumagana sa MySQL at MariaDB (ang
 * "ADD COLUMN IF NOT EXISTS" ay sa MariaDB lang, kaya iniiwasan ito para sa
 * hosting na MySQL ang gamit).
 */
function sp_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute([$table, $column]);
    if ((int) $check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

// SCHEMA VERSION: ang paggawa/pag-ayos ng mga table sa ibaba ay tumatakbo lang
// kapag nagbago ang numerong ito - hindi na sa bawat pagbukas ng page. Mahalaga
// ito sa libreng hosting (InfinityFree) na may limit sa CPU at database.
// >>> Kapag nagdagdag o nagbago ka ng table/column sa ibaba, PALITAN ang
//     numerong ito para tumakbo ulit ang setup. <<<
$spSchemaVersion = '2026-10-17.2';
$spSchemaFlag = __DIR__ . '/.schema_version';
$spNeedsSchema = @file_get_contents($spSchemaFlag) !== $spSchemaVersion;

try {
    // Sa localhost, ginagawa nito ang database kung wala pa. Sa online hosting
    // (cPanel, atbp.), bawal ito sa database user - doon ay ginagawa mo na ang
    // database sa hosting panel, kaya nilalampasan na lang kapag tinanggihan.
    if ($spNeedsSchema) {
        try {
            $setupPdo = new PDO("mysql:host=$host;charset=$charset", $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $setupPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            // Walang pahintulot gumawa ng database - ayos lang, ginawa na ito sa hosting panel
        }
    }

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=$charset",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Parehong oras ng Pilipinas sa MySQL, para tugma ang NOW()/CURDATE() sa PHP
    $pdo->exec("SET time_zone = '+08:00'");

    if ($spNeedsSchema) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS adopter_preferences (
        preference_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        living_space ENUM('Apartment', 'Condo', 'House with Small Space', 'House with Yard') NOT NULL,
        time_commitment ENUM('Less than 2 Hours', '2-4 Hours', '5-8 Hours', 'Almost Whole Day') NOT NULL,
        activity_level ENUM('Low/Chill', 'Moderate', 'Active') NOT NULL,
        pet_experience ENUM('Beginner', 'Intermediate', 'Experienced') NOT NULL,
        allergies ENUM('None', 'Fur Allergy', 'Sensitive to Shedding') NOT NULL,
        household_composition ENUM('Lives Alone', 'With Children', 'With Elderly', 'With Other Pets') NOT NULL,
        noise_tolerance ENUM('Low', 'Moderate', 'High') NOT NULL,
        maintenance_level ENUM('Low Maintenance', 'Moderate', 'High Maintenance') NOT NULL,
        adoption_intent ENUM('Companion', 'Guard Pet', 'Emotional Support', 'Family Pet') NOT NULL,
        pref_kind ENUM('Dog', 'Cat', 'Any') NOT NULL DEFAULT 'Any',
        pref_breed VARCHAR(100) DEFAULT 'Any',
        pref_temperament VARCHAR(100) DEFAULT 'Any',
        pref_energy_level ENUM('Low', 'Moderate', 'High', 'Any') NOT NULL DEFAULT 'Any',
        pref_size ENUM('Small', 'Medium', 'Large', 'Any') NOT NULL DEFAULT 'Any',
        pref_age_group ENUM('Puppy/Kitten', 'Young Adult', 'Adult', 'Senior', 'Any') NOT NULL DEFAULT 'Any',
        pref_gender ENUM('Male', 'Female', 'Any') NOT NULL DEFAULT 'Any',
        pref_health_condition ENUM('Healthy', 'Any') NOT NULL DEFAULT 'Healthy',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Adoption applications submitted by adopters for a specific pet
    $pdo->exec("CREATE TABLE IF NOT EXISTS adoption_applications (
        application_id INT AUTO_INCREMENT PRIMARY KEY,
        adopter_id INT NOT NULL,
        pet_id INT NOT NULL,

        full_name VARCHAR(100) NOT NULL,
        address TEXT NOT NULL,
        phone_number VARCHAR(20) NOT NULL,
        email_address VARCHAR(100) NOT NULL,
        birthdate DATE NOT NULL,
        occupation VARCHAR(100) NOT NULL,
        social_media_link VARCHAR(255),
        civil_status VARCHAR(20) NOT NULL,

        guardian_name VARCHAR(100) DEFAULT NULL,
        guardian_relationship VARCHAR(50) DEFAULT NULL,

        q_responsible_for_care TEXT NOT NULL,
        q_financial_responsibility TEXT NOT NULL,
        q_emergency_caregiver TEXT NOT NULL,
        q_hours_left_alone INT NOT NULL,
        q_introduction_strategy TEXT NOT NULL,
        q_family_support ENUM('Yes', 'No') NOT NULL,
        q_current_pets ENUM('Yes', 'No') NOT NULL,
        q_past_pet_ownership ENUM('Yes', 'No') NOT NULL,

        photo_front_house VARCHAR(255) NOT NULL,
        photo_street VARCHAR(255) NULL,
        photo_living_room VARCHAR(255) NOT NULL,
        photo_dining_area VARCHAR(255) NULL,
        photo_kitchen VARCHAR(255) NULL,
        photo_bedrooms VARCHAR(255),
        photo_windows VARCHAR(255),
        photo_backyard VARCHAR(255),
        valid_id_url VARCHAR(255) NOT NULL,
        barangay_certificate_url VARCHAR(255) NULL,

        preferred_interview_date DATE NOT NULL,
        preferred_interview_time TIME NOT NULL,
        meet_and_greet_availability TEXT NOT NULL,

        application_status ENUM('Pending', 'Under Review', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
        interview_result TEXT DEFAULT NULL,
        reviewed_by_staff_id INT DEFAULT NULL,
        rejection_reason TEXT DEFAULT NULL,

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (adopter_id) REFERENCES users(user_id) ON DELETE RESTRICT,
        FOREIGN KEY (pet_id) REFERENCES pet_inventory(pet_id) ON DELETE RESTRICT,
        FOREIGN KEY (reviewed_by_staff_id) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Hindi na kailangang i-type ng adopter ang sarili nilang availability ngayong may appointment_slots na
    // silang pipiliang totoong available na oras ng staff, kaya ginawang optional ang column na ito.
    $pdo->exec("ALTER TABLE adoption_applications MODIFY meet_and_greet_availability TEXT NULL");

    // Barangay Certificate - patunay na taga-Caloocan ang umaadopt, idinagdag sa existing na table kung wala pa
    sp_add_column($pdo, 'adoption_applications', 'barangay_certificate_url', "VARCHAR(255) NULL AFTER valid_id_url");
    // Residency (Barangay) ID: harap = valid_id_url, likod = valid_id_back_url
    sp_add_column($pdo, 'adoption_applications', 'valid_id_back_url', "VARCHAR(255) NULL AFTER valid_id_url");

    // Hiwa-hiwalay na pangalan at address (taga-Caloocan lang). Binubuo pa rin
    // ang full_name at address mula rito para sa ibang page na gumagamit nito.
    sp_add_column($pdo, 'adoption_applications', 'last_name', "VARCHAR(100) NULL AFTER full_name");
    sp_add_column($pdo, 'adoption_applications', 'first_name', "VARCHAR(100) NULL AFTER last_name");
    sp_add_column($pdo, 'adoption_applications', 'middle_name', "VARCHAR(100) NULL AFTER first_name");
    sp_add_column($pdo, 'adoption_applications', 'suffix', "VARCHAR(10) NULL AFTER middle_name");
    sp_add_column($pdo, 'adoption_applications', 'house_no', "VARCHAR(100) NULL AFTER address");
    sp_add_column($pdo, 'adoption_applications', 'street', "VARCHAR(150) NULL AFTER house_no");
    sp_add_column($pdo, 'adoption_applications', 'subdivision', "VARCHAR(150) NULL AFTER street");
    sp_add_column($pdo, 'adoption_applications', 'barangay', "VARCHAR(50) NULL AFTER subdivision");

    // Match % ng application, naka-freeze sa oras ng pag-submit (connection/match_scoring.php
    // sp_application_match). Hindi na nagbabago kapag binago ng adopter ang preferences.
    sp_add_column($pdo, 'adoption_applications', 'match_score', "TINYINT UNSIGNED NULL AFTER pet_id");
    sp_add_column($pdo, 'adoption_applications', 'match_breakdown', "MEDIUMTEXT NULL AFTER match_score");

    // Online interview: ang staff ang naglalagay ng meeting link (Google Meet, Zoom, atbp.).
    // Awtomatikong ipinapadala ito sa email ng adopter bago dumating ang napili niyang oras;
    // ang meeting_link_sent_at ang tanda na naipadala na, para hindi maulit.
    sp_add_column($pdo, 'adoption_applications', 'meeting_link', "VARCHAR(500) NULL AFTER preferred_interview_time");
    sp_add_column($pdo, 'adoption_applications', 'meeting_link_sent_at', "TIMESTAMP NULL AFTER meeting_link");
    // Ang staff na naglagay ng link - siya ang dadalhin ng system sa meeting pagdating ng oras
    sp_add_column($pdo, 'adoption_applications', 'meeting_link_set_by', "INT NULL AFTER meeting_link_sent_at");

    // Hindi nakadalo sa interview: ang staff ang nagmamarka (interview_missed_at). Habang
    // naka-set ito, makakapili ang adopter ng bagong slot sa Schedule page at kailangan
    // niyang sabihin kung bakit hindi siya nakadalo (missed_reason).
    sp_add_column($pdo, 'adoption_applications', 'interview_missed_at', "TIMESTAMP NULL AFTER meeting_link_sent_at");
    sp_add_column($pdo, 'adoption_applications', 'interview_missed_by', "INT NULL AFTER interview_missed_at");
    sp_add_column($pdo, 'adoption_applications', 'missed_reason', "TEXT NULL AFTER interview_missed_by");
    sp_add_column($pdo, 'adoption_applications', 'reschedule_count', "INT NOT NULL DEFAULT 0 AFTER missed_reason");
    // Kapag hindi nakadalo, kailangan munang kontakin ng adopter ang staff. Ang
    // staff ang nagpapadala ng rebooking link; doon lang siya makakapili ng bagong oras.
    sp_add_column($pdo, 'adoption_applications', 'rebook_sent_at', "TIMESTAMP NULL AFTER reschedule_count");

    // Interview request: ang adopter ang pumipili ng araw at oras, ang staff ang
    // nag-a-Accept o Decline (tingnan ang connection/interview_schedule.php).
    // NULL = lumang application na nag-book ng slot (tanggap na).
    sp_add_column($pdo, 'adoption_applications', 'schedule_status', "VARCHAR(20) NULL AFTER preferred_interview_time");
    sp_add_column($pdo, 'adoption_applications', 'schedule_decline_reason', "TEXT NULL AFTER schedule_status");
    sp_add_column($pdo, 'adoption_applications', 'schedule_decided_by', "INT NULL AFTER schedule_decline_reason");
    sp_add_column($pdo, 'adoption_applications', 'schedule_decided_at', "TIMESTAMP NULL AFTER schedule_decided_by");

    // Mga araw (1 = Lunes ... 5 = Biyernes) at oras na bukas ang City Hall para sa
    // interview - itinatakda ng staff sa Interview Availability. Iisang row lang.
    $pdo->exec("CREATE TABLE IF NOT EXISTS interview_settings (
        setting_id TINYINT PRIMARY KEY,
        work_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
        open_time TIME NOT NULL DEFAULT '08:00:00',
        close_time TIME NOT NULL DEFAULT '17:00:00',
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO interview_settings (setting_id) VALUES (1)");
    // Break ng staff araw-araw: simula at haba (30 o 60 minuto). Hindi ito mapipili ng adopter.
    sp_add_column($pdo, 'interview_settings', 'break_start', "TIME NULL AFTER close_time");
    sp_add_column($pdo, 'interview_settings', 'break_minutes', "TINYINT NULL AFTER break_start");
    // Iisang meeting link na gamit sa lahat ng interview (itinakda ng staff sa review page)
    sp_add_column($pdo, 'interview_settings', 'default_meeting_link', "VARCHAR(500) NULL");

    // Ganap sa City Hall (hal. may event, walang interview): pinipili ng staff ang
    // mismong petsa sa calendar. Ang mga holiday sa Pilipinas ay kusang kinukuwenta
    // (connection/interview_schedule.php), hindi nakatago rito.
    $pdo->exec("CREATE TABLE IF NOT EXISTS city_hall_closures (
        closure_id INT AUTO_INCREMENT PRIMARY KEY,
        closure_date DATE NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_closure_date (closure_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Lumang bersyon (buwan at araw lang): inililipat sa susunod na petsa nito, saka binubura
    if ($pdo->query("SHOW TABLES LIKE 'interview_special_days'")->fetchColumn()) {
        $oldRows = $pdo->query("SELECT month, day, name, created_by FROM interview_special_days")->fetchAll(PDO::FETCH_ASSOC);
        $moveStmt = $pdo->prepare("INSERT IGNORE INTO city_hall_closures (closure_date, name, created_by) VALUES (?, ?, ?)");
        foreach ($oldRows as $old) {
            for ($y = (int) date('Y'); $y <= (int) date('Y') + 8; $y++) {
                if (checkdate((int) $old['month'], (int) $old['day'], $y)) {
                    $next = sprintf('%04d-%02d-%02d', $y, $old['month'], $old['day']);
                    if ($next >= date('Y-m-d')) {
                        $moveStmt->execute([$next, $old['name'], $old['created_by']]);
                        break;
                    }
                }
            }
        }
        $pdo->exec("DROP TABLE interview_special_days");
    }

    // Pet Meet-up: pagkatapos ng interview, kapag okay ang adopter, nagpapadala
    // ang staff ng mga araw at oras na puwede siyang pumunta sa City Hall para
    // makita ang pet nang personal. Pipili ang adopter ng isa sa mga ito.
    sp_add_column($pdo, 'adoption_applications', 'meetup_sent_at', "TIMESTAMP NULL AFTER rebook_sent_at");
    sp_add_column($pdo, 'adoption_applications', 'meetup_sent_by', "INT NULL AFTER meetup_sent_at");
    sp_add_column($pdo, 'adoption_applications', 'meetup_note', "VARCHAR(500) NULL AFTER meetup_sent_by");
    sp_add_column($pdo, 'adoption_applications', 'meetup_option_id', "INT NULL AFTER meetup_note");
    sp_add_column($pdo, 'adoption_applications', 'meetup_chosen_at', "TIMESTAMP NULL AFTER meetup_option_id");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pet_meetup_options (
        option_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        meet_date DATE NOT NULL,
        meet_time TIME NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
        INDEX idx_app (application_id, meet_date, meet_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Bawat araw ng meet-up ay may oras ng bukas (meet_time) at sara (meet_end_time);
    // puwedeng pumunta ang adopter anumang oras sa pagitan nito
    sp_add_column($pdo, 'pet_meetup_options', 'meet_end_time', "TIME NULL AFTER meet_time");

    // Interview Summary Report: pagkatapos ng interview, isinusulat ng staff ang buod
    // ng napag-usapan nila ng adopter (pangalan, edad, tirahan, karanasan sa alaga,
    // atbp.). Isa bawat application; nakikita ito ng adopter (Adopter_File/interview_summary.php).
    $pdo->exec("CREATE TABLE IF NOT EXISTS interview_summaries (
        summary_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        staff_id INT NULL,
        adopter_name VARCHAR(100) NOT NULL,
        adopter_age TINYINT UNSIGNED NULL,
        civil_status VARCHAR(30) NULL,
        occupation VARCHAR(100) NULL,
        contact_number VARCHAR(30) NULL,
        address TEXT NULL,
        household_members TEXT NULL,
        residence_type VARCHAR(50) NULL,
        landlord_allows_pets VARCHAR(20) NULL,
        other_pets TEXT NULL,
        pet_experience TEXT NULL,
        reason_for_adopting TEXT NULL,
        care_plan TEXT NULL,
        hours_alone TINYINT UNSIGNED NULL,
        monthly_budget VARCHAR(100) NULL,
        adopter_questions TEXT NULL,
        discussion_notes TEXT NOT NULL,
        recommendation VARCHAR(40) NOT NULL,
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_summary_app (application_id),
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (staff_id) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Dating VARCHAR(10) - napuputol ang "Not applicable" (14 letra)
    $pdo->exec("ALTER TABLE interview_summaries MODIFY landlord_allows_pets VARCHAR(20) NULL");
    // Rescue Story Video ng bawat pet (uploads/videos/) - ipinapakita sa "Why this pet?"
    sp_add_column($pdo, 'pet_inventory', 'rescue_video', "VARCHAR(255) NULL AFTER photo_url");

    // Printed name at pirma: ng staff (interviewer) at ng adopter (pagkilala sa summary).
    // Ang pirma ay PNG na walang background sa uploads/signatures/
    sp_add_column($pdo, 'interview_summaries', 'staff_printed_name', "VARCHAR(150) NULL AFTER remarks");
    sp_add_column($pdo, 'interview_summaries', 'staff_signature', "VARCHAR(255) NULL AFTER staff_printed_name");
    sp_add_column($pdo, 'interview_summaries', 'staff_signed_at', "TIMESTAMP NULL DEFAULT NULL AFTER staff_signature");
    sp_add_column($pdo, 'interview_summaries', 'adopter_printed_name', "VARCHAR(150) NULL AFTER staff_signed_at");
    sp_add_column($pdo, 'interview_summaries', 'adopter_signature', "VARCHAR(255) NULL AFTER adopter_printed_name");
    sp_add_column($pdo, 'interview_summaries', 'adopter_signed_at', "TIMESTAMP NULL DEFAULT NULL AFTER adopter_signature");

    // Opisyal na hard copy ng certificate: ipi-print ng staff, pipirmahan ng City
    // Veterinarian at tatatakan (dry seal/stamp), saka iaabot sa adopter sa araw ng
    // pagkuha ng hayop. cert_ready_at = napirmahan na at handa nang kunin;
    // cert_released_at = naibigay na ang hard copy.
    sp_add_column($pdo, 'adoption_applications', 'cert_ready_at', "TIMESTAMP NULL AFTER reschedule_count");
    sp_add_column($pdo, 'adoption_applications', 'cert_ready_by', "INT NULL AFTER cert_ready_at");
    sp_add_column($pdo, 'adoption_applications', 'cert_released_at', "TIMESTAMP NULL AFTER cert_ready_by");
    sp_add_column($pdo, 'adoption_applications', 'cert_released_by', "INT NULL AFTER cert_released_at");
    // Kinumpirma ng adopter ("I Received" sa docs.php) na natanggap niya ang hard copy
    sp_add_column($pdo, 'adoption_applications', 'cert_adopter_received_at', "TIMESTAMP NULL AFTER cert_released_by");

    // Wika ng adopter sa buong site ('en' = English, 'tl' = Tagalog). Pinipili
    // sa Account Settings; ginagamit ng connection/i18n.php.
    sp_add_column($pdo, 'users', 'language', "VARCHAR(2) NOT NULL DEFAULT 'en'");

    // Hindi na ipinapadala ang abisong "Meet your pet at the shelter" (pagpili ng araw).
    // Ang matatanggap lang ng adopter ay ang "Pet meet-up confirmed" pagkapili niya.
    try {
        $pdo->exec("DELETE FROM notifications WHERE title = 'Meet your pet at the shelter' AND ref_key LIKE 'meetup\\_%' AND ref_key NOT LIKE 'meetup\\_chosen\\_%'");
    } catch (Exception $e) {
        // wala pa ang table
    }

    // View ng "Pet meet-up confirmed" = Location page (map ng Caloocan City Hall)
    try {
        $pdo->exec("UPDATE notifications SET link = 'shelter.php' WHERE title = 'Pet meet-up confirmed'");
    } catch (Exception $e) {
        // wala pa ang table
    }

    // Wala na sa adoption form ang Dining Area at Kitchen (pati Bedroom at Windows),
    // kaya puwede nang walang laman ang mga column na ito sa mga bagong application
    try {
        // Wala na rin ang Street View at Backyard
        $pdo->exec("ALTER TABLE adoption_applications
            MODIFY photo_dining_area VARCHAR(255) NULL,
            MODIFY photo_kitchen VARCHAR(255) NULL,
            MODIFY photo_street VARCHAR(255) NULL");
    } catch (Exception $e) {
        // wala pa ang table o naayos na
    }

    // Feedback ng adopter kung paano siya inasikaso ng staff sa City Veterinary
    // Office, pagkatapos niyang pindutin ang "I Received". Isa bawat adoption.
    $pdo->exec("CREATE TABLE IF NOT EXISTS certificate_feedback (
        feedback_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL UNIQUE,
        adopter_id INT NOT NULL,
        rating TINYINT NOT NULL,
        comment TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (adopter_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Mga detalyeng ang staff ang naglalagay sa certificates at adoption agreement
    // (wala ito sa application o pet records): ID na ipinakita, kulay ng pet,
    // pangalan ng beterinaryo at witness, petsa ng pirmahan, atbp.
    $pdo->exec("CREATE TABLE IF NOT EXISTS adoption_documents (
        application_id INT PRIMARY KEY,
        valid_id_type VARCHAR(100) NULL,
        valid_id_number VARCHAR(100) NULL,
        pet_color VARCHAR(100) NULL,
        distinguishing_marks VARCHAR(255) NULL,
        spayed_neutered VARCHAR(20) NULL,
        veterinarian_name VARCHAR(150) NULL,
        vet_license_no VARCHAR(100) NULL,
        witness_name VARCHAR(150) NULL,
        witness_position VARCHAR(150) NULL,
        signing_date DATE NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pangalan at address ng adopter ayon sa Valid ID mismo (binabasa ng AI
    // pagka-approve), para ito ang lumabas sa certificates imbes na ang
    // tinype lang sa application form. Kapag blangko, ang nasa form ang gamit.
    sp_add_column($pdo, 'adoption_documents', 'owner_name', "VARCHAR(150) NULL AFTER application_id");
    sp_add_column($pdo, 'adoption_documents', 'owner_address', "VARCHAR(255) NULL AFTER owner_name");
    sp_add_column($pdo, 'adoption_documents', 'id_read_at', "TIMESTAMP NULL AFTER signing_date");

    // Pet Monitoring - surprise visit ng staff sa bahay ng adopter, isang beses
    // kada buwan simula sa buwan pagkatapos ng adoption. Hindi ito sinasabi sa
    // adopter. Kapag ang result ay hindi "Stayed with Adopter" (binawi ng opisina
    // o ibinalik ng adopter), tapos na ang monitoring para sa adoption na iyon.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pet_visits (
        visit_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        pet_id INT NOT NULL,
        visit_date DATE NOT NULL,
        pet_condition ENUM('Healthy', 'Needs Attention', 'Poor') NOT NULL,
        living_condition ENUM('Adequate', 'Inadequate') NOT NULL,
        remarks TEXT NULL,
        result ENUM('Stayed with Adopter', 'Reclaimed by Office', 'Returned by Adopter') NOT NULL DEFAULT 'Stayed with Adopter',
        photo_url VARCHAR(255) NULL,
        recorded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (pet_id) REFERENCES pet_inventory(pet_id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL,
        INDEX idx_app_date (application_id, visit_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Mga binagong detalye ng pet batay sa obserbasyon sa visit
    // (hal. "Size: Small → Medium"), para makita sa History kung ano ang nagbago
    sp_add_column($pdo, 'pet_visits', 'pet_changes', "TEXT NULL AFTER photo_url");

    // Dahilan kung bakit ibinalik o binawi ang pet - NAKIKITA NG ADOPTER (sa My
    // Pets at sa notification), hindi tulad ng "remarks" na pang-staff lang
    sp_add_column($pdo, 'pet_visits', 'return_reason', "TEXT NULL AFTER remarks");

    // Pet Concern Reports - ulat ng adopter tungkol sa inampong pet (hal. may
    // sakit, nagbago ang ugali, hindi na kayang alagaan). Ipinapadala ito bilang
    // mensahe sa staff (message_id); ang status na Sent / Seen / Replied ay
    // kinukuha mula mismo sa Messages, kaya walang hiwalay na dapat i-update.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pet_concerns (
        concern_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        pet_id INT NOT NULL,
        adopter_id INT NOT NULL,
        staff_id INT NULL,
        concern_type VARCHAR(40) NOT NULL,
        details TEXT NOT NULL,
        message_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (pet_id) REFERENCES pet_inventory(pet_id) ON DELETE CASCADE,
        FOREIGN KEY (adopter_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (staff_id) REFERENCES users(user_id) ON DELETE SET NULL,
        INDEX idx_adopter (adopter_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Conversations table (grouping messages between two users)
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversations (
        conversation_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        admin_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Participants for each conversation (user membership)
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversation_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        UNIQUE KEY conversation_user_unique (conversation_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Messages with an is_notified flag so a WebSocket server can pick up new messages
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT NULL,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        is_notified TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE SET NULL,
        FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Status ng staff (Active / On Leave) - idinagdag sa users table kung wala pa
    sp_add_column($pdo, 'users', 'status', "ENUM('Active', 'On Leave') NOT NULL DEFAULT 'Active' AFTER role");

    // Shedding level - kailangan ito ng allergy matching. Kapag Fur Allergy ang adopter,
    // hindi dapat i-recommend ang mga pet na High ang shedding.
    sp_add_column($pdo, 'pet_inventory', 'shedding_level', "ENUM('Low', 'Moderate', 'High') NULL AFTER temperament");

    // Rescue History - saan, kailan (petsa at oras) na-rescue ang bawat pet, para makita sa Pet Inventory
    sp_add_column($pdo, 'pet_inventory', 'rescue_location', "VARCHAR(255) NULL AFTER description");
    sp_add_column($pdo, 'pet_inventory', 'rescue_date', "DATE NULL AFTER rescue_location");
    sp_add_column($pdo, 'pet_inventory', 'rescue_time', "TIME NULL AFTER rescue_date");

    // Activity Logs - nagtatala ng mga mahahalagang aksyon sa loob ng admin/staff panel
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        actor_id INT NULL,
        actor_name VARCHAR(100) NOT NULL,
        actor_role VARCHAR(20) NOT NULL,
        action VARCHAR(255) NOT NULL,
        details TEXT NULL,
        status ENUM('Completed', 'Pending', 'Failed') NOT NULL DEFAULT 'Completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (actor_id) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Daily Tasks - personal na checklist ng bawat staff sa Daily Schedule page
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_tasks (
        task_id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        task_name VARCHAR(255) NOT NULL,
        task_date DATE NOT NULL,
        task_time TIME NULL,
        is_done TINYINT(1) NOT NULL DEFAULT 0,
        completed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Medical Records - checkup/treatment/vaccine/recovery history ng bawat pet sa Medical & Intakes page
    $pdo->exec("CREATE TABLE IF NOT EXISTS medical_records (
        record_id INT AUTO_INCREMENT PRIMARY KEY,
        pet_id INT NOT NULL,
        status ENUM('Checkup', 'Treatment', 'Vaccines', 'Recovery') NOT NULL DEFAULT 'Checkup',
        checkup_date DATE NOT NULL,
        notes TEXT NULL,
        recorded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pet_id) REFERENCES pet_inventory(pet_id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pet Intakes - admission log ng mga bagong dating na hayop bago pa man sila i-list sa pet_inventory
    $pdo->exec("CREATE TABLE IF NOT EXISTS pet_intakes (
        intake_id INT AUTO_INCREMENT PRIMARY KEY,
        pet_id INT NULL,
        pet_name VARCHAR(100) NOT NULL,
        species VARCHAR(50) NOT NULL,
        age VARCHAR(50) NULL,
        intake_date DATE NOT NULL,
        status ENUM('Assessment', 'Vaccination', 'Quarantine', 'Cleared') NOT NULL DEFAULT 'Assessment',
        notes TEXT NULL,
        recorded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pet_id) REFERENCES pet_inventory(pet_id) ON DELETE SET NULL,
        FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Appointment Slots - shared shelter-wide interview availability na pinipili ng adopter (PSA-style booking)
    $pdo->exec("CREATE TABLE IF NOT EXISTS appointment_slots (
        slot_id INT AUTO_INCREMENT PRIMARY KEY,
        slot_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        status ENUM('Open', 'Booked') NOT NULL DEFAULT 'Open',
        created_by INT NULL,
        booked_by_application_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
        FOREIGN KEY (booked_by_application_id) REFERENCES adoption_applications(application_id) ON DELETE SET NULL,
        UNIQUE KEY unique_slot (slot_date, start_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // GCash payment records - patunay na nabayaran ng adopter ang adoption fee ng isang approved application
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL UNIQUE,
        adopter_id INT NOT NULL,
        pet_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 500.00,
        reference_number VARCHAR(100) NOT NULL,
        payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        recorded_by INT NULL,
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE,
        FOREIGN KEY (adopter_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (pet_id) REFERENCES pet_inventory(pet_id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Online payment support (Xendit). Ang mga lumang manual na record ay Paid/Manual agad,
    // kaya ligtas ang default values para sa existing rows.
    sp_add_column($pdo, 'payments', 'status', "ENUM('Pending', 'Paid', 'Failed') NOT NULL DEFAULT 'Paid' AFTER amount");
    sp_add_column($pdo, 'payments', 'method', "VARCHAR(30) NOT NULL DEFAULT 'Manual' AFTER status");
    sp_add_column($pdo, 'payments', 'xendit_request_id', "VARCHAR(100) NULL AFTER reference_number");
    sp_add_column($pdo, 'payments', 'checkout_url', "TEXT NULL AFTER xendit_request_id");
    // Napiling paraan ng pagbabayad (GCASH, PAYMAYA, GRABPAY, SHOPEEPAY, QRPH)
    // at ang QR string ng QR Ph, na walang checkout link
    sp_add_column($pdo, 'payments', 'pay_channel', "VARCHAR(30) NULL AFTER checkout_url");
    sp_add_column($pdo, 'payments', 'qr_string', "TEXT NULL AFTER pay_channel");
    // Ang Xendit na bayad ay walang reference number hangga't hindi pa nababayaran
    $pdo->exec("ALTER TABLE payments MODIFY reference_number VARCHAR(100) NULL");

    // Bawat singil na ginawa sa Xendit para sa isang application. Ang payments table
    // ay isang row lang kada application, kaya kapag nagpalit ng paraan o nag-lag at
    // nakagawa ng bagong singil, napapatungan ang lumang request id. Dito natin
    // natatandaan ang LAHAT, para kapag dalawa ang nabayaran, ma-refund ang sobra.
    $pdo->exec("CREATE TABLE IF NOT EXISTS xendit_charges (
        charge_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        xendit_request_id VARCHAR(100) NOT NULL UNIQUE,
        amount DECIMAL(10,2) NOT NULL,
        channel VARCHAR(30) NULL,
        status ENUM('Pending', 'Paid', 'Failed', 'Cancelled', 'Refund Pending', 'Refunded', 'Refund Failed') NOT NULL DEFAULT 'Pending',
        xendit_payment_id VARCHAR(100) NULL,
        refund_id VARCHAR(100) NULL,
        refund_error TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_charge_app (application_id),
        FOREIGN KEY (application_id) REFERENCES adoption_applications(application_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Isama ang mga dating Xendit payment para kumpleto ang talaan
    $pdo->exec("INSERT IGNORE INTO xendit_charges (application_id, xendit_request_id, amount, channel, status)
                SELECT application_id, xendit_request_id, amount, pay_channel, status
                FROM payments WHERE xendit_request_id IS NOT NULL AND xendit_request_id <> ''");

    // Naka-imbak na paliwanag ng beterinaryo kung bakit bagay ang isang pet sa
    // isang adopter. Mahal ang bawat tawag sa AI, kaya minsan lang ito ginagawa:
    // muli lang itong gagawin kapag nagbago ang source_hash - ibig sabihin,
    // nagpalit ng preference ang adopter o inayos ng staff ang datos ng pet.
    $pdo->exec("CREATE TABLE IF NOT EXISTS pet_explanations (
        explanation_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pet_id INT NOT NULL,
        lang VARCHAR(5) NOT NULL DEFAULT 'en',
        source_hash CHAR(32) NOT NULL,
        match_score INT NOT NULL DEFAULT 0,
        explanation TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (pet_id) REFERENCES pet_inventory(pet_id) ON DELETE CASCADE,
        UNIQUE KEY unique_explanation (user_id, pet_id, lang)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Isang buong upuan ng pakikipag-usap sa AI assistant. Nagtatapos ito kapag
    // isinara ng user ang chat, o kapag 30 minuto nang walang bagong mensahe.
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_sessions (
        session_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_role VARCHAR(20) NOT NULL,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ended_at TIMESTAMP NULL,
        message_count INT NOT NULL DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Bawat mensahe sa loob ng isang chat session, tanong man o sagot
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        chat_message_id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        sender ENUM('user', 'assistant') NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(session_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ang mga pet card o link na kasama ng sagot, bilang JSON - para kapag binuksan
    // ang lumang usapan sa history, buo itong lumalabas, hindi text lang.
    sp_add_column($pdo, 'chat_messages', 'payload', "TEXT NULL AFTER message");

    // Machine Learning - bawat pag-train ng Logistic Regression na natututo ng
    // timbang ng matching mula sa mga natapos na adoption. Ang is_active = 1 ang
    // model na ginagamit ng computeMatchScore(); kapag wala, ang manual na
    // timbang (60% preferences / 40% lifestyle) ang gamit. Tingnan ang connection/ml_model.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS ml_models (
        model_id INT AUTO_INCREMENT PRIMARY KEY,
        algorithm VARCHAR(60) NOT NULL DEFAULT 'Logistic Regression',
        feature_names TEXT NOT NULL,
        weights TEXT NOT NULL,
        bias DOUBLE NOT NULL DEFAULT 0,
        n_samples INT NOT NULL DEFAULT 0,
        n_positive INT NOT NULL DEFAULT 0,
        n_negative INT NOT NULL DEFAULT 0,
        accuracy DOUBLE NULL,
        precision_score DOUBLE NULL,
        recall_score DOUBLE NULL,
        f1_score DOUBLE NULL,
        validation VARCHAR(60) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        trained_by INT NULL,
        trained_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (trained_by) REFERENCES users(user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Notifications - mga abiso para sa adopter (na-approve ang application, may
    // meeting link na, natanggap ang bayad, atbp.). Ang ref_key ang pumipigil na
    // maulit ang parehong abiso kapag dumaan ang parehong event sa ibang daan
    // (hal. webhook at manual check ay parehong nagmamarka ng Paid).
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(40) NOT NULL DEFAULT 'general',
        title VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        link VARCHAR(255) NULL,
        ref_key VARCHAR(100) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        UNIQUE KEY unique_ref (user_id, ref_key),
        INDEX idx_user_unread (user_id, is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Role Change Requests - hindi na direktang pinapalitan ng admin ang role ng
    // isang account. Nagpapadala muna siya ng hiling dito, at ang may-ari mismo
    // ng account ang magde-desisyon sa pop-up (Agree o Decline). Kapag Agree
    // lang nagbabago ang users.role. Pareho ang daan pataas (Adopter -> Staff)
    // at pabalik (Staff -> Adopter), kaya hindi nawawala ang records ng user.
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_change_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        from_role ENUM('Adopter', 'Staff', 'Admin') NOT NULL,
        to_role ENUM('Adopter', 'Staff', 'Admin') NOT NULL,
        requested_by INT NULL,
        requested_by_name VARCHAR(100) NOT NULL DEFAULT 'Administrator',
        status ENUM('Pending', 'Accepted', 'Declined', 'Cancelled') NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (requested_by) REFERENCES users(user_id) ON DELETE SET NULL,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Tapos na ang setup - hindi na ito uulitin hangga't hindi binabago ang $spSchemaVersion
        @file_put_contents($spSchemaFlag, $spSchemaVersion);
    }

} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Backup na tagapagpadala ng interview meeting links kapag walang naka-setup na
// Task Scheduler: kada page load, pero kada minuto lang talaga tumatakbo.
// Hindi ito kailangan sa CLI - ang cron script mismo ang tumatawag doon.
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/interview_reminders.php';
    interview_reminder_tick($pdo);
}

/**
 * Bilang ng mga unread message ng isang user, kahit anong role (Adopter,
 * Staff, o Admin) - sa lahat ng usapan niya, hindi lang isa. Ginagamit ito
 * ng sidebar sa bawat page para may makitang badge kahit hindi pa binuksan
 * ang Messages page mismo.
 */
function sp_unread_message_count(PDO $pdo, int $user_id): int
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM messages m
             INNER JOIN conversations c ON m.conversation_id = c.conversation_id
             WHERE (c.user_id = ? OR c.admin_id = ?)
               AND m.sender_id != ?
               AND m.is_read = 0'
        );
        $stmt->execute([$user_id, $user_id, $user_id]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Magpadala ng notification sa isang user. Kapag may ref_key at may
 * nauna nang abiso na may parehong ref_key para sa user na ito, hindi na
 * uulitin - kaya ligtas itong tawagin nang paulit-ulit sa iisang event.
 */
function sp_notify(PDO $pdo, int $user_id, string $type, string $title, string $message, ?string $link = null, ?string $ref_key = null): bool
{
    try {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO notifications (user_id, type, title, message, link, ref_key)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $type, $title, $message, $link, $ref_key]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log('sp_notify failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Magpadala ng text message mula sa isang user papunta sa isa pa, gamit ang
 * parehong conversation na ginagamit ng Messages pages - kaya lalabas ito sa
 * Messages ng dalawa at bibilangin sa unread badge.
 */
function sp_send_message(PDO $pdo, int $sender_id, int $receiver_id, string $message): bool
{
    try {
        $conv = $pdo->prepare("SELECT conversation_id FROM conversations WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)");
        $conv->execute([$receiver_id, $sender_id, $sender_id, $receiver_id]);
        $conversation_id = $conv->fetchColumn();

        if (!$conversation_id) {
            $pdo->prepare("INSERT INTO conversations (user_id, admin_id) VALUES (?, ?)")
                ->execute([$receiver_id, $sender_id]);
            $conversation_id = $pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, message, is_read) VALUES (?, ?, 'text', ?, 0)")
            ->execute([$conversation_id, $sender_id, $message]);
        return true;
    } catch (Exception $e) {
        error_log('sp_send_message failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Button sa loob ng mensahe ng system: [[Label|target]]
 * Hal. [[View Available Schedule to Meet Pets|schedule.php#meetup-12]]
 * Ang target ay page lang ng adopter (hal. schedule.php) o Google Maps - walang
 * ibang link ang nagiging button, kaya hindi ito magagamit sa mapanganib na link.
 */
const SP_MSG_BUTTON_PATTERN = '/\[\[([^|\]\r\n]{1,80})\|([^\]\s]{1,300})\]\]/u';

function sp_message_button(string $label, string $target): string
{
    return '[[' . str_replace(['|', ']', '['], ' ', $label) . '|' . $target . ']]';
}

function sp_message_target_ok(string $target): bool
{
    return (bool) preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?(\#[A-Za-z0-9_\-]+)?$#', $target)
        || strpos($target, 'https://www.google.com/maps/') === 0;
}

/**
 * Plain na text ng mensahe -> HTML: escaped, may line break, at napipindot ang
 * mga http/https na link (bubukas sa bagong tab).
 */
function sp_message_text_html(string $text): string
{
    $html = htmlspecialchars($text);
    $html = preg_replace_callback(
        '#\bhttps?://[^\s<>"\']+#i',
        function ($m) {
            $url = rtrim($m[0], '.,);:!?');
            $rest = substr($m[0], strlen($url));
            return '<a class="msg-link" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $rest;
        },
        $html
    );
    return nl2br($html);
}

/**
 * HTML ng mensahe. $side = 'adopter' (napipindot ang button) o 'staff'.
 * Sa staff/admin, ang button papunta sa page ng adopter ay ipinapakita bilang
 * chip (hindi napipindot, dahil pang-adopter ang page); ang Google Maps ay
 * napipindot para sa lahat.
 */
function sp_message_html(string $text, string $side = 'adopter'): string
{
    $parts = preg_split(SP_MSG_BUTTON_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    for ($i = 0; $i < count($parts); $i++) {
        if ($i % 3 === 0) {
            $html .= sp_message_text_html($parts[$i]);
            continue;
        }
        $label = trim($parts[$i]);
        $target = $parts[$i + 1] ?? '';
        $i++;
        $external = strpos($target, 'https://') === 0;
        if (sp_message_target_ok($target) && ($side === 'adopter' || $external)) {
            $html .= '<a class="msg-action-btn" href="' . htmlspecialchars($target) . '"'
                . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . htmlspecialchars($label) . '</a>';
        } elseif ($side === 'adopter') {
            $html .= '<span class="msg-action-label">[' . htmlspecialchars($label) . ']</span>';
        } else {
            $html .= '<span class="msg-action-chip" title="This button appears in the adopter\'s chat">'
                . '<i class="fa-solid fa-hand-pointer" aria-hidden="true"></i> ' . htmlspecialchars($label) . '</span>';
        }
    }
    return $html;
}

/**
 * Maikling bersyon para sa preview ng usapan (walang [[...]] na makikita).
 */
function sp_message_preview(string $text): string
{
    return preg_replace(SP_MSG_BUTTON_PATTERN, '$1', $text);
}

/**
 * Abiso na natanggap na ang bayad. Tinatawag mula sa lahat ng daan kung saan
 * pwedeng maging Paid ang isang payment (webhook, staff check, adopter poll,
 * manual na pagtatala) - isang abiso lang ang lalabas dahil sa ref_key.
 */
function sp_notify_payment_paid(PDO $pdo, int $payment_id): void
{
    try {
        $stmt = $pdo->prepare(
            "SELECT pay.adopter_id, pay.application_id, pay.amount, p.name AS pet_name
             FROM payments pay
             JOIN pet_inventory p ON p.pet_id = pay.pet_id
             WHERE pay.payment_id = ? AND pay.status = 'Paid'"
        );
        $stmt->execute([$payment_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        sp_notify(
            $pdo,
            (int) $row['adopter_id'],
            'payment',
            'Payment received',
            'Your adoption fee of ₱' . number_format((float) $row['amount'], 2) . ' for ' . $row['pet_name'] . ' has been received. Thank you! Next, the shelter will release your certificate, and then your adoption will be approved.',
            'payments.php',
            'payment_paid_' . $payment_id
        );
        // Bayad na = handa na ang Certificate of Adoption at Vaccination Certificate sa
        // Docs. Ang approval ay pagkatapos ma-release ng staff ang certificate
        // (connection/application_flow.php). Kahit burahin ng adopter ang abisong ito,
        // makikita pa rin niya ang mga ito sa Docs page (docs.php).
        sp_notify(
            $pdo,
            (int) $row['adopter_id'],
            'certificate',
            'Your adoption certificates are ready',
            'You can now view your Certificate of Adoption, Vaccination Certificate, and Pet Adoption Agreement for ' . $row['pet_name'] . ' under Docs. Please read the agreement before pickup day. The official copy, signed by the City Veterinarian and bearing the office seal, will be handed to you on pickup day. Your adoption is approved once the certificate is released to you.',
            'docs.php',
            'certificate_ready_' . (int) $row['application_id']
        );
    } catch (Exception $e) {
        error_log('sp_notify_payment_paid failed: ' . $e->getMessage());
    }
}

/**
 * Mga uri ng notification na HINDI para sa adopter (hal. ang hiling na gawing
 * Staff ang account - may sarili itong popup, role_change_prompt.php). Hindi
 * ito ipinapakita sa Notifications ng adopter at hindi binibilang sa bell.
 */
const SP_ADOPTER_HIDDEN_NOTIF_TYPES = ['role_change'];

/** SQL na pang-filter: "AND type NOT IN (...)" para sa listahan ng adopter */
function sp_adopter_notif_filter(): string
{
    $quoted = array_map(function ($t) { return "'" . $t . "'"; }, SP_ADOPTER_HIDDEN_NOTIF_TYPES);
    return ' AND type NOT IN (' . implode(',', $quoted) . ')';
}

/**
 * Bilang ng hindi pa nababasang notification ng isang adopter - para sa bell at sidebar.
 */
function sp_unread_notification_count(PDO $pdo, int $user_id): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0' . sp_adopter_notif_filter());
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Wika ng naka-login na user: 'en' o 'tl'. Naka-cache sa session; binabago ng
 * profile_setup.php kapag pinalitan sa Account Settings.
 */
/*
 * ===== Isang adopter lang bawat alaga =====
 * Kapag may nag-apply na sa isang alaga (Pending, Under Review o Approved), hindi na
 * ito irerekomenda o ipapakita sa ibang adopter, at hindi na rin sila makakapag-apply,
 * hanggang matapos ang aplikasyong iyon (Rejected o na-cancel). Ang adopter na nag-apply
 * ay nakikita pa rin ito, pero hindi na siya makakapag-apply ulit (walang doble).
 */
const SP_ACTIVE_APP_STATUSES = "'Pending', 'Under Review', 'Approved'";

/**
 * SQL na kondisyon: walang aktibong aplikasyon ng IBANG adopter sa alagang ito.
 * Kailangang i-bind ang user_id ng tumitingin sa "?" (sa pagkakasunod nito sa query).
 * Hal.: "SELECT ... FROM pet_inventory WHERE status = 'Available' AND " . sp_pet_open_sql()
 * Laging may pangalan ng table ang $petIdCol (hal. 'pet_inventory.pet_id' o 'p.pet_id'):
 * ang "pet_id" lang ay tumutukoy sa adoption_applications sa loob ng subquery.
 */
function sp_pet_open_sql(string $petIdCol = 'pet_inventory.pet_id'): string
{
    return "NOT EXISTS (SELECT 1 FROM adoption_applications sp_r
                        WHERE sp_r.pet_id = $petIdCol
                          AND sp_r.application_status IN (" . SP_ACTIVE_APP_STATUSES . ")
                          AND sp_r.adopter_id <> ?)";
}

/**
 * Ang aktibong aplikasyon sa isang alaga (ang pinakaunang nagpasa), o null kung wala.
 *
 * @return array{application_id:int, adopter_id:int, application_status:string}|null
 */
function sp_pet_active_application(PDO $pdo, int $petId): ?array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT application_id, adopter_id, application_status FROM adoption_applications
             WHERE pet_id = ? AND application_status IN (" . SP_ACTIVE_APP_STATUSES . ")
             ORDER BY created_at ASC, application_id ASC LIMIT 1"
        );
        $stmt->execute([$petId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    return $row ? ['application_id' => (int) $row['application_id'], 'adopter_id' => (int) $row['adopter_id'], 'application_status' => $row['application_status']] : null;
}

/**
 * Mga alaga na may aktibong aplikasyon na ang adopter na ito (Pending / Under Review / atbp.).
 * Sa mga card, "Applied" na ang ipinapakita imbes na "Adopt Now".
 * @return array<int, string> pet_id => application_status
 */
function sp_my_applied_pets(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT pet_id, application_status FROM adoption_applications
             WHERE adopter_id = ? AND application_status IN (" . SP_ACTIVE_APP_STATUSES . ")"
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['pet_id']] = $r['application_status'];
        }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

function sp_user_lang(PDO $pdo, int $user_id): string
{
    if (isset($_SESSION['lang']) && in_array($_SESSION['lang'], ['en', 'tl'], true)) {
        return $_SESSION['lang'];
    }
    $lang = 'en';
    try {
        $stmt = $pdo->prepare('SELECT language FROM users WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $lang = $stmt->fetchColumn() === 'tl' ? 'tl' : 'en';
    } catch (Exception $e) {
        // wala pa ang column: English muna
    }
    $_SESSION['lang'] = $lang;
    return $lang;
}

/**
 * Ang nakabinbing hiling na pagpapalit ng role ng isang user, kung mayroon.
 * Isa lang ang pwedeng nakabinbin sa bawat account, kaya ito ang ginagamit
 * pareho ng pop-up sa panig ng user at ng badge sa panig ng admin.
 */
function sp_pending_role_request(PDO $pdo, int $user_id): ?array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM role_change_requests
              WHERE user_id = ? AND status = \'Pending\'
              ORDER BY request_id DESC LIMIT 1'
        );
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Lahat ng nakabinbing hiling, naka-index sa user_id. Iisang query para sa
 * buong listahan sa Manage Adopters at Manage Staff.
 */
function sp_pending_role_requests(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT * FROM role_change_requests WHERE status = \'Pending\''
        )->fetchAll(PDO::FETCH_ASSOC);
        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['user_id']] = $row;
        }
        return $byUser;
    } catch (Exception $e) {
        return [];
    }
}
