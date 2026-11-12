<?php
/**
 * Mga certificate ng isang natapos na adoption, magkasama sa iisang dokumento:
 *  - Certificate of Adoption - patunay na legal na naipasa ang pet sa adopter
 *  - Vaccination Certificate - mula sa 'Vaccines' na medical_records ng pet
 * Isang download lang: 2-page PDF, o iisang PNG/JPEG na magkapatong ang dalawa.
 * Lumalabas lang ito kapag Approved ang application AT Paid na ang adoption fee.
 * Ang adopter mismo (o staff/admin) lang ang pwedeng tumingin.
 */
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../Login_Page/login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$isStaff = in_array($_SESSION['role'] ?? '', ['Staff', 'Admin'], true);
$application_id = (int) ($_GET['application_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT aa.application_id, aa.adopter_id, aa.full_name, aa.address, aa.phone_number,
            p.pet_id, p.name AS pet_name, p.kind, p.breed, p.gender, p.age_group, p.size,
            p.vaccination_status, p.rescue_date,
            aa.cert_released_at, pay.payment_date
     FROM adoption_applications aa
     JOIN pet_inventory p ON p.pet_id = aa.pet_id
     JOIN payments pay ON pay.application_id = aa.application_id AND pay.status = 'Paid'
     WHERE aa.application_id = ? AND (aa.application_status = 'Approved' OR (aa.application_status IN ('Pending', 'Under Review') AND aa.meetup_chosen_at IS NOT NULL AND aa.interview_missed_at IS NULL))"
);
$stmt->execute([$application_id]);
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cert || (!$isStaff && (int) $cert['adopter_id'] !== $user_id)) {
    http_response_code(404);
    echo 'Certificate not found.';
    exit();
}

$adoptedTs = strtotime($cert['payment_date']);
$certSeq = date('Y', $adoptedTs) . '-' . str_pad((string) $cert['application_id'], 5, '0', STR_PAD_LEFT);

// Bawat item = isang page sa PDF. Ang Adoption Agreement ay 2 pages at walang
// makapal na border (kontrata ito, hindi certificate).
$agreementNo = $certSeq;
$sheets = [
    'adoption'    => ['title' => 'Certificate of Adoption', 'no' => 'CVO-' . $certSeq],
    'vaccination' => ['title' => 'Vaccination Certificate', 'no' => 'VAX-' . $certSeq],
    'agreement1'  => ['title' => 'Pet Adoption Agreement', 'no' => $agreementNo, 'plain' => true],
    'agreement2'  => ['title' => 'Pet Adoption Agreement', 'no' => $agreementNo, 'plain' => true],
];

// Lahat ng bakunang naitala ng staff para sa pet na ito (Medical & Intakes page)
$vaxStmt = $pdo->prepare(
    "SELECT checkup_date, notes FROM medical_records
     WHERE pet_id = ? AND status = 'Vaccines'
     ORDER BY checkup_date ASC, record_id ASC"
);
$vaxStmt->execute([$cert['pet_id']]);
$vaccines = $vaxStmt->fetchAll(PDO::FETCH_ASSOC);

// Hal. "Complete (Anti-Rabies, Oct. 10, 2026)" - status ng pet + pinakahuling bakuna
$vaxSummary = trim((string) $cert['vaccination_status']);
if ($vaccines) {
    $lastVax = end($vaccines);
    $vaxNote = trim((string) $lastVax['notes']);
    $vaxDetail = ($vaxNote !== '' ? $vaxNote . ', ' : '') . date('M. j, Y', strtotime($lastVax['checkup_date']));
    $vaxSummary = trim($vaxSummary . ' (' . $vaxDetail . ')');
}

// Mga detalyeng pinupunan ng staff - direkta silang nag-e-edit sa mismong papel
$docStmt = $pdo->prepare("SELECT * FROM adoption_documents WHERE application_id = ?");
$docStmt->execute([$cert['application_id']]);
$doc = $docStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if (!empty($doc['signing_date'])) {
    $doc['signing_date'] = date('F j, Y', strtotime($doc['signing_date']));
}

function doc_get(array $doc, string $key): string
{
    return trim((string) ($doc[$key] ?? ''));
}

// Pangalan at address ng adopter: ang nabasa mula sa Valid ID (o itinama ng
// staff); kapag wala pa, ang tinype sa application form.
$ownerFallback = [
    'owner_name' => trim((string) $cert['full_name']),
    'owner_address' => trim((string) $cert['address']),
];

// Resulta ng "Read from Valid ID" (staff lang ang nakakakita)
$flash = '';
$flashOk = true;
if ($isStaff) {
    if (!empty($_SESSION['error_message'])) {
        $flash = (string) $_SESSION['error_message'];
        $flashOk = false;
    } elseif (!empty($_SESSION['success_message'])) {
        $flash = (string) $_SESSION['success_message'];
    }
    unset($_SESSION['error_message'], $_SESSION['success_message']);
}

function cert_val($value): string
{
    $value = trim((string) $value);
    return $value === '' ? '&nbsp;' : htmlspecialchars($value);
}

// Laman ng linya: bold na value kung meron, kung wala ay blangkong linya
function agr_val(string $value): string
{
    return $value === '' ? '<span class="blank"></span>' : '<strong>' . htmlspecialchars($value) . '</strong>';
}

/**
 * Isang field na galing sa adoption_documents. Sa staff, nagiging clickable
 * (contenteditable) na bahagi ng papel mismo; sa adopter, plain text lang.
 * Mga uri ($mode):
 *  - line  : nasa loob ng .line (may sariling underline na)
 *  - agr   : inline sa Agreement - bold, blangkong linya kapag wala
 *  - under : "Date: ____" / "License No.: ____" sa ilalim ng pirma
 *  - sig   : pangalan sa ibabaw ng signature line (naka-uppercase)
 */
