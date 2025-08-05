<?php
// public/login.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

// Set PHP timezone (match your server timezone)
date_default_timezone_set('Asia/Jakarta'); // or 'UTC' to match MySQL

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = $mysqli->prepare("SELECT id, username, password, level FROM user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($id, $user, $hash, $level);

        if ($stmt->fetch()) {
            if (password_verify($password, $hash)) {
                $stmt->close();

                // ✅ 1. Generate OTP and expiration (5 minutes ahead)
                $otp = random_int(100000, 999999);
                $expires = date('Y-m-d H:i:s', time() + 300);

                // ✅ 2. Insert OTP into database
                $stmt = $mysqli->prepare("INSERT INTO otp_codes (user_id, otp_code, expires_at) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $id, $otp, $expires);
                $stmt->execute();
                $stmt->close();

                error_log("Generated OTP: $otp for user_id: $id");

                // ✅ 3. Send OTP via email
                $to = getEmailByUsername($username);
                if ($to) {
                    error_log("Sending OTP to: $to");
                    send_otp_email($to, $otp);
                } else {
                    error_log("No email address found for username: $username");
                }

                // ✅ 4. Store session temporarily for OTP verification
                $_SESSION['pending_user_id'] = $id;
                $_SESSION['pending_username'] = $username;
                $_SESSION['pending_level'] = strtolower($level);

                header('Location: verify_otp.php');
                exit;
            } else {
                $error = 'Invalid credentials.';
            }
        } else {
            $error = 'Invalid credentials.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Login - Temperature Monitoring</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="login-wrapper">
    <div class="card card-login">
      <h2 style="margin-bottom:8px;">Login</h2>
      <?php if ($error): ?>
        <div class="error"><?= htmlentities($error) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <div style="margin-bottom:12px;">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" required placeholder="Enter username">
        </div>
        <div style="margin-bottom:12px;">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required placeholder="Enter password">
        </div>
        <button class="btn" type="submit" style="width:100%;">Login</button>
      </form>
    </div>
  </div>
</body>
</html>
