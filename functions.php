<?php
function addNotification($conn, $userId, $message, $link = 'settlements.php') {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $message, $link);
    $stmt->execute();
}
?>




