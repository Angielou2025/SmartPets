<?php
// Iisang paraan ng pag-login para sa email/password at sa Google:
// itatakda ang session at ipapadala sa tamang dashboard ayon sa role.
function finish_login(PDO $pdo, array $user): void
{
    $pdo->prepare("UPDATE users SET login_attempts = 0, lockout_until = NULL WHERE user_id = ?")
        ->execute([$user['user_id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    unset($_SESSION['prefs_prompt_skipped']);

    if ($user['role'] === 'Admin') {
        header("Location: ../AdminFile/index.php");
    } elseif ($user['role'] === 'Staff') {
        header("Location: ../StaffFile/staff_dashboard.php");
    } else {
        // Dumaraan muna sa maikling animated na pagbati, saka sa pets.
        // Ang flag ang nagsasabing kakalogin lang - isang beses lang ito.
        $_SESSION['show_intro'] = true;
        // Pagkatapos ng loading screen: Account Settings muna (kung kulang ang
        // profile), saka ang "Punan ang Reference" popup - tingnan ang search_pets.php
        $_SESSION['profile_setup_pending'] = true;
        header("Location: ../Adopter_File/welcome_intro.php");
    }
    exit();
}
