<?php
/**
 * AI Health Check - buksan ito pagkatapos i-deploy para malaman kung gagana
 * ang AI assistant sa hosting:  yourdomain.com/connection/ai_health.php
 *
 * Admin lang ang makakapagbukas nito. Sinusuri ang bawat kailangan ng AI at
 * gumagawa ng isang maliit na totoong tawag sa AI.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Admin lang ang puwedeng magbukas ng page na ito. Mag-login muna bilang Admin.');
}

require __DIR__ . '/database.php';
require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/app_url.php';

$checks = [];

function hc_add(array &$checks, string $name, bool $ok, string $detail, string $fix = ''): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
}

// 1. PHP version
hc_add($checks, 'PHP version', version_compare(PHP_VERSION, '7.4', '>='),
    'PHP ' . PHP_VERSION,
    'Sa hosting panel, piliin ang PHP 8.0 o mas bago.');

// 2. cURL
$hasCurl = function_exists('curl_init');
hc_add($checks, 'PHP cURL extension', $hasCurl,
    $hasCurl ? 'Naka-enable' : 'Hindi naka-enable',
    'Sa hosting panel (Select PHP Version / PHP Extensions), i-enable ang "curl".');

// 3. API key
hc_add($checks, 'AI API key', ai_is_configured(),
    'Provider: ' . AI_PROVIDER . (ai_is_configured() ? ' (may key)' : ' (walang key)'),
    'Ilagay ang API key sa connection/claude_config.php (o ai_config.php para sa Gemini).');

// 4. CA bundle para sa HTTPS
$caOk = is_readable(__DIR__ . '/cacert.pem');
hc_add($checks, 'SSL certificate bundle (cacert.pem)', $caOk,
    $caOk ? 'Nahanap' : 'Wala - gagamitin ang default ng server',
    'I-upload din ang connection/cacert.pem kasama ng ibang file.');

// 5. Uploads folder (para sa mga larawang ina-attach sa chat)
$uploadsDir = realpath(__DIR__ . '/../uploads');
$uploadsOk = $uploadsDir !== false && is_writable($uploadsDir);
hc_add($checks, 'uploads/ folder writable', $uploadsOk,
    $uploadsOk ? 'Puwedeng sulatan' : 'Wala o hindi puwedeng sulatan',
    'Gawin ang uploads/ folder at i-set ang permission sa 755 (o 775) sa File Manager.');

// 6. Database tables ng chat
$tablesOk = false;
try {
    $pdo->query('SELECT 1 FROM chat_sessions LIMIT 1');
    $pdo->query('SELECT 1 FROM chat_messages LIMIT 1');
    $tablesOk = true;
} catch (Exception $e) {
}
hc_add($checks, 'Chat history tables', $tablesOk,
    $tablesOk ? 'chat_sessions at chat_messages ay nandoon' : 'Wala ang mga table',
    'I-import ang database mo sa phpMyAdmin ng hosting.');

// 7. Base URL
hc_add($checks, 'Base URL ng site', true,
    'Assistant API URL: ' . sp_url('connection/assistant_api.php'),
    '');

// 8. Totoong tawag sa AI
$liveOk = false;
$liveDetail = 'Nilaktawan (may problema sa itaas)';
if ($hasCurl && ai_is_configured()) {
    $started = microtime(true);
    $res = ai_chat('Reply with exactly: OK', [['role' => 'user', 'content' => 'Test']], 20, 'low');
    $secs = round(microtime(true) - $started, 1);
    $liveOk = $res['ok'];
    $liveDetail = $res['ok']
        ? 'Sumagot ang AI sa loob ng ' . $secs . 's: "' . htmlspecialchars(mb_substr($res['text'], 0, 40)) . '"'
        : 'Error: ' . htmlspecialchars($res['error']);
}
hc_add($checks, 'Live na tawag sa AI', $liveOk, $liveDetail,
    'Kung "Could not reach" / timeout: hinaharang ng hosting ang koneksyon palabas papunta sa AI - subukan ulit maya-maya, o tanungin ang support ng hosting kung pinapayagan ang HTTPS (port 443) papunta sa api.anthropic.com. Kung "authentication" / "invalid x-api-key": mali o na-revoke ang key. Kung "credit balance": magdagdag ng credits sa console.anthropic.com.');

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Health Check</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f3f5f4; margin: 0; padding: 24px 16px; color: #1d2a24; }
    .wrap { max-width: 720px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 6px; }
    .summary { padding: 14px 16px; border-radius: 12px; font-weight: 700; margin: 14px 0 18px; }
    .summary.ok { background: #e3f6ea; color: #1b6b3a; }
    .summary.bad { background: #fdecea; color: #a8322a; }
    .row { background: #fff; border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
    .row .name { font-weight: 700; }
    .row .detail { font-size: 13px; color: #56655d; margin-top: 4px; word-break: break-word; }
    .row .fix { font-size: 13px; color: #a8322a; margin-top: 6px; }
    .badge { float: right; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
    .badge.ok { background: #e3f6ea; color: #1b6b3a; }
    .badge.bad { background: #fdecea; color: #a8322a; }
</style>
</head>
<body>
<div class="wrap">
    <h1>AI Assistant Health Check</h1>
    <div class="summary <?php echo $allOk ? 'ok' : 'bad'; ?>">
        <?php echo $allOk ? 'Handa na ang AI assistant sa server na ito.' : 'May kailangang ayusin bago gumana nang buo ang AI assistant.'; ?>
    </div>
    <?php foreach ($checks as $c): ?>
        <div class="row">
            <span class="badge <?php echo $c['ok'] ? 'ok' : 'bad'; ?>"><?php echo $c['ok'] ? 'OK' : 'Ayusin'; ?></span>
            <div class="name"><?php echo htmlspecialchars($c['name']); ?></div>
            <div class="detail"><?php echo $c['detail']; ?></div>
            <?php if (!$c['ok'] && $c['fix'] !== ''): ?>
                <div class="fix"><?php echo htmlspecialchars($c['fix']); ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
