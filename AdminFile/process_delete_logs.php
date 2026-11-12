<?php
/**
 * Pagbura ng mga napiling Activity Log (Admin lang).
 *
 * Ang mismong pagbura ay itinatala rin sa activity_logs (sino, ilan, at aling
 * mga petsa) - para may bakas pa rin kahit nabura na ang mga log.
 */
session_start();
require __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

$back = 'activity_logs.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: ../Login_Page/login.php');
    exit();
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $back);
    exit();
}

$token = (string) ($_POST['token'] ?? '');
if ($token === '' || empty($_SESSION['al_delete_token']) || !hash_equals($_SESSION['al_delete_token'], $token)) {
    $_SESSION['al_flash'] = ['error', 'Your session expired. Please try again.'];
    header('Location: ' . $back);
    exit();
}

// Mga ID na buong numero lang
$ids = array_values(array_unique(array_filter(
    array_map('intval', (array) ($_POST['ids'] ?? [])),
    fn ($id) => $id > 0
)));

if (!$ids) {
    $_SESSION['al_flash'] = ['error', 'No activity logs were selected.'];
    header('Location: ' . $back);
    exit();
}

try {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $range = $pdo->prepare("SELECT MIN(created_at), MAX(created_at) FROM activity_logs WHERE log_id IN ($in)");
    $range->execute($ids);
    [$from, $to] = $range->fetch(PDO::FETCH_NUM) ?: [null, null];

    $del = $pdo->prepare("DELETE FROM activity_logs WHERE log_id IN ($in)");
    $del->execute($ids);
    $deleted = $del->rowCount();
} catch (Exception $e) {
    $_SESSION['al_flash'] = ['error', 'The selected logs could not be deleted. Please try again.'];
    header('Location: ' . $back);
    exit();
}

if ($deleted > 0) {
    $span = $from ? date('M j, Y', strtotime($from)) . ($to && date('Y-m-d', strtotime($to)) !== date('Y-m-d', strtotime($from)) ? ' – ' . date('M j, Y', strtotime($to)) : '') : '';
    log_activity(
        $pdo,
        'Deleted activity logs',
        $deleted . ' log' . ($deleted === 1 ? '' : 's') . ($span !== '' ? ' dated ' . $span : '') . ' permanently deleted'
    );
}

// Bagong token para sa susunod na pagbura
unset($_SESSION['al_delete_token']);
$_SESSION['al_flash'] = ['success', $deleted . ' activity log' . ($deleted === 1 ? ' was' : 's were') . ' deleted.'];
header('Location: ' . $back);
exit();
