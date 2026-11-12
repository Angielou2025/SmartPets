<?php
/**
 * Interview request: ang adopter ang pumipili ng ARAW (mga araw na bukas ang City
 * Hall, hindi pa lumilipas) at siya rin ang naglalagay ng ORAS sa
 * loob ng oras ng bukas at sara - parehong itinatakda ng staff (sp_interview_settings).
 * Pagkatapos, ang staff ang mag-a-Accept o Decline (may dahilan, ipinapadala sa Messages).
 *
 * Bawat interview ay tumatagal ng SP_INTERVIEW_MINUTES. Walang dalawang adopter na
 * puwedeng magkapatong ang oras: ang UNANG nagpasa ang may-ari ng oras, at ang
 * susunod na tatama roon ay tinatanggihan agad at pinapipili ng bagong oras.
 *
 * adoption_applications.schedule_status:
 *   NULL       = lumang application (nag-book ng slot) - itinuturing na tanggap na
 *   'Pending'  = hinihintay ang desisyon ng staff (nakareserba na ang oras)
 *   'Accepted' = tinanggap ng staff; naka-book na rin sa Interview Availability
 *   'Declined' = hindi pinayagan; bakante na ulit ang oras, pipili ulit ang adopter
 */

const SP_INTERVIEW_MINUTES = 90;       // haba ng isang interview (1 oras at 30 minuto)
// Pinakamaagang puwedeng i-request mula ngayon: 0 = kahit ngayon na (iniikot pataas
// sa susunod na 15 minuto). Hal. 4:28 PM ngayon -> 4:30 PM ang pinakamaaga.
const SP_INTERVIEW_LEAD_MINUTES = 0;
const SP_INTERVIEW_MAX_DAYS = 90;      // hanggang ilang araw mula ngayon puwedeng pumili
const SP_MEETUP_WINDOW_DAYS = 30;      // Pet meet-up: mga araw na mapipili mula ngayon

/**
 * Mga araw at oras na bukas ang City Hall para sa interview - itinatakda ng staff
 * sa Interview Availability (table interview_settings, iisang row lang).
 * days: 1 = Lunes ... 5 = Biyernes, 6 = Sabado, 7 = Linggo (ISO, gaya ng date('N')).
 *
 * break: break ng staff araw-araw - simula ('HH:MM') at haba (30 o 60 minuto), o null.
 *
 * @return array{days:int[], open:string, close:string, breakStart:?string, breakMinutes:int}
 */
function sp_interview_settings(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $settings = ['days' => [1, 2, 3, 4, 5], 'open' => '08:00', 'close' => '17:00', 'breakStart' => null, 'breakMinutes' => 0];
    try {
        $row = $pdo->query("SELECT work_days, open_time, close_time, break_start, break_minutes FROM interview_settings WHERE setting_id = 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $days = array_values(array_filter(array_map('intval', explode(',', (string) $row['work_days'])), fn ($d) => $d >= 1 && $d <= 7));
            $hasBreak = !empty($row['break_start']) && in_array((int) $row['break_minutes'], [30, 60], true);
            $settings = [
                'days' => $days,
                'open' => substr($row['open_time'], 0, 5),
                'close' => substr($row['close_time'], 0, 5),
                'breakStart' => $hasBreak ? substr($row['break_start'], 0, 5) : null,
                'breakMinutes' => $hasBreak ? (int) $row['break_minutes'] : 0,
            ];
        }
    } catch (Exception $e) {
        // wala pa ang table - ang default ang gamit
    }
    return $cache = $settings;
}

/** Ang naka-save na meeting link para sa lahat ng interview, o '' kung wala pa */
function sp_default_meeting_link(PDO $pdo): string
{
    try {
        return (string) $pdo->query("SELECT default_meeting_link FROM interview_settings WHERE setting_id = 1")->fetchColumn();
    } catch (Exception $e) {
        return '';
    }
}

/*
 * ===== Special days: mga araw na sarado ang City Hall =====
 * Dalawang pinanggagalingan, parehong pula sa calendar at hindi mapipili ng adopter
 * kahit bukas na araw ng linggo:
 *   1) Holiday sa Pilipinas - kusang kinukuwenta bawat taon (sp_ph_holidays)
 *   2) Ganap sa City Hall - pinipili ng staff sa calendar (table city_hall_closures)
 */

