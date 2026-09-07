<?php
require_once __DIR__ . '/../models/Table.php';

class ReservationController {
    private $tableModel;
    private $reservationModel;

    public function __construct($db) {
        $this->tableModel = new TableModel($db);
        $this->reservationModel = new Reservation($db);
    }

    public function availableTables($date, $time) {
        $all = $this->tableModel->all();
        $reservedIds = $this->reservationModel->reservedTableIds($date, $time);
        foreach ($all as &$t) {
            $t['reserved'] = in_array($t['id'], $reservedIds);
        }
        return $all;
    }

    public function book($userId, $tableId, $date, $time, $guests) {
        $start = $time;
        $end = date('H:i:s', strtotime($time . ' +1 hour'));
        return $this->reservationModel->create($userId, $tableId, $date, $start, $end, $guests);
    }

    public function myReservations($userId) {
        return $this->reservationModel->findByUser($userId);
    }

    public function updateStatus($id, $status) {
        return $this->reservationModel->updateStatus($id, $status);
    }
}
