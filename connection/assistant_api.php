<?php
// Sa online hosting, ang mga PHP warning ay puwedeng maipit bago ang JSON at
// masira ang sagot (lalabas sa chat ang "nagkaproblema sa koneksyon"). Kaya
// hindi ipinapakita ang mga warning, at kapag may fatal error, malinis na JSON
// pa rin ang ibinabalik - kasama ang dahilan sa "debug" para madaling ayusin.
ini_set('display_errors', '0');
@set_time_limit(90);
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json');
        }
        error_log('SMART PET chatbot fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode([
            'reply' => 'Pasensya na po, may aberya sa server ngayon. Puwede po bang subukan ulit maya-maya?',
            'debug' => $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')',
        ]);
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/database.php';
require_once __DIR__ . '/match_scoring.php';
require_once __DIR__ . '/ai_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['reply' => "Kailangan mo munang mag-login para magamit ang chat assistant."]);
    exit();
}

$role = $_SESSION['role'] ?? 'Adopter';
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'there';

// Tinanggal na ang AI assistant sa Staff, kaya sarado rin ang endpoint sa kanila
// kahit direktang tawagin.
if ($role === 'Staff') {
    http_response_code(403);
    echo json_encode(['reply' => 'The chat assistant is not available for staff accounts.']);
    exit();
}

// Ang laman ng request ay naka-base64 sa field na "p" (form-encoded). Sa free
// hosting gaya ng InfinityFree, hinaharang ng firewall (ModSecurity) ang mga POST
// na may JSON o mahabang text - "Error 403" bago pa umabot sa PHP. Hindi nito
// nababasa ang base64, kaya dumadaan. Tinatanggap pa rin ang lumang raw JSON.
if (isset($_POST['p'])) {
    $input = json_decode((string) base64_decode((string) $_POST['p'], true), true);
} else {
    $input = json_decode(file_get_contents('php://input'), true);
}
if (!is_array($input)) {
    $input = [];
}
$message = trim($input['message'] ?? '');

// Listahan ng mga naunang usapan ng user na ito, pinakabago muna
if (($input['action'] ?? '') === 'history') {
    try {
        $stmt = $pdo->prepare(
            'SELECT s.session_id, s.started_at, s.ended_at, s.message_count,
                    (SELECT m.message FROM chat_messages m
                      WHERE m.session_id = s.session_id AND m.sender = \'user\'
                      ORDER BY m.chat_message_id ASC LIMIT 1) AS first_message
               FROM chat_sessions s
              WHERE s.user_id = ? AND s.user_role = ? AND s.message_count > 0
              ORDER BY s.session_id DESC
              LIMIT 30'
        );
        // Kasama ang role: kung naging Staff ang dating Adopter, hindi na dapat
        // lumalabas sa kanya ang mga lumang usapan niya bilang Adopter.
        $stmt->execute([$user_id, $role]);
        echo json_encode(['ok' => true, 'sessions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'sessions' => []]);
    }
    exit();
}

/**
 * Kanya ba talaga ang usapang ito, sa kasalukuyan niyang role? Ito ang bantay
 * ng lahat ng aksyon sa isang partikular na session.
 */
function sp_owns_session(PDO $pdo, int $sessionId, int $user_id, string $role): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM chat_sessions WHERE session_id = ? AND user_id = ? AND user_role = ?');
    $stmt->execute([$sessionId, $user_id, $role]);
    return (bool) $stmt->fetchColumn();
}

// Buong palitan sa loob ng isang naunang usapan, kasama ang pet cards
if (($input['action'] ?? '') === 'transcript') {
    $wanted = (int) ($input['session_id'] ?? 0);
    try {
        if (!sp_owns_session($pdo, $wanted, $user_id, $role)) {
            echo json_encode(['ok' => false, 'messages' => []]);
            exit();
        }

        $head = $pdo->prepare('SELECT started_at FROM chat_sessions WHERE session_id = ?');
        $head->execute([$wanted]);
        $startedAt = $head->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT sender, message, payload FROM chat_messages WHERE session_id = ? ORDER BY chat_message_id ASC'
        );
        $stmt->execute([$wanted]);

        $messages = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $extra = null;
            if (!empty($m['payload'])) {
                $decoded = json_decode($m['payload'], true);
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
            $messages[] = ['sender' => $m['sender'], 'message' => $m['message'], 'extra' => $extra];
        }

        echo json_encode(['ok' => true, 'started_at' => $startedAt, 'messages' => $messages]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'messages' => []]);
    }
    exit();
}

// Ipagpatuloy ang isang lumang usapan: buksan itong muli para doon idugtong ang
// mga susunod na mensahe, at isara ang iba pang nakabukas.
if (($input['action'] ?? '') === 'resume') {
    $wanted = (int) ($input['session_id'] ?? 0);
    try {
        if (sp_owns_session($pdo, $wanted, $user_id, $role)) {
            $pdo->prepare(
                'UPDATE chat_sessions SET ended_at = NOW()
                  WHERE user_id = ? AND user_role = ? AND ended_at IS NULL AND session_id <> ?'
            )->execute([$user_id, $role, $wanted]);
            $pdo->prepare(
                'UPDATE chat_sessions SET ended_at = NULL, last_activity_at = NOW() WHERE session_id = ?'
            )->execute([$wanted]);
        }
    } catch (Exception $e) { /* kung mabigo, bagong session na lang ang gagawin */ }
    echo json_encode(['ok' => true]);
    exit();
}

// Burahin ang isang usapan. Kasama ang mga mensahe nito dahil sa ON DELETE CASCADE.
if (($input['action'] ?? '') === 'delete') {
    $wanted = (int) ($input['session_id'] ?? 0);
    $ok = false;
    try {
        if (sp_owns_session($pdo, $wanted, $user_id, $role)) {
            $pdo->prepare('DELETE FROM chat_sessions WHERE session_id = ?')->execute([$wanted]);
            $ok = true;
        }
    } catch (Exception $e) {
        $ok = false;
    }
    echo json_encode(['ok' => $ok]);
    exit();
}

