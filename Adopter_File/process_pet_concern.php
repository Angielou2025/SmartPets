<?php
/**
 * "Report a Concern" mula sa My Pets.
 *
 * Ang ulat ng adopter tungkol sa inampon niyang pet ay ipinapadala bilang
 * mensahe sa staff na nag-approve ng adoption (o sa ibang staff kung wala na
 * siya), at itinatala sa pet_concerns para masubaybayan ng adopter ang status:
 * Sent -> Seen by shelter -> Shelter replied.
 */
session_start();
require_once __DIR__ . '/../connection/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Adopter') {
    header('Location: ../Login_Page/login.php');
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_pets.php');
    exit();
}

function concern_fail(string $message): void
{
    $_SESSION['error_message'] = $message;
    header('Location: my_pets.php');
    exit();
}

$adopter_id = (int) $_SESSION['user_id'];
$application_id = (int) ($_POST['application_id'] ?? 0);
$concern_type = trim((string) ($_POST['concern_type'] ?? ''));
$details = mb_substr(trim((string) ($_POST['details'] ?? '')), 0, 1500);

$types = [
    'Health change' => '🩺',
    'Behavior change' => '🐾',
    'Can no longer care for the pet' => '🏠',
    'Other concern' => '💬',
];
if (!isset($types[$concern_type])) {
    concern_fail('Please choose what kind of concern this is.');
}
if (mb_strlen($details) < 10) {
    concern_fail('Please describe what happened in a few words so the shelter can help.');
}

// Sa adopter na ito lang, at kasalukuyan pa niyang alaga (hindi pa ibinalik)
$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.pet_id, aa.reviewed_by_staff_id, p.name AS pet_name, p.breed
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     WHERE aa.application_id = ? AND aa.adopter_id = ? AND aa.application_status = 'Approved'
       AND NOT EXISTS (
           SELECT 1 FROM pet_visits v
           WHERE v.application_id = aa.application_id
             AND v.result IN ('Reclaimed by Office', 'Returned by Adopter')
       )"
);
$stmt->execute([$application_id, $adopter_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    concern_fail('We could not find this pet in your adopted pets.');
}

// Sino ang tatanggap: ang staff na nag-approve, kung Staff o Admin pa rin siya;
// kung hindi, ang unang aktibong Staff, saka Admin
$staff_id = 0;
if (!empty($app['reviewed_by_staff_id'])) {
    $chk = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND role IN ('Staff', 'Admin')");
    $chk->execute([(int) $app['reviewed_by_staff_id']]);
    $staff_id = (int) $chk->fetchColumn();
}
if ($staff_id === 0) {
    $staff_id = (int) $pdo->query(
        "SELECT user_id FROM users
          WHERE role IN ('Staff', 'Admin')
          ORDER BY (role = 'Staff') DESC, (status = 'Active') DESC, user_id ASC
          LIMIT 1"
    )->fetchColumn();
}
if ($staff_id === 0) {
    concern_fail('There is no shelter staff available to receive your report right now. Please try again later.');
}

$message = $types[$concern_type] . " PET CONCERN REPORT\n"
    . 'Pet: ' . $app['pet_name'] . ' (' . $app['breed'] . ")\n"
    . 'Concern: ' . $concern_type . "\n\n"
    . $details . "\n\n"
    . '(Sent from My Pets)';

$pdo->beginTransaction();
try {
    if (!sp_send_message($pdo, $adopter_id, $staff_id, $message)) {
        throw new RuntimeException('message not sent');
    }
    $message_id = (int) $pdo->lastInsertId(); // ang huling INSERT ay ang mensahe

    $pdo->prepare(
        'INSERT INTO pet_concerns (application_id, pet_id, adopter_id, staff_id, concern_type, details, message_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$application_id, (int) $app['pet_id'], $adopter_id, $staff_id, $concern_type, $details, $message_id]);
    $concern_id = (int) $pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('process_pet_concern failed: ' . $e->getMessage());
    concern_fail('Your report could not be sent. Please try again.');
}

sp_notify(
    $pdo,
    $adopter_id,
    'concern',
    'Your report about ' . $app['pet_name'] . ' was sent',
    'The shelter staff received your report in Messages. Their reply will appear in Messages.',
    'my_pets.php',
    'concern_sent_' . $concern_id
);

$_SESSION['success_message'] = 'Your report about ' . $app['pet_name'] . ' was sent to the shelter. Their reply will appear in Messages.';
header('Location: my_pets.php');
exit();
