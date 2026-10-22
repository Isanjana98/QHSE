<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db_config.php';

$companyName = $_GET['company_name'] ?? '';

if (empty($companyName) && !empty($_SESSION['subcontractor_id'])) {
    $subId = (int)$_SESSION['subcontractor_id'];
    $stmt = $conn->prepare('SELECT company_name FROM subcontractors WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $subId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res) {
        $companyName = $res['company_name'];
    }
    $stmt->close();
}

$records = [];
if (!empty($companyName)) {
    $stmt = $conn->prepare("SELECT id, tool_type, inspection_date, division, project_location, supervisor_name, created_at
                              FROM tool_inspection_records
                             WHERE LOWER(TRIM(company_name)) = LOWER(TRIM(?))
                                ORDER BY inspection_date DESC, id DESC");
    if ($stmt) {
        $stmt->bind_param('s', $companyName);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
    }
}

$conn->close();
echo json_encode(['records' => $records]);
?>b