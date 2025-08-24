<?php
session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $group_id = $_POST['group_id'];
    $title = $_POST['title'];
    $description = $_POST['description'] ?? '';
    $total_amount = floatval($_POST['total_amount']);
    $split_type = $_POST['split_type'];
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    
    // Multiple payers data
    $payers = $_POST['payers'] ?? [];
    $payer_amounts = $_POST['payer_amounts'] ?? [];

    // Basic validation
    if (empty($group_id) || empty($title) || $total_amount <= 0 || empty($payers)) {
        die("Error: Missing required fields or invalid amount.");
    }

    // Validate payers and amounts
    $total_paid = 0;
    $valid_payers = [];
    
    foreach ($payers as $payer_id) {
        $amount_paid = floatval($payer_amounts[$payer_id] ?? 0);
        if ($amount_paid > 0) {
            $total_paid += $amount_paid;
            $valid_payers[$payer_id] = $amount_paid;
        }
    }
    
    // Check if total paid matches total expense (within 0.01 tolerance)
    if (abs($total_paid - $total_amount) > 0.01) {
        die("Error: Total paid (" . number_format($total_paid, 2) . ") doesn't match total expense (" . number_format($total_amount, 2) . ")");
    }

    if (empty($valid_payers)) {
        die("Error: At least one person must pay a positive amount.");
    }

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Insert into expenses table (we'll use the first payer as primary, but track all in expense_payers)
        $primary_payer = array_key_first($valid_payers);
        $stmt = $conn->prepare("INSERT INTO expenses (group_id, paid_by, title, description, amount, expense_date) VALUES (?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception("Prepare Error: " . $conn->error);
        }

        $stmt->bind_param("iissds", $group_id, $primary_payer, $title, $description, $total_amount, $expense_date);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute Error: " . $stmt->error);
        }
        
        $expense_id = $stmt->insert_id;
        $stmt->close();

        // 2. Insert multiple payers into expense_payers table
        $stmt_payer = $conn->prepare("INSERT INTO expense_payers (expense_id, user_id, amount_paid) VALUES (?, ?, ?)");
        if (!$stmt_payer) {
            throw new Exception("Prepare Error for expense_payers: " . $conn->error);
        }
        
        foreach ($valid_payers as $user_id => $amount_paid) {
            $stmt_payer->bind_param("iid", $expense_id, $user_id, $amount_paid);
            if (!$stmt_payer->execute()) {
                throw new Exception("Execute Error for expense_payers: " . $stmt_payer->error);
            }
        }
        $stmt_payer->close();

        // 3. Fetch group members
        $members = [];
        $stmt = $conn->prepare("SELECT user_id FROM group_members WHERE group_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare Error for group_members: " . $conn->error);
        }
        
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $members[] = $row['user_id'];
        }
        $stmt->close();

        if (empty($members)) {
            throw new Exception("No members found in the selected group.");
        }

        // 4. Calculate splits
        $member_splits = [];
        
        if ($split_type === 'equal') {
            $share = round($total_amount / count($members), 2);
            $remaining = $total_amount - ($share * count($members));
            
            foreach ($members as $index => $user_id) {
                $member_splits[$user_id] = $share;
                // Add any remaining cents to the first member to ensure total matches
                if ($index === 0) {
                    $member_splits[$user_id] += $remaining;
                }
            }
        } elseif ($split_type === 'custom') {
            if (!isset($_POST['custom_share']) || !is_array($_POST['custom_share'])) {
                throw new Exception("Custom shares not provided.");
            }
            
            $total_custom_amount = 0;
            foreach ($_POST['custom_share'] as $user_id => $share_amount) {
                $share_amount = floatval($share_amount);
                if ($share_amount > 0) {
                    $total_custom_amount += $share_amount;
                    $member_splits[$user_id] = $share_amount;
                }
            }
            
            // Validate that custom shares equal total amount
            if (abs($total_custom_amount - $total_amount) > 0.01) {
                throw new Exception("Custom share amounts (" . number_format($total_custom_amount, 2) . ") don't match total amount (" . number_format($total_amount, 2) . ")");
            }
        }

        // 5. Insert expense splits
        $stmt_split = $conn->prepare("INSERT INTO expense_splits (expense_id, user_id, amount_owed) VALUES (?, ?, ?)");
        if (!$stmt_split) {
            throw new Exception("Prepare Error for expense_splits: " . $conn->error);
        }
        
        foreach ($member_splits as $user_id => $amount_owed) {
            $stmt_split->bind_param("iid", $expense_id, $user_id, $amount_owed);
            if (!$stmt_split->execute()) {
                throw new Exception("Execute Error for expense_splits: " . $stmt_split->error);
            }
        }
        $stmt_split->close();

        // 6. Calculate net positions and update settlements
        updateSettlements($conn, $group_id, $valid_payers, $member_splits);
        
        // Commit transaction
        $conn->commit();
        
        // Redirect back to group details
        header("Location: view_group.php?group_id=" . $group_id);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        die("Error: " . $e->getMessage());
    }
}

