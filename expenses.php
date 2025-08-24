<?php
session_start();
include 'db.php';

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Login check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

// Get group_id
if (!isset($_GET['group_id']) || !is_numeric($_GET['group_id'])) {
    echo "Invalid Group ID";
    exit();
}
$group_id = intval($_GET['group_id']);

// Check if user is member or admin
$stmt = $conn->prepare("SELECT g.*, u.username AS creator_name 
                        FROM groups g 
                        JOIN users u ON g.created_by = u.id
                        WHERE g.id = ?");
$stmt->bind_param("i", $group_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();

if (!$group) {
    echo "Group not found.";
    exit();
}

$is_admin = ($group['created_by'] == $user_id);

// Check membership
$stmt = $conn->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
$stmt->bind_param("ii", $group_id, $user_id);
$stmt->execute();
$is_member = $stmt->get_result()->num_rows > 0;

if (!$is_admin && !$is_member) {
    echo "Access Denied!";
    exit();
}

// ✅ Fetch all expenses with multi-payer support - Updated Query
$stmt = $conn->prepare("SELECT e.*, 
    GROUP_CONCAT(DISTINCT CONCAT(u.username, ' (₹', FORMAT(ep.amount_paid, 2), ')') SEPARATOR ', ') as payers_info,
    GROUP_CONCAT(DISTINCT u.username SEPARATOR ', ') as payer_names,
    COUNT(DISTINCT ep.user_id) as payer_count
FROM expenses e 
LEFT JOIN expense_payers ep ON e.id = ep.expense_id
LEFT JOIN users u ON ep.user_id = u.id
WHERE e.group_id = ? 
GROUP BY e.id
ORDER BY e.created_at DESC");

$stmt->bind_param("i", $group_id);
$stmt->execute();
$expenses = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Expenses - <?= htmlspecialchars($group['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .multi-payer-badge {
            background: linear-gradient(45deg, #17a2b8, #138496);
            border: none;
        }
        .expense-row:hover {
            background-color: #f8f9fa;
        }
        .payer-info {
            font-size: 0.9em;
        }
    </style>
</head>
<body class="bg-light">

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-list"></i> All Expenses in 
            <span class="text-primary"><?= htmlspecialchars($group['name']) ?></span>
        </h2>
        <div class="btn-group">
            <a href="add_expense.php?group_id=<?= $group_id ?>" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Expense
            </a>
            <a  class="btn btn-info">
                <i class="fas fa-users-cog"></i> Multi-Payer
            </a>
        </div>
    </div>

    <?php if ($expenses->num_rows > 0): ?>
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th width="5%">#</th>
                                <th width="25%">Title</th>
                                <th width="20%">Description</th>
                                <th width="12%">Amount</th>
                                <th width="25%">Paid By</th>
                                <th width="13%">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $i = 1; while($exp = $expenses->fetch_assoc()): ?>
                            <tr class="expense-row">
                                <td><?= $i++ ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <strong><?= htmlspecialchars($exp['title']) ?></strong>
                                        <?php if ($exp['payer_count'] > 1): ?>
                                            <span class="badge multi-payer-badge text-white ms-2">
                                                <i class="fas fa-users"></i> Multi
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($exp['description'])): ?>
                                        <span class="text-muted"><?= htmlspecialchars($exp['description']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">No description</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">₹<?= number_format($exp['amount'], 2) ?></span>
                                </td>
                                <td class="payer-info">
                                    <?php if ($exp['payer_count'] > 1): ?>
                                        <div class="small">
                                            <strong class="text-info">Multiple payers:</strong><br>
                                            <?= htmlspecialchars($exp['payers_info'] ?? 'Unknown') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-primary">
                                            <i class="fas fa-user me-1"></i>
                                            <?= htmlspecialchars($exp['payer_names'] ?? 'Unknown') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= date("d M Y", strtotime($exp['created_at'])) ?><br>
                                        <span class="text-secondary"><?= date("h:i A", strtotime($exp['created_at'])) ?></span>
                                    </small>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Summary Statistics -->
        <?php 
        // Calculate total expenses and reset pointer
        $expenses->data_seek(0);
        $total_amount = 0;
        $total_expenses = 0;
        while($exp = $expenses->fetch_assoc()) {
            $total_amount += $exp['amount'];
            $total_expenses++;
        }
        ?>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <i class="fas fa-receipt fa-2x mb-2"></i>
                        <h4><?= $total_expenses ?></h4>
                        <small>Total Expenses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <i class="fas fa-rupee-sign fa-2x mb-2"></i>
                        <h4>₹<?= number_format($total_amount, 2) ?></h4>
                        <small>Total Amount</small>
                    </div>
                </div>
            </div>
        </div>
        
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-receipt fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No expenses found</h4>
                <p class="text-muted">Start by adding your first expense to this group.</p>
                <div class="btn-group">
                    <a href="add_expense.php?group_id=<?= $group_id ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Expense
                    </a>
                    <a href="add_multi_payer_expense.php?group_id=<?= $group_id ?>" class="btn btn-info">
                        <i class="fas fa-users-cog"></i> Multi-Payer Expense
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="text-center mt-4 mb-5">
        <a href="group.php?group_id=<?= $group_id ?>" class="btn btn-secondary btn-lg">
            <i class="fas fa-arrow-left"></i> Back to Group
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>