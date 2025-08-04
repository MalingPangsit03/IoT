<?php
// includes/auth.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

// regenerate session periodically to mitigate fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}
