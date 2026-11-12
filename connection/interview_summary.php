<?php
/**
 * Interview Summary Report - ang buod ng napag-usapan ng staff at adopter sa
 * interview. Isinusulat ng staff sa Review Application (StaffFile/review_application.php),
 * at nakikita ng adopter sa Adopter_File/interview_summary.php.
 */

const SP_SUMMARY_RESIDENCE_TYPES = [
    'House (owned)',
    'House (rented)',
    'Apartment',
    'Condominium',
    'Living with relatives',
    'Other',
];

const SP_SUMMARY_LANDLORD = ['Yes', 'No', 'Not applicable'];

const SP_SUMMARY_RECOMMENDATIONS = [
    'Recommended',
    'Recommended with conditions',
    'Needs follow-up',
    'Not recommended',
];

/** Edad batay sa birthdate, sa araw ng interview (o ngayon kung wala) */
function sp_summary_age(?string $birthdate, ?string $onDate = null): ?int
{
    if (empty($birthdate) || !strtotime($birthdate)) {
        return null;
    }
    try {
        return (new DateTime($birthdate))->diff(new DateTime($onDate ?: 'today'))->y;
    } catch (Exception $e) {
        return null;
    }
}

/** Ang naka-save na summary ng application, o null */
function sp_interview_summary(PDO $pdo, int $applicationId): ?array
{
    try {
        $st = $pdo->prepare(
            "SELECT s.*, u.username AS staff_name
             FROM interview_summaries s
             LEFT JOIN users u ON u.user_id = s.staff_id
             WHERE s.application_id = ?"
        );
        $st->execute([$applicationId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** Mga paliwanag ng staff na ipinapakita bilang bullet list sa report */
const SP_SUMMARY_BULLET_FIELDS = [
    'Reason for Adopting',
    'Summary of the Discussion',
    'Your Questions or Concerns',
    'Questions or Concerns',
    'Remarks / Next Steps',
];

/**
 * Hinahati ang paliwanag ng staff sa mga punto (para sa bullet list): bawat linya,
 * bawat pangungusap, at bawat "then" na nagsisimula ng bagong punto. Inaalis ang
 * mga naunang marka (-, *, •, 1.) at ginagawang malaking titik ang unang letra.
 *
 * @return string[]
 */
function sp_summary_points(?string $text): array
{
    $points = [];
    foreach (preg_split('/\R+/u', trim((string) $text)) as $line) {
        $parts = preg_split('/(?<=[.!?])\s+(?=\S)|\s+then\s+/iu', trim($line));
        foreach ($parts as $p) {
            $p = trim(preg_replace('/^\s*(?:[-*•·]+|\d+[.)])\s*/u', '', $p));
            if ($p === '') {
                continue;
            }
            $points[] = mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1);
        }
    }
    return $points;
}

/** HTML ng isang value sa report: bullet list para sa paliwanag ng staff, karaniwang text sa iba */
function sp_summary_value_html(string $label, $value): string
{
    if (in_array($label, SP_SUMMARY_BULLET_FIELDS, true)) {
        $points = sp_summary_points((string) $value);
        if ($points) {
            return '<ul class="sum-points">' . implode('', array_map(
                fn ($p) => '<li>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</li>',
                $points
            )) . '</ul>';
        }
    }
    return '<p>' . nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')) . '</p>';
}

/**
 * Isinasalin sa malinis at propesyonal na English ang mga isinulat ng staff
 * (Tagalog, Taglish, o English na may mali), bago i-save at ipakita sa adopter.
 * Hindi binabago ang ibig sabihin; inaayos lang ang wika, spelling at grammar,
 * at isang punto bawat linya para sa mga paliwanag. Kapag naka-off o hindi
 * maabot ang AI, ibinabalik ang orihinal (walang nawawala).
 */
function sp_summary_to_english(array $fields): array
{
    require_once __DIR__ . '/ai_config.php';
    // Field => [pinakamahabang haba, bullet ba (isang punto bawat linya)]
    $spec = [
        'household_members' => [2000, false],
        'other_pets' => [2000, false],
        'pet_experience' => [2000, false],
        'reason_for_adopting' => [2000, true],
        'care_plan' => [2000, false],
        'adopter_questions' => [2000, true],
        'discussion_notes' => [5000, true],
        'remarks' => [2000, true],
    ];
    $input = [];
    foreach ($spec as $k => $_) {
        if (isset($fields[$k]) && trim((string) $fields[$k]) !== '') {
            $input[$k] = (string) $fields[$k];
        }
    }
    if (!$input || !ai_is_configured()) {
        return $fields;
    }

    $schema = [
        'type' => 'object',
        'properties' => array_map(fn () => ['type' => 'string'], $input),
        'required' => array_keys($input),
        'additionalProperties' => false,
    ];
    $bulletKeys = implode(', ', array_keys(array_filter($spec, fn ($s) => $s[1])));
    $system = 'You rewrite interview notes written by staff of the City Veterinary Office of Caloocan about a pet adoption interview. '
        . 'The notes may be in Filipino, Taglish, or English with typos. Translate them into clear, polite, professional English. '
        . 'Keep the exact meaning, names, numbers and facts. Do not add, remove, guess or soften any information. '
        . 'Fix spelling and grammar. Write in the third person about the adopter where the staff wrote "niya/siya" (he or she: use "the adopter"). '
        . 'Pages of the SMART PET system have these exact names, so correct misspellings to them: "My Pets" (where adopters see their adopted pets '
        . 'and report a concern), "Messages", "My Applications", "Payments", "Certificate". Documents: "Barangay ID", "Barangay certificate".';
    $prompt = "Rewrite each field of this JSON in professional English and return the same keys.\n"
        . "For these fields, write one complete sentence per point, each point on its own line, with no bullet symbols or numbering: $bulletKeys.\n"
        . "For the other fields, keep the answer short (a phrase or one sentence).\n\n"
        . json_encode($input, JSON_UNESCAPED_UNICODE);

    $messages = [['role' => 'user', 'content' => $prompt]];
    $result = AI_PROVIDER === 'claude'
        ? claude_chat($system, $messages, 3000, 'low', $schema)
        : gemini_chat($system . ' Reply with only a JSON object with exactly these keys: ' . implode(', ', array_keys($input)) . '.', $messages, 3000, 'low', true);
    if (empty($result['ok'])) {
        return $fields;
    }
    $data = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim((string) $result['text']))), true);
    if (!is_array($data)) {
        return $fields;
    }
    foreach ($input as $k => $_) {
        $v = isset($data[$k]) ? trim((string) $data[$k]) : '';
        if ($v !== '') {
            $fields[$k] = mb_substr($v, 0, $spec[$k][0]);
        }
    }
    return $fields;
}

