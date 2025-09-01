<?php
session_start();
require_once 'db.php';

// Include PHPMailer
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
  This file:
  - checks email
  - generates token + expiry
  - detects whether your users table has "reset_token_expiry" or "reset_expires"
    and updates the correct column (so you don't need to change your DB)
  - sends reset email via PHPMailer
  - sets a $alertScript variable (SweetAlert) which is printed at the bottom of the page
*/

// Will contain JS to run SweetAlert on page load (set in PHP logic below)
$alertScript = '';
$isProcessing = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $isProcessing = true;
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $alertScript = "Swal.fire({
            icon:'error',
            title:'Email Required',
            text:'Please enter your email address.',
            confirmButtonColor: '#d33'
        });";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alertScript = "Swal.fire({
            icon:'error',
            title:'Invalid Email',
            text:'Please enter a valid email address.',
            confirmButtonColor: '#d33'
        });";
    } else {
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $userId = $user['id'];
            $token = bin2hex(random_bytes(32));
            $expiry = date("Y-m-d H:i:s", strtotime("+1 hour")); // 1 hour expiry

            // Detect which reset expiry column exists in the DB
            $cols = [];
            $colCheck = $conn->query("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'users' 
                  AND COLUMN_NAME IN ('reset_token_expiry','reset_expires')
            ");
            if ($colCheck) {
                while ($r = $colCheck->fetch_assoc()) $cols[] = $r['COLUMN_NAME'];
            }

            if (in_array('reset_token_expiry', $cols)) {
                $updateSql = "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?";
            } elseif (in_array('reset_expires', $cols)) {
                $updateSql = "UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?";
            } else {
                // no matching column found — can't save token
                $alertScript = "Swal.fire({
                    icon:'error',
                    title:'Server Error',
                    text:'Password reset configuration error. Please contact support.',
                    confirmButtonColor: '#d33'
                });";
                // free resources
                if ($stmt) $stmt->close();
                if ($colCheck) $colCheck->close();
                goto render_page;
            }

            // Prepare and execute update
            $updStmt = $conn->prepare($updateSql);
            if ($updStmt === false) {
                $alertScript = "Swal.fire({
                    icon:'error',
                    title:'Database Error',
                    text:'Unable to process your request. Please try again later.',
                    confirmButtonColor: '#d33'
                });";
                if ($stmt) $stmt->close();
                if ($colCheck) $colCheck->close();
                goto render_page;
            }
            $updStmt->bind_param("ssi", $token, $expiry, $userId);
            $updStmt->execute();

            // free resources
            $updStmt->close();
            $stmt->close();
            if ($colCheck) $colCheck->close();

            // Build reset link — change domain/path to match your project
            $resetLink = "http://localhost/MY_WEB/reset_password.php?token=" . $token;

            // Send email
            try {
                $mail = new PHPMailer(true);

                // SMTP config
                $mail->SMTPDebug = 0; // keep at 0 in production
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'spliteasify@gmail.com';     // your Gmail
                $mail->Password   = 'rqpc ksek yqgf zisx';       // app password
                $mail->SMTPSecure = 'tls';
                $mail->Port       = 587;

                // optional debug logging to file
                $mail->Debugoutput = function($str, $level) {
                    file_put_contents(_DIR_ . '/mail_debug.log', date('Y-m-d H:i:s') . " [$level] $str\n", FILE_APPEND);
                };

                // Email content
                $mail->setFrom('spliteasify@gmail.com', 'SplitEase');
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Reset Your SplitEase Password';
                $mail->Body    = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='utf-8'>
                        <style>
                            body { font-family: Arial, sans-serif; background-color: #f8f9fa; padding: 20px; }
                            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                            .header { text-align: center; margin-bottom: 30px; }
                            .logo { font-size: 24px; font-weight: bold; color: #198754; margin-bottom: 10px; }
                            .reset-btn { background: #198754; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold; }
                            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #6c757d; font-size: 14px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <div class='logo'>🔐 SplitEase</div>
                                <h2 style='color: #495057; margin: 0;'>Password Reset Request</h2>
                            </div>
                            
                            <p>Hello,</p>
                            <p>We received a request to reset your SplitEase account password. If you made this request, click the button below to create a new password:</p>
                            
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='$resetLink' class='reset-btn'>Reset Your Password</a>
                            </div>
                            
                            <p><strong>Important:</strong> This reset link will expire in 1 hour for security reasons.</p>
                            
                            <p>If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>
                            
                            <div class='footer'>
                                <p>Best regards,<br>The SplitEase Team</p>
                                <p><em>This is an automated email. Please do not reply to this message.</em></p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";

                // send
                $mail->send();

                // success alert (do not exit; let the page render and print this JS at the bottom)
                $alertScript = "Swal.fire({
                    icon: 'success',
                    title: 'Email Sent Successfully!',
                    html: 'We have sent a password reset link to <strong>$email</strong>.<br><br>Please check your inbox and follow the instructions to reset your password.',
                    confirmButtonText: 'Go to Login',
                    confirmButtonColor: '#198754',
                    allowOutsideClick: false
                }).then(() => { window.location.href = 'login.php'; });";

            } catch (Exception $e) {
                // Use exception message to help debug; escape quotes
                $errMsg = addslashes($e->getMessage());
                $alertScript = "Swal.fire({
                    icon:'error',
                    title:'Email Delivery Failed',
                    text:'Unable to send reset email. Please try again or contact support if the problem persists.',
                    confirmButtonColor: '#d33'
                });";
            }

        } else {
            // email not found - for security, we don't reveal if email exists or not
            if ($result) $result->free();
            if ($stmt) $stmt->close();
            $alertScript = "Swal.fire({
                icon: 'info',
                title: 'Request Processed',
                html: 'If an account with that email exists, you will receive a password reset link shortly.<br><br>Please check your inbox and spam folder.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#0d6efd'
            });";
        }
    }
    $isProcessing = false;
}

