<?php
session_start();
require '../connection/database.php';

// Siguraduhing naka-login ang adopter bago ma-access ang pahina
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Presence heartbeat: i-update ang huling activity ng adopter sa bawat request
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

$username = $user['username'] ?? 'User';
$profile_image = $user['profile_image'] ?? '';

// Handle message and file sending via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    header('Content-Type: application/json');
    
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $message_type = 'text';
    $attachment_url = NULL;

    try {
        // Siguraduhing staff lang ang pwedeng makontak ng adopter
        $role_stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND LOWER(role) = 'staff'");
        $role_stmt->execute([$receiver_id]);
        if (!$role_stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'You can only message staff accounts.']);
            exit();
        }

        // Handle File / Image Upload
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $file_name = $file['name'];
            $file_tmp = $file['tmp_name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Allowable formats
            $allowed_img = ['jpg', 'jpeg', 'png', 'gif'];
            $allowed_doc = ['pdf', 'doc', 'docx'];

            if (in_array($file_ext, $allowed_img)) {
                $message_type = 'image';
            } elseif (in_array($file_ext, $allowed_doc)) {
                $message_type = 'file';
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid file type']);
                exit();
            }

            // Generate unique filename and move to uploads folder
            $new_filename = uniqid('msg_') . '.' . $file_ext;
            $upload_path = '../uploads/' . $new_filename;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $attachment_url = $new_filename;
            }
        }

        // Find or create conversation
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

        // Save message with attachment
        $send_stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_type, message, attachment_url, is_read) VALUES (?, ?, ?, ?, ?, 0)");
        $send_stmt->execute([$conversation_id, $user_id, $message_type, $message, $attachment_url]);

        $inserted_id = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'message_id' => $inserted_id]);
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

        // Kunin ang mensahe kasama ang conversation nito, at siguraduhing kabilang dito ang adopter na ito
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
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['poll']) && isset($_GET['last_time']) && isset($_GET['last_id'])) {
    $last_time = $_GET['last_time'];
    $last_id = intval($_GET['last_id']);
    $chat_id = isset($_GET['chat']) ? intval($_GET['chat']) : 0;
    
    if ($chat_id > 0) {
        $role_stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND LOWER(role) = 'staff'");
        $role_stmt->execute([$chat_id]);
        $is_staff = $role_stmt->fetch();

        $conv_stmt = $pdo->prepare("
            SELECT conversation_id 
            FROM conversations 
            WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)
        ");
        $conv_stmt->execute([$user_id, $chat_id, $chat_id, $user_id]);
        $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conversation && $is_staff) {
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

// Get current chat partner
$current_chat_id = isset($_GET['chat']) ? intval($_GET['chat']) : 0;

// Payagan lang ang pag-chat sa staff accounts
if ($current_chat_id > 0) {
    $role_check = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND LOWER(role) = 'staff'");
    $role_check->execute([$current_chat_id]);
    if (!$role_check->fetch()) {
        $current_chat_id = 0;
    }
}

// Mark messages as read when opening chat
if ($current_chat_id > 0) {
    // 1. Hanapin ang conversation ID sa magkabilang direksyon
    $conv_stmt = $pdo->prepare("
        SELECT conversation_id 
        FROM conversations 
        WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)
    ");
    $conv_stmt->execute([$user_id, $current_chat_id, $current_chat_id, $user_id]);
    $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);

    // 2. I-update ang lahat ng unread messages sa conversation na iyon mula sa kabilang user
    if ($conversation) {
        $mark_read = $pdo->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? 
            AND sender_id = ? 
            AND is_read = 0
        ");
        $mark_read->execute([$conversation['conversation_id'], $current_chat_id]);
    }
}

// Fetch staff users lang para sa direct chat (adopters can only contact staff)
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

// Fetch chat messages for current conversation
$chat_messages = [];
$current_chat_user = null;

if ($current_chat_id > 0) {
    // Get conversation ID
    $conv_stmt = $pdo->prepare("
        SELECT conversation_id 
        FROM conversations 
        WHERE (user_id = ? AND admin_id = ?) OR (user_id = ? AND admin_id = ?)
    ");
    $conv_stmt->execute([$user_id, $current_chat_id, $current_chat_id, $user_id]);
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
    // Unread Count Query
    $unread_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.conversation_id
        WHERE ((c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?))
        AND m.sender_id != ? 
        AND m.is_read = 0
    ");
    $unread_stmt->execute([$user_id, $user['user_id'], $user['user_id'], $user_id, $user_id]);
    $user['unread_count'] = $unread_stmt->fetchColumn();
    
    // Get last message preview
    $last_msg_stmt = $pdo->prepare("
        SELECT m.message, m.created_at 
        FROM messages m
        INNER JOIN conversations c ON m.conversation_id = c.conversation_id
        WHERE (c.user_id = ? AND c.admin_id = ?) OR (c.user_id = ? AND c.admin_id = ?)
        ORDER BY m.message_id DESC LIMIT 1
    ");
    $last_msg_stmt->execute([$user_id, $user['user_id'], $user['user_id'], $user_id]);
    $last_msg = $last_msg_stmt->fetch(PDO::FETCH_ASSOC);
    $user['last_message'] = $last_msg['message'] ?? '';
    $user['last_message_time'] = $last_msg['created_at'] ?? '';
}
unset($user); // Break reference

// Count total unread messages
$total_unread = array_sum(array_column($chat_users, 'unread_count'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMART PET - Messenger</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo @filemtime(__DIR__ . '/dashboard.css'); ?>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --pet-green: #1b4d2e;
            --pet-green-light: #2d6a45;
            --pet-orange: #ff8c00;
            --pet-orange-dark: #e67600;
            --panel-bg: #f4f1ea;
            --bubble-received: #ffffff;
            --text-dark: #2b2b2b;
            --text-muted: #8a8a8a;
        }

        /* Messenger Shell */
        .messenger-container {
            display: flex;
            height: calc(100vh - 80px);
            background: var(--panel-bg);
            border-radius: 20px;
            overflow: hidden;
            margin: 20px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
            animation: fadeUpIn 0.5s ease-out both;
        }

        @keyframes fadeUpIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .chat-list-items > .chat-item { animation: fadeUpIn 0.4s ease-out both; }
        .chat-list-items > .chat-item:nth-child(1) { animation-delay: 0.12s; }
        .chat-list-items > .chat-item:nth-child(2) { animation-delay: 0.17s; }
        .chat-list-items > .chat-item:nth-child(3) { animation-delay: 0.22s; }
        .chat-list-items > .chat-item:nth-child(4) { animation-delay: 0.27s; }
        .chat-list-items > .chat-item:nth-child(5) { animation-delay: 0.32s; }
        .chat-list-items > .chat-item:nth-child(n+6) { animation-delay: 0.37s; }

        /* Chat List Sidebar */
        .chat-list {
            width: 340px;
            background: #ffffff;
            border-right: 1px solid rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
        }

        .chat-list-header {
            padding: 22px 20px 14px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }

        .chat-list-title {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .chat-list-header h2 {
            color: var(--pet-green);
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.2px;
        }

        .chat-list-header p {
            color: var(--text-muted);
            font-size: 12.5px;
            margin-top: 2px;
        }

        .chat-list-unread {
            flex-shrink: 0;
            margin-top: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #fff1e0;
            color: var(--pet-orange-dark);
            font-size: 11.5px;
            font-weight: 700;
        }

        /* Search box ng listahan */
        .chat-search {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding: 0 12px;
            height: 38px;
            border-radius: 10px;
            background: #f4f6f5;
            border: 1.5px solid transparent;
            color: #8a978f;
            transition: border-color .2s ease, background-color .2s ease, box-shadow .2s ease;
        }

        .chat-search:focus-within {
            background: #fff;
            border-color: var(--pet-green-light);
            box-shadow: 0 0 0 3px rgba(45, 106, 69, 0.12);
        }

        .chat-search i { font-size: 13px; }

        .chat-search input {
            flex: 1;
            min-width: 0;
            border: none;
            background: transparent;
            outline: none;
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text-dark);
        }

        .chat-list-items {
            flex: 1;
            overflow-y: auto;
            padding: 8px 10px;
        }

        .chat-item {
            position: relative;
            padding: 12px;
            margin-bottom: 4px;
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-item:hover {
            background: #f6f8f7;
        }

        /* Napiling usapan: malambot na orange na card na may guhit sa kaliwa */
        .chat-item.active {
            background: #fff4e8;
            box-shadow: inset 0 0 0 1px #fbdcb8;
        }

        .chat-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 14px;
            bottom: 14px;
            width: 3px;
            border-radius: 0 3px 3px 0;
            background: var(--pet-orange);
        }

        /* May hindi pa nababasa: makapal ang pangalan at preview */
        .chat-item.has-unread .chat-name { font-weight: 800; }
        .chat-item.has-unread .chat-preview { color: var(--text-dark); font-weight: 600; }
        .chat-item.has-unread .chat-time { color: var(--pet-orange-dark); font-weight: 700; }

        .chat-list-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        .chat-list-empty i {
            display: block;
            font-size: 30px;
            margin-bottom: 10px;
            color: #c9d3cd;
        }

        .chat-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            background: linear-gradient(135deg, var(--pet-green-light), var(--pet-green));
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            flex-shrink: 0;
        }

        .chat-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .chat-avatar i {
            color: rgba(255, 255, 255, 0.85);
            font-size: 18px;
        }

        /* Nakasulat na ang "Staff" sa tabi ng pangalan, kaya hindi na kailangan ang icon */
        .chat-list .role-badge { display: none; }

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
            color: #fff;
            border: 2px solid #fff;
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .chat-info {
            flex: 1;
            min-width: 0;
        }

        .chat-name {
            color: var(--text-dark);
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .chat-role {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.3px;
            padding: 2px 7px;
            border-radius: 8px;
            background: #e6f3ec;
            color: var(--pet-green);
        }

        /* Button sa loob ng mensahe ng staff (hal. View Available Schedule to Meet Pets) */
        .msg-action-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            margin: 8px 0 2px; padding: 9px 14px; border-radius: 20px;
            background: #f39c12; color: #fff !important; font-weight: 700; font-size: 13px;
            text-decoration: none !important; line-height: 1.3; text-align: center;
        }
        .msg-action-btn:hover { background: #d9860b; }
        .msg-action-label { font-weight: 700; }
        .chat-preview {
            color: var(--text-muted);
            font-size: 12.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-meta {
            text-align: right;
            flex-shrink: 0;
        }

        .chat-time {
            color: var(--text-muted);
            font-size: 11px;
            margin-bottom: 6px;
        }

        .unread-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: var(--pet-orange);
            color: white;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        /* Chat Area */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--panel-bg);
        }

        .chat-area.empty {
            align-items: center;
            justify-content: center;
        }

        /* Walang napiling usapan: may mahinang tuldok-tuldok na background */
        .chat-area.empty {
            background-color: var(--panel-bg);
            background-image: radial-gradient(rgba(27, 77, 46, 0.07) 1px, transparent 1px);
            background-size: 18px 18px;
        }

        .empty-state {
            max-width: 420px;
            padding: 24px;
            text-align: center;
            color: var(--text-muted);
            animation: fadeUpIn 0.5s ease-out 0.15s both;
        }

        .empty-illustration {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 22px;
        }

        .empty-ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: radial-gradient(circle, #ffe6c7 0%, rgba(255, 230, 199, 0) 70%);
            animation: emptyPulse 3s ease-in-out infinite;
        }

        .empty-icon {
            position: absolute;
            inset: 22px;
            border-radius: 24px;
            background: linear-gradient(145deg, #ffa53a, var(--pet-orange-dark));
            color: #fff;
            font-size: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 24px rgba(230, 118, 0, 0.3), inset 0 2px 0 rgba(255, 255, 255, 0.3);
            transform: rotate(-6deg);
        }

        .empty-paw {
            position: absolute;
            color: var(--pet-green-light);
            opacity: 0.35;
        }

        .empty-paw.p1 { top: 4px; right: -8px; font-size: 16px; transform: rotate(20deg); }
        .empty-paw.p2 { bottom: 6px; left: -12px; font-size: 12px; transform: rotate(-25deg); }

        @keyframes emptyPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.12); opacity: 0.6; }
        }

        .empty-state h3 {
            font-size: 21px;
            font-weight: 800;
            margin-bottom: 8px;
            color: var(--pet-green);
        }

        .empty-state > p {
            font-size: 14px;
            line-height: 1.55;
        }

        .empty-tips {
            list-style: none;
            margin: 22px auto 0;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            text-align: left;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-radius: 14px;
            font-size: 13px;
            color: #56635c;
        }

        .empty-tips li {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .empty-tips i {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: #e8f3ec;
            color: var(--pet-green-light);
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .empty-tips .fa-circle { color: #2ecc71; font-size: 8px; }

        @media (prefers-reduced-motion: reduce) {
            .empty-ring { animation: none; }
        }

        .chat-header {
            padding: 16px 24px;
            background: #ffffff;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .chat-header-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            background: linear-gradient(135deg, var(--pet-green-light), var(--pet-green));
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .chat-header-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .chat-header-avatar i {
            color: rgba(255, 255, 255, 0.85);
        }

        .chat-header-info {
            flex: 1;
        }

        .chat-header-name {
            color: var(--text-dark);
            font-weight: 700;
            font-size: 16px;
        }

        .chat-header-status {
            color: var(--text-muted);
            font-size: 12.5px;
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
            bottom: 0;
            right: 0;
            width: 13px;
            height: 13px;
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
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .message {
            display: flex;
            gap: 10px;
            max-width: 65%;
            animation: message-in 0.25s ease-out;
        }

        @keyframes message-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
            object-fit: cover;
            background: linear-gradient(135deg, var(--pet-green-light), var(--pet-green));
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .message-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .message-avatar i {
            color: rgba(255, 255, 255, 0.85);
            font-size: 12px;
        }

        .message-content {
            padding: 11px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.5;
            word-wrap: break-word;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .message-content p {
            margin: 0;
            word-break: break-word;
        }

        .message.sent .message-content {
            background: linear-gradient(135deg, var(--pet-orange), var(--pet-orange-dark));
            color: #fff;
            border-radius: 16px 16px 4px 16px;
        }

        .message.received .message-content {
            background: var(--bubble-received);
            color: var(--text-dark);
            border-radius: 16px 16px 16px 4px;
        }

        .message-content a {
            color: inherit;
        }

        .message-time {
            font-size: 10.5px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .message.sent .message-time {
            text-align: right;
        }

        .message-content {
            position: relative;
        }

        .msg-menu-btn {
            position: absolute;
            top: 2px;
            background: rgba(0, 0, 0, 0.15);
            border: none;
            color: inherit;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 11px;
            display: flex;
            align-items: center;
            transition: background-color 0.2s ease, opacity 0.2s ease;
            justify-content: center;
            opacity: 0.85;
        }

        .message.sent .msg-menu-btn {
            left: -26px;
        }

        .message.received .msg-menu-btn {
            right: -26px;
        }

        .msg-menu-btn:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.3);
        }

        .unsent-text {
            font-style: italic;
            opacity: 0.7;
        }

        .attachment-wrap {
            position: relative;
            display: inline-block;
            margin-top: 8px;
        }

        .attachment-wrap.file-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .download-btn {
            position: absolute;
            bottom: 6px;
            right: 6px;
            background: rgba(0, 0, 0, 0.55);
            color: #fff;
            border: none;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: background-color 0.2s ease;
        }

        .download-btn:hover {
            background: rgba(0, 0, 0, 0.8);
        }

        .file-wrap .download-btn {
            position: static;
            background: rgba(0, 0, 0, 0.1);
            color: inherit;
        }

        /* Delete confirmation modal */
        .delete-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .delete-modal-overlay.active {
            display: flex;
        }

        .delete-modal-box {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            width: 90%;
            max-width: 320px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .delete-modal-box h3 {
            margin: 0 0 14px 0;
            font-size: 16px;
            color: var(--text-dark);
        }

        .delete-modal-box button {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 8px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            text-align: left;
        }

        .delete-option-everyone {
            background: #fdecea;
            color: #e74c3c;
        }

        .delete-option-me {
            background: #f1f1f1;
            color: var(--text-dark);
        }

        .delete-option-cancel {
            background: none;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 0;
        }

        /* Image preview lightbox (opens in the same tab, no navigation) */
        .image-preview-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            z-index: 1100;
            align-items: center;
            justify-content: center;
        }

        .image-preview-overlay.active {
            display: flex;
        }

        .image-preview-overlay img {
            max-width: 90vw;
            max-height: 85vh;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }

        .image-preview-close {
            position: absolute;
            top: 20px;
            right: 24px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s ease;
        }

        .image-preview-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .image-preview-download {
            position: absolute;
            bottom: 24px;
            right: 24px;
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            border: none;
            padding: 10px 16px;
            border-radius: 24px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s ease;
        }

        .image-preview-download:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .attachment-wrap img {
            cursor: pointer;
        }

        .message-input-area {
            padding: 18px 24px 22px;
            background: #ffffff;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }

        .message-input-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            background: var(--panel-bg);
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 22px;
            padding: 13px 20px;
            color: var(--text-dark);
            font-size: 14px;
            resize: none;
            min-height: 46px;
            max-height: 120px;
            transition: border-color 0.2s ease;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--pet-orange);
        }

        .message-input::placeholder {
            color: var(--text-muted);
        }

        .send-btn {
            background: linear-gradient(135deg, var(--pet-orange), var(--pet-orange-dark));
            color: white;
            border: none;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease;
            flex-shrink: 0;
        }

        .send-btn:hover {
            transform: scale(1.06);
        }

        .send-btn:active {
            transform: scale(0.94);
        }

        .attachment-btn {
            background: var(--panel-bg);
            color: var(--text-muted);
            border: 1px solid rgba(0, 0, 0, 0.08);
            width: 46px;
            height: 46px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .attachment-btn:hover {
            background: #eee;
            color: var(--text-dark);
        }

        /* Scrollbar Styling */
        .chat-list-items::-webkit-scrollbar,
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }

        .chat-list-items::-webkit-scrollbar-track,
        .chat-messages::-webkit-scrollbar-track {
            background: transparent;
        }

        .chat-list-items::-webkit-scrollbar-thumb,
        .chat-messages::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.15);
            border-radius: 3px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .messenger-container {
                margin: 10px;
                height: calc(100vh - 60px);
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

            .message {
                max-width: 85%;
            }
        }

        /* Mga button sa header ng usapan */
        .chat-back-btn { display: none; }
        .chat-exit-btn, .chat-back-btn {
            width: 38px; height: 38px; flex-shrink: 0; border: none; border-radius: 50%;
            align-items: center; justify-content: center;
            background: #f1f4f2; color: #1f2933; font-size: 17px; cursor: pointer;
            transition: background 0.15s ease;
        }
        .chat-exit-btn { display: flex; margin-left: auto; }
        .chat-exit-btn:hover, .chat-back-btn:hover { background: #e3e9e5; }
        .chat-exit-btn:focus-visible, .chat-back-btn:focus-visible { outline: 3px solid #8fd3ae; outline-offset: 2px; }

        /* ===== Telepono: buong screen ang bukas na usapan (gaya ng AI Recommendation) =====
           Natatakpan ang mobile bar; ← = balik sa listahan, × = buksan ang sidebar */
        @media (max-width: 900px) {
            /* May animation (transform) ang container - kapag naiwan, magiging "kahon" nito
               ang position: fixed sa ibaba imbes na ang buong screen */
            body.sp-chat-open .messenger-container { animation: none; transform: none; }
            body.sp-chat-open .chat-area.active {
                display: flex;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                width: auto; height: auto;
                z-index: 9996;
                border-radius: 0;
                background: #fff;
            }
            body.sp-chat-open .chat-list { display: none; }
            body.sp-chat-open .chat-back-btn { display: flex; }
            /* Walang lumulutang na AI bubble sa ibabaw ng usapan */
            body.sp-chat-open #spChatToggle { display: none !important; }
            body.sp-chat-open { overflow: hidden; }

            body.sp-chat-open .chat-header { padding: 10px 12px; gap: 10px; padding-top: calc(10px + env(safe-area-inset-top)); }
            body.sp-chat-open .chat-header-avatar { width: 38px; height: 38px; }
            body.sp-chat-open .chat-messages { padding: 14px 12px; }
            body.sp-chat-open .message-input-area { padding-bottom: calc(10px + env(safe-area-inset-bottom)); }
            /* 16px: hindi nag-a-auto-zoom ang iPhone kapag tinapik ang input */
            body.sp-chat-open .message-input { font-size: 16px; }
        }
    </style>
</head>
<body style="background-color: #1b4d2e;"<?php echo ($current_chat_id > 0 && $current_chat_user) ? ' class="sp-chat-open"' : ''; ?>>

    <div class="dashboard-container">
        
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="brand">
                <div class="logo-circle">
                    <img src="../Images/caloocan_logo.jpg" alt="City Logo">
                </div>
                <h2>SMART <span>PET</span></h2>
            </div>

            <nav class="nav-menu">
                <a href="adopter_dashboard.php" class="nav-item"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="adopter_preferences.php" class="nav-item"><i class="fa-solid fa-clipboard-list"></i> My preference</a>
                <a href="ai_recommendation.php" class="nav-item"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Recommendation</a>
                <a href="my_pets.php" class="nav-item"><i class="fa-solid fa-heart"></i> My pets</a>
                <a href="favorites.php" class="nav-item"><i class="fa-solid fa-star"></i> Favorites</a>
                <a href="messages.php" class="nav-item active"><i class="fa-solid fa-comment-dots"></i> Message<?php if ($total_unread > 0): ?><span class="sp-unread-badge"><?php echo $total_unread > 99 ? '99+' : $total_unread; ?></span><?php endif; ?></a>
                <?php $sp_notif_count = sp_unread_notification_count($pdo, (int) $_SESSION['user_id']); ?>
                <a href="payments.php" class="nav-item"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
            </nav>

            <div class="sidebar-illustration">
                <img src="../Images/bg_design_pets.png" alt="Pets Illustration" onerror="this.style.display='none'">
            </div>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            
            <!-- TOP NAVBAR -->
            <header class="top-navbar">
                <div class="top-nav-links">
                    <a href="search_pets.php">Search Pets</a>
                    <a href="shelter.php">Location</a>
                    <a href="docs.php">Certificate</a>
                </div>
                <a href="edit_profile.php" class="user-profile-link" style="text-decoration: none; color: inherit;">
                    <div class="user-profile">
                        <div class="profile-avatar" style="overflow: hidden; display: flex; align-items: center; justify-content: center;">
                            <?php if (!empty($profile_image)): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <i class="fa-solid fa-user" style="color: #666; font-size: 20px;"></i>
                            <?php endif; ?>
                        </div>
                        <span><?php echo htmlspecialchars($username); ?></span>
                    </div>
                </a>
            </header>

            <!-- MESSENGER SECTION -->
            <section class="messenger-container">
                
                <!-- Chat List -->
                <div class="chat-list" id="chatList">
                    <div class="chat-list-header">
                        <div class="chat-list-title">
                            <div>
                                <h2>Messages</h2>
                                <p>Chat with our shelter staff</p>
                            </div>
                            <?php if ($total_unread > 0): ?>
                                <span class="chat-list-unread"><?php echo $total_unread > 99 ? '99+' : (int) $total_unread; ?> new</span>
                            <?php endif; ?>
                        </div>
                        <?php if (count($chat_users) > 0): ?>
                        <!-- Hanapin ang staff sa listahan -->
                        <label class="chat-search">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input type="search" id="chatSearch" placeholder="Search staff" aria-label="Search staff" autocomplete="off">
                        </label>
                        <?php endif; ?>
                    </div>

                    <div class="chat-list-items">
                        <?php if (count($chat_users) > 0): ?>
                            <?php foreach ($chat_users as $user): ?>
                                <div class="chat-item <?php echo $current_chat_id == $user['user_id'] ? 'active' : ''; ?><?php echo $user['unread_count'] > 0 ? ' has-unread' : ''; ?>"
                                     data-name="<?php echo htmlspecialchars(mb_strtolower($user['username'])); ?>"
                                     onclick="openChat(<?php echo $user['user_id']; ?>)">
                                    <div class="chat-avatar">
                                        <?php if (!empty($user['profile_image'])): ?>
                                            <img src="../uploads/<?php echo htmlspecialchars($user['profile_image']); ?>" alt="<?php echo htmlspecialchars($user['username']); ?>">
                                        <?php else: ?>
                                            <i class="fa-solid fa-user"></i>
                                        <?php endif; ?>
                                        <div class="role-badge">
                                            <i class="fa-solid fa-headset"></i>
                                        </div>
                                        <span class="presence-dot <?php echo $user['is_online'] ? 'online' : 'offline'; ?>" data-presence-for="<?php echo (int)$user['user_id']; ?>"></span>
                                    </div>
                                    <div class="chat-info">
                                        <div class="chat-name">
                                            <?php echo htmlspecialchars($user['username']); ?>
                                            <span class="chat-role">Staff</span>
                                        </div>
                                        <?php
                                            $preview = $user['last_message'] !== '' ? sp_message_preview($user['last_message']) : 'No messages yet';
                                            $preview_short = mb_strlen($preview) > 60 ? mb_substr($preview, 0, 60) . '...' : $preview; // ang CSS ang magpuputol ayon sa lapad
                                        ?>
                                        <div class="chat-preview"><?php echo htmlspecialchars($preview_short); ?></div>
                                    </div>
                                    <div class="chat-meta">
                                        <div class="chat-time"><?php echo $user['last_message_time'] ? date('h:i A', strtotime($user['last_message_time'])) : ''; ?></div>
                                        <?php if ($user['unread_count'] > 0): ?>
                                            <div class="unread-badge"><?php echo $user['unread_count']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="chat-list-empty" id="chatSearchEmpty" hidden>
                                <i class="fa-solid fa-user-slash"></i>
                                <p>No staff match your search</p>
                            </div>
                        <?php else: ?>
                            <div class="chat-list-empty">
                                <i class="fa-solid fa-comments"></i>
                                <p>No staff available right now</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Chat Area -->
                <?php if ($current_chat_id > 0 && $current_chat_user): ?>
                    <div class="chat-area active" id="chatArea">
                        <div class="chat-header">
                            <?php // Telepono lang: bumalik sa listahan ng mga staff ?>
                            <button type="button" class="chat-back-btn" onclick="closeChat()" title="Back to conversations" aria-label="Back to conversations">
                                <i class="fa-solid fa-arrow-left"></i>
                            </button>
                            <div class="chat-header-avatar">
                                <?php if (!empty($current_chat_user['profile_image'])): ?>
                                    <img src="../uploads/<?php echo htmlspecialchars($current_chat_user['profile_image']); ?>" alt="<?php echo htmlspecialchars($current_chat_user['username']); ?>">
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
                            <?php // Desktop: isara ang usapan. Telepono: isara at buksan ang sidebar (gaya ng AI Recommendation) ?>
                            <button type="button" class="action-btn chat-exit-btn" onclick="exitChat()" title="Close" aria-label="Close and open menu">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        
                        <div class="chat-messages" id="chatMessages">
                            <?php if (count($chat_messages) > 0): ?>
                                <?php foreach ($chat_messages as $msg): ?>
                                    <?php $is_mine = $msg['sender_id'] == $user_id; ?>
                                    <div class="message <?php echo $is_mine ? 'sent' : 'received'; ?>" data-message-id="<?php echo $msg['message_id']; ?>">
                                        <div class="message-avatar">
                                            <?php if (!empty($msg['sender_image'])): ?>
                                                <img src="../uploads/<?php echo htmlspecialchars($msg['sender_image']); ?>" alt="Sender">
                                            <?php else: ?>
                                                <i class="fa-solid fa-user"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?php if (!empty($msg['is_deleted'])): ?>
                                                <div class="message-content">
                                                    <p class="unsent-text"><i class="fa-solid fa-ban"></i> <?php echo $is_mine ? 'You unsent a message' : htmlspecialchars($msg['sender_name']) . ' unsent a message'; ?></p>
                                                </div>
                                            <?php else: ?>
                                                <div class="message-content">
                                                    <button type="button" class="msg-menu-btn" onclick='openDeleteModal(<?php echo (int)$msg['message_id']; ?>, <?php echo $is_mine ? 'true' : 'false'; ?>, event)' title="More">
                                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                                    </button>

                                                    <?php if (!empty($msg['message'])): ?>
                                                        <p style="margin: 0; word-break: break-word;"><?php echo sp_message_html((string) $msg['message'], 'adopter'); ?></p>
                                                    <?php endif; ?>

                                                    <!-- Render Image Attachment -->
                                                    <?php if ($msg['message_type'] === 'image' && !empty($msg['attachment_url'])): ?>
                                                        <div class="attachment-wrap">
                                                            <img src="../uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" alt="Attachment" style="max-width: 220px; max-height: 220px; border-radius: 8px; display: block; object-fit: cover;" onclick='openImagePreview("../uploads/<?php echo htmlspecialchars($msg['attachment_url'], ENT_QUOTES); ?>", "<?php echo htmlspecialchars($msg['attachment_url'], ENT_QUOTES); ?>")'>
                                                            <button type="button" class="download-btn" onclick='downloadAttachment("../uploads/<?php echo htmlspecialchars($msg['attachment_url'], ENT_QUOTES); ?>", "<?php echo htmlspecialchars($msg['attachment_url'], ENT_QUOTES); ?>")' title="Download">
                                                                <i class="fa-solid fa-download"></i>
                                                            </button>
                                                        </div>
                                                    <!-- Render File Attachment -->
                                                    <?php elseif ($msg['message_type'] === 'file' && !empty($msg['attachment_url'])): ?>
                                                        <div class="attachment-wrap file-wrap">
                                                            <a href="../uploads/<?php echo htmlspecialchars($msg['attachment_url']); ?>" target="_blank" style="color: inherit; text-decoration: underline; font-weight: 500;">
                                                                <i class="fa-solid fa-file-pdf"></i> View Attachment
                                                            </a>
                                                            <button type="button" class="download-btn" onclick='downloadAttachment("../uploads/<?php echo htmlspecialchars($msg['attachment_url'], ENT_QUOTES); ?>", "<?php echo htmlspecialchars($msg['attachment_url'], ENT_QUOTES); ?>")' title="Download">
                                                                <i class="fa-solid fa-download"></i>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="message-time"><?php echo date('h:i A', strtotime($msg['created_at'])); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; color: #8a8a8a; padding: 40px;">
                                    <i class="fa-solid fa-paper-plane" style="font-size: 40px; margin-bottom: 15px; color: #ff8c00; opacity: 0.5;"></i>
                                    <p>No messages yet</p>
                                    <p style="font-size: 12px;">Send a message to start the conversation</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="message-input-area">
                            <!-- Preview Box para sa selected/pasted image -->
                            <div id="imagePreviewContainer" style="display: none; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 12px; margin-bottom: 10px; align-items: center; gap: 10px; position: relative; width: fit-content;">
                                <img id="imagePreview" src="" style="max-height: 80px; max-width: 120px; border-radius: 8px; object-fit: cover;">
                                <button type="button" id="removePreviewBtn" style="position: absolute; top: -8px; right: -8px; background: #e74c3c; color: white; border: none; border-radius: 50%; width: 22px; height: 22px; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>

                            <form id="messageForm" class="message-input-form" enctype="multipart/form-data">
                                <input type="hidden" name="receiver_id" value="<?php echo $current_chat_id; ?>">
                                <input type="hidden" name="send_message" value="1">
                                
                                <label for="fileInput" class="attachment-btn">
                                    <i class="fa-solid fa-paperclip"></i>
                                </label>
                                <input type="file" id="fileInput" name="attachment" style="display: none;" accept="image/*,.pdf,.doc,.docx">
                                
                                <textarea name="message" id="messageInput" class="message-input" placeholder="Type a message or paste image (Ctrl+V)..."></textarea>
                                
                                <button type="submit" class="send-btn">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </button>
                            </form>
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
                            <p>Choose a staff member from the list to view your messages or ask about adoption, schedules, and your pets.</p>
                            <ul class="empty-tips">
                                <li><i class="fa-solid fa-paperclip"></i> Send photos or documents</li>
                                <li><i class="fa-solid fa-circle"></i> Green dot means the staff is online</li>
                                <li><i class="fa-solid fa-envelope"></i> New replies show a count on the Message menu</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

            </section>

            <script>
            // Search: sinasala ang listahan ng staff ayon sa pangalan
            (function () {
                const input = document.getElementById('chatSearch');
                if (!input) return;
                const items = document.querySelectorAll('.chat-list-items .chat-item');
                const empty = document.getElementById('chatSearchEmpty');
                input.addEventListener('input', function () {
                    const q = input.value.trim().toLowerCase();
                    let shown = 0;
                    items.forEach(function (item) {
                        const match = !q || (item.dataset.name || '').includes(q);
                        item.style.display = match ? '' : 'none';
                        if (match) shown++;
                    });
                    empty.hidden = shown > 0;
                });
            })();
            </script>

        </main>
    </div>

    <!-- Delete Message Modal -->
    <div id="deleteModal" class="delete-modal-overlay">
        <div class="delete-modal-box">
            <h3>Delete message?</h3>
            <button type="button" class="delete-option-everyone" id="deleteEveryoneBtn" onclick="confirmDelete('everyone')" style="display:none;">
                <i class="fa-solid fa-ban"></i> Unsend for Everyone
            </button>
            <button type="button" class="delete-option-me" onclick="confirmDelete('me')">
                <i class="fa-solid fa-trash"></i> Delete for Me
            </button>
            <button type="button" class="delete-option-cancel" onclick="closeDeleteModal()">Cancel</button>
        </div>
    </div>

    <!-- Image Preview Lightbox (stays in the same tab) -->
    <div id="imagePreviewModal" class="image-preview-overlay">
        <button type="button" class="image-preview-close" onclick="closeImagePreview()" title="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <img id="imagePreviewModalImg" src="" alt="Preview">
        <button type="button" class="image-preview-download" id="imagePreviewDownloadBtn" title="Download">
            <i class="fa-solid fa-download"></i> Download
        </button>
    </div>

    <script>
    function openChat(userId) {
        window.location.href = 'messages.php?chat=' + userId;
    }
    
    function closeChat() {
        window.location.href = 'messages.php';
    }

    // × sa header ng usapan. Sa telepono (buong screen ang chat), binubuksan ang sidebar
    // para makapili ng ibang page - kapareho ng × sa AI Recommendation.
    function exitChat() {
        if (window.matchMedia('(max-width: 900px)').matches) {
            document.body.classList.add('sp-nav-open');
        } else {
            closeChat();
        }
    }
    
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    // Kapareho ng sp_message_html() sa PHP: ang [[Label|target]] ay nagiging button,
    // pero page lang ng adopter o Google Maps ang tinatanggap na target
    function renderMessage(text) {
        const pattern = /\[\[([^|\]\r\n]{1,80})\|([^\]\s]{1,300})\]\]/g;
        const okTarget = function (t) {
            return /^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?(#[A-Za-z0-9_\-]+)?$/.test(t) || t.indexOf('https://www.google.com/maps/') === 0;
        };
        let html = '';
        let last = 0;
        let m;
        while ((m = pattern.exec(text)) !== null) {
            html += escapeHtml(text.slice(last, m.index)).replace(/\n/g, '<br>');
            const label = escapeHtml(m[1].trim());
            if (okTarget(m[2])) {
                const external = m[2].indexOf('https://') === 0;
                html += '<a class="msg-action-btn" href="' + escapeHtml(m[2]) + '"' + (external ? ' target="_blank" rel="noopener"' : '') + '>' + label + '</a>';
            } else {
                html += '<span class="msg-action-label">[' + label + ']</span>';
            }
            last = pattern.lastIndex;
        }
        return html + escapeHtml(text.slice(last)).replace(/\n/g, '<br>');
    }

    function scrollToBottom() {
        const chatMessages = document.getElementById('chatMessages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }
    
    window.addEventListener('load', scrollToBottom);

    // ===== Delete Message Logic =====
    let deleteTargetId = null;
    let deleteTargetIsMine = false;

    function openDeleteModal(messageId, isMine, event) {
        if (event) event.stopPropagation();
        deleteTargetId = messageId;
        deleteTargetIsMine = isMine;

        const modal = document.getElementById('deleteModal');
        const everyoneBtn = document.getElementById('deleteEveryoneBtn');
        everyoneBtn.style.display = isMine ? 'block' : 'none';
        modal.classList.add('active');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
        deleteTargetId = null;
    }

    function confirmDelete(deleteType) {
        if (!deleteTargetId) return;
        const messageId = deleteTargetId;

        const formData = new FormData();
        formData.append('delete_message', '1');
        formData.append('message_id', messageId);
        formData.append('delete_type', deleteType);

        fetch('messages.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                closeDeleteModal();
                if (data.success) {
                    const msgEl = document.querySelector(`[data-message-id="${messageId}"]`);
                    if (msgEl) {
                        if (data.delete_type === 'everyone') {
                            const contentDiv = msgEl.querySelector('.message-content');
                            const isSent = msgEl.classList.contains('sent');
                            contentDiv.innerHTML = `<p class="unsent-text"><i class="fa-solid fa-ban"></i> ${isSent ? 'You unsent a message' : 'This message was unsent'}</p>`;
                        } else {
                            // Delete for me: alisin sa view
                            msgEl.remove();
                        }
                    }
                } else {
                    alert(data.error || 'Failed to delete message');
                }
            })
            .catch(err => {
                closeDeleteModal();
                console.error('Error deleting message:', err);
            });
    }

    document.getElementById('deleteModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });

    // ===== Download Attachment Logic =====
    function downloadAttachment(url, filename) {
        fetch(url)
            .then(res => res.blob())
            .then(blob => {
                const blobUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = blobUrl;
                a.download = filename || 'download';
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(blobUrl);
            })
            .catch(err => {
                console.error('Download failed, opening in new tab instead:', err);
                window.open(url, '_blank');
            });
    }

    // ===== Image Preview Lightbox Logic (walang bagong tab) =====
    function openImagePreview(url, filename) {
        const modal = document.getElementById('imagePreviewModal');
        const img = document.getElementById('imagePreviewModalImg');
        const downloadBtn = document.getElementById('imagePreviewDownloadBtn');

        img.src = url;
        downloadBtn.onclick = function() { downloadAttachment(url, filename); };
        modal.classList.add('active');
    }

    function closeImagePreview() {
        const modal = document.getElementById('imagePreviewModal');
        modal.classList.remove('active');
        document.getElementById('imagePreviewModalImg').src = '';
    }

    document.getElementById('imagePreviewModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeImagePreview();
    });

    let lastMessageId = <?php echo count($chat_messages) > 0 ? end($chat_messages)['message_id'] : 0; ?>;
    const currentChatId = <?php echo $current_chat_id; ?>;

    const messageInput = document.getElementById('messageInput');
    const fileInput = document.getElementById('fileInput');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImg = document.getElementById('imagePreview');
    const removePreviewBtn = document.getElementById('removePreviewBtn');
    const messageForm = document.getElementById('messageForm');

    // Function para ipakita ang preview
    function showPreview(file) {
        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewContainer.style.display = 'flex';
            };
            reader.readAsDataURL(file);
        }
    }

    // Function para alisin ang preview/file
    function clearFileInput() {
        fileInput.value = '';
        previewImg.src = '';
        previewContainer.style.display = 'none';
    }

    if (removePreviewBtn) {
        removePreviewBtn.addEventListener('click', clearFileInput);
    }

    // 1. Kapag pumili ng file gamit ang File Picker/Attachment Button
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                showPreview(this.files[0]);
            }
        });
    }

    // 2. Kapag nag-Ctrl + V (Paste) ng screenshot/image sa textarea
    if (messageInput) {
        messageInput.addEventListener('paste', function(e) {
            const items = (e.clipboardData || e.originalEvent.clipboardData).items;
            for (let item of items) {
                if (item.type.indexOf('image') !== -1) {
                    const blob = item.getAsFile();
                    const file = new File([blob], "pasted_image.png", { type: blob.type });
                    
                    // Ilagay ang pasted file sa DataTransfer para maitakda sa fileInput
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    fileInput.files = dataTransfer.files;

                    showPreview(file);
                    break;
                }
            }
        });

        // Enter key handler para sa submission
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (messageForm) messageForm.requestSubmit();
            }
        });
    }

    // Form Submission Handling via AJAX
    if (messageForm) {
        messageForm.addEventListener('submit', function(e) {
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
                    clearFileInput(); // Linisin ang image preview at file input
                    pollMessages();       
                } else {
                    alert(data.error || 'Failed to send message');
                }
            })
            .catch(error => console.error('Error:', error));
        });
    }

    // Polling Function
    function pollMessages() {
        if (currentChatId > 0) {
            fetch(`messages.php?poll=1&chat=${currentChatId}&last_time=${new Date().toISOString()}&last_id=${lastMessageId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.messages && data.messages.length > 0) {
                        const chatMessages = document.getElementById('chatMessages');
                        data.messages.forEach(msg => {
                            if (!document.querySelector(`[data-message-id="${msg.message_id}"]`)) {
                                const messageDiv = document.createElement('div');
                                const isSent = msg.sender_id == '<?php echo $user_id; ?>';
                                messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                                messageDiv.setAttribute('data-message-id', msg.message_id);

                                let contentHTML = '';

                                if (msg.is_deleted == 1 || msg.is_deleted === true) {
                                    contentHTML = `<div class="message-content"><p class="unsent-text"><i class="fa-solid fa-ban"></i> ${isSent ? 'You unsent a message' : 'This message was unsent'}</p></div>`;
                                } else {
                                    let attachmentHTML = '';
                                    if (msg.message_type === 'image' && msg.attachment_url) {
                                        attachmentHTML = `<div class="attachment-wrap"><img src="../uploads/${escapeHtml(msg.attachment_url)}" style="max-width:220px; max-height:220px; border-radius:8px; display:block; object-fit:cover;" onclick='openImagePreview("../uploads/${escapeHtml(msg.attachment_url)}", ${JSON.stringify(msg.attachment_url)})'><button type="button" class="download-btn" onclick='downloadAttachment("../uploads/${escapeHtml(msg.attachment_url)}", ${JSON.stringify(msg.attachment_url)})' title="Download"><i class="fa-solid fa-download"></i></button></div>`;
                                    } else if (msg.message_type === 'file' && msg.attachment_url) {
                                        attachmentHTML = `<div class="attachment-wrap file-wrap"><a href="../uploads/${escapeHtml(msg.attachment_url)}" target="_blank" style="color:inherit; text-decoration:underline;"><i class="fa-solid fa-file-pdf"></i> View Attachment</a><button type="button" class="download-btn" onclick='downloadAttachment("../uploads/${escapeHtml(msg.attachment_url)}", ${JSON.stringify(msg.attachment_url)})' title="Download"><i class="fa-solid fa-download"></i></button></div>`;
                                    }

                                    contentHTML = `<div class="message-content">
                                            <button type="button" class="msg-menu-btn" onclick='openDeleteModal(${msg.message_id}, ${isSent}, event)' title="More"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                            ${msg.message ? `<p>${renderMessage(msg.message)}</p>` : ''}
                                            ${attachmentHTML}
                                        </div>`;
                                }

                                messageDiv.innerHTML = `
                                    <div class="message-avatar">
                                        ${msg.sender_image ? `<img src="../uploads/${escapeHtml(msg.sender_image)}" alt="Sender">` : '<i class="fa-solid fa-user"></i>'}
                                    </div>
                                    <div>
                                        ${contentHTML}
                                        <div class="message-time">${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                                    </div>
                                `;
                                chatMessages.appendChild(messageDiv);
                                lastMessageId = msg.message_id;
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

    setInterval(pollMessages, 3000);

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
