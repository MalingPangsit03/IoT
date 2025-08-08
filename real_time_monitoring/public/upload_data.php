<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// === Read Raw JSON from POST ===
$rawJson = file_get_contents('php://input');
file_put_contents("raw_debug.txt", $rawJson); // Optional: log raw data for debugging

$data = json_decode($rawJson, true);
if (!is_array($data)) {
    file_put_contents("json_error.txt", json_last_error_msg() . "\nRAW: " . $rawJson);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Invalid JSON']);
    exit;
}

// === Extract and Validate Parameters ===
$device_id   = trim($data['device_id'] ?? '');
$device_name = trim($data['device_name'] ?? '');
$temperature = isset($data['temperature']) ? floatval($data['temperature']) : null;
$humidity    = isset($data['humidity']) ? floatval($data['humidity']) : null;
$date        = trim($data['date'] ?? '');
$ip_address  = trim($data['ip_address'] ?? $_SERVER['REMOTE_ADDR']);

if ($device_id === '' || $temperature === null || $humidity === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing required parameters']);
    exit;
}

date_default_timezone_set('Asia/Jakarta');
$date = date('Y-m-d H:i:s');

// === Anti-Spam: Prevent too frequent uploads ===
define('MIN_INTERVAL_SECS', 30); // set to 0 to allow continuous upload
$allow_insert = true;

$stmt = $mysqli->prepare("SELECT date FROM data_suhu WHERE device_id = ? ORDER BY date DESC LIMIT 1");
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

// === Insert/Update Device Info ===
$stmt = $mysqli->prepare("
    INSERT INTO device (device_id, device_name, ip_address, created_date, status)
    VALUES (?, ?, ?, NOW(), 'active')
    ON DUPLICATE KEY UPDATE 
        device_name = VALUES(device_name),
        ip_address = VALUES(ip_address),
        updated_date = NOW()
");
$stmt->bind_param("sss", $device_id, $device_name, $ip_address);
$stmt->execute();
$stmt->close();

// === Insert Sensor Data ===
$stmt = $mysqli->prepare("
    INSERT INTO data_suhu (device_id, temperature, humidity, date, ip_address)
    VALUES (?, ?, ?, ?, ?)
");
$stmt->bind_param("sddss", $device_id, $temperature, $humidity, $date, $ip_address);

if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'device_id' => $device_id,
        'temperature' => $temperature,
        'humidity' => $humidity,
        'timestamp' => $date
    ]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $mysqli->error]);
}
$stmt->close();
?>