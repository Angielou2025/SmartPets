<?php
/**
 * Step indicator ng Forgot Password: 1 Email -> 2 Verify Code -> 3 New Password.
 * Itakda ang $currentStep (1, 2 o 3) bago i-include.
 */
$resetSteps = [1 => 'Email', 2 => 'Verify Code', 3 => 'New Password'];
?>
<ol class="reset-steps" aria-label="Password reset progress">
    <?php foreach ($resetSteps as $n => $label):
        $state = $n < $currentStep ? 'done' : ($n === $currentStep ? 'active' : '');
    ?>
        <li class="<?php echo $state; ?>" <?php echo $n === $currentStep ? 'aria-current="step"' : ''; ?>>
            <span class="step-dot">
                <?php if ($n < $currentStep): ?>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7"/></svg>
                <?php else: ?>
                    <?php echo $n; ?>
                <?php endif; ?>
            </span>
            <span class="step-label"><?php echo $label; ?></span>
        </li>
    <?php endforeach; ?>
</ol>