/** Petsa ng Easter Sunday (Gregorian, "anonymous" na paraan - walang calendar extension) */
function sp_easter(int $year): DateTime
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/**
 * Mga holiday sa Pilipinas sa isang taon (regular at special non-working).
 * Ang Eid'l Fitr at Eid'l Adha ay idinedeklara bawat taon, kaya idinadagdag
 * ito ng staff sa calendar kapag may proklamasyon na.
 *
 * @return array<string, string> 'Y-m-d' => pangalan
 */
function sp_ph_holidays(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    $fixed = [
        '01-01' => "New Year's Day",
        '04-09' => 'Araw ng Kagitingan',
        '05-01' => 'Labor Day',
        '06-12' => 'Independence Day',
        '08-21' => 'Ninoy Aquino Day',
        '11-01' => "All Saints' Day",
        '11-30' => 'Bonifacio Day',
        '12-08' => 'Feast of the Immaculate Conception',
        '12-24' => 'Christmas Eve',
        '12-25' => 'Christmas Day',
        '12-30' => 'Rizal Day',
        '12-31' => 'Last Day of the Year',
    ];
    $list = [];
    foreach ($fixed as $md => $name) {
        $list["$year-$md"] = $name;
    }

    // Semana Santa: batay sa Easter
    $easter = sp_easter($year);
    $list[(clone $easter)->modify('-3 days')->format('Y-m-d')] = 'Maundy Thursday';
    $list[(clone $easter)->modify('-2 days')->format('Y-m-d')] = 'Good Friday';
    $list[(clone $easter)->modify('-1 day')->format('Y-m-d')] = 'Black Saturday';

    // National Heroes Day: huling Lunes ng Agosto
    $list[(new DateTime("last monday of august $year"))->format('Y-m-d')] = 'National Heroes Day';

    // Chinese New Year (batay sa lunar calendar)
    $cny = [2025 => '01-29', 2026 => '02-17', 2027 => '02-06', 2028 => '01-26', 2029 => '02-13', 2030 => '02-03',
            2031 => '01-23', 2032 => '02-11', 2033 => '01-31', 2034 => '02-19', 2035 => '02-08'];
    if (isset($cny[$year])) {
        $list["$year-{$cny[$year]}"] = 'Chinese New Year';
    }

    ksort($list);
    return $cache[$year] = $list;
}

/**
 * Mga ganap sa City Hall na inilagay ng staff (hindi kasama ang holiday).
 *
 * @return array<int, array{id:int, date:string, name:string}> naka-ayos ayon sa petsa
 */
function sp_city_hall_closures(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $rows = $pdo->query("SELECT closure_id, closure_date, name FROM city_hall_closures ORDER BY closure_date")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $cache = []; // wala pa ang table
    }
    return $cache = array_map(fn ($r) => ['id' => (int) $r['closure_id'], 'date' => $r['closure_date'], 'name' => (string) $r['name']], $rows);
}

/**
 * Lahat ng special day (holiday + ganap ng staff) mula $fromYear hanggang $toYear.
 * Kapag parehong may holiday at ganap sa isang araw, ang ganap ng staff ang ipinapakita.
 *
 * @return array<string, string> 'Y-m-d' => pangalan
 */
function sp_interview_special_map(PDO $pdo, ?int $fromYear = null, ?int $toYear = null): array
{
    $fromYear = $fromYear ?? (int) date('Y');
    // Hanggang sa pinakahuling araw na mapipili ng adopter
    $toYear = $toYear ?? (int) date('Y', strtotime('+' . SP_INTERVIEW_MAX_DAYS . ' days'));
    $map = [];
    for ($y = $fromYear; $y <= $toYear; $y++) {
        $map += sp_ph_holidays($y);
    }
    foreach (sp_city_hall_closures($pdo) as $c) {
        $y = (int) substr($c['date'], 0, 4);
        if ($y >= $fromYear && $y <= $toYear) {
            $map[$c['date']] = $c['name'];
        }
    }
    ksort($map);
    return $map;
}

