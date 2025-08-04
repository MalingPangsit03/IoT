<?php
$mysqli = new mysqli('localhost', 'root', '', 'suhu_ruang'); // adjust credentials
if ($mysqli->connect_error) die("DB error: " . $mysqli->connect_error);

$result = $mysqli->query("SELECT id, password FROM user");
while ($row = $result->fetch_assoc()) {
    $id = $row['id'];
    $plain = $row['password'];

    // Skip if already hashed (bcrypt hashes start with $2y$ or $2b$)
    if (str_starts_with($plain, '$2y$') || str_starts_with($plain, '$2b$')) {
        continue;
    }

    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare("UPDATE user SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hash, $id);
    $stmt->execute();
    $stmt->close();

    echo "User ID {$id} password migrated.<br>";
}
$mysqli->close();
