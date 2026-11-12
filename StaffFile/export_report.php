<?php
/**
 * Export ng records - Pet Inventory at Payments.
 *
 * Pumipili ang staff ng saklaw ng petsa (This Week / This Month / This Year /
 * Custom / All), pagkatapos ay makikita niya agad ang aktwal na report bago
 * i-download bilang PDF (kasama kung sino ang nag-print at kung kanino/saan galing).
 *
 * Nasa browser ginagawa ang file (html2canvas + jsPDF) dahil walang naka-install
 * na PDF library sa PHP dito. Ibig sabihin, kung ano ang nasa preview ay siya
 * ring eksaktong lalabas sa na-download na file.
 */
session_start();
require_once __DIR__ . '/../connection/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true)) {
    header('Location: /Caps2/Login_Page/login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$userStmt = $pdo->prepare('SELECT username FROM users WHERE user_id = ?');
$userStmt->execute([$userId]);
$username = $userStmt->fetchColumn() ?: 'Staff Member';

$isAdmin = ($_SESSION['role'] ?? '') === 'Admin';

// --- Anong record ang ile-export ---
$type = $_GET['type'] ?? 'pets';
if (!in_array($type, ['pets', 'payments'], true)) {
    $type = 'pets';
}

// --- Saklaw ng petsa ---
$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['week', 'month', 'year', 'custom', 'all'], true)) {
    $range = 'month';
}

$today = date('Y-m-d');
switch ($range) {
    case 'week':
        // Lunes hanggang Linggo ng kasalukuyang linggo
        $from = date('Y-m-d', strtotime('monday this week'));
        $to = date('Y-m-d', strtotime('sunday this week'));
        $rangeLabel = 'This Week';
        break;
    case 'year':
        $from = date('Y-01-01');
        $to = date('Y-12-31');
        $rangeLabel = 'This Year';
        break;
    case 'custom':
        $from = $_GET['from'] ?? $today;
        $to = $_GET['to'] ?? $today;
        // Huwag paniwalaan ang laman ng URL - dapat totoong petsa talaga
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = $today; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = $today; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        $rangeLabel = 'Custom Range';
        break;
    case 'all':
        $from = '1970-01-01';
        $to = '2999-12-31';
        $rangeLabel = 'All Records';
        break;
    default:
        $range = 'month';
        $from = date('Y-m-01');
        $to = date('Y-m-t');
        $rangeLabel = 'This Month';
        break;
}

$periodText = $range === 'all'
    ? 'All records to date'
    : date('F j, Y', strtotime($from)) . ' to ' . date('F j, Y', strtotime($to));

// --- Kunin ang mga record ---
$rows = [];
$columns = [];
$totalAmount = 0.0;

if ($type === 'pets') {
    $reportTitle = 'Pet Inventory Records';
    $columns = ['Pet ID', 'Name', 'Species', 'Breed', 'Age Group', 'Gender', 'Status', 'Date Registered'];
    try {
        $stmt = $pdo->prepare(
            "SELECT pet_id, name, kind, breed, age_group, gender, status, created_at
               FROM pet_inventory
              WHERE DATE(created_at) BETWEEN ? AND ?
              ORDER BY pet_id DESC"
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt as $r) {
            $rows[] = [
                str_pad((string) $r['pet_id'], 3, '0', STR_PAD_LEFT),
                $r['name'],
                $r['kind'],
                $r['breed'],
                $r['age_group'],
                $r['gender'],
                $r['status'],
                date('M j, Y', strtotime($r['created_at'])),
            ];
        }
    } catch (Exception $e) {
        $rows = [];
    }
} else {
    $reportTitle = 'Payment Records';
    $columns = ['Payment ID', 'Adopter', 'Pet', 'Amount', 'Method', 'Reference No.', 'Status', 'Date'];
    try {
        $stmt = $pdo->prepare(
            "SELECT pay.payment_id, pay.amount, pay.method, pay.reference_number,
                    pay.status, pay.payment_date,
                    aa.full_name AS adopter_name, p.name AS pet_name
               FROM payments pay
               JOIN adoption_applications aa ON aa.application_id = pay.application_id
               JOIN pet_inventory p ON p.pet_id = pay.pet_id
              WHERE DATE(pay.payment_date) BETWEEN ? AND ?
              ORDER BY pay.payment_id DESC"
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt as $r) {
            // Ang kabuuan ay ang aktwal na natanggap lang - hindi kasama ang
            // Pending at Failed, para hindi mapalaki ang ireport na koleksyon.
            if ($r['status'] === 'Paid') {
                $totalAmount += (float) $r['amount'];
            }
            $rows[] = [
                'PAY-' . str_pad((string) $r['payment_id'], 3, '0', STR_PAD_LEFT),
                $r['adopter_name'],
                $r['pet_name'],
                'PHP ' . number_format((float) $r['amount'], 2),
                $r['method'] ?: '-',
                $r['reference_number'] ?: '-',
                $r['status'],
                date('M j, Y', strtotime($r['payment_date'])),
            ];
        }
    } catch (Exception $e) {
        $rows = [];
    }
}

