<?php
/**
 * Emergency ng staff: hindi matutuloy ang mga interview sa isang araw.
 *
 *  1. Isinasara ang araw na iyon (city_hall_closures) para wala nang bagong mag-book.
 *  2. Bawat interview sa araw na iyon (ayon sa oras): inililipat sa bagong araw sa
 *     PAREHONG oras kapag bakante pa; kapag may naka-book na roon o hindi puwede,
 *     pinapapili ang adopter ng bagong oras o araw (rebooking link) at ipinapakita
 *     ang mga bakanteng oras sa bagong araw.
 *  3. Bawat adopter: alert sa notifications, message mula sa staff, at email (Gmail),
 *     kasama ang dahilan ng staff.
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';
require_once __DIR__ . '/../connection/interview_schedule.php';
require_once __DIR__ . '/../connection/mailer.php';

$back = 'staff_availability.php';
function em_fail(string $msg): void
{
    global $back;
    $_SESSION['error_message'] = $msg;
    header('Location: ' . $back);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header('Location: ' . $back);
    exit();
}
$staffId = (int) $_SESSION['user_id'];
$from = (string) ($_POST['from_date'] ?? '');
$to = (string) ($_POST['to_date'] ?? '');
$reason = trim(preg_replace('/\s+/', ' ', (string) ($_POST['reason'] ?? '')));
$isDate = fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));

if (!$isDate($from) || !$isDate($to)) {
    em_fail('Please choose the affected day and the new day.');
}
if ($from < date('Y-m-d')) {
    em_fail('The affected day has already passed.');
}
if ($to <= $from) {
    em_fail('The new day must be after the affected day.');
}
if ($reason === '' || mb_strlen($reason) > 500) {
    em_fail('Please write the reason (up to 500 characters). It is sent to the adopters.');
}
$settings = sp_interview_settings($pdo);
if (!in_array((int) date('N', strtotime($to)), $settings['days'], true)) {
    em_fail(date('l, F j', strtotime($to)) . ' is not an interview day. Please choose a day when City Hall is open.');
}
if (($sp = sp_interview_special_on($pdo, $to)) !== null) {
    em_fail(date('F j', strtotime($to)) . " is a special day ($sp). Please choose another day.");
}

// Mga interview sa araw na iyon na hindi pa tapos (ayon sa oras)
$st = $pdo->prepare(
    "SELECT aa.*, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.preferred_interview_date = ?
       AND aa.application_status IN ('Pending', 'Under Review')
       AND aa.interview_missed_at IS NULL
       AND (aa.schedule_status IS NULL OR aa.schedule_status IN ('Pending', 'Accepted'))
     ORDER BY aa.preferred_interview_time"
);
$st->execute([$from]);
$apps = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), function ($a) {
    $end = strtotime($a['preferred_interview_date'] . ' ' . $a['preferred_interview_time']) + SP_INTERVIEW_MINUTES * 60;
    return $end > time();
}));

sp_interview_lock($pdo);

// Isara ang araw para walang bagong mag-book dito
try {
    $pdo->prepare("INSERT IGNORE INTO city_hall_closures (closure_date, name, created_by) VALUES (?, ?, ?)")
        ->execute([$from, mb_substr('Emergency: ' . $reason, 0, 100), $staffId]);
} catch (Exception $e) {
}

$fromLabel = date('l, F j, Y', strtotime($from));
$toLabel = date('l, F j, Y', strtotime($to));
$moved = 0;
$rebook = 0;
$mailed = 0;

// Unang ikot: ilipat ang mga puwede sa parehong oras. Ang mga hindi (may naka-book na)
// ay pinapapili ng bagong oras - inaabisuhan pagkatapos, para tama na ang listahan
// ng bakanteng oras matapos mailipat ang iba.
$notices = [];
foreach ($apps as $a) {
    $appId = (int) $a['application_id'];
    $time = substr($a['preferred_interview_time'], 0, 5);
    if (sp_interview_request_error($pdo, $to, $time, $appId) === null) {
        $pdo->prepare(
            "UPDATE adoption_applications SET preferred_interview_date = ?, meeting_link_sent_at = NULL, reschedule_count = reschedule_count + 1
             WHERE application_id = ?"
        )->execute([$to, $appId]);
        $moved++;
        $notices[] = ['a' => $a, 'moved' => true];
    } else {
        $pdo->prepare(
            "UPDATE adoption_applications SET schedule_status = 'Declined', schedule_decline_reason = ?, schedule_decided_by = ?, schedule_decided_at = NOW(),
                    rebook_sent_at = NOW(), meeting_link_sent_at = NULL
             WHERE application_id = ?"
        )->execute([mb_substr('Emergency: ' . $reason, 0, 1000), $staffId, $appId]);
        $rebook++;
        $notices[] = ['a' => $a, 'moved' => false];
    }
}
sp_interview_unlock($pdo);

// Ikalawang ikot: abiso (alert sa notifications), message, at email sa bawat adopter
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
foreach ($notices as $n) {
    $a = $n['a'];
    $appId = (int) $a['application_id'];
    $time = substr($a['preferred_interview_time'], 0, 5);
    $oldStart = strtotime($from . ' ' . $time);
    if ($n['moved']) {
        $newStart = strtotime($to . ' ' . $time);
        $when = $toLabel . ' at ' . date('g:i A', $newStart);
        $title = 'Emergency: your interview was moved';
        $text = "Your interview for {$a['pet_name']} on $fromLabel cannot push through. Reason: $reason "
            . "It was moved to $when. If this time does not work for you, you can reschedule it in My Schedule.";
        $link = 'schedule.php';
        $paras = [
            'Hi ' . $e($a['full_name']) . ',',
            'We are sorry, but your adoption interview for <strong>' . $e($a['pet_name']) . '</strong> on <strong>' . $e($fromLabel) . '</strong> cannot push through.',
            '<strong>Reason:</strong> ' . $e($reason),
            'Your interview was moved to <strong>' . $e($toLabel) . '</strong> from <strong>' . date('g:i A', $newStart) . ' to '
                . date('g:i A', $newStart + SP_INTERVIEW_MINUTES * 60) . '</strong>, the same time as before.',
            'If this does not work for you, you can choose another schedule in My Schedule.',
        ];
        $button = 'View My Schedule';
    } else {
        $free = sp_interview_free_ranges($settings, $to, sp_interview_taken($pdo, $appId, $to)[$to] ?? []);
        $freeText = $free ? sp_interview_ranges_text($free) : '';
        $title = 'Emergency: please choose a new interview time';
        $text = "Your interview for {$a['pet_name']} on $fromLabel cannot push through. Reason: $reason "
            . 'Sorry, your usual time (' . date('g:i A', $oldStart) . ") is already booked on $toLabel. "
            . ($freeText !== '' ? "Available start times that day: $freeText. " : '')
            . 'You can also choose any other available day.';
        $link = 'schedule.php?rebook=' . $appId;
        $paras = [
            'Hi ' . $e($a['full_name']) . ',',
            'We are sorry, but your adoption interview for <strong>' . $e($a['pet_name']) . '</strong> on <strong>' . $e($fromLabel) . '</strong> cannot push through.',
            '<strong>Reason:</strong> ' . $e($reason),
            'We wanted to move you to <strong>' . $e($toLabel) . '</strong> at the same time (<strong>' . date('g:i A', $oldStart) . '</strong>), but that time is already booked.',
            $freeText !== ''
                ? 'Available start times on ' . $e($toLabel) . ': <strong>' . $e($freeText) . '</strong>. You can also choose any other available day.'
                : 'There is no free time left on ' . $e($toLabel) . '. Please choose any other available day.',
            'Each interview takes 1 hour and 30 minutes. Please use the button below to choose your new schedule.',
        ];
        $button = 'Choose a New Schedule';
    }

    $adopterId = (int) $a['adopter_id'];
    sp_notify($pdo, $adopterId, 'interview', $title, $text, $link, 'emergency_' . $appId . '_' . time());
    sp_send_message($pdo, $staffId, $adopterId, "Hi {$a['full_name']}, " . $text . "\n" . sp_message_button($button, $link));
    if (sp_send_email(sp_application_email($pdo, $a), $a['full_name'], 'Important: your SMART PET interview on ' . date('M j', strtotime($from)) . ' was moved', $title, $paras, $button, sp_app_url('Adopter_File/' . $link))) {
        $mailed++;
    }
}
log_activity($pdo, 'Emergency: moved interviews from ' . $from, "To: $to, Moved: $moved, Asked to rebook: $rebook, Reason: $reason");

$total = count($apps);
$_SESSION['success_message'] = $total === 0
    ? "No interviews were scheduled on $fromLabel. The day is now closed for interviews."
    : "Emergency notice sent to $total adopter" . ($total === 1 ? '' : 's') . ". $moved moved to $toLabel at the same time"
        . ($rebook ? ", $rebook asked to choose a new time (their time was already booked)" : '')
        . ". Emails sent: $mailed of $total. $fromLabel is now closed for interviews.";
header('Location: ' . $back);
exit();
