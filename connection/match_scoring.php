<?php
require_once __DIR__ . '/app_url.php';
require_once __DIR__ . '/ml_model.php';

// Compatibility scoring sa pagitan ng adopter_preferences at pet_inventory.
// Ang buong score ay nakabatay sa PREFERENCES at LIFESTYLE ng adopter.
//
// MUST-HAVE (hindi nagbibigay ng puntos - nagbabawas kapag hindi tugma):
//   - Allergy Safety : Fur Allergy + High shedding = 0 ang buong score (hindi
//                      dapat i-adopt). Walang allergy = walang epekto.
//   - Pet Kind       : kapag Aso ang gusto pero Pusa ang pet (o baligtad), 30%
//                      na lang ng score ang natitira - hindi ito irerekomenda.
//
// TIMBANG NG PUNTOS (kabuuang 100%):
//   60%  PET PREFERENCES - ang mismong gustong katangian ng pet: size, age,
//                          gender, energy, health, breed, temperament
//   40%  LIFESTYLE FIT   - bagay ba ang pet sa bahay at pamumuhay ng adopter:
//                          living space, activity, maintenance, pet experience
//
// Ang "malapit" na tugma (hal. Kitten para sa naghahanap ng Young Adult, o
// Medium para sa Small) ay may kalahating puntos, hindi zero.
//
// Ang shedding_level ng pet ang pangunahing batayan ng allergy check. Kapag wala
// pang nakatakdang shedding_level, huhulaan ito mula sa temperament/description,
// at maingat ang magiging marka (hindi basta ipinapasa).

if (!defined('MATCH_THRESHOLD')) {
    define('MATCH_THRESHOLD', 50);
}

// Pinakamababang score para IREKOMENDA ang pet sa adopter (AI Recommendation at
// "Recommended For You" sa dashboard). Mas mababa rito = hindi bagay, hindi
// irerekomenda kahit gaano katagal nang naghihintay sa shelter.
if (!defined('MATCH_RECOMMEND_MIN')) {
    define('MATCH_RECOMMEND_MIN', 70);
}

// Gaano natitira sa score kapag hindi tugma ang uri ng hayop (Aso vs Pusa)
if (!defined('MATCH_KIND_MISMATCH_FACTOR')) {
    define('MATCH_KIND_MISMATCH_FACTOR', 0.3);
}

/**
 * Ang match score (0-100) na ginagamit ng buong system.
 *
 * Kapag may ACTIVE na Machine Learning model (connection/ml_model.php), ang
 * natutunang Logistic Regression ang nagbibigay ng score: ang probabilidad na
 * magiging matagumpay ang adoption. Kapag wala pa (kulang ang data), ang
 * manual na timbang (60% preferences / 40% lifestyle, may must-have na
 * allergy at uri ng hayop) ang ginagamit - computeRuleScore().
 */
function computeMatchScore(array $prefs, array $pet): int {
    $breakdown = computeMatchBreakdown($prefs, $pet);
    $ruleScore = computeRuleScoreFromBreakdown($breakdown);

    $model = ml_active_model();
    if ($model === null) {
        return $ruleScore;
    }

    $mlScore = (int) round(100 * ml_predict_proba($model['weights'], $model['bias'], ml_features_from_breakdown($breakdown)));

    // Must-have override: kapag delikado sa allergy o maling uri ng hayop, hindi
    // kailanman mas mataas sa manual na score, anuman ang natutunan ng model
    foreach ($breakdown as $row) {
        if (!empty($row['gate']) && $row['factor'] < 1.0) {
            $mlScore = min($mlScore, $ruleScore);
        }
    }

    return max(0, min(100, $mlScore));
}

/**
 * Match ng isang APPLICATION - naka-freeze sa oras ng pag-submit.
 * Ang naka-save na match_score/match_breakdown ang ginagamit, kaya hindi ito
 * nagbabago kahit palitan pa ng adopter ang preferences (ang bagong preferences
 * ay para lang sa mga susunod na recommendation at application).
 *
 * $row: kailangan ang application_id, saved_match_score, saved_match_breakdown,
 * at ang mga field ng preferences + pet (para sa lumang application na wala pang
 * naka-save - kinukuwenta at sine-save ito sa unang pagkakataon, saka naka-freeze na).
 * Ibinabalik: ['score' => ?int, 'breakdown' => ?array]
 */
