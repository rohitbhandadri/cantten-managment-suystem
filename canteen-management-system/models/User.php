<?php
class User {
    private $conn;
    private $table = 'users';
    private $hasStatusColumn = null;
    private $hasSalaryColumn = null;

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureUserCredentialsSchema();
        $this->ensureStaffTableSchema();
    }

    private function columnExists($column) {
        if ($column === 'status' && $this->hasStatusColumn !== null) {
            return $this->hasStatusColumn;
        }
        if ($column === 'salary' && $this->hasSalaryColumn !== null) {
            return $this->hasSalaryColumn;
        }

        $stmt = $this->conn->prepare("SHOW COLUMNS FROM {$this->table} LIKE ?");
        $stmt->execute([$column]);
        $exists = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

        if ($column === 'status') {
            $this->hasStatusColumn = $exists;
        }
        if ($column === 'salary') {
            $this->hasSalaryColumn = $exists;
        }

        return $exists;
    }

    private function ensureUserCredentialsSchema() {
        $check = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'username'");
        $check->execute();

        if ((int)$check->fetchColumn() === 0) {
            try {
                $this->conn->exec("ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL AFTER email");
                $this->conn->exec("CREATE UNIQUE INDEX idx_users_username ON users(username)");
            } catch (PDOException $e) {
                // Ignore drift errors for older local schemas.
            }
        }
    }

    private function generateUniqueUsername($name, $email) {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '.', trim($name)) ?? 'staff');
        $base = trim($base, '.');

        if ($base === '') {
            $base = strtolower(strstr($email, '@', true) ?: 'staff');
        }

        $safeBase = preg_replace('/[^a-z0-9._-]+/', '', $base) ?: 'staff';
        $candidate = $safeBase;
        $suffix = 1;

        while ($this->usernameExists($candidate)) {
            $candidate = $safeBase . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function usernameExists($username) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function ensureStaffTableSchema() {
        $requiredColumns = [
            'staff_role' => "ALTER TABLE staff_management ADD COLUMN staff_role VARCHAR(60) NOT NULL DEFAULT 'Service Staff' AFTER staff_email",
            'staff_phone' => "ALTER TABLE staff_management ADD COLUMN staff_phone VARCHAR(20) NULL AFTER staff_role",
            'staff_shift' => "ALTER TABLE staff_management ADD COLUMN staff_shift ENUM('Morning','Evening','Night') NOT NULL DEFAULT 'Morning' AFTER staff_phone",
            'is_active' => "ALTER TABLE staff_management ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER staff_status",
            'deleted_at' => "ALTER TABLE staff_management ADD COLUMN deleted_at DATETIME NULL AFTER is_active",
        ];

        foreach ($requiredColumns as $column => $sql) {
            $check = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'staff_management' AND column_name = ?");
            $check->execute([$column]);

            if ((int)$check->fetchColumn() === 0) {
                try {
                    $this->conn->exec($sql);
                } catch (PDOException $e) {
                    // Ignore schema drift failure here since the page will still load for valid databases.
                }
            }
        }
    }

    private function ensureStaffColumns() {
        if (!$this->columnExists('salary')) {
            try {
                $this->conn->exec("ALTER TABLE {$this->table} ADD COLUMN salary DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER role");
                $this->hasSalaryColumn = true;
            } catch (PDOException $e) {
                $this->hasSalaryColumn = false;
            }
        }

        if (!$this->columnExists('status')) {
            try {
                $this->conn->exec("ALTER TABLE {$this->table} ADD COLUMN status ENUM('on_duty','on_leave','removed') NOT NULL DEFAULT 'on_duty' AFTER salary");
                $this->hasStatusColumn = true;
            } catch (PDOException $e) {
                $this->hasStatusColumn = false;
            }
        }
    }

    public function getTotalMonthlyPayroll() {
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(staff_salary), 0) FROM staff_management WHERE staff_status != 'removed'");
        $stmt->execute();
        return (float)$stmt->fetchColumn();
    }

    public function countOrdersHandledByStaff($staffId) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM staff_ratings WHERE staff_id = ?");
        $stmt->execute([(int)$staffId]);
        return (int)$stmt->fetchColumn();
    }

    public function findByEmail($email) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByLogin($login) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE email = ? OR username = ? LIMIT 1");
        $stmt->execute([$login, $login]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getStaffById($id) {
        $stmt = $this->conn->prepare("SELECT id, staff_name, staff_email, staff_role, staff_phone, staff_shift, staff_salary, staff_status, performance_rating, rating_count FROM staff_management WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($name, $email, $password, $role = 'customer', $phone = null, $salary = 0, $status = 'on_duty', $designation = 'Service Staff', $shift = 'Morning') {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($role === 'staff') {
            $staffStmt = $this->conn->prepare("INSERT INTO staff_management (staff_name, staff_email, staff_role, staff_phone, staff_shift, staff_salary, staff_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $staffStmt->execute([
                $name,
                $email,
                $designation,
                $phone,
                $shift,
                (float)$salary,
                $status,
            ]);

            $staffId = (int)$this->conn->lastInsertId();
            $username = $this->generateUniqueUsername($name, $email);

            $userStmt = $this->conn->prepare("INSERT INTO users (name, email, username, password_hash, role, phone, status, salary) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $userStmt->execute([
                $name,
                $email,
                $username,
                $hash,
                'staff',
                $phone,
                $status,
                (float)$salary,
            ]);

            return $staffId;
        }

        $stmt = $this->conn->prepare("INSERT INTO {$this->table} (name, email, username, password_hash, role, phone) VALUES (?, ?, ?, ?, ?, ?)");
        $username = $this->generateUniqueUsername($name, $email);
        $stmt->execute([$name, $email, $username, $hash, $role, $phone]);
        return (int)$this->conn->lastInsertId();
    }

    public function updateStaff($id, $name, $email, $salary, $role, $phone, $shift, $status) {
        $allowedStatus = ['on_duty', 'on_leave', 'removed'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'on_duty';
        }

        $stmt = $this->conn->prepare("UPDATE staff_management SET staff_name = ?, staff_email = ?, staff_salary = ?, staff_role = ?, staff_phone = ?, staff_shift = ?, staff_status = ? WHERE id = ?");
        return $stmt->execute([
            trim($name),
            trim($email),
            (float)$salary,
            trim($role),
            trim($phone),
            trim($shift),
            $status,
            (int)$id,
        ]);
    }

    public function updateLastLogin($id) {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET last_login_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function countActiveCustomers() {
        $stmt = $this->conn->query("SELECT COUNT(*) FROM {$this->table} WHERE role = 'customer'");
        return $stmt->fetchColumn();
    }

    public function listByRole($role) {
        if ($role === 'staff') {
            $stmt = $this->conn->prepare("SELECT id, staff_name AS name, staff_email AS email, staff_role AS role, staff_phone AS phone, staff_shift AS shift, staff_salary AS salary, staff_status AS status, performance_rating, rating_count FROM staff_management WHERE is_active = 1 AND deleted_at IS NULL ORDER BY staff_name ASC");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($this->columnExists('status')) {
            $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE role = ? AND status != 'removed' ORDER BY name ASC");
            $stmt->execute([$role]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE role = ? ORDER BY name ASC");
        $stmt->execute([$role]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByRole($role) {
        if ($role === 'staff') {
            $stmt = $this->conn->prepare("SELECT COUNT(*) FROM staff_management WHERE is_active = 1 AND deleted_at IS NULL");
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        }

        if ($this->columnExists('status')) {
            $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} WHERE role = ? AND status != 'removed'");
            $stmt->execute([$role]);
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->table} WHERE role = ?");
        $stmt->execute([$role]);
        return (int)$stmt->fetchColumn();
    }

    public function countStaffByStatus($status) {
        $allowedStatus = ['on_duty', 'on_leave'];
        if (!in_array($status, $allowedStatus, true)) {
            return 0;
        }

        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM staff_management WHERE staff_status = ? AND is_active = 1 AND deleted_at IS NULL");
        $stmt->execute([$status]);
        return (int)$stmt->fetchColumn();
    }

    public function setStaffStatus($id, $status) {
        $allowed = ['on_duty', 'on_leave', 'removed'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE staff_management SET staff_status = ? WHERE id = ? AND is_active = 1 AND deleted_at IS NULL");
        return $stmt->execute([$status, $id]);
    }

    public function deleteStaff($id) {
        $stmt = $this->conn->prepare("UPDATE staff_management SET is_active = 0, deleted_at = NOW() WHERE id = ? AND is_active = 1");
        return $stmt->execute([$id]);
    }
}
