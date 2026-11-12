<?php
// Iisang lugar ng SMTP setup ng system email para hindi na inuulit sa bawat file
// na nagpapadala ng email (interview reminders, atbp.).
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../Adopter_File/PHPMailer/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../Adopter_File/PHPMailer/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../Adopter_File/PHPMailer/PHPMailer-master/src/SMTP.php';

/**
 * Bagong PHPMailer na naka-configure na sa Gmail account ng Smart Pet System.
 * Ang tumatawag na lang ang maglalagay ng recipient, subject, at body.
 */
function smartpet_mailer(): PHPMailer
{
    // ILAGAY DITO ANG TOTOONG GMAIL AT APP PASSWORD MO:
    $system_email = 'angielou2024@gmail.com';
    $app_password = 'xyqbrcbwivlahgmw';

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $system_email;
    $mail->Password   = $app_password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';
    // Relax OpenSSL peer verification for local/XAMPP development environments
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];
    $mail->setFrom($system_email, 'Smart Pet System');
    $mail->isHTML(true);

    return $mail;
}

/**
 * Buong URL ng isang page sa system (para sa mga link sa loob ng email).
 * Hal. sp_app_url('Adopter_File/schedule.php')
 */
function sp_app_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/Caps2/' . ltrim($path, '/');
}

/**
 * Simpleng email na may pamagat, mga talata, at (opsyonal) isang button.
 * Ang $paragraphs ay HTML na - ang tumatawag ang bahalang mag-escape ng datos.
 * Hindi nagtatapon ng error: false lang kapag hindi naipadala.
 * $attachments (opsyonal): [['name' => 'file.pdf', 'data' => <laman>, 'type' => 'application/pdf'], ...]
 */
function sp_send_email(string $to, string $toName, string $subject, string $heading, array $paragraphs, ?string $buttonText = null, ?string $buttonUrl = null, array $attachments = []): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $body = "<div style='font-family: Arial, sans-serif; padding: 24px; border: 1px solid #ddd; border-radius: 10px; max-width: 560px;'>"
        . "<h2 style='color: #2e5b32; margin-top: 0;'>" . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . "</h2>";
    foreach ($paragraphs as $p) {
        $body .= "<p>$p</p>";
    }
    if ($buttonText && $buttonUrl) {
        $url = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
        $body .= "<p style='margin: 24px 0;'><a href='$url' style='background: #2e5b32; color: #fff; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block;'>"
            . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . "</a></p>"
            . "<p style='font-size: 13px; color: #666;'>If the button does not work, copy this link into your browser:<br><a href='$url' style='color: #2e5b32;'>$url</a></p>";
    }
    $body .= "<p style='margin-bottom: 0;'>SMART PET &middot; Caloocan City</p></div>";

    try {
        $mail = smartpet_mailer();
        $mail->addAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = trim(strip_tags(implode("\n\n", $paragraphs))) . ($buttonUrl ? "\n\n$buttonText: $buttonUrl" : '');
        foreach ($attachments as $att) {
            if (!empty($att['data']) && !empty($att['name'])) {
                $mail->addStringAttachment($att['data'], $att['name'], PHPMailer::ENCODING_BASE64, $att['type'] ?? 'application/octet-stream');
            }
        }
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('sp_send_email failed (' . $subject . '): ' . $e->getMessage());
        return false;
    }
}

/**
 * Email ng adopter para sa isang application: ang inilagay sa form, kung wala
 * ay ang email ng account.
 */
function sp_application_email(PDO $pdo, array $application): string
{
    $to = trim((string) ($application['email_address'] ?? ''));
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return $to;
    }
    $stmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ?');
    $stmt->execute([(int) $application['adopter_id']]);
    return trim((string) $stmt->fetchColumn());
}
