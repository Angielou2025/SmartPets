<?php
/**
 * Pagsuri ng Residency ID (Barangay ID) na in-upload ng adopter.
 *
 * Ipinapabasa sa AI ang larawan para malaman kung:
 *  - ID card nga ito at Barangay / Residency ID (hindi ibang ID),
 *  - tama ang side (harap o likod) na in-upload sa slot,
 *  - malinaw at nababasa (hindi malabo, walang matinding silaw),
 *  - taga-City of Caloocan (sa harap lang tinitingnan).
 *
 * Ang mga pumasang larawan ay tinatandaan sa session (sha1 ng file), kaya hindi
 * na sinusuri ulit pagka-submit. Kapag naka-off o hindi maabot ang AI, hindi
 * hinaharangan ang adopter - ang staff pa rin ang titingin sa review.
 */

require_once __DIR__ . '/ai_config.php';

/**
 * @param string $side 'front' o 'back'
 * @return array{ok: bool, checked: bool, message: string}
 */
function sp_check_residency_id(string $bytes, string $mime, string $side): array
{
    $side = $side === 'back' ? 'back' : 'front';
    $sideLabel = $side === 'back' ? 'back' : 'front';

    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
        return ['ok' => false, 'checked' => true, 'message' => 'Please use a JPG or PNG photo of your Barangay ID.'];
    }

    $hash = sha1($bytes);
    if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['sp_id_checks'][$hash] ?? '') === $side) {
        return ['ok' => true, 'checked' => true, 'message' => 'Your Barangay ID (' . $sideLabel . ') looks clear.'];
    }

    if (!ai_is_configured()) {
        return ['ok' => true, 'checked' => false, 'message' => ''];
    }

    $schema = [
        'type' => 'object',
        'properties' => [
            'is_id_card' => ['type' => 'boolean'],
            'is_barangay_id' => ['type' => 'boolean'],
            'side' => ['type' => 'string', 'enum' => ['front', 'back', 'unknown']],
            'is_readable' => ['type' => 'boolean'],
            'is_caloocan' => ['type' => 'string', 'enum' => ['yes', 'no', 'unclear']],
        ],
        'required' => ['is_id_card', 'is_barangay_id', 'side', 'is_readable', 'is_caloocan'],
        'additionalProperties' => false,
    ];

    $system = 'You check photos of Philippine Barangay IDs (Barangay Identification Card / Residency ID) uploaded '
        . 'to a city veterinary office in the City of Caloocan. Judge only what is visible in the photo. Do not transcribe personal details.';

    $prompt = "Look at this photo and return:\n"
        . "- is_id_card: true only if the photo shows a physical ID card (not a screenshot of a form, a selfie, a pet, a document page, etc.).\n"
        . "- is_barangay_id: true if it is a Barangay ID / Barangay Identification Card / barangay residency ID issued by a barangay. "
        . "False for other IDs such as PhilSys National ID, Driver's License, UMID, Passport, Postal, Voter's, PRC, school or company IDs.\n"
        . "- side: \"front\" if it shows the holder's photo, name, and the barangay header; \"back\" if it shows details like date of birth, "
        . "civil status, emergency contact, thumbmark, or validity without the main photo; \"unknown\" if you cannot tell.\n"
        . "- is_readable: false if the photo is blurry, out of focus, too dark, has strong glare over the text, or the card is cut off "
        . "so the printed text cannot be read. True if the text is clear enough to read.\n"
        . "- is_caloocan: \"yes\" if the card shows it was issued in Caloocan City, \"no\" if it clearly shows another city or municipality, "
        . "\"unclear\" if the city is not shown.";

    // Ilabas muna ang session lock habang naghihintay sa AI (para hindi bumagal ang ibang page)
    $hadSession = session_status() === PHP_SESSION_ACTIVE;
    if ($hadSession) {
        session_write_close();
    }
    $result = ai_read_image_json($system, $prompt, $bytes, $mime, $schema, 1000);
    if ($hadSession) {
        @session_start();
    }

    if (!$result['ok']) {
        // Hindi naabot ang AI - huwag harangan; ang staff ang titingin
        return ['ok' => true, 'checked' => false, 'message' => ''];
    }

    $d = $result['data'];
    $other = $side === 'back' ? 'front' : 'back';

    if (empty($d['is_id_card'])) {
        return ['ok' => false, 'checked' => true, 'message' => 'This photo does not show an ID card. Please upload a photo of your Barangay ID.'];
    }
    if (empty($d['is_barangay_id'])) {
        return ['ok' => false, 'checked' => true, 'message' => 'This looks like a different ID. Please upload your Barangay (Residency) ID.'];
    }
    if (empty($d['is_readable'])) {
        return ['ok' => false, 'checked' => true, 'message' => 'Your ID is blurred or hard to read. Please retake a clear photo with all four corners showing.'];
    }
    if (($d['side'] ?? '') === $other) {
        return ['ok' => false, 'checked' => true, 'message' => 'This is the ' . $other . ' of your ID. Please upload the ' . $side . ' side here.'];
    }
    if ($side === 'front' && ($d['is_caloocan'] ?? '') === 'no') {
        return ['ok' => false, 'checked' => true, 'message' => 'This Barangay ID is not from the City of Caloocan. Only Caloocan residents can apply.'];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['sp_id_checks'][$hash] = $side;
        // Huwag palakihin nang husto ang session
        if (count($_SESSION['sp_id_checks']) > 20) {
            $_SESSION['sp_id_checks'] = array_slice($_SESSION['sp_id_checks'], -20, null, true);
        }
    }

    return ['ok' => true, 'checked' => true, 'message' => 'Your Barangay ID (' . $sideLabel . ') looks clear.'];
}

/** MIME ng larawan batay sa laman mismo ng file (hindi sa pangalan). */
function sp_image_mime(string $path): string
{
    $info = @getimagesize($path);
    return is_array($info) ? (string) ($info['mime'] ?? '') : '';
}
