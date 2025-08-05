<?php
// AES key and IV (must match ESP32)
$key = "1234567890abcdef"; // 16 bytes
$iv  = "abcdef1234567890"; // 16 bytes

// Paste your ESP32 Base64-encrypted string here:
$base64Data = "XZoFfirRndsUlWjYrIRzWg==";

// Step 1: Decode Base64
$ciphertext = base64_decode($base64Data);
if ($ciphertext === false) {
    die("Base64 decode failed\n");
}

// Step 2: Decrypt with AES-128-CBC
$decrypted = openssl_decrypt($ciphertext, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

if ($decrypted === false) {
    echo "Decryption failed\n";
} else {
    echo "Decrypted text: $decrypted\n";
}
