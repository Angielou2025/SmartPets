<?php
session_start();
require_once __DIR__ . '/database.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$me = (int)$_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'fetch_conversations') {
        // List distinct conversation partners (either who sent to me or whom I sent to), show last message
        $stmt = $pdo->prepare(
            "SELECT u.user_id, u.username, u.profile_image, m.message, MAX(m.created_at) as last_time
             FROM messages m
             JOIN users u ON (u.user_id = IF(m.sender_id = :me, m.receiver_id, m.sender_id))
             WHERE m.sender_id = :me OR m.receiver_id = :me
             GROUP BY u.user_id, u.username, u.profile_image, m.message
             ORDER BY last_time DESC"
        );
        $stmt->execute(['me' => $me]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'conversations' => $rows]);
        exit;
    }

    if ($action === 'fetch_conversation') {
        $other = (int)($_REQUEST['user_id'] ?? 0);
        if (!$other) { echo json_encode(['error' => 'missing user_id']); exit; }

        $stmt = $pdo->prepare(
            "SELECT m.*, s.username AS sender_name, r.username AS receiver_name
             FROM messages m
             LEFT JOIN users s ON s.user_id = m.sender_id
             LEFT JOIN users r ON r.user_id = m.receiver_id
             WHERE (m.sender_id = :me AND m.receiver_id = :other) OR (m.sender_id = :other AND m.receiver_id = :me)
             ORDER BY m.created_at ASC"
        );
        $stmt->execute(['me' => $me, 'other' => $other]);
        $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'messages' => $msgs]);
        exit;
    }

    if ($action === 'send_message') {
        $to = (int)($_POST['to'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if (!$to || $message === '') { echo json_encode(['error' => 'Missing parameters']); exit; }

        // Find or create conversation for two participants
        $stmt = $pdo->prepare(
            "SELECT cp1.conversation_id FROM conversation_participants cp1
             JOIN conversation_participants cp2 ON cp1.conversation_id = cp2.conversation_id
             WHERE cp1.user_id = :me AND cp2.user_id = :other LIMIT 1"
        );
        $stmt->execute(['me' => $me, 'other' => $to]);
        $conv = $stmt->fetchColumn();

        if (!$conv) {
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO conversations () VALUES ()");
            $conv = $pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (:conv, :uid)");
            $ins->execute(['conv' => $conv, 'uid' => $me]);
            $ins->execute(['conv' => $conv, 'uid' => $to]);
            $pdo->commit();
        }

        $ins = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, message, is_read, is_notified) VALUES (:conv, :sender, :receiver, :message, 0, 0)");
        $ins->execute(['conv' => $conv, 'sender' => $me, 'receiver' => $to, 'message' => $message]);

        // Return inserted message id and timestamp
        $message_id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM messages WHERE message_id = :id");
        $stmt->execute(['id' => $message_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'message' => $row]);
        exit;
    }

    echo json_encode(['error' => 'unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
