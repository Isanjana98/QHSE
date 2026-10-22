<?php
// Shared helpers for the mobile app API.
// Put this file in C:\xampp\htdocs\QHSE_System\

require_once __DIR__ . '/db_config.php';   // must define $conn (mysqli)

function api_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

function api_ok(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Create a random token, store only its SHA-256 hash, return the raw token. */
function api_issue_token(mysqli $conn, string $role, int $userId, string $kind = 'api', int $ttlSeconds = 2592000): string
{
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);

    $stmt = $conn->prepare(
        "INSERT INTO api_tokens (token_hash, kind, user_role, user_id, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    if (!$stmt) {
        api_fail('Server error (token table missing? run api_tokens.sql).', 500);
    }
    $stmt->bind_param('sssii', $hash, $kind, $role, $userId, $ttlSeconds);
    $stmt->execute();
    $stmt->close();

    return $token;
}

/** Read "Authorization: Bearer xxx" (works on XAMPP/Apache too). */
function api_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $header = $v;
                break;
            }
        }
    }

    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Use at the top of dashboard.php / permits.php / inspections.php:
 *   $auth = require_api_auth($conn, ['subcontractor']);
 *   $subId = $auth['user_id'];   // filter your queries by this!
 */
function require_api_auth(mysqli $conn, array $allowedRoles = []): array
{
    $token = api_bearer_token();
    if ($token === '') {
        api_fail('Please sign in to continue.', 401);
    }

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT user_role, user_id FROM api_tokens
         WHERE token_hash = ? AND kind = 'api' AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        api_fail('Your session has expired. Please sign in again.', 401);
    }
    if ($allowedRoles && !in_array($row['user_role'], $allowedRoles, true)) {
        api_fail('You do not have access to this resource.', 403);
    }

    return ['role' => $row['user_role'], 'user_id' => (int)$row['user_id']];
}