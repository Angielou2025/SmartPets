<?php
session_start();

// I-clear ang last_active para instant na maging "Offline" ang user (hindi na maghihintay ng 30s window)
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../connection/database.php';
    $logout_stmt = $pdo->prepare("UPDATE users SET last_active = NULL WHERE user_id = ?");
    $logout_stmt->execute([$_SESSION['user_id']]);
}

// Destroy all session data
$_SESSION = array();

// Destroy the session cookie (parehong path/domain ng session, para talagang mabura)
if (isset($_COOKIE[session_name()])) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

// Destroy the session
session_destroy();

// Hindi na maibabalik ng Back button ang page na naka-login pa
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

?>
<!DOCTYPE html>
<html>
<head>
    <?php // Relative na link (katabi ng logout.php) - gumagana sa localhost at sa online hosting ?>
    <meta http-equiv="refresh" content="0;url=login.php">
    <script>
        // Clear localStorage for staff menu selection on logout
        try { localStorage.removeItem('staff_selected_menu'); sessionStorage.removeItem('staff_selected_menu'); } catch (e) {}
        // Redirect to login page after clearing localStorage
        window.location.replace('login.php');
    </script>
</head>
<body>
</body>
</html>