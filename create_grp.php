<?php
session_start();
include 'db.php'; // make sure your db.php has proper DB connection
require 'send_invite_email.php'; // ✅ Add this

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$creator_id = $_SESSION['user_id'];
$errors = [];
$success = "";
$added_members = [];
$group_id = null; // ✅ Initialize group_id

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_name = trim($_POST['group_name']);
    $member_emails = isset($_POST['member_emails']) ? json_decode($_POST['member_emails'], true) : [];
    if (!is_array($member_emails)) $member_emails = [];

    if (empty($group_name)) {
        $errors[] = "Group name is required.";
    }

    if (empty($errors)) {
        // Insert into groups
        $stmt = $conn->prepare("INSERT INTO groups (name, created_by) VALUES (?, ?)");
        $stmt->bind_param("si", $group_name, $creator_id);
        if ($stmt->execute()) {
            $group_id = $stmt->insert_id; // ✅ Store group_id for later use

            // Add creator as a member
            $stmt_member = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            $stmt_member->bind_param("ii", $group_id, $creator_id);
            $stmt_member->execute();
            $stmt_member->close(); // ✅ Close statement

            // Handle member emails
            foreach ($member_emails as $email) {
                $email = strtolower(trim($email));

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {

                    // (Optional: Skip creator if their email is in the list)
                    if (isset($_SESSION['user_email']) && $email === strtolower($_SESSION['user_email'])) {
                        $added_members[] = "$email (skipped – creator)";
                        continue;
                    }

                    // Check if user exists
                    $stmt_user = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt_user->bind_param("s", $email);
                    $stmt_user->execute();
                    $stmt_user->store_result();

                    if ($stmt_user->num_rows > 0) {
                        $stmt_user->bind_result($member_id);
                        $stmt_user->fetch();

                        // Check if already in group
                        $stmt_check = $conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
                        $stmt_check->bind_param("ii", $group_id, $member_id);
                        $stmt_check->execute();
                        $check_result = $stmt_check->get_result();

                        if ($check_result->num_rows == 0) {
                            $stmt_add = $conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
                            $stmt_add->bind_param("ii", $group_id, $member_id);
                            $stmt_add->execute();
                            $stmt_add->close(); // ✅ Close statement
                            $added_members[] = "$email (added)";
                        } else {
                            $added_members[] = "$email (already in group)";
                        }

                        $stmt_check->close();
                    } else {
                        // Check if already invited
                        $stmt_check_invite = $conn->prepare("SELECT id FROM group_invites WHERE group_id = ? AND email = ?");
                        $stmt_check_invite->bind_param("is", $group_id, $email);
                        $stmt_check_invite->execute();
                        $result_invite = $stmt_check_invite->get_result();

                        if ($result_invite->num_rows == 0) {
                            $emailResult = sendInviteEmail($email);

                            if ($emailResult === true) {
                                $stmt_invite = $conn->prepare("INSERT INTO group_invites (group_id, email) VALUES (?, ?)");
                                $stmt_invite->bind_param("is", $group_id, $email);
                                $stmt_invite->execute();
                                $stmt_invite->close();
                                $added_members[] = "$email (invited)";
                            } else {
                                $added_members[] = "$email (email failed: $emailResult)";
                            }
                        } else {
                            $added_members[] = "$email (already invited)";
                        }

                        $stmt_check_invite->close();
                    }

                    $stmt_user->close();

                } else {
                    $added_members[] = "$email (invalid email)";
                    continue;
                }
            }
            
            $stmt->close(); // ✅ Close the main statement
            $success = "Group '$group_name' created successfully!";
        } else {
            $errors[] = "Failed to create group.";
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #0c0c0c 0%, #1a1a2e 25%, #16213e 50%, #0f0f23 75%, #000000 100%);
            background-attachment: fixed;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: #ffffff;
        }

        .container {
            background: rgba(30, 30, 30, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5), 
                        0 0 0 1px rgba(255, 255, 255, 0.05),
                        inset 0 1px 0 rgba(255, 255, 255, 0.1);
            width: 100%;
            max-width: 450px;
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, #00d4ff, transparent);
        }

        h2 {
            text-align: center;
            background: linear-gradient(135deg, #00d4ff, #7b68ee, #ff6b6b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 30px;
            text-shadow: 0 0 20px rgba(0, 212, 255, 0.3);
        }

        label {
            font-weight: 600;
            color: #e0e0e0;
            margin-top: 20px;
            margin-bottom: 8px;
            display: block;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input[type="text"], input[type="email"] {
            width: 100%;
            padding: 15px 18px;
            margin-bottom: 15px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(20, 20, 20, 0.8);
            color: #ffffff;
            font-size: 16px;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
        }

        input[type="text"]:focus, input[type="email"]:focus {
            outline: none;
            border-color: #00d4ff;
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.3),
                        inset 0 1px 3px rgba(0, 0, 0, 0.2);
            transform: translateY(-2px);
        }

        input::placeholder {
            color: #888;
        }

        .member-input-container {
            position: relative;
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 15px;
        }

        .member-input-container input[type="email"] {
            flex: 1;
            margin-bottom: 0;
        }

        .add-btn {
            padding: 15px 20px;
            background: linear-gradient(135deg, #00d4ff, #0099cc);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            white-space: nowrap;
            box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
        }

        .add-btn:hover {
            background: linear-gradient(135deg, #00b8e6, #007399);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 212, 255, 0.4);
        }

        .member-list {
            background: rgba(15, 15, 15, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            min-height: 60px;
            max-height: 150px;
            overflow-y: auto;
            backdrop-filter: blur(5px);
        }

        .member-list::-webkit-scrollbar {
            width: 6px;
        }

        .member-list::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }

        .member-list::-webkit-scrollbar-thumb {
            background: #00d4ff;
            border-radius: 3px;
        }

        .member-item {
            background: rgba(0, 212, 255, 0.1);
            border: 1px solid rgba(0, 212, 255, 0.3);
            padding: 8px 12px;
            margin: 5px 0;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .member-item:hover {
            background: rgba(0, 212, 255, 0.2);
            transform: translateX(5px);
        }

        .remove-member {
            background: none;
            border: none;
            color: #ff6b6b;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .remove-member:hover {
            background: rgba(255, 107, 107, 0.2);
            transform: scale(1.2);
        }

        .empty-state {
            color: #888;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }

        .submit-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #7b68ee, #9966cc, #8a2be2);
            border: none;
            color: white;
            font-weight: 700;
            font-size: 16px;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 6px 20px rgba(123, 104, 238, 0.4);
        }

        .submit-btn:hover {
            background: linear-gradient(135deg, #6a5acd, #8a2be2, #9932cc);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(123, 104, 238, 0.5);
        }

        .submit-btn:active {
            transform: translateY(-1px);
        }

        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            backdrop-filter: blur(5px);
        }

        .alert-danger {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid rgba(255, 107, 107, 0.3);
            color: #ff8a80;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.3);
            color: #81c784;
        }

        .alert ul {
            margin-top: 10px;
            padding-left: 20px;
        }

        .alert li {
            margin: 5px 0;
            color: #a5d6a7;
        }

        @media (max-width: 480px) {
            .container {
                padding: 30px 20px;
                margin: 10px;
            }
            
            .member-input-container {
                flex-direction: column;
            }
            
            .add-btn {
                width: 100%;
            }
        }
    </style>
    <script>
        let members = [];

        function addEmail() {
            const emailInput = document.getElementById("member_email");
            const email = emailInput.value.trim();

            if (!email || !validateEmail(email)) {
                alert("Please enter a valid email address!");
                return;
            }

            if (members.includes(email)) {
                alert("This email is already added!");
                return;
            }

            members.push(email);
            updateMemberList();
            emailInput.value = "";
        }

        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        function removeMember(index) {
            members.splice(index, 1);
            updateMemberList();
        }

        function updateMemberList() {
            const list = document.getElementById("member_list");
            
            if (members.length > 0) {
                list.innerHTML = members.map((email, index) => 
                    `<div class="member-item">
                        <span>${email}</span>
                        <button type="button" class="remove-member" onclick="removeMember(${index})" title="Remove member">×</button>
                    </div>`
                ).join("");
            } else {
                list.innerHTML = '<div class="empty-state">No members added yet.</div>';
            }

            const hiddenInput = document.getElementById("member_emails");
            hiddenInput.value = JSON.stringify(members);
        }

        // Allow Enter key to add email
        document.addEventListener('DOMContentLoaded', function() {
            const emailInput = document.getElementById("member_email");
            if (emailInput) {
                emailInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addEmail();
                    }
                });
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <h2>👥 Create a New Group</h2>

        <?php if (!empty($errors)) { ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error) {
                        echo "<li>" . htmlspecialchars($error) . "</li>";
                    } ?>
                </ul>
            </div>
        <?php } ?>

        <?php if (!empty($success)) { ?>
            <div class="alert alert-success">
                <p><?php echo htmlspecialchars($success); ?></p>
                <?php if (!empty($added_members)) { ?>
                    <ul>
                        <?php foreach ($added_members as $msg) {
                            echo "<li>" . htmlspecialchars($msg) . "</li>";
                        } ?>
                    </ul>
                <?php } ?>
            </div>
        <?php } ?>

        <form method="POST">
            <label>📋 Group Name</label>
            <input type="text" name="group_name" placeholder="Enter group name" required value="<?php echo isset($_POST['group_name']) ? htmlspecialchars($_POST['group_name']) : ''; ?>">

            <label>👥 Add Members (Email)</label>
            <div class="member-input-container">
                <input type="email" id="member_email" placeholder="Enter email address">
                <button type="button" class="add-btn" onclick="addEmail()">+ Add</button>
            </div>

            <div id="member_list" class="member-list">
                <div class="empty-state">No members added yet.</div>
            </div>
            
            <input type="hidden" name="member_emails" id="member_emails" />

            <button type="submit" class="submit-btn">✨ Create Group</button>
        </form>
    </div>

    <?php if ($success && $group_id) { ?>
        <script>
            setTimeout(() => { 
                window.location.href = "view_group.php?id=<?php echo $group_id; ?>"; 
            }, 2000);
        </script>
    <?php } ?>
</body>
</html>