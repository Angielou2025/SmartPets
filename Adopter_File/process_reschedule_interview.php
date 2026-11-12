<?php
/**
 * Pag-reschedule ng adopter ng kanyang interview. Dalawang sitwasyon:
 *
 *  1. Hindi nakadalo - minarkahan ng staff na "Did Not Attend" (interview_missed_at).
 *     Makakapili lang ng bagong oras kapag nagpadala na ang staff ng rebooking
 *     link (rebook_sent_at), pagkatapos kontakin ng adopter ang staff.
 *  2. Bago pa ang interview - naging busy ang adopter. Pwede hanggang
 *     RESCHEDULE_CUTOFF_MINUTES bago magsimula ang interview.
 *
 * Sa parehong kaso, pipili ang adopter ng bagong araw at oras sa calendar (gaya ng
 * pag-apply). Babalik ito sa 'Pending' - ang staff ulit ang mag-a-Approve o
 * Reject - at mabubura ang dati niyang naka-book na oras para magamit ng iba.
 * Ang dahilan ay ipinapadala bilang message sa staff.
 */
session_start();
require_once '../connection/database.php';
require_once '../connection/activity_logger.php';
require_once '../connection/mailer.php';
require_once '../connection/interview_schedule.php';

const RESCHEDULE_CUTOFF_MINUTES = 60;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    header('Location: ../Login_Page/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: schedule.php');
    exit();
}

function reschedule_fail(string $message): void
{
    $_SESSION['error_message'] = $message;
    // Galing sa rebooking popup (Schedule) - doon ibabalik, bukas ulit ang popup
    if (in_array($_POST['return'] ?? '', ['schedule', 'notifications', 'preferences'], true)) {
        header('Location: schedule.php?rebook=' . (int) ($_POST['application_id'] ?? 0));
    } else {
        header('Location: schedule.php');
    }
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);
$newDate = trim((string) ($_POST['interview_date'] ?? ''));
$newTime = trim((string) ($_POST['interview_time'] ?? ''));
$reason = trim($_POST['missed_reason'] ?? '');

