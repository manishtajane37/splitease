<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id = $_SESSION['user_id'];
$created_group_id = $_SESSION['new_group_id'] ?? null;

// Fetch groups
$sql = "SELECT g.id, g.name, g.created_by
        FROM groups g
        JOIN group_members gm ON g.id = gm.group_id
        WHERE gm.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$groups = [];
while ($row = $result->fetch_assoc()) {
    $groups[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Groups | SplitEase</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- External CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
    <!-- <link rel="stylesheet" href="morden_style.css"> -->
    
    <style>
/* Enhanced Groups Page Styling */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
    min-height: 100vh;
    color: white;
    overflow-x: hidden;
    position: relative;
}

/* Animated background elements */
body::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
   background: linear-gradient(135deg, #0a0a0a, #1a1a2e, #16213e, #0f3460);
    z-index: -1;
    animation: float 20s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0px) rotate(0deg); }
    33% { transform: translateY(-30px) rotate(2deg); }
    66% { transform: translateY(20px) rotate(-1deg); }
}

/* Sidebar Styles */
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




/* Toggle button */
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
    padding: 30px;
    transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 100vh;
}

.main-content.full {
    margin-left: 30px;
}

/* Header */
.header {
    text-align: center;
    margin-bottom: 4rem;
    color: white;
    position: relative;
}

.header h1 {
    font-size: 3.5rem;
    font-weight: 800;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    background: linear-gradient(135deg, #fff, #f093fb);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    text-shadow: 0 4px 20px rgba(255, 255, 255, 0.3);
    animation: glow 2s ease-in-out infinite alternate;
}

@keyframes glow {
    from { filter: drop-shadow(0 0 20px rgba(255, 255, 255, 0.3)); }
    to { filter: drop-shadow(0 0 30px rgba(240, 147, 251, 0.4)); }
}

.header p {
    font-size: 1.3rem;
    opacity: 0.9;
    font-weight: 400;
}

/* Success Alert */
.alert-success {
    background: linear-gradient(135deg, rgba(39, 174, 96, 0.9), rgba(46, 204, 113, 0.9));
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 20px;
    padding: 25px;
    margin-bottom: 30px;
    color: white;
    box-shadow: 0 10px 40px rgba(39, 174, 96, 0.3);
    animation: slideInDown 0.5s ease-out;
}

@keyframes slideInDown {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Enhanced Group Cards */
.group-box {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.05));
    backdrop-filter: blur(30px);
    border-radius: 25px;
    padding: 30px;
    margin-bottom: 25px;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
    border: 2px solid rgba(255, 255, 255, 0.2);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    color: white;
    animation: fadeInUp 0.6s ease-out;
}

@keyframes fadeInUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.group-box::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: linear-gradient(90deg, #667eea, #764ba2, #f093fb);
    background-size: 200% 100%;
    animation: shimmer 3s ease-in-out infinite;
}

@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

.group-box:hover {
    transform: translateY(-10px) scale(1.02);
    box-shadow: 0 25px 70px rgba(102, 126, 234, 0.3);
    border-color: rgba(255, 255, 255, 0.4);
}

.group-box h5 {
    margin-bottom: 15px;
    font-weight: 700;
    color: white;
    font-size: 1.4rem;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
}

.group-box p {
    color: rgba(255, 255, 255, 0.8);
    margin-bottom: 20px;
    font-size: 1rem;
}

/* Enhanced Buttons */
.btn {
    border-radius: 15px;
    padding: 12px 24px;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    position: relative;
    overflow: hidden;
}

.btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s;
}

.btn:hover::before {
    left: 100%;
}

.btn-outline-primary {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
    color: white;
    border: 2px solid rgba(102, 126, 234, 0.5);
    backdrop-filter: blur(10px);
}

.btn-outline-primary:hover {
    background: linear-gradient(135deg, #667eea, #764ba2);
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, #27ae60, #2ecc71);
    color: white;
    box-shadow: 0 8px 25px rgba(39, 174, 96, 0.3);
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 35px rgba(39, 174, 96, 0.4);
    color: white;
}

.btn-danger {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
    box-shadow: 0 8px 25px rgba(231, 76, 60, 0.3);
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 35px rgba(231, 76, 60, 0.4);
    color: white;
}

.btn-warning {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    box-shadow: 0 8px 25px rgba(243, 156, 18, 0.3);
}

.btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 35px rgba(243, 156, 18, 0.4);
    color: white;
}

.btn-primary {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
    box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 35px rgba(52, 152, 219, 0.4);
    color: white;
}

/* Form Sections */
.form-section {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.05));
    backdrop-filter: blur(30px);
    border-radius: 25px;
    padding: 35px;
    margin-bottom: 30px;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
    border: 2px solid rgba(255, 255, 255, 0.2);
    color: white;
    animation: fadeInUp 0.6s ease-out;
}

.form-section h4 {
    margin-bottom: 25px;
    font-weight: 700;
    color: white;
    font-size: 1.5rem;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
}