/**
 * Bahagi ng pirma sa ibaba ng report: staff na nag-interview at adopter.
 * $forPdf = gumamit ng data URI ng larawan (walang remote access ang Dompdf).
 */
function sp_summary_signatures_html(array $summary, string $base = '../', bool $forPdf = false): string
{
    require_once __DIR__ . '/signature_pad.php';
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    // Staff (nag-interview) lang ang pumipirma
    $blocks = [
        ['Interviewed by', $summary['staff_printed_name'] ?? '', $summary['staff_signature'] ?? '', $summary['staff_signed_at'] ?? '', 'Staff, City Veterinary Office', 'Not signed yet'],
    ];
    $cells = [];
    foreach ($blocks as [$title, $name, $file, $at, $role, $empty]) {
        // Kailangan ng GD ang Dompdf para sa PNG; kapag wala, nakasulat na lang na may pirma
        $pdfCanShowImage = extension_loaded('gd');
        $src = $forPdf ? ($pdfCanShowImage ? sp_signature_data_uri($file) : '') : sp_signature_url($file, $base);
        if ($src !== '') {
            $sig = '<img class="sum-sig-img" src="' . $e($src) . '" alt="Signature of ' . $e($name) . '">';
        } elseif ($forPdf && $at && $file) {
            $sig = '<span class="sum-sig-empty">Signed electronically in SMART PET</span>';
        } else {
            $sig = '<span class="sum-sig-empty">' . $e($empty) . '</span>';
        }
        $cells[] = '<div class="sum-sig">'
            . '<span class="sum-sig-title">' . $e($title) . '</span>'
            . '<div class="sum-sig-area">' . $sig . '</div>'
            . '<div class="sum-sig-name">' . ($name !== '' ? $e($name) : '&nbsp;') . '</div>'
            . '<div class="sum-sig-role">Printed name and signature &middot; ' . $e($role) . '</div>'
            . ($at ? '<div class="sum-sig-date">Signed ' . $e(date('F j, Y g:i A', strtotime($at))) . '</div>' : '')
            . '</div>';
    }
    if ($forPdf) {
        // Dompdf: table ang maaasahan; nasa kaliwa ang pirma
        return '<table class="sum-sigs"><tr><td></td><td>' . $cells[0] . '</td></tr></table>';
    }
    return '<div class="sum-sigs">' . implode('', $cells) . '</div>';
}

