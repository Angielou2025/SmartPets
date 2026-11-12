/**
 * Interview picker (Adopter_File/_interview_picker.php).
 * Calendar icon -> calendar (anumang Lunes-Biyernes na hindi pa lumilipas) ->
 * pumili ng araw -> ilagay ang sariling oras sa loob ng oras ng opisina.
 * Ang mga oras na kinuha na ng ibang adopter ay ipinapakita at hindi mapipili;
 * kada 15 segundo, kinukuha ulit ang listahan habang bukas ang page.
 *
 * window.InterviewPicker.validate(picker) - true kapag kumpleto at tama;
 * kung hindi, ipinapakita ang error at binubuksan ang calendar.
 */
window.InterviewPicker = (function () {
    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];
    const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function todayKey() { const t = new Date(); return key(t.getFullYear(), t.getMonth(), t.getDate()); }
    function parseDate(str) {
        const p = str.split('-');
        return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
    }
    function toMinutes(hhmm) {
        const p = hhmm.split(':');
        return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
    }
    function toHHMM(min) { return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }
    function label12(min) {
        const h = Math.floor(min / 60), m = min % 60;
        return (h % 12 || 12) + ':' + pad(m) + ' ' + (h < 12 ? 'AM' : 'PM');
    }
    function longDate(str) {
        const d = parseDate(str);
        return DAY_NAMES[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function init(picker) {
        let cfg = {};
        try { cfg = JSON.parse(picker.dataset.config || '{}') || {}; } catch (e) { cfg = {}; }
        const LENGTH = cfg.length || 90;
        // 0 = puwede kahit ngayon na (iniikot pataas sa susunod na 15 minuto)
        const LEAD = typeof cfg.lead === 'number' ? cfg.lead : 60;
        const OPEN = toMinutes(cfg.open || '08:00');
        const LAST_START = toMinutes(cfg.close || '17:00') - LENGTH;
        const MAX_DATE = cfg.maxDate || '9999-12-31';
        // Mga araw na bukas ang City Hall, itinakda ng staff (1 = Lunes ... 6 = Sabado, 7 = Linggo)
        const DAYS = Array.isArray(cfg.days) ? cfg.days.map(Number) : [1, 2, 3, 4, 5];
        // getDay() ng JavaScript: 0 = Linggo, kaya ginagawang 7
        function isoDay(jsDay) { return jsDay === 0 ? 7 : jsDay; }
        // Break ng staff araw-araw: [simula, tapos] sa minuto, o null
        const BREAK = Array.isArray(cfg.break) ? cfg.break : null;
        let taken = (cfg.taken && !Array.isArray(cfg.taken)) ? cfg.taken : {};
        // Special days: holiday sa Pilipinas at ganap sa City Hall na inilagay ng staff
        // 'Y-m-d' => pangalan (hal. '2026-12-25' => 'Christmas Day')
        const SPECIAL = (cfg.special && !Array.isArray(cfg.special)) ? cfg.special : {};
        function specialOn(dateStr) { return SPECIAL[dateStr] || null; }
        // Petsang special day na pinindot ng adopter (ipinapakita ang paliwanag)
        let specialView = null;

        const toggle = picker.querySelector('.ip-toggle');
        const panel = picker.querySelector('.ip-panel');
        const cal = picker.querySelector('.ip-cal');
        const timeBox = picker.querySelector('.ip-time');
        const timeInput = picker.querySelector('.ip-time-input');
        const dateValue = picker.querySelector('.ip-date-value');
        const timeValue = picker.querySelector('.ip-time-value');
        const errorBox = picker.querySelector('.ip-error');
        const takenBox = picker.querySelector('.ip-taken');
        const takenList = picker.querySelector('.ip-taken-list');
        const iconTop = picker.querySelector('.gcal-top');
        const iconNum = picker.querySelector('.gcal-num');

        function nowMinutes() { const n = new Date(); return n.getHours() * 60 + n.getMinutes(); }

        // Pinakamaagang oras sa araw na ito: bukas ng City Hall, o ngayon (kung ngayong araw)
        function earliestOn(dateStr) {
            let min = OPEN;
            if (dateStr === todayKey()) {
                // Susunod na buong minuto mula ngayon (hal. 8:12 ngayon -> 8:13)
                min = Math.max(min, nowMinutes() + LEAD + 1);
            }
            return min;
        }

        function takenOn(dateStr) { return taken[dateStr] || []; }

        // Mga oras na puwede pang SIMULAN (bawat minuto), bilang mga hanay [mula, hanggang].
        // Inaalis ang oras na tatama sa break ng staff o sa naka-schedule na, at ang
        // walang sapat na 1 oras at 30 minuto bago mag-out. Walang laman = wala nang oras.
        function freeRanges(dateStr) {
            const busy = takenOn(dateStr);
            const ranges = [];
            let runStart = null, last = null;
            for (let t = earliestOn(dateStr); t <= LAST_START; t++) {
                const ok = !hitsBreak(t) && !busy.some(function (r) { return clashes(t, r); });
                if (ok) {
                    if (runStart === null) runStart = t;
                    last = t;
                } else if (runStart !== null) {
                    ranges.push([runStart, last]);
                    runStart = null;
                }
            }
            if (runStart !== null) ranges.push([runStart, last]);
            return ranges;
        }

        // Pinakamaaga at pinakahuling oras na puwedeng simulan sa isang araw
        function timeRange(dateStr) {
            const r = freeRanges(dateStr);
            return r.length ? { min: r[0][0], max: r[r.length - 1][1], ranges: r } : null;
        }

        // Puwedeng pindutin: araw na bukas ang City Hall, hindi pa lumilipas, at may
        // natitira pang 1 oras at 30 minuto (pagkatapos ng break at ng naka-schedule na)
        function isSelectable(dateStr) {
            if (DAYS.indexOf(isoDay(parseDate(dateStr).getDay())) === -1) return false;
            if (dateStr < todayKey() || dateStr > MAX_DATE) return false;
            if (specialOn(dateStr)) return false;
            return timeRange(dateStr) !== null;
        }

        function rangesText(ranges) {
            return ranges.map(function (r) {
                return r[0] === r[1] ? label12(r[0]) : label12(r[0]) + ' to ' + label12(r[1]);
            }).join(', or ');
        }

        // May 1 minutong puwang sa naka-book na interview: tapos 9:32 -> 9:33 ang pinakamaaga
        function clashes(startMin, r) { return startMin <= r[1] && startMin + LENGTH >= r[0]; }

        function clashWith(dateStr, startMin) {
            const end = startMin + LENGTH;
            return takenOn(dateStr).find(function (r) { return clashes(startMin, r); }) || null;
        }

        function hitsBreak(startMin) {
            return BREAK !== null && startMin < BREAK[1] && startMin + LENGTH > BREAK[0];
        }

        let selected = isSelectable(dateValue.value || '') ? dateValue.value : null;
        const start = parseDate(selected || todayKey());
        let viewYear = start.getFullYear();
        let viewMonth = start.getMonth();

        function setOpen(open) {
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            picker.classList.toggle('is-open', open);
        }

        function showError(msg) {
            errorBox.textContent = msg || '';
            errorBox.hidden = !msg;
        }

        function renderCalendar() {
            const now = new Date();
            const current = viewYear * 12 + viewMonth;
            const minMonth = now.getFullYear() * 12 + now.getMonth();
            const maxD = parseDate(MAX_DATE);
            const maxMonth = maxD.getFullYear() * 12 + maxD.getMonth();
            const tKey = todayKey();

            let html = '<div class="ip-cal-nav">'
                + '<span class="ip-cal-title">' + MONTHS[viewMonth] + ' ' + viewYear + '</span>'
                + '<span class="ip-cal-arrows">'
                + '<button type="button" class="ip-cal-arrow" data-step="-1" aria-label="Previous month"' + (current <= minMonth ? ' disabled' : '') + '><i class="fa-solid fa-chevron-left"></i></button>'
                + '<button type="button" class="ip-cal-arrow" data-step="1" aria-label="Next month"' + (current >= maxMonth ? ' disabled' : '') + '><i class="fa-solid fa-chevron-right"></i></button>'
                + '</span></div><div class="ip-cal-grid">';

            WEEKDAYS.forEach(function (w, i) {
                // Kupas ang pangalan ng araw na sarado ang City Hall
                html += '<span class="ip-cal-dow' + (DAYS.indexOf(isoDay(i)) === -1 ? ' weekend' : '') + '">' + w + '</span>';
            });

            const firstDay = new Date(viewYear, viewMonth, 1).getDay();
            const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
            for (let i = 0; i < firstDay; i++) html += '<span></span>';

            for (let d = 1; d <= daysInMonth; d++) {
                const k = key(viewYear, viewMonth, d);
                const open = isSelectable(k);
                const special = specialOn(k);
                // Special day na hindi pa lumilipas: napipindot para makita kung bakit sarado
                const specialClickable = special && k >= tKey && k <= MAX_DATE;
                const cls = ['ip-day'];
                if (open) cls.push('open');
                if (special) cls.push('special');
                if (k === specialView) cls.push('viewing');
                if (k === selected) cls.push('selected');
                if (k === tKey) cls.push('today');
                if (open && takenOn(k).length) cls.push('has-taken');
                html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + k + '"'
                    + (special ? ' title="' + escAttr('Special day: ' + special) + '"' : '')
                    + (open || specialClickable ? '' : ' disabled') + '>' + d + '</button>';
            }
            html += '</div>';
            if (!DAYS.length) {
                html += '<p class="ip-cal-empty">City Hall has no interview days set right now. Please check again later or send the shelter a message.</p>';
            }
            cal.innerHTML = html;
        }

        const timeEmpty = picker.querySelector('.ip-time-empty');
        const specialBox = picker.querySelector('.ip-special');

        // Paliwanag kapag special day ang pinindot: anong special day at bakit sarado
        function renderSpecial() {
            if (!specialBox) return;
            specialBox.hidden = !specialView;
            if (!specialView) return;
            const d = parseDate(specialView);
            specialBox.querySelector('.ip-special-mon').textContent = MONTHS[d.getMonth()].slice(0, 3).toUpperCase();
            specialBox.querySelector('.ip-special-day').textContent = d.getDate();
            specialBox.querySelector('.ip-special-name').textContent = specialOn(specialView);
            specialBox.querySelector('.ip-special-text').textContent = longDate(specialView)
                + ' is a special day, so City Hall is closed and interviews are suspended. Please choose another date.';
        }

        function renderTime() {
            renderSpecial();
            if (timeEmpty) timeEmpty.hidden = !!selected || !!specialView;
            if (!selected) {
                timeBox.hidden = true;
                return;
            }
            const range = timeRange(selected);
            timeBox.hidden = false;
            timeBox.querySelector('.ip-time-date').textContent = longDate(selected);
            timeBox.querySelector('.ip-hours').textContent = range
                ? 'Available start times: ' + rangesText(range.ranges) + '. Times that have passed or are taken are hidden.'
                : 'No interview time is left on this day. Please choose another date.';
            if (range) {
                timeInput.min = toHHMM(range.min);
                timeInput.max = toHHMM(range.max);
            }
            // Hindi puwedeng oras: break ng staff at mga oras na kinuha na ng ibang adopter.
            // Ngayong araw: hindi na ipinapakita ang lumipas na.
            const isToday = selected === todayKey();
            const passed = isToday ? nowMinutes() : -1;
            const list = takenOn(selected).filter(function (r) { return r[1] > passed; });
            let chips = '';
            if (BREAK !== null && BREAK[1] > passed) {
                chips += '<span class="ip-taken-chip ip-break-chip"><i class="fa-solid fa-mug-hot"></i> Staff break '
                    + label12(BREAK[0]) + ' - ' + label12(BREAK[1]) + '</span>';
            }
            chips += list.map(function (r) {
                return '<span class="ip-taken-chip">' + label12(r[0]) + ' - ' + label12(r[1]) + '</span>';
            }).join('');
            takenBox.hidden = chips === '';
            takenList.innerHTML = chips;
            syncSelectors();
        }

        function updateIcon() {
            const d = selected ? parseDate(selected) : new Date();
            iconTop.textContent = MONTHS[d.getMonth()].slice(0, 3).toUpperCase();
            iconNum.textContent = d.getDate();
        }

        // Tinitingnan ang oras at isinusulat sa hidden fields kapag tama
        function commit(showMessages) {
            timeValue.value = '';
            if (!selected) {
                if (showMessages) {
                    showError(specialView
                        ? longDate(specialView) + ' is a special day (' + specialOn(specialView) + '). Please choose another date.'
                        : 'Please choose a date on the calendar.');
                }
                return false;
            }
            const range = timeRange(selected);
            const t = timeInput.value;
            if (!t) {
                if (showMessages) showError('Please choose AM or PM, then the hour and minutes.');
                return false;
            }
            const m = toMinutes(t);
            if (!range) {
                showError('No interview time is left on this day. Each interview takes 1 hour and 30 minutes. Please choose another date.');
                return false;
            }
            if (m < range.min || m > range.max) {
                showError('Please enter a time from ' + rangesText(range.ranges) + '.');
                return false;
            }
            if (hitsBreak(m)) {
                showError('This time overlaps with the staff break from ' + label12(BREAK[0]) + ' to ' + label12(BREAK[1])
                    + '. Each interview takes 1 hour and 30 minutes, so please choose a time that starts at '
                    + label12(BREAK[1]) + ' or later, or one that ends by ' + label12(BREAK[0]) + '.');
                return false;
            }
            const clash = clashWith(selected, m);
            if (clash) {
                showError('This time is already booked by another adopter (' + label12(clash[0]) + ' - ' + label12(clash[1]) + '). '
                    + 'Each interview takes 1 hour and 30 minutes. You can choose from ' + rangesText(range.ranges) + ', or pick another day.');
                return false;
            }
            showError('');
            timeValue.value = t;
            return true;
        }

        // Pinindot ang special day: hindi ito mapipili - aalisin ang naunang napiling
        // petsa at ipapakita kung anong special day ito
        function viewSpecial(dateStr) {
            specialView = dateStr;
            selected = null;
            dateValue.value = '';
            timeValue.value = '';
            showError('');
            renderCalendar();
            renderTime();
            updateIcon();
            dateValue.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function selectDate(dateStr, focusTime) {
            specialView = null;
            selected = dateStr;
            dateValue.value = dateStr;
            const range = timeRange(dateStr);
            // Panatilihin ang naunang oras kung pasok pa; kung hindi, burahin
            if (timeInput.value && range) {
                const m = toMinutes(timeInput.value);
                if (m < range.min || m > range.max) timeInput.value = '';
            }
            showError('');
            renderCalendar();
            renderTime();
            updateIcon();
            if (timeInput.value) commit(true); else commit(false);
            dateValue.dispatchEvent(new Event('change', { bubbles: true }));
            if (focusTime) setTimeout(function () { timeInput.focus(); }, 0);
        }

        toggle.addEventListener('click', function () { setOpen(panel.hidden); });

        cal.addEventListener('click', function (e) {
            const arrow = e.target.closest('.ip-cal-arrow');
            if (arrow) {
                const m = viewYear * 12 + viewMonth + parseInt(arrow.dataset.step, 10);
                viewYear = Math.floor(m / 12);
                viewMonth = m % 12;
                renderCalendar();
                return;
            }
            const special = e.target.closest('.ip-day.special:not([disabled])');
            if (special) { viewSpecial(special.dataset.date); return; }
            const day = e.target.closest('.ip-day.open');
            if (day) selectDate(day.dataset.date, true);
        });

        // ===== Pili ng oras: iisang field (gaya ng dati); pagpindot, lalabas ang
        // maliit na listahan ng oras | minuto | AM/PM - mga puwede lang =====
        const tBtn = picker.querySelector('.ip-tbtn');
        const tVal = picker.querySelector('.ip-tval');
        const tPop = picker.querySelector('.ip-tpop');
        const colH = picker.querySelector('.ip-col-h');
        const colM = picker.querySelector('.ip-col-m');
        const colP = picker.querySelector('.ip-col-p');
        let period = null, selHour = null;


        // Bawat minuto (00-59) na puwedeng simulan: hindi lumipas, hindi tatama sa
        // break o sa naka-schedule na, at may sapat na 1 oras at 30 minuto bago mag-out
        function validStarts() {
            const out = [];
            if (!selected || !timeRange(selected)) return out;
            const busy = takenOn(selected);
            for (let m = earliestOn(selected); m <= LAST_START; m++) {
                if (!hitsBreak(m) && !busy.some(function (b) { return clashes(m, b); })) out.push(m);
            }
            return out;
        }
        function h12(m) { const h = Math.floor(m / 60) % 12; return h === 0 ? 12 : h; }

        function setTime(m) {
            timeInput.value = m === null ? '' : toHHMM(m);
            timeInput.dispatchEvent(new Event('change'));
            syncSelectors();
        }

        function item(label, value, on, kind) {
            return '<button type="button" class="ip-titem' + (on ? ' on' : '') + '" data-k="' + kind + '" data-v="' + value + '">' + label + '</button>';
        }

        // Binubuo ulit ang field at ang tatlong hanay batay sa napiling araw at oras
        function syncSelectors() {
            const starts = validStarts();
            let cur = timeInput.value ? toMinutes(timeInput.value) : null;
            if (cur !== null && starts.indexOf(cur) === -1) { cur = null; timeInput.value = ''; }
            const hasAM = starts.some(function (m) { return m < 720; });
            const hasPM = starts.some(function (m) { return m >= 720; });
            if (cur !== null) { period = cur < 720 ? 'AM' : 'PM'; selHour = Math.floor(cur / 60); }
            if ((period === 'AM' && !hasAM) || (period === 'PM' && !hasPM) || !period) period = hasAM ? 'AM' : (hasPM ? 'PM' : null);
            const inPeriod = starts.filter(function (m) { return period === 'AM' ? m < 720 : m >= 720; });
            const hours = [];
            inPeriod.forEach(function (m) { const h = Math.floor(m / 60); if (hours.indexOf(h) === -1) hours.push(h); });
            if (hours.indexOf(selHour) === -1) selHour = null;
            const mins = selHour === null ? [] : inPeriod.filter(function (m) { return Math.floor(m / 60) === selHour; });

            colH.innerHTML = hours.map(function (h) { return item(pad(h12(h * 60)), h, h === selHour, 'h'); }).join('');
            colM.innerHTML = mins.length
                ? mins.map(function (m) { return item(pad(m % 60), m, m === cur, 'm'); }).join('')
                : '<span class="ip-tnote">Hour first</span>';
            colP.innerHTML = (hasAM ? item('AM', 'AM', period === 'AM', 'p') : '') + (hasPM ? item('PM', 'PM', period === 'PM', 'p') : '');

            tVal.textContent = cur !== null ? pad(h12(cur)) + ':' + pad(cur % 60) + ' ' + (cur < 720 ? 'AM' : 'PM') : '--:-- --';
            tVal.classList.toggle('empty', cur === null);
            tBtn.disabled = !starts.length;
        }

        function openPop(open) {
            tPop.hidden = !open;
            tBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                syncSelectors();

                tPop.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                [colH, colM, colP].forEach(function (c) {
                    const on = c.querySelector('.on');
                    if (on) c.scrollTop = on.offsetTop - c.clientHeight / 2 + on.offsetHeight / 2;
                });
            }
        }
        tBtn.addEventListener('click', function () { openPop(tPop.hidden); });
        document.addEventListener('click', function (e) { if (!tPop.hidden && !e.target.closest('.ip-tfield')) openPop(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !tPop.hidden) openPop(false); });


        tPop.addEventListener('click', function (e) {
            // Binubuo ulit ang listahan pagkapili, kaya huwag nang ipasa ang click sa
            // document (akala nito nasa labas ang pinindot at isasara ang listahan)
            e.stopPropagation();
            const b = e.target.closest('.ip-titem');
            if (!b) return;
            const starts = validStarts();
            if (b.dataset.k === 'p') {
                // Lipat ng AM/PM: unang puwedeng oras sa bahaging iyon
                period = b.dataset.v;
                const first = starts.find(function (m) { return period === 'AM' ? m < 720 : m >= 720; });
                selHour = null;
                setTime(first === undefined ? null : first);
            } else if (b.dataset.k === 'h') {
                // Pagpili ng oras: kusang pinipili ang unang puwedeng minuto nito
                selHour = parseInt(b.dataset.v, 10);
                const first = starts.find(function (m) { return Math.floor(m / 60) === selHour; });
                setTime(first === undefined ? null : first);
            } else {
                setTime(parseInt(b.dataset.v, 10));
                openPop(false);
            }
        });
        timeInput.addEventListener('input', function () { commit(false); });
        timeInput.addEventListener('change', function () {
            commit(true);
            timeValue.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // Kinukuha ulit ang mga oras na kinuha na, para makita agad kung may naunang nagpasa
        function refreshTaken() {
            const url = 'interview_taken.php' + (picker.dataset.exclude ? '?exclude=' + encodeURIComponent(picker.dataset.exclude) : '');
            fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || typeof data.taken !== 'object') return;
                    taken = Array.isArray(data.taken) ? {} : data.taken;
                    renderCalendar();
                    renderTime();
                    if (timeInput.value) commit(true);
                })
                .catch(function () { /* susubukan ulit sa susunod */ });
        }
        setInterval(function () { if (!document.hidden && !panel.hidden) refreshTaken(); }, 15000);

        picker._ip = {
            validate: function () {
                const ok = commit(true);
                if (!ok) {
                    setOpen(true);
                    picker.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return ok;
            },
            // Para sa draft ng form: ibalik ang dating napili
            restore: function (dateStr, timeStr) {
                if (!isSelectable(dateStr)) {
                    // Lumipas na o hindi na bukas ang dating napiling araw
                    if (dateStr) showError('Your previous interview date (' + longDate(dateStr) + ') is no longer available. Please choose a new date and time.');
                    return;
                }
                timeInput.value = timeStr || '';
                viewYear = parseDate(dateStr).getFullYear();
                viewMonth = parseDate(dateStr).getMonth();
                selectDate(dateStr, false);
            }
        };

        renderCalendar();
        renderTime();
        updateIcon();
        if (selected) commit(false);
    }

    // Pati ang mga picker na nasa ibaba ng script (hal. rebooking popup)
    function initAll() {
        document.querySelectorAll('.ip-picker').forEach(function (p) { if (!p._ip) init(p); });
    }
    initAll();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);

    return {
        validate: function (picker) { return picker && picker._ip ? picker._ip.validate() : true; },
        restore: function (picker, dateStr, timeStr) { if (picker && picker._ip) picker._ip.restore(dateStr, timeStr); }
    };
})();
