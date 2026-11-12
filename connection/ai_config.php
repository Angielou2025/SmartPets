<?php
/**
 * AI provider configuration at helper functions.
 *
 * Dito dumadaan ang lahat ng tawag sa AI ng buong system (chat assistant at
 * ang paliwanag ng beterinaryo sa recommendations). Isang provider lang ang
 * aktibo sa isang pagkakataon, at ang AI_PROVIDER sa ibaba ang pumipili.
 *
 * ------------------------------------------------------------------
 * GEMINI (libre - ito ang kasalukuyang ginagamit)
 * ------------------------------------------------------------------
 *   1. Pumunta sa https://aistudio.google.com/apikey
 *   2. Mag-sign in gamit ang Google account - walang credit card na kailangan
 *   3. "Create API key" -> kopyahin ang key (AIza...)
 *   4. I-paste sa GEMINI_API_KEY sa ibaba
 *
 * ------------------------------------------------------------------
 * CLAUDE (bayad - kailangan ng credits sa console.anthropic.com)
 * ------------------------------------------------------------------
 *   Palitan lang ang AI_PROVIDER ng 'claude', tapos siguraduhing may nakalagay
 *   na key sa claude_config.php. Walang ibang babaguhin sa buong system.
 *
 * Habang blangko ang key ng napiling provider, tumatakbo pa rin ang system -
 * babalik lang ang chat assistant sa simpleng keyword na sagot, at hindi
 * lalabas ang AI na paliwanag ng beterinaryo.
 */

require_once __DIR__ . '/claude_config.php';

// Alin ang gagamitin: 'gemini' (libre) o 'claude' (bayad)
define('AI_PROVIDER', 'claude');

// I-paste dito ang API key mula sa https://aistudio.google.com/apikey
define('GEMINI_API_KEY', '');

define('GEMINI_MODEL', '');
define('GEMINI_API_URL', '');

/**
 * May nakalagay na bang API key para sa aktibong provider?
 */
function ai_is_configured(): bool
{
    if (AI_PROVIDER === 'claude') {
        return claude_is_configured();
    }

    return trim(GEMINI_API_KEY) !== '';
}

/**
 * Isang beses na tanong-sagot sa AI.
 *
 * @param string $systemPrompt  Papel/tuntunin ng AI
 * @param string $userPrompt    Ang aktwal na tanong
 * @return array{ok: bool, text: string, error: string}
 */
function ai_message(string $systemPrompt, string $userPrompt, int $maxTokens = 1200): array
{
    return ai_chat($systemPrompt, [['role' => 'user', 'content' => $userPrompt]], $maxTokens);
}

/**
 * Tumatawag sa AI gamit ang buong usapan, kaya naaalala nito ang mga naunang
 * sinabi - ito ang ginagamit ng chat assistant.
 *
 * @param string $systemPrompt Papel/tuntunin ng AI
 * @param array  $messages     [['role' => 'user'|'assistant', 'content' => string], ...]
 * @param string $effort       'low' (mabilis at mura) hanggang 'high' (mas malalim)
 * @return array{ok: bool, text: string, error: string}
 */
function ai_chat(string $systemPrompt, array $messages, int $maxTokens = 1200, string $effort = 'medium'): array
{
    if (AI_PROVIDER === 'claude') {
        return claude_chat($systemPrompt, $messages, $maxTokens, $effort);
    }

    return gemini_chat($systemPrompt, $messages, $maxTokens, $effort);
}

/**
 * Ipinapabasa sa AI ang isang larawan (hal. Valid ID) at JSON ang ibinabalik
 * na sagot, ayon sa $jsonSchema.
 *
 * @param string $mimeType image/jpeg, image/png, o image/gif
 * @return array{ok: bool, data: array, error: string}
 */
