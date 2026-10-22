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

// =====================================================
// SUBCONTRACTOR COMPANY NAMES (for searchable dropdown)
// =====================================================
$subs = [];
$res_comp = $conn->query("SELECT id, company_name, owner_name, mobile_number, division FROM subcontractors WHERE company_name IS NOT NULL AND company_name <> '' ORDER BY company_name ASC, id ASC");
if ($res_comp) {
    while ($r = $res_comp->fetch_assoc()) {
        $subs[] = [
            'id'       => (int)$r['id'],
            'company'  => $r['company_name'],
            'owner'    => $r['owner_name'] ?? '',
            'mobile'   => $r['mobile_number'] ?? '',
            'division' => $r['division'] ?? '',
        ];
    }
}

// =====================================================
// INSPECTION RECORDS (table shown under the start card initially)
// =====================================================
$records = [];
$records_error = '';
$res_rec = $conn->query("SELECT id, company_name, owner_name, mobile_number, division, project_location,
                                tool_type, inspection_date, supervisor_name, created_at
                         FROM tool_inspection_records
                         ORDER BY inspection_date DESC, id DESC LIMIT 200");
if ($res_rec) {
    while ($r = $res_rec->fetch_assoc()) { $records[] = $r; }
} else {
    $records_error = $conn->error;
}

// =====================================================
// TOOL / EQUIPMENT INSPECTION CHECKLISTS
// =====================================================
$checklist_groups = [
    [
        'group_title' => 'Power Tool Inspection Checklist',
        'group_icon'  => 'bi-cpu-fill',
        'items' => [
            ['name' => 'Welding Machine',  'doc_no' => 'HSE/CL/W/Rev-0',  'link' => 'save_welding_checklist.php', 'bg' => 'assets/images/w.jpg'],
            ['name' => 'Portable Drill',   'doc_no' => 'HSE/CL/D/Rev-0',  'link' => 'portable_dril.php',          'bg' => 'assets/images/d.jpg'],
            ['name' => 'Concrete Cutting', 'doc_no' => 'HSE/CL/CC/Rev-0', 'link' => 'concrete_cutting.php',       'bg' => 'assets/images/Core.jpg'],
            ['name' => 'Hand Grinder',     'doc_no' => 'HSE/CL/G/Rev-0',  'link' => 'hand_grinder.php',           'bg' => 'assets/images/Grinder.jpg'],
            ['name' => 'Extension Cord',   'doc_no' => 'HSE/CL/E/Rev-0',  'link' => 'extension_cord.php',         'bg' => 'assets/images/Extension.jpg'],
        ],
    ],
    [
        'group_title' => 'Ladder Inspection Checklist',
        'group_icon'  => 'bi-signpost-split-fill',
        'items' => [
            ['name' => 'Ladder', 'doc_no' => 'HSE/CL/L/Rev-0', 'link' => 'ladder.php', 'bg' => 'assets/images/ladder1.jpg'],
        ],
    ],
    [
        'group_title' => 'Scaffold Inspection Checklist',
        'group_icon'  => 'bi-building-fill-gear',
        'items' => [
            ['name' => 'Scaffold', 'doc_no' => 'HSE/CL/S/Rev-0', 'link' => 'scaffold.php', 'bg' => 'assets/images/etienne.jpg'],
        ],
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tool Inspection | QHSE Pro</title>
    <meta name="description" content="QHSC System - Supervisor Portal for Power Tool, Ladder and Scaffold inspection checklists.">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">

    <style>
    :root{
        --bg-app: #f4f6f9;
        --card-bg: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --primary-gradient: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
        --card-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.04), 0 3px 4px -2px rgba(15, 23, 42, 0.03);
        --card-shadow-hover: 0 12px 20px -6px rgba(249, 115, 22, 0.12);
        --accent-color:#f97316; --accent-dark:#ea580c; --accent-soft:#fff7ed; --border:#e2e8f0;
    }

    body{
        background-color: var(--bg-app);
        font-family: 'Poppins', sans-serif;
        color: var(--text-main);
        min-height: 100vh;
        font-size: 14px;
        -webkit-font-smoothing: antialiased;
    }
    h1,h2,h3,h4,h5,h6{ font-family: 'Poppins', sans-serif; letter-spacing: -0.02em; }
    .data-num{ font-family: 'JetBrains Mono', monospace; font-feature-settings: "tnum" 1; }
    a{ text-decoration: none !important; }
    *:focus-visible{ outline:2px solid var(--accent-color); outline-offset:2px; }

    .page-layout-wrapper { display: flex; align-items: flex-start; min-height: 100vh; }
    .main-content-area { flex: 1; padding: 15px; overflow-x: hidden; min-width: 0; }
    .max-width-container { max-width: 1400px; }

    /* Initially hidden helper class */
    .initial-hidden { display: none !important; }

    /* ---------- Page header ---------- */
    .page-header{
        background:var(--card-bg); border:1px solid var(--border); border-radius:14px; box-shadow:var(--card-shadow);
        padding:12px 18px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
        position:relative; overflow:hidden;
    }
    .page-header::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--primary-gradient); }
    .page-header h2{ font-size:20px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px; }
    .page-title-text{ color:#0f172a; }
    .title-icon{ width:32px; height:32px; border-radius:9px; background:var(--primary-gradient); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:15px; box-shadow:0 4px 10px rgba(249,115,22,.28); }
    .page-header p.subtitle{ margin:2px 0 0; font-size:12px; color:var(--text-muted); }
    .page-title-meta{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

    .pill-badge{ display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:50px; font-size:11.5px; font-weight:500; background:var(--bg-app); color:#334155; border:1px solid var(--border); white-space:nowrap; }
    .user-chip{ display:flex; align-items:center; gap:8px; padding:4px 12px 4px 4px; background:var(--bg-app); border:1px solid var(--border); border-radius:50px; }
    .user-chip .avatar{ width:30px; height:30px; background:var(--primary-gradient); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; }
    .user-chip .avatar-img{ width:30px; height:30px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 0 0 1px var(--border); }
    .user-chip .u-name{ font-weight:600; color:var(--text-main); line-height:1.15; display:block; font-size:12px; }
    .user-chip .u-div{ color:var(--text-muted); font-size:10px; display:block; }

    /* ---------- START CARD (opens the subcontractor details popup) ---------- */
    .step-card{
        background:var(--card-bg); border-radius:14px; box-shadow:var(--card-shadow);
        border:1px solid rgba(226,232,240,.9); margin-bottom:12px; width: 100%;
    }
    .start-card .card-body{ padding: 16px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
    .start-info{ display:flex; align-items:center; gap:12px; }
    .step-num{ width:40px; height:40px; border-radius:12px; background:var(--primary-gradient); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:18px; box-shadow:0 4px 10px rgba(249,115,22,.28); flex-shrink:0; }
    .start-info h4{ font-size:16px; font-weight:700; margin:0; }
    .start-info p{ color:var(--text-muted); font-size:12px; margin:2px 0 0; }
    .btn-continue{ background:var(--primary-gradient); color:#fff; border:none; border-radius:8px; font-weight:600; font-size:13px; padding:8px 18px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 4px 10px rgba(249,115,22,.25); cursor:pointer; }
    .btn-continue:hover{ color:#fff; filter:brightness(1.05); }
    .btn-cancel-soft{ background:#fff; border:1px solid var(--border); border-radius:8px; font-weight:600; font-size:13px; padding:8px 16px; color:var(--text-main); }
    .btn-cancel-soft:hover{ border-color:#f97316; color:#ea580c; }

    /* ---------- Subcontractor details POPUP ---------- */
    #subModal .modal-content{ border:none; border-radius:14px; box-shadow:var(--card-shadow-hover); }
    #subModal .modal-header{ background:var(--primary-gradient); color:#fff; border:none; padding:12px 18px; border-radius:14px 14px 0 0; }
    #subModal .modal-header .modal-title{ font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
    #subModal .btn-close{ filter: invert(1) grayscale(100%) brightness(200%); opacity:.85; }
    #subModal .modal-body{ padding:16px 20px; }
    #subModal .modal-footer{ border-top:1px solid var(--border); padding:10px 20px; gap:8px; }
    #subModal .form-label{ font-size:12px; font-weight:600; color:#334155; margin-bottom:3px; }
    #subModal .form-label .req{ color:#dc2626; }
    #subModal .form-control{ font-family:'Poppins',sans-serif; font-size:13px; border:1px solid var(--border); border-radius:8px; padding:6px 10px; }
    #subModal .form-control:focus{ border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.12); }
    #subModal .form-control[readonly]{ background:#f8fafc; color:var(--text-muted); }
    #subModal .row.g-3 { --bs-gutter-y: 8px; }
    .modal-desc{ color:var(--text-muted); font-size:12px; margin:0 0 12px; }

    /* Searchable company dropdown */
    .combo-wrap{ position:relative; }
    .combo-list{ position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:50; background:#fff; border:1px solid var(--border); border-radius:8px; box-shadow:0 12px 24px -8px rgba(15,23,42,.18); max-height:200px; overflow-y:auto; display:none; padding:4px; }
    .combo-list.open{ display:block; }
    .combo-item{ padding:6px 10px; border-radius:6px; font-size:12.5px; cursor:pointer; color:var(--text-main); }
    .combo-item:hover, .combo-item.active{ background:var(--accent-soft); color:var(--accent-dark); }
    .combo-item mark{ background:#fed7aa; color:inherit; padding:0; border-radius:2px; }
    .combo-sub{ display:block; font-size:10.5px; color:var(--text-muted); margin-top:1px; }
    .combo-item:hover .combo-sub, .combo-item.active .combo-sub{ color:var(--accent-dark); }
    .form-control.autofilled{ background:#fffbeb; }
    .combo-empty{ padding:8px 10px; font-size:12px; color:var(--text-muted); }

    /* ---------- STEP 2: summary bar ---------- */
    .sub-summary{
        display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
        background:var(--card-bg); border:1px solid var(--border); border-left:5px solid var(--accent-color);
        border-radius:12px; box-shadow:var(--card-shadow); padding:10px 16px; margin-bottom:12px;
    }
    .sub-summary .sum-items{ display:flex; flex-wrap:wrap; gap:6px 18px; }
    .sum-item{ font-size:12px; color:var(--text-muted); }
    .sum-item b{ display:block; color:var(--text-main); font-size:13px; font-weight:600; }
    .sum-actions{ display:flex; gap:6px; flex-wrap:wrap; }
    .btn-change{ background:#fff; border:1px solid var(--border); border-radius:8px; padding:5px 12px; font-size:12px; font-weight:600; color:var(--text-main); display:inline-flex; align-items:center; gap:5px; cursor:pointer; }
    .btn-change:hover{ border-color:#f97316; color:#ea580c; }

    /* ---------- Records table ---------- */
    .records-card{ background:var(--card-bg); border-radius:14px; box-shadow:var(--card-shadow); border:1px solid rgba(226,232,240,.9); margin-bottom:12px; }
    .records-card .card-body{ padding:15px 20px; }
    .records-top{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
    .records-top h4{ font-size:16px; font-weight:700; margin:0; display:flex; align-items:center; gap:6px; }
    .records-top h4 i{ width:26px; height:26px; border-radius:7px; background:var(--accent-soft); color:var(--accent-dark); font-size:13px; display:inline-flex; align-items:center; justify-content:center; }
    .rec-search{ position:relative; width:240px; max-width:100%; }
    .rec-search i{ position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--accent-dark); font-size:13px; }
    .rec-search input{ width:100%; height:34px; border:1px solid var(--border); border-radius:8px; padding:0 10px 0 32px; font-size:12.5px; font-family:'Poppins',sans-serif; }
    .rec-search input:focus{ border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.12); outline:none; }
    .rec-wrap{ overflow-x:auto; max-height: 250px; }
    table.rec{ width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    table.rec thead th{ background:var(--text-main); color:#fff; font-size:11px; letter-spacing:.05em; text-transform:uppercase; padding:8px 10px; white-space:nowrap; font-weight:600; text-align:left; position: sticky; top: 0; z-index: 2; }
    table.rec thead th:first-child{ border-radius:8px 0 0 8px; }
    table.rec thead th:last-child{ border-radius:0 8px 8px 0; }
    table.rec td{ padding:8px 10px; border-bottom:1px solid #eef1f4; vertical-align:middle; }
    table.rec tbody tr:hover td{ background:var(--accent-soft); }
    .tool-badge{ display:inline-block; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; background:var(--accent-soft); color:var(--accent-dark); border:1px solid rgba(249,115,22,.2); white-space:nowrap; }
    .btn-view{ display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:7px; background:#fff; border:1px solid var(--border); font-size:11.5px; font-weight:600; color:var(--text-main); cursor:pointer; }
    .btn-view:hover{ border-color:#f97316; color:#ea580c; }
    .records-tools{ display:flex; align-items:center; gap:8px; }
    .btn-qr-page{ display:inline-flex; align-items:center; gap:6px; padding:7px 11px; border:1px solid #fed7aa; border-radius:8px; background:#fff7ed; color:#9a3412; font-size:11.5px; font-weight:700; text-decoration:none; white-space:nowrap; }
    .btn-qr-page:hover{ background:#ffedd5; color:#7c2d12; }
    .rec-empty{ text-align:center; padding:20px 0; color:var(--text-muted); }
    .rec-empty i{ font-size:28px; display:block; margin-bottom:4px; }
    .detail-grid{ display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
    .detail-grid .d-item small{ display:block; font-size:11px; color:var(--text-muted); margin-bottom:1px; }
    .detail-grid .d-item b{ font-size:13px; font-weight:600; word-break:break-word; }
    #recordModal .modal-content{ border:none; border-radius:14px; overflow:hidden; }
    #recordModal .modal-header{ background:var(--primary-gradient); color:#fff; border:none; padding:12px 18px; }
    #recordModal .btn-close{ filter:invert(1) grayscale(100%) brightness(200%); opacity:.85; }
    .qa-title{ font-size:14px; font-weight:700; margin:16px 0 8px; padding-top:12px; border-top:1px dashed var(--border); display:flex; align-items:center; gap:6px; }
    .qa-list{ display:flex; flex-direction:column; gap:6px; }
    .qa-row{ display:flex; align-items:flex-start; gap:10px; background:#f8fafc; border:1px solid #eef1f4; border-radius:8px; padding:7px 10px; }
    .qa-no{ flex:0 0 auto; width:22px; height:22px; border-radius:50%; background:var(--accent-soft); color:var(--accent-dark); font-size:11px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
    .qa-q{ flex:1; font-size:12.5px; font-weight:500; }
    .qa-q small{ display:block; font-size:11px; color:var(--text-muted); font-weight:400; }
    .qa-ans{ flex:0 0 auto; font-size:11.5px; font-weight:700; padding:3px 12px; border-radius:20px; }
    .qa-ans.yes{ background:#dcfce7; color:#15803d; }
    .qa-ans.no{ background:#fee2e2; color:#b91c1c; }
    .qa-ans.na{ background:#f1f5f9; color:#64748b; }
    .qa-note{ font-size:12px; color:var(--text-muted); padding:8px 0; }
    .qa-alert{ background:#fef2f2; border-left:3px solid #dc2626; color:#991b1b; font-size:12px; font-weight:500; padding:7px 10px; border-radius:6px; margin-bottom:8px; }
    @media (max-width:575.98px){ .detail-grid{ grid-template-columns:1fr; } .rec-search{ width:100%; } }

    /* ---------- Checklists ---------- */
    .checklist-group-card{ background: var(--card-bg); border-radius: 14px; box-shadow: var(--card-shadow); border: 1px solid rgba(226, 232, 240, 0.9); margin-bottom: 12px; }
    .checklist-group-card .card-body{ padding: 15px; }
    .group-title{ font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0 0 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .group-title i{ width:26px; height:26px; border-radius:7px; background:var(--accent-soft); color:var(--accent-dark); font-size:13px; display:inline-flex; align-items:center; justify-content:center; }
    .group-count{ margin-left: auto; font-size: 11px; font-weight: 600; color: var(--text-muted); background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 50px; padding: 2px 8px; }

    .checklist-grid{ display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; }
    .checklist-card{ background: #ffffff; border: 1px solid #eef1f4; border-radius: 12px; overflow: hidden; cursor: pointer; transition: all .2s ease; display: flex; flex-direction: column; color: inherit; position: relative; }
    .checklist-card:hover{ transform: translateY(-2px); box-shadow: var(--card-shadow-hover); border-color: rgba(249, 115, 22, 0.3); }
    .checklist-card .card-top{ height: 3px; background: var(--primary-gradient); }
    .checklist-card .card-content{ position: relative; background-size: cover; background-position: center center; padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 8px; color: #fff; }
    .checklist-card .card-content::before{ content: ""; position: absolute; inset: 0; background: linear-gradient(rgba(15, 23, 42, 0.40), rgba(30, 41, 59, 0.50)); z-index: 1; }
    .checklist-card .card-content > *{ position: relative; z-index: 2; }
    .checklist-card h4{ font-size: 13.5px; font-weight: 700; margin: 0; color: #ffffff; line-height: 1.25; text-shadow: 0 1px 4px rgba(0,0,0,0.8); }
    .checklist-card .doc-no{ font-size: 10.5px; color: #e2e8f0; font-family: 'JetBrains Mono', monospace; text-shadow: 0 1px 3px rgba(0,0,0,0.8); }
    .checklist-card .open-tag{ margin-top: auto; padding-top: 4px; border-top: 1px dashed rgba(255, 255, 255, 0.4); font-size: 11.5px; font-weight: 600; color: #fed7aa; display: inline-flex; align-items: center; gap: 4px; text-shadow: 0 1px 3px rgba(0,0,0,0.8); }
    .checklist-card .open-tag i{ transition: transform .2s ease; }
    .checklist-card:hover .open-tag i{ transform: translate(2px, -2px); }

    /* ---------- Checklist modal ---------- */
    .modal-backdrop.show{ background: rgba(15, 23, 42, 0.55) !important; opacity: 1 !important; }
    #checklistModal .modal-dialog{ max-width: 90vw; width: 900px; height: 90vh; margin: 5vh auto; }
    #checklistModal .modal-content{ height: 100%; border: none !important; border-radius: 14px !important; overflow: hidden; background: #fff !important; box-shadow: var(--card-shadow-hover); }
    #checklistModal .modal-header{ background: var(--primary-gradient) !important; color: #fff; border: none !important; padding: 12px 18px; }
    #checklistModal .modal-header .modal-title{ font-size: 15px; font-weight: 700; letter-spacing: -0.01em; }
    #checklistModal .modal-header .btn-close{ filter: invert(1) grayscale(100%) brightness(200%); opacity: .85; }
    #checklistModal .modal-header .btn-close:hover{ opacity: 1; }
    #checklistModal .modal-body{ padding: 0 !important; height: calc(100% - 53px); position: relative; background: #f8fafc; }
    #checklistModal iframe{ width: 100%; height: 100%; border: none; display: block; background: #fff; }
    #checklistModal .modal-loading{ position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(255,255,255,.85); font-weight: 600; color: #ea580c; z-index: 5; font-size: 13px; gap: 8px; }

    @media (max-width: 991.98px) {
        .page-layout-wrapper { flex-direction: column; }
        .main-content-area { padding: 10px; }
        .page-header { flex-direction: column; align-items: flex-start; }
        .page-title-meta { width: 100%; justify-content: flex-start; }
    }
    @media (max-width: 575.98px) {
        .checklist-grid { grid-template-columns: 1fr; }
        .pill-badge { font-size: 11.5px; padding: 5px 8px; }
        .page-header h2 { font-size: 18px; }
        .start-card .card-body{ padding:12px 14px; }
        #checklistModal .modal-dialog { max-width: 95vw; width: 95vw; height: 92vh; margin: 4vh auto; }
    }
    </style>
</head>
<body>

<div class="page-layout-wrapper">

<?php include "navbar.php"; ?>

<div class="main-content-area">

<div class="container-fluid max-width-container">

    <!-- PAGE HEADER -->
    <header class="page-header">
        <div>
            <h2>
                <span class="title-icon"><i class="bi bi-tools"></i></span>
                <span class="page-title-text">Tool Inspection</span>
            </h2>
            <p class="subtitle" id="pageSubtitle">First enter the subcontractor details, then select a checklist.</p>
        </div>

        <div class="page-title-meta">
            <a href="inspection_records.php" class="pill-badge" style="font-weight:600; color:#ea580c; border-color:#f97316;">
                <i class="bi bi-clipboard2-data-fill"></i> Inspection Records

            </a>
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

    <!-- =====================================================
         START CARD : button opens the Subcontractor Details popup
    ====================================================== -->
    <div class="step-card start-card" id="step1">
        <div class="card-body">
            <div class="start-info">
                <span class="step-num"><i class="bi bi-clipboard2-plus-fill"></i></span>
                <div>
                    <h4>Start a New Inspection</h4>
                    <p>Enter the subcontractor details first, then choose the checklist to fill in.</p>
                </div>
            </div>
            <button type="button" class="btn-continue" id="openSubModalBtn">
                <i class="bi bi-person-vcard-fill"></i> Enter Subcontractor Details
            </button>
        </div>
    </div>

    <!-- =====================================================
         INSPECTION RECORDS TABLE (shown initially below the start card)
    ====================================================== -->
    <div class="records-card" id="recordsSection">
        <div class="card-body">
            <div class="records-top">
                <h4><i class="bi bi-clipboard2-data-fill"></i> Inspection Records</h4>
                <div class="records-tools">
                    <a href="tool_inspection_qr.php" class="btn-qr-page"><i class="bi bi-qr-code"></i> QR Codes</a>
                    <div class="rec-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="recSearch" placeholder="Search company, tool...">
                    </div>
                </div>
            </div>

            <div class="rec-wrap">
                <table class="rec" id="recTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Subcontractor</th>
                            <th>Tool</th>
                            <th>Inspected By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($records_error !== ''): ?>
                        <tr><td colspan="5" class="text-danger text-center py-4">
                            <i class="bi bi-exclamation-triangle-fill"></i> Could not load records.
                            <div class="small"><?php echo htmlspecialchars($records_error); ?></div>
                        </td></tr>
                    <?php elseif (!$records): ?>
                        <tr><td colspan="5"><div class="rec-empty"><i class="bi bi-inbox"></i>No inspection records yet</div></td></tr>
                    <?php else: foreach ($records as $i => $r): ?>
                        <tr data-search="<?php echo htmlspecialchars(strtolower($r['company_name'] . ' ' . $r['tool_type'] . ' ' . $r['owner_name'] . ' ' . $r['supervisor_name'])); ?>">
                            <td class="text-nowrap"><?php echo htmlspecialchars($r['inspection_date']); ?></td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($r['company_name']); ?></td>
                            <td><span class="tool-badge"><?php echo htmlspecialchars($r['tool_type']); ?></span></td>
                            <td><?php echo htmlspecialchars($r['supervisor_name'] ?: '—'); ?></td>
                            <td><button type="button" class="btn-view" data-idx="<?php echo $i; ?>"><i class="bi bi-eye-fill"></i> View</button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- =====================================================
         STEP 2 : CHECKLISTS (hidden initially, shown after the popup form is submitted)
    ====================================================== -->
    <div id="step2" class="initial-hidden">

        <div class="sub-summary">
            <div class="sum-items">
                <div class="sum-item">Subcontractor<b id="sumCompany">-</b></div>
                <div class="sum-item">Contact Person<b id="sumPerson">-</b></div>
                <div class="sum-item">Contact No<b id="sumPhone">-</b></div>
                <div class="sum-item">Division<b id="sumDivision">-</b></div>
                <div class="sum-item">Location<b id="sumLocation">-</b></div>
                <div class="sum-item">Date<b id="sumDate">-</b></div>
            </div>
            <div class="sum-actions">
                <button type="button" class="btn-change" id="changeDetailsBtn">
                    <i class="bi bi-pencil-square"></i> Change Details
                </button>
                <button type="button" class="btn-change" id="backToRecordsBtn">
                    <i class="bi bi-arrow-left-circle"></i> Back to Records
                </button>
            </div>
        </div>

        <?php foreach ($checklist_groups as $group): ?>
            <div class="checklist-group-card">
                <div class="card-body">
                    <h4 class="group-title">
                        <i class="bi <?php echo htmlspecialchars($group['group_icon']); ?>"></i>
                        <?php echo htmlspecialchars($group['group_title']); ?>
                        <span class="group-count"><?php echo count($group['items']); ?> item<?php echo count($group['items']) > 1 ? 's' : ''; ?></span>
                    </h4>

                    <div class="checklist-grid">
                        <?php foreach ($group['items'] as $item): ?>
                            <a
                                href="javascript:void(0)"
                                class="checklist-card checklist-link"
                                data-src="<?php echo htmlspecialchars($item['link']); ?>"
                                data-title="<?php echo htmlspecialchars($item['name']); ?>"
                            >
                                <div class="card-top"></div>
                                <div class="card-content" style="background-image: url('<?php echo htmlspecialchars($item['bg']); ?>');">
                                    <h4><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <span class="doc-no">Doc No: <?php echo htmlspecialchars($item['doc_no']); ?></span>
                                    <span class="open-tag"><i class="bi bi-box-arrow-up-right"></i> Open Checklist</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

</div>

</div>
</div>

<!-- =====================================================
     SUBCONTRACTOR DETAILS POPUP (form moved here)
====================================================== -->
<div class="modal fade" id="subModal" tabindex="-1" aria-labelledby="subModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="subModalTitle"><i class="bi bi-person-vcard-fill"></i> Subcontractor Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="modal-desc">Fill in the details of the subcontractor whose tools are being inspected.</p>

                <form id="subForm" novalidate>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="subCompany">Subcontractor / Company Name <span class="req">*</span></label>
                            <div class="combo-wrap">
                                <input type="text" class="form-control" id="subCompany" name="company" placeholder="Type to search company..." autocomplete="off" required>
                                <div class="combo-list" id="companyList"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="subPerson">Contact Person <span class="req">*</span></label>
                            <input type="text" class="form-control" id="subPerson" name="person" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="subPhone">Contact Number <span class="req">*</span></label>
                            <input type="tel" class="form-control" id="subPhone" name="phone" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="subDivision">Division</label>
                            <input type="text" class="form-control" id="subDivision" name="division" placeholder="Auto-filled from company">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="subLocation">Project / Work Location <span class="req">*</span></label>
                            <input type="text" class="form-control" id="subLocation" name="location" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="subDate">Inspection Date <span class="req">*</span></label>
                            <input type="date" class="form-control" id="subDate" name="date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="subSupervisor">Inspected By (Supervisor)</label>
                            <input type="text" class="form-control" id="subSupervisor" name="supervisor" value="<?php echo htmlspecialchars($sup_name); ?>" readonly>
                        </div>
                    </div>

                    <div id="formError" class="text-danger small mt-2" style="display:none;">
                        <i class="bi bi-exclamation-circle-fill"></i> Please fill all required fields.
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel-soft" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="subForm" class="btn-continue">
                    Continue to Checklists <i class="bi bi-arrow-right-circle-fill"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Record details modal -->
<div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clipboard2-check-fill"></i> Inspection Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="detail-grid" id="recordDetails"></div>
                <div id="recordAnswers"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning btn-sm text-white" id="downloadInspectionReport" disabled><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF Report</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js"></script>
<script>
function updateDateTime() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    const dateStr = now.toLocaleDateString('en-US', options);
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    if (document.getElementById('liveDate')) document.getElementById('liveDate').innerText = dateStr;
    if (document.getElementById('liveTime')) document.getElementById('liveTime').innerText = timeStr;
}
setInterval(updateDateTime, 1000);
updateDateTime();
</script>

<div class="modal fade" id="checklistModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="checklistModalTitle">
                    <i class="bi bi-clipboard2-check-fill"></i> Checklist
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body position-relative">
                <div class="modal-loading" id="checklistModalLoading">
                    <div class="spinner-border spinner-border-sm me-2" role="status" style="color: #ea580c;"></div> Loading checklist...
                </div>
                <iframe id="checklistModalFrame" src="about:blank"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function(){

    // ---------- Elements ----------
    const step1          = document.getElementById("step1");
    const step2          = document.getElementById("step2");
    const recordsSection = document.getElementById("recordsSection");
    const form           = document.getElementById("subForm");
    const errBox         = document.getElementById("formError");
    const subtitle       = document.getElementById("pageSubtitle");
    const dateInp        = document.getElementById("subDate");

    const subModalEl     = document.getElementById("subModal");
    const subModal       = new bootstrap.Modal(subModalEl);

    // ---------- Searchable company dropdown + autofill ----------
    const subs      = <?php echo json_encode($subs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const compInput = document.getElementById("subCompany");
    const compList  = document.getElementById("companyList");
    const personInp = document.getElementById("subPerson");
    const phoneInp  = document.getElementById("subPhone");
    const divInp    = document.getElementById("subDivision");
    let activeIdx   = -1;
    let matches     = [];
    let selectedId  = null;

    function escHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function renderCompanies() {
        const q = compInput.value.trim().toLowerCase();
        matches = subs.filter(s => s.company.toLowerCase().includes(q));
        activeIdx = -1;

        if (!matches.length) {
            compList.innerHTML = '<div class="combo-empty">No matching company found</div>';
        } else {
            compList.innerHTML = matches.map(function(s, idx){
                let html = escHtml(s.company);
                if (q) {
                    const i = s.company.toLowerCase().indexOf(q);
                    html = escHtml(s.company.slice(0, i)) + '<mark>' + escHtml(s.company.slice(i, i + q.length)) + '</mark>' + escHtml(s.company.slice(i + q.length));
                }
                const sub = s.owner ? '<span class="combo-sub">' + escHtml(s.owner) + (s.mobile ? ' &bull; ' + escHtml(s.mobile) : '') + '</span>' : '';
                return '<div class="combo-item" data-idx="' + idx + '">' + html + sub + '</div>';
            }).join("");
        }
        compList.classList.add("open");
    }

    function fillField(inp, val) {
        inp.value = val || "";
        inp.classList.toggle("autofilled", !!val);
    }

    function selectCompany(s) {
        compInput.value = s.company;
        selectedId = s.id;
        fillField(personInp, s.owner);
        fillField(phoneInp,  s.mobile);
        fillField(divInp,    s.division);
        compList.classList.remove("open");
    }

    compInput.addEventListener("input", function(){
        selectedId = null;
        renderCompanies();
    });
    compInput.addEventListener("focus", renderCompanies);

    [personInp, phoneInp, divInp].forEach(function(inp){
        inp.addEventListener("input", function(){ inp.classList.remove("autofilled"); });
    });

    compList.addEventListener("mousedown", function(e){
        const item = e.target.closest(".combo-item");
        if (!item) return;
        e.preventDefault();
        selectCompany(matches[parseInt(item.getAttribute("data-idx"), 10)]);
    });

    compInput.addEventListener("keydown", function(e){
        const items = compList.querySelectorAll(".combo-item");
        if (!items.length) return;
        if (e.key === "ArrowDown" || e.key === "ArrowUp") {
            e.preventDefault();
            activeIdx = e.key === "ArrowDown" ? Math.min(activeIdx + 1, items.length - 1) : Math.max(activeIdx - 1, 0);
            items.forEach((el, i) => el.classList.toggle("active", i === activeIdx));
            items[activeIdx].scrollIntoView({ block: "nearest" });
        } else if (e.key === "Enter" && activeIdx >= 0) {
            e.preventDefault();
            selectCompany(matches[activeIdx]);
        } else if (e.key === "Escape") {
            // keep the popup open, only close the dropdown list
            if (compList.classList.contains("open")) {
                e.stopPropagation();
                compList.classList.remove("open");
            }
        }
    });

    document.addEventListener("click", function(e){
        if (!e.target.closest(".combo-wrap")) compList.classList.remove("open");
    });

    dateInp.value = new Date().toISOString().slice(0, 10);

    let subDetails = null;

    // ---------- Popup open / close ----------
    function openSubModal() {
        errBox.style.display = "none";
        subModal.show();
    }

    subModalEl.addEventListener("shown.bs.modal", function(){
        compInput.focus();
    });
    subModalEl.addEventListener("hide.bs.modal", function(){
        compList.classList.remove("open");
    });

    document.getElementById("openSubModalBtn").addEventListener("click", openSubModal);

    // ---------- View switching ----------
    function showStep2() {
        document.getElementById("sumCompany").textContent  = subDetails.company;
        document.getElementById("sumPerson").textContent   = subDetails.person;
        document.getElementById("sumPhone").textContent    = subDetails.phone;
        document.getElementById("sumDivision").textContent = subDetails.division || "-";
        document.getElementById("sumLocation").textContent = subDetails.location;
        document.getElementById("sumDate").textContent     = subDetails.date;

        step1.classList.add("initial-hidden");            // start card hidden
        recordsSection.classList.add("initial-hidden");   // records table hidden
        step2.classList.remove("initial-hidden");         // checklists shown
        subtitle.textContent = "Select a checklist below to open and fill it in.";
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function showStart() {
        step2.classList.add("initial-hidden");
        step1.classList.remove("initial-hidden");
        recordsSection.classList.remove("initial-hidden");
        subtitle.textContent = "First enter the subcontractor details, then select a checklist.";
    }

    form.addEventListener("submit", function(e){
        e.preventDefault();

        const data = {
            company:    form.company.value.trim(),
            person:     form.person.value.trim(),
            phone:      form.phone.value.trim(),
            division:   form.division.value.trim(),
            sub_id:     selectedId,
            location:   form.location.value.trim(),
            date:       form.date.value,
            supervisor: form.supervisor.value.trim()
        };

        if (!data.company || !data.person || !data.phone || !data.location || !data.date) {
            errBox.style.display = "block";
            return;
        }
        errBox.style.display = "none";

        subDetails = data;
        subModal.hide();
        showStep2();
    });

    // Change Details -> reopen the popup with the values still filled in
    document.getElementById("changeDetailsBtn").addEventListener("click", openSubModal);

    // Back to Records -> leave the checklist view
    document.getElementById("backToRecordsBtn").addEventListener("click", showStart);

    const records = <?php echo json_encode($records, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const recModal = new bootstrap.Modal(document.getElementById("recordModal"));
    const reportButton = document.getElementById('downloadInspectionReport');
    let activeRecord = null;
    let activeAnswers = null;
    let activeExtra = {};

    document.querySelectorAll(".btn-view").forEach(function(btn){
        btn.addEventListener("click", function(){
            const r = records[parseInt(this.getAttribute("data-idx"), 10)];
            if (!r) return;
            activeRecord = r;
            activeAnswers = null;
            activeExtra = {};
            reportButton.disabled = true;
            const fields = [
                ["Subcontractor",   r.company_name],
                ["Owner / Contact", r.owner_name],
                ["Mobile Number",   r.mobile_number],
                ["Division",        r.division],
                ["Project / Location", r.project_location],
                ["Tool Inspected",  r.tool_type],
                ["Inspection Date", r.inspection_date],
                ["Inspected By",    r.supervisor_name],
                ["Recorded At",     r.created_at]
            ];
            document.getElementById("recordDetails").innerHTML = fields.map(function(f){
                return '<div class="d-item"><small>' + escHtml(f[0]) + '</small><b>' + escHtml(f[1] || "-") + '</b></div>';
            }).join("");
            recModal.show();
            loadAnswers(r.id);
        });
    });

    // ---------- Checklist questions + answers ----------
    const ansBox = document.getElementById("recordAnswers");
    let ansToken = 0;

    function loadAnswers(recordId) {
        const token = ++ansToken;
        activeAnswers = null;
        activeExtra = {};
        reportButton.disabled = true;
        ansBox.innerHTML = '<div class="qa-note"><span class="spinner-border spinner-border-sm me-2" style="color:#ea580c;"></span>Loading checklist answers...</div>';

        fetch("get_inspection_answers.php?id=" + encodeURIComponent(recordId), { credentials: "same-origin" })
            .then(function(res){ return res.json(); })
            .then(function(d){
                if (token !== ansToken) return;
                if (!d.ok) { ansBox.innerHTML = '<div class="qa-note text-danger">Could not load answers: ' + escHtml(d.error || "") + '</div>'; return; }
                if (!d.linked) { ansBox.innerHTML = '<div class="qa-note"><i class="bi bi-info-circle"></i> No checklist answers saved for this record.</div>'; return; }

                activeAnswers = d.answers;
                activeExtra = d.extra || {};
                reportButton.disabled = false;
                const noCount = d.answers.filter(function(a){ return a.ans === "No"; }).length;
                let html = '<div class="qa-title"><i class="bi bi-ui-checks-grid"></i> Checklist Answers</div>';
                if (noCount > 0) {
                    html += '<div class="qa-alert"><i class="bi bi-exclamation-triangle-fill"></i> ' + noCount + ' item' + (noCount > 1 ? 's' : '') + ' answered "No" - tool should be tagged and taken out of service.</div>';
                }
                html += '<div class="qa-list">' + d.answers.map(function(a){
                    const cls = a.ans === "Yes" ? "yes" : (a.ans === "No" ? "no" : "na");
                    return '<div class="qa-row">' +
                        '<span class="qa-no">' + a.no + '</span>' +
                        '<div class="qa-q">' + escHtml(a.en) + (a.si ? '<small>' + escHtml(a.si) + '</small>' : '') + '</div>' +
                        '<span class="qa-ans ' + cls + '">' + escHtml(a.ans || "-") + '</span>' +
                        '</div>';
                }).join("") + '</div>';

                const e = d.extra || {};
                const meta = [];
                if (e.workplace)    meta.push(["Project / Workplace", e.workplace]);
                if (e.reference)    meta.push(["Reference No", e.reference]);
                meta.push(["Completed By", e.completed_by]);
                meta.push(["EPF / NIC", e.epf_nic]);
                html += '<div class="detail-grid mt-3">' + meta.map(function(f){
                    return '<div class="d-item"><small>' + escHtml(f[0]) + '</small><b>' + escHtml(f[1] || "-") + '</b></div>';
                }).join("") + '</div>';

                ansBox.innerHTML = html;
            })
            .catch(function(){
                if (token !== ansToken) return;
                ansBox.innerHTML = '<div class="qa-note text-danger">Could not load answers.</div>';
            });
    }

    function loadImageData(url) {
        return fetch(url)
            .then(function(response){
                if (!response.ok) throw new Error('Could not load the Hayleys logo.');
                return response.blob();
            })
            .then(function(blob){
                return new Promise(function(resolve, reject){
                    const reader = new FileReader();
                    reader.onload = function(){ resolve(reader.result); };
                    reader.onerror = function(){ reject(new Error('Could not read the Hayleys logo.')); };
                    reader.readAsDataURL(blob);
                });
            });
    }

    reportButton.addEventListener('click', async function(){
        if (!activeRecord || !activeAnswers || !window.jspdf) return;
        const originalText = reportButton.innerHTML;
        reportButton.disabled = true;
        reportButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Preparing PDF...';
        try {
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
            const logoData = await loadImageData('assets/images/hayleys_logo.png');
            const pageWidth = 210;
            const pageHeight = 297;
            const margin = 16;
            let y = 56;

            function addPageHeader(){
                pdf.addImage(logoData, 'PNG', margin, 9, 42, 21);
                pdf.setTextColor(23, 33, 26);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(16);
                pdf.text('TOOL INSPECTION REPORT', 66, 17);
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(9);
                pdf.setTextColor(102, 112, 133);
                pdf.text('QHSE | Hayleys Fentons', 66, 24);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(14);
                pdf.setTextColor(124, 45, 18);
                pdf.text(activeRecord.tool_type || 'Tool Inspection', 66, 39, { maxWidth: pageWidth - 82 });
                pdf.setDrawColor(234, 88, 12);
                pdf.setLineWidth(0.7);
                pdf.line(margin, 47, pageWidth - margin, 47);
            }

            function ensureSpace(height){
                if (y + height > pageHeight - 16) {
                    pdf.addPage();
                    addPageHeader();
                    y = 56;
                }
            }

            function addSectionTitle(title){
                ensureSpace(12);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(11);
                pdf.setTextColor(124, 45, 18);
                pdf.text(title, margin, y);
                y += 7;
            }

            function addDetail(label, value){
                const lines = pdf.splitTextToSize(label + ': ' + (value || '-'), pageWidth - margin * 2);
                const height = Math.max(5, lines.length * 4.5);
                ensureSpace(height + 1);
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(9);
                pdf.setTextColor(52, 64, 84);
                pdf.text(lines, margin, y);
                y += height + 1;
            }

            addPageHeader();
            addSectionTitle('Inspection Details');
            [
                ['Subcontractor', activeRecord.company_name],
                ['Owner / Contact', activeRecord.owner_name],
                ['Mobile Number', activeRecord.mobile_number],
                ['Division', activeRecord.division],
                ['Project / Location', activeRecord.project_location],
                ['Inspection Date', activeRecord.inspection_date],
                ['Inspected By', activeRecord.supervisor_name],
                ['Recorded At', activeRecord.created_at]
            ].forEach(function(field){ addDetail(field[0], field[1]); });

            addSectionTitle('Checklist Questions and Answers');
            activeAnswers.forEach(function(answer){
                const questionLines = pdf.splitTextToSize(answer.no + '. ' + answer.en, 145);
                const rowHeight = Math.max(8, questionLines.length * 4.5 + 2);
                ensureSpace(rowHeight);
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(9);
                pdf.setTextColor(52, 64, 84);
                pdf.text(questionLines, margin, y);
                pdf.setFont('helvetica', 'bold');
                pdf.setTextColor(answer.ans === 'Yes' ? 22 : (answer.ans === 'No' ? 153 : 102), answer.ans === 'Yes' ? 101 : (answer.ans === 'No' ? 27 : 112), answer.ans === 'Yes' ? 52 : (answer.ans === 'No' ? 27 : 133));
                pdf.text(answer.ans || '-', pageWidth - margin, y, { align: 'right' });
                y += rowHeight;
                pdf.setDrawColor(229, 231, 235);
                pdf.setLineWidth(0.2);
                pdf.line(margin, y - 2, pageWidth - margin, y - 2);
            });

            addSectionTitle('Checklist Submitted By');
            [['Completed By', activeExtra.completed_by], ['EPF / NIC', activeExtra.epf_nic], ['Project / Workplace', activeExtra.workplace], ['Reference No', activeExtra.reference]]
                .forEach(function(field){ if (field[1]) addDetail(field[0], field[1]); });

            const filename = (activeRecord.tool_type || 'tool-inspection').toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-inspection-' + activeRecord.id + '.pdf';
            pdf.save(filename);
        } catch (error) {
            window.alert(error.message || 'Could not create the inspection report PDF.');
        } finally {
            reportButton.disabled = !activeAnswers;
            reportButton.innerHTML = originalText;
        }
    });

    const recSearch = document.getElementById("recSearch");
    if (recSearch) {
        recSearch.addEventListener("input", function(){
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll("#recTable tbody tr[data-search]").forEach(function(tr){
                tr.style.display = tr.getAttribute("data-search").includes(q) ? "" : "none";
            });
        });
    }

    // ---------- Checklist popup (iframe) ----------
    const modalEl = document.getElementById("checklistModal");
    const frame   = document.getElementById("checklistModalFrame");
    const loading = document.getElementById("checklistModalLoading");
    const titleEl = document.getElementById("checklistModalTitle");
    const bsModal = new bootstrap.Modal(modalEl);

    document.querySelectorAll(".checklist-link").forEach(function(link){
        link.addEventListener("click", function(e){
            e.preventDefault();
            if (!subDetails) { openSubModal(); return; }

            let src   = this.getAttribute("data-src");
            let title = this.getAttribute("data-title") || "Checklist";
            if (!src) return;

            const params = new URLSearchParams({
                sub_company:    subDetails.company,
                sub_person:     subDetails.person,
                sub_phone:      subDetails.phone,
                sub_division:   subDetails.division,
                sub_id:         subDetails.sub_id || "",
                sub_location:   subDetails.location,
                sub_date:       subDetails.date,
                sub_supervisor: subDetails.supervisor
            });
            src += (src.indexOf("?") === -1 ? "?" : "&") + params.toString();

            titleEl.innerHTML = '<i class="bi bi-clipboard2-check-fill"></i> ' + title + ' - ' + subDetails.company;
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
    });
});
</script>

</body>
</html>