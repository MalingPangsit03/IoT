<?php
// includes/functions.php

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function is_admin() {
    return (isset($_SESSION['level']) && $_SESSION['level'] === 'admin');
}