$backUrl = $type === 'pets'
    ? ($isAdmin ? '/Caps2/AdminFile/manage_pets.php' : '/Caps2/StaffFile/pet_inventory.php')
    : ($isAdmin ? '/Caps2/AdminFile/payments.php' : '/Caps2/StaffFile/payments.php');

// Pangalan ng file na ida-download
$fileBase = ($type === 'pets' ? 'SMART_PET_Pet_Records_' : 'SMART_PET_Payment_Records_')
    . ($range === 'all' ? 'All' : $from . '_to_' . $to);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Export Records - Smart Pet</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: #eef1f4; padding: 24px 16px 60px; color: #1f2937; }

        .ex-shell { max-width: 1000px; margin: 0 auto; }

        /* ---- Control panel: saklaw ng petsa at mga download button ---- */
        .ex-controls {
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 6px 18px rgba(16, 24, 40, 0.07);
            margin-bottom: 20px;
        }
        .ex-controls h2 { font-size: 16px; font-weight: 800; margin-bottom: 4px; }
        .ex-controls .ex-hint { font-size: 13px; color: #6b7280; margin-bottom: 16px; }

        .ex-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .ex-field { display: flex; flex-direction: column; gap: 5px; }
        .ex-field label { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .3px; }
        .ex-field select, .ex-field input[type="date"] {
            padding: 9px 12px;
            border: 1px solid #d5d9e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: #1f2937;
            background: #fff;
            min-width: 165px;
        }
        .ex-field select:focus, .ex-field input:focus { outline: none; border-color: #1f6b48; }

        .ex-btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: filter .15s ease, background .15s ease;
        }
        .ex-btn:hover { filter: brightness(1.08); }
        .ex-btn-apply { background: #1f6b48; color: #fff; }
        .ex-btn-pdf { background: #c0392b; color: #fff; }
        .ex-people { margin-top: 14px; }
        .ex-people .ex-wide { flex: 0 1 340px; }
        .ex-people input { width: 100%; box-sizing: border-box; height: 42px; padding: 0 12px; border: 1px solid #d1d5db; border-radius: 10px; font-family: inherit; font-size: 14px; background: #fff; }
        .ex-people input:focus { outline: none; border-color: #1f6b48; box-shadow: 0 0 0 3px rgba(31, 107, 72, .15); }
        .ex-field input.is-missing { border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220, 38, 38, .12); }
        .ex-btn:disabled { opacity: .55; cursor: not-allowed; filter: none; }

        .ex-downloads {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #eef0f3;
        }
        .ex-downloads-label { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; width: 100%; margin-bottom: 2px; letter-spacing: .3px; }

        /* ---- Ang report mismo. Ito ang kinukuhanan ng larawan, kaya nakapirmi
               ang puting background at itim na teksto kahit anong theme. ---- */
        .ex-paper {
            background: #ffffff;
            padding: 34px 38px 40px;
            border-radius: 10px;
            box-shadow: 0 6px 18px rgba(16, 24, 40, 0.07);
            color: #111827;
        }

        .ex-head { display: flex; align-items: center; gap: 14px; border-bottom: 3px solid #1f6b48; padding-bottom: 16px; margin-bottom: 18px; }
        .ex-head img { width: 58px; height: 58px; border-radius: 50%; object-fit: cover; }
        .ex-head .ex-org { font-size: 20px; font-weight: 800; letter-spacing: .5px; }
        .ex-head .ex-org span { color: #e67e22; }
        .ex-head .ex-sub { font-size: 12.5px; color: #6b7280; margin-top: 2px; }
        .ex-head .ex-from { margin-left: auto; align-self: flex-start; max-width: 40%; text-align: right; font-size: 12.5px; color: #374151; overflow-wrap: anywhere; }
        .ex-head .ex-from[hidden] { display: none; }
        .ex-head .ex-from span { color: #6b7280; font-weight: 600; }

        .ex-title { font-size: 19px; font-weight: 800; margin-bottom: 4px; }
        .ex-meta { font-size: 13px; color: #4b5563; line-height: 1.7; margin-bottom: 18px; }
        .ex-meta strong { color: #111827; }

        table.ex-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.ex-table th {
            background: #1f6b48;
            color: #fff;
            text-align: left;
            padding: 9px 10px;
            font-weight: 700;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: .3px;
            border: 1px solid #1f6b48;
        }
        table.ex-table td { padding: 8px 10px; border: 1px solid #dfe3e8; color: #1f2937; }
        table.ex-table tbody tr:nth-child(even) td { background: #f7f9f8; }

        .ex-empty { text-align: center; padding: 34px 10px; color: #6b7280; font-size: 14px; border: 1px solid #dfe3e8; border-top: none; }

        .ex-summary { margin-top: 18px; padding-top: 14px; border-top: 2px solid #1f6b48; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; font-size: 13.5px; }
        .ex-summary strong { font-size: 15px; }

        .ex-foot { margin-top: 26px; padding-top: 12px; border-top: 1px solid #e5e7eb; font-size: 11.5px; color: #6b7280; line-height: 1.6; }

        .ex-status { margin-top: 10px; font-size: 13px; color: #1f6b48; font-weight: 600; min-height: 18px; }

        @media (max-width: 640px) {
            .ex-field select, .ex-field input[type="date"] { min-width: 100%; }
            .ex-field { width: 100%; }

            /* ===== Telepono: buong screen at puti (walang malabong background), × sa kanang itaas ===== */
            html.sp-pb body, body { background: #ffffff !important; padding: 0 0 40px !important; }
            .sp-pb-bg, .sp-pb-ov { display: none !important; }
            .sp-pb-close {
                top: 10px !important; right: 10px !important; width: 38px !important; height: 38px !important;
                font-size: 20px !important; background: #f1f4f2 !important; box-shadow: none !important;
            }
            .ex-shell { max-width: none; }

            /* Control panel: buong lapad, mas maliit na font at button */
            .ex-controls { border-radius: 0; box-shadow: none; padding: 16px 16px 14px; margin-bottom: 12px; border-bottom: 1px solid #eef0f3; }
            .ex-controls h2 { font-size: 15px; padding-right: 48px; }   /* hindi natatakpan ng × */
            .ex-controls .ex-hint { font-size: 12px; line-height: 1.45; margin-bottom: 12px; padding-right: 40px; }
            .ex-row { gap: 8px; }
            .ex-field { gap: 4px; }
            .ex-field label { font-size: 10.5px; }
            .ex-field select, .ex-field input[type="date"] { padding: 8px 10px; font-size: 15px; border-radius: 8px; }
            .ex-btn { width: 100%; justify-content: center; height: 40px; padding: 0 14px; font-size: 13.5px; }
            .ex-people { margin-top: 10px; }
            .ex-people .ex-wide { flex: 1 1 100%; }
            .ex-people input { height: 40px; font-size: 15px; }
            .ex-downloads { margin-top: 12px; padding-top: 12px; }
            .ex-status { font-size: 12px; }

            /* Ang report (papel): nananatiling 760px (kasinlapad ng tunay na papel) at pinapaliit
               ng script (transform: scale) para kasya sa screen - walang pag-scroll pakanan,
               at pareho ang itsura ng preview at ng PDF */
            .ex-paper-fit { margin: 0 12px; overflow: hidden; }
            .ex-paper { width: 760px; padding: 34px 38px 40px; transform-origin: top left; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../connection/print_backdrop.php'; sp_print_backdrop($backUrl); ?>
<div class="ex-shell">

    <?php // Wala nang "Back to ..." link: ang × sa kanang itaas (o Esc) na ang pambalik ?>

    <!-- Pumili ng saklaw -->
    <div class="ex-controls">
        <h2>Export Records</h2>
        <p class="ex-hint">Choose a period, fill in who is printing it and who it is from, then download the PDF. The PDF matches the preview below exactly.</p>

        <form method="GET" id="exForm">
            <div class="ex-row">
                <div class="ex-field">
                    <label for="exType">Record Type</label>
                    <select name="type" id="exType" onchange="document.getElementById('exForm').submit();">
                        <option value="pets" <?php echo $type === 'pets' ? 'selected' : ''; ?>>Pet Inventory</option>
                        <option value="payments" <?php echo $type === 'payments' ? 'selected' : ''; ?>>Payments</option>
                    </select>
                </div>

                <div class="ex-field">
                    <label for="exRange">Period</label>
                    <select name="range" id="exRange" onchange="toggleCustom()">
                        <option value="week" <?php echo $range === 'week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo $range === 'month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="year" <?php echo $range === 'year' ? 'selected' : ''; ?>>This Year</option>
                        <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                        <option value="all" <?php echo $range === 'all' ? 'selected' : ''; ?>>All Records</option>
                    </select>
                </div>

                <div class="ex-field" id="exFromWrap">
                    <label for="exFrom">Start Date</label>
                    <input type="date" name="from" id="exFrom" value="<?php echo htmlspecialchars($range === 'all' ? date('Y-m-01') : $from); ?>">
                </div>

                <div class="ex-field" id="exToWrap">
                    <label for="exTo">End Date</label>
                    <input type="date" name="to" id="exTo" value="<?php echo htmlspecialchars($range === 'all' ? date('Y-m-t') : $to); ?>">
                </div>

                <button type="submit" class="ex-btn ex-btn-apply"><i class="fa-solid fa-filter"></i> Apply</button>
            </div>
        </form>

        <?php // Para sa nakalimbag na papel: sino ang nag-print at kanino/saan galing ?>
        <div class="ex-row ex-people">
            <div class="ex-field ex-wide">
                <label for="exFromName">From</label>
                <input type="text" id="exFromName" maxlength="150" placeholder="e.g., City Veterinary Office - Records Section">
            </div>
        </div>

        <div class="ex-downloads">
            <button type="button" class="ex-btn ex-btn-pdf" onclick="exportAs('pdf')"><i class="fa-solid fa-file-pdf"></i> Download PDF</button>
            <div class="ex-status" id="exStatus"></div>
        </div>
    </div>

    <!-- ===== Ang report na kinukuhanan ng larawan =====
         #exPaperFit: sa telepono, pinapaliit (scale) ang buong papel para kasya sa screen -->
    <div class="ex-paper-fit" id="exPaperFit">
    <div class="ex-paper" id="exPaper">
        <div class="ex-head">
            <img src="/Caps2/Images/caloocan_logo.jpg" alt="City Logo" crossorigin="anonymous">
            <div>
                <div class="ex-org">SMART <span>PET</span></div>
                <div class="ex-sub">Caloocan City Animal Shelter &mdash; Pet Adoption Management System</div>
            </div>
            <div class="ex-from" id="pvFromRow" hidden><span>From:</span> <b id="pvFrom"></b></div>
        </div>

        <div class="ex-title"><?php echo htmlspecialchars($reportTitle); ?></div>
        <div class="ex-meta">
            <strong>Period:</strong> <?php echo htmlspecialchars($rangeLabel); ?> (<?php echo htmlspecialchars($periodText); ?>)<br>
            <strong>Generated by:</strong> <span id="pvBy"><?php echo htmlspecialchars($username); ?></span> (<?php echo htmlspecialchars($_SESSION['role'] ?? 'Staff'); ?>)<br>
            <strong>Generated on:</strong> <?php echo date('F j, Y \a\t g:i A'); ?>
        </div>

        <table class="ex-table">
            <thead>
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th><?php echo htmlspecialchars($col); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $cellValue): ?>
                            <td><?php echo htmlspecialchars((string) $cellValue); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (empty($rows)): ?>
            <div class="ex-empty">No records found for the selected period.</div>
        <?php endif; ?>

        <div class="ex-summary">
            <div>Total Records: <strong><?php echo count($rows); ?></strong></div>
            <?php if ($type === 'payments'): ?>
                <div>Total Collected (Paid only): <strong>PHP <?php echo number_format($totalAmount, 2); ?></strong></div>
            <?php endif; ?>
        </div>

        <div class="ex-foot">
            This report was generated automatically by the SMART PET system. The figures reflect the records stored in the database at the time of generation.
        </div>
    </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
    const FILE_BASE = <?php echo json_encode($fileBase); ?>;
    const statusEl = document.getElementById('exStatus');

    // Ang From/To ay may saysay lang kapag Custom Range ang napili
    function toggleCustom() {
        const isCustom = document.getElementById('exRange').value === 'custom';
        document.getElementById('exFromWrap').style.display = isCustom ? '' : 'none';
        document.getElementById('exToWrap').style.display = isCustom ? '' : 'none';
    }
    toggleCustom();

    function setBusy(busy, message) {
        document.querySelectorAll('.ex-downloads .ex-btn').forEach(function (b) { b.disabled = busy; });
        statusEl.textContent = message || '';
    }

    function saveDataUrl(dataUrl, filename) {
        const a = document.createElement('a');
        a.href = dataUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    // Kinukunan ng larawan ang buong report sa 2x na sukat para malinaw pa rin
    // ito kapag na-print o ni-zoom.
    async function renderPaper() {
        const paper = document.getElementById('exPaper');
        // Telepono: alisin muna ang pagpapaliit (scale) para buong laki ang larawan sa PDF
        const oldTransform = paper.style.transform;
        paper.style.transform = 'none';
        try {
            return await html2canvas(paper, {
                scale: 2,
                backgroundColor: '#ffffff',
                useCORS: true,
                logging: false,
                windowWidth: Math.max(document.documentElement.clientWidth, paper.offsetWidth + 40)
            });
        } finally {
            paper.style.transform = oldTransform;
        }
    }

    // Telepono: pinapaliit ang buong papel (760px) para eksaktong kasya sa lapad ng screen.
    // Ang lalagyan (#exPaperFit) ay binibigyan ng taas na kasinliit din, para walang bakanteng puwang.
    (function () {
        const fit = document.getElementById('exPaperFit');
        const paper = document.getElementById('exPaper');
        const mq = window.matchMedia('(max-width: 640px)');
        function fitPaper() {
            if (!mq.matches) {
                paper.style.transform = '';
                fit.style.height = '';
                return;
            }
            const scale = Math.min(1, fit.clientWidth / paper.offsetWidth);
            paper.style.transform = 'scale(' + scale + ')';
            fit.style.height = Math.ceil(paper.offsetHeight * scale) + 'px';
        }
        fitPaper();
        window.addEventListener('resize', fitPaper);
        window.addEventListener('load', fitPaper);
        // Nagbabago ang taas kapag nag-type sa "From" (lumalabas sa header ng papel)
        if (window.ResizeObserver) { new ResizeObserver(fitPaper).observe(paper); }
    })();

    // "Generated by" at "From": lumalabas agad sa preview (at sa PDF)
    const fromIn = document.getElementById('exFromName');
    function syncPeople() {
        document.getElementById('pvFrom').textContent = fromIn.value.trim();
        document.getElementById('pvFromRow').hidden = fromIn.value.trim() === '';
        fromIn.classList.remove('is-missing');
    }
    fromIn.addEventListener('input', syncPeople);

    async function exportAs(format) {
        // Kailangan kung sino ang nag-print at kung kanino/saan galing ang papel
        if (fromIn.value.trim() === '') {
            fromIn.classList.add('is-missing');
            fromIn.focus();
            statusEl.textContent = 'Please fill in "From" first.';
            return;
        }
        try {
            setBusy(true, 'Preparing your PDF...');
            const canvas = await renderPaper();

            // --- PDF: A4 portrait, hinahati sa maraming page kapag mahaba ---
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pageW = pdf.internal.pageSize.getWidth();
            const pageH = pdf.internal.pageSize.getHeight();
            const margin = 8;
            const imgW = pageW - margin * 2;
            const imgH = (canvas.height * imgW) / canvas.width;

            if (imgH <= pageH - margin * 2) {
                pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', margin, margin, imgW, imgH);
            } else {
                // Hinahati ang mahabang larawan ayon sa taas na kasya sa isang page
                const usableH = pageH - margin * 2;
                const sliceHeightPx = (usableH * canvas.width) / imgW;
                let offsetY = 0;
                let page = 0;

                while (offsetY < canvas.height) {
                    const sliceH = Math.min(sliceHeightPx, canvas.height - offsetY);
                    const slice = document.createElement('canvas');
                    slice.width = canvas.width;
                    slice.height = sliceH;
                    const ctx = slice.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, slice.width, slice.height);
                    ctx.drawImage(canvas, 0, offsetY, canvas.width, sliceH, 0, 0, canvas.width, sliceH);

                    if (page > 0) { pdf.addPage(); }
                    pdf.addImage(
                        slice.toDataURL('image/jpeg', 0.95), 'JPEG',
                        margin, margin, imgW, (sliceH * imgW) / canvas.width
                    );

                    offsetY += sliceH;
                    page++;
                }
            }

            pdf.save(FILE_BASE + '.pdf');
            setBusy(false, 'Downloaded PDF successfully.');
        } catch (err) {
            setBusy(false, '');
            alert('Sorry, the file could not be generated. Please check your internet connection and try again.');
        }
    }
</script>
</body>
</html>
