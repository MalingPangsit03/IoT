<?php
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login(); // Block direct access without login + OTP

$username = $_SESSION['username'];
$level    = $_SESSION['level'];

// ✅ Latest reading per device with alert_status
$sql_latest = "
SELECT ds.device_id, d.device_name,
       ds.temperature, ds.humidity, ds.date, ds.ip_address,
       d.alert_status
FROM data_suhu ds
LEFT JOIN device d ON ds.device_id = d.device_id
INNER JOIN (
    SELECT device_id, MAX(date) AS maxdate
    FROM data_suhu
    GROUP BY device_id
) latest ON ds.device_id = latest.device_id AND ds.date = latest.maxdate
ORDER BY ds.device_id;
";
$result_latest = $mysqli->query($sql_latest);

// ✅ History (latest 30 records)
$sql_history = "
SELECT ds.device_id, d.device_name,
       ds.temperature, ds.humidity, ds.date, ds.ip_address
FROM data_suhu ds
LEFT JOIN device d ON ds.device_id = d.device_id
ORDER BY ds.date DESC
LIMIT 30;
";
$result_history = $mysqli->query($sql_history);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dashboard - Temperature Monitoring</title>
  <meta http-equiv="refresh" content="30">
  <link rel="stylesheet" href="style.css">
  <style>
    .alert-high { background: #ffe5e5; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.9em; }
    .badge-warning { background: #f0ad4e; color: white; }
    .badge-danger { background: #d9534f; color: white; }
    .badge-normal { background: #5cb85c; color: white; }
    .small { font-size: 0.85em; color: #555; }
    .flex { display: flex; gap: 1rem; align-items: center; }
    .card { background: white; padding: 16px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 16px; }
  </style>
</head>
<body>

<div class="topbar">
  <div><strong>Temperature Monitoring Dashboard</strong></div>
  <div class="flex">
    <a href="dashboard.php">Home</a>
    <a href="log_data.php">Log Data</a>
    <a href="log_data.php?period=day">Data Per Hari</a>
    <a href="log_data.php?period=week">Data Per Minggu</a>
    <div>Welcome, <?= htmlentities($username) ?> (<?= htmlentities(ucfirst($level)) ?>)</div>
    <div><a href="logout.php">Logout</a></div>
  </div>
</div>

<div class="card">
  <h3>Latest Reading per Device</h3>
  <table>
    <thead>
      <tr>
        <th>Device ID</th>
        <th>Name</th>
        <th>Temp (°C)</th>
        <th>Humidity (%)</th>
        <th>Timestamp</th>
        <th>IP</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $any_alert = false;
      if ($result_latest && $result_latest->num_rows):
        while ($row = $result_latest->fetch_assoc()):
          $is_alert = ($row['alert_status'] === 'alert');
          if ($is_alert) $any_alert = true;
          $row_class = $is_alert ? 'alert-high' : '';
      ?>
          <tr class="<?= $row_class ?>">
            <td><?= htmlentities($row['device_id']) ?></td>
            <td><?= htmlentities($row['device_name'] ?: '-') ?></td>
            <td>
              <?= number_format($row['temperature'], 1) ?>
              <span class="badge <?= $is_alert ? 'badge-danger' : 'badge-normal' ?>">
                <?= $is_alert ? 'High' : 'OK' ?>
              </span>
            </td>
            <td><?= number_format($row['humidity'], 1) ?></td>
            <td><?= htmlentities($row['date']) ?></td>
            <td><?= htmlentities($row['ip_address']) ?></td>
            <td>
              <span class="badge <?= $is_alert ? 'badge-danger' : 'badge-normal' ?>">
                <?= $is_alert ? 'Alert' : 'Normal' ?>
              </span>
            </td>
          </tr>
      <?php
        endwhile;
      else:
      ?>
        <tr><td colspan="7">No data available.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Recent History (Last 30 Records)</h3>
  <table>
    <thead>
      <tr>
        <th>Device ID</th>
        <th>Name</th>
        <th>Temp (°C)</th>
        <th>Humidity (%)</th>
        <th>Timestamp</th>
        <th>IP</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($result_history && $result_history->num_rows): ?>
        <?php while ($h = $result_history->fetch_assoc()): ?>
          <tr>
            <td><?= htmlentities($h['device_id']) ?></td>
            <td><?= htmlentities($h['device_name'] ?: '-') ?></td>
            <td><?= number_format($h['temperature'], 1) ?></td>
            <td><?= number_format($h['humidity'], 1) ?></td>
            <td><?= htmlentities($h['date']) ?></td>
            <td><?= htmlentities($h['ip_address']) ?></td>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="6">No history available.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if (is_admin()): ?>
  <div class="card">
    <h3>Admin Controls</h3>
    <p><a href="manage_users.php">Manage Users</a></p>
  </div>
<?php endif; ?>

<?php if ($any_alert): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    alert("⚠️ Warning: One or more devices are in ALERT mode!");
});
</script>
<?php endif; ?>

</body>
</html>
