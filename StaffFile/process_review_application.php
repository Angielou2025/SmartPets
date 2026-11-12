<?php
session_start();
require_once __DIR__ . '/../connection/database.php';
require_once __DIR__ . '/../connection/activity_logger.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'])) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

// Admin ang nag-review (mula sa Manage Adopters o global search) - doon babalik pagkatapos
// i-decide. Ang hidden "from" field (na sinusunod mula sa review page) ang pangunahing
// pinagbabatayan, dahil iyon ang totoong pinanggalingan ng aksyon.
$isAdminViewer = ($_POST['from'] ?? '') === 'admin' || ($_SESSION['role'] ?? '') === 'Admin';
$backUrl = $isAdminViewer ? '/Caps2/AdminFile/Manage_adopters_file/manage_adopters.php' : 'applications.php';

$application_id = (int) ($_POST['application_id'] ?? 0);
$action = $_POST['action'] ?? '';
$staff_id = $_SESSION['user_id'];
// May sariling review page na ang Admin sa ilalim ng AdminFile, kaya doon siya
// ibinabalik - hindi na sa loob ng Staff section.
$reviewUrl = $isAdminViewer
    ? '/Caps2/AdminFile/review_application.php?application_id=' . $application_id
    : '/Caps2/StaffFile/review_application.php?application_id=' . $application_id;

// Galing sa Interview Availability (pag-click sa Booked na slot): doon babalik
if (($_POST['return'] ?? '') === 'availability') {
    $reviewUrl = '/Caps2/StaffFile/staff_availability.php';
    $backUrl = $reviewUrl;
}

$appStmt = $pdo->prepare("SELECT * FROM adoption_applications WHERE application_id = ?");
$appStmt->execute([$application_id]);
$application = $appStmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header('Location: ' . $backUrl);
    exit();
}

if (in_array($application['application_status'], ['Approved', 'Rejected'])) {
    header('Location: ' . $reviewUrl);
    exit();
}

$petNameStmt = $pdo->prepare("SELECT name FROM pet_inventory WHERE pet_id = ?");
$petNameStmt->execute([$application['pet_id']]);
$petName = $petNameStmt->fetchColumn() ?: "Pet #{$application['pet_id']}";

// Galing sa listahan ng Interview Requests sa Interview Availability
if (($_POST['return'] ?? '') === 'requests') {
    $returnDate = (string) ($_POST['return_date'] ?? '');
    $reviewUrl = '/Caps2/StaffFile/staff_availability.php'
        . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) ? '?date=' . $returnDate : '') . '#requests';
    $backUrl = $reviewUrl;
}

