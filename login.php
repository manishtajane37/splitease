<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Login - SplitEase</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  
  <!-- Bootstrap + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  
  <!-- Fonts + Style -->
  <link rel="stylesheet" href="style.css" />
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
</head>
<body class="neon-body">

  <div class="container d-flex align-items-center justify-content-center min-vh-100">
    <div class="neon-card p-5 rounded-4">
      <h2 class="text-center mb-4 neon-text">SplitEase Login</h2>
      <form action="login.php" method="post">

        <div class="mb-3">
          <label>Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>

        <div class="mb-3">
          <label>Password</label>
          <div class="input-group">
            <input type="password" name="password" class="form-control" id="loginPassword" required>
            <span class="input-group-text bg-white">
              <i class="bi bi-eye-slash" id="toggleLoginPassword" style="cursor: pointer;"></i>
            </span>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100">Login</button>

        <!-- 🔗 Signup link -->
        <p class="text-center mt-3">
          Don't have an account?
          <a href="signup.html" class="text-decoration-none fw-bold text-success">Sign up here</a>
        </p>

      </form>
    </div>
  </div>

  <!-- 👁 Toggle Password Script -->
  <script>
    const toggleLoginPassword = document.getElementById('toggleLoginPassword');
    const loginPassword = document.getElementById('loginPassword');

    toggleLoginPassword.addEventListener('click', function () {
      const type = loginPassword.getAttribute('type') === 'password' ? 'text' : 'password';
      loginPassword.setAttribute('type', type);
      this.classList.toggle('bi-eye');
      this.classList.toggle('bi-eye-slash');
    });
  </script>
<?php
session_start();
require_once 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // ✅ Store user info in session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username']; // ✅ Save username

            header("Location: dash.php");
            exit();
        } else {
            echo "Incorrect password.";
        }
    } else {
        echo "No user found with that email.";
    }
}
?>

</body>
</html>


