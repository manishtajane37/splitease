<?php

include 'db.php';
require_once 'functions.php';
session_start();


// 🔧 Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ Check login
if (!isset($_SESSION['user_id'])) {
    echo "You must be logged in.";
    exit();
}

$user_id = $_SESSION['user_id'];

// ✅ Check group_id
// ✅ Accept both 'id' and 'group_id' in URL
// ✅ Accept group_id from GET or POST
$group_id = null;

if (isset($_POST['group_id']) && is_numeric($_POST['group_id'])) {
    $group_id = intval($_POST['group_id']);
} elseif (isset($_GET['group_id']) && is_numeric($_GET['group_id'])) {
    $group_id = intval($_GET['group_id']);
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $group_id = intval($_GET['id']);
}

if (!$group_id) {
    echo "Group ID not provided or invalid.";
    exit();
}

// ✅ Fetch group + creator name
$stmt = $conn->prepare("SELECT g.*, u.username AS creator_name 
                        FROM groups g
                        JOIN users u ON g.created_by = u.id
                        WHERE g.id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();
$group = $result->fetch_assoc();
$stmt->close();

if (!$group) {
    echo "Group not found.";
    exit();
}

// ✅ Check if current user is group admin
$is_admin = ($user_id == $group['created_by']);

// ✅ If admin, fetch pending join requests
$pending_requests = [];
if ($is_admin) {
    $stmt = $conn->prepare("
        SELECT r.id AS request_id, u.username, u.email, r.requested_at
        FROM group_join_requests r
        JOIN users u ON r.user_id = u.id
        WHERE r.group_id = ? AND r.status = 'pending'
        ORDER BY r.requested_at ASC
    ");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $pending_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}


// ✅ Check if user is member of this group OR is admin
$stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
$stmt->bind_param("ii", $group_id, $user_id);
$stmt->execute();
$is_member = $stmt->get_result()->num_rows > 0;
$stmt->close();

// ✅ Handle approve/reject join request (FIXED VERSION)
if ($is_admin && isset($_POST['action']) && isset($_POST['request_id'])) {
    $req_id = intval($_POST['request_id']);
    $action = $_POST['action'];

    // Get request details
    $stmt = $conn->prepare("SELECT group_id, user_id FROM group_join_requests WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $req_id, $group_id);
    $stmt->execute();
    $req_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($req_data) {
        if ($action === 'approve') {
            // ✅ Check if already in group before inserting
            $check_stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
            $check_stmt->bind_param("ii", $req_data['group_id'], $req_data['user_id']);
            $check_stmt->execute();
            $is_already_member = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();

            if (!$is_already_member) {
                $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
                $stmt->bind_param("ii", $req_data['group_id'], $req_data['user_id']);
                $stmt->execute();
                $stmt->close();
            }

            // Update request status to accepted
            $stmt = $conn->prepare("UPDATE group_join_requests SET status='accepted' WHERE id=?");
            $stmt->bind_param("i", $req_id);
            $stmt->execute();
            $stmt->close();

            // ✅ FIXED: Send APPROVAL notification (moved to correct block)
            addNotification(
                $conn,
                $req_data['user_id'],
                "Your request to join group <strong>" . htmlspecialchars($group['name']) . "</strong> has been approved! Welcome to the group!",
                "view_group.php?group_id=" . $group_id
            );

            $success_message = "<div class='alert alert-success'>Member approved successfully!</div>";

        } elseif ($action === 'reject') {
            // Update request status to rejected
            $stmt = $conn->prepare("UPDATE group_join_requests SET status='rejected' WHERE id=?");
            $stmt->bind_param("i", $req_id);
            $stmt->execute();
            $stmt->close();

            // ✅ Send REJECTION notification
            addNotification(
                $conn,
                $req_data['user_id'],
                "Your request to join group <strong>" . htmlspecialchars($group['name']) . "</strong> has been rejected.",
                "dash.php" // Redirect to dashboard instead of group page
            );

            $success_message = "<div class='alert alert-warning'>Request rejected.</div>";
        }
    }

    // 🔄 Reload the page after action
    header("Location: view_group.php?group_id=" . $group_id);
    exit();
}

// ✅ Handle member removal (admin only)
if (isset($_POST['remove_member']) && $is_admin) {
    $member_to_remove = intval($_POST['member_id']);
    
    // Don't allow admin to remove themselves or the group creator
    if ($member_to_remove != $user_id && $member_to_remove != $group['created_by']) {
        // Start transaction for data consistency
        $conn->begin_transaction();
        
        try {
            // Remove from group_members
            $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $group_id, $member_to_remove);
            $stmt->execute();
            $stmt->close();
            
            // Remove from expense_splits for this group
            $stmt = $conn->prepare("DELETE es FROM expense_splits es 
                                   JOIN expenses e ON es.expense_id = e.id 
                                   WHERE e.group_id = ? AND es.user_id = ?");
            $stmt->bind_param("ii", $group_id, $member_to_remove);
            $stmt->execute();
            $stmt->close();
            
            // Remove from expense_payers table
            $stmt = $conn->prepare("DELETE ep FROM expense_payers ep 
                                   JOIN expenses e ON ep.expense_id = e.id 
                                   WHERE e.group_id = ? AND ep.user_id = ?");
            $stmt->bind_param("ii", $group_id, $member_to_remove);
            $stmt->execute();
            $stmt->close();
            
            // Remove from settlements
            $stmt = $conn->prepare("DELETE FROM settlements WHERE group_id = ? AND (paid_by = ? OR paid_to = ?)");
            $stmt->bind_param("iii", $group_id, $member_to_remove, $member_to_remove);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            $success_message = "<div class='alert alert-success'>Member removed successfully!</div>";
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "<div class='alert alert-danger'>Error removing member: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $error_message = "<div class='alert alert-danger'>Cannot remove group creator or yourself!</div>";
    }
}

// ✅ Handle approve/reject join request
if ($is_admin && isset($_POST['action']) && isset($_POST['request_id'])) {
    $req_id = intval($_POST['request_id']);
    $action = $_POST['action'];

    // Get request details
    $stmt = $conn->prepare("SELECT group_id, user_id FROM group_join_requests WHERE id=? AND group_id=?");
    $stmt->bind_param("ii", $req_id, $group_id);
    $stmt->execute();
    $req_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($req_data) {
        if ($action === 'approve') {
            // ✅ Check if already in group before inserting
            $check_stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
            $check_stmt->bind_param("ii", $req_data['group_id'], $req_data['user_id']);
            $check_stmt->execute();
            $is_already_member = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();

            if (!$is_already_member) {
                $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, joined_at) VALUES (?, ?, NOW())");
                $stmt->bind_param("ii", $req_data['group_id'], $req_data['user_id']);
                $stmt->execute();
                $stmt->close();
            }

            // Update request status
            $stmt = $conn->prepare("UPDATE group_join_requests SET status='accepted' WHERE id=?");
            $stmt->bind_param("i", $req_id);
            $stmt->execute();
            $stmt->close();

            $success_message = "<div class='alert alert-success'>Member approved successfully!</div>";

        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE group_join_requests SET status='rejected' WHERE id=?");
            $stmt->bind_param("i", $req_id);
            $stmt->execute();
            $stmt->close();

                // ✅ Send notification to the user
    addNotification(
        $conn,
        $req_data['user_id'],
        "Your request to join group <strong>" . htmlspecialchars($group['name']) . "</strong> has been approved!",
        "view_group.php?group_id=" . $group_id
    );


            $success_message = "<div class='alert alert-danger'>Request rejected.</div>";
        }
    }

    // 🔄 Reload the page after action
    header("Location: view_group.php?group_id=" . $group_id);
    exit();
}




// ✅ Fetch group members with proper role indication
// Include creator even if not in group_members table
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username, u.email, 
           COALESCE(gm.joined_at, g.created_at) as joined_at,
           CASE WHEN u.id = g.created_by THEN 1 ELSE 0 END as is_creator,
           CASE WHEN u.id = g.created_by THEN 'Creator' 
                WHEN gm.user_id IS NOT NULL THEN 'Member' 
                ELSE 'Admin' END as role
    FROM users u
    LEFT JOIN group_members gm ON gm.user_id = u.id AND gm.group_id = ?
    JOIN groups g ON g.id = ?
    WHERE (gm.group_id = ? AND gm.user_id = u.id) OR u.id = g.created_by
    ORDER BY is_creator DESC, joined_at ASC
");
$stmt->bind_param("iii", $group_id, $group_id, $group_id);
$stmt->execute();
$members = $stmt->get_result();

// Get member count for statistics
$member_count = $members->num_rows;

// ✅ Fetch expenses with multi-payer support - showing all payers
$stmt = $conn->prepare("SELECT e.*, 
    GROUP_CONCAT(DISTINCT CONCAT(u.username, ' (₹', FORMAT(ep.amount_paid, 2), ')') SEPARATOR ', ') as payers_info,
    GROUP_CONCAT(DISTINCT u.username SEPARATOR ', ') as payer_names,
    COUNT(DISTINCT ep.user_id) as payer_count
FROM expenses e 
LEFT JOIN expense_payers ep ON e.id = ep.expense_id
LEFT JOIN users u ON ep.user_id = u.id
WHERE e.group_id = ? 
GROUP BY e.id
ORDER BY e.created_at DESC
LIMIT 10");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$expenses = $stmt->get_result();

// ✅ Get total group statistics
$stmt = $conn->prepare("SELECT COUNT(*) as total_expenses, 
                        COALESCE(SUM(amount), 0) as total_amount 
                        FROM expenses WHERE group_id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ✅ Calculate user's financial summary with multi-payer support
// Total paid by user (from expense_payers table)
$stmt = $conn->prepare("SELECT COALESCE(SUM(ep.amount_paid), 0) AS total_paid 
                        FROM expense_payers ep
                        JOIN expenses e ON ep.expense_id = e.id
                        WHERE ep.user_id = ? AND e.group_id = ?");
$stmt->bind_param("ii", $user_id, $group_id);
$stmt->execute();
$paid = $stmt->get_result()->fetch_assoc()['total_paid'];
$stmt->close();

// Total owed by user (from expense_splits table)
$stmt = $conn->prepare("SELECT COALESCE(SUM(es.amount_owed), 0) AS total_owed 
                        FROM expense_splits es 
                        JOIN expenses e ON es.expense_id = e.id 
                        WHERE es.user_id = ? AND e.group_id = ?");
$stmt->bind_param("ii", $user_id, $group_id);
$stmt->execute();
$owed = $stmt->get_result()->fetch_assoc()['total_owed'];
$stmt->close();

$net = $paid - $owed;

// ✅ Get settlements for this user
$stmt = $conn->prepare("SELECT 
    s.amount,
    payer.username as payer_name,
    receiver.username as receiver_name,
    s.paid_by,
    s.paid_to
FROM settlements s
JOIN users payer ON s.paid_by = payer.id
JOIN users receiver ON s.paid_to = receiver.id
WHERE s.group_id = ? AND (s.paid_by = ? OR s.paid_to = ?)
ORDER BY s.amount DESC");
$stmt->bind_param("iii", $group_id, $user_id, $user_id);
$stmt->execute();
$settlements = $stmt->get_result();

// ✅ Calculate balances for all members
$balances = [];

// Step 1: Fetch all group members (including creator)
$member_stmt = $conn->prepare("
    SELECT u.id, u.username
    FROM users u
    WHERE u.id IN (
        SELECT user_id FROM group_members WHERE group_id = ?
        UNION SELECT created_by FROM groups WHERE id = ?
    )
");
$member_stmt->bind_param("ii", $group_id, $group_id);
$member_stmt->execute();
$member_result = $member_stmt->get_result();

while ($row = $member_result->fetch_assoc()) {
    $member_user_id = $row['id'];
    $username = $row['username'];

    // Total paid by user
    $stmt_paid = $conn->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) AS total_paid
        FROM expense_payers ep
        JOIN expenses e ON ep.expense_id = e.id
        WHERE ep.user_id = ? AND e.group_id = ?
    ");
    $stmt_paid->bind_param("ii", $member_user_id, $group_id);
    $stmt_paid->execute();
    $paid_result = $stmt_paid->get_result()->fetch_assoc();
    $total_paid = $paid_result['total_paid'];
    $stmt_paid->close();

    // Total owed by user
    $stmt_owed = $conn->prepare("
        SELECT COALESCE(SUM(amount_owed), 0) AS total_owed
        FROM expense_splits es
        JOIN expenses e ON es.expense_id = e.id
        WHERE es.user_id = ? AND e.group_id = ?
    ");
    $stmt_owed->bind_param("ii", $member_user_id, $group_id);
    $stmt_owed->execute();
    $owed_result = $stmt_owed->get_result()->fetch_assoc();
    $total_owed = $owed_result['total_owed'];
    $stmt_owed->close();

    $member_net = round($total_paid - $total_owed, 2);
    $balances[] = [
        'username' => $username,
        'net' => $member_net
    ];


}
$member_stmt->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($group['name']) ?> - Group Details</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="morden_style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        /* Modern Enhanced Button Group Styles */
.btn-group.flex-wrap .btn {
    margin: 10px;
    border-radius: 16px;
    padding: 14px 28px;
    font-weight: 600;
    font-size: 0.95rem;
    letter-spacing: 0.3px;
    position: relative;
    overflow: hidden;
    border: none;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    box-shadow: 
        0 4px 15px rgba(0, 0, 0, 0.12),
        0 2px 4px rgba(0, 0, 0, 0.08);
    backdrop-filter: blur(10px);
    transform: translateY(0);
}

/* Icon styling */
.btn-group.flex-wrap .btn i {
    margin-right: 8px;
    font-size: 1.15rem;
    transition: transform 0.3s ease;
}

/* Shimmer effect overlay */
.btn-group.flex-wrap .btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(
        90deg,
        transparent,
        rgba(255, 255, 255, 0.2),
        transparent
    );
    transition: left 0.5s ease;
}

/* Hover effects */
.btn-group.flex-wrap .btn:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 
        0 12px 28px rgba(0, 0, 0, 0.18),
        0 6px 12px rgba(0, 0, 0, 0.12);
}

.btn-group.flex-wrap .btn:hover::before {
    left: 100%;
}

.btn-group.flex-wrap .btn:hover i {
    transform: translateX(2px);
}

/* Active/Pressed state */
.btn-group.flex-wrap .btn:active {
    transform: translateY(-1px) scale(0.98);
    box-shadow: 
        0 4px 12px rgba(0, 0, 0, 0.15),
        0 2px 4px rgba(0, 0, 0, 0.1);
    transition: all 0.1s ease;
}

/* Focus state for accessibility */
.btn-group.flex-wrap .btn:focus {
    outline: none;
    box-shadow: 
        0 4px 15px rgba(0, 0, 0, 0.12),
        0 0 0 3px rgba(0, 123, 255, 0.3);
}

/* Enhanced Primary Button */
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    position: relative;
}

.btn-primary::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(135deg, #7c3aed 0%, #3b82f6 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.btn-primary:hover::after {
    opacity: 1;
}

.btn-primary:hover {
    color: white;
}

/* Enhanced Success Button */
.btn-success {
    background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
    color: #065f46;
    font-weight: 700;
}

.btn-success:hover {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

/* Enhanced Outline Primary Button */
.btn-outline-primary {
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid transparent;
    background-clip: padding-box;
    position: relative;
    color: #3b82f6;
    backdrop-filter: blur(10px);
}

.btn-outline-primary::before {
    content: '';
    position: absolute;
    inset: 0;
    padding: 2px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    border-radius: inherit;
    mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    mask-composite: xor;
    -webkit-mask-composite: xor;
}

.btn-outline-primary:hover {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
    color: white;
    transform: translateY(-4px) scale(1.02);
}

/* Enhanced Secondary Button */
.btn-secondary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    opacity: 0.9;
}

.btn-secondary:hover {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    opacity: 1;
    color: white;
}

/* Warning Button */
.btn-warning {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
    color: #92400e;
    font-weight: 600;
}

.btn-warning:hover {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

/* Danger Button */
.btn-danger {
    background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
    color: #991b1b;
    font-weight: 600;
}

.btn-danger:hover {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

/* Info Button */
.btn-info {
    background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
    color: #0c4a6e;
    font-weight: 600;
}

.btn-info:hover {
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    color: white;
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .btn-group.flex-wrap .btn {
        box-shadow: 
            0 4px 15px rgba(0, 0, 0, 0.3),
            0 2px 4px rgba(0, 0, 0, 0.2);
    }
    
    .btn-group.flex-wrap .btn:hover {
        box-shadow: 
            0 12px 28px rgba(0, 0, 0, 0.4),
            0 6px 12px rgba(0, 0, 0, 0.25);
    }
}

/* Animation for newly added buttons */
@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.btn-group.flex-wrap .btn {
    animation: slideInUp 0.3s ease-out;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .btn-group.flex-wrap .btn {
        margin: 6px;
        padding: 12px 20px;
        font-size: 0.9rem;
    }
}

/* Loading state */
.btn.loading {
    pointer-events: none;
    opacity: 0.7;
}

.btn.loading::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    margin: auto;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
        .creator-badge { 
            background: linear-gradient(45deg, #dc3545, #b02a37); 
            border: none;
        }
        .admin-badge { 
            background: linear-gradient(45deg, #007bff, #0056b3); 
            border: none;
        }
        .member-badge { 
            background: linear-gradient(45deg, #28a745, #1e7e34); 
            border: none;
        }
        .member-card { 
            transition: all 0.3s ease; 
            border-left: 4px solid transparent;
        }
        .member-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1); 
        }
        .member-card.creator { border-left-color: #dc3545; }
        .member-card.admin { border-left-color: #007bff; }
        .member-card.member { border-left-color: #28a745; }
        .stat-card { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
        }
        .group-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .expense-card {
            transition: all 0.3s ease;
            border-left: 4px solid #28a745;
        }
        .expense-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .multi-payer-badge {
            background: linear-gradient(45deg, #17a2b8, #138496);
            border: none;
        }
        .settlement-card {
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body class="bg-light">
<div class="container mt-4">
    
    <!-- Display success/error messages -->
    <?php if (isset($success_message)) echo $success_message; ?>
    <?php if (isset($error_message)) echo $error_message; ?>


    <!-- Enhanced Group Header -->
    <div class="group-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-2">
                    <i class="fas fa-users me-2"></i>
                    <?= htmlspecialchars($group['name']) ?>
                    <small class="opacity-75">(#<?= $group['id'] ?>)</small>
                </h2>
                <?php if (!empty($group['description'])): ?>
                    <p class="mb-2 opacity-90"><?= htmlspecialchars($group['description']) ?></p>
                <?php endif; ?>
                <small class="opacity-75">
                    <i class="fas fa-user me-1"></i>
                    Created by <?= htmlspecialchars($group['creator_name'] ?? 'Unknown') ?> on <?= date("d M Y", strtotime($group['created_at'])) ?>
                </small>
            </div>
            <div class="col-md-4 text-end">
                <?php if ($is_admin): ?>
                    <span class="badge creator-badge text-white mb-2 fs-6">
                        <i class="fas fa-crown"></i> Group Creator
                    </span>
                <?php else: ?>
                    <span class="badge member-badge text-white mb-2 fs-6">
                        <i class="fas fa-user"></i> Group Member
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Group Statistics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card stat-card text-center">
                <div class="card-body">
                    <i class="fas fa-users fa-2x mb-2"></i>
                    <h4><?= $member_count ?></h4>
                    <small>Members</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card text-center">
                <div class="card-body">
                    <i class="fas fa-receipt fa-2x mb-2"></i>
                    <h4><?= $group_stats['total_expenses'] ?></h4>
                    <small>Expenses</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card text-center">
                <div class="card-body">
                    <i class="fas fa-rupee-sign fa-2x mb-2"></i>
                    <h4>₹<?= number_format($group_stats['total_amount'], 0) ?></h4>
                    <small>Total Spent</small>
                </div>
            </div>
        </div>
    </div>


    <?php if ($is_admin && !empty($pending_requests)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0"><i class="fas fa-user-clock me-2"></i>Pending Join Requests</h5>
    </div>
    <div class="card-body">
        <?php foreach ($pending_requests as $req): ?>
            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                <div>
                    <strong><?= htmlspecialchars($req['username']) ?></strong> 
                    <small class="text-muted">(<?= htmlspecialchars($req['email']) ?>)</small><br>
                    <small class="text-muted">Requested on <?= date("d M Y, h:i A", strtotime($req['requested_at'])) ?></small>
                </div>
                <div>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                        <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">
                            <i class="fas fa-check"></i> Approve
                        </button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                        <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


    <div class="row">
        <!-- Enhanced Group Members Section -->
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-users me-2"></i>Group Members (<?= $member_count ?>)</h5>
                    <?php if ($is_admin): ?>
                        <a href="emailforjoin.php?group_id=<?= $group_id ?>" class="btn btn-light btn-sm">
                            <i class="fas fa-user-plus"></i> Add Member
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if ($member_count > 0): ?>
                        <?php 
                        $members->data_seek(0); // Reset pointer
                        while ($member = $members->fetch_assoc()): 
                            $card_class = strtolower($member['role']);
                        ?>
                            <div class="member-card <?= $card_class ?> p-3 border-bottom">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle bg-<?= $member['is_creator'] ? 'danger' : 'primary' ?> text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px; font-size: 18px; font-weight: bold;">
                                            <?= strtoupper(substr($member['username'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <h6 class="mb-1 fw-bold">
                                                <?= htmlspecialchars($member['username']) ?>
                                                <?php if ($member['id'] == $user_id): ?>
                                                    <small class="text-muted">(You)</small>
                                                <?php endif; ?>
                                            </h6>
                                            
                                            <!-- Role Badge -->
                                            <?php if ($member['is_creator']): ?>
                                                <span class="badge creator-badge text-white mb-1">
                                                    <i class="fas fa-crown"></i> Group Creator
                                                </span>
                                            <?php else: ?>
                                                <span class="badge member-badge text-white mb-1">
                                                    <i class="fas fa-user"></i> Member
                                                </span>
                                            <?php endif; ?>
                                            
                                            <!-- Email visibility logic -->
                                            <?php if ($is_admin || $member['id'] == $user_id): ?>
                                                <br><small class="text-muted">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?= htmlspecialchars($member['email']) ?>
                                                </small>
                                            <?php endif; ?>
                                            
                                            <br><small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                Joined: <?= date("d M Y", strtotime($member['joined_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <!-- Remove member button (only for admin, not for creator or self) -->
                                    <?php if ($is_admin && $member['id'] != $user_id && !$member['is_creator']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to remove <?= htmlspecialchars($member['username']) ?> from this group?')">
                                            <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                            <button type="submit" name="remove_member" class="btn btn-outline-danger btn-sm" title="Remove Member">
                                                <i class="fas fa-user-times"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-users fa-3x mb-3"></i>
                            <p>No members found in this group.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>



        <!-- Enhanced Recent Expenses Section with Multi-Payer Support -->
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Recent Expenses</h5>
                    <div class="btn-group">
                        <a href="add_expense.php?group_id=<?= $group_id ?>" class="btn btn-light btn-sm">
                            <i class="fas fa-plus"></i> Add Expense
                        </a>
                        <a href="add_multi_payer_expense.php?group_id=<?= $group_id ?>" class="btn btn-info btn-sm">
                            <i class="fas fa-users-cog"></i> Multi-Payer
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($expenses->num_rows > 0): ?>
                        <?php while ($expense = $expenses->fetch_assoc()): ?>
                            <div class="expense-card p-3 border-bottom">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <h6 class="mb-0 fw-bold me-2"><?= htmlspecialchars($expense['title']) ?></h6>
                                            <?php if ($expense['payer_count'] > 1): ?>
                                                <span class="badge multi-payer-badge text-white">
                                                    <i class="fas fa-users"></i> Multi-Payer
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if (!empty($expense['description'])): ?>
                                            <p class="mb-2 text-muted small"><?= htmlspecialchars($expense['description']) ?></p>
                                        <?php endif; ?>
                                        
                                        <small class="text-muted">
                                            <i class="fas fa-user me-1"></i>
                                            <?php if ($expense['payer_count'] > 1): ?>
                                                <strong>Paid by:</strong> <?= htmlspecialchars($expense['payers_info'] ?? 'Unknown') ?>
                                            <?php else: ?>
                                                Paid by <strong><?= htmlspecialchars($expense['payer_names'] ?? 'Unknown') ?></strong>
                                            <?php endif; ?>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar me-1"></i>
                                            <?= date("d M Y, h:i A", strtotime($expense['created_at'])) ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-success fs-6 ms-2">₹<?= number_format($expense['amount'], 2) ?></span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        <div class="p-3 text-center">
                            <a href="expenses.php?group_id=<?= $group_id ?>" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-eye me-1"></i>View All Expenses
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-receipt fa-3x mb-3"></i>
                            <p>No expenses added yet.</p>
                            <div class="btn-group">
                                <a href="add_expense.php?group_id=<?= $group_id ?>" class="btn btn-primary">
                                    <i class="fas fa-plus me-1"></i>Add Expense
                                </a>
                                <a href="add_multi_payer_expense.php?group_id=<?= $group_id ?>" class="btn btn-info">
                                    <i class="fas fa-users-cog me-1"></i>Multi-Payer
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Your Summary Section -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Your Financial Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-4">
                            <div class="p-4 bg-light rounded border">
                                <i class="fas fa-arrow-up text-primary fa-2x mb-2"></i>
                                <h4 class="text-primary mb-1">₹<?= number_format($paid, 2) ?></h4>
                                <small class="text-muted">Total You Paid</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-4 bg-light rounded border">
                                <i class="fas fa-calculator text-warning fa-2x mb-2"></i>
                                <h4 class="text-warning mb-1">₹<?= number_format($owed, 2) ?></h4>
                                <small class="text-muted">Your Total Share</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-4 <?= $net >= 0 ? 'bg-success' : 'bg-danger' ?> text-white rounded">
                                <i class="fas fa-balance-scale fa-2x mb-2"></i>
                                <h4 class="mb-1">₹<?= number_format(abs($net), 2) ?></h4>
                                <small>
                                    <?php if ($net > 0): ?>
                                        <i class="fas fa-arrow-down me-1"></i>Others Owe You
                                    <?php elseif ($net < 0): ?>
                                        <i class="fas fa-arrow-up me-1"></i>You Owe Others
                                    <?php else: ?>
                                        <i class="fas fa-check me-1"></i>All Settled Up
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settlements Section -->
    <?php if ($settlements->num_rows > 0): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="fas fa-handshake me-2"></i>Your Settlements</h5>
                </div>
                <div class="card-body p-0">
                    <?php while ($settlement = $settlements->fetch_assoc()): ?>
                        <div class="settlement-card p-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if ($settlement['paid_by'] == $user_id): ?>
                                        <span class="text-success">
                                            <i class="fas fa-arrow-right me-1"></i>
                                            You owe <strong><?= htmlspecialchars($settlement['receiver_name']) ?></strong>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-primary">
                                            <i class="fas fa-arrow-left me-1"></i>
                                            <strong><?= htmlspecialchars($settlement['payer_name']) ?></strong> owes you
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-warning text-dark fs-6">
                                    ₹<?= number_format($settlement['amount'], 2) ?>
                                </span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <br><br>
        <!-- Member Balances Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="fas fa-scale-balanced me-2"></i>Member Balances</h5>
        </div>
        <div class="card-body">
            <?php if (count($balances) > 0): ?>
                <ul class="list-group">
                    <?php foreach ($balances as $b): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <?= htmlspecialchars($b['username']) ?>
                            <?php if ($b['net'] > 0): ?>
                                <span class="badge bg-success">Gets ₹<?= number_format($b['net'], 2) ?></span>
                            <?php elseif ($b['net'] < 0): ?>
                                <span class="badge bg-danger">Owes ₹<?= number_format(abs($b['net']), 2) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Settled</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">No balances yet.</p>
            <?php endif; ?>
        </div>
    </div>


    <!-- Enhanced Action Buttons -->
    <div class="row mt-4 mb-5">
        <div class="col-12 text-center">
            <div class="btn-group flex-wrap" role="group">
                <a href="add_expense.php?group_id=<?= $group_id ?>" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus"></i> Add Expense
                </a>
                <!-- <a href="add_multi_payer_expense.php?group_id=<?= $group_id ?>" class="btn btn-info btn-lg">
                    <i class="fas fa-users-cog"></i> Multi-Payer Expense
                </a> -->
                <a href="settlements.php?group_id=<?= $group_id ?>" class="btn btn-success btn-lg">
                    <i class="fas fa-handshake"></i> Settle Up
                </a>
                <a href="expenses.php?group_id=<?= $group_id ?>" class="btn btn-outline-primary btn-lg">
                    <i class="fas fa-list"></i> All Expenses
                </a>
                <?php if ($is_admin): ?>
                    <!-- <a href="edit_group.php?group_id=<?= $group_id ?>" class="btn btn-warning btn-lg">
                        <i class="fas fa-edit"></i> Edit Group
                    </a> -->
                <?php endif; ?>
                <a href="dash.php" class="btn btn-secondary btn-lg">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
