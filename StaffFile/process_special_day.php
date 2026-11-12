<?php
/**
 * Ganap sa City Hall (walang interview sa araw na iyon): pagdagdag at pagbura.
 * Tinatawag ng calendar sa "City Hall Interview Hours" modal (staff_availability.php).
 * Ang mga holiday sa Pilipinas ay kusang kasama na (sp_ph_holidays) at hindi rito.
 *
 * POST action=add    date (Y-m-d), name
 * POST action=delete closure_id
 * Sagot (JSON): { ok, message, closures: [{id, date, name}, ...] }
 */
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';
require_once __DIR__ . '/../connection/interview_schedule.php';

header('Content-Type: application/json');

function closure_list(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT closure_id, closure_date, name FROM city_hall_closures ORDER BY closure_date")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
    return array_map(fn ($r) => ['id' => (int) $r['closure_id'], 'date' => $r['closure_date'], 'name' => $r['name']], $rows);
}

function closure_reply(bool $ok, string $message, int $status = 200): void
{
    global $pdo;
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message, 'closures' => $status < 500 && $status !== 401 ? closure_list($pdo) : []]);
    exit();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    closure_reply(false, 'Your session has ended. Please log in again.', 401);
}
// Galing lang sa sariling page (fetch na may header na ito)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    closure_reply(false, 'Invalid request.', 400);
}

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $date = (string) ($_POST['date'] ?? '');
    $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        closure_reply(false, 'Please choose a date on the calendar.', 422);
    }
    if ($date < date('Y-m-d')) {
        closure_reply(false, 'That date has already passed. Please choose today or a future date.', 422);
    }
    if ($name === '' || mb_strlen($name) > 100) {
        closure_reply(false, 'Please enter what is happening at City Hall (up to 100 characters).', 422);
    }

    $label = date('F j, Y', strtotime($date));
    $holiday = sp_ph_holidays((int) substr($date, 0, 4))[$date] ?? null;
    if ($holiday !== null) {
        closure_reply(false, "$label is already a holiday ($holiday), so City Hall is already closed.", 422);
    }
    try {
        $pdo->prepare("INSERT INTO city_hall_closures (closure_date, name, created_by) VALUES (?, ?, ?)")
            ->execute([$date, $name, (int) $_SESSION['user_id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            closure_reply(false, "$label is already marked as not available. Remove it first if you want to change it.", 422);
        }
        closure_reply(false, 'The date could not be saved. Please try again.', 500);
    }
    log_activity($pdo, 'Marked City Hall not available', "$label - $name");

    // Paalala kung may adopter nang naka-request o tinanggap sa araw na iyon
    $warn = '';
    try {
        $cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM adoption_applications
             WHERE preferred_interview_date = ? AND interview_missed_at IS NULL
               AND application_status IN ('Pending', 'Under Review')
               AND (schedule_status IS NULL OR schedule_status IN ('Pending', 'Accepted'))"
        );
        $cnt->execute([$date]);
        $n = (int) $cnt->fetchColumn();
        if ($n > 0) {
            $warn = " Note: $n adopter" . ($n === 1 ? ' already has' : 's already have') . ' an interview on this day. Please message them to reschedule.';
        }
    } catch (Exception $e) {
        // hindi hadlang sa pag-save
    }
    closure_reply(true, "$label ($name) is now marked as not available. Adopters can no longer choose this day.$warn");
}

if ($action === 'delete') {
    $id = (int) ($_POST['closure_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT closure_date, name FROM city_hall_closures WHERE closure_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        closure_reply(false, 'That date was already removed.', 422);
    }
    $pdo->prepare("DELETE FROM city_hall_closures WHERE closure_id = ?")->execute([$id]);
    $label = date('F j, Y', strtotime($row['closure_date']));
    log_activity($pdo, 'Removed City Hall not-available date', "$label - {$row['name']}");
    closure_reply(true, "$label ({$row['name']}) was removed. Adopters can choose this day again if City Hall is open.");
}

closure_reply(false, 'Invalid request.', 400);