function sp_application_match(PDO $pdo, array $row): array
{
    if (isset($row['saved_match_score']) && $row['saved_match_score'] !== null) {
        $breakdown = json_decode((string) ($row['saved_match_breakdown'] ?? ''), true);
        return ['score' => (int) $row['saved_match_score'], 'breakdown' => is_array($breakdown) ? $breakdown : null];
    }
    if (empty($row['pref_kind'])) {
        return ['score' => null, 'breakdown' => null];
    }
    $snap = sp_match_snapshot($row, $row);
    if (!empty($row['application_id'])) {
        try {
            $pdo->prepare('UPDATE adoption_applications SET match_score = ?, match_breakdown = ? WHERE application_id = ? AND match_score IS NULL')
                ->execute([$snap['score'], $snap['breakdown_json'], (int) $row['application_id']]);
        } catch (Throwable $e) {
            // hindi ma-save ngayon - susubukan ulit sa susunod na pagbukas
        }
    }
    return ['score' => $snap['score'], 'breakdown' => $snap['breakdown']];
}

/** Score at breakdown na ise-save sa application (pag-submit o unang pag-freeze). */
function sp_match_snapshot(array $prefs, array $pet): array
{
    $breakdown = computeMatchBreakdown($prefs, $pet);
    return [
        'score' => computeMatchScore($prefs, $pet),
        'breakdown' => $breakdown,
        'breakdown_json' => json_encode($breakdown, JSON_UNESCAPED_UNICODE),
    ];
}

/** Score gamit lang ang manual na timbang (walang ML) - pang-kumpara at fallback. */
function computeRuleScore(array $prefs, array $pet): int {
    return computeRuleScoreFromBreakdown(computeMatchBreakdown($prefs, $pet));
}

/**
 * Kabuuan ng puntos (preferences + lifestyle), pinarami sa mga must-have.
 */
function computeRuleScoreFromBreakdown(array $breakdown): int {
    $score = 0.0;
    $factor = 1.0;
    foreach ($breakdown as $criterion) {
        if (!empty($criterion['gate'])) {
            $factor *= $criterion['factor'];
        } else {
            $score += $criterion['points'];
        }
    }
    return (int) round(max(0, min(100, $score * $factor)));
}

