<?php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

$current_user_id = $_SESSION['user_id'];
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

try {
    // Verify user is admin of the group
    $stmt = $pdo->prepare("
        SELECT 1 FROM groups 
        WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$group_id, $current_user_id]);
    
    if ($stmt->rowCount() === 0) {
        header('HTTP/1.1 403 Forbidden');
        exit();
    }
    
    // Get available members (connections not already in group)
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.image_path
        FROM user_relationships ur
        JOIN users u ON (
            (ur.user_id = u.id AND ur.related_user_id = ?) OR 
            (ur.related_user_id = u.id AND ur.user_id = ?)
        )
        WHERE ur.status = 'accepted'
        AND u.id != ?
        AND u.id NOT IN (
            SELECT cp.user_id 
            FROM conversation_participants cp
            JOIN conversations c ON cp.conversation_id = c.id
            WHERE c.group_id = ?
        )
    ");
    $stmt->execute([
        $current_user_id,
        $current_user_id,
        $current_user_id,
        $group_id
    ]);
    
    $members = $stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode($members);
} catch (PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}