// ===== Interview request ng adopter: Accept o Decline =====
if ($action === 'accept_schedule' || $action === 'decline_schedule') {
    require_once __DIR__ . '/../connection/interview_schedule.php';

    if (($application['schedule_status'] ?? null) !== 'Pending') {
        $_SESSION['error_message'] = 'This interview request has already been answered.';
        header('Location: ' . $reviewUrl);
        exit();
    }
    $when = sp_interview_when($application);
    $adopterId = (int) $application['adopter_id'];

    if ($action === 'accept_schedule') {
        if (strtotime($application['preferred_interview_date'] . ' ' . $application['preferred_interview_time']) <= time()) {
            $_SESSION['error_message'] = 'This requested time has already passed. Decline it so the adopter can choose a new date.';
            header('Location: ' . $reviewUrl);
            exit();
        }
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE adoption_applications
             SET schedule_status = 'Accepted', schedule_decline_reason = NULL,
                 schedule_decided_by = ?, schedule_decided_at = NOW(), meeting_link_sent_at = NULL
             WHERE application_id = ?"
        )->execute([$staff_id, $application_id]);
        // Kusang gagamitin ang naka-save na meeting link para sa lahat (kung wala pang sariling link)
        $defaultLink = sp_default_meeting_link($pdo);
        if ($defaultLink !== '') {
            $pdo->prepare("UPDATE adoption_applications SET meeting_link = ?, meeting_link_set_by = ?
                           WHERE application_id = ? AND (meeting_link IS NULL OR meeting_link = '')")
                ->execute([$defaultLink, $staff_id, $application_id]);
        }
        sp_interview_book_accepted($pdo, $application, (int) $staff_id);
        $pdo->commit();

        sp_notify(
            $pdo,
            $adopterId,
            'interview',
            'Interview schedule accepted',
            "The staff accepted your interview for $petName on $when. The meeting link will be emailed to you 10 minutes before it starts.",
            'schedule.php',
            'schedule_accepted_' . $application_id . '_' . strtotime($application['preferred_interview_date'] . ' ' . $application['preferred_interview_time'])
        );
        sp_send_message(
            $pdo,
            (int) $staff_id,
            $adopterId,
            "Hi {$application['full_name']}, your interview for $petName on $when has been accepted. See you then! "
            . "The meeting link will be emailed to you 10 minutes before the interview starts."
        );

        $_SESSION['success_message'] = "Interview accepted for $when. The adopter has been notified.";
        log_activity($pdo, "Accepted interview schedule for $petName", "Applicant: {$application['full_name']}, Schedule: $when");
        header('Location: ' . $reviewUrl);
        exit();
    }

    // Decline: kailangan ang dahilan - ipinapadala ito sa adopter sa Messages
    $reason = mb_substr(trim((string) ($_POST['decline_reason'] ?? '')), 0, 1000);
    if ($reason === '') {
        $_SESSION['error_message'] = 'Please tell the adopter why this schedule cannot be accepted.';
        header('Location: ' . $reviewUrl);
        exit();
    }

    $pdo->prepare(
        "UPDATE adoption_applications
         SET schedule_status = 'Declined', schedule_decline_reason = ?,
             schedule_decided_by = ?, schedule_decided_at = NOW()
         WHERE application_id = ?"
    )->execute([$reason, $staff_id, $application_id]);

    $pickPath = 'schedule.php#request-' . $application_id;
    sp_notify(
        $pdo,
        $adopterId,
        'interview',
        'Choose a new interview date',
        "The staff could not accept your interview for $petName on $when. Reason: $reason Please choose a new date.",
        $pickPath,
        'schedule_declined_' . $application_id . '_' . time()
    );
    sp_send_message(
        $pdo,
        (int) $staff_id,
        $adopterId,
        "Hi {$application['full_name']}, we are sorry, but we cannot accept your interview for $petName on $when.\n\n"
        . "Reason: $reason\n\nPlease choose another date and time that works for you."
        . "\n" . sp_message_button('Choose a New Interview Date', $pickPath)
    );

    require_once __DIR__ . '/../connection/mailer.php';
    sp_send_email(
        sp_application_email($pdo, $application),
        $application['full_name'],
        "Please choose a new interview date for $petName",
        'Choose a new interview date',
        [
            'Hi ' . htmlspecialchars($application['full_name'], ENT_QUOTES, 'UTF-8') . ',',
            'We cannot accept your interview for <strong>' . htmlspecialchars($petName, ENT_QUOTES, 'UTF-8')
                . '</strong> on <strong>' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</strong>.',
            'Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'),
            'Please choose another date and time using the button below.',
        ],
        'Choose a New Date',
        sp_app_url('Adopter_File/schedule.php') . '#request-' . $application_id
    );

    $_SESSION['success_message'] = 'Interview request declined. The adopter was sent your reason and asked to choose a new date.';
    log_activity($pdo, "Declined interview schedule for $petName", "Applicant: {$application['full_name']}, Requested: $when, Reason: $reason");
    header('Location: ' . $reviewUrl);
    exit();
}

