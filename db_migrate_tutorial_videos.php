<?php
/**
 * db_migrate_tutorial_videos.php
 * =================================================================
 * Creates the tutorial_videos table and seeds it with the eight
 * videos that were previously hardcoded in tutorial.php and
 * subcontractor_tutorials.php.
 *
 * Also repairs two columns in quiz_results that the watch-progress
 * code has always written to but which never matched the schema:
 *
 *   videos_watched  tinyint(1)  ->  TEXT   (code stores a JSON array)
 *   video_progress  (missing)   ->  TEXT   (code stores a JSON object)
 *
 * Safe to run more than once - every step checks first.
 *
 *   php db_migrate_tutorial_videos.php
 *
 * or, if you have no command line, open it in a browser while
 * logged in as admin.
 * =================================================================
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    session_start();
    if (empty($_SESSION['admin_logged'])) {
        http_response_code(403);
        exit('Admin login required.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/db_config.php';

function step(string $msg): void { echo $msg . "\n"; }

/* -----------------------------------------------------------------
 | Small schema helpers
 ----------------------------------------------------------------- */
function table_exists(mysqli $conn, string $table): bool
{
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $col): bool
{
    $r = $conn->query(
        "SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'"
    );
    return $r && $r->num_rows > 0;
}

function column_type(mysqli $conn, string $table, string $col): string
{
    $r = $conn->query(
        "SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'"
    );
    $row = $r ? $r->fetch_assoc() : null;
    return $row['Type'] ?? '';
}

echo "=== Tutorial video migration ===\n\n";

/* -----------------------------------------------------------------
 | 1. tutorial_videos
 ----------------------------------------------------------------- */
if (table_exists($conn, 'tutorial_videos')) {
    step('1. tutorial_videos            already exists, skipped');
} else {
    $sql = "
        CREATE TABLE `tutorial_videos` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `title`            VARCHAR(255) NOT NULL,
            `description`      TEXT NULL,
            `type`             ENUM('file','youtube') NOT NULL DEFAULT 'file',
            `src`              VARCHAR(255) NOT NULL,
            `duration`         VARCHAR(16)  NOT NULL DEFAULT '',
            `duration_seconds` INT          NOT NULL DEFAULT 0,
            `file_size`        BIGINT       NOT NULL DEFAULT 0,
            `sort_order`       INT          NOT NULL DEFAULT 0,
            `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_active_order` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    if ($conn->query($sql)) {
        step('1. tutorial_videos            CREATED');
    } else {
        exit("1. tutorial_videos            FAILED: " . $conn->error . "\n");
    }
}

/* -----------------------------------------------------------------
 | 2. Seed the eight existing videos
 |
 | src is stored in the same "stored path" form the rest of the
 | system uses. They start as local paths; tools/s3_upload_videos.php
 | rewrites them to "s3:videos/..." once the files are in the bucket.
 ----------------------------------------------------------------- */
$seed = [
    ['How to Save Water in the Workplace',   'Simple ways to reduce water usage and avoid unnecessary wastage at work.',            'A.mp4', '2:02', 122],
    ['Proper Waste Management',              'Learn how to handle, separate, and dispose of workplace waste correctly.',            'B.mp4', '3:15', 195],
    ['Hot Weather & Sun Care Tips',          'Useful tips to stay safe, hydrated, and protected during hot weather.',               'C.mp4', '1:30',  90],
    ['Workplace Safety & Hazard Awareness',  'Learn to identify common workplace hazards and follow safe work practices.',          'D.mp4', '8:38', 518],
    ['Divide and Rule',                      'Understand the importance of teamwork, communication, and working together safely.',  'E.mp4', '4:17', 257],
    ['Protect Your Skin',                    'Learn simple ways to protect your skin from workplace hazards and exposure.',         'F.mp4', '5:56', 356],
    ['Watch Out for Others Too',             'Learn how looking out for your coworkers can help prevent workplace incidents.',      'G.mp4', '1:46', 106],
    ['Stress at Work',                       'Understand workplace stress and learn simple ways to manage it effectively.',         'H.mp4', '3:17', 197],
];

$existing = $conn->query("SELECT COUNT(*) c FROM tutorial_videos")->fetch_assoc()['c'] ?? 0;

