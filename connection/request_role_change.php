<?php
/**
 * Hiling ng admin na palitan ang role ng isang account.
 *
 * Hindi ito ang nagpapalit ng users.role. Nagtatala lang ito ng nakabinbing
 * hiling, at ang may-ari ng account ang magpapasya sa pop-up na lalabas sa
 * itaas ng kanilang screen (tingnan ang role_change_prompt.php). Doon lang,
 * kapag Agree, talagang nagbabago ang role - kaya ligtas kahit may records na
 * ang account, walang nabubura, role lang ang gumagalaw pabalik-balik.
 *
 * Ginagamit ito ng Manage Adopters at ng Manage Staff.
 */
session_start();
require __DIR__ . '/database.php';
require __DIR__ . '/activity_logger.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

/**
 * Saan babalik pagkatapos. Relatibong path lang sa loob ng app ang tinatanggap
 * para hindi ito magamit na open redirect palabas ng site.
 */
$return = $_POST['return'] ?? '';
if ($return === '' || !preg_match('#^/Caps2/[A-Za-z0-9_\-/]+\.php$#', $return)) {
    $return = '/Caps2/AdminFile/Manage_adopters_file/manage_adopters.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $return);
    exit();
}

$action = $_POST['action'] ?? 'request';
$adminId = (int) $_SESSION['user_id'];
$adminName = $_SESSION['username'] ?? 'Administrator';

try {
    if ($action === 'cancel') {
        $requestId = (int) ($_POST['request_id'] ?? 0);

        $stmt = $pdo->prepare(
            "SELECT r.*, u.username
               FROM role_change_requests r
               JOIN users u ON u.user_id = r.user_id
              WHERE r.request_id = ? AND r.status = 'Pending'"
        );
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $_SESSION['error_message'] = 'That role change request is no longer pending.';
            header('Location: ' . $return);
            exit();
        }

        $pdo->prepare(
            "UPDATE role_change_requests
                SET status = 'Cancelled', responded_at = NOW()
              WHERE request_id = ? AND status = 'Pending'"
        )->execute([$requestId]);

        $_SESSION['success_message'] = 'Role change request for ' . $request['username'] . ' has been cancelled.';
        log_activity(
            $pdo,
            "Cancelled the role change request for {$request['username']}",
            "Request #{$requestId}: {$request['from_role']} to {$request['to_role']}."
        );

        header('Location: ' . $return);
        exit();
    }

    // --- Bagong hiling ---
    $userId = (int) ($_POST['user_id'] ?? 0);
    $toRole = $_POST['to_role'] ?? '';

    if (!in_array($toRole, ['Adopter', 'Staff'], true)) {
        $_SESSION['error_message'] = 'Invalid role selected.';
        header('Location: ' . $return);
        exit();
    }

    $stmt = $pdo->prepare('SELECT user_id, username, role FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $_SESSION['error_message'] = 'That account no longer exists.';
        header('Location: ' . $return);
        exit();
    }

    if ($target['role'] === 'Admin') {
        $_SESSION['error_message'] = 'Administrator accounts cannot be reassigned.';
        header('Location: ' . $return);
        exit();
    }

    if ($target['role'] === $toRole) {
        $_SESSION['error_message'] = $target['username'] . ' is already ' . ($toRole === 'Staff' ? 'a Staff member' : 'an Adopter') . '.';
        header('Location: ' . $return);
        exit();
    }

    // Isang nakabinbing hiling lang sa bawat account, para hindi magkapatong
    // ang dalawang pop-up sa panig ng user.
    if (sp_pending_role_request($pdo, $userId) !== null) {
        $_SESSION['error_message'] = $target['username'] . ' already has a pending role change request.';
        header('Location: ' . $return);
        exit();
    }

    $pdo->prepare(
        'INSERT INTO role_change_requests (user_id, from_role, to_role, requested_by, requested_by_name)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $target['role'], $toRole, $adminId, $adminName]);

    $requestId = (int) $pdo->lastInsertId();

    $notifyTitle = $toRole === 'Staff' ? 'You are being assigned as Staff' : 'Your role is being changed back to Adopter';
    $notifyMessage = $toRole === 'Staff'
        ? 'The administrator wants to assign you as a Staff member. Open the app to accept or decline this request.'
        : 'The administrator wants to move your account back to Adopter. Open the app to accept or decline this request.';
    sp_notify($pdo, $userId, 'role_change', $notifyTitle, $notifyMessage, null, 'role_change_' . $requestId);

    $_SESSION['success_message'] = 'Role change request sent to ' . $target['username'] . '. The change will only take effect once they accept it.';
    log_activity(
        $pdo,
        "Requested a role change for {$target['username']}",
        "Request #{$requestId}: {$target['role']} to {$toRole}. Waiting for the account owner's approval.",
        'Pending'
    );
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

header('Location: ' . $return);
exit();
