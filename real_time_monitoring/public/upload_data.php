<?php
// public/upload_data.php
header('Content-Type: application/json');

// Load DB connection
require_once __DIR__ . '/../config/db.php';

// Minimum seconds between two readings from the same device
define('MIN_INTERVAL_SECS', 30);

// --- Read input data ---
$raw_body = file_get_contents('php://input');
$data = json_decode($raw_body, true);

// If JSON decoding fails, fallback to form-urlencoded
if (json_last_error() !== JSON_ERROR_NONE) {
    $device_id   = trim($_REQUEST['device_id'] ?? '');
    $device_name = trim($_REQUEST['device_name'] ?? '');
    $temperature = isset($_REQUEST['temperature']) ? floatval($_REQUEST['temperature']) : null;
    $humidity    = isset($_REQUEST['humidity']) ? floatval($_REQUEST['humidity']) : null;
    $date        = null; // will use server time
    $ip_address  = $_SERVER['REMOTE_ADDR'];
} else {
    $device_id   = trim($data['sensor_id'] ?? '');
    $device_name = trim($data['device_name'] ?? '');
    $temperature = isset($data['temperature']) ? floatval($data['temperature']) : null;
    $humidity    = isset($data['humidity']) ? floatval($data['humidity']) : null;
    $date        = trim($data['date'] ?? '');
    $ip_address  = trim($data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
}

// --- Validate required fields ---
if ($device_id === '' || $temperature === null || $humidity === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing required parameters']);
    exit;
}

// --- Rate limiting check ---
$allow_insert = true;
$stmt = $mysqli->prepare("
    SELECT date FROM data_suhu
    WHERE device_id = ?
    ORDER BY date DESC
    LIMIT 1
");
$stmt->bind_param("s", $device_id);
$stmt->execute();
$stmt->bind_result($last_date);
if ($stmt->fetch()) {
    $recent_time = strtotime($last_date);
    if (time() - $recent_time < MIN_INTERVAL_SECS) {
        $allow_insert = false;
    }
}
$stmt->close();

if (!$allow_insert) {
    echo json_encode(['status' => 'skipped', 'msg' => 'Too soon since last entry']);
    exit;
}

// --- Insert/Update device info ---
// Your `device` table has extra columns, so we will keep what we can update
$stmt = $mysqli->prepare("
    INSERT INTO device (device_id, device_name, ip_address, created_date, status)
    VALUES (?, ?, ?, NOW(), 'active')
    ON DUPLICATE KEY UPDATE 
        device_name = VALUES(device_name),
        ip_address = VALUES(ip_address),
        updated_date = NOW()
");
$ip_for_device = $ip_address ?: ($_SERVER['REMOTE_ADDR'] ?? '');
$stmt->bind_param("sss", $device_id, $device_name, $ip_for_device);
$stmt->execute();
$stmt->close();

// --- Insert the reading into data_suhu ---
$stmt = $mysqli->prepare("
    INSERT INTO data_suhu (device_id, temperature, humidity, date, ip_address)
    VALUES (?, ?, ?, ?, ?)
");
$timestamp = $date ?: date('Y-m-d H:i:s');
$stmt->bind_param("sddss", $device_id, $temperature, $humidity, $timestamp, $ip_address);
if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'device_id' => $device_id,
        'temperature' => $temperature,
        'humidity' => $humidity,
        'timestamp' => $timestamp
    ]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $mysqli->error]);
}
$stmt->close();
