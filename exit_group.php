<?php
session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $group_id = $_POST['group_id'];
    $user_id = $_POST['user_id'];

    // Prevent the creator from exiting
    $check_admin = $conn->prepare("SELECT * FROM groups WHERE id = ? AND created_by = ?");
    $check_admin->bind_param("ii", $group_id, $user_id);
    $check_admin->execute();
    $result = $check_admin->get_result();

    if ($result->num_rows > 0) {
        echo "<script>alert('❌ You are the admin. Please delete the group instead.'); window.location.href='group.php';</script>";
        exit;
    }

    // Exit group
    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $group_id, $user_id);
    $stmt->execute();

    echo "<script>alert('✅ You have exited the group.'); window.location.href='group.php';</script>";
}
?>