/** Pangalan ng special day sa petsang 'Y-m-d', o null kung hindi special day */
function sp_interview_special_on(PDO $pdo, string $date): ?string
{
    $y = (int) substr($date, 0, 4);
    return sp_interview_special_map($pdo, $y, $y)[$date] ?? null;
}

function sp_interview_minutes(string $hhmm): int
{
    return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
}

function sp_interview_label(int $minutes): string
{
    return date('g:i A', mktime(intdiv($minutes, 60), $minutes % 60));
}

/** Break ng staff bilang [simula, tapos] sa minuto, o null kung walang break */
function sp_interview_break(array $settings): ?array
{
    if ($settings['breakStart'] === null) {
        return null;
    }
    $start = sp_interview_minutes($settings['breakStart']);
    return [$start, $start + $settings['breakMinutes']];
}

/**
 * Mga oras na kinuha na (Pending o Accepted), naka-grupo kada araw, mula ngayon.
 * $excludeAppId = huwag isama ang sariling request (kapag nagpapalit ng araw).
 *
 * @return array<string, array<int, array{0:int, 1:int}>> petsa => [[simula, tapos], ...] sa minuto
 */
function sp_interview_taken(PDO $pdo, int $excludeAppId = 0, ?string $onlyDate = null): array
{
    $sql = "SELECT preferred_interview_date AS d, preferred_interview_time AS t
            FROM adoption_applications
            WHERE application_status IN ('Pending', 'Under Review')
              AND interview_missed_at IS NULL
              AND (schedule_status IS NULL OR schedule_status IN ('Pending', 'Accepted'))
              AND application_id <> ?
              AND preferred_interview_date >= CURDATE()";
    $params = [$excludeAppId];
    if ($onlyDate !== null) {
        $sql .= " AND preferred_interview_date = ?";
        $params[] = $onlyDate;
    }
    $taken = [];
    try {
        $stmt = $pdo->prepare($sql . " ORDER BY preferred_interview_date, preferred_interview_time");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $start = sp_interview_minutes($r['t']);
            $taken[$r['d']][] = [$start, $start + SP_INTERVIEW_MINUTES];
        }
    } catch (Exception $e) {
        return [];
    }
    return $taken;
}

/** Lahat ng kailangan ng calendar ng adopter (Adopter_File/_interview_picker.php) */
function sp_interview_picker_config(PDO $pdo, int $excludeAppId = 0): array
{
    $settings = sp_interview_settings($pdo);
    return [
        'days' => $settings['days'],
        'open' => $settings['open'],
        'close' => $settings['close'],
        'break' => sp_interview_break($settings),
        'special' => (object) sp_interview_special_map($pdo), // 'Y-m-d' => pangalan (laging object sa JSON)
        'length' => SP_INTERVIEW_MINUTES,
        'lead' => SP_INTERVIEW_LEAD_MINUTES,
        'maxDate' => date('Y-m-d', strtotime('+' . SP_INTERVIEW_MAX_DAYS . ' days')),
        'taken' => sp_interview_taken($pdo, $excludeAppId),
    ];
}

/**
 * Sinusuri ang napiling araw at oras ng adopter. null kapag ayos; kung hindi,
 * ang mensaheng ipapakita sa adopter. Tawagin habang hawak ang sp_interview_lock().
 */
