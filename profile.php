<?php

include 'db.php';
require_once 'functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// FETCH USER DETAILS with better error handling
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// If user not found, redirect to login
if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// Check if we're in edit mode
$edit_mode = isset($_GET['edit']) && $_GET['edit'] == '1';

// Initialize variables with default values and better error handling
$total_expense = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) as total 
                        FROM expense_payers 
                        WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_expense = $row['total'] ?? 0;
    $stmt->close();
}

// Total Groups Joined
$total_groups = 0;
$stmt = $conn->prepare("SELECT COUNT(DISTINCT group_id) as count FROM group_members WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_groups = $row['count'] ?? 0;
    $stmt->close();
}

// Total Settlements - Enhanced query with status filtering
// Total Settlements - only approved & paid
// Total Settlements - Only amounts settled to the user
// Total Settlements - Amount paid by the user
$total_settlements = 0;

$stmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(
            CASE 
                WHEN status = 'paid' THEN amount
                WHEN status = 'partial' THEN partial_paid_amount
                ELSE 0
            END
        ), 0) AS total
    FROM settlements
    WHERE paid_by = ?
      AND status IN ('paid', 'partial')
");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $total_settlements = $row['total'];
    }
    $stmt->close();
}



// Member Since with fallback
$member_since = $user['created_at'] ?? date('Y-m-d');
$joinDate = date('M Y', strtotime($member_since));

// Handle form submission with enhanced validation
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['fullName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // Enhanced validation
    if (empty($name) || empty($email)) {
        $error_message = "Name and email are required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } elseif (strlen($name) < 2) {
        $error_message = "Name must be at least 2 characters long.";
    } elseif (strlen($name) > 100) {
        $error_message = "Name must be less than 100 characters.";
    } else {
        // Check if email already exists for another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        if ($stmt) {
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error_message = "Email address is already in use by another account.";
            }
            $stmt->close();
        }

        if (empty($error_message)) {
            // Keep existing profile pic unless new uploaded or removed
            $profile_pic = $user['profile_pic'] ?? 'default.jpg';

            // Handle profile picture removal
            if (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
                // Delete old profile picture if it exists and is not default
                if ($user['profile_pic'] && $user['profile_pic'] !== 'uploads/default.jpg' && file_exists('uploads/' . $user['profile_pic'])) {
                    unlink('uploads/' . $user['profile_pic']);
                }
                $profile_pic = 'uploads/default.jpg';
            }
            // Handle cropped image data
            elseif (isset($_POST['cropped_image']) && !empty($_POST['cropped_image'])) {
                $croppedImageData = $_POST['cropped_image'];
                
                // Validate base64 image data
                if (preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $croppedImageData)) {
                    // Remove data:image/jpeg;base64, part
                    $imageData = explode(',', $croppedImageData)[1];
                    $imageData = base64_decode($imageData);
                    
                    if ($imageData !== false) {
                        // Create uploads directory if it doesn't exist
                        if (!is_dir('uploads')) {
                            mkdir('uploads', 0755, true);
                        }
                        
                        // Generate unique filename with user ID and timestamp
                        $newFileName = 'profile_' . $user_id . '_' . time() . '.jpg';
                        $fullPath = 'uploads/' . $newFileName;
                        
                        if (file_put_contents($fullPath, $imageData)) {
                            // Delete old profile picture if it exists and is not default
                            if ($user['profile_pic'] && $user['profile_pic'] !== 'uploads/default.jpg' && file_exists('uploads/' . $user['profile_pic'])) {
                                unlink('uploads/' . $user['profile_pic']);
                            }
                            $profile_pic = $newFileName;
                        } else {
                            $error_message = "Failed to upload profile picture. Please try again.";
                        }
                    } else {
                        $error_message = "Invalid image data. Please try again.";
                    }
                } else {
                    $error_message = "Invalid image format. Please upload a JPEG, PNG, or WebP image.";
                }
            }

            // UPDATE IN DATABASE - Only if no errors occurred
            if (empty($error_message)) {
                $sqlUpdate = "UPDATE users SET username = ?, email = ?, phone = ?, profile_pic = ?, updated_at = NOW() WHERE id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                
                if ($stmtUpdate) {
                    $stmtUpdate->bind_param("ssssi", $name, $email, $phone, $profile_pic, $user_id);
                    
                    if ($stmtUpdate->execute()) {
                        $_SESSION['username'] = $name;
                        header("Location: profile.php?updated=1");
                        exit();
                    } else {
                        $error_message = "Failed to update profile. Please try again.";
                    }
                    $stmtUpdate->close();
                } else {
                    $error_message = "Database error. Please try again.";
                }
            }
        }
    }
}

