<?php
/**
 * Humihingi kay Claude ng paliwanag na parang galing sa beterinaryo kung bakit
 * bagay ang isang pet sa naka-login na adopter.
 *
 * Tinatawag ito ng SMART PET Assistant chat bubble kapag pinindot ang
 * "Why is this a match?" sa isang pet card.
 */
session_start();
require_once '../connection/database.php';
require_once '../connection/match_scoring.php';
require_once '../connection/ai_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$pet_id = (int) ($_GET['pet_id'] ?? 0);

// Wikang hinihingi ng adopter sa explanation screen: 'tl' o 'en'
$lang = (($_GET['lang'] ?? 'en') === 'tl') ? 'tl' : 'en';

if ($pet_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No pet selected']);
    exit();
}

// Kunin ang preferences ng adopter at ang detalye ng pet
$prefStmt = $pdo->prepare("SELECT * FROM adopter_preferences WHERE user_id = ?");
$prefStmt->execute([$user_id]);
$prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);

// Hindi ipinapaliwanag ang alagang may nauna nang nag-apply na ibang adopter
$petStmt = $pdo->prepare("SELECT * FROM pet_inventory WHERE pet_id = ? AND status = 'Available' AND " . sp_pet_open_sql());
$petStmt->execute([$pet_id, (int) $user_id]);
$pet = $petStmt->fetch(PDO::FETCH_ASSOC);

if (!$prefs || !$pet) {
    http_response_code(404);
    echo json_encode(['error' => 'Pet or preferences not found']);
    exit();
}

$score = computeMatchScore($prefs, $pet);
$breakdown = computeMatchBreakdown($prefs, $pet);

// Ang huella na ito ang nagsasabi kung luma na ang naka-imbak na paliwanag.
// Kasama rito ang buong preferences ng adopter at ang buong datos ng pet, kaya
// kusang mapapalitan ang paliwanag kapag nagpalit ng reference ang adopter o
// may inayos ang staff sa pet. Kung wala namang nagbago, hindi na tayo babayad
// ulit sa AI - ang nakatago sa database na ang ibinibigay.
$sourceHash = md5(json_encode([$prefs, $pet, $lang]));

$cacheStmt = $pdo->prepare(
    'SELECT explanation, source_hash FROM pet_explanations WHERE user_id = ? AND pet_id = ? AND lang = ?'
);
$cacheStmt->execute([$user_id, $pet_id, $lang]);
$cached = $cacheStmt->fetch(PDO::FETCH_ASSOC);

if ($cached && $cached['source_hash'] === $sourceHash) {
    echo json_encode([
        'ok' => true,
        'pet_name' => $pet['name'],
        'explanation' => $cached['explanation'],
        'cached' => true,
    ]);
    exit();
}

if (!ai_is_configured()) {
    echo json_encode([
        'ok' => false,
        'error' => 'AI explanation is not available yet. Add your API key in connection/ai_config.php.',
    ]);
    exit();
}

// Ibigay kay Claude ang totoong datos - ayaw nating mag-imbento siya ng detalye
$criteria = '';
foreach ($breakdown as $row) {
    if (!empty($row['gate'])) {
        // Must-have: pasado ba o hindi, hindi puntos
        $criteria .= sprintf(
            "- %s (must-have): adopter wants \"%s\", pet is \"%s\" -> %s\n",
            $row['label'], $row['pref'], $row['pet'],
            $row['factor'] >= 1.0 ? 'OK' : ($row['factor'] <= 0.0 ? 'NOT SAFE for this adopter' : 'does NOT match what the adopter wants')
        );
        continue;
    }
    $criteria .= sprintf(
        "- %s (%s): adopter wants \"%s\", pet is \"%s\" -> earned %s of %s points\n",
        $row['label'], $row['group'], $row['pref'], $row['pet'], $row['points'], $row['weight']
    );
}

