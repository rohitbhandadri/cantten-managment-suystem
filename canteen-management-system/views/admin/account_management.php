<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/User.php';
requireAdmin();

$database = new Database();
$db = $database->connect();
$userModel = new User($db);

$roleFilter = $_GET['role'] ?? 'all';
if (!in_array($roleFilter, ['all', 'customer', 'staff'], true)) {
    $roleFilter = 'all';
}

$accounts = $userModel->listActiveAccounts($roleFilter);
$totalAccounts = count($userModel->listActiveAccounts());
$totalCustomers = count($userModel->listActiveAccounts('customer'));
$totalStaff = count($userModel->listActiveAccounts('staff'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Account Management - CanteenPro</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body class="admin-body">
<?php include __DIR__ . '/../partials/header_admin.php'; ?>

<main class="admin-main">
    <header class="admin-topbar">
        <div class="row-between">
            <div>
                <h1>Account Management</h1>
                <p class="muted">View all active customer and staff accounts.</p>
            </div>
            <a href="<?= BASE_URL ?>/views/admin/staff_management.php" class="btn-primary btn-small">+ Create Staff Account</a>
        </div>
    </header>

    <div class="stat-grid three">
        <div class="stat-card">
            <span class="muted small">Active accounts</span>
            <h2><?= $totalAccounts ?></h2>
        </div>
        <div class="stat-card">
            <span class="muted small">Customer accounts</span>
            <h2><?= $totalCustomers ?></h2>
        </div>
        <div class="stat-card">
            <span class="muted small">Staff accounts</span>
            <h2><?= $totalStaff ?></h2>
        </div>
    </div>

    <div class="card">
        <div class="staff-toolbar">
            <div>
                <h2>Active user accounts</h2>
                <p class="muted small">Staff accounts are created and managed from Staff Accounts.</p>
            </div>
            <form method="GET" class="staff-filter-bar">
                <select name="role" aria-label="Filter accounts by role">
                    <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>All accounts</option>
                    <option value="customer" <?= $roleFilter === 'customer' ? 'selected' : '' ?>>Customers</option>
                    <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Staff</option>
                </select>
                <button type="submit" class="btn-primary btn-small">Filter</button>
            </form>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Login ID</th>
                    <th>Email</th>
                    <th>Account Type</th>
                    <th>Account Status</th>
                    <th>Last Login</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $account): ?>
                <?php
                $isStaff = $account['role'] === 'staff';
                $status = $account['status'] ?: 'active';
                $statusLabel = $isStaff ? ($status === 'on_leave' ? 'On Leave' : 'On Duty') : 'Active';
                $statusClass = $status === 'on_leave' ? 'status-pending' : 'status-confirmed';
                ?>
                <tr>
                    <td>
                        <strong><?= e($account['name']) ?></strong>
                        <?php if ($isStaff && !empty($account['staff_role'])): ?>
                            <div class="muted small"><?= e($account['staff_role']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($account['username'] ?: $account['email']) ?></td>
                    <td><?= e($account['email']) ?></td>
                    <td><?= $isStaff ? 'Staff' : 'Customer' ?></td>
                    <td><span class="status-pill <?= $statusClass ?>"><?= e($statusLabel) ?></span></td>
                    <td><?= $account['last_login_at'] ? date('M d, Y g:i A', strtotime($account['last_login_at'])) : 'Never' ?></td>
                    <td><?= date('M d, Y', strtotime($account['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($accounts)): ?>
                <tr><td colspan="7" class="muted center">No active accounts found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
