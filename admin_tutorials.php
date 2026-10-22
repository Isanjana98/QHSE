<?php
/**
 * admin_tutorials.php
 * =================================================================
 * Admin screen for managing the safety tutorial videos that
 * tutorial.php and subcontractor_tutorials.php display.
 *
 * Uploads go straight from the browser to S3 using a presigned PUT.
 * They have to: this server accepts only 2 MB through PHP and the
 * videos run to 180 MB.
 * =================================================================
 */

session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

if (empty($_SESSION['tut_csrf'])) {
    $_SESSION['tut_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['tut_csrf'];

$tableReady = (bool) $conn->query("SHOW TABLES LIKE 'tutorial_videos'")->num_rows;

$videos = [];
if ($tableReady) {
    $res = $conn->query("
        SELECT id, title, description, type, src, duration, duration_seconds,
               file_size, sort_order, is_active
          FROM tutorial_videos
         ORDER BY sort_order ASC, id ASC
    ");
    while ($res && $row = $res->fetch_assoc()) {
        $videos[] = $row;
    }
}

$s3On       = s3_enabled();
$phpLimit   = ini_get('upload_max_filesize');
$totalBytes = array_sum(array_column($videos, 'file_size'));

/* When uploading is unavailable, say exactly which piece is missing
   rather than always pointing at the same line of the config. */
$s3Blockers = [];
if (!$s3On) {
    if (!file_exists(__DIR__ . '/s3_config.php')) {
        $s3Blockers[] = '<code>s3_config.php</code> does not exist on this server '
                      . '(it is gitignored, so it is never deployed &mdash; copy '
                      . '<code>s3_config.example.php</code> and fill it in here)';
    } else {
        if (!class_exists('\Aws\S3\S3Client')) {
            $s3Blockers[] = 'the AWS SDK is not installed &mdash; run <code>composer install</code>';
        }
        $bucket = (string) s3_config('bucket');
        if ($bucket === '' || strpos($bucket, 'CHANGE-ME') !== false) {
            $s3Blockers[] = '<code>bucket</code> is not set';
        }
        if (!s3_config('key') && !s3_config('secret')) {
            $s3Blockers[] = '<code>key</code> and <code>secret</code> are empty '
                          . '(fine if the server uses an IAM role)';
        }
        if (!s3_config('enabled', false)) {
            $s3Blockers[] = '<code>\'enabled\'</code> is still <code>false</code>';
        }
    }
}

function human_size($bytes): string
{
    $bytes = (float) $bytes;
    if ($bytes <= 0) {
        return '-';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));
    return round($bytes / (1024 ** $i), $i ? 1 : 0) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tutorial Videos | Hayleys Fentons</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="table_pagination.css">
<style>
:root{--primary-dark:#bb4c14;--primary:#da6913;--background:#F6F8F5;--surface:#FFFFFF;--border:#E4E9E2;}
body{background:var(--background);font-family:'Inter',sans-serif;color:#0E1F14;margin:0;overflow-x:hidden;}
.layout{display:flex;min-height:100vh;}

/* ---- sidebar (matches admin_dashboard.php) ---- */
.sidebar-wrap{width:100px;flex-shrink:0;background:var(--background);display:flex;flex-direction:column;
  align-items:flex-start;justify-content:center;padding:20px 0 20px 18px;position:sticky;top:0;height:100vh;
  z-index:1000;transition:width .3s cubic-bezier(.4,0,.2,1);}
.sidebar-wrap:hover{width:240px;}
.sidebar-logo{height:60px;width:auto;object-fit:contain;margin-bottom:25px;transition:all .3s ease;}
.vertical-nav{background:linear-gradient(180deg,#ea580c 0%,#2f7106 50%,#15803d 100%);width:75px;
  height:calc(100vh - 120px);border-radius:40px;display:flex;flex-direction:column;align-items:center;
  justify-content:space-between;padding:25px 0;box-shadow:0 10px 25px rgba(0,0,0,.15);
  transition:width .3s cubic-bezier(.4,0,.2,1);overflow:hidden;white-space:nowrap;}
.sidebar-wrap:hover .vertical-nav{width:210px;align-items:flex-start;padding-left:20px;padding-right:20px;}
.nav-links-group{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:15px;width:100%;flex:1;}
.sidebar-wrap:hover .nav-links-group{align-items:flex-start;}
.nav-icon-link{width:50px;height:50px;display:flex;align-items:center;justify-content:center;color:#fff;
  font-size:20px;border-radius:18px;text-decoration:none;transition:all .25s ease;position:relative;gap:15px;}
.sidebar-wrap:hover .nav-icon-link{width:100%;justify-content:flex-start;padding-left:12px;border-radius:14px;}
.nav-text{display:none;font-size:.95rem;font-weight:600;color:#fff;}
.sidebar-wrap:hover .nav-text{display:inline-block;}
.nav-icon-link:hover,.nav-icon-link.active{background:rgba(255,255,255,.35);color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.1);}
.logout-pill-btn{width:50px;height:50px;background:#a13d07;border-radius:25px;display:flex;align-items:center;
  justify-content:center;color:#fff;text-decoration:none;box-shadow:inset 0 2px 4px rgba(0,0,0,.2);
  transition:all .3s ease;gap:12px;}
.sidebar-wrap:hover .logout-pill-btn{width:100%;border-radius:14px;justify-content:flex-start;padding-left:14px;}
.logout-pill-btn:hover{background:#873105;color:#fff;}

/* ---- Top Welcome Box Styling ---- */
.welcome-top-box {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
}

.welcome-left-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.greeting-title-area {
    font-family: 'Manrope', sans-serif;
    font-weight: 800;
    font-size: 1.35rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.animated-icon {
    display: inline-block;
    font-size: 1.25rem;
    animation: bounceIcon 1.5s infinite ease-in-out;
    transform-origin: bottom center;
}

@keyframes bounceIcon {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-4px) rotate(8deg); }
}

.greeting-sub-text {
    font-size: 0.8rem;
    color: #6b7280;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.welcome-right-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.nav-pill-box {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    padding: 6px 12px;
    border-radius: 18px;
    color: #374151;
    font-size: 11px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
}

.notification-btn {
    width: 38px;
    height: 38px;
    background: #fff5ed;
    border: 1px solid #fed7aa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ea580c;
    position: relative;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.notification-container { position: relative; }
.notification-btn:hover { background: #ffedd5; }
.notification-btn:focus-visible { outline: 3px solid rgba(234, 88, 12, .3); outline-offset: 2px; }
.notification-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: #ef4444;
    color: white;
    font-size: 9px;
    font-weight: 700;
    padding: 2px 4px;
    border-radius: 10px;
    border: 2px solid #fff;
}
.notification-panel {
    display: none;
    position: absolute;
    top: calc(100% + 12px);
    right: 0;
    width: min(390px, calc(100vw - 32px));
    max-height: min(480px, calc(100vh - 110px));
    overflow: hidden;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 18px 44px rgba(15, 23, 42, .18);
    z-index: 1100;
}
.notification-panel.show { display: flex; flex-direction: column; }
.notification-panel-header { padding: 14px 16px 11px; border-bottom: 1px solid #eef0f2; }
.notification-panel-header strong { display: block; color: #17211a; font-size: 14px; }
.notification-panel-header span { color: #6b7280; font-size: 11px; }
.notification-list { overflow-y: auto; }
.notification-item {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 12px 16px;
    color: #1f2937;
    text-decoration: none;
    border-bottom: 1px solid #f1f2f3;
    transition: background .15s ease;
}
.notification-item:hover { background: #fff7ed; color: #1f2937; }
.notification-item.is-unread { background: #fffaf5; }
.notification-item-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    color: #c2410c;
    background: #fff0e4;
    font-size: 15px;
}
.notification-item-copy { min-width: 0; flex: 1; }
.notification-item-copy strong { display: block; font-size: 12px; line-height: 1.4; }
.notification-item-copy span { display: block; overflow: hidden; color: #667085; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.notification-item-copy time { display: block; margin-top: 3px; color: #98a1ad; font-size: 10px; }
.notification-empty { padding: 24px 16px; color: #6b7280; font-size: 12px; text-align: center; }

/* ---- main ---- */
.main-content-wrap{flex:1;min-width:0;padding:28px 34px 60px;}
.page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px;}
.page-head h1{font-size:22px;font-weight:700;margin:0;}
.page-head p{margin:2px 0 0;color:#5b6b60;font-size:13px;}
.card-box{background:var(--surface);border:1px solid var(--border);border-radius:14px;
  box-shadow:0 4px 14px -4px rgba(15,23,42,.06);padding:20px;margin-bottom:20px;}
.card-box h2{font-size:15px;font-weight:700;margin:0 0 14px;display:flex;align-items:center;gap:8px;}

/* ---- dropzone ---- */
.dropzone{border:2px dashed #cfd9d1;border-radius:12px;padding:30px 20px;text-align:center;cursor:pointer;
  transition:all .2s ease;background:#fcfdfc;}
.dropzone:hover,.dropzone.drag{border-color:var(--primary);background:#fff7f1;}
.dropzone i{font-size:34px;color:var(--primary);}
.dropzone .dz-main{font-weight:600;margin-top:8px;}
.dropzone .dz-sub{font-size:12px;color:#6b7a70;margin-top:2px;}

.file-pill{display:flex;align-items:center;gap:12px;background:#f3f6f4;border:1px solid var(--border);
  border-radius:10px;padding:10px 14px;margin-top:14px;font-size:13px;}
.file-pill .fp-name{font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.progress{height:8px;border-radius:10px;background:#e8ede9;}
.progress-bar{background:var(--primary);}

table.vid-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;}
table.vid-table th{text-align:left;font-size:11px;letter-spacing:.04em;text-transform:uppercase;
  color:#6b7a70;font-weight:700;padding:0 10px 10px;border-bottom:1px solid var(--border);}
table.vid-table td{padding:12px 10px;border-bottom:1px solid #eef2ef;vertical-align:middle;}
table.vid-table tr:last-child td{border-bottom:none;}
.vid-title{font-weight:600;}
.vid-desc{color:#6b7a70;font-size:12px;margin-top:2px;max-width:420px;}
.badge-off{background:#e5e7eb;color:#4b5563;}
.icon-btn{border:1px solid var(--border);background:#fff;border-radius:8px;width:30px;height:30px;
  display:inline-flex;align-items:center;justify-content:center;color:#44554a;transition:all .15s;}
.icon-btn:hover{background:var(--primary);border-color:var(--primary);color:#fff;}
.icon-btn:disabled{opacity:.35;cursor:not-allowed;}
.icon-btn.danger:hover{background:#dc2626;border-color:#dc2626;}
.muted{color:#6b7a70;}
</style>
<link rel="stylesheet" href="admin_sidebar.css">
</head>
<body>
<div class="layout">

    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- ============ MAIN ============ -->
    <div class="main-content-wrap">
        
        <!-- Top White Box with Typing Greeting, Compliance Division, Date/Time & Notification Icon -->
        <div class="welcome-top-box">
            <div class="welcome-left-content">
                <div class="greeting-title-area">
                    <span id="greetingIconContainer"></span>
                    <span id="typingGreeting"></span>
                </div>
                <div class="greeting-sub-text">
                    <i class="bi bi-shield-check text-warning"></i> Compliance Division &bull; Manage permits and team activities efficiently
                </div>
            </div>

            <div class="welcome-right-controls">
                <div class="nav-pill-box">
                    <i class="bi bi-calendar3 text-warning"></i>
                    <span><?= date('D, d M Y'); ?></span>
                </div>
                <div class="nav-pill-box">
                    <i class="bi bi-clock-history text-warning"></i>
                    <span id="liveClock">Loading time...</span>
                </div>
                <div class="notification-container">
                    <button type="button" class="notification-btn" id="notificationButton" title="Notifications" aria-label="Notifications" aria-expanded="false" aria-controls="notificationPanel">
                        <i class="bi bi-bell-fill fs-5"></i>
                        <span class="notification-badge" id="notificationBadge" hidden></span>
                    </button>
                    <section class="notification-panel" id="notificationPanel" aria-label="Recent admin updates">
                        <div class="notification-panel-header">
                            <strong>Admin updates</strong>
                            <span>Recent permits, inspections and registrations</span>
                        </div>
                        <div class="notification-list" id="notificationList" aria-live="polite">
                            <div class="notification-empty">Loading updates...</div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <div class="page-head">
            <div>
                <h1>Tutorial Videos</h1>
                <p>
                    <?= count($videos) ?> video<?= count($videos) === 1 ? '' : 's' ?>
                    <?php if ($totalBytes > 0): ?> &middot; <?= human_size($totalBytes) ?> total<?php endif; ?>
                    &middot; shown on the subcontractor tutorial &amp; quiz pages
                </p>
            </div>
        </div>

        <div id="alertZone"></div>

        <?php if (!$tableReady): ?>
            <div class="alert alert-danger">
                <strong>Setup needed.</strong> The <code>tutorial_videos</code> table does not exist yet.
                Run <code>php db_migrate_tutorial_videos.php</code> first.
            </div>
        <?php else: ?>

        <!-- ---------------- UPLOAD ---------------- -->
        <div class="card-box">
            <h2><i class="bi bi-cloud-arrow-up"></i> Add a video</h2>

            <?php if (!$s3On): ?>
                <div class="alert alert-warning mb-3">
                    <strong>Upload is unavailable until S3 is switched on.</strong><br>
                    Videos go straight from the browser to the bucket, so uploading needs S3 enabled.
                    PHP here accepts only <strong><?= htmlspecialchars($phpLimit) ?></strong> per upload,
                    which the longer tutorial videos already exceed.

                    <?php if ($s3Blockers): ?>
                        <div class="mt-2 mb-1">Still to do:</div>
                        <ul class="mb-1 ps-4">
                            <?php foreach ($s3Blockers as $b): ?>
                                <li><?= $b ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="mt-2">
                        See <code>S3_SETUP.md</code> for the full steps.
                        You can still rename, reorder, hide and delete the videos below.
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="dropzone <?= $s3On ? '' : 'opacity-50' ?>" id="dropzone">
                        <i class="bi bi-film"></i>
                        <div class="dz-main">Drop a video here, or click to choose</div>
                        <div class="dz-sub">MP4, WebM, MOV or M4V &middot; up to 2 GB</div>
                    </div>
                    <input type="file" id="fileInput" accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov,.m4v" hidden>

                    <div id="filePill" class="file-pill d-none">
                        <i class="bi bi-file-earmark-play fs-5 text-secondary"></i>
                        <div class="fp-name" id="fpName">-</div>
                        <div class="muted" id="fpMeta">-</div>
                        <button class="icon-btn danger" id="fpClear" title="Remove"><i class="bi bi-x-lg"></i></button>
                    </div>

                    <div id="uploadProgress" class="mt-3 d-none">
                        <div class="d-flex justify-content-between mb-1" style="font-size:12px;">
                            <span id="upLabel">Uploading…</span>
                            <span id="upPct">0%</span>
                        </div>
                        <div class="progress"><div class="progress-bar" id="upBar" style="width:0%"></div></div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <label class="form-label fw-semibold" style="font-size:12px;">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm mb-2" id="newTitle" maxlength="255"
                           placeholder="e.g. Working at Height Basics">

                    <label class="form-label fw-semibold" style="font-size:12px;">Description</label>
                    <textarea class="form-control form-control-sm mb-3" id="newDesc" rows="3"
                              placeholder="One or two lines explaining what the video covers."></textarea>

                    <button class="btn btn-sm w-100 text-white" style="background:var(--primary);"
                            id="btnUpload" <?= $s3On ? '' : 'disabled' ?>>
                        <i class="bi bi-upload me-1"></i> Upload &amp; publish
                    </button>
                </div>
            </div>
        </div>

        <!-- ---------------- LIST ---------------- -->
        <div class="card-box">
            <h2><i class="bi bi-list-ol"></i> Current videos</h2>

            <?php if (!$videos): ?>
                <p class="muted mb-0">No videos yet. Add one above.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="vid-table" id="vidTable" data-table-pagination>
                <thead>
                    <tr>
                        <th style="width:70px;">Order</th>
                        <th>Video</th>
                        <th style="width:90px;">Length</th>
                        <th style="width:90px;">Size</th>
                        <th style="width:90px;">Status</th>
                        <th style="width:150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($videos as $i => $v): ?>
                    <tr data-id="<?= (int) $v['id'] ?>">
                        <td>
                            <button class="icon-btn btn-up" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-arrow-up"></i>
                            </button>
                            <button class="icon-btn btn-down" title="Move down" <?= $i === count($videos) - 1 ? 'disabled' : '' ?>>
                                <i class="bi bi-arrow-down"></i>
                            </button>
                        </td>
                        <td>
                            <div class="vid-title"><?= htmlspecialchars($v['title']) ?></div>
                            <div class="vid-desc"><?= htmlspecialchars($v['description'] ?? '') ?></div>
                            <div class="muted" style="font-size:11px;margin-top:3px;">
                                <?php if ($v['type'] === 'youtube'): ?>
                                    <i class="bi bi-youtube"></i> YouTube &middot; <?= htmlspecialchars($v['src']) ?>
                                <?php elseif (s3_is_key($v['src'])): ?>
                                    <i class="bi bi-cloud-check"></i> S3
                                <?php else: ?>
                                    <i class="bi bi-hdd"></i> local &middot; <?= htmlspecialchars($v['src']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($v['duration'] ?: '-') ?></td>
                        <td><?= human_size($v['file_size']) ?></td>
                        <td>
                            <span class="badge <?= $v['is_active'] ? 'bg-success' : 'badge-off' ?>">
                                <?= $v['is_active'] ? 'Visible' : 'Hidden' ?>
                            </span>
                        </td>
                        <td>
                            <button class="icon-btn btn-edit" title="Edit"><i class="bi bi-pencil"></i></button>
                            <button class="icon-btn btn-toggle" title="<?= $v['is_active'] ? 'Hide' : 'Show' ?>">
                                <i class="bi bi-<?= $v['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                            </button>
                            <button class="icon-btn danger btn-del" title="Delete"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- ---------------- EDIT MODAL ---------------- -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-size:15px;">Edit video</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="editId">
        <label class="form-label fw-semibold" style="font-size:12px;">Title</label>
        <input type="text" class="form-control form-control-sm mb-3" id="editTitle" maxlength="255">
        <label class="form-label fw-semibold" style="font-size:12px;">Description</label>
        <textarea class="form-control form-control-sm mb-3" id="editDesc" rows="3"></textarea>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="editActive">
          <label class="form-check-label" for="editActive" style="font-size:13px;">Visible to subcontractors</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm text-white" style="background:var(--primary);" id="editSave">Save</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const API  = 'tutorial_videos_api.php';

/* ---------------- helpers ---------------- */
function notify(msg, kind = 'success') {
    const zone = document.getElementById('alertZone');
    zone.innerHTML = `<div class="alert alert-${kind} alert-dismissible fade show">${msg}
        <button class="btn-close" data-bs-dismiss="alert"></button></div>`;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function api(fields) {
    const body = new FormData();
    body.append('csrf', CSRF);
    for (const [k, v] of Object.entries(fields)) {
        if (Array.isArray(v)) { v.forEach(x => body.append(k + '[]', x)); }
        else { body.append(k, v); }
    }
    const res  = await fetch(API, { method: 'POST', body });
    let data;
    try { data = await res.json(); }
    catch { throw new Error('Server returned an unreadable response.'); }
    if (!data.ok) throw new Error(data.error || 'Request failed.');
    return data;
}

function humanSize(b) {
    if (!b) return '-';
    const u = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(Math.floor(Math.log(b) / Math.log(1024)), u.length - 1);
    return (b / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + u[i];
}

function fmtDuration(s) {
    if (!s) return '-';
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    return h ? `${h}:${String(m).padStart(2,'0')}:${String(x).padStart(2,'0')}`
             : `${m}:${String(x).padStart(2,'0')}`;
}

/* Read the duration in the browser - no ffmpeg needed on the server. */
function readDuration(file) {
    return new Promise(resolve => {
        const v = document.createElement('video');
        v.preload = 'metadata';
        const url = URL.createObjectURL(file);
        const finish = secs => { URL.revokeObjectURL(url); resolve(secs); };
        v.onloadedmetadata = () => finish(Number.isFinite(v.duration) ? Math.round(v.duration) : 0);
        v.onerror = () => finish(0);
        setTimeout(() => finish(0), 15000);   // never hang the upload on this
        v.src = url;
    });
}

/* The exact CORS rule this page needs, with the real origin filled
   in. AllowedOrigins must be scheme + host only - a path or trailing
   slash will not match and the upload fails with no useful message. */
function corsHelp() {
    const rule = JSON.stringify([{
        AllowedHeaders: ['*'],
        AllowedMethods: ['PUT'],
        AllowedOrigins: [window.location.origin],
        ExposeHeaders:  ['ETag'],
        MaxAgeSeconds:  3000
    }], null, 2);

    return '<br><br>In the AWS console open <strong>S3 &rarr; awsnexgen &rarr; Permissions &rarr; '
         + 'Cross-origin resource sharing (CORS) &rarr; Edit</strong> and paste:'
         + '<pre class="mt-2 mb-1 p-2 bg-light border rounded" style="font-size:11px;white-space:pre;'
         + 'overflow:auto;max-height:230px;">' + rule + '</pre>'
         + '<small>This page\'s origin is <code>' + window.location.origin + '</code>.</small>';
}

/* PUT straight to S3, reporting progress. fetch() cannot report
   upload progress, so this uses XHR. */
function putToS3(url, file, contentType, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url, true);
        xhr.setRequestHeader('Content-Type', contentType);
        xhr.upload.onprogress = e => {
            if (e.lengthComputable) onProgress(e.loaded / e.total);
        };
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300)
            ? resolve()
            : reject(new Error(`S3 rejected the upload (HTTP ${xhr.status}).` + corsHelp()));
        xhr.onerror = () => reject(new Error(
            'The browser could not reach S3. This is nearly always a missing CORS rule.' + corsHelp()));
        xhr.send(file);
    });
}

/* ---------------- upload flow ---------------- */
const dz        = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');
const filePill  = document.getElementById('filePill');
const btnUpload = document.getElementById('btnUpload');
let   chosen    = null;

if (dz) {
    dz.addEventListener('click', () => fileInput.click());
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('drag');
        if (e.dataTransfer.files.length) setFile(e.dataTransfer.files[0]);
    });
    fileInput.addEventListener('change', () => { if (fileInput.files.length) setFile(fileInput.files[0]); });
    document.getElementById('fpClear').addEventListener('click', () => {
        chosen = null; fileInput.value = ''; filePill.classList.add('d-none');
    });
}

function setFile(f) {
    chosen = f;
    document.getElementById('fpName').textContent = f.name;
    document.getElementById('fpMeta').textContent = humanSize(f.size);
    filePill.classList.remove('d-none');

    // Offer the filename as a starting title if the box is empty.
    const t = document.getElementById('newTitle');
    if (!t.value.trim()) {
        t.value = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
    }
}

btnUpload?.addEventListener('click', async () => {
    const title = document.getElementById('newTitle').value.trim();
    const desc  = document.getElementById('newDesc').value.trim();

    if (!chosen)  return notify('Choose a video file first.', 'warning');
    if (!title)   return notify('Give the video a title.', 'warning');

    const box   = document.getElementById('uploadProgress');
    const bar   = document.getElementById('upBar');
    const pct   = document.getElementById('upPct');
    const label = document.getElementById('upLabel');

    btnUpload.disabled = true;
    box.classList.remove('d-none');
    bar.style.width = '0%'; pct.textContent = '0%';

    try {
        label.textContent = 'Reading video…';
        const seconds = await readDuration(chosen);

        label.textContent = 'Preparing upload…';
        const pre = await api({
            action: 'presign',
            filename: chosen.name,
            size: chosen.size,
            content_type: chosen.type || 'video/mp4'
        });

        label.textContent = `Uploading ${humanSize(chosen.size)} to S3…`;
        await putToS3(pre.upload_url, chosen, pre.content_type, frac => {
            const p = Math.round(frac * 100);
            bar.style.width = p + '%';
            pct.textContent = p + '%';
        });

        label.textContent = 'Saving…';
        await api({
            action: 'create',
            title, description: desc,
            type: 'file',
            src: pre.stored,
            duration_seconds: seconds,
            file_size: chosen.size
        });

        notify(`“${title}” uploaded${seconds ? ' (' + fmtDuration(seconds) + ')' : ''}. Reloading…`);
        setTimeout(() => location.reload(), 900);

    } catch (err) {
        notify(err.message, 'danger');
        box.classList.add('d-none');
        btnUpload.disabled = false;
    }
});

/* ---------------- row actions ---------------- */
const editModal = document.getElementById('editModal')
    ? new bootstrap.Modal(document.getElementById('editModal')) : null;

document.querySelectorAll('#vidTable tbody tr').forEach(tr => {
    const id    = tr.dataset.id;
    const title = tr.querySelector('.vid-title').textContent.trim();
    const desc  = tr.querySelector('.vid-desc').textContent.trim();
    const isOn  = tr.querySelector('.badge').textContent.trim() === 'Visible';

    tr.querySelector('.btn-edit').addEventListener('click', () => {
        document.getElementById('editId').value      = id;
        document.getElementById('editTitle').value   = title;
        document.getElementById('editDesc').value    = desc;
        document.getElementById('editActive').checked = isOn;
        editModal.show();
    });

    tr.querySelector('.btn-toggle').addEventListener('click', async () => {
        try {
            await api({ action: 'update', id, title, description: desc, is_active: isOn ? '' : '1' });
            location.reload();
        } catch (e) { notify(e.message, 'danger'); }
    });

    tr.querySelector('.btn-del').addEventListener('click', async () => {
        if (!confirm(`Delete “${title}”?\n\nThe video file is removed from storage as well. This cannot be undone.`)) return;
        try {
            await api({ action: 'delete', id });
            notify('Video deleted. Reloading…');
            setTimeout(() => location.reload(), 700);
        } catch (e) { notify(e.message, 'danger'); }
    });

    tr.querySelector('.btn-up').addEventListener('click',   () => move(tr, -1));
    tr.querySelector('.btn-down').addEventListener('click', () => move(tr,  1));
});

document.getElementById('editSave')?.addEventListener('click', async () => {
    try {
        await api({
            action: 'update',
            id:          document.getElementById('editId').value,
            title:       document.getElementById('editTitle').value.trim(),
            description: document.getElementById('editDesc').value.trim(),
            is_active:   document.getElementById('editActive').checked ? '1' : ''
        });
        location.reload();
    } catch (e) { notify(e.message, 'danger'); }
});

async function move(tr, dir) {
    const tbody = tr.parentNode;
    const rows  = [...tbody.children];
    const i     = rows.indexOf(tr);
    const j     = i + dir;
    if (j < 0 || j >= rows.length) return;

    dir < 0 ? tbody.insertBefore(tr, rows[j]) : tbody.insertBefore(rows[j], tr);

    try {
        await api({ action: 'reorder', ids: [...tbody.children].map(r => r.dataset.id) });
        location.reload();
    } catch (e) { notify(e.message, 'danger'); }
}

// Live Clock Function
function updateClock() {
    const now = new Date();
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const clockEl = document.getElementById('liveClock');
    if (clockEl) {
        clockEl.textContent = `${String(hours).padStart(2, '0')}:${minutes}:${seconds} ${ampm}`;
    }
}
setInterval(updateClock, 1000);
updateClock();

// Dynamic Greeting Function
function getGreetingConfig() {
    const hour = new Date().getHours();
    if (hour < 12) {
        return { text: "Good Morning !", color: "#16803a", icon: "☀️" };
    } else if (hour < 17) {
        return { text: "Good Afternoon !", color: "#ea580c", icon: "🌤️" };
    } else {
        return { text: "Good Evening !", color: "#2563eb", icon: "🌙" };
    }
}

const config = getGreetingConfig();
const greetingText = config.text;
let charIndex = 0;

function typeWriter() {
    const typingElement = document.getElementById("typingGreeting");
    if (!typingElement) return;
    typingElement.style.color = config.color;

    if (charIndex < greetingText.length) {
        typingElement.innerHTML += greetingText.charAt(charIndex);
        charIndex++;
        setTimeout(typeWriter, 70);
    } else {
        const iconContainer = document.getElementById("greetingIconContainer");
        if (iconContainer) {
            iconContainer.innerHTML = `<span class="animated-icon">${config.icon}</span>`;
        }
    }
}

// Notification Panel Toggle & API Handler
const notificationButton = document.getElementById('notificationButton');
const notificationPanel = document.getElementById('notificationPanel');
const notificationList = document.getElementById('notificationList');
const notificationBadge = document.getElementById('notificationBadge');

function formatNotificationTime(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const minutes = Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hr${hours === 1 ? '' : 's'} ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days} day${days === 1 ? '' : 's'} ago`;
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function renderNotifications(items) {
    if (!notificationList) return;
    notificationList.replaceChildren();
    if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'notification-empty';
        empty.textContent = 'No recent updates.';
        notificationList.appendChild(empty);
        return;
    }

    items.forEach((item) => {
        const link = document.createElement('a');
        link.className = `notification-item${item.is_unread ? ' is-unread' : ''}`;
        link.href = item.href;

        const icon = document.createElement('span');
        icon.className = 'notification-item-icon';
        const iconElement = document.createElement('i');
        iconElement.className = `bi ${item.icon}`;
        icon.appendChild(iconElement);

        const copy = document.createElement('span');
        copy.className = 'notification-item-copy';
        const title = document.createElement('strong');
        title.textContent = item.title;
        const detail = document.createElement('span');
        detail.textContent = item.detail;
        const time = document.createElement('time');
        time.dateTime = item.timestamp;
        time.textContent = formatNotificationTime(item.timestamp);
        copy.append(title, detail, time);
        link.append(icon, copy);
        notificationList.appendChild(link);
    });
}

async function loadNotifications(markRead = false) {
    try {
        const url = `admin_notifications.php${markRead ? '?mark_read=1' : ''}`;
        const response = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Notification request failed');
        const data = await response.json();
        const count = Number(data.unread_count) || 0;
        if (notificationBadge) {
            notificationBadge.textContent = count > 9 ? '9+' : String(count);
            notificationBadge.hidden = count === 0;
        }
        renderNotifications(data.items || []);
    } catch (error) {
        if (notificationList && (!notificationList.children.length || notificationList.querySelector('.notification-empty'))) {
            const empty = document.createElement('div');
            empty.className = 'notification-empty';
            empty.textContent = 'Updates could not be loaded.';
            notificationList.replaceChildren(empty);
        }
    }
}

if (notificationButton && notificationPanel) {
    notificationButton.addEventListener('click', () => {
        const isOpening = !notificationPanel.classList.contains('show');
        notificationPanel.classList.toggle('show', isOpening);
        notificationButton.setAttribute('aria-expanded', String(isOpening));
        if (isOpening) loadNotifications(true);
    });
}

document.addEventListener('click', (event) => {
    if (!event.target.closest('.notification-container') && notificationPanel) {
        notificationPanel.classList.remove('show');
        if (notificationButton) notificationButton.setAttribute('aria-expanded', 'false');
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && notificationPanel) {
        notificationPanel.classList.remove('show');
        if (notificationButton) {
            notificationButton.setAttribute('aria-expanded', 'false');
            notificationButton.focus();
        }
    }
});

loadNotifications();
setInterval(() => loadNotifications(), 60000);

window.onload = function() {
    updateClock();
    typeWriter();
};
</script>
<script src="table_pagination.js"></script>
</body>
</html>