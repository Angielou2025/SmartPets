<?php
/**
 * Iisang daan para maging "Paid" ang isang Xendit na singil - ginagamit ng
 * webhook, ng Check Status ng staff, at ng pagbalik ng adopter galing checkout.
 *
 * BAKIT KAILANGAN ITO: kapag nag-lag habang nagbabayad, pwedeng makagawa ng
 * dalawang singil ang adopter (hal. pinindot ulit, o nagpalit ng paraan) at
 * mabayaran pareho. Ang unang makumpirmang bayad ang tatanggapin; ang anumang
 * susunod na bayad para sa parehong adoption ay awtomatikong ire-refund.
 *
 * Kailangan munang na-require ang database.php at xendit_config.php.
 */

/**
 * Itala ang bagong singil na ginawa sa Xendit.
 */
function sp_xendit_record_charge(PDO $pdo, int $applicationId, string $requestId, float $amount, string $channel): void
{
    if ($requestId === '') {
        return;
    }
    $pdo->prepare(
        "INSERT IGNORE INTO xendit_charges (application_id, xendit_request_id, amount, channel, status)
         VALUES (?, ?, ?, ?, 'Pending')"
    )->execute([$applicationId, $requestId, $amount, $channel]);
}

/**
 * Hindi sabay na gagawa ng dalawang singil para sa iisang application
 * (hal. na-double click dahil nag-lag). Ibinabalik ang true kapag nakuha ang lock.
 */
function sp_xendit_lock_application(PDO $pdo, int $applicationId): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $stmt->execute(['sp_pay_app_' . $applicationId]);
    return (int) $stmt->fetchColumn() === 1;
}

function sp_xendit_unlock_application(PDO $pdo, int $applicationId): void
{
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['sp_pay_app_' . $applicationId]);
}

/**
 * Kinukuha ang payment id ng aktwal na bayad mula sa sagot ni Xendit.
 */
function sp_xendit_reference(array $data): string
{
    foreach ($data['payments'] ?? [] as $paid) {
        if (!empty($paid['payment_id'])) {
            return (string) $paid['payment_id'];
        }
    }
    return (string) ($data['payment_id'] ?? $data['reference_id'] ?? '');
}

/**
 * I-apply ang bagong status ng isang singil.
 *
 * @return string 'paid' | 'duplicate_refunded' | 'duplicate_refund_failed' | 'failed' | 'pending' | 'unchanged' | 'unknown'
 */
