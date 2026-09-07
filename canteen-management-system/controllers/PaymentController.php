<?php
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../models/Order.php';

class PaymentController {
    private $paymentModel;
    private $orderModel;

    public function __construct($db) {
        $this->paymentModel = new Payment($db);
        $this->orderModel = new Order($db);
    }

    public function initiate($orderId, $method, $amount) {
        return $this->paymentModel->create($orderId, $method, $amount);
    }

    // Simulates OTP-verified payment confirmation (as in the base paper's OTP verification flow)
    public function confirm($paymentId, $orderId) {
        $ref = $this->paymentModel->markSuccess($paymentId, $orderId);
        if (!$ref) {
            return false;
        }
        $this->orderModel->updateStatus($orderId, 'preparing');
        return $ref;
    }

    public function getForOrder($orderId) {
        return $this->paymentModel->findByOrder($orderId);
    }
}
