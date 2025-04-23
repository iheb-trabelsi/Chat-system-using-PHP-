<?php
session_start();
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

if (!isset($_GET['conversation_id'])) {
    echo json_encode(['error' => 'Missing conversation ID']);
    exit();
}

$conversation_id = (int)$_GET['conversation_id'];
$current_user_id = $_SESSION['user_id'];

try {
    // Verify user is in conversation
    $stmt = $pdo->prepare("
        SELECT 1 FROM conversation_participants 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversation_id, $current_user_id]);
    
    if ($stmt->rowCount() === 0) {
        echo json_encode(['error' => 'Not in conversation']);
        exit();
    }

    // Get messages with sender info
    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.conversation_id,
            m.sender_id,
            m.content,
            m.file_path,
            m.file_name,
            m.sent_at,
            u.full_name as sender_name,
            u.image_path as sender_image
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ?
        ORDER BY m.sent_at ASC
    ");
    $stmt->execute([$conversation_id]);
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($messages);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}