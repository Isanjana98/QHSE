<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';
$conn->set_charset("utf8mb4");

// ============================================================
// AUTH: subcontractor_dashboard.php eke thiyena token verify
// code eka methanata copy karanna. Eken $sub_id define wenna ona.
// ============================================================

if (!isset($sub_id) || !$sub_id) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authorised']);
    exit();
}

$stmt = $conn->prepare("SELECT company_name, phone FROM subcontractors WHERE id = ?");
$stmt->bind_param("i", $sub_id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare(
    "SELECT permit_id, permit_number, permit_category, cat_project, status, declaration_date
     FROM service_provider_permits
     WHERE subcontractor_id = ?
     ORDER BY permit_id DESC"
);
$stmt->bind_param("i", $sub_id);
$stmt->execute();
$res = $stmt->get_result();

$permits = [];
$pending = 0;
while ($row = $res->fetch_assoc()) {
    if (($row['status'] ?? 'Pending') === 'Pending') {
        $pending++;
    }
    $permits[] = $row;
}
$stmt->close();
$conn->close();

echo json_encode([
    'ok'            => true,
    'company_name'  => $sub['company_name'] ?? 'Subcontractor',
    'phone'         => $sub['phone'] ?? 'N/A',
    'pending_count' => $pending,
    'permits'       => $permits,
]);