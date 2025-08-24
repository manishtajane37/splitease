<?php
require_once 'db.php';
require_once 'functions.php'; // contains addNotification()
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get group_id from URL if provided
$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;

// 🔧 POST REQUEST HANDLER - Handle partial payments first
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['partial_id']) && isset($_POST['partial_amount'])) {
    $id = intval($_POST['partial_id']);
    $amount_paid_now = floatval($_POST['partial_amount']);

    // Validate amount
    if ($amount_paid_now <= 0) {
        $_SESSION['error'] = "Please enter a valid amount greater than 0.";
        header("Location: settlements.php" . ($group_id ? "?group_id=$group_id" : ""));
        exit();
    }

    // Get existing settlement with user details
    $stmt = $conn->prepare("SELECT s.*, u1.username AS payer_name, u2.username AS receiver_name 
                           FROM settlements s
                           JOIN users u1 ON s.paid_by = u1.id
                           JOIN users u2 ON s.paid_to = u2.id
                           WHERE s.id = ? AND (s.paid_by = ? OR s.paid_to = ?)");
    $stmt->bind_param("iii", $id, $user_id, $user_id);
    $stmt->execute();
    $settlement = $stmt->get_result()->fetch_assoc();

    if ($settlement && $settlement['status'] !== 'paid' && $settlement['status'] !== 'cancelled') {
        $total = floatval($settlement['amount']);
        $already_paid = floatval($settlement['partial_paid_amount'] ?? 0);
        $new_total_paid = $already_paid + $amount_paid_now;

        // Prevent overpayment with proper float comparison
        if ($new_total_paid > $total + 0.01) { // Added small tolerance for float precision
            $_SESSION['error'] = "Payment amount exceeds remaining balance. Remaining: ₹" . number_format($total - $already_paid, 2);
            header("Location: settlements.php" . ($group_id ? "?group_id=$group_id" : ""));
            exit();
        }

        // ✅ CRITICAL FIX: Always require confirmation for full payments
        // Use proper float comparison to determine if payment is complete
        if (abs($new_total_paid - $total) < 0.01) { // Payment is complete (within 1 paisa tolerance)
            $status = 'awaiting_confirmation';
            $new_total_paid = $total; // Ensure exact amount to avoid precision issues
        } else {
            $status = 'partial';
        }

        // Update settlement - Don't auto-set settled_at timestamp
        $stmt = $conn->prepare("UPDATE settlements SET partial_paid_amount = ?, status = ? WHERE id = ?");
        $stmt->bind_param("dsi", $new_total_paid, $status, $id);
        
        if (!$stmt->execute()) {
            $_SESSION['error'] = "Failed to update settlement. Please try again.";
            header("Location: settlements.php" . ($group_id ? "?group_id=$group_id" : ""));
            exit();
        }

        // Send notifications
        $payer = $settlement['paid_by'];
        $receiver = $settlement['paid_to'];
        $payer_name = $settlement['payer_name'];
        $receiver_name = $settlement['receiver_name'];

        $remaining = $total - $new_total_paid;
        
        // ✅ ENHANCED FIX: Better notification messages and logging
        if ($status === 'awaiting_confirmation') {
            $message_suffix = " for ₹" . number_format($total, 2) . " - Please confirm receipt!";
            addNotification($conn, $receiver, "$payer_name completed full payment$message_suffix", "settlements.php");
            addNotification($conn, $payer, "You completed payment to $receiver_name$message_suffix", "settlements.php");
            $_SESSION['success'] = "Full payment of ₹" . number_format($amount_paid_now, 2) . " recorded! Settlement now awaiting receiver confirmation.";
            
            // Debug logging
            error_log("FULL PAYMENT: Settlement ID $id updated to awaiting_confirmation. Total: $total, Paid: $new_total_paid");
        } else {
            $message_suffix = " (₹" . number_format($remaining, 2) . " remaining)";
            addNotification($conn, $receiver, "$payer_name paid ₹" . number_format($amount_paid_now, 2) . "$message_suffix", "settlements.php");
            addNotification($conn, $payer, "You paid ₹" . number_format($amount_paid_now, 2) . " to $receiver_name$message_suffix", "settlements.php");
            $_SESSION['success'] = "Partial payment of ₹" . number_format($amount_paid_now, 2) . " recorded successfully!";
            
            // Debug logging
            error_log("PARTIAL PAYMENT: Settlement ID $id updated to partial. Remaining: $remaining");
        }

    } else {
        $_SESSION['error'] = "Settlement not found or already completed.";
    }

    header("Location: settlements.php" . ($group_id ? "?group_id=$group_id" : ""));
    exit();
}

// 🔧 POST REQUEST HANDLER - Handle mark paid approval requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid_id'])) {
    $settlement_id = intval($_POST['mark_paid_id']);
    
    // CRITICAL FIX: Verify settlement exists and is in correct state before redirect
    $verify_stmt = $conn->prepare("SELECT id, status FROM settlements WHERE id = ? AND (paid_by = ? OR paid_to = ?)");
    $verify_stmt->bind_param("iii", $settlement_id, $user_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    $settlement_check = $verify_result->fetch_assoc();
    
    if (!$settlement_check) {
        $_SESSION['error'] = "Settlement not found or access denied.";
        header("Location: settlements.php" . ($group_id ? "?group_id=$group_id" : ""));
        exit();
    }
    
    if (!in_array($settlement_check['status'], ['pending', 'partial'])) {
        $_SESSION['error'] = "Settlement cannot be marked as paid in its current status: " . $settlement_check['status'];
        header("Location: settlements.php" . ($group_id ? "?group_id=$group_id" : ""));
        exit();
    }
    
    // Log for debugging
    error_log("MARK PAID POST: Settlement ID {$settlement_id}, Status: {$settlement_check['status']}, User: {$user_id}");
    
    // Build the redirect URL properly
    $redirect_url = "settlement_approval.php?id=" . $settlement_id;
    if ($group_id) {
        $redirect_url .= "&group_id=" . $group_id;
    }
    
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Send redirect header - this ensures EVERY mark paid goes through confirmation
    header("Location: " . $redirect_url);
    exit();
}

// 🔧 GET ACTION HANDLER - Handle all GET actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = intval($_GET['id']);

    // Fetch settlement info with usernames and verify user access
    $stmt = $conn->prepare("
        SELECT s.*, 
               u1.username AS payer_name, 
               u2.username AS receiver_name
        FROM settlements s
        JOIN users u1 ON s.paid_by = u1.id
        JOIN users u2 ON s.paid_to = u2.id
        WHERE s.id = ? AND (s.paid_by = ? OR s.paid_to = ?)
    ");
    $stmt->bind_param("iii", $id, $user_id, $user_id);
    $stmt->execute();
    $settlement = $stmt->get_result()->fetch_assoc();

    if ($settlement) {
        $payer = $settlement['paid_by'];
        $receiver = $settlement['paid_to'];
        $amount = $settlement['amount'];
        $payer_name = $settlement['payer_name'];
        $receiver_name = $settlement['receiver_name'];
        $current_status = $settlement['status'];

        switch ($action) {
            case 'confirm_paid':
                // CONSISTENT: Always redirect to settlement_approval.php
                $redirect_params = $group_id ? "?group_id=$group_id" : "";
                header("Location: settlement_approval.php?id=$id" . ($redirect_params ? "&" . ltrim($redirect_params, '?') : ""));
                exit();
                break;

            case 'send_reminder':
                if ($current_status === 'pending' || $current_status === 'partial') {
                    // FIXED: Only the receiver can send reminders to the payer
                    if ($user_id !== $receiver) {
                        $_SESSION['error'] = "Only the payment receiver can send reminders.";
                        break;
                    }
                    
                    $remaining = $amount - ($settlement['partial_paid_amount'] ?? 0);
                    addNotification($conn, $payer, "Reminder: You still owe ₹" . number_format($remaining, 2) . " to $receiver_name.", "settlements.php");
                    addNotification($conn, $receiver, "You sent a reminder to $payer_name for ₹" . number_format($remaining, 2) . ".", "settlements.php");
                    $_SESSION['success'] = "Reminder sent successfully!";
                } else {
                    $_SESSION['error'] = "Cannot send reminder for this settlement.";
                }
                break;

            case 'request_cancel':
                if ($current_status === 'pending' || $current_status === 'partial') {
                    // Both payer and receiver can request cancellation
                    $stmt = $conn->prepare("UPDATE settlements SET status='cancel_request' WHERE id=?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();

                    if ($user_id == $payer) {
                        addNotification($conn, $receiver, "$payer_name requested cancellation for ₹" . number_format($amount, 2) . " settlement.", "settlements.php");
                        addNotification($conn, $payer, "You requested cancellation for ₹" . number_format($amount, 2) . " settlement.", "settlements.php");
                    } else {
                        addNotification($conn, $payer, "$receiver_name requested cancellation for ₹" . number_format($amount, 2) . " settlement.", "settlements.php");
                        addNotification($conn, $receiver, "You requested cancellation for ₹" . number_format($amount, 2) . " settlement.", "settlements.php");
                    }
                    $_SESSION['success'] = "Cancellation request sent!";
                } else {
                    $_SESSION['error'] = "Cannot request cancellation for this settlement.";
                }
                break;

            case 'approve_cancel':
                // FIXED: Proper parameter handling
                $redirect_params = $group_id ? "?group_id=$group_id" : "";
                header("Location: settlement_approval.php?id=$id" . ($redirect_params ? "&" . ltrim($redirect_params, '?') : ""));
                exit();
                break;

            case 'reject_cancel':
                if ($current_status === 'cancel_request') {
                    // FIXED: Only the receiver can reject cancellation requests
                    if ($user_id !== $receiver) {
                        $_SESSION['error'] = "Only the payment receiver can reject cancellation requests.";
                        break;
                    }
                    
                    $stmt = $conn->prepare("UPDATE settlements SET status='pending' WHERE id=?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();

                    addNotification($conn, $payer, "Your cancellation request for ₹" . number_format($amount, 2) . " was rejected by $receiver_name.", "settlements.php");
                    addNotification($conn, $receiver, "You rejected the cancellation request for ₹" . number_format($amount, 2) . " from $payer_name.", "settlements.php");
                    $_SESSION['success'] = "Cancellation request rejected!";
                } else {
                    $_SESSION['error'] = "Cannot reject cancellation for this settlement.";
                }
                break;

            default:
                $_SESSION['error'] = "Invalid action.";
                break;
        }
    } else {
        $_SESSION['error'] = "Settlement not found or you don't have permission to access it.";
    }

    header("Location: settlements.php" . ($group_id ? "?group_id=$group_id" : ""));
    exit();
}

// Build the WHERE clause based on whether group_id is provided
$where_clause = "";
$bind_params = array($user_id, $user_id);
$bind_types = "ii";

if ($group_id) {
    $where_clause = " AND s.group_id = ?";
    $bind_params[] = $group_id;
    $bind_types .= "i";
}

// 🔧 FETCH PENDING SETTLEMENTS
$sql_pending = "SELECT s.*, 
                       IFNULL(u1.username, 'Unknown') AS payer, 
                       IFNULL(u2.username, 'Unknown') AS receiver,
                       g.name AS group_name
                FROM settlements s
                LEFT JOIN users u1 ON s.paid_by = u1.id
                LEFT JOIN users u2 ON s.paid_to = u2.id
                LEFT JOIN groups g ON s.group_id = g.id
                WHERE (s.paid_by = ? OR s.paid_to = ?) 
                  AND s.status IN ('pending', 'partial', 'cancel_request', 'awaiting_confirmation')
                  $where_clause
                ORDER BY s.created_at DESC";

$stmt = $conn->prepare($sql_pending);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$pending_result = $stmt->get_result();

// 🔧 FETCH COMPLETED SETTLEMENTS
$sql_completed = "SELECT s.*, 
                         u1.username AS payer, 
                         u2.username AS receiver,
                         g.name AS group_name
                  FROM settlements s
                  JOIN users u1 ON s.paid_by = u1.id
                  JOIN users u2 ON s.paid_to = u2.id
                  LEFT JOIN groups g ON s.group_id = g.id
                  WHERE (s.paid_by = ? OR s.paid_to = ?) 
                    AND s.status IN ('paid', 'cancelled')
                    $where_clause
                  ORDER BY s.settled_at DESC
                  LIMIT 20";

$stmt2 = $conn->prepare($sql_completed);
$stmt2->bind_param($bind_types, ...$bind_params);
$stmt2->execute();
$completed_result = $stmt2->get_result();

// Calculate summary statistics
$summary_where = $group_id ? " AND group_id = $group_id" : "";
$stmt_summary = $conn->prepare("
    SELECT 
        SUM(CASE WHEN paid_by = ? AND status IN ('pending', 'partial') THEN amount - COALESCE(partial_paid_amount, 0) ELSE 0 END) as you_owe,
        SUM(CASE WHEN paid_to = ? AND status IN ('pending', 'partial') THEN amount - COALESCE(partial_paid_amount, 0) ELSE 0 END) as owed_to_you
    FROM settlements 
    WHERE (paid_by = ? OR paid_to = ?) AND status IN ('pending', 'partial') $summary_where
");
$stmt_summary->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
$stmt_summary->execute();
$summary = $stmt_summary->get_result()->fetch_assoc();

// Get group info if group_id is provided
$group_info = null;
if ($group_id) {
    $stmt_group = $conn->prepare("SELECT name FROM groups WHERE id = ?");
    $stmt_group->bind_param("i", $group_id);
    $stmt_group->execute();
    $group_info = $stmt_group->get_result()->fetch_assoc();
}

// Get user's groups for navigation
$stmt_groups = $conn->prepare("
    SELECT DISTINCT g.id, g.name
    FROM groups g
    WHERE g.id IN (
        SELECT group_id FROM group_members WHERE user_id = ?
        UNION
        SELECT id FROM groups WHERE created_by = ?
    )
    ORDER BY g.name
");
$stmt_groups->bind_param("ii", $user_id, $user_id);
$stmt_groups->execute();
$user_groups = $stmt_groups->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settlements - Money Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
       /* Enhanced Dashboard Styles */
body { 
   background: linear-gradient(135deg, #0a0a0a, #1a1a2e, #16213e, #0f3460);
   color: white;
    background-attachment: fixed;
    min-height: 100vh;
    position: relative;
}

/* Animated background overlay */
body::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: 
        radial-gradient(circle at 25% 25%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 75% 75%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
    pointer-events: none;
    z-index: -1;
}

.container { 
    max-width: 1200px; 
    margin: 0 auto; 
    padding: 30px 20px;
    position: relative;
    z-index: 1;
}

/* Enhanced Summary Cards */
.summary-card { 
    border-radius: 20px; 
    padding: 35px 30px; 
    text-align: center; 
    color: white;
    margin-bottom: 25px;
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    box-shadow: 
        0 10px 30px rgba(0, 0, 0, 0.15),
        0 4px 8px rgba(0, 0, 0, 0.1);
}

/* Floating particles animation */
.summary-card::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: 
        radial-gradient(circle, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
    background-size: 20px 20px;
    animation: float 20s linear infinite;
    pointer-events: none;
}

@keyframes float {
    0% { transform: translate(0, 0) rotate(0deg); }
    100% { transform: translate(-20px, -20px) rotate(360deg); }
}

/* Glow effect on hover */
.summary-card:hover {
    transform: translateY(-8px) scale(1.02);
    box-shadow: 
        0 20px 40px rgba(0, 0, 0, 0.25),
        0 8px 16px rgba(0, 0, 0, 0.15),
        inset 0 1px 0 rgba(255, 255, 255, 0.2);
}

/* Enhanced card backgrounds */
.owe-card { 
    background: 
        linear-gradient(135deg, #ff6b6b 0%, #ee5a52 25%, #ff4757 50%, #c44569 100%);
    position: relative;
}

.owe-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, 
        rgba(255, 107, 107, 0.9) 0%, 
        rgba(238, 90, 82, 0.9) 25%, 
        rgba(255, 71, 87, 0.9) 50%, 
        rgba(196, 69, 105, 0.9) 100%);
    border-radius: inherit;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.owe-card:hover::after {
    opacity: 1;
}

.owed-card { 
    background: 
        linear-gradient(135deg, #26de81 0%, #20bf6b 25%, #0be881 50%, #00d2d3 100%);
    position: relative;
}

.owed-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, 
        rgba(38, 222, 129, 0.9) 0%, 
        rgba(32, 191, 107, 0.9) 25%, 
        rgba(11, 232, 129, 0.9) 50%, 
        rgba(0, 210, 211, 0.9) 100%);
    border-radius: inherit;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.owed-card:hover::after {
    opacity: 1;
}

/* Enhanced amount display */
.summary-amount { 
    font-size: 3rem; 
    font-weight: 800; 
    margin: 15px 0; 
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 2;
    background: linear-gradient(45deg, rgba(255, 255, 255, 1), rgba(255, 255, 255, 0.8));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    animation: shimmer 3s ease-in-out infinite alternate;
}

@keyframes shimmer {
    0% { filter: brightness(1); }
    100% { filter: brightness(1.2); }
}

/* Enhanced Status Badges */
.status-badge { 
    padding: 8px 16px; 
    border-radius: 25px; 
    font-size: 11px; 
    font-weight: 700; 
    text-transform: uppercase; 
    letter-spacing: 0.5px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(5px);
}

.status-badge::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s ease;
}

.status-badge:hover::before {
    left: 100%;
}

.status-pending { 
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
}

.status-partial { 
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
}

.status-paid { 
    background: linear-gradient(135deg, #2ecc71, #27ae60);
    color: white;
    box-shadow: 0 4px 15px rgba(46, 204, 113, 0.3);
}

.status-cancelled { 
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
    box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
}

.status-cancel_request { 
    background: linear-gradient(135deg, #95a5a6, #7f8c8d);
    color: white;
    box-shadow: 0 4px 15px rgba(149, 165, 166, 0.3);
}

.status-awaiting_confirmation {
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: white;
    box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
}

/* Enhanced Table Styling */
.table-custom {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 
        0 15px 35px rgba(0, 0, 0, 0.1),
        0 5px 15px rgba(0, 0, 0, 0.05);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
}

.table-custom:hover {
    box-shadow: 
        0 20px 40px rgba(0, 0, 0, 0.15),
        0 8px 20px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

/* Enhanced table headers */
.table-custom thead th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 20px 15px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.85rem;
    position: relative;
}

.table-custom thead th::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
}

/* Enhanced table rows */
.table-custom tbody tr {
    transition: all 0.3s ease;
    border: none;
}

.table-custom tbody tr:hover {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
    transform: scale(1.01);
}

.table-custom tbody td {
    padding: 18px 15px;
    border: none;
    vertical-align: middle;
    position: relative;
    color: #333;
}

.table-custom tbody tr:not(:last-child) td {
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

/* Enhanced Partial Payment Form */
.partial-form { 
    background: 
        linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px; 
    border-radius: 25px; 
    margin: 30px 0; 
    position: relative;
    overflow: hidden;
    box-shadow: 
        0 15px 35px rgba(102, 126, 234, 0.3),
        0 5px 15px rgba(102, 126, 234, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.partial-form::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: 
        radial-gradient(circle, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
    background-size: 30px 30px;
    animation: float 25s linear infinite reverse;
}

.partial-form h3 {
    margin-bottom: 25px;
    font-weight: 700;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
}

.partial-form .form-control {
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid rgba(255, 255, 255, 0.2);
    color: white;
    border-radius: 12px;
    padding: 15px 20px;
    font-size: 1rem;
    transition: all 0.3s ease;
    backdrop-filter: blur(5px);
}

.partial-form .form-control:focus {
    background: rgba(255, 255, 255, 0.15);
    border-color: rgba(255, 255, 255, 0.5);
    box-shadow: 
        0 0 0 3px rgba(255, 255, 255, 0.1),
        0 4px 15px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.partial-form .form-control::placeholder {
    color: rgba(255, 255, 255, 0.7);
}

/* Enhanced Navigation Pills */
.nav-pills {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 15px;
    padding: 8px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.nav-pills .nav-link {
    border-radius: 10px;
    padding: 12px 24px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.nav-pills .nav-link:hover {
    color: white;
    background: rgba(255, 255, 255, 0.1);
    transform: translateY(-1px);
}

.nav-pills .nav-link.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 
        0 4px 15px rgba(102, 126, 234, 0.3),
        0 2px 8px rgba(102, 126, 234, 0.2);
    transform: translateY(-2px);
}

.nav-pills .nav-link.active::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
    border-radius: inherit;
}

/* Enhanced Action Buttons */
.btn-group-vertical .btn {
    margin-bottom: 5px;
    border-radius: 8px !important;
    font-size: 12px;
    padding: 8px 12px;
    transition: all 0.3s ease;
    border: none;
}

.btn-group-vertical .btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Loading animations */
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.loading {
    animation: pulse 2s infinite;
}

/* Responsive enhancements */
@media (max-width: 768px) {
    .container {
        padding: 20px 15px;
    }
    
    .summary-card {
        padding: 25px 20px;
    }
    
    .summary-amount {
        font-size: 2.5rem;
    }
    
    .partial-form {
        padding: 30px 25px;
    }
    
    .table-custom {
        font-size: 0.9rem;
    }
    
    .btn-group-vertical .btn {
        font-size: 11px;
        padding: 6px 10px;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .table-custom {
        background: rgba(255, 255, 255, 0.08);
        color: #f8f9fa;
    }
    
    .table-custom tbody tr:hover {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
    }
}

/* Card improvements */
.card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 15px;
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

.card-header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 15px 15px 0 0 !important;
}
    </style>
</head>
<body>

<div class="container mt-4">
    <!-- Header -->
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <h1 class="mb-0">
                <i class="fas fa-handshake me-2"></i>
                Settlements
                <?php if ($group_info): ?>
                    <small class="text-muted"> - <?= htmlspecialchars($group_info['name']) ?></small>
                <?php endif; ?>
            </h1>
        </div>
        <div class="col-md-4 text-end">
            <a href="<?= $group_id ? "view_group.php?group_id=$group_id" : "dash.php" ?>" class="btn btn-outline-light">
                <i class="fas fa-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <!-- Group Filter Navigation -->
    <?php if ($user_groups->num_rows > 0): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="card-title mb-3" style="color: #333;">Filter by Group:</h6>
            <ul class="nav nav-pills">
                <li class="nav-item">
                    <a class="nav-link <?= !$group_id ? 'active' : '' ?>" href="settlements.php">
                        <i class="fas fa-globe me-1"></i>All Groups
                    </a>
                </li>
                <?php while ($group = $user_groups->fetch_assoc()): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $group_id == $group['id'] ? 'active' : '' ?>" 
                       href="settlements.php?group_id=<?= $group['id'] ?>">
                        <i class="fas fa-users me-1"></i><?= htmlspecialchars($group['name']) ?>
                    </a>
                </li>
                <?php endwhile; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Alert Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Debug Message (remove this after testing) -->
    <?php if (isset($_SESSION['debug'])): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <i class="fas fa-info-circle me-2"></i>DEBUG: <?= htmlspecialchars($_SESSION['debug']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['debug']); ?>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="summary-card owe-card">
                <i class="fas fa-arrow-up fa-2x mb-3"></i>
                <h3>You Owe</h3>
                <div class="summary-amount">₹<?= number_format($summary['you_owe'] ?? 0, 2) ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="summary-card owed-card">
                <i class="fas fa-arrow-down fa-2x mb-3"></i>
                <h3>Owed to You</h3>
                <div class="summary-amount">₹<?= number_format($summary['owed_to_you'] ?? 0, 2) ?></div>
            </div>
        </div>
    </div>

    <?php
    // Show partial payment form
    if (isset($_GET['action']) && $_GET['action'] === 'partial' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        
        $stmt = $conn->prepare("SELECT s.*, u1.username AS payer, u2.username AS receiver 
                                FROM settlements s
                                JOIN users u1 ON s.paid_by = u1.id
                                JOIN users u2 ON s.paid_to = u2.id
                                WHERE s.id = ? AND (s.paid_by = ? OR s.paid_to = ?)");
        $stmt->bind_param("iii", $id, $user_id, $user_id);
        $stmt->execute();
        $settlement = $stmt->get_result()->fetch_assoc();

        if ($settlement && $settlement['status'] !== 'paid' && $settlement['status'] !== 'cancelled') {
            $already_paid = floatval($settlement['partial_paid_amount'] ?? 0);
            $total_amount = floatval($settlement['amount']);
            $remaining = $total_amount - $already_paid;
    ?>
        <div class="partial-form">
            <h3><i class="fas fa-credit-card me-2"></i>Add Partial Payment</h3>
            <form method="post" action="settlements.php<?= $group_id ? "?group_id=$group_id" : "" ?>" onsubmit="return validatePartialPayment(this)">
                <input type="hidden" name="partial_id" value="<?= $settlement['id'] ?>">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Payer:</label>
                            <div class="fw-bold"><?= htmlspecialchars($settlement['payer']) ?></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Receiver:</label>
                            <div class="fw-bold"><?= htmlspecialchars($settlement['receiver']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Total Amount:</label>
                            <div class="fw-bold">₹<?= number_format($total_amount, 2) ?></div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Already Paid:</label>
                            <div class="fw-bold">₹<?= number_format($already_paid, 2) ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Remaining Amount:</label>
                    <div class="fw-bold fs-4">₹<?= number_format($remaining, 2) ?></div>
                </div>
                
                <div class="mb-3">
                    <label for="partial_amount" class="form-label">Enter Amount Paid Now:</label>
                    <input type="number" 
                           class="form-control form-control-lg" 
                           id="partial_amount" 
                           name="partial_amount" 
                           min="0.01" 
                           max="<?= $remaining ?>" 
                           step="0.01" 
                           placeholder="Enter amount..."
                           data-remaining="<?= $remaining ?>"
                           data-total="<?= $total_amount ?>"
                           required>
                    <div class="form-text" style="color: rgba(255, 255, 255, 0.8);">
                        Maximum allowed: ₹<?= number_format($remaining, 2) ?>
                    </div>
                </div>
                
                <div id="payment-warning" class="alert alert-warning" style="display: none;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Full Payment Detected!</strong><br>
                    This payment will complete the settlement and require receiver confirmation.
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-light btn-lg">
                        <i class="fas fa-check me-1"></i>Submit Payment
                    </button>
                    <a href="settlements.php<?= $group_id ? "?group_id=$group_id" : "" ?>" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-times me-1"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    <?php
        }
    }
    ?>

    <!-- Pending Settlements -->
    <div class="card table-custom mb-4">
        <div class="card-header bg-warning text-dark">
            <h5 class="mb-0">
                <i class="fas fa-clock me-2"></i>Pending Settlements
                <span class="badge bg-dark ms-2"><?= $pending_result->num_rows ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if ($pending_result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Payer</th>
                            <th>Receiver</th>
                            <?php if (!$group_id): ?><th>Group</th><?php endif; ?>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $pending_result->fetch_assoc()): ?>
                        <?php 
                            $paid = floatval($row['partial_paid_amount'] ?? 0);
                            $total = floatval($row['amount']);
                            $remaining = $total - $paid;
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary rounded-circle text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 14px;">
                                        <?= strtoupper(substr($row['payer'], 0, 1)) ?>
                                    </div>
                                    <?= htmlspecialchars($row['payer']) ?>
                                    <?php if ($row['paid_by'] == $user_id): ?>
                                        <small class="text-muted ms-1">(You)</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="bg-success rounded-circle text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 14px;">
                                        <?= strtoupper(substr($row['receiver'], 0, 1)) ?>
                                    </div>
                                    <?= htmlspecialchars($row['receiver']) ?>
                                    <?php if ($row['paid_to'] == $user_id): ?>
                                        <small class="text-muted ms-1">(You)</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if (!$group_id): ?>
                            <td>
                                <?php if ($row['group_name']): ?>
                                    <a href="settlements.php?group_id=<?= $row['group_id'] ?>" class="text-decoration-none">
                                        <small class="badge bg-light text-dark">
                                            <i class="fas fa-users me-1"></i><?= htmlspecialchars($row['group_name']) ?>
                                        </small>
                                    </a>
                                <?php else: ?>
                                    <small class="text-muted">No Group</small>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($paid > 0): ?>
                                    <div>
                                        <strong>₹<?= number_format($paid, 2) ?></strong> / ₹<?= number_format($total, 2) ?>
                                        <?php if ($remaining > 0.01): ?>
                                            <br><small class="text-danger">Remaining: ₹<?= number_format($remaining, 2) ?></small>
                                        <?php else: ?>
                                            <br><small class="text-success">Fully Paid - Awaiting Confirmation</small>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <strong>₹<?= number_format($total, 2) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $row['status'] ?>">
                                    <?php
                                    $status_display = $row['status'];
                                    if ($status_display === 'awaiting_confirmation') {
                                        echo 'Awaiting Confirmation';
                                    } else {
                                        echo ucwords(str_replace('_', ' ', $status_display));
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <small><?= date('M j, Y', strtotime($row['created_at'] ?? 'now')) ?></small>
                            </td>
                            <td>
                                <div class="btn-group-vertical">
                                    <?php if ($row['status'] === 'pending' || $row['status'] === 'partial'): ?>
                                        <!-- Enhanced form with better error handling -->
                                        <form method="post" style="display: inline;" onsubmit="return handleMarkPaid(this, <?= $row['id'] ?>);">
                                            <input type="hidden" name="mark_paid_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm" id="mark-paid-<?= $row['id'] ?>">
                                                <i class="fas fa-check"></i> Mark Paid
                                            </button>
                                        </form>
                                        
                                        <?php if ($remaining > 0.01): // Only show Add Payment if there's remaining amount ?>
                                        <a class="btn btn-primary btn-sm" href="settlements.php?action=partial&id=<?= $row['id'] ?><?= $group_id ? "&group_id=$group_id" : "" ?>">
                                            <i class="fas fa-credit-card"></i> Add Payment
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($user_id == $row['paid_to']): // Only receiver can send reminders ?>
                                        <a class="btn btn-warning btn-sm" href="settlements.php?action=send_reminder&id=<?= $row['id'] ?><?= $group_id ? "&group_id=$group_id" : "" ?>" onclick="return confirm('Send payment reminder?');">
                                            <i class="fas fa-bell"></i> Remind
                                        </a>
                                        <?php endif; ?>
                                        
                                        <a class="btn btn-danger btn-sm" href="settlements.php?action=request_cancel&id=<?= $row['id'] ?><?= $group_id ? "&group_id=$group_id" : "" ?>" onclick="return confirm('Request to cancel this settlement?');">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    <?php elseif ($row['status'] === 'cancel_request'): ?>
                                        <?php if ($user_id == $row['paid_to']): // Only receiver can approve/reject cancellation ?>
                                        <a class="btn btn-success btn-sm" href="settlement_approval.php?id=<?= $row['id'] ?><?= $group_id ? "&group_id=$group_id" : "" ?>" onclick="return confirm('Approve cancellation request?');">
                                            <i class="fas fa-check"></i> Approve
                                        </a>
                                        <a class="btn btn-danger btn-sm" href="settlements.php?action=reject_cancel&id=<?= $row['id'] ?><?= $group_id ? "&group_id=$group_id" : "" ?>" onclick="return confirm('Reject cancellation request?');">
                                            <i class="fas fa-times"></i> Reject
                                        </a>
                                        <?php else: ?>
                                        <small class="text-muted">Awaiting response...</small>
                                        <?php endif; ?>
                                    <?php elseif ($row['status'] === 'awaiting_confirmation'): ?>
                                        <?php if ($user_id == $row['paid_to']): // Only receiver can confirm ?>
                                        <a class="btn btn-success btn-sm" 
                                        href="settlement_approval.php?id=<?= $row['id'] ?><?= $group_id ? "&group_id=$group_id" : "" ?>"
                                        onclick="return confirm('Confirm that you received the full payment of ₹<?= number_format($total, 2) ?>?');">
                                            <i class="fas fa-check-circle"></i> Confirm Receipt
                                        </a>
                                        <div class="mt-1">
                                            <small class="text-info">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Payment completed - awaiting your confirmation
                                            </small>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-center">
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                Awaiting receiver confirmation...
                                            </small>
                                            <div class="mt-1">
                                                <small class="text-success">
                                                    Payment completed: ₹<?= number_format($total, 2) ?>
                                                </small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h5 style="color: #333;">No pending settlements!</h5>
                    <p class="text-muted">You're all caught up with your payments.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

        <!-- Completed Settlements -->
        <div class="card table-custom">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-check-circle me-2"></i>Completed Settlements
                    <span class="badge bg-light text-dark ms-2"><?= $completed_result->num_rows ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if ($completed_result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Payer</th>
                                <th>Receiver</th>
                                <?php if (!$group_id): ?><th>Group</th><?php endif; ?>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $completed_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-secondary rounded-circle text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 14px;">
                                            <?= strtoupper(substr($row['payer'], 0, 1)) ?>
                                        </div>
                                        <?= htmlspecialchars($row['payer']) ?>
                                        <?php if ($row['paid_by'] == $user_id): ?>
                                            <small class="text-muted ms-1">(You)</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-secondary rounded-circle text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 14px;">
                                            <?= strtoupper(substr($row['receiver'], 0, 1)) ?>
                                        </div>
                                        <?= htmlspecialchars($row['receiver']) ?>
                                        <?php if ($row['paid_to'] == $user_id): ?>
                                            <small class="text-muted ms-1">(You)</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php if (!$group_id): ?>
                                <td>
                                    <?php if ($row['group_name']): ?>
                                        <small class="badge bg-light text-dark">
                                            <i class="fas fa-users me-1"></i><?= htmlspecialchars($row['group_name']) ?>
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted">No Group</small>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td><strong>₹<?= number_format($row['amount'], 2) ?></strong></td>
                                <td>
                                    <span class="status-badge status-<?= $row['status'] ?>">
                                        <?= ucwords($row['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <?= $row['settled_at'] ? date('M j, Y g:i A', strtotime($row['settled_at'])) : '-' ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                        <h5 style="color: #333;">No completed settlements yet</h5>
                        <p class="text-muted">Your settlement history will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row mt-4 mb-5">
            <div class="col-12 text-center">
                <div class="btn-group" role="group">
                    <?php if ($group_id): ?>
                        <a href="view_group.php?group_id=<?= $group_id ?>" class="btn btn-outline-light btn-lg">
                            <i class="fas fa-users me-1"></i>Back to Group
                        </a>
                    <?php else: ?>
                        <a href="dash.php" class="btn btn-outline-light btn-lg">
                            <i class="fas fa-home me-1"></i>Back to Dashboard
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Enhanced form handling for mark paid
    function handleMarkPaid(form, settlementId) {
        if (!confirm('Are you sure you want to mark this as paid? Settlement ID: ' + settlementId)) {
            return false;
        }
        
        const button = form.querySelector('button[type="submit"]');
        const originalContent = button.innerHTML;
        
        // Show processing state
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';
        button.disabled = true;
        
        // Debug: Log form data
        console.log('Form data:', new FormData(form));
        console.log('Settlement ID:', settlementId);
        
        // Allow form to submit
        return true;
    }

    // CRITICAL FIX: Enhanced partial payment validation
    function validatePartialPayment(form) {
        const amountInput = form.querySelector('#partial_amount');
        const warningDiv = document.getElementById('payment-warning');
        const amount = parseFloat(amountInput.value);
        const remaining = parseFloat(amountInput.dataset.remaining);
        const total = parseFloat(amountInput.dataset.total);
        
        // Validate amount
        if (isNaN(amount) || amount <= 0) {
            alert('Please enter a valid amount greater than 0.');
            return false;
        }
        
        if (amount > remaining + 0.01) {
            alert('Payment amount exceeds remaining balance of ₹' + remaining.toFixed(2));
            return false;
        }
        
        // Check if this completes the payment
        if (Math.abs(amount - remaining) < 0.01) {
            const confirmMsg = `This payment of ₹${amount.toFixed(2)} will complete the settlement.\n\nTotal Settlement: ₹${total.toFixed(2)}\nThis will require receiver confirmation.\n\nProceed?`;
            if (!confirm(confirmMsg)) {
                return false;
            }
            
            console.log('FULL PAYMENT SUBMITTED:', {
                settlementId: form.querySelector('input[name="partial_id"]').value,
                amount: amount,
                remaining: remaining,
                total: total
            });
        } else {
            const confirmMsg = `Record partial payment of ₹${amount.toFixed(2)}?\n\nRemaining after this payment: ₹${(remaining - amount).toFixed(2)}`;
            if (!confirm(confirmMsg)) {
                return false;
            }
            
            console.log('PARTIAL PAYMENT SUBMITTED:', {
                settlementId: form.querySelector('input[name="partial_id"]').value,
                amount: amount,
                remaining: remaining - amount
            });
        }
        
        // Show processing state
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';
        submitBtn.disabled = true;
        
        return true;
    }

    // Show warning when full payment is detected
    document.addEventListener('DOMContentLoaded', function() {
        const amountInput = document.getElementById('partial_amount');
        const warningDiv = document.getElementById('payment-warning');
        
        if (amountInput && warningDiv) {
            amountInput.addEventListener('input', function() {
                const amount = parseFloat(this.value);
                const remaining = parseFloat(this.dataset.remaining);
                
                if (!isNaN(amount) && Math.abs(amount - remaining) < 0.01) {
                    warningDiv.style.display = 'block';
                    warningDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    warningDiv.style.display = 'none';
                }
            });
        }
        
        // Add loading states to other buttons
        const actionButtons = document.querySelectorAll('a[href*="action="]');
        
        actionButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Add loading state
                const originalContent = this.innerHTML;
                this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Loading...';
                
                // Restore content after delay (in case of errors)
                setTimeout(() => {
                    this.innerHTML = originalContent;
                }, 5000);
            });
        });
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Debug: Check if page loaded properly
    console.log('Settlements page loaded');
    console.log('Current URL:', window.location.href);
    </script>
    </body>
    </html>