function updateSettlements($conn, $group_id, $payers, $splits) {
    /*
    * FIXED Algorithm:
    * 1. Calculate net position for each member: amount_paid - amount_owed
    * 2. Sort creditors and debtors for consistent processing order
    * 3. Create settlements with proper greedy matching
    * 4. All new settlements start as 'pending' status
    */
    
    $net_positions = [];
    
    // Initialize all members' net positions
    foreach ($splits as $user_id => $amount_owed) {
        $net_positions[$user_id] = -$amount_owed; // They owe this amount
    }
    
    // Add what each person paid
    foreach ($payers as $user_id => $amount_paid) {
        if (!isset($net_positions[$user_id])) {
            $net_positions[$user_id] = 0; // In case payer is not in splits (shouldn't happen normally)
        }
        $net_positions[$user_id] += $amount_paid; // They paid this amount
    }
    
    // CRITICAL FIX: Separate and sort creditors and debtors for consistent processing
    $creditors = [];
    $debtors = [];
    
    foreach ($net_positions as $user_id => $net_amount) {
        if (abs($net_amount) < 0.01) continue; // Skip if essentially zero
        
        if ($net_amount > 0) {
            $creditors[] = ['user_id' => $user_id, 'amount' => $net_amount];
        } else {
            $debtors[] = ['user_id' => $user_id, 'amount' => abs($net_amount)];
        }
    }
    
    // CRITICAL FIX: Sort arrays by user_id for consistent processing order
    // This ensures the same expense scenario always creates settlements in the same order
    usort($creditors, function($a, $b) { return $a['user_id'] - $b['user_id']; });
    usort($debtors, function($a, $b) { return $a['user_id'] - $b['user_id']; });
    
    // CRITICAL FIX: Process settlements using consistent greedy algorithm
    $creditor_index = 0;
    $debtor_index = 0;
    
    while ($creditor_index < count($creditors) && $debtor_index < count($debtors)) {
        $creditor = &$creditors[$creditor_index];
        $debtor = &$debtors[$debtor_index];
        
        $creditor_amount = $creditor['amount'];
        $debtor_amount = $debtor['amount'];
        
        // Calculate settlement amount (minimum of what's owed and what's due)
        $settlement_amount = min($creditor_amount, $debtor_amount);
        
        if ($settlement_amount >= 0.01) { // Only create settlements for meaningful amounts
            // CRITICAL FIX: Always create settlements with 'pending' status
            // This ensures consistent behavior regardless of processing order
            createNewSettlement(
                $conn, 
                $group_id, 
                $debtor['user_id'],     // paid_by (debtor)
                $creditor['user_id'],   // paid_to (creditor) 
                $settlement_amount
            );
        }
        
        // Update remaining amounts
        $creditor['amount'] -= $settlement_amount;
        $debtor['amount'] -= $settlement_amount;
        
        // Move to next creditor or debtor if current one is satisfied
        if ($creditor['amount'] < 0.01) {
            $creditor_index++;
        }
        if ($debtor['amount'] < 0.01) {
            $debtor_index++;
        }
    }
}

