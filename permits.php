<?php

session_start();

if (!isset($_SESSION['supervisor_logged']) || $_SESSION['supervisor_logged'] !== true) {

    header("Location: index.php");
    exit();

}



// ===========================
// DATABASE CONNECTION
// ===========================
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

// Helper functions (statusBadge etc.)
require_once "permit_helpers.php";

// =====================================================
// WORK-COMPLETION PHOTO (Supervisor side)
// =====================================================
if (!function_exists('render_supervisor_photo_action')) {

    function render_supervisor_photo_action($status, $type_code, $permit_id, $adminImage, $supervisorImage) {

        $status = strtolower(trim($status));

        if ($status === 'completed') {
            return '<span class="action-icon-box" style="background:var(--text-main);color:#fff;" title="Both photos received">
                        <img src="assets/images/update.png" alt="Completed" style="width:16px; height:16px; object-fit:contain; filter: brightness(200%);">
                    </span>';
        }

        if ($status === 'approved' || $status === 'third party approved') {
            return '<a
                        href="javascript:void(0)"
                        class="action-icon-box"
                        onclick="openUploadModal(\'' . htmlspecialchars($type_code) . '\', ' . $permit_id . ')"
                        title="Upload Work Completion Photo"
                    >
                        <img src="assets/images/camera.png" alt="Upload Photo" style="width:16px; height:16px; object-fit:contain;">
                    </a>';
        }

        return '';
    }
}

// =====================================================
// RESUBMIT (Supervisor side)
// =====================================================
if (!function_exists('render_resubmit_action')) {

    function render_resubmit_action($status, $type_code, $permit_id) {

        $status = strtolower(trim($status));

        if ($status !== 'rejected' && $status !== 'third party rejected') {
            return '';
        }

        $resubmit_titles = [
            'hot_work'        => 'Resubmit Hot Work Permit',
            'work_at_height'  => 'Resubmit Work at Height Permit',
            'normal'          => 'Resubmit Service Provider Permit',
        ];

        $title = $resubmit_titles[$type_code] ?? 'Resubmit Permit';
        $src   = 'service_provider_permit.php?resubmit_id=' . intval($permit_id);

        return '<a
                    href="javascript:void(0)"
                    class="action-icon-box permit-link"
                    data-src="' . htmlspecialchars($src) . '"
                    data-title="' . htmlspecialchars($title) . '"
                    title="Edit and resubmit this permit"
                >
                    <img src="assets/images/re.png" alt="Resubmit" style="width:16px; height:16px; object-fit:contain;">
                </a>';
    }
}



$sup_name      = $_SESSION['sup_name'] ?? "Supervisor";
$sup_division  = $_SESSION['sup_division'] ?? "General";
$supervisor_id = $_SESSION['supervisor_id'] ?? 1;

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

