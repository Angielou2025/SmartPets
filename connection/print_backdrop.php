<?php
/**
 * "Transparent" na background para sa mga print / export page.
 *
 * Sa halip na plain na kulay, makikita sa likod ang page na pinanggalingan
 * (hal. Pet Inventory, Reports, Certificates) - malabo, madilim, at hindi
 * napipindot - kaya parang pop-up sa ibabaw nito ang print page.
 * Kapag aktwal nang pini-print, nawawala ang background (puting papel pa rin).
 *
 * Gamit: ilagay pagkabukas ng <body>:
 *   <?php require_once __DIR__ . '/../connection/print_backdrop.php'; sp_print_backdrop('/Caps2/AdminFile/reports.php'); ?>
 *
 * Ang $closeUrl ay kung saan pupunta ang × at ang Esc (karaniwang pareho sa background).
 * $showClose = false kapag may sariling Close button na ang page.
 */
function sp_print_backdrop(string $bgUrl, ?string $closeUrl = null, bool $showClose = true): void
{
    // Page lang sa loob ng system ang pwedeng ilagay sa likod
    if (strpos($bgUrl, '/Caps2/') !== 0) {
        return;
    }
    $closeUrl = $closeUrl ?? $bgUrl;
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ?>
<style>
    html.sp-pb, html.sp-pb body { background: transparent !important; }
    html.sp-pb body { min-height: 100vh; }
    .sp-pb-bg {
        position: fixed;
        inset: 0;
        width: 100%;
        height: 100%;
        border: 0;
        z-index: 0;
        pointer-events: none;
    }
    .sp-pb-ov {
        position: fixed;
        inset: 0;
        z-index: 1;
        background: rgba(15, 23, 20, 0.5);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        animation: spPbFade .25s ease;
    }
    /* Ang laman ng print page: nasa ibabaw ng background */
    html.sp-pb body > *:not(.sp-pb-bg):not(.sp-pb-ov):not(script):not(style) { position: relative; z-index: 2; }
    .sp-pb-close {
        position: fixed !important;
        top: 14px;
        right: 16px;
        z-index: 20 !important;
        width: 42px;
        height: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.95);
        color: #1f2937;
        font-size: 22px;
        line-height: 1;
        text-decoration: none;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
        transition: transform .15s ease, background-color .15s ease;
    }
    .sp-pb-close:hover { transform: scale(1.06); background: #fff; }
    @keyframes spPbFade { from { opacity: 0; } to { opacity: 1; } }
    @media print {
        .sp-pb-bg, .sp-pb-ov, .sp-pb-close { display: none !important; }
        html.sp-pb, html.sp-pb body { background: #fff !important; }
    }
    @media (prefers-reduced-motion: reduce) { .sp-pb-ov { animation: none; } }
</style>
<script>document.documentElement.classList.add('sp-pb');</script>
<iframe class="sp-pb-bg" src="<?php echo $h($bgUrl); ?>" title="" aria-hidden="true" tabindex="-1" loading="eager"></iframe>
<div class="sp-pb-ov" aria-hidden="true"></div>
<?php if ($showClose): ?><a class="sp-pb-close" href="<?php echo $h($closeUrl); ?>" title="Close (Esc)" aria-label="Close">&times;</a><?php endif; ?>
<script>
    // Esc = isara (bumalik sa page na pinanggalingan)
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !document.querySelector('[role="dialog"]:not([hidden])')) {
            window.location.href = <?php echo json_encode($closeUrl); ?>;
        }
    });
</script>
<?php
}