if ($action === 'set_meeting_link') {
    // Tinatanggap ang link kahit ano ang kinopya mula sa Google Meet: may o walang
    // https://, o kasama ang ibang text (hal. "Join with Google Meet: meet.google.com/abc-defg-hij").
    $link = trim($_POST['meeting_link'] ?? '');
    if ($link !== '') {
        if (preg_match('#^[a-z]{3}-[a-z]{4}-[a-z]{3}$#i', $link)) {
            // Meeting code lang ang na-paste
            $link = 'https://meet.google.com/' . strtolower($link);
        } elseif (preg_match('#https?://[^\s<>"\']+#i', $link, $m)) {
            $link = $m[0];
        } elseif (preg_match('#(?:[a-z0-9-]+\.)+[a-z]{2,}(?:/[^\s<>"\']*)?#i', $link, $m)) {
            $link = 'https://' . $m[0];
        }
        $link = rtrim($link, '.,;)');
    }
    if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) {
        $_SESSION['error_message'] = 'Please paste a valid meeting link, e.g. meet.google.com/abc-defg-hij.';
        header('Location: ' . $reviewUrl);
        exit();
    }

    // Kapag nagbago ang link, i-reset ang "naipadala na" na tanda para ang bagong
    // link ang maipadala sa adopter (agad kung malapit na o lampas na ang oras).
    $linkChanged = $link !== (string) ($application['meeting_link'] ?? '');
    $pdo->prepare("UPDATE adoption_applications SET meeting_link = ?, meeting_link_sent_at = IF(?, NULL, meeting_link_sent_at),
                          meeting_link_set_by = ? WHERE application_id = ?")
        ->execute([$link !== '' ? $link : null, $linkChanged ? 1 : 0, $link !== '' ? (int) $staff_id : null, $application_id]);

    // "Use this link for all interviews": ise-save bilang default at ilalagay sa mga
    // susunod na tinanggap na interview na wala pang link
    $madeDefault = 0;
    if (!empty($_POST['make_default']) && $link !== '') {
        require_once __DIR__ . '/../connection/interview_schedule.php';
        $pdo->prepare("UPDATE interview_settings SET default_meeting_link = ? WHERE setting_id = 1")->execute([$link]);
        $fill = $pdo->prepare(
            "UPDATE adoption_applications
             SET meeting_link = ?, meeting_link_sent_at = NULL, meeting_link_set_by = ?
             WHERE schedule_status = 'Accepted' AND (meeting_link IS NULL OR meeting_link = '')
               AND CONCAT(preferred_interview_date, ' ', preferred_interview_time) > NOW()"
        );
        $fill->execute([$link, (int) $staff_id]);
        $madeDefault = $fill->rowCount();
        log_activity($pdo, 'Set default interview meeting link', "Link: $link");
    } elseif (!empty($_POST['clear_default'])) {
        $pdo->exec("UPDATE interview_settings SET default_meeting_link = NULL WHERE setting_id = 1");
        log_activity($pdo, 'Removed default interview meeting link', '');
    }

    if ($link === '') {
        $_SESSION['success_message'] = 'Meeting link removed.';
        log_activity($pdo, "Removed interview meeting link for $petName", "Applicant: {$application['full_name']}");
    } else {
        $_SESSION['success_message'] = 'Meeting link saved. It will be emailed to the adopter 10 minutes before the interview.';
        log_activity($pdo, "Set interview meeting link for $petName", "Applicant: {$application['full_name']}, Link: $link");

        if ($linkChanged) {
            $when = date('M j, Y', strtotime($application['preferred_interview_date']))
                . ' at ' . date('g:i A', strtotime($application['preferred_interview_time']));
            sp_notify(
                $pdo,
                (int) $application['adopter_id'],
                'interview',
                'Interview meeting link is ready',
                "Your online interview for $petName is on $when. The meeting link will be emailed to you 10 minutes before it starts.",
                'schedule.php',
                'meeting_link_' . $application_id . '_' . md5($link)
            );
        }
    }

    // Kung due na (o lampas na) ang interview, ipadala na agad imbes na hintayin ang tick
    require_once __DIR__ . '/../connection/interview_reminders.php';
    send_due_interview_reminders($pdo);
    $sentCheck = $pdo->prepare("SELECT meeting_link_sent_at FROM adoption_applications WHERE application_id = ?");
    $sentCheck->execute([$application_id]);
    if ($link !== '' && $linkChanged && $sentCheck->fetchColumn()) {
        $_SESSION['success_message'] = 'Meeting link saved and emailed to the adopter.';
    }
    if (!empty($_POST['make_default']) && $link !== '') {
        $_SESSION['success_message'] .= ' This link will now be used for all interviews'
            . ($madeDefault > 0 ? " (also added to $madeDefault upcoming interview" . ($madeDefault === 1 ? '' : 's') . ').' : '.');
    }

    header('Location: ' . $reviewUrl);
    exit();
}

