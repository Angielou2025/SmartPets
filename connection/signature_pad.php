<?php
/**
 * Pirma (signature) - ginagamit sa Interview Summary Report ng staff at adopter.
 *
 * Dalawang paraan ng pagpirma:
 *   - Draw: pumirma gamit ang daliri (phone/tablet) o mouse.
 *   - Upload: larawan ng pirma sa puting papel. Inaalis ng system ang background
 *     (nagiging transparent ang papel), pinaiitim ang tinta, at tinatabas ang gilid.
 * Ang resulta ay PNG na walang background, ipinapadala bilang data URL sa hidden
 * input, at sine-save ng server sa uploads/signatures/ (sp_save_signature).
 */

/** Sine-save ang PNG na pirma (data URL) at ibinabalik ang file name, o null kapag hindi tama */
function sp_save_signature(?string $dataUrl, string $prefix): ?string
{
    $dataUrl = (string) $dataUrl;
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        return null;
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || strlen($bytes) < 100 || strlen($bytes) > 1024 * 1024) {
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || ($info['mime'] ?? '') !== 'image/png' || $info[0] < 20 || $info[1] < 10) {
        return null;
    }
    $dir = __DIR__ . '/../uploads/signatures';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    $name = preg_replace('/[^a-z0-9_]/i', '', $prefix) . '_' . bin2hex(random_bytes(8)) . '.png';
    return @file_put_contents($dir . '/' . $name, $bytes) !== false ? $name : null;
}

/** URL ng naka-save na pirma (para sa <img>), o '' */
function sp_signature_url(?string $file, string $base = '../'): string
{
    $file = basename((string) $file);
    return ($file !== '' && is_file(__DIR__ . '/../uploads/signatures/' . $file)) ? $base . 'uploads/signatures/' . rawurlencode($file) : '';
}

/** Data URI ng pirma (para sa PDF ng Dompdf na walang remote access), o '' */
function sp_signature_data_uri(?string $file): string
{
    $path = __DIR__ . '/../uploads/signatures/' . basename((string) $file);
    return ($file && is_file($path)) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)) : '';
}

/**
 * HTML ng signature pad.
 *   $name     = pangalan ng hidden input (data URL ng bagong pirma)
 *   $existing = URL ng naka-save nang pirma (ipinapakita; puwedeng palitan)
 *   $theme    = 'green' (adopter) o 'blue' (staff)
 */
function sp_signature_pad(string $name, string $existing = '', string $theme = 'green', bool $required = false): void
{
    static $assets = false;
    $id = 'sig_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '_' . mt_rand(1000, 9999);
    if (!$assets) {
        $assets = true;
        sp_signature_assets();
    }
    ?>
    <div class="sig-pad sig-<?php echo $theme === 'blue' ? 'blue' : 'green'; ?>" id="<?php echo $id; ?>" data-required="<?php echo $required ? '1' : '0'; ?>">
        <?php if ($existing !== ''): ?>
            <div class="sig-current">
                <img src="<?php echo htmlspecialchars($existing); ?>" alt="Saved signature">
                <button type="button" class="sig-change"><i class="fa-solid fa-pen"></i> Change signature</button>
            </div>
        <?php endif; ?>
        <div class="sig-editor"<?php echo $existing !== '' ? ' hidden' : ''; ?>>
            <div class="sig-tabs" role="tablist">
                <button type="button" class="sig-tab active" data-mode="draw" role="tab"><i class="fa-solid fa-signature"></i> Draw</button>
                <button type="button" class="sig-tab" data-mode="upload" role="tab"><i class="fa-solid fa-image"></i> Upload photo</button>
            </div>
            <div class="sig-box">
                <canvas class="sig-canvas" aria-label="Signature area"></canvas>
                <span class="sig-line" aria-hidden="true"></span>
                <span class="sig-placeholder">Sign here using your finger or mouse</span>
            </div>
            <div class="sig-upload" hidden>
                <label class="sig-upload-btn">
                    <input type="file" accept="image/*" class="sig-file">
                    <i class="fa-solid fa-upload"></i> Choose a photo of your signature
                </label>
                <p class="sig-upload-hint">Sign on plain white paper with a dark pen, then take a clear photo. The background is removed automatically.</p>
            </div>
            <div class="sig-actions">
                <button type="button" class="sig-clear"><i class="fa-solid fa-eraser"></i> Clear</button>
                <span class="sig-status" aria-live="polite"></span>
            </div>
        </div>
        <input type="hidden" name="<?php echo htmlspecialchars($name); ?>" class="sig-value">
    </div>
    <?php
}

