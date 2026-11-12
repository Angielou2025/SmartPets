<?php
// Reminder bell para sa staff. Isinasama ito ng _sidebar.php kaya lumalabas sa
// lahat ng staff page nang hindi na kailangang baguhin ang bawat isa.
//
// Kailangan ng $pdo at ng naka-login na $_SESSION['user_id'] - parehong meron na
// bago pa tawagin ito ng sidebar.
require_once __DIR__ . '/_reminders.php';

$sp_rem_staff_id = (int) ($_SESSION['user_id'] ?? 0);
$sp_rem_items = $sp_rem_staff_id > 0 ? staff_get_reminders($pdo, $sp_rem_staff_id) : [];
$sp_rem_counts = staff_reminder_counts($sp_rem_items);
?>
<style>
/* ===== Staff reminder bell ===== */
.sp-rem-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    margin: 0 0 14px;
    padding: 10px 12px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
    font: inherit;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    text-align: left;
    transition: background 0.15s ease;
}

.sp-rem-btn:hover {
    background: rgba(255, 255, 255, 0.16);
}

.sp-rem-btn .sp-rem-ico {
    font-size: 16px;
    line-height: 1;
}

.sp-rem-btn .sp-rem-label {
    flex: 1;
}

.sp-rem-badge {
    min-width: 22px;
    padding: 2px 7px;
    border-radius: 11px;
    background: #9ca3af;
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    text-align: center;
}

.sp-rem-badge.has-urgent {
    background: #e63946;
    animation: spRemPulse 1.6s ease-in-out infinite;
}

.sp-rem-badge.has-warning {
    background: #f59c23;
}

.sp-rem-badge.is-empty {
    display: none;
}

@keyframes spRemPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.12); }
}

/* --- Panel --- */
.sp-rem-panel {
    position: fixed;
    top: 16px;
    left: 236px;
    width: 380px;
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 32px);
    display: none;
    flex-direction: column;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 24px 70px rgba(2, 6, 23, 0.28);
    z-index: 10000;
    overflow: hidden;
}

.sp-rem-panel.show {
    display: flex;
}

.sp-rem-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid #eef0f3;
}

.sp-rem-head h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: #102a2a;
}

.sp-rem-close {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e2e5ea;
    border-radius: 50%;
    background: #f6f7f9;
    color: #475569;
    font-size: 14px;
    cursor: pointer;
}

.sp-rem-close:hover {
    background: #e63946;
    border-color: #e63946;
    color: #fff;
}

.sp-rem-list {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 8px;
    scrollbar-width: thin;
}

.sp-rem-item {
    display: flex;
    gap: 10px;
    padding: 11px 12px;
    border-radius: 10px;
    border-left: 4px solid #cbd5e1;
    background: #f8fafc;
    margin-bottom: 8px;
    text-decoration: none;
    color: inherit;
    transition: background 0.14s ease, transform 0.14s ease;
}

.sp-rem-item:hover {
    background: #eef2f7;
    transform: translateX(2px);
}

.sp-rem-item.level-urgent {
    border-left-color: #e63946;
    background: #fdf1f2;
}

.sp-rem-item.level-warning {
    border-left-color: #f59c23;
    background: #fff8ec;
}

.sp-rem-item.level-info {
    border-left-color: #1e3a8a;
}

.sp-rem-item-ico {
    font-size: 17px;
    line-height: 1.2;
}

.sp-rem-item-title {
    font-weight: 800;
    font-size: 13.5px;
    color: #102a2a;
    margin-bottom: 2px;
}

.sp-rem-item-detail {
    font-size: 12.5px;
    color: #475569;
    line-height: 1.45;
}

