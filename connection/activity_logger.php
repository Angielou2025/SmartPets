<?php
// Simpleng helper para itala ang mga mahahalagang aksyon sa activity_logs table.
// Kailangang naka-require na ang database.php (para available ang $pdo) bago gamitin ito.
function log_activity(PDO $pdo, string $action, string $details = '', string $status = 'Completed'): void
{
    $actor_id = $_SESSION['user_id'] ?? null;
    $actor_name = $_SESSION['username'] ?? 'System';
    $actor_role = $_SESSION['role'] ?? 'System';

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs (actor_id, actor_name, actor_role, action, details, status) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$actor_id, $actor_name, $actor_role, $action, $details, $status]);
    } catch (Exception $e) {
        // Hindi dapat ipa-fail ang buong request kapag nagka-error lang sa pag-log
    }
}
