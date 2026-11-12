<?php
session_start();
require '../connection/database.php';
require_once '../connection/match_scoring.php';
require_once '../connection/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../Login_Page/login.php");
    exit();
}
if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Admin access only.');
}

$user_id = (int) $_SESSION['user_id'];
$sp_unread_count = sp_unread_message_count($pdo, $user_id);
$username = $_SESSION['username'] ?? '';

$stmt = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$profile_image = $stmt->fetchColumn() ?: '';
$profile_img_path = !empty($profile_image) ? '../uploads/' . $profile_image : null;

// ---- Pag-train (POST, tapos redirect para hindi maulit kapag nag-refresh) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'train') {
    $result = ml_train_and_save($pdo, $user_id);
    $_SESSION[$result['ok'] ? 'success_message' : 'error_message'] = $result['message'];
    log_activity($pdo, 'Trained the ML matching model', $result['message'], $result['ok'] ? 'Completed' : 'Failed');
    header('Location: ml_model.php');
    exit();
}

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// ---- Datos para sa page ----
$dataset = ml_build_dataset($pdo);
$n = count($dataset['y']);
$nPos = array_sum($dataset['y']);
$nNeg = $n - $nPos;
$canTrain = $n >= ML_MIN_TRAIN_SAMPLES && $nPos > 0 && $nNeg > 0;
$progress = min(100, (int) round($n / ML_MIN_SAMPLES * 100));

