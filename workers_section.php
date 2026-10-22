<?php
session_start();

// Admin login check
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

// Database connection details
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// =====================================================
// Generate next worker unique code (HYE/{vendor_code}/W001)
// Uses the highest existing number (not COUNT) so deleted
// workers never cause duplicate codes.
// =====================================================
function generateWorkerCode($conn, $sub_id, $vendor_code) {
    $vendor = ($vendor_code !== '') ? $vendor_code : '0000';
    $prefix = "HYE/" . $vendor . "/W";
    $like = $prefix . "%";

    $max_no = 0;
    $stmt = $conn->prepare("SELECT worker_unique_code FROM workers WHERE subcontractor_id = ? AND worker_unique_code LIKE ?");
    if ($stmt) {
        $stmt->bind_param("is", $sub_id, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $num = intval(substr($r['worker_unique_code'], strlen($prefix)));
            if ($num > $max_no) {
                $max_no = $num;
            }
        }
        $stmt->close();
    }

    return $prefix . str_pad($max_no + 1, 3, '0', STR_PAD_LEFT);
}

// =====================================================
// Accept / Reject handler (runs on THIS page)
// =====================================================
if (isset($_GET['action']) && isset($_GET['id'])) {

    $worker_id = intval($_GET['id']);
    $worker_action = $_GET['action'];

    if ($worker_action == 'accept') {

        // Get the worker + the subcontractor's vendor code
        $info = $conn->prepare("SELECT w.subcontractor_id, w.status, s.vendor_code
                                FROM workers w
                                LEFT JOIN subcontractors s ON s.id = w.subcontractor_id
                                WHERE w.id = ?");
        if ($info) {
            $info->bind_param("i", $worker_id);
            $info->execute();
            $w = $info->get_result()->fetch_assoc();
            $info->close();

            // Only a Pending worker can be approved (prevents duplicate code generation)
            if ($w && $w['status'] === 'Pending') {
                $vendor_code = trim($w['vendor_code'] ?? '');
                $new_code = generateWorkerCode($conn, intval($w['subcontractor_id']), $vendor_code);

                $upd = $conn->prepare("UPDATE workers SET status = 'Approved', worker_unique_code = ?, approved_at = NOW() WHERE id = ? AND status = 'Pending'");
                if ($upd) {
                    $upd->bind_param("si", $new_code, $worker_id);
                    $upd->execute();
                    $upd->close();
                }
            }
        }

    } elseif ($worker_action == 'reject') {

        $upd = $conn->prepare("UPDATE workers SET status = 'Rejected' WHERE id = ? AND status = 'Pending'");
        if ($upd) {
            $upd->bind_param("i", $worker_id);
            $upd->execute();
            $upd->close();
        }
    }

    header("Location: workers_section.php?tab=pending");
    exit();
}

// Which tab is active (?tab=pending or ?tab=completed)
$active_tab = (isset($_GET['tab']) && $_GET['tab'] == 'completed') ? 'completed' : 'pending';

// Pending worker requests
$pending_workers = $conn->query("SELECT * FROM workers WHERE status = 'Pending' ORDER BY id DESC");

// Approved / Rejected workers
$completed_workers = $conn->query("SELECT * FROM workers WHERE status IN ('Approved','Rejected') ORDER BY id DESC");

// Helper: worker row -> safe JSON for a data attribute
function workerJsonAttr($row) {
    // ID scans live in the private bucket; hand the browser a media.php
    // URL rather than a raw storage key.
    $row = media_rowify($row, ['id_front_path', 'id_back_path']);
    return htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workers Management - Hayleys Fentons</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- SweetAlert2 CSS & JS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #da6913;
            --primary-dark: #bb4c14;
            --success: #16803a;
            --danger: #d9483c;
            --background: #F6F8F5;
            --surface: #FFFFFF;
            --border: #E4E9E2;
            --text: #0E1F14;
        }
        body {
            background: var(--background);
            font-family: 'Inter', sans-serif;
            color: var(--text);
            margin: 0;
            overflow-x: hidden;
        }

        .app-layout { display: flex; min-height: 100vh; }

        .sidebar-wrap {
            width: 100px; flex-shrink: 0; background: var(--background);
            display: flex; flex-direction: column; align-items: flex-start; justify-content: center;
            padding: 20px 0px 20px 18px; position: sticky; top: 0; height: 100vh; z-index: 1000;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .sidebar-wrap:hover { width: 240px; }
        .sidebar-logo { height: 60px; width: auto; object-fit: contain; margin-bottom: 25px; margin-left: -6px; transition: all 0.3s ease; }
        .sidebar-wrap:hover .sidebar-logo { margin-left: 45px; }

        .vertical-nav {
            background: linear-gradient(180deg, #ea580c 0%, #b45309 50%, #15803d 100%);
            width: 75px; height: calc(100vh - 120px); border-radius: 40px;
            display: flex; flex-direction: column; align-items: center; justify-content: space-between;
            padding: 25px 0; box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; white-space: nowrap;
        }
        .sidebar-wrap:hover .vertical-nav { width: 210px; align-items: flex-start; padding-left: 20px; padding-right: 20px; }

        .nav-links-group { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 15px; width: 100%; flex: 1; }
        .sidebar-wrap:hover .nav-links-group { align-items: flex-start; }

        .nav-icon-link {
            width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-size: 20px; border-radius: 18px; text-decoration: none;
            transition: all 0.25s ease; position: relative; gap: 15px;
        }
        .sidebar-wrap:hover .nav-icon-link { width: 100%; justify-content: flex-start; padding-left: 12px; border-radius: 14px; }
        .nav-text { display: none; font-size: 0.95rem; font-weight: 600; color: #ffffff; }
        .sidebar-wrap:hover .nav-text { display: inline-block; }
        .nav-icon-link:hover, .nav-icon-link.active { background: rgba(255,255,255,0.35); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        .logout-pill-btn {
            width: 50px; height: 50px; background: #a13d07; border-radius: 25px;
            display: flex; align-items: center; justify-content: center; color: #fff; text-decoration: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.2); transition: all 0.3s ease; gap: 12px;
        }
        .sidebar-wrap:hover .logout-pill-btn { width: 100%; border-radius: 14px; justify-content: flex-start; padding-left: 14px; }
        .logout-pill-btn:hover { background: #873105; color: #fff; }

        .main-content-wrap { flex: 1; min-width: 0; padding: 25px 35px; box-sizing: border-box; }

        /* Top Welcome Box Styling */
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

        .section-title { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 1.6rem; color: #1a1a1a; }
        .section-subtitle { color: #6c757d; font-size: 0.9rem; }

        .mini-status-card {
            background: #fff; border: 1px solid var(--border); border-radius: 14px;
            padding: 10px 14px; display: flex; align-items: center; gap: 12px; height: 100%;
        }
        .mini-card-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .pending-card .mini-card-icon { background: rgba(218,105,19,.12); color: var(--primary); }
        .approved-card .mini-card-icon { background: rgba(22,128,58,.12); color: var(--success); }
        .total-card .mini-card-icon { background: rgba(14,31,20,.08); color: #333; }
        .mini-card-label { font-size: 0.72rem; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
        .mini-card-value { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 1.3rem; color: #1a1a1a; }

        .subcontractor-tabs .nav-pills { gap: 10px; flex-wrap: wrap; }
        .sub-tab {
            display: flex; align-items: center; gap: 8px; background: #fff; border: 1px solid var(--border);
            border-radius: 12px; padding: 10px 18px; font-weight: 600; font-size: 0.88rem; color: #555;
            transition: all .2s; text-decoration: none;
        }
        .sub-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .sub-tab-icon { display: flex; }
        .sub-tab-count { background: rgba(0,0,0,.08); border-radius: 20px; padding: 2px 9px; font-size: 0.75rem; font-weight: 700; }
        .sub-tab.active .sub-tab-count { background: rgba(255,255,255,.25); }

        .management-card { background: #fff; border-radius: 16px; border: 1px solid var(--border); overflow: hidden; }
        .management-card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; padding: 18px 22px; border-bottom: 1px solid var(--border); }

        .custom-search-input-box { position: relative; width: 260px; }
        .custom-search-input-box input {
            width: 100%; background: #fff; border: 1px solid var(--border); border-radius: 10px;
            padding: 7px 14px 7px 36px; font-size: 0.85rem; color: var(--text); outline: none; transition: all 0.2s;
        }
        .custom-search-input-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(218,105,19,0.1); }
        .custom-search-input-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #888; font-size: 0.95rem; }

        .management-title { display: flex; align-items: center; gap: 12px; }
        .title-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #fff; flex-shrink: 0; }
        .pending-title-icon { background: var(--primary); }
        .completed-title-icon { background: var(--success); }
        .management-title h5 { margin: 0; font-family: 'Manrope', sans-serif; font-weight: 700; font-size: 1rem; }
        .management-title small { color: #6c757d; }

        .management-table thead th { background: #111827; color: #fff; font-size: 0.8rem; font-weight: 600; padding: 12px 16px; border: none; }
        .management-table thead th:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
        .management-table thead th:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
        .management-table-wrapper { padding: 15px; }
        .management-table tbody td { padding: 12px 16px; font-size: 0.85rem; vertical-align: middle; border-bottom: 1px solid var(--border); }
        .management-table tbody tr:last-child td { border-bottom: none; }

        .company-cell { display: flex; align-items: center; gap: 10px; }
        .company-avatar { width: 38px; height: 38px; border-radius: 10px; background: rgba(218,105,19,.12); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .company-avatar.completed-avatar { background: rgba(22,128,58,.12); color: var(--success); }
        .company-name { font-weight: 700; color: #1a1a1a; font-size: 0.88rem; }
        .info-text { color: #444; font-size: 0.85rem; }

        .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 13px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; }
        .status-pending { background: rgba(218,105,19,.12); color: var(--primary); }
        .status-approved { background: rgba(22,128,58,.12); color: var(--success); }
        .status-rejected { background: rgba(217,72,60,.12); color: var(--danger); }

        .action-btn-group { display: flex; align-items: center; justify-content: center; gap: 5px; }
        .action-btn-group a {
            display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px;
            border-radius: 8px; border: 1px solid var(--border); background: #fff; text-decoration: none !important; transition: all .2s;
        }
        .btn-success-soft:hover { background: rgba(22,128,58,.12); border-color: var(--success); }
        .btn-danger-soft:hover { background: rgba(217,72,60,.12); border-color: var(--danger); }

        .view-btn {
            display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px;
            border-radius: 8px; border: 1px solid var(--border); background: #fff; padding: 0;
            text-decoration: none !important; transition: all .2s; cursor: pointer;
        }
        .view-btn.view-primary { color: var(--primary); }
        .view-btn.view-primary:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        td .view-btn.view-text { width: auto; padding: 6px 14px; gap: 6px; font-size: 0.8rem; font-weight: 600; }

        .empty-cell { text-align: center; padding: 50px 20px; color: #8a8a8a; }
        .empty-icon { font-size: 2.2rem; margin-bottom: 10px; color: #ccc; }
        .empty-cell strong { display: block; color: #555; margin-bottom: 4px; }
        .empty-cell span { font-size: 0.85rem; }

        /* ===== View Worker Modal ===== */
        @media (min-width: 1200px) { .modal-xl-custom { max-width: 1140px !important; } }
        .vw-box { background: #fff; border-radius: 8px; padding: 9px 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
        .vw-label { display: block; color: #6c757d; font-size: 10.5px; margin-bottom: 2px; }
        .vw-value { font-weight: 700; color: #1a1a1a; font-size: 13px; word-break: break-word; }
        .vw-health-box { border: 1px solid var(--border); border-radius: 8px; background: #f8f9fa; padding: 10px; height: 100%; }
        .vw-health-row { display: flex; justify-content: space-between; gap: 8px; font-size: 11px; padding: 3px 0; border-bottom: 1px solid #eee; }
        .vw-id-photo { max-height: 160px; object-fit: cover; cursor: zoom-in; transition: transform .15s; }
        .vw-id-photo:hover { transform: scale(1.03); }
        #imagePreviewModal .modal-content { background: #111; border: 0; }
        #imagePreviewModal img { max-width: 100%; max-height: 80vh; object-fit: contain; }
    </style>
    <link rel="stylesheet" href="admin_sidebar.css">
</head>
<body class="bg-light">

<div class="app-layout">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- Main Content Area -->
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

        <div class="container-fluid my-2 px-0">
            <div class="subcontractor-section">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <h2 class="section-title mb-1">Worker Management</h2>
                        <p class="section-subtitle mb-0">Review worker registrations and manage approval status.</p>
                    </div>
                </div>

                <!-- SUMMARY CARDS -->
                <div class="row g-3 mb-4">
                    <div class="col-xl-4 col-md-4">
                        <div class="mini-status-card pending-card">
                            <div class="mini-card-icon"><i class="bi bi-clock-history"></i></div>
                            <div>
                                <div class="mini-card-label">Pending Requests</div>
                                <div class="mini-card-value"><?= ($pending_workers) ? $pending_workers->num_rows : 0; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-4">
                        <div class="mini-status-card approved-card">
                            <div class="mini-card-icon"><i class="bi bi-check-circle-fill"></i></div>
                            <div>
                                <div class="mini-card-label">Approved / Completed</div>
                                <div class="mini-card-value"><?= ($completed_workers) ? $completed_workers->num_rows : 0; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-4 col-md-4">
                        <div class="mini-status-card total-card">
                            <div class="mini-card-icon"><i class="bi bi-people-fill"></i></div>
                            <div>
                                <div class="mini-card-label">Total Records</div>
                                <div class="mini-card-value"><?= (($pending_workers) ? $pending_workers->num_rows : 0) + (($completed_workers) ? $completed_workers->num_rows : 0); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TABS -->
                <div class="subcontractor-tabs mb-3">
                    <div class="nav nav-pills" id="workerTabs" role="tablist">
                        <a class="sub-tab <?= ($active_tab == 'pending') ? 'active' : ''; ?>" id="worker-pending-tab" data-bs-toggle="pill" href="#worker-pending-pane" role="tab">
                            <span class="sub-tab-icon pending-tab-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span>Pending Requests</span>
                            <span class="sub-tab-count pending-count"><?= ($pending_workers) ? $pending_workers->num_rows : 0; ?></span>
                        </a>
                        <a class="sub-tab <?= ($active_tab == 'completed') ? 'active' : ''; ?>" id="worker-completed-tab" data-bs-toggle="pill" href="#worker-completed-pane" role="tab">
                            <span class="sub-tab-icon completed-tab-icon"><i class="bi bi-check2-circle"></i></span>
                            <span>Approved / Rejected</span>
                            <span class="sub-tab-count completed-count"><?= ($completed_workers) ? $completed_workers->num_rows : 0; ?></span>
                        </a>
                    </div>
                </div>

                <!-- TAB CONTENT -->
                <div class="tab-content" id="workerTabsContent">
                    <!-- PENDING TAB -->
                    <div class="tab-pane fade <?= ($active_tab == 'pending') ? 'show active' : ''; ?>" id="worker-pending-pane" role="tabpanel">
                        <div class="management-card">
                            <div class="management-card-header">
                                <div class="management-title">
                                    <span class="title-icon pending-title-icon"><i class="bi bi-hourglass-split"></i></span>
                                    <div>
                                        <h5>Pending Worker Requests</h5>
                                        <small>Review and approve new worker registrations</small>
                                    </div>
                                </div>
                                <div class="custom-search-input-box">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="pendingSearchInput" placeholder="Search worker..." onkeyup="filterTable('pendingSearchInput', 'pendingTableBody')">
                                </div>
                            </div>

                            <div class="management-table-wrapper">
                                <div class="table-responsive">
                                    <table class="table management-table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Worker Name</th>
                                                <th>Company / Subcontractor</th>
                                                <th>NIC Number</th>
                                                <th>Mobile Number</th>
                                                <th>Scope / Trade</th>
                                                <th>Status</th>
                                                <th class="text-center" width="160">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pendingTableBody">
                                        <?php
                                        if ($pending_workers && $pending_workers->num_rows > 0):
                                            while ($row = $pending_workers->fetch_assoc()):
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="company-cell">
                                                        <div class="company-avatar"><i class="bi bi-person-fill"></i></div>
                                                        <div>
                                                            <div class="company-name"><?= htmlspecialchars($row['worker_name'] ?? 'N/A'); ?></div>
                                                            <small>Registered Worker</small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['company_name'] ?? 'N/A'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['nic_number'] ?? 'N/A'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['mobile_number'] ?? 'N/A'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['scope'] ?? 'N/A'); ?></span></td>
                                                <td>
                                                    <span class="status-pill status-pending"><i class="bi bi-clock-fill"></i> Pending</span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="action-btn-group">
                                                        <button type="button" class="view-btn view-primary js-view-worker" data-worker="<?= workerJsonAttr($row); ?>" title="View Details">
                                                            <img src="assets/images/v.png" alt="View" width="18" height="18">
                                                        </button>
                                                        <a href="workers_section.php?action=accept&id=<?= urlencode($row['id']); ?>" class="btn-success-soft btn-approve-action" title="Approve">
                                                            <img src="assets/images/a.png" alt="Approve" width="18" height="18">
                                                        </a>
                                                        <a href="workers_section.php?action=reject&id=<?= urlencode($row['id']); ?>" class="btn-danger-soft btn-reject-action" title="Reject">
                                                            <img src="assets/images/r.png" alt="Reject" width="18" height="18">
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php
                                            endwhile;
                                        else:
                                        ?>
                                            <tr>
                                                <td colspan="7" class="empty-cell">
                                                    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                                                    <strong>No Pending Requests</strong>
                                                    <span>All worker requests have been processed.</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- COMPLETED TAB -->
                    <div class="tab-pane fade <?= ($active_tab == 'completed') ? 'show active' : ''; ?>" id="worker-completed-pane" role="tabpanel">
                        <div class="management-card">
                            <div class="management-card-header">
                                <div class="management-title">
                                    <span class="title-icon completed-title-icon"><i class="bi bi-check2-circle"></i></span>
                                    <div>
                                        <h5>Approved & Rejected Workers</h5>
                                        <small>History of processed worker registrations</small>
                                    </div>
                                </div>
                                <div class="custom-search-input-box">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="completedSearchInput" placeholder="Search worker..." onkeyup="filterTable('completedSearchInput', 'completedTableBody')">
                                </div>
                            </div>

                            <div class="management-table-wrapper">
                                <div class="table-responsive">
                                    <table class="table management-table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Worker Name</th>
                                                <th>Company / Subcontractor</th>
                                                <th>NIC Number</th>
                                                <th>Mobile Number</th>
                                                <th>Scope / Trade</th>
                                                <th>Status</th>
                                                <th class="text-center" width="140">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="completedTableBody">
                                        <?php
                                        if ($completed_workers && $completed_workers->num_rows > 0):
                                            while ($row = $completed_workers->fetch_assoc()):
                                                $status_class = ($row['status'] == 'Approved') ? 'status-approved' : 'status-rejected';
                                                $status_icon = ($row['status'] == 'Approved') ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="company-cell">
                                                        <div class="company-avatar completed-avatar"><i class="bi bi-person-fill"></i></div>
                                                        <div>
                                                            <div class="company-name"><?= htmlspecialchars($row['worker_name'] ?? 'N/A'); ?></div>
                                                            <small><?= !empty($row['worker_unique_code']) ? htmlspecialchars($row['worker_unique_code']) : 'Registered Worker'; ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['company_name'] ?? 'N/A'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['nic_number'] ?? 'N/A'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['mobile_number'] ?? 'N/A'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['scope'] ?? 'N/A'); ?></span></td>
                                                <td>
                                                    <span class="status-pill <?= $status_class; ?>">
                                                        <i class="bi <?= $status_icon; ?>"></i>
                                                        <?= htmlspecialchars($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="view-btn view-text view-primary js-view-worker" data-worker="<?= workerJsonAttr($row); ?>" title="View Details">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php
                                            endwhile;
                                        else:
                                        ?>
                                            <tr>
                                                <td colspan="7" class="empty-cell">
                                                    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                                                    <strong>No Processed Records</strong>
                                                    <span>No approved or rejected workers found.</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ================= VIEW WORKER DETAILS MODAL ================= -->
<div class="modal fade" id="viewWorkerModal" tabindex="-1" aria-labelledby="viewWorkerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-xl-custom">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="border-top: 5px solid #e15e13 !important;">
            <div class="modal-header bg-white border-bottom position-relative px-4 py-3 d-flex flex-column align-items-center text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-1" style="max-width: 140px;">
                    <img src="assets/images/hayleys_logo.png" alt="Hayleys Logo" class="img-fluid" style="max-height: 40px; object-fit: contain;">
                </div>
                <h4 class="modal-title fw-bold text-dark mb-0" id="viewWorkerModalLabel" style="font-size: 1.15rem;">Worker Details Profile</h4>
                <p class="text-muted mb-0" style="font-size: 11.5px;">Complete database record for the selected worker.</p>
            </div>

            <div class="modal-body p-3 bg-light" style="max-height: 75vh; overflow-y: auto;">
                <div class="row g-2">
                    <div class="col-md-8">
                        <div class="vw-box">
                            <span class="vw-label">Worker Unique Code</span>
                            <span class="vw-value" style="color:#bb4c14; font-size:15px;" id="v_worker_unique_code">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="vw-box">
                            <span class="vw-label">Approval Status</span>
                            <span class="vw-value" id="v_status">-</span>
                        </div>
                    </div>

                    <div class="col-md-3"><div class="vw-box"><span class="vw-label">ID / Sub-ID</span><span class="vw-value"><span id="v_id">-</span> / <span class="text-primary" id="v_sub_id_number">-</span></span></div></div>
                    <div class="col-md-5"><div class="vw-box"><span class="vw-label">Worker Full Name</span><span class="vw-value" id="v_worker_name">-</span></div></div>
                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">NIC Number</span><span class="vw-value" id="v_nic_number">-</span></div></div>

                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Date of Birth &amp; Gender</span><span class="vw-value"><span id="v_dob">-</span> (<span id="v_gender">-</span>)</span></div></div>
                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Mobile Number</span><span class="vw-value" id="v_mobile_number">-</span></div></div>
                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Email Address</span><span class="vw-value" id="v_email">-</span></div></div>

                    <div class="col-md-12"><div class="vw-box"><span class="vw-label">Residential Address</span><span class="vw-value" id="v_address">-</span></div></div>

                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Police Report Date</span><span class="vw-value" id="v_police_date">-</span></div></div>
                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">GS Report Date</span><span class="vw-value" id="v_gs_date">-</span></div></div>
                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Blood &amp; Emergency</span><span class="vw-value"><span class="text-danger" id="v_blood_group">-</span> | <span id="v_emergency_contact">-</span></span></div></div>

                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Company Name &amp; Contact</span><span class="vw-value"><span id="v_company_name">-</span> (<span id="v_company_contact">-</span>)</span></div></div>
                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Division Hired By</span><span class="vw-value" id="v_division_hired">-</span></div></div>
                    <div class="col-md-4"><div class="vw-box"><span class="vw-label">Scope &amp; Designation</span><span class="vw-value"><span id="v_scope">-</span> / <span id="v_designation">-</span></span></div></div>

                    <div class="col-md-6"><div class="vw-box"><span class="vw-label">Experience &amp; Allergies</span><span class="vw-value"><span id="v_work_experience">-</span> Yrs &bull; Allergies: <span class="text-danger" id="v_allergies">-</span></span></div></div>
                    <div class="col-md-6"><div class="vw-box"><span class="vw-label">Educational / Vocational Qualifications</span><span class="vw-value"><span id="v_edu_qualifications">-</span> / <span id="v_voc_qualifications">-</span></span></div></div>

                    <div class="col-md-12">
                        <div class="vw-box">
                            <span class="vw-label fw-bold mb-2" style="font-size:11px;"><i class="bi bi-file-earmark-image text-warning me-1"></i> NIC / ID Card Photos</span>
                            <div class="row g-2">
                                <div class="col-md-6 text-center">
                                    <span class="d-block text-secondary mb-1" style="font-size:10px;">Front Side</span>
                                    <div id="v_id_front_container">-</div>
                                </div>
                                <div class="col-md-6 text-center">
                                    <span class="d-block text-secondary mb-1" style="font-size:10px;">Back Side</span>
                                    <div id="v_id_back_container">-</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="vw-box">
                            <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-1">
                                <h6 class="fw-bold text-dark m-0" style="font-size:12px;"><i class="bi bi-play-btn text-warning me-1"></i>Safety Training Review</h6>
                                <span class="badge bg-light text-dark" id="v_training_score">No training submission</span>
                            </div>
                            <div class="small mb-2" id="v_training_acknowledged">Training acknowledgement: -</div>
                            <div class="row g-2">
                                <div class="col-md-4"><div class="vw-health-box"><strong class="d-block mb-1" style="font-size:11px;">Videos Completed</strong><div id="v_training_videos" class="small text-muted">-</div></div></div>
                                <div class="col-md-8"><div class="vw-health-box"><strong class="d-block mb-1" style="font-size:11px;">Quiz Answers (correct and incorrect)</strong><div id="v_training_answers" class="small text-muted">-</div></div></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="vw-box">
                            <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-1">
                                <h6 class="fw-bold text-dark m-0" style="font-size: 12px;"><i class="bi bi-heart-pulse text-danger me-1"></i> Health Information Details</h6>
                                <span class="badge bg-danger bg-opacity-10 text-danger" style="font-size: 10px;">Medical Records</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="vw-health-box">
                                        <div class="mb-1 fw-bold" style="font-size:11px;"><i class="bi bi-activity text-warning me-1"></i>NCD Diseases</div>
                                        <div id="v_ncd_details">-</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="vw-health-box">
                                        <div class="mb-1 fw-bold" style="font-size:11px;"><i class="bi bi-shield-exclamation text-danger me-1"></i>Chronic Diseases</div>
                                        <div id="v_chronic_details">-</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="vw-health-box">
                                        <div class="mb-1 fw-bold" style="font-size:11px;"><i class="bi bi-emoji-smile text-primary me-1"></i>Mental Health Conditions</div>
                                        <div id="v_mental_details">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <div class="text-end p-1">
                            <span class="text-muted" style="font-size: 10.5px;">Record Created At: <span class="fw-bold text-dark" id="v_created_at">-</span></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-white border-top px-4 py-2">
                <button type="button" class="btn btn-outline-secondary px-3 py-1" style="font-size: 12.5px;" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= ID IMAGE PREVIEW MODAL ================= -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content rounded-4 overflow-hidden">
            <div class="modal-header border-0 py-2 px-3">
                <h6 class="modal-title text-white mb-0" id="imagePreviewTitle" style="font-size: 13px;">ID Card Photo</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-2">
                <img src="" alt="ID Preview" id="imagePreviewImg">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Live Clock Function
    function updateClock() {
        const now = new Date();
        let hours = now.getHours();
        let minutes = now.getMinutes();
        let seconds = now.getSeconds();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        minutes = minutes < 10 ? '0' + minutes : minutes;
        seconds = seconds < 10 ? '0' + seconds : seconds;
        const el = document.getElementById('liveClock');
        if (el) el.innerText = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
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

    // Table search
    function filterTable(inputId, tbodyId) {
        const filter = document.getElementById(inputId).value.toLowerCase();
        const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
        for (let i = 0; i < rows.length; i++) {
            const text = rows[i].textContent || rows[i].innerText;
            rows[i].style.display = text.toLowerCase().indexOf(filter) > -1 ? "" : "none";
        }
    }

    // Escape text for safe innerHTML use
    function esc(str) {
        const d = document.createElement('div');
        d.textContent = (str === null || str === undefined) ? '' : String(str);
        return d.innerHTML;
    }

    // Build health list html from JSON string
    function parseHealthJson(jsonStr) {
        if (!jsonStr) return '<span class="text-muted" style="font-size:11px;">None specified</span>';
        try {
            const data = JSON.parse(jsonStr);
            const keys = Object.keys(data);
            if (!keys.length) return '<span class="text-muted" style="font-size:11px;">None specified</span>';
            let html = '<ul class="list-unstyled mb-0">';
            keys.forEach(key => {
                const cls = data[key] === 'Not infected' ? 'text-success' : 'text-danger fw-bold';
                html += '<li class="vw-health-row"><span>' + esc(key) + '</span><span class="' + cls + '">' + esc(data[key]) + '</span></li>';
            });
            return html + '</ul>';
        } catch (e) {
            return esc(jsonStr);
        }
    }

    function photoHtml(path, alt) {
        if (!path) return '<span class="text-muted">Not uploaded</span>';
        const p = esc(path);
        return '<img src="' + p + '" alt="' + alt + '" title="Click to enlarge" class="img-thumbnail vw-id-photo js-zoom-img">';
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.innerText = (value !== null && value !== undefined && String(value).trim() !== '') ? value : '-';
    }

    document.addEventListener("DOMContentLoaded", function () {
        const viewModalEl = document.getElementById('viewWorkerModal');
        const viewModal = new bootstrap.Modal(viewModalEl);

        // ---------- Open modal from View buttons ----------
        document.querySelectorAll('.js-view-worker').forEach(btn => {
            btn.addEventListener('click', function () {
                let worker;
                try {
                    worker = JSON.parse(this.getAttribute('data-worker'));
                } catch (e) {
                    Swal.fire('Error', 'Could not load worker details.', 'error');
                    return;
                }

                setText('v_worker_unique_code', worker.worker_unique_code || 'Will be assigned after admin approval');
                setText('v_status', worker.status);
                setText('v_id', worker.id);
                setText('v_sub_id_number', worker.sub_id_number);
                setText('v_worker_name', worker.worker_name);
                setText('v_nic_number', worker.nic_number);
                setText('v_dob', worker.dob);
                setText('v_gender', worker.gender);
                setText('v_mobile_number', worker.mobile_number);
                setText('v_email', worker.email);
                setText('v_address', worker.address);
                setText('v_police_date', worker.police_date);
                setText('v_gs_date', worker.gs_date);
                setText('v_blood_group', worker.blood_group);
                setText('v_emergency_contact', worker.emergency_contact);
                setText('v_company_name', worker.company_name);
                setText('v_company_contact', worker.company_contact);
                setText('v_division_hired', worker.division_hired);
                setText('v_scope', worker.scope);
                setText('v_designation', worker.designation);
                setText('v_work_experience', worker.work_experience);
                setText('v_allergies', worker.allergies);
                setText('v_edu_qualifications', worker.edu_qualifications);
                setText('v_voc_qualifications', worker.voc_qualifications);
                setText('v_created_at', worker.created_at);

                document.getElementById('v_id_front_container').innerHTML = photoHtml(worker.id_front_path, 'ID Front');
                document.getElementById('v_id_back_container').innerHTML = photoHtml(worker.id_back_path, 'ID Back');

                document.getElementById('v_ncd_details').innerHTML = parseHealthJson(worker.ncd_details);
                document.getElementById('v_chronic_details').innerHTML = parseHealthJson(worker.chronic_details);
                document.getElementById('v_mental_details').innerHTML = parseHealthJson(worker.mental_details);
                renderWorkerTraining(worker);

                viewModal.show();
            });
        });

        function renderWorkerTraining(worker) {
            const score = document.getElementById('v_training_score');
            const acknowledgement = document.getElementById('v_training_acknowledged');
            const videosBox = document.getElementById('v_training_videos');
            const answersBox = document.getElementById('v_training_answers');
            videosBox.textContent = 'Loading training results...';
            answersBox.replaceChildren();
            score.textContent = 'Loading...';
            fetch('get_worker_training_review.php?id=' + encodeURIComponent(worker.id), { credentials: 'same-origin' })
                .then(function(response){ return response.json(); })
                .then(function(data){
                    if (!data.ok) throw new Error(data.error || 'Could not load training results.');
                    if (!data.training) {
                        score.textContent = 'No training submission';
                        acknowledgement.textContent = 'Training acknowledgement: -';
                        videosBox.textContent = '-';
                        answersBox.textContent = 'This worker record was not submitted through the video training link.';
                        return;
                    }

                    const training = data.training;
                    score.textContent = 'Score: ' + training.score + ' / ' + training.total_questions;
                    acknowledgement.textContent = training.acknowledged_at
                        ? 'Understood and confirmed: ' + training.acknowledged_at
                        : 'Training acknowledgement: -';
                    videosBox.replaceChildren();
                    answersBox.replaceChildren();

                    if (Array.isArray(training.videos) && training.videos.length) {
                        const videoList = document.createElement('ul');
                        videoList.className = 'list-unstyled mb-0';
                        training.videos.forEach(function (video) {
                            const item = document.createElement('li');
                            item.className = 'mb-1';
                            item.textContent = video.title || ('Video ' + (video.id || ''));
                            videoList.append(item);
                        });
                        videosBox.append(videoList);
                    } else {
                        videosBox.textContent = 'No completed videos recorded.';
                    }

                    if (Array.isArray(training.answers) && training.answers.length) {
                        const answerList = document.createElement('div');
                        training.answers.forEach(function (answer, index) {
                            const item = document.createElement('div');
                            item.className = 'border-bottom py-2';
                            const question = document.createElement('strong');
                            question.className = 'd-block';
                            question.textContent = (index + 1) + '. ' + (answer.question || 'Question');
                            const chosen = document.createElement('span');
                            chosen.className = answer.is_correct ? 'text-success' : 'text-danger';
                            chosen.textContent = (answer.is_correct ? 'Correct: ' : 'Incorrect: ') + (answer.selected_answer || '-');
                            item.append(question, chosen);
                            if (!answer.is_correct) {
                                const correct = document.createElement('span');
                                correct.className = 'd-block text-success';
                                correct.textContent = 'Correct answer: ' + (answer.correct_answer || '-');
                                item.append(correct);
                            }
                            answerList.append(item);
                        });
                        answersBox.append(answerList);
                    } else {
                        answersBox.textContent = 'No quiz answers recorded.';
                    }
                })
                .catch(function(error){
                    score.textContent = 'Unavailable';
                    acknowledgement.textContent = error.message;
                    videosBox.textContent = '-';
                    answersBox.textContent = '-';
                });
        }

        // ---------- ID photo preview (opens in a modal, no new page) ----------
        const imgModalEl = document.getElementById('imagePreviewModal');
        const imgModal = new bootstrap.Modal(imgModalEl);
        let reopenWorkerModal = false;

        viewModalEl.addEventListener('click', function (e) {
            const img = e.target.closest('.js-zoom-img');
            if (!img) return;
            document.getElementById('imagePreviewImg').src = img.getAttribute('src');
            document.getElementById('imagePreviewTitle').innerText = img.getAttribute('alt') || 'ID Card Photo';
            reopenWorkerModal = true;
            viewModalEl.addEventListener('hidden.bs.modal', function showImg() {
                viewModalEl.removeEventListener('hidden.bs.modal', showImg);
                imgModal.show();
            });
            viewModal.hide();
        });

        // When the photo closes, go back to the worker details modal
        imgModalEl.addEventListener('hidden.bs.modal', function () {
            document.getElementById('imagePreviewImg').src = '';
            if (reopenWorkerModal) {
                reopenWorkerModal = false;
                viewModal.show();
            }
        });

        // ---------- Tab state via URL ----------
        const currentTab = new URLSearchParams(window.location.search).get('tab');
        if (currentTab === 'completed') {
            const completedTabBtn = document.getElementById('worker-completed-tab');
            if (completedTabBtn) new bootstrap.Tab(completedTabBtn).show();
        }

        document.querySelectorAll('[data-bs-toggle="pill"]').forEach(tabEl => {
            tabEl.addEventListener('shown.bs.tab', function (event) {
                const target = event.target.getAttribute('href');
                const newUrl = window.location.pathname + (target === '#worker-completed-pane' ? '?tab=completed' : '?tab=pending');
                window.history.replaceState(null, '', newUrl);
            });
        });

        // ---------- Row-level approve / reject confirmations ----------
        function bindConfirm(selector, opts) {
            document.querySelectorAll(selector).forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const targetUrl = this.getAttribute('href');
                    Swal.fire(Object.assign({
                        showCancelButton: true,
                        cancelButtonColor: '#6c757d',
                        cancelButtonText: 'Cancel'
                    }, opts)).then(result => {
                        if (result.isConfirmed) window.location.href = targetUrl;
                    });
                });
            });
        }

        bindConfirm('.btn-approve-action', {
            title: 'Approve Worker?',
            text: 'Do you want to approve this worker and generate a unique code?',
            icon: 'question',
            confirmButtonColor: '#16803a',
            confirmButtonText: 'Yes, Approve!'
        });

        bindConfirm('.btn-reject-action', {
            title: 'Reject Worker?',
            text: 'Are you sure you want to reject this worker registration?',
            icon: 'warning',
            confirmButtonColor: '#d9483c',
            confirmButtonText: 'Yes, Reject!'
        });
    });
</script>

</body>
</html>