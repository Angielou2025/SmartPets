<?php
/**
 * Claude API configuration at helper functions.
 *
 * PAANO I-SETUP:
 *   1. Pumunta sa https://console.anthropic.com at mag-sign up
 *   2. Billing -> magdagdag ng credits (walang libreng tier ang Claude API;
 *      sapat na ang $5 para sa libu-libong recommendation)
 *   3. API Keys -> Create Key -> kopyahin ang key (sk-ant-api03-...)
 *   4. I-paste ang key sa CLAUDE_API_KEY sa ibaba
 *
 * Habang blangko ang key, gumagana pa rin ang recommendations - ang AI na
 * paliwanag lang ng beterinaryo ang hindi lalabas.
 */

define('CLAUDE_API_KEY', '');

define('CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages');
define('CLAUDE_MODEL', 'claude-sonnet-5');
define('CLAUDE_API_VERSION', '2023-06-01');

/**
 * May nakalagay na bang API key?
 */
function claude_is_configured(): bool
{
    return trim(CLAUDE_API_KEY) !== '';
}

/**
 * Isang beses na tanong-sagot kay Claude.
 *
 * @param string $systemPrompt  Papel/tuntunin ng AI
 * @param string $userPrompt    Ang aktwal na tanong
 * @return array{ok: bool, text: string, error: string}
 */
function claude_message(string $systemPrompt, string $userPrompt, int $maxTokens = 1200): array
{
    return claude_chat($systemPrompt, [['role' => 'user', 'content' => $userPrompt]], $maxTokens);
}

/**
 * Tumatawag sa Claude Messages API gamit ang buong usapan, kaya naaalala ni
 * Claude ang mga naunang sinabi - ito ang ginagamit ng chat assistant.
 *
 * @param string $systemPrompt Papel/tuntunin ng AI
 * @param array  $messages     [['role' => 'user'|'assistant', 'content' => string|array], ...]
 *                             (array = mga content block, hal. larawan + text)
 * @param string $effort       'low' (mabilis at mura) hanggang 'high' (mas malalim)
 * @param array|null $jsonSchema Kapag may laman, JSON na sumusunod dito ang sagot
 * @return array{ok: bool, text: string, error: string}
 */
function claude_chat(string $systemPrompt, array $messages, int $maxTokens = 1200, string $effort = 'medium', ?array $jsonSchema = null): array
{
    if (!claude_is_configured()) {
        return ['ok' => false, 'text' => '', 'error' => 'Claude API key is not configured yet.'];
    }

    if (!$messages) {
        return ['ok' => false, 'text' => '', 'error' => 'No message to send.'];
    }

    // Sa ilang hosting, naka-off ang cURL extension ng PHP - fatal error ito
    // kung hindi susuriin, kaya babalik na lang sa backup na sagot
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'text' => '', 'error' => 'The PHP cURL extension is not enabled on this server.'];
    }

    $payload = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => $maxTokens,
        // Kontrolado nito kung gaano kalalim mag-isip si Claude bago sumagot.
        'output_config' => ['effort' => $effort],
        'system' => $systemPrompt,
        'messages' => array_values($messages),
    ];

    if ($jsonSchema !== null) {
        $payload['output_config']['format'] = ['type' => 'json_schema', 'schema' => $jsonSchema];
    }

    // Kung tatanggihan ng safety classifier ang request, awtomatikong ipagpapatuloy
    // ito ng ibang modelo sa loob ng parehong tawag. Opus at Fable lang ang
    // tumatanggap nito - nagkakamali ang Sonnet at Haiku kapag ipinadala ito.
    $supportsFallbacks = strpos(CLAUDE_MODEL, 'opus') !== false
        || strpos(CLAUDE_MODEL, 'fable') !== false;

    if ($supportsFallbacks) {
        $payload['fallbacks'] = 'default';
    }

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: ' . CLAUDE_API_VERSION,
    ];

    if ($supportsFallbacks) {
        $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }

    $ch = curl_init(CLAUDE_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
        // Kapag hinaharang ng hosting ang koneksyon palabas, mabilis na susuko
        // imbes na maghintay nang isang minuto ang user
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    // Kapareho ng Xendit: luma na ang CA bundle ng XAMPP at may HTTPS scanning
    // ang Avast, kaya sarili nating updated na bundle ang ginagamit.
    $caBundle = __DIR__ . '/cacert.pem';
    if (is_readable($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'text' => '', 'error' => 'Could not reach Claude: ' . $curlError];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'text' => '', 'error' => 'Unexpected response from Claude.'];
    }

    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? ('Claude returned HTTP ' . $status);
        return ['ok' => false, 'text' => '', 'error' => $message];
    }

    // Tinanggihan ng safety classifier ang buong chain
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return ['ok' => false, 'text' => '', 'error' => 'Claude declined to answer this request.'];
    }

    // Pagsamahin ang lahat ng text block (maaaring hati-hati ang sagot)
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    if (trim($text) === '') {
        return ['ok' => false, 'text' => '', 'error' => 'Claude returned an empty response.'];
    }

    return ['ok' => true, 'text' => trim($text), 'error' => ''];
}
