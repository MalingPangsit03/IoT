<?php
// public/upload_data.php
header('Content-Type: application/json');

// Load DB connection
require_once __DIR__ . '/../config/db.php';

// === AES DECRYPTION FUNCTION ===
function decryptAES($base64Data) {
    $key = "1234567890abcdef";
    $iv  = "abcdef1234567890";
    $ciphertext = base64_decode($base64Data);
    if ($ciphertext === false) return false;

    $decrypted = openssl_decrypt($ciphertext, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) return false;

    // Trim to remove PKCS#7 padding bytes (if needed)
    return rtrim($decrypted, "\x00..\x1F");  // Removes control chars
}


// === 1. GET RAW JSON INPUT ===
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

// If not JSON, try fallback to form POST (for testing)
if (!isset($input['data']) && isset($_POST['data'])) {
    $input = ['data' => $_POST['data']];
}

if (!isset($input['data'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'No encrypted data received']);
    exit;
}

// === 2. DECRYPT DATA ===
$decryptedJson = decryptAES($input['data']);
if ($decryptedJson === false || empty($decryptedJson)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Base64 decode or AES decryption failed']);
    exit;
}

// === 3. PARSE JSON ===
$data = json_decode($decryptedJson, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Invalid JSON after decryption']);
    exit;
}

// === 4. EXTRACT PARAMETERS ===
$device_id   = trim($data['device_id'] ?? '');
$device_name = trim($data['device_name'] ?? '');
$temperature = isset($data['temperature']) ? floatval($data['temperature']) : null;
$humidity    = isset($data['humidity']) ? floatval($data['humidity']) : null;
$date        = trim($data['date'] ?? '');
$ip_address  = trim($data['ip_address'] ?? $_SERVER['REMOTE_ADDR']);

// === 5. VALIDATE PARAMETERS ===
if ($device_id === '' || $temperature === null || $humidity === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing required parameters']);
    exit;
}

// Use Jakarta time if no date provided
if ($date === '') {
    date_default_timezone_set('Asia/Jakarta');
    $date = date('Y-m-d H:i:s');
}

// === 6. ANTI-SPAM: Prevent rapid re-uploading ===
define('MIN_INTERVAL_SECS', 30);
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

// === 7. INSERT OR UPDATE DEVICE INFO ===
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

// === 8. INSERT SENSOR DATA ===
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
