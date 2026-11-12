<?php
/**
 * Rebooking popup ng adopter (hindi nakadalo sa interview at nagpadala na ang
 * staff ng rebooking link). Isinasama ng schedule.php; bumubukas kapag may
 * ?rebook=<application_id> - galing sa View ng abiso o sa button sa Schedule.
 * Kailangan: $pdo at $user_id.
 */
// Lumalabas lang kapag pinindot ang View sa abiso ng rebooking (?rebook=<application_id>).
// Hindi nakadalo sa interview at nagpadala na ang staff ng rebooking link.
$rebookOpen = isset($_GET['rebook']);
$rebookApp = null;
$rebookSlots = [];
// Error galing sa process_reschedule_interview.php (nabasa na ng schedule.php bilang $error_message)
$rebookError = $_SESSION['error_message'] ?? ($error_message ?? '');
unset($_SESSION['error_message']);
if ($rebookOpen) {
    $rbStmt = $pdo->prepare(
        "SELECT aa.application_id, aa.preferred_interview_date, aa.preferred_interview_time, p.name AS pet_name
         FROM adoption_applications aa
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE aa.application_id = ? AND aa.adopter_id = ?
           AND aa.rebook_sent_at IS NOT NULL
           AND (aa.interview_missed_at IS NOT NULL OR aa.schedule_status = 'Declined')
           AND aa.application_status IN ('Pending', 'Under Review')"
    );
    $rbStmt->execute([(int) $_GET['rebook'], $user_id]);
    $rebookApp = $rbStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($rebookApp) {
        try {
            $slotRows = $pdo->query(
                "SELECT slot_id, slot_date, start_time, end_time FROM appointment_slots
                 WHERE status = 'Open' AND TIMESTAMP(slot_date, start_time) > NOW()
                 ORDER BY slot_date ASC, start_time ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($slotRows as $row) {
                $rebookSlots[$row['slot_date']][] = $row;
            }
        } catch (Exception $e) {
            $rebookSlots = [];
        }
    }
}

?>
    <?php if ($rebookOpen): ?>
    <!-- ===== Rebooking popup (bumubukas mula sa View ng abiso) ===== -->
    <div class="rb-backdrop" id="rbBackdrop" role="dialog" aria-modal="true" aria-labelledby="rbTitle">
        <div class="rb-box">
            <button type="button" class="rb-close" id="rbClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            <div class="rb-icon"><i class="fa-solid fa-calendar-days"></i></div>

            <?php if ($rebookApp): ?>
                <h3 id="rbTitle">Rebook your interview</h3>
                <p class="rb-sub">
                    Choose a new interview schedule for <strong><?php echo htmlspecialchars($rebookApp['pet_name']); ?></strong>.
                </p>
                <?php if ($rebookError !== ''): ?>
                    <div class="rb-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($rebookError); ?></div>
                <?php endif; ?>
                <?php
                // Parehong calendar ng pag-apply: araw na bukas ang City Hall at sariling oras
                require_once __DIR__ . '/../connection/interview_schedule.php';
                $pickerConfig = sp_interview_picker_config($pdo, (int) $rebookApp['application_id']);
                $pickerExclude = (int) $rebookApp['application_id'];
                $pickerDate = '';
                $pickerTime = '';
                ?>
                <form method="POST" action="process_reschedule_interview.php" id="rbForm" class="request-form" style="text-align:left;">
                    <input type="hidden" name="application_id" value="<?php echo (int) $rebookApp['application_id']; ?>">
                    <input type="hidden" name="return" value="schedule">
                    <?php include __DIR__ . '/_interview_picker.php'; ?>
                    <div class="rb-actions">
                        <button type="button" class="rb-cancel" data-rb-close>Later</button>
                        <button type="submit" class="rb-submit" id="rbSubmit"><i class="fa-solid fa-calendar-check"></i> Send New Schedule</button>
                    </div>
                </form>
            <?php else: ?>
                <h3 id="rbTitle">Rebooking not available</h3>
                <p class="rb-sub">This rebooking link was already used or is no longer valid. Your current interview is shown on this page.</p>
                <div class="rb-actions">
                    <button type="button" class="rb-submit" data-rb-close>OK</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .rb-backdrop {
            position: fixed; inset: 0; z-index: 2500;
            display: flex; align-items: center; justify-content: center; padding: 20px;
            background: rgba(12, 30, 18, 0.55); backdrop-filter: blur(3px);
            animation: rbFade .25s ease;
        }
        .rb-backdrop.closing { animation: rbFade .2s ease reverse forwards; }
        .rb-box {
            position: relative; width: 100%; max-width: 520px; max-height: 88vh; overflow-y: auto;
            background: #fff; border-radius: 20px; padding: 26px 24px 22px; text-align: center;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
            animation: rbPop .4s cubic-bezier(.34, 1.56, .64, 1);
        }
        .rb-close {
            position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border: none; border-radius: 50%;
            background: #f3f4f6; color: #555; cursor: pointer; transition: background .2s ease;
        }
        .rb-close:hover { background: #e5e7eb; }
        .rb-icon {
            width: 58px; height: 58px; margin: 0 auto 12px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff;
            background: linear-gradient(135deg, #ffad4a, #f07a12); box-shadow: 0 8px 18px rgba(240, 122, 18, 0.35);
        }
        .rb-box h3 { margin: 0 0 6px; color: #1b4d2e; font-size: 20px; }
        .rb-sub { margin: 0 0 16px; color: #555; font-size: 14px; }
        .rb-error { background: #ffebee; color: #c62828; border-radius: 10px; padding: 10px 12px; font-size: 13px; margin-bottom: 14px; text-align: left; }
        .rb-list { display: flex; flex-direction: column; gap: 10px; max-height: 42vh; overflow-y: auto; padding: 2px; text-align: left; }
        .rb-day { display: flex; gap: 12px; align-items: center; background: #f8faf8; border: 1px solid #e5ece7; border-radius: 12px; padding: 10px 12px; }
        .rb-date { min-width: 48px; text-align: center; border-radius: 10px; padding: 6px 4px; color: #fff; background: linear-gradient(160deg, #2e5b32, #1b4d2e); }
        .rb-date .d { display: block; font-size: 18px; font-weight: 800; line-height: 1; }
        .rb-date .m { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
        .rb-times { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .rb-dow { width: 100%; font-size: 12.5px; font-weight: 700; color: #1b4d2e; }
        .rb-chip { position: relative; cursor: pointer; }
        .rb-chip input { position: absolute; opacity: 0; pointer-events: none; }
        .rb-chip span {
            display: inline-block; font-size: 12.5px; font-weight: 700; color: #1b4d2e; background: #fff;
            border: 1.5px solid #cfe3d6; border-radius: 16px; padding: 6px 12px; transition: all .15s ease;
        }
        .rb-chip:hover span { border-color: #2e5b32; }
        .rb-chip input:checked + span { background: #2e5b32; border-color: #2e5b32; color: #fff; }
        .rb-chip input:focus-visible + span { box-shadow: 0 0 0 3px rgba(46, 91, 50, 0.25); }
        .rb-empty { color: #888; border: 2px dashed #e0e0e0; border-radius: 12px; padding: 22px 16px; }
        .rb-empty i { font-size: 26px; color: #cbd5cf; margin-bottom: 8px; display: block; }
        .rb-empty p { margin: 0; font-size: 13px; }
        .rb-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
        .rb-cancel {
            border: 1.5px solid #d1d5db; background: #fff; color: #444; border-radius: 10px;
            padding: 10px 18px; font-weight: 600; cursor: pointer; font-family: inherit;
        }
        .rb-submit {
            display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 10px;
            padding: 10px 18px; font-weight: 700; color: #fff; cursor: pointer; font-family: inherit;
            background: linear-gradient(135deg, #2e7d32, #1b4d2e); box-shadow: 0 6px 14px rgba(27, 77, 46, 0.25);
            transition: opacity .2s ease, transform .15s ease;
        }
        .rb-submit:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
        .rb-submit:not(:disabled):hover { transform: translateY(-1px); }
        @keyframes rbFade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes rbPop { from { opacity: 0; transform: translateY(16px) scale(.94); } to { opacity: 1; transform: none; } }
    </style>
    <script>
        (function () {
            const bd = document.getElementById('rbBackdrop');
            if (!bd) return;
            document.body.style.overflow = 'hidden';
            function close() {
                bd.classList.add('closing');
                setTimeout(function () {
                    bd.remove();
                    document.body.style.overflow = '';
                }, 200);
                // Tanggalin ang ?rebook sa address bar
                history.replaceState(null, '', 'schedule.php');
            }
            document.getElementById('rbClose').addEventListener('click', close);
            bd.querySelectorAll('[data-rb-close]').forEach(function (b) { b.addEventListener('click', close); });
            bd.addEventListener('click', function (e) { if (e.target === bd) close(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.body.contains(bd)) close(); });

        })();
    </script>
    <?php endif; ?>
