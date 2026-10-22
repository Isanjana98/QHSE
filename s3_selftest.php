<?php
/**
 * s3_selftest.php
 * =================================================================
 * Checks an S3 setup end to end and says exactly what is wrong.
 *
 *   php s3_selftest.php
 *
 * or open it in a browser while logged in as admin.
 *
 * It writes one tiny object (a few bytes under the prefix
 * _selftest/) and deletes it again. Nothing else is touched.
 *
 * Safe to run with 'enabled' => false - it tests the credentials
 * directly, so you can confirm everything works BEFORE switching
 * the system over.
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

require_once __DIR__ . '/s3_helper.php';

$pass = $fail = $warn = 0;

function ok(string $m, string $detail = ''): void   { global $pass; $pass++; printf("  [ OK ]   %-34s %s\n", $m, $detail); }
function bad(string $m, string $detail = ''): void  { global $fail; $fail++; printf("  [FAIL]   %-34s %s\n", $m, $detail); }
function warn(string $m, string $detail = ''): void { global $warn; $warn++; printf("  [WARN]   %-34s %s\n", $m, $detail); }
function note(string $m): void                      { echo "           " . $m . "\n"; }

function mask(?string $v): string
{
    $v = (string) $v;
    if ($v === '')            { return '(empty)'; }
    if (strlen($v) <= 8)      { return str_repeat('*', strlen($v)); }
    return substr($v, 0, 4) . str_repeat('*', 6) . substr($v, -2) . ' [' . strlen($v) . ' chars]';
}

echo "=== S3 self-test ===\n\n";

/* -----------------------------------------------------------------
 | 1. Configuration
 ----------------------------------------------------------------- */
echo "Configuration\n";

if (!file_exists(__DIR__ . '/s3_config.php')) {
    bad('s3_config.php exists', 'missing - copy s3_config.example.php to s3_config.php');
    note('It is gitignored, so it is never deployed by git. Create it on each server.');
    exit(1);
}
ok('s3_config.php exists');

$bucket = (string) s3_config('bucket');
$region = (string) s3_config('region');
$key    = (string) s3_config('key');
$secret = (string) s3_config('secret');

if ($bucket === '' || strpos($bucket, 'CHANGE-ME') !== false) {
    bad('bucket set', $bucket ?: '(empty)');
} else {
    ok('bucket set', $bucket);
}

ok('region set', $region ?: '(empty)');

if ($key === '' && $secret === '') {
    warn('credentials', 'both empty - assuming an IAM role is attached');
} else {
    if (preg_match('/^(AKIA|ASIA)[A-Z0-9]{16}$/', $key)) {
        ok('access key id looks valid', mask($key));
    } else {
        bad('access key id looks valid', mask($key) . ' - expected 20 chars starting AKIA');
        note('A common mistake is pasting the value into the wrong place in s3_config.php.');
    }

    if (strlen($secret) === 40) {
        ok('secret key length', mask($secret));
    } else {
        bad('secret key length', mask($secret) . ' - an AWS secret key is exactly 40 chars');
    }
}

$urlSecret = (string) s3_config('url_secret');
if ($urlSecret === '' || strpos($urlSecret, 'CHANGE-ME') !== false) {
    bad('url_secret set', 'still the placeholder - generate one');
} else {
    ok('url_secret set', strlen($urlSecret) . ' chars');
}

printf("  %-9s %-34s %s\n", '[info]', "'enabled' flag",
    s3_config('enabled') ? 'true  (system is using S3)' : 'false (still writing to local disk)');

if (!class_exists('\Aws\S3\S3Client')) {
    bad('AWS SDK installed', 'run: composer install');
    exit(1);
}
ok('AWS SDK installed');

if ($fail > 0) {
    echo "\nFix the failures above before continuing.\n";
    exit(1);
}

/* -----------------------------------------------------------------
 | 2. Live calls
 |
 | Built here rather than via s3_client() so the test still runs
 | while 'enabled' is false.
 ----------------------------------------------------------------- */
echo "\nConnection\n";

if ($ca = s3_ca_bundle()) {
    ok('CA bundle', $ca);
    note('PHP has no curl.cainfo set; using this bundle so HTTPS works.');
} else {
    ok('CA bundle', 'using the system default');
}

try {
    $s3 = new \Aws\S3\S3Client(s3_client_args());
    ok('client created');
} catch (\Throwable $e) {
    bad('client created', $e->getMessage());
    exit(1);
}

