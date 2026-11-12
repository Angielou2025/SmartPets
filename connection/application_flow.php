<?php
/**
 * Daloy ng adoption application - iisang batayan para sa lahat ng page.
 *
 * Pagkakasunod (hindi ma-a-approve hangga't hindi tapos ang lahat):
 *   1. Interview schedule  - tinanggap ng staff ang napiling araw at oras ng adopter
 *   2. Meeting link        - inilagay ng staff ang Google Meet / Zoom link
 *   3. Interview           - lumipas na ang oras at HINDI minarkahang "Not Attended"
 *                            (kapag Not Attended: magre-rebook ang adopter, balik sa 1)
 *   4. Pet meet-up         - nagpadala ang staff ng meet-up schedule at pumili ang adopter
 *   5. Adoption fee        - nabayaran ng adopter (bago na ang approval)
 *   6. Certificate         - nai-release na ng staff ang certificate sa adopter
 *   7. Approve             - ang staff ang mag-a-approve; kapag hindi niya ginawa sa loob
 *                            ng 24 oras mula nang ma-release ang certificate, kusa na itong
 *                            maa-approve (basta kumpleto ang lahat ng hakbang)
 */

require_once __DIR__ . '/interview_schedule.php';

const SP_AUTO_APPROVE_HOURS = 24;

/**
 * SQL na kondisyon: puwede nang magbayad (at sumunod sa certificate) ang application.
 * Kasama ang mga dati nang Approved para hindi masira ang mga lumang record.
 */
function sp_payable_sql(string $alias = 'aa'): string
{
    return "($alias.application_status = 'Approved'
             OR ($alias.application_status IN ('Pending', 'Under Review')
                 AND $alias.meetup_chosen_at IS NOT NULL
                 AND $alias.interview_missed_at IS NULL))";
}

/** Puwede nang magbayad ang application na ito? (kaparehong patakaran ng sp_payable_sql) */
function sp_app_is_payable(array $app): bool
{
    if (($app['application_status'] ?? '') === 'Approved') {
        return true;
    }
    return in_array($app['application_status'] ?? '', ['Pending', 'Under Review'], true)
        && !empty($app['meetup_chosen_at'])
        && empty($app['interview_missed_at']);
}

