<?php
/**
 * Pop-up ng Pet Meet-up: pipili ang adopter ng ARAW (hindi oras) para pumunta sa
 * Caloocan City Hall at makita ang pet. Parehong itsura ng calendar sa pag-apply
 * (Google Calendar style), kasama ang special days. Ipinapakita lang ang oras ng
 * bukas at sara ng City Hall sa napiling araw - puwede siyang pumunta anumang oras doon.
 *
 * Bumubukas kapag may ?meetup=<application_id> (button sa message/email ng staff)
 * o kapag pinindot ang [data-meetup-open] sa Schedule page.
 * Kailangan: $pdo, $meetups, $meetupOptions (galing sa schedule.php).
 */
require_once __DIR__ . '/../connection/interview_schedule.php';

$muData = [];
foreach ($meetups as $mu) {
    $id = (int) $mu['application_id'];
    $opts = [];
    $chosenDate = null;
    foreach ($meetupOptions[$id] ?? [] as $o) {
        if ((int) $o['option_id'] === (int) $mu['meetup_option_id']) {
            $chosenDate = $o['meet_date'];
        }
        if ((int) $o['is_future'] !== 1) {
            continue;
        }
        $opts[$o['meet_date']] = [
            'id' => (int) $o['option_id'],
            'open' => date('g:i A', strtotime($o['meet_time'])),
            'close' => !empty($o['meet_end_time']) ? date('g:i A', strtotime($o['meet_end_time'])) : '',
        ];
    }
    $muData[$id] = ['pet' => $mu['pet_name'], 'note' => (string) ($mu['meetup_note'] ?? ''), 'options' => (object) $opts, 'chosen' => $chosenDate];
}
if (!$muData) {
    return;
}
$muSpecial = sp_interview_special_map($pdo, (int) date('Y'), (int) date('Y', strtotime('+' . SP_MEETUP_WINDOW_DAYS . ' days')));
$muOpenId = (int) ($_GET['meetup'] ?? 0);
?>
<div class="mu-backdrop" id="muBackdrop" role="dialog" aria-modal="true" aria-labelledby="muTitle" hidden>
    <div class="mu-box">
        <button type="button" class="mu-close" data-mu-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>

        <div class="mu-view" id="muPick">
            <div class="mu-icon"><i class="fa-solid fa-paw"></i></div>
            <h3 id="muTitle">Meet <span class="mu-pet"></span> at City Hall</h3>
            <p class="mu-sub">Choose the day you will visit. You can come <strong>anytime while City Hall is open</strong> on that day.</p>
            <p class="mu-note" hidden><i class="fa-solid fa-note-sticky"></i> <strong>Note from the staff:</strong> <span></span></p>

            <div class="ip-panel mu-panel">
                <div class="ip-panel-head"><span>Select a day to visit</span></div>
                <div class="ip-body">
                    <div class="ip-cal-col">
                        <div class="ip-cal" id="muCal"></div>
                        <div class="ip-legend">
                            <span><i class="ip-dot ip-dot-open"></i> Open</span>
                            <span><i class="ip-dot ip-dot-off"></i> Closed</span>
                            <span><i class="ip-dot ip-dot-special"></i> Special day</span>
                        </div>
                    </div>
                    <div class="ip-side">
                        <div class="ip-time-empty" id="muEmpty">
                            <i class="fa-regular fa-hand-pointer"></i>
                            <span>Choose a day on the calendar to see when City Hall is open.</span>
                        </div>
                        <div class="ip-special" id="muSpecial" hidden>
                            <div class="ip-special-badge"><span class="ip-special-mon"></span><span class="ip-special-day"></span></div>
                            <span class="ip-special-tag"><i class="fa-solid fa-star"></i> Special day</span>
                            <strong class="ip-special-name"></strong>
                            <p class="ip-special-text"></p>
                        </div>
                        <div class="mu-day" id="muDay" hidden>
                            <div class="mu-day-date"></div>
                            <div class="mu-hours">
                                <i class="fa-regular fa-clock"></i>
                                <div><span>City Hall is open</span><strong></strong></div>
                            </div>
                            <p>Come anytime within these hours. Please bring a valid ID.</p>
                        </div>
                        <p class="ip-error" id="muError" role="alert" hidden></p>
                    </div>
                </div>
            </div>

            <div class="mu-actions">
                <button type="button" class="mu-cancel" data-mu-close>Later</button>
                <button type="button" class="mu-confirm" id="muConfirm" disabled><i class="fa-solid fa-calendar-check"></i> Confirm Meet-up Day</button>
            </div>
        </div>

        <div class="mu-view" id="muDone" hidden>
            <div class="mu-success"><i class="fa-solid fa-check"></i></div>
            <h3>Success! Your meet-up is set</h3>
            <p class="mu-sub">See you at City Hall to meet <strong class="mu-done-pet"></strong>.</p>
            <dl class="mu-summary">
                <div><dt><i class="fa-regular fa-calendar"></i> Date</dt><dd class="mu-done-date"></dd></div>
                <div><dt><i class="fa-regular fa-clock"></i> City Hall hours</dt><dd class="mu-done-time"></dd></div>
                <div><dt><i class="fa-solid fa-location-dot"></i> Place</dt><dd class="mu-done-place"></dd></div>
            </dl>
            <p class="mu-link-label">This is the link for the location of Caloocan City Hall:</p>
            <a class="mu-map" id="muMap" href="#" target="_blank" rel="noopener"><i class="fa-solid fa-map-location-dot"></i> Open Caloocan City Hall in Google Maps</a>
            <div class="mu-actions center">
                <button type="button" class="mu-confirm" id="muDoneBtn">Done</button>
            </div>
        </div>
    </div>
