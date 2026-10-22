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

// Fetch Current Supervisor Details from Database for Display / Profile Picture
$query = "SELECT name, email, phone, nic, division, username, profile_pic FROM supervisors WHERE id = ?";
$stmt_sup = $conn->prepare($query);

$supervisor = [
    'name' => $sup_name,
    'email' => 'supervisor@hayleys.com',
    'phone' => '+94 77 123 4567',
    'nic' => 'N/A',
    'division' => $sup_division,
    'username' => 'supervisor',
    'profile_pic' => ''
];

if ($stmt_sup) {
    $stmt_sup->bind_param("i", $supervisor_id);
    $stmt_sup->execute();
    $result_sup = $stmt_sup->get_result();
    if ($result_sup->num_rows > 0) {
        $supervisor = $result_sup->fetch_assoc();
    }
    $stmt_sup->close();
}

// ===========================
// PAGINATION SETUP
// ===========================
$limit = 10; // Rows per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Get total count of subcontractors
$countResult = $conn->query("SELECT COUNT(*) as cnt FROM subcontractors");
$totalSubcontractors = ($countResult) ? $countResult->fetch_assoc()['cnt'] : 0;
$totalPages = ceil($totalSubcontractors / $limit);

// Fetch paginated subcontractors
$stmt_subs = $conn->prepare("SELECT * FROM subcontractors ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt_subs->bind_param("ii", $limit, $offset);
$stmt_subs->execute();
$result_subs = $stmt_subs->get_result();
$rows = $result_subs ? $result_subs->fetch_all(MYSQLI_ASSOC) : [];
$stmt_subs->close();

// Summary counts (top stat cards - fetched from all records)
$cntApproved = $cntPending = $cntRejected = 0;
$summaryResult = $conn->query("SELECT status, COUNT(*) as cnt FROM subcontractors GROUP BY status");
if ($summaryResult) {
    while ($r = $summaryResult->fetch_assoc()) {
        $s = strtolower(trim($r['status'] ?? ''));
        if ($s === 'approved') $cntApproved = (int)$r['cnt'];
        elseif ($s === 'pending') $cntPending = (int)$r['cnt'];
        elseif ($s === 'rejected') $cntRejected = (int)$r['cnt'];
    }
}

// Determine Profile Picture URL
$display_pic = media_exists($supervisor['profile_pic'] ?? '')
                ? media_url($supervisor['profile_pic'])
                : 'assets/images/default-avatar.png';

// Status badge helper
function statusBadge($status) {
    $status = trim($status ?? '');
    if (strcasecmp($status, 'Approved') === 0) {
        return "<span class='status-badge status-approved'><i class='bi bi-check-circle-fill'></i> Approved</span>";
    } elseif (strcasecmp($status, 'Pending') === 0) {
        return "<span class='status-badge status-pending'><i class='bi bi-clock-fill'></i> Pending</span>";
    } elseif (strcasecmp($status, 'Rejected') === 0) {
        return "<span class='status-badge status-rejected'><i class='bi bi-x-circle-fill'></i> Rejected</span>";
    }
    return "<span class='status-badge status-other'><i class='bi bi-question-circle-fill'></i> " . htmlspecialchars($status ?: 'Unknown') . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registered Subcontractors | QHSE Pro</title>
    <meta name="description" content="QHSC System - Registered Subcontractors List.">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

    /* ---------- Stat cards ---------- */
    .stat-card{ background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:16px 18px; box-shadow:var(--card-shadow); display:flex; align-items:center; gap:14px; height:100%; transition:box-shadow .2s ease, transform .2s ease; }
    .stat-card:hover{ box-shadow:var(--card-shadow-hover); transform:translateY(-2px); }
    .stat-icon{ width:46px; height:46px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    .stat-icon.orange{ background:var(--accent-soft); color:var(--accent-dark); }
    .stat-icon.green{ background:var(--ok-soft); color:var(--ok); }
    .stat-icon.red{ background:var(--danger-soft); color:var(--danger); }
    .stat-value{ font-size:24px; font-weight:700; line-height:1.1; color:var(--text-main); }
    .stat-label{ font-size:12px; color:var(--text-muted); font-weight:500; margin-top:2px; }

    /* ---------- Toolbar & search ---------- */
    .toolbar-row{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin:20px 0 14px; }
    .result-count{ font-size:13px; color:var(--text-muted); }
    .result-count strong{ color:var(--text-main); font-weight:700; }
    .search-container{ background:#fff; border:1px solid var(--border); border-radius:12px; padding:6px 10px; box-shadow:var(--card-shadow); display:flex; align-items:center; gap:8px; width:100%; max-width:400px; transition:border-color .2s, box-shadow .2s; }
    .search-container:focus-within{ border-color:rgba(249,115,22,.55); box-shadow:0 0 0 4px rgba(249,115,22,.12); }
    .search-container .search-icon{ color:var(--text-muted); font-size:14px; padding-left:4px; }
    .search-input{ border:none; background:transparent; font-size:12.5px; font-family:'Poppins',sans-serif; color:var(--text-main); width:100%; padding:4px 0; outline:none !important; box-shadow:none !important; }
    .search-input::placeholder{ color:#94a3b8; }
    .btn-clear{ background:#f1f5f9; color:var(--text-muted); border-radius:8px; padding:6px 10px; border:1px solid var(--border); font-weight:600; font-size:12px; display:inline-flex; align-items:center; justify-content:center; transition:all .2s ease; white-space:nowrap; cursor:pointer; }
    .btn-clear:hover{ background:var(--border); color:var(--text-main); }

    /* ---------- Table card & Reduced row gap ---------- */
    .dashboard-card{ background:var(--card-bg); border-radius:18px; box-shadow:var(--card-shadow); border:1px solid rgba(226,232,240,.9); padding:18px; }
    .table-wrapper{ border-radius:12px; overflow:hidden; }
    .data-table{ border-collapse:separate; border-spacing:0; width:100%; margin-bottom:0; }
    .data-table thead th{ background:#0f172a !important; color:#fff !important; padding:10px 12px; text-transform:uppercase; font-size:11.5px; font-weight:600; border:none; letter-spacing:.05em; white-space:nowrap; }
    .data-table thead tr th:first-child{ border-top-left-radius:10px; border-bottom-left-radius:10px; }
    .data-table thead tr th:last-child{ border-top-right-radius:10px; border-bottom-right-radius:10px; }
    
    /* Reduced padding to minimize gap between rows */
    .data-table tbody td{ padding:7px 12px; font-size:13px; border-bottom:1px solid #eef1f4; vertical-align:middle; color:var(--text-main); }
    .data-table tbody tr{ transition:background .15s ease; }
    .data-table tbody tr:hover{ background:var(--accent-soft); }
    .data-table tbody tr:last-child td{ border-bottom:none; }

    .company-name{ font-weight:600; color:var(--text-main); font-size:13px; display:flex; align-items:center; gap:8px; }
    .company-name .c-ico{ width:26px; height:26px; border-radius:7px; background:var(--accent-soft); color:var(--accent-dark); display:inline-flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; }
    .vendor-code{ font-family:'JetBrains Mono',monospace; font-weight:600; color:var(--text-muted); font-size:12.5px; }

    .status-badge{ display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:20px; font-size:11.5px; font-weight:600; }
    .status-approved{ background:var(--ok-soft); color:var(--ok); border:1px solid rgba(21,128,61,.18); }
    .status-pending{ background:var(--warn-soft); color:var(--warn); border:1px solid rgba(194,65,12,.18); }
    .status-rejected{ background:var(--danger-soft); color:var(--danger); border:1px solid rgba(220,38,38,.18); }
    .status-other{ background:#f1f5f9; color:var(--text-muted); border:1px solid var(--border); }

    .btn-view-details{ padding:4px 10px; font-size:11.5px; font-weight:600; background:var(--accent-soft); color:var(--accent-dark); border:1px solid rgba(249,115,22,.3); border-radius:7px; transition:all .2s; }
    .btn-view-details:hover{ background:var(--primary-gradient); color:#fff; border-color:transparent; }

    /* ---------- Pagination Styles ---------- */
    .pagination .page-item .page-link { color: var(--text-main); border-color: var(--border); font-size: 13px; padding: 6px 12px; }
    .pagination .page-item.active .page-link { background: var(--primary-gradient); border-color: transparent; color: #fff; }
    .pagination .page-item.disabled .page-link { color: #cbd5e1; background: #f8fafc; }
    .pagination .page-link:hover { background: var(--accent-soft); color: var(--accent-dark); border-color: var(--border); }

    .empty-state{ padding:30px 12px; text-align:center; color:var(--text-muted); }
    .empty-state i{ font-size:26px; color:#cbd5e1; display:block; margin-bottom:4px; }

    /* ---------- Modal ---------- */
    .modal-backdrop.show{ background-color:rgba(15,23,42,.55) !important; opacity:1 !important; }
    .modal-content{ border-radius:18px; border:none; box-shadow:var(--card-shadow-hover); background:var(--bg-app); overflow:hidden; }
    .modal-header{ background:var(--primary-gradient); color:#fff; padding:16px 22px; border-bottom:none; }
    .modal-title{ font-family:'Poppins',sans-serif; font-size:16px; font-weight:700; }
    .info-card{ background:#fff; border-radius:14px; border:1px solid var(--border); padding:16px; height:100%; box-shadow:var(--card-shadow); }
    .info-card-title{ font-size:12.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--accent-dark); font-weight:700; border-bottom:2px solid #fff2eb; padding-bottom:8px; margin-bottom:12px; display:flex; align-items:center; gap:7px; }
    .info-item{ margin-bottom:10px; } .info-item:last-child{ margin-bottom:0; }
    .detail-label{ font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; color:var(--text-muted); font-weight:600; margin-bottom:1px; }
    .detail-value{ font-size:13.5px; font-weight:600; color:var(--text-main); word-break:break-word; }

    @media (min-width:768px){
        .page-layout-wrapper{ flex-direction:row; align-items:flex-start; }
        .main-content-area{ padding:22px; }
        .page-header h2{ font-size:23px; }
    }
    @media (max-width:768px){
        .search-container{ max-width:100%; }
        .toolbar-row{ flex-direction:column-reverse; align-items:stretch; }
        .stat-value{ font-size:20px; }
    }
    </style>
</head>
<body>

<div class="page-layout-wrapper">

<?php
if (file_exists("navbar.php")) {
    include "navbar.php";
}
?>

<div class="main-content-area">

<div class="container-fluid px-0" style="max-width: 1400px; margin: 0 auto;">

    <!-- PAGE HEADER -->
    <header class="page-header">
        <div>
            <h2>
                <span class="title-icon"><i class="bi bi-building-fill"></i></span>
                Registered Subcontractors
            </h2>
            <p class="page-sub">Review company profiles, owner details and approval status.</p>
        </div>

        <div class="page-title-meta">
            <div class="pill-badge">
                <i class="bi bi-calendar3" style="color: #fb923c;"></i> <span id="liveDate"></span>
            </div>
            <div class="pill-badge">
                <i class="bi bi-clock-history" style="color: #f97316;"></i> <span id="liveTime"></span>
            </div>
            <div class="user-chip">
                <?php if (!empty($display_pic) && $display_pic !== 'assets/images/default-avatar.png'): ?>
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

    <!-- SUMMARY CARDS -->
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon orange"><i class="bi bi-buildings-fill"></i></div>
                <div>
                    <div class="stat-value"><?= $totalSubcontractors; ?></div>
                    <div class="stat-label">Total Subcontractors</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon green"><i class="bi bi-patch-check-fill"></i></div>
                <div>
                    <div class="stat-value"><?= $cntApproved; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-value"><?= $cntPending; ?></div>
                    <div class="stat-label">Pending Approval</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon red"><i class="bi bi-x-octagon-fill"></i></div>
                <div>
                    <div class="stat-value"><?= $cntRejected; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
        </div>
    </div>

    <!-- TOOLBAR: COUNT + LIVE SEARCH -->
    <div class="toolbar-row">
        <div class="result-count">
            Showing <strong><?= count($rows); ?></strong> of <strong><?= $totalSubcontractors; ?></strong> subcontractors
        </div>
        <div class="search-container">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="liveSearchInput" class="search-input" placeholder="Search by vendor code, company, address, mobile..." autocomplete="off">
            <button type="button" id="clearSearchBtn" class="btn-clear" style="display: none;" title="Clear Search">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>

    <!-- TABLE CARD -->
    <div class="dashboard-card">
        <div class="table-responsive table-wrapper">
            <table class="table data-table align-middle" id="subcontractorsTable">
                <thead>
                    <tr>
                        <th style="width: 130px;">Vendor Code</th>
                        <th>Company Name</th>
                        <th>Address</th>
                        <th style="width: 140px;">Mobile Number</th>
                        <th style="width: 90px; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) > 0): foreach ($rows as $row): 
                        $vendorCode = !empty($row['vendor_code']) ? $row['vendor_code'] : '-';
                    ?>
                    <tr class="searchable-row" data-searchable="<?= htmlspecialchars(strtolower($row['id'] . ' ' . $vendorCode . ' ' . $row['company_name'] . ' ' . $row['company_address'] . ' ' . $row['mobile_number'] . ' ' . $row['owner_name'])); ?>">
                        <td>
                            <span class="vendor-code"><?= htmlspecialchars($vendorCode); ?></span>
                        </td>
                        <td>
                            <div class="company-name">
                                
                                <?= htmlspecialchars($row['company_name'] ?? 'N/A'); ?>
                            </div>
                        </td>
                        <td>
                            <span class="text-muted" style="font-size: 12.5px;"><?= htmlspecialchars($row['company_address'] ?? 'N/A'); ?></span>
                        </td>
                        <td>
                            <span style="font-weight: 500; font-size: 12.5px;"><?= htmlspecialchars($row['mobile_number'] ?? 'N/A'); ?></span>
                        </td>
                        <td style="text-align: center;">
                            <button type="button" class="btn btn-view-details" data-bs-toggle="modal" data-bs-target="#subModal<?= $row['id']; ?>">
                                <i class="bi bi-eye me-1"></i>View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>No records found.</div></td></tr>
                    <?php endif; ?>
                    <tr id="noRecordsRow" style="display: none;">
                        <td colspan="5"><div class="empty-state"><i class="bi bi-search"></i>No matching subcontractors found.</div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-4">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?= $page - 1; ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= ($page == $i) ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?= $i; ?>"><?= $i; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?= $page + 1; ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>

    </div>

    <!-- POPUP MODALS FOR SUBCONTRACTOR DETAILS -->
    <?php foreach ($rows as $row): $badge = statusBadge($row['status'] ?? ''); ?>
    <div class="modal fade" id="subModal<?= $row['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-building-fill me-2"></i><?= htmlspecialchars($row['company_name']); ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">

                        <!-- Company Profile & Registration -->
                        <div class="col-lg-6">
                            <div class="info-card">
                                <div class="info-card-title"><i class="bi bi-briefcase-fill"></i> Company Information</div>
                                <div class="info-item">
                                    <div class="detail-label">Company Address</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['company_address'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="row g-2 mt-1">
                                    <div class="col-7">
                                        <div class="detail-label">Business Reg Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['business_reg_number'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-5">
                                        <div class="detail-label">Reg Date</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['business_reg_date'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="row g-2 mt-1">
                                    <div class="col-6">
                                        <div class="detail-label">EPF Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['epf_number'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="detail-label">ETF Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['etf_number'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="row g-2 mt-1">
                                    <div class="col-6">
                                        <div class="detail-label">Division</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['division'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="detail-label">Scope</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['scope'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Owner Personal Details -->
                        <div class="col-lg-6">
                            <div class="info-card">
                                <div class="info-card-title"><i class="bi bi-person-badge-fill"></i> Owner Information</div>
                                <div class="info-item">
                                    <div class="detail-label">Owner Name</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['owner_name'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="info-item mt-1">
                                    <div class="detail-label">Owner Address</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['owner_address'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="row g-2 mt-1">
                                    <div class="col-6">
                                        <div class="detail-label">Owner ID Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['owner_id_number'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="detail-label">Mobile Number</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['mobile_number'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                                <div class="row g-2 mt-1">
                                    <div class="col-6">
                                        <div class="detail-label">Gender & DOB</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['owner_gender'] ?? 'N/A'); ?> (<?= htmlspecialchars($row['owner_dob'] ?? 'N/A'); ?>)</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="detail-label">Email</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['email'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Portal & Agreement Info -->
                        <div class="col-lg-6">
                            <div class="info-card">
                                <div class="info-card-title"><i class="bi bi-shield-lock-fill"></i> Portal & Agreement Status</div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="detail-label">Username</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['username'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="detail-label">Current Approval Status</div>
                                        <div class="detail-value mt-1"><?= $badge; ?></div>
                                    </div>
                                </div>
                                <div class="row g-2 mt-2">
                                    <div class="col-6">
                                        <div class="detail-label">Agreement Effective Date</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['agreement_effective_date'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="detail-label">Agreement Termination Date</div>
                                        <div class="detail-value"><?= htmlspecialchars($row['agreement_termination_date'] ?? 'N/A'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Resources, Workforce & Tools -->
                        <div class="col-lg-6">
                            <div class="info-card">
                                <div class="info-card-title"><i class="bi bi-tools"></i> Resources & Workforce</div>
                                <div class="info-item">
                                    <div class="detail-label">Machineries</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['machineries'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="info-item mt-1">
                                    <div class="detail-label">Tools</div>
                                    <div class="detail-value"><?= htmlspecialchars($row['tools'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="row g-2 mt-1">
                                    <div class="col-6">
                                        <div class="detail-label">Male Workers</div>
                                        <div class="detail-value text-success fw-bold"><?= htmlspecialchars($row['male_workers'] ?? '0'); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="detail-label">Female Workers</div>
                                        <div class="detail-value text-danger fw-bold"><?= htmlspecialchars($row['female_workers'] ?? '0'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer bg-white px-4 py-3">
                    <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

</div>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
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

$(document).ready(function() {
    const $searchInput = $('#liveSearchInput');
    const $clearBtn = $('#clearSearchBtn');
    const $rows = $('.searchable-row');
    const $noRecordsRow = $('#noRecordsRow');

    $searchInput.on('keyup input', function() {
        const query = $(this).val().toLowerCase().trim();

        $clearBtn.toggle(query.length > 0);

        let visibleCount = 0;
        $rows.each(function() {
            const rowText = String($(this).attr('data-searchable') || '');
            if (rowText.includes(query)) {
                $(this).show();
                visibleCount++;
            } else {
                $(this).hide();
            }
        });

        $noRecordsRow.toggle(visibleCount === 0 && $rows.length > 0);
    });

    $clearBtn.on('click', function() {
        $searchInput.val('').trigger('input').trigger('focus');
    });
});
</script>

</body>
</html>
<?php
$conn->close();
?>