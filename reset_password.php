<?php
require_once 'db.php';
session_start();

$token = '';
$showForm = false;
$error = '';
$success = false;

// Check if token is provided
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Validate token format (basic check)
    if (empty($token) || strlen($token) < 32) {
        $error = "Invalid token format.";
    } else {
        // Check if token exists and is valid
        $stmt = $conn->prepare("SELECT id, reset_token_expiry FROM users WHERE reset_token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check if token is not expired
            if (strtotime($user['reset_token_expiry']) > time()) {
                $showForm = true;
                
                // Handle form submission
                if ($_SERVER["REQUEST_METHOD"] == "POST") {
                    $newPassword = $_POST['password'] ?? '';
                    
                    // Validate password
                    if (empty($newPassword)) {
                        $error = "Password is required.";
                    } elseif (strlen($newPassword) < 8) {
                        $error = "Password must be at least 8 characters long.";
                    } else {
                        // Hash the new password
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        
                        // Update password and clear reset token
                        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
                        $stmt->bind_param("si", $hashedPassword, $user['id']);
                        
                        if ($stmt->execute()) {
                            $success = true;
                            $showForm = false;
                        } else {
                            $error = "Failed to update password. Please try again.";
                        }
                    }
                }
            } else {
                $error = "Reset link has expired. Please request a new password reset.";
            }
        } else {
            $error = "Invalid or expired reset link.";
        }
        $stmt->close();
    }
} else {
    $error = "No reset token provided.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">

<div class="card shadow p-4" style="max-width: 400px; width: 100%;">
    <h3 class="text-center mb-4">🔒 Reset Password</h3>
    
    <?php if ($showForm): ?>
        <form method="post" id="resetForm">
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <div class="input-group">
                    <input type="password" 
                           name="password" 
                           class="form-control" 
                           id="password" 
                           placeholder="Enter new password" 
                           minlength="8"
                           required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="form-text">Password must be at least 8 characters long.</div>
            </div>
            
            <div class="mb-3">
                <label for="confirmPassword" class="form-label">Confirm New Password</label>
                <input type="password" 
                       class="form-control" 
                       id="confirmPassword" 
                       placeholder="Confirm new password" 
                       required>
                <div class="invalid-feedback">
                    Passwords do not match.
                </div>
            </div>
            
            <button type="submit" class="btn btn-success w-100" id="submitBtn">
                <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                Update Password
            </button>
        </form>
    <?php else: ?>
        <div class="text-center">
            <p class="text-muted">Unable to reset password.</p>
            <a href="forgot_password.php" class="btn btn-primary">Request New Reset Link</a>
        </div>
    <?php endif; ?>
</div>

<script>
// Password visibility toggle
const togglePassword = document.querySelector("#togglePassword");
const passwordInput = document.querySelector("#password");

if (togglePassword && passwordInput) {
    togglePassword.addEventListener("click", function () {
        const type = passwordInput.getAttribute("type") === "password" ? "text" : "password";
        passwordInput.setAttribute("type", type);

        const icon = this.querySelector("i");
        icon.classList.toggle("bi-eye");
        icon.classList.toggle("bi-eye-slash");
    });
}

// Form validation
const form = document.getElementById('resetForm');
const confirmPasswordInput = document.getElementById('confirmPassword');

if (form) {
    // Real-time password confirmation validation
    confirmPasswordInput?.addEventListener('input', function() {
        const password = passwordInput.value;
        const confirmPassword = this.value;
        
        if (confirmPassword && password !== confirmPassword) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid');
        }
    });

    // Form submission
    form.addEventListener('submit', function(e) {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        
        if (password !== confirmPassword) {
            e.preventDefault();
            confirmPasswordInput.classList.add('is-invalid');
            return false;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submitBtn');
        const spinner = submitBtn.querySelector('.spinner-border');
        spinner.classList.remove('d-none');
        submitBtn.disabled = true;
    });
}

// Display messages
<?php if ($success): ?>
    Swal.fire({
        icon: 'success',
        title: 'Password Updated!',
        text: 'Your password has been successfully updated. You can now login with your new password.',
        confirmButtonText: 'Go to Login',
        confirmButtonColor: '#198754',
        allowOutsideClick: false
    }).then(() => {
        window.location.href = 'login.php';
    });
<?php endif; ?>

<?php if ($error): ?>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: '<?php echo addslashes($error); ?>',
        confirmButtonColor: '#d33'
    });
<?php endif; ?>
</script>

</body>
</html>
