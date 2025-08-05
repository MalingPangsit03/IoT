<?php
session_start();
require_once 'config/db.php';

$user_id = $_SESSION['pending_user_id'] ?? null;
$otp = $_POST['otp'] ?? '';

if (!$user_id || !$otp) {
    header("Location: login_form.php");
    exit;
}

// Find OTP
$stmt = $mysqli->prepare("SELECT id, expires_at, is_used FROM otp_codes WHERE user_id = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("is", $user_id, $otp);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
    $stmt->bind_result($otp_id, $expires_at, $is_used);
    $stmt->fetch();

    if ($is_used == 0 && strtotime($expires_at) > time()) {
        // Mark OTP as used
        $mysqli->query("UPDATE otp_codes SET is_used = 1 WHERE id = $otp_id");

        // Login success
        $_SESSION['user_id'] = $user_id;
        unset($_SESSION['pending_user_id']);
        header("Location: dashboard.php");
        exit;
    } else {
        $_SESSION['error'] = "OTP expired or already used.";
    }
} else {
    $_SESSION['error'] = "Invalid OTP.";
}

header("Location: verify_otp.php");
exit;
