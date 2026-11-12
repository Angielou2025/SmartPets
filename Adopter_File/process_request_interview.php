<?php
/**
 * Bagong interview request mula sa Schedule page: kapag hindi tinanggap ng staff
 * ang naunang araw (Declined), o kapag gustong palitan ng adopter ang request na
 * hindi pa nasasagot (Pending). Babalik ito sa 'Pending' at ang staff ulit ang
 * mag-a-Accept o Decline.
 */
session_start();
require_once '../connection/database.php';
require_once '../connection/activity_logger.php';
require_once '../connection/interview_schedule.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    header('Location: ../Login_Page/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: schedule.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);
$date = trim((string) ($_POST['interview_date'] ?? ''));
$time = trim((string) ($_POST['interview_time'] ?? ''));

function request_fail(string $message, int $application_id): void
{
    $_SESSION['error_message'] = $message;
    header('Location: schedule.php#request-' . $application_id);
    exit();
}

$stmt = $pdo->prepare(
    "SELECT aa.*, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.application_id = ? AND aa.adopter_id = ?"
);
$stmt->execute([$application_id, $user_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application
    || !in_array($application['application_status'], ['Pending', 'Under Review'], true)
    || !in_array($application['schedule_status'] ?? null, ['Pending', 'Declined'], true)) {
    request_fail('This interview schedule can no longer be changed here.', $application_id);
}

// Naka-lock habang sinusuri at sine-save, para ang naunang nagpasa ang makakuha ng oras
sp_interview_lock($pdo);
$error = sp_interview_request_error($pdo, $date, $time, $application_id);
if ($error !== null) {
    sp_interview_unlock($pdo);
    request_fail($error, $application_id);
}

$pdo->prepare(
    "UPDATE adoption_applications
     SET preferred_interview_date = ?, preferred_interview_time = ?,
         schedule_status = 'Pending', schedule_decline_reason = NULL,
         schedule_decided_by = NULL, schedule_decided_at = NULL, meeting_link_sent_at = NULL
     WHERE application_id = ?"
)->execute([$date, $time . ':00', $application_id]);
sp_interview_unlock($pdo);

$petName = $application['pet_name'];
$newWhen = date('l, F j, Y', strtotime($date)) . ' at ' . date('g:i A', strtotime($time));

// Sabihan ang staff na nag-decline (kung mayroon) sa Messages
$staffId = (int) ($application['schedule_decided_by'] ?? 0);
if ($staffId > 0) {
    sp_send_message(
        $pdo,
        $user_id,
        $staffId,
        "I chose a new interview schedule for $petName: $newWhen. Please let me know if this works."
    );
}

log_activity(
    $pdo,
    "Adopter requested a new interview schedule for $petName",
    "Applicant: {$application['full_name']}, Requested: $newWhen"
);

$_SESSION['success_message'] = "Your new schedule ($newWhen) was sent. Please wait for the staff to accept it.";
header('Location: schedule.php');
exit();
