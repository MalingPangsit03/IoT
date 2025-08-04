<?php
// public/get_calibration.php
header('Content-Type: application/json');

// Load DB connection
require_once __DIR__ . '/../config/db.php';

// --- Read and validate device_id ---
$device_id = trim($_GET['sensor_id'] ?? ''); // ESP32 still sends sensor_id param
if ($device_id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing sensor_id parameter']);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $device_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid sensor_id format']);
    exit;
}

// --- Fetch calibration data from DB ---
$stmt = $mysqli->prepare("
    SELECT temperature, humidity
    FROM calibrator
    WHERE device_id = ?
    LIMIT 1
");
$stmt->bind_param('s', $device_id);
$stmt->execute();
$stmt->bind_result($offset_temp, $offset_hum);

if (!$stmt->fetch()) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(['error' => 'Calibration not found for given sensor_id']);
    exit;
}
$stmt->close();

// --- Return result ---
echo json_encode([
    'sensor_id' => $device_id,
    'offset_temp' => floatval($offset_temp),
    'offset_hum' => floatval($offset_hum),
    'updated_at' => null // no timestamp in calibrator table
]);