</div>
<style>
    .mu-backdrop {
        position: fixed; inset: 0; z-index: 10020; display: flex; align-items: center; justify-content: center; padding: 16px;
        background: rgba(12, 30, 18, 0.55); backdrop-filter: blur(3px); animation: muFade .25s ease;
    }
    .mu-backdrop[hidden] { display: none; }
    .mu-box {
        position: relative; width: 100%; max-width: 640px; max-height: 92vh; overflow-y: auto; box-sizing: border-box;
        background: #fff; border-radius: 20px; padding: 24px 22px 20px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
        animation: muPop .4s cubic-bezier(.34, 1.56, .64, 1);
    }
    .mu-close {
        position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border: none; border-radius: 50%;
        background: #f3f4f6; color: #555; cursor: pointer;
    }
    .mu-close:hover { background: #e5e7eb; }
    .mu-view { text-align: center; }
    .mu-icon, .mu-success {
        width: 56px; height: 56px; margin: 0 auto 10px; border-radius: 16px; display: flex; align-items: center; justify-content: center;
        font-size: 24px; color: #fff; background: linear-gradient(135deg, #2e7d32, #1b4d2e); box-shadow: 0 8px 18px rgba(27, 77, 46, 0.3);
    }
    .mu-success { border-radius: 50%; animation: muPop .5s cubic-bezier(.34, 1.56, .64, 1); }
    .mu-box h3 { margin: 0 0 6px; color: #1b4d2e; font-size: 20px; }
    .mu-sub { margin: 0 0 12px; color: #555; font-size: 14px; }
    .mu-note { text-align: left; font-size: 13px; color: #374151; background: #f6faf7; border-radius: 10px; padding: 10px 12px; margin: 0 0 4px; }
    .mu-panel { max-width: none; text-align: left; }
    .mu-day { padding: 14px 16px; }
    .mu-day[hidden] { display: none; }
    .mu-day-date { font-weight: 700; color: #202124; font-size: 13.5px; margin-bottom: 10px; }
    .mu-hours { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 10px; background: #e8f0fe; color: #1a56c4; }
    .mu-hours i { font-size: 18px; }
    .mu-hours span { display: block; font-size: 11.5px; color: #3c5a99; }
    .mu-hours strong { display: block; font-size: 14px; }
    .mu-day p { margin: 10px 0 0; font-size: 12.5px; color: #5f6368; line-height: 1.45; }
    .mu-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
    .mu-actions.center { justify-content: center; }
    .mu-cancel { border: 1.5px solid #d1d5db; background: #fff; color: #444; border-radius: 10px; padding: 10px 18px; font-weight: 600; cursor: pointer; font-family: inherit; }
    .mu-confirm {
        display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 10px; padding: 10px 18px; font-weight: 700;
        color: #fff; cursor: pointer; font-family: inherit; background: linear-gradient(135deg, #2e7d32, #1b4d2e); box-shadow: 0 6px 14px rgba(27, 77, 46, 0.25);
    }
    .mu-confirm:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
    .mu-summary { margin: 4px 0 14px; padding: 12px 14px; border-radius: 12px; background: #f6faf7; border: 1px solid #dcebe0; text-align: left; }
    .mu-summary div { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; font-size: 13.5px; }
    .mu-summary div + div { border-top: 1px dashed #d5e6da; }
    .mu-summary dt { color: #5f6368; font-weight: 600; white-space: nowrap; }
    .mu-summary dt i { width: 16px; color: #2e7d32; }
    .mu-summary dd { margin: 0; color: #1b4d2e; font-weight: 700; text-align: right; }
    .mu-link-label { margin: 0 0 8px; font-size: 13.5px; color: #374151; }
    .mu-map {
        display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; text-decoration: none;
        font-weight: 700; font-size: 14px; color: #1a56c4; background: #e8f0fe; border: 1px solid #c6dafc; overflow-wrap: anywhere;
    }
    .mu-map:hover { background: #dbe7fd; }
    @keyframes muFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes muPop { from { opacity: 0; transform: translateY(16px) scale(.94); } to { opacity: 1; transform: none; } }
    @media (max-width: 600px) {
        .mu-box { padding: 20px 14px 16px; }
        .mu-actions { flex-direction: column-reverse; }
        .mu-actions button { width: 100%; justify-content: center; }
        .mu-summary div { flex-direction: column; gap: 2px; }
        .mu-summary dd { text-align: left; }
    }
</style>
<script>
(function () {
    const DATA = <?php echo json_encode($muData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const SPECIAL = <?php echo json_encode((object) $muSpecial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const DAYNAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    const bd = document.getElementById('muBackdrop');
    const cal = document.getElementById('muCal');
    const err = document.getElementById('muError');
    const confirmBtn = document.getElementById('muConfirm');
    let app = null, appId = 0, selected = null, viewing = null, viewY = 0, viewM = 0, minM = 0, maxM = 0;

    function pad(n) { return String(n).padStart(2, '0'); }
    function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function parse(k) { const p = k.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function longDate(k) { const d = parse(k); return DAYNAMES[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
    function esc(s) { const t = document.createElement('span'); t.textContent = s; return t.innerHTML; }

    function renderCal() {
        const now = new Date();
        const tKey = key(now.getFullYear(), now.getMonth(), now.getDate());
        const cur = viewY * 12 + viewM;
        let html = '<div class="ip-cal-nav"><span class="ip-cal-title">' + MONTHS[viewM] + ' ' + viewY + '</span><span class="ip-cal-arrows">'
            + '<button type="button" class="ip-cal-arrow" data-step="-1" aria-label="Previous month"' + (cur <= minM ? ' disabled' : '') + '><i class="fa-solid fa-chevron-left"></i></button>'
            + '<button type="button" class="ip-cal-arrow" data-step="1" aria-label="Next month"' + (cur >= maxM ? ' disabled' : '') + '><i class="fa-solid fa-chevron-right"></i></button>'
            + '</span></div><div class="ip-cal-grid">';
        WEEKDAYS.forEach(function (w) { html += '<span class="ip-cal-dow">' + w + '</span>'; });
        const first = new Date(viewY, viewM, 1).getDay();
        const total = new Date(viewY, viewM + 1, 0).getDate();
        for (let i = 0; i < first; i++) html += '<span></span>';
        for (let d = 1; d <= total; d++) {
            const k = key(viewY, viewM, d);
            const open = !!app.options[k];
            const special = SPECIAL[k] && k >= tKey ? SPECIAL[k] : null;
            const cls = ['ip-day'];
            if (open) cls.push('open');
            if (special) cls.push('special');
            if (k === selected) cls.push('selected');
            if (k === viewing) cls.push('viewing');
            if (k === tKey) cls.push('today');
            html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + k + '"'
                + (special ? ' title="' + esc('Special day: ' + special) + '"' : '')
                + (open || special ? '' : ' disabled') + '>' + d + '</button>';
        }
        cal.innerHTML = html + '</div>';
    }

    function renderSide() {
        document.getElementById('muEmpty').hidden = !!(selected || viewing);
        const sp = document.getElementById('muSpecial');
        sp.hidden = !viewing;
        if (viewing) {
            const d = parse(viewing);
            sp.querySelector('.ip-special-mon').textContent = MONTHS[d.getMonth()].slice(0, 3).toUpperCase();
            sp.querySelector('.ip-special-day').textContent = d.getDate();
            sp.querySelector('.ip-special-name').textContent = SPECIAL[viewing];
            sp.querySelector('.ip-special-text').textContent = longDate(viewing) + ' is a special day, so City Hall is closed. Please choose another day.';
        }
        const day = document.getElementById('muDay');
        day.hidden = !selected;
        if (selected) {
            const o = app.options[selected];
            day.querySelector('.mu-day-date').textContent = longDate(selected);
            day.querySelector('.mu-hours strong').textContent = o.open + (o.close ? ' - ' + o.close : '');
        }
        confirmBtn.disabled = !selected;
    }

    cal.addEventListener('click', function (e) {
        const arrow = e.target.closest('.ip-cal-arrow');
        if (arrow) {
            const m = viewY * 12 + viewM + parseInt(arrow.dataset.step, 10);
            viewY = Math.floor(m / 12); viewM = m % 12;
            renderCal();
            return;
        }
        const b = e.target.closest('.ip-day:not([disabled])');
        if (!b) return;
        err.hidden = true;
        if (b.classList.contains('open')) { selected = b.dataset.date; viewing = null; }
        else { viewing = b.dataset.date; selected = null; }
        renderCal();
        renderSide();
    });

    function open(id) {
        app = DATA[id];
        if (!app) return;
        appId = id;
        const dates = Object.keys(app.options).sort();
        selected = app.chosen && app.options[app.chosen] ? app.chosen : null;
        viewing = null;
        const now = new Date();
        const start = dates.length ? parse(dates[0]) : now;
        const end = dates.length ? parse(dates[dates.length - 1]) : now;
        const focus = selected ? parse(selected) : start;
        viewY = focus.getFullYear(); viewM = focus.getMonth();
        minM = Math.min(now.getFullYear() * 12 + now.getMonth(), start.getFullYear() * 12 + start.getMonth());
        maxM = end.getFullYear() * 12 + end.getMonth();

        bd.querySelectorAll('.mu-pet').forEach(function (el) { el.textContent = app.pet; });
        const note = bd.querySelector('.mu-note');
        note.hidden = !app.note;
        note.querySelector('span').textContent = app.note;
        document.getElementById('muPick').hidden = false;
        document.getElementById('muDone').hidden = true;
        err.hidden = true;
        if (!dates.length) {
            err.textContent = 'The days offered by the staff have already passed. Please message the staff for a new schedule.';
            err.hidden = false;
        }
        renderCal();
        renderSide();
        bd.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function close() {
        const wasDone = !document.getElementById('muDone').hidden;
        bd.hidden = true;
        document.body.style.overflow = '';
        if (/[?&]meetup=/.test(location.search)) history.replaceState(null, '', 'schedule.php');
        // Napili na: i-reload para makita ang bagong detalye sa Schedule page
        if (wasDone) location.href = 'schedule.php#meetup-' + appId;
    }

    confirmBtn.addEventListener('click', function () {
        if (!selected) return;
        confirmBtn.disabled = true;
        const label = confirmBtn.innerHTML;
        confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('application_id', appId);
        fd.append('option_id', app.options[selected].id);
        fetch('process_choose_meetup.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                confirmBtn.innerHTML = label;
                if (!res.ok) {
                    err.textContent = res.message || 'Something went wrong. Please try again.';
                    err.hidden = false;
                    confirmBtn.disabled = false;
                    return;
                }
                bd.querySelector('.mu-done-pet').textContent = res.pet;
                bd.querySelector('.mu-done-date').textContent = res.date;
                bd.querySelector('.mu-done-time').textContent = res.time + ' (come anytime)';
                bd.querySelector('.mu-done-place').textContent = res.place + ', ' + res.address;
                document.getElementById('muMap').href = res.map;
                document.getElementById('muPick').hidden = true;
                document.getElementById('muDone').hidden = false;
                bd.querySelector('.mu-box').scrollTop = 0;
            })
            .catch(function () {
                confirmBtn.innerHTML = label;
                confirmBtn.disabled = false;
                err.textContent = 'Could not save your choice. Please check your internet connection and try again.';
                err.hidden = false;
            });
    });

    bd.querySelectorAll('[data-mu-close]').forEach(function (b) { b.addEventListener('click', close); });
    document.getElementById('muDoneBtn').addEventListener('click', close);
    bd.addEventListener('click', function (e) { if (e.target === bd) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !bd.hidden) close(); });
    document.querySelectorAll('[data-meetup-open]').forEach(function (b) {
        b.addEventListener('click', function (e) { e.preventDefault(); open(parseInt(b.dataset.meetupOpen, 10)); });
    });

    <?php if ($muOpenId > 0): ?>
    open(<?php echo $muOpenId; ?>);
    <?php endif; ?>
})();
</script>
