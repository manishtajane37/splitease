<?php
session_start();
require_once 'db.php';
require_once 'functions.php'; // for addNotification()

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Validate settlement ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid settlement ID.";
    header("Location: settlements.php");
    exit();
}

$settlement_id = intval($_GET['id']);
$update_stmt = null; // Initialize to avoid scope issues

try {
    // Start transaction for data consistency
    $conn->begin_transaction();

    // Fetch settlement with user details and verify user access
    $stmt = $conn->prepare("
        SELECT s.*, 
               u1.username AS payer_name, 
               u2.username AS receiver_name
        FROM settlements s
        LEFT JOIN users u1 ON s.paid_by = u1.id
        LEFT JOIN users u2 ON s.paid_to = u2.id
        WHERE s.id = ? AND (s.paid_by = ? OR s.paid_to = ?)
    ");
    $stmt->bind_param("iii", $settlement_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $settlement = $result->fetch_assoc();

    // Validate settlement exists and user has access
    if (!$settlement) {
        throw new Exception("Settlement not found or you don't have permission to access it.");
    }

    // Determine the action based on the current status and user role
    $current_status = $settlement['status'];
    $payer = $settlement['paid_by'];
    $receiver = $settlement['paid_to'];
    $amount = $settlement['amount'];
    $payer_name = $settlement['payer_name'] ?? 'Unknown User';
    $receiver_name = $settlement['receiver_name'] ?? 'Unknown User';

    // Log for debugging
    error_log("SETTLEMENT APPROVAL: ID {$settlement_id}, Status: {$current_status}, User: {$user_id}, Payer: {$payer}, Receiver: {$receiver}");

    // Handle different approval scenarios
    if ($current_status === 'awaiting_confirmation') {
        // Only the receiver can confirm payments
        if ($user_id !== $receiver) {
            throw new Exception("Only the payment receiver can confirm this settlement.");
        }
        
        // Confirm the payment
        $now = date("Y-m-d H:i:s");
        $update_stmt = $conn->prepare("
            UPDATE settlements 
            SET status = 'paid', 
                settled_at = ?,
                updated_at = ?
            WHERE id = ?
        ");
        $update_stmt->bind_param("ssi", $now, $now, $settlement_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update settlement status.");
        }

        // Send notifications
        $amount_formatted = number_format($amount, 2);
        
        // Notify the payer
        if ($payer) {
            $payer_message = "Settlement of ₹{$amount_formatted} with {$receiver_name} confirmed and completed!";
            addNotification($conn, $payer, $payer_message, "settlements.php");
        }

        // Notify the receiver
        $receiver_message = "You confirmed receiving ₹{$amount_formatted} from {$payer_name}. Settlement completed!";
        addNotification($conn, $receiver, $receiver_message, "settlements.php");

        $_SESSION['success'] = "Settlement confirmed and marked as paid!";

    } elseif ($current_status === 'cancel_request') {
        // Only the receiver can approve cancellation requests
        if ($user_id !== $receiver) {
            throw new Exception("Only the payment receiver can approve cancellation requests.");
        }

        // Approve the cancellation
        $now = date("Y-m-d H:i:s");
        $update_stmt = $conn->prepare("
            UPDATE settlements 
            SET status = 'cancelled', 
                settled_at = ?,
                updated_at = ?
            WHERE id = ?
        ");
        $update_stmt->bind_param("ssi", $now, $now, $settlement_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update settlement status.");
        }

        // Send notifications
        $amount_formatted = number_format($amount, 2);
        
        // Notify the payer
        if ($payer) {
            $payer_message = "Settlement of ₹{$amount_formatted} with {$receiver_name} has been cancelled.";
            addNotification($conn, $payer, $payer_message, "settlements.php");
        }

        // Notify the receiver
        $receiver_message = "You cancelled the settlement of ₹{$amount_formatted} with {$payer_name}.";
        addNotification($conn, $receiver, $receiver_message, "settlements.php");

        $_SESSION['success'] = "Settlement cancelled successfully!";

    } elseif (in_array($current_status, ['pending', 'partial'])) {
        // CRITICAL FIX: ALWAYS require confirmation workflow for consistency
        // This ensures every settlement follows the same process regardless of who initiates
        
        $now = date("Y-m-d H:i:s");
        $update_stmt = $conn->prepare("
            UPDATE settlements 
            SET status = 'awaiting_confirmation', 
                updated_at = ?
            WHERE id = ?
        ");
        $update_stmt->bind_param("si", $now, $settlement_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update settlement status.");
        }

        // Send appropriate notifications based on who initiated
        $amount_formatted = number_format($amount, 2);
        
        if ($user_id == $payer) {
            // Payer marked as paid - needs receiver confirmation
            addNotification($conn, $receiver, "{$payer_name} marked ₹{$amount_formatted} as paid. Please confirm if you received the payment.", "settlements.php");
            addNotification($conn, $payer, "You marked ₹{$amount_formatted} as paid. Waiting for {$receiver_name} to confirm receipt.", "settlements.php");
            $_SESSION['success'] = "Payment marked as sent. Waiting for receiver to confirm.";
            
            error_log("PAYER MARKED PAID: Settlement {$settlement_id} set to awaiting_confirmation");
            
        } elseif ($user_id == $receiver) {
            // Receiver marked as received - needs payer confirmation (for consistency)
            addNotification($conn, $payer, "{$receiver_name} confirmed receiving ₹{$amount_formatted}. Please verify you made this payment.", "settlements.php");
            addNotification($conn, $receiver, "You confirmed receiving ₹{$amount_formatted}. Waiting for {$payer_name} to verify payment.", "settlements.php");
            $_SESSION['success'] = "Payment marked as received. Waiting for payer to verify.";
            
            error_log("RECEIVER MARKED RECEIVED: Settlement {$settlement_id} set to awaiting_confirmation");
            
        } else {
            throw new Exception("You don't have permission to approve this settlement.");
        }

    } else {
        throw new Exception("This settlement cannot be approved. Current status: " . $current_status);
    }

    // Verify the update was successful (only if update_stmt was executed)
    if ($update_stmt && $update_stmt->affected_rows === 0) {
        throw new Exception("No settlement was updated. It may have been modified by another process.");
    }

    // Commit the transaction
    $conn->commit();
    
    error_log("SETTLEMENT UPDATE SUCCESS: ID {$settlement_id}, New Status: " . ($current_status === 'awaiting_confirmation' ? 'paid' : 'awaiting_confirmation'));
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Log the error
    error_log("Settlement approval error: " . $e->getMessage() . " - User ID: {$user_id}, Settlement ID: {$settlement_id}");
    
    // Set error message for user
    $_SESSION['error'] = $e->getMessage();
}

// Determine redirect location
$redirect_url = "settlements.php";

// Check if there's a group context to maintain
if (isset($_GET['group_id']) && is_numeric($_GET['group_id'])) {
    $group_id = intval($_GET['group_id']);
    $redirect_url = "settlements.php?group_id={$group_id}";
} elseif (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'settlements.php') !== false) {
    // Try to maintain any existing group context from the referrer
    if (preg_match('/group_id=(\d+)/', $_SERVER['HTTP_REFERER'], $matches)) {
        $redirect_url = "settlements.php?group_id=" . $matches[1];
    } else {
        $redirect_url = $_SERVER['HTTP_REFERER'];
    }
}

// Redirect to appropriate page
header("Location: " . $redirect_url);
exit();
?>