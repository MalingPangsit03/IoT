<?php
// public/verify_otp.php
session_start();
require_once __DIR__ . '/../config/db.php';

$error = '';

if (!isset($_SESSION['pending_user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp_input = trim($_POST['otp'] ?? '');
    $user_id = $_SESSION['pending_user_id'];

    $stmt = $mysqli->prepare("SELECT id FROM otp_codes 
        WHERE user_id = ? AND otp_code = ? AND is_used = 0 AND expires_at >= NOW()");
    $stmt->bind_param("is", $user_id, $otp_input);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        // Mark OTP as used
        $stmt2 = $mysqli->prepare("UPDATE otp_codes SET is_used = 1 WHERE user_id = ? AND otp_code = ?");
        $stmt2->bind_param("is", $user_id, $otp_input);
        $stmt2->execute();
        $stmt2->close();

        // Finalize login
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $_SESSION['pending_username'];
        $_SESSION['level'] = $_SESSION['pending_level'];
        unset($_SESSION['pending_user_id'], $_SESSION['pending_username'], $_SESSION['pending_level']);

        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid or expired OTP.";
    }

    error_log("Verifying OTP: input={$otp_input}, user_id={$user_id}");
    
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify OTP</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="login-wrapper">
    <div class="card card-login">
      <h2>OTP Verification</h2>
      <?php if ($error): ?>
        <div class="error"><?= htmlentities($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <label>Enter the 6-digit OTP sent to your email:</label>
        <input type="text" name="otp" required maxlength="6" placeholder="e.g. 123456">
        <button class="btn" type="submit" style="width:100%;">Verify</button>
      </form>
    </div>
  </div>
</body>
</html>
