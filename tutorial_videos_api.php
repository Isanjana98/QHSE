<?php
/**
 * tutorial_videos_api.php
 * =================================================================
 * JSON backend for admin_tutorials.php. Admin session required.
 *
 * Actions (POST, action=...):
 *
 *   presign  - mint a presigned PUT URL so the browser can send the
 *              video straight to S3. Necessary, not merely faster:
 *              upload_max_filesize is 2M on this server and the
 *              videos run to 180M.
 *   create   - record a video after its upload finished
 *   update   - edit title / description / active flag
 *   delete   - remove the row and the object behind it
 *   reorder  - persist a new display order
 * =================================================================
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

/* -----------------------------------------------------------------
 | Guards
 ----------------------------------------------------------------- */
function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function done(array $data = []): void
{
    echo json_encode(['ok' => true] + $data);
    exit;
}

if (empty($_SESSION['admin_logged'])) {
    fail('Admin login required.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST required.', 405);
}

// CSRF: the page puts this token in a hidden field.
if (
    empty($_SESSION['tut_csrf'])
    || !hash_equals($_SESSION['tut_csrf'], (string) ($_POST['csrf'] ?? ''))
) {
    fail('Invalid session token. Reload the page and try again.', 403);
}

$action = (string) ($_POST['action'] ?? '');

/* -----------------------------------------------------------------
 | Helpers
 ----------------------------------------------------------------- */
const ALLOWED_VIDEO_EXT = ['mp4', 'webm', 'mov', 'm4v'];
const MAX_VIDEO_BYTES   = 2147483648;   // 2 GB

/** "137" -> "2:17",  "3725" -> "1:02:05" */
function format_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;

    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

function next_sort_order(mysqli $conn): int
{
    $r = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM tutorial_videos");
    return (int) (($r ? $r->fetch_assoc()['n'] : 1) ?: 1);
}

/* =================================================================
 | presign
 ================================================================= */
if ($action === 'presign') {

    if (!s3_enabled()) {
        fail(
            'S3 is not enabled yet. Video upload needs it, because this '
          . 'server only accepts ' . ini_get('upload_max_filesize')
          . ' through PHP. Set enabled => true in s3_config.php.',
            409
        );
    }

    $filename = trim((string) ($_POST['filename'] ?? ''));
    $size     = (int) ($_POST['size'] ?? 0);
    $ctype    = trim((string) ($_POST['content_type'] ?? '')) ?: 'video/mp4';

    if ($filename === '') {
        fail('No filename given.');
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_VIDEO_EXT, true)) {
        fail('Unsupported file type ".' . $ext . '". Allowed: ' . implode(', ', ALLOWED_VIDEO_EXT) . '.');
    }
    if ($size <= 0) {
        fail('Could not read the file size.');
    }
    if ($size > MAX_VIDEO_BYTES) {
        fail('That file is ' . round($size / 1048576) . ' MB. The limit is 2048 MB.');
    }
    if (strpos($ctype, 'video/') !== 0) {
        $ctype = 'video/mp4';
    }

    $key      = s3_build_key('videos', $filename, 'tut');
    $presign  = s3_presign_put($key, $ctype);

    if (!$presign) {
        fail('Could not reach S3 to prepare the upload. Check the credentials in s3_config.php.', 502);
    }

    done([
        'upload_url'   => $presign['url'],
        'stored'       => $presign['stored'],
        'content_type' => $ctype,
    ]);
}

/* =================================================================
 | create
 ================================================================= */
