<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db_config.php';

if (!isset($_SESSION['subcontractor_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access.']);
    exit();
}

$sub_id = $_SESSION['subcontractor_id'];
$token = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime('+14 days'));

$stmt = $conn->prepare("INSERT INTO worker_registration_links (subcontractor_id, token, status, expires_at) VALUES (?, ?, 'active', ?)");
if ($stmt) {
    $stmt->bind_param("iss", $sub_id, $token, $expires_at);
    if ($stmt->execute()) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $domain = $_SERVER['HTTP_HOST'];
        
        // Exact directory path
        $dir = dirname($_SERVER['PHP_SELF']);
        $path = ($dir === '/' || $dir === '\\') ? '' : rtrim($dir, '/\\');
        
        // Ensure file name matches your actual PHP file name (e.g., register_worker.php)
        $url = $protocol . $domain . $path . "/register_worker.php?token=" . $token;

        echo json_encode(['ok' => true, 'url' => $url]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['ok' => false, 'error' => 'Query failed: ' . $conn->error]);
}
?>