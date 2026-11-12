<?php
/**
 * Xendit configuration at helper functions.
 *
 * PAANO I-SETUP:
 *   1. Mag-sign up sa https://dashboard.xendit.co/register
 *   2. Sa Dashboard: Settings -> Developers -> API Keys -> Generate secret key
 *      (siguraduhing nasa TEST MODE ka muna - walang totoong pera ang gagalaw)
 *   3. I-paste ang secret key sa XENDIT_SECRET_KEY sa ibaba
 *   4. Sa Dashboard: Settings -> Developers -> Webhooks -> kopyahin ang
 *      verification token, i-paste sa XENDIT_WEBHOOK_TOKEN
 *
 * Habang blangko ang secret key, naka-off lang ang online payment at
 * gagana pa rin ang manual na pag-record ng bayad.
 */

// I-paste dito ang secret key mula sa Xendit Dashboard (nagsisimula sa "xnd_development_" o "xnd_production_")
define('XENDIT_SECRET_KEY', 'xnd_development_ZTX4HL8ghKBKUdZHCyY6SncDV3i6X3S2J4nDkkJULRdh0wV934aoo7nzYbOV');

// Verification token para sa webhook (Dashboard -> Webhooks)
define('XENDIT_WEBHOOK_TOKEN', '');

// Saan babalik ang nagbayad pagkatapos ng checkout.
// Kusang kinukuwenta mula sa address ng website na binuksan (localhost man o
// InfinityFree), kaya hindi na ito kailangang palitan tuwing mag-a-upload.
// Kung gusto itong itakda nang manual, palitan lang ng hal. 'https://yourdomain.com/Caps2'.
require_once __DIR__ . '/app_url.php';
function xendit_detect_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return 'http://localhost/Caps2';
    }
    // Sa hosting na nasa likod ng proxy (hal. InfinityFree), nasa X-Forwarded-Proto ang https
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    return ($https ? 'https' : 'http') . '://' . $host . sp_base_url();
}
define('XENDIT_BASE_URL', xendit_detect_base_url());
define('XENDIT_SUCCESS_URL', XENDIT_BASE_URL . '/StaffFile/payments.php?xendit=success');
define('XENDIT_FAILURE_URL', XENDIT_BASE_URL . '/StaffFile/payments.php?xendit=failed');

define('XENDIT_API_BASE', 'https://api.xendit.co');

// Required ng Xendit v3 Payments API - ito lang ang tinatanggap na value sa ngayon
define('XENDIT_API_VERSION', '2024-11-11');

/**
 * Mga paraan ng pagbabayad na pagpipilian ng adopter.
 *  - redirect: e-wallet - ipinapadala ang adopter sa checkout page ng e-wallet
 *  - qr      : QR Ph - QR code na maii-scan ng halos lahat ng bank app at e-wallet
 * I-enable muna ang bawat channel sa Xendit Dashboard (Settings -> Payment Methods).
 */
const XENDIT_METHODS = [
    'GCASH'     => ['label' => 'GCash',     'type' => 'redirect', 'hint' => 'Pay with your GCash wallet'],
    'PAYMAYA'   => ['label' => 'Maya',      'type' => 'redirect', 'hint' => 'Pay with your Maya wallet'],
    'GRABPAY'   => ['label' => 'GrabPay',   'type' => 'redirect', 'hint' => 'Pay with your GrabPay wallet'],
    'SHOPEEPAY' => ['label' => 'ShopeePay', 'type' => 'redirect', 'hint' => 'Pay with your ShopeePay wallet'],
    'QRPH'      => ['label' => 'QR Ph (Any Bank)', 'type' => 'qr', 'hint' => 'Scan with BDO, BPI, Landbank, UnionBank, Metrobank, and other bank apps'],
];

/**
 * May nakalagay na bang API key? Ginagamit ng UI para malaman kung
 * pwede nang gamitin ang online payment.
 */
function xendit_is_configured(): bool
{
    return trim(XENDIT_SECRET_KEY) !== '';
}

/**
 * Pinagsasaluhang HTTP caller para sa Xendit API.
 * Basic Auth ang gamit ng Xendit: secret key ang username, blangko ang password.
 *
 * @return array{ok: bool, status: int, data: array, error: string}
 */
function xendit_request(string $method, string $path, array $payload = null): array
{
    if (!xendit_is_configured()) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Xendit API key is not configured yet.'];
    }

    $ch = curl_init(XENDIT_API_BASE . $path);
    // Hinihingi ng Xendit v3 Payments API ang api-version header
    $headers = [
        'Content-Type: application/json',
        'api-version: ' . XENDIT_API_VERSION,
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => XENDIT_SECRET_KEY . ':',
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => 30,
    ]);

    // Luma na ang CA bundle ng XAMPP (2022), kaya may kasama tayong sariling updated
    // na kopya. Nananatiling naka-ON ang SSL verification - hindi ito dapat i-off
    // sa isang sistemang humahawak ng bayad.
    $caBundle = __DIR__ . '/cacert.pem';
    if (is_readable($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Could not reach Xendit: ' . $curlError];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        $data = [];
    }

    if ($status < 200 || $status >= 300) {
        $message = $data['message'] ?? $data['error_code'] ?? 'Xendit returned HTTP ' . $status;
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $message];
    }

    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
}