// Sinusulat ito nang tuwiran sa wikang hiningi, hindi isinasalin mula sa Ingles,
// para natural ang dating at hindi doble ang tawag sa API.
$languageRule = $lang === 'tl'
    ? "Sumulat ka sa magaan at mainit na Tagalog na kayang intindihin ng karaniwang "
      . "adopter. Huwag gumamit ng mabibigat na terminong medikal nang hindi ipinapaliwanag. "
      . "Maaari mong panatilihin sa Ingles ang pangalan ng alaga, ang lahi, at ang mga "
      . "terminong walang natural na katumbas sa Tagalog."
    : "Write your answer in warm, plain English that an ordinary adopter understands. "
      . "Do not use medical jargon without explaining it.";

$systemPrompt = <<<SYS
You are a licensed veterinarian at a Caloocan City animal shelter who advises
families on whether a specific shelter pet suits their household.

$languageRule

Rules you must follow:
- Base every claim ONLY on the adopter and pet data given to you. Never invent
  facts about the pet's history, health, or behaviour.
- Lead with the single strongest reason this pet fits this particular adopter,
  and tie it to their stated adoption intent.
- If there is any allergy or safety concern, say so plainly and early. Adopter
  safety outweighs a good match on other traits.
- Mention one honest thing the adopter should prepare for. Never pretend the
  match is perfect.
- Keep it to 3 short paragraphs, no headings, no bullet lists.
- Never mention any number from the scoring: no percentage, no points, no
  "X of Y", no threshold. The scoring below is only to tell you WHICH traits
  fit and which do not - describe those in plain words, never as numbers.
SYS;

$userPrompt = "ADOPTER PROFILE\n"
    . "- Adoption intent: " . ($prefs['adoption_intent'] ?? 'Not stated') . "\n"
    . "- Home: " . ($prefs['living_space'] ?? 'Not stated') . "\n"
    . "- Household: " . ($prefs['household_composition'] ?? 'Not stated') . "\n"
    . "- Activity level: " . ($prefs['activity_level'] ?? 'Not stated') . "\n"
    . "- Time available daily: " . ($prefs['time_commitment'] ?? 'Not stated') . "\n"
    . "- Pet experience: " . ($prefs['pet_experience'] ?? 'Not stated') . "\n"
    . "- Allergies: " . ($prefs['allergies'] ?? 'None') . "\n"
    . "- Noise tolerance: " . ($prefs['noise_tolerance'] ?? 'Not stated') . "\n"
    . "- Maintenance preference: " . ($prefs['maintenance_level'] ?? 'Not stated') . "\n\n"
    . "THE PET\n"
    . "- Name: " . $pet['name'] . "\n"
    . "- Kind and breed: " . $pet['kind'] . ", " . $pet['breed'] . "\n"
    . "- Age group: " . $pet['age_group'] . "\n"
    . "- Size: " . $pet['size'] . "\n"
    . "- Gender: " . $pet['gender'] . "\n"
    . "- Energy level: " . $pet['energy_level'] . "\n"
    . "- Temperament: " . $pet['temperament'] . "\n"
    . "- Shedding level: " . ($pet['shedding_level'] ?: 'not recorded') . "\n"
    . "- Health condition: " . $pet['health_condition'] . "\n"
    . "- Vaccination: " . $pet['vaccination_status'] . "\n"
    . "- Shelter notes: " . ($pet['description'] ?: 'none') . "\n\n"
    . "COMPATIBILITY SCORING ALREADY COMPUTED BY THE SYSTEM\n"
    . $criteria . "\n"
    . "Explain to this adopter why " . $pet['name'] . " is a good match for them.";

$result = ai_message($systemPrompt, $userPrompt, 1200);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit();
}

// Itago para sa susunod na pagbukas. Ang ON DUPLICATE KEY ang humahawak sa
// kaso ng lumang paliwanag na kailangan nang palitan.
try {
    $pdo->prepare(
        'INSERT INTO pet_explanations (user_id, pet_id, lang, source_hash, match_score, explanation)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            source_hash = VALUES(source_hash),
            match_score = VALUES(match_score),
            explanation = VALUES(explanation)'
    )->execute([$user_id, $pet_id, $lang, $sourceHash, $score, $result['text']]);
} catch (Exception $e) {
    // Kahit bigo ang pag-imbak, may sagot pa rin ang adopter
}

echo json_encode([
    'ok' => true,
    'pet_name' => $pet['name'],
    'explanation' => $result['text'],
    'cached' => false,
]);
