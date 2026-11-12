<?php
// Awtomatikong pagpapadala ng meeting link sa email ng adopter bago ang online
// interview, sa petsa at oras na siya mismo ang pumili nang mag-apply.
//
// Dalawang paraan ito tinatawag:
//   1. cron/send_interview_reminders.php - CLI script na pinapatakbo ng Windows Task
//      Scheduler kada minuto (ang maaasahang paraan).
//   2. interview_reminder_tick() sa dulo ng database.php - "poor man's cron": kada
//      page load, kung lampas na ng isang minuto mula sa huling check, titingnan kung
//      may due na at ipapadala. Backup lang ito kung hindi naka-setup ang scheduler.
require_once __DIR__ . '/mailer.php';

// Ilang minuto bago ang interview ipapadala ang email, para may oras pang buksan
// ng adopter ang link at makapasok sa tamang oras.
const INTERVIEW_REMINDER_LEAD_MINUTES = 10;

/**
 * Hanapin ang mga interview na dapat nang padalhan ng link at ipadala ang email.
 * Isang beses lang bawat application (meeting_link_sent_at ang tanda).
 *
 * @return array{sent:int, failed:int}
 */
function send_due_interview_reminders(PDO $pdo, int $leadMinutes = INTERVIEW_REMINDER_LEAD_MINUTES): array
{
    // Due kapag: may link na, hindi pa naipapadala, buhay pa ang application, at ang
    // oras ng interview ay nasa loob ng lead window. Hindi na isinasama ang mga
    // interview na lampas na ng isang araw - wala nang saysay ang link doon.
    $stmt = $pdo->prepare(
        "SELECT aa.application_id, aa.adopter_id, aa.full_name, aa.email_address, aa.preferred_interview_date,
                aa.preferred_interview_time, aa.meeting_link, u.email AS account_email, p.name AS pet_name
         FROM adoption_applications aa
         JOIN users u ON u.user_id = aa.adopter_id
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.meeting_link IS NOT NULL AND aa.meeting_link <> ''
           AND aa.meeting_link_sent_at IS NULL
           AND aa.application_status IN ('Pending', 'Under Review')
           AND (aa.schedule_status IS NULL OR aa.schedule_status = 'Accepted')
           AND TIMESTAMP(aa.preferred_interview_date, aa.preferred_interview_time) <= DATE_ADD(NOW(), INTERVAL ? MINUTE)
           AND TIMESTAMP(aa.preferred_interview_date, aa.preferred_interview_time) >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $stmt->execute([$leadMinutes]);
    $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $failed = 0;
    $markStmt = $pdo->prepare("UPDATE adoption_applications SET meeting_link_sent_at = NOW() WHERE application_id = ? AND meeting_link_sent_at IS NULL");

    foreach ($due as $row) {
        // I-claim muna ang row bago mag-send, para kapag sabay na tumakbo ang cron at
        // ang page-load tick ay isa lang ang makakapagpadala.
        $markStmt->execute([$row['application_id']]);
        if ($markStmt->rowCount() === 0) {
            continue;
        }

        if (send_interview_link_email($row)) {
            $sent++;
            // Abiso rin sa loob ng app, may direktang Join link, sakaling hindi
            // agad makita ng adopter ang email.
            if (function_exists('sp_notify')) {
                sp_notify(
                    $pdo,
                    (int) $row['adopter_id'],
                    'interview',
                    'Your interview starts soon',
                    'Your online interview for ' . $row['pet_name'] . ' is about to start. Join here: ' . $row['meeting_link'],
                    $row['meeting_link'],
                    'interview_due_' . $row['application_id']
                );
            }
        } else {
            // Ibalik sa hindi pa naipapadala para masubukan ulit sa susunod na tick
            $pdo->prepare("UPDATE adoption_applications SET meeting_link_sent_at = NULL WHERE application_id = ?")
                ->execute([$row['application_id']]);
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Ang mismong email na may meeting link. Ipinapadala sa email na inilagay sa
 * application form; kung wala, sa email ng account.
 */
function send_interview_link_email(array $app): bool
{
    $to = trim((string) ($app['email_address'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $to = trim((string) ($app['account_email'] ?? ''));
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $name = htmlspecialchars($app['full_name'] ?: 'there', ENT_QUOTES, 'UTF-8');
    $pet = htmlspecialchars($app['pet_name'], ENT_QUOTES, 'UTF-8');
    $link = htmlspecialchars($app['meeting_link'], ENT_QUOTES, 'UTF-8');
    $when = date('l, F j, Y', strtotime($app['preferred_interview_date']))
        . ' at ' . date('g:i A', strtotime($app['preferred_interview_time']));

    try {
        $mail = smartpet_mailer();
        $mail->addAddress($to, $app['full_name'] ?: '');
        $mail->Subject = "Your SMART PET adoption interview for $pet starts soon";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; padding: 24px; border: 1px solid #ddd; border-radius: 10px; max-width: 560px;'>
                <h2 style='color: #2e5b32; margin-top: 0;'>Your interview is about to start</h2>
                <p>Hi $name,</p>
                <p>This is your reminder for your adoption interview for <strong>$pet</strong>, scheduled on
                   <strong>$when</strong>.</p>
                <p>Please join the online meeting using the link below:</p>
                <p style='margin: 24px 0;'>
                    <a href='$link' style='background: #2e5b32; color: #fff; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-block;'>Join Meeting</a>
                </p>
                <p style='font-size: 13px; color: #666;'>If the button does not work, copy this link into your browser:<br>
                   <a href='$link' style='color: #2e5b32;'>$link</a></p>
                <p style='font-size: 13px; color: #666;'>Please be ready a few minutes early with a stable internet connection. See you there!</p>
                <p style='margin-bottom: 0;'>SMART PET &middot; Caloocan City</p>
            </div>
        ";
        $mail->AltBody = "Hi {$app['full_name']}, your adoption interview for {$app['pet_name']} is scheduled on $when. Join here: {$app['meeting_link']}";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Interview reminder email failed for application #' . $app['application_id'] . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Backup trigger na tinatawag kada page load. Kada 60 segundo lang talaga
 * tumatakbo (lock file ang tanda) para hindi bumigat ang bawat request.
 */
function interview_reminder_tick(PDO $pdo): void
{
    $lockFile = __DIR__ . '/../cron/.last_reminder_tick';
    $now = time();

    if (is_file($lockFile) && ($now - (int) @filemtime($lockFile)) < 60) {
        return;
    }
    // I-touch agad para ang mga kasabay na request ay hindi na rin tumakbo
    @touch($lockFile, $now);

    try {
        send_due_interview_reminders($pdo);
    } catch (Throwable $e) {
        error_log('Interview reminder tick failed: ' . $e->getMessage());
    }
}
