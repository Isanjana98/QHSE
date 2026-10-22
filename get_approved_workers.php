<?php
session_start();
header('Content-Type: application/json');

$ok = (!empty($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true)
   || (!empty($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true);
if(!$ok){ echo json_encode([]); exit; }

$vendor = preg_replace('/[^A-Za-z0-9]/', '', $_GET['vendor_code'] ?? '');
if($vendor === ''){ echo json_encode([]); exit; }

require_once __DIR__ . '/db_config.php';

// Worker code format: HYE/{vendor_code}/W001
$like = "HYE/" . $vendor . "/%";
$stmt = $conn->prepare("SELECT * FROM workers WHERE status = 'Approved' AND worker_unique_code LIKE ? ORDER BY worker_unique_code ASC");
$stmt->bind_param("s", $like);
$stmt->execute();
$res = $stmt->get_result();

function pick($row, $keys){
    foreach($keys as $k){
        if(isset($row[$k]) && $row[$k] !== '') return $row[$k];
    }
    return '';
}

$out = [];
while($r = $res->fetch_assoc()){
    $out[] = [
        'code' => $r['worker_unique_code'],
        // If your column names differ, add them to these lists
        'name' => pick($r, ['worker_name','full_name','name']),
        'nic'  => pick($r, ['nic_number','nic','worker_nic','nic_no'])
    ];
}
echo json_encode($out);