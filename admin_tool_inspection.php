<?php
session_start();

// Admin login check
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . "/db_config.php";

// ---------- Which optional columns exist? (ALTER TABLE run karala nathnam kuda page eka wada karanawa) ----------
function col_exists($conn, $table, $col) {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
    return $r && $r->num_rows > 0;
}
$has_rec_div = col_exists($conn, 'tool_inspection_records', 'supervisor_division');
$has_rec_sid = col_exists($conn, 'tool_inspection_records', 'supervisor_id');
$has_sup_div = col_exists($conn, 'supervisors', 'division');

$parts = [];
if ($has_rec_div)                  { $parts[] = "NULLIF(r.supervisor_division, '')"; }
if ($has_sup_div && $has_rec_sid)  { $parts[] = "NULLIF(s.division, '')"; }
$div_expr = $parts ? "COALESCE(" . implode(", ", $parts) . ", '')" : "''";
$join     = ($has_sup_div && $has_rec_sid) ? "LEFT JOIN supervisors s ON s.id = r.supervisor_id" : "";

// ---------- All inspection records (all divisions / all supervisors) ----------
$records = [];
$records_error = '';
$res = $conn->query("SELECT r.id, r.company_name, r.owner_name, r.mobile_number, r.division, r.project_location,
                            r.tool_type, r.inspection_date, r.supervisor_name, r.created_at,
                            $div_expr AS supervisor_division
                     FROM tool_inspection_records r
                     $join
                     ORDER BY r.inspection_date DESC, r.id DESC LIMIT 2000");
if ($res) {
    while ($r = $res->fetch_assoc()) { $records[] = $r; }
} else {
    $records_error = $conn->error;
}

// Filter dropdown values + summary numbers
$divisions = []; $tools = []; $companies = []; $today_cnt = 0; $today = date('Y-m-d');
foreach ($records as $r) {
    if (!empty($r['supervisor_division'])) { $divisions[$r['supervisor_division']] = true; }
    if (!empty($r['tool_type']))           { $tools[$r['tool_type']] = true; }
    if (!empty($r['company_name']))        { $companies[strtolower($r['company_name'])] = true; }
    if ($r['inspection_date'] === $today)  { $today_cnt++; }
}
ksort($divisions); ksort($tools);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Inspections | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --background:#F6F8F5; --border:#E4E9E2; --accent:#ea580c; --accent-soft:#fff7ed; --muted:#6b7280; }
        body { background:var(--background); font-family:'Inter',sans-serif; color:#0E1F14; margin:0; overflow-x:hidden; }
        .app-layout { display:flex; min-height:100vh; }

        /* ---- Sidebar (same as admin_dashboard.php) ---- */
        .sidebar-wrap { width:100px; flex-shrink:0; background:var(--background); display:flex; flex-direction:column; align-items:flex-start; justify-content:center; padding:20px 0 20px 18px; position:sticky; top:0; height:100vh; z-index:1000; transition:width .3s cubic-bezier(.4,0,.2,1); }
        .sidebar-wrap:hover { width:240px; }
        .sidebar-logo { height:60px; width:auto; object-fit:contain; margin-bottom:25px; }
        .vertical-nav { background:linear-gradient(180deg,#ea580c 0%,#b45309 50%,#15803d 100%); width:75px; height:calc(100vh - 120px); border-radius:40px; display:flex; flex-direction:column; align-items:center; justify-content:space-between; padding:25px 0; box-shadow:0 10px 25px rgba(0,0,0,.15); transition:width .3s cubic-bezier(.4,0,.2,1); overflow:hidden; white-space:nowrap; }
        .sidebar-wrap:hover .vertical-nav { width:210px; align-items:flex-start; padding-left:20px; padding-right:20px; }
        .nav-links-group { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:15px; width:100%; flex:1; }
        .sidebar-wrap:hover .nav-links-group { align-items:flex-start; }
        .nav-icon-link { width:50px; height:50px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; border-radius:18px; text-decoration:none; transition:all .25s ease; gap:15px; }
        .sidebar-wrap:hover .nav-icon-link { width:100%; justify-content:flex-start; padding-left:12px; border-radius:14px; }
        .nav-text { display:none; font-size:.95rem; font-weight:600; color:#fff; }
        .sidebar-wrap:hover .nav-text { display:inline-block; }
        .nav-icon-link:hover, .nav-icon-link.active { background:rgba(255,255,255,.35); color:#fff; box-shadow:0 4px 12px rgba(0,0,0,.1); }
        .logout-pill-btn { width:50px; height:50px; background:#a13d07; border-radius:25px; display:flex; align-items:center; justify-content:center; color:#fff; text-decoration:none; box-shadow:inset 0 2px 4px rgba(0,0,0,.2); transition:all .3s ease; gap:12px; }
        .sidebar-wrap:hover .logout-pill-btn { width:100%; border-radius:14px; justify-content:flex-start; padding-left:14px; }
        .logout-pill-btn:hover { background:#873105; color:#fff; }

        .main-content-wrap { flex:1; min-width:0; padding:16px 35px; box-sizing:border-box; }

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

        /* ---- Header ---- */
        .page-top { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px; box-shadow:0 2px 10px rgba(0,0,0,.04); }
        .page-top h4 { font-family:'Manrope',sans-serif; font-weight:800; font-size:1.3rem; margin:0; display:flex; align-items:center; gap:10px; }
        .page-top h4 .ti { width:34px; height:34px; border-radius:10px; background:linear-gradient(135deg,#f97316,#ea580c); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
        .page-top p { margin:2px 0 0; font-size:.8rem; color:var(--muted); font-weight:600; }

        /* ---- Stats ---- */
        .stats-bar { background:#fff; border:1.5px solid #e5e7eb; border-radius:14px; padding:12px 25px; display:flex; align-items:center; justify-content:space-around; flex-wrap:wrap; gap:15px; margin-bottom:14px; box-shadow:0 4px 15px rgba(0,0,0,.03); }
        .stat-item { display:flex; align-items:center; gap:14px; }
        .stat-icon-box { width:45px; height:45px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; }
        .stat-value { font-family:'Manrope',sans-serif; font-weight:800; font-size:1.25rem; line-height:1.2; display:block; }
        .stat-label { font-size:.72rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
        .stat-divider { width:1px; height:35px; background:#e5e7eb; }
        @media (max-width:992px){ .stat-divider{display:none;} }

        /* ---- Table card ---- */
        .table-card { background:#fff; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.04); padding:18px 24px; }
        .filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:12px; }
        .filters .f { display:flex; flex-direction:column; gap:3px; }
        .filters label { font-size:11px; font-weight:600; color:#4b5563; }
        .filters select, .filters input[type=search] { height:36px; border:1px solid #d1d5db; border-radius:8px; padding:0 10px; font-size:.82rem; background:#fff; color:#374151; outline:none; min-width:150px; }
        .filters select:focus { border-color:#f97316; box-shadow:0 0 0 3px rgba(249,115,22,.12); }
        .btn-reset { height:36px; border:1px solid #d1d5db; background:#fff; border-radius:8px; padding:0 14px; font-size:.8rem; font-weight:600; color:#374151; }
        .btn-reset:hover { border-color:#f97316; color:var(--accent); }

        #inspTable { border-collapse:separate !important; border-spacing:0 2px !important; width:100% !important; }
        #inspTable thead th { background:#0d1322 !important; color:#fff !important; font-weight:700; text-transform:uppercase; font-size:.72rem; letter-spacing:.5px; padding:8px 10px !important; border:none !important; white-space:nowrap; }
        #inspTable thead th:first-child { border-radius:8px 0 0 8px; }
        #inspTable thead th:last-child { border-radius:0 8px 8px 0; }
        #inspTable tbody td { padding:6px 10px !important; vertical-align:middle; font-size:.84rem; border-top:1px solid #e5e7eb !important; border-bottom:1px solid #e5e7eb !important; background:#fff !important; }
        #inspTable tbody td:first-child { border-left:1px solid #e5e7eb !important; border-radius:8px 0 0 8px; white-space:nowrap; }
        #inspTable tbody td:last-child { border-right:1px solid #e5e7eb !important; border-radius:0 8px 8px 0; }
        #inspTable tbody tr:hover td { background:var(--accent-soft) !important; }
        .div-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.73rem; font-weight:700; background:#e0f2fe; color:#0284c7; white-space:nowrap; }
        .tool-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.73rem; font-weight:700; background:var(--accent-soft); color:#c2410c; border:1px solid rgba(249,115,22,.2); white-space:nowrap; }
        .btn-view { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:6px; background:#fff; border:1px solid #e2e8f0; font-size:.76rem; font-weight:600; color:#0f172a; cursor:pointer; }
        .btn-view:hover { border-color:#f97316; color:var(--accent); }
        .dataTables_wrapper .dataTables_length select, .dataTables_wrapper .dataTables_filter input { border:1px solid #d1d5db !important; border-radius:8px !important; font-size:.82rem !important; }
        .dataTables_wrapper .dataTables_info { font-size:.82rem; color:var(--muted); }
        .dataTables_wrapper .page-item.active .page-link { background:var(--accent); border-color:var(--accent); }
        .dataTables_wrapper .page-link { color:#374151; font-size:.78rem; }

        /* ---- Modal ---- */
        .modal-backdrop.show { background:rgba(15,23,42,.55) !important; opacity:1 !important; }
        #recordModal .modal-content { border:none; border-radius:14px; overflow:hidden; }
        #recordModal .modal-header { background:linear-gradient(135deg,#f97316,#ea580c); color:#fff; border:none; padding:12px 18px; }
        #recordModal .btn-close { filter:invert(1) grayscale(100%) brightness(200%); opacity:.85; }
        .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
        .detail-grid .d-item small { display:block; font-size:11px; color:var(--muted); margin-bottom:1px; }
        .detail-grid .d-item b { font-size:13px; font-weight:600; word-break:break-word; }
        .qa-title { font-size:14px; font-weight:700; margin:16px 0 8px; padding-top:12px; border-top:1px dashed var(--border); display:flex; align-items:center; gap:6px; }
        .qa-list { display:flex; flex-direction:column; gap:6px; }
        .qa-row { display:flex; align-items:flex-start; gap:10px; background:#f8fafc; border:1px solid #eef1f4; border-radius:8px; padding:7px 10px; }
        .qa-no { flex:0 0 auto; width:22px; height:22px; border-radius:50%; background:var(--accent-soft); color:#c2410c; font-size:11px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
        .qa-q { flex:1; font-size:12.5px; font-weight:500; }
        .qa-q small { display:block; font-size:11px; color:var(--muted); font-weight:400; }
        .qa-ans { flex:0 0 auto; font-size:11.5px; font-weight:700; padding:3px 12px; border-radius:20px; }
        .qa-ans.yes { background:#dcfce7; color:#15803d; }
        .qa-ans.no { background:#fee2e2; color:#b91c1c; }
        .qa-ans.na { background:#f1f5f9; color:#64748b; }
        .qa-note { font-size:12px; color:var(--muted); padding:8px 0; }
        .qa-alert { background:#fef2f2; border-left:3px solid #dc2626; color:#991b1b; font-size:12px; font-weight:500; padding:7px 10px; border-radius:6px; margin-bottom:8px; }
        @media (max-width:575.98px){ .detail-grid{grid-template-columns:1fr;} .main-content-wrap{padding:12px;} }
    </style>
    <link rel="stylesheet" href="admin_sidebar.css">
</head>
<body>
<div class="app-layout">

    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- Main -->
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

        <div class="page-top">
            <div>
                <h4><span class="ti"><i class="bi bi-tools"></i></span> Tool Inspections</h4>
                <p>All tool, ladder and scaffold inspections submitted by the divisions</p>
            </div>
        </div>

        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-icon-box bg-warning bg-opacity-10 text-warning"><i class="bi bi-clipboard2-data-fill"></i></div>
                <div><span class="stat-value"><?= count($records); ?></span><span class="stat-label">Total Inspections</span></div>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <div class="stat-icon-box bg-success bg-opacity-10 text-success"><i class="bi bi-calendar-check-fill"></i></div>
                <div><span class="stat-value"><?= $today_cnt; ?></span><span class="stat-label">Today</span></div>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <div class="stat-icon-box bg-info bg-opacity-10 text-info"><i class="bi bi-diagram-3-fill"></i></div>
                <div><span class="stat-value"><?= count($divisions); ?></span><span class="stat-label">Divisions</span></div>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <div class="stat-icon-box bg-primary bg-opacity-10 text-primary"><i class="bi bi-building-fill-gear"></i></div>
                <div><span class="stat-value"><?= count($companies); ?></span><span class="stat-label">Subcontractors</span></div>
            </div>
        </div>

        <div class="table-card">
            <?php if ($records_error !== ''): ?>
                <div class="alert alert-danger py-2" style="font-size:13px;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Could not load records: <?= htmlspecialchars($records_error); ?><br>
                    <small>Table eka thiyenawada kiyala balanna (tool_inspection_records).</small>
                </div>
            <?php endif; ?>

            <div class="filters">
                <div class="f">
                    <label for="fDivision">Division</label>
                    <select id="fDivision">
                        <option value="">All Divisions</option>
                        <?php foreach (array_keys($divisions) as$d): ?>
                            <option value="<?= htmlspecialchars($d); ?>"><?= htmlspecialchars($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="f">
                    <label for="fTool">Tool</label>
                    <select id="fTool">
                        <option value="">All Tools</option>
                        <?php foreach (array_keys($tools) as$t): ?>
                            <option value="<?= htmlspecialchars($t); ?>"><?= htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn-reset" id="fReset"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </div>

            <div class="table-responsive">
                <table id="inspTable" class="table align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Division</th>
                            <th>Inspected By</th>
                            <th>Subcontractor</th>
                            <th>Tool</th>
                            <th>Location</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($records as $i =>$r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['inspection_date'] ?? ''); ?></td>
                            <td><?php if (!empty($r['supervisor_division'])): ?><span class="div-badge"><?= htmlspecialchars($r['supervisor_division']); ?></span><?php else: ?>-<?php endif; ?></td>
                            <td><?= htmlspecialchars($r['supervisor_name'] ?: '-'); ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($r['company_name']); ?></td>
                            <td><span class="tool-badge"><?= htmlspecialchars($r['tool_type']); ?></span></td>
                            <td><?= htmlspecialchars($r['project_location'] ?: '-'); ?></td>
                            <td class="text-center"><button type="button" class="btn-view" data-idx="<?= $i; ?>"><i class="bi bi-eye-fill"></i> View</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
const records = <?= json_encode($records, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

function escHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

$(document).ready(function () {
    var table = $('#inspTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [],
        columnDefs: [{ orderable: false, targets: 6 }],
        language: { emptyTable: "No inspection records yet", zeroRecords: "No matching records" }
    });

    // Division / Tool dropdown filters (exact match on the visible text)
    function exact(v){ return v ? '^\\s*' + $.fn.dataTable.util.escapeRegex(v) + '\\s*$' : ''; }$('#fDivision').on('change', function(){ table.column(1).search(exact(this.value), true, false).draw(); });
    $('#fTool').on('change', function(){ table.column(4).search(exact(this.value), true, false).draw(); });

    $('#fReset').on('click', function(){
        $('#fDivision, #fTool').val('');
        table.search('').columns().search('').draw();
    });

    // ---------- View modal ----------
    var recModal = new bootstrap.Modal(document.getElementById('recordModal'));
    var ansBox = document.getElementById('recordAnswers');
    var ansToken = 0;

    $('#inspTable tbody').on('click', '.btn-view', function () {
        var r = records[parseInt(this.getAttribute('data-idx'), 10)];
        if (!r) return;
        var fields = [
            ["Subcontractor", r.company_name],
            ["Owner / Contact", r.owner_name],
            ["Mobile Number", r.mobile_number],
            ["Subcontractor Division", r.division],
            ["Project / Location", r.project_location],
            ["Tool Inspected", r.tool_type],
            ["Inspection Date", r.inspection_date],
            ["Inspected By", r.supervisor_name],
            ["Supervisor Division", r.supervisor_division],
            ["Recorded At", r.created_at]
        ];
        document.getElementById('recordDetails').innerHTML = fields.map(function (f) {
            return '<div class="d-item"><small>' + escHtml(f[0]) + '</small><b>' + escHtml(f[1] || "-") + '</b></div>';
        }).join("");
        recModal.show();
        loadAnswers(r.id);
    });

    function loadAnswers(recordId) {
        var token = ++ansToken;
        ansBox.innerHTML = '<div class="qa-note"><span class="spinner-border spinner-border-sm me-2" style="color:#ea580c;"></span>Loading checklist answers...</div>';

        fetch('get_inspection_answers.php?id=' + encodeURIComponent(recordId), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (d) {
                if (token !== ansToken) return;
                if (!d.ok) { ansBox.innerHTML = '<div class="qa-note text-danger">Could not load answers: ' + escHtml(d.error || "") + '</div>'; return; }
                if (!d.linked) { ansBox.innerHTML = '<div class="qa-note"><i class="bi bi-info-circle"></i> No checklist answers saved for this record.</div>'; return; }

                var noCount = d.answers.filter(function (a) { return a.ans === "No"; }).length;
                var html = '<div class="qa-title"><i class="bi bi-ui-checks-grid"></i> Checklist Answers</div>';
                if (noCount > 0) {
                    html += '<div class="qa-alert"><i class="bi bi-exclamation-triangle-fill"></i> ' + noCount + ' item' + (noCount > 1 ? 's' : '') + ' answered "No" - tool should be tagged and taken out of service.</div>';
                }
                html += '<div class="qa-list">' + d.answers.map(function (a) {
                    var cls = a.ans === "Yes" ? "yes" : (a.ans === "No" ? "no" : "na");
                    return '<div class="qa-row"><span class="qa-no">' + a.no + '</span>' +
                        '<div class="qa-q">' + escHtml(a.en) + (a.si ? '<small>' + escHtml(a.si) + '</small>' : '') + '</div>' +
                        '<span class="qa-ans ' + cls + '">' + escHtml(a.ans || "-") + '</span></div>';
                }).join("") + '</div>';

                var e = d.extra || {}, meta = [];
                if (e.workplace) meta.push(["Project / Workplace", e.workplace]);
                if (e.reference) meta.push(["Reference No", e.reference]);
                meta.push(["Completed By", e.completed_by]);
                meta.push(["EPF / NIC", e.epf_nic]);
                html += '<div class="detail-grid mt-3">' + meta.map(function (f) {
                    return '<div class="d-item"><small>' + escHtml(f[0]) + '</small><b>' + escHtml(f[1] || "-") + '</b></div>';
                }).join("") + '</div>';

                ansBox.innerHTML = html;
            })
            .catch(function () {
                if (token !== ansToken) return;
                ansBox.innerHTML = '<div class="qa-note text-danger">Could not load answers.</div>';
            });
    }
});

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
</body>
</html>