<?php
session_start();
require_once __DIR__ . '/../connection/database.php';

// Siguraduhing naka-login ang staff bago ma-access ang pahina
if (!isset($_SESSION['user_id'])) {
    header("Location: /Caps2/Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Presence heartbeat: i-update ang huling activity ng staff sa bawat request
$pdo->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?")->execute([$user_id]);

// Helper: itinuturing na "online" ang user kung may activity sa loob ng huling 30 segundo
function isUserOnline($last_active) {
    if (empty($last_active)) return false;
    return (strtotime($last_active) >= (time() - 30));
}

// Fetch user profile data
$stmt = $pdo->prepare("SELECT username, profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$username = $user['username'] ?? 'Staff';
$profile_image = $user['profile_image'] ?? '';

// Handle message sending via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    header('Content-Type: application/json');
    
    $receiver_id = $_POST['receiver_id'] ?? 0;
    $message = trim($_POST['message'] ?? '');
    $message_type = 'text';
    $attachment_url = NULL;

    try {
        // Handle File Attachment Process
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $file_name = $file['name'];
            $file_tmp = $file['tmp_name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            $allowed_img = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowed_doc = ['pdf', 'doc', 'docx', 'txt'];

            if (in_array($file_ext, $allowed_img)) {
                $message_type = 'image';
            } elseif (in_array($file_ext, $allowed_doc)) {
                $message_type = 'file';
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid file format.']);
                exit();
            }

            $new_filename = 'msg_' . uniqid() . '.' . $file_ext;
            $upload_path = __DIR__ . '/../uploads/' . $new_filename;

            if (!move_uploaded_file($file_tmp, $upload_path)) {
                echo json_encode(['success' => false, 'error' => 'Failed to upload file.']);
                exit();
            }
            $attachment_url = $new_filename;
        }

        // Find or create conversation dynamically (Staff vs Admin or Staff vs Adopter)
        $conv_stmt = $pdo->prepare("SELECT conversation_id FROM conversations WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)");
        $conv_stmt->execute([$receiver_id, $user_id, $user_id, $receiver_id]);
        $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conversation) {
            $create_conv = $pdo->prepare("INSERT INTO conversations (user_id, admin_id) VALUES (?, ?)");
            $create_conv->execute([$receiver_id, $user_id]);
            $conversation_id = $pdo->lastInsertId();
        } else {
            $conversation_id = $conversation['conversation_id'];
        }
        
        // Save Message with attachment data
        $send_stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, message, attachment_url, is_read) VALUES (?, ?, ?, ?, ?, 0)");
        $send_stmt->execute([$conversation_id, $user_id, $message_type, $message, $attachment_url]);

        $inserted_id = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'message_id' => $inserted_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle Forwarding an existing attachment to another user (admin or adopter)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['forward_message'])) {
    header('Content-Type: application/json');

    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $fwd_type = $_POST['message_type'] ?? '';
    $attachment_url = basename($_POST['attachment_url'] ?? ''); // sanitize, filename lang
    $fwd_caption = trim($_POST['message'] ?? '');

    try {
        if (!in_array($fwd_type, ['image', 'file'], true) || $attachment_url === '') {
            echo json_encode(['success' => false, 'error' => 'Invalid attachment to forward.']);
            exit();
        }

        // Siguraduhing admin o adopter lang ang pwedeng pagforwardan
        $role_stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND LOWER(role) IN ('admin', 'adopter', 'staff')");
        $role_stmt->execute([$receiver_id]);
        if (!$role_stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'You can only forward to admin, adopter, or staff accounts.']);
            exit();
        }

        // Siguraduhing kabilang ang attachment sa isang conversation ng staff na ito (para hindi maaabuso ng arbitrary filenames)
        $own_stmt = $pdo->prepare("
            SELECT m.message_id 
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.conversation_id
            WHERE m.attachment_url = ? AND (c.user_id = ? OR c.admin_id = ?)
            LIMIT 1
        ");
        $own_stmt->execute([$attachment_url, $user_id, $user_id]);
        if (!$own_stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Attachment not found.']);
            exit();
        }

        // Find or create conversation with the target user
        $conv_stmt = $pdo->prepare("SELECT conversation_id FROM conversations WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)");
        $conv_stmt->execute([$receiver_id, $user_id, $user_id, $receiver_id]);
        $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conversation) {
            $create_conv = $pdo->prepare("INSERT INTO conversations (user_id, admin_id) VALUES (?, ?)");
            $create_conv->execute([$receiver_id, $user_id]);
            $conversation_id = $pdo->lastInsertId();
        } else {
            $conversation_id = $conversation['conversation_id'];
        }

        $send_stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, message, attachment_url, is_read) VALUES (?, ?, ?, ?, ?, 0)");
        $send_stmt->execute([$conversation_id, $user_id, $fwd_type, ($fwd_caption !== '' ? $fwd_caption : null), $attachment_url]);

        echo json_encode(['success' => true, 'message_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle Deleting a Message (for everyone, or just for the current user)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_message'])) {
    header('Content-Type: application/json');

    $message_id = intval($_POST['message_id'] ?? 0);
    $delete_type = $_POST['delete_type'] ?? ''; // 'everyone' or 'me'

    try {
        if ($message_id <= 0 || !in_array($delete_type, ['everyone', 'me'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid delete request.']);
            exit();
        }

        // Kunin ang mensahe kasama ang conversation nito, at siguraduhing kabilang dito ang staff na ito
        $msg_stmt = $pdo->prepare("
            SELECT m.message_id, m.sender_id, c.user_id AS conv_user_id, c.admin_id AS conv_admin_id
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.conversation_id
            WHERE m.message_id = ? AND (c.user_id = ? OR c.admin_id = ?)
            LIMIT 1
        ");
        $msg_stmt->execute([$message_id, $user_id, $user_id]);
        $msg = $msg_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$msg) {
            echo json_encode(['success' => false, 'error' => 'Message not found.']);
            exit();
        }

        if ($delete_type === 'everyone') {
            // Sender lang ang pwedeng mag-delete-for-everyone ng sarili niyang mensahe
            if ((int)$msg['sender_id'] !== (int)$user_id) {
                echo json_encode(['success' => false, 'error' => 'You can only delete your own messages for everyone.']);
                exit();
            }
            $del_stmt = $pdo->prepare("UPDATE messages SET is_deleted = 1 WHERE message_id = ?");
            $del_stmt->execute([$message_id]);
        } else {
            // Delete for me lang: itago sa view ng kasalukuyang user, hindi nagbabago sa kabilang tao
            $get_hidden = $pdo->prepare("SELECT deleted_for FROM messages WHERE message_id = ?");
            $get_hidden->execute([$message_id]);
            $existing = $get_hidden->fetchColumn();
            $ids = array_filter(array_map('trim', explode(',', (string)$existing)));
            if (!in_array((string)$user_id, $ids, true)) {
                $ids[] = (string)$user_id;
            }
            $new_hidden = implode(',', $ids);

            $del_stmt = $pdo->prepare("UPDATE messages SET deleted_for = ? WHERE message_id = ?");
            $del_stmt->execute([$new_hidden, $message_id]);
        }

        echo json_encode(['success' => true, 'delete_type' => $delete_type, 'message_id' => $message_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Handle AJAX Polling Requests
if (isset($_GET['poll']) && $_GET['poll'] == '1') {
    header('Content-Type: application/json');
    $chat_id = isset($_GET['chat']) ? intval($_GET['chat']) : 0;
    $last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
    $new_messages = [];

    if ($chat_id > 0) {
        // Kunin ang conversation_id
        $conv_stmt = $pdo->prepare("
            SELECT conversation_id FROM conversations 
            WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)
        ");
        $conv_stmt->execute([$chat_id, $user_id, $user_id, $chat_id]);
        $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);

        if ($conversation) {
            // Mark new messages as read immediately when polling active chat
            $mark_read = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ? AND is_read = 0");
            $mark_read->execute([$conversation['conversation_id'], $user_id]);

            $stmt = $pdo->prepare("
                SELECT m.*, u.username as sender_name, u.profile_image as sender_image 
                FROM messages m 
                JOIN users u ON m.sender_id = u.user_id 
                WHERE m.conversation_id = ? AND m.message_id > ?
                AND (m.deleted_for IS NULL OR FIND_IN_SET(?, m.deleted_for) = 0)
                ORDER BY m.message_id ASC
            ");
            $stmt->execute([$conversation['conversation_id'], $last_id, $user_id]);
            $new_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Fetch updated sidebar list details dynamically for polling
    $users_stmt = $pdo->prepare("
        SELECT DISTINCT user_id, username, profile_image, role, last_active 
        FROM users 
        WHERE LOWER(role) IN ('admin', 'adopter', 'staff') AND user_id != ?
        ORDER BY role ASC, username ASC
    ");
    $users_stmt->execute([$user_id]);
    $sidebar_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sidebar_users as &$s_user) {
        $s_user['is_online'] = isUserOnline($s_user['last_active']);

        $unread_stmt = $pdo->prepare("
            SELECT COUNT(m.message_id) 
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.conversation_id
            WHERE ((c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?))
            AND m.sender_id != ? AND m.is_read = 0
        ");
        $unread_stmt->execute([$user_id, $s_user['user_id'], $s_user['user_id'], $user_id, $user_id]);
        $s_user['unread_count'] = $unread_stmt->fetchColumn();

        $last_msg_stmt = $pdo->prepare("
            SELECT m.message, m.created_at 
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.conversation_id
            WHERE (c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?)
            ORDER BY m.message_id DESC LIMIT 1
        ");
        $last_msg_stmt->execute([$user_id, $s_user['user_id'], $s_user['user_id'], $user_id]);
        $last_msg = $last_msg_stmt->fetch(PDO::FETCH_ASSOC);

        $s_user['last_message'] = sp_message_preview((string) ($last_msg['message'] ?? 'No messages yet'));
        $s_user['last_message_time'] = !empty($last_msg['created_at']) ? date('h:i A', strtotime($last_msg['created_at'])) : '';
    }
    unset($s_user);

    // Online status ng kasalukuyang kausap (para sa chat header)
    $partner_online = false;
    $partner_last_active = null;
    if ($chat_id > 0) {
        $presence_stmt = $pdo->prepare("SELECT last_active FROM users WHERE user_id = ?");
        $presence_stmt->execute([$chat_id]);
        $presence = $presence_stmt->fetch(PDO::FETCH_ASSOC);
        $partner_last_active = $presence['last_active'] ?? null;
        $partner_online = isUserOnline($partner_last_active);
    }

    echo json_encode([
        'messages' => $new_messages,
        'users' => $sidebar_users,
        'partner_online' => $partner_online,
        'partner_last_active' => $partner_last_active
    ]);
    exit();
}

// Get current chat partner
$current_chat_id = isset($_GET['chat']) ? intval($_GET['chat']) : 0;

// Mark messages as read when opening chat
if ($current_chat_id > 0) {
    $conv_stmt = $pdo->prepare("
        SELECT conversation_id FROM conversations 
        WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)
    ");
    $conv_stmt->execute([$current_chat_id, $user_id, $user_id, $current_chat_id]);
    $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($conversation) {
        $mark_read = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ? AND is_read = 0");
        $mark_read->execute([$conversation['conversation_id'], $user_id]);
    }
}

// Fetch admin and adopter users for direct chat (Case-Insensitive)
$users_stmt = $pdo->prepare("
    SELECT DISTINCT user_id, username, profile_image, role, last_active 
    FROM users 
    WHERE LOWER(role) IN ('admin', 'adopter', 'staff') AND user_id != ?
    ORDER BY role ASC, username ASC
");
$users_stmt->execute([$user_id]);
$chat_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($chat_users as &$cu) {
    $cu['is_online'] = isUserOnline($cu['last_active']);
}
unset($cu);

// Fetch chat messages for current conversation
$chat_messages = [];
$current_chat_user = null;

if ($current_chat_id > 0) {
    $conv_stmt = $pdo->prepare("
        SELECT conversation_id FROM conversations 
        WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)
    ");
    $conv_stmt->execute([$current_chat_id, $user_id, $user_id, $current_chat_id]);
    $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($conversation) {
        $chat_stmt = $pdo->prepare("
            SELECT m.*, u.username as sender_name, u.profile_image as sender_image 
            FROM messages m 
            JOIN users u ON m.sender_id = u.user_id 
            WHERE m.conversation_id = ?
            AND (m.deleted_for IS NULL OR FIND_IN_SET(?, m.deleted_for) = 0)
            ORDER BY m.message_id ASC
        ");
        $chat_stmt->execute([$conversation['conversation_id'], $user_id]);
        $chat_messages = $chat_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get current chat user info
    $user_stmt = $pdo->prepare("SELECT username, profile_image, role, last_active FROM users WHERE user_id = ?");
    $user_stmt->execute([$current_chat_id]);
    $current_chat_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    if ($current_chat_user) {
        $current_chat_user['is_online'] = isUserOnline($current_chat_user['last_active']);
    }
}

// Count unread messages and fetch last message for each user
foreach ($chat_users as &$user) {
    $unread_stmt = $pdo->prepare("
        SELECT COUNT(m.message_id) 
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.conversation_id
        WHERE ((c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?))
        AND m.sender_id != ? 
        AND m.is_read = 0
    ");
    $unread_stmt->execute([$user_id, $user['user_id'], $user['user_id'], $user_id, $user_id]);
    $user['unread_count'] = $unread_stmt->fetchColumn();

    $last_msg_stmt = $pdo->prepare("
        SELECT m.message, m.created_at 
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.conversation_id
        WHERE (c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?)
        ORDER BY m.message_id DESC LIMIT 1
    ");
    $last_msg_stmt->execute([$user_id, $user['user_id'], $user['user_id'], $user_id]);
    $last_msg = $last_msg_stmt->fetch(PDO::FETCH_ASSOC);

    $user['last_message'] = sp_message_preview((string) ($last_msg['message'] ?? 'No messages yet'));
    $user['last_message_time'] = $last_msg['created_at'] ?? '';
}
unset($user);

// Count total unread messages
$total_unread = array_sum(array_column($chat_users, 'unread_count'));

// Safely obtain last_message_id
$last_msg_id = !empty($chat_messages) ? end($chat_messages)['message_id'] : 0;
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Staff Messenger - Smart Pet</title>
    <link rel="stylesheet" href="/Caps2/AdminFile/style.css">
    <link rel="stylesheet" href="/Caps2/StaffFile/staff_dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .messenger-container {
            display: flex;
            height: calc(100vh - 120px);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(255, 255, 255, 0.9));
            border-radius: 16px;
            overflow: hidden;
            margin: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .chat-list {
            width: 350px;
            background: linear-gradient(180deg, #f8f9fa, #ffffff);
            border-right: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
        }
        
        .chat-list-header {
            padding: 20px;
            background: linear-gradient(135deg, #ff8c00, #ff6b00);
            border-bottom: 1px solid #e9ecef;
        }
        
        .chat-list-header h2 {
            color: white;
            margin: 0 0 12px 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .chat-search-box {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .chat-search-icon {
            position: absolute;
            left: 14px;
            font-size: 13px;
            color: #999;
            pointer-events: none;
        }
        
        .chat-search-input {
            width: 100%;
            padding: 9px 14px 9px 36px;
            border-radius: 20px;
            border: none;
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            font-size: 13px;
            outline: none;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
        }
        
        .chat-search-input::placeholder {
            color: #999;
        }
        
        .chat-search-input:focus {
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.6);
        }
        
        .chat-list-items {
            flex: 1;
            overflow-y: auto;
        }
        
        .chat-item {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f3f4;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chat-item:hover {
            background: #f8f9fa;
        }
        
        .chat-item.active {
            background: #fff3e0;
            border-left: 3px solid #ff8c00;
        }
        
        .chat-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2d5a3d, #1b4d2e);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .chat-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .chat-avatar i {
            color: white;
            font-size: 18px;
        }
        
        .role-badge {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            border: 2px solid white;
        }
        
        .role-badge.admin {
            background: #e74c3c;
        }
        
        .role-badge.adopter {
            background: #3498db;
        }
        
        .chat-info {
            flex: 1;
            min-width: 0;
        }
        
        .chat-name {
            color: #333;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .chat-role {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #e9ecef;
            color: #666;
        }
        
        .chat-preview {
            color: #666;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chat-meta {
            text-align: right;
        }
        
        .chat-time {
            color: #999;
            font-size: 10px;
            margin-bottom: 4px;
        }
        
        .unread-badge {
            background: #ff8c00;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #ffffff;
        }
        
        .chat-area.empty {
            align-items: center;
            justify-content: center;
        }
        
        .empty-state {
            text-align: center;
            color: #999;
        }
        
        .empty-state i {
            font-size: 60px;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 8px;
            color: #333;
        }
        
        .chat-header {
            padding: 16px 20px;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chat-header-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2d5a3d, #1b4d2e);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .chat-header-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .chat-header-avatar i {
            color: white;
        }
        
        .chat-header-info {
            flex: 1;
        }
        
        .chat-header-name {
            color: #333;
            font-weight: 600;
            font-size: 15px;
        }
        
        .chat-header-status {
            color: #666;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .chat-header-status::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #b0b0b0;
        }

        .chat-header-status.is-online::before {
            background: #2ecc71;
        }

        .chat-header-status.is-offline::before {
            background: #b0b0b0;
        }

        .presence-dot {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid #fff;
            background: #b0b0b0;
        }

        .presence-dot.online {
            background: #2ecc71;
        }

        .presence-dot.offline {
            background: #b0b0b0;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            background: #f8f9fa;
        }
        
        .message {
            display: flex;
            gap: 10px;
            max-width: 70%;
        }
        
        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message.received {
            align-self: flex-start;
        }
        
        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2d5a3d, #1b4d2e);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }
        
        .message-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .message-avatar i {
            color: white;
            font-size: 12px;
        }
        
        .message-content {
            background: white;
            padding: 12px 16px;
            border-radius: 18px;
            color: #333;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .message.sent .message-content {
            background: linear-gradient(135deg, #ff8c00, #ff6b00);
            color: white;
            border-radius: 18px 18px 4px 18px;
        }
        
        .message.received .message-content {
            background: white;
            border-radius: 18px 18px 18px 4px;
        }
        
        .message-text {
            margin: 0;
            word-break: break-word;
        }
        
        .message-attachment {
            margin-bottom: 6px;
        }
        
        .message-attachment:last-child {
            margin-bottom: 0;
        }
        
        .attachment-wrap {
            position: relative;
            display: inline-block;
        }
        
        .attachment-wrap.file-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .forward-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: none;
            background: rgba(0, 0, 0, 0.55);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s ease;
        }
        
        .forward-btn:hover {
            background: rgba(0, 0, 0, 0.75);
        }
        
        .file-wrap .forward-btn {
            position: static;
            width: 24px;
            height: 24px;
            background: #ff8c00;
            flex-shrink: 0;
        }
        
        .file-wrap .forward-btn:hover {
            background: #e67600;
        }

        .message-content {
            position: relative;
        }

        .msg-menu-btn {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: none;
            background: rgba(0, 0, 0, 0.35);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 11px;
            opacity: 0;
            transition: opacity 0.2s ease, background 0.2s ease;
        }

        .message:hover .msg-menu-btn {
            opacity: 1;
        }

        .msg-menu-btn:hover {
            background: rgba(0, 0, 0, 0.6);
        }

        .deleted-text {
            font-style: italic;
            color: #999 !important;
        }
        
        /* Forward Modal */
        .forward-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .forward-modal {
            background: #fff;
            border-radius: 14px;
            width: 320px;
            max-width: 90vw;
            max-height: 70vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .forward-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .forward-modal-header h3 {
            font-size: 16px;
            color: #333;
            margin: 0;
        }
        
        .forward-modal-close {
            background: none;
            border: none;
            color: #999;
            font-size: 16px;
            cursor: pointer;
        }
        
        .forward-modal-list {
            overflow-y: auto;
            padding: 8px;
        }
        
        .forward-user-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        
        .forward-user-item:hover {
            background: #f8f9fa;
        }
        
        .forward-user-info {
            flex: 1;
            min-width: 0;
        }
        
        .forward-user-name {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        
        .forward-user-role {
            font-size: 11px;
            color: #999;
        }
        
        .forward-modal-caption {
            display: none;
            flex-direction: column;
            gap: 12px;
            padding: 16px 18px;
        }
        
        .forward-selected-user {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .forward-back-btn {
            background: none;
            border: none;
            color: #666;
            font-size: 14px;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
        }
        
        .forward-back-btn:hover {
            background: #f1f3f4;
        }
        
        .forward-selected-name {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        
        .forward-caption-input {
            width: 100%;
            min-height: 70px;
            max-height: 140px;
            resize: vertical;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            color: #333;
            font-family: inherit;
        }
        
        .forward-caption-input:focus {
            outline: none;
            border-color: #ff8c00;
        }
        
        .forward-caption-input::placeholder {
            color: #999;
        }
        
        .forward-send-btn {
            align-self: flex-end;
            background: linear-gradient(135deg, #ff8c00, #ff6b00);
            color: white;
            border: none;
            padding: 9px 18px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: opacity 0.2s ease;
        }
        
        .forward-send-btn:hover {
            opacity: 0.9;
        }
        
        .message-time {
            font-size: 10px;
            color: #999;
            margin-top: 4px;
        }
        
        .message-input-area {
            padding: 16px 20px;
            background: white;
            border-top: 1px solid #e9ecef;
        }
        
        .message-input-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .message-input {
            flex: 1;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 24px;
            padding: 12px 18px;
            color: #333;
            font-size: 14px;
            resize: none;
            min-height: 45px;
            max-height: 120px;
            transition: border-color 0.2s;
        }
        
        .message-input:focus {
            outline: none;
            border-color: #ff8c00;
        }
        
        .message-input::placeholder {
            color: #999;
        }
        
        .send-btn {
            background: linear-gradient(135deg, #ff8c00, #ff6b00);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s;
            box-shadow: 0 2px 8px rgba(255, 140, 0, 0.3);
        }
        
        .send-btn:hover {
            transform: scale(1.05);
        }
        
        .chat-list-items::-webkit-scrollbar,
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        
        .chat-list-items::-webkit-scrollbar-track,
        .chat-messages::-webkit-scrollbar-track {
            background: #f1f3f4;
        }
        
        .chat-list-items::-webkit-scrollbar-thumb,
        .chat-messages::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }
        
        @media (max-width: 768px) {
            .messenger-container {
                margin: 10px;
                height: calc(100vh - 100px);
            }
            
            .chat-list {
                width: 100%;
                display: flex;
            }
            
            .chat-area {
                display: none;
            }
            
            .chat-area.active {
                display: flex;
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 10;
            }
            
            .chat-list.hidden {
                display: none;
            }
        }

        /* ===================== Bagong itsura (kulay ng staff: asul) ===================== */
        .messenger-container { background: #fff; border: 1px solid #e6eaf1; border-radius: 18px; box-shadow: 0 10px 30px rgba(15, 27, 61, 0.08); }

        /* Listahan ng usapan */
        .chat-list { background: #fff; border-right: 1px solid #eef0f3; }
        .chat-list-header { background: #fff; padding: 20px 18px 14px; border-bottom: 1px solid #eef0f3; }
        .chat-list-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
        .chat-list-header h2 { color: #0f1b3d; margin: 0; font-size: 21px; font-weight: 800; }
        .chat-list-title p { margin: 2px 0 0; font-size: 12.5px; color: #6b7280; }
        .chat-list-unread { flex-shrink: 0; margin-top: 4px; padding: 4px 10px; border-radius: 999px; background: #eef2fb; color: #1e3a8a; font-size: 11.5px; font-weight: 800; }
        .chat-search-input {
            height: 38px; padding: 0 14px 0 36px; border-radius: 10px; background: #f4f6f9; border: 1.5px solid transparent;
            box-shadow: none; font-size: 13.5px; transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
        }
        .chat-search-input:focus { background: #fff; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.14); }
        .chat-search-icon { color: #9aa3ad; }

        .chat-list-items { padding: 8px 10px; }
        .chat-item { position: relative; padding: 12px; margin-bottom: 4px; border: none; border-radius: 12px; transition: background-color .2s ease, box-shadow .2s ease; }
        .chat-item:hover { background: #f5f7fb; }
        .chat-item.active { background: #eef2fb; border-left: none; box-shadow: inset 0 0 0 1px #c9d6f2; }
        .chat-item.active::before { content: ''; position: absolute; left: 0; top: 14px; bottom: 14px; width: 3px; border-radius: 0 3px 3px 0; background: #2f5bc4; }
        .chat-item.has-unread .chat-name { font-weight: 800; }
        .chat-item.has-unread .chat-preview { color: #0f1b3d; font-weight: 600; }
        .chat-item.has-unread .chat-time { color: #1e3a8a; font-weight: 700; }
        .chat-avatar { background: linear-gradient(135deg, #1e40af, #3b6fe0); box-shadow: 0 2px 8px rgba(30, 64, 175, 0.22); }
        .chat-name { color: #0f1b3d; }
        .chat-role { background: #eef2fb; color: #1e3a8a; font-weight: 700; border-radius: 6px; }
        .chat-preview { color: #6b7280; font-size: 12.5px; }
        .chat-time { font-size: 11px; color: #8a94a6; }
        .unread-badge { background: #2f5bc4; display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; font-size: 11px; font-weight: 800; }
        /* Tuldok na online: sa ibabang kanan ng larawan, mas malaki */
        .presence-dot { top: auto; bottom: 0; right: 0; width: 13px; height: 13px; }
        .role-badge { top: -3px; bottom: auto; right: -3px; width: 17px; height: 17px; font-size: 8px; color: #fff; }
        .role-badge.adopter { background: #2f5bc4; }
        .role-badge.admin { background: #d97706; }
        .chat-list-empty { padding: 40px 20px; text-align: center; color: #8a94a6; font-size: 13px; }
        .chat-list-empty i { display: block; font-size: 30px; margin-bottom: 10px; color: #c7d0dd; }

        /* Usapan */
        .chat-header { background: #fff; border-bottom: 1px solid #eef0f3; padding: 14px 20px; }
        .chat-header-avatar { background: linear-gradient(135deg, #1e40af, #3b6fe0); }
        .chat-header-name { color: #0f1b3d; font-weight: 800; font-size: 15.5px; }
        .chat-messages { background: #f6f8fb; background-image: radial-gradient(rgba(30, 58, 138, 0.05) 1px, transparent 1px); background-size: 18px 18px; }
        .message-avatar { background: linear-gradient(135deg, #1e40af, #3b6fe0); }
        .message-content { box-shadow: 0 1px 2px rgba(15, 27, 61, 0.08); }
        .message.sent .message-content { background: linear-gradient(135deg, #2a62de, #1e3a8a); }
        .message.received .message-content { background: #fff; border: 1px solid #e8ecf3; }
        .message-time { color: #8a94a6; }
        .file-wrap .forward-btn { background: #2f5bc4; }
        .file-wrap .forward-btn:hover { background: #1e3a8a; }

        /* Pag-type ng mensahe */
        .message-input-area { background: #fff; border-top: 1px solid #eef0f3; padding: 14px 18px; }
        .message-input-form { align-items: center; }
        .attach-btn {
            width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0; cursor: pointer;
            display: flex; align-items: center; justify-content: center; color: #6b7280; font-size: 17px;
            transition: background-color .2s ease, color .2s ease;
        }
        .attach-btn:hover { background: #eef2fb; color: #1e3a8a; }
        .message-input { background: #f4f6f9; border: 1.5px solid transparent; border-radius: 22px; transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease; }
        .message-input:focus { background: #fff; border-color: #2a62de; box-shadow: 0 0 0 3px rgba(42, 98, 222, 0.12); }
        .send-btn { width: 44px; height: 44px; background: linear-gradient(135deg, #2a62de, #1e3a8a); box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3); flex-shrink: 0; }
        .file-preview-name { font-size: 11.5px; color: #1e3a8a; font-weight: 600; margin-top: 4px; padding-left: 50px; }

        /* Forward pop-up */
        .forward-caption-input:focus { border-color: #2a62de; }
        .forward-send-btn { background: linear-gradient(135deg, #2a62de, #1e3a8a); }

        /* Walang napiling usapan */
        .chat-area.empty { background-color: #f6f8fb; background-image: radial-gradient(rgba(30, 58, 138, 0.06) 1px, transparent 1px); background-size: 18px 18px; }
        .empty-state { max-width: 430px; padding: 24px; text-align: center; color: #6b7280; }
        .empty-state > p { font-size: 14px; line-height: 1.55; }
        .empty-state h3 { font-size: 21px; font-weight: 800; color: #0f1b3d; margin-bottom: 8px; }
        .empty-illustration { position: relative; width: 120px; height: 120px; margin: 0 auto 22px; }
        .empty-illustration i { opacity: 1; margin: 0; font-size: inherit; }
        .empty-ring { position: absolute; inset: 0; border-radius: 50%; background: radial-gradient(circle, #dbe6ff 0%, rgba(219, 230, 255, 0) 70%); animation: emptyPulse 3s ease-in-out infinite; }
        .empty-icon {
            position: absolute; inset: 22px; border-radius: 24px; color: #fff; font-size: 32px;
            display: flex; align-items: center; justify-content: center; transform: rotate(-6deg);
            background: linear-gradient(145deg, #3b6fe0, #1e3a8a); box-shadow: 0 12px 24px rgba(30, 58, 138, 0.3), inset 0 2px 0 rgba(255, 255, 255, 0.25);
        }
        .empty-paw { position: absolute; color: #2f5bc4; opacity: .3; }
        .empty-paw.p1 { top: 4px; right: -8px; font-size: 16px; transform: rotate(20deg); }
        .empty-paw.p2 { bottom: 6px; left: -12px; font-size: 12px; transform: rotate(-25deg); }
        @keyframes emptyPulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.12); opacity: .6; } }
        .empty-tips { list-style: none; margin: 22px auto 0; padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; text-align: left; background: rgba(255, 255, 255, 0.85); border: 1px solid #e8ecf3; border-radius: 14px; font-size: 13px; color: #4b5563; }
        .empty-tips li { display: flex; align-items: center; gap: 10px; }
        .empty-tips i { width: 26px; height: 26px; border-radius: 8px; background: #eef2fb; color: #1e3a8a; font-size: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; opacity: 1; margin: 0; }
        .empty-tips .fa-circle { color: #22c55e; font-size: 8px; }
        @media (prefers-reduced-motion: reduce) { .empty-ring { animation: none; } }

        /* ---- Mas malinis na usapan ---- */
        /* Sariling mensahe: walang paulit-ulit na sariling larawan, nasa kanan ang oras */
        .message.sent .message-avatar { display: none; }
        .message.sent .message-time { text-align: right; }
        .message { max-width: 66%; }
        .message-content { line-height: 1.5; }

        /* Divider ng petsa (Today / Yesterday / Oct 8, 2026) */
        .msg-day {
            display: flex; align-items: center; gap: 12px; margin: 6px 0 2px;
            color: #6b7280; font-size: 11.5px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase;
        }
        .msg-day::before, .msg-day::after { content: ''; flex: 1; height: 1px; background: #e2e7f0; }
        .msg-day span { padding: 4px 12px; border-radius: 999px; background: #fff; border: 1px solid #e2e7f0; }

        /* Mga link at button sa loob ng mensahe */
        .message-text .msg-link { color: inherit; text-decoration: underline; text-underline-offset: 2px; word-break: break-all; }
        .message.received .msg-link { color: #1e3a8a; }
        .msg-action-chip, .message-text .msg-action-btn {
            display: inline-flex; align-items: center; gap: 6px; margin: 6px 0 2px; padding: 6px 12px;
            border-radius: 999px; font-size: 12.5px; font-weight: 700; line-height: 1.3; text-decoration: none;
        }
        .message.sent .msg-action-chip,
        .message.sent .msg-action-btn { background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.35); color: #fff; }
        .message.received .msg-action-chip,
        .message.received .msg-action-btn { background: #eef2fb; border: 1px solid #c9d6f2; color: #1e3a8a; }
        .msg-action-chip { cursor: default; }
        .message-text .msg-action-btn:hover { filter: brightness(1.08); }

        .chat-list-items::-webkit-scrollbar-track, .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-list-items::-webkit-scrollbar-thumb, .chat-messages::-webkit-scrollbar-thumb { background: #cfd8e6; }
    </style>
</head>
<body class="staff-dashboard-root">
<div class="app-container">
    <?php include __DIR__ . '/_sidebar.php'; ?>

    <main class="main-wrapper">
        <div class="top-header">
            <!-- Walang hiwalay na search dito - ang "Search users" sa loob ng Messages ang gamit.
                 Naiwan ang lalagyan para manatili sa kanan ang profile picture. -->
            <div class="search-top"></div>
            <a href="/Caps2/StaffFile/edit_profile.php" class="profile-section" style="text-decoration: none; color: inherit;">
                <?php if (!empty($profile_image)): ?>
                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($profile_image); ?>" 
                         alt="Profile" 
                         style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                <?php else: ?>
                    <div class="avatar"><?php echo strtoupper(substr($username, 0, 2)); ?></div>
                <?php endif; ?>
                <div class="profile-name"><?php echo htmlspecialchars($username); ?></div>
            </a>
        </div>

        <div class="dashboard-content page-content">
            <section class="messenger-container">
                
                <!-- Chat List -->
                <div class="chat-list" id="chatList">
                    <?php $spTotalUnread = array_sum(array_map(fn ($u) => (int) $u['unread_count'], $chat_users)); ?>
                    <div class="chat-list-header">
                        <div class="chat-list-title">
                            <div>
                                <h2>Messages</h2>
                                <p>Chat with adopters and the admin</p>
                            </div>
                            <?php if ($spTotalUnread > 0): ?>
                                <span class="chat-list-unread"><?php echo $spTotalUnread > 99 ? '99+' : $spTotalUnread; ?> new</span>
                            <?php endif; ?>
                        </div>
                        <div class="chat-search-box">
                            <i class="fa-solid fa-magnifying-glass chat-search-icon"></i>
                            <input type="text" id="chatSearchInput" class="chat-search-input" placeholder="Search users..." autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="chat-list-items" id="chatListItems">
                        <?php if (count($chat_users) > 0): ?>
                            <?php foreach ($chat_users as $user): ?>
                                <div class="chat-item <?php echo $current_chat_id == $user['user_id'] ? 'active' : ''; ?><?php echo $user['unread_count'] > 0 ? ' has-unread' : ''; ?>"
                                     data-username="<?php echo htmlspecialchars(strtolower($user['username'])); ?>"
                                     onclick="openChat(<?php echo $user['user_id']; ?>)">
                                    <div class="chat-avatar">
                                        <?php if (!empty($user['profile_image'])): ?>
                                            <img src="/Caps2/uploads/<?php echo htmlspecialchars($user['profile_image']); ?>" alt="<?php echo htmlspecialchars($user['username']); ?>">
                                        <?php else: ?>
                                            <i class="fa-solid fa-user"></i>
                                        <?php endif; ?>
                                        <div class="role-badge <?php echo strtolower($user['role']); ?>">
                                            <i class="fa-solid fa-<?php echo $user['role'] == 'Admin' ? 'shield' : 'user'; ?>"></i>
                                        </div>
                                        <span class="presence-dot <?php echo $user['is_online'] ? 'online' : 'offline'; ?>" data-presence-for="<?php echo (int)$user['user_id']; ?>"></span>
                                    </div>
                                    <div class="chat-info">
                                        <div class="chat-name">
                                            <?php echo htmlspecialchars($user['username']); ?>
                                            <span class="chat-role"><?php echo htmlspecialchars($user['role']); ?></span>
                                        </div>
                                        <div class="chat-preview"><?php echo htmlspecialchars(!empty($user['last_message']) ? (mb_strlen($user['last_message']) > 60 ? mb_substr($user['last_message'], 0, 60) . '...' : $user['last_message']) : 'No messages yet'); ?></div>
                                    </div>
                                    <div class="chat-meta">
                                        <div class="chat-time"><?php echo $user['last_message_time'] ? date('h:i A', strtotime($user['last_message_time'])) : ''; ?></div>
                                        <?php if ($user['unread_count'] > 0): ?>
                                            <div class="unread-badge"><?php echo $user['unread_count']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="chat-list-empty">
                                <i class="fa-solid fa-comments"></i>
                                <p>No users available to chat</p>
                            </div>
                        <?php endif; ?>
                        <div id="chatSearchEmpty" class="chat-list-empty" style="display:none;">
                            <i class="fa-solid fa-user-slash"></i>
                            <p>No matching users</p>
                        </div>
                    </div>
                </div>
                
                <!-- Chat Area -->
                <?php if ($current_chat_id > 0 && $current_chat_user): ?>
                    <div class="chat-area active" id="chatArea">
                        <div class="chat-header">
                            <div class="chat-header-avatar">
                                <?php if (!empty($current_chat_user['profile_image'])): ?>
                                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($current_chat_user['profile_image']); ?>" alt="<?php echo htmlspecialchars($current_chat_user['username']); ?>">
                                <?php else: ?>
                                    <i class="fa-solid fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <div class="chat-header-info">
                                <div class="chat-header-name"><?php echo htmlspecialchars($current_chat_user['username']); ?></div>
                                <div class="chat-header-status <?php echo $current_chat_user['is_online'] ? 'is-online' : 'is-offline'; ?>" id="chatHeaderStatus">
                                    <?php echo $current_chat_user['is_online'] ? 'Online' : 'Offline'; ?>
                                </div>
                            </div>
                            <button class="btn btn-ghost" onclick="closeChat()" style="background:none;border:none;color:#666;cursor:pointer;font-size:18px;">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        
                        <div class="chat-messages" id="chatMessages">
                            <?php if (count($chat_messages) > 0): ?>
                                <?php $lastDay = ''; ?>
                                <?php foreach ($chat_messages as $msg): ?>
                                    <?php
                                    $is_mine = $msg['sender_id'] == $user_id;
                                    // Divider ng petsa kapag nagbago ang araw (Today / Yesterday / Oct 8, 2026)
                                    $msgDay = date('Y-m-d', strtotime($msg['created_at']));
                                    if ($msgDay !== $lastDay):
                                        $lastDay = $msgDay;
                                        $dayLabel = $msgDay === date('Y-m-d') ? 'Today'
                                            : ($msgDay === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday'
                                            : date('M j, Y', strtotime($msgDay)));
                                    ?>
                                        <div class="msg-day" data-day="<?php echo $msgDay; ?>"><span><?php echo $dayLabel; ?></span></div>
                                    <?php endif; ?>
                                    <div class="message <?php echo $is_mine ? 'sent' : 'received'; ?>" data-message-id="<?php echo $msg['message_id']; ?>">
                                        <div class="message-avatar">
                                            <?php if (!empty($msg['sender_image'])): ?>
                                                <img src="/Caps2/uploads/<?php echo htmlspecialchars($msg['sender_image']); ?>" alt="Sender">
                                            <?php else: ?>
                                                <i class="fa-solid fa-user"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?php if (!empty($msg['is_deleted'])): ?>
                                                <div class="message-content">
                                                    <p class="message-text deleted-text"><i class="fa-solid fa-ban"></i> This message was deleted</p>
                                                </div>
                                            <?php else: ?>
                                                <div class="message-content">
                                                    <button type="button" class="msg-menu-btn" onclick='openDeleteModal(<?php echo (int)$msg['message_id']; ?>, <?php echo $is_mine ? 'true' : 'false'; ?>, event)' title="More">
                                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                                    </button>
                                                    <!-- Display Image Attachment -->
                                                    <?php if ($msg['message_type'] === 'image' && !empty($msg['attachment_url'])): ?>
                                                        <div class="message-attachment">
                                                            <div class="attachment-wrap">
                                                                <a href="/Caps2/uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" target="_blank">
                                                                    <img src="/Caps2/uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" alt="Attachment" style="max-width: 220px; max-height: 220px; border-radius: 8px; display: block; object-fit: cover;">
                                                                </a>
                                                                <button type="button" class="forward-btn" onclick='openForwardModal(<?php echo htmlspecialchars(json_encode($msg['attachment_url']), ENT_QUOTES); ?>, "image", event)' title="Forward">
                                                                    <i class="fa-solid fa-share"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    <!-- Display Document Attachment -->
                                                    <?php elseif ($msg['message_type'] === 'file' && !empty($msg['attachment_url'])): ?>
                                                        <div class="message-attachment">
                                                            <div class="attachment-wrap file-wrap">
                                                                <a href="/Caps2/uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" target="_blank" style="color: inherit; text-decoration: underline; font-weight: 500;">
                                                                    <i class="fa-solid fa-file-pdf"></i> View Attachment
                                                                </a>
                                                                <button type="button" class="forward-btn" onclick='openForwardModal(<?php echo htmlspecialchars(json_encode($msg['attachment_url']), ENT_QUOTES); ?>, "file", event)' title="Forward">
                                                                    <i class="fa-solid fa-share"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($msg['message'])): ?>
                                                        <p class="message-text"><?php echo sp_message_html((string) $msg['message'], 'staff'); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="message-time"><?php echo date('h:i A', strtotime($msg['created_at'])); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; color: #999; padding: 40px;">
                                    <i class="fa-solid fa-paper-plane" style="font-size: 40px; margin-bottom: 15px; opacity: 0.3;"></i>
                                    <p>No messages yet</p>
                                    <p style="font-size: 12px;">Send a message to start the conversation</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="message-input-area">
                            <form id="staffMessageForm" class="message-input-form" enctype="multipart/form-data">
                                <input type="hidden" name="receiver_id" value="<?php echo $current_chat_id; ?>">
                                <input type="hidden" name="send_message" value="1">
                                
                                <!-- File Attachment Button -->
                                <label for="staffFileInput" class="attach-btn" title="Attach File / Image">
                                    <i class="fa-solid fa-paperclip"></i>
                                </label>
                                <input type="file" id="staffFileInput" name="attachment" style="display: none;" accept="image/*,.pdf,.doc,.docx" onchange="previewFileName()">

                                <!-- Image Preview Container -->
                                <div id="imagePreviewContainer" style="display: none; position: relative; width: fit-content; margin-bottom: 10px;">
                                    <img id="imagePreview" src="" alt="Preview" style="max-height: 100px; border-radius: 8px; border: 1px solid #ddd; object-fit: cover; display: block;">
                                    <button type="button" onclick="clearPreview()" style="position: absolute; top: -6px; right: -6px; background: #e74c3c; color: white; border: none; border-radius: 50%; width: 22px; height: 22px; font-size: 11px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>

                                <textarea name="message" id="staffMessageInput" class="message-input" placeholder="Type a message..."></textarea>
                                
                                <button type="submit" class="send-btn">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </button>
                            </form>
                            <div id="filePreviewName" class="file-preview-name"></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="chat-area empty">
                        <div class="empty-state">
                            <div class="empty-illustration" aria-hidden="true">
                                <span class="empty-ring"></span>
                                <span class="empty-icon"><i class="fa-solid fa-comments"></i></span>
                                <span class="empty-paw p1"><i class="fa-solid fa-paw"></i></span>
                                <span class="empty-paw p2"><i class="fa-solid fa-paw"></i></span>
                            </div>
                            <h3>Select a conversation</h3>
                            <p>Choose a user from the list to reply to adopters, answer questions about applications, or coordinate with the admin.</p>
                            <ul class="empty-tips">
                                <li><i class="fa-solid fa-magnifying-glass"></i> Search a name to find a conversation fast</li>
                                <li><i class="fa-solid fa-paperclip"></i> Send photos or documents</li>
                                <li><i class="fa-solid fa-circle"></i> Green dot means the user is online</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
                
            </section>
        </div>
    </main>
</div>

<!-- Forward To Modal -->
<div id="forwardModal" class="forward-modal-overlay">
    <div class="forward-modal">
        <div class="forward-modal-header">
            <h3 id="forwardModalTitle">Forward to...</h3>
            <button type="button" class="forward-modal-close" onclick="closeForwardModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="forward-modal-list" id="forwardUserList">
            <?php if (count($chat_users) > 0): ?>
                <?php foreach ($chat_users as $fwd_user): ?>
                    <div class="forward-user-item" onclick='selectForwardUser(<?php echo (int)$fwd_user['user_id']; ?>, <?php echo htmlspecialchars(json_encode($fwd_user['username']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($fwd_user['profile_image'] ?: null), ENT_QUOTES); ?>)'>
                        <div class="chat-avatar" style="width:36px;height:36px;">
                            <?php if (!empty($fwd_user['profile_image'])): ?>
                                <img src="/Caps2/uploads/<?php echo htmlspecialchars($fwd_user['profile_image']); ?>" alt="">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="font-size:14px;"></i>
                            <?php endif; ?>
                        </div>
                        <div class="forward-user-info">
                            <div class="forward-user-name"><?php echo htmlspecialchars($fwd_user['username']); ?></div>
                            <div class="forward-user-role"><?php echo htmlspecialchars($fwd_user['role']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 30px 20px; text-align: center; color: #999;">
                    <p>No users available</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="forward-modal-caption" id="forwardCaptionStep">
            <div class="forward-selected-user">
                <button type="button" class="forward-back-btn" onclick="backToForwardList()" title="Back">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <div class="chat-avatar" id="forwardSelectedAvatar" style="width:32px;height:32px;"></div>
                <div class="forward-selected-name" id="forwardSelectedName"></div>
            </div>
            <textarea id="forwardCaptionInput" class="forward-caption-input" placeholder="Add a comment (optional)..."></textarea>
            <button type="button" class="forward-send-btn" onclick="confirmForward()">
                <i class="fa-solid fa-paper-plane"></i> Send
            </button>
        </div>
    </div>
</div>

<!-- Delete Message Modal -->
<div id="deleteModal" class="forward-modal-overlay">
    <div class="forward-modal" style="max-width: 340px;">
        <div class="forward-modal-header">
            <h3>Delete message</h3>
            <button type="button" class="forward-modal-close" onclick="closeDeleteModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div style="padding: 10px 0;">
            <div id="deleteEveryoneBtn" class="forward-user-item" onclick="confirmDelete('everyone')" style="color:#e74c3c;">
                <div class="chat-avatar" style="width:36px;height:36px; background:#fdecea;">
                    <i class="fa-solid fa-trash" style="font-size:14px; color:#e74c3c;"></i>
                </div>
                <div class="forward-user-info">
                    <div class="forward-user-name" style="color:#e74c3c;">Remove for everyone</div>
                    <div class="forward-user-role">Removes this message for all participants</div>
                </div>
            </div>
            <div class="forward-user-item" onclick="confirmDelete('me')">
                <div class="chat-avatar" style="width:36px;height:36px;">
                    <i class="fa-solid fa-eye-slash" style="font-size:14px;"></i>
                </div>
                <div class="forward-user-info">
                    <div class="forward-user-name">Remove for me</div>
                    <div class="forward-user-role">Only hides it from your own view</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Small success toast -->
<div id="actionToast" style="position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#2c3e50; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s ease, transform .2s ease; z-index:9999;"></div>

<script>
function openChat(userId) {
    window.location.href = 'messages.php?chat=' + userId;
}

function closeChat() {
    window.location.href = 'messages.php';
}

// Small toast for success/failure feedback
let toastTimer = null;
function showToast(message, isError) {
    const toast = document.getElementById('actionToast');
    if (!toast) return;
    toast.textContent = message;
    toast.style.background = isError ? '#e74c3c' : '#2c3e50';
    toast.style.opacity = '1';
    toast.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-50%) translateY(20px)';
    }, 2500);
}

// Forward-to-user modal logic
let forwardData = { url: null, type: null };
let forwardSelected = { id: null, name: null };

function openForwardModal(attachmentUrl, type, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    forwardData = { url: attachmentUrl, type: type };
    resetForwardModal();
    const modal = document.getElementById('forwardModal');
    if (modal) modal.style.display = 'flex';
}

function resetForwardModal() {
    const list = document.getElementById('forwardUserList');
    const captionStep = document.getElementById('forwardCaptionStep');
    const title = document.getElementById('forwardModalTitle');
    const captionInput = document.getElementById('forwardCaptionInput');
    if (list) list.style.display = '';
    if (captionStep) captionStep.style.display = 'none';
    if (title) title.textContent = 'Forward to...';
    if (captionInput) captionInput.value = '';
    forwardSelected = { id: null, name: null };
}

function closeForwardModal() {
    const modal = document.getElementById('forwardModal');
    if (modal) modal.style.display = 'none';
    resetForwardModal();
}

// Step 1 -> Step 2: pinili na ni staff kung sino ang tatanggap
function selectForwardUser(userId, username, profileImage) {
    forwardSelected = { id: userId, name: username };

    document.getElementById('forwardUserList').style.display = 'none';
    document.getElementById('forwardCaptionStep').style.display = 'flex';
    document.getElementById('forwardModalTitle').textContent = 'Add a comment';

    const avatarBox = document.getElementById('forwardSelectedAvatar');
    avatarBox.innerHTML = profileImage
        ? `<img src="/Caps2/uploads/${escapeHtml(profileImage)}" alt="">`
        : '<i class="fa-solid fa-user" style="font-size:14px;"></i>';
    document.getElementById('forwardSelectedName').textContent = username;

    const captionInput = document.getElementById('forwardCaptionInput');
    captionInput.value = '';
    captionInput.focus();
}

// Balik sa listahan ng users (Step 2 -> Step 1)
function backToForwardList() {
    document.getElementById('forwardUserList').style.display = '';
    document.getElementById('forwardCaptionStep').style.display = 'none';
    document.getElementById('forwardModalTitle').textContent = 'Forward to...';
}

// Step 2: ipadala na ang attachment kasama ang comment (kung meron)
function confirmForward() {
    if (!forwardData.url || !forwardSelected.id) return;

    const captionInput = document.getElementById('forwardCaptionInput');
    const caption = captionInput ? captionInput.value.trim() : '';
    const receiverId = forwardSelected.id;

    fetch('messages.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `forward_message=1&receiver_id=${encodeURIComponent(receiverId)}&attachment_url=${encodeURIComponent(forwardData.url)}&message_type=${encodeURIComponent(forwardData.type)}&message=${encodeURIComponent(caption)}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeForwardModal();
            showToast(`Forwarded to ${forwardSelected.name}`, false);
            // Kung yung pinag-forward-an ang bukas na chat, ipakita agad ang bagong mensahe
            if (receiverId == currentChatId) {
                fetchMessages();
            }
        } else {
            showToast(data.error || 'Failed to forward message', true);
        }
    })
    .catch(err => {
        console.error('Forward error:', err);
        showToast('Failed to forward message', true);
    });
}

// Delete message modal logic
let deleteTarget = { id: null, isMine: false };

function openDeleteModal(messageId, isMine, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    deleteTarget = { id: messageId, isMine: isMine };
    const everyoneBtn = document.getElementById('deleteEveryoneBtn');
    if (everyoneBtn) everyoneBtn.style.display = isMine ? 'flex' : 'none';
    const modal = document.getElementById('deleteModal');
    if (modal) modal.style.display = 'flex';
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    if (modal) modal.style.display = 'none';
    deleteTarget = { id: null, isMine: false };
}

function confirmDelete(type) {
    if (!deleteTarget.id) return;
    const messageId = deleteTarget.id;

    fetch('messages.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `delete_message=1&message_id=${encodeURIComponent(messageId)}&delete_type=${encodeURIComponent(type)}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeDeleteModal();
            const el = document.querySelector(`[data-message-id="${messageId}"]`);
            if (type === 'everyone') {
                if (el) {
                    const contentDiv = el.querySelector('.message-content');
                    if (contentDiv) {
                        contentDiv.innerHTML = '<p class="message-text deleted-text"><i class="fa-solid fa-ban"></i> This message was deleted</p>';
                    }
                }
                showToast('Message removed for everyone', false);
            } else {
                if (el) el.remove();
                showToast('Message removed for you', false);
            }
        } else {
            showToast(data.error || 'Failed to delete message', true);
        }
    })
    .catch(err => {
        console.error('Delete error:', err);
        showToast('Failed to delete message', true);
    });
}

document.getElementById('deleteModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

document.getElementById('forwardModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeForwardModal();
});

// Auto-scroll to bottom of chat messages
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

// Kapareho ng sp_message_html(..., 'staff') sa PHP: napipindot ang mga link,
// chip ang button papunta sa page ng adopter, at napipindot ang Google Maps.
function spMessageHtml(text) {
    const BUTTON = /\[\[([^|\]\r\n]{1,80})\|([^\]\s]{1,300})\]\]/g;
    const linkify = s => escapeHtml(s)
        .replace(/\bhttps?:\/\/[^\s<>"']+/gi, url => {
            const clean = url.replace(/[.,);:!?]+$/, '');
            return `<a class="msg-link" href="${clean}" target="_blank" rel="noopener noreferrer">${clean}</a>${url.slice(clean.length)}`;
        })
        .replace(/\n/g, '<br>');
    let html = '', last = 0, m;
    while ((m = BUTTON.exec(text)) !== null) {
        html += linkify(text.slice(last, m.index));
        const label = escapeHtml(m[1].trim());
        const target = m[2];
        if (target.indexOf('https://www.google.com/maps/') === 0) {
            html += `<a class="msg-action-btn" href="${escapeHtml(target)}" target="_blank" rel="noopener">${label}</a>`;
        } else {
            html += `<span class="msg-action-chip" title="This button appears in the adopter's chat"><i class="fa-solid fa-hand-pointer" aria-hidden="true"></i> ${label}</span>`;
        }
        last = BUTTON.lastIndex;
    }
    return html + linkify(text.slice(last));
}

function scrollToBottom() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
}

// Scroll to bottom when page loads
window.addEventListener('load', scrollToBottom);

// Live search/filter para sa listahan ng users sa sidebar
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('chatSearchInput');
    const chatItems = document.querySelectorAll('#chatListItems .chat-item');
    const emptyState = document.getElementById('chatSearchEmpty');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            let visibleCount = 0;

            chatItems.forEach(item => {
                const name = item.getAttribute('data-username') || '';
                const matches = name.includes(query);
                item.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            if (emptyState) {
                emptyState.style.display = (query && visibleCount === 0) ? 'block' : 'none';
            }
        });
    }
});

// Polling variables
let lastMessageId = <?php echo (int)$last_msg_id; ?>;
const currentChatId = <?php echo $current_chat_id; ?>;

// Function para i-render ang image o file preview
function handleFileSelection(file) {
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImg = document.getElementById('imagePreview');
    const filePreviewText = document.getElementById('filePreviewName');

    if (!file) {
        clearPreview();
        return;
    }

    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewContainer.style.display = 'inline-block';
        };
        reader.readAsDataURL(file);
        if (filePreviewText) filePreviewText.textContent = '';
    } else {
        if (previewContainer) previewContainer.style.display = 'none';
        if (filePreviewText) filePreviewText.textContent = "Attached: " + file.name;
    }
}

// Function kapag pumili sa file input (Paperclip icon)
function previewFileName() {
    const input = document.getElementById('staffFileInput');
    if (input.files.length > 0) {
        handleFileSelection(input.files[0]);
    }
}

// Function para tanggalin ang nakakabit na preview/file
function clearPreview() {
    const input = document.getElementById('staffFileInput');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImg = document.getElementById('imagePreview');
    const filePreviewText = document.getElementById('filePreviewName');

    if (input) input.value = '';
    if (previewImg) previewImg.src = '';
    if (previewContainer) previewContainer.style.display = 'none';
    if (filePreviewText) filePreviewText.textContent = '';
}

// Event Listener para sa Paste (Ctrl + V) sa Textarea
document.addEventListener('DOMContentLoaded', function() {
    const messageInput = document.getElementById('staffMessageInput');

    if (messageInput) {
        messageInput.addEventListener('paste', function(e) {
            const items = (e.clipboardData || e.originalEvent.clipboardData).items;

            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    const blob = items[i].getAsFile();
                    const file = new File([blob], `pasted_image_${Date.now()}.png`, { type: blob.type });

                    // I-attach ang na-paste na image sa hidden file input
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    const fileInput = document.getElementById('staffFileInput');
                    fileInput.files = dataTransfer.files;

                    // I-display ang preview sa itaas ng input box
                    handleFileSelection(file);
                    break;
                }
            }
        });
    }
});

// AJAX Form Submission
document.getElementById('staffMessageForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const messageInput = document.getElementById('staffMessageInput');
    const fileInput = document.getElementById('staffFileInput');

    if (!messageInput.value.trim() && fileInput.files.length === 0) return;

    fetch('messages.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            messageInput.value = '';
            clearPreview();
            
            // Tawagin ang pollMessages para lumabas agad ang chat sa screen nang hindi nag-rerefresh
            fetchMessages(); 
        } else {
            alert(data.error || 'Failed to send message');
        }
    })
    .catch(err => console.error('Error sending message:', err));
});

// Single Enter Key Event Handler
document.addEventListener('DOMContentLoaded', function() {
    const messageInput = document.getElementById('staffMessageInput');
    const messageForm = document.getElementById('staffMessageForm');

    if (messageInput && messageForm) {
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                messageForm.requestSubmit();
            }
        });
    }

    const forwardCaptionInput = document.getElementById('forwardCaptionInput');
    if (forwardCaptionInput) {
        forwardCaptionInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                confirmForward();
            }
        });
    }
});

// Auto-Polling for Messages and Sidebar Updates
function fetchMessages() {
    fetch(`messages.php?poll=1&chat=${currentChatId}&last_id=${lastMessageId}`)
        .then(res => res.json())
        .then(data => {
            // Update active chat window with new messages
            if (data.messages && data.messages.length > 0) {
                const chatBox = document.getElementById('chatMessages');
                if (chatBox) {
                    data.messages.forEach(msg => {
                        if (!document.querySelector(`[data-message-id="${msg.message_id}"]`)) {
                            const isSent = msg.sender_id == '<?php echo $user_id; ?>';
                            let contentHTML = '';

                            if (msg.is_deleted == 1 || msg.is_deleted === true) {
                                contentHTML = `<div class="message-content"><p class="message-text deleted-text"><i class="fa-solid fa-ban"></i> This message was deleted</p></div>`;
                            } else {
                                let attachmentHTML = '';
                                if (msg.message_type === 'image' && msg.attachment_url) {
                                    attachmentHTML = `<div class="message-attachment"><div class="attachment-wrap"><a href="/Caps2/uploads/${escapeHtml(msg.attachment_url)}" target="_blank"><img src="/Caps2/uploads/${escapeHtml(msg.attachment_url)}" style="max-width:220px; max-height:220px; border-radius:8px; display:block; object-fit:cover;"></a><button type="button" class="forward-btn" onclick='openForwardModal(${escapeHtml(JSON.stringify(msg.attachment_url))}, "image", event)' title="Forward"><i class="fa-solid fa-share"></i></button></div></div>`;
                                } else if (msg.message_type === 'file' && msg.attachment_url) {
                                    attachmentHTML = `<div class="message-attachment"><div class="attachment-wrap file-wrap"><a href="/Caps2/uploads/${escapeHtml(msg.attachment_url)}" target="_blank" style="color:inherit; text-decoration:underline;"><i class="fa-solid fa-file-pdf"></i> View Attachment</a><button type="button" class="forward-btn" onclick='openForwardModal(${escapeHtml(JSON.stringify(msg.attachment_url))}, "file", event)' title="Forward"><i class="fa-solid fa-share"></i></button></div></div>`;
                                }
                                contentHTML = `<div class="message-content">
                                        <button type="button" class="msg-menu-btn" onclick='openDeleteModal(${msg.message_id}, ${isSent}, event)' title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                        ${attachmentHTML}
                                        ${msg.message ? `<p class="message-text">${spMessageHtml(msg.message)}</p>` : ''}
                                    </div>`;
                            }

                            // Divider na "Today" kapag ito ang unang mensahe ngayong araw
                            const msgDay = (msg.created_at || '').slice(0, 10);
                            const days = chatBox.querySelectorAll('.msg-day');
                            const lastDay = days.length ? days[days.length - 1].dataset.day : '';
                            if (msgDay && msgDay !== lastDay) {
                                const sep = document.createElement('div');
                                sep.className = 'msg-day';
                                sep.dataset.day = msgDay;
                                sep.innerHTML = '<span>Today</span>';
                                chatBox.appendChild(sep);
                            }

                            const messageDiv = document.createElement('div');
                            messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                            messageDiv.setAttribute('data-message-id', msg.message_id);
                            messageDiv.innerHTML = `
                                <div class="message-avatar">
                                    ${msg.sender_image ? `<img src="/Caps2/uploads/${escapeHtml(msg.sender_image)}" alt="Sender">` : `<i class="fa-solid fa-user"></i>`}
                                </div>
                                <div>
                                    ${contentHTML}
                                    <div class="message-time">${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                                </div>
                            `;
                            chatBox.appendChild(messageDiv);
                            if (msg.message_id > lastMessageId) {
                                lastMessageId = msg.message_id;
                            }
                        }
                    });
                    scrollToBottom();
                }
            }

            // Update sidebar unread counts and message previews real-time
            if (data.users && data.users.length > 0) {
                data.users.forEach(u => {
                    const chatItem = document.querySelector(`.chat-item[onclick="openChat(${u.user_id})"]`);
                    if (chatItem) {
                        const previewEl = chatItem.querySelector('.chat-preview');
                        const timeEl = chatItem.querySelector('.chat-time');
                        const metaEl = chatItem.querySelector('.chat-meta');

                        if (previewEl) {
                            previewEl.textContent = u.last_message.substring(0, 30) + (u.last_message.length > 30 ? '...' : '');
                        }
                        if (timeEl) {
                            timeEl.textContent = u.last_message_time;
                        }

                        let badgeEl = chatItem.querySelector('.unread-badge');
                        if (u.unread_count > 0 && u.user_id != currentChatId) {
                            if (!badgeEl) {
                                badgeEl = document.createElement('div');
                                badgeEl.className = 'unread-badge';
                                metaEl.appendChild(badgeEl);
                            }
                            badgeEl.textContent = u.unread_count;
                        } else if (badgeEl) {
                            badgeEl.remove();
                        }

                        const dotEl = chatItem.querySelector(`.presence-dot[data-presence-for="${u.user_id}"]`);
                        if (dotEl) {
                            dotEl.classList.toggle('online', !!u.is_online);
                            dotEl.classList.toggle('offline', !u.is_online);
                        }
                    }
                });
            }

            // Update active chat header online/offline status
            if (typeof data.partner_online !== 'undefined') {
                const statusEl = document.getElementById('chatHeaderStatus');
                if (statusEl) {
                    statusEl.classList.toggle('is-online', data.partner_online);
                    statusEl.classList.toggle('is-offline', !data.partner_online);
                    statusEl.textContent = data.partner_online ? 'Online' : 'Offline';
                }
            }
        })
        .catch(err => console.error('Error fetching messages:', err));
}

// Poll every 3 seconds
setInterval(fetchMessages, 3000);

// Mobile responsive handling
function handleMobileView() {
    const chatList = document.getElementById('chatList');
    const chatArea = document.getElementById('chatArea');
    
    if (window.innerWidth <= 768) {
        if (chatArea && chatArea.classList.contains('active')) {
            chatList.classList.add('hidden');
        } else {
            chatList.classList.remove('hidden');
        }
    } else {
        chatList.classList.remove('hidden');
    }
}

window.addEventListener('resize', handleMobileView);
window.addEventListener('load', handleMobileView);
</script>

    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>