<?php
session_start();
require_once 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get user ID from session
$user_id = $_SESSION['user_id'];

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count_result = $stmt->get_result()->fetch_assoc();
    $unread_count = $count_result['unread_count'];
} else {
    $unread_count = 0;
}

// Fetch FRESH user data from DB (including updated profile pic)
$stmt = $conn->prepare("SELECT username, email, phone, profile_pic FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $username = $user['username'];
    $email = $user['email'];
    $phone = $user['phone'];
    // Handle profile picture path properly
    $profile_pic = (!empty($user['profile_pic']) && $user['profile_pic'] !== 'default.png') 
                   ? $user['profile_pic'] 
                   : 'default.png';
} else {
    // Handle case where user not found
    header("Location: logout.php");
    exit();
}

// Close the statement
$stmt->close();

// FIXED function to get user stats
function getUserStats($conn, $user_id) {
    $stats = [
        'total_spent' => 0,
        'you_owe' => 0,
        'others_owe' => 0,
        'net_balance' => 0
    ];

    // Total spent by the user (from expense_payers table for multi-payer support)
    $sql = "SELECT COALESCE(SUM(ep.amount_paid), 0) as total 
            FROM expense_payers ep
            JOIN expenses e ON ep.expense_id = e.id
            WHERE ep.user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_spent'] = $row['total'] ?? 0;
    $stmt->close();

    // FIXED: Calculate what user actually owes based on settlements table
    // Sum all positive amounts where user is the one who owes (paid_by = user)
    $sql = "SELECT COALESCE(SUM(amount), 0) as you_owe 
            FROM settlements 
            WHERE paid_by = ? AND amount > 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['you_owe'] = $row['you_owe'] ?? 0;
    $stmt->close();

    // FIXED: Calculate what others owe user based on settlements table
    // Sum all positive amounts where others owe user (paid_to = user)
    $sql = "SELECT COALESCE(SUM(amount), 0) as others_owe 
            FROM settlements 
            WHERE paid_to = ? AND amount > 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['others_owe'] = $row['others_owe'] ?? 0;
    $stmt->close();

    // Calculate net balance: what others owe you minus what you owe others
    $stats['net_balance'] = $stats['others_owe'] - $stats['you_owe'];

    return $stats;
}

$stats = getUserStats($conn, $user_id);

// Get settlement details for the user
function getSettlementDetails($conn, $user_id) {
    $settlements = [
        'you_owe' => [],
        'owed_to_you' => []
    ];

    // People you owe money to
    $stmt = $conn->prepare("
        SELECT u.username, s.amount, g.name as group_name
        FROM settlements s 
        JOIN users u ON s.paid_to = u.id 
        JOIN groups g ON s.group_id = g.id
        WHERE s.paid_by = ? AND s.amount > 0.01
        ORDER BY s.amount DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $settlements['you_owe'][] = $row;
    }
    $stmt->close();

    // People who owe you money
    $stmt = $conn->prepare("
        SELECT u.username, s.amount, g.name as group_name
        FROM settlements s 
        JOIN users u ON s.paid_by = u.id 
        JOIN groups g ON s.group_id = g.id
        WHERE s.paid_to = ? AND s.amount > 0.01
        ORDER BY s.amount DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $settlements['owed_to_you'][] = $row;
    }
    $stmt->close();

    return $settlements;
}

