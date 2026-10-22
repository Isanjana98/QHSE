<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    respond(401, ['ok' => false, 'error' => 'Admin login is required.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))
) {
    respond(403, ['ok' => false, 'error' => 'Invalid request. Refresh the page and try again.']);
}

$subcontractorId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
if (!$subcontractorId || $subcontractorId < 1) {
    respond(400, ['ok' => false, 'error' => 'Invalid subcontractor.']);
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/subcontractor_update_links_schema.php';
$completionFields = require __DIR__ . '/subcontractor_completion_fields.php';

if (!ensureSubcontractorUpdateLinksTable($conn)) {
    respond(500, ['ok' => false, 'error' => 'Could not prepare the secure update link.']);
}

$stmt = $conn->prepare('SELECT * FROM subcontractors WHERE id = ? LIMIT 1');
if (!$stmt) {
    respond(500, ['ok' => false, 'error' => 'Could not load subcontractor details.']);
}
$stmt->bind_param('i', $subcontractorId);
$stmt->execute();
$subcontractor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$subcontractor) {
    respond(404, ['ok' => false, 'error' => 'Subcontractor record not found.']);
}

$missingFields = subcontractorMissingProfileFields($subcontractor, $completionFields);
if (!$missingFields) {
    respond(409, ['ok' => false, 'error' => 'This subcontractor profile has no missing required details.']);
}

$host = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
    respond(400, ['ok' => false, 'error' => 'Could not determine the application URL.']);
}
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$directory = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$basePath = $directory === '' ? '' : $directory;
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);

$conn->begin_transaction();
try {
    $revoke = $conn->prepare('UPDATE subcontractor_update_links SET consumed_at = NOW() WHERE subcontractor_id = ? AND consumed_at IS NULL');
    if (!$revoke) {
        throw new RuntimeException('Could not revoke previous links.');
    }
    $revoke->bind_param('i', $subcontractorId);
    if (!$revoke->execute()) {
        throw new RuntimeException('Could not revoke previous links.');
    }
    $revoke->close();

    $insert = $conn->prepare('INSERT INTO subcontractor_update_links (subcontractor_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))');
    if (!$insert) {
        throw new RuntimeException('Could not create the new link.');
    }
    $insert->bind_param('is', $subcontractorId, $tokenHash);
    if (!$insert->execute()) {
        throw new RuntimeException('Could not create the new link.');
    }
    $insert->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('create_subcontractor_update_link.php: ' . $error->getMessage());
    respond(500, ['ok' => false, 'error' => 'Could not create the secure update link.']);
}

$conn->close();
$link = $scheme . '://' . $host . $basePath . '/complete_subcontractor.php?token=' . rawurlencode($token);
respond(200, [
    'ok' => true,
    'url' => $link,
    'email' => $subcontractor['email'] ?? '',
    'company' => $subcontractor['company_name'] ?? '',
    'missing' => array_values(array_column($missingFields, 'label')),
    'expires_in_days' => 14,
]);