// Manually defined statuses list based on your UI screenshot requirements
$all_allowed_statuses = ['approved', 'completed', 'pending', 'on hold', 'rejected', 'expired'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Permits | QHSE Pro</title>
    <meta name="description" content="QHSC System - Supervisor Portal for managing Hot Work, Work at Height, and Service Provider permits.">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">

    <style>
    :root{
        --bg-app:#f4f6f9; --card-bg:#ffffff; --text-main:#0f172a; --text-muted:#64748b;
        --accent-color:#f97316; --accent-dark:#ea580c; --accent-soft:#fff7ed; --border:#e2e8f0;
        --primary-gradient:linear-gradient(135deg,#f97316 0%,#ea580c 100%);
        --card-shadow:0 4px 12px -2px rgba(15,23,42,.05),0 2px 4px -2px rgba(15,23,42,.03);
        --card-shadow-hover:0 14px 24px -8px rgba(249,115,22,.18);
        --danger:#dc2626; --danger-soft:#fef2f2; --warn:#c2410c; --warn-soft:#fff7ed; --ok:#15803d; --ok-soft:#f0fdf4;
    }
    *:focus-visible{ outline:2px solid var(--accent-color); outline-offset:2px; }
    body{ background:var(--bg-app); font-family:'Poppins',sans-serif; color:var(--text-main); min-height:100vh; font-size:14px; -webkit-font-smoothing:antialiased; overflow-x:hidden; }
    h1,h2,h3,h4,h5,h6{ font-family:'Poppins',sans-serif; letter-spacing:-.02em; }
    a{ text-decoration:none !important; }

    .page-layout-wrapper{ display:flex; flex-direction:column; min-height:100vh; width:100%; }
    .main-content-area{ flex:1; padding:14px; width:100%; max-width:100%; overflow-x:hidden; }

    /* ---------- Page header ---------- */
    .page-header{
        background:var(--card-bg); border:1px solid var(--border); border-radius:18px; box-shadow:var(--card-shadow);
        padding:18px 22px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;
        position:relative; overflow:hidden;
    }
    .page-header::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--primary-gradient); }
    .page-header h2{ font-size:21px; font-weight:700; margin:0; display:flex; align-items:center; gap:10px; }
    .title-icon{ width:38px; height:38px; border-radius:11px; background:var(--primary-gradient); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:17px; box-shadow:0 6px 14px rgba(249,115,22,.28); }
    .page-sub{ margin:4px 0 0; color:var(--text-muted); font-size:12.5px; }
    .page-title-meta{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

    .pill-badge{ display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:50px; font-size:12px; font-weight:500; background:var(--bg-app); color:#334155; border:1px solid var(--border); white-space:nowrap; }
    .user-chip{ display:flex; align-items:center; gap:10px; padding:5px 14px 5px 5px; background:var(--bg-app); border:1px solid var(--border); border-radius:50px; }
    .user-chip .avatar{ width:36px; height:36px; background:var(--primary-gradient); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; }
    .user-chip .avatar-img{ width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 0 0 1px var(--border); }
    .user-chip .u-name{ font-weight:600; color:var(--text-main); line-height:1.15; display:block; font-size:12.5px; }
    .user-chip .u-div{ color:var(--text-muted); font-size:10.5px; display:block; }

    .alert{ border:none; border-radius:12px; box-shadow:var(--card-shadow); font-size:13px; }
    @media (min-width:768px){
        .page-layout-wrapper{ flex-direction:row; align-items:flex-start; }
        .main-content-area{ padding:22px; }
        .page-header h2{ font-size:23px; }
    }
    @media (prefers-reduced-motion:reduce){ *{ transition:none !important; } }

    :root{ --info:#0369a1; --info-soft:#f0f9ff; }
    .data-num{ font-family:'JetBrains Mono',monospace; font-feature-settings:"tnum" 1; }

    /* ================= QUICK ACTION CARD ================= */
    /* Single full-width card (Tool Inspection card removed - it is now in the navbar) */
    .hero-row{ display:grid; grid-template-columns:1fr; gap:14px; margin:16px 0; }
    .create-permit-hero{
        background:var(--card-bg); border-radius:16px; box-shadow:var(--card-shadow);
        border:1px solid rgba(226,232,240,.9); overflow:hidden; position:relative;
        transition:all .2s ease; min-width:0;
    }
    .create-permit-hero:hover{ transform:translateY(-2px); box-shadow:var(--card-shadow-hover); border-color:rgba(249,115,22,.3); }
    .create-permit-hero a{ display:block; color:inherit; }
    .hero-inner{ display:flex; align-items:center; gap:14px; padding:14px 18px; }
    .hero-icon-wrap{ width:46px; height:46px; min-width:46px; border-radius:13px; background:var(--primary-gradient); display:flex; align-items:center; justify-content:center; box-shadow:0 6px 14px rgba(249,115,22,.25); transition:transform .25s ease; }
    .create-permit-hero:hover .hero-icon-wrap{ transform:scale(1.05); }
    .hero-icon-wrap i{ font-size:20px; color:#fff; }
    .hero-text{ flex:1; min-width:0; }
    .hero-eyebrow{ font-size:11px; font-weight:600; color:var(--accent-dark); margin-bottom:1px; display:block; }
    .hero-text h3{ font-size:15.5px; font-weight:700; margin:0; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .hero-text p{ font-size:12px; color:var(--text-muted); margin:0; line-height:1.35; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .hero-cta{ flex-shrink:0; }
    .btn-hero{ display:inline-flex; align-items:center; gap:5px; padding:7px 13px; border-radius:9px; background:var(--primary-gradient); color:#fff !important; font-weight:600; font-size:12px; white-space:nowrap; box-shadow:0 4px 10px rgba(249,115,22,.22); transition:all .2s ease; }
    .create-permit-hero:hover .btn-hero{ filter:brightness(1.05); }
    .create-permit-hero.primary{ background:var(--primary-gradient); border-color:transparent; box-shadow:0 10px 22px -8px rgba(234,88,12,.55); }
    .create-permit-hero.primary:hover{ box-shadow:0 14px 26px -8px rgba(234,88,12,.65); }
    .create-permit-hero.primary .hero-icon-wrap{ background:rgba(255,255,255,.2); box-shadow:none; }
    .create-permit-hero.primary .hero-eyebrow{ color:rgba(255,255,255,.85); }
    .create-permit-hero.primary .hero-text h3{ color:#fff; }
    .create-permit-hero.primary .hero-text p{ color:rgba(255,255,255,.85); }
    .create-permit-hero.primary .btn-hero{ background:#fff; color:var(--accent-dark) !important; box-shadow:none; }
    .create-permit-hero:not(.primary) .hero-icon-wrap{ background:var(--accent-soft); box-shadow:none; }
    .create-permit-hero:not(.primary) .hero-icon-wrap i{ color:var(--accent-dark); }
    .create-permit-hero:not(.primary) .btn-hero{ background:var(--text-main); box-shadow:none; }

    /* ================= HISTORY CARD / TABLE ================= */
    .history-card{ background:var(--card-bg); border-radius:18px; box-shadow:var(--card-shadow); border:1px solid rgba(226,232,240,.9); }
    .history-card .card-body{ padding:20px; }
    .history-plain-title{ font-size:17px; font-weight:700; color:var(--text-main); margin:0 0 16px; display:flex; align-items:center; gap:10px; }
    .history-plain-title i{ width:30px; height:30px; border-radius:9px; background:var(--accent-soft); color:var(--accent-dark); font-size:14px; display:inline-flex; align-items:center; justify-content:center; }

    .table-top-control{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px; padding:12px 16px; background:#f8fafc; border:1px solid var(--border); border-radius:12px; }
    .left-filters{ display:flex; gap:8px; flex-wrap:wrap; flex-shrink:0; }
    .left-filters .form-select{ min-width:120px; width:auto; font-size:13px; padding:7px 12px; border-radius:9px; border:1px solid var(--border); font-family:'Poppins',sans-serif; }
    .left-filters .form-select:focus{ border-color:#f97316; box-shadow:0 0 0 4px rgba(249,115,22,.12); }
    .right-search{ position:relative; width:260px; flex-shrink:0; }
    .right-search i{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--accent-dark); font-size:14px; pointer-events:none; }
    .right-search input#permitSearch{ width:100%; height:38px; border:1px solid var(--border); border-radius:10px; background:#fff; padding:0 12px 0 35px; font-size:13px; font-family:'Poppins',sans-serif; color:var(--text-main); transition:all .2s ease; }
    .right-search input#permitSearch::placeholder{ color:#94a3b8; }
    .right-search input#permitSearch:focus{ border-color:#f97316; box-shadow:0 0 0 4px rgba(249,115,22,.12); outline:none; }

    .table-responsive{ width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
    #permitTable{ width:100% !important; border-collapse:separate !important; border-spacing:0 !important; }
    #permitTable thead tr{ background-color:var(--text-main) !important; }
    #permitTable thead th{ background-color:var(--text-main) !important; border:none !important; color:#fff !important; padding:12px 12px !important; font-weight:600; font-size:11.5px; letter-spacing:.05em; text-transform:uppercase; text-align:left !important; white-space:nowrap; }
    #permitTable thead th:first-child{ border-top-left-radius:10px !important; border-bottom-left-radius:10px !important; }
    #permitTable thead th:last-child{ border-top-right-radius:10px !important; border-bottom-right-radius:10px !important; }

    .table{ border-radius:12px; overflow:hidden; font-size:13.5px; font-family:'Poppins',sans-serif; border-collapse:separate; border-spacing:0 4px; background:transparent; }
    .table td{ padding:9px 12px !important; vertical-align:middle; border-top:1px solid #eef1f4; border-bottom:1px solid #eef1f4; color:var(--text-main); background:#fff; text-align:left !important; }
    .table tbody td:first-child{ border-top-left-radius:8px; border-bottom-left-radius:8px; border-left:1px solid #eef1f4; }
    .table tbody td:last-child{ border-top-right-radius:8px; border-bottom-right-radius:8px; border-right:1px solid #eef1f4; }
    .table tbody tr{ transition:background .15s ease; }
    .table tbody tr:hover td{ background:var(--accent-soft); }

    .action-cell{ display:flex; align-items:center; justify-content:flex-start !important; gap:8px; white-space:nowrap; text-align:left !important; }
    .action-icon-box{ display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; background:#fff; border:1px solid var(--border); border-radius:9px; transition:all .2s ease; text-decoration:none; cursor:pointer; }
    .action-icon-box:hover{ border-color:#f97316; box-shadow:0 4px 10px rgba(249,115,22,.15); transform:translateY(-1px); }

    /* ================= BADGES ================= */
    .badge{ padding:5px 11px; font-size:11.5px; font-weight:600; border-radius:20px; letter-spacing:.02em; }
    .badge.type-hot{ background:var(--danger-soft); color:var(--danger); border:1px solid rgba(220,38,38,.18); }
    .badge.type-height{ background:var(--warn-soft); color:var(--warn); border:1px solid rgba(194,65,12,.18); }
    .badge.type-service{ background:var(--info-soft); color:var(--info); border:1px solid rgba(3,105,161,.18); }
    .badge-status{ display:inline-flex; align-items:center; padding:5px 12px; font-size:11.5px; font-weight:600; border-radius:20px; letter-spacing:.02em; white-space:nowrap; }
    .status-pending{ background:var(--warn-soft); color:var(--warn); border:1px solid rgba(194,65,12,.18); }
    .status-completed{ background:#f1f5f9; color:var(--text-main); border:1px solid var(--border); }
    .status-approved{ background:var(--ok-soft); color:var(--ok); border:1px solid rgba(21,128,61,.18); }
    .status-rejected{ background:var(--danger-soft); color:var(--danger); border:1px solid rgba(220,38,38,.18); }
    .status-expired{ background:#f1f5f9; color:var(--text-muted); border:1px solid var(--border); }
    .status-hold{ background:#fefce8; color:#ca8a04; border:1px solid rgba(202,138,4,.18); }

    /* ================= BUTTONS ================= */
    .btn-danger,.btn-warning,.btn-primary,.btn-success,.btn-secondary{ border-radius:10px; font-weight:600; font-family:'Poppins',sans-serif; border:none; font-size:13.5px; }
    .btn-danger{ background:linear-gradient(135deg,#ef4444,#dc2626); }
    .btn-warning{ background:var(--primary-gradient); color:#fff; }
    .btn-primary{ background:linear-gradient(135deg,#0284c7,#0369a1); }
    .btn-success{ background:linear-gradient(135deg,#16a34a,#15803d); }
    .btn-sm{ border-radius:8px; padding:6px 12px; font-size:12px; }

    /* ================= DATATABLES OVERRIDES ================= */
    .dataTables_wrapper{ font-size:12.5px; font-family:'Poppins',sans-serif; }
    .dataTables_wrapper .row:first-child{ display:none !important; }
    .dataTables_wrapper .dataTables_info{ color:var(--text-muted); font-size:12.5px; }
    .dataTables_wrapper .dataTables_paginate{ display:inline-flex !important; align-items:center; gap:3px; padding:3px 6px !important; background:#fff; border:1px solid var(--border); border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,.02); float:right; }
    .dataTables_wrapper .dataTables_paginate .paginate_button{ display:inline-flex !important; align-items:center !important; justify-content:center !important; border-radius:5px !important; font-family:'Poppins',sans-serif !important; font-size:11px !important; padding:2px 8px !important; min-width:26px !important; height:26px !important; line-height:1 !important; border:1px solid transparent !important; background:transparent !important; color:var(--text-main) !important; margin:0 !important; box-sizing:border-box !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current,
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover{ background:var(--primary-gradient) !important; border-color:transparent !important; color:#fff !important; box-shadow:0 1px 3px rgba(249,115,22,.25) !important; }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover{ background:#f1f5f9 !important; color:#f97316 !important; border-color:var(--border) !important; }

    /* ================= MODALS ================= */
    .modal-backdrop.show{ background:rgba(15,23,42,.55) !important; opacity:1 !important; }
    #permitModal .modal-dialog,#permitFormModal .modal-dialog{ max-width:90vw; width:900px; height:90vh; margin:5vh auto; }
    #permitModal .modal-content,.permit-modal-content{ height:100%; border:none !important; border-radius:18px !important; overflow:hidden; background:#fff !important; box-shadow:var(--card-shadow-hover); }
    #permitModal .modal-header,.permit-modal-header{ background:var(--primary-gradient) !important; color:#fff; border:none !important; padding:14px 20px; }
    #permitModal .modal-header .modal-title,.permit-modal-header .modal-title{ font-size:16px; font-weight:700; letter-spacing:-.01em; }
    #permitModal .modal-header .btn-close,.permit-modal-header .btn-close{ filter:invert(1) grayscale(100%) brightness(200%); opacity:.85; }
    #permitModal .modal-header .btn-close:hover,.permit-modal-header .btn-close:hover{ opacity:1; }
    #permitModal .modal-body,.permit-modal-content .modal-body{ padding:0 !important; height:calc(100% - 61px); position:relative; background:#f8fafc; }
    #permitModal iframe,.permit-form-frame{ width:100%; height:100%; border:none; display:block; background:#fff; }
    #permitModal .modal-loading,.permit-loading{ position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background:rgba(255,255,255,.85); font-weight:600; color:#ea580c; z-index:5; font-size:13.5px; gap:10px; }
    .modal-content{ border-radius:16px; border:none; }

    /* ================= RESPONSIVE ================= */
    @media (max-width:992px){
        .page-layout-wrapper{ flex-direction:column !important; }
        .main-content-area{ padding:14px !important; width:100%; }
    }
    @media (max-width:768px){
        .history-card .card-body{ padding:14px; }
        .table-top-control{ flex-direction:column; align-items:stretch; }
        .left-filters{ width:100%; }
        .left-filters .form-select{ flex:1; min-width:0; }
        .right-search{ width:100%; }
    }
    @media (max-width:576px){
        .main-content-area{ padding:10px !important; }
        body{ font-size:13px; }
        .hero-icon-wrap{ width:38px; height:38px; min-width:38px; }
        .hero-icon-wrap i{ font-size:16px; }
        .hero-text h3{ font-size:14px; }
        .hero-text p{ font-size:11px; }
        .left-filters{ flex-direction:column; }
        .left-filters .form-select{ width:100%; }
        #permitModal .modal-dialog,#permitFormModal .modal-dialog{ width:100vw; height:100vh; margin:0; max-width:100vw; }
        #permitModal .modal-content,.permit-modal-content{ border-radius:0 !important; }
    }

    @media (min-width:992px) and (min-height:700px){
        body.fit-screen{ height:100vh; overflow:hidden; min-height:0; }
        body.fit-screen .page-layout-wrapper{ height:100vh; }
        body.fit-screen .main-content-area{ height:100vh; display:flex; flex-direction:column; padding:14px 20px !important; overflow:hidden; }
        body.fit-screen .max-width-container{ flex:1 1 auto; min-height:0; width:100%; display:flex; flex-direction:column; }
        body.fit-screen .max-width-container > *{ flex-shrink:0; }
        body.fit-screen .max-width-container > .history-card{ flex:1 1 0; min-height:0; }

        body.fit-screen .page-header{ padding:12px 20px; margin-bottom:10px; }
        body.fit-screen .hero-row{ margin:10px 0; }
        body.fit-screen .hero-inner{ padding:10px 16px; }
        body.fit-screen .hero-icon-wrap{ width:40px; height:40px; min-width:40px; }

        body.fit-screen .history-card .card-body{ padding:14px 18px; display:flex; flex-direction:column; min-height:0; }
        body.fit-screen .history-plain-title{ margin-bottom:10px; }
        body.fit-screen .table-top-control{ margin-bottom:10px; padding:8px 12px; }
        body.fit-screen .table-responsive{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
        body.fit-screen .dataTables_wrapper{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; }
        body.fit-screen .dt-scroll-area{ flex:1 1 auto; min-height:0; overflow:auto; }
        body.fit-screen .dt-footer-row{ flex-shrink:0; margin-top:6px !important; }
        body.fit-screen #permitTable thead th{ padding:10px 12px !important; position:sticky; top:0; z-index:2; }
        body.fit-screen .table td{ padding:6px 12px !important; }
        body.fit-screen .action-icon-box{ width:30px; height:30px; }
    }
    </style>
</head>
<body class="fit-screen">

<div class="page-layout-wrapper" style="display: flex; align-items: flex-start; min-height: 100vh;">

<?php include "navbar.php"; ?>

<div class="main-content-area" style="flex: 1; overflow-x: hidden; min-width: 0;">

<div class="container-fluid max-width-container" style="max-width: 1400px; margin: 0 auto;">

    <!-- PAGE HEADER -->
    <header class="page-header">
        <div>
            <h2>
                <span class="title-icon"><i class="bi bi-file-earmark-medical-fill"></i></span>
                <span id="dynamicPageTitle">Permit Management Dashboard</span>
            </h2>
            <p class="page-sub">Create permits, track approvals and upload work completion photos.</p>
        </div>

        <div class="page-title-meta">
            <div class="pill-badge">
                <i class="bi bi-calendar3" style="color: #ea580c;"></i> <span id="liveDate"></span>
            </div>
            <div class="pill-badge">
                <i class="bi bi-clock-history" style="color: #f97316;"></i> <span id="liveTime"></span>
            </div>
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
    </header>

    <!-- Create New Permit (full width) -->
    <div class="hero-row">

        <div class="create-permit-hero primary">
            <a href="service_provider_permit.php" class="permit-link" data-src="service_provider_permit.php" data-title="Service Provider Permit" aria-label="Open Service Provider Permit Form">
                <div class="hero-inner">
                    <span class="hero-icon-wrap"><i class="bi bi-file-earmark-plus-fill"></i></span>
                    <span class="hero-text">
                        <span class="hero-eyebrow">CREATE NEW PERMIT</span>
                        <h3>Permit Form</h3>
                        <p>Start a new service provider permit application.</p>
                    </span>
                    <span class="hero-cta"><span class="btn-hero">Create Permit <i class="bi bi-arrow-right"></i></span></span>
                </div>
            </a>
        </div>

    </div>

    <div class="card history-card border-0">
        <div class="card-body">
            <h4 class="history-plain-title"><i class="bi bi-clock-history"></i> Permit History</h4>

            <div class="table-top-control">
                <div class="left-filters">
                    <select id="entriesLength" class="form-select">
                        <option value="5" selected>Show 5</option>
                        <option value="10">Show 10</option>
                        <option value="25">Show 25</option>
                        <option value="50">Show 50</option>
                        <option value="-1">Show All</option>
                    </select>

                    <select id="statusFilter" class="form-select">
                        <option value="all">All Status</option>
                        <?php
                        foreach ($all_allowed_statuses as $st) {
                            $display_label = ucwords($st);
                            echo '<option value="' . htmlspecialchars($st) . '">' . htmlspecialchars($display_label) . '</option>';
                        }
                        ?>
                    </select>

                    <select id="typeFilter" class="form-select">
                        <option value="all">All Permit Types</option>
                        <option value="Hot Work">Hot Work</option>
                        <option value="Work at Height">Work at Height</option>
                        <option value="General">General</option>
                    </select>
                </div>

                <div class="right-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="permitSearch" placeholder="Search permit...">
                </div>
            </div>

            <div class="table-responsive">
                <table id="permitTable" class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Permit Number</th>
                            <th>Permit Type</th>
                            <th>Project</th>
                            <th>Contact No</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php

                    $sql = "
                    SELECT
                        permit_id AS permit_id,
                        permit_number,
                        CASE work_category
                            WHEN 'hot_work'       THEN 'Hot Work'
                            WHEN 'work_at_height' THEN 'Work at Height'
                            ELSE 'General'
                        END AS permit_type,
                        COALESCE(work_category, 'General') AS type_code,
                        company_name AS project_company,
                        contact_number,
                        status,
                        created_at,
                        admin_work_image,
                        supervisor_work_image,
                        rejection_reason,
                        'view_service_provider.php' AS view_page
                    FROM service_provider_permits
                    ORDER BY created_at DESC
                    ";

                    $permit_result = $conn->query($sql);

                    if (!$permit_result) {
                        echo '<tr><td colspan="7" class="text-danger text-center py-4"><i class="bi bi-exclamation-triangle-fill"></i> SQL ERROR : ' . htmlspecialchars($conn->error) . '</td></tr>';
                    }
                    elseif ($permit_result->num_rows > 0) {
                        while ($row = $permit_result->fetch_assoc()) {
                            $status = strtolower(trim($row['status'] ?? 'pending'));

                            $status_class = 'status-pending';
                            $status_icon_html = '<img src="assets/images/ll.png" style="width:14px; height:14px; object-fit:contain; vertical-align:middle; margin-right:4px;">';
                            $status_label = ucwords($status);

                            if ($status === 'completed' || $status === 'updated') {
                                $status_class = 'status-completed';
                                $status_icon_html = '<img src="assets/images/update.png" alt="Updated" style="width:14px; height:14px; object-fit:contain; vertical-align:middle; margin-right:4px;">';
                            } elseif ($status === 'approved' || $status === 'third party approved') {
                                $status_class = 'status-approved';
                                $status_icon_html = '<img src="assets/images/a.png" alt="Approved" style="width:14px; height:14px; object-fit:contain; vertical-align:middle; margin-right:4px;">';
                            } elseif ($status === 'rejected' || $status === 'third party rejected') {
                                $status_class = 'status-rejected';
                                $status_icon_html = '<img src="assets/images/r.png" alt="Rejected" style="width:14px; height:14px; object-fit:contain; vertical-align:middle; margin-right:4px;">';
                            } elseif ($status === 'expired') {
                                $status_class = 'status-expired';
                                $status_icon_html = '<img src="assets/images/expired.png" alt="" style="width:14px; height:14px; object-fit:contain; vertical-align:middle; margin-right:4px;">';
                            } elseif ($status === 'hold' || $status === 'on hold') {
                                $status_class = 'status-hold';
                                $status_icon_html = '<i class="bi bi-pause-circle-fill" style="font-size:13px; vertical-align:middle; margin-right:4px; color:#ca8a04;"></i>';
                            }

                            $badge = '<span class="badge-status ' . $status_class . '">' . $status_icon_html . ' ' . $status_label . '</span>';

                            switch ($row['permit_type']) {
                                case 'Hot Work': $type_badge = 'type-hot'; break;
                                case 'Work at Height': $type_badge = 'type-height'; break;
                                case 'General': $type_badge = 'type-service'; break;
                                default: $type_badge = ''; break;
                            }

                            $permit_id = (int)($row['permit_id'] ?? 0);
                            $permit_number = htmlspecialchars($row['permit_number'] ?? '—');
                            $permit_type = htmlspecialchars($row['permit_type'] ?? '—');
                            $project_company = htmlspecialchars($row['project_company'] ?? '—');
                            $contact_number = htmlspecialchars($row['contact_number'] ?? '—');
                            $created_at = htmlspecialchars($row['created_at'] ?? '—');
                            $view_page = htmlspecialchars($row['view_page'] ?? '');

                            echo '
                            <tr class="permitRow" data-status="' . htmlspecialchars($status)  . '" data-type="' . htmlspecialchars($row['permit_type'] ?? '') . '">
                                <td class="fw-semibold">' . $permit_number . '</td>
                                <td><span class="badge ' . $type_badge . '">' . $permit_type . '</span></td>
                                <td>' . $project_company . '</td>
                                <td>' . $contact_number . '</td>
                                <td>' . $badge . '</td>
                                <td class="text-nowrap">' . $created_at . '</td>
                                <td class="action-cell">
                                    <a href="javascript:void(0)" class="action-icon-box permit-link" data-src="' . $view_page . '?id=' . $permit_id . '" data-title="View Permit Details (' . $permit_number . ')" title="View Permit Details">
                                        <img src="assets/images/v.png" style="width:16px; height:16px; object-fit:contain;">
                                    </a>
                                    ' . render_supervisor_photo_action($status, $row['type_code'] ?? '', $permit_id, $row['admin_work_image'] ?? null, $row['supervisor_work_image'] ?? null) . '
                                    ' . render_resubmit_action($status, $row['type_code'] ?? '', $permit_id) . '
                                </td>
                            </tr>';
                        }
                    }
                    else {
                        echo '<tr><td colspan="7" class="text-center py-4"><i class="bi bi-inbox display-6 d-block mb-2 text-muted"></i>No Permit Records Found</td></tr>';
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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

$(document).ready(function(){
    let permitTable = $('#permitTable').DataTable({
        pageLength: 5,
        lengthMenu:[
            [5,10,25,50,-1],
            [5,10,25,50,"All"]
        ],
        order: [[5, 'desc']],
        ordering:true,
        searching:true,
        dom: '<"row mb-3"<"col-md-6"l><"col-md-6"f>><"dt-scroll-area"rt><"row align-items-center mt-3 dt-footer-row"<"col-md-6"i><"col-md-6"p>>',
        language:{
            paginate:{
                previous:"Previous",
                next:"Next"
            }
        }
    });

    $.fn.dataTable.ext.search.push(function(settings, searchData, index, rowData, counter){
        if(settings.nTable.id !== "permitTable"){ return true; }
        let row = permitTable.row(index).node();
        if(!row){ return true; }
        let rowStatus = $(row).data("status");
        let rowType = $(row).data("type");
        let status = $("#statusFilter").val();
        let type = $("#typeFilter").val();
        
        // Handle matching for variations like 'hold' and 'on hold'
        let statusOK = (status === "all");
        if (!statusOK) {
            if (status === "on hold") {
                statusOK = (rowStatus === "on hold" || rowStatus === "hold");
            } else {
                statusOK = (rowStatus === status);
            }
        }

        let typeOK = (type === "all" || rowType === type);
        return statusOK && typeOK;
    });

    $("#permitSearch").on("keyup", function(){
        permitTable.search(this.value).draw();
    });

    $("#statusFilter, #typeFilter").on("change", function(){
        permitTable.draw();
    });

    $("#entriesLength").on("change", function(){
        document.body.classList.toggle('fit-screen', this.value === '5');
        permitTable.page.len(parseInt(this.value)).draw();
    });
});
</script>

<div class="modal fade" id="permitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="permitModalTitle">
                    <i class="bi bi-file-earmark-text"></i> Permit Form
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body position-relative">
                <div class="modal-loading" id="permitModalLoading">
                    <div class="spinner-border spinner-border-sm me-2" role="status" style="color: #ea580c;"></div> Loading form...
                </div>
                <iframe id="permitModalFrame" src="about:blank"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function(){
    let modalEl   = document.getElementById("permitModal");
    let frame     = document.getElementById("permitModalFrame");
    let loading   = document.getElementById("permitModalLoading");
    let titleEl   = document.getElementById("dynamicPageTitle");
    let bsModal   = new bootstrap.Modal(modalEl);

    document.querySelectorAll(".permit-link").forEach(function(link){
        link.addEventListener("click", function(e){
            e.preventDefault();
            let src   = this.getAttribute("data-src");
            let title = this.getAttribute("data-title") || "Permit Form";
            if (!src || src === "permits.php") return;
            if (titleEl) {
                titleEl.innerText = title;
            }
            loading.style.display = "flex";
            frame.src = src;
            bsModal.show();
        });
    });

    frame.addEventListener("load", function(){
        loading.style.display = "none";
    });

    modalEl.addEventListener("hidden.bs.modal", function(){
        frame.src = "about:blank";
        if (titleEl) {
            titleEl.innerText = "Permit Management Dashboard";
        }
    });
});

function openUploadModal(type, id) {
    document.getElementById("uploadType").value = type;
    document.getElementById("uploadId").value = id;
    document.getElementById("uploadImageFile").value = "";
    let modal = new bootstrap.Modal(document.getElementById("workPhotoModal"));
    modal.show();
}
</script>

<div class="modal fade" id="workPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="upload_work_completion.php" METHOD="POST" enctype="multipart/form-data">
                <div class="modal-header" style="background: var(--primary-gradient); color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-camera-fill me-2"></i> Upload Completion Photos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: invert(1) grayscale(100%) brightness(200%);"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="type" id="uploadType">
                    <input type="hidden" name="id" id="uploadId">
                    <p class="text-muted small">Please upload multiple completion photos to verify the work.</p>
                    <label class="form-label">Select Photos (jpg, png, webp - max 5MB each)</label>
                    <input type="file" name="work_images[]" id="uploadImageFile" class="form-control" accept="image/png, image/jpeg, image/webp" multiple required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload me-1"></i> Upload All</button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>