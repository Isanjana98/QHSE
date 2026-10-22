<?php
session_start();

if (!isset($_SESSION['supervisor_logged']) || $_SESSION['supervisor_logged'] !== true) {
    header("Location: index.php");
    exit();
}

// DATABASE CONNECTION
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

// SUPERVISOR DETAILS
$sup_name      = $_SESSION['sup_name'] ?? "Supervisor";
$sup_division  = $_SESSION['sup_division'] ?? "General";
$supervisor_id = $_SESSION['supervisor_id'] ?? 1;

// Fetch the logged-in supervisor's profile picture
$display_pic = '';
$stmt_pic = $conn->prepare("SELECT profile_pic FROM supervisors WHERE id = ?");
if ($stmt_pic) {
    $stmt_pic->bind_param("i", $supervisor_id);
    $stmt_pic->execute();
    $stmt_pic->bind_result($sup_profile_pic);
    $stmt_pic->fetch();
    $stmt_pic->close();

    if (media_exists($sup_profile_pic)) {
        $display_pic = media_url($sup_profile_pic);
    }
}

// ---------------------------------------------------------
// DATA FETCHING FROM DATABASE FOR SUMMARY STATS
// ---------------------------------------------------------
$total_workers = 0;
$total_subcontractors = 0;
$pending_subs_count = 0;
$approved_subs_count = 0;
$pending_requests = 0;
$approved_requests = 0;
$total_permits = 0;

// 1. Total Workers
$result = $conn->query("SELECT COUNT(*) as count FROM workers");
if ($result && $row = $result->fetch_assoc()) {
    $total_workers = $row['count'];
}

// 2. Total Subcontractors & Status breakdown
$result = $conn->query("SELECT COUNT(*) as count FROM subcontractors");
if ($result && $row = $result->fetch_assoc()) {
    $total_subcontractors = $row['count'];
}

$result = $conn->query("SELECT COUNT(*) as count FROM subcontractors WHERE status = 'pending'");
if ($result && $row = $result->fetch_assoc()) {
    $pending_subs_count = $row['count'];
}

$result = $conn->query("SELECT COUNT(*) as count FROM subcontractors WHERE status = 'approved'");
if ($result && $row = $result->fetch_assoc()) {
    $approved_subs_count = $row['count'];
}

// 3. Pending Requests / Permits & Approved Permits
$result = $conn->query("SELECT COUNT(*) as count FROM permits WHERE status = 'pending'");
if ($result && $row = $result->fetch_assoc()) {
    $pending_requests = $row['count'];
}

$result = $conn->query("SELECT COUNT(*) as count FROM permits WHERE status = 'approved'");
if ($result && $row = $result->fetch_assoc()) {
    $approved_requests = $row['count'];
}

$result = $conn->query("SELECT COUNT(*) as count FROM permits");
if ($result && $row = $result->fetch_assoc()) {
    $total_permits = $row['count'];
}

function format_time_left($sec) {
    if ($sec <= 0) return 'Expired';
    $d = floor($sec / 86400); $h = floor(($sec % 86400) / 3600); $m = floor(($sec % 3600) / 60);
    if ($d > 0) return $d . 'd ' . $h . 'h left';
    if ($h > 0) return $h . 'h ' . $m . 'm left';
    return $m . 'm left';
}

// ---------------------------------------------------------
// APPROVED WORKS + NOTIFICATIONS
// ---------------------------------------------------------
$approved_list = [];
$notif_list = [];