// Burahin ang lahat ng usapan ng user na ito sa kasalukuyan niyang role
if (($input['action'] ?? '') === 'delete_all') {
    $ok = false;
    try {
        $pdo->prepare('DELETE FROM chat_sessions WHERE user_id = ? AND user_role = ?')->execute([$user_id, $role]);
        $ok = true;
    } catch (Exception $e) {
        $ok = false;
    }
    echo json_encode(['ok' => $ok]);
    exit();
}

// Isinara ng user ang chat, kaya may tiyak na tayong oras ng pagtatapos
if (($input['action'] ?? '') === 'end') {
    try {
        $pdo->prepare('UPDATE chat_sessions SET ended_at = NOW() WHERE user_id = ? AND ended_at IS NULL')
            ->execute([$user_id]);
    } catch (Exception $e) { /* hindi kailangang malaman ng user */ }
    echo json_encode(['ok' => true]);
    exit();
}

// Larawang inilakip ng user (screenshot, litrato ng pet, atbp.) - kinukuha at
// vinavalidate dito, saka isinasama sa tanong na ipapadala sa AI (Claude
// vision). Ang totoong file ang naka-save, hindi ang base64, para hindi
// pumaltos ang database.
$hasImage = false;
$imageBlock = null;
$savedImagePath = null;
$imageInput = is_array($input['image'] ?? null) ? $input['image'] : null;

if ($imageInput) {
    $imageMediaType = (string) ($imageInput['media_type'] ?? '');
    $imageDataRaw = (string) ($imageInput['data'] ?? '');
    $allowedImageTypes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    if (isset($allowedImageTypes[$imageMediaType]) && $imageDataRaw !== '') {
        // Baka may kasamang "data:image/png;base64," prefix pa - tanggalin muna
        $comma = strpos($imageDataRaw, ',');
        $base64 = ($comma !== false && stripos(substr($imageDataRaw, 0, $comma), 'base64') !== false)
            ? substr($imageDataRaw, $comma + 1)
            : $imageDataRaw;

        $decoded = base64_decode($base64, true);
        if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 6 * 1024 * 1024) {
            // Walang "chat" sa pangalan - hinaharang ng InfinityFree ang URL na may "chat"
            $filename = 'assist_img_' . bin2hex(random_bytes(8)) . '.' . $allowedImageTypes[$imageMediaType];
            if (@file_put_contents(__DIR__ . '/../uploads/' . $filename, $decoded) !== false) {
                $savedImagePath = $filename;
                $hasImage = true;
                $imageBlock = [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $imageMediaType, 'data' => $base64],
                ];
            }
        }
    }
}

if ($message === '' && !$hasImage) {
    echo json_encode(['reply' => "Maaari mo bang ulitin ang tanong mo?"]);
    exit();
}

// Huwag hayaang punuin ng sobrang haba ang isang tanong
if (mb_strlen($message) > 1000) {
    $message = mb_substr($message, 0, 1000);
}

// Ang mga naunang palitan ng mensahe, galing sa browser - ito ang nagbibigay
// ng memorya sa usapan. Nililinis muna bago ipadala sa AI.
$history = [];
foreach ((array) ($input['history'] ?? []) as $turn) {
    $turnRole = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $turnText = trim((string) ($turn['text'] ?? ''));
    if ($turnText === '') {
        continue;
    }
    $history[] = ['role' => $turnRole, 'content' => mb_substr($turnText, 0, 1000)];
}
$history = array_slice($history, -10); // huling 5 palitan lang

// Dapat sa 'user' nagsisimula ang usapan para sa AI, kaya tanggalin ang
// mga naunang sagot ng bot na walang katapat na tanong (hal. ang pagbati).
while ($history && $history[0]['role'] === 'assistant') {
    array_shift($history);
}

$text = strtolower($message);