function sp_interview_request_error(PDO $pdo, string $date, string $time, int $excludeAppId = 0): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)
        || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        return 'Please choose an interview date on the calendar and enter your preferred time.';
    }
    $settings = sp_interview_settings($pdo);
    if (!in_array((int) date('N', strtotime($date)), $settings['days'], true)) {
        return 'City Hall is not open for interviews on that day. Please choose another date.';
    }
    $special = sp_interview_special_on($pdo, $date);
    if ($special !== null) {
        return date('F j, Y', strtotime($date)) . ' is a special day (' . $special . '). City Hall is closed for interviews on this day. Please choose another date.';
    }
    if ($date < date('Y-m-d')) {
        return 'That date has already passed. Please choose another date.';
    }
    if ($date > date('Y-m-d', strtotime('+' . SP_INTERVIEW_MAX_DAYS . ' days'))) {
        return 'Please choose a date within the next ' . SP_INTERVIEW_MAX_DAYS . ' days.';
    }

    // Wala nang 1 oras at 30 minutong bakante sa araw na ito (dahil sa oras ngayon,
    // break ng staff, o mga naka-schedule na) - sa ibang araw na pipili
    $free = sp_interview_free_ranges($settings, $date, sp_interview_taken($pdo, $excludeAppId, $date)[$date] ?? []);
    if (!$free) {
        return 'There is no more interview time left on ' . date('F j, Y', strtotime($date))
            . '. Each interview takes 1 hour and 30 minutes and must end by ' . sp_interview_label(sp_interview_minutes($settings['close']))
            . '. Please choose another date.';
    }

    $start = sp_interview_minutes($time);
    $open = sp_interview_minutes($settings['open']);
    $lastStart = sp_interview_minutes($settings['close']) - SP_INTERVIEW_MINUTES;
    if ($start < $open || $start > $lastStart) {
        return 'Please choose a time from ' . sp_interview_label($open) . ' to ' . sp_interview_label($lastStart) . '.';
    }
    // Hindi puwedeng lumipas na oras (may 2 minutong palugit habang pinipindot ang Submit)
    if (strtotime($date . ' ' . $time) < time() + SP_INTERVIEW_LEAD_MINUTES * 60 - 120) {
        return SP_INTERVIEW_LEAD_MINUTES > 0
            ? 'Please choose a time at least ' . SP_INTERVIEW_LEAD_MINUTES . ' minutes from now.'
            : 'That time has already passed. Please choose a later time.';
    }

    $end = $start + SP_INTERVIEW_MINUTES;

    // Tumatama sa break ng staff
    $break = sp_interview_break($settings);
    if ($break !== null && $start < $break[1] && $end > $break[0]) {
        return 'That time overlaps with the staff break from ' . sp_interview_label($break[0]) . ' to ' . sp_interview_label($break[1])
            . '. Each interview takes 1 hour and 30 minutes, so please choose a time that starts at '
            . sp_interview_label($break[1]) . ' or later, or one that ends by ' . sp_interview_label($break[0]) . '.';
    }

    // May nauna nang adopter na tatama sa oras na ito. May 1 minutong puwang sa
    // pagitan ng mga interview: kapag tapos ang isa nang 9:32, 9:33 ang pinakamaaga.
    $taken = sp_interview_taken($pdo, $excludeAppId, $date)[$date] ?? [];
    foreach ($taken as [$s, $e]) {
        if (sp_interview_clashes($start, $s, $e)) {
            return 'Sorry, that time is already booked by another adopter (' . sp_interview_label($s) . ' - ' . sp_interview_label($e) . '). '
                . 'Each interview takes 1 hour and 30 minutes. On ' . date('M j, Y', strtotime($date)) . ' you can choose a start time from '
                . sp_interview_ranges_text($free) . ', or pick another day.';
        }
    }
    return null;
}

/** Tumatama ba ang interview na nagsisimula sa $start sa naka-book na [$s, $e] (may 1 minutong puwang) */
function sp_interview_clashes(int $start, int $s, int $e): bool
{
    return $start <= $e && $start + SP_INTERVIEW_MINUTES >= $s;
}

/** Hal. "8:00 AM to 10:29 AM, or 1:00 PM to 6:30 PM" */
function sp_interview_ranges_text(array $ranges): string
{
    return implode(', or ', array_map(
        fn ($r) => $r[0] === $r[1] ? sp_interview_label($r[0]) : sp_interview_label($r[0]) . ' to ' . sp_interview_label($r[1]),
        $ranges
    ));
}

/**
 * Mga oras na puwede pang SIMULAN ang interview sa isang araw (bawat minuto), bilang
 * mga hanay [[mula, hanggang], ...] sa minuto. Kasama na ang oras ngayon (kung ngayong
 * araw), ang break ng staff, at ang mga oras na kinuha na (may 1 minutong puwang).
 * Walang laman = wala nang 1 oras at 30 minutong bakante bago mag-out.
 *
 * @param array<int, array{0:int, 1:int}> $taken
 * @return array<int, array{0:int, 1:int}>
 */