if ($action === 'create') {

    $title  = trim((string) ($_POST['title'] ?? ''));
    $desc   = trim((string) ($_POST['description'] ?? ''));
    $type   = ($_POST['type'] ?? 'file') === 'youtube' ? 'youtube' : 'file';
    $src    = trim((string) ($_POST['src'] ?? ''));
    $secs   = max(0, (int) ($_POST['duration_seconds'] ?? 0));
    $bytes  = max(0, (int) ($_POST['file_size'] ?? 0));

    if ($title === '') {
        fail('Title is required.');
    }
    if ($src === '') {
        fail('No video source. The upload may not have finished.');
    }

    if ($type === 'youtube') {
        // Accept a bare ID or any of the usual URL shapes.
        if (preg_match('#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{11})#', $src, $m)) {
            $src = $m[1];
        }
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $src)) {
            fail('That does not look like a YouTube video ID or link.');
        }
    } else {
        // Must be one of our own stored paths, never an arbitrary URL.
        if (!s3_is_key($src) && strpos($src, 'assets/videos/') !== 0) {
            fail('Unexpected video source.');
        }
    }

    $dur   = format_duration($secs);
    $order = next_sort_order($conn);

    $stmt = $conn->prepare("
        INSERT INTO tutorial_videos
            (title, description, type, src, duration, duration_seconds, file_size, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        fail('Database error: ' . $conn->error, 500);
    }

    $stmt->bind_param('sssssiii', $title, $desc, $type, $src, $dur, $secs, $bytes, $order);

    if (!$stmt->execute()) {
        fail('Could not save: ' . $stmt->error, 500);
    }

    $id = $stmt->insert_id;
    $stmt->close();

    done(['id' => $id, 'duration' => $dur]);
}

/* =================================================================
 | update
 ================================================================= */
if ($action === 'update') {

    $id     = (int) ($_POST['id'] ?? 0);
    $title  = trim((string) ($_POST['title'] ?? ''));
    $desc   = trim((string) ($_POST['description'] ?? ''));
    $active = !empty($_POST['is_active']) ? 1 : 0;

    if ($id <= 0) {
        fail('Missing video id.');
    }
    if ($title === '') {
        fail('Title is required.');
    }

    $stmt = $conn->prepare("
        UPDATE tutorial_videos
           SET title = ?, description = ?, is_active = ?
         WHERE id = ?
    ");
    if (!$stmt) {
        fail('Database error: ' . $conn->error, 500);
    }

    $stmt->bind_param('ssii', $title, $desc, $active, $id);
    if (!$stmt->execute()) {
        fail('Could not save: ' . $stmt->error, 500);
    }
    $stmt->close();

    done();
}

/* =================================================================
 | delete
 ================================================================= */
if ($action === 'delete') {

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        fail('Missing video id.');
    }

    $sel = $conn->prepare("SELECT type, src FROM tutorial_videos WHERE id = ?");
    if (!$sel) {
        fail('Database error: ' . $conn->error, 500);
    }
    $sel->bind_param('i', $id);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    if (!$row) {
        fail('That video no longer exists.', 404);
    }

    // Remove the row first: a stranded object is cheaper to live with
    // than a row pointing at a file that is already gone.
    $del = $conn->prepare("DELETE FROM tutorial_videos WHERE id = ?");
    $del->bind_param('i', $id);
    if (!$del->execute()) {
        fail('Could not delete: ' . $del->error, 500);
    }
    $del->close();

    $fileRemoved = false;
    if ($row['type'] === 'file') {
        $fileRemoved = media_delete($row['src']);
    }

    done(['file_removed' => $fileRemoved]);
}

/* =================================================================
 | reorder
 ================================================================= */
if ($action === 'reorder') {

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || !$ids) {
        fail('Nothing to reorder.');
    }

    $stmt = $conn->prepare("UPDATE tutorial_videos SET sort_order = ? WHERE id = ?");
    if (!$stmt) {
        fail('Database error: ' . $conn->error, 500);
    }

    $conn->begin_transaction();
    $order = 0;
    foreach ($ids as $rawId) {
        $vid = (int) $rawId;
        if ($vid <= 0) {
            continue;
        }
        $order++;
        $stmt->bind_param('ii', $order, $vid);
        if (!$stmt->execute()) {
            $conn->rollback();
            fail('Could not reorder: ' . $stmt->error, 500);
        }
    }
    $conn->commit();
    $stmt->close();

    done(['count' => $order]);
}

fail('Unknown action.');
