<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/OrderController.php';
requireLogin();

$database = new Database();
$db = $database->connect();
$orderController = new OrderController($db);
$order = $orderController->getOrder((int)($_GET['id'] ?? 0));

if (!$order || $order['user_id'] != $_SESSION['user_id']) {
    redirect('views/customer/orders.php');
}

$statusSteps = ['pending' => 1, 'preparing' => 2, 'ready' => 3, 'completed' => 4];
$currentStep = $statusSteps[$order['status']] ?? 1;
$statusLabel = ['pending' => 'Order Received', 'preparing' => 'Preparing Your Order', 'ready' => 'Ready for Pickup', 'completed' => 'Completed', 'cancelled' => 'Order Cancelled'];
$isCancelled = $order['status'] === 'cancelled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Order Tracking - CanteenPro</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../partials/header_customer.php'; ?>

<main class="container narrow">
    <p class="muted">Order Tracking</p>
    <h1>Order #<?= e($order['order_number']) ?></h1>

    <div class="status-card <?= $isCancelled ? 'cancelled' : '' ?>">
        <div class="status-dot"></div>
        <div>
            <span class="badge-status <?= $isCancelled ? 'sold-out' : 'available' ?>">Current Status</span>
            <h2><?= e($statusLabel[$order['status']]) ?></h2>
            <p class="muted">Total: $<?= number_format($order['total_amount'], 2) ?></p>
        </div>
    </div>

    <?php if ($isCancelled): ?>
        <div class="card cancelled-message">
            <h3>This order was cancelled</h3>
            <p class="muted">The cancelled order remains in your order history for reference.</p>
        </div>
    <?php else: ?>
    <div class="card">
        <h3>Order Progress</h3>
        <div class="progress-track">
            <?php
            $steps = ['Received', 'Preparing', 'Ready', 'Completed'];
            foreach ($steps as $i => $label):
                $stepNum = $i + 1;
                $stateClass = $stepNum < $currentStep ? 'done' : ($stepNum == $currentStep ? 'active' : 'pending');
            ?>
                <div class="progress-step <?= $stateClass ?>">
                    <div class="progress-circle"><?= $stepNum < $currentStep ? '✓' : $stepNum ?></div>
                    <div><?= $label ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <a href="<?= BASE_URL ?>/views/customer/orders.php" class="link">&larr; Back to Orders</a>
</main>
</body>
</html>
