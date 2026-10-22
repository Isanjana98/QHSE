<?php
require_once __DIR__ . '/api_helpers.php';
api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_fail('Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$ticket = $input['ticket'] ?? '';
if (!is_string($ticket) || !preg_match('/^[a-f0-9]{64}$/', $ticket)) {
    api_fail('Invalid app sign-in link.', 422);
}

$ticketHash = hash('sha256', $ticket);
$conn->begin_transaction();

$stmt = $conn->prepare(
    "SELECT id, user_id FROM api_tokens
     WHERE token_hash = ? AND kind = 'app_login' AND used = 0 AND expires_at > NOW()
     LIMIT 1 FOR UPDATE"
);
if (!$stmt) {
    $conn->rollback();
    api_fail('Could not verify app sign-in.', 500);
}
$stmt->bind_param('s', $ticketHash);
$stmt->execute();
$ticketRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticketRow) {
    $conn->rollback();
    api_fail('App sign-in link expired or already used. Please sign in again.', 401);
}

$use = $conn->prepare("UPDATE api_tokens SET used = 1 WHERE id = ? AND used = 0");
$use->bind_param('i', $ticketRow['id']);
$use->execute();
$ticketUsed = $use->affected_rows === 1;
$use->close();
if (!$ticketUsed) {
    $conn->rollback();
    api_fail('App sign-in link already used. Please sign in again.', 401);
}

$userId = (int)$ticketRow['user_id'];
$stmt = $conn->prepare(
    "SELECT id, username, company_name, status
     FROM subcontractors WHERE id = ? LIMIT 1"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || in_array($user['status'] ?? 'Pending', ['Pending', 'Rejected'], true)) {
    $conn->rollback();
    api_fail('This subcontractor account is not approved for sign-in.', 403);
}

$apiToken = api_issue_token($conn, 'subcontractor', $userId);
$conn->commit();

api_ok([
    'role' => 'subcontractor',
    'token' => $apiToken,
    'user' => [
        'id' => $userId,
        'username' => $user['username'],
        'company_name' => html_entity_decode($user['company_name'], ENT_QUOTES),
    ],
]);