/* --- where does the bucket actually live? --- */
$realRegion = null;
try {
    $loc = $s3->getBucketLocation(['Bucket' => $bucket]);
    $realRegion = $loc['LocationConstraint'] ?: 'us-east-1';

    if ($realRegion === $region) {
        ok('region matches bucket', $region);
    } else {
        bad('region matches bucket', "config says '$region' but the bucket is in '$realRegion'");
        note("Set  'region' => '$realRegion'  in s3_config.php.");
    }
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    if ($p = $e->getPrevious()) { $msg .= ' ' . $p->getMessage(); }
    if (stripos($msg, 'InvalidAccessKeyId') !== false) {
        bad('credentials accepted', 'InvalidAccessKeyId - the access key id is not recognised by AWS');
    } elseif (stripos($msg, 'SignatureDoesNotMatch') !== false) {
        bad('credentials accepted', 'SignatureDoesNotMatch - the secret key is wrong (or has a stray space)');
    } elseif (stripos($msg, 'NoSuchBucket') !== false) {
        bad('bucket exists', "no bucket named '$bucket'");
    } elseif (stripos($msg, 'AccessDenied') !== false) {
        warn('region lookup', 'IAM policy lacks s3:GetBucketLocation - harmless, continuing');
        note('Region cannot be auto-verified. If uploads fail with PermanentRedirect,');
        note('the region in s3_config.php is wrong for this bucket.');
    } else {
        bad('bucket reachable', substr(strtok($msg, "\n"), 0, 150));
    }
}

/* --- write / read / delete round trip --- */
$testKey = '_selftest/' . bin2hex(random_bytes(6)) . '.txt';
$body    = 'qhse-selftest ' . date('c');
$wrote   = false;

try {
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $testKey,
        'Body'        => $body,
        'ContentType' => 'text/plain',
    ]);
    $wrote = true;
    ok('PutObject (upload)', $testKey);
} catch (\Throwable $e) {
    $msg = strtok($e->getMessage(), "\n");
    bad('PutObject (upload)', substr($msg, 0, 150));
    if (stripos($msg, 'AccessDenied') !== false) {
        note('The IAM policy needs s3:PutObject on arn:aws:s3:::' . $bucket . '/*');
    }
}

if ($wrote) {
    try {
        $got = (string) $s3->getObject(['Bucket' => $bucket, 'Key' => $testKey])['Body'];
        $got === $body
            ? ok('GetObject (download)', 'content matches')
            : bad('GetObject (download)', 'content differs');
    } catch (\Throwable $e) {
        bad('GetObject (download)', substr(strtok($e->getMessage(), "\n"), 0, 150));
    }

    /* --- presigned GET, which is how media.php serves everything --- */
    try {
        $cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $testKey]);
        $url = (string) $s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $ch   = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25];
        if ($caB = s3_ca_bundle()) {
            $opts[CURLOPT_CAINFO] = $caB;   // same bundle the SDK uses
        }
        curl_setopt_array($ch, $opts);
        $resp    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($code === 200 && $resp === $body) {
            ok('presigned GET works', 'HTTP 200 - this is how media.php serves files');
        } elseif ($code === 0) {
            bad('presigned GET works', 'could not connect: ' . ($curlErr ?: 'unknown'));
        } else {
            bad('presigned GET works', 'HTTP ' . $code);
        }
    } catch (\Throwable $e) {
        bad('presigned GET works', substr(strtok($e->getMessage(), "\n"), 0, 150));
    }

    try {
        $s3->deleteObject(['Bucket' => $bucket, 'Key' => $testKey]);
        ok('DeleteObject (cleanup)', 'test object removed');
    } catch (\Throwable $e) {
        warn('DeleteObject (cleanup)', 'left behind: ' . $testKey);
    }
}

/* -----------------------------------------------------------------
 | 3. CORS - required for admin video upload
 ----------------------------------------------------------------- */
echo "\nBrowser upload (admin_tutorials.php)\n";

try {
    $cors  = $s3->getBucketCors(['Bucket' => $bucket]);
    $rules = $cors['CORSRules'] ?? [];

    $putAllowed = false;
    foreach ($rules as $r) {
        if (in_array('PUT', $r['AllowedMethods'] ?? [], true)) {
            $putAllowed = true;
            note('allowed origins: ' . implode(', ', $r['AllowedOrigins'] ?? []));
        }
    }

    $putAllowed
        ? ok('CORS allows PUT')
        : bad('CORS allows PUT', 'a CORS rule exists but none permits PUT');

} catch (\Throwable $e) {
    if (stripos($e->getMessage(), 'NoSuchCORSConfiguration') !== false) {
        bad('CORS allows PUT', 'no CORS configuration on the bucket');
        note('Video upload from the browser will fail without it. See S3_SETUP.md section 2.');
    } else {
        warn('CORS check', 'could not read CORS config (needs s3:GetBucketCors) - verify by hand');
    }
}

/* -----------------------------------------------------------------
 | Summary
 ----------------------------------------------------------------- */
printf("\n=== %d passed, %d warnings, %d failed ===\n", $pass, $warn, $fail);

if ($fail === 0) {
    echo "\nS3 is working.\n";
    if (!s3_config('enabled')) {
        echo "Next: run  php tools/s3_upload_videos.php  then set 'enabled' => true in s3_config.php.\n";
    }
} else {
    echo "\nFix the failures above, then run this again.\n";
}

exit($fail === 0 ? 0 : 1);