if ($action === 'mark_no_show') {
    $interviewAt = strtotime($application['preferred_interview_date'] . ' ' . $application['preferred_interview_time']);
    if ($interviewAt > time()) {
        $_SESSION['error_message'] = 'You can only mark a no-show after the interview time has passed.';
        header('Location: ' . $reviewUrl);
        exit();
    }
    if (!empty($application['interview_missed_at'])) {
        header('Location: ' . $reviewUrl);
        exit();
    }

    // Walang rebooking link pa - kailangan munang kontakin ng adopter ang staff
    $pdo->prepare("UPDATE adoption_applications SET interview_missed_at = NOW(), interview_missed_by = ?, rebook_sent_at = NULL WHERE application_id = ?")
        ->execute([$staff_id, $application_id]);

    $when = date('M j, Y', $interviewAt) . ' at ' . date('g:i A', $interviewAt);
    sp_notify(
        $pdo,
        (int) $application['adopter_id'],
        'interview',
        'You did not attend the meeting',
        "You did not attend your interview for $petName on $when. Please contact the staff in Messages so they can send you a rebooking link.",
        'messages.php',
        'no_show_' . $application_id . '_' . $interviewAt
    );
    // Direktang tanong mula sa staff sa Messages, para doon din siya makasagot
    sp_send_message(
        $pdo,
        (int) $staff_id,
        (int) $application['adopter_id'],
        "Hi {$application['full_name']}, you did not attend your adoption interview for $petName on $when. "
        . "May we know what happened? Please reply here, and we will send you a rebooking link so you can choose a new schedule."
    );

    require_once __DIR__ . '/../connection/mailer.php';
    $emailed = sp_send_email(
        sp_application_email($pdo, $application),
        $application['full_name'],
        "You did not attend your SMART PET interview for $petName",
        'You did not attend the meeting',
        [
            'Hi ' . htmlspecialchars($application['full_name'], ENT_QUOTES, 'UTF-8') . ',',
            'You did not attend your adoption interview for <strong>' . htmlspecialchars($petName, ENT_QUOTES, 'UTF-8')
                . '</strong> on <strong>' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '</strong>.',
            'Your application is still open. Please message our staff and tell us what happened. They will send you a rebooking link so you can choose a new interview schedule.',
        ],
        'Message the Staff',
        sp_app_url('Adopter_File/messages.php')
    );

    $_SESSION['success_message'] = 'Marked as did not attend. The adopter has been notified'
        . ($emailed ? ' by message and email' : ' (the email could not be sent)')
        . '. Send them a rebooking link once they contact you.';
    log_activity($pdo, "Marked interview as missed for $petName", "Applicant: {$application['full_name']}, Scheduled: $when");
    header('Location: ' . $reviewUrl);
    exit();
}

if ($action === 'send_rebook_link') {
    if (empty($application['interview_missed_at'])) {
        header('Location: ' . $reviewUrl);
        exit();
    }

    $pdo->prepare("UPDATE adoption_applications SET rebook_sent_at = NOW() WHERE application_id = ?")
        ->execute([$application_id]);

    require_once __DIR__ . '/../connection/mailer.php';
    // Dadalhin ang adopter sa Schedule step ng My Preference, kung saan niya
    // pipindutin ang bagong oras ng interview
    // Bumubukas ang rebooking popup sa Schedule page ng adopter
    $rebookPath = 'schedule.php?rebook=' . $application_id;
    $rebookUrl = sp_app_url('Adopter_File/' . $rebookPath);

    sp_notify(
        $pdo,
        (int) $application['adopter_id'],
        'interview',
        'Rebooking link for your interview',
        "The staff sent you a rebooking link. Choose a new interview schedule for $petName.",
        $rebookPath,
        'rebook_' . $application_id . '_' . time()
    );
    sp_send_message(
        $pdo,
        (int) $staff_id,
        (int) $application['adopter_id'],
        "Here is your rebooking link for your adoption interview for $petName. Open it and choose a new schedule:\n$rebookUrl"
    );

    $emailed = sp_send_email(
        sp_application_email($pdo, $application),
        $application['full_name'],
        "Rebook your SMART PET interview for $petName",
        'Choose a new interview schedule',
        [
            'Hi ' . htmlspecialchars($application['full_name'], ENT_QUOTES, 'UTF-8') . ',',
            'You can now choose a new interview schedule for <strong>' . htmlspecialchars($petName, ENT_QUOTES, 'UTF-8') . '</strong>. Use the button below to pick an available time.',
        ],
        'Choose a New Schedule',
        $rebookUrl
    );

    $_SESSION['success_message'] = 'Rebooking link sent to the adopter'
        . ($emailed ? ' by notification, message, and email.' : ' by notification and message (the email could not be sent).');
    log_activity($pdo, "Sent interview rebooking link for $petName", "Applicant: {$application['full_name']}");
    header('Location: ' . $reviewUrl);
    exit();
}