/* Form Controls */
.form-control {
    background: rgba(255, 255, 255, 0.1);
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-radius: 15px;
    padding: 15px 20px;
    color: white;
    font-size: 1rem;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
}

.form-control::placeholder {
    color: rgba(255, 255, 255, 0.7);
}

.form-control:focus {
    background: rgba(255, 255, 255, 0.15);
    border-color: rgba(102, 126, 234, 0.8);
    box-shadow: 0 0 25px rgba(102, 126, 234, 0.3);
    color: white;
}

/* Alert Info */
.alert-info {
    background: linear-gradient(135deg, rgba(52, 152, 219, 0.9), rgba(41, 128, 185, 0.9));
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 20px;
    padding: 25px;
    color: white;
    box-shadow: 0 10px 40px rgba(52, 152, 219, 0.3);
    animation: slideInDown 0.5s ease-out;
}

/* No Groups Message */
.no-groups {
    text-align: center;
    padding: 60px 40px;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
    border-radius: 25px;
    backdrop-filter: blur(20px);
    border: 2px dashed rgba(255, 255, 255, 0.3);
    color: white;
    font-size: 1.1rem;
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
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
    
    .header h1 {
        font-size: 2.5rem;
    }
    
    .group-box,
    .form-section {
        padding: 25px;
    }
}

@media (max-width: 576px) {
    .main-content {
        padding: 15px;
    }
    
    .header h1 {
        font-size: 2rem;
        flex-direction: column;
        gap: 1rem;
    }
    
    .group-box,
    .form-section {
        padding: 20px;
    }
    
    .btn {
        padding: 10px 20px;
        font-size: 0.9rem;
    }
}

/* Custom Scrollbar */
::-webkit-scrollbar {
    width: 10px;
}

::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
}

::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #5a6fd8, #6a4190);
}

/* Loading Animation */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar Toggle Button -->
        <button id="sidebarToggle" class="sidebar-toggle">☰</button>
        
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <h2>SplitEase</h2>
            <ul>
                <li><a href="dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="group.php" class="active"><i class="fas fa-users"></i> My Groups</a></li>
                <li><a href="add_expense.php"><i class="fas fa-plus-circle"></i> Add Expense</a></li>
                <li><a href="settlements.php"><i class="fas fa-handshake"></i> Settlements</a></li>
                <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li> 
                <li> <a href="#" id="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.getElementById('logout-link').addEventListener('click', function(e) {
    e.preventDefault(); // Stay on the same page

    Swal.fire({
        title: "Are you sure?",
        text: "You will be logged out from your account!",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "Yes, Logout",
        cancelButtonText: "Cancel"
    }).then((result) => {
        if (result.isConfirmed) {
            // Show green tick
            Swal.fire({
                title: "Logged Out!",
                icon: "success",
                timer: 1500,
                showConfirmButton: false
            });

            // Redirect to logout.php after 1.5 sec
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 1500);
        }
    });
});
</script>

        <!-- Main Content -->
        <div class="main-content" id="mainContent">
          <div class="container mt-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="mt-3">
            <?php echo $_SESSION['message']; ?>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header -->
    <div class="header">
        <h1><i class="fas fa-users"></i> My Groups</h1>
        <p>Manage your group expenses, members, and settlements with ease.</p>
    </div>


                <!-- Success Message for New Group -->
                <?php if ($created_group_id): ?>
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle me-2"></i>Group Created Successfully!</h5>
                        <p><strong>Group ID: <?php echo $created_group_id; ?></strong></p>
                        <p>Share this ID with your friends so they can join the group.</p>
                        <a href="add_member.php?group_id=<?php echo $created_group_id; ?>" class="btn btn-sm btn-light">
                            <i class="fas fa-user-plus me-2"></i>Add Members by Email
                        </a>
                    </div>
                    <?php unset($_SESSION['new_group_id']); ?>
                <?php endif; ?>

                <!-- Display All Groups -->
                <?php if (count($groups) > 0): ?>
                    <div class="row">
                        <?php foreach ($groups as $index => $group): ?>
                            <div class="col-lg-6 col-md-12 mb-4" style="animation-delay: <?php echo $index * 0.1; ?>s;">
                                <div class="group-box">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h5><i class="fas fa-users me-2"></i><?php echo htmlspecialchars($group['name']); ?></h5>
                                            <p><i class="fas fa-id-card me-2"></i><strong>Group ID:</strong> <?php echo $group['id']; ?></p>
                                        </div>
                                        <div class="badge bg-primary">
                                            <?php echo ($group['created_by'] == $_SESSION['user_id']) ? 'Admin' : 'Member'; ?>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="view_group.php?group_id=<?php echo $group['id']; ?>" class="btn btn-outline-primary">
                                            <i class="fas fa-eye me-2"></i>View Group
                                        </a>

                                        <?php if ($group['created_by'] == $_SESSION['user_id']): ?>
                                            <a href="emailforjoin.php?group_id=<?php echo $group['id']; ?>" class="btn btn-success">
                                                <i class="fas fa-user-plus me-2"></i>Add Members
                                            </a>

                                          