/**
 * Gumagawa ng bagong singil sa Xendit para sa isang adoption.
 * Ang $referenceId ay dapat unique sa bawat singil (ginagamit natin ang application id).
 */
function xendit_create_payment_request(
    string $referenceId,
    float $amount,
    string $description,
    string $successUrl = XENDIT_SUCCESS_URL,
    string $failureUrl = XENDIT_FAILURE_URL,
    string $channelCode = 'GCASH'
): array {
    $payload = [
        'reference_id'   => $referenceId,
        'type'           => 'PAY',
        'country'        => 'PH',
        'currency'       => 'PHP',
        // Number ang hinihingi ng Xendit dito, hindi string
        'request_amount' => round($amount, 2),
        'capture_method' => 'AUTOMATIC',
        'channel_code'   => $channelCode,
        'description'    => $description,
    ];

    // Ang e-wallet ay may checkout page na babalikan pagkatapos magbayad;
    // ang QR Ph ay QR code lang, walang babalikang page
    if ((XENDIT_METHODS[$channelCode]['type'] ?? 'redirect') === 'redirect') {
        $payload['channel_properties'] = [
            'success_return_url' => $successUrl,
            'failure_return_url' => $failureUrl,
        ];
    }

    return xendit_request('POST', '/v3/payment_requests', $payload);
}

/**
 * Ang QR string na ibinabalik ng QR Ph (action na PRESENT_TO_CUSTOMER / QR_STRING).
 */
function xendit_extract_qr_string(array $paymentRequest): string
{
    foreach ($paymentRequest['actions'] ?? [] as $action) {
        if (strtoupper((string) ($action['descriptor'] ?? '')) === 'QR_STRING' && !empty($action['value'])) {
            return (string) $action['value'];
        }
    }
    return '';
}

/**
 * Tinitingnan ang kasalukuyang status ng isang payment request.
 * Ito ang ginagamit ng "Check Status" button - gumagana kahit localhost pa,
 * dahil ang system mo ang tumatawag kay Xendit (hindi si Xendit ang tumatawag sa'yo).
 */
function xendit_get_payment_request(string $paymentRequestId): array
{
    return xendit_request('GET', '/v3/payment_requests/' . urlencode($paymentRequestId));
}

/**
 * Kinakansela ang singil na hindi pa nababayaran (hal. nagpalit ng paraan ng
 * pagbabayad ang adopter), para hindi na ito mabayaran pa at madoble.
 * Kapag nabayaran na pala, tatanggihan ito ni Xendit - ayos lang iyon.
 */
function xendit_cancel_payment_request(string $paymentRequestId): array
{
    return xendit_request('POST', '/v3/payment_requests/' . urlencode($paymentRequestId) . '/cancel');
}

/**
 * Ibinabalik ang pera sa nagbayad. Ginagamit kapag dalawang beses nabayaran
 * ang iisang adoption - ang sobrang bayad ang nire-refund.
 */
function xendit_create_refund(string $paymentRequestId, float $amount, string $referenceId): array
{
    return xendit_request('POST', '/refunds', [
        'payment_request_id' => $paymentRequestId,
        'reference_id'       => $referenceId,
        'currency'           => 'PHP',
        'amount'             => round($amount, 2),
        'reason'             => 'DUPLICATE',
    ]);
}

/**
 * Hinahanap ang redirect/checkout URL sa "actions" na ibinabalik ng Xendit.
 * Dito ipapadala ang adopter para magbayad gamit ang GCash.
 */
function xendit_extract_checkout_url(array $paymentRequest): string
{
    foreach ($paymentRequest['actions'] ?? [] as $action) {
        foreach (['url', 'value'] as $key) {
            if (!empty($action[$key]) && str_starts_with((string) $action[$key], 'http')) {
                return (string) $action[$key];
            }
        }
    }
    return '';
}

/**
 * Isinasalin ang Xendit status papunta sa status na ginagamit ng payments table.
 */
function xendit_map_status(string $xenditStatus): string
{
    $status = strtoupper($xenditStatus);

    if (in_array($status, ['SUCCEEDED', 'CAPTURED', 'PAID', 'COMPLETED'], true)) {
        return 'Paid';
    }
    if (in_array($status, ['FAILED', 'EXPIRED', 'VOIDED', 'CANCELED', 'CANCELLED'], true)) {
        return 'Failed';
    }
    return 'Pending';
}