function sp_interview_free_ranges(array $settings, string $date, array $taken): array
{
    $open = sp_interview_minutes($settings['open']);
    $lastStart = sp_interview_minutes($settings['close']) - SP_INTERVIEW_MINUTES;
    $min = $open;
    if ($date === date('Y-m-d')) {
        // Susunod na buong minuto mula ngayon (hal. 8:01 ngayon -> 8:02)
        $min = max($min, (int) date('G') * 60 + (int) date('i') + SP_INTERVIEW_LEAD_MINUTES + 1);
    }
    $break = sp_interview_break($settings);
    $ranges = [];
    $runStart = null;
    $last = null;
    for ($t = $min; $t <= $lastStart; $t++) {
        $ok = !($break !== null && $t < $break[1] && $t + SP_INTERVIEW_MINUTES > $break[0]);
        if ($ok) {
            foreach ($taken as [$s, $e]) {
                if (sp_interview_clashes($t, $s, $e)) {
                    $ok = false;
                    break;
                }
            }
        }
        if ($ok) {
            if ($runStart === null) {
                $runStart = $t;
            }
            $last = $t;
        } elseif ($runStart !== null) {
            $ranges[] = [$runStart, $last];
            $runStart = null;
        }
    }
    if ($runStart !== null) {
        $ranges[] = [$runStart, $last];
    }
    return $ranges;
}
/**
 * Lock para sa pagsuri + pag-save ng oras, para kapag sabay na nagpasa ang dalawang
 * adopter, isa lang ang makakakuha ng oras (ang nauna).
 */
function sp_interview_lock(PDO $pdo): bool
{
    try {
        return (int) $pdo->query("SELECT GET_LOCK('sp_interview_schedule', 10)")->fetchColumn() === 1;
    } catch (Exception $e) {
        return false;
    }
}

function sp_interview_unlock(PDO $pdo): void
{
    try {
        $pdo->query("SELECT RELEASE_LOCK('sp_interview_schedule')")->fetchColumn();
    } catch (Exception $e) {
        // kusa ring nawawala ang lock pagsara ng connection
    }
}

/**
 * Pagka-Accept ng staff: inilalagay sa Interview Availability bilang Booked na
 * oras ng adopter (para gumana ang Meet-up, Not Attended at iba pa roon), at
 * inaalis ang mga Open na slot na sumasapaw sa oras na iyon.
 */
function sp_interview_book_accepted(PDO $pdo, array $application, int $staff_id): void
{
    $date = $application['preferred_interview_date'];
    $start = date('H:i:s', strtotime($application['preferred_interview_time']));
    $end = date('H:i:s', strtotime($start) + SP_INTERVIEW_MINUTES * 60);
    $appId = (int) $application['application_id'];

    // Ang dating naka-book para sa application na ito (hal. tinanggap na dati) ay binubuksan ulit
    $pdo->prepare(
        "UPDATE appointment_slots SET status = 'Open', booked_by_application_id = NULL
         WHERE booked_by_application_id = ? AND TIMESTAMP(slot_date, end_time) > NOW()"
    )->execute([$appId]);

    $pdo->prepare(
        "DELETE FROM appointment_slots
         WHERE slot_date = ? AND status = 'Open' AND start_time < ? AND end_time > ?"
    )->execute([$date, $end, $start]);

    try {
        $pdo->prepare(
            "INSERT INTO appointment_slots (slot_date, start_time, end_time, status, created_by, booked_by_application_id)
             VALUES (?, ?, ?, 'Booked', ?, ?)"
        )->execute([$date, $start, $end, $staff_id, $appId]);
    } catch (PDOException $e) {
        // May lumang naka-book na slot sa eksaktong parehong oras - tinanggap pa rin
        // ng staff, kaya hindi ito hadlang; ang application ang pinagbabatayan.
    }
}

/** Hal. "Thursday, October 8, 2026 at 2:30 PM" */
function sp_interview_when(array $application, string $dateFormat = 'l, F j, Y'): string
{
    return date($dateFormat, strtotime($application['preferred_interview_date']))
        . ' at ' . date('g:i A', strtotime($application['preferred_interview_time']));
}
