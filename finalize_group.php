<?php
session_start();
require_once 'db.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$group_id = intval($_POST['group_id'] ?? 0);
$user_id = $_SESSION['user_id'];

// Validate group
$stmt = $conn->prepare("SELECT created_by FROM groups WHERE id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();
$group = $result->fetch_assoc();
$stmt->close();

if (!$group) {
    $_SESSION['error'] = "Group not found.";
    header("Location: view_group.php?group_id=" . $group_id);
    exit();
}

// Only admin (creator) can finalize
if ($group['created_by'] != $user_id) {
    $_SESSION['error'] = "Only the group admin can finalize expenses.";
    header("Location: view_group.php?group_id=" . $group_id);
    exit();
}

// Update group to finalized
$stmt = $conn->prepare("UPDATE groups SET is_expenses_final = 1 WHERE id = ?");
$stmt->bind_param("i", $group_id);
if ($stmt->execute()) {
    $_SESSION['success'] = "Group expenses have been finalized. No new expenses can be added.";
} else {
    $_SESSION['error'] = "Failed to finalize group: " . $stmt->error;
}
$stmt->close();

// Redirect back
header("Location: view_group.php?group_id=" . $group_id);
exit();
