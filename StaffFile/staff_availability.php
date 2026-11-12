<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$user_stmt = $pdo->prepare("SELECT username, profile_image, role FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$username = $current_user['username'] ?? 'Staff Member';
$profile_image = $current_user['profile_image'] ?? '';
$user_initials = strtoupper(substr($username, 0, 2));

require_once __DIR__ . '/../connection/interview_schedule.php';
// Mga araw at oras na bukas ang City Hall (itinatakda sa "Edit Hours")
$settings = sp_interview_settings($pdo);
// Special days: holiday sa Pilipinas (kusa) + ganap sa City Hall na inilagay ng staff
$closures = sp_city_hall_closures($pdo);
$upcomingClosures = count(array_filter($closures, fn ($c) => $c['date'] >= date('Y-m-d')));
// Para sa calendar sa modal at sa Meet-up preview: 'Y-m-d' => pangalan ng holiday
$holidayMap = [];
for ($hy = (int) date('Y') - 1; $hy <= (int) date('Y') + 3; $hy++) {
    $holidayMap += sp_ph_holidays($hy);
}
$dayNames =[1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$hoursLabel = ($settings['days'] ? implode(', ', array_map(fn ($d) => $dayNames[$d], $settings['days'])) : 'No days set')
    . ' · ' . date('g:i A', strtotime($settings['open'])) . ' - ' . date('g:i A', strtotime($settings['close']))
    . ($settings['breakStart'] !== null
        ? ' · Break ' . date('g:i A', strtotime($settings['breakStart'])) . ' - ' . date('g:i A', strtotime($settings['breakStart']) + $settings['breakMinutes'] * 60)
        : '')
    . ' · Closed on holidays'
    . ($upcomingClosures ? ' · ' . $upcomingClosures . ' upcoming not-available day' . ($upcomingClosures === 1 ? '' : 's') : '');

// Mga tinanggap na interview (Booked). Nananatili kahit lumipas na ang oras,
// hangga't bukas pa ang application (Pending / Under Review), para mapindot ng
// staff at maipadala ang Pet Meet-up schedule o markahang Not Attended.
// Ang lumang slot ng na-reschedule na interview ay hindi na kasama. Wala nang
// "Open" na slot - ang adopter na ang pumipili ng oras sa loob ng oras ng City Hall.
$slots = [];
try {
    $slots = $pdo->query("
        SELECT s.slot_id, s.slot_date, s.start_time, s.end_time, s.status,
               TIMESTAMPDIFF(SECOND, NOW(), TIMESTAMP(s.slot_date, s.start_time)) AS starts_in,
               aa.application_id, aa.full_name AS booked_by, aa.application_status,
               aa.interview_missed_at, aa.rebook_sent_at, aa.meeting_link,
               aa.meetup_sent_at, aa.meetup_note,
               mo.meet_date AS meetup_date, mo.meet_time AS meetup_time, mo.meet_end_time AS meetup_end,
               p.name AS pet_name
        FROM appointment_slots s
        LEFT JOIN adoption_applications aa ON aa.application_id = s.booked_by_application_id
        LEFT JOIN pet_inventory p ON p.pet_id = aa.pet_id
        LEFT JOIN pet_meetup_options mo ON mo.option_id = aa.meetup_option_id
        WHERE s.status = 'Booked'
          AND aa.preferred_interview_date = s.slot_date AND aa.preferred_interview_time = s.start_time
          AND (s.slot_date >= CURDATE() OR aa.application_status IN ('Pending', 'Under Review'))
        ORDER BY s.slot_date ASC, s.start_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $slots = [];
}

$slotsByDate = [];
foreach ($slots as $s) {
    $slotsByDate[$s['slot_date']][] = $s;
}

// Mga araw at oras na pinili ng adopter - hinihintay ang Accept o Decline ng staff
$requests = [];
try {
    $requests = $pdo->query("
        SELECT aa.application_id, aa.full_name, aa.preferred_interview_date, aa.preferred_interview_time, aa.created_at,
               TIMESTAMP(aa.preferred_interview_date, aa.preferred_interview_time) < NOW() AS is_past,
               p.name AS pet_name
        FROM adoption_applications aa
        LEFT JOIN pet_inventory p ON p.pet_id = aa.pet_id
        WHERE aa.schedule_status = 'Pending' AND aa.application_status IN ('Pending', 'Under Review')
        ORDER BY aa.preferred_interview_date ASC, aa.preferred_interview_time ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $requests = [];
}
$requestsByDate = [];
foreach ($requests as $r) {
    $requestsByDate[$r['preferred_interview_date']][] = $r;
}

// Mga inalok na Pet Meet-up schedule ng bawat naka-book na application
$meetupOptions = [];
$bookedAppIds = array_values(array_filter(array_map(fn($s) => (int) ($s['application_id'] ?? 0), $slots)));
if ($bookedAppIds) {
    try {
        $in = implode(',', array_fill(0, count($bookedAppIds), '?'));
        $optStmt = $pdo->prepare("SELECT application_id, meet_date, meet_time, meet_end_time FROM pet_meetup_options WHERE application_id IN ($in) ORDER BY meet_date, meet_time");
        $optStmt->execute($bookedAppIds);
        foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $meetupOptions[(int) $o['application_id']][] = $o;
        }
    } catch (Exception $e) {
        $meetupOptions = [];
    }
}

// Hal. "Oct 2 (Fri), 8:00 AM - 5:00 PM". Ang lumang meet-up na walang oras ng sara ay isang oras lang.
function meetup_label(string $date, string $open, ?string $close, string $dateFormat = 'D, M j, Y'): string
{
    $label = date($dateFormat, strtotime($date)) . ', ' . date('g:i A', strtotime($open));
    return $close ? $label . ' - ' . date('g:i A', strtotime($close)) : $label;
}

// Maikling estado ng naka-book na interview, para makita agad sa listahan
function booked_state(array $s): array
{
    $decided = !in_array($s['application_status'], ['Pending', 'Under Review'], true);
    if ($decided) {
        return ['label' => 'Application ' . $s['application_status'], 'class' => 'st-done'];
    }
    if (!empty($s['interview_missed_at'])) {
        return empty($s['rebook_sent_at'])
            ? ['label' => 'Did not attend - send a reschedule link', 'class' => 'st-missed']
            : ['label' => 'Did not attend - reschedule link sent', 'class' => 'st-missed'];
    }
    if (!empty($s['meetup_date'])) {
        return ['label' => 'Pet meet-up: ' . meetup_label($s['meetup_date'], $s['meetup_time'], $s['meetup_end'] ?? null, 'M j'), 'class' => 'st-ok'];
    }
    if (!empty($s['meetup_sent_at'])) {
        return ['label' => 'Meet-up schedule sent - waiting for the adopter', 'class' => 'st-wait'];
    }
    if ((int) $s['starts_in'] > 0) {
        return ['label' => 'Interview not started yet', 'class' => 'st-wait'];
    }
    return ['label' => 'Interview done - send the pet meet-up schedule or mark as not attended', 'class' => 'st-action'];
}

$todayStr = date('Y-m-d');
$weekAhead = date('Y-m-d', strtotime('+7 days'));
$bookedCount = count(array_filter($slots, fn($s) => $s['slot_date'] >= $todayStr));
$thisWeekCount = count(array_filter($slots, fn($s) => $s['slot_date'] >= $todayStr && $s['slot_date'] <= $weekAhead))
    + count(array_filter($requests, fn($r) => $r['preferred_interview_date'] >= $todayStr && $r['preferred_interview_date'] <= $weekAhead));

// Napiling araw (?date=YYYY-MM-DD, hal. galing sa notification) at ang buwang ipapakita (?month=YYYY-MM)
$dateParam = (string) ($_GET['date'] ?? '');
$hasDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam) === 1
    && checkdate((int) substr($dateParam, 5, 2), (int) substr($dateParam, 8, 2), (int) substr($dateParam, 0, 4));
$monthParam = $_GET['month'] ?? ($hasDate ? substr($dateParam, 0, 7) : date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = date('Y-m');
}
if ($hasDate && strpos($dateParam, $monthParam) === 0) {
    $selectedDate = $dateParam;
} elseif (strpos($todayStr, $monthParam) === 0) {
    $selectedDate = $todayStr;
} else {
    $selectedDate = $monthParam . '-01';
}
$firstOfMonth = new DateTime($monthParam . '-01');
$daysInMonth = (int) $firstOfMonth->format('t');
$startWeekday = (int) $firstOfMonth->format('w');
$prevMonth = (clone $firstOfMonth)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $firstOfMonth)->modify('+1 month')->format('Y-m');
$monthLabel = $firstOfMonth->format('F Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Availability - Smart Pet</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <style>
        .avail-grid-container {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 20px;
            margin-top: 20px;
        }

        @media (max-width: 1024px) {
            .avail-grid-container { grid-template-columns: 1fr; }
        }

        .avail-panel {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(10,10,10,0.04);
        }

        .avail-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .avail-nav { display: flex; align-items: center; gap: 8px; }
        .avail-nav a {
            width: 32px; height: 32px; border-radius: 50%; background: #f0f4f1; color: #1f6b48;
            display: flex; align-items: center; justify-content: center; text-decoration: none;
            transition: background-color 0.2s ease;
        }
        .avail-nav a:hover { background: #dcebe0; }
        .avail-nav .avail-today {
            width: auto; border-radius: 20px; padding: 0 14px; font-size: 12.5px; font-weight: 700;
            background: #f59c23; color: #fff;
        }

        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
        .cal-dow { text-align: center; font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; padding-bottom: 6px; }
        .cal-day {
            min-height: 78px; border-radius: 10px; background: #f8f9fa; padding: 6px;
            display: flex; flex-direction: column; gap: 3px;
        }
        .cal-day.empty { background: transparent; }
        .cal-day.today { background: #eaf3ee; box-shadow: inset 0 0 0 2px #1f6b48; }
        .cal-daynum { font-size: 12px; font-weight: 700; color: #556; }
        .cal-day.today .cal-daynum { color: #1f6b48; }
        .cal-slot-dot { display: flex; gap: 3px; flex-wrap: wrap; }
        .cal-slot-dot span { width: 7px; height: 7px; border-radius: 50%; }
        .dot-open { background: #1e7e34; }
        .dot-booked { background: #c5221f; }

        .slot-list { display: flex; flex-direction: column; gap: 10px; max-height: 560px; overflow-y: auto; }
        .slot-item {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            background: #f8f9fa; border-radius: 12px; padding: 10px 14px;
        }
        .slot-item .slot-date { font-weight: 800; font-size: 13px; color: #111827; }
        .slot-item .slot-time { font-size: 12px; color: #6b7280; }
        .slot-item .slot-who { font-size: 11.5px; color: #9ca3af; margin-top: 2px; }

        .badge-slot {
            padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; flex-shrink: 0;
        }
        .badge-open { background: #e6f4ea; color: #1e7e34; }
        .badge-booked { background: #dcfce7; color: #166534; } /* berde: kapareho ng "Accepted interview" sa calendar */

        /* Add Availability modal */
        .slot-tabs { display: flex; gap: 6px; padding: 0 20px; margin-top: 4px; }
        .slot-tab {
            flex: 1; padding: 9px 12px; border-radius: 10px; border: 1px solid #e2e8e4;
            background: #f6f8f7; color: #475569; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit;
        }
        .slot-tab.active { background: #1f6b48; border-color: #1f6b48; color: #fff; }
        .slot-help { margin: 0 0 12px; font-size: 13px; color: #6b7280; line-height: 1.5; }
        .slot-small { display: block; margin-top: 4px; font-size: 11.5px; color: #9aa6a3; }
        .day-picks { display: flex; gap: 6px; flex-wrap: wrap; }
        .day-pick { cursor: pointer; }
        .day-pick input { position: absolute; opacity: 0; pointer-events: none; }
        .day-pick span {
            display: inline-block; min-width: 46px; text-align: center; padding: 7px 10px; border-radius: 8px;
            border: 1.5px solid #dcebe0; font-size: 12.5px; font-weight: 700; color: #475569; background: #fff;
        }
        .day-pick.wide span { min-width: 140px; }
        .day-pick input:checked + span { background: #1f6b48; border-color: #1f6b48; color: #fff; }
        .day-pick input:focus-visible + span { box-shadow: 0 0 0 3px rgba(31,107,72,0.25); }
        .slot-preview {
            margin-top: 14px; padding: 12px; border-radius: 10px; background: #f6faf7; border: 1px solid #dcebe0;
            display: flex; flex-wrap: wrap; gap: 6px; max-height: 170px; overflow-y: auto;
        }
        .slot-preview-head { width: 100%; font-size: 12.5px; font-weight: 800; color: #1f3d2c; margin-bottom: 2px; }
        .slot-chip { font-size: 11.5px; font-weight: 700; padding: 4px 9px; border-radius: 14px; background: #fff; border: 1px solid #cfe0d4; color: #1f6b48; }
        .slot-preview-warn { font-size: 12.5px; color: #b45309; }

        /* ===== City Hall Interview Hours modal ===== */
        .hours-modal .hm-title-wrap { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .hours-modal .hm-title-icon {
            width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: #e8f3ec; color: #1f6b48; font-size: 16px;
        }
        .hours-modal .hm-subtitle { font-size: 12.5px; color: #6b7280; margin-top: 2px; }
        .hours-modal .modal-body { gap: 0; background: #fafbfa; }
        .hm-section { background: #fff; border: 1px solid #e7ece9; border-radius: 14px; padding: 16px; }
        .hm-section + .hm-section { margin-top: 14px; }
        .hm-section-head { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
        .hm-step {
            width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0; margin-top: 1px;
            display: flex; align-items: center; justify-content: center;
            background: #1f6b48; color: #fff; font-size: 12px; font-weight: 800;
        }
        .hm-section-head h4 { margin: 0; font-size: 14.5px; font-weight: 800; color: #102a2a; display: flex; align-items: center; gap: 8px; }
        .hm-section-head p { margin: 3px 0 0; font-size: 12.5px; color: #6b7280; line-height: 1.5; }
        .hm-count {
            min-width: 22px; height: 20px; padding: 0 7px; border-radius: 999px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #fdecea; color: #b42318; font-size: 11px; font-weight: 800;
        }
        .hours-modal .form-grid-2 .form-group input,
        .hours-modal .sp-add select,
        .hours-modal .sp-add input {
            height: 40px; padding: 0 12px; border: 1.5px solid #dfe6e2; border-radius: 10px;
            font-size: 14px; color: #102a2a; font-family: inherit; background: #fff; box-sizing: border-box;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .hours-modal .form-grid-2 .form-group input:focus,
        .hours-modal .sp-add select:focus,
        .hours-modal .sp-add input:focus { outline: none; border-color: #1f6b48; box-shadow: 0 0 0 3px rgba(31,107,72,0.12); }
        .hours-modal .day-pick span { padding: 8px 12px; border-radius: 10px; transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
        .hours-modal .day-pick:hover input:not(:checked) + span { border-color: #b9d3c2; background: #f6faf7; }

        /* Special days: calendar + detalye ng napiling araw */
        .sp-cal-wrap { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 12px; }
        .sp-cal { border: 1px solid #e7ece9; border-radius: 12px; padding: 10px; background: #fff; }
        .sp-cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .sp-cal-title { font-size: 14px; font-weight: 800; color: #102a2a; }
        .sp-cal-arrow {
            width: 30px; height: 30px; border-radius: 8px; border: 1px solid #e2e8e4; background: #fff; color: #475569;
            cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 11px;
            transition: background-color .15s ease;
        }
        .sp-cal-arrow:hover:not(:disabled) { background: #f1f6f3; }
        .sp-cal-arrow:disabled { opacity: .35; cursor: default; }
        .sp-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .sp-cal-dow { text-align: center; font-size: 10.5px; font-weight: 700; color: #9aa3ad; padding: 2px 0 4px; text-transform: uppercase; }
        .sp-day {
            position: relative; aspect-ratio: 1; min-height: 30px; border: none; border-radius: 8px; background: #f3f8f5;
            color: #1f3d2c; font-family: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer;
            transition: background-color .15s ease, box-shadow .15s ease, color .15s ease;
        }
        .sp-day:hover:not(:disabled) { background: #e2efe7; }
        .sp-day.is-closed { background: #f4f4f5; color: #a1a1aa; font-weight: 600; }
        .sp-day.is-holiday { background: #fdecea; color: #b42318; }
        .sp-day.is-event { background: #d93025; color: #fff; }
        .sp-day.is-event:hover:not(:disabled) { background: #b42318; }
        .sp-day.is-holiday::after, .sp-day.is-event::after {
            content: ''; position: absolute; left: 50%; bottom: 4px; width: 4px; height: 4px; margin-left: -2px;
            border-radius: 50%; background: currentColor; opacity: .7;
        }
        .sp-day.is-today { box-shadow: inset 0 0 0 2px #1f6b48; }
        .sp-day.is-past { opacity: .35; cursor: default; }
        .sp-day.is-selected { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #1f6b48; z-index: 1; }

        .sp-detail { border: 1px solid #e7ece9; border-radius: 12px; padding: 14px; background: #fafbfa; display: flex; flex-direction: column; gap: 10px; min-width: 0; }
        .sp-detail-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; text-align: center; color: #6b7280; font-size: 12.5px; line-height: 1.5; padding: 10px; }
        .sp-detail-empty i { font-size: 22px; color: #b9d3c2; }
        .sp-detail-head { display: flex; align-items: center; gap: 10px; }
        .sp-badge { width: 46px; flex-shrink: 0; border-radius: 9px; overflow: hidden; text-align: center; border: 1px solid #e2e8e4; background: #fff; }
        .sp-badge-mon { display: block; background: #1f6b48; color: #fff; font-size: 9.5px; font-weight: 800; letter-spacing: .6px; padding: 2px 0; }
        .sp-badge-day { display: block; font-size: 17px; font-weight: 800; color: #202124; line-height: 1.5; }
        .sp-detail-date { font-size: 13px; font-weight: 800; color: #102a2a; line-height: 1.3; }
        .sp-chip { display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .3px; }
        .sp-chip-open { background: #e7f6ec; color: #1b6b3a; }
        .sp-chip-holiday { background: #fdecea; color: #b42318; }
        .sp-chip-event { background: #d93025; color: #fff; }
        .sp-detail-name { font-size: 15px; font-weight: 800; color: #202124; word-break: break-word; }
        .sp-detail-note { margin: 0; font-size: 12px; color: #6b7280; line-height: 1.5; }
        .sp-field-label { font-size: 12px; font-weight: 700; color: #475569; margin-bottom: -4px; }
        .hours-modal .sp-input {
            width: 100%; height: 40px; padding: 0 12px; border: 1.5px solid #dfe6e2; border-radius: 10px; box-sizing: border-box;
            font-size: 14px; font-family: inherit; color: #102a2a; background: #fff; transition: border-color .2s ease, box-shadow .2s ease;
        }
        .hours-modal .sp-input:focus { outline: none; border-color: #1f6b48; box-shadow: 0 0 0 3px rgba(31,107,72,0.12); }
        .sp-btn {
            height: 40px; padding: 0 14px; border-radius: 10px; cursor: pointer; font-family: inherit; font-size: 13.5px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center; gap: 7px; transition: background-color .15s ease, border-color .15s ease;
        }
        .sp-btn:disabled { opacity: .6; cursor: wait; }
        .sp-btn-primary { border: none; background: #d93025; color: #fff; }
        .sp-btn-primary:hover { background: #b42318; }
        .sp-btn-danger { border: 1.5px solid #f3c7c2; background: #fff; color: #b42318; }
        .sp-btn-danger:hover { background: #fdecea; }

        .sp-legend { display: flex; flex-wrap: wrap; gap: 6px 14px; margin-top: 10px; font-size: 11.5px; color: #6b7280; }
        .sp-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .sp-lg { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }
        .sp-lg-holiday { background: #fdecea; border: 1px solid #f3c7c2; }
        .sp-lg-event { background: #d93025; }
        .sp-lg-closed { background: #f4f4f5; border: 1px solid #e4e4e7; }

        .sp-upcoming { margin-top: 14px; padding-top: 12px; border-top: 1px solid #eef1ef; }
        .sp-upcoming-head { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 800; color: #102a2a; }
        .sp-item { cursor: pointer; }
        .sp-item:hover { border-color: #d5dfd9; }
        @media (max-width: 600px) {
            .sp-cal-wrap { grid-template-columns: 1fr; }
        }
        .sp-msg { margin-top: 10px; padding: 9px 12px; border-radius: 10px; font-size: 12.5px; font-weight: 600; }
        .sp-msg.ok { background: #e7f6ec; color: #1b6b3a; }
        .sp-msg.bad { background: #fdecea; color: #b42318; }

        /* Special days: listahan */
        .sp-list { list-style: none; margin: 12px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; max-height: 230px; overflow-y: auto; }
        .sp-item {
            display: flex; align-items: center; gap: 12px; padding: 8px 10px 8px 8px;
            border: 1px solid #ebefed; border-radius: 12px; background: #fff;
            animation: spIn .25s ease;
        }
        @keyframes spIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
        .sp-date {
            width: 46px; flex-shrink: 0; border-radius: 9px; overflow: hidden; text-align: center;
            border: 1px solid #f3c7c2; background: #fff;
        }
        .sp-date-mon { display: block; background: #d93025; color: #fff; font-size: 9.5px; font-weight: 800; letter-spacing: .6px; padding: 2px 0; }
        .sp-date-day { display: block; font-size: 17px; font-weight: 800; color: #202124; line-height: 1.5; }
        .sp-info { flex: 1; min-width: 0; }
        .sp-name { font-size: 13.5px; font-weight: 700; color: #102a2a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sp-next { font-size: 11.5px; color: #6b7280; margin-top: 1px; }
        .sp-next .is-open-day { color: #b42318; font-weight: 700; }
        .sp-remove {
            width: 32px; height: 32px; flex-shrink: 0; border-radius: 8px; border: 1px solid transparent;
            background: transparent; color: #9aa3ad; cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background-color .15s ease, color .15s ease, border-color .15s ease;
        }
        .sp-remove:hover { background: #fdecea; border-color: #f3c7c2; color: #b42318; }
        .sp-remove:disabled { opacity: .5; cursor: wait; }
        .sp-empty {
            display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 14px;
            border-radius: 12px; background: #fafbfa; color: #6b7280; font-size: 12.5px; line-height: 1.5;
        }
        .sp-empty[hidden] { display: none; }
        .sp-empty i { font-size: 20px; color: #c3ccc7; }

        /* Calendar: special day (sarado ang City Hall) */
        .cal-day.special { background: #fff5f4; }
        .cal-day.special .cal-daynum { color: #b42318; }
        .tag-special { background: #fdecea; color: #b42318; }
        .lg-special { background: #fdecea; border: 1px solid #f3c7c2; }

        .btn-remove-slot {
            background: transparent; border: 1px solid #d1d5db; color: #6b7280;
            padding: 4px 10px; border-radius: 6px; font-size: 11px; cursor: pointer;
            transition: background 0.14s ease;
        }
        .btn-remove-slot:hover { background: #fce8e6; border-color: #c5221f; color: #c5221f; }

        .btn-add-slot {
            background: #1f6b48; color: #fff; border: none; padding: 8px 16px; border-radius: 8px;
            font-weight: 700; font-size: 13px; cursor: pointer; transition: background 0.14s ease;
        }
        .btn-add-slot:hover { background: #165336; }

        .empty-note { text-align: center; padding: 30px 10px; color: #9ca3af; font-size: 13px; }

        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-grid-2 .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #102a2a; }
        .form-grid-2 .form-group input {
            width: 100%; padding: 9px 10px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 14px; color: #102a2a; font-family: inherit;
        }
        @media (max-width: 560px) { .form-grid-2 { grid-template-columns: 1fr; } }

        /* Naka-book na interview: napipindot para sa Pet Meet-up / Not Attended */
        .slot-item.is-booked { flex-wrap: wrap; }
        .slot-actions {
            flex-basis: 100%; display: flex; flex-wrap: wrap; gap: 8px;
            padding-top: 10px; margin-top: 2px; border-top: 1px dashed #e5e7eb;
        }
        .slot-actions form { margin: 0; }
        .booked-modal.only-meetup .bk-missed { display: none; }
        .slot-actions .bk-btn { padding: 7px 12px; font-size: 12px; }
        .slot-state { margin-top: 4px; font-size: 11px; font-weight: 700; }
        .slot-state.st-action { color: #b45309; }
        .slot-state.st-ok { color: #1e7e34; }
        .slot-state.st-wait { color: #6b7280; }
        .slot-state.st-missed { color: #c5221f; }
        .slot-state.st-done { color: #6b7280; }

        .bk-info { display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; font-size: 13.5px; margin-bottom: 14px; }
        .bk-info dt { color: #6b7280; font-weight: 600; }
        .bk-info dd { margin: 0; color: #102a2a; font-weight: 700; overflow-wrap: anywhere; }
        .bk-box { border-radius: 12px; padding: 14px; margin-top: 12px; }
        .bk-box h4 { margin: 0 0 6px; font-size: 14.5px; font-weight: 800; }
        .bk-box p { margin: 0 0 10px; font-size: 13px; line-height: 1.5; color: #374151; }
        .bk-meetup { background: #f0f7f2; border: 1px solid #cfe5d6; }
        .bk-meetup h4 { color: #1f6b48; }
        .bk-missed { background: #fdf1f0; border: 1px solid #f3c9c4; }
        .bk-missed h4 { color: #c5221f; }
        .bk-note { background: #f6f7f9; border: 1px solid #e5e7eb; }
        .meet-rows { display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px; }
        .meet-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; }
        .meet-row input {
            width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 14px; font-family: inherit;
        }
        .meet-row button { border: 1px solid #d1d5db; background: #fff; border-radius: 8px; padding: 0 10px; cursor: pointer; color: #6b7280; }
        .bk-box textarea {
            width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 13.5px; font-family: inherit; resize: vertical; min-height: 60px; margin: 6px 0 10px;
        }
        .btn-link-add { background: none; border: none; color: #1f6b48; font-weight: 700; font-size: 13px; cursor: pointer; padding: 4px 0; }
        .bk-btn { border: none; border-radius: 8px; padding: 9px 16px; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit; }
        .bk-btn.green { background: #1f6b48; color: #fff; }
        .bk-btn.red { background: #c5221f; color: #fff; }
        .bk-btn.outline { background: #fff; color: #1f6b48; border: 1px solid #1f6b48; text-decoration: none; display: inline-block; }
        .bk-explain { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
        .bk-explain p { margin: 0; background: #fff; border: 1px solid #dcebe0; border-radius: 10px; padding: 10px 12px; }
        .bk-lang {
            display: inline-block; margin-right: 6px; padding: 1px 8px; border-radius: 10px;
            background: #1f6b48; color: #fff; font-size: 10.5px; font-weight: 800; letter-spacing: 0.3px; vertical-align: 1px;
        }
        .bk-offered-summary { font-size: 13px; color: #374151; margin: 0 0 10px; }
        .meetup-form .form-grid-2 .form-group input { box-sizing: border-box; }
        .bk-chosen { font-size: 13.5px; font-weight: 700; color: #1e7e34; margin: 0 0 8px; }
        .bk-offered { margin: 0 0 10px; padding-left: 18px; font-size: 13px; color: #374151; }
        /* Interview Requests ng adopter */
        .req-panel { margin-top: 20px; }
        .req-count {
            display: inline-block; min-width: 22px; padding: 1px 8px; margin-left: 6px; border-radius: 11px;
            background: #f59c23; color: #fff; font-size: 12px; text-align: center; vertical-align: 2px;
        }
        .req-list { display: flex; flex-direction: column; gap: 10px; max-height: 420px; overflow-y: auto; }
        .req-item {
            display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
            background: #fffaf0; border: 1px solid #f6dfb8; border-radius: 12px; padding: 10px 14px;
        }
        .req-item.is-past { background: #f8f9fa; border-color: #e5e7eb; }
        .req-date {
            width: 48px; flex-shrink: 0; border-radius: 10px; overflow: hidden; text-align: center;
            background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }
        .req-date .m { display: block; background: #1a73e8; color: #fff; font-size: 9.5px; font-weight: 800; padding: 2px 0; letter-spacing: 0.6px; }
        .req-date .d { display: block; font-size: 18px; font-weight: 800; color: #1a73e8; padding: 3px 0 4px; }
        .req-info { flex: 1; min-width: 180px; }
        .req-who { font-weight: 800; font-size: 13.5px; color: #111827; }
        .req-who span { font-weight: 600; color: #6b7280; }
        .req-when { font-size: 12.5px; color: #374151; margin-top: 2px; }
        .req-past { font-size: 11.5px; color: #b45309; font-weight: 700; margin-top: 3px; }
        .req-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .req-actions form { margin: 0; }
        .req-actions .bk-btn { padding: 7px 12px; font-size: 12px; }
        .req-decline { flex-basis: 100%; border-top: 1px dashed #f0d39b; padding-top: 10px; }
        .req-decline label { display: block; font-size: 12.5px; font-weight: 700; color: #374151; margin-bottom: 4px; }
        .req-decline label span { font-weight: 400; color: #6b7280; }
        .req-decline textarea {
            width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 13px; font-family: inherit; resize: vertical; margin-bottom: 8px;
        }
        /* Calendar: napipindot ang bawat araw; sa gilid ang mga schedule ng araw na iyon */
        button.cal-day {
            border: none; font-family: inherit; text-align: left; cursor: pointer; width: 100%;
            transition: background-color 0.15s ease, box-shadow 0.15s ease;
        }
        button.cal-day:hover { background: #eef5f0; }
        .cal-day.closed { background: #f1f1f1; }
        .cal-day.closed .cal-daynum { color: #c0c4c8; }
        .cal-day.past { opacity: 0.6; }
        .cal-day.selected, .cal-day.selected:hover { background: #1f6b48; box-shadow: none; opacity: 1; }
        .cal-day.selected .cal-daynum { color: #fff; }
        .cal-tag { display: block; font-size: 10px; font-weight: 700; padding: 2px 5px; border-radius: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tag-req { background: #fff1d6; color: #b45309; }
        .tag-booked { background: #fce8e6; color: #c5221f; }
        .cal-day.selected .cal-tag { background: rgba(255,255,255,0.2); color: #fff; }
        .cal-legend-row { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px; font-size: 12px; color: #6b7280; }
        .cal-legend-row span { display: inline-flex; align-items: center; gap: 6px; }
        .lg { width: 12px; height: 12px; border-radius: 4px; display: inline-block; }
        .lg-req { background: #fff1d6; border: 1px solid #f6c56b; }
        .lg-booked { background: #fce8e6; border: 1px solid #f3b4ae; }
        .lg-closed { background: #f1f1f1; border: 1px solid #ddd; }

        .slot-item[hidden] { display: none; }
        .badge-req { background: #fef3c7; color: #92400e; }

        /* Palit ng buwan sa tabi ng pangalan ng buwan */
        .month-switch { display: flex; align-items: center; gap: 8px; }
        .month-arrow {
            width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
            background: #fff; border: 1px solid #dcebe0; color: #1f6b48; font-size: 18px; font-weight: 700;
            text-decoration: none; transition: background-color 0.2s ease;
        }
        .month-arrow:hover { background: #eaf3ee; }

        /* Oras ng City Hall */
        .hours-bar {
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            margin-top: 20px; padding: 14px 16px; border-radius: 14px; background: #fff;
            border: 1px solid #e8ecf3;
            box-shadow: 0 8px 22px rgba(15, 27, 61, 0.05);
        }
        .hours-icon {
            width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: #eef2fb; color: #1e3a8a;
        }
        .hours-icon svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
        .hours-text { flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: 2px; }
        .hours-text strong { font-size: 14.5px; color: #0f1b3d; font-weight: 800; }
        .hours-text span { font-size: 13px; color: #6b7280; }
        .btn-edit-hours {
            display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px !important;
            border-radius: 10px !important; background: linear-gradient(135deg, #2a62de, #1e3a8a) !important;
            box-shadow: 0 6px 14px rgba(30, 58, 138, 0.25);
            transition: transform .15s ease, box-shadow .2s ease, filter .2s ease !important;
        }
        .btn-edit-hours:hover { transform: translateY(-1px); filter: brightness(1.08); box-shadow: 0 10px 20px rgba(30, 58, 138, 0.3); }
        .btn-edit-hours svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Calendar at listahan: kulay ng staff (asul) */
        .avail-panel { border: 1px solid #eef0f3; scroll-margin-top: 16px; }
        .month-title { margin: 0 4px; font-size: 18px; font-weight: 800; color: #0f1b3d; min-width: 140px; text-align: center; }
        .month-arrow { border-color: #dbe3f4 !important; color: #1e3a8a !important; border-radius: 10px !important; }
        .month-arrow:hover { background: #eef2fb !important; }
        .avail-nav a { background: #eef2fb !important; color: #1e3a8a !important; }
        .avail-nav a:hover { background: #dbe3f4 !important; }
        .avail-nav .avail-today { background: linear-gradient(135deg, #2a62de, #1e3a8a) !important; color: #fff !important; box-shadow: 0 4px 10px rgba(30, 58, 138, 0.25); }
        .cal-dow { color: #8a94a6 !important; letter-spacing: .4px; }
        .cal-day { border: 1px solid transparent; }
        button.cal-day:hover { background: #eef2fb !important; border-color: #dbe3f4; }
        .cal-day.today { background: #eef2fb !important; box-shadow: inset 0 0 0 2px #2f5bc4 !important; }
        .cal-day.today .cal-daynum { color: #1e3a8a !important; }
        .cal-day.selected, .cal-day.selected:hover {
            background: linear-gradient(135deg, #1e40af, #2f5bc4) !important; border-color: transparent;
            box-shadow: 0 8px 16px rgba(30, 64, 175, 0.28) !important;
        }
        /* Napiling araw (pati kung ngayon din): puting numero para kita sa asul */
        .cal-day.selected .cal-daynum,
        .cal-day.selected.today .cal-daynum { color: #fff !important; }
        .cal-day.selected .cal-tag { background: rgba(255, 255, 255, 0.22) !important; color: #fff !important; }
        /* Ngayon na napili: may puting singsing sa loob para makilala pa rin na "ngayon" */
        .cal-day.selected.today { box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.7), 0 8px 16px rgba(30, 64, 175, 0.28) !important; }

        /* ===== Kulay ng mga araw: mas malinaw ang pagkakaiba =====
             bukas = puti na may border | sarado = abo na may guhit-guhit
             special = pula | request = amber | accepted = berde | napili = asul */
        #staffCal .cal-day:not(.empty) { background: #ffffff; border: 1px solid #e6eaf1; }
        #staffCal .cal-daynum { color: #1f2937; font-size: 12.5px; }
        #staffCal .cal-day.closed {
            background: repeating-linear-gradient(135deg, #f4f5f7 0 6px, #eceef2 6px 12px);
            border-color: #e9ecf0;
        }
        #staffCal .cal-day.closed .cal-daynum { color: #b6bcc6; }
        #staffCal .cal-day.past:not(.selected) { opacity: .5; }
        #staffCal .cal-day.special { background: #fff1f0; border-color: #f8c9c4; }
        #staffCal .cal-day.special .cal-daynum { color: #c5221f; }
        #staffCal .tag-special { background: #fde2df; color: #b42318; border: 1px solid #f8c9c4; }
        #staffCal .tag-req { background: #fef3c7; color: #92400e; border: 1px solid #fcd98a; }
        #staffCal .tag-booked { background: #dcfce7; color: #166534; border: 1px solid #a7e3bd; }
        #staffCal .cal-tag { padding: 2px 6px; border-radius: 6px; }
        #staffCal button.cal-day:not(.selected):hover { background: #eef2fb; border-color: #c9d6f2; }
        #staffCal .cal-day.today:not(.selected) { background: #eef2fb; border-color: #2f5bc4; box-shadow: none !important; }
        #staffCal .cal-day.selected .cal-tag { border-color: transparent; }

        /* Legend: kapareho ng kulay sa calendar */
        .lg { border-radius: 4px; }
        .lg-req { background: #fef3c7 !important; border: 1px solid #f5b83d !important; }
        .lg-booked { background: #dcfce7 !important; border: 1px solid #4ade80 !important; }
        .lg-closed { background: repeating-linear-gradient(135deg, #f4f5f7 0 3px, #dfe2e7 3px 6px) !important; border: 1px solid #d5d9e0 !important; }
        .lg-special { background: #fde2df !important; border: 1px solid #f19a91 !important; }
        .cal-legend-row { color: #4b5563 !important; font-weight: 600; }

        @media (max-width: 560px) {
            .meet-row { grid-template-columns: 1fr 1fr; }
            .meet-row button { grid-column: 1 / -1; padding: 6px; }
        }
    </style>
</head>
<body class="staff-dashboard-root">
<div class="app-container">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <div class="top-header">
            <div class="search-top"></div>
            <a href="/Caps2/StaffFile/edit_profile.php" class="profile-section" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
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
            <header class="sp-page-head">
                <span class="sp-page-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect class="ic-fill" x="3.5" y="5" width="17" height="15.5" rx="2.5"/>
                        <path d="M3.5 9.5h17M8 3v4M16 3v4"/>
                        <path d="M9 14.5l2 2 4-4"/>
                    </svg>
                </span>
                <div class="sp-page-text">
                    <h1>Interview Availability</h1>
                    <p>Set the days and hours City Hall is open for interviews. Adopters pick a day and time within these hours, then you accept or decline.</p>
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

            <!-- Mga stat card: madilim hanggang maliwanag; dinadala sa calendar at listahan -->
            <div class="cards-grid sp-cards">
                <a class="stat-card sp-card sp-c1" href="#requests" title="See requests on the calendar">
                    <div class="stat-info">
                        <h1><?php echo str_pad((string) count($requests), 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Requests<br><small>Waiting for your answer</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Sobre na may tuldok (bagong hiling) -->
                        <svg viewBox="0 0 24 24">
                            <rect class="ic-fill" x="3" y="6" width="16" height="12" rx="2"/>
                            <path d="M3.5 7l7.5 5.5L18.5 7"/>
                            <circle class="ic-solid" cx="19" cy="6" r="2.6"/>
                        </svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c2" href="#requests" title="See accepted interviews">
                    <div class="stat-info">
                        <h1><?php echo str_pad((string) $bookedCount, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>Accepted<br><small>Interviews</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Badge na may check -->
                        <svg viewBox="0 0 24 24">
                            <path class="ic-fill" d="M12 3l2.4 1.7 2.9-.1.9 2.8 2.4 1.7-1 2.8 1 2.8-2.4 1.7-.9 2.8-2.9-.1L12 21l-2.4-1.7-2.9.1-.9-2.8L3.4 15l1-2.8-1-2.8 2.4-1.7.9-2.8 2.9.1z"/>
                            <path d="M8.8 12.2l2.2 2.2 4.2-4.4"/>
                        </svg>
                    </div>
                </a>
                <a class="stat-card sp-card sp-c4" href="#calendar" title="See this week on the calendar">
                    <div class="stat-info">
                        <h1><?php echo str_pad((string) $thisWeekCount, 2, '0', STR_PAD_LEFT); ?></h1>
                        <p>This Week<br><small>Interviews scheduled</small></p>
                    </div>
                    <div class="sp-icon" aria-hidden="true">
                        <!-- Calendar na may linggo -->
                        <svg viewBox="0 0 24 24">
                            <rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/>
                            <path d="M3.5 9.5h17M8 3v4M16 3v4"/>
                            <rect class="ic-solid" x="6.5" y="12.5" width="11" height="3" rx="1.2"/>
                        </svg>
                    </div>
                </a>
            </div>

            <!-- Oras ng City Hall na itinakda ng staff -->
            <div class="hours-bar">
                <span class="hours-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 9.5L12 4l9 5.5"/>
                        <path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3.5 20.5h17"/>
                    </svg>
                </span>
                <div class="hours-text">
                    <strong>City Hall interview hours</strong>
                    <span><?php echo htmlspecialchars($hoursLabel); ?></span>
                </div>
                <button type="button" class="btn-add-slot btn-edit-hours" onclick="openAddSlotModal()">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M13.5 8.5l3 3"/></svg>
                    Edit Hours
                </button>
                <?php // Emergency: ililipat ang lahat ng interview sa isang araw at aabisuhan ang mga adopter ?>
                <button type="button" class="btn-emergency" onclick="openEmergencyModal()">
                    <i class="fa-solid fa-triangle-exclamation"></i> Emergency
                </button>
            </div>

            <?php
            // Bilang ng interview bawat araw (para makita ng staff kung ilan ang maaapektuhan)
            $emCounts = [];
            try {
                $emStmt = $pdo->query(
                    "SELECT preferred_interview_date d, COUNT(*) c FROM adoption_applications
                     WHERE application_status IN ('Pending', 'Under Review') AND interview_missed_at IS NULL
                       AND (schedule_status IS NULL OR schedule_status IN ('Pending', 'Accepted'))
                       AND preferred_interview_date >= CURDATE()
                     GROUP BY preferred_interview_date"
                );
                foreach ($emStmt as $r) { $emCounts[$r['d']] = (int) $r['c']; }
            } catch (Exception $e) {}
            // Susunod na araw na bukas ang City Hall (default na lilipatan)
            $emSettings = sp_interview_settings($pdo);
            $emNext = date('Y-m-d', strtotime('+1 day'));
            for ($i = 1; $i <= 30; $i++) {
                $d = date('Y-m-d', strtotime("+$i day"));
                if (in_array((int) date('N', strtotime($d)), $emSettings['days'], true) && sp_interview_special_on($pdo, $d) === null) { $emNext = $d; break; }
            }
            ?>
            <div class="em-backdrop" id="emergencyModal" role="dialog" aria-modal="true" aria-labelledby="emTitle" hidden>
                <form class="em-card" method="POST" action="process_interview_emergency.php"
                      data-confirm="All interviews on the chosen day will be moved, and every affected adopter will get a notification, a message, and an email."
                      data-confirm-title="Send emergency notice?" data-confirm-ok="Yes, move interviews" data-confirm-tone="red" data-confirm-icon="fa-triangle-exclamation">
                    <div class="em-head">
                        <span class="em-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <div>
                            <h3 id="emTitle">Emergency: Move Interviews</h3>
                            <p>Use this when City Hall cannot hold interviews on a day. Every adopter with an interview that day is notified with your reason and moved to a new day.</p>
                        </div>
                        <button type="button" class="em-x" onclick="closeEmergencyModal()" aria-label="Close">&times;</button>
                    </div>
                    <label class="em-field">Day affected
                        <input type="date" name="from_date" id="emFrom" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                        <small id="emCount"></small>
                    </label>
                    <label class="em-field">Reason (sent to the adopters)
                        <textarea name="reason" rows="3" maxlength="500" required placeholder="e.g., City Hall is closed today due to a power outage."></textarea>
                    </label>
                    <label class="em-field">Move interviews to
                        <input type="date" name="to_date" id="emTo" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo $emNext; ?>" required>
                        <small>Each adopter keeps the same time on this day. If that time is already taken, the adopter is asked to choose another time or day.</small>
                    </label>
                    <div class="em-actions">
                        <button type="button" class="em-cancel" onclick="closeEmergencyModal()">Cancel</button>
                        <button type="submit" class="em-submit"><i class="fa-solid fa-paper-plane"></i> Notify and Move</button>
                    </div>
                </form>
            </div>
            <style>
                .btn-emergency { display: inline-flex; align-items: center; gap: 8px; margin-left: 8px; padding: 10px 16px; border: none; border-radius: 10px; background: #dc2626; color: #fff; font-weight: 700; font-family: inherit; font-size: 14px; cursor: pointer; box-shadow: 0 6px 14px rgba(220, 38, 38, .25); }
                .btn-emergency:hover { background: #b91c1c; }
                .em-backdrop { position: fixed; inset: 0; z-index: 9000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, .55); backdrop-filter: blur(4px); }
                .em-backdrop[hidden] { display: none; }
                .em-card { width: 100%; max-width: 520px; max-height: calc(100vh - 32px); overflow-y: auto; background: #fff; border-radius: 18px; padding: 20px 22px; box-shadow: 0 24px 60px rgba(0, 0, 0, .3); display: flex; flex-direction: column; gap: 12px; }
                .em-head { display: flex; gap: 12px; align-items: flex-start; }
                .em-icon { width: 42px; height: 42px; flex-shrink: 0; border-radius: 12px; display: grid; place-items: center; background: #fee2e2; color: #dc2626; font-size: 18px; }
                .em-head h3 { margin: 0 0 4px; font-size: 17px; color: #1e293b; }
                .em-head p { margin: 0; font-size: 13px; color: #64748b; line-height: 1.45; }
                .em-x { margin-left: auto; width: 34px; height: 34px; border: none; border-radius: 50%; background: #f1f5f9; font-size: 20px; cursor: pointer; }
                .em-field { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 700; color: #334155; }
                .em-field input, .em-field textarea { font-family: inherit; font-size: 14px; font-weight: 400; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 10px; resize: vertical; }
                .em-field small { font-weight: 400; color: #64748b; font-size: 12px; line-height: 1.4; }
                #emCount { color: #b91c1c; font-weight: 700; }
                .em-actions { display: flex; justify-content: flex-end; gap: 10px; }
                .em-cancel { padding: 10px 16px; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; font-weight: 700; font-family: inherit; cursor: pointer; }
                .em-submit { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border: none; border-radius: 10px; background: #dc2626; color: #fff; font-weight: 700; font-family: inherit; cursor: pointer; }
                @media (max-width: 600px) { .btn-emergency { margin: 8px 0 0; width: 100%; justify-content: center; } }
            </style>
            <script>
                (function () {
                    const counts = <?php echo json_encode((object) $emCounts); ?>;
                    const from = document.getElementById('emFrom'), out = document.getElementById('emCount');
                    function show() {
                        const n = counts[from.value] || 0;
                        out.textContent = n ? n + ' interview' + (n === 1 ? '' : 's') + ' on this day will be moved.' : 'No interviews are scheduled on this day.';
                    }
                    from.addEventListener('change', show);
                    // Ang lilipatan ay laging pagkatapos ng apektadong araw
                    from.addEventListener('change', function () {
                        const to = document.getElementById('emTo');
                        const next = new Date(from.value + 'T00:00:00'); next.setDate(next.getDate() + 1);
                        const iso = next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0') + '-' + String(next.getDate()).padStart(2, '0');
                        to.min = iso;
                        if (!to.value || to.value <= from.value) to.value = iso;
                    });
                    window.openEmergencyModal = function () { show(); document.getElementById('emergencyModal').hidden = false; };
                    window.closeEmergencyModal = function () { document.getElementById('emergencyModal').hidden = true; };
                    document.getElementById('emergencyModal').addEventListener('click', function (e) { if (e.target === this) closeEmergencyModal(); });
                })();
            </script>

            <div class="avail-grid-container">
                <div class="avail-panel" id="calendar">
                    <div class="avail-header">
                        <!-- Palit ng buwan (ang ‹ Today › sa kanan ay palit ng araw) -->
                        <div class="month-switch">
                            <a href="?month=<?php echo urlencode($prevMonth); ?>#requests" class="month-arrow" aria-label="Previous month" title="Previous month">‹</a>
                            <h3 class="month-title"><?php echo htmlspecialchars($monthLabel); ?></h3>
                            <a href="?month=<?php echo urlencode($nextMonth); ?>#requests" class="month-arrow" aria-label="Next month" title="Next month">›</a>
                        </div>
                        <div class="avail-nav">
                            <a href="#" data-day-step="-1" aria-label="Previous day">‹</a>
                            <a href="#" data-day-step="0" class="avail-today">Today</a>
                            <a href="#" data-day-step="1" aria-label="Next day">›</a>
                        </div>
                    </div>

                    <div class="cal-grid" id="staffCal">
                        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
                            <div class="cal-dow"><?php echo $dow; ?></div>
                        <?php endforeach; ?>

                        <?php for ($i = 0; $i < $startWeekday; $i++): ?>
                            <div class="cal-day empty"></div>
                        <?php endfor; ?>

                        <?php for ($day = 1; $day <= $daysInMonth; $day++):
                            $dateStr = $monthParam . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                            $isOpenDay = in_array((int) date('N', strtotime($dateStr)), $settings['days'], true);
                            $specialName = sp_interview_special_on($pdo, $dateStr);
                            $nReq = count($requestsByDate[$dateStr] ?? []);
                            $nBooked = count($slotsByDate[$dateStr] ?? []);
                            $cls = 'cal-day';
                            if ($dateStr === $todayStr) $cls .= ' today';
                            if ($dateStr === $selectedDate) $cls .= ' selected';
                            if ($specialName !== null) $cls .= ' special';
                            elseif (!$isOpenDay) $cls .= ' closed';
                            if ($dateStr < $todayStr) $cls .= ' past';
                        ?>
                            <button type="button" class="<?php echo $cls; ?>" data-date="<?php echo $dateStr; ?>"<?php echo $specialName !== null ? ' title="Special day: ' . htmlspecialchars($specialName) . ' (City Hall closed)"' : ''; ?>>
                                <span class="cal-daynum"><?php echo $day; ?></span>
                                <?php if ($specialName !== null): ?>
                                    <span class="cal-tag tag-special"><?php echo htmlspecialchars($specialName); ?></span>
                                <?php endif; ?>
                                <?php if ($nReq): ?>
                                    <span class="cal-tag tag-req"><?php echo $nReq; ?> request<?php echo $nReq === 1 ? '' : 's'; ?></span>
                                <?php endif; ?>
                                <?php if ($nBooked): ?>
                                    <span class="cal-tag tag-booked"><?php echo $nBooked; ?> interview<?php echo $nBooked === 1 ? '' : 's'; ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                    <div class="cal-legend-row">
                        <span><i class="lg lg-req"></i> Waiting for your answer</span>
                        <span><i class="lg lg-booked"></i> Accepted interview</span>
                        <span><i class="lg lg-closed"></i> City Hall closed</span>
                        <span><i class="lg lg-special"></i> Special day</span>
                    </div>
                </div>

                <!-- Mga schedule ng napiling araw: request (Accept/Decline) at mga tinanggap na interview -->
                <div class="avail-panel" id="requests">
                    <h3 style="margin:0 0 2px 0; font-size:16px; font-weight:800;">🗓️ <span id="dayTitle"><?php echo htmlspecialchars(date('l, F j, Y', strtotime($selectedDate))); ?></span></h3>
                    <span style="font-size:12px; color:#9ca3af; display:block; margin-bottom:14px;">Tap a day on the calendar to see who scheduled on that day. The first adopter to send a time gets it, so times here never overlap.</span>

                    <div class="slot-list" id="dayList">
                        <?php foreach ($requests as $r): $rid = (int) $r['application_id']; ?>
                            <div class="slot-item req-item<?php echo $r['is_past'] ? ' is-past' : ''; ?>" data-date="<?php echo $r['preferred_interview_date']; ?>">
                                <div class="req-info">
                                    <div class="slot-date"><?php echo htmlspecialchars(date('M d, Y', strtotime($r['preferred_interview_date']))); ?></div>
                                    <div class="slot-time"><?php echo htmlspecialchars(date('g:i A', strtotime($r['preferred_interview_time'])) . ' - ' . date('g:i A', strtotime($r['preferred_interview_time']) + SP_INTERVIEW_MINUTES * 60)); ?></div>
                                    <div class="slot-who"><?php echo htmlspecialchars($r['full_name']); ?> for <?php echo htmlspecialchars($r['pet_name'] ?? 'a pet'); ?></div>
                                    <div class="slot-state st-action"><?php echo $r['is_past'] ? 'This time has passed - decline it so the adopter can choose a new date' : 'Waiting for your answer'; ?></div>
                                </div>
                                <span class="badge-slot badge-req">Request</span>
                                <div class="slot-actions">
                                    <?php if (!$r['is_past']): ?>
                                    <form method="POST" action="/Caps2/StaffFile/process_review_application.php">
                                        <input type="hidden" name="application_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="action" value="accept_schedule">
                                        <input type="hidden" name="return" value="requests">
                                        <input type="hidden" name="return_date" value="<?php echo $r['preferred_interview_date']; ?>">
                                        <button type="submit" class="bk-btn green">Approve</button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="bk-btn red" onclick="toggleDecline(<?php echo $rid; ?>)">Reject</button>
                                    <a class="bk-btn outline" href="review_application.php?application_id=<?php echo $rid; ?>">View</a>
                                </div>
                                <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="req-decline" id="decline-<?php echo $rid; ?>" hidden>
                                    <input type="hidden" name="application_id" value="<?php echo $rid; ?>">
                                    <input type="hidden" name="action" value="decline_schedule">
                                    <input type="hidden" name="return" value="requests">
                                    <input type="hidden" name="return_date" value="<?php echo $r['preferred_interview_date']; ?>">
                                    <label for="decline-reason-<?php echo $rid; ?>">Why can't you accept this schedule? <span>(sent to the adopter)</span></label>
                                    <textarea id="decline-reason-<?php echo $rid; ?>" name="decline_reason" rows="2" maxlength="1000" required placeholder="e.g., We have a city event on this day. Please choose another date."></textarea>
                                    <button type="submit" class="bk-btn red">Send Reason and Reject</button>
                                </form>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($slots as $s): ?>
                            <div class="slot-item is-booked" data-date="<?php echo $s['slot_date']; ?>">
                                <div>
                                    <div class="slot-date"><?php echo htmlspecialchars(date('M d, Y', strtotime($s['slot_date']))); ?></div>
                                    <div class="slot-time"><?php echo htmlspecialchars(date('g:i A', strtotime($s['start_time'])) . ' - ' . date('g:i A', strtotime($s['end_time']))); ?></div>
                                    <?php $st = booked_state($s); ?>
                                    <div class="slot-who"><?php echo htmlspecialchars($s['booked_by']); ?> for <?php echo htmlspecialchars($s['pet_name'] ?? 'a pet'); ?></div>
                                    <div class="slot-state <?php echo $st['class']; ?>"><?php echo htmlspecialchars($st['label']); ?></div>
                                </div>
                                <span class="badge-slot badge-booked">Accepted</span>

                                <?php
                                // Mga button sa ilalim ng tinanggap na interview (pagkatapos ng oras nito)
                                $canAct = in_array($s['application_status'], ['Pending', 'Under Review'], true) && (int) $s['starts_in'] <= 0;
                                ?>
                                <?php if ($canAct && empty($s['interview_missed_at'])): ?>
                                    <div class="slot-actions">
                                        <button type="button" class="bk-btn green" onclick="openBookedModal(<?php echo (int) $s['slot_id']; ?>, 'meetup')">
                                            <?php echo !empty($s['meetup_sent_at']) ? 'Send New Meet-up Schedule' : 'Send Meet-up Schedule'; ?>
                                        </button>
                                        <?php if (empty($s['meetup_date'])): ?>
                                        <form method="POST" action="/Caps2/StaffFile/process_review_application.php" onsubmit="return confirm('Mark this adopter as not attended? They will be notified and asked to contact you.');">
                                            <input type="hidden" name="application_id" value="<?php echo (int) $s['application_id']; ?>">
                                            <input type="hidden" name="action" value="mark_no_show">
                                            <input type="hidden" name="return" value="availability">
                                            <button type="submit" class="bk-btn red">Not Attended</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($canAct): ?>
                                    <div class="slot-actions">
                                        <form method="POST" action="/Caps2/StaffFile/process_review_application.php">
                                            <input type="hidden" name="application_id" value="<?php echo (int) $s['application_id']; ?>">
                                            <input type="hidden" name="action" value="send_rebook_link">
                                            <input type="hidden" name="return" value="availability">
                                            <button type="submit" class="bk-btn red"><?php echo empty($s['rebook_sent_at']) ? 'Send Reschedule Link' : 'Resend Reschedule Link'; ?></button>
                                        </form>
                                        <form method="POST" action="process_delete_slot.php" onsubmit="return confirm('Remove this missed interview from the list? The application stays, and the adopter can still reschedule using the link.');">
                                            <input type="hidden" name="slot_id" value="<?php echo (int) $s['slot_id']; ?>">
                                            <input type="hidden" name="mode" value="missed">
                                            <button type="submit" class="bk-btn outline">Remove</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <div class="slot-actions">
                                        <a class="bk-btn outline" href="review_application.php?application_id=<?php echo (int) $s['application_id']; ?>">View</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <p class="empty-note" id="dayEmpty" hidden>No one has scheduled an interview on this day.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Mga naka-book na interview: Pet Meet-up schedule o Not Attended -->
<?php foreach ($slots as $s):
    if ($s['status'] !== 'Booked' || empty($s['application_id'])) continue;
    $appId = (int) $s['application_id'];
    $decided = !in_array($s['application_status'], ['Pending', 'Under Review'], true);
    $passed = (int) $s['starts_in'] <= 0;
    $offered = $meetupOptions[$appId] ?? [];
    $petLabel = $s['pet_name'] ?? 'the pet';
?>
<div id="bookedModal-<?php echo (int) $s['slot_id']; ?>" class="modal-backdrop booked-modal" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card" role="document" style="max-width:560px;">
        <div class="modal-header">
            <div class="modal-title">Booked Interview</div>
            <button type="button" class="btn btn-ghost" onclick="closeBookedModal()" aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <dl class="bk-info">
                <dt>Applicant</dt><dd><?php echo htmlspecialchars($s['booked_by']); ?></dd>
                <dt>Pet</dt><dd><?php echo htmlspecialchars($petLabel); ?></dd>
                <dt>Interview</dt><dd><?php echo htmlspecialchars(date('M j, Y', strtotime($s['slot_date'])) . ', ' . date('g:i A', strtotime($s['start_time'])) . ' - ' . date('g:i A', strtotime($s['end_time']))); ?></dd>
                <dt>Status</dt><dd><?php echo htmlspecialchars($s['application_status']); ?></dd>
                <?php if (!empty($s['meeting_link'])): ?>
                <dt>Meeting</dt><dd><a href="<?php echo htmlspecialchars($s['meeting_link']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($s['meeting_link']); ?></a></dd>
                <?php endif; ?>
            </dl>
            <a class="bk-btn outline" href="review_application.php?application_id=<?php echo $appId; ?>">Open Application</a>

            <?php if ($decided): ?>
                <div class="bk-box bk-note"><p>This application is already <strong><?php echo htmlspecialchars($s['application_status']); ?></strong>.</p></div>

            <?php elseif (!$passed): ?>
                <div class="bk-box bk-note">
                    <p>The interview has not started yet. After the interview, come back here to send the pet meet-up schedule, or mark the adopter as not attended.</p>
                </div>

            <?php elseif (!empty($s['interview_missed_at'])): ?>
                <div class="bk-box bk-missed">
                    <h4>Did not attend</h4>
                    <?php if (empty($s['rebook_sent_at'])): ?>
                        <p>The adopter was told they did not attend and was asked to contact you in Messages. Once they reach out, send them a reschedule link. It opens their My Preference page, where they can pick a new interview time.</p>
                    <?php else: ?>
                        <p>Reschedule link sent on <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($s['rebook_sent_at']))); ?>. Waiting for the adopter to pick a new interview time.</p>
                    <?php endif; ?>
                    <form method="POST" action="/Caps2/StaffFile/process_review_application.php">
                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                        <input type="hidden" name="action" value="send_rebook_link">
                        <input type="hidden" name="return" value="availability">
                        <button type="submit" class="bk-btn red"><?php echo empty($s['rebook_sent_at']) ? 'Send Reschedule Link' : 'Resend Reschedule Link'; ?></button>
                    </form>
                </div>

            <?php else: ?>
                <div class="bk-box bk-meetup">
                    <h4>🐾 Schedule a Pet Meet-up</h4>
                    <div class="bk-explain">
                        <p lang="en">
                            <span class="bk-lang">English</span>
                            This schedule is for the adopter to <strong>visit the shelter at Caloocan City Hall and meet <?php echo htmlspecialchars($petLabel); ?> in person</strong>.
                            Only send it if you decided after the interview that the adopter is a good fit. If you will reject the application, you do not need to send a meet-up schedule.
                            The days and hours below are filled in from the City Hall schedule. The adopter will choose one day in the next <?php echo SP_MEETUP_WINDOW_DAYS; ?> days (special days are skipped) and can come anytime while City Hall is open.
                        </p>
                        <p lang="tl">
                            <span class="bk-lang">Tagalog</span>
                            Ang schedule na ito ay para <strong>pumunta ang adopter sa shelter sa Caloocan City Hall at makita nang personal si <?php echo htmlspecialchars($petLabel); ?></strong>.
                            Ipadala lang ito kung napagpasyahan mo pagkatapos ng interview na pasado ang adopter. Kung ire-reject mo ang application, hindi na kailangang magpadala ng meet-up schedule.
                            Kusang nakalagay ang mga araw at oras mula sa schedule ng City Hall. Pipili ang adopter ng isang araw sa susunod na <?php echo SP_MEETUP_WINDOW_DAYS; ?> araw (laktaw ang special days) at puwede siyang pumunta anumang oras habang bukas ang City Hall.
                        </p>
                    </div>

                    <?php if (!empty($s['meetup_date'])): ?>
                        <p class="bk-chosen">✔ The adopter chose <?php echo htmlspecialchars(meetup_label($s['meetup_date'], $s['meetup_time'], $s['meetup_end'] ?? null)); ?>.</p>
                    <?php elseif ($offered): ?>
                        <?php
                        $firstO = $offered[0];
                        $lastO = $offered[count($offered) - 1];
                        ?>
                        <p class="bk-offered-summary">
                            <strong>Sent on <?php echo htmlspecialchars(date('M j, g:i A', strtotime($s['meetup_sent_at']))); ?></strong> - waiting for the adopter to choose from
                            <?php echo count($offered); ?> open day<?php echo count($offered) === 1 ? '' : 's'; ?>
                            (<?php echo htmlspecialchars(date('M j', strtotime($firstO['meet_date'])) . ($lastO['meet_date'] !== $firstO['meet_date'] ? ' to ' . date('M j', strtotime($lastO['meet_date'])) : '')); ?>,
                            <?php echo htmlspecialchars(date('g:i A', strtotime($firstO['meet_time'])) . (!empty($firstO['meet_end_time']) ? ' - ' . date('g:i A', strtotime($firstO['meet_end_time'])) : '')); ?>).
                        </p>
                    <?php endif; ?>

                    <!-- Kapareho ng Weekly Schedule ng City Hall: mga araw na bukas, oras ng bukas at sara -->
                    <form method="POST" action="/Caps2/StaffFile/process_review_application.php" class="meetup-form">
                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                        <input type="hidden" name="action" value="send_meetup">
                        <input type="hidden" name="return" value="availability">
                        <div class="form-grid-2">
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label>Open Days *</label>
                                <div class="day-picks">
                                    <?php foreach ($dayNames as $i => $dn): ?>
                                        <label class="day-pick"><input type="checkbox" name="meet_days[]" value="<?php echo $i; ?>"<?php echo in_array($i, $settings['days'], true) ? ' checked' : ''; ?>><span><?php echo $dn; ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>City Hall Opens *</label>
                                <input type="time" name="meet_open" class="mu-open" value="<?php echo htmlspecialchars($settings['open']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>City Hall Closes *</label>
                                <input type="time" name="meet_close" class="mu-close" value="<?php echo htmlspecialchars($settings['close']); ?>" required>
                            </div>
                        </div>
                        <div class="slot-preview mu-preview"></div>
                        <label style="display:block; font-size:13px; font-weight:600; margin-top:10px;">Note for the adopter (optional)</label>
                        <textarea name="meetup_note" maxlength="500" placeholder="e.g., Please bring a valid ID and a leash."></textarea>
                        <button type="submit" class="bk-btn green"><?php echo !empty($s['meetup_sent_at']) ? 'Send New Meet-up Schedule' : 'Send Meet-up Schedule'; ?></button>
                    </form>
                </div>

                <?php if (empty($s['meetup_date'])): ?>
                <div class="bk-box bk-missed">
                    <h4>Adopter did not attend?</h4>
                    <p>The adopter will be told they did not attend the interview and asked to contact you in Messages. You can then send them a reschedule link.</p>
                    <form method="POST" action="/Caps2/StaffFile/process_review_application.php" onsubmit="return confirm('Mark this adopter as not attended? They will be notified and asked to contact you.');">
                        <input type="hidden" name="application_id" value="<?php echo $appId; ?>">
                        <input type="hidden" name="action" value="mark_no_show">
                        <input type="hidden" name="return" value="availability">
                        <button type="submit" class="bk-btn red">Not Attended</button>
                    </form>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Oras ng City Hall: mga araw na bukas at oras ng bukas at sara -->
<div id="addSlotModal" class="modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-card hours-modal" role="document" style="max-width:680px;">
        <div class="modal-header">
            <div class="hm-title-wrap">
                <span class="hm-title-icon" aria-hidden="true"><i class="fa-solid fa-building-columns"></i></span>
                <div>
                    <div class="modal-title">City Hall Interview Hours</div>
                    <div class="hm-subtitle">Set when adopters can request an interview</div>
                </div>
            </div>
            <button type="button" class="btn btn-ghost" onclick="closeAddSlotModal()" aria-label="Close">✕</button>
        </div>
        <form action="process_add_slot.php" method="POST" id="hoursForm">
            <div class="modal-body">
                <!-- ===== Lingguhang oras ===== -->
                <section class="hm-section">
                <div class="hm-section-head">
                    <span class="hm-step">1</span>
                    <div>
                        <h4>Weekly Schedule</h4>
                        <p>The days and hours City Hall is open for interviews. Adopters cannot choose a time that falls on your break.</p>
                    </div>
                </div>
                <div class="form-grid-2">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Open Days *</label>
                        <div class="day-picks">
                            <?php foreach ($dayNames as $i => $dn): ?>
                                <label class="day-pick"><input type="checkbox" name="days[]" value="<?php echo $i; ?>"<?php echo in_array($i, $settings['days'], true) ? ' checked' : ''; ?>><span><?php echo $dn; ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>City Hall Opens *</label>
                        <input type="time" name="open_time" value="<?php echo htmlspecialchars($settings['open']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>City Hall Closes *</label>
                        <input type="time" name="close_time" value="<?php echo htmlspecialchars($settings['close']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Break Time</label>
                        <input type="time" name="break_start" value="<?php echo htmlspecialchars((string) $settings['breakStart']); ?>">
                        <small class="slot-small">Leave empty if there is no break.</small>
                    </div>
                    <div class="form-group">
                        <label>Break Length</label>
                        <div class="day-picks">
                            <label class="day-pick"><input type="radio" name="break_minutes" value="30"<?php echo $settings['breakMinutes'] !== 60 ? ' checked' : ''; ?>><span>30 min</span></label>
                            <label class="day-pick"><input type="radio" name="break_minutes" value="60"<?php echo $settings['breakMinutes'] === 60 ? ' checked' : ''; ?>><span>1 hour</span></label>
                        </div>
                    </div>
                </div>
                <div class="slot-preview" id="hoursPreview"></div>
                </section>

                <!-- ===== Special days: sarado kahit bukas na araw ng linggo =====
                     Walang name ang mga input dito, kaya hindi kasama sa pag-save ng
                     oras; sariling fetch ang gamit (process_special_day.php). -->
                <section class="hm-section" id="specialDays">
                <div class="hm-section-head">
                    <span class="hm-step">2</span>
                    <div>
                        <h4>Special Days</h4>
                        <p>Philippine holidays are already blocked every year. Tap a date on the calendar to mark a day when City Hall is not available because of an event. Adopters see these days in red and are told what the special day is.</p>
                    </div>
                </div>

                <div class="sp-cal-wrap">
                    <!-- Calendar: pindutin ang araw -->
                    <div class="sp-cal" id="spCal" aria-label="Special days calendar"></div>

                    <!-- Detalye ng napiling araw -->
                    <div class="sp-detail" id="spDetail" aria-live="polite"></div>
                </div>

                <div class="sp-legend">
                    <span><i class="sp-lg sp-lg-holiday"></i> Holiday (automatic)</span>
                    <span><i class="sp-lg sp-lg-event"></i> Not available (City Hall event)</span>
                    <span><i class="sp-lg sp-lg-closed"></i> Closed day of the week</span>
                </div>

                <div class="sp-msg" id="spMsg" role="status" aria-live="polite" hidden></div>

                <div class="sp-upcoming">
                    <div class="sp-upcoming-head">
                        <span>Upcoming not-available days</span>
                        <span class="hm-count" id="spCount">0</span>
                    </div>
                    <ul class="sp-list" id="spList" aria-label="Upcoming not-available days"></ul>
                    <div class="sp-empty" id="spEmpty" hidden>
                        <i class="fa-regular fa-calendar-check"></i>
                        <span>No City Hall events yet. Tap a date on the calendar to mark it as not available.</span>
                    </div>
                </div>
                </section>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeAddSlotModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Hours</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Interview Requests: ipakita ang dahilan ng pag-decline
    function toggleDecline(id) {
        const form = document.getElementById('decline-' + id);
        if (!form) return;
        form.hidden = !form.hidden;
        if (!form.hidden) form.querySelector('textarea').focus();
    }
    function openAddSlotModal() {
        const modal = document.getElementById('addSlotModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeAddSlotModal() {
        const modal = document.getElementById('addSlotModal');
        if (!modal.classList.contains('show')) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        // May binago sa special days: i-refresh para lumabas sa calendar
        if (window.spSpecialChanged) location.reload();
    }
    // Naka-book na interview: Pet Meet-up / Not Attended
    // mode 'meetup' = galing sa "Send Meet-up Schedule" button: ang meet-up form lang ang ipapakita
    function openBookedModal(slotId, mode) {
        const modal = document.getElementById('bookedModal-' + slotId);
        if (!modal) return;
        modal.classList.toggle('only-meetup', mode === 'meetup');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeBookedModal() {
        document.querySelectorAll('.booked-modal.show').forEach(function (m) {
            m.classList.remove('show');
            m.setAttribute('aria-hidden', 'true');
        });
    }
    // Pet Meet-up: silip ng mga araw na magiging bukas para sa pagbisita
    // (kaparehong pagbilang ng send_meetup sa process_review_application.php)
    document.querySelectorAll('.meetup-form').forEach(function (form) {
        const preview = form.querySelector('.mu-preview');
        const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const SPECIAL = <?php echo json_encode((object) sp_interview_special_map($pdo, (int) date('Y'), (int) date('Y') + 1), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        function toLabel(hhmm) {
            const p = hhmm.split(':');
            let h = parseInt(p[0], 10);
            const ap = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + p[1] + ' ' + ap;
        }

        // Mga araw na mapipili ng adopter: ngayon hanggang sa susunod na <?php echo SP_MEETUP_WINDOW_DAYS; ?> araw
        const WINDOW = <?php echo SP_MEETUP_WINDOW_DAYS; ?>;
        function render() {
            const open = form.querySelector('.mu-open').value;
            const close = form.querySelector('.mu-close').value;
            // ISO: 1 = Lunes ... 7 = Linggo
            const days = Array.prototype.map.call(form.querySelectorAll('input[name="meet_days[]"]:checked'), function (c) { return parseInt(c.value, 10); });

            if (!open || !close) { preview.innerHTML = ''; return; }
            if (close <= open) { preview.innerHTML = '<span class="slot-preview-warn">The closing time must be later than the opening time.</span>'; return; }
            if (!days.length) { preview.innerHTML = '<span class="slot-preview-warn">Choose at least one open day.</span>'; return; }

            const now = new Date();
            const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const nowHHMM = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
            const chips = [];
            for (let i = 0; i <= WINDOW; i++) {
                const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                if (days.indexOf(d.getDay() || 7) === -1) continue;
                if (i === 0 && close <= nowHHMM) continue; // sarado na ngayong araw
                // Special day (hal. Dec 25): sarado ang City Hall, hindi iaalok
                if (SPECIAL[d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')]) continue;
                chips.push(DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate());
            }
            if (!chips.length) { preview.innerHTML = '<span class="slot-preview-warn">City Hall has no open day in the next ' + WINDOW + ' days.</span>'; return; }

            preview.innerHTML = '<div class="slot-preview-head">The adopter can choose from ' + chips.length + ' open day' + (chips.length === 1 ? '' : 's')
                + ' · ' + toLabel(open) + ' - ' + toLabel(close) + '</div>'
                + chips.map(function (c) { return '<span class="slot-chip">' + c + '</span>'; }).join('');
        }

        form.addEventListener('input', render);
        form.addEventListener('change', render);
        render();
    });
    document.querySelectorAll('.booked-modal').forEach(function (m) {
        m.addEventListener('mousedown', function (e) { if (e.target === m) closeBookedModal(); });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeBookedModal();
        if (e.key === 'Escape') closeAddSlotModal();
    });

    // Silip ng oras na mapipili ng adopter (kaparehong patakaran ng connection/interview_schedule.php)
    (function () {
        const form = document.getElementById('hoursForm');
        const preview = document.getElementById('hoursPreview');
        const LENGTH = <?php echo (int) SP_INTERVIEW_MINUTES; ?>;
        function mins(v) { if (!v) return null; const p = v.split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1], 10); }
        function label(m) { const h = Math.floor(m / 60), mm = m % 60; return (h % 12 || 12) + ':' + String(mm).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM'); }
        function render() {
            const open = mins(form.open_time.value), close = mins(form.close_time.value);
            const bs = mins(form.break_start.value);
            const bLen = parseInt((form.querySelector('input[name="break_minutes"]:checked') || {}).value || '30', 10);
            const days = form.querySelectorAll('input[name="days[]"]:checked').length;
            if (!days) { preview.innerHTML = '<span class="slot-preview-warn">Choose at least one day.</span>'; return; }
            if (open === null || close === null || close - open < LENGTH) {
                preview.innerHTML = '<span class="slot-preview-warn">The closing time must be at least 1 hour and 30 minutes after the opening time.</span>';
                return;
            }
            if (bs !== null && (bs < open || bs + bLen > close)) {
                preview.innerHTML = '<span class="slot-preview-warn">The break must be within City Hall hours.</span>';
                return;
            }
            preview.innerHTML = '<div class="slot-preview-head">City Hall is open from ' + label(open) + ' to ' + label(close) + '.'
                + (bs !== null ? ' Staff break: ' + label(bs) + ' to ' + label(bs + bLen) + '. Adopters cannot pick a time that falls on the break.' : '')
                + ' Each interview takes 1 hour and 30 minutes.</div>';
        }
        form.addEventListener('input', render);
        form.addEventListener('change', render);
        render();
    })();

    // Special days: calendar sa modal. Kusang sarado ang mga holiday; pinipili ng
    // staff ang araw na may ganap sa City Hall (process_special_day.php, hiwalay sa "Save Hours")
    (function () {
        const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const OPEN_DAYS = <?php echo json_encode(array_values($settings['days'])); ?>; // 1 = Lunes ... 7 = Linggo
        const HOLIDAYS = <?php echo json_encode((object) $holidayMap, JSON_HEX_TAG | JSON_HEX_AMP); ?>; // 'Y-m-d' => pangalan
        let closures = <?php echo json_encode($closures, JSON_HEX_TAG | JSON_HEX_AMP); ?>; // [{id, date, name}]

        const cal = document.getElementById('spCal');
        const detail = document.getElementById('spDetail');
        const list = document.getElementById('spList');
        const empty = document.getElementById('spEmpty');
        const count = document.getElementById('spCount');
        const msg = document.getElementById('spMsg');
        let msgTimer = null;

        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
        function parse(s) { const p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
        function longDate(s) { const d = parse(s); return WEEKDAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
        function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        function isOpenDay(s) { const g = parse(s).getDay(); return OPEN_DAYS.indexOf(g === 0 ? 7 : g) !== -1; }
        function closureOn(s) { return closures.find(function (c) { return c.date === s; }) || null; }

        const now = new Date();
        const TODAY = key(now.getFullYear(), now.getMonth(), now.getDate());
        let viewY = now.getFullYear(), viewM = now.getMonth();
        let selected = null;

        function renderCal() {
            const cur = viewY * 12 + viewM;
            const min = now.getFullYear() * 12 + now.getMonth();
            let html = '<div class="sp-cal-nav">'
                + '<button type="button" class="sp-cal-arrow" data-step="-1" aria-label="Previous month"' + (cur <= min ? ' disabled' : '') + '><i class="fa-solid fa-chevron-left"></i></button>'
                + '<span class="sp-cal-title">' + MONTHS[viewM] + ' ' + viewY + '</span>'
                + '<button type="button" class="sp-cal-arrow" data-step="1" aria-label="Next month"' + (cur >= min + 24 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-right"></i></button>'
                + '</div><div class="sp-cal-grid">';
            DOW.forEach(function (w) { html += '<span class="sp-cal-dow">' + w + '</span>'; });
            const first = new Date(viewY, viewM, 1).getDay();
            const total = new Date(viewY, viewM + 1, 0).getDate();
            for (let i = 0; i < first; i++) html += '<span></span>';
            for (let d = 1; d <= total; d++) {
                const k = key(viewY, viewM, d);
                const holiday = HOLIDAYS[k];
                const closure = closureOn(k);
                const cls = ['sp-day'];
                let title = '';
                if (closure) { cls.push('is-event'); title = closure.name; }
                else if (holiday) { cls.push('is-holiday'); title = holiday; }
                else if (!isOpenDay(k)) cls.push('is-closed');
                if (k < TODAY) cls.push('is-past');
                if (k === TODAY) cls.push('is-today');
                if (k === selected) cls.push('is-selected');
                html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + k + '"'
                    + (title ? ' title="' + esc(title) + '"' : '') + (k < TODAY ? ' disabled' : '') + '>' + d + '</button>';
            }
            cal.innerHTML = html + '</div>';
        }

        function renderDetail() {
            if (!selected) {
                detail.innerHTML = '<div class="sp-detail-empty"><i class="fa-regular fa-hand-pointer"></i>'
                    + '<span>Tap a date on the calendar to mark City Hall as not available on that day.</span></div>';
                return;
            }
            const d = parse(selected);
            const badge = '<div class="sp-badge"><span class="sp-badge-mon">' + MONTHS[d.getMonth()].slice(0, 3).toUpperCase() + '</span>'
                + '<span class="sp-badge-day">' + d.getDate() + '</span></div>';
            const head = '<div class="sp-detail-head">' + badge + '<div><div class="sp-detail-date">' + longDate(selected) + '</div>';
            const closure = closureOn(selected);
            const holiday = HOLIDAYS[selected];

            if (closure) {
                detail.innerHTML = head + '<span class="sp-chip sp-chip-event">Not available</span></div></div>'
                    + '<div class="sp-detail-name">' + esc(closure.name) + '</div>'
                    + '<p class="sp-detail-note">Adopters cannot choose this day. They are told it is a special day: ' + esc(closure.name) + '.</p>'
                    + '<button type="button" class="sp-btn sp-btn-danger" data-remove="' + closure.id + '"><i class="fa-regular fa-trash-can"></i> Remove</button>';
            } else if (holiday) {
                detail.innerHTML = head + '<span class="sp-chip sp-chip-holiday">Holiday</span></div></div>'
                    + '<div class="sp-detail-name">' + esc(holiday) + '</div>'
                    + '<p class="sp-detail-note">This is a Philippine holiday, so City Hall is already closed and adopters cannot choose this day.</p>';
            } else {
                const closedNote = isOpenDay(selected) ? '' : '<p class="sp-detail-note">City Hall is already closed on ' + WEEKDAYS[d.getDay()] + 's, but you can still add the event so it shows on the calendar.</p>';
                detail.innerHTML = head + '<span class="sp-chip sp-chip-open">' + (isOpenDay(selected) ? 'Open for interviews' : 'Closed day') + '</span></div></div>'
                    + closedNote
                    + '<label class="sp-field-label" for="spName">What is happening at City Hall?</label>'
                    + '<input type="text" id="spName" class="sp-input" maxlength="100" placeholder="e.g. City Foundation Day, Office Seminar" autocomplete="off">'
                    + '<button type="button" class="sp-btn sp-btn-primary" id="spAddBtn"><i class="fa-solid fa-ban"></i> Mark as not available</button>';
                const input = document.getElementById('spName');
                input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
                document.getElementById('spAddBtn').addEventListener('click', add);
                setTimeout(function () { input.focus(); }, 0);
            }
        }

        function renderList() {
            const upcoming = closures.filter(function (c) { return c.date >= TODAY; });
            count.textContent = upcoming.length;
            empty.hidden = upcoming.length > 0;
            list.innerHTML = upcoming.map(function (c) {
                const d = parse(c.date);
                return '<li class="sp-item" data-date="' + c.date + '">'
                    + '<span class="sp-date" aria-hidden="true"><span class="sp-date-mon">' + MONTHS[d.getMonth()].slice(0, 3).toUpperCase() + '</span>'
                    + '<span class="sp-date-day">' + d.getDate() + '</span></span>'
                    + '<div class="sp-info"><div class="sp-name">' + esc(c.name) + '</div><div class="sp-next">' + longDate(c.date)
                    + (isOpenDay(c.date) ? ' · <span class="is-open-day">interviews suspended</span>' : '') + '</div></div>'
                    + '<button type="button" class="sp-remove" data-remove="' + c.id + '" title="Remove" aria-label="Remove ' + esc(longDate(c.date) + ' - ' + c.name) + '">'
                    + '<i class="fa-regular fa-trash-can"></i></button></li>';
            }).join('');
        }

        function renderAll() { renderCal(); renderDetail(); renderList(); }

        function showMsg(text, ok) {
            msg.textContent = text;
            msg.className = 'sp-msg ' + (ok ? 'ok' : 'bad');
            msg.hidden = false;
            clearTimeout(msgTimer);
            msgTimer = setTimeout(function () { msg.hidden = true; }, 8000);
        }

        function send(data) {
            const body = new FormData();
            Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
            return fetch('process_special_day.php', {
                method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }
            }).then(function (r) { return r.json(); });
        }

        function handle(res) {
            if (Array.isArray(res.closures)) closures = res.closures;
            showMsg(res.message, res.ok);
            if (res.ok) window.spSpecialChanged = true;
            renderAll();
        }

        function add() {
            const input = document.getElementById('spName');
            const btn = document.getElementById('spAddBtn');
            const name = input.value.trim();
            if (!name) { showMsg('Please enter what is happening at City Hall on this day.', false); input.focus(); return; }
            btn.disabled = true;
            send({ action: 'add', date: selected, name: name })
                .then(handle)
                .catch(function () { showMsg('Could not reach the server. Please try again.', false); btn.disabled = false; });
        }

        function remove(id, btn) {
            const c = closures.find(function (x) { return String(x.id) === String(id); });
            if (c && !confirm('Remove ' + longDate(c.date) + ' (' + c.name + ')? Adopters will be able to choose this day again if City Hall is open.')) return;
            btn.disabled = true;
            send({ action: 'delete', closure_id: id })
                .then(handle)
                .catch(function () { showMsg('Could not reach the server. Please try again.', false); btn.disabled = false; });
        }

        cal.addEventListener('click', function (e) {
            const arrow = e.target.closest('.sp-cal-arrow');
            if (arrow) {
                const m = viewY * 12 + viewM + parseInt(arrow.dataset.step, 10);
                viewY = Math.floor(m / 12); viewM = m % 12;
                renderCal();
                return;
            }
            const day = e.target.closest('.sp-day:not([disabled])');
            if (day) { selected = day.dataset.date; renderCal(); renderDetail(); }
        });

        detail.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-remove]');
            if (btn) remove(btn.dataset.remove, btn);
        });

        list.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-remove]');
            if (btn) { remove(btn.dataset.remove, btn); return; }
            // Pindutin ang item: ipakita sa calendar
            const item = e.target.closest('.sp-item');
            if (item) {
                selected = item.dataset.date;
                const d = parse(selected);
                viewY = d.getFullYear(); viewM = d.getMonth();
                renderCal(); renderDetail();
            }
        });

        renderAll();
    })();

    // Pindutin ang araw sa calendar: ang mga nag-schedule lang sa araw na iyon ang ipapakita sa gilid
    (function () {
        const cal = document.getElementById('staffCal');
        const list = document.getElementById('dayList');
        const title = document.getElementById('dayTitle');
        const empty = document.getElementById('dayEmpty');
        const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        function showDay(dateStr) {
            current = dateStr;
            const p = dateStr.split('-');
            const d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
            title.textContent = DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
            let shown = 0;
            list.querySelectorAll('.slot-item[data-date]').forEach(function (item) {
                const match = item.dataset.date === dateStr;
                item.hidden = !match;
                if (match) shown++;
            });
            empty.hidden = shown > 0;
            cal.querySelectorAll('.cal-day[data-date]').forEach(function (b) { b.classList.toggle('selected', b.dataset.date === dateStr); });
            // Para manatili ang araw kapag nag-refresh o bumalik mula sa Approve/Reject
            history.replaceState(null, '', '?date=' + dateStr + location.hash);
        }

        let current = <?php echo json_encode($selectedDate); ?>;

        cal.addEventListener('click', function (e) {
            const b = e.target.closest('.cal-day[data-date]');
            if (b) showDay(b.dataset.date);
        });

        // ‹ Today ›: lumilipat nang isang araw. Kapag nasa ibang buwan na ang araw,
        // binubuksan ang buwang iyon (nasa server ang laman ng bawat buwan).
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        document.querySelectorAll('[data-day-step]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const step = parseInt(btn.dataset.dayStep, 10);
                let d;
                if (step === 0) {
                    d = new Date();
                } else {
                    const p = current.split('-');
                    d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10) + step);
                }
                const next = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
                if (cal.querySelector('.cal-day[data-date="' + next + '"]')) {
                    showDay(next);
                } else {
                    location.href = '?date=' + next + '#requests';
                }
            });
        });

        showDay(current);
    })();
</script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
