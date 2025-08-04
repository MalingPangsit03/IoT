<?php
// public/manage_users.php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
if (!is_admin()) {
    die("Access denied.");
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['csrf_token'];

$errors = [];
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid form submission.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $level = strtolower(trim($_POST['level'] ?? 'user'));
            if ($username === '' || $password === '' || !in_array($level, ['admin', 'user'])) {
                $errors[] = "All fields are required and level must be 'admin' or 'user'.";
            } else {
                // Check if username exists
                $stmt = $mysqli->prepare("SELECT id FROM user WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $errors[] = "Username already exists.";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $mysqli->prepare("INSERT INTO user (username, password, level, created_date) VALUES (?, ?, ?, NOW())");
                    $ins->bind_param("sss", $username, $hash, $level);
                    if ($ins->execute()) {
                        $success = "User added successfully.";
                    } else {
                        $errors[] = "Failed to add user: " . $mysqli->error;
                    }
                    $ins->close();
                }
                $stmt->close();
            }
        } elseif ($action === 'edit') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $level = strtolower(trim($_POST['level'] ?? 'user'));
            $password = $_POST['password'] ?? '';
            if ($user_id <= 0 || !in_array($level, ['admin', 'user'])) {
                $errors[] = "Invalid input.";
            } else {
                // Update level and optionally password
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $mysqli->prepare("UPDATE user SET level = ?, password = ? WHERE id = ?");
                    $upd->bind_param("ssi", $level, $hash, $user_id);
                } else {
                    $upd = $mysqli->prepare("UPDATE user SET level = ? WHERE id = ?");
                    $upd->bind_param("si", $level, $user_id);
                }
                if ($upd->execute()) {
                    $success = "User updated successfully.";
                } else {
                    $errors[] = "Failed to update user.";
                }
                $upd->close();
            }
        } elseif ($action === 'delete') {
            $user_id = intval($_POST['user_id'] ?? 0);
            if ($user_id <= 0) {
                $errors[] = "Invalid user.";
            } else {
                // Prevent deleting yourself
                if ($user_id === $_SESSION['user_id']) {
                    $errors[] = "You cannot delete your own account.";
                } else {
                    $del = $mysqli->prepare("DELETE FROM user WHERE id = ?");
                    $del->bind_param("i", $user_id);
                    if ($del->execute()) {
                        $success = "User deleted.";
                    } else {
                        $errors[] = "Failed to delete user.";
                    }
                    $del->close();
                }
            }
        }
    }
}

// Fetch all users
$users = $mysqli->query("SELECT id, username, level, created_date FROM user ORDER BY created_date DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Manage Users</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .flex { display: flex; gap: 1rem; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    th, td { border: 1px solid #ccc; padding: 8px; }
    .error { background:#ffe5e5; padding:8px; border:1px solid #d9534f; margin-bottom:8px; }
    .success { background:#e6ffed; padding:8px; border:1px solid #5cb85c; margin-bottom:8px; }
    .small { font-size:0.85em; color:#555; }
    .btn { padding:6px 12px; border:none; cursor:pointer; border-radius:4px; }
    .btn-edit { background:#f0ad4e; color:white; }
    .btn-delete { background:#d9534f; color:white; }
    .btn-add { background:#5cb85c; color:white; }
    .card { background:white; padding:16px; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.08); margin-bottom:16px; }
  </style>
</head>
<body>
  <div class="topbar">
    <div><strong>User Management</strong></div>
    <div><a href="dashboard.php">Back to Dashboard</a></div>
  </div>

  <div class="card">
    <h3>Add New User</h3>
    <?php if ($errors): ?>
      <div class="error">
        <?php foreach ($errors as $e) echo "<div>" . htmlentities($e) . "</div>"; ?>
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="success"><?= htmlentities($success) ?></div>
    <?php endif; ?>
    <form method="post" style="gap:1rem; display:flex; flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= htmlentities($csrf) ?>">
      <input type="hidden" name="action" value="add">
      <div style="flex:1 1 200px;">
        <label>Username<br><input name="username" required></label>
      </div>
      <div style="flex:1 1 200px;">
        <label>Password<br><input name="password" type="password" required></label>
      </div>
      <div style="flex:1 1 200px;">
        <label>Role<br>
          <select name="level" required>
            <option value="user">User</option>
            <option value="admin">Admin</option>
          </select>
        </label>
      </div>
      <div style="flex:1 1 100px; align-self:flex-end;">
        <button class="btn btn-add" type="submit">Add User</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Existing Users</h3>
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Username</th><th>Role</th><th>Created</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($u = $users->fetch_assoc()): ?>
          <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlentities($u['username']) ?></td>
            <td><?= htmlentities(ucfirst($u['level'])) ?></td>
            <td><span class="small"><?= htmlentities($u['created_date']) ?></span></td>
            <td class="flex">
              <!-- Edit form (inline) -->
              <form method="post" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= htmlentities($csrf) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <label style="margin-right:4px;">
                  Role
                  <select name="level">
                    <option value="user" <?= strtolower($u['level']) === 'user'? 'selected':'' ?>>User</option>
                    <option value="admin" <?= strtolower($u['level']) === 'admin'? 'selected':'' ?>>Admin</option>
                  </select>
                </label>
                <label style="margin-right:4px;">
                  New Password
                  <input name="password" type="password" placeholder="(leave blank)">
                </label>
                <button class="btn btn-edit" type="submit">Save</button>
              </form>

              <!-- Delete form -->
              <form method="post" style="margin:0;" onsubmit="return confirm('Delete this user?');">
                <input type="hidden" name="csrf_token" value="<?= htmlentities($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button class="btn btn-delete" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
        <?php if ($users->num_rows === 0): ?>
          <tr><td colspan="5">No users found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
