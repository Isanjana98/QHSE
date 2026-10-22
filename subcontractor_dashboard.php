<?php
// =====================================================================
// subcontractor_dashboard.php
//  - Web browser  -> HTML dashboard (session login)
//  - Mobile app   -> JSON (Authorization: Bearer <token>, via api_helpers.php)
// =====================================================================

require_once __DIR__ . '/api_helpers.php';   // also loads db_config.php ($conn)

$bearer_token = api_bearer_token();

$is_ajax_or_mobile =
    $bearer_token !== ''
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_GET['format']) && $_GET['format'] === 'json');

if ($is_ajax_or_mobile) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    ini_set('display_errors', 0);
    error_reporting(0);
}

session_start();

if ($conn->connect_error) {
    if ($is_ajax_or_mobile) {
        echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
        exit();
    }
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// ---- Who is calling? ----
$sub_id = null;

if ($bearer_token !== '') {
    // Mobile app: validate token against api_tokens (exits with JSON error if invalid)
    $auth   = require_api_auth($conn, ['subcontractor']);
    $sub_id = $auth['user_id'];
} elseif (isset($_SESSION['subcontractor_id'])) {
    // Web browser session
    $sub_id = (int)$_SESSION['subcontractor_id'];
}

if (!$sub_id) {
    if ($is_ajax_or_mobile) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized access']);
        exit();
    }
    header("Location: index.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

// Subcontractor details fetch
$stmt = $conn->prepare("SELECT * FROM subcontractors WHERE id = ?");
if (!$stmt) {
    if ($is_ajax_or_mobile) {
        echo json_encode(['ok' => false, 'error' => 'Database query preparation failed']);
        exit();
    }
    die("Database query failed: " . $conn->error);
}
$stmt->bind_param("i", $sub_id);
$stmt->execute();
$result = $stmt->get_result();
$sub = $result->fetch_assoc();
$stmt->close();

$company_name_db = $sub['company_name'] ?? 'Subcontractor';

// Get selected filters from URL
$selected_limit = isset($_GET['limit']) ? $_GET['limit'] : '5';
$allowed_limits = ['5', '10', '25', '50', 'all'];
if (!in_array($selected_limit, $allowed_limits)) {
    $selected_limit = '5';
}

$selected_permit_type = isset($_GET['permit_type']) ? trim($_GET['permit_type']) : '';
$selected_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build dynamic query conditions
$where_sql = "WHERE subcontractor_id = ?";
$params = [$sub_id];
$types = "i";

if ($selected_permit_type !== '') {
    $where_sql .= " AND work_category = ?";
    $params[] = $selected_permit_type;
    $types .= "s";
}

if ($selected_status !== '') {
    $where_sql .= " AND status = ?";
    $params[] = $selected_status;
    $types .= "s";
}

if ($search_query !== '') {
    $where_sql .= " AND (permit_number LIKE ? OR cat_project LIKE ? OR supervisor_name LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

// Total Jobs / Permits Count (filtered)
$total_jobs_count = 0;
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM service_provider_permits " . $where_sql);
if ($count_stmt) {
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    $total_jobs_count = (int)($count_row['total'] ?? 0);
    $count_stmt->close();
}

// Status-wise Counts for Progress Bar
$statuses = [
    'Approved' => 0,
    'Completed' => 0,
    'Pending' => 0,
    'On Hold' => 0,
    'Rejected' => 0,
    'Expired' => 0
];

$status_stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM service_provider_permits WHERE subcontractor_id = ? GROUP BY status");
if ($status_stmt) {
    $status_stmt->bind_param("i", $sub_id);
    $status_stmt->execute();
    $status_res = $status_stmt->get_result();
    while ($row = $status_res->fetch_assoc()) {
        $db_status = trim($row['status']);
        if (strcasecmp($db_status, 'Hold') == 0) {
            $db_status = 'On Hold';
        }
        foreach ($statuses as $key => $val) {
            if (strcasecmp($key, $db_status) == 0) {
                $statuses[$key] = (int)$row['count'];
            }
        }
    }
    $status_stmt->close();
}

// Total Registered Workers Count
$total_workers = 0;
$workers_count_stmt = $conn->prepare("SELECT COUNT(w.id) as total_workers FROM service_provider_workers w JOIN service_provider_permits p ON w.permit_id = p.permit_id WHERE p.subcontractor_id = ?");
if ($workers_count_stmt) {
    $workers_count_stmt->bind_param("i", $sub_id);
    $workers_count_stmt->execute();
    $workers_count_result = $workers_count_stmt->get_result();
    $workers_count_row = $workers_count_result->fetch_assoc();
    $total_workers = (int)($workers_count_row['total_workers'] ?? 0);
    $workers_count_stmt->close();
}

// Pagination Settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);

if ($selected_limit === 'all') {
    $limit = $total_jobs_count > 0 ? $total_jobs_count : 1;
} else {
    $limit = (int)$selected_limit;
}

$total_pages = ($total_jobs_count > 0 && $selected_limit !== 'all') ? (int)ceil($total_jobs_count / $limit) : 1;
if ($page > $total_pages) { $page = $total_pages; }
$offset = ($selected_limit === 'all') ? 0 : ($page - 1) * $limit;
if ($offset < 0) { $offset = 0; }

// Permits Data fetch
$permits_result = false;
$permits_array = [];
$data_query = "SELECT * FROM service_provider_permits " . $where_sql . " ORDER BY permit_id DESC";
if ($selected_limit !== 'all') {
    $data_query .= " LIMIT ? OFFSET ?";
}

$approved_stmt2 = $conn->prepare($data_query);

if ($approved_stmt2) {
    if ($selected_limit !== 'all') {
        $params_data = $params;
        $params_data[] = $limit;
        $params_data[] = $offset;
        $types_data = $types . "ii";
        $approved_stmt2->bind_param($types_data, ...$params_data);
    } else {
        $approved_stmt2->bind_param($types, ...$params);
    }

    $approved_stmt2->execute();
    $permits_result = $approved_stmt2->get_result();

    while ($p_row = $permits_result->fetch_assoc()) {
        $permits_array[] = $p_row;
    }

    // Reset pointer for web view HTML rendering below
    $permits_result->data_seek(0);
    $approved_stmt2->close();
}

$conn->close();

// IF MOBILE APP REQUESTS JSON, RETURN JSON RESPONSE
if ($is_ajax_or_mobile) {
    echo json_encode([
        'ok' => true,
        'dashboard' => [
            'company_name' => $company_name_db,
            'business_reg_number' => $sub['business_reg_number'] ?? 'N/A',
            'total_permits' => $total_jobs_count,
            'approved' => $statuses['Approved'],
            'completed' => $statuses['Completed'],
            'pending' => $statuses['Pending'],
            'on_hold' => $statuses['On Hold'],
            'rejected' => $statuses['Rejected'],
            'expired' => $statuses['Expired'],
            'workers' => $total_workers,
            'page' => $page,
            'total_pages' => $total_pages,
            'limit' => $selected_limit,
            'permits_list' => $permits_array
        ]
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subcontractor Dashboard | QHSE EN</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
:root {
    --primary-orange: #e15e13;
    --primary-orange-dark: #bb4c14;
    --bg-app: #f4f6f9;
    --card-bg: #ffffff;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --card-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.05);
}

body {
    min-height: 100vh;
    font-family: 'Poppins', sans-serif;
    background-color: var(--bg-app);
    color: var(--text-main);
    margin: 0;
    display: flex;
    font-size: 12px;
    -webkit-font-smoothing: antialiased;
}

/* SIDEBAR STYLES */
.sidebar-outer-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin: 4px 0 20px 15px;
    position: sticky;
    top: 4px;
    height: calc(100vh - 24px);
    flex-shrink: 0;
    z-index: 1000;
}

.sidebar-logo-outside {
    width: 90px;
    height: 90px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: -8px;
    margin-bottom: -15px;
}

.sidebar-logo-outside img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.navbar-custom {
    background: linear-gradient(180deg, var(--primary-orange) 0%, var(--primary-orange-dark) 100%);
    border-radius: 35px;
    padding: 22px 10px;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12);
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #fff;
    width: 85px;
    flex: 1;
    position: static;
    transition: width 0.35s ease;
    overflow-x: hidden;
}

.navbar-custom:hover {
    width: 230px;
    padding: 22px 16px;
}

.navbar-inner-content {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
}

.mobile-dropdown-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    height: 100%;
}

.sidebar-menu {
    display: flex;
    flex-direction: column;
    gap: 14px;
    align-items: center;
    width: 100%;
    padding: 10px 0;
    margin: auto 0;
}

.sidebar-menu-item {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 14px;
    padding: 10px 12px;
    border-radius: 16px;
    color: #ffffff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    width: 100%;
    white-space: nowrap;
    transition: all 0.3s ease;
}

.sidebar-menu-item i {
    font-size: 18px;
    min-width: 24px;
    text-align: center;
}

.sidebar-menu-item span.nav-text,
.logout-btn span {
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease;
}

.navbar-custom:hover .sidebar-menu-item span.nav-text,
.navbar-custom:hover .logout-btn span {
    opacity: 1;
    visibility: visible;
}

.sidebar-menu-item:hover, .sidebar-menu-item.active {
    background: rgba(255, 255, 255, 0.28);
    transform: translateX(4px);
}

.right-section {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    border-top: 1px solid rgba(255, 255, 255, 0.18);
    padding-top: 16px;
}

.logout-btn {
    width: 100%;
    height: 44px;
    padding: 0 12px;
    border-radius: 14px;
    background: rgba(0, 0, 0, 0.2);
    color: #fff;
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    white-space: nowrap;
}

.logout-btn:hover {
    background: #ef4444;
    color: #fff;
}

.mobile-toggle-btn {
    display: none;
    background: transparent;
    border: none;
    color: #fff;
    font-size: 24px;
    cursor: pointer;
}

/* MAIN CONTENT */
.main-content-wrapper {
    flex: 1;
    padding: 15px;
    max-width: calc(100vw - 100px);
    overflow-y: auto;
}

.dashboard-header {
    padding: 5px 0;
    margin-bottom: 12px;
}

.header-title h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-main);
}

.pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 50px;
    font-size: 11px;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

/* STAT CARDS */
.stat-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 12px 15px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
    display: flex;
    align-items: center;
    gap: 12px;
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.stat-icon.jobs { background: rgba(16, 185, 129, 0.1); color: #10b981; }
.stat-icon.workers { background: rgba(2, 132, 199, 0.1); color: #0284c7; }

.stat-info h3 { font-size: 16px; font-weight: 700; margin-bottom: 0; }
.stat-info p { font-size: 10.5px; color: var(--text-muted); margin-bottom: 0; text-transform: uppercase; font-weight: 600; }

.content-box {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 15px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-bottom: 15px;
}

/* PERMIT STATUS PROGRESS BAR */
.permit-status-container {
    background: #ffffff;
    border-radius: 12px;
    padding: 12px 18px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-bottom: 12px;
}

.permit-progress-bar-wrapper {
    display: flex;
    height: 8px;
    width: 100%;
    background-color: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    gap: 2px;
    margin: 10px 0 12px 0;
}

.permit-progress-segment { height: 100%; }

.status-legend-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 6px 12px;
    font-size: 11px;
}

.status-legend-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.status-legend-left {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-main);
    font-weight: 500;
}

.status-dot { width: 8px; height: 8px; border-radius: 2px; }

.bg-dot-approved { background-color: #10b981; }
.bg-dot-completed { background-color: #0d9488; }
.bg-dot-pending { background-color: #f59e0b; }
.bg-dot-on-hold { background-color: #8b5cf6; }
.bg-dot-rejected { background-color: #e11d48; }
.bg-dot-expired { background-color: #64748b; }

/* PERMIT TYPE BADGES IN TABLE */
.permit-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #1e293b;
}
.permit-type-icon {
    font-size: 12px;
    color: #e15e13;
}

/* MODERN ROUNDED TABLE DESIGN */
.aw-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 4px;
    margin: 0;
}

.aw-table thead th {
    background: #0f172a;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    padding: 12px 14px;
    white-space: nowrap;
    border: none;
}

.aw-table thead th:first-child {
    border-radius: 10px 0 0 10px;
}

.aw-table thead th:last-child {
    border-radius: 0 10px 10px 0;
}

.aw-table tbody td {
    background: #fff;
    font-size: 12px;
    padding: 8px 12px;
    vertical-align: middle;
    border-top: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
}

.aw-table tbody td:first-child {
    border-left: 1px solid #e5e7eb;
    border-radius: 10px 0 0 10px;
}

.aw-table tbody td:last-child {
    border-right: 1px solid #e5e7eb;
    border-radius: 0 10px 10px 0;
}

.aw-table tbody tr:hover td {
    background: #fffaf5;
}

/* TABLE EXPIRE PROGRESS */
.table-progress-wrapper {
    width: 90px;
    height: 4px;
    background-color: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    margin: 2px 0;
}
.table-progress-bar {
    height: 100%;
    background-color: #f59e0b;
    border-radius: 4px;
}
.time-left-text {
    font-size: 10px;
    color: var(--text-muted);
}

@media (max-width: 992px) {
    body { flex-direction: column; }
    .sidebar-outer-wrap {
        flex-direction: row;
        width: calc(100% - 24px) !important;
        height: auto !important;
        margin: 12px;
        position: sticky;
        top: 12px;
    }
    .navbar-custom { width: 100%; height: auto; padding: 10px 16px; border-radius: 20px; }
    .navbar-inner-content { flex-direction: row; justify-content: space-between; align-items: center; }
    .mobile-toggle-btn { display: block; }
    .mobile-dropdown-wrapper {
        display: none;
        position: absolute;
        top: calc(100% + 10px);
        left: 0; right: 0;
        background: linear-gradient(180deg, var(--primary-orange) 0%, var(--primary-orange-dark) 100%);
        border-radius: 20px;
        padding: 15px;
        flex-direction: column;
        gap: 10px;
    }
    .mobile-dropdown-wrapper.show { display: flex; }
    .main-content-wrapper { max-width: 100%; padding: 10px; }
}
</style>
<link rel="stylesheet" href="subcontractor_navbar.css">
</head>
<body>

<?php include __DIR__ . '/subcontractor_navbar.php'; ?>

<!-- Main Content -->
<div class="main-content-wrapper">
    <div class="container-fluid p-0">

        <!-- HEADER -->
        <div class="dashboard-header d-flex align-items-center justify-content-between">
            <div class="header-title text-truncate">
                <h2 class="m-0 text-truncate"><span id="typedWelcome" class="text-warning"></span></h2>
                <p class="m-0 mt-1 text-muted" style="font-size: 11px;">BR Number: <span class="fw-bold text-dark"><?= htmlspecialchars($sub['business_reg_number'] ?? 'N/A'); ?></span></p>
            </div>
            <div class="pill-badge">
                <i class="bi bi-calendar3 text-warning"></i> <span id="liveDate"></span>
            </div>
        </div>

        <!-- PERMIT STATUS PROGRESS BAR -->
        <div class="permit-status-container">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="fw-bold m-0 text-dark" style="font-size: 12.5px;">Permit status</h5>
                <span class="text-muted fw-bold" style="font-size: 11px;"><?= $total_jobs_count; ?> total</span>
            </div>

            <div class="permit-progress-bar-wrapper">
                <?php foreach($statuses as $st_name => $st_count):
                    if($total_jobs_count > 0 && $st_count > 0):
                        $percentage = ($st_count / $total_jobs_count) * 100;
                        $bg_class = match($st_name) {
                            'Approved' => 'bg-dot-approved',
                            'Completed' => 'bg-dot-completed',
                            'Pending' => 'bg-dot-pending',
                            'On Hold' => 'bg-dot-on-hold',
                            'Rejected' => 'bg-dot-rejected',
                            'Expired' => 'bg-dot-expired',
                            default => 'bg-secondary'
                        };
                ?>
                    <div class="permit-progress-segment <?= $bg_class; ?>" style="width: <?= $percentage; ?>%;" title="<?= $st_name; ?>: <?= $st_count; ?>"></div>
                <?php endif; endforeach; ?>
            </div>

            <div class="status-legend-grid">
                <div class="status-legend-item"><div class="status-legend-left"><div class="status-dot bg-dot-approved"></div> Approved</div><span class="fw-bold"><?= $statuses['Approved']; ?></span></div>
                <div class="status-legend-item"><div class="status-legend-left"><div class="status-dot bg-dot-completed"></div> Completed</div><span class="fw-bold"><?= $statuses['Completed']; ?></span></div>
                <div class="status-legend-item"><div class="status-legend-left"><div class="status-dot bg-dot-pending"></div> Pending</div><span class="fw-bold"><?= $statuses['Pending']; ?></span></div>
                <div class="status-legend-item"><div class="status-legend-left"><div class="status-dot bg-dot-on-hold"></div> On hold</div><span class="fw-bold"><?= $statuses['On Hold']; ?></span></div>
                <div class="status-legend-item"><div class="status-legend-left"><div class="status-dot bg-dot-rejected"></div> Rejected</div><span class="fw-bold"><?= $statuses['Rejected']; ?></span></div>
                <div class="status-legend-item"><div class="status-legend-left"><div class="status-dot bg-dot-expired"></div> Expired</div><span class="fw-bold"><?= $statuses['Expired']; ?></span></div>
            </div>
        </div>

        <!-- STATS CARDS -->
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-icon jobs"><i class="bi bi-check-circle-fill"></i></div>
                    <div class="stat-info"><h3><?= $statuses['Approved']; ?></h3><p>Total Approved Jobs</p></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-icon workers"><i class="bi bi-people-fill"></i></div>
                    <div class="stat-info"><h3><?= $total_workers; ?></h3><p>Total Registered Workers</p></div>
                </div>
            </div>
        </div>

        <!-- PERMITS TABLE SECTION -->
        <div class="content-box mb-3">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
                <h5 class="fw-bold m-0 text-dark" style="font-size: 13px;"><i class="bi bi-list-task text-primary me-1"></i> Permits Overview</h5>

                <!-- FILTERS & SEARCH FORM -->
                <form method="GET" action="" class="d-flex flex-wrap align-items-center gap-2">

                    <!-- Show Limit Dropdown -->
                    <select name="limit" class="form-select form-select-sm w-auto rounded-pill px-3" onchange="this.form.submit()">
                        <option value="5" <?= ($selected_limit === '5') ? 'selected' : ''; ?>>Show 5</option>
                        <option value="10" <?= ($selected_limit === '10') ? 'selected' : ''; ?>>Show 10</option>
                        <option value="25" <?= ($selected_limit === '25') ? 'selected' : ''; ?>>Show 25</option>
                        <option value="50" <?= ($selected_limit === '50') ? 'selected' : ''; ?>>Show 50</option>
                        <option value="all" <?= ($selected_limit === 'all') ? 'selected' : ''; ?>>Show All</option>
                    </select>

                    <!-- Permit Types Dropdown (Restricted to General, Hot Work, Work at Height) -->
                    <select name="permit_type" class="form-select form-select-sm w-auto rounded-pill px-3" onchange="this.form.submit()">
                        <option value="">All Permit Types</option>
                        <option value="General" <?= ($selected_permit_type === 'General') ? 'selected' : ''; ?>>General</option>
                        <option value="Hot Work" <?= ($selected_permit_type === 'Hot Work') ? 'selected' : ''; ?>>Hot Work</option>
                        <option value="Work at Height" <?= ($selected_permit_type === 'Work at Height') ? 'selected' : ''; ?>>Work at Height</option>
                    </select>

                    <!-- Status Dropdown -->
                    <select name="status" class="form-select form-select-sm w-auto rounded-pill px-3" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="Approved" <?= ($selected_status === 'Approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="Completed" <?= ($selected_status === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="Pending" <?= ($selected_status === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="On Hold" <?= ($selected_status === 'On Hold') ? 'selected' : ''; ?>>On Hold</option>
                        <option value="Rejected" <?= ($selected_status === 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                        <option value="Expired" <?= ($selected_status === 'Expired') ? 'selected' : ''; ?>>Expired</option>
                    </select>

                    <!-- Search Input -->
                    <div class="input-group input-group-sm rounded-pill overflow-hidden border" style="width: 200px;">
                        <span class="input-group-text bg-white border-0 pe-1"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-0 ps-1 shadow-none" placeholder="Search..." value="<?= htmlspecialchars($search_query); ?>" onchange="this.form.submit()">
                    </div>

                    <?php if ($selected_permit_type !== '' || $selected_status !== '' || $search_query !== '' || $selected_limit !== '5'): ?>
                        <a href="subcontractor_dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="aw-table">
                    <thead>
                        <tr>
                            <th>PERMIT NUMBER</th>
                            <th>PERMIT TYPE</th>
                            <th>PROJECT</th>
                            <th>SUPERVISOR</th>
                            <th>STATUS</th>
                            <th>CREATED AT</th>
                            <th>ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($permits_result && $permits_result->num_rows > 0): ?>
                            <?php while($app_row = $permits_result->fetch_assoc()):
                                $status_val = $app_row['status'] ?? 'Pending';
                                $work_cat_raw = $app_row['work_category'] ?? 'N/A';
                                $work_cat_formatted = ucwords(str_replace('_', ' ', $work_cat_raw));

                                $created_at = $app_row['declaration_date'] ?? date('Y-m-d H:i:s');
                                $created_time = strtotime($created_at);
                                $expiry_time = $created_time + (7 * 24 * 60 * 60);
                                $current_time = time();
                                $total_duration = 7 * 24 * 60 * 60;
                                $time_remaining = $expiry_time - $current_time;

                                if ($time_remaining > 0) {
                                    $days_left = floor($time_remaining / (24 * 60 * 60));
                                    $hours_left = floor(($time_remaining % (24 * 60 * 60)) / (60 * 60));
                                    $time_text = ($days_left > 0 ? "{$days_left}d " : "") . "{$hours_left}h left";
                                    $progress_percent = max(0, min(100, ($time_remaining / $total_duration) * 100));
                                } else {
                                    $time_text = "Expired";
                                    $progress_percent = 0;
                                }

                                // Status Badge color styling
                                $badge_bg = match(strtolower($status_val)) {
                                    'approved' => 'bg-success',
                                    'completed' => 'bg-info text-dark',
                                    'pending' => 'bg-warning text-dark',
                                    'on hold', 'hold' => 'bg-secondary',
                                    'rejected' => 'bg-danger',
                                    default => 'bg-secondary'
                                };
                            ?>
                                <tr>
                                    <td class="fw-bold text-success"><?= htmlspecialchars($app_row['permit_number']); ?></td>
                                    <td>
                                        <div class="permit-type-badge">
                                            <i class="bi bi-file-earmark-text permit-type-icon"></i>
                                            <span><?= htmlspecialchars($work_cat_formatted); ?></span>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($app_row['cat_project'] ?? 'N/A'); ?></td>
                                    <td><?= htmlspecialchars($app_row['supervisor_name'] ?? 'N/A'); ?></td>
                                    <td><span class="badge <?= $badge_bg; ?>"><?= htmlspecialchars($status_val); ?></span></td>
                                    <td>
                                        <div style="font-size: 11px;"><?= htmlspecialchars($created_at); ?></div>
                                        <div class="table-progress-wrapper">
                                            <div class="table-progress-bar" style="width: <?= $progress_percent; ?>%;"></div>
                                        </div>
                                        <div class="time-left-text"><?= $time_text; ?></div>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 10.5px;">View</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No permits found matching your criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <?php if ($total_pages > 1 && $selected_limit !== 'all'): ?>
                <nav class="d-flex justify-content-between align-items-center mt-3 px-1">
                    <div class="text-muted" style="font-size: 11px;">
                        Page <strong><?= $page; ?></strong> of <strong><?= $total_pages; ?></strong>
                    </div>
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                            $paginationParams = ['limit' => $selected_limit];
                            if ($selected_permit_type !== '') $paginationParams['permit_type'] = $selected_permit_type;
                            if ($selected_status !== '') $paginationParams['status'] = $selected_status;
                            if ($search_query !== '') $paginationParams['search'] = $search_query;
                        ?>
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                            <?php $paginationParams['page'] = $page - 1; ?>
                            <a class="page-link" href="?<?= http_build_query($paginationParams); ?>">&laquo; Prev</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php $paginationParams['page'] = $i; ?>
                            <li class="page-item <?= ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?= http_build_query($paginationParams); ?>"><?= $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php $paginationParams['page'] = $page + 1; ?>
                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?= http_build_query($paginationParams); ?>">Next &raquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
function updateDate() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    if(document.getElementById('liveDate')) {
        document.getElementById('liveDate').innerText = now.toLocaleDateString('en-US', options);
    }
}
updateDate();

const welcomeText = "Welcome, " + "<?= addslashes(htmlspecialchars($sub['company_name'] ?? 'Subcontractor')); ?>";
let charIndex = 0;
const typingElement = document.getElementById("typedWelcome");

function typeText() {
    if (charIndex < welcomeText.length) {
        typingElement.innerHTML += welcomeText.charAt(charIndex);
        charIndex++;
        setTimeout(typeText, 70);
    }
}

window.addEventListener("DOMContentLoaded", () => {
    if (typingElement) {
        setTimeout(typeText, 300);
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('navbarToggleBtn');
    const dropdownWrapper = document.getElementById('navDropdown');
    const toggleIcon = document.getElementById('toggleIcon');

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            dropdownWrapper.classList.toggle('show');
            if (dropdownWrapper.classList.contains('show')) {
                toggleIcon.classList.remove('bi-list');
                toggleIcon.classList.add('bi-x-lg');
            } else {
                toggleIcon.classList.remove('bi-x-lg');
                toggleIcon.classList.add('bi-list');
            }
        });
    }
});
</script>
</body>
</html>