function sp_contains(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (strpos($haystack, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Ibinabalik ang kasalukuyang bukas na chat session ng user, o gumagawa ng bago.
 *
 * Isinasara muna ang mga naiwang bukas na usapan na 30 minuto nang tahimik -
 * doon nagtatapos ang isang session kapag hindi naman nag-close ang user.
 */
function sp_chat_session(PDO $pdo, int $user_id, string $role): int
{
    $pdo->prepare(
        'UPDATE chat_sessions SET ended_at = last_activity_at
         WHERE user_id = ? AND ended_at IS NULL
           AND last_activity_at < (NOW() - INTERVAL 30 MINUTE)'
    )->execute([$user_id]);

    // Hiwalay ang usapan kada role, kaya hindi nadudugtong ang mensahe ng Staff
    // sa naiwang bukas na session niya noong Adopter pa siya.
    $stmt = $pdo->prepare(
        'SELECT session_id FROM chat_sessions
         WHERE user_id = ? AND user_role = ? AND ended_at IS NULL
         ORDER BY session_id DESC LIMIT 1'
    );
    $stmt->execute([$user_id, $role]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        return (int) $existing;
    }

    $pdo->prepare('INSERT INTO chat_sessions (user_id, user_role) VALUES (?, ?)')
        ->execute([$user_id, $role]);

    return (int) $pdo->lastInsertId();
}

function sp_log_chat(PDO $pdo, int $sessionId, string $sender, string $text, ?array $payload = null): void
{
    $pdo->prepare('INSERT INTO chat_messages (session_id, sender, message, payload) VALUES (?, ?, ?, ?)')
        ->execute([$sessionId, $sender, $text, $payload ? json_encode($payload) : null]);

    $pdo->prepare(
        'UPDATE chat_sessions SET last_activity_at = NOW(), message_count = message_count + 1
         WHERE session_id = ?'
    )->execute([$sessionId]);
}

/**
 * Totoong datos mula sa database na ibinibigay natin sa AI bawat tanong.
 * Ito ang pumipigil sa kanya na mag-imbento ng pet, bilang, o status.
 */
function sp_live_context(PDO $pdo, string $role, int $user_id): string
{
    $lines = [];

    try {
        $available = (int) $pdo->query("SELECT COUNT(*) FROM pet_inventory WHERE status = 'Available'")->fetchColumn();
        $lines[] = "- Pets currently available for adoption: $available";

        if ($role === 'Adopter') {
            $prefStmt = $pdo->prepare('SELECT * FROM adopter_preferences WHERE user_id = ? LIMIT 1');
            $prefStmt->execute([$user_id]);
            $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);

            if (!$prefs) {
                $lines[] = '- This adopter has NOT filled in their preferences yet, so no matching is possible for them and they cannot apply for a pet yet.';
            } else {
                $lines[] = '- Their preferences on file: home is ' . ($prefs['living_space'] ?? 'not stated')
                    . ', activity level ' . ($prefs['activity_level'] ?? 'not stated')
                    . ', daily time available ' . ($prefs['time_commitment'] ?? 'not stated')
                    . ', pet experience ' . ($prefs['pet_experience'] ?? 'not stated')
                    . ', allergies ' . (($prefs['allergies'] ?? '') ?: 'none') . '.';

                // Sadyang WALANG porsyento o score dito. Hindi dapat malaman ng
                // adopter kung paano kinukuwenta ang pagkakabagay - ranggo lang.
                $rec = sp_adopter_recommendations($pdo, $user_id);
                if (!empty($rec['pets'])) {
                    $names = [];
                    foreach ($rec['pets'] as $i => $p) {
                        $names[] = '#' . ($i + 1) . ' ' . $p['name'] . ' (' . $p['kind'] . ', ' . $p['breed'] . ', ' . $p['age_group'] . ', ' . $p['waiting'] . ')';
                    }
                    $lines[] = '- Their current top matches, best fit first: ' . implode('; ', $names) . '.';
                } else {
                    $lines[] = '- No available pet is a strong enough fit for them right now.';
                }
            }

            $appStmt = $pdo->prepare(
                "SELECT aa.application_status, aa.created_at, pi.name FROM adoption_applications aa
                 JOIN pet_inventory pi ON pi.pet_id = aa.pet_id
                 WHERE aa.adopter_id = ? ORDER BY aa.created_at DESC LIMIT 3"
            );
            $appStmt->execute([$user_id]);
            $apps = $appStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($apps) {
                $parts = [];
                foreach ($apps as $a) {
                    $parts[] = $a['name'] . ' is "' . $a['application_status'] . '" (applied ' . date('M j, Y', strtotime($a['created_at'])) . ')';
                }
                $lines[] = '- Their adoption applications, newest first: ' . implode('; ', $parts) . '.';
            } else {
                $lines[] = '- They have not submitted any adoption application yet.';
            }
        }

        if ($role === 'Staff' || $role === 'Admin') {
            $pending = (int) $pdo->query("SELECT COUNT(*) FROM adoption_applications WHERE application_status IN ('Pending', 'Under Review')")->fetchColumn();
            $adopted = (int) $pdo->query("SELECT COUNT(*) FROM pet_inventory WHERE status = 'Adopted'")->fetchColumn();
            $lines[] = "- Applications waiting for review (Pending or Under Review): $pending";
            $lines[] = "- Pets already adopted: $adopted";
        }

        // Ang totoong laman ng araw ng staff. Ito ang nagpapasagot sa kanya nang
        // tiyak sa "ano ang dapat kong asikasuhin ngayon?"
        if ($role === 'Staff') {
            // Kasama rito ang mga lumang task na hindi pa natatapos - hindi na
            // ito nawawala kapag nagbago ang araw, kaya dapat pareho ang
            // sinasabi ng AI dito sa nakikita ng staff sa Daily Schedule page.
            $taskStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(is_done = 0), 0) AS todo, COALESCE(SUM(is_done = 1), 0) AS done
                   FROM daily_tasks WHERE staff_id = ? AND (task_date = CURDATE() OR is_done = 0)"
            );
            $taskStmt->execute([$user_id]);
            $tasks = $taskStmt->fetch(PDO::FETCH_ASSOC) ?: ['todo' => 0, 'done' => 0];
            $lines[] = '- Their own Daily Schedule: ' . (int) $tasks['todo']
                . ' task(s) still open (including anything overdue from before today), ' . (int) $tasks['done'] . ' done today.';

            $nextStmt = $pdo->prepare(
                "SELECT task_name, task_time FROM daily_tasks
                  WHERE staff_id = ? AND is_done = 0
                  ORDER BY task_date ASC, task_time IS NULL, task_time ASC LIMIT 3"
            );
            $nextStmt->execute([$user_id]);
            $next = $nextStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($next) {
                $parts = [];
                foreach ($next as $n) {
                    $parts[] = $n['task_name'] . ($n['task_time'] ? ' at ' . date('g:i A', strtotime($n['task_time'])) : '');
                }
                $lines[] = '- Their next open tasks today: ' . implode('; ', $parts) . '.';
            }

            $openSlots = (int) $pdo->query(
                "SELECT COUNT(*) FROM appointment_slots WHERE status = 'Open' AND slot_date >= CURDATE()"
            )->fetchColumn();
            $bookedSoon = (int) $pdo->query(
                "SELECT COUNT(*) FROM appointment_slots
                  WHERE status = 'Booked' AND slot_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 7 DAY)"
            )->fetchColumn();
            $lines[] = "- Interview slots: $openSlots open upcoming, $bookedSoon booked within the next 7 days.";

            $notCleared = (int) $pdo->query("SELECT COUNT(*) FROM pet_intakes WHERE status <> 'Cleared'")->fetchColumn();
            $lines[] = "- Pet intakes not yet cleared (still in Assessment, Vaccination, or Quarantine): $notCleared";

            $unpaid = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'")->fetchColumn();
            $lines[] = "- Adoption payments still pending: $unpaid";
        }

        // Ang tinitingnan ng Admin ay ang buong shelter, hindi ang sariling
        // gawain: mga tao, pera, at ang galaw ng sistema.
        if ($role === 'Admin') {
            $adopters = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Adopter'")->fetchColumn();
            $staffCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Staff'")->fetchColumn();
            $lines[] = "- Registered accounts: $adopters adopter(s) and $staffCount staff member(s).";

            $approvedMonth = (int) $pdo->query(
                "SELECT COUNT(*) FROM adoption_applications
                  WHERE application_status = 'Approved'
                    AND MONTH(updated_at) = MONTH(CURRENT_DATE())
                    AND YEAR(updated_at) = YEAR(CURRENT_DATE())"
            )->fetchColumn();
            $lines[] = "- Applications approved this month: $approvedMonth";

            $money = $pdo->query(
                "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM payments
                  WHERE status = 'Paid'
                    AND MONTH(payment_date) = MONTH(CURRENT_DATE())
                    AND YEAR(payment_date) = YEAR(CURRENT_DATE())"
            )->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'total' => 0];
            $lines[] = '- Adoption fees collected this month: ' . (int) $money['n']
                . ' payment(s) totalling PHP ' . number_format((float) $money['total'], 2) . '.';

            $logsToday = (int) $pdo->query(
                "SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()"
            )->fetchColumn();
            $lines[] = "- Staff and admin actions recorded in Activity Logs today: $logsToday";
        }
    } catch (Exception $e) {
        // Kahit bigo ang database, gumagana pa rin ang AI - wala lang siyang live na datos
    }

    return $lines ? implode("\n", $lines) : '- No live data could be read right now.';
}