$stmt_ap = $conn->prepare("SELECT permit_id, permit_number, cat_project, contact_number, work_category, status, created_at, expiry_date
                           FROM service_provider_permits
                           WHERE LOWER(TRIM(division)) = LOWER(?)
                           ORDER BY created_at DESC");
if ($stmt_ap) {
    $div_key = trim($sup_division);
    $stmt_ap->bind_param("s", $div_key);
    $stmt_ap->execute();
    $res_ap = $stmt_ap->get_result();
    while ($r = $res_ap->fetch_assoc()) { $approved_list[] = $r; }
    $stmt_ap->close();
}

$stmt_nt = $conn->prepare("SELECT permit_id, permit_number, cat_project, work_category, status, hold_reason, rejection_reason, created_at
                           FROM service_provider_permits
                           WHERE status IN ('On Hold','Rejected') AND LOWER(TRIM(division)) = LOWER(?)
                           ORDER BY created_at DESC LIMIT 20");
if ($stmt_nt) {
    $div_key2 = trim($sup_division);
    $stmt_nt->bind_param("s", $div_key2);
    $stmt_nt->execute();
    $res_nt = $stmt_nt->get_result();
    while ($r = $res_nt->fetch_assoc()) { $notif_list[] = $r; }
    $stmt_nt->close();
}
$notif_count = count($notif_list);

// ---------------------------------------------------------
// PERMIT STATUS BREAKDOWN (segmented bar)
// ---------------------------------------------------------
$status_defs = [
    'approved'    => ['Approved',    '#16a34a'],
    'completed'   => ['Completed',   '#0d9488'],
    'pending'     => ['Pending',     '#f59e0b'],
    'on hold'     => ['On hold',     '#8b5cf6'],
    'rejected'    => ['Rejected',    '#e11d48'],
    'expired'     => ['Expired',     '#6b7280'],
];
$status_alias = ['hold' => 'on hold', 'updated' => 'completed'];
$status_counts = array_fill_keys(array_keys($status_defs), 0);

$stmt_sb = $conn->prepare("SELECT LOWER(TRIM(status)) AS s, COUNT(*) AS c
                           FROM service_provider_permits
                           WHERE LOWER(TRIM(division)) = LOWER(?)
                           GROUP BY LOWER(TRIM(status))");
if ($stmt_sb) {
    $div_key3 = trim($sup_division);
    $stmt_sb->bind_param("s", $div_key3);
    $stmt_sb->execute();
    $res_sb = $stmt_sb->get_result();
    while ($r = $res_sb->fetch_assoc()) {
        $k = $status_alias[$r['s']] ?? $r['s'];
        if (isset($status_counts[$k])) { $status_counts[$k] += (int)$r['c']; }
    }
    $stmt_sb->close();
}
$status_total = array_sum($status_counts);


// ---------------------------------------------------------
// DYNAMIC TIME-BASED GREETING SETUP
// ---------------------------------------------------------
date_default_timezone_set('Asia/Colombo');
$current_hour = date('G');

if ($current_hour >= 5 && $current_hour < 12) {
    $greeting = "Good Morning";
    $greeting_color = "#10b981";
    $greeting_icon = "bi-brightness-high-fill";
} elseif ($current_hour >= 12 && $current_hour < 17) {
    $greeting = "Good Afternoon";
    $greeting_color = "#f59e0b";
    $greeting_icon = "bi-sun-fill";
} else {
    $greeting = "Good Evening";
    $greeting_color = "#3b82f6";
    $greeting_icon = "bi-moon-stars-fill";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supervisor Dashboard | QHSE Pro</title>

<!-- BOOTSTRAP 5.3 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- BOOTSTRAP ICONS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!-- GOOGLE FONTS -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">

<style>
:root {
    --bg-app: #f4f6f9;
    --card-bg: #ffffff;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --primary-gradient: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
    --card-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.04), 0 3px 4px -2px rgba(15, 23, 42, 0.03);
}

body {
    background-color: var(--bg-app);
    font-family: 'Poppins', sans-serif;
    color: var(--text-main);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

h1, h2, h3, h4, h5, h6 {
    letter-spacing: -0.02em;
    font-family: 'Poppins', sans-serif;
}

.data-num {
    font-family: 'JetBrains Mono', monospace;
    font-feature-settings: "tnum" 1;
}

@keyframes slowBounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}

@keyframes slowPulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.85; }
}

@keyframes slowRotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.icon-morning { display: inline-block; animation: slowRotate 12s linear infinite; }
.icon-afternoon { display: inline-block; animation: slowBounce 2.5s ease-in-out infinite; }
.icon-evening { display: inline-block; animation: slowPulse 3s ease-in-out infinite; }

.dashboard-header {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 18px 22px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.dashboard-header::after {
    content: '';
    position: absolute;
    top: 0; left: 0; width: 4px; height: 100%;
    background: var(--primary-gradient);
}

.header-title h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 2px;
}

.header-title p {
    font-size: 12.5px;
    color: var(--text-muted);
    margin-bottom: 0;
}

.pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 50px;
    font-size: 11.5px;
    font-weight: 500;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.pill-badge.alert {
    background: rgba(249, 115, 22, 0.08);
    color: #c2410c;
    border-color: rgba(249, 115, 22, 0.15);
}

.user-chip {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px 12px 4px 4px;
    border-radius: 50px;
    background: var(--card-bg);
    border: 1px solid #e2e8f0;
}

.user-chip .avatar {
    width: 38px; height: 38px;
    background: var(--primary-gradient);
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
}

.user-chip .avatar-img {
    width: 38px; height: 38px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e2e8f0;
}

.user-chip .u-name { font-weight: 700; color: var(--text-main); line-height: 1.2; display: block; font-size: 13.5px; }
.user-chip .u-div { color: var(--text-muted); font-size: 11.5px; display: block; }

.action-box {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 18px 22px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
}

a { text-decoration: none !important; }

.notif-bell {
    width: 38px; height: 38px; border-radius: 50%;
    background: #fff7ed; border: 1px solid rgba(249,115,22,0.25);
    color: #ea580c; display: inline-flex; align-items: center; justify-content: center;
    position: relative; padding: 0; cursor: pointer; transition: all 0.2s ease;
}
.notif-bell:hover { background: #ffedd5; transform: translateY(-1px); }
.notif-bell .notif-count {
    position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px;
    padding: 0 5px; border-radius: 9px; background: #dc2626; color: #fff;
    font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center;
}
.notif-item { border: 1px solid #e2e8f0; border-left: 4px solid #f59e0b; border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; font-size: 12.5px; background: #fff; }
.notif-item.rejected { border-left-color: #dc2626; }

.aw-table { width: 100%; border-collapse: separate; border-spacing: 0 4px; margin: 0; }
.aw-table thead th { background: #0f172a; color: #fff; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; padding: 14px 14px; white-space: nowrap; border: none; cursor: pointer; user-select: none; }
.aw-table thead th:first-child { border-radius: 14px 0 0 14px; }
.aw-table thead th:last-child { border-radius: 0 14px 14px 0; cursor: default; }
.aw-table thead th .sort-ico { font-size: 9px; opacity: .55; margin-left: 4px; }
.aw-table tbody td { background: #fff; font-size: 12.5px; padding: 6px 12px; vertical-align: middle; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
.aw-table tbody td:first-child { border-left: 1px solid #e5e7eb; border-radius: 12px 0 0 12px; font-weight: 700; color: #0f172a; }
.aw-table tbody td:last-child { border-right: 1px solid #e5e7eb; border-radius: 0 12px 12px 0; }
.aw-table tbody tr:hover td { background: #fffaf5; }

.type-pill, .status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 50px; font-size: 11.5px; font-weight: 600; border: 1px solid transparent; }
.type-pill.normal { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
.type-pill.hot_work { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
.type-pill.work_at_height { background: #ffedd5; color: #c2410c; border-color: #fed7aa; }
.status-pill.ok { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
.status-pill.expired { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }
.status-pill.pending { background: #fef3c7; color: #d97706; border-color: #fde68a; }
.status-pill.on_hold { background: #ede9fe; color: #7c3aed; border-color: #ddd6fe; }
.status-pill.rejected { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
.status-pill.completed { background: #ccfbf1; color: #0d9488; border-color: #99f6e4; }

.aw-toolbar { display: flex; justify-content: flex-end; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
.aw-toolbar .form-select, .aw-toolbar .form-control { font-size: 12.5px; border-radius: 8px; }
.aw-toolbar .aw-search { position: relative; }
.aw-toolbar .aw-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; }
.aw-toolbar .aw-search input { padding-left: 30px; width: 210px; }

.life-date { font-weight: 600; color: #475569; font-size: 12.5px; }

.life-bar { height: 6px; width: 125px; background: #e5e7eb; border-radius: 6px; overflow: hidden; margin-top: 5px; }
.life-bar > span { display: block; height: 100%; border-radius: 6px; background: linear-gradient(90deg, #f59e0b 0%, #10b981 100%); }
.life-bar.warn > span { background: #dc2626; }
.life-bar.done > span { background: #94a3b8; }

.life-left { font-size: 10.5px; color: #64748b; margin-top: 2px; }
.life-left.warn { color: #dc2626; font-weight: 600; }

.eye-btn { width: 34px; height: 34px; border-radius: 8px; border: 1px solid #e5e7eb; background: #fff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.eye-btn:hover { background: #eff6ff; }

.aw-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 10px; font-size: 12.5px; color: #475569; }
.aw-pager { display: flex; gap: 4px; padding: 4px; border: 1px solid #e5e7eb; border-radius: 8px; }
.aw-pager button { border: none; background: transparent; padding: 4px 11px; border-radius: 6px; font-size: 13px; color: #2563eb; }
.aw-pager button.active { background: #2563eb; color: #fff; }
.aw-pager button:disabled { color: #94a3b8; background: #f1f5f9; }

.modal-backdrop.show { background: rgba(15, 23, 42, 0.55) !important; opacity: 1 !important; }

#notifModal .modal-content { border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.35); }

#viewPermitModal .modal-dialog {
    max-width: 90vw;
    width: 900px;
    height: 90vh;
    margin: 5vh auto;
}
#viewPermitModal .modal-content {
    height: 100%;
    border: none !important;
    border-radius: 18px !important;
    overflow: hidden;
    background: #fff !important;
    box-shadow: 0 12px 20px -6px rgba(249, 115, 22, 0.12);
}
#viewPermitModal .modal-header {
    background: var(--primary-gradient) !important;
    color: #fff;
    border: none !important;
    padding: 14px 20px;
}
#viewPermitModal .modal-header .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.01em; }
#viewPermitModal .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); opacity: .85; }
#viewPermitModal .modal-header .btn-close:hover { opacity: 1; }
#viewPermitModal .modal-body {
    padding: 0 !important;
    height: calc(100% - 61px);
    position: relative;
    background: #f8fafc;
    overflow: hidden;
}
#viewPermitFrame { width: 100%; height: 100%; border: none; display: block; background: #fff; }
#viewPermitLoading {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,.85);
    font-weight: 600;
    color: #ea580c;
    z-index: 5;
    font-size: 13.5px;
    gap: 10px;
}
@media (max-width: 576px) {
    #viewPermitModal .modal-dialog { width: 100vw; height: 100vh; margin: 0; max-width: 100vw; }
    #viewPermitModal .modal-content { border-radius: 0 !important; }
}

/* ===== Permit status segmented bar ===== */
.status-overview { margin-bottom: 16px; }
.so-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 12px; }
.so-head h5 { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0; }
.so-total { font-size: 13px; color: var(--text-muted); font-weight: 500; }
.so-bar { display: flex; gap: 3px; height: 11px; border-radius: 8px; overflow: hidden; background: #eef1f4; margin-bottom: 16px; }
.so-seg { height: 100%; min-width: 6px; transition: filter .2s ease; }
.so-seg:hover { filter: brightness(1.12); }
.so-legend { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px 22px; }
.so-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted); min-width: 0; }
.so-dot { width: 11px; height: 11px; border-radius: 3px; flex-shrink: 0; }
.so-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.so-count { margin-left: auto; font-weight: 700; color: var(--text-main); font-family: 'JetBrains Mono', monospace; font-size: 13px; }
.so-item.zero .so-count { color: #94a3b8; font-weight: 600; }
@media (min-width: 768px)  { .so-legend { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 1200px) { .so-legend { grid-template-columns: repeat(6, 1fr); } }

@media (min-width: 992px) and (min-height: 700px) {
    body.fit-screen { height: 100vh; overflow: hidden; min-height: 0; }
    body.fit-screen .page-layout-wrapper { height: 100vh; }
    body.fit-screen .main-content-area { height: 100vh; display: flex; flex-direction: column; padding: 14px 20px !important; overflow: hidden; }
    body.fit-screen .max-width-container { flex: 1 1 auto; min-height: 0; width: 100%; display: flex; flex-direction: column; }
    body.fit-screen .max-width-container > * { flex-shrink: 0; }
    body.fit-screen .max-width-container > .fs-grow { flex: 1 1 0; min-height: 0; flex-wrap: nowrap; }

    body.fit-screen .dashboard-header { padding: 12px 20px; margin-bottom: 12px; }
    body.fit-screen .max-width-container > .row.g-3.mb-3 { margin-bottom: 12px !important; }
    body.fit-screen .stat-card { padding: 10px 14px; }
    body.fit-screen .stat-card-top { margin-bottom: 4px; }
    body.fit-screen .status-overview { padding: 12px 20px; margin-bottom: 12px; }
    body.fit-screen .so-head { margin-bottom: 8px; }
    body.fit-screen .so-bar { margin-bottom: 10px; }
    body.fit-screen .so-legend { gap: 6px 22px; }

    body.fit-screen .fs-grow > .col-12 { display: flex; min-height: 0; }
    body.fit-screen .fs-grow .action-box { flex: 1; display: flex; flex-direction: column; min-height: 0; padding: 14px 20px; }
    body.fit-screen .fs-grow .action-box > h5 { margin-bottom: 8px !important; }
    body.fit-screen .fs-grow .table-responsive { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
    body.fit-screen .aw-table thead th { padding-top: 10px; padding-bottom: 10px; position: sticky; top: 0; z-index: 2; }
    body.fit-screen .aw-table tbody td { padding-top: 3px; padding-bottom: 3px; }
    body.fit-screen .aw-footer { margin-top: 6px; flex-shrink: 0; }
}
</style>
</head>

<body class="fit-screen">

<div class="page-layout-wrapper" style="display: flex; align-items: flex-start; min-height: 100vh;">

    <?php include "navbar.php"; ?>

    <div class="main-content-area" style="flex: 1; padding: 20px; overflow-x: hidden;">
        <div class="container-fluid max-width-container" style="max-width: 1400px;">

            <!-- HEADER BAR -->
            <div class="dashboard-header d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div class="header-title">
                    <h2 id="typewriter-greeting" style="min-height: 30px; display: flex; align-items: center; gap: 8px;"></h2>
                    <p>Division: <span class="fw-bold text-dark"><?php echo htmlspecialchars($sup_division); ?></span> &bull; QHSE Command Center</p>
                </div>
                <div class="header-meta d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($pending_requests > 0): ?>
                        <div class="pill-badge alert">
                            <span class="spinner-grow spinner-grow-sm text-danger" style="width: 5px; height: 5px;" role="status"></span>
                            <?php echo number_format($pending_requests); ?> pending permit<?php echo $pending_requests == 1 ? '' : 's'; ?>
                        </div>
                    <?php endif; ?>
                    <div class="pill-badge">
                        <i class="bi bi-calendar3" style="color: #ea580c;"></i> <span id="liveDate"></span>
                    </div>
                    <div class="pill-badge">
                        <i class="bi bi-clock-history" style="color: #f97316;"></i> <span id="liveTime"></span>
                    </div>
                    <button type="button" class="notif-bell" data-bs-toggle="modal" data-bs-target="#notifModal" title="Notifications">
                        <i class="bi bi-bell-fill"></i>
                        <?php if ($notif_count > 0): ?><span class="notif-count"><?php echo $notif_count; ?></span><?php endif; ?>
                    </button>
                    <div class="user-chip">
                        <?php if (!empty($display_pic)): ?>
                            <img src="<?php echo htmlspecialchars($display_pic); ?>" alt="Avatar" class="avatar-img">
                        <?php else: ?>
                            <span class="avatar"><?php echo strtoupper(substr($sup_name, 0, 1)); ?></span>
                        <?php endif; ?>
                        <span>
                            <span class="u-name"><?php echo htmlspecialchars($sup_name); ?></span>
                            <span class="u-div"><?php echo htmlspecialchars($sup_division); ?> Division</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- PERMIT STATUS OVERVIEW (segmented bar) -->
            <div class="action-box status-overview">
                <div class="so-head">
                    <h5>Permit status</h5>
                    <span class="so-total"><?php echo number_format($status_total); ?> total</span>
                </div>
                <div class="so-bar" role="img" aria-label="Permit status breakdown">
                    <?php foreach ($status_defs as $key =>$def): if ($status_counts[$key] > 0): ?>
                        <div class="so-seg" style="flex: <?php echo $status_counts[$key]; ?> 1 0; background: <?php echo$def[1]; ?>;" title="<?php echo htmlspecialchars($def[0]) . ': ' . $status_counts[$key]; ?>"></div>
                    <?php endif; endforeach; ?>
                </div>
                <div class="so-legend">
                    <?php foreach ($status_defs as $key =>$def): ?>
                        <div class="so-item <?php echo $status_counts[$key] === 0 ? 'zero' : ''; ?>">
                            <span class="so-dot" style="background: <?php echo $def[1]; ?>;"></span>
                            <span class="so-label"><?php echo htmlspecialchars($def[0]); ?></span>
                            <span class="so-count"><?php echo $status_counts[$key]; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- APPROVED WORKS SECTION -->
            <div class="row fs-grow">
                <div class="col-12">
                    <div class="action-box">
                        <h5 class="fw-bold mb-3" style="font-size: 16px; color: var(--text-main);">
                            <i class="bi bi-check-circle-fill text-success me-2"></i> Service Provider Permits & Works
                        </h5>
                        <div class="aw-toolbar">
                            <select id="awShowLimit" class="form-select form-select-sm" style="width: 130px;">
                                <option value="5" selected>Show 5</option>
                                <option value="10">Show 10</option>
                                <option value="25">Show 25</option>
                                <option value="50">Show 50</option>
                                <option value="all">Show All</option>
                            </select>
                            <select id="awType" class="form-select form-select-sm" style="width: 150px;">
                                <option value="">All Permit Types</option>
                                <option value="General">General</option>
                                <option value="Hot Work">Hot Work</option>
                                <option value="Work at Height">Work at Height</option>
                            </select>
                            <select id="awStatus" class="form-select form-select-sm" style="width: 140px;">
                                <option value="">All Status</option>
                                <option value="Approved">Approved</option>
                                <option value="Completed">Completed</option>
                                <option value="Pending">Pending</option>
                                <option value="On Hold">On Hold</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Expired">Expired</option>
                            </select>
                            <div class="aw-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="awSearch" class="form-control form-control-sm" placeholder="Search...">
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="aw-table" id="awTable">
                                <thead>
                                    <tr>
                                        <th data-col="0">Permit Number <i class="bi bi-chevron-expand sort-ico"></i></th>
                                        <th data-col="1">Permit Type <i class="bi bi-chevron-expand sort-ico"></i></th>
                                        <th data-col="2">Project <i class="bi bi-chevron-expand sort-ico"></i></th>
                                        <th data-col="3">Contact No <i class="bi bi-chevron-expand sort-ico"></i></th>
                                        <th data-col="4">Status <i class="bi bi-chevron-expand sort-ico"></i></th>
                                        <th data-col="5" data-num="1">Created At <i class="bi bi-chevron-expand sort-ico"></i></th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="awBody">
                                <?php foreach ($approved_list as $a):
                                    $now = time(); $c_ts = strtotime($a['created_at']); $e_ts = !empty($a['expiry_date']) ? strtotime($a['expiry_date']) : 0;
                                    $expired = $e_ts && $e_ts < $now;
                                    $pct = 0; $left_txt = '-'; $bar_cls = '';
                                    if ($e_ts && $e_ts > $c_ts) {
                                        $total = $e_ts - $c_ts;
                                        $pct = (int)round(min(max($now - $c_ts, 0), $total) / $total * 100);
                                        $left_txt = format_time_left($e_ts - $now);
                                        if ($expired) { $bar_cls = 'done'; }
                                        elseif ($pct >= 85) { $bar_cls = 'warn'; }
                                    }
                                    $type_labels = ['General' => 'General', 'hot_work' => 'Hot Work', 'work_at_height' => 'Work at Height'];
                                    $t_key = $a['work_category'];
                                    $t_label = $type_labels[$t_key] ?? $t_key; 
                                    
                                    $raw_status = strtolower(trim($a['status'] ?? ''));
                                    if ($expired && $raw_status !== 'rejected' && $raw_status !== 'completed') {
                                        $display_status = 'Expired';
                                        $status_class = 'expired';
                                    } else {
                                        switch($raw_status) {
                                            case 'approved': $display_status = 'Approved'; $status_class = 'ok'; break;
                                            case 'completed': case 'updated': $display_status = 'Completed'; $status_class = 'completed'; break;
                                            case 'pending': $display_status = 'Pending'; $status_class = 'pending'; break;
                                            case 'on hold': case 'hold': $display_status = 'On Hold'; $status_class = 'on_hold'; break;
                                            case 'rejected': $display_status = 'Rejected'; $status_class = 'rejected'; break;
                                            default: $display_status = ucfirst($raw_status); $status_class = 'ok';
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($a['permit_number']); ?></td>
                                        <td><span class="type-pill <?php echo htmlspecialchars($t_key); ?>"><?php echo htmlspecialchars($t_label); ?></span></td>
                                        <td><?php echo htmlspecialchars($a['cat_project']); ?></td>
                                        <td><?php echo htmlspecialchars($a['contact_number'] ?? '-'); ?></td>
                                        <td data-status="<?php echo htmlspecialchars($display_status); ?>">
                                            <span class="status-pill <?php echo $status_class; ?>">
                                                <?php if ($display_status === 'Expired'): ?>
                                                    <i class="bi bi-hourglass-bottom"></i> Expired
                                                <?php elseif ($display_status === 'Approved'): ?>
                                                    <i class="bi bi-check-circle-fill"></i> Approved
                                                <?php elseif ($display_status === 'Completed'): ?>
                                                    <i class="bi bi-check-all"></i> Completed
                                                <?php elseif ($display_status === 'Pending'): ?>
                                                    <i class="bi bi-clock-fill"></i> Pending
                                                <?php elseif ($display_status === 'On Hold'): ?>
                                                    <i class="bi bi-pause-fill"></i> On Hold
                                                <?php elseif ($display_status === 'Rejected'): ?>
                                                    <i class="bi bi-x-circle-fill"></i> Rejected
                                                <?php else: ?>
                                                    <i class="bi bi-info-circle-fill"></i> <?php echo htmlspecialchars($display_status); ?>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td data-v="<?php echo (int)$c_ts; ?>">
                                            <div class="life-date"><?php echo htmlspecialchars($a['created_at']); ?></div>
                                            <div class="life-bar <?php echo $bar_cls; ?>" title="<?php echo $pct; ?>% of permit time used"><span style="width: <?php echo $pct; ?>%;"></span></div>
                                            <div class="life-left <?php echo ($bar_cls === 'warn') ? 'warn' : ''; ?>"><?php echo htmlspecialchars($left_txt); ?></div>
                                        </td>
                                        <td>
                                            <button type="button" class="eye-btn" onclick="openViewModal(<?php echo (int)$a['permit_id']; ?>)" title="View"><i class="bi bi-eye-fill"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="awEmpty" class="text-center text-muted py-4" style="display:none;">No records found.</div>
                        <div class="aw-footer">
                            <span id="awInfo"></span>
                            <div class="aw-pager" id="awPager"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- NOTIFICATION MODAL -->
<div class="modal fade" id="notifModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning bg-opacity-25">
                <h6 class="modal-title fw-bold"><i class="bi bi-bell-fill text-warning"></i> Notifications (<?php echo $notif_count; ?>)</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="notifModalBody">
                <?php if ($notif_count > 0): foreach ($notif_list as $n):
                    $is_rej = ($n['status'] === 'Rejected');
                    $reason =$is_rej ? $n['rejection_reason'] :$n['hold_reason']; ?>
                    <div class="notif-item <?php echo $is_rej ? 'rejected' : ''; ?>" id="notif-card-<?php echo (int)$n['permit_id']; ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong><?php echo htmlspecialchars($n['permit_number']); ?></strong>
                            <span class="badge <?php echo $is_rej ? 'bg-danger' : 'bg-warning text-dark'; ?>"><?php echo htmlspecialchars($n['status']); ?></span>
                        </div>
                        <div class="text-muted mt-1">Project: <?php echo htmlspecialchars($n['cat_project']); ?> &bull; <?php echo htmlspecialchars($n['work_category']); ?></div>
                        <div class="text-danger">Reason: <?php echo htmlspecialchars(!empty($reason) ?$reason : '-'); ?></div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="text-muted" style="font-size: 11px;"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($n['created_at']); ?></span>
                            <div>
                                <button type="button" class="btn btn-outline-primary btn-sm py-0 me-1" onclick="viewFromNotif(<?php echo (int)$n['permit_id']; ?>)">View</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0" onclick="dismissNotification(<?php echo (int)$n['permit_id']; ?>)">Dismiss</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <p class="text-center text-muted my-3" id="noNotifMsg">No new notifications.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- VIEW PERMIT MODAL -->
<div class="modal fade" id="viewPermitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text"></i> View Permit Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body position-relative">
                <div id="viewPermitLoading">
                    <div class="spinner-border spinner-border-sm me-2" role="status" style="color: #ea580c;"></div> Loading details...
                </div>
                <iframe id="viewPermitFrame" src="about:blank" onload="document.getElementById('viewPermitLoading').style.display='none'"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- SCRIPTS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var viewPermitModalEl = document.getElementById('viewPermitModal');
var viewPermitModal = new bootstrap.Modal(viewPermitModalEl);
function openViewModal(id) {
    document.getElementById('viewPermitLoading').style.display = 'flex';
    document.getElementById('viewPermitFrame').src = 'view_service_provider.php?id=' + id;
    viewPermitModal.show();
}
viewPermitModalEl.addEventListener('hidden.bs.modal', function () {
    document.getElementById('viewPermitFrame').src = 'about:blank';
});
function viewFromNotif(id) {
    var inst = bootstrap.Modal.getInstance(document.getElementById('notifModal'));
    if (inst) inst.hide();
    setTimeout(function () { openViewModal(id); }, 350);
}
function dismissNotification(permitId) {
    var card = document.getElementById('notif-card-' + permitId);
    if (card) {
        card.style.transition = "all 0.3s ease";
        card.style.opacity = "0";
        setTimeout(function() {
            card.remove();
            var remainingNotifs = document.querySelectorAll('.notif-item');
            if (remainingNotifs.length === 0) {
                var modalBody = document.getElementById('notifModalBody');
                modalBody.innerHTML = '<p class="text-center text-muted my-3">No new notifications.</p>';
            }
        }, 300);
    }
    var countBadge = document.querySelector('.notif-bell .notif-count');
    if (countBadge) {
        var currentCount = parseInt(countBadge.textContent) || 1;
        if (currentCount > 1) {
            countBadge.textContent = currentCount - 1;
        } else {
            countBadge.remove();
        }
    }
}
(function () {
    var body = document.getElementById('awBody');
    if (!body) return;
    var rows = Array.from(body.querySelectorAll('tr'));
    var view = rows.slice();
    var pageSize = 5, page = 1, sortCol = -1, asc = true;
    var elType = document.getElementById('awType'), elStatus = document.getElementById('awStatus'), elSearch = document.getElementById('awSearch');
    var elShowLimit = document.getElementById('awShowLimit');

    function cellVal(tr, col, num) {
        var td = tr.children[col];
        if (num) return parseFloat(td.getAttribute('data-v')) || 0;
        return td.textContent.trim().toLowerCase();
    }
    function applyFilter() {
        var q = elSearch.value.trim().toLowerCase(), t = elType.value, st = elStatus.value;
        view = rows.filter(function (r) {
            var rowStatus = r.children[4].getAttribute('data-status') || '';
            var matchStatus = !st || rowStatus === st;
            return (!q || r.textContent.toLowerCase().indexOf(q) > -1) &&
                   (!t || r.children[1].textContent.trim() === t) &&
                   matchStatus;
        });
        page = 1; render();
    }
    function render() {
        var total = view.length;
        var limitVal = elShowLimit.value;
        var pages = 1;

        if (limitVal === 'all') {
            pageSize = total > 0 ? total : 1;
        } else {
            pageSize = parseInt(limitVal) || 5;
        }

        document.body.classList.toggle('fit-screen', limitVal === '5');
        pages = Math.max(1, Math.ceil(total / pageSize));
        if (page > pages) page = pages;
        var start = (page - 1) * pageSize, end = Math.min(start + pageSize, total);

        rows.forEach(function (r) { r.style.display = 'none'; });
        view.slice(start, end).forEach(function (r) { r.style.display = ''; });

        var empty = document.getElementById('awEmpty');
        empty.style.display = total ? 'none' : 'block';
        empty.textContent = rows.length ? 'No matching results.' : 'No records found.';
        document.getElementById('awInfo').textContent = total ? 'Showing ' + (start + 1) + ' to ' + end + ' of ' + total + ' entries' : 'Showing 0 entries';

        var pg = document.getElementById('awPager');
        pg.innerHTML = '';
        function btn(label, target, cls, dis) {
            var b = document.createElement('button');
            b.type = 'button'; b.textContent = label; if (cls) b.className = cls; b.disabled = !!dis;
            b.onclick = function () { page = target; render(); };
            pg.appendChild(b);
        }

        if (limitVal !== 'all') {
            btn('Previous', page - 1, '', page === 1);
            for (var i = 1; i <= pages; i++) btn(i, i, i === page ? 'active' : '', false);
            btn('Next', page + 1, '', page === pages);
        }
    }

    document.querySelectorAll('#awTable thead th[data-col]').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = parseInt(th.getAttribute('data-col')), num = th.hasAttribute('data-num');
            asc = (sortCol === col) ? !asc : true; sortCol = col;
            rows.sort(function (x, y) {
                var a = cellVal(x, col, num), b = cellVal(y, col, num);
                return (a < b ? -1 : a > b ? 1 : 0) * (asc ? 1 : -1);
            });
            rows.forEach(function (r) { body.appendChild(r); });
            applyFilter();
        });
    });

    elType.addEventListener('change', applyFilter);
    elStatus.addEventListener('change', applyFilter);
    elSearch.addEventListener('input', applyFilter);
    elShowLimit.addEventListener('change', function() {
        page = 1;
        render();
    });

    render();
})();
</script>

<script>
function updateDateTime() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    const dateStr = now.toLocaleDateString('en-US', options);
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    if(document.getElementById('liveDate')) document.getElementById('liveDate').innerText = dateStr;
    if(document.getElementById('liveTime')) document.getElementById('liveTime').innerText = timeStr;
}
setInterval(updateDateTime, 1000);
updateDateTime();

document.addEventListener("DOMContentLoaded", function() {
    const greetingWord = "<?php echo $greeting; ?>";
    const supName = ", <?php echo htmlspecialchars($sup_name); ?> 👋";
    const greetingColor = "<?php echo $greeting_color; ?>";
    const iconClass = "<?php echo $greeting_icon; ?>";

    let animClass = "";
    <?php if ($current_hour >= 5 &&$current_hour < 12): ?>
        animClass = "icon-morning";
    <?php elseif ($current_hour >= 12 &&$current_hour < 17): ?>
        animClass = "icon-afternoon";
    <?php else: ?>
        animClass = "icon-evening";
    <?php endif; ?>

    const targetElement = document.getElementById("typewriter-greeting");

    const iconElement = document.createElement("i");
    iconElement.className = `bi ${iconClass} ${animClass}`;
    iconElement.style.color = greetingColor;
    iconElement.style.fontSize = "22px";
    targetElement.appendChild(iconElement);

    const greetingSpan = document.createElement("span");
    greetingSpan.style.color = greetingColor;
    targetElement.appendChild(greetingSpan);

    let fullText = " " + greetingWord + supName;
    let charIndex = 0;

    function typeWriter() {
        if (charIndex < fullText.length) {
            let currentChar = fullText.charAt(charIndex);

            if (charIndex <= greetingWord.length) {
                greetingSpan.innerHTML += currentChar;
            } else {
                if (charIndex === greetingWord.length + 1) {
                    const nameSpan = document.createElement("span");
                    nameSpan.id = "typewriter-rest";
                    targetElement.appendChild(nameSpan);
                }
                document.getElementById("typewriter-rest").innerHTML += currentChar;
            }
            charIndex++;
            setTimeout(typeWriter, 50);
        }
    }

    typeWriter();
});
</script>

</body>
</html>