if ($action === 'save_interview_summary') {
    // Buod ng interview: puwede lang pagkatapos magsimula ang interview at kung dumalo ang adopter
    require_once __DIR__ . '/../connection/interview_summary.php';
    $interviewAt = strtotime($application['preferred_interview_date'] . ' ' . $application['preferred_interview_time']);
    if (!empty($application['interview_missed_at'])) {
        $_SESSION['error_message'] = 'This adopter did not attend the interview, so there is no interview to summarize.';
        header('Location: ' . $reviewUrl . '#rv-summary');
        exit();
    }
    if ($interviewAt > time()) {
        $_SESSION['error_message'] = 'You can write the interview summary once the interview has started.';
        header('Location: ' . $reviewUrl . '#rv-summary');
        exit();
    }

    $text = fn (string $key, int $max = 2000) => mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
    $pick = fn (string $key, array $allowed) => in_array($_POST[$key] ?? '', $allowed, true) ? $_POST[$key] : null;
    $num = function (string $key, int $max) {
        $v = trim((string) ($_POST[$key] ?? ''));
        return ($v !== '' && ctype_digit($v) && (int) $v <= $max) ? (int) $v : null;
    };

    $fields = [
        'adopter_name' => $text('adopter_name', 100),
        'adopter_age' => $num('adopter_age', 120),
        'civil_status' => $text('civil_status', 30),
        'occupation' => $text('occupation', 100),
        'contact_number' => $text('contact_number', 30),
        'address' => $text('address', 500),
        'household_members' => $text('household_members'),
        'residence_type' => $pick('residence_type', SP_SUMMARY_RESIDENCE_TYPES),
        'landlord_allows_pets' => $pick('landlord_allows_pets', SP_SUMMARY_LANDLORD),
        'other_pets' => $text('other_pets'),
        'pet_experience' => $text('pet_experience'),
        'reason_for_adopting' => $text('reason_for_adopting'),
        'care_plan' => $text('care_plan'),
        'hours_alone' => $num('hours_alone', 24),
        'monthly_budget' => $text('monthly_budget', 100),
        'adopter_questions' => $text('adopter_questions'),
        'discussion_notes' => $text('discussion_notes', 5000),
        'recommendation' => $pick('recommendation', SP_SUMMARY_RECOMMENDATIONS),
        'remarks' => $text('remarks'),
    ];

    if ($fields['adopter_name'] === '' || $fields['discussion_notes'] === '' || $fields['recommendation'] === null) {
        $_SESSION['error_message'] = 'Please fill in the adopter name, the summary of the discussion, and your recommendation.';
        header('Location: ' . $reviewUrl . '#rv-summary');
        exit();
    }
    // Printed name at pirma ng staff (draw o larawan na tinanggalan ng background)
    require_once __DIR__ . '/../connection/signature_pad.php';
    $existingSummary = sp_interview_summary($pdo, $application_id);
    $staffPrinted = $text('staff_printed_name', 150);
    $newSignature = trim((string) ($_POST['staff_signature'] ?? ''));
    if ($staffPrinted === '') {
        $_SESSION['error_message'] = 'Please type your printed name under Interviewer Signature.';
        header('Location: ' . $reviewUrl . '#rv-summary');
        exit();
    }
    $savedSignature = null;
    if ($newSignature !== '') {
        $savedSignature = sp_save_signature($newSignature, 'staff_' . $application_id);
        if ($savedSignature === null) {
            $_SESSION['error_message'] = 'Your signature could not be saved. Please draw or upload it again.';
            header('Location: ' . $reviewUrl . '#rv-summary');
            exit();
        }
    } elseif (empty($existingSummary['staff_signature'])) {
        $_SESSION['error_message'] = 'Please add your signature under Interviewer Signature.';
        header('Location: ' . $reviewUrl . '#rv-summary');
        exit();
    }

    // Isinasalin sa propesyonal na English ang mga paliwanag ng staff bago i-save
    // (ito ang makikita ng adopter at ang nasa PDF)
    $fields = sp_summary_to_english($fields);

    $fields['staff_printed_name'] = $staffPrinted;
    if ($savedSignature !== null) {
        $fields['staff_signature'] = $savedSignature;
        $fields['staff_signed_at'] = date('Y-m-d H:i:s');
    }

    // Walang laman = NULL sa database
    foreach ($fields as $k => $v) {
        if ($v === '') {
            $fields[$k] = null;
        }
    }

    $isUpdate = sp_interview_summary($pdo, $application_id) !== null;
    $cols = array_keys($fields);
    $pdo->prepare(
        'INSERT INTO interview_summaries (application_id, staff_id, ' . implode(', ', $cols) . ')
         VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')
         ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id), '
            . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $cols))
    )->execute(array_merge([$application_id, $staff_id], array_values($fields)));

    $summaryPath = 'interview_summary.php?id=' . $application_id;
    $verb = $isUpdate ? 'updated' : 'is ready';
    sp_notify(
        $pdo,
        (int) $application['adopter_id'],
        'interview',
        'Interview summary ' . $verb,
        "The summary report of your interview for $petName $verb. Tap to view everything that was discussed.",
        $summaryPath,
        'interview_summary_' . $application_id . '_' . time()
    );
    sp_send_message(
        $pdo,
        (int) $staff_id,
        (int) $application['adopter_id'],
        "Hi {$application['full_name']}, thank you for your time in the interview for $petName. "
        . ($isUpdate ? 'We updated the summary report of your interview.' : 'Here is the summary report of what we discussed.')
        . "\n" . sp_message_button('View Interview Summary', $summaryPath)
    );

    require_once __DIR__ . '/../connection/mailer.php';
    // Kasama sa email ang PDF ng report (kung nagawa); link pa rin ang nasa notification at message
    $summaryPdf = sp_summary_pdf($pdo, $application_id);
    $attachments = $summaryPdf !== null ? [[
        'name' => 'Interview_Summary_APP-' . str_pad((string) $application_id, 3, '0', STR_PAD_LEFT) . '.pdf',
        'data' => $summaryPdf,
        'type' => 'application/pdf',
    ]] : [];
    $emailed = sp_send_email(
        sp_application_email($pdo, $application),
        $application['full_name'],
        "Your SMART PET interview summary for $petName",
        'Your interview summary report',
        [
            'Hi ' . htmlspecialchars($application['full_name'], ENT_QUOTES, 'UTF-8') . ',',
            'Thank you for attending your adoption interview for <strong>' . htmlspecialchars($petName, ENT_QUOTES, 'UTF-8') . '</strong>.',
            'The summary report of everything we discussed ' . $verb . '. '
                . ($attachments ? 'A PDF copy is attached to this email. ' : '')
                . 'You can also use the button below to view or print it.',
        ],
        'View Interview Summary',
        sp_app_url('Adopter_File/' . $summaryPath),
        $attachments
    );

    $_SESSION['success_message'] = 'Interview summary ' . ($isUpdate ? 'updated' : 'saved') . ' and sent to the adopter'
        . ($emailed
            ? ' by notification, message, and email' . ($attachments ? ' (with PDF copy).' : ' (the PDF could not be created).')
            : ' by notification and message (the email could not be sent).');
    log_activity($pdo, ($isUpdate ? 'Updated' : 'Saved') . " interview summary for $petName",
        "Applicant: {$application['full_name']}, Recommendation: {$fields['recommendation']}");
    header('Location: ' . $reviewUrl . '#rv-summary');
    exit();
}

