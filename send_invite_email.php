
<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

function sendInviteEmail($toEmail, $groupLink = "http://localhost/splitease/signup.php") {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'spliteasify@gmail.com';
        $mail->Password = 'rqpc ksek yqgf zisx';  // ⚠️ For security, store this in a config/env file
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('spliteasify@gmail.com', 'SplitEase');
        $mail->addAddress($toEmail);
        $mail->Subject = 'You’re invited to SplitEase';
        $mail->Body    = "You were invited to join a SplitEase group.\nPlease sign up at: $groupLink";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Mailer Error: {$mail->ErrorInfo}";
    }
}
