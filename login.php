<?php
// Mobile app login (JSON). Same rules as login_process.php:
// admin -> supervisor -> subcontractor, with Pending/Rejected checks.

require_once __DIR__ . '/api_helpers.php';
api_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_fail('Method not allowed.', 405);
}

$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$username = trim($in['username'] ?? '');
$password = $in['password'] ?? '';

if ($username === '' || $password === '') {
    api_fail('Please enter username and password.', 422);
}

// -------------------------------------------------
// 1. ADMIN (same hard-coded check as the web login)
// -------------------------------------------------
if ($username === 'admin' && $password === '1234') {
    api_ok([
        'role'      => 'admin',
        'token'     => api_issue_token($conn, 'admin', 0, 'api'),
        'web_token' => api_issue_token($conn, 'admin', 0, 'web', 60),
        'user'      => ['username' => 'admin', 'name' => 'Administrator'],
    ]);
}

// -------------------------------------------------
// 2. SUPERVISOR
// -------------------------------------------------
$stmt = $conn->prepare(
    "SELECT id, name, division, username, password, status
     FROM supervisors WHERE username = ? LIMIT 1"
);
$stmt->bind_param('s', $username);
$stmt->execute();
$sup = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($sup) {
    if (!password_verify($password, $sup['password'])) {
        api_fail('Invalid Password. Please try again.', 401);
    }
    $status = $sup['status'] ?? 'Pending';
    if ($status === 'Pending') {
        api_fail('Your account is still pending admin approval. Please wait for approval before logging in.', 403);
    }
    if ($status === 'Rejected') {
        api_fail('Your registration request has been rejected by the admin.', 403);
    }

    $id = (int)$sup['id'];
    api_ok([
        'role'      => 'supervisor',
        'token'     => api_issue_token($conn, 'supervisor', $id, 'api'),
        'web_token' => api_issue_token($conn, 'supervisor', $id, 'web', 60),
        'user'      => [
            'id'       => $id,
            'username' => $sup['username'],
            'name'     => html_entity_decode($sup['name'], ENT_QUOTES),
            'division' => $sup['division'],
        ],
    ]);
}

// -------------------------------------------------
// 3. SUBCONTRACTOR
// -------------------------------------------------
$stmt = $conn->prepare(
    "SELECT id, username, password, company_name, status
     FROM subcontractors WHERE username = ? LIMIT 1"
);
$stmt->bind_param('s', $username);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sub) {
    api_fail('Username not found. Please register or check again.', 401);
}
if (!password_verify($password, $sub['password'])) {
    api_fail('Invalid Password. Please try again.', 401);
}

$status = $sub['status'] ?? 'Pending';
if ($status === 'Pending') {
    api_fail('Your account is still pending admin approval.', 403);
}
if ($status === 'Rejected') {
    api_fail('Your account has been rejected by the admin.', 403);
}

$id = (int)$sub['id'];
api_ok([
    'role'  => 'subcontractor',
    'token' => api_issue_token($conn, 'subcontractor', $id, 'api'),
    'user'  => [
        'id'           => $id,
        'username'     => $sub['username'],
        // decoded so the app shows "&" instead of "&amp;"
        'company_name' => html_entity_decode($sub['company_name'], ENT_QUOTES),
    ],
]);