function ed(string $field, string $mode, string $placeholder = ''): string
{
    global $doc, $isStaff, $ownerFallback;
    $value = doc_get($doc, $field) ?: ($ownerFallback[$field] ?? '');

    if ($isStaff) {
        $cls = 'ed ed-' . $mode;
        return '<span class="' . $cls . '" contenteditable="true" spellcheck="false" data-field="' . $field
            . '" data-ph="' . htmlspecialchars($placeholder) . '">' . htmlspecialchars($value) . '</span>';
    }

    switch ($mode) {
        case 'line':
            return cert_val($value);
        case 'agr':
            return agr_val($value);
        case 'under':
            return $value === '' ? '____________________' : '<strong style="color:#111;">' . htmlspecialchars($value) . '</strong>';
        case 'sig':
            return htmlspecialchars(strtoupper($value));
    }
    return htmlspecialchars($value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adoption Documents - <?php echo htmlspecialchars($cert['pet_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page { size: letter portrait; margin: 0.4in; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #e5e7eb;
            font-family: "DejaVu Serif", Georgia, "Times New Roman", serif;
            color: #111;
        }

        .toolbar {
            max-width: 850px;
            margin: 20px auto 0;
            padding: 0 16px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            font-family: Arial, sans-serif;
        }
        .toolbar a, .toolbar button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: 1.5px solid #1b4d2e;
        }
        .toolbar a { background: #fff; color: #1b4d2e; }
        .toolbar button { background: #1b4d2e; color: #fff; }
        .toolbar button:disabled { opacity: 0.6; cursor: wait; }
        .dl-group { display: flex; gap: 8px; align-items: center; }
        .dl-group select {
            padding: 9px 14px;
            border-radius: 999px;
            border: 1.5px solid #1b4d2e;
            font-size: 14px;
            font-weight: 700;
            color: #1b4d2e;
            background: #fff;
            cursor: pointer;
        }
        /* Staff: direktang pag-edit sa papel. Nawawala ang highlight at mga
           placeholder kapag kinukuhanan ng PDF/larawan (tinatanggal ang .editing). */
        .edit-bar {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid #d7e8dc;
            border-radius: 14px;
            padding: 10px 14px;
        }
        .edit-tip { flex: 1; min-width: 220px; font-size: 13px; color: #374151; }
        .edit-tip mark { background: #fff7cc; padding: 0 4px; border-radius: 3px; }
        .edit-status { font-size: 13px; font-weight: 700; }
        .edit-status.dirty { color: #b45309; }
        .edit-status.saved { color: #1e7e34; }
        .edit-status.error { color: #c5221f; }
        .id-flash { width: 100%; font-size: 13px; padding: 8px 12px; border-radius: 8px; }
        .id-flash.ok { background: #e7f5ea; color: #1e7e34; }
        .id-flash.err { background: #fdecea; color: #c5221f; }

        .ed { outline: none; }
        .ed-sig { text-transform: uppercase; }
        .ed-line { display: inline-block; min-width: 60px; }
        .ed-agr, .ed-under { font-weight: 700; color: #111; }
        .editing .ed {
            background: #fff7cc;
            border-radius: 3px;
            cursor: text;
            box-shadow: 0 0 0 1px #f3d768;
        }
        .editing .ed:hover { background: #ffef99; }
        .editing .ed:focus { background: #fff; box-shadow: 0 0 0 2px #1b4d2e; }
        .editing .ed:empty::before {
            content: attr(data-ph);
            color: #a3a3a3;
            font-weight: 400;
            font-style: italic;
            text-transform: none;
        }
        .ed-agr:empty, .ed-under:empty { display: inline-block; min-width: 150px; border-bottom: 1px solid #111; }
        .ed-sig:empty { display: inline-block; min-width: 180px; }
        .toolbar .hint {
            width: 100%;
            font-size: 13px;
            color: #4b5563;
        }

        .sheet {
            max-width: 850px;
            margin: 16px auto 30px;
            background: #fff;
            border: 8px solid #1e3a5f;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .inner {
            border: 1.5px solid #1e3a5f;
            padding: 36px 48px 40px;
        }

        .seal {
            width: 76px; height: 76px;
            margin: 0 auto 14px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid #6b7280;
        }
        .seal img { width: 100%; height: 100%; object-fit: cover; }

        .gov { text-align: center; line-height: 1.45; }
        .gov .l1 { font-size: 15px; }
        .gov .l2 { font-size: 17px; font-weight: 700; letter-spacing: 0.5px; }
        .gov .l3 { font-size: 15px; }
        .gov-rule { border: 0; border-top: 2px solid #1e3a5f; width: 78%; margin: 14px auto 22px; }

        h1 {
            text-align: center;
            color: #1e3a5f;
            font-size: 34px;
            letter-spacing: 1px;
            margin: 0;
        }
        .cert-no { text-align: center; color: #6b7280; font-size: 13.5px; margin-top: 4px; }

        h3 {
            color: #1e3a5f;
            font-size: 14.5px;
            letter-spacing: 0.6px;
            margin: 26px 0 8px;
        }

        .row { display: flex; gap: 40px; }
        .field {
            flex: 1;
            display: flex;
            align-items: flex-end;
            gap: 8px;
            font-size: 13.5px;
            margin-bottom: 12px;
            min-width: 0;
        }
        .field label { white-space: nowrap; }
        .field .line {
            flex: 1;
            border-bottom: 1.2px solid #111;
            padding: 0 4px 1px;
            font-weight: 700;
            min-height: 20px;
            min-width: 0;
        }

        .certify { font-size: 14px; line-height: 1.75; margin-top: 20px; }
        .certify .fill { border-bottom: 1px solid #111; padding: 0 6px; font-weight: 700; }

        ol { font-size: 12.5px; line-height: 1.7; margin: 0; padding-left: 22px; }

        .vax-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; }
        .vax-table th, .vax-table td {
            border: 1.2px solid #111;
            width: 33.33%;
            height: 38px;
            padding: 6px 10px;
            text-align: center;
        }
        .vax-table th { font-size: 12.5px; }

        /* Pet Adoption Agreement - simpleng dokumento, sukat ng letter page (8.5 x 11) */
        .sheet.plain { border: 0; padding: 0; min-height: 1100px; }
        .sheet.plain .inner {
            border: 0;
            padding: 56px 60px;
            font-family: "Times New Roman", Times, serif;
            font-size: 14.5px;
            line-height: 1.45;
        }
        .sheet.plain p { margin: 0 0 12px; }
        .agr-head { text-align: center; line-height: 1.9; margin-bottom: 28px; }
        .agr-head .strong { font-weight: 700; font-size: 16px; }
        .agr-head .title { margin-top: 4px; }
        .sheet.plain h4 { font-size: 14.5px; margin: 22px 0 8px; }
        .agr-line { margin-bottom: 7px; }
        .agr-line .blank { display: inline-block; min-width: 170px; border-bottom: 1px solid #111; }
        .agr-list { margin: 0 0 12px; padding-left: 50px; }
        .agr-list li { margin-bottom: 8px; padding-left: 6px; }
        .agr-signs { display: flex; justify-content: space-between; gap: 40px; margin-top: 56px; }
        .agr-sign { width: 42%; text-align: center; font-size: 13px; }
        .agr-sign .name { font-weight: 700; font-size: 14.5px; min-height: 20px; }
        .agr-sign .rule { border-bottom: 1px solid #111; margin: 16px 10px 6px; }
        .agr-witness { margin-top: 36px; }

        .signs { display: flex; justify-content: space-between; gap: 40px; margin-top: 48px; }
        .sign { width: 44%; font-size: 13px; }
        .sign .sig-line {
            border-bottom: 1.2px solid #111;
            margin-bottom: 6px;
            height: 32px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            font-weight: 700;
        }
        .sign strong { display: block; }
        .sign .sub { color: #6b7280; margin-top: 4px; }

        /* × sa kanang itaas (telepono lang) */
        .cert-x { display: none; }

        /* ===== Telepono: buong screen at puti, × sa itaas; ang mga papel ay nananatiling
           tunay na ayos (850px) at pinapaliit ng script para kasya sa screen ===== */
        @media (max-width: 640px) {
            html.sp-pb body, body { background: #ffffff !important; }
            .sp-pb-bg, .sp-pb-ov { display: none !important; }
            /* (html.sp-pb body > ... sa unahan: ang print_backdrop.php ay naglalagay ng
               position: relative sa lahat ng direktang laman ng body) */
            .cert-x, html.sp-pb body > .cert-x {
                display: flex; position: fixed !important; top: 10px; right: 10px; z-index: 30 !important;
                width: 38px; height: 38px; border-radius: 50%; align-items: center; justify-content: center;
                background: #f1f4f2; color: #1f2937; font: 400 20px/1 Arial, sans-serif; text-decoration: none;
            }

            /* Toolbar: buong lapad, mas maliit na font at button */
            /* (!important: nasa ibaba ng file ang html.sp-pb .toolbar na may kahon at anino) */
            html.sp-pb .toolbar, .toolbar {
                max-width: none !important; margin: 0 !important; padding: 14px 14px 12px !important; gap: 8px;
                border-radius: 0 !important; box-shadow: none !important; border-bottom: 1px solid #eef0f3; background: #fff;
            }
            .toolbar > a:first-child { display: none; }          /* Back: may × na sa itaas */
            .dl-group { width: 100%; flex-wrap: wrap; gap: 6px; padding-right: 46px; }  /* hindi natatakpan ng × */
            .toolbar a, .toolbar button { padding: 0 12px; height: 36px; font-size: 12.5px; gap: 6px; }
            .dl-group select { height: 36px; padding: 0 10px; font-size: 12.5px; }
            .toolbar .hint { font-size: 11.5px; line-height: 1.5; }
            .edit-bar { padding: 8px 10px; gap: 8px; border-radius: 10px; }
            .edit-tip { min-width: 0; flex-basis: 100%; font-size: 11.5px; line-height: 1.45; }
            .edit-bar form, .edit-bar #saveBtn { flex: 1; }
            .edit-bar button { width: 100%; justify-content: center; }
            .edit-status { font-size: 11.5px; }
            .id-flash { font-size: 11.5px; padding: 6px 10px; }

            /* Ang mga papel: 850px pa rin (tunay na ayos), pinapaliit (scale) sa lalagyan */
            .sheet-fit { margin: 12px 12px 0; overflow: hidden; }
            .sheet-fit .sheet { width: 850px; max-width: none; margin: 0; transform-origin: top left; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12); }
            html.sp-pb .sheet-fit .sheet { box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12); }
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; box-shadow: none; max-width: none; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        /* Sa ibabaw ng transparent na background: puting card ang toolbar para mabasa */
        html.sp-pb .toolbar {
            padding: 12px 16px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        }
        html.sp-pb .sheet { box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35); }
<?php if (!$isStaff): ?>
        /* Adopter: pang-view lang - walang lalabas kapag pinindot ang Ctrl+P / Save as PDF */
        @media print {
            .sheet { display: none !important; }
            body::after {
                content: "This document is for viewing only. The official certificate will be handed to you on the day you pick up your pet.";
                display: block; padding: 40px; font: 16px Arial, sans-serif; text-align: center;
            }
        }
        .sheet { -webkit-user-select: none; user-select: none; }
<?php endif; ?>
    </style>
</head>
<body<?php echo $isStaff ? ' class="editing"' : ''; ?>>
<?php
// Transparent na background: ang page na pinanggalingan (Certificates ng staff o Docs ng adopter)
require_once __DIR__ . '/../connection/print_backdrop.php';
sp_print_backdrop($isStaff ? '/Caps2/StaffFile/certificates.php' : '/Caps2/Adopter_File/docs.php', null, false);
?>

    <?php // Telepono lang: × sa kanang itaas para lumabas (kapalit ng Back) ?>
    <a class="cert-x" href="<?php echo $isStaff ? '/Caps2/StaffFile/certificates.php' : 'docs.php'; ?>" title="Close" aria-label="Close">&times;</a>
    <div class="toolbar">
        <a href="<?php echo $isStaff ? '/Caps2/StaffFile/certificates.php' : 'docs.php'; ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <div class="dl-group">
            <?php if ($isStaff): ?>
                <!-- Staff lang ang pwedeng mag-print/download; pang-view lang ito sa adopter -->
                <button type="button" id="printBtn"><i class="fa-solid fa-print"></i> Print (PDF)</button>
                <select id="dlFormat" aria-label="File format">
                    <option value="pdf">PDF</option>
                    <option value="png">PNG</option>
                    <option value="jpeg">JPEG</option>
                </select>
                <button type="button" id="dlBtn"><i class="fa-solid fa-download"></i> Download</button>
            <?php endif; ?>
        </div>
        <?php if ($isStaff): ?>
            <div class="hint">Print this PDF (Certificate of Adoption, Vaccination Certificate, and Pet Adoption Agreement). Have the City Veterinarian sign it and apply the office dry seal or rubber stamp. On pickup day, have the adopter sign the agreement in front of a witness, then hand over the hard copy.</div>
            <div class="edit-bar">
                <span class="edit-tip"><i class="fa-solid fa-pen"></i> Click any <mark>highlighted</mark> field on the papers below to type in it. Fields left empty stay as blank lines for handwriting.</span>
                <span class="edit-status" id="editStatus"></span>
                <form method="POST" action="/Caps2/StaffFile/process_read_valid_id.php" id="readIdForm" style="margin:0;">
                    <input type="hidden" name="application_id" value="<?php echo (int) $cert['application_id']; ?>">
                    <button type="submit" id="readIdBtn" title="Fill in the adopter's name, address, and ID details from the uploaded Valid ID"><i class="fa-solid fa-id-card"></i> Read from Valid ID</button>
                </form>
                <button type="button" id="saveBtn"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </div>
            <?php if ($flash !== ''): ?>
                <div class="id-flash <?php echo $flashOk ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars($flash); ?></div>
            <?php elseif (!empty($doc['id_read_at'])): ?>
                <div class="id-flash ok">Name, address, and ID details were read from the Valid ID on <?php echo date('M j, Y g:i A', strtotime($doc['id_read_at'])); ?>. Check them against the ID before printing.</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="hint"><strong>For viewing only.</strong> This preview cannot be downloaded or printed. The official certificate is the printed copy signed by the City Veterinarian and bearing the office seal. It will be handed to you on the day you pick up your pet.</div>
        <?php endif; ?>
    </div>

    <?php foreach ($sheets as $type => $sheet):
        $certTitle = $sheet['title'];
        $certNo = $sheet['no'];
    ?>
    <div class="sheet<?php echo !empty($sheet['plain']) ? ' plain' : ''; ?>">
        <div class="inner">
            <?php if ($type === 'adoption' || $type === 'vaccination'): ?>
            <div class="seal"><img src="../Images/caloocan_logo.jpg" alt="LGU Seal"></div>

            <div class="gov">
                <div class="l1">Republic of the Philippines</div>
                <div class="l2">CITY GOVERNMENT OF CALOOCAN</div>
                <div class="l3">City Veterinary Office</div>
            </div>
            <hr class="gov-rule">

            <h1><?php echo htmlspecialchars(strtoupper($certTitle)); ?></h1>
            <div class="cert-no">Certificate No. <strong><?php echo htmlspecialchars($certNo); ?></strong></div>
            <?php endif; ?>

            <?php if ($type === 'agreement1'): ?>

            <div class="agr-head">
                <div>Republic of the Philippines</div>
                <div class="strong">CITY GOVERNMENT OF CALOOCAN</div>
                <div>City Veterinary Office</div>
                <div class="strong title">PET ADOPTION AGREEMENT</div>
                <div>Agreement No. <?php echo htmlspecialchars($agreementNo); ?></div>
            </div>

            <p>
                This Pet Adoption Agreement is entered into on this <strong><?php echo date('jS', $adoptedTs); ?></strong> day of
                <strong><?php echo date('F, Y', $adoptedTs); ?></strong>, by and between the <strong>CITY VETERINARY OFFICE</strong>
                of Caloocan City, hereinafter referred to as the "Office," and the person named below, hereinafter referred
                to as the "Adopter."
            </p>

            <h4>I. ADOPTER INFORMATION</h4>
            <div class="agr-line">Name: <?php echo ed('owner_name', 'agr', 'Full name'); ?></div>
            <div class="agr-line">Address: <?php echo ed('owner_address', 'agr', 'Address'); ?></div>
            <div class="agr-line">Contact Number: <strong><?php echo htmlspecialchars($cert['phone_number']); ?></strong></div>
            <div class="agr-line">Valid ID Presented: <?php echo ed('valid_id_type', 'agr', 'Type of ID'); ?> &nbsp; ID Number: <?php echo ed('valid_id_number', 'agr', 'ID number'); ?></div>

            <h4>II. PET INFORMATION</h4>
            <div class="agr-line">Pet Name: <strong><?php echo htmlspecialchars($cert['pet_name']); ?></strong> &nbsp; Kind: <strong><?php echo htmlspecialchars($cert['kind']); ?></strong></div>
            <div class="agr-line">Breed: <strong><?php echo htmlspecialchars($cert['breed']); ?></strong> &nbsp; Gender: <strong><?php echo htmlspecialchars($cert['gender']); ?></strong></div>
            <div class="agr-line">Age: <strong><?php echo htmlspecialchars($cert['age_group']); ?></strong> &nbsp; Color: <?php echo ed('pet_color', 'agr', 'Color'); ?></div>
            <div class="agr-line">
                Vaccination Status: <?php echo agr_val($vaxSummary); ?>
                &nbsp; Date Released: <?php echo agr_val(!empty($cert['cert_released_at']) ? date('M. j, Y', strtotime($cert['cert_released_at'])) : ''); ?>
            </div>

            <h4>III. RESPONSIBILITIES OF THE ADOPTER</h4>
            <p>By signing this Agreement, the Adopter accepts full responsibility for the adopted pet and agrees to the following:</p>
            <ol class="agr-list">
                <li>To provide the pet with adequate food, clean water, shelter, and a safe living environment at all times.</li>
                <li>To provide proper veterinary care when the pet becomes sick or injured, and to have the pet vaccinated against rabies annually in accordance with Republic Act No. 9482, otherwise known as the Anti-Rabies Act of 2007.</li>
                <li>To keep the pet within the premises of the residence stated in this Agreement and not to allow it to roam freely in public places without proper restraint or supervision.</li>
                <li>To treat the pet humanely at all times and not to subject it to any form of cruelty, neglect, abuse, or maltreatment as prohibited under Republic Act No. 8485, otherwise known as the Animal Welfare Act of 1998, as amended by Republic Act No. 10631.</li>
                <li>Not to sell, trade, give away, abandon, or transfer the pet to another person without the prior written consent of the Office.</li>
                <li>Not to use the pet for any illegal purpose, including but not limited to animal fighting, breeding for sale, or slaughter for human consumption.</li>
                <li>To notify the Office within a reasonable period in the event of any change of address or contact number, or if the pet becomes lost, seriously ill, or dies.</li>
            </ol>

            <?php elseif ($type === 'agreement2'): ?>

            <ol class="agr-list" start="8">
                <li>To allow the Office to conduct announced or unannounced post-adoption visits to verify the condition and welfare of the adopted pet.</li>
                <li>To return the pet to the Office, instead of abandoning it or surrendering it to another party, if the Adopter becomes unable to continue caring for it.</li>
            </ol>

            <h4>IV. RIGHT OF THE OFFICE TO RECLAIM THE PET</h4>
            <p>
                The Adopter understands and agrees that the <strong>City Veterinary Office reserves the right to reclaim and take back
                custody of the adopted pet</strong> if, upon inspection, investigation, or verified report, it is found that:
            </p>
            <ol class="agr-list">
                <li>The pet is not provided with adequate food, water, shelter, or necessary veterinary care;</li>
                <li>The pet is neglected, abandoned, abused, maltreated, or kept in unsanitary or unsafe conditions;</li>
                <li>The pet is used for any illegal or prohibited purpose;</li>
                <li>The pet has been sold, traded, or transferred to another person without the written consent of the Office;</li>
                <li>The Adopter refuses to allow the Office to conduct a post-adoption visit; or</li>
                <li>Any other provision of this Agreement has been violated.</li>
            </ol>
            <p>
                In such cases, the Adopter agrees to surrender the pet to the Office upon demand and waives any claim for the refund
                of the adoption fee or for any expenses incurred in relation to the care of the pet. The Adopter further understands
                that reclaiming the pet shall not prevent the Office from filing the appropriate administrative, civil, or criminal
                action under existing laws and ordinances.
            </p>

            <h4>V. ACKNOWLEDGMENT</h4>
            <p>
                The Adopter acknowledges having read and understood all the provisions of this Agreement, and accepts the adopted pet
                in its present condition. The Adopter further acknowledges that the Office has disclosed all known information
                regarding the health and behavior of the pet at the time of adoption.
            </p>
            <p>IN WITNESS WHEREOF, the parties have signed this Agreement on the date and at the place first written above.</p>

            <div class="agr-signs">
                <div class="agr-sign">
                    <div class="name"><?php echo ed('owner_name', 'sig', 'Adopter name'); ?></div>
                    <div class="rule"></div>
                    <div>Adopter's Signature over Printed Name</div>
                    <div>Date: <?php echo ed('signing_date', 'under', 'Date'); ?></div>
                </div>
                <div class="agr-sign">
                    <div class="name"><?php echo ed('veterinarian_name', 'sig', 'Name of City Veterinarian'); ?>&nbsp;</div>
                    <div class="rule"></div>
                    <div>City Veterinarian</div>
                    <div>Date: <?php echo ed('signing_date', 'under', 'Date'); ?></div>
                </div>
            </div>

            <div class="agr-witness">
                Witness:
                <?php if ($isStaff): ?>
                    <strong><?php echo ed('witness_name', 'sig', 'Witness name'); ?></strong>, <?php echo ed('witness_position', 'agr', 'Position'); ?>,
                <?php elseif (doc_get($doc, 'witness_name') !== ''): ?>
                    <strong><?php echo htmlspecialchars(strtoupper(doc_get($doc, 'witness_name'))); ?></strong><?php echo doc_get($doc, 'witness_position') !== '' ? ', ' . htmlspecialchars(doc_get($doc, 'witness_position')) : ''; ?>,
                <?php else: ?>
                    ________________________________,
                <?php endif; ?>
                City Veterinary Office
            </div>

            <?php elseif ($type === 'vaccination'): ?>

            <h3>OWNER</h3>
            <div class="row">
                <div class="field"><label>Name of Owner:</label><span class="line"><?php echo ed('owner_name', 'line', 'Full name'); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Address:</label><span class="line"><?php echo ed('owner_address', 'line', 'Address'); ?></span></div>
            </div>

            <h3>PET INFORMATION</h3>
            <div class="row">
                <div class="field"><label>Pet Name:</label><span class="line"><?php echo cert_val($cert['pet_name']); ?></span></div>
                <div class="field"><label>Breed:</label><span class="line"><?php echo cert_val($cert['breed']); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Age / Year:</label><span class="line"><?php echo cert_val($cert['age_group']); ?></span></div>
                <div class="field"><label>Gender:</label><span class="line"><?php echo cert_val($cert['gender']); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Color:</label><span class="line"><?php echo ed('pet_color', 'line', 'Color'); ?></span></div>
                <div class="field"><label>Kind:</label><span class="line"><?php echo cert_val($cert['kind']); ?></span></div>
            </div>

            <h3>VACCINATION DETAILS</h3>
            <table class="vax-table">
                <thead>
                    <tr><th>Date Vaccinated</th><th>Vaccine Brand</th><th>Lot / Batch No.</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($vaccines as $v): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($v['checkup_date'])); ?></td>
                            <td><?php echo cert_val($v['notes']); ?></td>
                            <td>&nbsp;</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php for ($i = count($vaccines); $i < 3; $i++): ?>
                        <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <p class="certify" style="margin-top: 34px;">
                This is to certify that the pet described above has been vaccinated by this Office
                as indicated in the record shown.
            </p>

            <div class="signs">
                <div class="sign">
                    <div class="sig-line"><?php echo ed('owner_name', 'sig', 'Adopter name'); ?></div>
                    <strong>Owner's Signature over Printed Name</strong>
                    <div class="sub">Date: <?php echo ed('signing_date', 'under', 'Date'); ?></div>
                </div>
                <div class="sign">
                    <div class="sig-line"><?php echo ed('veterinarian_name', 'sig', 'Name of Veterinarian'); ?></div>
                    <strong>Veterinarian in Charge</strong>
                    <div class="sub">License No.: <?php echo ed('vet_license_no', 'under', 'License No.'); ?></div>
                </div>
            </div>

            <?php else: ?>

            <h3>ADOPTER INFORMATION</h3>
            <div class="row">
                <div class="field"><label>Name:</label><span class="line"><?php echo ed('owner_name', 'line', 'Full name'); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Address:</label><span class="line"><?php echo ed('owner_address', 'line', 'Address'); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Contact No.:</label><span class="line"><?php echo cert_val($cert['phone_number']); ?></span></div>
                <div class="field"><label>Valid ID / No.:</label><span class="line"><?php
                    if ($isStaff) {
                        echo ed('valid_id_type', 'line', 'Type of ID') . ' / ' . ed('valid_id_number', 'line', 'ID number');
                    } else {
                        echo cert_val(trim(doc_get($doc, 'valid_id_type') . ' / ' . doc_get($doc, 'valid_id_number'), ' /'));
                    }
                ?></span></div>
            </div>

            <h3>PET INFORMATION</h3>
            <div class="row">
                <div class="field"><label>Pet Name:</label><span class="line"><?php echo cert_val($cert['pet_name']); ?></span></div>
                <div class="field"><label>Kind:</label><span class="line"><?php echo cert_val($cert['kind']); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Breed:</label><span class="line"><?php echo cert_val($cert['breed']); ?></span></div>
                <div class="field"><label>Sex:</label><span class="line"><?php echo cert_val($cert['gender']); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Age:</label><span class="line"><?php echo cert_val($cert['age_group']); ?></span></div>
                <div class="field"><label>Size / Color:</label><span class="line"><?php
                    if ($isStaff) {
                        echo htmlspecialchars((string) $cert['size']) . ' / ' . ed('pet_color', 'line', 'Color');
                    } else {
                        echo cert_val(trim($cert['size'] . ' / ' . doc_get($doc, 'pet_color'), ' /'));
                    }
                ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Date Admitted:</label><span class="line"><?php echo !empty($cert['rescue_date']) ? date('F j, Y', strtotime($cert['rescue_date'])) : '&nbsp;'; ?></span></div>
                <div class="field"><label>Distinguishing Marks:</label><span class="line"><?php echo ed('distinguishing_marks', 'line', 'e.g. White patch on left ear'); ?></span></div>
            </div>
            <div class="row">
                <div class="field"><label>Vaccination Status:</label><span class="line"><?php echo cert_val($cert['vaccination_status']); ?></span></div>
                <div class="field"><label>Spayed / Neutered:</label><span class="line"><?php echo ed('spayed_neutered', 'line', 'Yes / No'); ?></span></div>
            </div>

            <p class="certify">
                This is to certify that the pet described above was legally adopted from the City Veterinary Office of Caloocan City
                on <span class="fill"><?php echo date('F j, Y', $adoptedTs); ?></span>, and that the care, custody, and responsibility
                for the said animal are hereby transferred to the adopter named herein.
            </p>

            <h3>CONDITIONS OF ADOPTION</h3>
            <ol>
                <li>The adopter shall provide adequate food, shelter, and veterinary care for the adopted pet.</li>
                <li>The adopter shall have the pet vaccinated against rabies annually and registered with the barangay or city.</li>
                <li>The pet shall not be sold, surrendered, or given to another person without notifying this Office.</li>
                <li>This Office may reclaim the pet if it is found neglected, abandoned, or maltreated.</li>
            </ol>

            <div class="signs">
                <div class="sign">
                    <div class="sig-line"><?php echo ed('owner_name', 'sig', 'Adopter name'); ?></div>
                    <strong>Adopter's Signature over Printed Name</strong>
                    <div class="sub">Date: <?php echo ed('signing_date', 'under', 'Date'); ?></div>
                </div>
                <div class="sign">
                    <div class="sig-line"><?php echo ed('veterinarian_name', 'sig', 'Name of City Veterinarian'); ?></div>
                    <strong>City Veterinarian</strong>
                    <div class="sub">City Veterinary Office, Caloocan City</div>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        (function () {
            var fileBase = <?php echo json_encode('Adoption_Documents_' . $certSeq); ?>;
            var btn = document.getElementById('dlBtn');
            var printBtn = document.getElementById('printBtn');
            var select = document.getElementById('dlFormat');

            function saveDataUrl(dataUrl, filename) {
                var a = document.createElement('a');
                a.href = dataUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
            }

            function setBusy(button, busy) {
                if (!button) { return; }
                if (busy) {
                    button.dataset.html = button.innerHTML;
                    button.disabled = true;
                    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing...';
                } else {
                    button.disabled = false;
                    button.innerHTML = button.dataset.html || button.innerHTML;
                }
            }

            // Isa-isang kinukuha ang bawat certificate, laging desktop layout
            // kahit sa cellphone binuksan
            function renderSheets() {
                var sheets = Array.prototype.slice.call(document.querySelectorAll('.sheet'));
                // Telepono: alisin muna ang pagpapaliit (scale) para buong laki ang larawan
                sheets.forEach(function (s) { s.dataset.tf = s.style.transform; s.style.transform = 'none'; });
                var restore = function (v) { sheets.forEach(function (s) { s.style.transform = s.dataset.tf || ''; }); return v; };
                return renderEach(sheets).then(restore, function (e) { restore(); throw e; });
            }
            function renderEach(sheets) {
                return Promise.all(sheets.map(function (sheet) {
                    return html2canvas(sheet, {
                        scale: 2,
                        backgroundColor: '#ffffff',
                        windowWidth: 1000,
                        onclone: function (doc) {
                            doc.body.classList.remove('editing');
                            doc.querySelectorAll('.sheet').forEach(function (s) {
                                s.style.width = '850px';
                                s.style.maxWidth = '850px';
                                s.style.margin = '0';
                                s.style.boxShadow = 'none';
                                s.style.transform = 'none';
                            });
                        }
                    });
                }));
            }

            // Isang page bawat certificate
            function buildPdf(canvases) {
                var pdf = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'pt', format: 'letter' });
                var pageW = pdf.internal.pageSize.getWidth();
                var pageH = pdf.internal.pageSize.getHeight();
                var margin = 24;
                canvases.forEach(function (canvas, i) {
                    if (i > 0) { pdf.addPage(); }
                    var ratio = Math.min((pageW - margin * 2) / canvas.width, (pageH - margin * 2) / canvas.height);
                    var w = canvas.width * ratio;
                    var h = canvas.height * ratio;
                    pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', (pageW - w) / 2, margin, w, h);
                });
                return pdf;
            }

            // ---- Staff: pag-edit direkta sa papel ----
            var saveBtn = document.getElementById('saveBtn');
            var statusEl = document.getElementById('editStatus');
            var dirty = false;

            function setStatus(text, cls) {
                if (!statusEl) { return; }
                statusEl.textContent = text;
                statusEl.className = 'edit-status' + (cls ? ' ' + cls : '');
            }

            function saveChanges() {
                if (!saveBtn || !dirty) { return Promise.resolve(); }
                var data = new FormData();
                data.append('application_id', <?php echo (int) $cert['application_id']; ?>);
                data.append('ajax', '1');
                var seen = {};
                document.querySelectorAll('.ed[data-field]').forEach(function (el) {
                    var f = el.dataset.field;
                    if (seen[f]) { return; }
                    seen[f] = true;
                    data.append(f, el.textContent.trim());
                });
                setBusy(saveBtn, true);
                return fetch('/Caps2/StaffFile/process_certificate_details.php', { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.ok) { throw new Error(res.message || 'Save failed'); }
                        dirty = false;
                        // Ipakita ang na-format na petsa (hal. "10/15/2026" -> "October 15, 2026")
                        Object.keys(res.values || {}).forEach(function (f) {
                            document.querySelectorAll('.ed[data-field="' + f + '"]').forEach(function (el) {
                                el.textContent = res.values[f] || '';
                            });
                        });
                        setStatus('All changes saved', 'saved');
                    })
                    .catch(function (err) {
                        setStatus(err.message || 'Could not save changes', 'error');
                        throw err;
                    })
                    .finally(function () { setBusy(saveBtn, false); });
            }

            if (saveBtn) {
                document.querySelectorAll('.ed[data-field]').forEach(function (el) {
                    // Parehong field sa ibang page (hal. Color, Veterinarian) - sabay na nagbabago
                    el.addEventListener('input', function () {
                        document.querySelectorAll('.ed[data-field="' + el.dataset.field + '"]').forEach(function (other) {
                            if (other !== el) { other.textContent = el.textContent; }
                        });
                        dirty = true;
                        setStatus('Unsaved changes', 'dirty');
                    });
                    // Isang linya lang bawat field
                    el.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
                    });
                    // Plain text lang kapag nag-paste
                    el.addEventListener('paste', function (e) {
                        e.preventDefault();
                        var text = (e.clipboardData || window.clipboardData).getData('text').replace(/\s+/g, ' ');
                        document.execCommand('insertText', false, text);
                    });
                    // Para lumabas ulit ang placeholder kapag binura lahat
                    el.addEventListener('blur', function () {
                        if (el.textContent.trim() === '') { el.textContent = ''; }
                    });
                });

                saveBtn.addEventListener('click', function () { saveChanges().catch(function () {}); });

                var readIdForm = document.getElementById('readIdForm');
                if (readIdForm) {
                    readIdForm.addEventListener('submit', function (e) {
                        e.preventDefault();
                        if (!confirm('Read the adopter\'s name, address, and ID details from the uploaded Valid ID? This replaces what is currently on the papers for those fields.')) {
                            return;
                        }
                        // I-save muna ang ibang binago (hal. kulay ng pet) para hindi mawala
                        saveChanges().then(function () {
                            setBusy(document.getElementById('readIdBtn'), true);
                            readIdForm.submit();
                        }).catch(function () {});
                    });
                }

                window.addEventListener('beforeunload', function (e) {
                    if (dirty) { e.preventDefault(); e.returnValue = ''; }
                });
            }

            // Staff: buksan ang PDF sa bagong tab para doon i-print. Binubuksan agad
            // ang tab sa mismong click para hindi ma-block ng popup blocker.
            if (printBtn) {
                printBtn.addEventListener('click', function () {
                    var win = window.open('', '_blank');
                    setBusy(printBtn, true);
                    saveChanges().then(renderSheets).then(function (canvases) {
                        var url = buildPdf(canvases).output('bloburl');
                        if (win) {
                            win.location.href = url;
                        } else {
                            window.location.href = url;
                        }
                    }).catch(function () {
                        if (win) { win.close(); }
                        alert('Sorry, the PDF could not be generated. Please try again.');
                    }).finally(function () {
                        setBusy(printBtn, false);
                    });
                });
            }

            function download(format) {
                setBusy(btn, true);

                saveChanges().then(renderSheets).then(function (canvases) {
                    if (format === 'pdf') {
                        buildPdf(canvases).save(fileBase + '.pdf');
                        return;
                    }

                    // PNG/JPEG: iisang larawan, magkapatong ang mga certificate
                    var gap = 40;
                    var out = document.createElement('canvas');
                    out.width = Math.max.apply(null, canvases.map(function (c) { return c.width; }));
                    out.height = canvases.reduce(function (sum, c) { return sum + c.height; }, 0) + gap * (canvases.length - 1);
                    var ctx = out.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, out.width, out.height);
                    var y = 0;
                    canvases.forEach(function (c) {
                        ctx.drawImage(c, (out.width - c.width) / 2, y);
                        y += c.height + gap;
                    });

                    if (format === 'jpeg') {
                        saveDataUrl(out.toDataURL('image/jpeg', 0.95), fileBase + '.jpg');
                    } else {
                        saveDataUrl(out.toDataURL('image/png'), fileBase + '.png');
                    }
                }).catch(function () {
                    alert('Sorry, the certificate could not be downloaded. Please try again.');
                }).finally(function () {
                    setBusy(btn, false);
                });
            }

            // Staff lang ang may Download button; wala ito sa adopter
            if (!btn) { return; }
            btn.addEventListener('click', function () { download(select.value); });

            // Galing sa listahan na may napiling format - i-download agad (staff lang)
            var auto = <?php echo json_encode($isStaff && in_array($_GET['download'] ?? '', ['pdf', 'png', 'jpeg'], true) ? $_GET['download'] : ''); ?>;
            if (auto) {
                select.value = auto;
                window.addEventListener('load', function () { download(auto); });
            }
        })();
    </script>
    <script>
        // Telepono: bawat papel ay ibinabalot sa .sheet-fit at pinapaliit (transform: scale)
        // para eksaktong kasya sa lapad ng screen - nananatili ang tunay na ayos ng papel.
        // Ang lalagyan ay binibigyan ng taas na kasinliit din, para walang bakanteng puwang.
        (function () {
            var mq = window.matchMedia('(max-width: 640px)');
            var sheets = Array.prototype.slice.call(document.querySelectorAll('.sheet'));
            var fits = sheets.map(function (sheet) {
                var fit = document.createElement('div');
                fit.className = 'sheet-fit';
                sheet.parentNode.insertBefore(fit, sheet);
                fit.appendChild(sheet);
                return fit;
            });
            function fitAll() {
                sheets.forEach(function (sheet, i) {
                    var fit = fits[i];
                    if (!mq.matches) { sheet.style.transform = ''; fit.style.height = ''; return; }
                    if (sheet.style.transform === 'none') { return; }   // ginagawa ang PDF ngayon
                    var scale = Math.min(1, fit.clientWidth / sheet.offsetWidth);
                    sheet.style.transform = 'scale(' + scale + ')';
                    fit.style.height = Math.ceil(sheet.offsetHeight * scale) + 'px';
                });
            }
            fitAll();
            window.addEventListener('resize', fitAll);
            window.addEventListener('load', fitAll);
            // Nagbabago ang taas kapag nag-type ang staff sa papel
            if (window.ResizeObserver) {
                var ro = new ResizeObserver(fitAll);
                sheets.forEach(function (s) { ro.observe(s); });
            }
        })();
    </script>
</body>
</html>