$activeModel = ml_active_model();
$latest = $pdo->query('SELECT * FROM ml_models ORDER BY model_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
$history = $pdo->query(
    'SELECT m.*, u.username AS trainer FROM ml_models m
       LEFT JOIN users u ON u.user_id = m.trained_by
      ORDER BY m.model_id DESC LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

// Manual na timbang (galing mismo sa scoring engine) para ikumpara sa natutunan
$manualWeights = [];
foreach (computeMatchBreakdown([], []) as $row) {
    $manualWeights[$row['label']] = (float) $row['weight'];
}

$learned = [];
if ($latest) {
    $w = json_decode($latest['weights'], true) ?: [];
    $sumAbs = array_sum(array_map('abs', $w)) ?: 1;
    foreach (ml_feature_names() as $i => $name) {
        $coef = (float) ($w[$i] ?? 0);
        $learned[] = [
            'name' => $name,
            'manual' => $manualWeights[$name] ?? 0,
            'coef' => $coef,
            'importance' => abs($coef) / $sumAbs * 100,
        ];
    }
}

function ml_pct($v): string
{
    return $v === null ? '—' : number_format($v * 100, 1) . '%';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMART PET - ML Model</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .ml-head h2 { font-size: 24px; color: #1b1b1b; }
    .ml-head p { color: #56635c; font-size: 14px; margin-top: 4px; }
    .ml-alert { padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 600; }
    .ml-alert.ok { background: #e3f6ea; color: #1b6b3a; }
    .ml-alert.bad { background: #fdecea; color: #a8322a; }

    .ml-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 20px; }
    .ml-card { background: #fff; border-radius: 18px; padding: 22px; border: 1.5px solid #e4ded1; }
    .ml-card h3 { font-size: 17px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .ml-card h3 i { color: var(--primary-green); }

    .ml-status { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 999px; font-weight: 700; font-size: 13px; }
    .ml-status.on { background: #e3f6ea; color: #1b6b3a; }
    .ml-status.off { background: #fff4e3; color: #9a5b00; }
    .ml-status .dot { width: 9px; height: 9px; border-radius: 50%; background: currentColor; }
    .ml-status-text { margin: 12px 0 16px; font-size: 14px; color: #3b4541; line-height: 1.55; }

    .ml-progress { height: 12px; background: #eef1ef; border-radius: 999px; overflow: hidden; }
    .ml-progress > span { display: block; height: 100%; background: linear-gradient(90deg, var(--accent-orange), var(--primary-green)); border-radius: 999px; }
    .ml-progress-label { display: flex; justify-content: space-between; font-size: 12.5px; color: #56635c; margin-top: 6px; font-weight: 600; }

    .ml-counts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 16px 0; }
    .ml-count { background: #faf8f5; border-radius: 12px; padding: 12px; text-align: center; }
    .ml-count strong { display: block; font-size: 22px; color: var(--dark-green); }
    .ml-count span { font-size: 12px; color: #56635c; }

    .ml-train-btn {
      width: 100%; border: none; border-radius: 12px; padding: 14px; font-size: 15px; font-weight: 700;
      background: var(--primary-green); color: #fff; cursor: pointer; font-family: inherit;
      box-shadow: 0 4px 0 var(--dark-green); transition: transform .15s ease;
    }
    .ml-train-btn:hover { transform: translateY(-2px); }
    .ml-train-btn:disabled { background: #b9c3be; box-shadow: none; cursor: not-allowed; transform: none; }
    .ml-note { font-size: 12.5px; color: #56635c; margin-top: 10px; line-height: 1.5; }

    .ml-metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
    .ml-metric { background: #1b4332; color: #fff; border-radius: 14px; padding: 14px 12px; text-align: center; }
    .ml-metric strong { display: block; font-size: 22px; }
    .ml-metric span { font-size: 12px; opacity: .9; }
    .ml-confusion { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 12px; font-size: 13px; }
    .ml-confusion div { background: #faf8f5; border-radius: 10px; padding: 10px; }
    .ml-confusion b { color: var(--dark-green); }

    .ml-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .ml-table th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: #56635c; padding: 10px 8px; border-bottom: 2px solid #eee; }
    .ml-table td { padding: 10px 8px; border-bottom: 1px solid #f1f1f1; vertical-align: middle; }
    .ml-bar { height: 8px; background: #eef1ef; border-radius: 4px; overflow: hidden; min-width: 80px; }
    .ml-bar > span { display: block; height: 100%; border-radius: 4px; }
    .ml-bar.manual > span { background: #b9c3be; }
    .ml-bar.learned > span { background: var(--primary-green); }
    .ml-coef.neg { color: #c5221f; }

    .ml-steps { counter-reset: s; list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
    .ml-steps li { counter-increment: s; display: flex; gap: 12px; font-size: 14px; line-height: 1.5; color: #2d3748; }
    .ml-steps li::before {
      content: counter(s); flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%;
      background: var(--accent-orange); color: #1b1b1b; font-weight: 800; font-size: 13px;
      display: flex; align-items: center; justify-content: center;
    }
    .ml-formula { background: #1b1b1b; color: #f4a261; border-radius: 10px; padding: 12px 14px; font-family: Consolas, monospace; font-size: 14px; margin: 12px 0 0; overflow-x: auto; }
    .ml-empty { text-align: center; color: #56635c; padding: 24px; border: 2px dashed #e4ded1; border-radius: 12px; }

    @media (max-width: 1100px) { .ml-grid { grid-template-columns: 1fr; } }
    @media (max-width: 640px) {
      .ml-metrics { grid-template-columns: repeat(2, 1fr); }
      .ml-counts { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="app-container">
    <aside class="sidebar">
      <div class="brand">
        <div class="logo-circle"><img src="../Images/caloocan_logo.jpg" alt="City Logo"></div>
        <h2>SMART <span>PET</span></h2>
      </div>
      <nav class="sidebar-menu">
        <a href="index.php" class="menu-item"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="Manage_staff_file/manage_staff.php" class="menu-item"><i class="fa-solid fa-user-gear"></i> Manage Staff</a>
        <a href="Manage_adopters_file/manage_adopters.php" class="menu-item"><i class="fa-solid fa-users"></i> Manage Adopters</a>
        <a href="manage_pets.php" class="menu-item"><i class="fa-solid fa-paw"></i> Manage Pets</a>
        <a href="payments.php" class="menu-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
        <a href="reports.php" class="menu-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
        <a href="messages.php" class="menu-item"><i class="fa-solid fa-comment-dots"></i> Messages<?php if ($sp_unread_count > 0): ?><span class="sp-unread-badge"><?php echo $sp_unread_count > 99 ? '99+' : $sp_unread_count; ?></span><?php endif; ?></a>
        <a href="activity_logs.php" class="menu-item"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</a>
        <a href="ml_model.php" class="menu-item active"><i class="fa-solid fa-brain"></i> ML Model</a>
      </nav>
      <div class="sidebar-footer">
        <div class="city-illustration"><img src="../Images/caloocan_image.png" alt="Caloocan Arch Landmark" width="300"></div>
      </div>
    </aside>

    <main class="main-wrapper">
      <header class="top-header">
        <a href="edit_profile.php" class="profile-section" style="text-decoration: none; color: inherit;">
          <div class="avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
            <?php if ($profile_img_path && file_exists($profile_img_path)): ?>
              <img src="<?php echo htmlspecialchars($profile_img_path); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
            <?php else: ?>
              <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
            <?php endif; ?>
          </div>
          <span class="profile-name"><?php echo htmlspecialchars($username); ?></span>
        </a>
      </header>

      <section class="dashboard-content">
        <div class="ml-head">
          <h2>Machine Learning Model</h2>
          <p>Logistic Regression that learns which matching criteria lead to successful adoptions, using the shelter's own history.</p>
        </div>

        <?php if ($success_message): ?><div class="ml-alert ok"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="ml-alert bad"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

        <div class="ml-grid">
          <!-- Status at pag-train -->
          <div class="ml-card">
            <h3><i class="fa-solid fa-brain"></i> Model Status</h3>
            <?php if ($activeModel): ?>
              <span class="ml-status on"><span class="dot"></span> ML model is ACTIVE</span>
              <p class="ml-status-text">Pet matching now uses the trained model (#<?php echo (int) $activeModel['model_id']; ?>). The match score is the model's predicted probability that an adoption will be successful.</p>
            <?php else: ?>
              <span class="ml-status off"><span class="dot"></span> Collecting data &mdash; using manual weights</span>
              <p class="ml-status-text">The model needs at least <strong><?php echo ML_MIN_SAMPLES; ?> completed applications</strong> before it is trusted for matching. Until then, the system uses the manual weights (Pet Preferences 60%, Lifestyle Fit 40%, with Allergy Safety and Pet Kind as must-haves). You can still train it now to preview what it learns.</p>
            <?php endif; ?>

            <div class="ml-progress"><span style="width: <?php echo $progress; ?>%"></span></div>
            <div class="ml-progress-label"><span>Training data</span><span><?php echo $n; ?> / <?php echo ML_MIN_SAMPLES; ?> completed applications</span></div>

            <div class="ml-counts">
              <div class="ml-count"><strong><?php echo $n; ?></strong><span>Labeled samples</span></div>
              <div class="ml-count"><strong><?php echo $nPos; ?></strong><span>Successful (1)</span></div>
              <div class="ml-count"><strong><?php echo $nNeg; ?></strong><span>Unsuccessful (0)</span></div>
            </div>

            <form method="post">
              <input type="hidden" name="action" value="train">
              <button type="submit" class="ml-train-btn"<?php echo $canTrain ? '' : ' disabled'; ?>>
                <i class="fa-solid fa-play"></i> Train Model
              </button>
            </form>
            <?php if (!$canTrain): ?>
              <p class="ml-note">
                <?php if ($n < ML_MIN_TRAIN_SAMPLES): ?>
                  Training needs at least <?php echo ML_MIN_TRAIN_SAMPLES; ?> completed (Approved or Rejected) applications.
                <?php else: ?>
                  Training needs examples of both outcomes. All <?php echo $n; ?> completed applications currently have the same outcome.
                <?php endif; ?>
              </p>
            <?php endif; ?>
            <p class="ml-note"><strong>Successful</strong> = Approved and the pet stayed with the adopter. <strong>Unsuccessful</strong> = Rejected, or the pet was returned by the adopter / reclaimed by the office during Pet Monitoring.</p>
          </div>

          <!-- Resulta ng evaluation -->
          <div class="ml-card">
            <h3><i class="fa-solid fa-bullseye"></i> Model Evaluation</h3>
            <?php if ($latest): ?>
              <div class="ml-metrics">
                <div class="ml-metric"><strong><?php echo ml_pct($latest['accuracy']); ?></strong><span>Accuracy</span></div>
                <div class="ml-metric"><strong><?php echo ml_pct($latest['precision_score']); ?></strong><span>Precision</span></div>
                <div class="ml-metric"><strong><?php echo ml_pct($latest['recall_score']); ?></strong><span>Recall</span></div>
                <div class="ml-metric"><strong><?php echo ml_pct($latest['f1_score']); ?></strong><span>F1 Score</span></div>
              </div>
              <div class="ml-confusion">
                <div>Algorithm: <b><?php echo htmlspecialchars($latest['algorithm']); ?></b></div>
                <div>Validation: <b><?php echo htmlspecialchars($latest['validation'] ?? '—'); ?></b></div>
                <div>Trained on: <b><?php echo (int) $latest['n_samples']; ?> samples</b></div>
                <div>Trained: <b><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($latest['trained_at']))); ?></b></div>
              </div>
              <p class="ml-note">Metrics are measured on applications the model did <em>not</em> see during training (cross-validation), so they show how well it predicts new adoptions. With very little data these numbers are not yet reliable.</p>
            <?php else: ?>
              <div class="ml-empty">No model has been trained yet. Press <strong>Train Model</strong> once there is enough data.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Natutunang timbang vs manual -->
        <div class="ml-card">
          <h3><i class="fa-solid fa-scale-balanced"></i> What the Model Learned</h3>
          <?php if ($learned): ?>
            <div style="overflow-x: auto;">
              <table class="ml-table no-cards">
                <thead>
                  <tr>
                    <th>Criterion</th>
                    <th>Manual weight</th>
                    <th></th>
                    <th>Learned coefficient</th>
                    <th>Learned importance</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($learned as $row): ?>
                    <tr>
                      <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                      <td><?php echo $row['manual'] > 0 ? number_format($row['manual'], 0) . '%' : 'Must-have'; ?></td>
                      <td><div class="ml-bar manual"><span style="width: <?php echo min(100, $row['manual'] * 2); ?>%"></span></div></td>
                      <td class="ml-coef<?php echo $row['coef'] < 0 ? ' neg' : ''; ?>"><?php echo ($row['coef'] >= 0 ? '+' : '') . number_format($row['coef'], 3); ?></td>
                      <td><?php echo number_format($row['importance'], 1); ?>%</td>
                      <td><div class="ml-bar learned"><span style="width: <?php echo min(100, $row['importance'] * 2); ?>%"></span></div></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="ml-note">A <strong>positive</strong> coefficient means a better fit on that criterion raises the chance of a successful adoption; a <strong>negative</strong> one means the data did not support it. <strong>Learned importance</strong> is each coefficient's share of the total, to compare with the manual weights.</p>
          <?php else: ?>
            <div class="ml-empty">Train the model to see which criteria it finds most important.</div>
          <?php endif; ?>
        </div>

        <!-- Paliwanag para sa panel -->
        <div class="ml-card">
          <h3><i class="fa-solid fa-circle-info"></i> How It Works</h3>
          <ol class="ml-steps">
            <li><span><strong>Features:</strong> for every completed application, the system computes 13 fit values (0 to 1) between the adopter's preferences and the pet &mdash; allergy safety, kind, size, age, health, breed, gender, temperament, living space, activity, energy, maintenance, and pet-care experience.</span></li>
            <li><span><strong>Label:</strong> 1 if the adoption was successful (approved and the pet stayed), 0 if not (rejected, returned, or reclaimed).</span></li>
            <li><span><strong>Training:</strong> Logistic Regression learns one weight per feature using gradient descent with L2 regularization and class balancing.</span></li>
            <li><span><strong>Evaluation:</strong> accuracy, precision, recall, and F1 are measured with cross-validation on data the model has not seen.</span></li>
            <li><span><strong>Matching:</strong> once there are at least <?php echo ML_MIN_SAMPLES; ?> completed applications, the model's predicted probability becomes the match score. Before that, the manual weights are used (cold-start fallback). A pet that is unsafe for the adopter's allergy is never scored higher by the model.</span></li>
          </ol>
          <div class="ml-formula">P(success) = 1 / (1 + e<sup>&minus;(w&#8321;x&#8321; + w&#8322;x&#8322; + &hellip; + w&#8321;&#8323;x&#8321;&#8323; + b)</sup>) &nbsp;&nbsp;&rarr;&nbsp;&nbsp; Match Score = P(success) &times; 100</div>
        </div>

        <!-- Kasaysayan -->
        <div class="ml-card">
          <h3><i class="fa-solid fa-clock-rotate-left"></i> Training History</h3>
          <?php if ($history): ?>
            <div style="overflow-x: auto;">
              <table class="ml-table">
                <thead>
                  <tr><th>Model</th><th>Trained</th><th>By</th><th>Samples</th><th>Accuracy</th><th>F1</th><th>Status</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($history as $h): ?>
                    <tr>
                      <td>#<?php echo (int) $h['model_id']; ?></td>
                      <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($h['trained_at']))); ?></td>
                      <td><?php echo htmlspecialchars($h['trainer'] ?? '—'); ?></td>
                      <td><?php echo (int) $h['n_samples']; ?> (<?php echo (int) $h['n_positive']; ?>/<?php echo (int) $h['n_negative']; ?>)</td>
                      <td><?php echo ml_pct($h['accuracy']); ?></td>
                      <td><?php echo ml_pct($h['f1_score']); ?></td>
                      <td>
                        <?php if ($activeModel && (int) $activeModel['model_id'] === (int) $h['model_id']): ?>
                          <span class="ml-status on" style="padding:3px 10px;font-size:12px;">In use</span>
                        <?php else: ?>
                          <span class="ml-status off" style="padding:3px 10px;font-size:12px;"><?php echo $h['is_active'] ? 'Active' : 'Preview only'; ?></span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="ml-empty">No training runs yet.</div>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>
  <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>
