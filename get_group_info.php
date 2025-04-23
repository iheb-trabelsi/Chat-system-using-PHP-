<?php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

$current_user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

try {
    // Get conversation and group info
    $stmt = $pdo->prepare("
        SELECT 
            c.id, 
            c.is_group,
            g.id AS group_id,
            g.name,
            g.description,
            g.image_path,
            g.created_by AS admin_id,
            (g.created_by = ?) AS is_admin
        FROM conversations c
        LEFT JOIN groups g ON c.group_id = g.id AND c.is_group = TRUE
        WHERE c.id = ? AND EXISTS (
            SELECT 1 FROM conversation_participants 
            WHERE conversation_id = c.id AND user_id = ?
        )
    ");
    $stmt->execute([$current_user_id, $conversation_id, $current_user_id]);
    $conversation = $stmt->fetch();
    
    if (!$conversation) {
        header('HTTP/1.1 404 Not Found');
        exit();
    }
    
    $response = [
        'is_group' => (bool)$conversation['is_group'],
        'name' => $conversation['name'],
        'description' => $conversation['description'],
        'image_path' => $conversation['image_path'],
        'group_id' => $conversation['group_id'],
        'admin_id' => $conversation['admin_id'],
        'is_admin' => (bool)$conversation['is_admin'],
        'members' => []
    ];
    
    if ($conversation['is_group']) {
        // Get group members
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.email, u.image_path
            FROM conversation_participants cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.conversation_id = ?
        ");
        $stmt->execute([$conversation_id]);
        $response['members'] = $stmt->fetchAll();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}