// Parehong per-criterion breakdown na ginagamit ng computeMatchScore() sa itaas,
// pero ibinabalik dito ang detalye ng bawat kategorya para maipakita sa staff kung
// bakit ganoon ang naging match percentage (ginagamit sa "View Matching" sa Review page).
function computeMatchBreakdown(array $prefs, array $pet): array {
    $rows = [];
    $add = function (string $group, string $label, float $weight, float $fit, $prefVal, $petVal) use (&$rows) {
        $rows[] = [
            'group' => $group,
            'label' => $label,
            'weight' => $weight,
            'fit' => $fit,
            'points' => round($weight * $fit, 1),
            'gate' => false,
            'factor' => 1.0,
            'pref' => ($prefVal === '' || $prefVal === null) ? 'Any' : $prefVal,
            'pet' => ($petVal === '' || $petVal === null) ? 'N/A' : $petVal,
        ];
    };
    // Must-have: walang puntos, pero pinararami ng "factor" ang buong score
    $gate = function (string $label, float $fit, float $factor, $prefVal, $petVal) use (&$rows) {
        $rows[] = [
            'group' => 'Must-Have',
            'label' => $label,
            'weight' => 0,
            'fit' => $fit,
            'points' => 0,
            'gate' => true,
            'factor' => $factor,
            'pref' => ($prefVal === '' || $prefVal === null) ? 'Any' : $prefVal,
            'pet' => ($petVal === '' || $petVal === null) ? 'N/A' : $petVal,
        ];
    };

    // ---- MUST-HAVE -------------------------------------------------------------
    $shedding = petSheddingLevel($pet);
    $allergy = allergyFit($prefs['allergies'] ?? 'None', $shedding);
    $gate('Allergy Safety', $allergy, $allergy, $prefs['allergies'] ?? 'None', $shedding . ' shedding');

    $kindFit = matchExactOrAny($prefs['pref_kind'] ?? 'Any', $pet['kind'] ?? '');
    $gate('Pet Kind', $kindFit, $kindFit >= 1.0 ? 1.0 : MATCH_KIND_MISMATCH_FACTOR, $prefs['pref_kind'] ?? 'Any', $pet['kind'] ?? '');

    // ---- 60%: PET PREFERENCES ----------------------------------------------------
    $add('Pet Preferences', 'Size', 14, matchOrdinalOrAny($prefs['pref_size'] ?? 'Any', $pet['size'] ?? '', ['Small', 'Medium', 'Large']), $prefs['pref_size'] ?? 'Any', $pet['size'] ?? '');
    $add('Pet Preferences', 'Age Group', 14, matchOrdinalOrAny($prefs['pref_age_group'] ?? 'Any', $pet['age_group'] ?? '', ['Puppy/Kitten', 'Young Adult', 'Adult', 'Senior']), $prefs['pref_age_group'] ?? 'Any', $pet['age_group'] ?? '');
    $add('Pet Preferences', 'Gender', 12, matchExactOrAny($prefs['pref_gender'] ?? 'Any', $pet['gender'] ?? ''), $prefs['pref_gender'] ?? 'Any', $pet['gender'] ?? '');
    $add('Pet Preferences', 'Energy Level', 10, matchOrdinalOrAny($prefs['pref_energy_level'] ?? 'Any', $pet['energy_level'] ?? '', ['Low', 'Moderate', 'High']), $prefs['pref_energy_level'] ?? 'Any', $pet['energy_level'] ?? '');
    $add('Pet Preferences', 'Health Condition', 5, matchHealthCondition($prefs['pref_health_condition'] ?? 'Healthy', $pet['health_condition'] ?? ''), $prefs['pref_health_condition'] ?? 'Healthy', $pet['health_condition'] ?? '');
    $add('Pet Preferences', 'Breed', 3, matchTextOrAny($prefs['pref_breed'] ?? 'Any', $pet['breed'] ?? ''), $prefs['pref_breed'] ?? 'Any', $pet['breed'] ?? '');
    $add('Pet Preferences', 'Temperament', 2, matchTextOrAny($prefs['pref_temperament'] ?? 'Any', $pet['temperament'] ?? ''), $prefs['pref_temperament'] ?? 'Any', $pet['temperament'] ?? '');

    // ---- 40%: LIFESTYLE FIT ------------------------------------------------------
    $add('Lifestyle Fit', 'Living Space Fit', 12, livingSpaceSizeFit($prefs['living_space'] ?? '', $pet['size'] ?? ''), $prefs['living_space'] ?? '', $pet['size'] ?? '');
    $add('Lifestyle Fit', 'Activity Level Fit', 12, activityEnergyFit($prefs['activity_level'] ?? '', $pet['energy_level'] ?? ''), $prefs['activity_level'] ?? '', $pet['energy_level'] ?? '');
    $add('Lifestyle Fit', 'Maintenance Fit', 8, maintenanceFit($prefs['maintenance_level'] ?? '', $pet['energy_level'] ?? '', $pet['health_condition'] ?? ''), $prefs['maintenance_level'] ?? '', $pet['energy_level'] ?? '');
    $add('Lifestyle Fit', 'Experience Fit', 8, experienceFit($prefs['pet_experience'] ?? '', $pet), $prefs['pet_experience'] ?? '', petCareDifficultyLabel($pet));

    return $rows;
}

/**
 * Para sa mga katangiang may pagkakasunod (size, edad, energy): eksaktong tugma
 * = buong puntos, katabing antas = kalahati, malayo = wala.
 */
function matchOrdinalOrAny(?string $prefVal, ?string $petVal, array $order): float {
    $prefVal = trim((string) $prefVal);
    if ($prefVal === '' || strcasecmp($prefVal, 'Any') === 0) {
        return 1.0;
    }
    $a = array_search($prefVal, $order, true);
    $b = array_search((string) $petVal, $order, true);
    if ($a === false || $b === false) {
        return matchExactOrAny($prefVal, $petVal);
    }
    $gap = abs($a - $b);
    return $gap === 0 ? 1.0 : ($gap === 1 ? 0.5 : 0.0);
}

/**
 * Gaano kahirap alagaan ang pet (0 = madali). Mas mahirap ang mataas ang enerhiya,
 * may sakit o sugat, matanda, o mailap/matatakutin.
 */
function petCareDifficulty(array $pet): int {
    $difficulty = ['Low' => 0, 'Moderate' => 1, 'High' => 2][$pet['energy_level'] ?? ''] ?? 1;
    $health = (string) ($pet['health_condition'] ?? '');
    if (in_array($health, ['Medical Condition', 'Recovering/Injured', 'Partially Blind/Deaf'], true)) {
        $difficulty += 2;
    } elseif ($health === 'Elderly') {
        $difficulty += 1;
    }
    if (preg_match('/\b(wary|defensive|aggressive|fearful|shy)\b/i', (string) ($pet['temperament'] ?? ''))) {
        $difficulty += 1;
    }
    return $difficulty;
}

