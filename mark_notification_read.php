<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['id'])) {
    exit('Unauthorized');
}

$user_id = $_SESSION['user_id'];
$notif_id = intval($_POST['id']);

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $notif_id, $user_id);
$stmt->execute();

echo "success";
?>