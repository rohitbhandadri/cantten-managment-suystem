<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/CartController.php';
require_once __DIR__ . '/../../controllers/OrderController.php';
require_once __DIR__ . '/../../controllers/PaymentController.php';
require_once __DIR__ . '/../../models/Promo.php';
requireLogin();

$database = new Database();
$db = $database->connect();
$cart = new CartController();
$orderController = new OrderController($db);
$paymentController = new PaymentController($db);
$promo = new Promo($db);
require_once __DIR__ . '/../../models/StaffRating.php';
$staffRatingModel = new StaffRating($db);

$items = $cart->items();
if (empty($items)) {
    redirect('views/customer/cart.php');
}

$subtotal = $cart->subtotal();
$promoCode = trim($_SESSION['checkout_promo_code'] ?? '');
$promoClaim = $promoCode ? $promo->findValidForUser($_SESSION['user_id'], $promoCode) : null;
$discountAmount = $promoClaim ? round($subtotal * ((int)$promoClaim['discount_percent'] / 100), 2) : 0;
$taxableSubtotal = $subtotal - $discountAmount;
$tax = round($taxableSubtotal * 0.10, 2);
$serviceFee = 1.00;
$total = $taxableSubtotal + $tax + $serviceFee;

$step = $_SESSION['checkout_order_id'] ?? null ? 'verify' : 'select';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['place_order'])) {
        // Step 1: create order + payment record, "send OTP"
        $method = $_POST['method'] ?? 'esewa';
        $promoCode = strtoupper(trim($_POST['promo_code'] ?? ''));
        $promoClaim = $promoCode ? $promo->findValidForUser($_SESSION['user_id'], $promoCode) : null;
        if ($promoCode && !$promoClaim) {
            $error = 'That promo code is invalid, expired, or has already been used.';
            $step = 'select';
        } else {
            $discountAmount = $promoClaim ? round($subtotal * ((int)$promoClaim['discount_percent'] / 100), 2) : 0;
            $_SESSION['checkout_promo_code'] = $promoClaim['promo_code'] ?? '';
            $result = $orderController->placeOrder($_SESSION['user_id'], $items, 'takeaway', '', $discountAmount, $promoClaim['promo_code'] ?? null);
        }
        if (isset($result) && $result['success']) {
            $paymentId = $paymentController->initiate($result['order_id'], $method, $result['total']);
            $_SESSION['checkout_order_id'] = $result['order_id'];
            $_SESSION['checkout_payment_id'] = $paymentId;
            $_SESSION['checkout_otp'] = strval(random_int(1000, 9999)); // simulated OTP
            $step = 'verify';
        } elseif (isset($result)) {
            $error = $result['message'];
        }
    } elseif (isset($_POST['verify_otp'])) {
        // Step 2: verify OTP and confirm payment
        $entered = trim($_POST['otp'] ?? '');
        if ($entered === $_SESSION['checkout_otp']) {
            $paymentReference = $paymentController->confirm($_SESSION['checkout_payment_id'], $_SESSION['checkout_order_id']);
            if (!$paymentReference) {
                $error = 'This payment session is invalid or has already been completed.';
                $step = 'verify';
            } else {
                $orderId = $_SESSION['checkout_order_id'];
                $cart->clear();
                unset($_SESSION['checkout_order_id'], $_SESSION['checkout_payment_id'], $_SESSION['checkout_otp'], $_SESSION['checkout_promo_code']);

                $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
                $rating = isset($_POST['staff_rating']) ? (int)$_POST['staff_rating'] : 0;
                $comment = trim((string)($_POST['staff_comment'] ?? ''));

                if ($staffId > 0 && $rating >= 1 && $rating <= 5) {
                    $ratingId = $staffRatingModel->add($staffId, $_SESSION['user_id'], $orderId, $rating, $comment);

                    $perfColumnExists = (bool)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'canteen_db' AND table_name = 'staff_management' AND column_name = 'performance_rating'")->fetchColumn();
                    $countColumnExists = (bool)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'canteen_db' AND table_name = 'staff_management' AND column_name = 'rating_count'")->fetchColumn();

                    if ($ratingId && $perfColumnExists && $countColumnExists) {
                        $stmt = $db->prepare("SELECT performance_rating, rating_count FROM staff_management WHERE id = ?");
                        $stmt->execute([$staffId]);
                        $staffRow = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($staffRow) {
                            $newCount = (int)$staffRow['rating_count'] + 1;
                            $newAverage = ((float)$staffRow['performance_rating'] * (int)$staffRow['rating_count'] + $rating) / $newCount;
                            $bonus = $newAverage >= 4.5 ? 200 : ($newAverage >= 4.0 ? 100 : 0);
                            $db->prepare("UPDATE staff_management SET performance_rating = ?, rating_count = ?, staff_salary = staff_salary + ? WHERE id = ?")
                                ->execute([$newAverage, $newCount, $bonus, $staffId]);
                        }
                    }
                }

                redirect('views/customer/order_tracking.php?id=' . $orderId);
            }
        } else {
            $error = 'Incorrect OTP. Please try again.';
            $step = 'verify';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Checkout - CanteenPro</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body>
<header class="top-nav">
    <a href="<?= BASE_URL ?>/views/customer/cart.php" class="brand">&larr; CanteenPro</a>
    <div class="secure-badge">🔒 Secure Checkout</div>
</header>

<main class="container">
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="checkout-layout">
        <div>
            <div class="card">
                <h3>Order Summary</h3>
                <?php foreach ($items as $item): ?>
                    <div class="row-between summary-line">
                        <span><?= e($item['name']) ?> <span class="muted">x<?= $item['qty'] ?></span></span>
                        <span>$<?= number_format($item['price'] * $item['qty'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
                <hr>
                <div class="row-between"><span>Subtotal</span><span>$<?= number_format($subtotal, 2) ?></span></div>
                <?php if ($promoClaim): ?><div class="row-between discount-row"><span>Promo (<?= e($promoClaim['promo_code']) ?>)</span><span>- $<?= number_format($discountAmount, 2) ?></span></div><?php endif; ?>
                <div class="row-between"><span>Tax</span><span>$<?= number_format($tax, 2) ?></span></div>
                <div class="row-between total-row"><strong>Total</strong><strong>$<?= number_format($total, 2) ?></strong></div>
            </div>

            <?php if ($step === 'select'): ?>
            <form method="POST" class="card">
                <h3>Select Payment Method</h3>
                <label for="promo-code">Promo Code</label>
                <div class="promo-input-row">
                    <input type="text" id="promo-code" name="promo_code" value="<?= e($promoCode) ?>" placeholder="CUSTOMER-HEALTHY-LUNCH-25OFF">
                </div>
                <p class="muted small">Enter the code you claimed from CanteenPro.</p>
                <div class="payment-methods">
                    <label class="payment-option">
                        <input type="radio" name="method" value="esewa" checked>
                        <span>eSewa</span>
                    </label>
                    <label class="payment-option">
                        <input type="radio" name="method" value="khalti">
                        <span>Khalti</span>
                    </label>
                    <label class="payment-option">
                        <input type="radio" name="method" value="bank_transfer">
                        <span>Bank Transfer</span>
                    </label>
                </div>
                <button type="submit" name="place_order" class="btn-primary btn-block">Continue to Verification</button>
            </form>
            <?php endif; ?>
        </div>

        <div>
            <?php if ($step === 'verify'): ?>
            <form method="POST">
                <div class="card staff-feedback-card">
                    <div class="section-header">
                        <h3>⭐ Staff Feedback</h3>
                        <span class="mini-tag">Service review</span>
                    </div>

                    <div class="form-group">
                        <label for="staff_id">Staff member on duty</label>
                        <select name="staff_id" id="staff_id" required>
                            <option value="">Select staff</option>
                            <?php
                            $staffList = $db->query("SELECT id, staff_name FROM staff_management WHERE staff_status = 'on_duty' ORDER BY staff_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($staffList as $staff): ?>
                                <option value="<?= (int)$staff['id'] ?>"><?= e($staff['staff_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="staff_rating">Customer rating</label>
                        <select name="staff_rating" id="staff_rating" required>
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Very Good</option>
                            <option value="3">3 - Good</option>
                            <option value="2">2 - Fair</option>
                            <option value="1">1 - Poor</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="staff_comment">Comment</label>
                        <textarea name="staff_comment" id="staff_comment" rows="3" placeholder="Leave a short comment about the staff service..."></textarea>
                    </div>
                </div>

                <div class="card verify-card">
                    <div class="section-header">
                        <h3>🛡 Verification</h3>
                        <span class="mini-tag">Secure payment</span>
                    </div>
                    <p class="muted small otp-text">Enter the 4-digit OTP sent to your registered mobile number to confirm payment.
                    <br><em>(Demo OTP: <?= e($_SESSION['checkout_otp']) ?>)</em></p>

                    <div class="form-group">
                        <input type="text" name="otp" maxlength="4" placeholder="••••" class="otp-input" required>
                    </div>

                    <button type="submit" name="verify_otp" class="btn-primary btn-block">✔ Pay Now</button>
                </div>
            </form>
            <?php endif; ?>
            <p class="muted small center">🔒 256-bit Secure Encryption</p>
        </div>
    </div>
</main>
</body>
</html>
