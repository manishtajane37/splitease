<?php
include 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $phone = $_POST['phone'] ?? '';

    // Validate required fields
    if (empty($username) || empty($email) || empty($password) || empty($phone)) {
        echo "<script>alert('Please fill all required fields'); window.location.href='signup.html';</script>";
        exit();
    }

    // ✅ Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo "<script>alert('This email is already registered. Please log in.'); window.location.href='signup.html';</script>";
        exit();
    }

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Generate OTP and expiry
    $otp = rand(100000, 999999);
    $otp_expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

    // Insert into users table
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, phone, otp_code, otp_expires_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $username, $email, $hashed_password, $phone, $otp, $otp_expiry);

    if ($stmt->execute()) {
        // Send OTP via Email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'anjalirathod314@gmail.com';
            $mail->Password = 'qumc ikqm etxi kupf'; // App password, not regular Gmail password
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('anjalirathod314@gmail.com', 'SplitEase');
            $mail->addAddress($email);

            $mail->Subject = 'Verify your SplitEase Account';
            $mail->Body    = "Hi $username,\n\nYour OTP for SplitEase is: $otp\nThis OTP is valid for 10 minutes.";

            $mail->send();

            header("Location: verify.php?email=" . urlencode($email));
            exit();
        } catch (Exception $e) {
            echo "OTP Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        echo "Error: " . $stmt->error;
    }
}
?>
