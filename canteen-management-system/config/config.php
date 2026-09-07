<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
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
    return isLoggedIn() && ($_SESSION['role'] === 'admin' || staffRoleIs(['manager', 'admin']));
}

function isStaff() {
    return isLoggedIn() && $_SESSION['role'] === 'staff';
}

function normalizeStaffRole($role) {
    $role = strtolower(trim((string)$role));
    $role = str_replace(['_', '-'], ' ', $role);
    $aliases = [
        'chef' => 'cook',
        'service staff' => 'waiter',
        'server' => 'waiter',
        'stock' => 'inventory',
        'stock manager' => 'inventory',
        'inventory manager' => 'inventory',
        'accountant' => 'finance',
    ];
    return $aliases[$role] ?? $role;
}

function currentStaffRole() {
    return normalizeStaffRole($_SESSION['staff_role'] ?? '');
}

function staffRoleIs($roles) {
    if (!isStaff()) {
        return false;
    }
    $roles = array_map('normalizeStaffRole', (array)$roles);
    return in_array(currentStaffRole(), $roles, true);
}

function staffHasRole($roles) {
    return staffRoleIs(['manager', 'admin']) || staffRoleIs($roles);
}

function staffDashboardPath() {
    if (isAdmin()) {
        return 'views/admin/dashboard.php';
    }
    $paths = [
        'cashier' => 'views/staff/cashier.php',
        'cook' => 'views/staff/chef.php',
        'waiter' => 'views/staff/waiter.php',
        'inventory' => 'views/staff/inventory.php',
        'finance' => 'views/staff/finance.php',
    ];
    return $paths[currentStaffRole()] ?? 'views/staff/dashboard.php';
}

function requireLogin() {
    if (!isLoggedIn()) redirect('login.php');
}

function requireAdmin() {
    if (!isAdmin()) redirect(isStaff() ? 'views/staff/dashboard.php' : 'login.php');
}

function requireStaff() {
    if (!isStaff()) redirect('staff_login.php');
}

function requireStaffRole($roles) {
    requireStaff();
    if (!staffHasRole($roles)) {
        redirect('views/staff/dashboard.php');
    }
}

function requireCustomer() {
    if (!isLoggedIn() || ($_SESSION['role'] ?? '') !== 'customer') {
        redirect(isStaff() || isAdmin() ? staffDashboardPath() : 'login.php');
    }
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
