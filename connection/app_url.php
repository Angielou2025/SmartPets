<?php
/**
 * Ang URL path ng project folder, para gumana ang mga link kahit saan ito
 * naka-deploy:
 *   - localhost/Caps2/...   -> "/Caps2"
 *   - yourdomain.com/...    -> ""        (kung nasa mismong public_html)
 *   - yourdomain.com/app/... -> "/app"
 *
 * Kusang kinukuwenta mula sa DOCUMENT_ROOT ng server. Kung may kakaibang
 * setup ang hosting at mali ang lumalabas, i-define lang ang SP_BASE_URL bago
 * i-require ang file na ito, hal. define('SP_BASE_URL', '/app');
 */
function sp_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    if (defined('SP_BASE_URL')) {
        return $base = rtrim((string) SP_BASE_URL, '/');
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $base = '';

    // Unang paraan: ihambing ang file path ng tumatakbong script sa URL nito.
    // Hal. file = .../htdocs/Caps2/Adopter_File/x.php at URL = /Caps2/Adopter_File/x.php
    // -> "/Caps2". Hindi ito umaasa sa DOCUMENT_ROOT, na kakaiba minsan sa
    // shared hosting gaya ng InfinityFree.
    $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $scriptUrl = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($projectRoot !== false && $scriptFile !== false && $scriptUrl !== '') {
        $root = str_replace('\\', '/', $projectRoot);
        $file = str_replace('\\', '/', $scriptFile);
        if (stripos($file, $root . '/') === 0) {
            $rel = substr($file, strlen($root));            // hal. "/Adopter_File/x.php"
            $relLen = strlen($rel);
            if ($relLen <= strlen($scriptUrl) && strcasecmp(substr($scriptUrl, -$relLen), $rel) === 0) {
                return $base = rtrim(substr($scriptUrl, 0, -$relLen), '/');
            }
        }
    }

    // Ikalawang paraan: batay sa DOCUMENT_ROOT
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($projectRoot !== false && $docRoot !== false) {
        $projectRoot = str_replace('\\', '/', $projectRoot);
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
        if (stripos($projectRoot, $docRoot) === 0) {
            $base = substr($projectRoot, strlen($docRoot));
        }
    }

    return $base = rtrim($base, '/');
}

/**
 * Buong path ng isang file sa loob ng project, hal. sp_url('uploads/x.png')
 * -> "/Caps2/uploads/x.png" sa localhost, "/uploads/x.png" sa domain.
 */
function sp_url(string $path): string
{
    return sp_base_url() . '/' . ltrim($path, '/');
}