/**
 * Ang papel at mga hangganan ng SMART PET Assistant.
 */
function sp_system_prompt(string $role, string $username, string $context): string
{
    $pagesByRole = [
        'Adopter' => 'Dashboard, My Preference, AI Recommendation, My Pets, Favorites, Messages, Notifications, Payments, My Applications, Search Pets, Shelter, and Edit Profile',
        'Staff' => 'Staff Dashboard, Pet Inventory, Applications, Smart Matches, Medical Intake, Daily Schedule, Staff Availability, Payments, Messages, Reports, and Edit Profile',
        'Admin' => 'Dashboard, Manage Staff, Manage Adopters, Manage Pets, Requests, Payments, Reports, Messages, Activity Logs, and Edit Profile',
    ];
    $pages = $pagesByRole[$role] ?? $pagesByRole['Adopter'];

    // Magkaibang trabaho ang tatlong papel, kaya magkaiba rin ang dapat nilang
    // alam. Ang listahan ng tunay na proseso ang pumipigil sa AI na mag-imbento
    // ng page o button na wala naman sa system.
    $roleBriefs = [
        'Adopter' => <<<BRIEF
YOUR JOB WITH THIS USER
They are an adopter. You are their guide to what they see inside SMART PET.
You help them in two ways:

1. EXPLAIN THE CONTENT. When they ask what something on a page means or what
   to do next, explain it plainly using the real pages and process here: fill
   in My Preference first, browse Search Pets or the AI Recommendation page,
   open a pet to see its details, submit the adoption application, wait for
   staff to review it, join the online interview at the chosen slot, then pay
   the adoption fee on the Payments page once approved. Status words they may
   see: Pending, Under Review, Approved, Rejected for applications; Unpaid,
   Pending, Paid for fees. Updates about their applications, interviews, and
   payments appear on the Notifications page. Use the live data above when it
   answers their question about their own applications or payments.

2. READ THEIR SCREENSHOTS. They can attach a screenshot of any page in this
   system. Look at it carefully, describe what that page is showing them, point
   out the parts that matter (a status badge, an amount, a button, a message),
   and tell them what it means and what they can do next on that page. If the
   screenshot shows an error or something unexpected, explain it simply and
   tell them to reach shelter staff through the Messages page.

If they ask which pet suits them or why a pet fits them, point them to the AI
Recommendation page, which shows their best matches and a "Why this pet?"
explanation. Never reveal, estimate, or discuss any match percentage, score, or
threshold, and never explain how the matching is computed.

NOT A VET OR PET-CARE CONSULTANT. Do not give supply recommendations, health or
sickness advice, home-care steps, or training/feeding/grooming/vaccination
guidance, even if they describe symptoms or ask what to buy. Kindly say that is
outside what you can help with here, and point them to a licensed veterinarian
for anything about their pet's health, or to the Messages page to reach shelter
staff for anything else.
BRIEF,

        'Staff' => <<<BRIEF
YOUR JOB WITH THIS USER
You are this staff member's work assistant. Everything you say should be about
running the shelter through this system: pets, adoption applications, interview
slots, medical intake, their daily checklist, payments, and reports. If they ask
about anything outside shelter work, kindly say that is outside what you help
with.

When they ask how to do something, give the real steps in this system, in order,
naming the page and the button they need. Never describe a page or a button that
is not in the reference below. If you are unsure a feature exists, say so instead
of guessing.

End every how-to answer with one short, practical tip from shelter experience,
something that saves time or prevents a common mistake. Begin that sentence with
"Tip:" so it stands out.

HOW STAFF WORK ACTUALLY GETS DONE HERE
- Daily Schedule: their own checklist for the day. They add a task with a name
  and an optional time, tick it done, or delete it. It is personal, so other
  staff do not see it.
- Staff Availability: this is where interview slots come from. They add a date
  with a start and end time, and it becomes an Open slot. An adopter books an
  Open slot themselves while submitting an application, which turns it Booked.
- Applications: the list of adoption applications. Opening one shows the
  applicant's details, their uploaded photos and ID, and the match breakdown.
  From there they approve or reject it.
- Pet Inventory: "Add New Pet" adds an animal. Opening a pet shows and edits its
  details.
- Medical Intake: record a checkup for a pet, or log a newly arrived animal as an
  intake. Intake status moves Assessment, then Vaccination, then Quarantine, then
  Cleared.
- Smart Matches: shows which available pets suit which adopter, based on the
  preferences that adopter saved.
- Payments: records the adoption fee for an approved application.
- Reports: statistics on adoptions and on the pet inventory.
BRIEF,

        'Admin' => <<<BRIEF
YOUR JOB WITH THIS USER
You are this administrator's assistant for running the shelter system itself:
accounts, pets, adoption requests, payments, reports, and the audit trail.
Everything you say should be about operating SMART PET. If they ask about
anything outside that, kindly say it is outside what you help with.

When they ask how to do something, give the real steps in this system, in order,
naming the page and the button they need. Never describe a page or a button that
is not in the reference below. If you are unsure a feature exists, say so instead
of guessing.

End every how-to answer with one short, practical tip from shelter management
experience, something that saves time, protects the records, or prevents a common
mistake. Begin that sentence with "Tip:" so it stands out.

HOW ADMIN WORK ACTUALLY GETS DONE HERE
- Manage Staff: shows the current staff list, plus every registered adopter. To
  add staff, press "add new Staff", pick a registered adopter, and they become
  Staff. Removing a staff member does NOT delete their account - it turns them
  back into an Adopter, so their history is kept. A person must sign up as an
  adopter first before they can ever be made staff.
- Manage Adopters: the full list of registered adopters and their details, and
  their submitted applications can be opened from here.
- Manage Pets: add a pet, open the pencil icon on a row to edit it, or the trash
  icon to delete it.
- Requests: the adoption applications across the shelter, with counts for
  Pending, Under Review, and Approved this month, and a match-info view per
  request.
- Payments: the record of adoption fees.
- Reports: statistics on adoptions and on the pet inventory.
- Activity Logs: the audit trail of important actions taken by admins and staff.
  It is a record only - nothing there can be edited or undone.
- Dashboard: the charts and totals summarising the whole shelter.
- Messages: direct conversations with staff and adopters.
BRIEF,
    ];
    $roleBrief = $roleBriefs[$role] ?? $roleBriefs['Adopter'];

    return <<<SYS
You are the SMART PET Assistant, the built-in helper inside SMART PET, an
adoption system for the Caloocan City animal shelter. You are talking with
$username, whose role in the system is $role.

The pages this user can open are: $pages.

$roleBrief

LIVE DATA FROM THE SYSTEM, true at this exact moment:
$context

How to answer:
- Reply in the same language the user writes in. Tagalog question, Tagalog
  answer. English question, English answer. If they mix the two, mix them back
  the same way. Follow their lead on every message, and switch with them if
  they change language partway through the conversation.
- Talk like a real person chatting, not like a manual or a robot. Picture a
  friendly shelter volunteer who genuinely loves animals and is texting with
  $username. Be warm, casual, and encouraging. Use their first name now and
  then (not in every message). React to what they say first ("Ay, ang saya
  niyan!", "Gets kita", "Good question!") before giving the answer, when it
  fits naturally.
- If they sound worried, confused, or excited, acknowledge that feeling in a
  sentence before helping.
- ALWAYS be gentle, patient, and respectful. Never sound strict, annoyed,
  scolding, preachy, or like you are lecturing them. Never blame the user or
  make them feel they did something wrong, even if they skipped a step, asked
  the same thing twice, typed something unclear, or are frustrated or rude.
  Stay calm and kind no matter what.
- In Tagalog or Taglish, speak politely and naturally, and use "po" now and
  then, the way a courteous shelter volunteer would talk to a visitor.
- Avoid harsh or commanding wording like "You must", "You need to", "Hindi
  puwede", "Bawal", or "Dapat mo". Suggest instead: "Puwede mo pong...",
  "Mas maganda po kung...", "Try mo po munang...", "You can...".
- If something is not yet possible (for example they have not filled in My
  Preference, so they cannot apply yet), explain it softly as the next easy
  step, not as a rule they broke. Example: "Para po makapag-apply, unahin
  lang natin ang My Preference - saglit lang 'yon!"
- If the news is disappointing (a Rejected application, no matching pet yet,
  a pending payment), say it with empathy first, then give hope and a clear
  next step. Never deliver bad news bluntly.
- If they typed something unclear, do not say you did not understand in a
  flat way. Gently guess what they might mean, or ask a friendly clarifying
  question.
- When you have to decline (a health question, something outside SMART PET),
  apologize warmly, explain briefly, and always offer something you CAN do or
  who can help, so they never feel turned away.
- Never say "As an AI" or talk about being a language model. You are simply
  the SMART PET Assistant. But if they directly ask whether they are talking
  to a real person, be honest: say you are the system's virtual assistant, and
  that they can reach a real shelter staff member on the Messages page.
- Keep it short, usually two to four sentences. When they ask how to do
  something with several steps (for example how to adopt), put each step on its
  own line starting with a number like "1." so it is easy to follow, then add a
  short friendly closing line.
- When it feels natural, end with one short follow-up question that keeps the
  conversation going, for example asking if they already filled in My
  Preference, or if they want to know the next step. Do not do this on every
  single message.
- Use the live data above whenever it answers the question, and refer to it
  naturally rather than reciting it as a list.
- Never invent a pet, a number, a name, a date, or an application status that
  is not in the live data. If you do not know, say so plainly and point the
  user to the page where they can see it, or to the Messages page to reach a
  real staff member.
- You cannot change any data yourself. You explain where and how, and the person
  does it on the page. Never promise an approval, a schedule, or a decision on
  anyone's behalf.
- Stay on the topic of SMART PET, pets, and animal adoption. If asked about
  anything else, kindly say that is outside what you can help with.
- Write plain text only. No markdown, no asterisks for bold, no headings, and
  no bullet characters, because your reply appears in a small plain-text chat
  bubble. Numbered steps on separate lines are fine. At most one friendly emoji
  per reply, and only when it fits.
- The user can attach an image, such as a photo of their pet or a screenshot
  of a page in this system. Look at it directly and answer based on what it
  actually shows - do not say you cannot see images.
SYS;
}

$reply = null;
$pets = [];
$link = null;
// Galing sa AI Recommendation page: doon mismo ipapakita ang mga pet card
$recommendPage = ($input['context'] ?? '') === 'recommend_page';
$showMatches = false;
// "Ibang pets naman": ipinapakita ang susunod na pinakabagay, hindi ang mga naipakita na
$showMore = false;
const SP_MATCH_TOKEN = '[SHOW_MATCHES]';
const SP_MORE_TOKEN = '[SHOW_MORE]';

/**
 * Backup (kapag walang AI): ayaw ba ng adopter sa mga naipakita at humihingi ng iba?
 * Hal. "iba naman", "ibang pet", "show more", "other pets", "ayoko sa mga yan".
 */
function sp_wants_more_pets(string $text): bool
{
    $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    return (bool) preg_match('/\b(iba naman|iba pa|ibang (pets?|aso|pusa|alaga)|other pets?|show more|more pets?|others|ayoko (sa|dyan|diyan|niyan)|ayaw ko (sa|dyan|diyan|niyan)|di ko type|hindi ko type|not my type)\b/u', $t);
}

/**
 * Backup (kapag walang AI): humihingi ba talaga ang adopter ng mga PET na bagay sa kanya?
 * Hindi sapat ang salitang "recommend" lang - hal. "i-recommend si Duterte" ay hindi.
 * Kailangan: maikling utos lang ("recommend", "recommend now", "i-recommend mo ako"),
 * o may salitang tungkol sa alaga (pet, aso, pusa, dog, cat, alaga) kasama ng
 * recommend/suggest/match/bagay.
 */
function sp_wants_pet_matches(string $text): bool
{
    $t = trim(preg_replace('/\s+/u', ' ', $text));
    if (preg_match('/^(pls |please |pa)?(i-?)?(recommend|suggest|match)( (mo|po|now|na|me|ako|naman|ulit|again|pls|please))*[.!?]*$/u', $t)) {
        return true;
    }
    $asks = sp_contains($t, ['recommend', 'suggest', 'match', 'bagay', 'swak', 'para sa akin', 'para sakin']);
    $aboutPets = (bool) preg_match('/(?<![\p{L}])(pets?|aso|pusa|dogs?|cats?|alaga|tuta|kuting|puppy|kitten)(?![\p{L}])/u', $t);
    return $asks && $aboutPets;
}

// ---- Tunay na usapan: ang AI na mismo ang sumasagot, may alam sa naunang
//      sinabi at may hawak na totoong datos mula sa database ----
if ($reply === null && ai_is_configured()) {
    $conversation = $history;

    // Vision lang si Claude sa setup na ito - kung Gemini ang aktibong
    // provider, isasama pa rin ang tanong pero hindi ang larawan.
    if ($hasImage && AI_PROVIDER === 'claude') {
        $userContent = [$imageBlock];
        if ($message !== '') {
            $userContent[] = ['type' => 'text', 'text' => $message];
        }
        $conversation[] = ['role' => 'user', 'content' => $userContent];
    } else {
        $conversation[] = ['role' => 'user', 'content' => $message !== '' ? $message : 'Nag-attach ako ng larawan.'];
    }

    $systemPrompt = sp_system_prompt($role, $username, sp_live_context($pdo, $role, $user_id));
    if ($role === 'Adopter') {
        // Ang AI ang nagpapasya kung humihingi talaga ng pet matches ang adopter
        $systemPrompt .= "\n\nPET MATCHES: Only when the adopter is clearly asking you to recommend, suggest or match "
            . "shelter PETS for themselves (for example \"recommend me a pet\", \"anong aso ang bagay sa akin\", "
            . "\"i-recommend mo ako ng pusa\", or just \"recommend\"), reply with one short friendly sentence and end it "
            . "with the exact text " . SP_MATCH_TOKEN . ". The system will then show their matching pets.\n"
            . "Never add " . SP_MATCH_TOKEN . " for anything else - for example when they ask you to recommend a person, "
            . "a politician, a celebrity, food, a product, a place, or anything that is not a shelter pet. In those cases "
            . "just answer normally: politely explain that you can only help with pet adoption here, and do not show pets.";
        if ($recommendPage) {
            $systemPrompt .= "\n\nOTHER PETS: When pets were already shown and the adopter says they do not like them or "
                . "wants different/other/more pets (for example \"ayoko sa mga yan\", \"iba naman\", \"di ko type\", "
                . "\"show me other pets\"), reply with one short friendly sentence and end it with the exact text "
                . SP_MORE_TOKEN . " instead of " . SP_MATCH_TOKEN . ". The system will show the next best matches.";
        }
    }
    $result = ai_chat(
        $systemPrompt,
        $conversation,
        700,
        // Maikling sagot lang sa chat, kaya low effort - mabilis at mura
        'low'
    );

    if ($result['ok']) {
        $reply = $result['text'];
        if ($recommendPage && strpos($reply, SP_MORE_TOKEN) !== false) {
            $reply = trim(str_replace([SP_MORE_TOKEN, SP_MATCH_TOKEN], '', $reply));
            $showMatches = true;
            $showMore = true;
        } elseif (strpos($reply, SP_MATCH_TOKEN) !== false) {
            $reply = trim(str_replace(SP_MATCH_TOKEN, '', $reply));
            $showMatches = true;
            // Sa chat bubble (ibang page): link papunta sa AI Recommendation page
            if (!$recommendPage) {
                $link = ['label' => 'Open AI Recommendation', 'url' => sp_url('Adopter_File/ai_recommendation.php')];
            }
        }
    } else {
        // Hindi ito nakikita ng user (backup na sagot ang lalabas), kaya itinatala
        // sa error log ng server at sa "debug" ng sagot (makikita sa browser console)
        error_log('SMART PET chatbot AI error: ' . $result['error']);
        $aiDebug = $result['error'];
    }
}

// ---- Pet matching (backup, kapag walang AI o hindi ito maabot) ----
//      Sa chat bubble: itinuturo ang adopter sa AI Recommendation page.
//      Sa mismong page na iyon: ipinapakita ang mga card (show_matches).
try {
    if ($reply === null && $role === 'Adopter' && $recommendPage && sp_wants_more_pets($text)) {
        $showMatches = true;
        $showMore = true;
        $reply = "Sige, $username! Ipapakita ko ang iba pang pets na bagay pa rin sa'yo.";
    } elseif ($reply === null && $role === 'Adopter' && sp_wants_pet_matches($text)) {
        $rec = sp_adopter_recommendations($pdo, $user_id);
        if (!empty($rec['pets'])) {
            $showMatches = true;
            if ($recommendPage) {
                $reply = "Sige, $username! Hahanapin ko ang mga pet na pinakabagay sa'yo.";
            } else {
                $reply = "Ooh, exciting 'to! May mga pet na akong nakitang bagay na bagay sa lifestyle mo, $username. Nasa AI Recommendation page sila, kasama ang paliwanag kung bakit sila bagay sa'yo. Silipin mo na!";
                $link = ['label' => 'Open AI Recommendation', 'url' => sp_url('Adopter_File/ai_recommendation.php')];
            }
        } else {
            $reply = $rec['reply'];
            $link = $rec['link'];
        }
    }
} catch (Exception $e) {
    $reply = null;
    $link = null;
}

// ---- Fallback kapag walang API key o hindi maabot ang AI ----
if ($reply === null) {
    try {
        if (sp_contains($text, ['ilang available', 'how many pet', 'ilang pet', 'available na pet', 'available pets'])) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM pet_inventory WHERE status = 'Available'")->fetchColumn();
            $reply = "Sa ngayon, may $count pet(s) na 'Available' sa inventory. Puwede mo silang tignan sa Search Pets o Manage Pets page.";
        } elseif (($role === 'Admin' || $role === 'Staff') && sp_contains($text, ['pending application', 'pending na application', 'ilang application', 'bagong application'])) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM adoption_applications WHERE application_status IN ('Pending', 'Under Review')")->fetchColumn();
            $reply = "May $count application(s) na 'Pending' o 'Under Review' ngayon. Tignan sa Applications/Requests page para i-review.";
        } elseif ($role === 'Adopter' && sp_contains($text, ['status ng application', 'application status', 'status ng adopt', 'application ko'])) {
            $stmt = $pdo->prepare(
                "SELECT aa.application_status, pi.name FROM adoption_applications aa
                 JOIN pet_inventory pi ON pi.pet_id = aa.pet_id
                 WHERE aa.adopter_id = ? ORDER BY aa.created_at DESC LIMIT 1"
            );
            $stmt->execute([$user_id]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($app) {
                $reply = "Ang huling application mo para kay {$app['name']} ay nasa status na: \"{$app['application_status']}\". Tignan ang detalye sa My Applications page.";
            } else {
                $reply = "Wala pa akong nakitang adoption application mo. Puntahan ang Search Pets para makahanap ng gustong i-adopt!";
            }
        }
    } catch (Exception $e) {
        $reply = null;
    }
}

