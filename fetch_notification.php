<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch last 5 notifications
$sql = "SELECT id, message, link, created_at FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        'id' => $row['id'],  // needed to mark as read
        'message' => $row['message'],
        'link' => $row['link'],  // no default, use actual DB value
        'created_at' => date("d M Y, h:i A", strtotime($row['created_at']))
    ];
}

header('Content-Type: application/json');
echo json_encode($notifications);
?>