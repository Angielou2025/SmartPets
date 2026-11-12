<?php
/**
 * OTP para sa pag-verify ng Gmail habang nagre-register.
 * Hindi ginagawa ang account hangga't hindi naipapasok ang tamang code - ito
 * ang patunay na totoo ang Gmail at sa nagre-register talaga ito.
 *
 * Kailangang naka-session_start() at na-require ang connection/mailer.php.
 */

const SP_REG_OTP_MINUTES      = 10; // ilang minuto bago mag-expire ang code
const SP_REG_OTP_MAX_ATTEMPTS = 5;  // ilang maling subok bago kailangang magpadala ng bago
const SP_REG_OTP_RESEND_WAIT  = 60; // segundo bago pwedeng magpadala ulit

/**
 * Gmail address ba ito na may tamang anyo? (Ang OTP ang magpapatunay na totoo ito.)
 */
function sp_is_gmail_address(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/^[a-z0-9](?:[a-z0-9.+]*[a-z0-9])?@gmail\.com$/i', $email) === 1;
}

/**
 * Gumawa at magpadala ng bagong OTP. Ang hash lang ng code ang itinatabi sa session.
 */
function sp_send_registration_otp(string $email, string $username): bool
{
    $otp = sprintf('%06d', random_int(0, 999999));

    $sent = sp_send_email(
        $email,
        $username,
        'Smart Pet - Verify your Gmail account',
        'Verify your email',
        [
            'Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',',
            'Use this code to finish creating your Smart Pet account:',
            "<span style='display:inline-block; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #2e5b32;'>$otp</span>",
            'This code expires in ' . SP_REG_OTP_MINUTES . ' minutes.',
            "<span style='font-size: 12px; color: #666;'>If you did not try to create a Smart Pet account, you can ignore this email.</span>",
        ]
    );

    if ($sent) {
        $_SESSION['pending_registration']['otp_hash']   = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['pending_registration']['otp_expiry'] = time() + SP_REG_OTP_MINUTES * 60;
        $_SESSION['pending_registration']['otp_sent']   = time();
        $_SESSION['pending_registration']['attempts']   = 0;
    }
    return $sent;
}

/**
 * Email na nagsasabing naka-register na ang Gmail na ito sa Smart Pet.
 */
function sp_send_registered_notice(string $email, string $username): bool
{
    return sp_send_email(
        $email,
        $username,
        'Smart Pet - This email is now registered',
        'Welcome to Smart Pet!',
        [
            'Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',',
            'This email (<strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong>) is now registered to Smart Pet. Your account has been created and verified.',
            'You can now log in, browse pets available for adoption, and submit an adoption application.',
            "<span style='font-size: 12px; color: #666;'>If you did not create this account, please contact the Caloocan City Veterinary Office right away.</span>",
        ],
        'Log in to Smart Pet',
        sp_app_url('Login_Page/login.php')
    );
}
