<?php
// includes/config.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';


function send_otp_email($to, $otp) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'radicaltoken1003@gmail.com'; // ✅ Replace with your Gmail
        $mail->Password   = 'qycc qezo rkuu eiej'; // ✅ Use Gmail App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('radicaltoken1003@gmail.com', 'PHP Emailer');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = 'Your OTP Code';
        $mail->Body    = "<h3>Your OTP is: <strong>$otp</strong></h3><p>Valid for 5 minutes.</p>";

        $mail->send();
    } catch (Exception $e) {
        error_log("OTP mail error: " . $mail->ErrorInfo);
    }
}
