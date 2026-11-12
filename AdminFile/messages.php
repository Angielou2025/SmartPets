<?php
session_start();
require '../connection/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../Login_Page/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

// Presence heartbeat: i-update ang huling activity ng admin sa bawat request
$pdo->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?")->execute([$user_id]);

// Helper: itinuturing na "online" ang user kung may activity sa loob ng huling 30 segundo
function isUserOnline($last_active) {
    if (empty($last_active)) return false;
    return (strtotime($last_active) >= (time() - 30));
}

// Fetch user data including profile image
$stmt = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$profile_image = $user['profile_image'] ?? '';
$profile_img_path = !empty($profile_image) ? '../uploads/' . $profile_image : null;

// Handle message sending via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    header('Content-Type: application/json');
    
    $receiver_id = filter_input(INPUT_POST, 'receiver_id', FILTER_VALIDATE_INT);
    $message = trim($_POST['message'] ?? '');
    $message_type = 'text';
    $attachment_url = NULL;

    if (!$receiver_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid receiver ID.']);
        exit();
    }

    try {
        // Siguraduhing Staff lang ang pwedeng makontak ng admin
        $role_stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND LOWER(role) = 'staff'");
        $role_stmt->execute([$receiver_id]);
        if (!$role_stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'You can only message staff accounts.']);
            exit();
        }

        // Handle file upload
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
            $upload_path = '../uploads/' . $new_filename;

            if (!move_uploaded_file($file_tmp, $upload_path)) {
                echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
                exit();
            }
            $attachment_url = $new_filename;
        }

        if (empty($message) && empty($attachment_url)) {
            echo json_encode(['success' => false, 'error' => 'Cannot send an empty message.']);
            exit();
        }

        // Search or Create Conversation
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
        
        // Insert Message
        $send_stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, message, attachment_url, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
        $send_stmt->execute([$conversation_id, $user_id, $message_type, $message, $attachment_url]);

        $inserted_id = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'message_id' => $inserted_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle Forwarding/Sharing an existing attachment to another staff
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

        // Siguraduhing Staff lang ang pwedeng pagforwardan
        $role_stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND LOWER(role) = 'staff'");
        $role_stmt->execute([$receiver_id]);
        if (!$role_stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'You can only forward to staff accounts.']);
            exit();
        }

        // Siguraduhing kabilang ang attachment sa isang conversation ng admin na ito (para hindi maaabuso ng arbitrary filenames)
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

        // Find or create conversation with the target staff
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

        $send_stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, message, attachment_url, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
        $send_stmt->execute([$conversation_id, $user_id, $fwd_type, ($fwd_caption !== '' ? $fwd_caption : null), $attachment_url]);

        echo json_encode(['success' => true, 'message_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
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

        // Kunin ang mensahe kasama ang conversation nito, at siguraduhing kabilang dito ang admin na ito
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
                echo json_encode(['success' => false, 'error' => 'You can only unsend your own messages.']);
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

// Handle polling for new messages
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['poll']) && isset($_GET['last_id'])) {
    header('Content-Type: application/json');
    $last_id = intval($_GET['last_id']);
    $chat_id = isset($_GET['chat']) ? intval($_GET['chat']) : 0;
    
    if ($chat_id > 0) {
        $conv_stmt = $pdo->prepare("SELECT conversation_id FROM conversations WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)");
        $conv_stmt->execute([$chat_id, $user_id, $user_id, $chat_id]);
        $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conversation) {
            $poll_stmt = $pdo->prepare("
                SELECT m.*, u.username as sender_name, u.profile_image as sender_image 
                FROM messages m 
                JOIN users u ON m.sender_id = u.user_id 
                WHERE m.conversation_id = ? AND m.message_id > ?
                AND (m.deleted_for IS NULL OR FIND_IN_SET(?, m.deleted_for) = 0)
                ORDER BY m.message_id ASC
            ");
            $poll_stmt->execute([$conversation['conversation_id'], $last_id, $user_id]);
            $new_messages = $poll_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Kunin ang huling activity ng kausap para sa online/offline status
            $presence_stmt = $pdo->prepare("SELECT last_active FROM users WHERE user_id = ?");
            $presence_stmt->execute([$chat_id]);
            $presence = $presence_stmt->fetch(PDO::FETCH_ASSOC);
            $partner_online = isUserOnline($presence['last_active'] ?? null);

            echo json_encode([
                'messages' => $new_messages,
                'partner_online' => $partner_online,
                'partner_last_active' => $presence['last_active'] ?? null
            ]);
            exit();
        }
    }
    
    echo json_encode(['messages' => []]);
    exit();
}

$current_chat_id = isset($_GET['chat']) ? intval($_GET['chat']) : 0;

