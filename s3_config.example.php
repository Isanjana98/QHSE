<?php
/**
 * s3_config.example.php
 * ---------------------------------------------------------------
 * TEMPLATE ONLY - never put real credentials in this file. It is
 * NOT gitignored and will be committed.
 *
 * Copy it and edit the copy:
 *
 *   cp s3_config.example.php s3_config.php
 *
 * s3_config.php IS gitignored, so it is never committed - and
 * therefore never deployed by git either. Create it separately on
 * each server.
 *
 * Plain values only. Put what AWS gave you between the quotes;
 * there is no second argument to get wrong.
 *
 * Optional: setting the environment variables S3_ENABLED, S3_BUCKET,
 * S3_REGION, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY or
 * S3_URL_SECRET overrides the matching value below. That is handled
 * in s3_helper.php, so nothing here changes for it to work.
 * ---------------------------------------------------------------
 */

return [

    /* ----------------------------------------------------------
     | Master switch.
     |
     | false -> uploads are written to local disk, as before S3.
     | true  -> uploads go to the bucket.
     |
     | Switch to true only once the keys below are right AND
     | tools/s3_upload_videos.php has finished. Admin video upload
     | needs this true: the browser sends videos straight to S3,
     | because the largest exceed what PHP will accept.
     ---------------------------------------------------------- */
    'enabled' => false,

    /* ----------------------------------------------------------
     | Bucket identity.
     | region must be the region the bucket really lives in - a
     | mismatch produces a PermanentRedirect error.
     ---------------------------------------------------------- */
    'bucket' => 'CHANGE-ME-bucket-name',
    'region' => 'ap-south-1',

    /* ----------------------------------------------------------
     | Credentials.
     |
     | Leave both as empty strings if the server has an IAM role -
     | the SDK finds it by itself and no long-lived key can leak.
     ---------------------------------------------------------- */
    'key'    => '',
    'secret' => '',

    /* ----------------------------------------------------------
     | Key prefixes - the "folders" inside the bucket.
     ---------------------------------------------------------- */
    'prefixes' => [
        'videos'   => 'videos/',           // tutorial videos
        'permits'  => 'permits/',          // permit photographs
        'work'     => 'work-completion/',  // work completion photographs
        'id'       => 'id-photos/',        // worker NIC scans  (SENSITIVE)
        'profiles' => 'profiles/',         // supervisor avatars
    ],

    /* ----------------------------------------------------------
     | How long a presigned link stays valid. media.php mints one
     | per request, so it only has to outlive a single fetch.
     ---------------------------------------------------------- */
    'link_ttl' => '+30 minutes',

    /* ----------------------------------------------------------
     | Signs the ?p= parameter on media.php so a signed-in user
     | cannot hand-craft a URL for an object never shown to them.
     | Generate: php -r "echo bin2hex(random_bytes(32));"
     | Use a different random value on each server.
     ---------------------------------------------------------- */
    'url_secret' => 'CHANGE-ME-generate-a-random-64-character-hex-string',
];
