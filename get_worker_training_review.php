<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Admin login required.']);
    exit();
}

$workerId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$workerId || $workerId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid worker.']);
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/worker_registration_schema.php';
if (!ensureWorkerRegistrationSchema($conn)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Training review storage unavailable.']);
    exit();
}

$stmt = $conn->prepare('SELECT answers, videos_watched, score, total_questions, acknowledged_at, submitted_at FROM worker_training_results WHERE worker_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load training review.']);
    exit();
}
$stmt->bind_param('i', $workerId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    echo json_encode(['ok' => true, 'training' => null]);
    exit();
}

$answers = json_decode((string)$row['answers'], true);
$videos = json_decode((string)$row['videos_watched'], true);
echo json_encode([
    'ok' => true,
    'training' => [
        'answers' => is_array($answers) ? $answers : [],
        'videos' => is_array($videos) ? $videos : [],
        'score' => (int)$row['score'],
        'total_questions' => (int)$row['total_questions'],
        'acknowledged_at' => $row['acknowledged_at'],
        'submitted_at' => $row['submitted_at'],
    ],
], JSON_UNESCAPED_UNICODE);