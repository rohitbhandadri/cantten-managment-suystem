<?php
class CartController {
    public function __construct() {
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
    }

    public function add($itemId, $qty, $name, $price, $image = '') {
        $itemId = (int)$itemId;
        $qty = max(1, (int)$qty);
        if (isset($_SESSION['cart'][$itemId])) {
            $_SESSION['cart'][$itemId]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$itemId] = [
                'id' => $itemId, 'name' => $name, 'price' => $price, 'qty' => $qty, 'image' => $image
            ];
        }
    }

    public function updateQty($itemId, $qty) {
        $itemId = (int)$itemId;
        if ($qty <= 0) {
            unset($_SESSION['cart'][$itemId]);
        } elseif (isset($_SESSION['cart'][$itemId])) {
            $_SESSION['cart'][$itemId]['qty'] = (int)$qty;
        }
    }

    public function remove($itemId) {
        unset($_SESSION['cart'][(int)$itemId]);
    }

    public function items() {
        return $_SESSION['cart'];
    }

    public function count() {
        return array_sum(array_column($_SESSION['cart'], 'qty'));
    }

    public function subtotal() {
        $sum = 0;
        foreach ($_SESSION['cart'] as $item) {
            $sum += $item['price'] * $item['qty'];
        }
        return $sum;
    }

    public function clear() {
        $_SESSION['cart'] = [];
    }
}