if ($existing > 0) {
    step("2. seed rows                  table already has $existing row(s), skipped");
} else {
    $ins = $conn->prepare("
        INSERT INTO tutorial_videos
            (title, description, type, src, duration, duration_seconds, file_size, sort_order)
        VALUES (?, ?, 'file', ?, ?, ?, ?, ?)
    ");
    if (!$ins) {
        exit("2. seed rows                  FAILED: " . $conn->error . "\n");
    }

    $order = 0;
    $added = 0;
    foreach ($seed as [$title, $desc, $file, $dur, $secs]) {
        $order++;
        $src  = 'assets/videos/' . $file;
        $abs  = __DIR__ . '/assets/videos/' . $file;
        $size = is_file($abs) ? (int) filesize($abs) : 0;

        $ins->bind_param('ssssiii', $title, $desc, $src, $dur, $secs, $size, $order);
        if ($ins->execute()) {
            $added++;
        } else {
            step("   ! failed to seed $file: " . $ins->error);
        }
    }
    $ins->close();
    step("2. seed rows                  INSERTED $added video(s)");
}

/* -----------------------------------------------------------------
 | 3. quiz_results.videos_watched  tinyint -> TEXT
 ----------------------------------------------------------------- */
if (!table_exists($conn, 'quiz_results')) {
    step('3. quiz_results.videos_watched  table missing, skipped');
} else {
    $type = strtolower(column_type($conn, 'quiz_results', 'videos_watched'));

    if ($type === '' ) {
        if ($conn->query("ALTER TABLE `quiz_results` ADD COLUMN `videos_watched` TEXT NULL AFTER `answers`")) {
            step('3. quiz_results.videos_watched  ADDED as TEXT');
        } else {
            step('3. quiz_results.videos_watched  FAILED: ' . $conn->error);
        }
    } elseif (strpos($type, 'tinyint') !== false) {
        // The old column holds 0/1. The code has always tried to write a
        // JSON array here, which silently failed, so there is no real
        // progress data to preserve.
        if ($conn->query("ALTER TABLE `quiz_results` MODIFY `videos_watched` TEXT NULL")) {
            $conn->query("UPDATE `quiz_results` SET `videos_watched` = '[]' WHERE `videos_watched` IN ('0','1') OR `videos_watched` IS NULL");
            step('3. quiz_results.videos_watched  CHANGED tinyint(1) -> TEXT, reset to []');
        } else {
            step('3. quiz_results.videos_watched  FAILED: ' . $conn->error);
        }
    } else {
        step("3. quiz_results.videos_watched  already $type, skipped");
    }
}

/* -----------------------------------------------------------------
 | 4. quiz_results.video_progress  (never existed)
 ----------------------------------------------------------------- */
if (!table_exists($conn, 'quiz_results')) {
    step('4. quiz_results.video_progress  table missing, skipped');
} elseif (column_exists($conn, 'quiz_results', 'video_progress')) {
    step('4. quiz_results.video_progress  already exists, skipped');
} elseif ($conn->query("ALTER TABLE `quiz_results` ADD COLUMN `video_progress` TEXT NULL AFTER `videos_watched`")) {
    step('4. quiz_results.video_progress  ADDED');
} else {
    step('4. quiz_results.video_progress  FAILED: ' . $conn->error);
}

/* -----------------------------------------------------------------
 | Summary
 ----------------------------------------------------------------- */
echo "\n=== Current tutorial_videos ===\n";
$r = $conn->query("SELECT id, sort_order, title, src, duration, is_active FROM tutorial_videos ORDER BY sort_order, id");
if ($r) {
    printf("%-4s %-6s %-38s %-30s %-7s %s\n", 'ID', 'ORDER', 'TITLE', 'SRC', 'DUR', 'ACTIVE');
    echo str_repeat('-', 100) . "\n";
    while ($row = $r->fetch_assoc()) {
        printf(
            "%-4s %-6s %-38s %-30s %-7s %s\n",
            $row['id'],
            $row['sort_order'],
            mb_strimwidth($row['title'], 0, 37, '...'),
            mb_strimwidth($row['src'], 0, 29, '...'),
            $row['duration'],
            $row['is_active'] ? 'yes' : 'no'
        );
    }
}

echo "\nDone. Next: open admin_tutorials.php as an admin.\n";
$conn->close();
