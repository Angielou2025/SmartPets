<?php
/**
 * Pagpili ng adopter ng Pet Meet-up schedule - isa sa mga araw at oras na
 * inalok ng staff pagkatapos ng interview. Pagkapili, ipinapadala sa adopter
 * ang lokasyon ng Caloocan City Hall, at sinasabihan ang staff.
 */
session_start();
require_once '../connection/database.php';
require_once '../connection/activity_logger.php';
require_once '../connection/mailer.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    header('Location: ../Login_Page/login.php');
    exit();
}

const SP_CITY_HALL_NAME = 'Caloocan City Hall';
const SP_CITY_HALL_ADDRESS = '10th Ave, Grace Park West, Caloocan, 1400 Metro Manila, Philippines';
const SP_CITY_HALL_MAP = 'https://www.google.com/maps/search/?api=1&query=Caloocan+City+Hall+Philippines';

// Galing sa pop-up ng calendar (fetch): JSON ang sagot para lumabas ang success sa pop-up mismo
$isAjax = ($_POST['ajax'] ?? '') === '1';

function meetup_fail(string $message): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => $message]);
        exit();
    }
    $_SESSION['error_message'] = $message;
    header('Location: schedule.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);
$option_id = (int) ($_POST['option_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $application_id <= 0) {
    header('Location: schedule.php');
    exit();
}
if ($option_id <= 0) {
    meetup_fail('Please choose a meet-up schedule.');
}

// Sa naka-login na adopter lang, bukas pa ang application, at nagpadala na ang staff
$stmt = $pdo->prepare(
    "SELECT aa.*, p.name AS pet_name
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.application_id = ? AND aa.adopter_id = ?"
);
$stmt->execute([$application_id, $user_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application || empty($application['meetup_sent_at'])
    || !in_array($application['application_status'], ['Pending', 'Under Review'], true)) {
    meetup_fail('This meet-up schedule is no longer available.');
}

$optStmt = $pdo->prepare(
    "SELECT * FROM pet_meetup_options
     WHERE option_id = ? AND application_id = ? AND TIMESTAMP(meet_date, COALESCE(meet_end_time, meet_time)) > NOW()"
);
$optStmt->execute([$option_id, $application_id]);
$option = $optStmt->fetch(PDO::FETCH_ASSOC);

if (!$option) {
    meetup_fail('That meet-up schedule has already passed or is no longer offered. Please choose another one.');
}

$pdo->prepare("UPDATE adoption_applications SET meetup_option_id = ?, meetup_chosen_at = NOW() WHERE application_id = ?")
    ->execute([$option_id, $application_id]);

$petName = $application['pet_name'];
// Hal. "Monday, October 5, 2026, 8:00 AM - 5:00 PM" (anumang oras sa pagitan nito)
$when = date('l, F j, Y', strtotime($option['meet_date'])) . ', '
    . date('g:i A', strtotime($option['meet_time']))
    . (!empty($option['meet_end_time']) ? ' - ' . date('g:i A', strtotime($option['meet_end_time'])) : '');

// Ang tanging abiso tungkol sa meet-up: matagumpay na ang pagpili, kasama ang detalye
$meetDate = date('l, F j, Y', strtotime($option['meet_date']));
$meetTime = date('g:i A', strtotime($option['meet_time']))
    . (!empty($option['meet_end_time']) ? ' - ' . date('g:i A', strtotime($option['meet_end_time'])) : '');
sp_notify(
    $pdo,
    $user_id,
    'interview',
    'Pet meet-up confirmed',
    "Your meet-up with $petName is confirmed.\n"
    . "Date: $meetDate\n"
    . "Time: $meetTime (you can come anytime within these hours)\n"
    . 'Place: ' . SP_CITY_HALL_NAME . ', ' . SP_CITY_HALL_ADDRESS . "\n"
    . "Please bring a valid ID.\n"
    . 'Tap View to see the location of Caloocan City Hall on the map.',
    // View = Location page (map ng Caloocan City Hall)
    'shelter.php',
    'meetup_chosen_' . $application_id . '_' . $option_id
);

// Sa staff na nagpadala ng schedule (o sa nag-review, kapag wala na)
$staffId = (int) ($application['meetup_sent_by'] ?: $application['reviewed_by_staff_id']);
if ($staffId > 0) {
    sp_send_message(
        $pdo,
        $user_id,
        $staffId,
        "I chose $when for the pet meet-up with $petName at " . SP_CITY_HALL_NAME . '.'
    );
    // Sagot ng staff na may lokasyon ng City Hall, kung saan sila magkikita
    sp_send_message(
        $pdo,
        $staffId,
        $user_id,
        "Your pet meet-up with $petName is confirmed for $when. We will meet you at " . SP_CITY_HALL_NAME
        . ', ' . SP_CITY_HALL_ADDRESS . ". Please bring a valid ID. Tap the button below for directions."
        . "\n" . sp_message_button('Open Caloocan City Hall in Google Maps', SP_CITY_HALL_MAP)
    );
}

sp_send_email(
    sp_application_email($pdo, $application),
    $application['full_name'],
    "Your pet meet-up with $petName is confirmed",
    'Your pet meet-up is confirmed',
    [
        'Hi ' . htmlspecialchars($application['full_name'], ENT_QUOTES, 'UTF-8') . ',',
        'See you on <strong>' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</strong> to meet <strong>'
            . htmlspecialchars($petName, ENT_QUOTES, 'UTF-8') . '</strong> in person.',
        'Location: <strong>' . SP_CITY_HALL_NAME . '</strong>, ' . SP_CITY_HALL_ADDRESS . '.',
        'Please bring a valid ID. Use the button below to open the location on Google Maps.',
    ],
    'Open Location on Google Maps',
    SP_CITY_HALL_MAP
);

log_activity($pdo, "Adopter chose pet meet-up schedule for $petName", "Applicant: {$application['full_name']}, Schedule: $when");

// Susunod na hakbang (connection/application_flow.php): bayaran ang adoption fee
// bago ang approval. Bukas na ngayon ang Payments para sa application na ito.
sp_notify(
    $pdo,
    $user_id,
    'payment',
    'Next step: pay the adoption fee',
    "Your meet-up with $petName is set. You can now pay the adoption fee in Payments. After you pay, the shelter will release your certificate and approve your adoption.",
    'payments.php',
    'pay_after_meetup_' . $application_id
);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'pet' => $petName,
        'date' => $meetDate,
        'time' => $meetTime,
        'place' => SP_CITY_HALL_NAME,
        'address' => SP_CITY_HALL_ADDRESS,
        'map' => SP_CITY_HALL_MAP,
    ]);
    exit();
}
$_SESSION['success_message'] = "Your pet meet-up is set for $when at " . SP_CITY_HALL_NAME . '.';
header('Location: schedule.php#meetup-' . $application_id);
exit();
