<?php
// config/db.php
$host = 'localhost';
$db   = 'suhu_ruang'; // or your actual db name
$user = 'root';
$pass = ''; // set if you have password

$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_errno) {
    die("DB connection failed: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");