// ---- Static FAQ rules ----
if ($reply === null) {
    $faq = [
        ['keywords' => ['hi', 'hello', 'kumusta', 'kamusta', 'good morning', 'good afternoon'], 'roles' => null,
         'answer' => "Kamusta, $username! Ako ang SMART PET Assistant 🐾 Ano ang maitutulong ko sa'yo ngayon?"],
        ['keywords' => ['salamat', 'thank you', 'thanks'], 'roles' => null,
         'answer' => "Walang anuman! Narito lang ako kung may kailangan ka pang tulong."],
        ['keywords' => ['sino ka', 'who are you', 'ano ka'], 'roles' => null,
         'answer' => "Ako ang SMART PET Assistant! Parang kasama mo ako dito sa system - tutulungan kitang maintindihan ang mga page at gabayan ka sa pag-adopt."],
        ['keywords' => ['tulong', 'help', 'ano magagawa mo', 'ano kayang gawin'], 'roles' => null,
         'answer' => "Sige, tutulungan kita! Puwede mo akong tanungin kung paano mag-adopt, ano na ang status ng application mo, o ano ang ibig sabihin ng nakikita mo sa isang page. Puwede ka ring mag-attach ng screenshot at ipapaliwanag ko. Ano ang gusto mong malaman?"],

        // Adopter FAQs
        ['keywords' => ['paano mag-adopt', 'paano mag adopt', 'how to adopt', 'mag-adopt'], 'roles' => ['Adopter'],
         'answer' => "Ang saya naman, gusto mo nang mag-adopt! Ganito lang ang proseso:\n1. Punan muna ang My Preference para malaman namin ang lifestyle mo.\n2. Tumingin ng pet sa Search Pets o sa AI Recommendation page.\n3. I-click ang pet na nagustuhan mo at i-submit ang adoption application, kasama ang pagpili ng interview slot.\n4. Hintayin ang review ng staff - makikita mo ang status sa My Applications at Notifications.\n5. Sumali sa online interview sa napiling oras.\n6. Kapag Approved na, bayaran ang adoption fee sa Payments page.\nNakapag-fill up ka na ba ng My Preference?"],
        ['keywords' => ['magbayad', 'bayad', 'payment', 'adoption fee', 'pay'], 'roles' => ['Adopter'],
         'answer' => "Kapag Approved na ang application mo, lalabas ang adoption fee sa Payments page. Doon mo ito mababayaran, at magiging 'Paid' ang status kapag natanggap na. Approved na ba ang application mo?"],
        ['keywords' => ['favorite', 'paborito'], 'roles' => ['Adopter'],
         'answer' => "Puwede mong i-click ang heart icon sa listahan ng pets para i-save bilang favorite. Tignan ang mga na-save mo sa Favorites page."],
        ['keywords' => ['preference', 'smart match'], 'roles' => ['Adopter'],
         'answer' => "Sa Adopter Preferences, ilalagay mo ang iyong lifestyle at kagustuhan para makapag-suggest ang system ng mga pet na bagay sa'yo (Smart Match)."],
        ['keywords' => ['message', 'kausapin', 'makakausap', 'contact', 'staff'], 'roles' => ['Adopter'],
         'answer' => "Syempre! Puntahan mo lang ang Messages page para direktang makausap ang shelter staff tungkol sa isang pet o sa application mo. Mabait sila, huwag kang mahiyang magtanong."],

        // Staff FAQs
        ['keywords' => ['magdagdag ng pet', 'add pet', 'add new pet'], 'roles' => ['Staff'],
         'answer' => "Sa Pet Inventory page, i-click ang \"Add New Pet\" button, punan ang detalye ng pet, at i-save."],
        ['keywords' => ['review application', 'i-review', 'review ng application'], 'roles' => ['Staff'],
         'answer' => "Sa Applications page, piliin ang isang application at i-click ang Review para tignan ang detalye at gumawa ng desisyon (Approve/Reject)."],
        ['keywords' => ['daily schedule', 'daily task', 'checklist'], 'roles' => ['Staff'],
         'answer' => "Ang Daily Schedule ay isang personal na checklist mo ng mga gawain para sa araw. Puwede kang magdagdag, mag-mark done, o mag-delete ng task doon."],
        ['keywords' => ['smart matches', 'smart match'], 'roles' => ['Staff'],
         'answer' => "Ang Smart Matches page ay nagpapakita ng mga suggested na pet para sa bawat adopter batay sa kanilang preferences."],

        // Admin FAQs
        ['keywords' => ['mag-edit ng pet', 'i-edit ang pet', 'edit pet'], 'roles' => ['Admin'],
         'answer' => "Sa Manage Pets page, i-click ang pencil (edit) icon sa row ng pet para baguhin ang detalye nito, o ang trash icon para i-delete."],
        ['keywords' => ['magdagdag ng staff', 'add staff', 'add new staff'], 'roles' => ['Admin'],
         'answer' => "Sa Manage Staff page, i-click ang \"add new Staff\" button, pumili ng registered adopter, at gagawin siyang Staff."],
        ['keywords' => ['manage adopters', 'listahan ng adopters'], 'roles' => ['Admin'],
         'answer' => "Sa Manage Adopters page makikita mo ang buong listahan ng mga naka-register na adopter at ang kanilang mga detalye."],
        ['keywords' => ['activity log', 'activity logs'], 'roles' => ['Admin'],
         'answer' => "Ang Activity Logs page ay nagtatala ng mahahalagang aksyon ng mga Admin at Staff sa loob ng system."],
        ['keywords' => ['reports', 'report'], 'roles' => ['Admin'],
         'answer' => "Sa Reports page, makikita mo ang mga estadistika tungkol sa adoption at pet inventory."],
    ];

    foreach ($faq as $entry) {
        if ($entry['roles'] !== null && !in_array($role, $entry['roles'], true)) {
            continue;
        }
        // Buong salita lang, para hindi tumama ang "hi" sa "this" o "pay" sa "happy"
        $matched = false;
        foreach ($entry['keywords'] as $kw) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($kw, '/') . '(?![\p{L}])/u', $text)) {
                $matched = true;
                break;
            }
        }
        if ($matched) {
            $reply = $entry['answer'];
            break;
        }
    }
}

