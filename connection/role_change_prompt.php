<?php
/**
 * Pop-up sa itaas ng screen kapag may hiling ang admin na palitan ang role ng
 * naka-login na account.
 *
 * Ang may-ari ng account ang may huling desisyon: Agree o Decline. Hangga't
 * hindi pa siya sumasagot, hindi nagbabago ang users.role - kaya ligtas kahit
 * may adoption records na siya. Isinasama ito ng chat_bubble.php, na nasa
 * bawat naka-login na page ng tatlong role, kaya kusang lumalabas ito kahit
 * saang page siya naroon pagkatapos mag-login.
 */
if (!isset($_SESSION['user_id']) || !isset($pdo) || !($pdo instanceof PDO)) {
    return;
}

$rcNotice = $_SESSION['role_change_notice'] ?? '';
unset($_SESSION['role_change_notice']);

$rcRequest = sp_pending_role_request($pdo, (int) $_SESSION['user_id']);

if (!$rcRequest && $rcNotice === '') {
    return;
}

// Sariling accent, hindi umaasa sa CSS variables ng chat_bubble.php - wala
// kasi iyon sa Staff, at kailangan pa ring tama ang kulay ng pop-up doon.
switch ($_SESSION['role'] ?? 'Adopter') {
    case 'Staff':
        $rcPrimary = '#2952c9';
        $rcLight = '#e8edfb';
        break;
    case 'Admin':
        $rcPrimary = '#ff8c1a';
        $rcLight = '#fff1e6';
        break;
    default:
        $rcPrimary = '#2d6a45';
        $rcLight = '#eaf3ee';
        break;
}

// Kasalukuyang path, para pagkatapos ng Decline ay dito pa rin siya babalik.
$rcReturn = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if (!preg_match('#^/Caps2/[A-Za-z0-9_\-/]+\.php$#', (string) $rcReturn)) {
    $rcReturn = '';
}

if ($rcRequest) {
    $rcToStaff = $rcRequest['to_role'] === 'Staff';
    $rcHeading = $rcToStaff ? 'You are being assigned as Staff' : 'Your role is going back to Adopter';
    $rcBody = $rcToStaff
        ? 'The administrator is assigning you as a Staff member of SMART PET. Staff can manage pets, applications, schedules and medical records.'
        : 'The administrator is moving your account back to Adopter. You will go back to browsing and applying for pets.';
    $rcFoot = 'Nothing changes until you decide. Your account and all of its records stay exactly as they are if you decline.';
}
?>
<?php if ($rcRequest): ?>
<div id="spRoleChangeOverlay" role="dialog" aria-modal="true" aria-labelledby="spRoleChangeTitle">
    <div class="sp-rc-card">
        <div class="sp-rc-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-3.1 0-7 1.6-7 4.2V21h14v-3.8c0-2.6-3.9-4.2-7-4.2ZM6 10V7H4v3H1v2h3v3h2v-3h3v-2Z"/></svg>
        </div>
        <h3 id="spRoleChangeTitle"><?php echo htmlspecialchars($rcHeading); ?></h3>
        <p class="sp-rc-body"><?php echo htmlspecialchars($rcBody); ?></p>
        <p class="sp-rc-meta">
            Requested by <strong><?php echo htmlspecialchars($rcRequest['requested_by_name']); ?></strong>
            on <?php echo date('F j, Y', strtotime($rcRequest['created_at'])); ?>
        </p>
        <p class="sp-rc-foot"><?php echo htmlspecialchars($rcFoot); ?></p>
        <div class="sp-rc-actions">
            <form method="POST" action="/Caps2/connection/process_role_change.php">
                <input type="hidden" name="request_id" value="<?php echo (int) $rcRequest['request_id']; ?>">
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($rcReturn); ?>">
                <input type="hidden" name="decision" value="decline">
                <button type="submit" class="sp-rc-btn sp-rc-decline">Decline</button>
            </form>
            <form method="POST" action="/Caps2/connection/process_role_change.php">
                <input type="hidden" name="request_id" value="<?php echo (int) $rcRequest['request_id']; ?>">
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($rcReturn); ?>">
                <input type="hidden" name="decision" value="agree">
                <button type="submit" class="sp-rc-btn sp-rc-agree">Agree</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($rcNotice !== ''): ?>
