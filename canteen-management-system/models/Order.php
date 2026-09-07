<?php
class Order {
    private $conn;
    private $table = 'orders';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create($userId, $subtotal, $tax, $serviceFee, $total, $orderType = 'takeaway', $instructions = '', $discountAmount = 0, $promoCode = null) {
        $orderNumber = 'ORD-' . strtoupper(substr(uniqid(), -6));
        $stmt = $this->conn->prepare("INSERT INTO {$this->table}
            (order_number, user_id, status, order_type, subtotal, discount_amount, promo_code, tax, service_fee, total_amount, special_instructions)
            VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$orderNumber, $userId, $orderType, $subtotal, $discountAmount, $promoCode, $tax, $serviceFee, $total, $instructions]);
        return $this->conn->lastInsertId();
    }

    public function find($id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByUser($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE user_id = ? ORDER BY order_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recent($limit = 10) {
        $stmt = $this->conn->prepare("SELECT o.*, u.name AS customer_name FROM {$this->table} o
            JOIN users u ON o.user_id = u.id ORDER BY o.order_at DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $status) {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
    }

    public function todayStats() {
        $revenue = $this->conn->query("SELECT COALESCE(SUM(total_amount),0) FROM {$this->table} WHERE DATE(order_at) = CURDATE() AND status <> 'cancelled'")->fetchColumn();
        $totalOrders = $this->conn->query("SELECT COUNT(*) FROM {$this->table} WHERE DATE(order_at) = CURDATE()")->fetchColumn();
        $pending = $this->conn->query("SELECT COUNT(*) FROM {$this->table} WHERE DATE(order_at) = CURDATE() AND status = 'pending'")->fetchColumn();
        return ['revenue' => $revenue, 'total_orders' => $totalOrders, 'pending' => $pending];
    }

    public function mostOrdered($period, $limit = 5) {
        $periodConditions = [
            'today' => 'DATE(o.order_at) = CURDATE()',
            'week' => 'YEARWEEK(o.order_at, 1) = YEARWEEK(CURDATE(), 1)',
        ];
        $condition = $periodConditions[$period] ?? $periodConditions['today'];
        $limit = max(1, (int)$limit);
        $stmt = $this->conn->prepare("SELECT mi.name, SUM(oi.quantity) AS quantity_ordered,
                SUM(oi.total_price) AS sales_amount
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN menu_items mi ON oi.menu_item_id = mi.id
                WHERE {$condition} AND o.status <> 'cancelled'
                GROUP BY mi.id, mi.name
                ORDER BY quantity_ordered DESC, sales_amount DESC
                LIMIT {$limit}");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

class OrderItem {
    private $conn;
    private $table = 'order_items';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create($orderId, $menuItemId, $unitPrice, $qty) {
        $total = $unitPrice * $qty;
        $stmt = $this->conn->prepare("INSERT INTO {$this->table}
            (order_id, menu_item_id, unit_price, quantity, total_price) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$orderId, $menuItemId, $unitPrice, $qty, $total]);
        return $this->conn->lastInsertId();
    }

    public function findByOrder($orderId) {
        $stmt = $this->conn->prepare("SELECT oi.*, mi.name, mi.image FROM {$this->table} oi
            JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
