<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// === AES Decryption Function ===
function decrypt_aes128cbc_base64($base64data, $key, $iv) {
    $cipherText = base64_decode($base64data);
    if ($cipherText === false) {
        return false;
    }

    $decrypted = openssl_decrypt(
        $cipherText,
        'AES-128-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($decrypted === false) {
        return false;
    }

    // Remove PKCS#7 padding
    $pad = ord(substr($decrypted, -1));
    if ($pad > 0 && $pad <= 16) {
        $decrypted = substr($decrypted, 0, -$pad);
    }

    return $decrypted;
}

// === Key and IV (MUST match ESP32) ===
$key = '1234567890abcdef';     // 16 bytes AES key
$iv  = 'abcdef1234567890';     // 16 bytes IV

// === Read and Decrypt the Payload ===
$encryptedBase64 = file_get_contents('php://input');
$rawJson = decrypt_aes128cbc_base64($encryptedBase64, $key, $iv);

if (!$rawJson) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Decryption failed']);
    exit;
}

// Optional logging for debugging
file_put_contents("raw_debug.txt", $rawJson);

// === Parse JSON ===
$data = json_decode($rawJson, true);
if (!is_array($data)) {
    file_put_contents("json_error.txt", json_last_error_msg() . "\nRAW: " . $rawJson);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Invalid JSON']);
    exit;
}

// === Extract Parameters ===
$device_id   = trim($data['device_id'] ?? '');
$device_name = trim($data['device_name'] ?? '');
$temperature = isset($data['temperature']) ? floatval($data['temperature']) : null;
$humidity    = isset($data['humidity']) ? floatval($data['humidity']) : null;
$temp_alert  = isset($data['temp_alert']) ? intval($data['temp_alert']) : 0;
$hum_alert   = isset($data['hum_alert']) ? intval($data['hum_alert']) : 0;
$date        = trim($data['date'] ?? '');
$ip_address  = trim($data['ip_address'] ?? $_SERVER['REMOTE_ADDR']);

if ($device_id === '' || $temperature === null || $humidity === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing required parameters']);
    exit;
}

date_default_timezone_set('Asia/Jakarta');
$date = date('Y-m-d H:i:s'); // Trusted server-side time

// === Anti-Spam: Block too frequent uploads ===
define('MIN_INTERVAL_SECS', 30); // 0 = no cooldown
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

// === Insert or Update Device Info (with alert status) ===
$alert_status = ($temp_alert || $hum_alert) ? 'alert' : 'normal';

$stmt = $mysqli->prepare("
    INSERT INTO device (device_id, device_name, ip_address, created_date, status, alert_status)
    VALUES (?, ?, ?, NOW(), 'active', ?)
    ON DUPLICATE KEY UPDATE 
        device_name = VALUES(device_name),
        ip_address = VALUES(ip_address),
        updated_date = NOW(),
        alert_status = VALUES(alert_status)
");
$stmt->bind_param("ssss", $device_id, $device_name, $ip_address, $alert_status);
$stmt->execute();
$stmt->close();

// === Insert Sensor Reading (with alert flags) ===
$stmt = $mysqli->prepare("
    INSERT INTO data_suhu (device_id, temperature, humidity, temp_alert, hum_alert, date, ip_address)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("sddiiss", $device_id, $temperature, $humidity, $temp_alert, $hum_alert, $date, $ip_address);

if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success',
        'device_id' => $device_id,
        'temperature' => $temperature,
        'humidity' => $humidity,
        'temp_alert' => $temp_alert,
        'hum_alert' => $hum_alert,
        'timestamp' => $date
    ]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => $mysqli->error]);
}
$stmt->close();
?>
