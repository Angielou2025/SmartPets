<?php
/**
 * Pag-save ng oras ng City Hall para sa interview: mga araw ng linggo na bukas
 * (Lunes hanggang Linggo) at oras ng bukas at sara. Ito ang sinusunod ng calendar
 * ng adopter (connection/interview_schedule.php). Wala nang hiwalay na slot -
 * ang adopter na ang naglalagay ng oras sa loob ng mga oras na ito.
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';
require_once __DIR__ . '/../connection/interview_schedule.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: staff_availability.php");
    exit();
}

function hours_fail(string $message): void
{
    $_SESSION['error_message'] = $message;
    header("Location: staff_availability.php");
    exit();
}

$days = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_POST['days'] ?? [])),
    fn ($d) => $d >= 1 && $d <= 7
)));
sort($days); // 1 = Lunes ... 7 = Linggo
$open = trim((string) ($_POST['open_time'] ?? ''));
$close = trim((string) ($_POST['close_time'] ?? ''));

if (!$days) {
    hours_fail('Please choose at least one day.');
}
if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $open) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $close)) {
    hours_fail('Please enter a valid opening and closing time.');
}
if (sp_interview_minutes($close) - sp_interview_minutes($open) < SP_INTERVIEW_MINUTES) {
    hours_fail('The closing time must be at least 1 hour and 30 minutes after the opening time, so one interview can fit.');
}

// Break ng staff (optional): 30 minuto hanggang 1 oras, sa loob ng oras ng City Hall
$breakStart = trim((string) ($_POST['break_start'] ?? ''));
$breakMinutes = (int) ($_POST['break_minutes'] ?? 30);
if ($breakStart !== '') {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $breakStart)) {
        hours_fail('Please enter a valid break time.');
    }
    if (!in_array($breakMinutes, [30, 60], true)) {
        hours_fail('The break must be 30 minutes or 1 hour.');
    }
    $bs = sp_interview_minutes($breakStart);
    if ($bs < sp_interview_minutes($open) || $bs + $breakMinutes > sp_interview_minutes($close)) {
        hours_fail('The break must be within City Hall hours.');
    }
}

$pdo->prepare(
    "INSERT INTO interview_settings (setting_id, work_days, open_time, close_time, break_start, break_minutes, updated_by)
     VALUES (1, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE work_days = VALUES(work_days), open_time = VALUES(open_time), close_time = VALUES(close_time),
                             break_start = VALUES(break_start), break_minutes = VALUES(break_minutes), updated_by = VALUES(updated_by)"
)->execute([
    implode(',', $days), $open . ':00', $close . ':00',
    $breakStart !== '' ? $breakStart . ':00' : null, $breakStart !== '' ? $breakMinutes : null,
    (int) $_SESSION['user_id'],
]);

$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$label = implode(', ', array_map(fn ($d) => $dayNames[$d], $days))
    . ', ' . date('g:i A', strtotime($open)) . ' - ' . date('g:i A', strtotime($close))
    . ($breakStart !== '' ? ', break ' . date('g:i A', strtotime($breakStart)) . ' (' . ($breakMinutes === 60 ? '1 hour' : '30 minutes') . ')' : '');

$_SESSION['success_message'] = "City Hall interview hours saved: $label. Requests already sent are not changed.";
log_activity($pdo, 'Updated City Hall interview hours', $label);
header("Location: staff_availability.php");
exit();
