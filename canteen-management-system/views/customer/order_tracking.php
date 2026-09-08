<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/OrderController.php';
require_once __DIR__ . '/../../models/StaffRating.php';
requireCustomer();

$database = new Database();
$db = $database->connect();
$orderController = new OrderController($db);
$staffRatingModel = new StaffRating($db);
$activeStaffStmt = $db->prepare("SELECT id, staff_name, staff_role FROM staff_management WHERE is_active = 1 AND deleted_at IS NULL AND staff_status <> 'removed' ORDER BY staff_name");
$activeStaffStmt->execute();
$activeStaff = $activeStaffStmt->fetchAll(PDO::FETCH_ASSOC);
$order = $orderController->getOrder((int)($_GET['id'] ?? 0));

if (!$order || $order['user_id'] != $_SESSION['user_id']) {
    redirect('views/customer/orders.php');
}

$reviewMessage = '';
if (empty($_SESSION['review_csrf_token'])) {
    $_SESSION['review_csrf_token'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!hash_equals($_SESSION['review_csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $reviewMessage = 'Your review form expired. Please try again.';
    } elseif ($order['status'] !== 'completed') {
        $reviewMessage = 'This order is not ready for a review yet.';
    } elseif ($staffRatingModel->add((int)($_POST['staff_id'] ?? $order['served_by_staff_id']), (int)$_SESSION['user_id'], (int)$order['id'], (int)($_POST['rating'] ?? 0), $_POST['comment'] ?? '')) {
        $reviewMessage = 'Thanks for reviewing the staff service.';
    } else {
        $reviewMessage = 'This order has already been reviewed or the review is invalid.';
    }
}

$statusSteps = ['pending' => 1, 'preparing' => 2, 'ready' => 3, 'completed' => 4];
$currentStep = $statusSteps[$order['status']] ?? 1;
$statusLabel = ['pending' => 'New', 'preparing' => 'Cooking', 'ready' => 'Ready', 'completed' => 'Served', 'cancelled' => 'Order Cancelled'];
$isCancelled = $order['status'] === 'cancelled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?php if (!in_array($order['status'], ['completed', 'cancelled'], true)): ?><meta http-equiv="refresh" content="10"><?php endif; ?>
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

    <?php if ($order['status'] === 'completed'): ?>
        <div class="card review-form-card">
            <h3>Rate your service</h3>
            <?php if ($reviewMessage): ?><div class="alert alert-success"><?= e($reviewMessage) ?></div><?php endif; ?>
            <form method="POST" class="grid-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['review_csrf_token']) ?>">
                <div><label for="rating">Rating</label><select name="rating" id="rating" required><option value="5">5 - Excellent</option><option value="4">4 - Very good</option><option value="3">3 - Good</option><option value="2">2 - Fair</option><option value="1">1 - Poor</option></select></div>
                <div><label for="staff_id">Staff member</label><select name="staff_id" id="staff_id" required><?php foreach ($activeStaff as $staffMember): ?><option value="<?= (int)$staffMember['id'] ?>" <?= (int)$staffMember['id'] === (int)$order['served_by_staff_id'] ? 'selected' : '' ?>><?= e($staffMember['staff_name']) ?> (<?= e($staffMember['staff_role']) ?>)</option><?php endforeach; ?></select></div>
                <div class="full-width"><label for="comment">Comment</label><textarea name="comment" id="comment" rows="3" maxlength="1000" placeholder="Tell us about the service..."></textarea></div>
                <div><button class="btn-primary" name="submit_review">Submit review</button></div>
            </form>
        </div>
    <?php endif; ?>

    <a href="<?= BASE_URL ?>/views/customer/orders.php" class="link">&larr; Back to Orders</a>
</main>
</body>
</html>
