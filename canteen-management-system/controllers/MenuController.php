<?php
require_once __DIR__ . '/../models/MenuItem.php';
require_once __DIR__ . '/../models/Category.php';

class MenuController {
    public $menuItemModel;
    public $categoryModel;

    public function __construct($db) {
        $this->menuItemModel = new MenuItem($db);
        $this->categoryModel = new Category($db);
    }

    public function listActive() {
        return $this->menuItemModel->all(true);
    }

    public function listAll() {
        return $this->menuItemModel->all(false);
    }

    public function get($id) {
        return $this->menuItemModel->find($id);
    }

    public function create($data, $imagePath) {
        $data['image'] = $imagePath;
        $data['is_active'] = isset($data['is_active']) ? 1 : 0;
        return $this->menuItemModel->create($data);
    }

    public function update($id, $data) {
        $data['is_active'] = isset($data['is_active']) ? 1 : 0;
        $this->menuItemModel->update($id, $data);
    }

    public function toggle($id) {
        $this->menuItemModel->toggleAvailability($id);
    }

    public function delete($id) {
        $this->menuItemModel->delete($id);
    }
}