// Mark messages as read
if ($current_chat_id > 0) {
    $mark_read = $pdo->prepare("
        UPDATE messages m
        INNER JOIN conversations c ON m.conversation_id = c.conversation_id
        SET m.is_read = 1 
        WHERE ((c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?))
        AND m.sender_id != ? 
        AND m.is_read = 0
    ");
    $mark_read->execute([$current_chat_id, $user_id, $user_id, $current_chat_id, $user_id]);
}

// Fetch users list
$users_stmt = $pdo->prepare("
    SELECT DISTINCT user_id, username, profile_image, role, last_active 
    FROM users 
    WHERE LOWER(role) = 'staff' AND user_id != ?
    ORDER BY username ASC
");
$users_stmt->execute([$user_id]);
$chat_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($chat_users as &$cu) {
    $cu['is_online'] = isUserOnline($cu['last_active']);
}
unset($cu);

// Fetch current conversation messages
$chat_messages = [];
$current_chat_user = null;

if ($current_chat_id > 0) {
    $conv_stmt = $pdo->prepare("SELECT conversation_id FROM conversations WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)");
    $conv_stmt->execute([$current_chat_id, $user_id, $user_id, $current_chat_id]);
    $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($conversation) {
        $chat_stmt = $pdo->prepare("
            SELECT m.*, u.username as sender_name, u.profile_image as sender_image 
            FROM messages m 
            JOIN users u ON m.sender_id = u.user_id 
            WHERE m.conversation_id = ?
            AND (m.deleted_for IS NULL OR FIND_IN_SET(?, m.deleted_for) = 0)
            ORDER BY m.created_at ASC
        ");
        $chat_stmt->execute([$conversation['conversation_id'], $user_id]);
        $chat_messages = $chat_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $user_stmt = $pdo->prepare("SELECT username, profile_image, role, last_active FROM users WHERE user_id = ?");
    $user_stmt->execute([$current_chat_id]);
    $current_chat_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    if ($current_chat_user) {
        $current_chat_user['is_online'] = isUserOnline($current_chat_user['last_active']);
    }
}

// Count unread and last message per user
foreach ($chat_users as &$user) {
    $unread_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.conversation_id
        WHERE ((c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?))
        AND m.sender_id != ? 
        AND m.is_read = 0
    ");
    $unread_stmt->execute([$user['user_id'], $user_id, $user_id, $user['user_id'], $user_id]);
    $user['unread_count'] = $unread_stmt->fetchColumn();
    
    $last_msg_stmt = $pdo->prepare("
        SELECT m.message, m.created_at 
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.conversation_id
        WHERE (c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?)
        ORDER BY m.message_id DESC LIMIT 1
    ");
    $last_msg_stmt->execute([$user['user_id'], $user_id, $user_id, $user['user_id']]);
    $last_msg = $last_msg_stmt->fetch(PDO::FETCH_ASSOC);
    $user['last_message'] = $last_msg['message'] ?? '';
    $user['last_message_time'] = $last_msg['created_at'] ?? '';
}
unset($user); // Inayos ang reference ng array loop

$total_unread = array_sum(array_column($chat_users, 'unread_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SMART PET - Messages</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .chat-main-container {
      display: flex;
      background: #ffffff;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.05);
      height: calc(100vh - 120px);
      overflow: hidden;
      font-family: inherit;
    }

    .chat-sidebar {
      width: 320px;
      border-right: 1px solid #f0f0f0;
      display: flex;
      flex-direction: column;
      background: #ffffff;
    }

    .chat-sidebar-header {
      background: #ff6b00;
      color: #ffffff;
      padding: 16px 20px;
      border-top-left-radius: 12px;
    }

    .chat-sidebar-header h2 {
      margin: 0;
      font-size: 18px;
      font-weight: 600;
    }

    .chat-users-list {
      flex: 1;
      overflow-y: auto;
    }

    .user-item-card {
      display: flex;
      align-items: center;
      padding: 12px 16px;
      border-bottom: 1px solid #f5f5f5;
      text-decoration: none;
      color: inherit;
      transition: background 0.2s;
      position: relative;
    }

    .user-item-card:hover {
      background: #fafafa;
    }

    .user-item-card.active {
      background: #fff8f0;
      border-left: 4px solid #ff6b00;
    }

    .user-avatar-wrapper {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      position: relative;
      margin-right: 12px;
      flex-shrink: 0;
      background: #eee;
    }

    .user-avatar-wrapper img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }

    .avatar-placeholder, .avatar-placeholder-sm {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #888;
    }

    .user-info {
      flex: 1;
      min-width: 0;
    }

    .user-name-row {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 4px;
    }

    .user-name {
      font-weight: 600;
      font-size: 14px;
      color: #333;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .role-badge {
      font-size: 10px;
      background: #eee;
      color: #666;
      padding: 2px 6px;
      border-radius: 4px;
    }

    .last-msg-preview {
      font-size: 12px;
      color: #888;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .user-meta {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 4px;
      margin-left: 8px;
    }

    .msg-time {
      font-size: 11px;
      color: #aaa;
    }

    .unread-badge {
      background: #ff6b00;
      color: white;
      font-size: 10px;
      padding: 2px 6px;
      border-radius: 10px;
      font-weight: bold;
    }

    .chat-body-area {
      flex: 1;
      display: flex;
      flex-direction: column;
      background: #fcfcfc;
    }

    .active-chat-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 24px;
      background: #ffffff;
      border-bottom: 1px solid #f0f0f0;
    }

    .active-user-info {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .active-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      overflow: hidden;
      background: #eee;
    }

    .active-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .active-name {
      margin: 0;
      font-size: 15px;
      font-weight: 600;
      color: #333;
    }

    .active-status {
      font-size: 12px;
      color: #888;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .active-status::before {
      content: '';
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #b0b0b0;
    }

    .active-status.is-online::before {
      background: #2ecc71;
    }

    .active-status.is-offline::before {
      background: #b0b0b0;
    }

    .presence-dot {
      position: absolute;
      bottom: 0;
      right: 0;
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

    .close-chat-btn {
      color: #aaa;
      font-size: 18px;
      text-decoration: none;
    }

    .close-chat-btn:hover {
      color: #333;
    }

    .messages-stream {
      flex: 1;
      padding: 24px;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 16px;
      background: #f8f9fa;
    }

    .msg-wrapper {
      display: flex;
      gap: 10px;
      max-width: 70%;
    }

    .msg-wrapper.sent {
      align-self: flex-end;
      flex-direction: row-reverse;
    }

    .msg-wrapper.received {
      align-self: flex-start;
    }

    .msg-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      overflow: hidden;
      background: #ddd;
      flex-shrink: 0;
    }

    .msg-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .msg-bubble {
      padding: 10px 16px;
      border-radius: 16px;
      font-size: 14px;
      line-height: 1.4;
    }

    .msg-wrapper.received .msg-bubble {
      background: #ffffff;
      color: #333;
      border-bottom-left-radius: 4px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .msg-wrapper.sent .msg-bubble {
      background: #ff6b00;
      color: #ffffff;
      border-bottom-right-radius: 4px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .msg-bubble p {
      margin: 0;
      word-break: break-word;
    }

    .msg-time-stamp {
      font-size: 10px;
      color: #aaa;
      margin-top: 4px;
      display: block;
    }

    .msg-wrapper.sent .msg-time-stamp {
      text-align: right;
    }

    .chat-input-area {
      padding: 16px 24px;
      background: #ffffff;
      border-top: 1px solid #f0f0f0;
      position: relative;
    }

    .chat-input-area form {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .attach-btn {
      color: #666;
      font-size: 20px;
      cursor: pointer;
      padding: 4px;
    }

    .attach-btn:hover {
      color: #ff6b00;
    }

    .input-wrapper {
      flex: 1;
      background: #f8f9fa;
      border-radius: 24px;
      padding: 8px 16px;
      border: 1px solid #eee;
    }

    .input-wrapper textarea {
      width: 100%;
      border: none;
      background: transparent;
      outline: none;
      resize: none;
      font-size: 14px;
      font-family: inherit;
      padding-top: 4px;
    }

    .send-btn {
      background: #ff6b00;
      color: white;
      border: none;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: background 0.2s;
    }

    .send-btn:hover {
      background: #e05d00;
    }

    .no-active-chat, .empty-chat-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100%;
      color: #aaa;
    }

    .no-active-chat i {
      font-size: 48px;
      margin-bottom: 12px;
    }

    .attachment-preview img {
      max-width: 220px;
      max-height: 220px;
      border-radius: 8px;
      display: block;
      margin-top: 6px;
    }

    .attachment-file a {
      color: inherit;
      text-decoration: underline;
    }

    #fileIndicator {
      display: none;
      position: absolute;
      top: -30px;
      left: 24px;
      background: #ff6b00;
      color: white;
      font-size: 12px;
      padding: 4px 12px;
      border-radius: 12px;
      align-items: center;
      gap: 8px;
    }

    #fileIndicator i {
      cursor: pointer;
    }

    /* Search bar */
    .chat-search-wrap {
      position: relative;
      padding: 10px 16px;
      border-bottom: 1px solid #f0f0f0;
    }

    .chat-search-wrap i {
      position: absolute;
      left: 28px;
      top: 50%;
      transform: translateY(-50%);
      color: #aaa;
      font-size: 13px;
    }

    .chat-search-wrap input {
      width: 100%;
      padding: 8px 12px 8px 30px;
      border: 1px solid #eee;
      background: #f8f9fa;
      border-radius: 20px;
      font-size: 13px;
      outline: none;
      box-sizing: border-box;
    }

    .chat-search-wrap input:focus {
      border-color: #ff6b00;
    }

    /* Message menu / actions */
    .msg-bubble {
      position: relative;
    }

    .msg-menu-btn {
      position: absolute;
      top: 2px;
      right: -28px;
      background: none;
      border: none;
      color: #aaa;
      cursor: pointer;
      font-size: 13px;
      padding: 4px;
      display: none;
    }

    .msg-wrapper.sent .msg-menu-btn {
      right: auto;
      left: -28px;
    }

    .msg-wrapper:hover .msg-menu-btn {
      display: block;
    }

    .msg-menu-btn:hover {
      color: #333;
    }

    .unsent-text {
      color: #999;
      font-style: italic;
      font-size: 13px;
      margin: 0;
    }

    .attachment-actions {
      display: flex;
      gap: 8px;
      margin-top: 6px;
    }

    .icon-action-btn {
      background: #f0f0f0;
      border: none;
      color: #555;
      width: 26px;
      height: 26px;
      border-radius: 50%;
      cursor: pointer;
      font-size: 11px;
    }

    .icon-action-btn:hover {
      background: #ff6b00;
      color: #fff;
    }

    /* Modals */
    .chat-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }

    .chat-modal-box {
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      width: 320px;
      max-width: 90%;
    }

    .chat-modal-box h3 {
      margin: 0 0 8px;
      font-size: 16px;
      color: #333;
    }

    .chat-modal-box p {
      margin: 0 0 16px;
      font-size: 13px;
      color: #888;
    }

    .chat-modal-actions {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .modal-btn {
      padding: 10px;
      border-radius: 8px;
      border: 1px solid #eee;
      background: #f8f9fa;
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
    }

    .modal-btn.danger {
      background: #fdecea;
      color: #d9362f;
      border-color: #fbd6d3;
    }

    .modal-btn.cancel {
      background: none;
      border: none;
      color: #888;
    }

    .modal-btn:hover {
      opacity: 0.85;
    }

    .forward-box #forwardSearchInput,
    .forward-box #forwardCaptionInput {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid #eee;
      border-radius: 8px;
      font-size: 13px;
      outline: none;
      box-sizing: border-box;
      margin-bottom: 10px;
    }

    .forward-staff-list {
      max-height: 200px;
      overflow-y: auto;
      margin-bottom: 12px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .forward-staff-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 6px 8px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 13px;
    }

    .forward-staff-item:hover {
      background: #fafafa;
    }

    @media (max-width: 768px) {
      .chat-sidebar {
        width: 100%;
        display: <?php echo $current_chat_id > 0 ? 'none' : 'flex'; ?>;
      }
      .chat-body-area {
        display: <?php echo $current_chat_id > 0 ? 'flex' : 'none'; ?>;
      }
    }

    /* ================================================================
       Propesyonal na itsura ng Messages (kulay ng Admin: berde).
       Override ng mga style sa itaas - walang binagong gawa ng chat.
       ================================================================ */
    .chat-main-container {
      height: calc(100vh - 110px);
      border: 1px solid #ebe5d8;
      border-radius: 16px;
      box-shadow: 0 12px 30px rgba(10, 10, 10, 0.05);
    }

    /* ---- Listahan ng usapan ---- */
    .chat-sidebar { width: 330px; border-right: 1px solid #eef0f3; }
    /* Telepono: buong lapad ang listahan (ang 330px sa itaas ay pang-desktop) */
    @media (max-width: 768px) { .chat-sidebar { width: 100%; border-right: none; } }
    .chat-sidebar-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
      padding: 20px 18px 6px;
      background: #fff;
      color: inherit;
      border-radius: 0;
    }
    .chat-sidebar-header h2 { font-size: 21px; font-weight: 800; color: #0b2a1e; }
    .chat-sidebar-header p { margin: 2px 0 0; font-size: 12.5px; color: #6b7280; }
    .chat-unread-total {
      flex-shrink: 0;
      margin-top: 4px;
      padding: 4px 10px;
      border-radius: 999px;
      background: #e8f3ed;
      color: #1f6b48;
      font-size: 11.5px;
      font-weight: 800;
    }
    .chat-search-wrap {
      margin: 12px 18px 14px !important;
      padding: 0 14px !important;
      height: 40px;
      display: flex;
      align-items: center;
      gap: 8px;
      border: 1.5px solid #e2e6e4 !important;
      border-radius: 10px !important;
      background: #f9fafb !important;
      transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
    }
    .chat-search-wrap:focus-within { border-color: #2d6a4f !important; background: #fff !important; box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.12); }
    .chat-search-wrap i { color: #9ca3af; }
    .chat-search-wrap input { border: none !important; outline: none; background: transparent !important; width: 100%; font-size: 14px; }
    .chat-users-list { padding: 0 10px 10px; border-top: 1px solid #eef0f3; padding-top: 8px; }
    .user-item-card {
      margin-bottom: 4px;
      padding: 12px;
      border: none;
      border-radius: 12px;
      transition: background-color .2s ease, box-shadow .2s ease;
    }
    .user-item-card:hover { background: #f6faf8; }
    .user-item-card.active {
      background: #e8f3ed;
      border-left: none;
      box-shadow: inset 0 0 0 1px #cfe5d8;
    }
    .user-item-card.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 14px;
      bottom: 14px;
      width: 3px;
      border-radius: 0 3px 3px 0;
      background: #2d6a4f;
    }
    .user-avatar-wrapper { background: linear-gradient(135deg, #1b4332, #40916c); box-shadow: 0 2px 8px rgba(27, 67, 50, 0.2); }
    .user-avatar-wrapper .avatar-placeholder { color: #fff; }
    .presence-dot { width: 13px; height: 13px; }
    .user-name { font-weight: 700; color: #0b2a1e; font-size: 14.5px; }
    .role-badge { background: #e8f3ed; color: #1f6b48; font-weight: 700; border-radius: 6px; }
    .last-msg-preview { font-size: 12.5px; color: #6b7280; }
    .msg-time { color: #8a94a6; }
    .unread-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 20px;
      height: 20px;
      padding: 0 6px;
      background: #2d6a4f;
      font-size: 11px;
      font-weight: 800;
      border-radius: 999px;
    }
    .user-item-card:has(.unread-badge) .user-name { font-weight: 800; }
    .user-item-card:has(.unread-badge) .last-msg-preview { color: #0b2a1e; font-weight: 600; }

    /* ---- Usapan ---- */
    .chat-body-area { background: #f7faf8; }
    .active-chat-header { padding: 14px 22px; border-bottom: 1px solid #eef0f3; }
    .active-avatar { background: linear-gradient(135deg, #1b4332, #40916c); box-shadow: 0 2px 8px rgba(27, 67, 50, 0.2); }
    .active-avatar .avatar-placeholder { color: #fff; }
    .active-name { font-size: 16px; font-weight: 800; color: #0b2a1e; }
    .close-chat-btn {
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
      color: #6b7280;
      transition: background-color .2s ease, color .2s ease;
    }
    .close-chat-btn:hover { background: #f3f4f6; color: #0b2a1e; }
    .messages-stream {
      gap: 12px;
      background-color: #f7faf8;
      background-image: radial-gradient(rgba(27, 67, 50, 0.05) 1px, transparent 1px);
      background-size: 18px 18px;
    }
    .msg-bubble { box-shadow: 0 1px 2px rgba(11, 42, 30, 0.08); font-size: 14px; line-height: 1.5; }
    .msg-wrapper.received .msg-bubble { background: #fff; border: 1px solid #e8ecea; color: #1f2937; }
    .msg-wrapper.sent .msg-bubble { background: linear-gradient(135deg, #2d6a4f, #1b4332); color: #fff; border: none; }
    .msg-time-stamp { color: #8a94a6; font-size: 11px; }
    .msg-avatar { background: linear-gradient(135deg, #1b4332, #40916c); }

    /* Divider ng petsa */
    .msg-day {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 6px 0 2px;
      color: #6b7280;
      font-size: 11.5px;
      font-weight: 700;
      letter-spacing: .4px;
      text-transform: uppercase;
    }
    .msg-day::before, .msg-day::after { content: ''; flex: 1; height: 1px; background: #e3e8e5; }
    .msg-day span { padding: 4px 12px; border-radius: 999px; background: #fff; border: 1px solid #e3e8e5; }

    /* Mga link at button sa loob ng mensahe */
    .msg-bubble .msg-link { color: inherit; text-decoration: underline; text-underline-offset: 2px; word-break: break-all; }
    .msg-wrapper.received .msg-link { color: #1f6b48; }
    .msg-action-chip, .msg-bubble .msg-action-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin: 6px 0 2px;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 12.5px;
      font-weight: 700;
      line-height: 1.3;
      text-decoration: none;
    }
    .msg-wrapper.sent .msg-action-chip,
    .msg-wrapper.sent .msg-action-btn { background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.35); color: #fff; }
    .msg-wrapper.received .msg-action-chip,
    .msg-wrapper.received .msg-action-btn { background: #e8f3ed; border: 1px solid #cfe5d8; color: #1f6b48; }
    .msg-action-chip { cursor: default; }
    .msg-bubble .msg-action-btn:hover { filter: brightness(1.08); }

    /* Walang laman pa ang usapan */
    .empty-chat-placeholder { margin: auto; text-align: center; color: #6b7280; }
    .empty-chat-placeholder i { font-size: 30px; color: #9ca3af; margin-bottom: 10px; }
    .empty-chat-placeholder p { font-weight: 700; color: #374151; margin: 0 0 4px; }
    .empty-chat-placeholder span { font-size: 13px; }

    /* Pag-type ng mensahe */
    /* May puwang sa kanan para hindi matakpan ng AI bubble ang send button */
    .chat-input-area { background: #fff; border-top: 1px solid #eef0f3; padding: 14px 92px 14px 18px; }
    .chat-input-area .attach-btn {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #6b7280;
      cursor: pointer;
      transition: background-color .2s ease, color .2s ease;
    }
    .chat-input-area .attach-btn:hover { background: #e8f3ed; color: #1f6b48; }
    .chat-input-area .input-wrapper textarea {
      background: #f4f6f5 !important;
      border: 1.5px solid transparent !important;
      border-radius: 22px !important;
      transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
    }
    .chat-input-area .input-wrapper textarea:focus { background: #fff !important; border-color: #2d6a4f !important; box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.12); outline: none; }
    .chat-input-area .send-btn {
      width: 44px;
      height: 44px;
      flex-shrink: 0;
      border: none;
      border-radius: 50%;
      background: linear-gradient(135deg, #2d6a4f, #1b4332) !important;
      color: #fff;
      box-shadow: 0 4px 12px rgba(27, 67, 50, 0.3);
      cursor: pointer;
    }

    /* Walang napiling usapan */
    .no-active-chat {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px;
      text-align: center;
      color: #6b7280;
      background-color: #f7faf8;
      background-image: radial-gradient(rgba(27, 67, 50, 0.06) 1px, transparent 1px);
      background-size: 18px 18px;
    }
    .no-active-chat .nac-icon {
      width: 76px;
      height: 76px;
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 22px;
      color: #fff;
      font-size: 30px;
      transform: rotate(-6deg);
      background: linear-gradient(145deg, #40916c, #1b4332);
      box-shadow: 0 12px 24px rgba(27, 67, 50, 0.3), inset 0 2px 0 rgba(255, 255, 255, 0.25);
    }
    .no-active-chat .nac-icon i { margin: 0; font-size: inherit; color: inherit; opacity: 1; }
    .no-active-chat h3 { margin: 0 0 6px; font-size: 21px; font-weight: 800; color: #0b2a1e; }
    .no-active-chat > p { max-width: 380px; margin: 0; font-size: 14px; line-height: 1.55; }
    .nac-tips {
      list-style: none;
      margin: 22px 0 0;
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      text-align: left;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid #e3e8e5;
      border-radius: 14px;
      font-size: 13px;
      color: #4b5563;
    }
    .nac-tips li { display: flex; align-items: center; gap: 10px; }
    .nac-tips i {
      width: 26px;
      height: 26px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px;
      background: #e8f3ed;
      color: #1f6b48;
      font-size: 11px;
      opacity: 1;
      margin: 0;
    }
    .nac-tips .fa-circle { color: #22c55e; font-size: 8px; }
  </style>
</head>
<body>

  <div class="app-container">
    <aside class="sidebar">
      <div class="brand">
        <div class="logo-circle">
          <img src="../Images/caloocan_logo.jpg" alt="City Logo">
        </div>
        <h2>SMART <span>PET</span></h2>
      </div>

      <nav class="sidebar-menu">
        <a href="index.php" class="menu-item"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="Manage_staff_file/manage_staff.php" class="menu-item"><i class="fa-solid fa-user-gear"></i> Manage Staff</a>
        <a href="Manage_adopters_file/manage_adopters.php" class="menu-item"><i class="fa-solid fa-users"></i> Manage Adopters</a>
        <a href="manage_pets.php" class="menu-item"><i class="fa-solid fa-paw"></i> Manage Pets</a>
        <a href="payments.php" class="menu-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
        <a href="reports.php" class="menu-item"><i class="fa-solid fa-chart-bar"></i> Reports</a>
        <a href="messages.php" class="menu-item active">
          <i class="fa-solid fa-comment-dots"></i> Messages<?php if ($total_unread > 0): ?><span class="sp-unread-badge"><?php echo $total_unread > 99 ? '99+' : $total_unread; ?></span><?php endif; ?>
        </a>
        <a href="activity_logs.php" class="menu-item"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</a>
                <a href="ml_model.php" class="menu-item"><i class="fa-solid fa-brain"></i> ML Model</a>
      </nav>

      <div class="sidebar-footer">
        <div class="city-illustration">
          <img src="../Images/caloocan_image.png" alt="Caloocan Arch Landmark" width="300">
        </div>
      </div>
    </aside>

    <main class="main-wrapper">
      <header class="top-header">
        <a href="edit_profile.php" class="profile-section" style="text-decoration: none; color: inherit;">
          <div class="avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
            <?php if ($profile_img_path && file_exists($profile_img_path)): ?>
              <img src="<?php echo htmlspecialchars($profile_img_path); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
            <?php else: ?>
              <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
            <?php endif; ?>
          </div>
          <span class="profile-name"><?php echo htmlspecialchars($username); ?></span>
        </a>
      </header>

      <section class="dashboard-content" style="padding: 20px;">
        <div class="chat-main-container">
          
          <div class="chat-sidebar">
            <?php $spTotalUnread = array_sum(array_map(fn ($u) => (int) $u['unread_count'], $chat_users)); ?>
            <div class="chat-sidebar-header">
              <div>
                <h2>Messages</h2>
                <p>Chat with the shelter staff</p>
              </div>
              <?php if ($spTotalUnread > 0): ?>
                <span class="chat-unread-total"><?php echo $spTotalUnread > 99 ? '99+' : $spTotalUnread; ?> new</span>
              <?php endif; ?>
            </div>

            <div class="chat-search-wrap">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" id="staffSearchInput" placeholder="Search staff..." autocomplete="off">
            </div>
            
            <div class="chat-users-list" id="chatUsersList">
              <?php if (count($chat_users) > 0): ?>
                <?php foreach ($chat_users as $user): ?>
                  <?php $isActive = ($current_chat_id == $user['user_id']); ?>
                  <a href="messages.php?chat=<?php echo $user['user_id']; ?>" class="user-item-card <?php echo $isActive ? 'active' : ''; ?>" data-username="<?php echo htmlspecialchars(mb_strtolower($user['username'])); ?>">
                    <div class="user-avatar-wrapper">
                      <?php if (!empty($user['profile_image'])): ?>
                        <img src="../uploads/<?php echo htmlspecialchars($user['profile_image']); ?>" alt="User Avatar">
                      <?php else: ?>
                        <div class="avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                      <?php endif; ?>
                      <span class="presence-dot <?php echo $user['is_online'] ? 'online' : 'offline'; ?>" data-presence-for="<?php echo (int)$user['user_id']; ?>"></span>
                    </div>
                    
                    <div class="user-info">
                      <div class="user-name-row">
                        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                        <span class="role-badge"><?php echo htmlspecialchars($user['role']); ?></span>
                      </div>
                      <div class="last-msg-preview">
                        <?php $spPrev = sp_message_preview((string) ($user['last_message'] ?? '')); echo htmlspecialchars($spPrev !== '' ? (mb_strlen($spPrev) > 42 ? mb_substr($spPrev, 0, 42) . '...' : $spPrev) : 'No messages yet'); ?>
                      </div>
                    </div>

                    <div class="user-meta">
                      <span class="msg-time"><?php echo $user['last_message_time'] ? date('h:i A', strtotime($user['last_message_time'])) : ''; ?></span>
                      <?php if ($user['unread_count'] > 0): ?>
                        <span class="unread-badge"><?php echo $user['unread_count']; ?></span>
                      <?php endif; ?>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="empty-users" style="padding: 20px; text-align: center; color: #999;">No staff found.</div>
              <?php endif; ?>
              <div id="noSearchResults" class="empty-users" style="display:none; padding: 20px; text-align: center; color: #999;">No matching staff.</div>
            </div>
          </div>

          <div class="chat-body-area">
            <?php if ($current_chat_id > 0 && $current_chat_user): ?>
              <div class="active-chat-header">
                <div class="active-user-info">
                  <div class="active-avatar">
                    <?php if (!empty($current_chat_user['profile_image'])): ?>
                      <img src="../uploads/<?php echo htmlspecialchars($current_chat_user['profile_image']); ?>" alt="Avatar">
                    <?php else: ?>
                      <div class="avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                  </div>
                  <div>
                    <h3 class="active-name"><?php echo htmlspecialchars($current_chat_user['username']); ?></h3>
                    <span class="active-status <?php echo $current_chat_user['is_online'] ? 'is-online' : 'is-offline'; ?>" id="chatHeaderStatus">
                      <?php echo $current_chat_user['is_online'] ? 'Online' : 'Offline'; ?>
                    </span>
                  </div>
                </div>
                <a href="messages.php" class="close-chat-btn"><i class="fa-solid fa-xmark"></i></a>
              </div>

              <div id="chatMessages" class="messages-stream">
                <?php if (count($chat_messages) > 0): ?>
                  <?php $lastDay = ''; ?>
                  <?php foreach ($chat_messages as $msg): ?>
                    <?php
                    $isMe = ($msg['sender_id'] == $user_id);
                    // Divider ng petsa kapag nagbago ang araw (Today / Yesterday / Oct 8, 2026)
                    $msgDay = date('Y-m-d', strtotime($msg['created_at']));
                    if ($msgDay !== $lastDay):
                      $lastDay = $msgDay;
                      $dayLabel = $msgDay === date('Y-m-d') ? 'Today'
                        : ($msgDay === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('M j, Y', strtotime($msgDay)));
                    ?>
                      <div class="msg-day" data-day="<?php echo $msgDay; ?>"><span><?php echo $dayLabel; ?></span></div>
                    <?php endif; ?>
                    <div class="msg-wrapper <?php echo $isMe ? 'sent' : 'received'; ?>" data-message-id="<?php echo $msg['message_id']; ?>">
                      <?php if (!$isMe): ?>
                        <div class="msg-avatar">
                          <?php if (!empty($msg['sender_image'])): ?>
                            <img src="../uploads/<?php echo htmlspecialchars($msg['sender_image']); ?>" alt="Sender">
                          <?php else: ?>
                            <div class="avatar-placeholder-sm"><i class="fa-solid fa-user"></i></div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      
                      <div class="msg-content-block">
                        <div class="msg-bubble">
                          <button type="button" class="msg-menu-btn" onclick='openDeleteModal(<?php echo (int)$msg["message_id"]; ?>, <?php echo $isMe ? "true" : "false"; ?>, event)' title="More">
                            <i class="fa-solid fa-ellipsis-vertical"></i>
                          </button>

                          <?php if (!empty($msg['is_deleted'])): ?>
                            <p class="unsent-text"><i class="fa-solid fa-ban"></i> <?php echo $isMe ? 'You unsent a message' : 'This message was unsent'; ?></p>
                          <?php else: ?>
                            <?php if (!empty($msg['message'])): ?>
                              <p><?php echo sp_message_html((string) $msg['message'], 'staff'); ?></p>
                            <?php endif; ?>

                            <?php if ($msg['message_type'] === 'image' && !empty($msg['attachment_url'])): ?>
                              <div class="attachment-preview">
                                <a href="../uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" target="_blank">
                                  <img src="../uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" alt="Attachment">
                                </a>
                                <div class="attachment-actions">
                                  <button type="button" class="icon-action-btn" title="Share to staff" onclick='openForwardModal(<?php echo json_encode($msg["attachment_url"]); ?>, "image", event)'><i class="fa-solid fa-share"></i></button>
                                  <button type="button" class="icon-action-btn" title="Download" onclick='downloadAttachment("../uploads/<?php echo htmlspecialchars($msg["attachment_url"]); ?>", <?php echo json_encode($msg["attachment_url"]); ?>)'><i class="fa-solid fa-download"></i></button>
                                </div>
                              </div>
                            <?php elseif ($msg['message_type'] === 'file' && !empty($msg['attachment_url'])): ?>
                              <div class="attachment-file">
                                <a href="../uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" target="_blank">
                                  <i class="fa-solid fa-file-pdf"></i> View Attachment
                                </a>
                                <div class="attachment-actions">
                                  <button type="button" class="icon-action-btn" title="Share to staff" onclick='openForwardModal(<?php echo json_encode($msg["attachment_url"]); ?>, "file", event)'><i class="fa-solid fa-share"></i></button>
                                  <button type="button" class="icon-action-btn" title="Download" onclick='downloadAttachment("../uploads/<?php echo htmlspecialchars($msg["attachment_url"]); ?>", <?php echo json_encode($msg["attachment_url"]); ?>)'><i class="fa-solid fa-download"></i></button>
                                </div>
                              </div>
                            <?php endif; ?>
                          <?php endif; ?>
                        </div>
                        <span class="msg-time-stamp"><?php echo date('h:i A', strtotime($msg['created_at'])); ?></span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="empty-chat-placeholder">
                    <i class="fa-regular fa-paper-plane"></i>
                    <p>No messages yet</p>
                    <span>Send a message to start the conversation.</span>
                  </div>
                <?php endif; ?>
              </div>

              <div class="chat-input-area">
                <div id="fileIndicator">
                  <span id="fileName">File Attached</span>
                  <i class="fa-solid fa-xmark" id="removeFile"></i>
                </div>
                <form id="messageForm" enctype="multipart/form-data">
                  <input type="hidden" name="receiver_id" value="<?php echo $current_chat_id; ?>">
                  <input type="hidden" name="send_message" value="1">
                  
                  <label for="fileInput" class="attach-btn" title="Attach file">
                    <i class="fa-solid fa-paperclip"></i>
                  </label>
                  <input type="file" id="fileInput" name="attachment" style="display: none;" accept="image/*,.pdf,.doc,.docx">

                  <div class="input-wrapper">
                    <textarea name="message" id="messageInput" rows="1" placeholder="Type a message or paste an image..."></textarea>
                  </div>
                  
                  <button type="submit" class="send-btn">
                    <i class="fa-solid fa-paper-plane"></i>
                  </button>
                </form>
              </div>

            <?php else: ?>
              <div class="no-active-chat">
                <div class="nac-icon" aria-hidden="true"><i class="fa-solid fa-comments"></i></div>
                <h3>Select a conversation</h3>
                <p>Choose a staff member from the list to view your chat or send a new message.</p>
                <ul class="nac-tips">
                  <li><i class="fa-solid fa-magnifying-glass"></i> Search by name to find a conversation fast</li>
                  <li><i class="fa-solid fa-paperclip"></i> Attach images or documents, or paste an image</li>
                  <li><i class="fa-solid fa-circle"></i> A green dot means the user is online</li>
                </ul>
              </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- Delete Message Modal -->
        <div id="deleteModal" class="chat-modal-overlay" style="display:none;">
          <div class="chat-modal-box">
            <h3>Delete message</h3>
            <p>Choose how you want to delete this message.</p>
            <div class="chat-modal-actions">
              <button type="button" class="modal-btn danger" id="deleteForEveryoneBtn" style="display:none;">Delete for Everyone</button>
              <button type="button" class="modal-btn" id="deleteForMeBtn">Delete for Me</button>
              <button type="button" class="modal-btn cancel" id="cancelDeleteBtn">Cancel</button>
            </div>
          </div>
        </div>

        <!-- Forward / Share to Staff Modal -->
        <div id="forwardModal" class="chat-modal-overlay" style="display:none;">
          <div class="chat-modal-box forward-box">
            <h3>Share to Staff</h3>
            <input type="text" id="forwardSearchInput" placeholder="Search staff..." autocomplete="off">
            <div id="forwardStaffList" class="forward-staff-list">
              <?php foreach ($chat_users as $su): ?>
                <label class="forward-staff-item" data-username="<?php echo htmlspecialchars(mb_strtolower($su['username'])); ?>">
                  <input type="radio" name="forwardTarget" value="<?php echo (int)$su['user_id']; ?>">
                  <div class="user-avatar-wrapper" style="width:32px;height:32px;">
                    <?php if (!empty($su['profile_image'])): ?>
                      <img src="../uploads/<?php echo htmlspecialchars($su['profile_image']); ?>" alt="">
                    <?php else: ?>
                      <div class="avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                    <?php endif; ?>
                  </div>
                  <span><?php echo htmlspecialchars($su['username']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <input type="text" id="forwardCaptionInput" placeholder="Add a caption (optional)">
            <div class="chat-modal-actions">
              <button type="button" class="modal-btn" id="confirmForwardBtn">Send</button>
              <button type="button" class="modal-btn cancel" id="cancelForwardBtn">Cancel</button>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="script.js"></script>
  <script>
    const chatMessages = document.getElementById('chatMessages');
    const fileInput = document.getElementById('fileInput');
    const fileIndicator = document.getElementById('fileIndicator');
    const fileName = document.getElementById('fileName');
    const removeFile = document.getElementById('removeFile');
    const messageInput = document.getElementById('messageInput');
    const messageForm = document.getElementById('messageForm');

    function scrollToBottom() {
      if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
      }
    }
    window.addEventListener('load', scrollToBottom);

    // File Indicator Handlers
    fileInput?.addEventListener('change', function() {
      if (this.files.length > 0) {
        fileName.textContent = this.files[0].name;
        fileIndicator.style.display = 'flex';
      }
    });

    removeFile?.addEventListener('click', function() {
      fileInput.value = '';
      fileIndicator.style.display = 'none';
    });

    // Paste Image Handler
    messageInput?.addEventListener('paste', function(e) {
      const items = (e.clipboardData || e.originalEvent.clipboardData).items;
      for (let item of items) {
        if (item.type.indexOf('image') !== -1) {
          const file = item.getAsFile();
          const dataTransfer = new DataTransfer();
          dataTransfer.items.add(file);
          fileInput.files = dataTransfer.files;

          fileName.textContent = 'Pasted Image (' + file.name + ')';
          fileIndicator.style.display = 'flex';
          break;
        }
      }
    });

    // Handle Enter Key Submission
    messageInput?.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        messageForm?.requestSubmit();
      }
    });

    // Form Submission Handler
    messageForm?.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      if (!messageInput.value.trim() && fileInput.files.length === 0) return;

      fetch('messages.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          messageInput.value = '';
          fileInput.value = '';
          fileIndicator.style.display = 'none';
          
          // Tawagin ang pollMessages para lumabas agad ang chat sa screen nang hindi nag-rerefresh
          pollMessages();
        } else {
          alert(data.error || 'Failed to send message');
        }
      })
      .catch(error => console.error('Error sending message:', error));
    });

    // Polling Mechanism
    let lastMessageId = <?php echo count($chat_messages) > 0 ? end($chat_messages)['message_id'] : 0; ?>;
    const currentChatId = <?php echo $current_chat_id; ?>;
    const currentUserId = <?php echo (int)$user_id; ?>;

    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    // Kapareho ng sp_message_html(..., 'staff') sa PHP: napipindot ang mga link,
    // chip ang button papunta sa page ng adopter, at napipindot ang Google Maps.
    function spMessageHtml(text) {
      const BUTTON = /\[\[([^|\]\r\n]{1,80})\|([^\]\s]{1,300})\]\]/g;
      const linkify = s => escapeHtml(s)
        .replace(/\bhttps?:\/\/[^\s<>]+?(?=[.,);:!?]*(?:\s|$|&lt;|&quot;|&#039;))/gi, url =>
          `<a class="msg-link" href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>`)
        .replace(/\n/g, '<br>');
      let html = '', last = 0, m;
      while ((m = BUTTON.exec(text)) !== null) {
        html += linkify(text.slice(last, m.index));
        const label = escapeHtml(m[1].trim());
        if (m[2].indexOf('https://www.google.com/maps/') === 0) {
          html += `<a class="msg-action-btn" href="${escapeHtml(m[2])}" target="_blank" rel="noopener">${label}</a>`;
        } else {
          html += `<span class="msg-action-chip" title="This button appears in the adopter's chat"><i class="fa-solid fa-hand-pointer" aria-hidden="true"></i> ${label}</span>`;
        }
        last = BUTTON.lastIndex;
      }
      return html + linkify(text.slice(last));
    }

    function pollMessages() {
      if (currentChatId > 0) {
        fetch(`messages.php?poll=1&chat=${currentChatId}&last_id=${lastMessageId}`)
          .then(response => response.json())
          .then(data => {
            if (data.messages && data.messages.length > 0) {
              data.messages.forEach(msg => {
                if (!document.querySelector(`[data-message-id="${msg.message_id}"]`)) {
                  const isMe = msg.sender_id == currentUserId;
                  // Divider na "Today" kapag ito ang unang mensahe ngayong araw
                  const msgDay = (msg.created_at || '').slice(0, 10);
                  const dayEls = chatMessages.querySelectorAll('.msg-day');
                  const prevDay = dayEls.length ? dayEls[dayEls.length - 1].dataset.day : '';
                  if (msgDay && msgDay !== prevDay) {
                    const sep = document.createElement('div');
                    sep.className = 'msg-day';
                    sep.dataset.day = msgDay;
                    sep.innerHTML = '<span>Today</span>';
                    chatMessages.appendChild(sep);
                  }
                  const messageDiv = document.createElement('div');
                  messageDiv.className = `msg-wrapper ${isMe ? 'sent' : 'received'}`;
                  messageDiv.setAttribute('data-message-id', msg.message_id);

                  let contentHTML = '';
                  if (msg.is_deleted == 1 || msg.is_deleted === true) {
                    contentHTML = `<p class="unsent-text"><i class="fa-solid fa-ban"></i> ${isMe ? 'You unsent a message' : 'This message was unsent'}</p>`;
                  } else {
                    let attachmentHTML = '';
                    if (msg.message_type === 'image' && msg.attachment_url) {
                      attachmentHTML = `<div class="attachment-preview">
                          <a href="../uploads/${escapeHtml(msg.attachment_url)}" target="_blank"><img src="../uploads/${escapeHtml(msg.attachment_url)}"></a>
                          <div class="attachment-actions">
                            <button type="button" class="icon-action-btn" title="Share to staff" onclick='openForwardModal(${JSON.stringify(msg.attachment_url)}, "image", event)'><i class="fa-solid fa-share"></i></button>
                            <button type="button" class="icon-action-btn" title="Download" onclick='downloadAttachment("../uploads/${escapeHtml(msg.attachment_url)}", ${JSON.stringify(msg.attachment_url)})'><i class="fa-solid fa-download"></i></button>
                          </div>
                        </div>`;
                    } else if (msg.message_type === 'file' && msg.attachment_url) {
                      attachmentHTML = `<div class="attachment-file">
                          <a href="../uploads/${escapeHtml(msg.attachment_url)}" target="_blank"><i class="fa-solid fa-file-pdf"></i> View Attachment</a>
                          <div class="attachment-actions">
                            <button type="button" class="icon-action-btn" title="Share to staff" onclick='openForwardModal(${JSON.stringify(msg.attachment_url)}, "file", event)'><i class="fa-solid fa-share"></i></button>
                            <button type="button" class="icon-action-btn" title="Download" onclick='downloadAttachment("../uploads/${escapeHtml(msg.attachment_url)}", ${JSON.stringify(msg.attachment_url)})'><i class="fa-solid fa-download"></i></button>
                          </div>
                        </div>`;
                    }
                    contentHTML = `
                      ${msg.message ? `<p>${spMessageHtml(msg.message)}</p>` : ''}
                      ${attachmentHTML}
                    `;
                  }

                  messageDiv.innerHTML = `
                    ${!isMe ? `<div class="msg-avatar">${msg.sender_image ? `<img src="../uploads/${escapeHtml(msg.sender_image)}">` : '<div class="avatar-placeholder-sm"><i class="fa-solid fa-user"></i></div>'}</div>` : ''}
                    <div class="msg-content-block">
                      <div class="msg-bubble">
                        <button type="button" class="msg-menu-btn" onclick='openDeleteModal(${msg.message_id}, ${isMe}, event)' title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                        ${contentHTML}
                      </div>
                      <span class="msg-time-stamp">${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                    </div>
                  `;
                  chatMessages.appendChild(messageDiv);
                  if (msg.message_id > lastMessageId) lastMessageId = msg.message_id;
                }
              });
              scrollToBottom();
            }

            // I-update ang online/offline status ng kausap
            if (typeof data.partner_online !== 'undefined') {
              const statusEl = document.getElementById('chatHeaderStatus');
              if (statusEl) {
                statusEl.classList.toggle('is-online', data.partner_online);
                statusEl.classList.toggle('is-offline', !data.partner_online);
                statusEl.textContent = data.partner_online ? 'Online' : 'Offline';
              }
              const dotEl = document.querySelector(`.presence-dot[data-presence-for="${currentChatId}"]`);
              if (dotEl) {
                dotEl.classList.toggle('online', data.partner_online);
                dotEl.classList.toggle('offline', !data.partner_online);
              }
            }
          })
          .catch(error => console.error('Polling error:', error));
      }
    }

    if (currentChatId > 0) {
      setInterval(pollMessages, 3000);
    }

    // ===== Staff Search Bar (sidebar) =====
    const staffSearchInput = document.getElementById('staffSearchInput');
    const chatUsersList = document.getElementById('chatUsersList');
    const noSearchResults = document.getElementById('noSearchResults');

    staffSearchInput?.addEventListener('input', function() {
      const term = this.value.trim().toLowerCase();
      const cards = chatUsersList.querySelectorAll('.user-item-card');
      let visibleCount = 0;

      cards.forEach(card => {
        const uname = card.getAttribute('data-username') || '';
        const match = uname.includes(term);
        card.style.display = match ? '' : 'none';
        if (match) visibleCount++;
      });

      if (noSearchResults) {
        noSearchResults.style.display = (visibleCount === 0 && term !== '') ? 'block' : 'none';
      }
    });

    // ===== Delete Message Modal =====
    const deleteModal = document.getElementById('deleteModal');
    const deleteForEveryoneBtn = document.getElementById('deleteForEveryoneBtn');
    const deleteForMeBtn = document.getElementById('deleteForMeBtn');
    const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    let deleteTargetId = null;

    function openDeleteModal(messageId, isMe, event) {
      event?.stopPropagation();
      deleteTargetId = messageId;
      deleteForEveryoneBtn.style.display = isMe ? 'block' : 'none';
      deleteModal.style.display = 'flex';
    }

    cancelDeleteBtn?.addEventListener('click', () => {
      deleteModal.style.display = 'none';
      deleteTargetId = null;
    });

    deleteModal?.addEventListener('click', (e) => {
      if (e.target === deleteModal) {
        deleteModal.style.display = 'none';
        deleteTargetId = null;
      }
    });

    function sendDelete(deleteType) {
      if (!deleteTargetId) return;
      const formData = new FormData();
      formData.append('delete_message', '1');
      formData.append('message_id', deleteTargetId);
      formData.append('delete_type', deleteType);

      fetch('messages.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            const el = document.querySelector(`[data-message-id="${data.message_id}"] .msg-bubble`);
            if (el) {
              const isSent = el.closest('.msg-wrapper').classList.contains('sent');
              el.innerHTML = `<button type="button" class="msg-menu-btn" style="display:none;"><i class="fa-solid fa-ellipsis-vertical"></i></button><p class="unsent-text"><i class="fa-solid fa-ban"></i> ${isSent ? 'You unsent a message' : 'This message was unsent'}</p>`;
            }
          } else {
            alert(data.error || 'Failed to delete message');
          }
          deleteModal.style.display = 'none';
          deleteTargetId = null;
        })
        .catch(err => {
          console.error('Delete error:', err);
          deleteModal.style.display = 'none';
        });
    }

    deleteForEveryoneBtn?.addEventListener('click', () => sendDelete('everyone'));
    deleteForMeBtn?.addEventListener('click', () => sendDelete('me'));

    // ===== Forward / Share to Staff Modal =====
    const forwardModal = document.getElementById('forwardModal');
    const forwardSearchInput = document.getElementById('forwardSearchInput');
    const forwardStaffList = document.getElementById('forwardStaffList');
    const forwardCaptionInput = document.getElementById('forwardCaptionInput');
    const confirmForwardBtn = document.getElementById('confirmForwardBtn');
    const cancelForwardBtn = document.getElementById('cancelForwardBtn');
    let forwardAttachmentUrl = null;
    let forwardMessageType = null;

    function openForwardModal(attachmentUrl, messageType, event) {
      event?.stopPropagation();
      forwardAttachmentUrl = attachmentUrl;
      forwardMessageType = messageType;
      forwardCaptionInput.value = '';
      forwardSearchInput.value = '';
      forwardStaffList.querySelectorAll('.forward-staff-item').forEach(item => item.style.display = '');
      const radios = forwardStaffList.querySelectorAll('input[name="forwardTarget"]');
      radios.forEach(r => r.checked = false);
      forwardModal.style.display = 'flex';
    }

    cancelForwardBtn?.addEventListener('click', () => {
      forwardModal.style.display = 'none';
    });

    forwardModal?.addEventListener('click', (e) => {
      if (e.target === forwardModal) forwardModal.style.display = 'none';
    });

    forwardSearchInput?.addEventListener('input', function() {
      const term = this.value.trim().toLowerCase();
      forwardStaffList.querySelectorAll('.forward-staff-item').forEach(item => {
        const uname = item.getAttribute('data-username') || '';
        item.style.display = uname.includes(term) ? '' : 'none';
      });
    });

    confirmForwardBtn?.addEventListener('click', () => {
      const selected = forwardStaffList.querySelector('input[name="forwardTarget"]:checked');
      if (!selected) {
        alert('Please select a staff to share with.');
        return;
      }
      if (!forwardAttachmentUrl || !forwardMessageType) return;

      const formData = new FormData();
      formData.append('forward_message', '1');
      formData.append('receiver_id', selected.value);
      formData.append('attachment_url', forwardAttachmentUrl);
      formData.append('message_type', forwardMessageType);
      formData.append('message', forwardCaptionInput.value.trim());

      fetch('messages.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            forwardModal.style.display = 'none';
            if (selected.value == currentChatId) {
              pollMessages();
            }
          } else {
            alert(data.error || 'Failed to share attachment');
          }
        })
        .catch(err => console.error('Forward error:', err));
    });

    // ===== Download Attachment =====
    function downloadAttachment(url, filename) {
      const link = document.createElement('a');
      link.href = url;
      link.download = filename || '';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }
  </script>
    <?php include '../connection/chat_bubble.php'; ?>
</body>
</html>