function petCareDifficultyLabel(array $pet): string {
    $d = petCareDifficulty($pet);
    return $d <= 1 ? 'Easy to care for' : ($d <= 2 ? 'Some care needed' : 'Needs experienced owner');
}

/** Bagay ba ang hirap ng pag-aalaga sa karanasan ng adopter. */
function experienceFit(string $experience, array $pet): float {
    $d = petCareDifficulty($pet);
    switch ($experience) {
        case 'Beginner':
            return $d >= 4 ? 0.15 : [1.0, 0.9, 0.6, 0.35][$d];
        case 'Intermediate':
            return $d <= 2 ? 1.0 : ($d === 3 ? 0.7 : 0.45);
        case 'Experienced':
            return 1.0;
        default:
            return 0.7;
    }
}

/**
 * Kinukuha ang shedding level ng pet. Kung wala pang nakatakda, huhulaan mula sa
 * temperament/description - pero 'Unknown' ang ibabalik kapag walang malinaw na hudyat,
 * para maingat ang pagtrato ng allergy check.
 */
function petSheddingLevel(array $pet): string {
    $set = trim((string) ($pet['shedding_level'] ?? ''));
    if ($set !== '') {
        return $set;
    }

    $text = strtolower(($pet['temperament'] ?? '') . ' ' . ($pet['description'] ?? '') . ' ' . ($pet['breed'] ?? ''));

    foreach (['hypoallergenic', 'low shed', 'low-shed', 'non-shedding', 'nonshedding', 'minimal shedding', 'hairless'] as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return 'Low';
        }
    }
    foreach (['heavy shed', 'heavy-shed', 'high shedding', 'sheds a lot', 'double coat', 'long hair', 'long-haired', 'fluffy'] as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return 'High';
        }
    }

    return 'Unknown';
}

/**
 * Allergy compatibility - ito ang may hawak ng buong 50%.
 *
 * Fur Allergy + High shedding  = 0.0  -> nawawala ang buong 50%, hindi dapat i-adopt
 * Sensitive to Shedding        = mas maluwag pero may bawas pa rin
 * Walang allergy               = 1.0  -> buong puntos
 */
function allergyFit(string $allergies, string $sheddingLevel): float {
    $allergies = trim($allergies);
    if ($allergies === '' || strcasecmp($allergies, 'None') === 0) {
        return 1.0;
    }

    if (strcasecmp($allergies, 'Fur Allergy') === 0) {
        switch ($sheddingLevel) {
            case 'Low':      return 1.0;   // ligtas - hypoallergenic o halos walang lagas
            case 'Moderate': return 0.15;  // delikado
            case 'High':     return 0.0;   // hindi dapat i-adopt
            default:         return 0.30;  // hindi pa alam ang shedding - maingat
        }
    }

    if (strcasecmp($allergies, 'Sensitive to Shedding') === 0) {
        switch ($sheddingLevel) {
            case 'Low':      return 1.0;
            case 'Moderate': return 0.55;
            case 'High':     return 0.10;
            default:         return 0.50;
        }
    }

    return 1.0;
}

function matchExactOrAny(?string $prefVal, ?string $petVal): float {
    $prefVal = trim((string) $prefVal);
    if ($prefVal === '' || strcasecmp($prefVal, 'Any') === 0) {
        return 1.0;
    }
    return (strcasecmp($prefVal, (string) $petVal) === 0) ? 1.0 : 0.0;
}

function matchTextOrAny(?string $prefVal, ?string $petVal): float {
    $prefVal = trim((string) $prefVal);
    $petVal = trim((string) $petVal);
    if ($prefVal === '' || strcasecmp($prefVal, 'Any') === 0) {
        return 1.0;
    }
    if ($petVal === '') {
        return 0.5;
    }
    if (stripos($petVal, $prefVal) !== false || stripos($prefVal, $petVal) !== false) {
        return 1.0;
    }
    return 0.0;
}

function matchHealthCondition(?string $prefVal, ?string $petVal): float {
    if (strcasecmp((string) $prefVal, 'Any') === 0) {
        return 1.0;
    }
    return (stripos((string) $petVal, 'Healthy') !== false) ? 1.0 : 0.3;
}

