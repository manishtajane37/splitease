<?php
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['group_id'])) {
    $group_id = intval($_POST['group_id']);
    $user_id = $_SESSION['user_id'];
    
    // Verify user is member of this group
    $verify_stmt = $conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE group_id = ? AND user_id = ?");
    $verify_stmt->bind_param("ii", $group_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    $verify_row = $verify_result->fetch_assoc();
    
    if ($verify_row['count'] == 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    $verify_stmt->close();
    
    // Get all members of the group
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email 
        FROM users u 
        JOIN group_members gm ON u.id = gm.user_id 
        WHERE gm.group_id = ? 
        ORDER BY u.username
    ");
    
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        $members[] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'email' => $row['email']
        ];
    }
    
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode($members);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
}
?>