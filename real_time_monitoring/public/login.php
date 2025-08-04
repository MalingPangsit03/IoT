<?php
// public/login.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = $mysqli->prepare("SELECT id, password, level FROM user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($id, $hash, $level);
        if ($stmt->fetch()) {
            if (password_verify($password, $hash)) {
                // login success
                $_SESSION['user_id'] = $id;
                $_SESSION['username'] = $username;
                $_SESSION['level'] = strtolower($level);
                header('Location: dashboard.php');
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
      <div style="margin-top:12px; font-size:0.85rem; color:#555;">
        <!-- Optional: add forgot password link if implemented -->
        <!-- <a href="forgot_password.php">Forgot password?</a> -->
      </div>
    </div>
  </div>
</body>
</html>
