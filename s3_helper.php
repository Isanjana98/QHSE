<?php
/**
 * s3_helper.php
 * =================================================================
 * Central media layer for the QHSE system.
 *
 * HOW PATHS ARE STORED
 * --------------------
 * A media reference saved in the database is one of three things:
 *
 *   "s3:permits/abc123.jpg"      -> an object in the private bucket
 *   "uploads/permits/abc.jpg"    -> a legacy file on local disk
 *   "https://example.com/x.jpg"  -> an absolute URL
 *
 * The "s3:" marker is what distinguishes them. Legacy rows keep
 * working untouched, so this can be rolled out without migrating any
 * existing data.
 *
 * ALWAYS render a stored path through media_url(). Never echo a raw
 * column value into an <img src> any more.
 *
 * GRACEFUL DEGRADATION
 * --------------------
 * If s3_config.php is missing, or 'enabled' is false, or the AWS SDK
 * is not installed, every upload silently falls back to the original
 * local-disk behaviour. The site keeps working either way.
 * =================================================================
 */

if (!defined('S3_HELPER_LOADED')) {
    define('S3_HELPER_LOADED', true);

    $__s3_autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($__s3_autoload)) {
        require_once $__s3_autoload;
    }

    /** Marker that prefixes every S3-backed path stored in the DB. */
    define('S3_MARKER', 's3:');

    /**
     * Where each logical group lives when S3 is switched OFF.
     * These are exactly the folders the system used before S3.
     */
    define('S3_LOCAL_FALLBACK', [
        'videos'   => 'assets/videos/',
        'permits'  => 'uploads/permits/',
        'work'     => 'uploads/',
        'id'       => 'uploads/id_photos/',
        'profiles' => 'assets/images/profiles/',
    ]);


    /* =============================================================
     | CONFIGURATION
     ============================================================= */

    /**
     * Read one value from the environment, whichever way the host
     * exposes it. Returns null when it is not set anywhere.
     *
     * Needed because this server runs variables_order = "GPCS": the
     * "E" is absent, so under Apache getenv() cannot see real
     * environment variables. $_SERVER covers SetEnv / fastcgi_param.
     */
    function s3_env_value(string $name): ?string
    {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $v) {
            if ($v !== null && $v !== false && $v !== '') {
                return (string) $v;
            }
        }
        return null;
    }

    function s3_config(?string $key = null, $default = null)
    {
        static $cfg = null;

        if ($cfg === null) {
            $file = __DIR__ . '/s3_config.php';
            $cfg  = file_exists($file) ? (array) require $file : [];

            // An environment variable, where one is set, overrides the
            // file. Applied here so that s3_config.php stays plain
            // literal values and is hard to fill in wrongly.
            $envMap = [
                'enabled'    => 'S3_ENABLED',
                'bucket'     => 'S3_BUCKET',
                'region'     => 'S3_REGION',
                'key'        => 'AWS_ACCESS_KEY_ID',
                'secret'     => 'AWS_SECRET_ACCESS_KEY',
                'url_secret' => 'S3_URL_SECRET',
            ];

            foreach ($envMap as $cfgKey => $envName) {
                $v = s3_env_value($envName);
                if ($v !== null) {
                    $cfg[$cfgKey] = ($cfgKey === 'enabled')
                        ? filter_var($v, FILTER_VALIDATE_BOOLEAN)
                        : $v;
                }
            }

            // Tolerate 'true'/'1'/'yes' written as a string in the file.
            if (isset($cfg['enabled']) && !is_bool($cfg['enabled'])) {
                $cfg['enabled'] = filter_var($cfg['enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            // Trim stray whitespace from pasted credentials - a trailing
            // space or newline in a secret key produces a signature
            // error that is very hard to read.
            foreach (['bucket', 'region', 'key', 'secret', 'url_secret'] as $k) {
                if (isset($cfg[$k]) && is_string($cfg[$k])) {
                    $cfg[$k] = trim($cfg[$k]);
                }
            }
        }

        if ($key === null) {
            return $cfg;
        }
        return $cfg[$key] ?? $default;
    }

    /**
     * True only when S3 is configured AND usable.
     */
    function s3_enabled(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }

        $ok = s3_config('enabled', false)
            && class_exists('\Aws\S3\S3Client')
            && s3_config('bucket')
            && strpos((string) s3_config('bucket'), 'CHANGE-ME') === false;

        return $ok;
    }

    /**
     * Path to a CA certificate bundle, or null to let PHP decide.
     *
     * PHP on Windows ships without one and WAMP does not set
     * curl.cainfo, so every HTTPS call from the SDK dies with
     * "cURL error 60: unable to get local issuer certificate".
     * Linux hosts have a system bundle and PHP finds it, so this
     * returns null there and nothing changes.
     *
     * Set curl.cainfo in php.ini to make this unnecessary.
     */
    function s3_ca_bundle(): ?string
    {
        static $resolved = false;
        static $path     = null;

        if ($resolved) {
            return $path;
        }
        $resolved = true;

        // PHP already knows where its certificates are.
        if (ini_get('curl.cainfo') || ini_get('openssl.cafile')) {
            return $path = null;
        }

        $candidates = [
            __DIR__ . '/cacert.pem',                                    // drop one here to override
            'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt', // Git for Windows
            'C:/wamp64/bin/php/cacert.pem',
            '/etc/ssl/certs/ca-certificates.crt',                       // Debian / Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',                         // RHEL / CentOS
        ];

        foreach ($candidates as $c) {
            if (is_readable($c)) {
                return $path = $c;
            }
        }
        return $path = null;
    }

    /**
     * Arguments shared by every S3 client in the project, so the
     * helper, the self-test and the upload tool behave identically.
     */
    function s3_client_args(array $extra = []): array
    {
        $args = [
            'version' => 'latest',
            'region'  => s3_config('region', 'us-east-1'),
        ];

        $k = s3_config('key');
        $s = s3_config('secret');
        if ($k && $s) {
            $args['credentials'] = ['key' => $k, 'secret' => $s];
        }

        if ($ca = s3_ca_bundle()) {
            $args['http'] = ['verify' => $ca];
        }

        return $extra + $args;
    }

    /**
     * Shared S3 client, or null when S3 is off / misconfigured.
     */
    function s3_client()
    {
        static $client = null;
        static $tried  = false;

        if ($tried) {
            return $client;
        }
        $tried = true;

        if (!s3_enabled()) {
            return null;
        }

        // Explicit keys are optional: with none, the SDK falls back to
        // the instance / task IAM role, which is the safer setup.
        try {
            $client = new \Aws\S3\S3Client(s3_client_args());
        } catch (\Throwable $e) {
            error_log('S3 client init failed: ' . $e->getMessage());
            $client = null;
        }

        return $client;
    }

    function s3_bucket(): string
    {
        return (string) s3_config('bucket', '');
    }

    /** Key prefix ("folder") configured for a logical group. */
    function s3_prefix(string $group): string
    {
        $all = (array) s3_config('prefixes', []);
        return $all[$group] ?? ($group . '/');
    }


    /* =============================================================
     | PATH INSPECTION
     ============================================================= */

    function s3_is_key(?string $stored): bool
    {
        return $stored !== null
            && strncmp($stored, S3_MARKER, strlen(S3_MARKER)) === 0;
    }

    /** "s3:permits/a.jpg" -> "permits/a.jpg" */
    function s3_key_of(string $stored): string
    {
        return s3_is_key($stored)
            ? substr($stored, strlen(S3_MARKER))
            : $stored;
    }

    function s3_is_absolute_url(?string $stored): bool
    {
        return $stored !== null && preg_match('#^https?://#i', $stored) === 1;
    }


    /* =============================================================
     | URL SIGNING
     |
     | media.php only accepts a key accompanied by a matching HMAC,
     | so a logged-in user cannot hand-edit the parameter to reach an
     | object they were never shown.
     ============================================================= */

    function media_sign(string $key): string
    {
        $secret = (string) s3_config('url_secret', '');
        return substr(hash_hmac('sha256', $key, $secret), 0, 32);
    }

    function media_verify(string $key, string $sig): bool
    {
        return hash_equals(media_sign($key), $sig);
    }


    /* =============================================================
     | RENDERING  -  use this everywhere an image/video is displayed
     ============================================================= */

    /**
     * Turn a stored path into something a browser can load.
     *
     *   s3:permits/a.jpg  -> media.php?p=cGVybWl0cy9hLmpwZw&s=<hmac>
     *   uploads/a.jpg     -> uploads/a.jpg        (unchanged)
     *   https://host/a    -> https://host/a       (unchanged)
     *   null / ""         -> ""
     */
    function media_url(?string $stored): string
    {
        $stored = trim((string) $stored);

        if ($stored === '') {
            return '';
        }
        if (s3_is_absolute_url($stored)) {
            return $stored;
        }
        if (!s3_is_key($stored)) {
            return $stored;                       // legacy local file
        }

        $key = s3_key_of($stored);
        $p   = rtrim(strtr(base64_encode($key), '+/', '-_'), '=');

        return 'media.php?p=' . $p . '&s=' . media_sign($key);
    }

    /**
     * Run selected columns of a DB row through media_url().
     *
     * Use this immediately before json_encode()ing a row for the
     * browser, so client-side templates can drop the value into an
     * <img src> without knowing anything about S3.
     *
     *   $row = media_rowify($row, ['id_front_path', 'id_back_path']);
     */
    function media_rowify(array $row, array $columns): array
    {
        foreach ($columns as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = media_url($row[$col]);
            }
        }
        return $row;
    }

    /**
     * Drop-in replacement for the old  file_exists($path)  guards.
     * For S3 keys this trusts the stored value instead of paying for
     * a HeadObject round trip on every page render.
     */
    function media_exists(?string $stored): bool
    {
        $stored = trim((string) $stored);

        if ($stored === '') {
            return false;
        }
        if (s3_is_key($stored) || s3_is_absolute_url($stored)) {
            return true;
        }
        return file_exists(__DIR__ . '/' . ltrim($stored, '/'))
            || file_exists($stored);
    }


    /* =============================================================
     | WRITING
     ============================================================= */

    function s3_guess_content_type(string $path, string $fallbackName = ''): string
    {
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = @finfo_file($fi, $path);
                @finfo_close($fi);
                if ($mime) {
                    return $mime;
                }
            }
        }

        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif',  'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'mp4' => 'video/mp4',  'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'pdf' => 'application/pdf',
        ];
        $ext = strtolower(pathinfo($fallbackName !== '' ? $fallbackName : $path, PATHINFO_EXTENSION));

        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * Store one uploaded file.
     *
     * @param string $tmpPath      $_FILES[...]['tmp_name']
     * @param string $originalName $_FILES[...]['name'] - used for the extension
     * @param string $group        videos|permits|work|id|profiles
     * @param string $nameHint     optional readable prefix for the filename
     *
     * @return string|false  Stored path to persist in the DB, or false.
     *                       "s3:<key>" when S3 is on, otherwise the usual
     *                       relative local path.
     */
    function s3_store_upload(string $tmpPath, string $originalName, string $group, string $nameHint = '')
    {
        $key      = s3_build_key($group, $originalName, $nameHint);
        $filename = basename($key);

        /* ---------- S3 path ---------- */
        if (s3_enabled() && ($client = s3_client())) {
            try {
                $client->putObject([
                    'Bucket'      => s3_bucket(),
                    'Key'         => $key,
                    'SourceFile'  => $tmpPath,
                    'ContentType' => s3_guess_content_type($tmpPath, $originalName),
                ]);
                return S3_MARKER . $key;
            } catch (\Throwable $e) {
                error_log('S3 upload failed (' . $key . '): ' . $e->getMessage());
                // fall through to local disk rather than losing the file
            }
        }

        /* ---------- local fallback ---------- */
        $dir = S3_LOCAL_FALLBACK[$group] ?? 'uploads/';
        $abs = __DIR__ . '/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true) && !is_dir($abs)) {
            return false;
        }

        $dest = $abs . $filename;
        $ok   = is_uploaded_file($tmpPath)
              ? move_uploaded_file($tmpPath, $dest)
              : @copy($tmpPath, $dest);

        return $ok ? $dir . $filename : false;
    }

    /**
     * Mint a presigned PUT URL so the browser can send a file straight
     * to S3, bypassing PHP entirely.
     *
     * This is not an optimisation. This server has
     * upload_max_filesize=2M and post_max_size=8M, so a 180 MB
     * tutorial video cannot be posted through PHP at all.
     *
     * The bucket needs a CORS rule allowing PUT from the site's
     * origin - see S3_SETUP.md.
     *
     * @return array|null  ['url' => ..., 'key' => ..., 'stored' => 's3:<key>']
     */
    function s3_presign_put(string $key, string $contentType, string $ttl = '+30 minutes'): ?array
    {
        if (!($client = s3_client())) {
            return null;
        }

        try {
            $cmd = $client->getCommand('PutObject', [
                'Bucket'      => s3_bucket(),
                'Key'         => $key,
                'ContentType' => $contentType,
            ]);

            $request = $client->createPresignedRequest($cmd, $ttl);

            return [
                'url'    => (string) $request->getUri(),
                'key'    => $key,
                'stored' => S3_MARKER . $key,
            ];
        } catch (\Throwable $e) {
            error_log('S3 presign PUT failed for ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build a safe, collision-proof object key for a given group.
     */
    function s3_build_key(string $group, string $originalName, string $nameHint = ''): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext);
        if ($ext === '') {
            $ext = 'bin';
        }

        $hint = preg_replace('/[^A-Za-z0-9_-]/', '', $nameHint);

        return s3_prefix($group)
             . ($hint !== '' ? $hint . '_' : '')
             . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
    }

    /**
     * Delete a stored object/file. Safe to call on any of the three
     * stored-path shapes.
     */
    function media_delete(?string $stored): bool
    {
        $stored = trim((string) $stored);
        if ($stored === '' || s3_is_absolute_url($stored)) {
            return false;
        }

        if (s3_is_key($stored)) {
            if (!($client = s3_client())) {
                return false;
            }
            try {
                $client->deleteObject([
                    'Bucket' => s3_bucket(),
                    'Key'    => s3_key_of($stored),
                ]);
                return true;
            } catch (\Throwable $e) {
                error_log('S3 delete failed: ' . $e->getMessage());
                return false;
            }
        }

        $abs = __DIR__ . '/' . ltrim($stored, '/');
        return file_exists($abs) ? @unlink($abs) : false;
    }

}
