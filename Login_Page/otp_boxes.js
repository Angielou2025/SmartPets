// 6 na kahon ng OTP (gamit ng verify_email.php at reset_password.php).
// Lumilipat sa susunod habang nagta-type, bumabalik sa Backspace, at napupuno
// lahat kapag in-paste o in-autofill ang buong code. Ang buong code ay inilalagay
// sa hidden input na itinuro ng data-target.
// Gamit: <div class="otp-boxes" data-target="idNgHiddenInput"> + 6 na .otp-box
//
// Kapag may data-ajax-otp ang form, sinusuri ang code nang hindi umaalis sa page:
//  - tama: dumudulas ang 6 na kahon sa gitna, nagsasama sa iisang bilog, at
//          iginuguhit ang check - saka lilipat sa susunod na page
//  - mali: namumula at umuuga ang mga kahon, at lumalabas ang error
(function () {
    var CHECK_SVG =
        '<svg viewBox="0 0 52 52" aria-hidden="true">' +
            '<circle class="otp-check-circle" cx="26" cy="26" r="24"/>' +
            '<path class="otp-check-mark" d="M15 27.5l7.5 7.5 15-16"/>' +
        '</svg>';

    document.querySelectorAll('.otp-boxes[data-target]').forEach(function (group) {
        var boxes = Array.prototype.slice.call(group.querySelectorAll('.otp-box'));
        var hidden = document.getElementById(group.dataset.target);
        var form = hidden.form;
        var useAjax = form.hasAttribute('data-ajax-otp') && window.fetch && window.FormData;
        var busy = false;

        function sync() {
            hidden.value = boxes.map(function (b) { return b.value; }).join('');
            boxes.forEach(function (b) { b.classList.toggle('filled', b.value !== ''); });
            group.classList.remove('error');
        }

        function fillFrom(start, digits) {
            digits.split('').forEach(function (d, i) {
                if (boxes[start + i]) boxes[start + i].value = d;
            });
            boxes[Math.min(start + digits.length, boxes.length - 1)].focus();
            sync();
        }

        function shake() {
            group.classList.remove('shake');
            void group.offsetWidth; // para maulit ang animation
            group.classList.add('shake');
            setTimeout(function () { group.classList.remove('shake'); }, 400);
        }

        boxes.forEach(function (box, i) {
            box.addEventListener('input', function () {
                var digits = box.value.replace(/\D/g, '');
                box.value = '';
                if (digits) fillFrom(i, digits.slice(0, boxes.length - i));
                else sync();
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && box.value === '' && i > 0) {
                    boxes[i - 1].value = '';
                    boxes[i - 1].focus();
                    sync();
                    e.preventDefault();
                } else if (e.key === 'ArrowLeft' && i > 0) {
                    boxes[i - 1].focus();
                } else if (e.key === 'ArrowRight' && i < boxes.length - 1) {
                    boxes[i + 1].focus();
                }
            });

            box.addEventListener('paste', function (e) {
                var digits = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                if (!digits) return;
                e.preventDefault();
                fillFrom(i, digits.slice(0, boxes.length - i));
            });

            box.addEventListener('focus', function () { box.select(); });
        });

        // ---- Mensahe ng error sa itaas ng form ----
        function showError(message) {
            var box = form.parentNode.querySelector('.error');
            if (!box) {
                box = document.createElement('div');
                box.className = 'error';
                form.parentNode.insertBefore(box, form);
            }
            box.textContent = message;
            box.style.display = '';
            var ok = form.parentNode.querySelector('.success');
            if (ok) ok.style.display = 'none';
        }

        // ---- Tamang code: nagsasamang bilog + check ----
        function playSuccess(redirect, button) {
            var groupRect = group.getBoundingClientRect();
            var centerX = groupRect.left + groupRect.width / 2;

            // Gaano kalayo ang bawat kahon mula sa gitna
            boxes.forEach(function (b, i) {
                var r = b.getBoundingClientRect();
                b.style.setProperty('--to', (centerX - (r.left + r.width / 2)).toFixed(1) + 'px');
                b.style.transitionDelay = (Math.abs(i - (boxes.length - 1) / 2) * 0.04).toFixed(2) + 's';
                b.blur();
            });

            var check = document.createElement('div');
            check.className = 'otp-check';
            check.innerHTML = CHECK_SVG;
            group.appendChild(check);

            var label = document.createElement('p');
            label.className = 'otp-verified';
            label.textContent = 'Verified!';
            group.insertAdjacentElement('afterend', label);

            group.classList.add('merging');
            setTimeout(function () { group.classList.add('merged'); }, 520);
            setTimeout(function () { label.classList.add('show'); }, 900);

            if (button) {
                button.textContent = 'Verified ✓';
                button.classList.add('is-verified');
            }

            // data-otp-event: hindi lilipat ng page - magpapadala lang ng
            // "otp:verified" event (hal. sa Account Settings modal)
            setTimeout(function () {
                if (form.hasAttribute('data-otp-event')) {
                    busy = false; // puwedeng gamitin ulit (hal. buksan ulit ang modal)
                    form.dispatchEvent(new CustomEvent('otp:verified', { bubbles: true }));
                } else {
                    window.location.href = redirect || 'login.php';
                }
            }, 2000);
        }

        // ---- Maling code ----
        function playError(message) {
            showError(message || 'Incorrect code. Please try again.');
            group.classList.add('error');
            shake();
            setTimeout(function () {
                boxes.forEach(function (b) { b.value = ''; });
                sync();
                group.classList.add('error'); // manatiling pula hanggang mag-type ulit
                boxes[0].focus();
            }, 450);
        }

        form.addEventListener('submit', function (e) {
            sync();

            // Hindi ipapasa hangga't hindi buo ang 6 na numero - uuga ang mga kahon
            if (!/^\d{6}$/.test(hidden.value)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                var empty = boxes.filter(function (b) { return b.value === ''; })[0];
                (empty || boxes[0]).focus();
                shake();
                return;
            }

            if (!useAjax) return;
            e.preventDefault();
            if (busy) return;

            var submitter = e.submitter || form.querySelector('[type="submit"]');

            // Hintayin muna ang ibang check (hal. password checklist) bago ipadala
            setTimeout(function () {
                if (form.dataset.pwInvalid) return;

                busy = true;
                var data = new FormData(form);
                if (submitter && submitter.name) data.append(submitter.name, submitter.value || '1');
                data.append('ajax', '1');

                var originalText = submitter ? submitter.textContent : '';
                if (submitter) {
                    submitter.disabled = true;
                    submitter.textContent = 'Checking...';
                }
                group.classList.add('checking');

                fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (result) {
                        group.classList.remove('checking');
                        if (result.ok) {
                            playSuccess(result.redirect, submitter);
                            return;
                        }
                        if (result.redirect) {
                            window.location.href = result.redirect;
                            return;
                        }
                        busy = false;
                        if (submitter) {
                            submitter.disabled = false;
                            submitter.textContent = originalText;
                        }
                        playError(result.error);
                    })
                    .catch(function () {
                        // Hindi JSON ang sagot (hal. natapos ang session) - i-reload na lang
                        window.location.reload();
                    });
            }, 0);
        });
    });
})();