$settlement_details = getSettlementDetails($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SplitEase - Dashboard</title>
    <link rel="stylesheet" href="morden_style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* Enhanced Sidebar Styles */
        .sidebar {
            width: 280px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            background: linear-gradient(180deg, rgba(44, 62, 80, 0.95) 0%, rgba(52, 73, 94, 0.95) 100%);
            backdrop-filter: blur(20px);
            color: white;
            padding: 25px;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            box-shadow: 4px 0 30px rgba(0, 0, 0, 0.2);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar.hidden {
            transform: translateX(-100%);
        }

        .sidebar h2 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 2.5rem;
            text-align: center;
            color: #fff;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            border-bottom: 2px solid rgba(255, 255, 255, 0.15);
            padding-bottom: 1.5rem;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar ul li {
            margin-bottom: 8px;
        }

        .sidebar ul li a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            display: flex;
            align-items: center;
            padding: 16px 20px;
            border-radius: 15px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
            font-size: 1rem;
            position: relative;
            overflow: hidden;
        }

        .sidebar ul li a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .sidebar ul li a:hover::before {
            left: 100%;
        }

        .sidebar ul li a:hover,
        .sidebar ul li a.active {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(118, 75, 162, 0.2));
            color: #fff;
            transform: translateX(8px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .sidebar ul li a i {
            margin-right: 15px;
            width: 24px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Enhanced Toggle button */
        .sidebar-toggle {
            position: fixed;
            top: 20px;
            left: 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 18px;
            font-size: 20px;
            cursor: pointer;
            border-radius: 50%;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            z-index: 1100;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .sidebar-toggle:hover {
            transform: translateY(-3px) rotate(90deg);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.5);
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .main-content.full {
            margin-left: 30px;
        }

        /* Profile picture styles */
        .profile-pic {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            margin-right: 15px;
        }

        .user-info {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .user-info h2 {
            margin: 0;
            color: white;
        }
        /* Updated settlement section styles */
        .settlement-section {
            margin-top: 30px;
        }

        .settlement-boxes {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .settlement-card {
            flex: 1;
            min-width: 300px;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            color: white;
            transition: transform 0.3s ease;
        }

        .settlement-card:hover {
            transform: translateY(-5px);
        }

        .settlement-card.red {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }

        .settlement-card.green {
            background: linear-gradient(135deg, #27ae60, #229954);
        }

        .settlement-card h4 {
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .settlement-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 200px;
            overflow-y: auto;
        }

        .settlement-card li {
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            font-size: 0.9rem;
        }

        .settlement-card li:last-child {
            border-bottom: none;
        }

        .settlement-card .no-settlements {
            text-align: center;
            opacity: 0.8;
            font-style: italic;
        }

        .settlement-amount {
            font-weight: bold;
            font-size: 1.1rem;
        }

        .group-name {
            font-size: 0.8rem;
            opacity: 0.8;
            margin-left: 5px;
        }

        /* Enhanced stats grid */
        .stats-overview {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .stats-overview h3 {
            margin-bottom: 15px;
            font-weight: 600;
        }

        .net-balance {
            font-size: 2rem;
            font-weight: bold;
            text-align: center;
            margin: 10px 0;
        }

        .net-balance.positive {
            color: #2ecc71;
        }

        .net-balance.negative {
            color: #e74c3c;
        }

        .net-balance.neutral {
            color: #95a5a6;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 250px;
            }
            
            .main-content {
                margin-left: 250px;
                padding: 20px;
            }
            
            .main-content.full {
                margin-left: 20px;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .main-content.full {
                margin-left: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        
        <button id="sidebarToggle" class="sidebar-toggle">☰</button>
        
        <div class="sidebar" id="sidebar">
            <h2>SplitEase</h2>
            <ul>
                <li><a href="dash.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="group.php"><i class="fas fa-users"></i> My Groups</a></li>
                <li><a href="add_expense.php"><i class="fas fa-plus-circle"></i> Add Expense</a></li>
                <li><a href="settlements.php"><i class="fas fa-handshake"></i> Settlements</a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li> 
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
            <!-- Header -->
            <div class="header">
                <div class="user-info">
                    <?php 
                    // Display profile picture with proper path handling and error checking
                    $profilePicPath = 'uploads/' . $profile_pic;
                    
                    // Check if file exists, if not use default
                    if (!file_exists($profilePicPath)) {
                        $profilePicPath = 'uploads/default.png';
                        // If default doesn't exist either, create a placeholder
                        if (!file_exists($profilePicPath)) {
                            $profilePicPath = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjUiIGN5PSIyNSIgcj0iMjUiIGZpbGw9IiNlMGU3ZmYiLz4KPHN2ZyB4PSIxMiIgeT0iMTIiIHdpZHRoPSIyNiIgaGVpZ2h0PSIyNiIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiM2MzY2ZjEiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj4KPHBhdGggZD0iMjAgMjF2LTJhNCA0IDAgMCAwLTQtNEg4YTQgNCAwIDAgMC00IDR2MiIvPgo8Y2lyY2xlIGN4PSIxMiIgY3k9IjciIHI9IjQiLz4KPC9zdmc+Cjwvc3ZnPg==';
                        }
                    }
                    ?>
                    <img src="<?php echo htmlspecialchars($profilePicPath); ?>" class="profile-pic" alt="Profile Picture" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTAiIGhlaWdodD0iNTAiIHZpZXdCb3g9IjAgMCA1MCA1MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMjUiIGN5PSIyNSIgcj0iMjUiIGZpbGw9IiNlMGU3ZmYiLz4KPHN2ZyB4PSIxMiIgeT0iMTIiIHdpZHRoPSIyNiIgaGVpZ2h0PSIyNiIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiM2MzY2ZjEiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj4KPHBhdGggZD0iMjAgMjF2LTJhNCA0IDAgMCAwLTQtNEg4YTQgNCAwIDAgMC00IDR2MiIvPgo8Y2lyY2xlIGN4PSIxMiIgY3k9IjciIHI9IjQiLz4KPC9zdmc+Cjwvc3ZnPg=='">
                    <h2>Welcome back, <span style="font-weight: bold;"><?php echo htmlspecialchars($username); ?></span>!</h2>
                </div>
            </div>
  
<!-- Notifications Bell -->
<div style="position: relative; margin-left: 20px;">
    <a href="#" id="notifBell" style="font-size: 22px; color: #fff; text-decoration: none;">
        <i class="fas fa-bell"></i>
        <?php if ($unread_count > 0) { ?>
            <span id="notifCount" style="position: absolute; top: -8px; right: -10px; background: red; color: white; 
                         padding: 2px 6px; border-radius: 50%; font-size: 12px; font-weight: bold;">
                <?= $unread_count ?>
            </span>
        <?php } ?>
    </a>
    <div id="notifDropdown" style="display:none; position: absolute; right: 0; top: 35px; background: #1e1e2f; color: #fff; width: 320px; 
                                    border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); z-index: 999;">
        <ul id="notifList" style="list-style: none; margin: 0; padding: 0; max-height: 250px; overflow-y: auto;"></ul>
        <div style="text-align: center; padding: 8px; border-top: 1px solid rgba(255,255,255,0.1);">
            <a href="notifications.php" style="color: #4dabf7; text-decoration: none;">View All</a>
        </div>
    </div>
</div>



<script>
li.innerHTML = `<a href="#" data-id="${notif.id}" data-link="${notif.link}" style="text-decoration:none;color:#333;">
                    ${notif.message}
                </a>
                <div style="font-size:0.8em;color:#999;">${notif.created_at}</div>`;</script>
<script>
// Add click event to mark as read and redirect
li.querySelector('a').addEventListener('click', function(e) {
    e.preventDefault();
    let notifId = this.dataset.id;
    let link = this.dataset.link;

    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `id=${notifId}`
    }).then(response => response.text())
      .then(() => {
          // Reduce badge count without reload
          let badge = document.getElementById('notifCount');
          if (badge) {
              let count = parseInt(badge.innerText) - 1;
              badge.innerText = count > 0 ? count : '';
          }
          // Redirect after marking as read
          window.location.href = link;
      });
});
</script>




            <!-- Net Balance Overview -->
            <div class="stats-overview">
                <h3><i class="fas fa-balance-scale me-2"></i>Your Financial Overview</h3>
                <div class="net-balance <?php 
                    if ($stats['net_balance'] > 0) echo 'positive';
                    elseif ($stats['net_balance'] < 0) echo 'negative';
                    else echo 'neutral';
                ?>">
                    <?php if ($stats['net_balance'] > 0): ?>
                        <i class="fas fa-arrow-up me-2"></i>+₹<?php echo number_format($stats['net_balance'], 2); ?>
                        <div style="font-size: 1rem; font-weight: normal; margin-top: 5px;">Others owe you money</div>
                    <?php elseif ($stats['net_balance'] < 0): ?>
                        <i class="fas fa-arrow-down me-2"></i>-₹<?php echo number_format(abs($stats['net_balance']), 2); ?>
                        <div style="font-size: 1rem; font-weight: normal; margin-top: 5px;">You owe others money</div>
                    <?php else: ?>
                        <i class="fas fa-check-circle me-2"></i>₹0.00
                        <div style="font-size: 1rem; font-weight: normal; margin-top: 5px;">You're all settled up!</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats Section -->
            <section class="stats-grid">
                <div class="stat-card total-spent animate-fadeInUp">
                    <div class="stat-card-header">
                        <div class="stat-icon total-spent">
                            <i class="bi bi-currency-rupee"></i>
                        </div>
                        <div class="stat-content">
                            <h3>₹<?php echo number_format($stats['total_spent'], 2); ?></h3>
                            <p>Total You Paid</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card you-owe animate-fadeInUp">
                    <div class="stat-card-header">
                        <div class="stat-icon you-owe">
                            <i class="bi bi-arrow-up-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3>₹<?php echo number_format($stats['you_owe'], 2); ?></h3>
                            <p>You Owe Others</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card others-owe animate-fadeInUp">
                    <div class="stat-card-header">
                        <div class="stat-icon others-owe">
                            <i class="bi bi-arrow-down-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3>₹<?php echo number_format($stats['others_owe'], 2); ?></h3>
                            <p>Others Owe You</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Groups Section -->
            <section>
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="bi bi-people-fill"></i> My Groups
                    </h2>
                    <a href="create_grp.php" class="btn-primary-custom">
                        <i class="bi bi-plus-circle"></i>
                        Create New Group
                    </a>
                </div>

                <div class="groups-grid">
                    <?php
                    $sql = "
                        SELECT g.id, g.name, g.icon, g.created_at,
                               (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE group_id = g.id) AS total_expense,
                               (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS member_count
                        FROM groups g
                        JOIN group_members gm ON g.id = gm.group_id
                        WHERE gm.user_id = ?
                        ORDER BY g.created_at DESC
                    ";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0):
                        while ($group = $result->fetch_assoc()):
                    ?>
                        <div class="group-card animate-fadeInUp">
                            <div class="group-card-header">
                                <div class="group-icon">
                                    <i class="bi <?php echo htmlspecialchars($group['icon'] ?? 'bi-people'); ?>"></i>
                                </div>
                                <div class="group-info">
                                    <h4><?php echo htmlspecialchars($group['name']); ?></h4>
                                    <p>Created <?php echo date("j M Y", strtotime($group['created_at'])); ?></p>
                                </div>
                            </div>
                            <div class="group-stats">
                                <div>
                                    <span class="group-amount">₹<?php echo number_format($group['total_expense'] ?? 0, 2); ?></span>
                                    <p class="group-members"><?php echo $group['member_count']; ?> members</p>
                                </div>
                                <?php if (isset($group['id']) && !empty($group['id'])): ?>
                                    <a href="view_group.php?id=<?php echo $group['id']; ?>" class="btn-primary-custom btn-sm-custom">
                                        <i class="bi bi-eye"></i> View Group
                                    </a>
                                <?php else: ?>
                                    <span class="text-danger">Invalid Group</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <div class="no-groups">
                            <p>You're not part of any groups yet. <a href="create_grp.php">Create your first group!</a></p>
                        </div>
                    <?php endif; ?>
                    <?php $stmt->close(); ?>
                </div>
            </section>

            <!-- Enhanced Pending Settlements -->
            <div class="settlement-section">
                <h3><i class="fas fa-handshake me-2"></i>Pending Settlements</h3>
                <div class="settlement-boxes">

                    <!-- You owe others -->
                    <div class="settlement-card red">
                        <h4><i class="fas fa-arrow-up"></i> You Owe</h4>
                        <ul>
                            <?php if (!empty($settlement_details['you_owe'])): ?>
                                <?php foreach ($settlement_details['you_owe'] as $debt): ?>
                                    <li>
                                        <div>You owe <span class="settlement-amount">₹<?php echo number_format($debt['amount'], 2); ?></span> to <strong><?php echo htmlspecialchars($debt['username']); ?></strong></div>
                                        <div class="group-name">in <?php echo htmlspecialchars($debt['group_name']); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="no-settlements">
                                    <i class="fas fa-check-circle me-2"></i>No pending payments
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Others owe you -->
                    <div class="settlement-card green">
                        <h4><i class="fas fa-arrow-down"></i> Owed To You</h4>
                        <ul>
                            <?php if (!empty($settlement_details['owed_to_you'])): ?>
                                <?php foreach ($settlement_details['owed_to_you'] as $credit): ?>
                                    <li>
                                        <div><strong><?php echo htmlspecialchars($credit['username']); ?></strong> owes you <span class="settlement-amount">₹<?php echo number_format($credit['amount'], 2); ?></span></div>
                                        <div class="group-name">in <?php echo htmlspecialchars($credit['group_name']); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="no-settlements">
                                    <i class="fas fa-info-circle me-2"></i>No one owes you money
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </div>

   <script>
    const toggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('hidden');
        mainContent.classList.toggle('full');
    });

    // Make settlement rows clickable
    document.querySelectorAll('.settle-row').forEach(row => {
        row.addEventListener('click', function() {
            window.location.href = this.dataset.href;
        });
    });
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const notifList = document.getElementById('notifList');

    // Toggle dropdown
    bell.addEventListener('click', function(e) {
        e.preventDefault();
        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';

        // Fetch notifications when opening
        if (dropdown.style.display === 'block') {
            fetch('fetch_notifications.php')
                .then(response => response.json())
                .then(data => {
                    notifList.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(notif => {
                            let li = document.createElement('li');
                            li.style.padding = '10px';
                            li.style.borderBottom = '1px solid #eee';
                            li.innerHTML = `
                                <a href="#" data-id="${notif.id}" data-link="${notif.link}" style="text-decoration:none;color:#333;">
                                    ${notif.message}
                                </a>
                                <div style="font-size:0.8em;color:#999;">${notif.created_at}</div>
                            `;

                            // Mark as read & redirect
                            li.querySelector('a').addEventListener('click', function(ev) {
                                ev.preventDefault();
                                let notifId = this.dataset.id;
                                let link = this.dataset.link;

                                fetch('mark_notification_read.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                    body: `id=${notifId}`
                                }).then(() => {
                                    // Reduce badge count
                                    let badge = document.getElementById('notifCount');
                                    if (badge) {
                                        let count = parseInt(badge.innerText) - 1;
                                        badge.innerText = count > 0 ? count : '';
                                        if (badge.innerText === '') badge.remove();
                                    }
                                    window.location.href = link;
                                });
                            });

                            notifList.appendChild(li);
                        });
                    } else {
                        notifList.innerHTML = '<li style="padding:10px;text-align:center;color:#777;">No notifications</li>';
                    }
                });
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
});
</script>

</body>
</html>