function livingSpaceSizeFit(string $livingSpace, string $petSize): float {
    $table = [
        'Apartment'              => ['Small' => 1.0, 'Medium' => 0.6, 'Large' => 0.1],
        'Condo'                  => ['Small' => 1.0, 'Medium' => 0.7, 'Large' => 0.2],
        'House with Small Space' => ['Small' => 1.0, 'Medium' => 0.9, 'Large' => 0.5],
        'House with Yard'        => ['Small' => 1.0, 'Medium' => 1.0, 'Large' => 1.0],
    ];
    return $table[$livingSpace][$petSize] ?? 0.5;
}

function activityEnergyFit(string $activityLevel, string $petEnergy): float {
    $table = [
        'Low/Chill' => ['Low' => 1.0, 'Moderate' => 0.5, 'High' => 0.1],
        'Moderate'  => ['Low' => 0.7, 'Moderate' => 1.0, 'High' => 0.7],
        'Active'    => ['Low' => 0.3, 'Moderate' => 0.8, 'High' => 1.0],
    ];
    return $table[$activityLevel][$petEnergy] ?? 0.5;
}

function maintenanceFit(string $maintenanceLevel, string $petEnergy, ?string $healthCondition): float {
    $table = [
        'Low Maintenance'  => ['Low' => 1.0, 'Moderate' => 0.6, 'High' => 0.2],
        'Moderate'         => ['Low' => 0.8, 'Moderate' => 1.0, 'High' => 0.8],
        'High Maintenance' => ['Low' => 0.4, 'Moderate' => 0.7, 'High' => 1.0],
    ];
    $base = $table[$maintenanceLevel][$petEnergy] ?? 0.5;
    if ($maintenanceLevel === 'Low Maintenance' && stripos((string) $healthCondition, 'Healthy') === false) {
        $base *= 0.7;
    }
    return $base;
}

/**
 * Sinasagot nito ang tanong na "anong pet ang bagay sa akin", batay sa
 * preferences ng adopter: 70% pataas lang ang ipinapakita, at ang PINAKABAGAY
 * ang nauuna. Ang tagal sa shelter ay pang-tiebreak lang kapag pareho ang score -
 * hindi nito kailanman ipapasok ang pet na hindi bagay, gaano man katagal.
 * Ginagamit ito ng AI Recommendation page ng adopter.
 *
 * "Ibang pets" ($excludeIds = mga pet_id na naipakita na): ibinibigay ang
 * susunod na pinakabagay. Kapag ubos na ang 70% pataas, ang mga "close match"
 * (MATCH_THRESHOLD hanggang 69%) na ang ipinapakita - may tatak na close.
 *
 * Ibinabalik: reply (text kung walang preferences/match), pets (cards), link (call to action),
 * has_more (may iba pang maipapakita).
 */
