<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/OrderController.php';
requireStaff();

$database = new Database();
$db = $database->connect();
$orderController = new OrderController($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $allowedStatuses = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];
    $status = $_POST['status'];

    if (in_array($status, $allowedStatuses, true)) {
        $orderController->updateStatus((int)$_POST['order_id'], $status);
    }

    redirect('views/staff/dashboard.php');
}

$orders = $orderController->recent(50);
$todayStats = $orderController->todayStats();
$activeOrders = count(array_filter($orders, static function ($order) {
    return in_array($order['status'], ['pending', 'preparing', 'ready'], true);
}));
$preparingOrders = count(array_filter($orders, static function ($order) {
    return $order['status'] === 'preparing';
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Staff Dashboard - CanteenPro</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body class="admin-body">
<?php include __DIR__ . '/../partials/header_staff.php'; ?>

<main class="admin-main">
    <header class="admin-topbar">
        <div class="row-between">
            <div>
                <h1>Staff dashboard</h1>
                <p class="muted">Keep today's orders moving from kitchen to collection.</p>
            </div>
            <span class="status-pill status-ready">Live queue</span>
        </div>
    </header>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="muted small">Today's revenue</span>
            <h2>$<?= number_format((float)$todayStats['revenue'], 2) ?></h2>
            <span class="muted small">Completed and active orders</span>
        </div>
        <div class="stat-card">
            <span class="muted small">Today's orders</span>
            <h2><?= (int)$todayStats['total_orders'] ?></h2>
            <span class="muted small">All orders received today</span>
        </div>
        <div class="stat-card">
            <span class="muted small">Pending orders</span>
            <h2><?= (int)$todayStats['pending'] ?></h2>
            <span class="muted small">Waiting to be started</span>
        </div>
        <div class="stat-card">
            <span class="muted small">Active queue</span>
            <h2><?= $activeOrders ?></h2>
            <span class="muted small"><?= $preparingOrders ?> currently preparing</span>
        </div>
    </div>

    <div class="staff-toolbar">
        <div>
            <h2>Incoming orders</h2>
            <p class="muted small">Update each order as it moves through preparation.</p>
        </div>
        <span class="muted small">Showing latest <?= count($orders) ?></span>
    </div>

    <div class="card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Amount</th>
                    <th>Placed</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td>#<?= e($order['order_number']) ?></td>
                    <td><?= e($order['customer_name']) ?></td>
                    <td>$<?= number_format((float)$order['total_amount'], 2) ?></td>
                    <td><?= date('M d, g:i A', strtotime($order['order_at'])) ?></td>
                    <td><span class="status-pill status-<?= e($order['status']) ?>"><?= ucfirst(e($order['status'])) ?></span></td>
                    <td>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                            <select name="status" onchange="this.form.submit()">
                                <?php foreach (['pending', 'preparing', 'ready', 'completed', 'cancelled'] as $status): ?>
                                    <option value="<?= $status ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
                <tr><td colspan="6" class="muted center">No orders yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