/** Puwede nang magbayad (o i-release ang certificate) ang application na may ganitong ID? */
function sp_app_payable_id(PDO $pdo, int $applicationId): bool
{
    try {
        $st = $pdo->prepare("SELECT 1 FROM adoption_applications aa WHERE aa.application_id = ? AND " . sp_payable_sql('aa'));
        $st->execute([$applicationId]);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/** Mensahe kapag hindi pa puwedeng magbayad */
const SP_NOT_PAYABLE_MSG = 'This application is not ready for payment yet. The adopter must finish the interview and choose a pet meet-up date first.';

/**
 * Mga hakbang ng application at ang kalagayan ng bawat isa.
 * state: 'done' | 'current' (ito ang susunod na gagawin) | 'waiting' (hinihintay ang adopter)
 *        | 'blocked' (may problema, hal. hindi dumalo) | 'todo' (hindi pa naaabot)
 *
 * @return array<int, array{key:string, title:string, detail:string, state:string, action:?string, url:?string}>
 */
function sp_app_flow_steps(PDO $pdo, array $app): array
{
    $id = (int) $app['application_id'];
    $schedule = $app['schedule_status'] ?? null;
    $interviewAt = strtotime(($app['preferred_interview_date'] ?? '') . ' ' . ($app['preferred_interview_time'] ?? ''));
    $interviewEnd = $interviewAt ? $interviewAt + SP_INTERVIEW_MINUTES * 60 : 0;
    $whenLabel = $interviewAt ? date('M j, Y g:i A', $interviewAt) : 'the chosen schedule';
    $missed = !empty($app['interview_missed_at']);
    $availUrl = '/Caps2/StaffFile/staff_availability.php' . ($interviewAt ? '?date=' . date('Y-m-d', $interviewAt) : '') . '#requests';

    $paid = false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM payments WHERE application_id = ? AND status = 'Paid' LIMIT 1");
        $st->execute([$id]);
        $paid = (bool) $st->fetchColumn();
    } catch (Exception $e) {
        $paid = false;
    }

    // 1. Interview schedule (NULL = lumang application na tanggap na)
    $s1 = ($schedule === 'Accepted' || ($schedule === null && $interviewAt)) ? 'done'
        : ($schedule === 'Declined' ? 'waiting' : 'current');
    $steps[] = [
        'key' => 'schedule',
        'title' => 'Accept the interview schedule',
        'detail' => $s1 === 'done' ? "Interview set for $whenLabel."
            : ($s1 === 'waiting' ? 'You declined the schedule. Waiting for the adopter to choose a new date.'
                : "The adopter chose $whenLabel. Accept or decline it below."),
        'state' => $s1,
        'action' => $s1 === 'current' ? 'Go to Interview Scheduling' : null,
        'url' => $s1 === 'current' ? '#rv-interview' : null,
    ];

    // 2. Meeting link
    $hasLink = trim((string) ($app['meeting_link'] ?? '')) !== '';
    $s2 = $hasLink ? 'done' : ($s1 === 'done' ? 'current' : 'todo');
    $steps[] = [
        'key' => 'link',
        'title' => 'Add the meeting link',
        'detail' => $hasLink ? 'Meeting link saved. It is emailed to the adopter 10 minutes before the interview.'
            : 'Paste the Google Meet or Zoom link for the interview.',
        'state' => $s2,
        'action' => $s2 === 'current' ? 'Add Meeting Link' : null,
        'url' => $s2 === 'current' ? '#rv-interview' : null,
    ];

    // 3. Interview (dumalo) - kailangang lumipas na ang oras at hindi Not Attended
    if ($missed) {
        $s3 = 'blocked';
        $d3 = empty($app['rebook_sent_at'])
            ? 'Marked as Not Attended. The adopter was asked to message you. Send them a rebooking link so they can pick a new schedule.'
            : 'Marked as Not Attended. Rebooking link sent - waiting for the adopter to choose a new schedule.';
    } elseif ($s1 === 'done' && $hasLink && $interviewEnd && $interviewEnd <= time()) {
        $s3 = 'done';
        $d3 = 'The interview time has passed and the adopter was not marked as Not Attended.';
    } else {
        $s3 = ($s1 === 'done' && $hasLink) ? 'waiting' : 'todo';
        $d3 = "Hold the interview on $whenLabel. Afterwards, choose Not Attended or Send Meet-up Schedule in Interview Availability.";
    }
    $steps[] = [
        'key' => 'interview',
        'title' => 'Hold the interview',
        'detail' => $d3,
        'state' => $s3,
        'action' => $s3 === 'blocked' ? (empty($app['rebook_sent_at']) ? 'Send Rebooking Link' : null) : null,
        'url' => $s3 === 'blocked' && empty($app['rebook_sent_at']) ? '#rv-interview' : null,
    ];

    // 4. Pet meet-up
    $chosen = !empty($app['meetup_chosen_at']);
    $sent = !empty($app['meetup_sent_at']);
    $s4 = $chosen ? 'done' : ($s3 === 'done' ? ($sent ? 'waiting' : 'current') : 'todo');
    $steps[] = [
        'key' => 'meetup',
        'title' => 'Send the pet meet-up schedule',
        'detail' => $chosen ? 'The adopter chose a meet-up date to see the pet at City Hall.'
            : ($sent ? 'Meet-up schedule sent. Waiting for the adopter to choose a date.'
                : 'Send the dates when the adopter can meet the pet at City Hall.'),
        'state' => $s4,
        'action' => $s4 === 'current' ? 'Send Meet-up Schedule' : null,
        'url' => $s4 === 'current' ? $availUrl : null,
    ];

    // 5. Adoption fee (bago na ang approval)
    $s5 = $paid ? 'done' : ($s4 === 'done' ? 'waiting' : 'todo');
    $steps[] = [
        'key' => 'payment',
        'title' => 'Adoption fee paid',
        'detail' => $paid ? 'The adoption fee has been paid.'
            : ($s4 === 'done' ? 'Waiting for the adopter to pay. You can also record a GCash payment in Payments.'
                : 'Payment opens after the adopter chooses a meet-up date.'),
        'state' => $s5,
        'action' => $s5 === 'waiting' ? 'Open Payments' : null,
        'url' => $s5 === 'waiting' ? '/Caps2/StaffFile/payments.php' : null,
    ];

    // 6. Certificate
    $released = !empty($app['cert_released_at']);
    $s6 = $released ? 'done' : ($paid ? 'current' : 'todo');
    $steps[] = [
        'key' => 'certificate',
        'title' => 'Release the certificate',
        'detail' => $released ? 'Certificate released on ' . date('M j, Y g:i A', strtotime($app['cert_released_at'])) . '.'
            : ($paid ? 'Sign the certificate and give it to the adopter in Certificates.' : 'Available after the adoption fee is paid.'),
        'state' => $s6,
        'action' => $s6 === 'current' ? 'Open Certificates' : null,
        'url' => $s6 === 'current' ? '/Caps2/StaffFile/certificates.php' : null,
    ];

    // 7. Approve
    $approved = ($app['application_status'] ?? '') === 'Approved';
    $s7 = $approved ? 'done' : ($released ? 'current' : 'todo');
    $autoAt = $released ? strtotime($app['cert_released_at']) + SP_AUTO_APPROVE_HOURS * 3600 : null;
    $steps[] = [
        'key' => 'approve',
        'title' => 'Approve the application',
        'detail' => $approved ? 'Application approved.'
            : ($released ? 'All steps are done. Approve it now, or it will be approved automatically on ' . date('M j, Y g:i A', $autoAt) . '.'
                : 'Unlocks after the certificate is released.'),
        'state' => $s7,
        'action' => null,
        'url' => null,
    ];

    // Na-approve na (kasama ang mga na-approve sa lumang daloy, bago pa nagkaroon ng
    // meeting link at meet-up): tapos na ang lahat ng hakbang
    if ($approved) {
        foreach ($steps as &$s) {
            if ($s['state'] !== 'done') {
                $s['state'] = 'done';
                $s['detail'] = 'Completed (approved before this step was tracked).';
                $s['action'] = null;
                $s['url'] = null;
            }
        }
        unset($s);
    }

    return $steps;
}

/** Tapos na ba ang lahat ng hakbang bago ang approval? */
function sp_app_can_approve(array $steps): bool
{
    foreach ($steps as $s) {
        if ($s['key'] !== 'approve' && $s['state'] !== 'done') {
            return false;
        }
    }
    return true;
}

/** Ang unang hakbang na hindi pa tapos (ang susunod na gagawin), o null */
function sp_app_next_step(array $steps): ?array
{
    foreach ($steps as $s) {
        if ($s['state'] !== 'done') {
            return $s;
        }
    }
    return null;
}

/**
 * I-approve ang application: Approved na ang status, Adopted na ang alaga, at
 * aabisuhan ang adopter. $staffId = null kapag kusang na-approve (24 oras).
 */
function sp_app_approve(PDO $pdo, array $app, ?int $staffId, bool $auto = false): void
{
    $id = (int) $app['application_id'];
    $pdo->prepare("UPDATE adoption_applications SET application_status = 'Approved', reviewed_by_staff_id = ? WHERE application_id = ? AND application_status IN ('Pending', 'Under Review')")
        ->execute([$staffId, $id]);
    $pdo->prepare("UPDATE pet_inventory SET status = 'Adopted' WHERE pet_id = ?")
        ->execute([$app['pet_id']]);

    $petName = $app['pet_name'] ?? null;
    if ($petName === null) {
        $st = $pdo->prepare("SELECT name FROM pet_inventory WHERE pet_id = ?");
        $st->execute([$app['pet_id']]);
        $petName = $st->fetchColumn() ?: 'your pet';
    }

    sp_notify(
        $pdo,
        (int) $app['adopter_id'],
        'application',
        'Application approved',
        "Congratulations! Your adoption of $petName is now approved. Welcome to your new family member!",
        'my_pets.php',
        'app_approved_' . $id
    );

    if ($auto) {
        // Kusang pag-approve: "System" ang gumawa, hindi ang user na nagbukas ng page
        try {
            $pdo->prepare("INSERT INTO activity_logs (actor_id, actor_name, actor_role, action, details, status) VALUES (NULL, 'System', 'System', ?, ?, 'Completed')")
                ->execute(["Automatically approved adoption application for $petName",
                    'Applicant: ' . ($app['full_name'] ?? '') . ' (' . SP_AUTO_APPROVE_HOURS . ' hours after the certificate was released)']);
        } catch (Exception $e) {
            // hindi hadlang sa approval
        }
    } elseif (function_exists('log_activity')) {
        log_activity($pdo, "Approved adoption application for $petName", 'Applicant: ' . ($app['full_name'] ?? ''));
    }
}

/**
 * Kusang pag-approve: mga application na 24 oras nang na-release ang certificate,
 * hindi pa na-approve ng staff, at kumpleto ang lahat ng hakbang. Tinatawag sa bawat
 * page (connection/chat_bubble.php), pero tumatakbo lang nang isang beses bawat 5 minuto.
 */
function sp_auto_approve_due(PDO $pdo): void
{
    $flag = __DIR__ . '/.auto_approve_last';
    $last = (int) @file_get_contents($flag);
    if ($last > time() - 300) {
        return;
    }
    @file_put_contents($flag, (string) time());

    try {
        $stmt = $pdo->prepare(
            "SELECT aa.*, p.name AS pet_name FROM adoption_applications aa
             JOIN pet_inventory p ON p.pet_id = aa.pet_id
             WHERE aa.application_status IN ('Pending', 'Under Review')
               AND aa.cert_released_at IS NOT NULL
               AND aa.cert_released_at <= DATE_SUB(NOW(), INTERVAL " . SP_AUTO_APPROVE_HOURS . " HOUR)"
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $app) {
            if (sp_app_can_approve(sp_app_flow_steps($pdo, $app))) {
                sp_app_approve($pdo, $app, null, true);
            }
        }
    } catch (Exception $e) {
        // hindi hadlang sa pagbukas ng page; susubukan ulit mamaya
    }
}