<div id="spRoleChangeToast"><?php echo htmlspecialchars($rcNotice); ?></div>
<?php endif; ?>

<style>
    @keyframes spRcDrop {
        from { opacity: 0; transform: translateY(-24px); }
        to { opacity: 1; transform: translateY(0); }
    }

    #spRoleChangeOverlay {
        position: fixed;
        inset: 0;
        z-index: 10050;
        background: rgba(15, 23, 42, 0.45);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 28px 16px;
        overflow-y: auto;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    #spRoleChangeOverlay .sp-rc-card {
        background: #fff;
        width: 100%;
        max-width: 430px;
        border-radius: 14px;
        padding: 24px 24px 20px;
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.28);
        text-align: center;
        animation: spRcDrop 0.28s ease-out;
    }
    #spRoleChangeOverlay .sp-rc-icon {
        width: 54px;
        height: 54px;
        margin: 0 auto 14px;
        border-radius: 50%;
        background: <?php echo $rcLight; ?>;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    #spRoleChangeOverlay .sp-rc-icon svg {
        width: 28px;
        height: 28px;
        fill: <?php echo $rcPrimary; ?>;
    }
    #spRoleChangeOverlay h3 {
        margin: 0 0 10px;
        font-size: 19px;
        font-weight: 800;
        color: #1f2937;
        line-height: 1.3;
    }
    #spRoleChangeOverlay .sp-rc-body {
        margin: 0 0 12px;
        font-size: 14px;
        line-height: 1.55;
        color: #4b5563;
    }
    #spRoleChangeOverlay .sp-rc-meta {
        margin: 0 0 12px;
        font-size: 12.5px;
        color: #6b7280;
    }
    #spRoleChangeOverlay .sp-rc-foot {
        margin: 0 0 18px;
        font-size: 12.5px;
        line-height: 1.5;
        color: #6b7280;
        background: #f6f7f9;
        border-radius: 9px;
        padding: 10px 12px;
    }
    #spRoleChangeOverlay .sp-rc-actions {
        display: flex;
        gap: 10px;
    }
    #spRoleChangeOverlay .sp-rc-actions form { flex: 1; }
    #spRoleChangeOverlay .sp-rc-btn {
        width: 100%;
        padding: 11px 14px;
        border-radius: 9px;
        border: none;
        font-size: 14.5px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: filter 0.15s ease, background 0.15s ease;
    }
    #spRoleChangeOverlay .sp-rc-decline {
        background: #f1f2f4;
        color: #4b5563;
    }
    #spRoleChangeOverlay .sp-rc-decline:hover { background: #e4e6ea; }
    #spRoleChangeOverlay .sp-rc-agree {
        background: <?php echo $rcPrimary; ?>;
        color: #fff;
    }
    #spRoleChangeOverlay .sp-rc-agree:hover { filter: brightness(1.08); }

    #spRoleChangeToast {
        position: fixed;
        top: 18px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 10050;
        background: #1f2937;
        color: #fff;
        padding: 12px 20px;
        border-radius: 10px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 14px;
        font-weight: 600;
        box-shadow: 0 10px 26px rgba(0, 0, 0, 0.25);
        animation: spRcDrop 0.28s ease-out;
    }

    @media (max-width: 480px) {
        #spRoleChangeOverlay .sp-rc-card { padding: 20px 18px 16px; }
        #spRoleChangeOverlay .sp-rc-actions { flex-direction: column-reverse; }
    }
</style>

<?php if ($rcNotice !== ''): ?>
<script>
    setTimeout(function () {
        var t = document.getElementById('spRoleChangeToast');
        if (t) { t.style.transition = 'opacity .4s ease'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }
    }, 4200);
</script>
<?php endif; ?>
