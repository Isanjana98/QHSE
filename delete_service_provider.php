<?php
session_start();

if (!isset($_SESSION['supervisor_logged']) || $_SESSION['supervisor_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) die("Invalid permit id.");

$check = $conn->prepare("SELECT status FROM service_provider_permits WHERE permit_id=?");
$check->bind_param("i", $id);
$check->execute();
$permit = $check->get_result()->fetch_assoc();
$check->close();

if (!$permit) die("Permit not found.");

if (strtolower(trim($permit['status'])) !== 'pending') {
    die("Only Pending permits can be deleted.");
}

$conn->begin_transaction();
try {
    $conn->query("DELETE FROM service_provider_tasks WHERE permit_id=" . $id);
    $conn->query("DELETE FROM service_provider_checklist WHERE permit_id=" . $id);
    $conn->query("DELETE FROM service_provider_ppe WHERE permit_id=" . $id);
    $conn->query("DELETE FROM permit_images WHERE permit_type='Service Provider' AND permit_id=" . $id);

    $del = $conn->prepare("DELETE FROM service_provider_permits WHERE permit_id=?");
    $del->bind_param("i", $id);
    $del->execute();
    $del->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    die("Delete failed: " . $e->getMessage());
}

header("Location: permits.php");
exit();