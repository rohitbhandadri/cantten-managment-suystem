<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$baseUrl = preg_replace('#/views(?:/[^/]+){1,2}$#', '', $scriptDirectory);
define('BASE_URL', $baseUrl === '/' ? '' : rtrim($baseUrl, '/'));

function redirect($path) {
    header("Location: " . BASE_URL . "/" . ltrim($path, '/'));
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'admin';
}

function isStaff() {
    return isLoggedIn() && $_SESSION['role'] === 'staff';
}

function requireLogin() {
    if (!isLoggedIn()) redirect('login.php');
}

function requireAdmin() {
    if (!isAdmin()) redirect('login.php');
}

function requireStaff() {
    if (!isStaff()) redirect('staff_login.php');
}

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function flash($key, $msg = null) {
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $val = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $val;
    }
    return null;
}
