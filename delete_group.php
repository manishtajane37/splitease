<?php
session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $group_id = $_POST['group_id'];
    $admin_id = $_SESSION['user_id'];

    // Step 1: Check if current user is the group's creator
    $check = $conn->prepare("SELECT * FROM groups WHERE id = ? AND created_by = ?");
    if (!$check) {
        die("Prepare failed: " . $conn->error);
    }
    $check->bind_param("ii", $group_id, $admin_id);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows === 0) {
        echo "❌ You are not allowed to delete this group.";
        exit;
    }

    // Step 2: Delete from group_members
    $stmt1 = $conn->prepare("DELETE FROM group_members WHERE group_id = ?");
    if (!$stmt1) {
        die("Delete group_members failed: " . $conn->error);
    }
    $stmt1->bind_param("i", $group_id);
    $stmt1->execute();

    // Step 3: Delete from expenses
    $stmt2 = $conn->prepare("DELETE FROM expenses WHERE group_id = ?");
    if (!$stmt2) {
        die("Delete expenses failed: " . $conn->error);
    }
    $stmt2->bind_param("i", $group_id);
    $stmt2->execute();

    // Step 4: Delete from settlements
    $stmt3 = $conn->prepare("DELETE FROM settlements WHERE group_id = ?");
    if (!$stmt3) {
        die("Delete settlements failed: " . $conn->error);
    }
    $stmt3->bind_param("i", $group_id);
    $stmt3->execute();

    // Step 5: Delete from groups table
    $stmt4 = $conn->prepare("DELETE FROM groups WHERE id = ?");
    if (!$stmt4) {
        die("Delete group failed: " . $conn->error);
    }
    $stmt4->bind_param("i", $group_id);
    $stmt4->execute();

    echo "<script>alert('✅ Group deleted successfully'); window.location.href='group.php';</script>";
}
?>