// Show success message if redirected with updated=1
$showSuccess = isset($_GET['updated']) && $_GET['updated'] == '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SplitEase - My Profile</title>
    
    <!-- External CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="morden_style.css">

    <style>
        /* Enhanced Profile Page Styling - File 1 Design with File 2 Functionality */
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

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(30px);
            border-radius: 25px;
            padding: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            color: white;
            animation: fadeInUp 0.6s ease-out;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card::before {
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

        @keyframes fadeInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .stat-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 25px 70px rgba(102, 126, 234, 0.3);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            flex-shrink: 0;
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: white;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
        }

        /* Success/Error Messages */
        .success-message, .error-message {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.9), rgba(46, 204, 113, 0.9));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            color: white;
            box-shadow: 0 10px 40px rgba(39, 174, 96, 0.3);
            animation: slideInDown 0.5s ease-out;
            display: flex;
            align-items: center;
            gap: 15px;
            font-weight: 500;
        }

        .error-message {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.9), rgba(192, 57, 43, 0.9));
            box-shadow: 0 10px 40px rgba(231, 76, 60, 0.3);
        }

        @keyframes slideInDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Profile Card */
        .profile-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.05));
            backdrop-filter: blur(30px);
            border-radius: 25px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            color: white;
            animation: fadeInUp 0.6s ease-out;
            position: relative;
        }

        .profile-card::before {
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

        .profile-header {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.3), rgba(118, 75, 162, 0.3));
            backdrop-filter: blur(20px);
            padding: 40px;
            display: flex;
            align-items: center;
            gap: 30px;
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .profile-avatar {
            position: relative;
            flex-shrink: 0;
        }

        .avatar-img {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255, 255, 255, 0.3);
            transition: transform 0.3s ease;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            background: rgba(255, 255, 255, 0.1);
            display: block;
        }

        /* Enhanced Avatar Actions */
        .avatar-actions {
            position: absolute;
            bottom: -5px;
            right: -5px;
            display: flex;
            gap: 8px;
        }

        .avatar-action {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 16px;
            color: #333;
            border: 2px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .avatar-action:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
        }

        .avatar-action.upload {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
        }

        .avatar-action.upload:hover {
            background: linear-gradient(135deg, #3d8bfe, #00d4fe);
        }

        .avatar-action.remove {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
        }

        .avatar-action.remove:hover {
            background: linear-gradient(135deg, #ff5252, #e53935);
        }

        .avatar-action input {
            display: none;
        }

        .profile-info h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #fff, #f093fb);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            word-break: break-word;
        }

        .email {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 15px;
            word-break: break-word;
        }

        .member-since {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            opacity: 0.8;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
        }

        /* Form Section */
        .form-section {
            padding: 40px;
        }

        .form-section h3 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 30px;
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-info-display {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: white;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-value {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
            word-break: break-word;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            color: white;
            margin-bottom: 10px;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-input {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 15px 20px;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-input:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(102, 126, 234, 0.8);
            box-shadow: 0 0 25px rgba(102, 126, 234, 0.3);
            color: white;
            outline: none;
        }

        .form-input:disabled {
            background: rgba(255, 255, 255, 0.05);
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* Enhanced validation styles */
        .form-input.is-invalid {
            border-color: rgba(231, 76, 60, 0.8);
            box-shadow: 0 0 25px rgba(231, 76, 60, 0.3);
        }

        .invalid-feedback {
            color: #ff6b6b;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            font-weight: 500;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Enhanced Buttons */
        .btn {
            border-radius: 15px;
            padding: 15px 30px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
            cursor: pointer;
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

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.2));
            transform: translateY(-2px);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
            color: white;
        }

        /* Crop Modal Styles */
        .modal-content {
            background: linear-gradient(135deg, rgba(44, 62, 80, 0.95), rgba(52, 73, 94, 0.95));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            color: white;
        }

        .modal-header {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .modal-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .crop-container {
            width: 100%;
            height: 400px;
            margin: 1rem 0;
        }

        .crop-container img {
            max-width: 100%;
            height: auto;
        }

        /* Loading Animation */
        .loading-spinner {
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
                flex-direction: column;
                gap: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
                padding: 30px 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .profile-header {
                padding: 20px 15px;
            }
            
            .avatar-img {
                width: 120px;
                height: 120px;
            }
            
            .profile-info h2 {
                font-size: 2rem;
            }
            
            .form-section {
                padding: 25px;
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
                <li><a href="group.php"><i class="fas fa-users"></i> My Groups</a></li>
                <li><a href="add_expense.php"><i class="fas fa-plus-circle"></i> Add Expense</a></li>
                <li><a href="settlements.php"><i class="fas fa-handshake"></i> Settlements</a></li>
                <li><a href="profile.php" class="active"><i class="fas fa-user"></i> My Profile</a></li> 
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
            <!-- Crop Modal -->
            <div class="modal fade" id="cropModal" tabindex="-1" aria-labelledby="cropModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="cropModalLabel">
                                <i class="fas fa-crop"></i> Crop Your Profile Picture
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="crop-container">
                                <img id="cropImagePreview" style="max-width: 100%; display: block;" />
                            </div>
                            <div class="mt-3">
                                <small class="text-light">
                                    <i class="fas fa-info-circle"></i> 
                                    Use mouse wheel to zoom, drag to move the image. Select the area you want to crop.
                                </small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="button" id="cropAndUpload" class="btn btn-primary">
                                <i class="fas fa-check"></i> Crop & Upload
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Remove Photo Confirmation Modal -->
            <div class="modal fade" id="removePhotoModal" tabindex="-1" aria-labelledby="removePhotoModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="removePhotoModalLabel">
                                <i class="fas fa-user-times"></i> Remove Profile Photo
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="text-center mb-3">
                                <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                            </div>
                            <p class="text-center">Are you sure you want to remove your profile photo? This will set it back to the default avatar.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="button" id="confirmRemovePhoto" class="btn btn-danger">
                                <i class="fas fa-trash-alt"></i> Remove Photo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-user-circle"></i> My Profile</h1>
                <p>Manage your account settings and preferences with style</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card" style="animation-delay: 0.1s;">
                    <div class="stat-icon purple">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div>
                        <div class="stat-value">₹<?php echo number_format($total_expense, 2); ?></div>
                        <div class="stat-label">Total Expense</div>
                    </div>
                </div>
                <div class="stat-card" style="animation-delay: 0.2s;">
                    <div class="stat-icon blue">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo $total_groups; ?></div>
                        <div class="stat-label">Groups Joined</div>
                    </div>
                </div>
                <div class="stat-card" style="animation-delay: 0.3s;">
                    <div class="stat-icon green">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <div>
                        <div class="stat-value">₹<?php echo number_format($total_settlements, 2); ?></div>
                        <div class="stat-label">Total Settled</div>
                    </div>
                </div>
                <div class="stat-card" style="animation-delay: 0.4s;">
                    <div class="stat-icon orange">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?php echo date("d M Y", strtotime($member_since)); ?></div>
                        <div class="stat-label">Member Since</div>
                    </div>
                </div>
            </div>

            <!-- Profile Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php 
                        $profilePicPath = (!empty($user['profile_pic']) && $user['profile_pic'] !== 'default.jpg') 
                            ? 'uploads/' . htmlspecialchars($user['profile_pic'])
                            : 'uploads/default.jpg';
                        ?>
                        <img id="avatarImg" src="<?php echo $profilePicPath; ?>" alt="Profile" class="avatar-img" 
                             onerror="this.src='uploads/default.jpg'" loading="eager">
                        <?php if ($edit_mode): ?>
                        <div class="avatar-actions">
                            <div class="avatar-action upload" onclick="document.getElementById('avatarInput').click()" title="Change Photo">
                                <i class="fas fa-camera"></i>
                                <input type="file" id="avatarInput" accept="image/*">
                            </div>
                            <?php if (!empty($user['profile_pic']) && $user['profile_pic'] !== 'default.jpg'): ?>
                            <div class="avatar-action remove" onclick="showRemovePhotoModal()" title="Remove Photo">
                                <i class="fas fa-trash-alt"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user['username'] ?? 'New User'); ?></h2>
                        <div class="email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                        <div class="member-since">
                            <i class="fas fa-calendar"></i>
                            Member since <?php echo $joinDate; ?>
                        </div>
                    </div>
                </div>

                <?php if ($showSuccess): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    Profile updated successfully!
                </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <div class="form-section">
                    <?php if ($edit_mode): ?>
                    <!-- Edit Mode -->
                    <h3><i class="fas fa-edit"></i> Edit Profile</h3>
                    <form id="profileForm" method="POST" action="profile.php" enctype="multipart/form-data">
                        <input type="hidden" id="croppedImageData" name="cropped_image" value="">
                        <input type="hidden" id="removePhotoInput" name="remove_photo" value="">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="fullName" class="form-label">
                                    <i class="fas fa-user"></i> Full Name
                                </label>
                                <input type="text" id="fullName" name="fullName" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" 
                                       required maxlength="100" minlength="2">
                            </div>

                            <div class="form-group">
                                <label for="email" class="form-label">
                                    <i class="fas fa-envelope"></i> Email Address
                                </label>
                                <input type="email" id="email" name="email" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                       required maxlength="255">
                            </div>

                            <div class="form-group">
                                <label for="phone" class="form-label">
                                    <i class="fas fa-phone"></i> Phone Number
                                </label>
                                <input type="tel" id="phone" name="phone" class="form-input" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                       placeholder="Enter your phone number" maxlength="20">
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="profile.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <!-- View Mode -->
                    <h3><i class="fas fa-info-circle"></i> Personal Information</h3>
                    <div class="profile-info-display">
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-user"></i> Full Name
                            </span>
                            <span class="info-value"><?php echo htmlspecialchars($user['username'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </span>
                            <span class="info-value"><?php echo htmlspecialchars($user['email'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-phone"></i> Phone Number
                            </span>
                            <span class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-calendar-check"></i> Member Since
                            </span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($member_since)); ?></span>
                        </div>
                        <?php if (!empty($user['updated_at'])): ?>
                        <div class="info-item">
                            <span class="info-label">
                                <i class="fas fa-clock"></i> Last Updated
                            </span>
                            <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($user['updated_at'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-actions">
                        <a href="profile.php?edit=1" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

    <script>
        // Sidebar toggle functionality
        document.getElementById("sidebarToggle").addEventListener("click", function() {
            const sidebar = document.getElementById("sidebar");
            const mainContent = document.querySelector(".main-content");
            
            sidebar.classList.toggle("hidden");
            mainContent.classList.toggle("full");
        });

        // Image cropping functionality
        let cropper;
        const avatarInput = document.getElementById('avatarInput');
        const cropModal = new bootstrap.Modal(document.getElementById('cropModal'));
        const cropImagePreview = document.getElementById('cropImagePreview');
        const croppedImageData = document.getElementById('croppedImageData');

        // Handle file selection
        if (avatarInput) {
            avatarInput.addEventListener('change', function(e) {
                const files = e.target.files;
                if (files && files.length > 0) {
                    const file = files[0];
                    
                    // Validate file type
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(file.type)) {
                        showAlert('Please select a valid image file (JPG, JPEG, PNG, GIF, or WebP)', 'error');
                        avatarInput.value = '';
                        return;
                    }

                    // Validate file size (5MB max)
                    if (file.size > 5 * 1024 * 1024) {
                        showAlert('File size must be less than 5MB', 'error');
                        avatarInput.value = '';
                        return;
                    }

                    // Read file and show crop modal
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        cropImagePreview.src = event.target.result;
                        cropModal.show();
                        
                        // Initialize cropper when modal is shown
                        document.getElementById('cropModal').addEventListener('shown.bs.modal', function () {
                            if (cropper) {
                                cropper.destroy();
                            }
                            
                            cropper = new Cropper(cropImagePreview, {
                                aspectRatio: 1, // Square crop
                                viewMode: 2,
                                dragMode: 'move',
                                autoCropArea: 0.8,
                                restore: false,
                                guides: true,
                                center: true,
                                highlight: false,
                                cropBoxMovable: true,
                                cropBoxResizable: true,
                                toggleDragModeOnDblclick: false,
                                minCropBoxWidth: 100,
                                minCropBoxHeight: 100,
                                scalable: true,
                                zoomable: true,
                                wheelZoomRatio: 0.1,
                                ready: function () {
                                    console.log('Cropper is ready');
                                }
                            });
                        }, { once: true });
                    };
                    
                    reader.onerror = function() {
                        showAlert('Error reading file. Please try again.', 'error');
                        avatarInput.value = '';
                    };
                    
                    reader.readAsDataURL(file);
                }
            });
        }

        // Handle crop and upload
        document.getElementById('cropAndUpload').addEventListener('click', function() {
            if (cropper) {
                try {
                    // Get cropped canvas
                    const canvas = cropper.getCroppedCanvas({
                        width: 400,
                        height: 400,
                        imageSmoothingEnabled: true,
                        imageSmoothingQuality: 'high'
                    });

                    // Convert to base64 and store in hidden input
                    const croppedImageDataURL = canvas.toDataURL('image/jpeg', 0.9);
                    croppedImageData.value = croppedImageDataURL;

                    // Update avatar preview
                    document.getElementById('avatarImg').src = croppedImageDataURL;

                    // Close modal
                    cropModal.hide();
                    
                    // Show success message
                    showAlert('Image cropped successfully! Click "Save Changes" to update your profile.', 'success');
                    
                    // Show remove button if it's hidden
                    const removeBtn = document.querySelector('.avatar-action.remove');
                    if (removeBtn) {
                        removeBtn.style.display = 'flex';
                    }
                } catch (error) {
                    console.error('Error cropping image:', error);
                    showAlert('Error processing image. Please try again.', 'error');
                }
            }
        });

        // Clean up cropper when modal is hidden
        document.getElementById('cropModal').addEventListener('hidden.bs.modal', function () {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            // Reset file input
            if (avatarInput) {
                avatarInput.value = '';
            }
        });

        // Remove photo functionality
        const removePhotoModal = new bootstrap.Modal(document.getElementById('removePhotoModal'));
        
        function showRemovePhotoModal() {
            removePhotoModal.show();
        }

        document.getElementById('confirmRemovePhoto').addEventListener('click', function() {
            // Set hidden input to indicate photo removal
            document.getElementById('removePhotoInput').value = '1';
            
            // Clear cropped image data
            document.getElementById('croppedImageData').value = '';
            
            // Update avatar preview to default
            document.getElementById('avatarImg').src = 'uploads/default.jpg';
            
            // Hide the remove button
            const removeBtn = document.querySelector('.avatar-action.remove');
            if (removeBtn) {
                removeBtn.style.display = 'none';
            }
            
            // Close modal
            removePhotoModal.hide();
            
            // Show success message
            showAlert('Profile photo will be removed when you save changes.', 'success');
        });

        // Show alert function
        function showAlert(message, type = 'success') {
            // Remove existing temporary messages
            const existingMessages = document.querySelectorAll('.temp-message');
            existingMessages.forEach(msg => msg.remove());

            // Create new message
            const messageDiv = document.createElement('div');
            messageDiv.className = `temp-message ${type === 'success' ? 'success-message' : 'error-message'}`;
            messageDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                ${message}
            `;

            // Insert after profile header
            const profileHeader = document.querySelector('.profile-header');
            profileHeader.insertAdjacentElement('afterend', messageDiv);

            // Remove after 5 seconds
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.style.opacity = '0';
                    setTimeout(() => {
                        if (messageDiv.parentNode) {
                            messageDiv.remove();
                        }
                    }, 300);
                }
            }, 5000);
        }

        // Auto-hide success message after 5 seconds
        const successMessage = document.querySelector('.success-message:not(.temp-message)');
        if (successMessage) {
            setTimeout(() => {
                successMessage.style.opacity = '0';
                setTimeout(() => {
                    if (successMessage.parentNode) {
                        successMessage.remove();
                    }
                }, 300);
            }, 5000);
        }

        // Form validation and submission
        const profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                const submitBtn = document.getElementById('submitBtn');
                const fullName = document.getElementById('fullName').value.trim();
                const email = document.getElementById('email').value.trim();
                const phone = document.getElementById('phone').value.trim();

                // Reset any previous validation states
                document.querySelectorAll('.form-input').forEach(input => {
                    input.classList.remove('is-invalid');
                });

                let hasError = false;

                // Validate full name
                if (!fullName) {
                    showFieldError('fullName', 'Please enter your full name');
                    hasError = true;
                } else if (fullName.length < 2) {
                    showFieldError('fullName', 'Name must be at least 2 characters long');
                    hasError = true;
                } else if (fullName.length > 100) {
                    showFieldError('fullName', 'Name must be less than 100 characters');
                    hasError = true;
                }

                // Validate email
                if (!email) {
                    showFieldError('email', 'Please enter your email address');
                    hasError = true;
                } else if (!isValidEmail(email)) {
                    showFieldError('email', 'Please enter a valid email address');
                    hasError = true;
                }

                // Validate phone (optional but if provided, should be valid)
                if (phone && phone.length > 0) {
                    if (phone.length < 10 || phone.length > 20) {
                        showFieldError('phone', 'Phone number should be between 10-20 characters');
                        hasError = true;
                    }
                }

                if (hasError) {
                    e.preventDefault();
                    return;
                }

                // Show loading state
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';
                submitBtn.disabled = true;

                // Re-enable button after a delay (in case of errors)
                setTimeout(() => {
                    if (submitBtn.disabled) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                }, 10000);
            });
        }

        // Helper function to show field errors
        function showFieldError(fieldId, message) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.classList.add('is-invalid');
                field.focus();
                
                // Remove existing error message
                const existingError = field.parentNode.querySelector('.invalid-feedback');
                if (existingError) {
                    existingError.remove();
                }
                
                // Add new error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'invalid-feedback';
                errorDiv.style.display = 'block';
                errorDiv.textContent = message;
                field.parentNode.appendChild(errorDiv);
                
                // Remove error on input
                field.addEventListener('input', function() {
                    field.classList.remove('is-invalid');
                    const errorMsg = field.parentNode.querySelector('.invalid-feedback');
                    if (errorMsg) {
                        errorMsg.remove();
                    }
                }, { once: true });
            }
        }

        // Email validation helper
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        // Handle responsive sidebar
        function handleResize() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            
            if (window.innerWidth <= 768) {
                sidebar.classList.add('hidden');
                mainContent.classList.add('full');
            } else {
                sidebar.classList.remove('hidden');
                mainContent.classList.remove('full');
            }
        }

        // Initial check and event listener for window resize
        handleResize();
        window.addEventListener('resize', handleResize);

        // Keyboard navigation for accessibility
        document.addEventListener('keydown', function(e) {
            // ESC key to close modals
            if (e.key === 'Escape') {
                if (cropModal._isShown) {
                    cropModal.hide();
                }
                if (removePhotoModal._isShown) {
                    removePhotoModal.hide();
                }
            }
        });

        // Add entrance animations for stats cards
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stats cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 200 + (index * 100));
            });

            // Add fade in animation to profile card
            const profileCard = document.querySelector('.profile-card');
            if (profileCard) {
                profileCard.style.opacity = '0';
                profileCard.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    profileCard.style.transition = 'all 0.6s ease';
                    profileCard.style.opacity = '1';
                    profileCard.style.transform = 'translateY(0)';
                }, 600);
            }
        });

        // Add loading animation for buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Add smooth scrolling to page
        document.documentElement.style.scrollBehavior = 'smooth';

        // Smooth scroll to top when success message appears
        if (document.querySelector('.success-message')) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Prevent form submission on Enter key in input fields (except textarea)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') {
                e.preventDefault();
            }
        });

        // Image loading error handling
        document.getElementById('avatarImg').addEventListener('error', function() {
            this.src = 'uploads/default.jpg';
        });

        // Enhanced button hover effects
        document.querySelectorAll('.avatar-action').forEach(action => {
            action.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.1)';
            });
            
            action.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });

        // Add floating animation to background
        document.body.addEventListener('mousemove', function(e) {
            const mouseX = e.clientX / window.innerWidth;
            const mouseY = e.clientY / window.innerHeight;
            
            const bgElement = document.body;
            bgElement.style.backgroundPosition = `${mouseX * 50}px ${mouseY * 50}px`;
        });

        // Add particle effect (optional enhancement)
        function createParticle() {
            const particle = document.createElement('div');
            particle.style.position = 'fixed';
            particle.style.width = '4px';
            particle.style.height = '4px';
            particle.style.background = 'rgba(255, 255, 255, 0.3)';
            particle.style.borderRadius = '50%';
            particle.style.pointerEvents = 'none';
            particle.style.zIndex = '-1';
            particle.style.left = Math.random() * window.innerWidth + 'px';
            particle.style.top = '-10px';
            particle.style.animation = 'particleFall 10s linear infinite';
            
            document.body.appendChild(particle);
            
            setTimeout(() => {
                if (particle.parentNode) {
                    particle.remove();
                }
            }, 10000);
        }

        // Add particle animation CSS
        const particleStyle = document.createElement('style');
        particleStyle.textContent = `
            @keyframes particleFall {
                to {
                    transform: translateY(calc(100vh + 20px));
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(particleStyle);

        // Create particles periodically
        setInterval(createParticle, 3000);

        // Add ripple effect to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                const ripple = document.createElement('span');
                const rect = this.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;
                
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = x + 'px';
                ripple.style.top = y + 'px';
                ripple.classList.add('ripple');
                
                this.appendChild(ripple);
                
                setTimeout(() => {
                    ripple.remove();
                }, 600);
            });
        });

        // Add ripple effect CSS
        const rippleStyle = document.createElement('style');
        rippleStyle.textContent = `
            .btn {
                position: relative;
                overflow: hidden;
            }
            .ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.3);
                animation: rippleEffect 0.6s ease-out;
                pointer-events: none;
            }
            @keyframes rippleEffect {
                to {
                    transform: scale(2);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(rippleStyle);

        // Enhanced loading state with progress indication
        function showLoadingState(element, text = 'Loading...') {
            const originalText = element.innerHTML;
            element.innerHTML = `<span class="loading-spinner"></span> ${text}`;
            element.disabled = true;
            
            return function restoreState() {
                element.innerHTML = originalText;
                element.disabled = false;
            };
        }

        // Add success animation to stat cards
        function animateStatCards() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.transform = 'scale(1.05)';
                    setTimeout(() => {
                        card.style.transform = 'scale(1)';
                    }, 200);
                }, index * 100);
            });
        }

        // Trigger stat card animation on successful update
        if (document.querySelector('.success-message')) {
            setTimeout(animateStatCards, 1000);
        }

        // Add progressive image loading
        function loadImageProgressive(img) {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const tempImg = new Image();
            
            tempImg.onload = function() {
                canvas.width = this.width;
                canvas.height = this.height;
                ctx.filter = 'blur(5px)';
                ctx.drawImage(this, 0, 0);
                
                img.src = canvas.toDataURL();
                
                // Load high quality version
                setTimeout(() => {
                    img.src = tempImg.src;
                }, 100);
            };
            
            tempImg.src = img.dataset.src || img.src;
        }

        // Apply progressive loading to avatar
        const avatarImg = document.getElementById('avatarImg');
        if (avatarImg) {
            loadImageProgressive(avatarImg);
        }

        // Add form field focus animations
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentNode.style.transform = 'translateY(-2px)';
                this.parentNode.style.transition = 'transform 0.3s ease';
            });
            
            input.addEventListener('blur', function() {
                this.parentNode.style.transform = 'translateY(0)';
            });
        });

        // Add typing effect for success messages
        function typeWriter(element, text, speed = 50) {
            element.innerHTML = '';
            let i = 0;
            
            function type() {
                if (i < text.length) {
                    element.innerHTML += text.charAt(i);
                    i++;
                    setTimeout(type, speed);
                }
            }
            
            type();
        }

        // Apply typing effect to success message
        const successMsg = document.querySelector('.success-message:not(.temp-message)');
        if (successMsg) {
            const text = successMsg.textContent;
            typeWriter(successMsg, text, 30);
        }
    </script>
</body>
</html>