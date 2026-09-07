<?php
require_once __DIR__ . '/../models/User.php';

class AuthController {
    private $userModel;

    public function __construct($db) {
        $this->userModel = new User($db);
    }

    public function login($email, $password, $expectedRole) {
        $user = $this->userModel->findByLogin($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }
        if ($user['role'] !== $expectedRole) {
            return ['success' => false, 'message' => "This account is not registered as $expectedRole."];
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $this->userModel->updateLastLogin($user['id']);
        return ['success' => true, 'role' => $user['role']];
    }

    public function register($name, $email, $password, $phone) {
        if ($this->userModel->findByEmail($email)) {
            return ['success' => false, 'message' => 'Email already registered.'];
        }
        $id = $this->userModel->create($name, $email, $password, 'customer', $phone);
        return ['success' => true, 'id' => $id];
    }

    public function logout() {
        session_unset();
        session_destroy();
    }
}
