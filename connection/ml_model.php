<?php
/**
 * MACHINE LEARNING: Logistic Regression para sa pet matching.
 *
 * Ang ginagawa: natututo ang model kung aling mga criteria ang talagang
 * nagdadala ng MATAGUMPAY na adoption, gamit ang totoong kasaysayan ng shelter,
 * imbes na manual na timbang lang (60% preferences / 40% lifestyle).
 *
 *   Features (x)  - ang 13 "fit" value (0.0 - 1.0) na kinukuwenta na ng
 *                   computeMatchBreakdown(): allergy safety, kind, size, age,
 *                   health, breed, gender, temperament, living space, activity,
 *                   energy, maintenance, experience.
 *   Label (y)     - 1 = matagumpay: Approved at hindi ibinalik o binawi ang pet
 *                       (batay sa huling Pet Monitoring visit)
 *                   0 = hindi matagumpay: Rejected, o Approved pero ibinalik ng
 *                       adopter / binawi ng opisina
 *   Model         - P(success) = sigmoid(w · x + b)
 *   Training      - batch gradient descent, may L2 regularization at class
 *                   weighting (para hindi kumiling sa mas maraming klase)
 *   Validation    - leave-one-out (kaunting data) o 5-fold cross-validation
 *
 * COLD START: habang kulang pa sa ML_MIN_SAMPLES ang natapos na adoption, hindi
 * pa ginagamit ang model sa matching - ang manual na timbang ang gamit. Puwede
 * pa ring i-train para makita ang resulta, pero hindi ito ia-activate.
 *
 * SAFETY OVERRIDE: kahit anong sabihin ng model, hindi kailanman tataas ang
 * score ng pet na delikado sa allergy ng adopter (Allergy Safety fit = 0).
 */

if (!defined('ML_MIN_SAMPLES')) {
    define('ML_MIN_SAMPLES', 30);       // ilang natapos na adoption bago gamitin ang model
    define('ML_MIN_TRAIN_SAMPLES', 4);  // pinakakaunting puwedeng i-train (para sa demo)
    define('ML_EPOCHS', 3000);
    define('ML_LEARNING_RATE', 0.5);
    define('ML_L2', 0.01);
}

/** Pagkakasunod ng features - dapat pareho sa training at sa prediction. */
function ml_feature_names(): array
{
    return [
        'Allergy Safety', 'Pet Kind', 'Size', 'Age Group', 'Health Condition', 'Breed',
        'Gender', 'Temperament', 'Living Space Fit', 'Activity Level Fit', 'Energy Level',
        'Maintenance Fit', 'Experience Fit',
    ];
}

/** Ginagawang feature vector ang breakdown ng computeMatchBreakdown(). */
function ml_features_from_breakdown(array $breakdown): array
{
    $byLabel = [];
    foreach ($breakdown as $row) {
        $byLabel[$row['label']] = (float) $row['fit'];
    }
    $x = [];
    foreach (ml_feature_names() as $name) {
        $x[] = $byLabel[$name] ?? 0.5;
    }
    return $x;
}

function ml_sigmoid(float $z): float
{
    // Iwasan ang overflow ng exp() sa napakalaking value
    if ($z < -40) { return 0.0; }
    if ($z > 40) { return 1.0; }
    return 1.0 / (1.0 + exp(-$z));
}

/** P(success) ng isang feature vector ayon sa model. */
function ml_predict_proba(array $weights, float $bias, array $x): float
{
    $z = $bias;
    foreach ($weights as $i => $w) {
        $z += $w * ($x[$i] ?? 0.0);
    }
    return ml_sigmoid($z);
}

/**
 * Kinukuha ang training data mula sa mga natapos na application.
 *
 * Paalala: ang preferences ay ang KASALUKUYANG naka-save ng adopter; kung
 * binago niya ito pagkatapos mag-apply, iyon ang gagamitin.
 *
 * @return array{X: array<int, array<int, float>>, y: array<int, int>, rows: array<int, array>}
 */
