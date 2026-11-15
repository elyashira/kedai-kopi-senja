<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_role($required_role) {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== $required_role) {
        header('Location: ' . BASEURL . '/index.php');
        exit;
    }
}