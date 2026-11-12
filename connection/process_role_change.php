<?php
/**
 * Sagot ng may-ari ng account sa hiling ng admin na palitan ang role niya.
 *
 * Dito lang talaga nagbabago ang users.role. Kung Decline, walang nagbabago sa
 * account - nananatili siya sa kasalukuyang role at nababalitaan ang admin.
 */
session_start();
require __DIR__ . '/database.php';
require __DIR__ . '/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];

/**
 * Ang unang page ng bawat role pagkatapos mag-login.
 */
function sp_role_home(string $role): string
{
    if ($role === 'Admin') {
        return '/Caps2/AdminFile/index.php';
    }
    if ($role === 'Staff') {
        return '/Caps2/StaffFile/staff_dashboard.php';
    }
    return '/Caps2/Adopter_File/adopter_dashboard.php';
}

/**
 * Saan dadalhin ang user kapag hindi nagbago ang role niya (Decline, o
 * napaso na ang hiling). Relatibong path lang sa loob ng app.
 */
$return = $_POST['return'] ?? '';
if ($return === '' || !preg_match('#^/Caps2/[A-Za-z0-9_\-/]+\.php$#', $return)) {
    $return = sp_role_home($_SESSION['role'] ?? 'Adopter');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $return);
    exit();
}

$requestId = (int) ($_POST['request_id'] ?? 0);
$decision = $_POST['decision'] ?? '';

if (!in_array($decision, ['agree', 'decline'], true)) {
    header('Location: ' . $return);
    exit();
}

try {
    // Kanya ba talaga ang hiling na ito, at nakabinbin pa ba?
    $stmt = $pdo->prepare(
        "SELECT * FROM role_change_requests
          WHERE request_id = ? AND user_id = ? AND status = 'Pending'"
    );
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $_SESSION['error_message'] = 'That role change request is no longer available.';
        header('Location: ' . $return);
        exit();
    }

    $toRole = $request['to_role'];
    $fromRole = $request['from_role'];
    $adminId = $request['requested_by'] !== null ? (int) $request['requested_by'] : 0;
    $myName = $_SESSION['username'] ?? ('User #' . $userId);

    if ($decision === 'decline') {
        $pdo->prepare(
            "UPDATE role_change_requests
                SET status = 'Declined', responded_at = NOW()
              WHERE request_id = ? AND status = 'Pending'"
        )->execute([$requestId]);

        if ($adminId > 0) {
            sp_notify(
                $pdo,
                $adminId,
                'role_change',
                'Role change declined',
                $myName . ' declined the change from ' . $fromRole . ' to ' . $toRole . '.',
                null,
                'role_change_result_' . $requestId
            );
        }

        log_activity(
            $pdo,
            'Declined a role change request',
            "Request #{$requestId}: {$fromRole} to {$toRole}. The account stays as {$fromRole}.",
            'Failed'
        );

        $_SESSION['role_change_notice'] = 'You declined the role change. Your account stays as ' . $fromRole . '.';
        header('Location: ' . $return);
        exit();
    }

    // --- Agree: dito lang nagbabago ang role ---
    // Ginagamitan ng from_role na tseke para hindi tumama sa account na naiba
    // na ang role habang nakabinbin ang hiling.
    $update = $pdo->prepare('UPDATE users SET role = ?, status = \'Active\' WHERE user_id = ? AND role = ?');
    $update->execute([$toRole, $userId, $fromRole]);

    if ($update->rowCount() === 0) {
        $pdo->prepare(
            "UPDATE role_change_requests
                SET status = 'Cancelled', responded_at = NOW()
              WHERE request_id = ? AND status = 'Pending'"
        )->execute([$requestId]);

        $_SESSION['error_message'] = 'Your account role has already changed, so this request no longer applies.';
        header('Location: ' . $return);
        exit();
    }

    $pdo->prepare(
        "UPDATE role_change_requests
            SET status = 'Accepted', responded_at = NOW()
          WHERE request_id = ? AND status = 'Pending'"
    )->execute([$requestId]);

    if ($adminId > 0) {
        sp_notify(
            $pdo,
            $adminId,
            'role_change',
            'Role change accepted',
            $myName . ' accepted the change from ' . $fromRole . ' to ' . $toRole . '.',
            null,
            'role_change_result_' . $requestId
        );
    }

    // Itinatala muna bago baguhin ang session, para ang role na nakasulat sa
    // Activity Logs ay iyong hawak pa niya nang tanggapin niya ang hiling.
    log_activity(
        $pdo,
        'Accepted a role change request',
        "Request #{$requestId}: {$fromRole} to {$toRole}."
    );

    // Agad na sumusunod ang session sa bagong role para tama na ang sidebar at
    // ang mga pahintulot sa susunod na page na bubuksan niya.
    $_SESSION['role'] = $toRole;

    $_SESSION['role_change_notice'] = 'Your account is now set as ' . $toRole . '.';
    header('Location: ' . sp_role_home($toRole));
    exit();
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    header('Location: ' . $return);
    exit();
}
