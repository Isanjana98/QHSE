# S3 Media Storage — Setup Guide

The QHSE system stores its media in a private Amazon S3 bucket instead of on the
web server's own disk. This guide covers provisioning the AWS side and switching
the application over.

**Nothing changes until you set `'enabled' => true` in `s3_config.php`.** Until
then every upload goes to local disk exactly as before.

One exception: **tutorial video upload only works with S3 on.** This server has
`upload_max_filesize = 2M` and the videos run to 180 MB, so they cannot go
through PHP at all — the browser sends them straight to the bucket.

---

## 1. What moves, and what does not

| Media | Before | After |
|---|---|---|
| Tutorial videos (521 MB) | `assets/videos/` | `videos/` in the bucket |
| Permit photographs | `uploads/permits/`, `uploads/` | `permits/` |
| Work-completion photographs | `uploads/` | `work-completion/` |
| Worker NIC scans | `uploads/id_photos/` | `id-photos/` |
| Supervisor profile pictures | `assets/images/profiles/` | `profiles/` |
| UI icons and logos | `assets/images/` | unchanged — stay local |

Files already on disk are **not** migrated. They keep working: the application
recognises a legacy path and serves it from disk exactly as before. Only new
uploads go to S3. See §8 if you later want to move the old files too.

---

## 2. Create the bucket

In the S3 console:

1. **Create bucket** — pick a name and a region close to your users
   (`ap-south-1` Mumbai is the nearest to Sri Lanka).
2. **Block Public Access** — leave all four boxes **ticked**. The bucket must
   stay private; the application hands out short-lived signed links instead.
3. Default encryption (SSE-S3) is recommended and costs nothing.

You do **not** need a bucket policy.

### CORS — required

The admin video uploader PUTs files directly from the browser to S3, so the
bucket must allow it. Without this rule, uploads fail with a network error.
Replace the origins with your real site address.

```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["PUT"],
    "AllowedOrigins": [
      "https://your-site-domain",
      "http://localhost"
    ],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3000
  }
]
```

Downloads need no CORS rule: images and videos are loaded through ordinary
`<img>` and `<video>` tags, which are not subject to it.

---

## 3. Create an IAM user

