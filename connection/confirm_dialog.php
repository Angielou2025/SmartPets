<?php
/**
 * Magandang confirmation popup (nasa gitna ng screen) kapalit ng default na
 * "localhost says" ng browser. Isinasama ng connection/chat_bubble.php kaya
 * magagamit sa kahit anong page.
 *
 * Gamit sa form:
 *   <form ... data-confirm="Mensahe"
 *            data-confirm-title="Title"            (opsyonal)
 *            data-confirm-ok="Yes, continue"       (opsyonal)
 *            data-confirm-icon="fa-stamp"          (opsyonal, Font Awesome; o emoji)
 *            data-confirm-tone="green|orange|red"> (opsyonal)
 */
if (!empty($GLOBALS['sp_confirm_dialog_rendered'])) {
    return;
}
$GLOBALS['sp_confirm_dialog_rendered'] = true;
?>
<div class="spc-backdrop" id="spConfirm" role="alertdialog" aria-modal="true" aria-labelledby="spcTitle" aria-describedby="spcMessage" hidden>
    <div class="spc-box">
        <div class="spc-icon" id="spcIcon" aria-hidden="true"></div>
        <h3 id="spcTitle">Are you sure?</h3>
        <p id="spcMessage"></p>
        <div class="spc-actions">
            <button type="button" class="spc-cancel" id="spcCancel">Cancel</button>
            <button type="button" class="spc-ok" id="spcOk">Confirm</button>
        </div>
    </div>
</div>

<style>
    .spc-backdrop {
        position: fixed;
        inset: 0;
        z-index: 6000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(12, 28, 20, 0.5);
        backdrop-filter: blur(3px);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        animation: spcFade .2s ease;
    }
    .spc-backdrop[hidden] { display: none; }
    .spc-backdrop.closing { animation: spcFadeOut .18s ease forwards; }

    .spc-box {
        width: 100%;
        max-width: 400px;
        padding: 28px 26px 22px;
        border-radius: 20px;
        background: #fff;
        text-align: center;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
        animation: spcPop .35s cubic-bezier(.34, 1.56, .64, 1);
    }
    .spc-backdrop.closing .spc-box { animation: spcSink .18s ease forwards; }

    .spc-icon {
        --spc: #1f6b48;
        --spc-soft: #e6f4ea;
        width: 64px;
        height: 64px;
        margin: 0 auto 14px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        color: var(--spc);
        background: var(--spc-soft);
        box-shadow: 0 0 0 7px color-mix(in srgb, var(--spc-soft) 55%, transparent);
    }
    .spc-icon.tone-orange { --spc: #c56a00; --spc-soft: #fff1dc; }
    .spc-icon.tone-red { --spc: #c5221f; --spc-soft: #fce8e6; }

    .spc-box h3 {
        margin: 0 0 8px;
        font-size: 20px;
        font-weight: 800;
        color: #102a2a;
    }
    .spc-box p {
        margin: 0 0 22px;
        font-size: 14.5px;
        line-height: 1.55;
        color: #4b5563;
    }

    .spc-actions { display: flex; gap: 10px; }
    .spc-actions button {
        flex: 1;
        padding: 12px 14px;
        border-radius: 12px;
        font-family: inherit;
        font-size: 14.5px;
        font-weight: 700;
        cursor: pointer;
        transition: background .15s, transform .1s;
    }
    .spc-actions button:active { transform: translateY(1px); }
    .spc-cancel { border: 1.5px solid #d1d5db; background: #fff; color: #374151; }
    .spc-cancel:hover { background: #f3f4f6; }
    .spc-ok { border: none; background: #1f6b48; color: #fff; }
    .spc-ok:hover { background: #17573a; }
    .spc-ok.tone-orange { background: #f59c23; }
    .spc-ok.tone-orange:hover { background: #e08b18; }
    .spc-ok.tone-red { background: #c5221f; }
    .spc-ok.tone-red:hover { background: #a51c19; }
    .spc-actions button:focus-visible { outline: 3px solid #93c5aa; outline-offset: 2px; }

    @keyframes spcFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes spcFadeOut { to { opacity: 0; } }
    @keyframes spcPop { from { opacity: 0; transform: scale(.85) translateY(14px); } to { opacity: 1; transform: none; } }
    @keyframes spcSink { to { opacity: 0; transform: scale(.95) translateY(8px); } }
    @media (prefers-reduced-motion: reduce) {
        .spc-backdrop, .spc-box { animation: none !important; }
    }
</style>

<script>
(function () {
    var dlg = document.getElementById('spConfirm');
    if (!dlg) return;
    var icon = document.getElementById('spcIcon');
    var title = document.getElementById('spcTitle');
    var msg = document.getElementById('spcMessage');
    var ok = document.getElementById('spcOk');
    var cancel = document.getElementById('spcCancel');
    var pendingForm = null;
    var lastFocus = null;

    function open(form) {
        var tone = form.dataset.confirmTone || 'green';
        var ic = form.dataset.confirmIcon || 'fa-circle-question';
        pendingForm = form;
        lastFocus = document.activeElement;

        title.textContent = form.dataset.confirmTitle || 'Are you sure?';
        msg.textContent = form.dataset.confirm;
        ok.textContent = form.dataset.confirmOk || 'Confirm';
        icon.className = 'spc-icon tone-' + tone;
        ok.className = 'spc-ok tone-' + tone;
        // Font Awesome na icon kung may "fa-", kung hindi ay emoji/text
        icon.innerHTML = ic.indexOf('fa-') === 0 ? '<i class="fa-solid ' + ic + '"></i>' : ic;

        dlg.hidden = false;
        dlg.classList.remove('closing');
        ok.focus();
    }

    function close(then) {
        dlg.classList.add('closing');
        setTimeout(function () {
            dlg.hidden = true;
            dlg.classList.remove('closing');
            if (then) then();
            else if (lastFocus) lastFocus.focus();
        }, 180);
    }

    // Lahat ng form na may data-confirm: hihinto muna para magtanong
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.dataset || !form.dataset.confirm || form.dataset.confirmed === '1') return;
        e.preventDefault();
        open(form);
    }, true);

    ok.addEventListener('click', function () {
        var form = pendingForm;
        pendingForm = null;
        close(function () {
            if (!form) return;
            form.dataset.confirmed = '1';
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    });
    cancel.addEventListener('click', function () { pendingForm = null; close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { pendingForm = null; close(); } });
    document.addEventListener('keydown', function (e) {
        if (dlg.hidden) return;
        if (e.key === 'Escape') { pendingForm = null; close(); }
        // Tab sa loob lang ng dalawang button
        if (e.key === 'Tab') {
            e.preventDefault();
            (document.activeElement === ok ? cancel : ok).focus();
        }
    });
})();
</script>
