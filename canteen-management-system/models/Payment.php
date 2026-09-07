<?php
class Payment {
    private $conn;
    private $table = 'payments';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create($orderId, $method, $amount) {
        $stmt = $this->conn->prepare("INSERT INTO {$this->table} (order_id, method, status, amount) VALUES (?, ?, 'pending', ?)");
        $stmt->execute([$orderId, $method, $amount]);
        return $this->conn->lastInsertId();
    }

    public function markSuccess($id, $orderId) {
        $ref = 'TXN' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $this->conn->prepare("UPDATE {$this->table}
            SET status = 'success', transaction_ref = ?, paid_at = NOW()
            WHERE id = ? AND order_id = ? AND status = 'pending'");
        $stmt->execute([$ref, $id, $orderId]);
        return $stmt->rowCount() === 1 ? $ref : false;
    }

    public function findByOrder($orderId) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
