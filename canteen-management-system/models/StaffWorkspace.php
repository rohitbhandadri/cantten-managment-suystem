<?php
class StaffWorkspace {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureSchema();
    }

    private function hasColumn($table, $column) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    private function ensureSchema() {
        if (!$this->hasColumn('staff_management', 'department')) {
            $this->conn->exec("ALTER TABLE staff_management ADD COLUMN department VARCHAR(80) NOT NULL DEFAULT 'Operations' AFTER staff_role");
        }
        if (!$this->hasColumn('orders', 'table_number')) {
            $this->conn->exec("ALTER TABLE orders ADD COLUMN table_number VARCHAR(20) DEFAULT NULL AFTER special_instructions");
        }
        if (!$this->hasColumn('orders', 'served_by_staff_id')) {
            $this->conn->exec("ALTER TABLE orders ADD COLUMN served_by_staff_id INT DEFAULT NULL AFTER table_number");
        }

        $this->conn->exec("CREATE TABLE IF NOT EXISTS staff_tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id INT NOT NULL,
            title VARCHAR(180) NOT NULL,
            due_at DATETIME NULL,
            is_done TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (staff_id) REFERENCES staff_management(id) ON DELETE CASCADE
        )");
        $this->conn->exec("CREATE TABLE IF NOT EXISTS receiving_deliveries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(150) NOT NULL,
            items_received VARCHAR(255) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            status ENUM('pending','received') NOT NULL DEFAULT 'pending',
            received_by_staff_id INT DEFAULT NULL,
            received_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (received_by_staff_id) REFERENCES staff_management(id) ON DELETE SET NULL
        )");
        $this->conn->exec("CREATE TABLE IF NOT EXISTS operating_costs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(100) NOT NULL,
            department VARCHAR(80) NOT NULL DEFAULT 'Operations',
            amount DECIMAL(10,2) NOT NULL,
            cost_date DATE NOT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $this->conn->exec("CREATE TABLE IF NOT EXISTS cashier_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL UNIQUE,
            cashier_staff_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method ENUM('cash','card') NOT NULL,
            status ENUM('paid','refunded') NOT NULL DEFAULT 'paid',
            paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (cashier_staff_id) REFERENCES staff_management(id) ON DELETE RESTRICT
        )");
    }

    public function currentStaff($userId) {
        $stmt = $this->conn->prepare("SELECT sm.*, u.id AS user_id, u.email, u.username,
            COALESCE((SELECT AVG(rating) FROM staff_ratings WHERE staff_id = sm.id), 0) AS performance_average,
            (SELECT COUNT(*) FROM staff_ratings WHERE staff_id = sm.id) AS review_count
            FROM staff_management sm
            INNER JOIN users u ON u.email = sm.staff_email AND u.role = 'staff'
            WHERE u.id = ? AND sm.is_active = 1 AND sm.deleted_at IS NULL LIMIT 1");
        $stmt->execute([(int)$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function directory($department = '', $role = '') {
        $sql = "SELECT sm.*, COALESCE((SELECT AVG(rating) FROM staff_ratings WHERE staff_id = sm.id), 0) AS average_rating,
            (SELECT COUNT(*) FROM staff_ratings WHERE staff_id = sm.id) AS review_count
            FROM staff_management sm
            WHERE sm.is_active = 1 AND sm.deleted_at IS NULL";
        $params = [];
        if ($department !== '') {
            $sql .= " AND sm.department = ?";
            $params[] = $department;
        }
        if ($role !== '') {
            $sql .= " AND sm.staff_role = ?";
            $params[] = $role;
        }
        $sql .= " ORDER BY sm.department, sm.staff_role, sm.staff_name";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function profile($staffId) {
        $stmt = $this->conn->prepare("SELECT sm.*, COALESCE((SELECT AVG(rating) FROM staff_ratings WHERE staff_id = sm.id), 0) AS average_rating,
            (SELECT COUNT(*) FROM staff_ratings WHERE staff_id = sm.id) AS review_count
            FROM staff_management sm WHERE sm.id = ? LIMIT 1");
        $stmt->execute([(int)$staffId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function ratingBreakdown($staffId) {
        $stmt = $this->conn->prepare("SELECT rating, COUNT(*) AS total FROM staff_ratings WHERE staff_id = ? GROUP BY rating ORDER BY rating DESC");
        $stmt->execute([(int)$staffId]);
        $breakdown = array_fill(1, 5, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $breakdown[(int)$row['rating']] = (int)$row['total'];
        }
        return $breakdown;
    }

    public function reviews($staffId) {
        $stmt = $this->conn->prepare("SELECT sr.rating, sr.comment, sr.created_at, u.name AS customer_name
            FROM staff_ratings sr INNER JOIN users u ON u.id = sr.customer_id
            WHERE sr.staff_id = ? ORDER BY sr.created_at DESC");
        $stmt->execute([(int)$staffId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tasks($staffId) {
        $stmt = $this->conn->prepare("SELECT * FROM staff_tasks WHERE staff_id = ? ORDER BY is_done, due_at IS NULL, due_at, created_at DESC");
        $stmt->execute([(int)$staffId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addTask($staffId, $title, $dueAt) {
        $title = trim((string)$title);
        if ($title === '' || strlen($title) > 180) {
            return false;
        }
        $stmt = $this->conn->prepare("INSERT INTO staff_tasks (staff_id, title, due_at) VALUES (?, ?, ?)");
        $stmt->execute([(int)$staffId, $title, $dueAt ?: null]);
        return (int)$this->conn->lastInsertId();
    }

    public function toggleTask($staffId, $taskId) {
        $stmt = $this->conn->prepare("UPDATE staff_tasks SET is_done = NOT is_done WHERE id = ? AND staff_id = ?");
        return $stmt->execute([(int)$taskId, (int)$staffId]);
    }

    public function queue($limit = 50) {
        $stmt = $this->conn->prepare("SELECT o.*, u.name AS customer_name,
            (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', mi.name) ORDER BY mi.name SEPARATOR ', ')
                FROM order_items oi INNER JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = o.id) AS item_summary,
            sm.staff_name AS served_by_name
            FROM orders o
            INNER JOIN users u ON u.id = o.user_id
            LEFT JOIN staff_management sm ON sm.id = o.served_by_staff_id
            WHERE o.status IN ('pending', 'preparing', 'ready')
            ORDER BY o.order_at ASC LIMIT ?");
        $stmt->bindValue(1, max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stockLevels($limit = 100) {
        $stmt = $this->conn->prepare("SELECT mi.id, mi.name, mi.current_stock, mi.reorder_level, c.name AS category_name
            FROM menu_items mi LEFT JOIN categories c ON c.id = mi.category_id
            WHERE mi.is_active = 1 ORDER BY mi.current_stock ASC, mi.name ASC LIMIT ?");
        $stmt->bindValue(1, max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cashierOrders($limit = 50) {
        if (!staffHasRole(['cashier'])) {
            return [];
        }
        $stmt = $this->conn->prepare("SELECT o.id, o.order_number, o.table_number, o.status, o.total_amount, o.order_at,
            (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', mi.name) ORDER BY mi.name SEPARATOR ', ')
                FROM order_items oi INNER JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = o.id) AS item_summary
            FROM orders o LEFT JOIN cashier_transactions ct ON ct.order_id = o.id AND ct.status = 'paid'
            WHERE o.status IN ('pending', 'preparing', 'ready') AND ct.id IS NULL
            ORDER BY o.order_at ASC LIMIT ?");
        $stmt->bindValue(1, max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function finalizeCashierBill($orderId, $paymentMethod, $cashierStaffId) {
        if (!staffHasRole(['cashier']) || !in_array($paymentMethod, ['cash', 'card'], true)) {
            return false;
        }
        $this->conn->beginTransaction();
        try {
            $stmt = $this->conn->prepare("SELECT id, total_amount, status FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([(int)$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || $order['status'] === 'cancelled' || $order['status'] === 'completed') {
                $this->conn->rollBack();
                return false;
            }
            $insert = $this->conn->prepare("INSERT INTO cashier_transactions (order_id, cashier_staff_id, amount, payment_method) VALUES (?, ?, ?, ?)");
            $insert->execute([(int)$order['id'], (int)$cashierStaffId, (float)$order['total_amount'], $paymentMethod]);
            $update = $this->conn->prepare("UPDATE orders SET status = 'completed', served_by_staff_id = ? WHERE id = ? AND status IN ('pending', 'preparing', 'ready')");
            $update->execute([(int)$cashierStaffId, (int)$order['id']]);
            if ($update->rowCount() !== 1) {
                $this->conn->rollBack();
                return false;
            }
            $this->conn->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return false;
        }
    }

    public function cashierTransactionsToday() {
        if (!staffHasRole(['cashier', 'finance'])) {
            return [];
        }
        $stmt = $this->conn->prepare("SELECT ct.*, o.order_number, o.table_number
            FROM cashier_transactions ct INNER JOIN orders o ON o.id = ct.order_id
            WHERE ct.status = 'paid' AND DATE(ct.paid_at) = CURDATE() ORDER BY ct.paid_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cashierReconciliation() {
        if (!staffHasRole(['cashier', 'finance'])) {
            return ['cash' => 0, 'card' => 0, 'system_total' => 0];
        }
        $stmt = $this->conn->query("SELECT payment_method, COALESCE(SUM(amount), 0) AS total FROM cashier_transactions WHERE status = 'paid' AND DATE(paid_at) = CURDATE() GROUP BY payment_method");
        $summary = ['cash' => 0, 'card' => 0, 'system_total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary[$row['payment_method']] = (float)$row['total'];
        }
        $summary['system_total'] = $summary['cash'] + $summary['card'];
        return $summary;
    }

    public function updateQueueStatus($orderId, $staffId, $role, $status) {
        if (!staffHasRole(['cook', 'waiter', 'cashier'])) {
            return false;
        }
        $role = currentStaffRole();
        $stmt = $this->conn->prepare("SELECT status FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$orderId]);
        $current = $stmt->fetchColumn();
        $allowed = false;
        if (in_array($role, ['cook', 'chef'], true)) {
            $allowed = ($current === 'pending' && $status === 'preparing') || ($current === 'preparing' && $status === 'ready');
        } elseif (in_array($role, ['waiter', 'service staff', 'cashier'], true)) {
            $allowed = $current === 'ready' && $status === 'completed';
        }
        if (!$allowed) {
            return false;
        }
        $stmt = $this->conn->prepare("UPDATE orders SET status = ?, served_by_staff_id = ? WHERE id = ? AND status = ?");
        $stmt->execute([$status, (int)$staffId, (int)$orderId, $current]);
        return $stmt->rowCount() === 1;
    }

    public function deliveries($limit = 20) {
        $stmt = $this->conn->prepare("SELECT rd.*, sm.staff_name AS received_by_name FROM receiving_deliveries rd
            LEFT JOIN staff_management sm ON sm.id = rd.received_by_staff_id ORDER BY rd.status, rd.created_at DESC LIMIT ?");
        $stmt->bindValue(1, max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addDelivery($supplier, $items, $quantity) {
        if (!staffHasRole(['inventory'])) {
            return false;
        }
        $supplier = trim((string)$supplier);
        $items = trim((string)$items);
        $quantity = (int)$quantity;
        if ($supplier === '' || $items === '' || $quantity < 1) {
            return false;
        }
        $stmt = $this->conn->prepare("INSERT INTO receiving_deliveries (supplier, items_received, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$supplier, $items, $quantity]);
        return (int)$this->conn->lastInsertId();
    }

    public function markDeliveryReceived($deliveryId, $staffId) {
        if (!staffHasRole(['inventory'])) {
            return false;
        }
        $stmt = $this->conn->prepare("UPDATE receiving_deliveries SET status = 'received', received_by_staff_id = ?, received_at = NOW() WHERE id = ? AND status = 'pending'");
        return $stmt->execute([(int)$staffId, (int)$deliveryId]);
    }

    public function costs($from, $to) {
        $stmt = $this->conn->prepare("SELECT * FROM operating_costs WHERE cost_date BETWEEN ? AND ? ORDER BY cost_date DESC, id DESC");
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function financeSummary($from, $to) {
        $revenueStmt = $this->conn->prepare("SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o INNER JOIN payments p ON p.order_id = o.id AND p.status = 'success' WHERE DATE(o.order_at) BETWEEN ? AND ? AND o.status <> 'cancelled'");
        $revenueStmt->execute([$from, $to]);
        $costStmt = $this->conn->prepare("SELECT department, COALESCE(SUM(amount), 0) AS total FROM operating_costs WHERE cost_date BETWEEN ? AND ? GROUP BY department ORDER BY total DESC");
        $costStmt->execute([$from, $to]);
        $byDepartment = $costStmt->fetchAll(PDO::FETCH_ASSOC);
        return ['revenue' => (float)$revenueStmt->fetchColumn(), 'by_department' => $byDepartment, 'total_costs' => array_sum(array_column($byDepartment, 'total'))];
    }

    public function addCost($category, $department, $amount, $date, $notes = '') {
        if (!staffHasRole(['finance'])) {
            return false;
        }
        $amount = (float)$amount;
        if (trim($category) === '' || trim($department) === '' || $amount < 0 || !$date) {
            return false;
        }
        $stmt = $this->conn->prepare("INSERT INTO operating_costs (category, department, amount, cost_date, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([trim($category), trim($department), $amount, $date, trim($notes)]);
        return (int)$this->conn->lastInsertId();
    }
}