if ($action === 'send_meetup') {
    // Pet Meet-up: para lang sa adopter na dumalo sa interview at okay para sa staff
    if (!empty($application['interview_missed_at'])) {
        $_SESSION['error_message'] = 'This adopter did not attend the interview. Send a reschedule link first.';
        header('Location: ' . $reviewUrl);
        exit();
    }

    // Kapareho ng Weekly Schedule ng City Hall: mga araw na bukas (Lun-Linggo) at oras
    // ng bukas at sara. Walang pagpili ng oras - puwedeng pumunta ang adopter anumang
    // oras habang bukas ang City Hall sa araw na pipiliin niya (sa susunod na 30 araw).
    $meetupFail = function (string $message) use ($reviewUrl): void {
        $_SESSION['error_message'] = $message;
        header('Location: ' . $reviewUrl);
        exit();
    };

    // ISO: 1 = Lunes ... 7 = Linggo
    $days = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['meet_days'] ?? [])), fn ($n) => $n >= 1 && $n <= 7)));
    $openTime = trim((string) ($_POST['meet_open'] ?? ''));
    $closeTime = trim((string) ($_POST['meet_close'] ?? ''));
    $note = mb_substr(trim((string) ($_POST['meetup_note'] ?? '')), 0, 500);

    if (!preg_match('/^\d{2}:\d{2}$/', $openTime) || !preg_match('/^\d{2}:\d{2}$/', $closeTime) || $closeTime <= $openTime) {
        $meetupFail('Please set an opening time that is earlier than the closing time.');
    }
    if (!$days) {
        $meetupFail('Please choose at least one open day.');
    }
    require_once __DIR__ . '/../connection/interview_schedule.php';
    $from = new DateTime('today');
    $to = (new DateTime('today'))->modify('+' . SP_MEETUP_WINDOW_DAYS . ' days');

    // Bawat araw na bukas ang City Hall, at hindi pa lumilipas.
    // Nilalaktawan ang special days (hal. Dec 25) at mga Emergency closure.
    $specialMap = sp_interview_special_map($pdo, (int) $from->format('Y'), (int) $to->format('Y'));
    $options = [];
    for ($d = clone $from; $d <= $to; $d->modify('+1 day')) {
        if (!in_array((int) $d->format('N'), $days, true)) {
            continue;
        }
        if (isset($specialMap[$d->format('Y-m-d')])) {
            continue;
        }
        if (strtotime($d->format('Y-m-d') . ' ' . $closeTime) <= time()) {
            continue;
        }
        $options[] = [$d->format('Y-m-d'), $openTime . ':00', $closeTime . ':00'];
    }

    if (!$options) {
        $meetupFail('City Hall has no open day in the next ' . SP_MEETUP_WINDOW_DAYS . ' days with these settings. Please check the open days.');
    }

    // Pinapalitan ang mga dating inalok (kung nagpadala ulit ang staff)
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM pet_meetup_options WHERE application_id = ?")->execute([$application_id]);
    $ins = $pdo->prepare("INSERT INTO pet_meetup_options (application_id, meet_date, meet_time, meet_end_time, created_by) VALUES (?, ?, ?, ?, ?)");
    foreach ($options as [$d, $open, $close]) {
        $ins->execute([$application_id, $d, $open, $close, $staff_id]);
    }
    $pdo->prepare(
        "UPDATE adoption_applications
         SET meetup_sent_at = NOW(), meetup_sent_by = ?, meetup_note = ?, meetup_option_id = NULL, meetup_chosen_at = NULL,
             application_status = IF(application_status = 'Pending', 'Under Review', application_status)
         WHERE application_id = ?"
    )->execute([$staff_id, $note !== '' ? $note : null, $application_id]);
    $pdo->commit();

    // Hal. "Oct 1 to Oct 30 (Mon, Tue, Wed, Thu, Fri), 8:00 AM - 5:00 PM"
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    sort($days);
    $firstDay = $options[0][0];
    $lastDay = $options[count($options) - 1][0];
    $list = date('M j', strtotime($firstDay))
        . ($lastDay !== $firstDay ? ' to ' . date('M j', strtotime($lastDay)) : '')
        . ' (' . implode(', ', array_map(fn ($n) => $dayNames[$n] ?? '', $days)) . '), '
        . date('g:i A', strtotime($openTime)) . ' - ' . date('g:i A', strtotime($closeTime));
    // Walang abiso (notification) dito: ang tanging abiso ng adopter ay ang
    // kumpirmasyon kapag nakapili na siya ng araw (process_choose_meetup.php).
    // Makikita niya ang mga araw sa Schedule at My Applications, at sa mensahe/email.
    sp_send_message(
        $pdo,
        (int) $staff_id,
        (int) $application['adopter_id'],
        "Hi {$application['full_name']}, thank you for attending the interview! You can now visit us at Caloocan City Hall to meet $petName in person. "
        . "City Hall is open for visits on $list. Tap the button below, choose the day you will come, and visit anytime while City Hall is open."
        . ($note !== '' ? "\n\nNote from the staff: $note" : '')
        . "\n" . sp_message_button('Choose Your Meet-up Day', 'schedule.php?meetup=' . $application_id)
    );

    require_once __DIR__ . '/../connection/mailer.php';
    $paragraphs = [
        'Hi ' . htmlspecialchars($application['full_name'], ENT_QUOTES, 'UTF-8') . ',',
        'Thank you for attending your interview. You can now visit us at <strong>Caloocan City Hall</strong> to meet <strong>'
            . htmlspecialchars($petName, ENT_QUOTES, 'UTF-8') . '</strong> in person.',
        'The shelter is open for visits on <strong>' . htmlspecialchars($list, ENT_QUOTES, 'UTF-8') . '</strong>. Please choose the day you will come using the button below. You can visit anytime within those hours.',
    ];
    if ($note !== '') {
        $paragraphs[] = 'Note from the staff: ' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
    }
    $emailed = sp_send_email(
        sp_application_email($pdo, $application),
        $application['full_name'],
        "Meet $petName at the shelter - choose a schedule",
        'Meet your pet at the shelter',
        $paragraphs,
        'Choose Your Meet-up Day',
        sp_app_url('Adopter_File/schedule.php') . '?meetup=' . $application_id
    );

    $_SESSION['success_message'] = 'Pet meet-up schedule sent to the adopter'
        . ($emailed ? ' by message and email.' : ' by message (the email could not be sent).');
    log_activity($pdo, "Sent pet meet-up schedule for $petName", "Applicant: {$application['full_name']}, Options: $list");
    header('Location: ' . $reviewUrl);
    exit();
}