function ml_build_dataset(PDO $pdo): array
{
    $apps = $pdo->query(
        "SELECT aa.application_id, aa.adopter_id, aa.pet_id, aa.application_status,
                (SELECT v.result FROM pet_visits v
                  WHERE v.application_id = aa.application_id
                  ORDER BY v.visit_date DESC, v.visit_id DESC LIMIT 1) AS last_visit_result
           FROM adoption_applications aa
          WHERE aa.application_status IN ('Approved', 'Rejected')"
    )->fetchAll(PDO::FETCH_ASSOC);

    $prefStmt = $pdo->prepare('SELECT * FROM adopter_preferences WHERE user_id = ?');
    $petStmt = $pdo->prepare('SELECT * FROM pet_inventory WHERE pet_id = ?');

    $X = [];
    $y = [];
    $rows = [];
    foreach ($apps as $app) {
        $prefStmt->execute([$app['adopter_id']]);
        $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);
        $petStmt->execute([$app['pet_id']]);
        $pet = $petStmt->fetch(PDO::FETCH_ASSOC);
        if (!$prefs || !$pet) {
            continue; // walang preferences o nabura ang pet - hindi magagamit
        }

        $returned = in_array($app['last_visit_result'], ['Returned by Adopter', 'Reclaimed by Office'], true);
        $label = ($app['application_status'] === 'Approved' && !$returned) ? 1 : 0;

        $X[] = ml_features_from_breakdown(computeMatchBreakdown($prefs, $pet));
        $y[] = $label;
        $rows[] = [
            'application_id' => (int) $app['application_id'],
            'status' => $app['application_status'],
            'visit' => $app['last_visit_result'],
            'label' => $label,
        ];
    }

    return ['X' => $X, 'y' => $y, 'rows' => $rows];
}

/**
 * Logistic Regression gamit ang batch gradient descent.
 *
 * @return array{weights: array<int, float>, bias: float}
 */
function ml_train_logreg(array $X, array $y): array
{
    $n = count($X);
    $d = count(ml_feature_names());
    $w = array_fill(0, $d, 0.0);
    $b = 0.0;
    if ($n === 0) {
        return ['weights' => $w, 'bias' => $b];
    }

    // Class weighting: kung mas marami ang matagumpay kaysa hindi (o baligtad),
    // pantay pa rin ang bigat ng dalawang klase sa pag-aaral ng model
    $pos = array_sum($y);
    $neg = $n - $pos;
    $cwPos = $pos > 0 ? $n / (2 * $pos) : 1.0;
    $cwNeg = $neg > 0 ? $n / (2 * $neg) : 1.0;

    for ($epoch = 0; $epoch < ML_EPOCHS; $epoch++) {
        $gw = array_fill(0, $d, 0.0);
        $gb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $err = ml_predict_proba($w, $b, $X[$i]) - $y[$i];
            $err *= $y[$i] === 1 ? $cwPos : $cwNeg;
            for ($j = 0; $j < $d; $j++) {
                $gw[$j] += $err * $X[$i][$j];
            }
            $gb += $err;
        }
        for ($j = 0; $j < $d; $j++) {
            $w[$j] -= ML_LEARNING_RATE * ($gw[$j] / $n + ML_L2 * $w[$j]);
        }
        $b -= ML_LEARNING_RATE * ($gb / $n);
    }

    return ['weights' => $w, 'bias' => $b];
}

/**
 * Sinusukat ang model sa data na HINDI nito nakita habang nagte-train.
 * Leave-one-out kapag 30 pababa ang data, 5-fold cross-validation kapag higit.
 */
