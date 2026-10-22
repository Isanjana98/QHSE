<?php
/**
 * media.php
 * =================================================================
 * The single gateway for every private file held in S3.
 *
 *     media.php?p=<base64url key>&s=<hmac>
 *
 * It does three things and nothing else:
 *
 *   1. Confirms the caller has a live session. The bucket is private,
 *      so an anonymous visitor can never reach worker NIC scans or
 *      permit photographs by guessing a URL.
 *   2. Confirms the ?p= parameter carries a matching HMAC, so a
 *      logged-in user cannot hand-edit it into a key they were never
 *      shown.
 *   3. Mints a short-lived presigned URL and redirects to it.
 *
 * The file bytes travel straight from S3 to the browser - this script
 * only ever issues a 302, so it costs almost nothing even for the
 * large tutorial videos. Range requests (video seeking) keep working
 * because the browser replays them against the redirect target.
 * =================================================================
 */

ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/s3_helper.php';

/* -----------------------------------------------------------------
 | 1. Authentication
 |
 | The system has three separate login styles. Any one of them is a
 | valid session for media purposes; page-level code still decides
 | who may see which record.
 ----------------------------------------------------------------- */
$authorised = !empty($_SESSION['supervisor_logged'])
           || !empty($_SESSION['admin_logged'])
           || !empty($_SESSION['registered_company'])
           || !empty($_SESSION['subcontractor_id'])
           || !empty($_SESSION['worker_training_invitation_id']);

if (!$authorised) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}

/* -----------------------------------------------------------------
 | 2. Decode and verify the requested key
 ----------------------------------------------------------------- */
$p   = (string) ($_GET['p'] ?? '');
$sig = (string) ($_GET['s'] ?? '');

if ($p === '' || $sig === '') {
    http_response_code(400);
    exit('Bad request');
}

$padded = strtr($p, '-_', '+/');
$padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
$key = base64_decode($padded, true);

// Reject anything that is not a plain, forward-only object key.
if ($key === false || $key === '' || strpos($key, '..') !== false || $key[0] === '/') {
    http_response_code(400);
    exit('Bad request');
}

if (!media_verify($key, $sig)) {
    http_response_code(403);
    exit('Forbidden');
}

if (!empty($_SESSION['worker_training_invitation_id'])
    && empty($_SESSION['supervisor_logged'])
    && empty($_SESSION['admin_logged'])
    && empty($_SESSION['registered_company'])
    && empty($_SESSION['subcontractor_id'])
    && strpos($key, 'videos/') !== 0
) {
    http_response_code(403);
    exit('Forbidden');
}

/* -----------------------------------------------------------------
 | 3. Hand back a presigned redirect
 ----------------------------------------------------------------- */
$client = s3_client();
if (!$client) {
    http_response_code(503);
    exit('Media storage unavailable');
}

try {
    $cmd = $client->getCommand('GetObject', [
        'Bucket' => s3_bucket(),
        'Key'    => $key,
    ]);

    $request = $client->createPresignedRequest(
        $cmd,
        s3_config('link_ttl', '+15 minutes')
    );

    // Private, per-user, and expiring: never let a proxy cache it.
    header('Cache-Control: private, max-age=0, no-store');
    header('Location: ' . (string) $request->getUri(), true, 302);
    exit;

} catch (\Throwable $e) {
    error_log('media.php presign failed for ' . $key . ': ' . $e->getMessage());
    http_response_code(404);
    exit('Not found');
}