Create an IAM user with programmatic access and attach this inline policy.
Replace `YOUR-BUCKET-NAME` in both places.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ObjectAccess",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
    },
    {
      "Sid": "BucketListing",
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::YOUR-BUCKET-NAME"
    }
  ]
}
```

That is the minimum the application needs. It cannot create, delete or
reconfigure buckets.

> On EC2, attach this as a **role** to the instance instead and leave
> `key`/`secret` empty. The SDK picks the role up automatically and there are
> no long-lived keys to leak.

---

## 4. Configure the application

```bash
cp s3_config.example.php s3_config.php
```

Then edit `s3_config.php`:

```php
'enabled' => false,                 // leave false for now
'bucket'  => 'your-bucket-name',
'region'  => 'ap-south-1',
'key'     => 'AKIA...',
'secret'  => '...',
```

`s3_config.php` is gitignored and must never be committed. A random
`url_secret` has already been generated for you — keep it stable, since
changing it breaks links on pages a user currently has open (harmless, but
they see a broken image until they reload).

> **Why not environment variables?** This server runs with
> `variables_order = "GPCS"`. The `E` is missing, so under the web server
> `getenv()` cannot see real environment variables even when they are set —
> only the command line can. `s3_env()` in the config also checks `$_SERVER`
> and `$_ENV`, which covers `SetEnv` in `.htaccess` and `fastcgi_param`, but
> writing the values into the gitignored file is the dependable option here.

---

## 5. Create the tutorial video table

```bash
php db_migrate_tutorial_videos.php
```

This creates `tutorial_videos`, seeds it with the eight videos that used to be
hardcoded in `tutorial.php` and `subcontractor_tutorials.php`, and repairs two
columns in `quiz_results` that never matched what the watch-progress code
writes (`videos_watched` was `tinyint(1)` holding a JSON array; `video_progress`
did not exist). It is safe to run more than once.

No command line? Open the file in a browser while logged in as admin.

---

## 6. Upload the existing videos

```bash
php tools/s3_upload_videos.php --dry-run   # check the plan
php tools/s3_upload_videos.php             # do it
```

This uploads everything in `assets/videos/` to the `videos/` prefix, then
repoints the matching `tutorial_videos.src` rows from `assets/videos/A.mp4` to
`s3:videos/A.mp4`. Large files go up multipart, so an interrupted run can
simply be repeated — files already in the bucket are skipped unless you pass
`--force`.

Expect this to take a while: it is roughly 521 MB.

---

## 7. Switch it on

Set `'enabled' => true` in `s3_config.php`, then check:

- a tutorial video plays (`tutorial.php`)
- a new permit photo uploads and displays
- an existing, pre-S3 permit photo still displays
- a worker's NIC scan displays for an admin
- a video uploads from `admin_tutorials.php` and appears on the tutorial pages
- opening a `media.php?...` link in a logged-out private window gives **403**

To roll back, set `'enabled' => false`. New photo uploads return to local disk
and anything already in S3 keeps rendering, because stored paths are marked
individually rather than globally. Video *upload* stops working, since PHP
cannot accept files that large — everything already uploaded still plays.

---

## 8. Optional: migrate the files already on disk

Not done by default, and not required. To move the ~12 MB in `uploads/` and
`assets/images/profiles/` as well, copy the files up and rewrite these columns
to the `s3:<key>` form:

- `permit_images.image_path`
- `workers.id_front_path`, `workers.id_back_path`
- `supervisors.profile_pic`
- `service_provider_permits.admin_work_image`, `.supervisor_work_image`

Take a database backup first. Ask and this can be scripted.

---

## 9. Optional: CloudFront

Putting CloudFront in front of the bucket with an Origin Access Control would
cut latency and egress cost for the videos. Worthwhile follow-up, not required.

---

## Managing tutorial videos

`admin_tutorials.php` (admin sidebar → **Tutorial Videos**) is the single place
to add, rename, reorder, hide and delete them. Both `tutorial.php` and
`subcontractor_tutorials.php` read from the same table, so there is no longer a
PHP array to edit in two files.

Uploading: pick or drop a file, give it a title, press **Upload & publish**.
The browser reads the duration itself, asks PHP for a presigned URL, PUTs the
file straight to S3 with a progress bar, then records the metadata. Nothing
larger than 2 MB ever passes through PHP.

- `tutorial.php` shows only `type = 'file'` videos, because it gates the quiz on
  watch progress and a YouTube embed cannot report that.
- `subcontractor_tutorials.php` shows every active video.
- Watch progress is keyed by `tutorial_videos.id`, so reordering or deleting a
  video no longer shifts anybody's recorded progress.

---

## How it works

Stored paths take one of three shapes, and `media_url()` resolves all three:

```
s3:permits/abc123.jpg     -> media.php?p=<base64url key>&s=<hmac>
uploads/permits/old.jpg   -> uploads/permits/old.jpg   (unchanged, legacy)
https://host/x.jpg        -> https://host/x.jpg        (unchanged)
```

`media.php` is the only way to reach a private object. It checks for a live
session, verifies the HMAC so a signed-in user cannot hand-edit the key, then
issues a 302 to a 15-minute presigned URL. The bytes travel straight from S3 to
the browser, so PHP only ever serves a redirect — video seeking and range
requests keep working normally.

### For developers

Never echo a raw media column into an `<img src>`. Use:

```php
media_url($row['image_path'])            // stored path -> browser URL
media_exists($row['profile_pic'])        // replaces file_exists()
media_delete($row['image_path'])         // removes from S3 or disk
media_rowify($row, ['id_front_path'])    // before json_encode() for JS
s3_store_upload($tmp, $name, 'permits')  // server-side upload (small files)
s3_presign_put($key, $contentType)       // browser-side upload (large files)
s3_build_key('videos', $filename)        // safe, collision-proof object key
```

---

## Deployment note

`vendor/` is in `.gitignore` because the AWS SDK brings it to ~70 MB and 3,900
files. After deploying, run:

```bash
composer install --no-dev
```

If your host has no Composer, upload `vendor/` over FTP instead. **If you
deploy by copying the Git working tree, nothing changes for you** — the folder
is still on disk, just no longer tracked.

### PHP version pin — do not remove

`composer.json` contains:

```json
"config": { "platform": { "php": "8.1.0" } }
```

This exists because the CLI and the web server run **different PHP versions**:

| | Version |
|---|---|
| Apache (`httpd.conf` loads `php8.3.14/php8apache2_4.dll`) | **8.3.14** |
| Command line (`php` on PATH) | **8.4.0** |

Without the pin, Composer resolves against whichever PHP ran the install. A
`composer require` from the 8.4 command line pulled in `symfony/filesystem`
v8.0, which requires PHP ≥ 8.4, and every page then died with:

```
Composer detected issues in your platform:
Your Composer dependencies require a PHP version ">= 8.4.0".
```

The pin makes Composer resolve for PHP 8.1 — the minimum `aws/aws-sdk-php`
itself supports — so the installed tree runs on anything from 8.1 to 8.4, the
local Apache and the production host alike.

**The production server must run PHP 8.1 or newer.** The AWS SDK does not
support anything older, so there is no lower pin available; on an older PHP the
SDK cannot be used at all.
