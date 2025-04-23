<?php
// Start output buffering and session
ob_start();
session_start();

// Set JSON header immediately
header('Content-Type: application/json');

// Prevent any accidental output
function cleanOutput($data) {
    return is_string($data) ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : $data;
}

try {
    // Validate session first
    if (empty($_SESSION['user_id'])) {
        throw new Exception('Session expired. Please login again.', 401);
    }

    require_once 'database.php'; // Load DB connection after session check

    // Validate input
    if (empty($_POST['conversation_id'])) {
        throw new Exception('Missing conversation ID', 400);
    }

    // Process message
    $conversation_id = (int)$_POST['conversation_id'];
    $user_id = (int)$_SESSION['user_id'];
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';

    // File upload handling
    $file_path = '';
    $file_name = '';
    
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $upload_dir = 'uploads/';
        $allowed_types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            // Add other allowed types...
        ];

        // Validate and move file
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $file_type = finfo_file($file_info, $_FILES['file']['tmp_name']);
        finfo_close($file_info);

        if (!array_key_exists($file_type, $allowed_types)) {
            throw new Exception('Invalid file type', 400);
        }

        $file_ext = $allowed_types[$file_type];
        $file_name = bin2hex(random_bytes(8)) . '.' . $file_ext;
        $file_path = $upload_dir . $file_name;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $file_path)) {
            throw new Exception('File upload failed', 500);
        }
    }

    // Verify conversation participation
    $stmt = $pdo->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversation_id, $user_id]);
    
    if (!$stmt->fetch()) {
        if ($file_path && file_exists($file_path)) {
            unlink($file_path);
        }
        throw new Exception('Not in conversation', 403);
    }

    // Insert message
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO messages (conversation_id, sender_id, content, file_path, file_name) 
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$conversation_id, $user_id, $message, $file_path, $file_name]);
    
    // Update conversation activity
    $pdo->prepare("UPDATE conversations SET last_activity = NOW() WHERE id = ?")
       ->execute([$conversation_id]);
    
    $pdo->commit();

    // Successful response
    echo json_encode([
        'success' => true,
        'message_id' => $pdo->lastInsertId()
    ]);

} catch (Throwable $e) {
    // Clean up any file on error
    if (!empty($file_path) && file_exists($file_path)) {
        @unlink($file_path);
    }

    // Ensure proper error response
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'error' => cleanOutput($e->getMessage())
    ]);
} finally {
    // Guaranteed cleanup
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_flush();
    exit();
}