// CRITICAL FIX: New helper function to create settlements with consistent status
function createNewSettlement($conn, $group_id, $debtor_id, $creditor_id, $amount) {
    // Check if settlement already exists from debtor to creditor
    $stmt = $conn->prepare("SELECT id, amount FROM settlements WHERE group_id = ? AND paid_by = ? AND paid_to = ?");
    if (!$stmt) {
        throw new Exception("Prepare Error in createNewSettlement: " . $conn->error);
    }
    
    $stmt->bind_param("iii", $group_id, $debtor_id, $creditor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing settlement
        $settlement = $result->fetch_assoc();
        $new_amount = $settlement['amount'] + $amount;
        
        $update_stmt = $conn->prepare("UPDATE settlements SET amount = ?, updated_at = NOW() WHERE id = ?");
        if (!$update_stmt) {
            throw new Exception("Prepare Error in update settlement: " . $conn->error);
        }
        $update_stmt->bind_param("di", $new_amount, $settlement['id']);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Check if reverse settlement exists (from creditor to debtor)
        $reverse_stmt = $conn->prepare("SELECT id, amount FROM settlements WHERE group_id = ? AND paid_by = ? AND paid_to = ?");
        if (!$reverse_stmt) {
            throw new Exception("Prepare Error in reverse settlement check: " . $conn->error);
        }
        
        $reverse_stmt->bind_param("iii", $group_id, $creditor_id, $debtor_id);
        $reverse_stmt->execute();
        $reverse_result = $reverse_stmt->get_result();
        
        if ($reverse_result->num_rows > 0) {
            // There's a reverse settlement - net them out
            $reverse_settlement = $reverse_result->fetch_assoc();
            $reverse_amount = $reverse_settlement['amount'];
            
            if ($reverse_amount > $amount) {
                // Reduce the reverse settlement
                $new_reverse_amount = $reverse_amount - $amount;
                $update_stmt = $conn->prepare("UPDATE settlements SET amount = ?, updated_at = NOW() WHERE id = ?");
                if (!$update_stmt) {
                    throw new Exception("Prepare Error in reverse settlement update: " . $conn->error);
                }
                $update_stmt->bind_param("di", $new_reverse_amount, $reverse_settlement['id']);
                $update_stmt->execute();
                $update_stmt->close();
            } elseif ($reverse_amount < $amount) {
                // Delete reverse settlement and create new one in opposite direction
                $delete_stmt = $conn->prepare("DELETE FROM settlements WHERE id = ?");
                if (!$delete_stmt) {
                    throw new Exception("Prepare Error in settlement delete: " . $conn->error);
                }
                $delete_stmt->bind_param("i", $reverse_settlement['id']);
                $delete_stmt->execute();
                $delete_stmt->close();
                
                $net_amount = $amount - $reverse_amount;
                // CRITICAL FIX: Always start with 'pending' status
                $insert_stmt = $conn->prepare("INSERT INTO settlements (group_id, paid_by, paid_to, amount, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
                if (!$insert_stmt) {
                    throw new Exception("Prepare Error in settlement insert: " . $conn->error);
                }
                $insert_stmt->bind_param("iiid", $group_id, $debtor_id, $creditor_id, $net_amount);
                $insert_stmt->execute();
                $insert_stmt->close();
            } else {
                // Amounts are equal - delete the reverse settlement
                $delete_stmt = $conn->prepare("DELETE FROM settlements WHERE id = ?");
                if (!$delete_stmt) {
                    throw new Exception("Prepare Error in settlement delete equal: " . $conn->error);
                }
                $delete_stmt->bind_param("i", $reverse_settlement['id']);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        } else {
            // CRITICAL FIX: No existing settlements - create new one with 'pending' status
            $insert_stmt = $conn->prepare("INSERT INTO settlements (group_id, paid_by, paid_to, amount, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())");
            if (!$insert_stmt) {
                throw new Exception("Prepare Error in new settlement insert: " . $conn->error);
            }
            $insert_stmt->bind_param("iiid", $group_id, $debtor_id, $creditor_id, $amount);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
        $reverse_stmt->close();
    }
    $stmt->close();

}

// Get user groups for dropdown
$user_id = $_SESSION['user_id'] ?? null;  

if (!$user_id) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Add Multi-Payer Expense - SplitEase</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <!-- jQuery -->
  <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    :root {
      --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
      --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
      --warning-gradient: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
      --info-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
      --card-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
      --glassmorphism: rgba(255, 255, 255, 0.15);
      --backdrop-blur: blur(20px);
    }

    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #0a0a0a, #1a1a2e, #16213e, #0f3460);
      min-height: 100vh;
      padding: 20px;
      position: relative;
      overflow-x: hidden;
    }

    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: 
        radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.4) 0%, transparent 60%),
        radial-gradient(circle at 80% 20%, rgba(255, 119, 198, 0.4) 0%, transparent 60%),
        radial-gradient(circle at 40% 40%, rgba(120, 219, 226, 0.3) 0%, transparent 60%);
      pointer-events: none;
      z-index: 1;
      animation: backgroundPulse 8s ease-in-out infinite alternate;
    }

    @keyframes backgroundPulse {
      0% { opacity: 0.8; }
      100% { opacity: 1; }
    }

    .container {
      max-width: 1000px;
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.98));
      backdrop-filter: var(--backdrop-blur);
      padding: 0;
      margin: 40px auto;
      border-radius: 40px;
      box-shadow: 
        0 25px 80px rgba(0, 0, 0, 0.2),
        inset 0 1px 0 rgba(255, 255, 255, 0.8);
      position: relative;
      z-index: 2;
      animation: fadeInUp 1s ease-out;
      overflow: hidden;
    }

    .container::before {
      content: '';
      position: absolute;
      top: -3px;
      left: -3px;
      right: -3px;
      bottom: -3px;
      background: var(--primary-gradient);
      border-radius: 43px;
      z-index: -1;
      opacity: 0.8;
    }

    .form-header {
      background: var(--primary-gradient);
      padding: 40px 50px;
      border-radius: 40px 40px 0 0;
      position: relative;
      overflow: hidden;
    }

    .form-body {
      padding: 50px;
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95));
    }

    h2 {
      font-family: 'Poppins', sans-serif;
      font-weight: 700;
      font-size: 2.8rem;
      color: white;
      text-align: center;
      margin: 0;
      text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    }

    .form-label {
      font-weight: 700;
      color: #1a202c;
      margin-bottom: 1rem;
      font-size: 1.1rem;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      font-family: 'Poppins', sans-serif;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .form-control, .form-select {
      border: 3px solid rgba(102, 126, 234, 0.15);
      border-radius: 20px;
      padding: 20px 24px;
      font-size: 1.1rem;
      font-weight: 500;
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.98));
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 8px 25px rgba(102, 126, 234, 0.08);
    }

    .form-control:focus, .form-select:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 
        0 0 0 4px rgba(102, 126, 234, 0.15),
        0 15px 35px rgba(102, 126, 234, 0.2);
      transform: translateY(-3px) scale(1.02);
    }

    .form-section-title {
      font-family: 'Poppins', sans-serif;
      font-weight: 700;
      font-size: 1.6rem;
      background: var(--primary-gradient);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin: 3rem 0 2rem 0;
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 1px;
      position: relative;
      padding-bottom: 20px;
    }

    .form-section-title::after {
      content: '';
      position: absolute;
      left: 50%;
      bottom: 0;
      transform: translateX(-50%);
      width: 60px;
      height: 4px;
      background: var(--primary-gradient);
      border-radius: 2px;
    }

    .member-selection {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .member-card {
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.95));
      border: 3px solid transparent;
      border-radius: 24px;
      padding: 24px;
      cursor: pointer;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      text-align: center;
      box-shadow: 0 10px 30px rgba(102, 126, 234, 0.1);
      position: relative;
      overflow: hidden;
    }

    .member-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: var(--primary-gradient);
      opacity: 0;
      transition: all 0.4s ease;
      border-radius: 21px;
    }

    .member-card:hover {
      transform: translateY(-8px) scale(1.05);
      box-shadow: 0 20px 50px rgba(102, 126, 234, 0.25);
      border-color: rgba(102, 126, 234, 0.3);
    }

    .member-card:hover::before {
      opacity: 0.1;
    }

    .member-card.selected {
      background: var(--primary-gradient);
      color: white;
      transform: translateY(-8px) scale(1.05);
      box-shadow: 0 20px 50px rgba(102, 126, 234, 0.4);
    }

    .member-avatar {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: var(--success-gradient);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 700;
      font-size: 1.2rem;
      margin: 0 auto 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .member-card.selected .member-avatar {
      background: rgba(255, 255, 255, 0.2);
    }

    .member-name {
      font-weight: 600;
      font-size: 1.1rem;
      margin-bottom: 8px;
      font-family: 'Poppins', sans-serif;
    }

    .payer-amount-section {
      background: linear-gradient(145deg, rgba(240, 147, 251, 0.1), rgba(102, 126, 234, 0.05));
      border-radius: 28px;
      padding: 35px;
      margin-top: 30px;
      border: 3px solid rgba(240, 147, 251, 0.2);
      backdrop-filter: blur(10px);
    }

    .payer-amount-input {
      position: relative;
      margin-bottom: 1rem;
    }

    .payer-amount-input::before {
      content: '₹';
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
      color: #667eea;
      font-weight: 600;
      z-index: 3;
    }

    .payer-amount-input .form-control {
      padding-left: 40px;
    }

    .total-paid-display {
      background: var(--info-gradient);
      color: white;
      padding: 20px;
      border-radius: 20px;
      text-align: center;
      font-weight: 700;
      font-size: 1.2rem;
      margin-top: 20px;
      box-shadow: 0 10px 30px rgba(67, 233, 123, 0.3);
    }

    .custom-split {
      display: none;
      background: linear-gradient(145deg, rgba(240, 147, 251, 0.15), rgba(102, 126, 234, 0.1));
      border-radius: 28px;
      padding: 35px;
      margin-top: 30px;
      border: 3px solid rgba(240, 147, 251, 0.3);
      animation: slideIn 0.6s ease-out;
    }

    .custom-split.show {
      display: block;
    }

    .btn-primary {
      background: var(--primary-gradient);
      border: none;
      border-radius: 28px;
      padding: 20px 50px;
      font-weight: 700;
      font-size: 1.2rem;
      letter-spacing: 1px;
      text-transform: uppercase;
      font-family: 'Poppins', sans-serif;
      box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      overflow: hidden;
    }

    .btn-primary:hover {
      transform: translateY(-5px) scale(1.05);
      box-shadow: 0 25px 60px rgba(102, 126, 234, 0.5);
    }

    .alert-info {
      background: linear-gradient(135deg, rgba(79, 172, 254, 0.1), rgba(0, 242, 254, 0.1));
      border: 2px solid rgba(79, 172, 254, 0.3);
      color: #1a202c;
      border-radius: 20px;
      padding: 20px;
    }

    .split-type-selector {
      display: flex;
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .split-type-option {
      flex: 1;
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.95));
      border: 3px solid transparent;
      border-radius: 24px;
      padding: 24px;
      cursor: pointer;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      text-align: center;
      box-shadow: 0 10px 30px rgba(102, 126, 234, 0.1);
    }

    .split-type-option:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 50px rgba(102, 126, 234, 0.25);
    }

    .split-type-option.active {
      background: var(--primary-gradient);
      color: white;
      transform: translateY(-8px);
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(40px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateX(-20px);
      }
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    @media (max-width: 768px) {
      .container {
        margin: 20px auto;
        border-radius: 30px;
      }

      .form-header, .form-body {
        padding: 30px 25px;
      }

      h2 {
        font-size: 2.2rem;
      }

      .member-selection {
        grid-template-columns: 1fr;
      }

      .split-type-selector {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="form-header">
      <h2><i class="fas fa-users-dollar me-3"></i>Add Multi-Payer Expense</h2>
    </div>
    
    <div class="form-body">
      <form method="POST" id="expenseForm">
        <!-- Group Selection -->
        <div class="mb-4">
          <label for="group" class="form-label">
            <i class="fas fa-users me-2"></i>Select Group
          </label>
          <select class="form-select" id="group" name="group_id" required>
            <option value="" disabled selected>Choose a group...</option>
            <?php
            $sql = "SELECT g.id, g.name FROM groups g
                    JOIN group_members gm ON g.id = gm.group_id
                    WHERE gm.user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($group = $result->fetch_assoc()) {
                echo "<option value='{$group['id']}'>" . htmlspecialchars($group['name']) . "</option>";
            }
            $stmt->close();
            ?>
          </select>
        </div>

        <!-- Title and Amount -->
        <div class="row">
          <div class="col-md-8 mb-4">
            <label for="title" class="form-label">
              <i class="fas fa-receipt me-2"></i>Expense Title
            </label>
            <input type="text" class="form-control" name="title" id="title" 
                  placeholder="e.g., Grocery Shopping" required>
          </div>
          <div class="col-md-4 mb-4">
            <label for="total_amount" class="form-label">
              <i class="fas fa-rupee-sign me-2"></i>Total Amount
            </label>
            <input type="number" class="form-control" name="total_amount" id="total_amount" 
                  placeholder="0.00" step="0.01" min="0.01" required>
          </div>
        </div>

        <!-- Description -->
        <div class="mb-4">
          <label for="description" class="form-label">
            <i class="fas fa-align-left me-2"></i>Description 
            <span class="text-muted">(optional)</span>
          </label>
          <textarea class="form-control" name="description" rows="3" 
                    placeholder="Add any additional details..."></textarea>
        </div>

        <!-- Date -->
        <div class="mb-4">
          <div class="col-md-6">
            <label for="expense_date" class="form-label">
              <i class="fas fa-calendar-alt me-2"></i>Expense Date
            </label>
            <input type="date" class="form-control" name="expense_date" id="expense_date" 
                  value="<?php echo date('Y-m-d'); ?>" required>
          </div>
        </div>

        <!-- Who Paid Section -->
        <div class="form-section-title">
          <i class="fas fa-hand-holding-usd me-2"></i>Who Paid? (Select Multiple)
        </div>
        <div id="payers_section" class="member-selection">
          <em>Select a group to load members...</em>
        </div>

        <!-- Payer Amounts Section -->
        <div id="payer_amounts_section" class="payer-amount-section" style="display: none;">
          <h5 class="text-center mb-4">
            <i class="fas fa-calculator me-2"></i>How Much Did Each Person Pay?
          </h5>
          <div id="payer_amounts_inputs">
            <!-- Dynamic inputs will be generated here -->
          </div>
          <div id="total_paid_display" class="total-paid-display">
            Total Paid: ₹<span id="total_paid_amount">0.00</span>
          </div>
        </div>

        <!-- Split Type Section -->
        <div class="form-section-title">
          <i class="fas fa-chart-pie me-2"></i>How to Split the Expense?
        </div>
        <div class="split-type-selector">
          <div class="split-type-option active" onclick="toggleSplitType('equal')">
            <input type="radio" name="split_type" value="equal" checked style="display: none;">
            <div>
              <i class="fas fa-equals fa-2x mb-3"></i>
              <div class="fw-bold">Equal Split</div>
              <small>Divide equally among all members</small>
            </div>
          </div>
          <div class="split-type-option" onclick="toggleSplitType('custom')">
            <input type="radio" name="split_type" value="custom" style="display: none;">
            <div>
              <i class="fas fa-sliders-h fa-2x mb-3"></i>
              <div class="fw-bold">Custom Split</div>
              <small>Set custom amounts for each person</small>
            </div>
          </div>
        </div>

        <!-- Custom Split Section -->
        <div id="customSplitBox" class="custom-split">
          <h6 class="text-center mb-4">
            <i class="fas fa-edit me-2"></i>Custom Split Amounts
          </h6>
          <div id="custom_split_section">
            <em>Select a group to load members...</em>
          </div>
          <div class="alert alert-info mt-3">
            <i class="fas fa-lightbulb me-2"></i>
            <strong>Tip:</strong> Make sure all shares add up to the total amount!
          </div>
        </div>

        <!-- Submit Button -->
        <div class="text-center mt-5">
          <div class="form-group mt-4 d-flex justify-content-between">
    <!-- Back Button -->
    <button type="button" class="btn btn-secondary" onclick="history.back()">Back</button>

    <!-- Submit Button -->
    <button type="submit" class="btn btn-primary">Save Multi-Payer Expense</button>
</div>

        </div>
      </form>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    let groupMembers = [];
    let selectedPayers = new Set();

    // Toggle split type
    function toggleSplitType(type) {
      const equalDiv = document.querySelector('[onclick="toggleSplitType(\'equal\')"]');
      const customDiv = document.querySelector('[onclick="toggleSplitType(\'custom\')"]');
      const customBox = document.getElementById('customSplitBox');
      
      if (type === 'equal') {
        equalDiv.classList.add('active');
        customDiv.classList.remove('active');
        customBox.classList.remove('show');
        document.querySelector('input[value="equal"]').checked = true;
      } else {
        customDiv.classList.add('active');
        equalDiv.classList.remove('active');
        customBox.classList.add('show');
        document.querySelector('input[value="custom"]').checked = true;
      }
    }

    // Load group members when group is selected
    $(document).ready(function() {
        $('#group').on('change', function() {
            var groupId = $(this).val();
            if (groupId) {
                $.ajax({
                    url: 'get_members.php',
                    type: 'POST',
                    data: { group_id: groupId },
                    dataType: 'json',
                    success: function(data) {
                        groupMembers = data || [];
                        selectedPayers.clear();
                        renderPayersSection();
                        renderCustomSplitSection();
                        updatePayerAmountsSection();
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        console.error('Response:', xhr.responseText);
                        $('#payers_section').html('<em class="text-danger">Error loading members. Please try again.</em>');
                    }
                });
            } else {
                $('#payers_section').html('<em>Select a group to load members...</em>');
                $('#custom_split_section').html('<em>Select a group to load members...</em>');
            }
        });

        // Real-time validation
        $('#total_amount').on('input', function() {
            updateCustomSplitPlaceholders();
            validatePayerAmounts();
        });
    });

    function renderPayersSection() {
        let html = '';
        
        if (groupMembers && groupMembers.length > 0) {
            groupMembers.forEach(member => {
                const firstLetter = member.username.charAt(0).toUpperCase();
                const isSelected = selectedPayers.has(member.id.toString());
                
                html += `
                    <div class="member-card ${isSelected ? 'selected' : ''}" onclick="togglePayer('${member.id}')">
                        <input type="checkbox" name="payers[]" value="${member.id}" ${isSelected ? 'checked' : ''} style="display: none;">
                        <div class="member-avatar">${firstLetter}</div>
                        <div class="member-name">${member.username}</div>
                        <small>Click to ${isSelected ? 'remove' : 'select'}</small>
                    </div>`;
            });
        } else {
            html = '<em>No members found in this group.</em>';
        }
        
        $('#payers_section').html(html);
    }

    function togglePayer(userId) {
        const userIdStr = userId.toString();
        
        if (selectedPayers.has(userIdStr)) {
            selectedPayers.delete(userIdStr);
        } else {
            selectedPayers.add(userIdStr);
        }
        
        renderPayersSection();
        updatePayerAmountsSection();
    }

    function updatePayerAmountsSection() {
        if (selectedPayers.size === 0) {
            $('#payer_amounts_section').hide();
            return;
        }

        $('#payer_amounts_section').show();
        
        let html = '';
        const totalAmount = parseFloat($('#total_amount').val()) || 0;
        const suggestedAmount = totalAmount > 0 ? (totalAmount / selectedPayers.size).toFixed(2) : '0.00';
        
        // Add auto-distribute button if multiple payers
        if (selectedPayers.size > 1) {
            html += `
                <div class="text-center mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="autoDistributeAmounts()">
                        <i class="fas fa-magic me-1"></i>Auto-Distribute Evenly
                    </button>
                </div>`;
        }
        
        selectedPayers.forEach(userId => {
            const member = groupMembers.find(m => m.id.toString() === userId);
            if (member) {
                const firstLetter = member.username.charAt(0).toUpperCase();
                html += `
                    <div class="payer-amount-input">
                        <label class="form-label">
                            <div class="member-avatar me-2" style="width: 30px; height: 30px; font-size: 0.9rem; display: inline-flex;">${firstLetter}</div>
                            ${member.username}
                        </label>
                        <input type="number" class="form-control payer-amount" name="payer_amounts[${member.id}]" 
                              placeholder="₹${suggestedAmount}" step="0.01" min="0" 
                              onchange="validatePayerAmounts()" oninput="updateTotalPaid()">
                    </div>`;
            }
        });
        
        $('#payer_amounts_inputs').html(html);
        updateTotalPaid();
    }

    function updateTotalPaid() {
        let total = 0;
        $('.payer-amount').each(function() {
            const value = parseFloat($(this).val()) || 0;
            total += value;
        });
        
        $('#total_paid_amount').text(total.toFixed(2));
        
        // Visual feedback
        const expenseTotal = parseFloat($('#total_amount').val()) || 0;
        const difference = Math.abs(total - expenseTotal);
        
        if (difference < 0.01 && total > 0) {
            $('#total_paid_display').css('background', 'var(--success-gradient)');
        } else if (total > expenseTotal) {
            $('#total_paid_display').css('background', 'var(--warning-gradient)');
        } else {
            $('#total_paid_display').css('background', 'var(--info-gradient)');
        }
    }

    function validatePayerAmounts() {
        const expenseTotal = parseFloat($('#total_amount').val()) || 0;
        const totalPaid = parseFloat($('#total_paid_amount').text()) || 0;
        const difference = Math.abs(totalPaid - expenseTotal);
        
        return difference < 0.01 && totalPaid > 0;
    }

    function renderCustomSplitSection() {
        let html = '';
        
        if (groupMembers && groupMembers.length > 0) {
            const totalAmount = parseFloat($('#total_amount').val()) || 0;
            const equalShare = totalAmount > 0 ? (totalAmount / groupMembers.length).toFixed(2) : '0.00';
            
            groupMembers.forEach(member => {
                const firstLetter = member.username.charAt(0).toUpperCase();
                html += `
                    <div class="mb-3">
                        <label for="custom_${member.id}" class="form-label">
                            <div class="member-avatar me-2" style="width: 30px; height: 30px; font-size: 0.9rem; display: inline-flex;">${firstLetter}</div>
                            ${member.username}
                        </label>
                        <div class="custom-split-input">
                            <input type="number" class="form-control custom-split-amount" name="custom_share[${member.id}]" id="custom_${member.id}" 
                                  placeholder="₹${equalShare}" step="0.01" min="0" onchange="validateCustomSplit()" oninput="validateCustomSplit()">
                        </div>
                    </div>`;
            });
        } else {
            html = '<em>No members found in this group.</em>';
        }
        
        $('#custom_split_section').html(html);
    }

    function updateCustomSplitPlaceholders() {
        const amountValue = document.getElementById('total_amount').value;
        if (amountValue && !isNaN(amountValue) && amountValue > 0 && groupMembers.length > 0) {
            const equalShare = (parseFloat(amountValue) / groupMembers.length).toFixed(2);
            document.querySelectorAll('[name^="custom_share"]').forEach(input => {
                input.placeholder = `₹${equalShare}`;
            });
        }
    }

    function validateCustomSplit() {
        const totalAmount = parseFloat($('#total_amount').val()) || 0;
        let customTotal = 0;
        
        $('.custom-split-amount').each(function() {
            const value = parseFloat($(this).val()) || 0;
            customTotal += value;
        });
        
        // Visual feedback in custom split section
        const alertDiv = $('#customSplitBox .alert');
        const difference = Math.abs(customTotal - totalAmount);
        
        if (difference < 0.01 && customTotal > 0) {
            alertDiv.removeClass('alert-info alert-warning').addClass('alert-success');
            alertDiv.html('<i class="fas fa-check me-2"></i><strong>Perfect!</strong> Custom amounts match the total expense.');
        } else if (customTotal > totalAmount + 0.01) {
            alertDiv.removeClass('alert-info alert-success').addClass('alert-warning');
            alertDiv.html('<i class="fas fa-exclamation-triangle me-2"></i><strong>Warning:</strong> Custom amounts exceed total expense by ₹' + (customTotal - totalAmount).toFixed(2));
        } else if (customTotal > 0) {
            alertDiv.removeClass('alert-success alert-warning').addClass('alert-info');
            alertDiv.html('<i class="fas fa-lightbulb me-2"></i><strong>Tip:</strong> You need ₹' + (totalAmount - customTotal).toFixed(2) + ' more to match the total.');
        } else {
            alertDiv.removeClass('alert-success alert-warning').addClass('alert-info');
            alertDiv.html('<i class="fas fa-lightbulb me-2"></i><strong>Tip:</strong> Make sure all shares add up to the total amount!');
        }
        
        return difference < 0.01 && customTotal > 0;
    }

    // Auto-distribute amounts evenly when payers change
    function autoDistributeAmounts() {
        const totalAmount = parseFloat($('#total_amount').val()) || 0;
        if (totalAmount > 0 && selectedPayers.size > 0) {
            const amountPerPayer = (totalAmount / selectedPayers.size).toFixed(2);
            $('.payer-amount').val(amountPerPayer);
            updateTotalPaid();
        }
    }

    // Form validation
    document.getElementById('expenseForm').addEventListener('submit', function(e) {
        // Check if at least one payer is selected
        if (selectedPayers.size === 0) {
            e.preventDefault();
            alert('Please select at least one person who paid for this expense.');
            return false;
        }
        
        // Validate total amount
        const totalAmount = parseFloat($('#total_amount').val()) || 0;
        if (totalAmount <= 0) {
            e.preventDefault();
            alert('Please enter a valid total amount greater than 0.');
            return false;
        }
        
        // Validate payer amounts
        if (!validatePayerAmounts()) {
            e.preventDefault();
            const totalPaid = parseFloat($('#total_paid_amount').text()) || 0;
            alert(`The total paid (₹${totalPaid.toFixed(2)}) doesn't match the expense amount (₹${totalAmount.toFixed(2)}). Please adjust the amounts.`);
            return false;
        }
        
        // Validate custom split if selected
        const splitType = document.querySelector('input[name="split_type"]:checked').value;
        if (splitType === 'custom') {
            if (!validateCustomSplit()) {
                e.preventDefault();
                let customTotal = 0;
                let hasValidShares = false;
                
                document.querySelectorAll('[name^="custom_share"]').forEach(input => {
                    const value = parseFloat(input.value) || 0;
                    if (value > 0) {
                        customTotal += value;
                        hasValidShares = true;
                    }
                });
                
                if (!hasValidShares) {
                    alert('Please enter at least one custom share amount.');
                    return false;
                }
                
                if (Math.abs(customTotal - totalAmount) > 0.01) {
                    alert(`Custom split amounts (₹${customTotal.toFixed(2)}) don't match total amount (₹${totalAmount.toFixed(2)}). Please adjust the shares.`);
                    return false;
                }
            }
        }
        
        return true;
    });
  </script>
</body>
</html>