.sp-rem-item-meta {
    margin-top: 4px;
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.sp-rem-empty {
    padding: 34px 20px;
    text-align: center;
    color: #64748b;
    font-size: 13px;
}

.sp-rem-foot {
    padding: 10px 16px;
    border-top: 1px solid #eef0f3;
    font-size: 11.5px;
    color: #94a3b8;
}

/* --- Pop-up announcement na lumalabas pagbukas ng page --- */
.sp-rem-pop-backdrop {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    background: rgba(2, 6, 23, 0.55);
    z-index: 10001;
    overflow-y: auto;
}

.sp-rem-pop-backdrop.show {
    display: flex;
}

.sp-rem-pop {
    width: 100%;
    max-width: 460px;
    max-height: calc(100vh - 48px);
    margin: auto;
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 28px 80px rgba(2, 6, 23, 0.35);
    overflow: hidden;
    animation: spRemPopIn 0.22s ease-out;
}

@keyframes spRemPopIn {
    from { opacity: 0; transform: translateY(14px) scale(0.97); }
    to { opacity: 1; transform: none; }
}

.sp-rem-pop-head {
    flex: 0 0 auto;
    padding: 18px 20px;
    background: linear-gradient(135deg, #1e3a8a, #2b4fc4);
    color: #fff;
}

.sp-rem-pop-head h4 {
    margin: 0 0 3px;
    font-size: 17px;
    font-weight: 800;
}

.sp-rem-pop-head p {
    margin: 0;
    font-size: 12.5px;
    opacity: 0.85;
}

.sp-rem-pop-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 14px 16px;
    scrollbar-width: thin;
}

.sp-rem-pop-more {
    margin: 4px 2px 0;
    font-size: 12px;
    color: #64748b;
    font-weight: 600;
}

.sp-rem-pop-foot {
    flex: 0 0 auto;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding: 14px 16px;
    border-top: 1px solid #eef0f3;
}

.sp-rem-pop-foot button {
    padding: 9px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #334155;
}

.sp-rem-pop-foot button.primary {
    background: #1e3a8a;
    border-color: #1e3a8a;
    color: #fff;
}

.sp-rem-pop-foot button:hover {
    filter: brightness(0.96);
}

/* --- "Join Google Meet" sa reminder card --- */
.sp-rem-join {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 6px 12px;
    border-radius: 7px;
    background: #1a73e8;
    color: #fff;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
}

.sp-rem-join:hover {
    background: #1765cc;
}

/* --- Pop-up bago magsimula ang interview: countdown, tapos bubuksan ang meeting --- */
.sp-meet-backdrop {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
    background: rgba(2, 6, 23, 0.6);
    z-index: 10002;
}

.sp-meet-backdrop.show {
    display: flex;
}

.sp-meet {
    width: 100%;
    max-width: 420px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 28px 80px rgba(2, 6, 23, 0.35);
    overflow: hidden;
    text-align: center;
    animation: spRemPopIn 0.22s ease-out;
}

.sp-meet-head {
    padding: 20px;
    background: linear-gradient(135deg, #1a73e8, #0b57d0);
    color: #fff;
}

.sp-meet-head .ico {
    font-size: 34px;
    line-height: 1;
}

.sp-meet-head h4 {
    margin: 8px 0 2px;
    font-size: 18px;
    font-weight: 800;
}

.sp-meet-head p {
    margin: 0;
    font-size: 13px;
    opacity: 0.9;
}

.sp-meet-body {
    padding: 18px 20px 6px;
}

.sp-meet-count {
    font-size: 34px;
    font-weight: 800;
    color: #102a2a;
    font-variant-numeric: tabular-nums;
}

.sp-meet-note {
    margin: 6px 0 0;
    font-size: 13px;
    color: #475569;
    line-height: 1.5;
}

.sp-meet-foot {
    display: flex;
    gap: 10px;
    justify-content: center;
    padding: 16px 20px 20px;
}

.sp-meet-foot a,
.sp-meet-foot button {
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #334155;
}

.sp-meet-foot a {
    background: #1a73e8;
    border-color: #1a73e8;
    color: #fff;
}

@media (max-width: 900px) {
    .sp-rem-panel {
        left: 16px;
        right: 16px;
        top: 12px;
        width: auto;
        max-width: none;
    }
}
</style>

<button type="button" class="sp-rem-btn" id="spRemBtn" aria-expanded="false" aria-controls="spRemPanel">
    <span class="sp-rem-ico" aria-hidden="true">🔔</span>
    <span class="sp-rem-label">Reminders</span>
    <span class="sp-rem-badge" id="spRemBadge"></span>
</button>

<div class="sp-rem-panel" id="spRemPanel" role="dialog" aria-label="Staff reminders" aria-hidden="true">
    <div class="sp-rem-head">
        <h4>Reminders</h4>
        <button type="button" class="sp-rem-close" id="spRemClose" aria-label="Close">&#10005;</button>
    </div>
    <div class="sp-rem-list" id="spRemList"></div>
    <div class="sp-rem-foot" id="spRemFoot">Updated just now</div>
</div>

<div class="sp-rem-pop-backdrop" id="spRemPopBackdrop" role="alertdialog" aria-labelledby="spRemPopTitle" aria-hidden="true">
    <div class="sp-rem-pop">
        <div class="sp-rem-pop-head">
            <h4 id="spRemPopTitle">📢 Things you need to do</h4>
            <p id="spRemPopDate"></p>
        </div>
        <div class="sp-rem-pop-body" id="spRemPopBody"></div>
        <div class="sp-rem-pop-foot">
            <button type="button" id="spRemPopLater">Remind me later</button>
            <button type="button" class="primary" id="spRemPopOk">Got it</button>
        </div>
    </div>
</div>

<div class="sp-meet-backdrop" id="spMeetBackdrop" role="alertdialog" aria-labelledby="spMeetTitle" aria-hidden="true">
    <div class="sp-meet">
        <div class="sp-meet-head">
            <div class="ico" aria-hidden="true">🎥</div>
            <h4 id="spMeetTitle">Interview starting soon</h4>
            <p id="spMeetWho"></p>
        </div>
        <div class="sp-meet-body">
            <div class="sp-meet-count" id="spMeetCount"></div>
            <p class="sp-meet-note" id="spMeetNote"></p>
        </div>
        <div class="sp-meet-foot">
            <button type="button" id="spMeetLater">Not now</button>
            <a href="#" target="_blank" rel="noopener" id="spMeetJoin">Join Google Meet</a>
        </div>
    </div>
</div>

<script>
(function () {
    const BASE = '/Caps2/StaffFile/';
    // Kada 2 minuto kumukuha ng bagong listahan, para gumagalaw ang "Starts in X minutes"
    const REFRESH_MS = 120000;

    const btn = document.getElementById('spRemBtn');
    const badge = document.getElementById('spRemBadge');
    const panel = document.getElementById('spRemPanel');
    const list = document.getElementById('spRemList');
    const foot = document.getElementById('spRemFoot');
    const closeBtn = document.getElementById('spRemClose');

    const popBackdrop = document.getElementById('spRemPopBackdrop');
    const popBody = document.getElementById('spRemPopBody');
    const popDate = document.getElementById('spRemPopDate');
    const popOk = document.getElementById('spRemPopOk');
    const popLater = document.getElementById('spRemPopLater');

    if (!btn || !panel || !list || !popBackdrop) return;

    // Nasa loob ng sidebar ang markup (doon kasi kasama ang include), pero ang
    // panel at pop-up ay dapat nasa body para hindi sila maipit sa scroll o sa
    // sukat ng sidebar.
    document.body.appendChild(panel);
    document.body.appendChild(popBackdrop);
    document.body.appendChild(document.getElementById('spMeetBackdrop'));

    let items = <?php echo json_encode($sp_rem_items, JSON_UNESCAPED_UNICODE); ?>;
    let counts = <?php echo json_encode($sp_rem_counts); ?>;

    // Ang na-dismiss na toast ay hindi na uulit habang nasa parehong browser tab
    function isDismissed(key) {
        try {
            return sessionStorage.getItem('sp_rem_seen_' + key) === '1';
        } catch (e) {
            return false;
        }
    }

    function markDismissed(key) {
        try {
            sessionStorage.setItem('sp_rem_seen_' + key, '1');
        } catch (e) { /* walang storage, hahayaan lang */ }
    }

    function renderBadge() {
        badge.textContent = counts.total > 99 ? '99+' : String(counts.total);
        badge.className = 'sp-rem-badge';
        if (counts.total === 0) {
            badge.classList.add('is-empty');
        } else if (counts.urgent > 0) {
            badge.classList.add('has-urgent');
        } else if (counts.warning > 0) {
            badge.classList.add('has-warning');
        }
    }

    // Isang card sa listahan. Link kapag may pupuntahan, hindi kapag wala.
    function buildItem(item) {
        const row = document.createElement('a');
        row.className = 'sp-rem-item level-' + (item.level || 'info');
        row.href = BASE + (item.link || '');

        const ico = document.createElement('span');
        ico.className = 'sp-rem-item-ico';
        ico.textContent = item.icon || '🔔';
        row.appendChild(ico);

        const body = document.createElement('div');

        const title = document.createElement('div');
        title.className = 'sp-rem-item-title';
        title.textContent = item.title || '';
        body.appendChild(title);

        if (item.detail) {
            const detail = document.createElement('div');
            detail.className = 'sp-rem-item-detail';
            detail.textContent = item.detail;
            body.appendChild(detail);
        }

        if (item.meta) {
            const meta = document.createElement('div');
            meta.className = 'sp-rem-item-meta';
            meta.textContent = item.meta;
            body.appendChild(meta);
        }

        // Nasa loob ng link ang card, kaya span (hindi <a>) ang button na ito
        if (item.meet && item.meet.url) {
            const join = document.createElement('span');
            join.className = 'sp-rem-join';
            join.setAttribute('role', 'button');
            join.textContent = '🎥 Join Google Meet';
            join.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openMeet(item.meet, true);
            });
            body.appendChild(join);
        }

        row.appendChild(body);
        return row;
    }

    // ===== Pagdala sa staff sa meeting pagdating ng oras ng interview =====
    // Lumalabas ang pop-up 5 minuto bago magsimula, may countdown, at pagdating
    // ng oras ay binubuksan ang meeting link. Kapag hinarangan ng browser ang
    // bagong tab, dito na mismo sa tab na ito bubuksan ang meeting.
    const MEET_SHOW_BEFORE_MS = 5 * 60000;   // lumabas 5 minuto bago magsimula
    const MEET_AUTO_OPEN_MS = 5 * 60000;     // kusang bubuksan hanggang 5 minuto pagkatapos ng simula
    const MEET_SHOW_AFTER_MS = 60 * 60000;   // may Join button pa hanggang 1 oras pagkatapos

    const meetBackdrop = document.getElementById('spMeetBackdrop');
    const meetWho = document.getElementById('spMeetWho');
    const meetTitle = document.getElementById('spMeetTitle');
    const meetCount = document.getElementById('spMeetCount');
    const meetNote = document.getElementById('spMeetNote');
    const meetJoin = document.getElementById('spMeetJoin');
    const meetLater = document.getElementById('spMeetLater');
    let meets = [];
    let shownMeet = null;

    // localStorage para isang beses lang bubuksan kahit maraming staff tab ang bukas.
    // Kasama ang petsa at oras sa key, para lumabas ulit kapag na-reschedule.
    function meetFlag(name, meet, set) {
        const key = 'sp_meet_' + name + '_' + meet.id + '_'
            + new Date().toDateString().replace(/\s/g, '') + '_' + String(meet.time).replace(/\W/g, '');
        try {
            if (set) {
                localStorage.setItem(key, String(Date.now()));
                return true;
            }
            return localStorage.getItem(key) !== null;
        } catch (e) {
            return false;
        }
    }

    function loadMeets() {
        const received = Date.now();
        meets = items
            .filter(function (i) { return i.meet && i.meet.url && i.meet.mine; })
            .map(function (i) {
                return Object.assign({}, i.meet, { startAt: received + i.meet.starts_in * 1000 });
            })
            .sort(function (a, b) { return a.startAt - b.startAt; });
    }

    function openMeet(meet, byClick) {
        meetFlag('opened', meet, true);
        hideMeet();
        const win = window.open(meet.url, '_blank');
        if (win) {
            win.opener = null;
        } else if (!byClick) {
            // Hinarangan ng popup blocker (karaniwan kapag hindi galing sa click)
            window.location.href = meet.url;
        }
    }

    function hideMeet() {
        shownMeet = null;
        meetBackdrop.classList.remove('show');
        meetBackdrop.setAttribute('aria-hidden', 'true');
    }

    function formatCountdown(ms) {
        const total = Math.max(0, Math.ceil(ms / 1000));
        const m = Math.floor(total / 60);
        const s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function tickMeets() {
        const now = Date.now();
        const meet = meets.find(function (m) {
            return now >= m.startAt - MEET_SHOW_BEFORE_MS
                && now <= m.startAt + MEET_SHOW_AFTER_MS
                && !meetFlag('opened', m)
                && !meetFlag('later', m);
        });

        if (!meet) {
            if (shownMeet) hideMeet();
            return;
        }

        const left = meet.startAt - now;

        // Dumating na ang oras - bubuksan na ang meeting
        if (left <= 0 && left > -MEET_AUTO_OPEN_MS) {
            openMeet(meet, false);
            return;
        }

        if (!shownMeet || shownMeet.id !== meet.id) {
            shownMeet = meet;
            meetWho.textContent = meet.who + ' • ' + meet.time;
            meetJoin.href = meet.url;
            meetBackdrop.classList.add('show');
            meetBackdrop.setAttribute('aria-hidden', 'false');
        }

        if (left > 0) {
            meetTitle.textContent = 'Interview starting soon';
            meetCount.textContent = formatCountdown(left);
            meetNote.textContent = 'Your Google Meet will open automatically when the interview starts. Keep this page open.';
        } else {
            meetTitle.textContent = 'Interview already started';
            meetCount.textContent = meet.time;
            meetNote.textContent = 'The applicant may already be waiting in the meeting.';
        }
    }

    meetJoin.addEventListener('click', function (e) {
        if (!shownMeet) return;
        e.preventDefault();
        openMeet(shownMeet, true);
    });

    // "Not now" - hindi na lalabas o kusang magbubukas para sa interview na ito
    meetLater.addEventListener('click', function () {
        if (shownMeet) meetFlag('later', shownMeet, true);
        hideMeet();
    });

    function renderList() {
        list.textContent = '';

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'sp-rem-empty';
            empty.textContent = 'You are all caught up. No reminders right now.';
            list.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            list.appendChild(buildItem(item));
        });
    }

    // Ang pop-up ay para lang sa mga mahalaga (urgent at warning). Hindi na ito
    // uulit sa parehong tab hangga't hindi nagbabago ang laman - pero kapag may
    // bagong urgent na dumating (halimbawa malapit nang magsimula ang interview),
    // lalabas itong muli.
    function importantItems() {
        return items.filter(function (i) {
            return i.level === 'urgent' || i.level === 'warning';
        });
    }

    function popSignature(arr) {
        return arr.map(function (i) { return i.key; }).sort().join('|');
    }

    function showAnnouncement(force) {
        const important = importantItems();

        if (!important.length) {
            hideAnnouncement();
            return;
        }

        const signature = popSignature(important);
        if (!force && isDismissed('pop_' + signature)) return;

        popBackdrop.dataset.signature = signature;
        popDate.textContent = new Date().toLocaleDateString([], {
            weekday: 'long', month: 'long', day: 'numeric'
        });

        popBody.textContent = '';
        important.slice(0, 5).forEach(function (item) {
            popBody.appendChild(buildItem(item));
        });

        if (important.length > 5) {
            const more = document.createElement('p');
            more.className = 'sp-rem-pop-more';
            more.textContent = '+ ' + (important.length - 5) + ' more in the Reminders list.';
            popBody.appendChild(more);
        }

        popBackdrop.classList.add('show');
        popBackdrop.setAttribute('aria-hidden', 'false');
    }

    function hideAnnouncement(remember) {
        if (remember && popBackdrop.dataset.signature) {
            markDismissed('pop_' + popBackdrop.dataset.signature);
        }
        popBackdrop.classList.remove('show');
        popBackdrop.setAttribute('aria-hidden', 'true');
    }

    function openPanel() {
        panel.classList.add('show');
        panel.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        panel.classList.remove('show');
        panel.setAttribute('aria-hidden', 'true');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function () {
        if (panel.classList.contains('show')) {
            closePanel();
        } else {
            openPanel();
        }
    });

    closeBtn.addEventListener('click', closePanel);

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (popBackdrop.classList.contains('show')) {
            hideAnnouncement(false);
            return;
        }
        closePanel();
    });

    // Pag-click sa labas ng panel ay pagsasara rin
    document.addEventListener('mousedown', function (e) {
        if (!panel.classList.contains('show')) return;
        if (panel.contains(e.target) || btn.contains(e.target)) return;
        closePanel();
    });

    // "Got it" - hindi na uulit ang parehong paalala sa tab na ito
    popOk.addEventListener('click', function () {
        hideAnnouncement(true);
    });

    // "Remind me later" - isasara lang, lalabas ulit sa susunod na page
    popLater.addEventListener('click', function () {
        hideAnnouncement(false);
    });

    popBackdrop.addEventListener('mousedown', function (e) {
        if (e.target === popBackdrop) hideAnnouncement(false);
    });

    function refresh() {
        fetch(BASE + 'reminders_data.php', { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                if (!data || !data.reminders) return;
                items = data.reminders;
                counts = data.counts;
                renderBadge();
                renderList();
                loadMeets();
                tickMeets();
                showAnnouncement(false);
                foot.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            })
            .catch(function () { /* offline o may error - susubukan ulit sa susunod */ });
    }

    renderBadge();
    renderList();
    loadMeets();
    tickMeets();
    showAnnouncement(false);
    foot.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    setInterval(refresh, REFRESH_MS);
    setInterval(tickMeets, 1000);
})();
</script>
