<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

var_dump($_GET); // Debugging line
$device_id = trim($_GET['device_id'] ?? '');

if ($device_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing device_id']);
    exit;
}

$stmt = $mysqli->prepare("SELECT temp_high, temp_low, hum_high, hum_low FROM thresholds WHERE device_id = ?");
$stmt->bind_param("s", $device_id);
$stmt->execute();
$stmt->bind_result($temp_high, $temp_low, $hum_high, $hum_low);

if ($stmt->fetch()) {
    echo json_encode([
        'status' => 'success',
        'temp_high' => (float) $temp_high,
        'temp_low' => (float) $temp_low,
        'hum_high' => (float) $hum_high,
        'hum_low' => (float) $hum_low
    ]);
} else {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'msg' => 'Thresholds not found']);
}
$stmt->close();
?>
