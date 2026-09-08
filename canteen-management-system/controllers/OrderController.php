<?php
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/MenuItem.php';
require_once __DIR__ . '/../models/Ingredient.php';

class OrderController {
    private $conn;
    private $orderModel;
    private $orderItemModel;
    private $menuItemModel;
    private $ingredientModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->orderModel = new Order($db);
        $this->orderItemModel = new OrderItem($db);
        $this->menuItemModel = new MenuItem($db);
        $this->ingredientModel = new Ingredient($db);
    }

    public function placeOrder($userId, $cartItems, $orderType = 'takeaway', $instructions = '', $discountAmount = 0, $promoCode = null, $tableNumber = null) {
        requireCustomer();
        if (empty($cartItems)) {
            return ['success' => false, 'message' => 'Cart is empty.'];
        }
        $this->conn->beginTransaction();
        try {
            $validatedItems = [];
            $subtotal = 0;
            foreach ($cartItems as $item) {
                $itemId = (int)($item['id'] ?? 0);
                $quantity = (int)($item['qty'] ?? 0);
                if ($itemId <= 0 || $quantity <= 0) {
                    throw new RuntimeException('Invalid item quantity.');
                }

                $stmt = $this->conn->prepare("SELECT id, name, price, image, current_stock, is_active
                    FROM menu_items WHERE id = ? FOR UPDATE");
                $stmt->execute([$itemId]);
                $menuItem = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$menuItem || (int)$menuItem['is_active'] !== 1) {
                    throw new RuntimeException('One of the selected items is no longer available.');
                }
                if ((int)$menuItem['current_stock'] < $quantity) {
                    throw new RuntimeException($menuItem['name'] . ' does not have enough stock.');
                }

                $validatedItems[] = [
                    'id' => (int)$menuItem['id'],
                    'name' => $menuItem['name'],
                    'price' => (float)$menuItem['price'],
                    'image' => $menuItem['image'],
                    'qty' => $quantity,
                ];
                $subtotal += (float)$menuItem['price'] * $quantity;
            }

            $discountAmount = min(max(0, (float)$discountAmount), $subtotal);
            $taxableSubtotal = $subtotal - $discountAmount;
            $tax = round($taxableSubtotal * 0.10, 2);
            $serviceFee = 1.00;
            $total = $taxableSubtotal + $tax + $serviceFee;
            $orderId = $this->orderModel->create($userId, $subtotal, $tax, $serviceFee, $total, $orderType, $instructions, $discountAmount, $promoCode, $tableNumber);
            $this->ingredientModel->reserveForOrder($validatedItems, $orderId);
            foreach ($validatedItems as $item) {
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
        requireCustomer();
        return $this->orderModel->find($id);
    }

    public function getItems($orderId) {
        requireCustomer();
        return $this->orderItemModel->findByOrder($orderId);
    }

    public function myOrders($userId) {
        requireCustomer();
        return $this->orderModel->findByUser($userId);
    }

    public function recent($limit = 10) {
        requireAdmin();
        return $this->orderModel->recent($limit);
    }

    public function updateStatus($id, $status) {
        requireAdmin();
        $this->orderModel->updateStatus($id, $status);
    }

    public function todayStats() {
        requireAdmin();
        return $this->orderModel->todayStats();
    }
}
