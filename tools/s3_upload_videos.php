<?php
/**
 * tools/s3_upload_videos.php
 * =================================================================
 * One-off CLI uploader for the tutorial videos in assets/videos/.
 *
 *   php tools/s3_upload_videos.php            # upload what is missing
 *   php tools/s3_upload_videos.php --force    # re-upload everything
 *   php tools/s3_upload_videos.php --dry-run  # just show the plan
 *
 * Uses the SDK's ObjectUploader, which automatically switches to a
 * multipart upload for the larger files (C.mp4 is ~188 MB), so an
 * interrupted run can simply be repeated.
 *
 * Run this BEFORE flipping 'enabled' to true in s3_config.php,
 * otherwise the tutorial pages will point at keys that do not exist
 * yet.
 * =================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/s3_helper.php';

$force  = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

/* -----------------------------------------------------------------
 | Pre-flight
 ----------------------------------------------------------------- */
$cfg = s3_config();

if (!$cfg) {
    exit("ERROR: s3_config.php not found. Copy s3_config.example.php first.\n");
}
if (!class_exists('\Aws\S3\S3Client')) {
    exit("ERROR: AWS SDK missing. Run: composer install\n");
}
if (!$cfg['bucket'] || strpos($cfg['bucket'], 'CHANGE-ME') !== false) {
    exit("ERROR: set a real 'bucket' in s3_config.php first.\n");
}

// The uploader must work even while 'enabled' is still false, so it
// builds its own client rather than going through s3_client().
// s3_client_args() supplies the credentials and, on Windows where PHP
// has no CA bundle, the certificate path as well.
$args   = s3_client_args();
$client = new \Aws\S3\S3Client($args);

if ($ca = s3_ca_bundle()) {
    echo "CA bundle: $ca\n";
}

$bucket = $cfg['bucket'];
$prefix = $cfg['prefixes']['videos'] ?? 'videos/';
$dir    = $root . '/assets/videos/';

if (!is_dir($dir)) {
    exit("ERROR: $dir does not exist.\n");
}

$files = array_values(array_filter(
    scandir($dir),
    function ($f) use ($dir) {
        return is_file($dir . $f)
            && preg_match('/\.(mp4|webm|mov|m4v)$/i', $f);
    }
));

if (!$files) {
    exit("No video files found in assets/videos/.\n");
}

printf("Bucket : %s (%s)\n", $bucket, $args['region']);
printf("Prefix : %s\n", $prefix);
printf("Files  : %d\n\n", count($files));

/* -----------------------------------------------------------------
 | Upload loop
 ----------------------------------------------------------------- */
$uploaded = $skipped = $failed = 0;
$started  = microtime(true);

foreach ($files as $file) {
    $path = $dir . $file;
    $key  = $prefix . $file;
    $size = filesize($path);
    $mb   = number_format($size / 1048576, 1);

    printf("%-12s %8s MB  ", $file, $mb);

    if (!$force) {
        try {
            if ($client->doesObjectExist($bucket, $key)) {
                echo "already in bucket, skipped\n";
                $skipped++;
                continue;
            }
        } catch (\Throwable $e) {
            echo "FAILED (cannot check bucket: " . $e->getMessage() . ")\n";
            $failed++;
            continue;
        }
    }

    if ($dryRun) {
        echo "would upload -> s3://$bucket/$key\n";
        continue;
    }

    try {
        $source = fopen($path, 'rb');

        $uploader = new \Aws\S3\ObjectUploader(
            $client,
            $bucket,
            $key,
            $source,
            'private',
            [
                'params' => [
                    'ContentType' => s3_guess_content_type($path, $file),
                ],
            ]
        );

        $uploader->upload();
        fclose($source);

        echo "uploaded\n";
        $uploaded++;

    } catch (\Throwable $e) {
        if (isset($source) && is_resource($source)) {
            fclose($source);
        }
        echo "FAILED: " . $e->getMessage() . "\n";
        $failed++;
    }
}

printf(
    "\nDone in %ds - %d uploaded, %d skipped, %d failed.\n",
    round(microtime(true) - $started),
    $uploaded,
    $skipped,
    $failed
);

/* -----------------------------------------------------------------
 | Repoint the database rows at the bucket
 |
 | db_migrate_tutorial_videos.php seeds tutorial_videos.src with the
 | local path ("assets/videos/A.mp4"). Now that the object exists,
 | rewrite each row that has a matching file to "s3:videos/A.mp4".
 ----------------------------------------------------------------- */
if (!$dryRun) {
    echo "\nUpdating tutorial_videos rows...\n";

    $dbFile = $root . '/db_config.php';

    if (!is_file($dbFile)) {
        echo "  db_config.php not found, skipped.\n";
    } else {
        require $dbFile;   // provides $conn

        if (!$conn->query("SHOW TABLES LIKE 'tutorial_videos'")->num_rows) {
            echo "  tutorial_videos table not found - run db_migrate_tutorial_videos.php first.\n";
        } else {
            $upd = $conn->prepare("UPDATE tutorial_videos SET src = ? WHERE id = ?");
            $sel = $conn->query("SELECT id, title, src FROM tutorial_videos WHERE type = 'file'");

            $changed = 0;
            while ($sel && $row = $sel->fetch_assoc()) {
                $src = (string) $row['src'];

                // Only touch rows still pointing at a local file.
                if (strncmp($src, 'assets/videos/', 14) !== 0) {
                    continue;
                }

                $base = basename($src);
                $key  = $prefix . $base;

                if (!in_array($base, $files, true)) {
                    printf("  %-28s no matching file, left alone\n", mb_strimwidth($row['title'], 0, 27, '..'));
                    continue;
                }

                $stored = 's3:' . $key;
                $upd->bind_param('si', $stored, $row['id']);

                if ($upd->execute()) {
                    printf("  %-28s -> %s\n", mb_strimwidth($row['title'], 0, 27, '..'), $stored);
                    $changed++;
                } else {
                    printf("  %-28s FAILED: %s\n", mb_strimwidth($row['title'], 0, 27, '..'), $upd->error);
                }
            }
            $upd->close();
            echo "  $changed row(s) repointed at the bucket.\n";
        }
        $conn->close();
    }
}

if ($failed === 0 && !$dryRun) {
    echo "\nNext: set 'enabled' => true in s3_config.php.\n";
}

exit($failed === 0 ? 0 : 1);