function sp_adopter_recommendations(PDO $pdo, int $user_id, array $excludeIds = []): array
{
    $MIN_SCORE = MATCH_RECOMMEND_MIN;
    $MAX_SHOWN = 5;
    $excluded = array_flip(array_map('intval', $excludeIds));
    $prefsUrl = sp_url('Adopter_File/adopter_preferences.php');

    $prefStmt = $pdo->prepare('SELECT * FROM adopter_preferences WHERE user_id = ?');
    $prefStmt->execute([$user_id]);
    $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);

    if (!$prefs) {
        return [
            'reply' => "I need to know you first before I can match you with a pet. Tell me about your home and lifestyle, then ask me again.",
            'pets' => [],
            'link' => ['label' => 'Set my preferences', 'url' => $prefsUrl],
        ];
    }

    // Ang bilang ng araw ay kinukuwenta ng database mismo, para walang mali dahil
    // sa magkaibang timezone ng PHP at MySQL (dati: "-1 days in the shelter")
    // Hindi kasama ang alagang may nauna nang nag-apply na ibang adopter
    $availStmt = $pdo->prepare(
        "SELECT *, GREATEST(0, DATEDIFF(NOW(), created_at)) AS days_waiting
           FROM pet_inventory WHERE status = 'Available' AND " . sp_pet_open_sql()
    );
    $availStmt->execute([(int) $user_id]);
    $available = $availStmt->fetchAll(PDO::FETCH_ASSOC);
    $totalAvailable = count($available);

    $matched = [];
    foreach ($available as $pet) {
        $score = computeMatchScore($prefs, $pet);
        if ($score < MATCH_THRESHOLD) {
            continue; // hindi bagay sa preferences - hindi irerekomenda kahit matagal na
        }
        $pet['match_score'] = $score;
        $pet['days_in_shelter'] = (int) $pet['days_waiting'];
        $matched[] = $pet;
    }

    // Pinakabagay muna. Kapag pareho ang score, ang mas matagal nang
    // naghihintay sa shelter ang mauuna.
    usort($matched, function ($a, $b) {
        if ($a['match_score'] !== $b['match_score']) {
            return $b['match_score'] <=> $a['match_score'];
        }
        return $b['days_in_shelter'] <=> $a['days_in_shelter'];
    });

    // Ang rank ay posisyon sa BUONG listahan, kaya "Top 6" ang susunod pagkatapos ng Top 5
    foreach ($matched as $i => &$pet) {
        $pet['rank'] = $i + 1;
    }
    unset($pet);

    $remaining = array_values(array_filter($matched, function ($pet) use ($excluded) {
        return !isset($excluded[(int) $pet['pet_id']]);
    }));
    $strong = array_values(array_filter($remaining, function ($pet) use ($MIN_SCORE) {
        return $pet['match_score'] >= $MIN_SCORE;
    }));
    // Unang hiling: 70% pataas lang. "Ibang pets": close match kapag ubos na ang 70% pataas.
    $pool = $strong ?: ($excluded ? $remaining : []);
    $isClose = !$strong;

    if (!$pool) {
        if ($excluded) {
            return [
                'reply' => "Those are all the pets that fit your preferences right now. Try updating your preferences to see different pets, or browse everything in Search Pets.",
                'pets' => [],
                'link' => ['label' => 'Update my preferences', 'url' => $prefsUrl],
                'has_more' => false,
            ];
        }
        return [
            'reply' => "None of the $totalAvailable available pets reached a 70% match with your preferences yet. I only recommend pets I am confident about, so try updating your preferences or browse everything in Search Pets.",
            'pets' => [],
            'link' => ['label' => 'Update my preferences', 'url' => $prefsUrl],
            'has_more' => false,
        ];
    }

    $shown = array_slice($pool, 0, $MAX_SHOWN);
    $hasMore = count($remaining) > count($shown);

    $cards = [];
    foreach ($shown as $pet) {
        $days = (int) $pet['days_in_shelter'];
        $petId = (int) $pet['pet_id'];
        $cards[] = [
            'rank' => $pet['rank'],
            'close' => $isClose,
            'breakdown' => sp_score_explanation($prefs, $pet, (int) $pet['match_score']),
            'pet_id' => $petId,
            'name' => $pet['name'],
            'kind' => $pet['kind'],
            'breed' => $pet['breed'],
            'age_group' => $pet['age_group'],
            'score' => (int) $pet['match_score'],
            'waiting' => $days . ' day' . ($days === 1 ? '' : 's') . ' in the shelter',
            'photo' => !empty($pet['photo_url']) ? $pet['photo_url'] : null,
            'url' => sp_url('Adopter_File/pet_details.php?pet_id=' . $petId),
            'apply_url' => sp_url('Adopter_File/adoption_application.php?pet_id=' . $petId),
        ];
    }

    // Maikli lang - ang mga card mismo ang nagsasabi ng score at ng detalye
    if ($isClose) {
        $reply = 'These are close matches - a little below 70%, but still worth a look.';
    } else {
        $reply = count($cards) === 1
            ? 'Here is a pet that is good for you.'
            : 'Here are the pets that are good for you.';
    }

    return ['reply' => $reply, 'pets' => $cards, 'link' => null, 'has_more' => $hasMore];
}

/**
 * Paliwanag ng match % para sa card ng adopter: bawat criterion, ilang puntos ang
 * nakuha sa posibleng puntos, at ang gusto ng adopter kumpara sa pet.
 * Kapag ML model ang nagbigay ng score, kasama ang rule-based na kabuuan para
 * maipaliwanag kung bakit iba ang huling %.
 */
function sp_score_explanation(array $prefs, array $pet, int $finalScore): array
{
    $breakdown = computeMatchBreakdown($prefs, $pet);
    $rows = [];
    foreach ($breakdown as $row) {
        $rows[] = [
            'group' => $row['group'],
            'label' => $row['label'],
            'points' => $row['gate'] ? null : (float) $row['points'],
            'weight' => $row['gate'] ? null : (float) $row['weight'],
            'ok' => $row['gate'] ? $row['factor'] >= 1.0 : $row['fit'] >= 1.0,
            'factor' => (float) $row['factor'],
            'pref' => (string) $row['pref'],
            'pet' => (string) $row['pet'],
        ];
    }
    return [
        'rows' => $rows,
        'rule_score' => computeRuleScoreFromBreakdown($breakdown),
        'final_score' => $finalScore,
    ];
}