if ($reply === null) {
    $reply = "Pasensya na po, medyo hindi ko pa nakuha ang ibig mong sabihin. Puwede mo po ba itong sabihin sa ibang paraan? Puwede rin po akong tumulong tungkol sa pag-adopt, sa application mo, o sa mga page ng system. At kung gusto mo pong makausap ang shelter staff, nandiyan lang sila sa Messages page. 😊";
}

$response = ['reply' => $reply];
if ($showMatches) {
    $response['show_matches'] = true;
    if ($showMore) {
        $response['show_more'] = true;
    }
}
if (!empty($aiDebug)) {
    $response['debug'] = 'AI: ' . $aiDebug;
}
if (!empty($pets)) {
    // Hindi ipinapakita sa adopter ang porsyento - ranggo lang. Tinatanggal na
    // rin ito sa mismong ipinapadala, para hindi makita kahit sa view-source.
    $response['pets'] = array_map(function ($p) {
        unset($p['score']);
        return $p;
    }, $pets);
}
if ($link !== null) {
    $response['link'] = $link;
}
if ($savedImagePath !== null) {
    $response['image'] = $savedImagePath;
}

// Itala ang palitan para may makikitang history mamaya. Hindi ito hinahayaang
// makasira ng sagot - kung mabigo ang pagtatala, tuloy pa rin ang usapan.
try {
    $sessionId = sp_chat_session($pdo, $user_id, $role);
    // Ang filename lang ang naitatala, hindi ang base64, para hindi
    // pumaltos ang laki ng chat_messages table.
    sp_log_chat($pdo, $sessionId, 'user', $message, $savedImagePath ? ['image' => $savedImagePath] : null);

    // Isama ang pet cards at link, para buo ang lumang usapan pagbalik sa history
    $payload = [];
    if (!empty($response['pets'])) { $payload['pets'] = $response['pets']; }
    if (!empty($response['link'])) { $payload['link'] = $response['link']; }
    sp_log_chat($pdo, $sessionId, 'assistant', $reply, $payload ?: null);
} catch (Exception $e) { /* patuloy pa rin ang sagot sa user */ }

// Linisin ang anumang naligaw na output (hal. warning) para puro JSON ang sagot
$stray = ob_get_clean();
if ($stray !== '' && $stray !== false) {
    error_log('SMART PET chatbot stray output: ' . substr(strip_tags($stray), 0, 300));
}
echo json_encode($response);
