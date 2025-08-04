<?php
// upload_data.php
header('Content-Type: application/json');
session_write_close(); // no session needed here

require_once __DIR__ . '/../config/db.php'; // adjust path if different; expects $mysqli

// Optional rate limiting: minimum seconds between inserts per device
define('MIN_INTERVAL_SECS', 30);

// Get input (supports GET or POST)
$device_id   = trim($_REQUEST['device_id'] ?? '');
$device_name = trim($_REQUEST['device_name'] ?? '');
$temperature = $_REQUEST['temperature'] ?? null;
$humidity    = $_REQUEST['humidity'] ?? null;
$ip_address  = $_SERVER['REMOTE_ADDR'];

// Validate required fields
if ($device_id === '' || $temperature === null || $humidity === null) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'msg' => 'Missing required parameters. Need device_id, temperature, humidity.'
    ]);
    exit;
}

$temperature = floatval($temperature);
$humidity = floatval($humidity);

// Rate control: skip if last entry was too recent
$allow_insert = true;
$recent_stmt = $mysqli->prepare("
    SELECT date 
    FROM data_suhu 
    WHERE device_id = ? 
    ORDER BY date DESC 
    LIMIT 1
");
$recent_stmt->bind_param("s", $device_id);
$recent_stmt->execute();
$recent_stmt->bind_result($last_date);
if ($recent_stmt->fetch()) {
    $recent_time = strtotime($last_date);
    if (time() - $recent_time < MIN_INTERVAL_SECS) {
        $allow_insert = false;
    }
}
$recent_stmt->close();

if (!$allow_insert) {
    echo json_encode([
        'status' => 'skipped',
        'msg' => 'Too soon since last entry; waiting to avoid flooding.'
    ]);
    exit;
}

// Upsert device record
$upsert = $mysqli->prepare("
    INSERT INTO device (device_id, device_name, ip_address, status, created_date)
    VALUES (?, ?, ?, 'active', NOW())
    ON DUPLICATE KEY UPDATE 
        device_name = VALUES(device_name),
        ip_address = VALUES(ip_address)
");
$upsert->bind_param("sss", $device_id, $device_name, $ip_address);
$upsert->execute();
$upsert->close();

// Insert reading
$insert = $mysqli->prepare("
    INSERT INTO data_suhu (device_id, device_name, temperature, humidity, date, ip_address)
    VALUES (?, ?, ?, ?, NOW(), ?)
");
$insert->bind_param("ssdds", $device_id, $device_name, $temperature, $humidity, $ip_address);
if ($insert->execute()) {
    echo json_encode([
        'status' => 'success',
        'device_id' => $device_id,
        'temperature' => $temperature,
        'humidity' => $humidity,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'msg' => $mysqli->error
    ]);
}
$insert->close();
