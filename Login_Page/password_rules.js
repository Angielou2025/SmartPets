// Live na checklist ng password (parang sa Foodpanda): lumulutang ito sa ilalim
// ng password field habang nagta-type, at nagiging check ang bawat natupad na
// panuntunan. Hindi ito kumukuha ng espasyo - nakapatong lang sa ibang laman.
// Gamit: <input type="password" data-password-rules data-confirm="idNgConfirmInput">
// Dapat tugma ang mga panuntunan dito sa server check ng register.php at
// reset_password_handler.php.
(function () {
    var RULES = [
        { label: 'At least 8 characters', test: function (v) { return v.length >= 8; } },
        { label: 'One uppercase letter (A-Z)', test: function (v) { return /[A-Z]/.test(v); } },
        { label: 'One lowercase letter (a-z)', test: function (v) { return /[a-z]/.test(v); } },
        { label: 'One number (0-9)', test: function (v) { return /[0-9]/.test(v); } },
        { label: 'One special character (!@#$%^&*)', test: function (v) { return /[\W_]/.test(v); } }
    ];

    var CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>';
    var DOT = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.2"/></svg>';

    var style = document.createElement('style');
    style.textContent =
        '.pw-pop{position:absolute;left:0;right:0;top:calc(100% + 10px);z-index:30;list-style:none;margin:0;' +
            'padding:12px 14px;display:grid;gap:7px;text-align:left;font-size:13px;line-height:1.3;' +
            'background:#fff;border:1px solid #e3e3e3;border-radius:12px;box-shadow:0 12px 28px rgba(0,0,0,.14);' +
            'opacity:0;visibility:hidden;transform:translateY(-6px);transition:opacity .18s ease,transform .18s ease,visibility .18s}' +
        '.pw-pop::before{content:"";position:absolute;top:-7px;left:28px;width:12px;height:12px;background:#fff;' +
            'border-left:1px solid #e3e3e3;border-top:1px solid #e3e3e3;transform:rotate(45deg)}' +
        '.pw-pop.show{opacity:1;visibility:visible;transform:none}' +
        '.pw-pop li{display:flex;align-items:center;gap:8px;color:#8a8a8a;transition:color .2s}' +
        '.pw-pop .pw-icon{width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;' +
            'background:#ececec;flex-shrink:0;transition:background .2s,transform .2s}' +
        '.pw-pop svg{width:12px;height:12px;fill:none;stroke:#9a9a9a;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}' +
        '.pw-pop li svg circle{fill:#9a9a9a;stroke:none}' +
        '.pw-pop li.ok{color:#2f7a35}' +
        '.pw-pop li.ok .pw-icon{background:#3f6f2c;transform:scale(1.08)}' +
        '.pw-pop li.ok svg{stroke:#fff}' +
        '.pw-pop li.bad{color:#c0392b}' +
        '.pw-pop li.bad .pw-icon{background:#fde3e0}' +
        '.pw-pop li.bad svg circle{fill:#c0392b}' +
        '@media (prefers-reduced-motion: reduce){.pw-pop,.pw-pop li,.pw-pop .pw-icon{transition:none}}';
    document.head.appendChild(style);

    // Ang popup ay nakakabit sa mismong lalagyan ng input para sumunod sa puwesto nito
    function buildPop(labels, input) {
        var host = input.parentElement;
        if (getComputedStyle(host).position === 'static') host.style.position = 'relative';

        var ul = document.createElement('ul');
        ul.className = 'pw-pop';
        ul.setAttribute('aria-live', 'polite');
        labels.forEach(function (label) {
            var li = document.createElement('li');
            li.innerHTML = '<span class="pw-icon">' + DOT + '</span><span>' + label + '</span>';
            ul.appendChild(li);
        });
        host.appendChild(ul);
        return ul;
    }

    function setState(li, ok, showBad) {
        li.classList.toggle('ok', ok);
        li.classList.toggle('bad', !ok && showBad);
        li.querySelector('.pw-icon').innerHTML = ok ? CHECK : DOT;
    }

    // Lalabas lang kapag nakatutok sa field at may naitype na
    function bindVisibility(field, pop) {
        function refresh() {
            pop.classList.toggle('show', document.activeElement === field && field.value !== '');
        }
        field.addEventListener('input', refresh);
        field.addEventListener('focus', refresh);
        field.addEventListener('blur', function () { pop.classList.remove('show'); });
        field.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') pop.classList.remove('show');
        });
    }

    document.querySelectorAll('input[data-password-rules]').forEach(function (input) {
        var pop = buildPop(RULES.map(function (r) { return r.label; }), input);
        var items = pop.querySelectorAll('li');
        bindVisibility(input, pop);

        var confirm = input.dataset.confirm ? document.getElementById(input.dataset.confirm) : null;
        var matchItem = null;
        if (confirm) {
            var matchPop = buildPop(['Passwords match'], confirm);
            matchItem = matchPop.querySelector('li');
            bindVisibility(confirm, matchPop);
        }
        var submitted = false;

        function allOk() {
            return RULES.every(function (r) { return r.test(input.value); });
        }

        function update() {
            RULES.forEach(function (r, i) { setState(items[i], r.test(input.value), submitted); });
            if (matchItem) {
                var matches = confirm.value !== '' && confirm.value === input.value;
                setState(matchItem, matches, true);
            }
        }

        input.addEventListener('input', update);
        if (confirm) confirm.addEventListener('input', update);

        // Hindi ipapasa ang form hangga't hindi kumpleto - ipapakita ang kulang
        if (input.form) {
            input.form.addEventListener('submit', function (e) {
                submitted = true;
                update();
                // Para malaman ng otp_boxes.js kung puwedeng ipadala ang form
                delete input.form.dataset.pwInvalid;
                if (!allOk()) {
                    e.preventDefault();
                    input.form.dataset.pwInvalid = '1';
                    input.focus();
                    pop.classList.add('show');
                } else if (confirm && confirm.value !== input.value) {
                    e.preventDefault();
                    input.form.dataset.pwInvalid = '1';
                    confirm.focus();
                    confirm.dispatchEvent(new Event('input'));
                }
            });
        }

        update();
    });
})();