// Sa naka-login na adopter lang dapat ang application
$stmt = $pdo->prepare(
    "SELECT aa.*, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.application_id = ? AND aa.adopter_id = ?"
);
$stmt->execute([$application_id, $user_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application || in_array($application['application_status'], ['Approved', 'Rejected'], true)) {
    reschedule_fail('This interview cannot be rescheduled.');
}

$wasMissed = !empty($application['interview_missed_at']);
// Emergency ng staff: pinapapili ng bagong oras (may rebooking link, hindi "hindi nakadalo")
$isEmergencyRebook = !$wasMissed && !empty($application['rebook_sent_at']) && ($application['schedule_status'] ?? '') === 'Declined';
$oldStart = strtotime($application['preferred_interview_date'] . ' ' . $application['preferred_interview_time']);

// Hindi nakadalo: kailangan munang magpadala ang staff ng rebooking link
if ($wasMissed && empty($application['rebook_sent_at'])) {
    reschedule_fail('Please contact the staff first. They will send you a rebooking link so you can choose a new schedule.');
}

// Bago pa ang interview: may cutoff para may panahon ang staff na malaman ito
if (!$wasMissed && !$isEmergencyRebook && $oldStart - time() < RESCHEDULE_CUTOFF_MINUTES * 60) {
    reschedule_fail($oldStart > time()
        ? 'Your interview starts in less than an hour, so it can no longer be rescheduled. Please send the shelter a message instead.'
        : 'This interview has already started or passed. If you were not able to attend, the staff will mark it and you can reschedule after.');
}

// Ang hindi nakadalo ay nakausap na ang staff bago napadalhan ng reschedule link,
// kaya hindi na siya tinatanong ulit kung bakit. Ang kusang naglilipat lang ang tinatanong.
if ($reason === '' && !$wasMissed && !$isEmergencyRebook) {
    reschedule_fail('Please tell us why you need to reschedule.');
}
// Naka-lock habang sinusuri at sine-save, para ang naunang nagpasa ang makakuha ng oras
sp_interview_lock($pdo);
$scheduleError = sp_interview_request_error($pdo, $newDate, $newTime, $application_id);
if ($scheduleError !== null) {
    sp_interview_unlock($pdo);
    reschedule_fail($scheduleError);
}

try {
    $pdo->beginTransaction();

    // Burahin ang dating naka-book na oras (wala nang Open na slot - ang adopter
    // na ang pumipili ng oras, kaya bakante na ulit ito sa calendar)
    $pdo->prepare("DELETE FROM appointment_slots WHERE booked_by_application_id = ? AND TIMESTAMP(slot_date, end_time) > NOW()")
        ->execute([$application_id]);

    // Bagong petsa at oras, hihintayin ang Approve ng staff; binubura ang "naipadala
    // na" para maipadala ulit ang meeting link bago ang bagong interview.
    $pdo->prepare(
        "UPDATE adoption_applications
         SET preferred_interview_date = ?, preferred_interview_time = ?,
             schedule_status = 'Pending', schedule_decline_reason = NULL, schedule_decided_by = NULL, schedule_decided_at = NULL,
             interview_missed_at = NULL, meeting_link_sent_at = NULL, rebook_sent_at = NULL,
             missed_reason = COALESCE(NULLIF(?, ''), missed_reason), reschedule_count = reschedule_count + 1
         WHERE application_id = ?"
    )->execute([$newDate, $newTime . ':00', $reason, $application_id]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sp_interview_unlock($pdo);
    reschedule_fail('We could not reschedule your interview. Please try again.');
}
sp_interview_unlock($pdo);

$petName = $application['pet_name'];
$oldWhen = date('M j, Y', $oldStart) . ' at ' . date('g:i A', $oldStart);
$newStart = strtotime($newDate . ' ' . $newTime);
$newWhen = date('M j, Y', $newStart) . ' at ' . date('g:i A', $newStart);

// Ang dahilan ay dumidiretso sa Messages ng staff na may hawak ng application
$staffId = (int) ($application['interview_missed_by'] ?: $application['reviewed_by_staff_id']);
if ($staffId > 0) {
    sp_send_message(
        $pdo,
        $user_id,
        $staffId,
        ($wasMissed
            ? "I used the reschedule link for my interview for $petName." . ($reason !== '' ? " Reason for missing it: $reason" : '')
            : "I need to reschedule my interview for $petName (was $oldWhen). Reason: $reason")
        . "\n\nMy new interview schedule is $newWhen. Please approve it if this works."
    );
}

sp_notify(
    $pdo,
    $user_id,
    'interview',
    'New interview schedule sent',
    "You asked to move your interview for $petName to $newWhen. Please wait for the staff to approve it.",
    'schedule.php',
    'rescheduled_' . $application_id . '_' . $newStart
);

sp_send_email(
    sp_application_email($pdo, $application),
    $application['full_name'],
    "Your new SMART PET interview schedule for $petName was sent",
    'Your new schedule was sent',
    [
        'Hi ' . htmlspecialchars($application['full_name'], ENT_QUOTES, 'UTF-8') . ',',
        'You asked to move your adoption interview for <strong>' . htmlspecialchars($petName, ENT_QUOTES, 'UTF-8') . '</strong> to <strong>'
            . date('l, F j, Y', $newStart) . '</strong> from <strong>'
            . date('g:i A', $newStart) . ' to ' . date('g:i A', $newStart + SP_INTERVIEW_MINUTES * 60) . '</strong>.',
        'The staff will check this schedule. You will get a message once they approve it, or the reason if they cannot.',
    ],
    'View My Schedule',
    sp_app_url('Adopter_File/schedule.php')
);

log_activity(
    $pdo,
    $wasMissed ? "Adopter rescheduled missed interview for $petName" : "Adopter rescheduled upcoming interview for $petName",
    "Applicant: {$application['full_name']}, From: $oldWhen, To: $newWhen, Reason: $reason"
);

$_SESSION['success_message'] = "Your new schedule ($newWhen) was sent. Please wait for the staff to approve it.";
header('Location: schedule.php');
exit();
