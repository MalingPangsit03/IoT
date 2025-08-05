<?php
// includes/functions.php

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!isset($_SESSION['user_id']) || isset($_SESSION['pending_user_id'])) {
        header('Location: login.php');
        exit;
    }
}


function getEmailByUsername($username) {
    global $mysqli;

    $stmt = $mysqli->prepare("SELECT email FROM user WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($email);
    
    if ($stmt->fetch()) {
        $stmt->close();
        return $email;
    }

    error_log("No email found for username: $username");
    $stmt->close();
    return false;
}



function is_admin() {
    return (isset($_SESSION['level']) && $_SESSION['level'] === 'admin');
}
