<?php
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/MenuItem.php';

class OrderController {
    private $conn;
    private $orderModel;
    private $orderItemModel;
    private $menuItemModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->orderModel = new Order($db);
        $this->orderItemModel = new OrderItem($db);
        $this->menuItemModel = new MenuItem($db);
    }

    public function placeOrder($userId, $cartItems, $orderType = 'takeaway', $instructions = '', $discountAmount = 0, $promoCode = null) {
        if (empty($cartItems)) {
            return ['success' => false, 'message' => 'Cart is empty.'];
        }
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $subtotal += $item['price'] * $item['qty'];
        }
        $discountAmount = min(max(0, (float)$discountAmount), $subtotal);
        $taxableSubtotal = $subtotal - $discountAmount;
        $tax = round($taxableSubtotal * 0.10, 2);
        $serviceFee = 1.00;
        $total = $taxableSubtotal + $tax + $serviceFee;

        $this->conn->beginTransaction();
        try {
            $orderId = $this->orderModel->create($userId, $subtotal, $tax, $serviceFee, $total, $orderType, $instructions, $discountAmount, $promoCode);
            foreach ($cartItems as $item) {
                $this->orderItemModel->create($orderId, $item['id'], $item['price'], $item['qty']);
                $this->menuItemModel->reduceStock($item['id'], $item['qty']);
            }
            $this->conn->commit();
            return ['success' => true, 'order_id' => $orderId, 'total' => $total];
        } catch (Exception $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getOrder($id) {
        return $this->orderModel->find($id);
    }

    public function getItems($orderId) {
        return $this->orderItemModel->findByOrder($orderId);
    }

    public function myOrders($userId) {
        return $this->orderModel->findByUser($userId);
    }

    public function recent($limit = 10) {
        return $this->orderModel->recent($limit);
    }

    public function updateStatus($id, $status) {
        $this->orderModel->updateStatus($id, $status);
    }

    public function todayStats() {
        return $this->orderModel->todayStats();
    }
}