render_page: // label used above for early exit to render the page
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - SplitEase</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .card {
            border: none;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        .btn {
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .back-link {
            color: #6c757d;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .back-link:hover {
            color: #198754;
            text-decoration: none;
        }
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
        }
        .email-icon {
            font-size: 3rem;
            color: #198754;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center vh-100">

<div class="card shadow-lg p-4" style="max-width: 450px; width: 100%;">
    <div class="text-center mb-4">
        <i class="bi bi-envelope-paper email-icon"></i>
        <h3 class="mb-2">🔐 Forgot Password?</h3>
        <p class="text-muted">No worries! Enter your email address and we'll send you a link to reset your password.</p>
    </div>
    
    <form method="post" id="forgotForm" novalidate>
        <div class="mb-4">
            <label for="email" class="form-label fw-semibold">Email Address</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0" style="border-radius: 10px 0 0 10px;">
                    <i class="bi bi-envelope text-muted"></i>
                </span>
                <input type="email" 
                       name="email" 
                       id="email" 
                       class="form-control border-start-0" 
                       placeholder="Enter your email address"
                       style="border-radius: 0 10px 10px 0;"
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                       required>
                <div class="invalid-feedback">
                    Please enter a valid email address.
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn btn-success w-100 mb-3" id="submitBtn">
            <span class="spinner-border spinner-border-sm d-none me-2" role="status"></span>
            <i class="bi bi-send me-2"></i>
            Send Reset Link
        </button>
        
        <div class="text-center">
            <a href="login.php" class="back-link">
                <i class="bi bi-arrow-left me-1"></i>
                Back to Login
            </a>
        </div>
    </form>
    
    <div class="mt-4 pt-3 border-top text-center">
        <small class="text-muted">
            Remember your password? 
            <a href="login.php" class="text-success text-decoration-none fw-semibold">Sign In</a>
        </small>
    </div>
</div>

<script>
// Form validation and submission
const form = document.getElementById('forgotForm');
const emailInput = document.getElementById('email');
const submitBtn = document.getElementById('submitBtn');

// Email validation function
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Real-time email validation
emailInput.addEventListener('input', function() {
    const email = this.value.trim();
    
    if (email && !validateEmail(email)) {
        this.classList.add('is-invalid');
    } else {
        this.classList.remove('is-invalid');
    }
});

// Form submission
form.addEventListener('submit', function(e) {
    const email = emailInput.value.trim();
    
    // Validate email
    if (!email) {
        e.preventDefault();
        emailInput.classList.add('is-invalid');
        emailInput.focus();
        return false;
    }
    
    if (!validateEmail(email)) {
        e.preventDefault();
        emailInput.classList.add('is-invalid');
        emailInput.focus();
        return false;
    }
    
    // Show loading state
    const spinner = submitBtn.querySelector('.spinner-border');
    const icon = submitBtn.querySelector('.bi-send');
    
    spinner.classList.remove('d-none');
    icon.classList.add('d-none');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
});

// Auto-focus email input
window.addEventListener('load', function() {
    emailInput.focus();
});

// Print the JS alert if set
<?php if (!empty($alertScript)): ?>
    // Reset button state in case of error
    setTimeout(function() {
        if (submitBtn.disabled) {
            const spinner = submitBtn.querySelector('.spinner-border');
            if (spinner) spinner.classList.add('d-none');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-send me-2"></i>Send Reset Link';
        }
    }, 1000);
    
    <?php echo $alertScript; ?>
<?php endif; ?>
</script>

</body>
</html>
