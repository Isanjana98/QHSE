<?php
session_start();

if (!isset($_SESSION['supervisor_logged']) || $_SESSION['supervisor_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

require_once "permit_helpers.php";

$image_id = intval($_GET['image_id'] ?? 0);
$back     = $_GET['back'] ?? 'permits.php';

if ($image_id) {
    deletePermitImage($conn, $image_id);
}

header("Location: " . $back);
exit();