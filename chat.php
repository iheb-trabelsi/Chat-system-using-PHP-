<?php
session_start();
require_once 'database.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$current_user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle search for users
$search_results = [];
if (isset($_GET['search'])) {
    $search_term = trim($_GET['search']);
    if (!empty($search_term)) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, email, image_path
            FROM users
            WHERE (full_name LIKE ? OR email LIKE ?)
            AND id != ?
            LIMIT 10
        ");
        $stmt->execute([
            "%$search_term%",
            "%$search_term%",
            $current_user_id
        ]);
        $search_results = $stmt->fetchAll();
    }
}

// Handle sending connection request
if (isset($_POST['send_request'])) {
    $target_user_id = (int)$_POST['target_user_id'];
    
    if ($target_user_id !== $current_user_id) {
        // Check if relationship already exists
        $stmt = $pdo->prepare("
            SELECT id FROM user_relationships 
            WHERE (user_id = ? AND related_user_id = ?)
            OR (user_id = ? AND related_user_id = ?)
        ");
        $stmt->execute([
            $current_user_id, $target_user_id,
            $target_user_id, $current_user_id
        ]);
        
        if ($stmt->rowCount() === 0) {
            // Create new relationship request
            $stmt = $pdo->prepare("
                INSERT INTO user_relationships 
                (user_id, related_user_id, status, action_user_id) 
                VALUES (?, ?, 'pending', ?)
            ");
            $stmt->execute([
                $current_user_id, 
                $target_user_id,
                $current_user_id
            ]);
            $success = 'Connection request sent!';
        } else {
            $error = 'Connection already exists with this user.';
        }
    } else {
        $error = 'You cannot connect with yourself.';
    }
}

// Handle accepting connection request
if (isset($_POST['accept_request'])) {
    $request_id = (int)$_POST['request_id'];
    
    $stmt = $pdo->prepare("
        UPDATE user_relationships 
        SET status = 'accepted', action_user_id = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND related_user_id = ? AND status = 'pending'
    ");
    $stmt->execute([$current_user_id, $request_id, $current_user_id]);
    
    if ($stmt->rowCount() > 0) {
        $success = 'Connection request accepted!';
    } else {
        $error = 'Failed to accept request.';
    }
}

// Get pending connection requests
$stmt = $pdo->prepare("
    SELECT ur.id, u.full_name, u.email, u.image_path 
    FROM user_relationships ur
    JOIN users u ON ur.user_id = u.id
    WHERE ur.related_user_id = ? AND ur.status = 'pending'
");
$stmt->execute([$current_user_id]);
$pending_requests = $stmt->fetchAll();

// Get accepted connections
$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, u.image_path 
    FROM user_relationships ur
    JOIN users u ON (
        (ur.user_id = u.id AND ur.related_user_id = ?) OR 
        (ur.related_user_id = u.id AND ur.user_id = ?)
    )
    WHERE ur.status = 'accepted'
");
$stmt->execute([$current_user_id, $current_user_id]);
$connections = $stmt->fetchAll();

// Handle group creation
if (isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name']);
    $description = trim($_POST['group_description']);
    $selected_members = $_POST['group_members'] ?? [];
    
    if (empty($group_name)) {
        $error = 'Group name is required';
    } elseif (count($selected_members) < 1) {
        $error = 'Select at least one member for the group';
    } else {
        $pdo->beginTransaction();
        
        try {
            // Create the group
            $stmt = $pdo->prepare("
                INSERT INTO groups (name, description, created_by, image_path) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $group_name,
                $description,
                $current_user_id,
                null // You can add group image upload later
            ]);
            $group_id = $pdo->lastInsertId();
            
            // Create the conversation
            $stmt = $pdo->prepare("
                INSERT INTO conversations (is_group, group_id) 
                VALUES (TRUE, ?)
            ");
            $stmt->execute([$group_id]);
            $conversation_id = $pdo->lastInsertId();
            
            // Add participants (creator + selected members)
            $participants = array_merge([$current_user_id], $selected_members);
            $placeholders = implode(',', array_fill(0, count($participants), '(?,?)'));
            $values = [];
            foreach ($participants as $participant) {
                $values[] = $conversation_id;
                $values[] = $participant;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO conversation_participants (conversation_id, user_id) 
                VALUES $placeholders
            ");
            $stmt->execute($values);
            
            $pdo->commit();
            $success = 'Group created successfully!';
            header("Location: chat.php?conversation=$conversation_id");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to create group: ' . $e->getMessage();
        }
    }
}

// Handle adding members to group
if (isset($_POST['add_group_members'])) {
    $conversation_id = (int)$_POST['conversation_id'];
    $group_id = (int)$_POST['group_id'];
    $new_members = $_POST['new_members'] ?? [];
    
    // Verify current user is group admin
    $stmt = $pdo->prepare("
        SELECT 1 FROM groups 
        WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$group_id, $current_user_id]);
    
    if ($stmt->rowCount() === 0) {
        $error = 'Only group admin can add members';
    } elseif (empty($new_members)) {
        $error = 'Select at least one member to add';
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($new_members), '(?,?)'));
            $values = [];
            foreach ($new_members as $member) {
                $values[] = $conversation_id;
                $values[] = $member;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO conversation_participants (conversation_id, user_id) 
                VALUES $placeholders
            ");
            $stmt->execute($values);
            
            $success = 'Members added successfully!';
        } catch (Exception $e) {
            $error = 'Failed to add members: ' . $e->getMessage();
        }
    }
}

// Handle removing member from group
if (isset($_POST['remove_member'])) {
    $member_id = (int)$_POST['member_id'];
    $conversation_id = (int)$_POST['conversation_id'];
    $group_id = (int)$_POST['group_id'];
    
    // Verify current user is group admin and not trying to remove themselves
    $stmt = $pdo->prepare("
        SELECT 1 FROM groups 
        WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$group_id, $current_user_id]);
    
    if ($stmt->rowCount() === 0) {
        $error = 'Only group admin can remove members';
    } elseif ($member_id === $current_user_id) {
        $error = 'You cannot remove yourself as admin';
    } else {
        try {
            $stmt = $pdo->prepare("
                DELETE FROM conversation_participants 
                WHERE conversation_id = ? AND user_id = ?
            ");
            $stmt->execute([$conversation_id, $member_id]);
            
            $success = 'Member removed successfully!';
        } catch (Exception $e) {
            $error = 'Failed to remove member: ' . $e->getMessage();
        }
    }
}

// Get conversations
$stmt = $pdo->prepare("
    SELECT 
        c.id, 
        c.is_group,
        IF(c.is_group, g.name, u.full_name) AS display_name,
        IF(c.is_group, g.image_path, u.image_path) AS image_path,
        (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY sent_at DESC LIMIT 1) AS last_message,
        (SELECT sent_at FROM messages WHERE conversation_id = c.id ORDER BY sent_at DESC LIMIT 1) AS last_message_time,
        IF(c.is_group, NULL, u.id) AS other_user_id,
        IF(c.is_group, g.id, NULL) AS group_id
    FROM conversation_participants cp
    JOIN conversations c ON cp.conversation_id = c.id
    LEFT JOIN groups g ON c.group_id = g.id AND c.is_group = TRUE
    LEFT JOIN conversation_participants cp2 ON cp2.conversation_id = c.id AND cp2.user_id != ?
    LEFT JOIN users u ON cp2.user_id = u.id AND c.is_group = FALSE
    WHERE cp.user_id = ?
    GROUP BY c.id
    ORDER BY last_message_time DESC
");
$stmt->execute([$current_user_id, $current_user_id]);
$conversations = $stmt->fetchAll();

// Handle starting a new chat
if (isset($_POST['start_chat']) && isset($_POST['connection_id'])) {
    $connection_id = (int)$_POST['connection_id'];
    
    // Check if connection exists
    $stmt = $pdo->prepare("
        SELECT id FROM user_relationships 
        WHERE status = 'accepted' AND 
        ((user_id = ? AND related_user_id = ?) OR 
         (user_id = ? AND related_user_id = ?))
    ");
    $stmt->execute([
        $current_user_id, $connection_id,
        $connection_id, $current_user_id
    ]);
    
    if ($stmt->rowCount() > 0) {
        // Check if conversation already exists
        $stmt = $pdo->prepare("
            SELECT cp.conversation_id
            FROM conversation_participants cp
            JOIN conversation_participants cp2 ON cp2.conversation_id = cp.conversation_id
            WHERE cp.user_id = ? AND cp2.user_id = ?
        ");
        $stmt->execute([$current_user_id, $connection_id]);
        
        if ($stmt->rowCount() === 0) {
            // Create new conversation
            $pdo->beginTransaction();
            
            try {
                $pdo->exec("INSERT INTO conversations (is_group) VALUES (FALSE)");
                $conversation_id = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("
                    INSERT INTO conversation_participants (conversation_id, user_id) 
                    VALUES (?, ?), (?, ?)
                ");
                $stmt->execute([
                    $conversation_id, $current_user_id,
                    $conversation_id, $connection_id
                ]);
                
                $pdo->commit();
                $success = 'Chat started successfully!';
                header("Location: chat.php?conversation=$conversation_id");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to start chat.';
            }
        } else {
            $conversation_id = $stmt->fetchColumn();
            header("Location: chat.php?conversation=$conversation_id");
            exit();
        }
    } else {
        $error = 'You can only chat with accepted connections.';
    }
}

// Handle deleting a message
if (isset($_POST['delete_message'])) {
    $message_id = (int)$_POST['message_id'];
    $current_user_id = $_SESSION['user_id'];
    $conversation_id = isset($_POST['conversation']) ? (int)$_POST['conversation'] : null;

    // Check if this is an AJAX request
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    try {
        $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?");
        $stmt->execute([$message_id, $current_user_id]);
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        } else {
            header("Location: chat.php?conversation=" . $conversation_id);
            exit();
        }
    } catch (PDOException $e) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        } else {
            header("Location: chat.php?conversation=" . $conversation_id);
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iChat | Minimal Communication</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap');
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f5f5f7;
        }
        .sidebar {
            background-color: #ffffff;
            border-right: 1px solid #e5e5e5;
        }
        .chat-area {
            background-color: #fafafa;
        }
        .conversation-list {
            background-color: #ffffff;
            border-left: 1px solid #e5e5e5;
        }
        .btn-primary {
            background-color:rgb(0, 0, 0);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: #333333;
        }
        .message-out {
            background-color:rgb(9, 32, 240);
            color: #ffffff;
        }
        .message-in {
            background-color: #e5e5e5;
            color: #000000;
        }
        .file-message {
            background-color:rgb(10, 10, 10);
            border-radius: 12px;
            padding: 8px 12px;
            display: inline-block;
            max-width: 200px;
        }
        .file-icon {
            margin-right: 8px;
            color: #4b5563;
        }
        .group:hover .group-hover\:block {
            display: block;
        }
        #file-preview {
            display: none;
            margin-top: 8px;
        }
        #file-preview img {
            max-width: 100px;
            max-height: 100px;
            border-radius: 4px;
        }
        #groupModal {
            transition: all 0.3s ease;
        }
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.5);
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen">
        <!-- Sidebar - Steve Jobs style minimalist design -->
        <div class="w-1/4 sidebar flex flex-col">
            <!-- Header with logout -->
            <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">iChat</h1>
                    <p class="text-xs text-gray-500">Minimal communication</p>
                </div>
                <form action="logout.php" method="POST">
                    <button type="submit" class="text-xs btn-primary px-3 py-1 rounded-full hover:bg-gray-800 transition-colors">
                        Sign Out
                    </button>
                </form>
            </div>
            
            <!-- User profile -->
            <div class="p-4 border-b border-gray-200 flex items-center space-x-3">
                <?php if ($_SESSION['user_image']): ?>
                    <img src="<?= htmlspecialchars($_SESSION['user_image']) ?>" class="w-10 h-10 rounded-full">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center">
                        <span class="text-gray-600 text-sm font-medium">
                            <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="font-medium text-gray-900"><?= htmlspecialchars($_SESSION['user_name']) ?></p>
                    <p class="text-xs text-gray-500">Online</p>
                </div>
            </div>
            
            <!-- Search - Apple style search bar -->
            <div class="p-4 border-b border-gray-200">
                <form method="GET" class="relative">
                    <input type="text" name="search" placeholder="Search users..." 
                           class="w-full pl-8 pr-3 py-2 bg-gray-100 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-300 text-sm">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </form>
            </div>
            
            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto">
                <!-- Create Group Button -->
                <button onclick="openGroupModal()" class="w-full text-left flex items-center p-3 hover:bg-gray-50 rounded text-sm text-gray-700 border-b border-gray-200">
                    <i class="fas fa-users mr-2"></i> Create New Group
                </button>
                
                <!-- Pending Requests -->
                <?php if (!empty($pending_requests)): ?>
                    <div class="p-4 border-b border-gray-200">
                        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Connection Requests</h2>
                        <div class="space-y-2">
                            <?php foreach ($pending_requests as $request): ?>
                                <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded">
                                    <div class="flex items-center space-x-2">
                                        <?php if ($request['image_path']): ?>
                                            <img src="<?= htmlspecialchars($request['image_path']) ?>" class="w-8 h-8 rounded-full">
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
                                                <span class="text-gray-600 text-xs font-medium">
                                                    <?= strtoupper(substr($request['full_name'], 0, 1)) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <span class="text-sm"><?= htmlspecialchars($request['full_name']) ?></span>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                        <button type="submit" name="accept_request" class="text-xs btn-primary px-2 py-1 rounded hover:bg-gray-800">
                                            Accept
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Connections -->
                <div class="p-4">
                    <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Connections</h2>
                    <div class="space-y-1">
                        <?php if (empty($connections)): ?>
                            <p class="text-sm text-gray-500 py-2">No connections yet</p>
                        <?php else: ?>
                            <?php foreach ($connections as $connection): ?>
                                <form method="POST">
                                    <input type="hidden" name="connection_id" value="<?= $connection['id'] ?>">
                                    <button type="submit" name="start_chat" class="w-full text-left flex items-center p-2 hover:bg-gray-50 rounded">
                                        <?php if ($connection['image_path']): ?>
                                            <img src="<?= htmlspecialchars($connection['image_path']) ?>" class="w-8 h-8 rounded-full mr-2">
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center mr-2">
                                                <span class="text-gray-600 text-xs font-medium">
                                                    <?= strtoupper(substr($connection['full_name'], 0, 1)) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <span class="text-sm"><?= htmlspecialchars($connection['full_name']) ?></span>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Search Results -->
                <?php if (!empty($search_results)): ?>
                    <div class="p-4 border-t border-gray-200">
                        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Search Results</h2>
                        <div class="space-y-2">
                            <?php foreach ($search_results as $user): ?>
                                <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded">
                                    <div class="flex items-center space-x-2">
                                        <?php if ($user['image_path']): ?>
                                            <img src="<?= htmlspecialchars($user['image_path']) ?>" class="w-8 h-8 rounded-full">
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
                                                <span class="text-gray-600 text-xs font-medium">
                                                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="text-sm"><?= htmlspecialchars($user['full_name']) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($user['email']) ?></div>
                                        </div>
                                    </div>
                                    <form method="POST">
                                        <input type="hidden" name="target_user_id" value="<?= $user['id'] ?>">
                                        <button type="submit" name="send_request" class="text-xs btn-primary px-2 py-1 rounded hover:bg-gray-800">
                                            Connect
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </nav>
        </div>
        
        <!-- Main Chat Area -->
        <div class="flex-1 flex flex-col chat-area">
            <?php if (isset($_GET['conversation'])): ?>
                <!-- Group info panel (shown only for group chats) -->
                <div id="group-info-panel" class="p-4 border-b border-gray-200 bg-white hidden">
                    <!-- Group info will be loaded here via AJAX -->
                </div>
                
                <!-- Messages container -->
                <div id="messages-container" class="flex-1 p-6 overflow-y-auto space-y-4">
                    <!-- Messages will be loaded here via AJAX -->
                </div>
                
                <!-- Message input - Apple style input -->
                <div class="p-4 border-t border-gray-200 bg-white">
                    <form id="message-form" class="flex items-center space-x-2" enctype="multipart/form-data">
                        <input type="hidden" name="conversation_id" value="<?= htmlspecialchars($_GET['conversation']) ?>">
                        
                        <!-- File upload button -->
                        <input type="file" name="file" id="file-upload" style="display: none;">
                        <label for="file-upload" class="w-10 h-10 flex items-center justify-center text-gray-500 hover:text-gray-700 cursor-pointer">
                            <i class="fas fa-paperclip"></i>
                        </label>
                        
                        <!-- File preview -->
                        <div id="file-preview" class="flex items-center">
                            <span id="file-name" class="text-sm mr-2"></span>
                            <button type="button" onclick="clearFile()" class="text-red-500">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Message input -->
                        <input type="text" name="message" placeholder="iMessage" 
                               class="flex-grow px-4 py-2 bg-gray-100 rounded-full focus:outline-none focus:ring-1 focus:ring-gray-300">
                        
                        <!-- Send button -->
                        <button type="submit" class="w-10 h-10 btn-primary rounded-full flex items-center justify-center hover:bg-gray-800">
                            <i class="fas fa-paper-plane text-white"></i>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Empty state - Apple style empty state -->
                <div class="flex-1 flex flex-col items-center justify-center text-center p-6">
                    <div class="w-24 h-24 bg-gray-200 rounded-full flex items-center justify-center mb-4">
                        <i class="fas fa-comment text-gray-400 text-3xl"></i>
                    </div>
                    <h2 class="text-xl font-medium text-gray-900 mb-2">No Conversation Selected</h2>
                    <p class="text-gray-500 max-w-md">Select a conversation from your connections to start chatting. All messages are end-to-end encrypted.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Conversations List -->
        <div class="w-1/4 conversation-list">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-sm font-medium text-gray-900">Conversations</h2>
            </div>
            <div class="overflow-y-auto" style="height: calc(100vh - 60px);">
                <?php if (empty($conversations)): ?>
                    <div class="p-6 text-center">
                        <p class="text-sm text-gray-500">No conversations yet</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                        <a href="chat.php?conversation=<?= $conv['id'] ?>" class="block p-3 border-b border-gray-100 hover:bg-gray-50">
                            <div class="flex items-center space-x-3">
                                <?php if ($conv['image_path']): ?>
                                    <img src="<?= htmlspecialchars($conv['image_path']) ?>" class="w-10 h-10 rounded-full">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center">
                                        <span class="text-gray-600 text-sm font-medium">
                                            <?= strtoupper(substr($conv['display_name'], 0, 1)) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <div class="flex justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($conv['display_name']) ?></p>
                                        <span class="text-xs text-gray-500">
                                            <?= $conv['last_message_time'] ? date('h:i A', strtotime($conv['last_message_time'])) : '' ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate">
                                        <?= $conv['last_message'] ? htmlspecialchars($conv['last_message']) : 'No messages yet' ?>
                                    </p>
                                </div>
                                <?php if ($conv['is_group']): ?>
                                    <i class="fas fa-users text-gray-400 text-xs"></i>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Group Creation Modal -->
    <div id="groupModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium">Create New Group</h3>
                <button onclick="closeGroupModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="group_name">
                        Group Name
                    </label>
                    <input type="text" name="group_name" id="group_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-300">
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="group_description">
                        Description (Optional)
                    </label>
                    <textarea name="group_description" id="group_description" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-gray-300"></textarea>
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Add Members
                    </label>
                    <div class="max-h-48 overflow-y-auto border border-gray-200 rounded p-2">
                        <?php foreach ($connections as $connection): ?>
                            <div class="flex items-center p-2 hover:bg-gray-50">
                                <input type="checkbox" name="group_members[]" value="<?= $connection['id'] ?>" 
                                       id="member_<?= $connection['id'] ?>" class="mr-2">
                                <label for="member_<?= $connection['id'] ?>" class="flex items-center">
                                    <?php if ($connection['image_path']): ?>
                                        <img src="<?= htmlspecialchars($connection['image_path']) ?>" class="w-6 h-6 rounded-full mr-2">
                                    <?php else: ?>
                                        <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center mr-2">
                                            <span class="text-gray-600 text-xs">
                                                <?= strtoupper(substr($connection['full_name'], 0, 1)) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($connection['full_name']) ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeGroupModal()" 
                            class="px-4 py-2 text-sm bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                        Cancel
                    </button>
                    <button type="submit" name="create_group" 
                            class="px-4 py-2 text-sm bg-black text-white rounded hover:bg-gray-800">
                        Create Group
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Members Modal -->
    <div id="addMembersModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg p-6 w-full max-w-md">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium">Add Members</h3>
                <button onclick="closeAddMembersModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="addMembersForm" method="POST">
                <input type="hidden" name="add_group_members" value="1">
                <input type="hidden" id="addMembersConversationId" name="conversation_id">
                <input type="hidden" id="addMembersGroupId" name="group_id">
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Select Members to Add
                    </label>
                    <div class="max-h-48 overflow-y-auto border border-gray-200 rounded p-2" id="availableMembersList">
                        <!-- Members will be loaded here via JavaScript -->
                    </div>
                </div>
                
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeAddMembersModal()" 
                            class="px-4 py-2 text-sm bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 text-sm bg-black text-white rounded hover:bg-gray-800">
                        Add Members
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript for real-time messaging and file sharing -->
    <script>
    // Global variables
    let currentConversationId = null;
    let pollInterval = null;

    document.addEventListener('DOMContentLoaded', function() {
        currentConversationId = new URLSearchParams(window.location.search).get('conversation');
        if (currentConversationId) {
            initializeChat();
        }

        // Handle all form submissions
        document.addEventListener('submit', function(e) {
            // Message form
            if (e.target && e.target.id === 'message-form') {
                e.preventDefault();
                sendMessage();
            }
            // Delete message form
            else if (e.target && e.target.classList.contains('delete-message-form')) {
                e.preventDefault();
                deleteMessage(e.target);
            }
            // Add members form
            else if (e.target && e.target.id === 'addMembersForm') {
                e.preventDefault();
                addMembers(e.target);
            }
        });

        // File upload preview
        document.getElementById('file-upload').addEventListener('change', previewFile);
    });

    function initializeChat() {
        loadMessages(currentConversationId);
        loadGroupInfo(currentConversationId);
        startPolling(5000);
        document.addEventListener('visibilitychange', handleTabVisibility);
    }

    function startPolling(interval) {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(() => {
            if (!document.hidden) {
                loadMessages(currentConversationId);
                loadGroupInfo(currentConversationId);
            }
        }, interval);
    }

    function handleTabVisibility() {
        if (document.hidden) {
            clearInterval(pollInterval);
        } else {
            loadMessages(currentConversationId);
            loadGroupInfo(currentConversationId);
            startPolling(5000);
        }
    }

    function loadMessages(conversationId) {
        if (!conversationId) return;
        
        fetch(`get_messages.php?conversation_id=${conversationId}`)
            .then(response => {
                if (response.status === 401) {
                    window.location.href = 'login.php';
                    return Promise.reject('Unauthorized');
                }
                return response.json();
            })
            .then(data => {
                const container = document.getElementById('messages-container');
                if (!container) return;
                
                container.innerHTML = '';
                
                if (!Array.isArray(data) || data.length === 0) {
                    container.innerHTML = '<p class="text-gray-500 text-center py-4">No messages yet</p>';
                    return;
                }
                
                data.forEach(msg => {
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `flex ${msg.sender_id == <?= $current_user_id ?> ? 'justify-end' : 'justify-start'} group mb-3`;
                    
                    const isCurrentUser = msg.sender_id == <?= $current_user_id ?>;
                    
                    let messageContent;
                    if (msg.file_path) {
                        const fileExt = msg.file_path.split('.').pop().toLowerCase();
                        let fileIcon = getFileIcon(msg.file_path);
                        let preview = getFilePreview(msg.file_path);
                        
                        messageContent = `
                            <div class="file-message ${isCurrentUser ? 'bg-indigo-100 border border-indigo-200' : 'bg-gray-100 border border-gray-200'} rounded-lg p-3">
                                <div class="flex items-center">
                                    <i class="fas ${fileIcon} ${isCurrentUser ? 'text-indigo-600' : 'text-gray-600'} mr-2"></i>
                                    <a href="${msg.file_path}" target="_blank" 
                                       class="text-sm ${isCurrentUser ? 'text-indigo-700 hover:text-indigo-800' : 'text-gray-700 hover:text-gray-800'} hover:underline truncate">
                                        ${msg.file_name || 'Download file'}
                                    </a>
                                </div>
                                ${msg.content ? `
                                <p class="text-sm mt-2 ${isCurrentUser ? 'text-indigo-800' : 'text-gray-800'}">
                                    ${msg.content}
                                </p>
                                ` : ''}
                                ${preview}
                            </div>
                        `;
                    } else {
                        messageContent = `
                            <p class="text-sm ${isCurrentUser ? 'text-white' : 'text-gray-800'}">
                                ${msg.content}
                            </p>
                        `;
                    }
                    
                    messageDiv.innerHTML = `
                        <div class="relative">
                            <div class="max-w-xs lg:max-w-md px-4 py-2 rounded-2xl ${isCurrentUser ? 'bg-indigo-600' : 'bg-gray-200'}">
                                ${messageContent}
                                <p class="text-xs ${isCurrentUser ? 'text-indigo-200' : 'text-gray-500'} mt-1 text-right">
                                    ${new Date(msg.sent_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                                </p>
                            </div>
                            ${isCurrentUser ? `
                            <div class="absolute right-0 top-0 hidden group-hover:block">
                                <form method="POST" action="chat.php" class="delete-message-form">
                                    <input type="hidden" name="delete_message" value="1">
                                    <input type="hidden" name="message_id" value="${msg.id}">
                                    <input type="hidden" name="conversation" value="${currentConversationId}">
                                    <button type="button" onclick="deleteMessage(this.closest('form'))" 
                                        class="text-xs bg-white text-gray-800 p-1 rounded-full shadow hover:bg-gray-100 transition-all duration-200 hover:scale-110">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            ` : ''}
                        </div>
                    `;
                    
                    container.appendChild(messageDiv);
                });
                
                container.scrollTop = container.scrollHeight;
            })
            .catch(error => {
                console.error('Error:', error);
                const container = document.getElementById('messages-container');
                if (container) {
                    container.innerHTML = '<p class="text-red-500 text-center py-4">Error loading messages</p>';
                }
            });
    }

    function loadGroupInfo(conversationId) {
        if (!conversationId) return;
        
        fetch(`get_group_info.php?conversation_id=${conversationId}`)
            .then(response => response.json())
            .then(data => {
                const panel = document.getElementById('group-info-panel');
                if (!panel) return;
                
                if (data.is_group) {
                    panel.classList.remove('hidden');
                    
                    let membersHtml = '';
                    data.members.forEach(member => {
                        membersHtml += `
                            <div class="flex items-center justify-between p-2 hover:bg-gray-50 rounded">
                                <div class="flex items-center space-x-2">
                                    ${member.image_path ? 
                                        `<img src="${member.image_path}" class="w-8 h-8 rounded-full">` : 
                                        `<div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
                                            <span class="text-gray-600 text-xs">
                                                ${member.full_name.charAt(0).toUpperCase()}
                                            </span>
                                        </div>`
                                    }
                                    <span>${member.full_name}</span>
                                    ${member.id === data.admin_id ? 
                                        '<span class="text-xs text-gray-500">(Admin)</span>' : ''}
                                </div>
                                ${data.is_admin && member.id !== data.admin_id ? `
                                    <form method="POST" class="remove-member-form">
                                        <input type="hidden" name="remove_member" value="1">
                                        <input type="hidden" name="member_id" value="${member.id}">
                                        <input type="hidden" name="conversation_id" value="${conversationId}">
                                        <input type="hidden" name="group_id" value="${data.group_id}">
                                        <button type="button" onclick="removeMember(this.closest('form'))" 
                                                class="text-xs text-red-500 hover:text-red-700">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                ` : ''}
                            </div>
                        `;
                    });
                    
                    panel.innerHTML = `
                        <div class="flex items-center space-x-3 mb-4">
                            ${data.image_path ? 
                                `<img src="${data.image_path}" class="w-12 h-12 rounded-full">` : 
                                `<div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center">
                                    <span class="text-gray-600 text-lg">
                                        ${data.name.charAt(0).toUpperCase()}
                                    </span>
                                </div>`
                            }
                            <div>
                                <h3 class="font-medium">${data.name}</h3>
                                <p class="text-xs text-gray-500">${data.members.length} members</p>
                            </div>
                        </div>
                        
                        ${data.description ? `
                            <p class="text-sm text-gray-700 mb-4">${data.description}</p>
                        ` : ''}
                        
                        <div class="mb-4">
                            <h4 class="text-xs font-semibold text-gray-500 uppercase mb-2">Members</h4>
                            <div class="space-y-1 max-h-48 overflow-y-auto">
                                ${membersHtml}
                            </div>
                        </div>
                        
                        ${data.is_admin ? `
                            <div class="pt-4 border-t border-gray-200">
                                <button onclick="openAddMembersModal(${conversationId}, ${data.group_id})" 
                                        class="text-sm btn-primary px-3 py-1 rounded-full">
                                    <i class="fas fa-plus mr-1"></i> Add Members
                                </button>
                            </div>
                        ` : ''}
                    `;
                } else {
                    panel.classList.add('hidden');
                }
            })
            .catch(error => {
                console.error('Error loading group info:', error);
            });
    }

    function sendMessage() {
        const form = document.getElementById('message-form');
        const formData = new FormData(form);
        
        fetch('send_message.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
                return;
            }
            return response.json().catch(() => {
                throw new Error('Server returned invalid response');
            });
        })
        .then(data => {
            if (data?.success) {
                form.reset();
                clearFile();
                loadMessages(currentConversationId);
            } else {
                throw new Error(data?.error || 'Failed to send message');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert(error.message);
        });
    }

    function deleteMessage(form) {
        if (!confirm('Are you sure you want to delete this message?')) {
            return;
        }

        const formData = new FormData(form);
        
        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (response.redirected && response.url.includes('login.php')) {
                window.location.href = response.url;
                return;
            }
            return response.json().catch(() => {
                throw new Error('Server returned non-JSON response');
            });
        })
        .then(data => {
            if (data && data.success) {
                loadMessages(currentConversationId);
            } else {
                throw new Error(data?.error || 'Failed to delete message');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert(error.message);
        });
    }

    function removeMember(form) {
        if (!confirm('Are you sure you want to remove this member from the group?')) {
            return;
        }
        
        const formData = new FormData(form);
        
        fetch('chat.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadGroupInfo(currentConversationId);
                loadMessages(currentConversationId);
            } else {
                alert(data.error || 'Failed to remove member');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to remove member');
        });
    }

    function addMembers(form) {
        const formData = new FormData(form);
        
        fetch('chat.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeAddMembersModal();
                loadGroupInfo(currentConversationId);
                loadMessages(currentConversationId);
            } else {
                alert(data.error || 'Failed to add members');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to add members');
        });
    }

    function previewFile() {
        const fileInput = document.getElementById('file-upload');
        const filePreview = document.getElementById('file-preview');
        const fileName = document.getElementById('file-name');
        
        if (fileInput && filePreview && fileName && fileInput.files.length > 0) {
            const file = fileInput.files[0];
            fileName.textContent = file.name;
            filePreview.style.display = 'flex';
        }
    }

    function clearFile() {
        const fileInput = document.getElementById('file-upload');
        const filePreview = document.getElementById('file-preview');
        if (fileInput && filePreview) {
            fileInput.value = '';
            filePreview.style.display = 'none';
        }
    }

    function getFileIcon(filePath) {
        if (!filePath) return 'fa-file';
        const fileExt = filePath.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) return 'fa-image';
        if (['pdf'].includes(fileExt)) return 'fa-file-pdf';
        if (['doc', 'docx'].includes(fileExt)) return 'fa-file-word';
        if (['xls', 'xlsx'].includes(fileExt)) return 'fa-file-excel';
        if (['ppt', 'pptx'].includes(fileExt)) return 'fa-file-powerpoint';
        if (['zip', 'rar', '7z'].includes(fileExt)) return 'fa-file-archive';
        return 'fa-file';
    }

    function getFilePreview(filePath) {
        if (!filePath) return '';
        const fileExt = filePath.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) {
            return `<img src="${filePath}" class="max-w-full max-h-48 rounded mt-2 cursor-pointer" 
                    onclick="window.open('${filePath}', '_blank')">`;
        }
        return '';
    }

    // Group modal functions
    function openGroupModal() {
        document.getElementById('groupModal').classList.remove('hidden');
    }

    function closeGroupModal() {
        document.getElementById('groupModal').classList.add('hidden');
    }

    function openAddMembersModal(conversationId, groupId) {
        document.getElementById('addMembersConversationId').value = conversationId;
        document.getElementById('addMembersGroupId').value = groupId;
        
        // Load available members
        fetch(`get_available_members.php?group_id=${groupId}`)
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('availableMembersList');
                container.innerHTML = '';
                
                if (data.length === 0) {
                    container.innerHTML = '<p class="text-sm text-gray-500 py-2">No available members to add</p>';
                    return;
                }
                
                data.forEach(member => {
                    const memberDiv = document.createElement('div');
                    memberDiv.className = 'flex items-center p-2 hover:bg-gray-50';
                    memberDiv.innerHTML = `
                        <input type="checkbox" name="new_members[]" value="${member.id}" 
                               id="new_member_${member.id}" class="mr-2">
                        <label for="new_member_${member.id}" class="flex items-center">
                            ${member.image_path ? 
                                `<img src="${member.image_path}" class="w-6 h-6 rounded-full mr-2">` : 
                                `<div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center mr-2">
                                    <span class="text-gray-600 text-xs">
                                        ${member.full_name.charAt(0).toUpperCase()}
                                    </span>
                                </div>`
                            }
                            <span>${member.full_name}</span>
                        </label>
                    `;
                    container.appendChild(memberDiv);
                });
                
                document.getElementById('addMembersModal').classList.remove('hidden');
            })
            .catch(error => {
                console.error('Error loading available members:', error);
                alert('Failed to load available members');
            });
    }

    function closeAddMembersModal() {
        document.getElementById('addMembersModal').classList.add('hidden');
    }

    window.addEventListener('beforeunload', () => {
        clearInterval(pollInterval);
    });
</script>
</body>
</html>