/** CSS at JavaScript ng signature pad (isang beses lang sa bawat page) */
function sp_signature_assets(): void
{
    ?>
    <style>
        .sig-pad { --sig-accent: #2e5b32; --sig-soft: #eef6f0; --sig-line: #c9d9cd; display: flex; flex-direction: column; gap: 8px; }
        .sig-pad.sig-blue { --sig-accent: #1e3a8a; --sig-soft: #eef3ff; --sig-line: #c9d4ee; }
        .sig-current { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .sig-current img { height: 70px; max-width: 260px; object-fit: contain; border-bottom: 1.5px solid #374151; padding: 0 8px; background: #fff; }
        .sig-change, .sig-clear, .sig-tab {
            display: inline-flex; align-items: center; gap: 6px; border-radius: 8px; font-family: inherit; font-size: 12.5px;
            font-weight: 700; cursor: pointer; padding: 6px 12px; border: 1px solid var(--sig-line); background: #fff; color: var(--sig-accent);
        }
        .sig-tabs { display: inline-flex; gap: 6px; }
        .sig-tab.active { background: var(--sig-accent); border-color: var(--sig-accent); color: #fff; }
        .sig-box { position: relative; height: 150px; border: 1.5px dashed var(--sig-line); border-radius: 12px; background: #fff; touch-action: none; overflow: hidden; }
        .sig-box.has-ink .sig-placeholder { display: none; }
        .sig-canvas { position: absolute; inset: 0; width: 100%; height: 100%; cursor: crosshair; touch-action: none; }
        .sig-line { position: absolute; left: 24px; right: 24px; bottom: 34px; border-bottom: 1.5px solid #9ca3af; pointer-events: none; }
        .sig-placeholder { position: absolute; left: 0; right: 0; bottom: 42px; text-align: center; font-size: 13px; color: #9ca3af; pointer-events: none; }
        .sig-upload[hidden] { display: none; }
        .sig-upload-btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 9px; background: var(--sig-soft); color: var(--sig-accent); font-weight: 700; font-size: 13px; cursor: pointer; }
        .sig-upload-btn input { display: none; }
        .sig-upload-hint { margin: 6px 0 0; font-size: 12px; color: #6b7280; line-height: 1.45; }
        .sig-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .sig-status { font-size: 12.5px; color: #6b7280; }
        .sig-status.err { color: #c62828; font-weight: 600; }
        .sig-pad.is-invalid .sig-box { border-color: #e53935; }
    </style>
    <script>
    (function () {
        // Pinaiitim ang tinta at ginagawang transparent ang papel; tinatabas sa paligid ng pirma
        function cleanSignature(img) {
            var maxW = 900, s = Math.min(1, maxW / Math.max(img.naturalWidth, img.naturalHeight));
            var w = Math.round(img.naturalWidth * s), h = Math.round(img.naturalHeight * s);
            var c = document.createElement('canvas'); c.width = w; c.height = h;
            var x = c.getContext('2d'); x.drawImage(img, 0, 0, w, h);
            var d = x.getImageData(0, 0, w, h), p = d.data, n = w * h, lum = new Float32Array(n), sum = 0;
            for (var i = 0; i < n; i++) { lum[i] = 0.299 * p[i * 4] + 0.587 * p[i * 4 + 1] + 0.114 * p[i * 4 + 2]; sum += lum[i]; }
            // Liwanag ng papel = karaniwang liwanag; ang tinta ay mas madilim nang malaki
            var paper = sum / n, cut = paper * 0.72, soft = paper * 0.86;
            var minX = w, minY = h, maxX = -1, maxY = -1;
            for (var j = 0; j < n; j++) {
                var L = lum[j], a = 0;
                if (L <= cut) a = 255; else if (L < soft) a = Math.round(255 * (soft - L) / (soft - cut));
                p[j * 4] = 17; p[j * 4 + 1] = 24; p[j * 4 + 2] = 39; p[j * 4 + 3] = a;
                if (a > 60) { var px = j % w, py = (j / w) | 0; if (px < minX) minX = px; if (px > maxX) maxX = px; if (py < minY) minY = py; if (py > maxY) maxY = py; }
            }
            if (maxX < 0) return null;
            x.putImageData(d, 0, 0);
            var pad = 10; minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
            maxX = Math.min(w - 1, maxX + pad); maxY = Math.min(h - 1, maxY + pad);
            var out = document.createElement('canvas'); out.width = maxX - minX + 1; out.height = maxY - minY + 1;
            out.getContext('2d').drawImage(c, minX, minY, out.width, out.height, 0, 0, out.width, out.height);
            // Halos buong larawan ang "tinta" = hindi pirma sa puting papel
            var inkShare = ((maxX - minX) * (maxY - minY)) / (w * h);
            return { canvas: out, tooBusy: inkShare > 0.9 };
        }

        // Tinatabas ang drawing sa paligid ng mga guhit
        function trimCanvas(src) {
            var w = src.width, h = src.height, d = src.getContext('2d').getImageData(0, 0, w, h).data;
            var minX = w, minY = h, maxX = -1, maxY = -1;
            for (var y = 0; y < h; y++) for (var x = 0; x < w; x++) {
                if (d[(y * w + x) * 4 + 3] > 20) { if (x < minX) minX = x; if (x > maxX) maxX = x; if (y < minY) minY = y; if (y > maxY) maxY = y; }
            }
            if (maxX < 0) return null;
            var pad = 8; minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
            maxX = Math.min(w - 1, maxX + pad); maxY = Math.min(h - 1, maxY + pad);
            var out = document.createElement('canvas'); out.width = maxX - minX + 1; out.height = maxY - minY + 1;
            out.getContext('2d').drawImage(src, minX, minY, out.width, out.height, 0, 0, out.width, out.height);
            return out;
        }

        function init(root) {
            if (root._sig) return; root._sig = true;
            var value = root.querySelector('.sig-value');
            var editor = root.querySelector('.sig-editor');
            var box = root.querySelector('.sig-box');
            var canvas = root.querySelector('.sig-canvas');
            var upload = root.querySelector('.sig-upload');
            var status = root.querySelector('.sig-status');
            var ctx = canvas.getContext('2d');
            var drawing = false, last = null, mode = 'draw', hasInk = false;

            function setStatus(msg, err) { status.textContent = msg || ''; status.classList.toggle('err', !!err); }

            function fit() {
                var r = box.getBoundingClientRect(), ratio = window.devicePixelRatio || 1;
                if (!r.width) return;
                var keep = hasInk ? canvas.toDataURL() : null;
                canvas.width = Math.round(r.width * ratio); canvas.height = Math.round(r.height * ratio);
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111827'; ctx.lineWidth = 2.4;
                if (keep) { var im = new Image(); im.onload = function () { ctx.drawImage(im, 0, 0, r.width, r.height); }; im.src = keep; }
            }

            function pos(e) { var r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }

            function commitDrawing() {
                var t = trimCanvas(canvas);
                value.value = t ? t.toDataURL('image/png') : '';
                root.classList.remove('is-invalid');
            }

            function clearAll() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasInk = false; box.classList.remove('has-ink'); value.value = ''; setStatus('');
                var f = root.querySelector('.sig-file'); if (f) f.value = '';
            }

            canvas.addEventListener('pointerdown', function (e) {
                if (mode !== 'draw') return;
                drawing = true; last = pos(e);
                try { canvas.setPointerCapture(e.pointerId); } catch (err) { /* hal. lumang browser */ }
                ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2); ctx.fillStyle = '#111827'; ctx.fill();
                hasInk = true; box.classList.add('has-ink'); e.preventDefault();
            });
            canvas.addEventListener('pointermove', function (e) {
                if (!drawing) return;
                var p = pos(e);
                ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
                last = p; e.preventDefault();
            });
            ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
                canvas.addEventListener(ev, function () { if (drawing) { drawing = false; commitDrawing(); } });
            });

            root.querySelectorAll('.sig-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    mode = tab.dataset.mode;
                    root.querySelectorAll('.sig-tab').forEach(function (t) { t.classList.toggle('active', t === tab); });
                    upload.hidden = mode !== 'upload';
                    canvas.style.cursor = mode === 'draw' ? 'crosshair' : 'default';
                    root.querySelector('.sig-placeholder').textContent = mode === 'draw'
                        ? 'Sign here using your finger or mouse' : 'Your cleaned signature will appear here';
                    clearAll();
                });
            });

            root.querySelector('.sig-clear').addEventListener('click', clearAll);

            var fileInput = root.querySelector('.sig-file');
            fileInput.addEventListener('change', function () {
                var file = fileInput.files[0];
                if (!file) return;
                if (file.size > 10 * 1024 * 1024) { setStatus('This photo is larger than 10MB.', true); return; }
                setStatus('Removing the background...');
                var url = URL.createObjectURL(file), img = new Image();
                img.onload = function () {
                    URL.revokeObjectURL(url);
                    var res = cleanSignature(img);
                    if (!res || res.tooBusy) {
                        clearAll();
                        setStatus('No clear signature was found. Sign on plain white paper with a dark pen and take the photo again.', true);
                        return;
                    }
                    var out = res.canvas;
                    // Ipinapakita ang malinis na pirma sa gitna ng kahon
                    var r = box.getBoundingClientRect();
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    var sc = Math.min((r.width - 40) / out.width, (r.height - 30) / out.height, 1);
                    var dw = out.width * sc, dh = out.height * sc;
                    ctx.drawImage(out, (r.width - dw) / 2, (r.height - dh) / 2 - 6, dw, dh);
                    hasInk = true; box.classList.add('has-ink');
                    value.value = out.toDataURL('image/png');
                    root.classList.remove('is-invalid');
                    setStatus('Background removed. Check that your signature looks right.');
                };
                img.onerror = function () { URL.revokeObjectURL(url); setStatus('This photo could not be opened. Please use a JPG or PNG photo.', true); };
                img.src = url;
            });

            var change = root.querySelector('.sig-change');
            if (change) change.addEventListener('click', function () {
                root.querySelector('.sig-current').hidden = true;
                editor.hidden = false;
                root.dataset.required = '1';
                fit();
            });

            // Kailangan ng pirma bago i-submit (kapag required at wala pang naka-save)
            var form = root.closest('form');
            if (form) form.addEventListener('submit', function (e) {
                if (root.dataset.required === '1' && !editor.hidden && !value.value) {
                    e.preventDefault(); e.stopImmediatePropagation();
                    root.classList.add('is-invalid');
                    setStatus('Please add your signature.', true);
                    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, true);

            window.addEventListener('resize', fit);
            if ('ResizeObserver' in window) new ResizeObserver(fit).observe(box);
            fit();
        }

        function initAll() { document.querySelectorAll('.sig-pad').forEach(init); }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll); else initAll();
        window.SpSignature = { initAll: initAll };
    })();
    </script>
    <?php
}