function ml_cross_validate(array $X, array $y): array
{
    $n = count($X);
    $k = $n <= 30 ? $n : 5;
    $method = $n <= 30 ? 'Leave-one-out cross-validation' : '5-fold cross-validation';

    // Hinahalo ang pagkakasunod pero pare-pareho ang resulta bawat takbo
    $order = range(0, $n - 1);
    mt_srand(42);
    shuffle($order);
    mt_srand();

    $tp = $tn = $fp = $fn = 0;
    for ($fold = 0; $fold < $k; $fold++) {
        $trainX = $trainY = $testIdx = [];
        foreach ($order as $pos => $idx) {
            if ($pos % $k === $fold) {
                $testIdx[] = $idx;
            } else {
                $trainX[] = $X[$idx];
                $trainY[] = $y[$idx];
            }
        }
        // Kailangang may dalawang klase ang training set para may matutunan
        if (count(array_unique($trainY)) < 2) {
            continue;
        }
        $model = ml_train_logreg($trainX, $trainY);
        foreach ($testIdx as $idx) {
            $pred = ml_predict_proba($model['weights'], $model['bias'], $X[$idx]) >= 0.5 ? 1 : 0;
            if ($pred === 1 && $y[$idx] === 1) { $tp++; }
            elseif ($pred === 0 && $y[$idx] === 0) { $tn++; }
            elseif ($pred === 1) { $fp++; }
            else { $fn++; }
        }
    }

    $total = $tp + $tn + $fp + $fn;
    $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : null;
    $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : null;
    $f1 = ($precision !== null && $recall !== null && ($precision + $recall) > 0)
        ? 2 * $precision * $recall / ($precision + $recall) : null;

    return [
        'method' => $method,
        'accuracy' => $total > 0 ? ($tp + $tn) / $total : null,
        'precision' => $precision,
        'recall' => $recall,
        'f1' => $f1,
        'confusion' => ['tp' => $tp, 'tn' => $tn, 'fp' => $fp, 'fn' => $fn],
    ];
}

/**
 * Buong training: dataset -> validation -> final model sa lahat ng data -> save.
 *
 * @return array{ok: bool, message: string, model_id?: int}
 */
function ml_train_and_save(PDO $pdo, ?int $trainedBy = null): array
{
    $data = ml_build_dataset($pdo);
    $n = count($data['y']);
    $pos = array_sum($data['y']);
    $neg = $n - $pos;

    if ($n < ML_MIN_TRAIN_SAMPLES) {
        return ['ok' => false, 'message' => "Not enough completed applications to train yet ($n found, at least " . ML_MIN_TRAIN_SAMPLES . ' needed).'];
    }
    if ($pos === 0 || $neg === 0) {
        return ['ok' => false, 'message' => 'The model needs examples of BOTH successful and unsuccessful adoptions to learn from. Right now all ' . $n . ' completed applications have the same outcome.'];
    }

    $cv = ml_cross_validate($data['X'], $data['y']);
    $model = ml_train_logreg($data['X'], $data['y']);
    $active = $n >= ML_MIN_SAMPLES ? 1 : 0;

    $pdo->beginTransaction();
    try {
        if ($active) {
            $pdo->exec('UPDATE ml_models SET is_active = 0');
        }
        $pdo->prepare(
            'INSERT INTO ml_models (algorithm, feature_names, weights, bias, n_samples, n_positive, n_negative,
                                    accuracy, precision_score, recall_score, f1_score, validation, is_active, trained_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'Logistic Regression',
            json_encode(ml_feature_names()),
            json_encode($model['weights']),
            $model['bias'],
            $n, $pos, $neg,
            $cv['accuracy'], $cv['precision'], $cv['recall'], $cv['f1'],
            $cv['method'],
            $active,
            $trainedBy,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Training finished but the model could not be saved: ' . $e->getMessage()];
    }

    $msg = "Model trained on $n completed applications ($pos successful, $neg unsuccessful).";
    $msg .= $active
        ? ' It is now ACTIVE and is used for pet matching.'
        : ' It is NOT active yet - the system keeps using the manual weights until there are at least ' . ML_MIN_SAMPLES . ' completed applications.';

    return ['ok' => true, 'message' => $msg, 'model_id' => $id];
}

/**
 * Ang model na ginagamit ngayon sa matching, o null kung wala pa (fallback sa
 * manual na timbang). Isang beses lang kinukuha bawat request.
 */
function ml_active_model(): ?array
{
    static $loaded = false;
    static $model = null;
    if ($loaded) {
        return $model;
    }
    $loaded = true;

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $row = $pdo->query('SELECT * FROM ml_models WHERE is_active = 1 ORDER BY model_id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $weights = json_decode($row['weights'], true);
            $names = json_decode($row['feature_names'], true);
            // Kapag nagbago ang listahan ng features, hindi na tugma ang lumang model
            if (is_array($weights) && $names === ml_feature_names()) {
                $model = ['model_id' => (int) $row['model_id'], 'weights' => $weights, 'bias' => (float) $row['bias']];
            }
        }
    } catch (Exception $e) {
        $model = null; // wala pang table o may error - manual na timbang na lang
    }
    return $model;
}
