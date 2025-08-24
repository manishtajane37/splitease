<?php
session_start();
include 'db.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ✅ Step 1: Safely get group_id from POST or GET
$group_id = $_POST['group_id'] ?? $_GET['group_id'] ?? null;
if (!$group_id) {
    echo "<h3 style='color:red;'>Error: group_id is missing.</h3>";
    exit();
}

$admin_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];

    // Step 2: Check if user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_id = $row['id'];

        $check = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
        $check->bind_param("ii", $group_id, $user_id);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {
            echo "<script>alert('User is already in the group.'); window.location.href='view_group.php?group_id=$group_id';</script>";
        } else {
            $add = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            $add->bind_param("ii", $group_id, $user_id);
            $add->execute();

            echo "<script>alert('User added successfully.'); window.location.href='view_group.php?group_id=$group_id';</script>";
        }
    } else {
        // Send invite email and store invite
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'spliteasify@gmail.com';
            $mail->Password = 'rqpc ksek yqgf zisx';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('spliteasify@gmail.com', 'SplitEase');
            $mail->addAddress($email);
            $mail->Subject = 'You’re invited to SplitEase';
            $mail->Body    = "You were invited to join a SplitEase group.\nPlease sign up at: http://localhost/splitease/signup.php";

            $mail->send();

            $invite = $conn->prepare("INSERT INTO group_invites (group_id, email) VALUES (?, ?)");
            $invite->bind_param("is", $group_id, $email);
            $invite->execute();

            echo "<script>alert('User not found. Invitation email sent.'); window.location.href='view_group.php?group_id=$group_id';</script>";
        } catch (Exception $e) {
            echo "Mailer Error: {$mail->ErrorInfo}";
        }
    }
}
?>

<!-- ✅ HTML Form -->
<!DOCTYPE html>
<html>
<head>
    <title>Add Member</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-5 bg-light">
<div class="container">
    <h3>Add Member to Group</h3>
    <form method="POST">
        <!-- ✅ Hidden field that carries group_id -->
        <input type="hidden" name="group_id" value="<?php echo htmlspecialchars($group_id); ?>">

        <div class="mb-3">
            <label>Email address of member:</label>
            <input type="email" name="email" class="form-control" placeholder="friend@example.com" required>
        </div>
        <button type="submit" class="btn btn-primary">Add Member</button>
    </form>
</div>
</body>
</html>
