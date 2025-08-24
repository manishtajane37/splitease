<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$group_id = intval($_POST['group_id']);

// Check if group exists
$stmt = $conn->prepare("SELECT * FROM groups WHERE id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$group) {
    $_SESSION['message'] = "<div class='alert alert-danger'>Group not found.</div>";
    header("Location: group.php");
    exit();
}

// Check if already a member
$stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
$stmt->bind_param("ii", $group_id, $user_id);
$stmt->execute();
$is_member = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($is_member) {
    $_SESSION['message'] = "<div class='alert alert-warning'>You are already a member of this group.</div>";
    header("Location: group.php");
    exit();
}

// Check if request already pending
$stmt = $conn->prepare("SELECT 1 FROM group_join_requests WHERE group_id=? AND user_id=? AND status='pending'");
$stmt->bind_param("ii", $group_id, $user_id);
$stmt->execute();
$is_pending = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($is_pending) {
    $_SESSION['message'] = "<div class='alert alert-info'>Your request to join this group is already pending approval.</div>";
    header("Location: group.php");
    exit();
}

// Insert join request
$stmt = $conn->prepare("INSERT INTO group_join_requests (group_id, user_id) VALUES (?, ?)");
$stmt->bind_param("ii", $group_id, $user_id);
$stmt->execute();
$stmt->close();

$_SESSION['message'] = "<div class='alert alert-success'>Join request sent to the group admin for approval.</div>";
header("Location: group.php");
exit();
?>
