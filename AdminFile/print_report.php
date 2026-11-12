<?php
/**
 * Printable na Adoption Report (Weekly / Monthly / Yearly).
 *
 * Binubuksan mula sa "Print Report" ng Reports page. Kasama ang LAHAT ng report
 * para sa napiling panahon: buod, applications by status, breakdown ayon sa
 * panahon, approved adoptions, payments, at pets by kind (kasalukuyan).
 *
 * Mga parameter:
 *   type=weekly&date=YYYY-MM-DD   - ang buong linggo (Lunes-Linggo) ng petsang iyon
 *   type=monthly&year=YYYY&month=M
 *   type=yearly&year=YYYY
 *
 * Ang "approved adoption" ay parehong kahulugan sa buong system: Approved ang
 * application AT Adopted pa rin ang pet ngayon (tingnan ang reports.php).
 */
session_start();
require __DIR__ . '/../connection/database.php';

// Admin at Staff ang puwedeng mag-print (parehong Reports page ang may Print Report)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Staff'], true)) {
    header("Location: ../Login_Page/login.php");
    exit();
}

$username = $_SESSION['username'] ?? 'Admin';
$roleTitle = ($_SESSION['role'] ?? '') === 'Staff' ? 'Staff' : 'Administrator';

// ---------- Saklaw ng report ----------
$type = $_GET['type'] ?? 'monthly';
if (!in_array($type, ['weekly', 'monthly', 'yearly'], true)) {
    $type = 'monthly';
}

$today = new DateTimeImmutable('today');
$year = (int) ($_GET['year'] ?? $today->format('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) $today->format('Y');
}

if ($type === 'weekly') {
    $picked = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET['date'] ?? '') ?: $today;
    $start = $picked->modify('monday this week');
    $end = $start->modify('+7 days');
    $periodName = 'Weekly Report';
    $periodLabel = $start->format('M j') . ' – ' . $end->modify('-1 day')->format('M j, Y');
} elseif ($type === 'yearly') {
    $start = new DateTimeImmutable("$year-01-01");
    $end = $start->modify('+1 year');
    $periodName = 'Yearly Report';
    $periodLabel = (string) $year;
} else {
    $month = (int) ($_GET['month'] ?? $today->format('n'));
    if ($month < 1 || $month > 12) {
        $month = (int) $today->format('n');
    }
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $end = $start->modify('+1 month');
    $periodName = 'Monthly Report';
    $periodLabel = $start->format('F Y');
}

$from = $start->format('Y-m-d 00:00:00');
$to = $end->format('Y-m-d 00:00:00');
$rangeLabel = $start->format('F j, Y') . ' – ' . $end->modify('-1 day')->format('F j, Y');

// ---------- Maliliit na helper ----------
function rp_scalar(PDO $pdo, string $sql, array $params)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function rp_rows(PDO $pdo, string $sql, array $params): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

$range = [$from, $to];

// ---------- Buod ng panahon ----------
$applications = (int) rp_scalar($pdo,
    "SELECT COUNT(*) FROM adoption_applications WHERE created_at >= ? AND created_at < ?", $range);

