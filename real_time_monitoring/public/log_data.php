<?php
// public/log_data.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$level = $_SESSION['level'];
$device_filter = trim($_GET['device'] ?? '');
$period = $_GET['period'] ?? ''; // '', 'day', 'week'
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 7;
$offset = ($page - 1) * $per_page;

// Build WHERE clauses
$where_clauses = [];
$params = [];
$types = '';

// Device filter
if ($device_filter !== '') {
    $where_clauses[] = "ds.device_id = ?";
    $types .= 's';
    $params[] = $device_filter;
}

// Period filter
if ($period === 'day') {
    $where_clauses[] = "DATE(ds.date) = CURDATE()";
} elseif ($period === 'week') {
    $where_clauses[] = "YEARWEEK(ds.date, 1) = YEARWEEK(CURDATE(), 1)";
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Summary query (aggregate stats)
$summary_sql = "
SELECT 
    COUNT(*) AS total_points,
    ROUND(AVG(ds.temperature),2) AS avg_temp,
    ROUND(MIN(ds.temperature),2) AS min_temp,
    ROUND(MAX(ds.temperature),2) AS max_temp,
    ROUND(AVG(ds.humidity),2) AS avg_humi
FROM data_suhu ds
LEFT JOIN device d ON ds.device_id = d.device_id
{$where_sql}
";
$summary_stmt = $mysqli->prepare($summary_sql);
if ($types) {
    $summary_stmt->bind_param($types, ...$params);
}
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();

// Total count for pagination (reuse for computing total pages)
$total_rows = intval($summary['total_points'] ?? 0);
$total_pages = max(1, ceil($total_rows / $per_page));

// Main paginated raw data query
$sql = "
SELECT 
    ds.device_id, 
    d.device_name,
    ds.temperature, 
    ds.humidity, 
    ds.date, 
    ds.ip_address
FROM data_suhu ds
LEFT JOIN device d ON ds.device_id = d.device_id
{$where_sql}
ORDER BY ds.date DESC
LIMIT ? OFFSET ?
";
$stmt = $mysqli->prepare($sql);
if ($types) {
    // bind dynamic params plus limit and offset
    // need to build types string: existing + "ii"
    $bind_types = $types . 'ii';
    $stmt->bind_param($bind_types, ...array_merge($params, [$per_page, $offset]));
} else {
    $stmt->bind_param('ii', $per_page, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

// Build base URL for pagination links (preserving filters)
$query_parts = [];
if ($device_filter !== '') $query_parts[] = 'device=' . urlencode($device_filter);
if ($period !== '') $query_parts[] = 'period=' . urlencode($period);
$base_query = implode('&', $query_parts);
$base_url = 'log_data.php' . ($base_query ? "?{$base_query}" : '');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Log Data</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .summary { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
    .badge { padding:6px 12px; border-radius:4px; background:#2e86ab; color:#fff; margin-right:5px; }
    .filter { margin-bottom:1rem; }
    .pagination { margin-top:16px; display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .page-link { padding:6px 12px; border-radius:4px; background:#f0f4f9; text-decoration:none; color:#1f2d3a; border:1px solid #d1d9e6; }
    .page-link.active { background: var(--primary); color:#fff; border-color: var(--primary); }
    .topbar { display:flex; justify-content:space-between; background:#2e86ab; color:#fff; padding:10px; border-radius:5px; flex-wrap:wrap; }
    a { color: #fff; text-decoration:none; margin-right:10px; }
    .card { background:#fff; padding:16px; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.08); margin:16px; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:8px; border:1px solid #e3e8ee; text-align:left; }
    tbody tr:nth-child(odd) { background:#fcfdff; }
  </style>
</head>
<body>
  <div class="topbar">
    <div><strong>Log Data</strong></div>
    <div class="flex" style="gap:1rem; align-items:center;">
      <a href="dashboard.php">Home</a>
      <a href="log_data.php" style="color:#fff;">All</a>
      <a href="log_data.php?period=day" style="color:#fff;">Data Per Hari</a>
      <a href="log_data.php?period=week" style="color:#fff;">Data Per Minggu</a>
      <div>Welcome, <?= htmlentities($_SESSION['username']) ?> (<?= htmlentities(ucfirst($level)) ?>)</div>
      <div><a href="logout.php">Logout</a></div>
    </div>
  </div>

  <div class="card">
    <h3>Summary <?= $period ? '(' . ($period === 'day' ? 'Hari Ini' : 'Minggu Ini') . ')' : '' ?></h3>
    <div class="summary">
      <div class="badge">Total Points: <?= htmlentities($summary['total_points'] ?? 0) ?></div>
      <div class="badge">Avg Temp: <?= htmlentities($summary['avg_temp'] ?? 0) ?> °C</div>
      <div class="badge">Min Temp: <?= htmlentities($summary['min_temp'] ?? 0) ?> °C</div>
      <div class="badge">Max Temp: <?= htmlentities($summary['max_temp'] ?? 0) ?> °C</div>
      <div class="badge">Avg Humidity: <?= htmlentities($summary['avg_humi'] ?? 0) ?> %</div>
    </div>

    <div class="filter">
      <form method="get" style="display:flex; gap:1rem; flex-wrap:wrap;">
        <div>
          <label>Device ID:
            <input name="device" value="<?= htmlentities($device_filter) ?>" placeholder="e.g. Tools-1">
          </label>
        </div>
        <div>
          <label>Period:
            <select name="period">
              <option value="" <?= $period === '' ? 'selected' : '' ?>>All</option>
              <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Per Hari</option>
              <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Per Minggu</option>
            </select>
          </label>
        </div>
        <div>
          <button type="submit" class="btn">Apply Filter</button>
        </div>
      </form>
    </div>

    <h4>Raw Log Data (page <?= $page ?> of <?= $total_pages ?>)</h4>
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
        <?php if ($result && $result->num_rows): ?>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td><?= htmlentities($row['device_id']) ?></td>
              <td><?= htmlentities($row['device_name'] ?: '-') ?></td>
              <td><?= htmlentities(number_format($row['temperature'], 1)) ?></td>
              <td><?= htmlentities(number_format($row['humidity'], 1)) ?></td>
              <td><?= htmlentities($row['date']) ?></td>
              <td><?= htmlentities($row['ip_address']) ?></td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="6">No data match the filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <div class="pagination">
      <?php
        // build preserved query prefix without page
        $preserve = [];
        if ($device_filter !== '') $preserve[] = 'device=' . urlencode($device_filter);
        if ($period !== '') $preserve[] = 'period=' . urlencode($period);
        $base = 'log_data.php' . ($preserve ? '?' . implode('&', $preserve) : '');
        // prev
        if ($page > 1):
          $prev_q = $base . ($preserve ? '&' : '?') . 'page=' . ($page -1);
      ?>
        <a class="page-link" href="<?= $prev_q ?>">« Prev</a>
      <?php endif; ?>

      <?php
        // page numbers (simple range)
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        for ($p = $start; $p <= $end; $p++):
          $link = $base . ($preserve ? '&' : '?') . 'page=' . $p;
      ?>
          <a class="page-link <?= $p === $page ? 'active' : '' ?>" href="<?= $link ?>"><?= $p ?></a>
      <?php endfor; ?>

      <?php if ($page < $total_pages): 
          $next_q = $base . ($preserve ? '&' : '?') . 'page=' . ($page +1);
      ?>
        <a class="page-link" href="<?= $next_q ?>">Next »</a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