<!-- Delete Group Form -->
<form method="POST" action="delete_group.php" class="d-inline delete-form">
    <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
    <button type="submit" class="btn btn-danger">
        <i class="fas fa-trash me-2"></i>Delete Group
    </button>
</form>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.delete-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        Swal.fire({
            title: "Are you sure?",
            text: "This will permanently delete the group!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "Yes, Delete",
            cancelButtonText: "Cancel"
        }).then((result) => {
            if (result.isConfirmed) {

                // 🔥 AJAX request bhejna
                fetch(form.action, {
                    method: "POST",
                    body: new FormData(form)
                })
                .then(res => res.text())
                .then(() => {
                    // ✅ Success alert
                    Swal.fire({
                        icon: "success",
                        title: "Deleted!",
                        text: "Group has been removed successfully.",
                        showConfirmButton: false,
                        timer: 2000
                    }).then(() => {
                        window.location.href = "group.php"; // redirect after delete
                    });
                })
                .catch(() => {
                    Swal.fire("Error!", "Something went wrong.", "error");
                });

            }
        });
    });
});
</script>


                                        <?php else: ?>
                                           <!-- Exit Group Form -->
<form method="POST" action="exit_group.php" class="d-inline exit-form">
    <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
    <button type="submit" class="btn btn-warning">
        <i class="fas fa-sign-out-alt me-2"></i>Exit Group
    </button>
</form>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.exit-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        Swal.fire({
            title: "Are you sure?",
            text: "You will exit from this group!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#f0ad4e",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "Yes, Exit",
            cancelButtonText: "Cancel"
        }).then((result) => {
            if (result.isConfirmed) {

                // 🔥 AJAX request bhejna
                fetch(form.action, {
                    method: "POST",
                    body: new FormData(form)
                })
                .then(res => res.text())
                .then(() => {
                    // ✅ Success alert
                    Swal.fire({
                        icon: "success",
                        title: "Exited!",
                        text: "You have successfully exited from the group.",
                        showConfirmButton: false,
                        timer: 2000
                    }).then(() => {
                        window.location.href = "group.php"; // redirect after exit
                    });
                })
                .catch(() => {
                    Swal.fire("Error!", "Something went wrong.", "error");
                });

            }
        });
    });
});
</script>

                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-groups">
                        <i class="fas fa-users-slash fa-3x mb-3 opacity-50"></i>
                        <p>You are not in any groups yet.</p>
                        <p>Create or join a group to get started!</p>
                    </div>
                <?php endif; ?>

                <hr class="my-5" style="border-color: rgba(255,255,255,0.3); border-width: 2px;">

                <!-- Action Forms Row -->
                <div class="row">
                    <!-- Create New Group Form -->
                    <div class="col-lg-6 col-md-12 mb-4">
                        <div class="form-section">
                            <h4><i class="fas fa-plus-circle me-2"></i>Create a New Group</h4>
                            <form method="post" action="create_group.php">
                                <div class="mb-4">
                                    <!-- <input type="text" 
                                           name="group_name" 
                                           class="form-control" 
                                           placeholder="Enter your awesome group name" 
                                           required> -->
                                </div>
                                <button type="button" class="btn btn-success w-100" onclick="window.location.href='create_grp.php'">
                                    <i class="fas fa-plus-circle me-2"></i>Create Group
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Join Group Form -->
                    <div class="col-lg-6 col-md-12 mb-4">
                        <div class="form-section">
                            <h4><i class="fas fa-sign-in-alt me-2"></i>Join an Existing Group</h4>
                            <form method="post" action="join_group.php">
                                <div class="mb-4">
                                    <input type="number" 
                                           name="group_id" 
                                           class="form-control" 
                                           placeholder="Enter the group ID shared with you" 
                                           required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-sign-in-alt me-2"></i>Request Join Group
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        // Sidebar toggle functionality
        document.getElementById("sidebarToggle").addEventListener("click", function() {
            const sidebar = document.getElementById("sidebar");
            const mainContent = document.querySelector(".main-content");
            
            sidebar.classList.toggle("hidden");
            mainContent.classList.toggle("full");
        });

        // Add smooth scrolling to page
        document.documentElement.style.scrollBehavior = 'smooth';

        // Add loading state to buttons on form submit
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="loading me-2"></span>Processing...';
                    submitBtn.disabled = true;
                    
                    // Re-enable button after 3 seconds (in case of error)
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 3000);
                }
            });
        });

        // Add hover effect to group boxes
        document.querySelectorAll('.group-box').forEach((box, index) => {
            box.style.animationDelay = (index * 0.1) + 's';
            
            box.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-10px) scale(1.02)';
            });
            
            box.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Add particle effect on button hover
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>
