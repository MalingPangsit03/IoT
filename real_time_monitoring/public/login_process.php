<?php
session_start();
require_once 'config/db.php';
require_once 'config/mailer.php';

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

$stmt = $mysqli->prepare("SELECT id, password FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
    $stmt->bind_result($user_id, $hashed_password);
    $stmt->fetch();

    if (password_verify($password, $hashed_password)) {
        // Generate OTP
        $otp = rand(100000, 999999);
        $expiry = date('Y-m-d H:i:s', time() + 300); // 5 mins from now

        // Store OTP in otp_codes
        $insert = $mysqli->prepare("INSERT INTO otp_codes (user_id, otp_code, expires_at) VALUES (?, ?, ?)");
        $insert->bind_param("iss", $user_id, $otp, $expiry);
        $insert->execute();

        // Send email
        send_otp_email($email, $otp);

        // Store temp user session
        $_SESSION['pending_user_id'] = $user_id;
        header("Location: verify_otp.php");
        exit;
    }
}

$_SESSION['error'] = "Login failed";
header("Location: login_form.php");
exit;