function sp_xendit_apply_status(PDO $pdo, string $requestId, string $newStatus, string $reference = ''): string
{
    $find = $pdo->prepare("SELECT * FROM xendit_charges WHERE xendit_request_id = ?");
    $find->execute([$requestId]);
    $charge = $find->fetch(PDO::FETCH_ASSOC);

    if (!$charge) {
        // Singil na ginawa bago pa nagkaroon ng xendit_charges - hanapin sa payments
        $p = $pdo->prepare("SELECT application_id, amount, pay_channel FROM payments WHERE xendit_request_id = ?");
        $p->execute([$requestId]);
        $pay = $p->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            return 'unknown';
        }
        sp_xendit_record_charge($pdo, (int) $pay['application_id'], $requestId, (float) $pay['amount'], (string) $pay['pay_channel']);
        $find->execute([$requestId]);
        $charge = $find->fetch(PDO::FETCH_ASSOC);
    }

    if ($newStatus === 'Pending') {
        return 'pending';
    }

    $applicationId = (int) $charge['application_id'];

    if ($newStatus === 'Failed') {
        // Kasama ang mga kinansela natin - kumpirmado nang hindi na sila mababayaran,
        // kaya hindi na sila kailangang tanungin pa kay Xendit
        $mark = $pdo->prepare("UPDATE xendit_charges SET status = 'Failed' WHERE charge_id = ? AND status IN ('Pending', 'Cancelled')");
        $mark->execute([$charge['charge_id']]);
        // Ang payments row ay magiging Failed lang kung ito pa rin ang kasalukuyang singil
        $pdo->prepare("UPDATE payments SET status = 'Failed' WHERE application_id = ? AND xendit_request_id = ? AND status = 'Pending'")
            ->execute([$applicationId, $requestId]);
        // Ang kinansela natin mismo ay hindi "nabigong bayad" sa paningin ng adopter
        return ($mark->rowCount() > 0 && $charge['status'] === 'Pending') ? 'failed' : 'unchanged';
    }

    // --- Paid ---
    $pdo->beginTransaction();
    try {
        // Iisa lang ang pwedeng magpasya para sa application na ito sa isang pagkakataon
        $lockPay = $pdo->prepare("SELECT payment_id, status, xendit_request_id FROM payments WHERE application_id = ? FOR UPDATE");
        $lockPay->execute([$applicationId]);
        $payment = $lockPay->fetch(PDO::FETCH_ASSOC);

        // Kung ibang proseso na ang nakapagtala ng bayad na ito, wala nang gagawin
        $claim = $pdo->prepare(
            "UPDATE xendit_charges SET status = 'Paid', xendit_payment_id = NULLIF(?, '')
             WHERE charge_id = ? AND status IN ('Pending', 'Failed', 'Cancelled')"
        );
        $claim->execute([$reference, $charge['charge_id']]);
        if ($claim->rowCount() === 0) {
            $pdo->commit();
            return 'unchanged';
        }

        $isDuplicate = $payment
            && $payment['status'] === 'Paid'
            && (string) $payment['xendit_request_id'] !== $requestId;

        if (!$isDuplicate && $payment) {
            $label = XENDIT_METHODS[$charge['channel'] ?? '']['label'] ?? 'GCash';
            $pdo->prepare(
                "UPDATE payments
                 SET status = 'Paid', xendit_request_id = ?, method = ?,
                     reference_number = COALESCE(NULLIF(?, ''), reference_number),
                     payment_date = CURRENT_TIMESTAMP
                 WHERE payment_id = ?"
            )->execute([$requestId, 'Xendit ' . $label, $reference, $payment['payment_id']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (!$isDuplicate) {
        if ($payment) {
            sp_notify_payment_paid($pdo, (int) $payment['payment_id']);
        }
        return 'paid';
    }

    // Bayad na ang adoption na ito - ibalik ang sobrang bayad
    return sp_xendit_refund_charge($pdo, (int) $charge['charge_id']) ? 'duplicate_refunded' : 'duplicate_refund_failed';
}

/**
 * Ire-refund ang isang dobleng singil at aabisuhan ang adopter.
 * Pwede ring tawagin ulit ng staff ("Retry refund") kapag nabigo ang una.
 */
function sp_xendit_refund_charge(PDO $pdo, int $chargeId): bool
{
    $stmt = $pdo->prepare(
        "SELECT c.*, aa.adopter_id, p.name AS pet_name
         FROM xendit_charges c
         JOIN adoption_applications aa ON aa.application_id = c.application_id
         JOIN pet_inventory p ON p.pet_id = aa.pet_id
         WHERE c.charge_id = ?"
    );
    $stmt->execute([$chargeId]);
    $charge = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$charge || !in_array($charge['status'], ['Paid', 'Refund Failed'], true)) {
        return false;
    }

    $result = xendit_create_refund(
        $charge['xendit_request_id'],
        (float) $charge['amount'],
        'refund-dup-' . $charge['charge_id'] . '-' . time()
    );

    if (!$result['ok']) {
        $pdo->prepare("UPDATE xendit_charges SET status = 'Refund Failed', refund_error = ? WHERE charge_id = ?")
            ->execute([$result['error'], $chargeId]);
        error_log("Xendit duplicate refund failed for charge #{$chargeId}: " . $result['error']);
        return false;
    }

    $refundStatus = strtoupper((string) ($result['data']['status'] ?? 'PENDING'));
    $pdo->prepare("UPDATE xendit_charges SET status = ?, refund_id = ?, refund_error = NULL WHERE charge_id = ?")
        ->execute([
            $refundStatus === 'SUCCEEDED' ? 'Refunded' : 'Refund Pending',
            (string) ($result['data']['id'] ?? ''),
            $chargeId,
        ]);

    sp_notify(
        $pdo,
        (int) $charge['adopter_id'],
        'payment',
        'Duplicate payment refunded',
        'You were charged twice for the adoption fee of ' . $charge['pet_name'] . '. We kept one payment and are returning the extra ₱'
            . number_format((float) $charge['amount'], 2) . ' to your account. Refunds may take a few days to appear.',
        'payments.php',
        'payment_refund_' . $chargeId
    );
    return true;
}

/**
 * Tinatanong si Xendit tungkol sa LAHAT ng hindi pa tapos na singil ng isang
 * application - pati ang mga lumang napalitan na - para walang bayad na makaligtaan.
 *
 * @return string[] mga resulta ng sp_xendit_apply_status
 */
function sp_xendit_sync_application(PDO $pdo, int $applicationId): array
{
    $outcomes = [];

    $pending = $pdo->prepare("SELECT xendit_request_id FROM xendit_charges WHERE application_id = ? AND status IN ('Pending', 'Cancelled')");
    $pending->execute([$applicationId]);
    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $requestId) {
        $check = xendit_get_payment_request($requestId);
        if (!$check['ok']) {
            continue;
        }
        $newStatus = xendit_map_status((string) ($check['data']['status'] ?? 'PENDING'));
        $outcomes[] = sp_xendit_apply_status($pdo, $requestId, $newStatus, sp_xendit_reference($check['data']));
    }

    // Tingnan kung natuloy na ang mga refund na hinihintay pa
    $refunds = $pdo->prepare("SELECT charge_id, refund_id FROM xendit_charges WHERE application_id = ? AND status = 'Refund Pending' AND refund_id <> ''");
    $refunds->execute([$applicationId]);
    foreach ($refunds->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $check = xendit_request('GET', '/refunds/' . urlencode($r['refund_id']));
        if (!$check['ok']) {
            continue;
        }
        $status = strtoupper((string) ($check['data']['status'] ?? ''));
        if ($status === 'SUCCEEDED') {
            $pdo->prepare("UPDATE xendit_charges SET status = 'Refunded' WHERE charge_id = ?")->execute([$r['charge_id']]);
        } elseif ($status === 'FAILED') {
            $pdo->prepare("UPDATE xendit_charges SET status = 'Refund Failed', refund_error = ? WHERE charge_id = ?")
                ->execute([(string) ($check['data']['failure_code'] ?? 'Refund failed at Xendit'), $r['charge_id']]);
        }
    }

    return $outcomes;
}

/**
 * Kinakansela ang lahat ng hindi pa nababayarang singil ng application
 * (bago gumawa ng bago), para hindi na mabayaran ang luma.
 */
function sp_xendit_cancel_open_charges(PDO $pdo, int $applicationId): void
{
    $open = $pdo->prepare("SELECT charge_id, xendit_request_id FROM xendit_charges WHERE application_id = ? AND status = 'Pending'");
    $open->execute([$applicationId]);
    foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $res = xendit_cancel_payment_request($c['xendit_request_id']);
        if ($res['ok']) {
            $pdo->prepare("UPDATE xendit_charges SET status = 'Cancelled' WHERE charge_id = ? AND status = 'Pending'")
                ->execute([$c['charge_id']]);
        }
    }
}
