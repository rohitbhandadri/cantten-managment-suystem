<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/controllers/AuthController.php';

$database = new Database();
$db = $database->connect();
$auth = new AuthController($db);
$auth->logout();
session_start();
redirect('login.php');
