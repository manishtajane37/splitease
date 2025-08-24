<!-- <?php
session_start();              // Start session
session_unset();              // Remove all session variables
session_destroy();            // Destroy the session

// Redirect to login page
header("Location: login.php");
exit();
?> -->





<?php
// Start the session
session_start();

// Unset all session variables
$_SESSION = array();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear any output buffers
if (ob_get_level()) {
    ob_end_clean();
}

// Redirect to login page
header("Location: login.php");
exit();
?>