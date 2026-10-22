<?php
// Used by the mobile app WebView for admin / supervisor.
// Takes a one-time token (valid 60 seconds), starts the normal PHP web
// session exactly like login_process.php, then redirects to the dashboard.

session_start();
require_once __DIR__ . '/db_config.php';

function back_to_login(string $msg): void
{
    $_SESSION['login_error'] = $msg;
    header('Location: index.php');
    exit;
}

$token = $_GET['t'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    back_to_login('Invalid sign-in link. Please sign in again.');
}

$hash = hash('sha256', $token);

// Fetch a valid, unused web token
$stmt = $conn->prepare(
    "SELECT id, user_role, user_id FROM api_tokens
     WHERE token_hash = ? AND kind = 'web' AND used = 0 AND expires_at > NOW()
     LIMIT 1"
);
$stmt->bind_param('s', $hash);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    back_to_login('Sign-in link expired. Please sign in again.');
}

// Burn it so it can never be used twice
$use = $conn->prepare("UPDATE api_tokens SET used = 1 WHERE id = ? AND used = 0");
$use->bind_param('i', $row['id']);
$use->execute();
if ($use->affected_rows !== 1) {
    back_to_login('Sign-in link already used. Please sign in again.');
}
$use->close();

// Clear old role values (same list as login_process.php)
unset(
    $_SESSION['admin_logged'], $_SESSION['admin_username'],
    $_SESSION['supervisor_logged'], $_SESSION['sup_id'], $_SESSION['supervisor_id'],
    $_SESSION['sup_name'], $_SESSION['sup_division'], $_SESSION['sup_username'],
    $_SESSION['subcontractor_id'], $_SESSION['username'], $_SESSION['company_name'],
    $_SESSION['user_role']
);

if ($row['user_role'] === 'admin') {
    $_SESSION['admin_logged']   = true;
    $_SESSION['admin_username'] = 'admin';
    $_SESSION['user_role']      = 'admin';
    header('Location: admin_dashboard.php');
    exit;
}

if ($row['user_role'] === 'supervisor') {
    $stmt = $conn->prepare(
        "SELECT id, name, division, username FROM supervisors WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $row['user_id']);
    $stmt->execute();
    $sup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sup) {
        back_to_login('Account not found.');
    }

    $_SESSION['supervisor_logged'] = true;
    $_SESSION['sup_id']            = $sup['id'];
    $_SESSION['supervisor_id']     = $sup['id'];
    $_SESSION['sup_name']          = $sup['name'];
    $_SESSION['sup_division']      = $sup['division'];
    $_SESSION['sup_username']      = $sup['username'];
    $_SESSION['user_role']         = 'supervisor';
    header('Location: supervisor_dashboard.php');
    exit;
}

back_to_login('Unsupported account type for web sign-in.');