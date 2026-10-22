<?php
session_start();

// Admin login check
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Database connection details
require_once __DIR__ . '/db_config.php';
$completionFields = require __DIR__ . '/subcontractor_completion_fields.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// =====================================================
// Accept / Reject handler (runs on THIS page)
// =====================================================
if (isset($_GET['action']) && isset($_GET['id'])) {

    $sub_id = intval($_GET['id']);
    $sub_action = $_GET['action'];
    $sub_status = null;

    if ($sub_action == 'accept') {
        $sub_status = 'Approved';
    } elseif ($sub_action == 'reject') {
        $sub_status = 'Rejected';
    }

    if ($sub_status !== null) {
        $stmt = $conn->prepare("UPDATE subcontractors SET status = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $sub_status, $sub_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    header("Location: subcontractors_section.php");
    exit();
}

// Which tab is active
$active_tab = (isset($_GET['tab']) && $_GET['tab'] == 'completed') ? 'completed' : 'pending';

// =====================================================
// Pagination Configuration
// =====================================================
$limit = isset($_GET['limit']) ? ($_GET['limit'] === 'all' ? 999999 : intval($_GET['limit'])) : 5; // Default 5 per page

// Pending Pagination
$pending_page = isset($_GET['p_page']) ? intval($_GET['p_page']) : 1;
$pending_limit = $limit;
$pending_offset = ($pending_page - 1) * $pending_limit;

$pending_total_result = $conn->query("SELECT COUNT(*) as count FROM subcontractors WHERE status = 'Pending'");
$pending_total_row = $pending_total_result->fetch_assoc();
$pending_total_records = $pending_total_row['count'];
$pending_total_pages = ($pending_limit === 999999) ? 1 : ceil($pending_total_records / $pending_limit);

$pending_query = "SELECT * FROM subcontractors WHERE status = 'Pending' ORDER BY id DESC";
if ($pending_limit !== 999999) {
    $pending_query .= " LIMIT $pending_limit OFFSET $pending_offset";
}
$pending_subs = $conn->query($pending_query);

// Completed Pagination
$completed_page = isset($_GET['c_page']) ? intval($_GET['c_page']) : 1;
$completed_limit = $limit;
$completed_offset = ($completed_page - 1) * $completed_limit;

$completed_total_result = $conn->query("SELECT COUNT(*) as count FROM subcontractors WHERE status IN ('Approved','Rejected')");
$completed_total_row = $completed_total_result->fetch_assoc();
$completed_total_records = $completed_total_row['count'];
$completed_total_pages = ($completed_limit === 999999) ? 1 : ceil($completed_total_records / $completed_limit);

$completed_query = "SELECT * FROM subcontractors WHERE status IN ('Approved','Rejected') ORDER BY id DESC";
if ($completed_limit !== 999999) {
    $completed_query .= " LIMIT $completed_limit OFFSET $completed_offset";
}
$completed_subs = $conn->query($completed_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subcontractor Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            height: 100vh;
            overflow-y: hidden; /* Prevent body scrolling so everything fits in full screen viewport */
        }

        /* Layout with Flex Sidebar */
        .app-layout {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Left Vertical Sidebar styling */
        .sidebar-wrap {
            width: 100px;
            flex-shrink: 0;
            background: var(--background);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 12px 0px 12px 10px;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 1000;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-wrap:hover {
            width: 240px;
            align-items: flex-start;
            padding-left: 18px;
        }

        .sidebar-logo {
            height: 40px;
            width: auto;
            object-fit: contain;
            margin-bottom: 15px;
            margin-left: 0;
            transition: all 0.3s ease;
        }

        .sidebar-wrap:hover .sidebar-logo {
            margin-left: 45px; 
        }

        /* Expandable Vertical Nav Bar */
        .vertical-nav {
            background: linear-gradient(180deg, #ea580c 0%, #b45309 50%, #15803d 100%);
            width: 75px;
            height: calc(100vh - 80px);
            border-radius: 35px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            white-space: nowrap;
        }

        .sidebar-wrap:hover .vertical-nav {
            width: 210px;
            align-items: flex-start;
            padding-left: 20px;
            padding-right: 20px;
        }

        .nav-links-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            flex: 1;
        }

        .sidebar-wrap:hover .nav-links-group {
            align-items: flex-start;
        }

        .nav-icon-link {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 18px;
            border-radius: 14px;
            text-decoration: none;
            transition: all 0.25s ease;
            position: relative;
            gap: 15px;
        }

        .sidebar-wrap:hover .nav-icon-link {
            width: 100%;
            justify-content: flex-start;
            padding-left: 12px;
            border-radius: 12px;
        }

        .nav-text {
            display: none;
            font-size: 0.9rem;
            font-weight: 600;
            color: #ffffff;
        }

        .sidebar-wrap:hover .nav-text {
            display: inline-block;
        }

        .nav-icon-link:hover, .nav-icon-link.active {
            background: rgba(255, 255, 255, 0.35);
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .logout-pill-btn {
            width: 44px;
            height: 44px;
            background: #a13d07;
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-decoration: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            gap: 12px;
        }

        .sidebar-wrap:hover .logout-pill-btn {
            width: 100%;
            border-radius: 12px;
            justify-content: flex-start;
            padding-left: 14px;
        }

        .logout-pill-btn:hover {
            background: #873105;
            color: #fff;
        }

        /* Main Content Wrapper */
        .main-content-wrap {
            flex: 1;
            min-width: 0;
            padding: 15px 25px;
            box-sizing: border-box;
            height: 100vh;
            overflow-y: auto;
        }

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

        .page-header-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
            padding-top: 0;
        }
        .section-title {
            font-family: 'Manrope', sans-serif;
            font-weight: 800; font-size: 1.35rem; color: #1a1a1a;
            margin: 0;
        }
        .section-subtitle { color: #6c757d; font-size: 0.8rem; margin: 0; }

        /* SUMMARY CARDS */
        .mini-status-card {
            background: #fff; border: 1px solid var(--border); border-radius: 12px;
            padding: 8px 12px; display: flex; align-items: center; gap: 10px; height: 100%;
        }
        .mini-card-icon {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem; flex-shrink: 0;
        }
        .pending-card .mini-card-icon { background: rgba(218,105,19,.12); color: var(--primary); }
        .approved-card .mini-card-icon { background: rgba(22,128,58,.12); color: var(--success); }
        .total-card .mini-card-icon { background: rgba(14,31,20,.08); color: #333; }
        .mini-card-label {
            font-size: 0.68rem; color: #6c757d; font-weight: 600;
            text-transform: uppercase; letter-spacing: .3px;
        }
        .mini-card-value {
            font-family: 'Manrope', sans-serif; font-weight: 800;
            font-size: 1.1rem; color: #1a1a1a;
        }

        /* TABS */
        .subcontractor-tabs .nav-pills { gap: 6px; flex-wrap: wrap; }
        .sub-tab {
            display: flex; align-items: center; gap: 6px;
            background: #fff; border: 1px solid var(--border); border-radius: 8px;
            padding: 5px 12px; font-weight: 600; font-size: 0.78rem; color: #555;
            transition: all .2s;
        }
        .sub-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .sub-tab-icon { display: flex; font-size: 0.85rem; }
        .sub-tab-count {
            background: rgba(0,0,0,.08); border-radius: 20px;
            padding: 1px 6px; font-size: 0.65rem; font-weight: 700;
        }
        .sub-tab.active .sub-tab-count { background: rgba(255,255,255,.25); }

        /* MANAGEMENT CARD & HEADER */
        .management-card { 
            background: #fff; 
            border-radius: 14px; 
            border: 1px solid var(--border); 
            overflow: hidden; 
        }
        .management-card-header {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px; padding: 12px 18px; border-bottom: 1px solid var(--border);
        }
        
        .header-controls-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rows-per-page-box {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
        }
        .rows-per-page-box select {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 4px 10px;
            font-size: 0.8rem;
            color: var(--text);
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .rows-per-page-box select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(218, 105, 19, 0.1);
        }

        .custom-search-input-box {
            position: relative;
            width: 220px;
        }
        .custom-search-input-box input {
            width: 100%;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 12px 5px 32px;
            font-size: 0.8rem;
            color: var(--text);
            outline: none;
            transition: all 0.2s;
        }
        .custom-search-input-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(218, 105, 19, 0.1);
        }
        .custom-search-input-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 0.85rem;
        }

        .management-title { display: flex; align-items: center; gap: 10px; }
        .title-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: #fff; flex-shrink: 0;
        }
        .pending-title-icon { background: var(--primary); }
        .completed-title-icon { background: var(--success); }
        .management-title h5 { margin: 0; font-family: 'Manrope', sans-serif; font-weight: 700; font-size: 0.92rem; }
        .management-title small { color: #6c757d; font-size: 0.75rem; }

        /* TABLE - Compact padding for zero vertical scroll requirement */
        .management-table thead th {
            background: #111827; color: #fff; font-size: 0.75rem;
            font-weight: 600; padding: 8px 12px; border: none;
        }
        .management-table thead th:first-child {
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }
        .management-table thead th:last-child {
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        .management-table-wrapper {
            padding: 10px;
        }
        .management-table tbody td {
            padding: 5px 12px; font-size: 0.8rem;
            vertical-align: middle; border-bottom: 1px solid var(--border);
        }
        .management-table tbody tr:last-child td { border-bottom: none; }

        .company-cell { display: flex; align-items: center; gap: 8px; }
        .company-name { font-weight: 700; color: #1a1a1a; font-size: 0.82rem; }

        .info-text {
            color: #444;
            font-size: 0.8rem;
        }

        .status-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 700;
        }
        .status-pending { background: rgba(218,105,19,.12); color: var(--primary); }
        .status-approved { background: rgba(22,128,58,.12); color: var(--success); }
        .status-rejected { background: rgba(217,72,60,.12); color: var(--danger); }

        .profile-completion-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 8px; border-radius:20px; font-size:.68rem; font-weight:700; white-space:nowrap; }
        .profile-completion-badge.complete { background:#dcfce7; color:#166534; }
        .profile-completion-badge.incomplete { background:#fff0e4; color:#9a3412; }

        .action-btn-group { display: flex; align-items: center; justify-content: center; gap: 4px; }
        .btn-success-soft:hover { background: rgba(22,128,58,.12); border-color: var(--success); }
        .btn-danger-soft:hover { background: rgba(217,72,60,.12); border-color: var(--danger); }

        .view-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 6px;
            border: 1px solid var(--border); background: #fff;
            text-decoration: none !important; transition: all .2s;
        }
        .view-btn.view-primary { color: var(--primary); }
        .view-btn.view-primary:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        td .view-btn {
            width: auto; padding: 3px 10px; gap: 5px; font-size: 0.75rem; font-weight: 600;
        }
        #viewSubModal .modal-dialog { max-width: 980px; }
        #viewSubModal .modal-content { border-radius: 12px; overflow: hidden; }
        #viewSubModal .modal-header { background: #fff7ed !important; border-color: #fed7aa !important; }
        #viewSubModal .modal-title { color: #7c2d12 !important; }
        #viewSubModal .modal-title i { color: #ea580c !important; }
        #viewSubModal .spinner-border { color: #ea580c !important; }

        .empty-cell { text-align: center; padding: 25px 15px; color: #8a8a8a; }
        .empty-icon { font-size: 1.8rem; margin-bottom: 6px; color: #ccc; }
        .empty-cell strong { display: block; color: #555; margin-bottom: 2px; font-size: 0.85rem; }
        .empty-cell span { font-size: 0.78rem; }

        /* Pagination Styling */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 18px;
            border-top: 1px solid var(--border);
            background: #fff;
        }
        .pagination-info {
            font-size: 0.78rem;
            color: #6c757d;
        }
        .custom-pagination {
            display: flex;
            gap: 4px;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .custom-pagination .page-item .page-link {
            border: 1px solid var(--border);
            color: var(--text);
            padding: 4px 10px;
            font-size: 0.78rem;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .custom-pagination .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .custom-pagination .page-item .page-link:hover {
            background-color: #f1f3f0;
        }
        .custom-pagination .page-item.disabled .page-link {
            color: #ccc;
            pointer-events: none;
            background-color: #fcfcfc;
        }
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

        <div class="container-fluid my-1 px-0">

            <div class="subcontractor-section">

                <!-- PAGE HEADER -->
                <div class="page-header-container">
                    <div>
                        <h2 class="section-title">Subcontractor Management</h2>
                        <p class="section-subtitle">Manage subcontractor registrations, approvals and account status.</p>
                    </div>
                </div>

                <!-- SUMMARY CARDS -->
                <div class="row g-2 mb-3">
                    <div class="col-xl-4 col-md-4">
                        <div class="mini-status-card pending-card">
                            <div class="mini-card-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div>
                                <div class="mini-card-label">Pending Requests</div>
                                <div class="mini-card-value"><?= $pending_total_records; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-4">
                        <div class="mini-status-card approved-card">
                            <div class="mini-card-icon">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            <div>
                                <div class="mini-card-label">Approved / Completed</div>
                                <div class="mini-card-value"><?= $completed_total_records; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 col-md-4">
                        <div class="mini-status-card total-card">
                            <div class="mini-card-icon">
                                <i class="bi bi-buildings-fill"></i>
                            </div>
                            <div>
                                <div class="mini-card-label">Total Records</div>
                                <div class="mini-card-value"><?= $pending_total_records + $completed_total_records; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TABS -->
                <div class="subcontractor-tabs mb-2">
                    <div class="nav nav-pills" id="adminTabs" role="tablist">
                        <button class="sub-tab <?= ($active_tab == 'pending') ? 'active' : ''; ?>" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pending-pane" type="button" role="tab">
                            <span class="sub-tab-icon pending-tab-icon"><i class="bi bi-hourglass-split"></i></span>
                            <span>Pending Requests</span>
                            <span class="sub-tab-count pending-count"><?= $pending_total_records; ?></span>
                        </button>

                        <button class="sub-tab <?= ($active_tab == 'completed') ? 'active' : ''; ?>" id="completed-tab" data-bs-toggle="pill" data-bs-target="#completed-pane" type="button" role="tab">
                            <span class="sub-tab-icon completed-tab-icon"><i class="bi bi-check2-circle"></i></span>
                            <span>Approved / Rejected</span>
                            <span class="sub-tab-count completed-count"><?= $completed_total_records; ?></span>
                        </button>
                    </div>
                </div>

                <!-- TAB CONTENT -->
                <div class="tab-content" id="adminTabsContent">
                    
                    <!-- PENDING TAB -->
                    <div class="tab-pane fade <?= ($active_tab == 'pending') ? 'show active' : ''; ?>" id="pending-pane" role="tabpanel">
                        <div class="management-card">
                            <div class="management-card-header">
                                <div class="management-title">
                                    <span class="title-icon pending-title-icon"><i class="bi bi-hourglass-split"></i></span>
                                    <div>
                                        <h5>Pending Subcontractor Requests</h5>
                                        <small>Review and manage new registrations</small>
                                    </div>
                                </div>

                                <div class="header-controls-group">
                                    <div class="rows-per-page-box">
                                        <span>Show</span>
                                        <select id="pendingLimitSelect" onchange="changeLimit(this.value, 'pending')">
                                            <option value="5" <?= ($limit === 5) ? 'selected' : ''; ?>>5</option>
                                            <option value="10" <?= ($limit === 10) ? 'selected' : ''; ?>>10</option>
                                            <option value="25" <?= ($limit === 25) ? 'selected' : ''; ?>>25</option>
                                            <option value="50" <?= ($limit === 50) ? 'selected' : ''; ?>>50</option>
                                            <option value="all" <?= ($limit === 999999) ? 'selected' : ''; ?>>All</option>
                                        </select>
                                    </div>
                                    <div class="custom-search-input-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" id="pendingSearchInput" placeholder="Search subcontractor..." onkeyup="filterTable('pendingSearchInput', 'pendingTableBody')">
                                    </div>
                                </div>
                            </div>

                            <div class="management-table-wrapper">
                                <div class="table-responsive">
                                    <table class="table management-table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Vendor Code</th>
                                                <th>Company Name</th>
                                                <th>Address</th>
                                                <th>Mobile Number</th>
                                                <th>Profile Details</th>
                                                <th>Status</th>
                                                <th class="text-center" width="150">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pendingTableBody">
                                        <?php
                                        if ($pending_subs && $pending_subs->num_rows > 0):
                                            while ($row = $pending_subs->fetch_assoc()):
                                                $missingProfileFields = subcontractorMissingProfileFields($row, $completionFields);
                                                $missingProfileCount = count($missingProfileFields);
                                        ?>
                                            <tr>
                                                <td><span class="info-text"><?= htmlspecialchars($row['vendor_code'] ?? '-'); ?></span></td>
                                                <td>
                                                    <div class="company-cell">
                                                        <div>
                                                            <div class="company-name"><?= htmlspecialchars($row['company_name']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['company_address'] ?? '-'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['mobile_number'] ?? '-'); ?></span></td>
                                                <td>
                                                    <span class="profile-completion-badge <?= $missingProfileCount === 0 ? 'complete' : 'incomplete'; ?>" title="<?= htmlspecialchars($missingProfileCount ? implode(', ', array_column($missingProfileFields, 'label')) : 'All required details provided', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="bi <?= $missingProfileCount === 0 ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?>"></i>
                                                        <?= $missingProfileCount === 0 ? 'Complete' : $missingProfileCount . ' missing'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-pill status-pending">
                                                        <i class="bi bi-clock-fill"></i> Pending
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="action-btn-group">
                                                        <a href="view_subcontractor.php?id=<?= urlencode($row['id']); ?>" class="view-btn view-primary js-view-link" title="View Details">
                                                            <img src="assets/images/v.png" alt="View" width="16" height="16">
                                                        </a>
                                                        <a href="subcontractors_section.php?action=accept&id=<?= urlencode($row['id']); ?>" class="btn-success-soft" title="Approve">
                                                            <img src="assets/images/a.png" alt="Approve" width="16" height="16">
                                                        </a>
                                                        <a href="subcontractors_section.php?action=reject&id=<?= urlencode($row['id']); ?>" class="btn-danger-soft" title="Reject">
                                                            <img src="assets/images/r.png" alt="Reject" width="16" height="16">
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
                                                    <span>All subcontractor requests have been processed.</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Pagination Bar -->
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Showing page <b><?= $pending_page; ?></b> of <b><?= max(1, $pending_total_pages); ?></b> (Total <?= $pending_total_records; ?> records)
                                </div>
                                <?php if ($pending_total_pages > 1): ?>
                                <ul class="custom-pagination">
                                    <li class="page-item <?= ($pending_page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="subcontractors_section.php?tab=pending&limit=<?= ($limit === 999999) ? 'all' : $limit; ?>&p_page=<?= $pending_page - 1; ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $pending_total_pages; $i++): ?>
                                        <li class="page-item <?= ($pending_page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="subcontractors_section.php?tab=pending&limit=<?= ($limit === 999999) ? 'all' : $limit; ?>&p_page=<?= $i; ?>"><?= $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= ($pending_page >= $pending_total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="subcontractors_section.php?tab=pending&limit=<?= ($limit === 999999) ? 'all' : $limit; ?>&p_page=<?= $pending_page + 1; ?>">Next</a>
                                    </li>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- COMPLETED TAB -->
                    <div class="tab-pane fade <?= ($active_tab == 'completed') ? 'show active' : ''; ?>" id="completed-pane" role="tabpanel">
                        <div class="management-card">
                            <div class="management-card-header">
                                <div class="management-title">
                                    <span class="title-icon completed-title-icon"><i class="bi bi-check2-circle"></i></span>
                                    <div>
                                        <h5>Approved & Rejected Subcontractors</h5>
                                        <small>History of processed subcontractor accounts</small>
                                    </div>
                                </div>

                                <div class="header-controls-group">
                                    <div class="rows-per-page-box">
                                        <span>Show</span>
                                        <select id="completedLimitSelect" onchange="changeLimit(this.value, 'completed')">
                                            <option value="5" <?= ($limit === 5) ? 'selected' : ''; ?>>5</option>
                                            <option value="10" <?= ($limit === 10) ? 'selected' : ''; ?>>10</option>
                                            <option value="25" <?= ($limit === 25) ? 'selected' : ''; ?>>25</option>
                                            <option value="50" <?= ($limit === 50) ? 'selected' : ''; ?>>50</option>
                                            <option value="all" <?= ($limit === 999999) ? 'selected' : ''; ?>>All</option>
                                        </select>
                                    </div>
                                    <div class="custom-search-input-box">
                                        <i class="bi bi-search"></i>
                                        <input type="text" id="completedSearchInput" placeholder="Search subcontractor..." onkeyup="filterTable('completedSearchInput', 'completedTableBody')">
                                    </div>
                                </div>
                            </div>

                            <div class="management-table-wrapper">
                                <div class="table-responsive">
                                    <table class="table management-table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Vendor Code</th>
                                                <th>Company Name</th>
                                                <th>Address</th>
                                                <th>Mobile Number</th>
                                                <th>Profile Details</th>
                                                <th>Status</th>
                                                <th class="text-center" width="130">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="completedTableBody">
                                        <?php
                                        if ($completed_subs && $completed_subs->num_rows > 0):
                                            while ($row = $completed_subs->fetch_assoc()):
                                                $status_class = ($row['status'] == 'Approved') ? 'status-approved' : 'status-rejected';
                                                $status_icon = ($row['status'] == 'Approved') ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                                                $missingProfileFields = subcontractorMissingProfileFields($row, $completionFields);
                                                $missingProfileCount = count($missingProfileFields);
                                        ?>
                                            <tr>
                                                <td><span class="info-text"><?= htmlspecialchars($row['vendor_code'] ?? '-'); ?></span></td>
                                                <td>
                                                    <div class="company-cell">
                                                        <div>
                                                            <div class="company-name"><?= htmlspecialchars($row['company_name']); ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['company_address'] ?? '-'); ?></span></td>
                                                <td><span class="info-text"><?= htmlspecialchars($row['mobile_number'] ?? '-'); ?></span></td>
                                                <td>
                                                    <span class="profile-completion-badge <?= $missingProfileCount === 0 ? 'complete' : 'incomplete'; ?>" title="<?= htmlspecialchars($missingProfileCount ? implode(', ', array_column($missingProfileFields, 'label')) : 'All required details provided', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="bi <?= $missingProfileCount === 0 ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?>"></i>
                                                        <?= $missingProfileCount === 0 ? 'Complete' : $missingProfileCount . ' missing'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-pill <?= $status_class; ?>">
                                                        <i class="bi <?= $status_icon; ?>"></i>
                                                        <?= htmlspecialchars($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="view_subcontractor.php?id=<?= urlencode($row['id']); ?>" class="view-btn view-primary js-view-link" title="View Details">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
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
                                                    <span>No approved or rejected subcontractors found.</span>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Pagination Bar -->
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Showing page <b><?= $completed_page; ?></b> of <b><?= max(1, $completed_total_pages); ?></b> (Total <?= $completed_total_records; ?> records)
                                </div>
                                <?php if ($completed_total_pages > 1): ?>
                                <ul class="custom-pagination">
                                    <li class="page-item <?= ($completed_page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="subcontractors_section.php?tab=completed&limit=<?= ($limit === 999999) ? 'all' : $limit; ?>&c_page=<?= $completed_page - 1; ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $completed_total_pages; $i++): ?>
                                        <li class="page-item <?= ($completed_page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="subcontractors_section.php?tab=completed&limit=<?= ($limit === 999999) ? 'all' : $limit; ?>&c_page=<?= $i; ?>"><?= $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= ($completed_page >= $completed_total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="subcontractors_section.php?tab=completed&limit=<?= ($limit === 999999) ? 'all' : $limit; ?>&c_page=<?= $completed_page + 1; ?>">Next</a>
                                    </li>
                                </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="viewSubModal" tabindex="-1" aria-labelledby="viewSubModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-header bg-light px-4 py-3 border-bottom">
                <h5 class="modal-title fw-bold text-dark" id="viewSubModalLabel">
                    <i class="bi bi-file-text-fill me-2 text-primary"></i>Subcontractor Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="modalBodyContent" style="max-height: 75vh; overflow-y: auto;">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-muted mt-2">Loading details...</p>
                </div>
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
        let ampm = hours >= 12 ? 'PM' : 'AM';
        
        hours = hours % 12;
        hours = hours ? hours : 12; 
        minutes = minutes < 10 ? '0' + minutes : minutes;
        seconds = seconds < 10 ? '0' + seconds : seconds;
        
        const strTime = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
        const clockElement = document.getElementById('liveClock');
        if (clockElement) {
            clockElement.innerText = strTime;
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

    function changeLimit(val, tabName) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('tab', tabName);
        urlParams.set('limit', val);
        urlParams.delete('p_page');
        urlParams.delete('c_page');
        window.location.href = 'subcontractors_section.php?' + urlParams.toString();
    }

    function filterTable(inputId, tbodyId) {
        const input = document.getElementById(inputId);
        const filter = input.value.toLowerCase();
        const tbody = document.getElementById(tbodyId);
        const rows = tbody.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            let rowText = rows[i].textContent || rows[i].innerText;
            if (rowText.toLowerCase().indexOf(filter) > -1) {
                rows[i].style.display = "";
            } else {
                rows[i].style.display = "none";
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const viewLinks = document.querySelectorAll('.js-view-link');
        const modalBody = document.getElementById('modalBodyContent');
        const viewModal = new bootstrap.Modal(document.getElementById('viewSubModal'));

        viewLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const url = this.getAttribute('href');
                
                modalBody.innerHTML = `
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="text-muted mt-2">Loading details...</p>
                    </div>
                `;
                
                viewModal.show();

                fetch(url)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const detailsCard = doc.querySelector('.details-card');
                        
                        if (detailsCard) {
                            const backBtn = detailsCard.querySelector('a.btn-outline-secondary');
                            if (backBtn) backBtn.remove();
                            
                            modalBody.replaceChildren(detailsCard);
                        } else {
                            modalBody.innerHTML = '<div class="alert alert-danger">Details load hotana samasya ali.</div>';
                        }
                    })
                    .catch(error => {
                        modalBody.innerHTML = '<div class="alert alert-danger">Kahi tari chukle ahe. Punha prayatna kara.</div>';
                    });
            });
        });

        modalBody.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-create-update-link]');
            if (!button) return;

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';
            try {
                const response = await fetch('create_subcontractor_update_link.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ id: button.dataset.id, csrf: button.dataset.csrf })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.error || 'Could not create the link.');

                const result = await Swal.fire({
                    title: 'Completion link created',
                    text: `${data.company || 'Subcontractor'} has ${data.missing.length} missing detail(s). This link expires in ${data.expires_in_days} days and works once.`,
                    input: 'textarea',
                    inputLabel: 'Copy and share this secure link',
                    inputValue: data.url,
                    inputAttributes: { readonly: true, rows: 3 },
                    showCancelButton: true,
                    showDenyButton: Boolean(data.email),
                    confirmButtonText: 'Copy Link',
                    denyButtonText: 'Open Email Draft',
                    cancelButtonText: 'Close',
                    didOpen: () => Swal.getInput()?.select()
                });

                if (result.isConfirmed) {
                    try {
                        await navigator.clipboard.writeText(data.url);
                        await Swal.fire({ icon: 'success', title: 'Link copied', timer: 1400, showConfirmButton: false });
                    } catch (copyError) {
                        await Swal.fire('Copy unavailable', 'Select and copy the link from the box.', 'info');
                    }
                } else if (result.isDenied && data.email) {
                    const subject = `Please complete your ${data.company || 'subcontractor'} profile`;
                    const body = `Hello,\n\nPlease complete the missing details in your subcontractor profile using this secure link. The link expires in 14 days and can be used once.\n\n${data.url}\n\nThank you.`;
                    window.location.href = `mailto:${encodeURIComponent(data.email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
                }
            } catch (error) {
                await Swal.fire('Could not create link', error.message, 'error');
            } finally {
                button.disabled = false;
                button.innerHTML = originalContent;
            }
        });
    });
</script>

</body>
</html>