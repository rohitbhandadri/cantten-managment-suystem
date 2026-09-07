<?php
class Category {
    private $conn;
    private $table = 'categories';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function all() {
        return $this->conn->query("SELECT * FROM {$this->table} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($name) {
        $stmt = $this->conn->prepare("INSERT INTO {$this->table} (name) VALUES (?)");
        $stmt->execute([$name]);
        return $this->conn->lastInsertId();
    }
}