function ai_read_image_json(string $systemPrompt, string $prompt, string $imageBytes, string $mimeType, array $jsonSchema, int $maxTokens = 4000): array
{
    $b64 = base64_encode($imageBytes);

    if (AI_PROVIDER === 'claude') {
        $content = [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $b64]],
            ['type' => 'text', 'text' => $prompt],
        ];
        $result = claude_chat($systemPrompt, [['role' => 'user', 'content' => $content]], $maxTokens, 'low', $jsonSchema);
    } else {
        // Walang schema ang Gemini dito - JSON mode lang, at ang hugis ay nasa prompt
        $content = [
            ['inline_data' => ['mime_type' => $mimeType, 'data' => $b64]],
            ['text' => $prompt . "\n\nReply with only a JSON object with exactly these keys: "
                . implode(', ', array_keys($jsonSchema['properties'] ?? [])) . '.'],
        ];
        $result = gemini_chat($systemPrompt, [['role' => 'user', 'content' => $content]], $maxTokens, 'low', true);
    }

    if (!$result['ok']) {
        return ['ok' => false, 'data' => [], 'error' => $result['error']];
    }

    // Tanggalin ang ```json ... ``` kung sakaling binalot ng AI ang sagot
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($result['text'])));
    $data = json_decode($text, true);
    if (!is_array($data)) {
        return ['ok' => false, 'data' => [], 'error' => 'The AI did not return readable data.'];
    }

    return ['ok' => true, 'data' => $data, 'error' => ''];
}

/**
 * Tumatawag sa Gemini generateContent endpoint.
 *
 * @return array{ok: bool, text: string, error: string}
 */
function gemini_chat(string $systemPrompt, array $messages, int $maxTokens = 1200, string $effort = 'medium', bool $jsonOnly = false): array
{
    if (!ai_is_configured()) {
        return ['ok' => false, 'text' => '', 'error' => 'The AI assistant is not set up yet.'];
    }

    if (!$messages) {
        return ['ok' => false, 'text' => '', 'error' => 'No message to send.'];
    }

    // Iba ang tawag ng Gemini sa sagot ng AI - 'model', hindi 'assistant'.
    // Ang content na array ay mga "part" na ng Gemini (hal. larawan + text).
    $contents = [];
    foreach ($messages as $m) {
        $content = $m['content'] ?? '';
        $contents[] = [
            'role' => (($m['role'] ?? 'user') === 'assistant') ? 'model' : 'user',
            'parts' => is_array($content) ? array_values($content) : [['text' => (string) $content]],
        ];
    }

    // Walang katumbas ang 'effort' sa Gemini - tinatanggihan ng gemini-3.6-flash
    // ang thinkingConfig, at siya na mismo ang bahalang magpasya kung gaano
    // kalalim mag-isip. Pinapanatili ang parameter para pareho ang hugis ng
    // tawag sa dalawang provider.
    $generationConfig = ['maxOutputTokens' => $maxTokens];
    if ($jsonOnly) {
        $generationConfig['responseMimeType'] = 'application/json';
    }

    $payload = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => $generationConfig,
    ];

    $url = GEMINI_API_URL . rawurlencode(GEMINI_MODEL) . ':generateContent';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
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
        return ['ok' => false, 'text' => '', 'error' => 'Could not reach the AI service: ' . $curlError];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'text' => '', 'error' => 'Unexpected response from the AI service.'];
    }

    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? ('The AI service returned HTTP ' . $status);

        // Ang libreng tier ay may hangganan kada minuto. Ang buong mensahe ng
        // Google ay mahaba at may link papunta sa billing page - hindi iyon para
        // sa adopter, kaya pinapalitan natin ng maikli at malinaw na paliwanag.
        if ($status === 429 || stripos($message, 'quota') !== false || stripos($message, 'rate limit') !== false) {
            return [
                'ok' => false,
                'text' => '',
                'error' => 'The assistant is handling a lot of questions right now. Please wait a moment and ask again.',
            ];
        }

        return ['ok' => false, 'text' => '', 'error' => $message];
    }

    $candidate = $data['candidates'][0] ?? null;

    if ($candidate === null) {
        // Karaniwang ibig sabihin nito ay hinarang ng safety filter ang tanong
        $blocked = $data['promptFeedback']['blockReason'] ?? '';
        return [
            'ok' => false,
            'text' => '',
            'error' => $blocked !== ''
                ? 'The AI declined to answer this request.'
                : 'The AI returned an empty response.',
        ];
    }

    // Pagsamahin ang lahat ng text part (maaaring hati-hati ang sagot)
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (isset($part['text'])) {
            $text .= $part['text'];
        }
    }

    if (trim($text) === '') {
        $finish = $candidate['finishReason'] ?? '';
        return [
            'ok' => false,
            'text' => '',
            'error' => $finish === 'MAX_TOKENS'
                ? 'The AI ran out of room before it could answer.'
                : 'The AI returned an empty response.',
        ];
    }

    return ['ok' => true, 'text' => trim($text), 'error' => ''];
}