/** CSS ng bahagi ng pirma (page ng adopter at staff) */
function sp_summary_signatures_css(): string
{
    return '.sum-sigs { display: flex; justify-content: flex-end; margin-top: 26px; }
        .sum-sigs > .sum-sig { width: 100%; max-width: 320px; }
        .sum-sig { display: flex; flex-direction: column; min-width: 0; }
        .sum-sig-title { font-size: 11.5px; font-weight: 700; color: #8a94a6; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 4px; }
        .sum-sig-area { height: 72px; display: flex; align-items: flex-end; justify-content: center; position: relative; z-index: 1; }
        .sum-sig-img { max-height: 74px; max-width: 100%; object-fit: contain; }
        .sum-sig-empty { font-size: 12.5px; color: #9ca3af; font-style: italic; margin-bottom: 8px; }
        /* Pangalan sa ibabaw ng linya; bahagyang nakapatong ang pirma sa pangalan (signature over printed name) */
        .sum-sig-name { margin-top: -12px; padding: 0 0 4px; border-bottom: 1.5px solid #374151; text-align: center; font-weight: 700; font-size: 14px; color: #1f2937; text-transform: uppercase; letter-spacing: .3px; }
        .sum-sig-role { margin-top: 4px; }
        .sum-sig-role { text-align: center; font-size: 11.5px; color: #6b7280; }
        .sum-sig-date { text-align: center; font-size: 11px; color: #9ca3af; margin-top: 2px; }
        @media (max-width: 600px) { .sum-sigs > .sum-sig { max-width: 260px; } }';
}

/** Mga bahagi ng report (pareho sa page at sa PDF); walang laman = null */
function sp_summary_sections(array $summary): array
{
    return [
        'Personal Information' => [
            'Name' => $summary['adopter_name'],
            'Age' => $summary['adopter_age'] !== null ? $summary['adopter_age'] . ' years old' : null,
            'Civil Status' => $summary['civil_status'],
            'Occupation' => $summary['occupation'],
            'Contact Number' => $summary['contact_number'],
            'Address' => $summary['address'],
        ],
        'Home and Household' => [
            'Type of Residence' => $summary['residence_type'],
            'Landlord Allows Pets?' => $summary['landlord_allows_pets'],
            'Household Members' => $summary['household_members'],
            'Other Pets at Home' => $summary['other_pets'],
        ],
        'Pet Care' => [
            'Experience with Pets' => $summary['pet_experience'],
            'Reason for Adopting' => $summary['reason_for_adopting'],
            'Daily Care Plan' => $summary['care_plan'],
            'Hours the Pet Is Left Alone' => $summary['hours_alone'] !== null ? $summary['hours_alone'] . ' hours per day' : null,
            'Monthly Budget for the Pet' => $summary['monthly_budget'],
        ],
        'Discussion' => [
            'Summary of the Discussion' => $summary['discussion_notes'],
            'Your Questions or Concerns' => $summary['adopter_questions'],
            'Remarks / Next Steps' => $summary['remarks'],
        ],
    ];
}

/**
 * PDF ng Interview Summary Report (Dompdf) - ina-attach sa email ng adopter.
 * Ibinabalik ang laman ng PDF, o null kapag hindi nagawa (hal. walang Dompdf).
 */
function sp_summary_pdf(PDO $pdo, int $applicationId): ?string
{
    $autoload = __DIR__ . '/../Adopter_File/dompdf/autoload.inc.php';
    if (!is_file($autoload)) {
        return null;
    }
    try {
        $summary = sp_interview_summary($pdo, $applicationId);
        $st = $pdo->prepare(
            "SELECT aa.application_id, aa.preferred_interview_date, aa.preferred_interview_time,
                    p.name AS pet_name, p.kind AS pet_kind, p.breed AS pet_breed
             FROM adoption_applications aa
             JOIN pet_inventory p ON p.pet_id = aa.pet_id
             WHERE aa.application_id = ?"
        );
        $st->execute([$applicationId]);
        $app = $st->fetch(PDO::FETCH_ASSOC);
        if (!$summary || !$app) {
            return null;
        }

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $interviewAt = strtotime($app['preferred_interview_date'] . ' ' . $app['preferred_interview_time']);
        $recColor = [
            'Recommended' => ['#e6f4ea', '#1e7e34', '#22a05a'],
            'Not recommended' => ['#fdecea', '#b3261e', '#e05545'],
        ][$summary['recommendation']] ?? ['#fff8e6', '#8a5a00', '#f5b83d'];
        $pet = $e($app['pet_name']) . (!empty($app['pet_breed']) ? ' (' . $e($app['pet_kind'] . ' - ' . $app['pet_breed']) . ')' : '');

        // Simpleng table layout - mas maaasahan sa Dompdf kaysa grid/flex
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 36px 40px; }
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
            .brand { color: #1b4d2e; font-size: 12px; font-weight: bold; letter-spacing: 1px; }
            h1 { color: #1b4d2e; font-size: 19px; margin: 4px 0 6px; }
            .meta { color: #6b7280; margin: 2px 0; }
            .meta b { color: #1f2937; }
            .head { border-bottom: 2px solid #e5ece6; padding-bottom: 10px; margin-bottom: 12px; }
            .rec { background: ' . $recColor[0] . '; color: ' . $recColor[1] . '; border-left: 4px solid ' . $recColor[2] . '; padding: 9px 12px; margin-bottom: 6px; }
            .rec small { display: block; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; }
            .rec b { font-size: 14px; }
            h2 { color: #1b4d2e; font-size: 11.5px; text-transform: uppercase; letter-spacing: .5px; margin: 16px 0 6px; border-bottom: 1px solid #eef2ee; padding-bottom: 3px; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 5px 6px; vertical-align: top; border-bottom: 1px solid #f1f3f1; }
            td.l { width: 32%; color: #6b7280; font-weight: bold; font-size: 10px; text-transform: uppercase; }
            td p { margin: 0; }
            ul.sum-points { margin: 0; padding-left: 14px; }
            ul.sum-points li { margin: 0 0 4px; line-height: 1.45; }
            table.sum-sigs td { width: 50%; border: none; padding: 6px 14px 0 0; }
            .sum-sig-title { display: block; color: #6b7280; font-weight: bold; font-size: 9.5px; text-transform: uppercase; }
            .sum-sig-area { height: 58px; text-align: center; }
            .sum-sig-img { max-height: 58px; max-width: 220px; }
            .sum-sig-empty { color: #9ca3af; font-style: italic; font-size: 10px; line-height: 58px; }
            .sum-sig-name { text-align: center; font-weight: bold; text-transform: uppercase; padding-bottom: 3px; border-bottom: 1px solid #374151; }
            .sum-sig-role, .sum-sig-date { text-align: center; color: #6b7280; font-size: 9px; }
            .foot { margin-top: 22px; padding-top: 8px; border-top: 2px solid #e5ece6; color: #6b7280; font-size: 9.5px; }
        </style></head><body>';
        $html .= '<div class="head"><div class="brand">SMART PET &middot; CITY OF CALOOCAN</div>'
            . '<h1>Interview Summary Report</h1>'
            . '<p class="meta">Application <b>APP-' . str_pad((string) $app['application_id'], 3, '0', STR_PAD_LEFT) . '</b> for <b>' . $pet . '</b></p>'
            . '<p class="meta">Interview: <b>' . $e(date('F j, Y \a\t g:i A', $interviewAt)) . '</b></p>'
            . '<p class="meta">Interviewed by: <b>' . $e($summary['staff_name'] ?? 'SMART PET Staff') . '</b></p></div>';
        $html .= '<div class="rec"><small>Staff recommendation</small><b>' . $e($summary['recommendation']) . '</b></div>';

        foreach (sp_summary_sections($summary) as $title => $rows) {
            $rows = array_filter($rows, fn ($v) => $v !== null && $v !== '');
            if (!$rows) {
                continue;
            }
            $html .= '<h2>' . $e($title) . '</h2><table>';
            foreach ($rows as $label => $value) {
                $html .= '<tr><td class="l">' . $e($label) . '</td><td>' . sp_summary_value_html($label, $value) . '</td></tr>';
            }
            $html .= '</table>';
        }
        $html .= sp_summary_signatures_html($summary, '../', true);
        $html .= '<p class="foot">Last updated ' . $e(date('F j, Y g:i A', strtotime($summary['updated_at'])))
            . '. This report was generated by SMART PET. If something in this report is not correct, please message the staff.</p>';
        $html .= '</body></html>';

        require_once $autoload;
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    } catch (Throwable $ex) {
        error_log('sp_summary_pdf failed: ' . $ex->getMessage());
        return null;
    }
}

/**
 * Laman ng form kapag wala pang naka-save: kinukuha sa application ng adopter
 * (pangalan, edad, civil status, atbp.) para hindi na i-type ulit ng staff.
 */
function sp_summary_defaults(array $app): array
{
    return [
        'adopter_name' => $app['full_name'] ?? '',
        'adopter_age' => sp_summary_age($app['birthdate'] ?? null, $app['preferred_interview_date'] ?? null),
        'civil_status' => $app['civil_status'] ?? '',
        'occupation' => $app['occupation'] ?? '',
        'contact_number' => $app['phone_number'] ?? '',
        'address' => $app['address'] ?? '',
        'household_members' => '',
        'residence_type' => '',
        'landlord_allows_pets' => '',
        'other_pets' => ($app['q_current_pets'] ?? '') === 'No' ? 'None' : '',
        'pet_experience' => '',
        'reason_for_adopting' => '',
        'care_plan' => $app['q_responsible_for_care'] ?? '',
        'hours_alone' => isset($app['q_hours_left_alone']) ? (int) $app['q_hours_left_alone'] : null,
        'monthly_budget' => '',
        'adopter_questions' => '',
        'discussion_notes' => '',
        'recommendation' => '',
        'remarks' => '',
    ];
}
