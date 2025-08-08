<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$device_id = $_GET['device_id'] ?? '';
if ($device_id === '') {
    echo json_encode(['status' => 'error', 'msg' => 'Missing device_id']);
    exit;
}

$stmt = $mysqli->prepare("SELECT alert_status FROM device WHERE device_id = ?");
$stmt->bind_param("s", $device_id);
$stmt->execute();
$stmt->bind_result($alert_status);
if ($stmt->fetch()) {
    echo json_encode(['status' => 'success', 'alert_status' => $alert_status]);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Device not found']);
}
$stmt->close();
?>