if ($action === 'approve') {
    // Hindi ma-a-approve hangga't hindi tapos ang lahat ng hakbang (connection/application_flow.php):
    // interview + meeting link -> dumalo -> meet-up -> bayad -> certificate released
    require_once __DIR__ . '/../connection/application_flow.php';
    $steps = sp_app_flow_steps($pdo, $application);
    if (!sp_app_can_approve($steps)) {
        $next = sp_app_next_step($steps);
        $_SESSION['error_message'] = 'This application cannot be approved yet. Next step: ' . ($next['title'] ?? '') . ' - ' . ($next['detail'] ?? '');
        $_SESSION['approve_blocked'] = true; // ipapakita ang gabay (pop-up) sa review page
        header('Location: ' . $reviewUrl);
        exit();
    }

    $application['pet_name'] = $petName;
    sp_app_approve($pdo, $application, (int) $staff_id);
    $_SESSION['success_message'] = 'Application approved. The pet has been marked as Adopted.';

    // Basahin na ang Valid ID para handa na ang pangalan at address sa certificates.
    // Hindi nito pinipigilan ang approval kapag pumalya - puwede itong ulitin sa
    // certificate page ("Read from Valid ID").
    require_once __DIR__ . '/../connection/id_reader.php';
    try {
        $idRead = sp_read_valid_id($pdo, $application_id, (int) $staff_id);
        $_SESSION['success_message'] .= ' ' . $idRead['message'];
    } catch (Throwable $e) {
        $_SESSION['success_message'] .= ' The Valid ID could not be read automatically.';
    }
} elseif ($action === 'reject') {
    $reason = trim($_POST['rejection_reason'] ?? '');
    if ($reason === '') {
        $_SESSION['error_message'] = 'Rejection reason is required.';
        header('Location: ' . $reviewUrl);
        exit();
    }
    $pdo->prepare("UPDATE adoption_applications SET application_status = 'Rejected', reviewed_by_staff_id = ?, rejection_reason = ? WHERE application_id = ?")
        ->execute([$staff_id, $reason, $application_id]);
    $_SESSION['success_message'] = 'Application rejected.';
    log_activity($pdo, "Rejected adoption application for $petName", "Applicant: {$application['full_name']}, Reason: $reason");
    sp_notify(
        $pdo,
        (int) $application['adopter_id'],
        'application',
        'Application not approved',
        "Your adoption application for $petName was not approved. Reason: $reason",
        'my_applications.php',
        'app_rejected_' . $application_id
    );
}

header('Location: ' . $backUrl);
exit();
