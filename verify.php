<?php
include 'db.php'; // your database connection

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $entered_otp = $_POST['otp_code'];

    $stmt = $conn->prepare("SELECT otp_code, otp_expires_at FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($stored_otp, $expires_at);
    $stmt->fetch();
    $stmt->close();

    if (!$stored_otp) {
        $error_message = "No OTP found. Please register again.";
    } 
    elseif (new DateTime() > new DateTime($expires_at)) {
        $error_message = "OTP expired. Please register again.";
    } 
    elseif ($entered_otp === $stored_otp) {
        // OTP is correct
        $update = $conn->prepare("UPDATE users SET is_verified = 1, otp_code = NULL, otp_expires_at = NULL WHERE email = ?");
        $update->bind_param("s", $email);
        $update->execute();

        $success_message = "✅ OTP Verified Successfully!";
        echo "
            <div style='text-align: center; margin-top: 50px; color: #0f0;'>
                <h2>$success_message</h2>
                <p>Redirecting to login page...</p>
            </div>
            <script>
                setTimeout(() => window.location.href = 'login.php', 3000);
            </script>
        ";
        exit();
    } else {
        $error_message = "❌ Incorrect OTP. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Verify OTP - SplitEase</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500&family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      color: #fff;
    }
    .neon-card {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(25px);
      padding: 40px;
      border-radius: 20px;
      max-width: 400px;
      width: 100%;
      box-shadow: 0 0 30px rgba(0, 255, 255, 0.2);
    }
    .neon-text {
      font-family: 'Orbitron', sans-serif;
      color: #00e5ff;
      text-shadow: 0 0 8px #00e5ff;
      margin-bottom: 20px;
      text-align: center;
    }
    .form-label {
      color: #00d9ff;
    }
    .form-control {
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
      border: 1px solid #00e5ff;
      border-radius: 10px;
      padding: 12px;
    }
    .form-control::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }
    .btn-primary {
      background: linear-gradient(to right, #00e5ff, #ff00cc);
      border: none;
      font-weight: 600;
      color: #000;
      border-radius: 10px;
      padding: 12px;
      width: 100%;
    }
.success-message {
  text-align: center;
  margin-top: 100px;
  padding: 30px;
  background: rgba(0, 255, 0, 0.05);
  border: 2px solid #00ff88;
  border-radius: 16px;
  width: 80%;
  max-width: 500px;
  margin-left: auto;
  margin-right: auto;
  box-shadow: 0 0 20px rgba(0, 255, 128, 0.5);
  color: #00ff88;
  font-family: 'Poppins', sans-serif;
  animation: fadeIn 1s ease-in-out;
  backdrop-filter: blur(5px);
}

.success-message h2 {
  font-size: 2rem;
  font-weight: 600;
  margin-bottom: 10px;
}

.success-message p {
  font-size: 1.1rem;
  color: #ccffcc;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

    
  </style>
</head>
<body>
  <div class="neon-card">
    <h2 class="neon-text">OTP Verification</h2>
    <form action="verify.php" method="POST">
      <input type="hidden" name="email" value="<?php echo htmlspecialchars($_GET['email'] ?? $_POST['email'] ?? ''); ?>">
      <div class="mb-3">
        <label class="form-label">Enter OTP sent to your email:</label>
        <input type="text" class="form-control" name="otp_code" placeholder="6-digit OTP" required>
      </div>
      <button type="submit" class="btn btn-primary">Verify</button>
    </form>
    <?php if ($error_message): ?>
      <div class="text-danger mt-3 text-center"><?php echo $error_message; ?></div>
    <?php endif; ?>
  </div>
</body>
</html>