$approved = (int) rp_scalar($pdo,
    "SELECT COUNT(*) FROM adoption_applications aa
       JOIN pet_inventory p ON p.pet_id = aa.pet_id
      WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
        AND aa.updated_at >= ? AND aa.updated_at < ?", $range);

$rejected = (int) rp_scalar($pdo,
    "SELECT COUNT(*) FROM adoption_applications
      WHERE application_status = 'Rejected' AND updated_at >= ? AND updated_at < ?", $range);

$collected = (float) rp_scalar($pdo,
    "SELECT COALESCE(SUM(amount), 0) FROM payments
      WHERE status = 'Paid' AND payment_date >= ? AND payment_date < ?", $range);

$paidCount = (int) rp_scalar($pdo,
    "SELECT COUNT(*) FROM payments WHERE status = 'Paid' AND payment_date >= ? AND payment_date < ?", $range);

$intakes = (int) rp_scalar($pdo,
    "SELECT COUNT(*) FROM pet_intakes WHERE intake_date >= ? AND intake_date < ?",
    [$start->format('Y-m-d'), $end->format('Y-m-d')]);

$decided = $approved + $rejected;
$approvalRate = $decided > 0 ? round($approved / $decided * 100) : null;

// ---------- Applications by status (mga isinumite sa panahong ito) ----------
$statusOrder = ['Pending', 'Under Review', 'Approved', 'Rejected'];
$byStatus = array_fill_keys($statusOrder, 0);
foreach (rp_rows($pdo,
    "SELECT application_status AS s, COUNT(*) AS c FROM adoption_applications
      WHERE created_at >= ? AND created_at < ? GROUP BY application_status", $range) as $row) {
    $byStatus[$row['s']] = (int) $row['c'];
}

// ---------- Breakdown ayon sa panahon ----------
// weekly = bawat araw, monthly = bawat linggo ng buwan, yearly = bawat buwan
$buckets = [];
if ($type === 'weekly') {
    for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
        $buckets[] = [$d->format('D, M j'), $d, $d->modify('+1 day')];
    }
} elseif ($type === 'monthly') {
    $weekStarts = [1, 8, 15, 22];
    $lastDay = (int) $end->modify('-1 day')->format('j');
    foreach ($weekStarts as $i => $day) {
        $bStart = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), $day);
        $bEnd = isset($weekStarts[$i + 1])
            ? $start->setDate((int) $start->format('Y'), (int) $start->format('n'), $weekStarts[$i + 1])
            : $end;
        $label = 'Week ' . ($i + 1) . ' (' . $bStart->format('M j') . '–' . $bEnd->modify('-1 day')->format('j') . ')';
        $buckets[] = [$label, $bStart, $bEnd];
    }
} else {
    for ($m = $start; $m < $end; $m = $m->modify('+1 month')) {
        $buckets[] = [$m->format('F'), $m, $m->modify('+1 month')];
    }
}

$breakdown = [];
foreach ($buckets as [$label, $bStart, $bEnd]) {
    $r = [$bStart->format('Y-m-d 00:00:00'), $bEnd->format('Y-m-d 00:00:00')];
    $breakdown[] = [
        'label' => $label,
        'applications' => (int) rp_scalar($pdo,
            "SELECT COUNT(*) FROM adoption_applications WHERE created_at >= ? AND created_at < ?", $r),
        'approved' => (int) rp_scalar($pdo,
            "SELECT COUNT(*) FROM adoption_applications aa
               JOIN pet_inventory p ON p.pet_id = aa.pet_id
              WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
                AND aa.updated_at >= ? AND aa.updated_at < ?", $r),
        'payments' => (int) rp_scalar($pdo,
            "SELECT COUNT(*) FROM payments WHERE status = 'Paid' AND payment_date >= ? AND payment_date < ?", $r),
        'amount' => (float) rp_scalar($pdo,
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'Paid' AND payment_date >= ? AND payment_date < ?", $r),
    ];
}

// ---------- Mga listahan ----------
$approvedList = rp_rows($pdo,
    "SELECT aa.application_id, aa.full_name, p.name AS pet_name, p.kind, aa.updated_at
       FROM adoption_applications aa
       JOIN pet_inventory p ON p.pet_id = aa.pet_id
      WHERE aa.application_status = 'Approved' AND p.status = 'Adopted'
        AND aa.updated_at >= ? AND aa.updated_at < ?
      ORDER BY aa.updated_at ASC", $range);

$paymentList = rp_rows($pdo,
    "SELECT pay.payment_date, pay.amount, pay.reference_number, pay.method,
            aa.full_name, p.name AS pet_name
       FROM payments pay
       JOIN adoption_applications aa ON aa.application_id = pay.application_id
       JOIN pet_inventory p ON p.pet_id = pay.pet_id
      WHERE pay.status = 'Paid' AND pay.payment_date >= ? AND pay.payment_date < ?
      ORDER BY pay.payment_date ASC", $range);

// ---------- Pets by kind (kasalukuyang bilang sa shelter) ----------
$petsByKind = rp_rows($pdo,
    "SELECT kind,
            COUNT(*) AS total,
            SUM(status = 'Available') AS available,
            SUM(status = 'Adopted') AS adopted,
            SUM(status NOT IN ('Available', 'Adopted')) AS other
       FROM pet_inventory GROUP BY kind ORDER BY total DESC", []);
$petTotals = ['total' => 0, 'available' => 0, 'adopted' => 0, 'other' => 0];
foreach ($petsByKind as $row) {
    foreach ($petTotals as $k => $v) {
        $petTotals[$k] += (int) $row[$k];
    }
}

$autoPrint = !isset($_GET['noprint']);

function peso(float $n): string
{
    return '&#8369;' . number_format($n, 2);
}

// ---------- Mga pormal na detalye ng dokumento ----------
if ($type === 'weekly') {
    $reportNo = 'SP-RPT-' . $start->format('o') . '-W' . $start->format('W');
    $periodPhrase = 'During the week of ' . $start->format('F j') . ' to ' . $end->modify('-1 day')->format('F j, Y');
    $bucketWord = 'day';
} elseif ($type === 'yearly') {
    $reportNo = 'SP-RPT-' . $start->format('Y') . '-Y';
    $periodPhrase = 'For the year ' . $start->format('Y');
    $bucketWord = 'month';
} else {
    $reportNo = 'SP-RPT-' . $start->format('Y-m') . '-M';
    $periodPhrase = 'During ' . $start->format('F Y');
    $bucketWord = 'week';
}

// Executive summary: isang talata na buod ng buong panahon
$summaryText = sprintf(
    '%s, the shelter received %s %s and approved %s %s',
    $periodPhrase,
    $applications,
    $applications === 1 ? 'adoption application' : 'adoption applications',
    $approved,
    $approved === 1 ? 'adoption' : 'adoptions'
);
$summaryText .= $approvalRate === null ? '. ' : sprintf(' (an approval rate of %d%%). ', $approvalRate);
$summaryText .= sprintf(
    'A total of %s in adoption fees was collected from %s %s.',
    html_entity_decode('&#8369;', ENT_QUOTES, 'UTF-8') . number_format($collected, 2),
    $paidCount,
    $paidCount === 1 ? 'payment' : 'payments'
);
$peak = null;
foreach ($breakdown as $b) {
    if ($b['approved'] > 0 && ($peak === null || $b['approved'] > $peak['approved'])) {
        $peak = $b;
    }
}
if ($peak !== null && count($breakdown) > 1) {
    $summaryText .= sprintf(' The most adoptions in a single %s were recorded in %s, with %d approved.', $bucketWord, $peak['label'], $peak['approved']);
}
$summaryText .= sprintf(' The shelter currently houses %d pets, %d of which are available for adoption.', $petTotals['total'], $petTotals['available']);

$generatedAt = date('F j, Y \a\t g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($reportNo . ' - ' . $periodName . ' - ' . $periodLabel); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --ink: #111827;
            --muted: #4b5563;
            --soft: #6b7280;
            --rule: #d1d5db;
            --hair: #e5e7eb;
            --tint: #f3f4f6;
            --brand: #1b4332;
        }
        body {
            font-family: 'Segoe UI', Calibri, Arial, sans-serif;
            color: var(--ink);
            background: #e5e7eb;
            font-size: 11pt;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ---- Toolbar sa screen lang (hindi napi-print) ---- */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 24px;
            background: #111827;
            color: #fff;
            font-size: 14px;
        }
        .toolbar strong { font-weight: 700; }
        .toolbar span { opacity: .7; }
        .toolbar-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .tb-in { height: 36px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 9px; font-family: inherit; font-size: 13px; min-width: 200px; }
        .tb-in.missing { border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220, 38, 38, .15); }
        .tb-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 36px;
            padding: 0 16px;
            border: 1px solid rgba(255, 255, 255, .25);
            border-radius: 6px;
            background: transparent;
            color: #fff;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
        }
        .tb-btn.primary { background: var(--brand); border-color: var(--brand); }
        .tb-btn:hover { filter: brightness(1.15); }

        /* ---- Papel (A4) ---- */
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            padding: 18mm 18mm 16mm;
            background: #fff;
            box-shadow: 0 6px 24px rgba(0, 0, 0, .18);
        }

        /* ---- Letterhead ---- */
        .letterhead {
            display: grid;
            grid-template-columns: 64px 1fr auto;
            align-items: center;
            gap: 14px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--brand);
        }
        .letterhead img {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
        }
        .lh-org { line-height: 1.25; }
        .lh-org .name { font-size: 16pt; font-weight: 800; letter-spacing: .5px; color: var(--brand); }
        .lh-org .sub { font-size: 9.5pt; color: var(--muted); }
        .lh-docno {
            text-align: right;
            font-size: 8.5pt;
            color: var(--soft);
            line-height: 1.5;
        }
        .lh-docno b { color: var(--ink); font-weight: 700; }
        .lh-from { max-width: 260px; margin-left: auto; overflow-wrap: anywhere; }

        .doc-title {
            margin: 18px 0 4px;
            text-align: center;
        }
        .doc-title h1 {
            font-size: 15pt;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .doc-title p { font-size: 10.5pt; color: var(--muted); }

        .doc-info {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            margin: 14px 0 20px;
            border: 1px solid var(--rule);
        }
        .doc-info div { padding: 7px 10px; border-right: 1px solid var(--rule); }
        .doc-info div:last-child { border-right: none; }
        .doc-info span {
            display: block;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: var(--soft);
        }
        .doc-info b { font-size: 10pt; font-weight: 600; }

        /* ---- Mga seksyon ---- */
        .sec { margin-bottom: 18px; }
        .sec-title {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid var(--ink);
            font-size: 10.5pt;
            font-weight: 800;
            letter-spacing: .8px;
            text-transform: uppercase;
            break-after: avoid;
        }
        .sec-title .roman { min-width: 22px; color: var(--brand); }
        .sec-note { margin-top: 5px; font-size: 8.5pt; color: var(--soft); font-style: italic; }
        .exec { font-size: 10.5pt; text-align: justify; }

        /* Key figures: payak na grid na may manipis na linya */
        .figures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border-top: 1px solid var(--rule);
            border-left: 1px solid var(--rule);
        }
        .figure {
            padding: 9px 12px;
            border-right: 1px solid var(--rule);
            border-bottom: 1px solid var(--rule);
        }
        .figure span {
            display: block;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: var(--soft);
        }
        .figure strong { display: block; margin: 2px 0 1px; font-size: 16pt; font-weight: 700; }
        .figure small { font-size: 8.5pt; color: var(--soft); }

        /* ---- Mga table ---- */
        table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        thead { display: table-header-group; }
        /* Ang Total ay sa pinakadulo lang ng table - hindi inuulit sa bawat pahina */
        tfoot { display: table-row-group; }
        th {
            padding: 6px 8px;
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
            background: var(--tint);
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: .5px;
            text-align: left;
            text-transform: uppercase;
        }
        td { padding: 5px 8px; border-bottom: 1px solid var(--hair); vertical-align: top; }
        tr { break-inside: avoid; }
        .r { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .c { text-align: center; }
        tfoot td {
            border-top: 1.5px solid var(--ink);
            border-bottom: 1.5px solid var(--ink);
            font-weight: 700;
        }
        .empty { padding: 12px 8px; text-align: center; color: var(--soft); font-style: italic; }
        .mono { font-family: Consolas, 'Courier New', monospace; font-size: 8.5pt; }

        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .keep { break-inside: avoid; }

        /* ---- Certification ---- */
        .cert { margin-top: 20px; break-inside: avoid; }
        .cert p { font-size: 10pt; text-align: justify; }
        .signatories {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            margin-top: 22px;
        }
        .sig-role { font-size: 8.5pt; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--soft); }
        .sig-line { height: 38px; border-bottom: 1px solid var(--ink); }
        .sig-name { margin-top: 4px; font-size: 10pt; font-weight: 700; min-height: 1.4em; }
        .sig-meta { font-size: 8.5pt; color: var(--soft); }

        .privacy {
            margin: -8px 0 20px;
            padding: 8px 10px;
            border: 1px solid var(--rule);
            font-size: 8pt;
            color: var(--muted);
            break-inside: avoid;
        }
        .privacy b { color: var(--ink); }

        .doc-end {
            margin-top: 22px;
            text-align: center;
            font-size: 8pt;
            letter-spacing: 2px;
            color: var(--soft);
        }

        /* ---- Print: page number at report no. sa ibaba ng bawat pahina ---- */
        @page {
            size: A4;
            margin: 15mm 15mm 16mm;
            @bottom-left {
                content: "<?php echo htmlspecialchars($reportNo); ?>";
                font-family: 'Segoe UI', Arial, sans-serif;
                font-size: 8pt;
                color: #6b7280;
            }
            @bottom-right {
                content: "Page " counter(page) " of " counter(pages);
                font-family: 'Segoe UI', Arial, sans-serif;
                font-size: 8pt;
                color: #6b7280;
            }
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
        }
        @media screen and (max-width: 860px) {
            .sheet { width: auto; margin: 12px; padding: 20px; }
            .figures, .doc-info, .signatories { grid-template-columns: 1fr 1fr; }
            .two-col { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../connection/print_backdrop.php'; sp_print_backdrop((($_SESSION['role'] ?? '') === 'Staff') ? '/Caps2/StaffFile/reports.php' : '/Caps2/AdminFile/reports.php', null, false); ?>
    <div class="toolbar">
        <div>
            <strong><?php echo htmlspecialchars($periodName); ?></strong>
            <span>&nbsp;&middot;&nbsp;<?php echo htmlspecialchars($rangeLabel); ?></span>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="tb-btn" onclick="window.close()"><i class="fa-solid fa-xmark"></i> Close</button>
            <input type="text" class="tb-in" id="rpFrom" maxlength="150" placeholder="From (e.g., City Veterinary Office)" aria-label="From">
            <button type="button" class="tb-btn primary" onclick="spPrintReport()"><i class="fa-solid fa-file-pdf"></i> Print / Save as PDF</button>
        </div>
    </div>

    <main class="sheet">
        <!-- Letterhead -->
        <header class="letterhead">
            <img src="/Caps2/Images/caloocan_logo.jpg" alt="Logo">
            <div class="lh-org">
                <div class="name">SMART PET</div>
                <div class="sub">Pet Adoption Management System</div>
            </div>
            <div class="lh-docno">
                <div>Report No. <b><?php echo htmlspecialchars($reportNo); ?></b></div>
                <div>Date Issued <b><?php echo date('F j, Y'); ?></b></div>
                <div class="lh-from" hidden>From: <b class="js-from"></b></div>
            </div>
        </header>

        <div class="doc-title">
            <h1>Adoption <?php echo htmlspecialchars($periodName); ?></h1>
            <p><?php echo htmlspecialchars($periodLabel); ?></p>
        </div>

        <div class="doc-info">
            <div><span>Reporting Period</span><b><?php echo htmlspecialchars($rangeLabel); ?></b></div>
            <div><span>Report Type</span><b><?php echo htmlspecialchars(ucfirst($type)); ?></b></div>
            <div><span>Generated By</span><b><?php echo htmlspecialchars($username); ?>, <?php echo htmlspecialchars($roleTitle); ?></b></div>
        </div>

        <div class="privacy">
            <b>Data Privacy Notice:</b> This report contains personal information of adopters and is intended solely for
            official use. Handle, store, and dispose of it in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173).
            Generated on <?php echo htmlspecialchars($generatedAt); ?>.
        </div>

        <!-- I. Executive summary -->
        <section class="sec keep">
            <h2 class="sec-title"><span class="roman">I.</span> Executive Summary</h2>
            <p class="exec"><?php echo htmlspecialchars($summaryText); ?></p>
        </section>

        <!-- II. Key figures -->
        <section class="sec keep">
            <h2 class="sec-title"><span class="roman">II.</span> Key Figures</h2>
            <div class="figures">
                <div class="figure">
                    <span>Applications Received</span>
                    <strong><?php echo $applications; ?></strong>
                    <small>submitted within the period</small>
                </div>
                <div class="figure">
                    <span>Approved Adoptions</span>
                    <strong><?php echo $approved; ?></strong>
                    <small><?php echo $approvalRate === null ? 'no decisions recorded' : $approvalRate . '% approval rate'; ?></small>
                </div>
                <div class="figure">
                    <span>Rejected Applications</span>
                    <strong><?php echo $rejected; ?></strong>
                    <small>decided within the period</small>
                </div>
                <div class="figure">
                    <span>Adoption Fees Collected</span>
                    <strong><?php echo peso($collected); ?></strong>
                    <small>from <?php echo $paidCount; ?> paid adoption<?php echo $paidCount === 1 ? '' : 's'; ?></small>
                </div>
                <div class="figure">
                    <span>New Animal Intakes</span>
                    <strong><?php echo $intakes; ?></strong>
                    <small>admitted to the shelter</small>
                </div>
                <div class="figure">
                    <span>Current Shelter Population</span>
                    <strong><?php echo $petTotals['total']; ?></strong>
                    <small><?php echo $petTotals['available']; ?> available for adoption</small>
                </div>
            </div>
        </section>

        <!-- III & IV -->
        <div class="two-col">
            <section class="sec keep">
                <h2 class="sec-title"><span class="roman">III.</span> Applications by Status</h2>
                <table>
                    <thead>
                        <tr><th>Status</th><th class="r">No.</th><th class="r">Percent</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusOrder as $s): $c = $byStatus[$s]; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s); ?></td>
                                <td class="r"><?php echo $c; ?></td>
                                <td class="r"><?php echo $applications > 0 ? number_format($c / $applications * 100, 1) . '%' : '&mdash;'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td>Total</td><td class="r"><?php echo $applications; ?></td><td class="r"><?php echo $applications > 0 ? '100.0%' : '&mdash;'; ?></td></tr>
                    </tfoot>
                </table>
                <p class="sec-note">Present status of applications submitted within the period.</p>
            </section>

            <section class="sec keep">
                <h2 class="sec-title"><span class="roman">IV.</span> Shelter Population by Kind</h2>
                <table>
                    <thead>
                        <tr><th>Kind</th><th class="r">Available</th><th class="r">Adopted</th><th class="r">Others</th><th class="r">Total</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($petsByKind)): ?>
                            <tr><td colspan="5" class="empty">No pets registered.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($petsByKind as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['kind']); ?></td>
                                <td class="r"><?php echo (int) $row['available']; ?></td>
                                <td class="r"><?php echo (int) $row['adopted']; ?></td>
                                <td class="r"><?php echo (int) $row['other']; ?></td>
                                <td class="r"><?php echo (int) $row['total']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="r"><?php echo $petTotals['available']; ?></td>
                            <td class="r"><?php echo $petTotals['adopted']; ?></td>
                            <td class="r"><?php echo $petTotals['other']; ?></td>
                            <td class="r"><?php echo $petTotals['total']; ?></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="sec-note">As of <?php echo date('F j, Y'); ?>.</p>
            </section>
        </div>

        <!-- V. Breakdown (hindi hinahati sa dalawang pahina - hanggang 12 row lang) -->
        <section class="sec keep">
            <h2 class="sec-title"><span class="roman">V.</span> <?php echo $type === 'weekly' ? 'Daily' : ($type === 'monthly' ? 'Weekly' : 'Monthly'); ?> Breakdown</h2>
            <table>
                <thead>
                    <tr>
                        <th><?php echo $type === 'weekly' ? 'Day' : ($type === 'monthly' ? 'Week' : 'Month'); ?></th>
                        <th class="r">Applications</th>
                        <th class="r">Approved Adoptions</th>
                        <th class="r">Payments</th>
                        <th class="r">Amount Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakdown as $b): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($b['label']); ?></td>
                            <td class="r"><?php echo $b['applications']; ?></td>
                            <td class="r"><?php echo $b['approved']; ?></td>
                            <td class="r"><?php echo $b['payments']; ?></td>
                            <td class="r"><?php echo peso($b['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="r"><?php echo $applications; ?></td>
                        <td class="r"><?php echo $approved; ?></td>
                        <td class="r"><?php echo $paidCount; ?></td>
                        <td class="r"><?php echo peso($collected); ?></td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <!-- VI. Approved adoptions -->
        <section class="sec">
            <h2 class="sec-title"><span class="roman">VI.</span> Record of Approved Adoptions</h2>
            <table>
                <thead>
                    <tr><th class="c">No.</th><th>Date Approved</th><th>Application No.</th><th>Adopter</th><th>Pet</th><th>Kind</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($approvedList)): ?>
                        <tr><td colspan="6" class="empty">No approved adoptions within the period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($approvedList as $i => $row): ?>
                        <tr>
                            <td class="c"><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($row['updated_at']))); ?></td>
                            <td class="mono">APP-<?php echo str_pad((string) $row['application_id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['pet_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['kind']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- VII. Payments -->
        <section class="sec">
            <h2 class="sec-title"><span class="roman">VII.</span> Record of Adoption Fee Payments</h2>
            <table>
                <thead>
                    <tr><th class="c">No.</th><th>Date Paid</th><th>Adopter</th><th>Pet</th><th>Method</th><th>Reference No.</th><th class="r">Amount</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($paymentList)): ?>
                        <tr><td colspan="7" class="empty">No payments collected within the period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($paymentList as $i => $row): ?>
                        <tr>
                            <td class="c"><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['payment_date']))); ?></td>
                            <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['pet_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['method'] ?: '—'); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($row['reference_number'] ?: '—'); ?></td>
                            <td class="r"><?php echo peso((float) $row['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (!empty($paymentList)): ?>
                    <tfoot>
                        <tr><td colspan="6">Total Amount Collected</td><td class="r"><?php echo peso($collected); ?></td></tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </section>

        <!-- VIII. Certification -->
        <section class="cert">
            <h2 class="sec-title"><span class="roman">VIII.</span> Certification</h2>
            <p>
                This is to certify that the information contained in this report was generated from the records of the
                SMART PET Pet Adoption Management System for the period <?php echo htmlspecialchars($rangeLabel); ?>,
                and is true and correct to the best of our knowledge.
            </p>
            <div class="signatories">
                <div>
                    <div class="sig-role">Prepared by</div>
                    <div class="sig-line"></div>
                    <div class="sig-name"><?php echo htmlspecialchars($username); ?></div>
                    <div class="sig-meta"><?php echo htmlspecialchars($roleTitle); ?></div>
                    <div class="sig-meta">Date: <?php echo date('m/d/Y'); ?></div>
                </div>
                <div>
                    <div class="sig-role">Reviewed by</div>
                    <div class="sig-line"></div>
                    <div class="sig-name">&nbsp;</div>
                    <div class="sig-meta">Signature over printed name</div>
                    <div class="sig-meta">Date: ______________</div>
                </div>
                <div>
                    <div class="sig-role">Approved by</div>
                    <div class="sig-line"></div>
                    <div class="sig-name">&nbsp;</div>
                    <div class="sig-meta">Signature over printed name</div>
                    <div class="sig-meta">Date: ______________</div>
                </div>
            </div>
            <div class="doc-end">&mdash; END OF REPORT &mdash;</div>
        </section>

    </main>

    <script>
        // "From": lumalabas agad sa upper right ng letterhead; kailangan bago mag-print.
        // Ang "Generated by" ay kusang pangalan ng naka-login na user.
        (function () {
            const from = document.getElementById('rpFrom'), row = document.querySelector('.lh-from');
            function sync() {
                row.hidden = from.value.trim() === '';
                document.querySelectorAll('.js-from').forEach(function (el) { el.textContent = from.value.trim() || ' '; });
                from.classList.remove('missing');
            }
            from.addEventListener('input', sync);
            window.spPrintReport = function () {
                if (from.value.trim() === '') { from.classList.add('missing'); from.focus(); return; }
                window.print();
            };
            // Hindi na kusang nagpi-print: ilalagay muna ang "From"
            window.addEventListener('load', function () { from.focus(); });
        })();
    </script>
</body>
</html>
