<?php
// Tagabuo ng mga paalala para sa staff. Isang lugar lang kinukuha ang lahat ng
// reminder para pare-pareho ang lumalabas sa bell widget at sa JSON endpoint.
//
// Bawat reminder ay may ganitong hugis:
//   [
//     'key'      => natatanging id (ginagamit sa pag-dismiss sa browser),
//     'level'    => 'urgent' | 'warning' | 'info',
//     'icon'     => emoji,
//     'title'    => maikling pamagat,
//     'detail'   => karagdagang paliwanag,
//     'meta'     => oras o petsa,
//     'link'     => saan dadalhin kapag pinindot,
//   ]

if (!function_exists('staff_reminders_safe_all')) {
    /**
     * Ligtas na query - kapag wala pa ang table o column, walang error na lalabas
     * sa page, walang reminder lang mula sa pinagkunang iyon.
     */
    function staff_reminders_safe_all(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('staff_get_reminders')) {
    /**
     * Lahat ng dapat malaman ng staff ngayon, pinagsunod-sunod ayon sa pagkaurgent.
     */
    function staff_get_reminders(PDO $pdo, int $staffId): array
    {
        $reminders = [];
        $now = new DateTimeImmutable('now');

        // --- 1. Mga interview ngayong araw ---
        $todayInterviews = staff_reminders_safe_all(
            $pdo,
            "SELECT aa.application_id, aa.full_name, aa.preferred_interview_time,
                    aa.meeting_link, aa.meeting_link_set_by, p.name AS pet_name
             FROM adoption_applications aa
             JOIN pet_inventory p ON p.pet_id = aa.pet_id
             WHERE aa.preferred_interview_date = CURDATE()
               AND aa.application_status IN ('Pending', 'Under Review')
               AND (aa.schedule_status IS NULL OR aa.schedule_status = 'Accepted')
             ORDER BY aa.preferred_interview_time ASC"
        );

        // --- 0. Mga araw na pinili ng adopter na hinihintay ang Accept o Decline ---
        // Isang abiso bawat adopter na nag-schedule, para makita agad kung sino at kailan
        $pendingRequests = staff_reminders_safe_all(
            $pdo,
            "SELECT aa.application_id, aa.full_name, aa.preferred_interview_date, aa.preferred_interview_time, p.name AS pet_name
             FROM adoption_applications aa
             JOIN pet_inventory p ON p.pet_id = aa.pet_id
             WHERE aa.schedule_status = 'Pending' AND aa.application_status IN ('Pending', 'Under Review')
             ORDER BY aa.preferred_interview_date ASC, aa.preferred_interview_time ASC"
        );
        foreach ($pendingRequests as $req) {
            $reqStart = strtotime($req['preferred_interview_date'] . ' ' . $req['preferred_interview_time']);
            $reminders[] = [
                'key' => 'interview_request_' . (int) $req['application_id'] . '_' . $reqStart,
                'level' => 'warning',
                'icon' => '📨',
                'title' => 'New interview schedule from ' . $req['full_name'],
                'detail' => 'For ' . $req['pet_name'] . '. Approve or reject this schedule.',
                'meta' => date('l, F j, Y', $reqStart) . ' · ' . date('g:i A', $reqStart)
                    . ' - ' . date('g:i A', $reqStart + 90 * 60),
                'link' => 'staff_availability.php?date=' . $req['preferred_interview_date'] . '#requests',
            ];
        }

        foreach ($todayInterviews as $row) {
            $id = (int) $row['application_id'];
            $link = 'review_application.php?application_id=' . $id;
            $timeLabel = date('g:i A', strtotime($row['preferred_interview_time']));
            $start = new DateTimeImmutable(date('Y-m-d') . ' ' . $row['preferred_interview_time']);
            $minutesAway = (int) round(($start->getTimestamp() - $now->getTimestamp()) / 60);
            $who = $row['full_name'] . ' - ' . $row['pet_name'];

            if ($minutesAway < -90) {
                // Matagal nang lampas, ibig sabihin hindi pa naisasara ang application
                $reminders[] = [
                    'key' => 'interview_overdue_' . $id,
                    'level' => 'warning',
                    'icon' => '⏰',
                    'title' => 'Interview already passed',
                    'detail' => $who . ' - record the result and update the application.',
                    'meta' => 'Today, ' . $timeLabel,
                    'link' => $link,
                ];
            } elseif ($minutesAway <= 60) {
                $when = $minutesAway <= 0
                    ? 'Starting now'
                    : 'Starts in ' . $minutesAway . ' minute' . ($minutesAway === 1 ? '' : 's');
                $reminders[] = [
                    'key' => 'interview_soon_' . $id,
                    'level' => 'urgent',
                    'icon' => '🎥',
                    'title' => 'Interview ' . strtolower($when),
                    'detail' => $who,
                    'meta' => 'Today, ' . $timeLabel,
                    'link' => $link,
                ];
            } else {
                $reminders[] = [
                    'key' => 'interview_today_' . $id,
                    'level' => 'info',
                    'icon' => '📅',
                    'title' => 'Interview scheduled today',
                    'detail' => $who,
                    'meta' => 'Today, ' . $timeLabel,
                    'link' => $link,
                ];
            }

            // May meeting link na: ang staff na naglagay nito ay dadalhin ng widget sa
            // meeting pagdating ng oras. Ilang segundo pa bago magsimula ang ipinapasa
            // (hindi ang mismong oras), para hindi magkamali kahit iba ang orasan ng PC.
            $meetUrl = trim((string) $row['meeting_link']);
            if ($meetUrl !== '' && $minutesAway > -90) {
                $setBy = $row['meeting_link_set_by'];
                $reminders[count($reminders) - 1]['meet'] = [
                    'id' => $id,
                    'url' => $meetUrl,
                    'starts_in' => $start->getTimestamp() - $now->getTimestamp(),
                    'mine' => $setBy === null || (int) $setBy === $staffId,
                    'who' => $who,
                    'time' => $timeLabel,
                ];
            }

            // Walang meeting link - hindi makakapasok ang adopter kahit dumating ang oras
            if (trim((string) $row['meeting_link']) === '' && $minutesAway > -90) {
                $reminders[] = [
                    'key' => 'interview_nolink_' . $id,
                    'level' => 'urgent',
                    'icon' => '🔗',
                    'title' => 'Meeting link is missing',
                    'detail' => 'Add the online meeting link for ' . $who . ' so it can be emailed before the interview.',
                    'meta' => 'Today, ' . $timeLabel,
                    'link' => $link,
                ];
            }
        }

        // --- 2. Mga interview bukas, para may oras pang maghanda ---
        $tomorrowCount = staff_reminders_safe_all(
            $pdo,
            "SELECT COUNT(*) AS c FROM adoption_applications
             WHERE preferred_interview_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
               AND application_status IN ('Pending', 'Under Review')
               AND (schedule_status IS NULL OR schedule_status = 'Accepted')"
        );
        $tomorrow = (int) ($tomorrowCount[0]['c'] ?? 0);
        if ($tomorrow > 0) {
            $reminders[] = [
                'key' => 'interviews_tomorrow_' . date('Y-m-d'),
                'level' => 'info',
                'icon' => '🗓️',
                'title' => $tomorrow . ' interview' . ($tomorrow === 1 ? '' : 's') . ' scheduled tomorrow',
                'detail' => 'Prepare the meeting links and review the applications ahead of time.',
                'meta' => date('M j, Y', strtotime('+1 day')),
                'link' => 'daily_schedule.php?date=' . date('Y-m-d', strtotime('+1 day')),
            ];
        }

        // --- 3. Mga application na matagal nang naghihintay ng review ---
        $waiting = staff_reminders_safe_all(
            $pdo,
            "SELECT COUNT(*) AS c FROM adoption_applications
             WHERE application_status = 'Pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
        );
        $waitingCount = (int) ($waiting[0]['c'] ?? 0);
        if ($waitingCount > 0) {
            $reminders[] = [
                'key' => 'apps_waiting_' . date('Y-m-d'),
                'level' => 'warning',
                'icon' => '📄',
                'title' => $waitingCount . ' application' . ($waitingCount === 1 ? '' : 's') . ' waiting for review',
                'detail' => 'These have been pending for more than 2 days.',
                'meta' => 'Needs action',
                'link' => 'applications.php?status=Pending',
            ];
        }

        // --- 4. Approved na pero walang naitalang bayad ---
        $unpaid = staff_reminders_safe_all(
            $pdo,
            "SELECT COUNT(*) AS c
             FROM adoption_applications aa
             LEFT JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
             WHERE (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL)) AND pay.payment_id IS NULL"
        );
        $unpaidCount = (int) ($unpaid[0]['c'] ?? 0);
        if ($unpaidCount > 0) {
            $reminders[] = [
                'key' => 'unpaid_' . date('Y-m-d'),
                'level' => 'warning',
                'icon' => '💰',
                'title' => $unpaidCount . ' approved adoption' . ($unpaidCount === 1 ? '' : 's') . ' without payment',
                'detail' => 'Record the adoption fee once the adopter has paid.',
                'meta' => 'Needs action',
                'link' => 'payments.php',
            ];
        }

        // --- 5. Sariling checklist ng staff na ito ---
        // Kasama ang mga lumipas nang araw: ang hindi natapos kahapon ay mas
        // kailangang ipaalala kaysa sa nakatakda pa lang ngayon.
        $tasks = staff_reminders_safe_all(
            $pdo,
            "SELECT task_id, task_name, task_date, task_time
             FROM daily_tasks
             WHERE staff_id = ? AND task_date <= CURDATE() AND is_done = 0
             ORDER BY task_date ASC, task_time IS NULL, task_time ASC",
            [$staffId]
        );

        $today = date('Y-m-d');
        foreach ($tasks as $task) {
            $taskId = (int) $task['task_id'];
            $hasTime = !empty($task['task_time']);
            $timeLabel = $hasTime ? date('g:i A', strtotime($task['task_time'])) : 'No set time';
            $isPastDay = $task['task_date'] < $today;
            $isLateToday = !$isPastDay && $hasTime
                && strtotime($today . ' ' . $task['task_time']) < $now->getTimestamp();
            $isOverdue = $isPastDay || $isLateToday;

            $dayLabel = $isPastDay
                ? date('M j, Y', strtotime($task['task_date']))
                : 'Today';

            $reminders[] = [
                'key' => 'task_' . $taskId,
                'level' => $isOverdue ? 'warning' : 'info',
                'icon' => $isOverdue ? '⚠️' : '✅',
                'title' => $isOverdue ? 'Unfinished task' : 'Task for today',
                'detail' => $task['task_name'],
                'meta' => $dayLabel . ', ' . $timeLabel,
                'link' => 'daily_schedule.php',
            ];
        }

        // --- 6. Medical & Intake ---
        // Ang batayan ay ang PINAKAHULING medical record ng bawat pet, kapareho ng
        // ginagamit ng Medical & Intakes page, para pareho ang kanilang sinasabi.
        $latestMedical = staff_reminders_safe_all(
            $pdo,
            "SELECT mr.pet_id, mr.status, mr.checkup_date, pi.name AS pet_name,
                    DATEDIFF(CURDATE(), mr.checkup_date) AS days_since
             FROM medical_records mr
             INNER JOIN (SELECT pet_id, MAX(checkup_date) AS max_date FROM medical_records GROUP BY pet_id) latest
                 ON mr.pet_id = latest.pet_id AND mr.checkup_date = latest.max_date
             JOIN pet_inventory pi ON pi.pet_id = mr.pet_id
             WHERE mr.status IN ('Treatment', 'Recovery')
             ORDER BY mr.checkup_date ASC"
        );

        // 6a. Ginagamot pa - dapat may bagong update kada ilang araw
        foreach (array_slice($latestMedical, 0, 5) as $row) {
            $petId = (int) $row['pet_id'];
            $days = (int) $row['days_since'];
            $isTreatment = $row['status'] === 'Treatment';
            $isStale = $days >= ($isTreatment ? 3 : 14);

            if (!$isTreatment && !$isStale) {
                // Kakarecover lang, wala pang dapat gawin
                continue;
            }

            $sinceLabel = $days === 0
                ? 'Updated today'
                : 'Last update ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago';

            $reminders[] = [
                'key' => 'medical_' . $petId . '_' . $row['checkup_date'],
                'level' => $isStale ? 'warning' : 'info',
                'icon' => '🩺',
                'title' => $isTreatment
                    ? ($isStale ? 'Treatment needs an update' : 'Pet under treatment')
                    : 'Recovery check is due',
                'detail' => $row['pet_name'] . ' - '
                    . ($isTreatment
                        ? 'record the progress of the treatment.'
                        : 'confirm if the pet has fully recovered.'),
                'meta' => $sinceLabel,
                'link' => 'medical_intake.php',
            ];
        }

        if (count($latestMedical) > 5) {
            $extra = count($latestMedical) - 5;
            $reminders[] = [
                'key' => 'medical_more_' . date('Y-m-d'),
                'level' => 'info',
                'icon' => '🩺',
                'title' => $extra . ' more pet' . ($extra === 1 ? '' : 's') . ' with open medical records',
                'detail' => 'Open Medical & Intakes to see the full list.',
                'meta' => 'Ongoing',
                'link' => 'medical_intake.php',
            ];
        }

        // 6b. Mga pet sa shelter na wala pang kahit isang medical record
        $noRecord = staff_reminders_safe_all(
            $pdo,
            "SELECT pi.pet_id, pi.name
             FROM pet_inventory pi
             LEFT JOIN medical_records mr ON mr.pet_id = pi.pet_id
             WHERE pi.status <> 'Adopted' AND mr.record_id IS NULL
             ORDER BY pi.created_at ASC"
        );

        if ($noRecord) {
            $names = array_column(array_slice($noRecord, 0, 4), 'name');
            $label = implode(', ', $names);
            if (count($noRecord) > 4) {
                $label .= ' and ' . (count($noRecord) - 4) . ' more';
            }

            $reminders[] = [
                'key' => 'medical_missing_' . date('Y-m-d'),
                'level' => 'warning',
                'icon' => '📋',
                'title' => count($noRecord) . ' pet' . (count($noRecord) === 1 ? ' has' : 's have') . ' no medical record yet',
                'detail' => $label . ' - add the initial checkup so their health history is complete.',
                'meta' => 'Needs action',
                'link' => 'medical_intake.php',
            ];
        }

        // 6c. Hindi pa kumpleto ang bakuna ng mga pet na nasa shelter pa
        $needVaccine = staff_reminders_safe_all(
            $pdo,
            "SELECT pet_id, name, vaccination_status
             FROM pet_inventory
             WHERE status <> 'Adopted'
               AND vaccination_status IN ('Not Vaccinated', 'Partially Vaccinated')
             ORDER BY vaccination_status ASC, name ASC"
        );

        if ($needVaccine) {
            $names = array_column(array_slice($needVaccine, 0, 4), 'name');
            $label = implode(', ', $names);
            if (count($needVaccine) > 4) {
                $label .= ' and ' . (count($needVaccine) - 4) . ' more';
            }

            $reminders[] = [
                'key' => 'vaccine_' . date('Y-m-d'),
                'level' => 'warning',
                'icon' => '💉',
                'title' => count($needVaccine) . (count($needVaccine) === 1 ? ' pet needs' : ' pets need') . ' vaccination',
                'detail' => $label . ' - schedule the shots and record them under Vaccines.',
                'meta' => 'Needs action',
                'link' => 'medical_intake.php',
            ];
        }

        // 6d. Mga intake na hindi pa Cleared
        $openIntakes = staff_reminders_safe_all(
            $pdo,
            "SELECT intake_id, pet_name, species, status, intake_date,
                    DATEDIFF(CURDATE(), intake_date) AS days_since
             FROM pet_intakes
             WHERE status <> 'Cleared'
             ORDER BY intake_date ASC"
        );

        foreach (array_slice($openIntakes, 0, 5) as $intake) {
            $days = (int) $intake['days_since'];
            // Ang bagong dating ay paalala lang; ang matagal nang naiipit ay kailangan nang asikasuhin
            $isStuck = $days >= 3;

            $sinceLabel = $days <= 0
                ? 'Arrived today'
                : 'Day ' . ($days + 1) . ' in the shelter';

            $reminders[] = [
                'key' => 'intake_' . (int) $intake['intake_id'] . '_' . $intake['status'],
                'level' => $isStuck ? 'warning' : 'info',
                'icon' => '🏠',
                'title' => $isStuck
                    ? 'Intake stuck in ' . $intake['status']
                    : 'New intake needs ' . strtolower($intake['status']),
                'detail' => $intake['pet_name'] . ' (' . $intake['species'] . ') - move it forward once '
                    . strtolower($intake['status']) . ' is done.',
                'meta' => $sinceLabel,
                'link' => 'medical_intake.php',
            ];
        }

        if (count($openIntakes) > 5) {
            $extra = count($openIntakes) - 5;
            $reminders[] = [
                'key' => 'intake_more_' . date('Y-m-d'),
                'level' => 'info',
                'icon' => '🏠',
                'title' => $extra . ' more intake' . ($extra === 1 ? '' : 's') . ' not yet cleared',
                'detail' => 'Open Medical & Intakes to see the full list.',
                'meta' => 'Ongoing',
                'link' => 'medical_intake.php',
            ];
        }

        // --- 7. Pet Monitoring: surprise visit kada buwan ---
        require_once __DIR__ . '/_monitoring.php';
        $dueVisits = array_values(array_filter(sp_monitoring_list($pdo), function ($m) {
            return $m['state'] === 'due';
        }));

        if ($dueVisits) {
            $daysLeft = (int) date('t') - (int) date('j');
            $missed = count(array_filter($dueVisits, function ($m) { return $m['missed_last_month']; }));
            $names = array_map(function ($m) {
                return $m['pet_name'] . ' (' . $m['full_name'] . ')';
            }, array_slice($dueVisits, 0, 4));
            $label = implode(', ', $names);
            if (count($dueVisits) > 4) {
                $label .= ' and ' . (count($dueVisits) - 4) . ' more';
            }

            $reminders[] = [
                // Kasama ang buwan sa key para lumabas ulit ang pop-up tuwing bagong buwan
                'key' => 'surprise_visits_' . date('Y-m') . '_' . count($dueVisits),
                'level' => ($daysLeft <= 7 || $missed > 0) ? 'warning' : 'info',
                'icon' => '🏡',
                'title' => count($dueVisits) . ' surprise visit' . (count($dueVisits) === 1 ? '' : 's') . ' due this month',
                'detail' => $label . ' - visit unannounced and record the result.'
                    . ($missed > 0 ? ' ' . $missed . ' of them had no visit last month.' : ''),
                'meta' => $daysLeft === 0 ? 'Last day of the month' : $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left this month',
                'link' => 'pet_monitoring.php',
            ];
        }

        // --- 7b. Hindi nakadalo sa interview, wala pang rebooking link ---
        $noShows = staff_reminders_safe_all(
            $pdo,
            "SELECT aa.application_id, aa.full_name, aa.interview_missed_at, p.name AS pet_name
             FROM adoption_applications aa
             JOIN pet_inventory p ON p.pet_id = aa.pet_id
             WHERE aa.interview_missed_at IS NOT NULL AND aa.rebook_sent_at IS NULL
               AND aa.application_status IN ('Pending', 'Under Review')
             ORDER BY aa.interview_missed_at ASC"
        );

        foreach ($noShows as $ns) {
            $reminders[] = [
                'key' => 'rebook_' . (int) $ns['application_id'],
                'level' => 'warning',
                'icon' => '🔁',
                'title' => 'Missed interview - send a rebooking link',
                'detail' => $ns['full_name'] . ' - ' . $ns['pet_name'] . '. Once they contact you, send the rebooking link from the application.',
                'meta' => 'Missed ' . date('M j, Y', strtotime($ns['interview_missed_at'])),
                'link' => 'review_application.php?application_id=' . (int) $ns['application_id'],
            ];
        }

        // --- 8. Pet Concern Reports mula sa adopter (Report a Concern sa My Pets) ---
        // Para sa staff na tumanggap ng report: hindi pa nababasa = urgent;
        // nabasa na pero hindi pa sinasagot = paalala na sagutin.
        $concerns = staff_reminders_safe_all(
            $pdo,
            "SELECT c.concern_id, c.concern_type, c.created_at, p.name AS pet_name,
                    u.username AS adopter_name, COALESCE(m.is_read, 0) AS seen
             FROM pet_concerns c
             JOIN pet_inventory p ON p.pet_id = c.pet_id
             JOIN users u ON u.user_id = c.adopter_id
             LEFT JOIN messages m ON m.message_id = c.message_id
             WHERE c.staff_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM messages r
                   WHERE r.conversation_id = m.conversation_id
                     AND r.sender_id = c.staff_id
                     AND r.message_id > c.message_id
               )
             ORDER BY c.created_at ASC",
            [$staffId]
        );

        foreach ($concerns as $c) {
            $reminders[] = [
                'key' => 'concern_' . (int) $c['concern_id'] . ($c['seen'] ? '_seen' : '_new'),
                'level' => $c['seen'] ? 'warning' : 'urgent',
                'icon' => '📣',
                'title' => $c['seen'] ? 'Pet concern waiting for your reply' : 'New pet concern report',
                'detail' => $c['adopter_name'] . ' - ' . $c['pet_name'] . ': ' . $c['concern_type'] . '. Reply in Messages.',
                'meta' => date('M j, Y g:i A', strtotime($c['created_at'])),
                'link' => 'messages.php',
            ];
        }

        // Urgent muna, tapos warning, tapos info
        $rank = ['urgent' => 0, 'warning' => 1, 'info' => 2];
        usort($reminders, function ($a, $b) use ($rank) {
            return ($rank[$a['level']] ?? 3) <=> ($rank[$b['level']] ?? 3);
        });

        return $reminders;
    }
}

if (!function_exists('staff_reminder_counts')) {
    /**
     * Bilang kada level, para sa badge ng bell.
     */
    function staff_reminder_counts(array $reminders): array
    {
        $counts = ['total' => count($reminders), 'urgent' => 0, 'warning' => 0, 'info' => 0];
        foreach ($reminders as $r) {
            $level = $r['level'] ?? 'info';
            if (isset($counts[$level])) {
                $counts[$level]++;
            }
        }
        return $counts;
    }
}
