<?php
/**
 * Pagbasa ng Valid ID ng adopter para sa certificates.
 *
 * Pagka-approve ng application, ipinapabasa sa AI ang in-upload na Valid ID
 * at kinukuha ang pangalan, address, uri ng ID, at ID number. Sine-save ang
 * mga ito sa adoption_documents, kaya ito na ang lumalabas sa Certificate of
 * Adoption, Vaccination Certificate, at Adoption Agreement.
 *
 * Hindi nito pinapalitan ang anumang field na napunan na ng staff, maliban
 * kung $overwrite = true (ang "Read from Valid ID" button sa certificate page).
 * Kapag hindi mabasa ang ID o naka-off ang AI, walang nababago - ang tinype ng
 * adopter sa application form pa rin ang lalabas, at puwedeng i-type ng staff.
 */

require_once __DIR__ . '/ai_config.php';

/**
 * @return array{ok: bool, message: string}
 */
function sp_read_valid_id(PDO $pdo, int $applicationId, int $staffId, bool $overwrite = false): array
{
    $stmt = $pdo->prepare("SELECT valid_id_url FROM adoption_applications WHERE application_id = ?");
    $stmt->execute([$applicationId]);
    $file = basename((string) $stmt->fetchColumn());

    $path = __DIR__ . '/../uploads/' . $file;
    if ($file === '' || !is_file($path)) {
        return ['ok' => false, 'message' => 'The Valid ID file for this application was not found.'];
    }

    if (!ai_is_configured()) {
        return ['ok' => false, 'message' => 'The AI is not set up, so the Valid ID could not be read.'];
    }

    $mimeTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $mime = $mimeTypes[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
    if ($mime === null) {
        return ['ok' => false, 'message' => 'The Valid ID is not an image the AI can read.'];
    }

    $schema = [
        'type' => 'object',
        'properties' => [
            'is_valid_id' => ['type' => 'boolean'],
            'id_type' => ['type' => 'string'],
            'id_number' => ['type' => 'string'],
            'full_name' => ['type' => 'string'],
            'address' => ['type' => 'string'],
        ],
        'required' => ['is_valid_id', 'id_type', 'id_number', 'full_name', 'address'],
        'additionalProperties' => false,
    ];

    $system = 'You read Philippine government-issued IDs (Barangay ID / Residency ID, PhilSys National ID, Driver\'s License, UMID, '
        . 'Passport, Postal ID, Voter\'s ID, PRC ID, SSS, PhilHealth, TIN, Senior Citizen, PWD, school ID, etc.) '
        . 'for a city veterinary office that prints adoption certificates. Copy text exactly as printed on the card. '
        . 'Never guess or invent a value: if a field is not printed or cannot be read clearly, return an empty string for it.';

    $prompt = "Read this ID card and return:\n"
        . "- is_valid_id: true if the image is an ID card, false if it is something else.\n"
        . "- id_type: the kind of ID, e.g. \"PhilSys National ID\", \"Driver's License\", \"UMID\", \"Passport\".\n"
        . "- id_number: the ID / card / license number exactly as printed.\n"
        . "- full_name: the holder's full name in natural order (First Middle Last, with any suffix like Jr. at the end), "
        . "even if the card prints it as \"LAST, FIRST MIDDLE\". Use normal capitalization, e.g. \"Juan Santos Dela Cruz\".\n"
        . "- address: the holder's address as printed, on one line, with normal capitalization.";

    $result = ai_read_image_json($system, $prompt, (string) file_get_contents($path), $mime, $schema);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => 'The Valid ID could not be read: ' . $result['error']];
    }

    $data = $result['data'];
    if (empty($data['is_valid_id'])) {
        return ['ok' => false, 'message' => 'The uploaded Valid ID does not look like an ID card, so nothing was filled in.'];
    }

    // Pangalan ng field sa adoption_documents => [value mula sa ID, max na haba]
    $found = [
        'owner_name' => [$data['full_name'] ?? '', 150],
        'owner_address' => [$data['address'] ?? '', 255],
        'valid_id_type' => [$data['id_type'] ?? '', 100],
        'valid_id_number' => [$data['id_number'] ?? '', 100],
    ];

    $existing = $pdo->prepare("SELECT owner_name, owner_address, valid_id_type, valid_id_number FROM adoption_documents WHERE application_id = ?");
    $existing->execute([$applicationId]);
    $current = $existing->fetch(PDO::FETCH_ASSOC) ?: [];

    $values = [];
    foreach ($found as $col => [$value, $max]) {
        $value = mb_substr(preg_replace('/\s+/u', ' ', trim((string) $value)), 0, $max);
        if ($value === '') {
            continue;
        }
        if (!$overwrite && trim((string) ($current[$col] ?? '')) !== '') {
            continue;
        }
        $values[$col] = $value;
    }

    if (!$values) {
        return ['ok' => false, 'message' => 'No new details could be read from the Valid ID.'];
    }

    $cols = array_keys($values);
    $sql = 'INSERT INTO adoption_documents (application_id, ' . implode(', ', $cols) . ', id_read_at, updated_by)
            VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW(), ?)
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $cols))
            . ', id_read_at = NOW(), updated_by = VALUES(updated_by)';
    $pdo->prepare($sql)->execute(array_merge([$applicationId], array_values($values), [$staffId]));

    return ['ok' => true, 'message' => 'Name, address, and ID details were read from the Valid ID.'];
}
