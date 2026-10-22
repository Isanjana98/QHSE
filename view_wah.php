<?php
/* =====================================================
   VIEW WORK AT HEIGHT PERMIT
===================================================== */

session_start();

$is_admin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$is_supervisor = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;

if (!$is_admin && !$is_supervisor) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

if ($conn->connect_error) {
    die("Database Connection Failed : " . $conn->connect_error);
}

/* =========================================================
   SAFE PREPARE HELPER
   If a query fails to prepare (e.g. a column/table is missing
   because db_migrate_permit_workflow.php hasn't been run yet),
   this shows a clear message instead of a raw fatal error.
========================================================= */
function safePrepare($conn, $sql) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Database Error: " . $conn->error . "<br><br>This usually means a required table/column is missing. Please run <b>db_migrate_permit_workflow.php</b> once in your browser, then try again.");
    }
    return $stmt;
}

$permit_id = intval($_GET['id'] ?? 0);

if ($permit_id <= 0) {
    die("Invalid or missing permit ID.");
}

/* =========================================================
   HANDLE IMAGE UPLOAD (Division + Admin, only once Approved)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {

    $uploader_role = $is_admin ? 'admin' : ($is_supervisor ? 'supervisor' : '');

    if ($uploader_role === '') {
        header("Location: view_wah.php?id=" . $permit_id . "&upload=error");
        exit();
    }

    // Enforce the sequence: permit must be fully Approved, supervisor uploads
    // first, and only after that can the admin upload.
    $chk = safePrepare($conn, "SELECT status, supervisor_work_image, admin_work_image FROM work_at_height_permits WHERE id = ?");
    $chk->bind_param("i", $permit_id);
    $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    $chkStatus = strtolower(trim($chkRow['status'] ?? ''));
    $supDone = !empty($chkRow['supervisor_work_image']);
    $adminDone = !empty($chkRow['admin_work_image']);

    $allowed = false;
    if ($chkStatus === 'approved') {
        if ($uploader_role === 'supervisor' && !$supDone) {
            $allowed = true;
        } elseif ($uploader_role === 'admin' && $supDone && !$adminDone) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        header("Location: view_wah.php?id=" . $permit_id . "&upload=error");
        exit();
    }

    if (isset($_FILES['permit_images']) && is_array($_FILES['permit_images']['name'])) {

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        // Destination is chosen by s3_store_upload(): the private bucket
        // when S3 is enabled, otherwise the original uploads/ folder.

        $anyUploaded = false;
        $lastPath = null;

        foreach ($_FILES['permit_images']['name'] as $idx => $fileName) {
            if ($_FILES['permit_images']['error'][$idx] !== UPLOAD_ERR_OK) continue;

            $fileTmpPath = $_FILES['permit_images']['tmp_name'][$idx];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileExtension, $allowedExtensions)) continue;

            $dest_path = s3_store_upload($fileTmpPath, $fileName, 'permits', 'wah_' . $permit_id);

            if ($dest_path) {
                $ins = safePrepare($conn, "INSERT INTO permit_images (permit_type, permit_id, image_path, uploaded_by) VALUES ('Work at Height', ?, ?, ?)");
                $ins->bind_param("iss", $permit_id, $dest_path, $uploader_role);
                $ins->execute();
                $ins->close();
                $anyUploaded = true;
                $lastPath = $dest_path;
            }
        }

        if ($anyUploaded) {
            $col = ($uploader_role === 'admin') ? 'admin_work_image' : 'supervisor_work_image';
            $upd = safePrepare($conn, "UPDATE work_at_height_permits SET `$col` = ? WHERE id = ?");
            $upd->bind_param("si", $lastPath, $permit_id);
            $upd->execute();
            $upd->close();

            header("Location: view_wah.php?id=" . $permit_id . "&upload=success");
            exit();
        }
    }

    header("Location: view_wah.php?id=" . $permit_id . "&upload=error");
    exit();
}

/* =========================================================
   HANDLE MARK-AS-COMPLETE ACTION (Admin only, after both the
   supervisor and admin have uploaded work-completion photos.)
========================================================= */
if (isset($_GET['action']) && $_GET['action'] === 'complete') {
    if (!$is_admin) {
        die("Only an admin can mark this permit as complete.");
    }

    $chk = safePrepare($conn, "SELECT status, supervisor_work_image, admin_work_image FROM work_at_height_permits WHERE id = ?");
    $chk->bind_param("i", $permit_id);
    $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    $chkStatus = strtolower(trim($chkRow['status'] ?? ''));
    $ready = ($chkStatus === 'approved') && !empty($chkRow['supervisor_work_image']) && !empty($chkRow['admin_work_image']);

    if ($ready) {
        $sql = "UPDATE work_at_height_permits SET status='Completed' WHERE id = ?";
        $stmt = safePrepare($conn, $sql);
        $stmt->bind_param("i", $permit_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: view_wah.php?id=" . $permit_id);
    exit();
}

/* =========================================================
   HANDLE APPROVE ACTION (Admin — only allowed AFTER Third Party
   has already approved. This is the FINAL approval that starts
   the 12-hour permit validity window.)
========================================================= */
if (isset($_GET['action']) && $_GET['action'] === 'approve') {
    if (!$is_admin) {
        die("Only an admin can approve this permit.");
    }

    $chk = safePrepare($conn, "SELECT status FROM work_at_height_permits WHERE id = ?");
    $chk->bind_param("i", $permit_id);
    $chk->execute();
    $curStatus = strtolower(trim($chk->get_result()->fetch_assoc()['status'] ?? ''));
    $chk->close();

    if ($curStatus !== 'third party approved') {
        header("Location: view_wah.php?id=" . $permit_id . "&approve_error=not_ready");
        exit();
    }

    // 12-hour validity window starts the moment Admin gives final approval.
    // This duration is fixed and must not be altered anywhere else in the code.
    $sql = "UPDATE work_at_height_permits
            SET status='Approved', rejection_reason=NULL,
                approved_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 12 HOUR)
            WHERE id = ?";
    $stmt = safePrepare($conn, $sql);
    $stmt->bind_param("i", $permit_id);
    $stmt->execute();
    $stmt->close();

    header("Location: view_wah.php?id=" . $permit_id);
    exit();
}

/* =========================================================
   HANDLE REJECT ACTION (Admin — only allowed AFTER Third Party
   has already approved.)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject') {
    if (!$is_admin) {
        die("Only an admin can reject this permit.");
    }
    $reason = trim($_POST['reason'] ?? '');

    if ($reason === '') {
        header("Location: view_wah.php?id=" . $permit_id . "&reject_error=empty_reason");
        exit();
    }

    $chk = safePrepare($conn, "SELECT status FROM work_at_height_permits WHERE id = ?");
    $chk->bind_param("i", $permit_id);
    $chk->execute();
    $curStatus = strtolower(trim($chk->get_result()->fetch_assoc()['status'] ?? ''));
    $chk->close();

    if ($curStatus !== 'third party approved') {
        header("Location: view_wah.php?id=" . $permit_id . "&reject_error=not_ready");
        exit();
    }

    $sql = "UPDATE work_at_height_permits SET status='Rejected', rejection_reason=? WHERE id = ?";
    $stmt = safePrepare($conn, $sql);
    $stmt->bind_param("si", $reason, $permit_id);
    $stmt->execute();
    $stmt->close();

    header("Location: view_wah.php?id=" . $permit_id);
    exit();
}

/* =====================================================
   GET PERMIT DETAILS
===================================================== */
$stmt = safePrepare($conn, "
    SELECT w.*, s.company_name 
    FROM work_at_height_permits w 
    LEFT JOIN subcontractors s ON w.subcontractor_id = s.id 
    WHERE w.id = ?
");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
    die("Work At Height Permit not found.");
}

/* =====================================================
   AUTO-EXPIRE: If this permit was fully Approved and its
   fixed 12-hour validity window has passed, flip it to
   'Expired' automatically. This window is never editable.
===================================================== */
if (strtolower(trim($permit['status'] ?? '')) === 'approved'
    && !empty($permit['expires_at'])
    && strtotime($permit['expires_at']) <= time()) {

    $expStmt = safePrepare($conn, "UPDATE work_at_height_permits SET status='Expired' WHERE id = ?");
    $expStmt->bind_param("i", $permit_id);
    $expStmt->execute();
    $expStmt->close();
    $permit['status'] = 'Expired';
}

/* =====================================================
   PARSE WORKERS LIST & PRECAUTIONS
===================================================== */
$workers = [];
if (!empty($permit['workers_list'])) {
    $decoded_workers = json_decode($permit['workers_list'], true);
    if (is_array($decoded_workers)) {
        foreach ($decoded_workers as $dw) {
            $workers[] = [
                'worker_name' => $dw['name'] ?? $dw['worker_name'] ?? '',
                'worker_nic'  => $dw['nic'] ?? $dw['worker_nic'] ?? ''
            ];
        }
    }
}

$controlAnswers = [];
if (!empty($permit['precautions'])) {
    $decoded_precautions = json_decode($permit['precautions'], true);
    if (is_array($decoded_precautions)) {
        foreach ($decoded_precautions as $key => $val) {
            if (preg_match('/_(\d+)$/', $key, $matches)) {
                $itemNo = (int)$matches[1];
                $controlAnswers[$itemNo] = $val;
            } else {
                $controlAnswers[$key] = $val;
            }
        }
    }
}

$controlLabels = [
    1  => "Health evaluation of persons going at height is done.",
    2  => "Safe means of access / egress provided.",
    3  => "Edge protection provided wherever there is falling hazards.",
    4  => "Fall prevention equipment used to access / egress work platform.",
    5  => "Fall prevention equipment used are adequate and in good condition.",
    6  => "Anchorage / Lifeline installed and inspected.",
    7  => "Travel restraint system used to safeguard persons from falling hazards.",
    8  => "All persons subjected to falling hazards are equipped with Personal Fall Arrest System (PFAS).",
    9  => "Electrical power and/or other energy is isolated (if working near/on it).",
    10 => "Area under the working at height is barricaded.",
    11 => "All persons subjected to falling hazards are adequately trained to perform work at heights.",
    12 => "Fall Protection Plan & Risk Assessment conducted and communicated.",
    13 => "Emergency Response Procedure for WAH activities developed and communicated to workers.",
];

/* =====================================================
   GET IMAGES
===================================================== */
$division_images = [];
$admin_images = [];

$imgStmt = $conn->prepare("SELECT image_path, uploaded_by FROM permit_images WHERE permit_type = 'Work at Height' AND permit_id = ? ORDER BY id ASC");
if ($imgStmt) {
    $imgStmt->bind_param("i", $permit_id);
    $imgStmt->execute();
    $imgRes = $imgStmt->get_result();
    while ($imgRow = $imgRes->fetch_assoc()) {
        if (($imgRow['uploaded_by'] ?? '') === 'admin') {
            $admin_images[] = media_url($imgRow['image_path'] ?? '');
        } else {
            $division_images[] = media_url($imgRow['image_path'] ?? '');
        }
    }
    $imgStmt->close();
}

/* =========================================================
   STATUS CONFIGURATION
========================================================= */
$status = strtolower(trim($permit['status'] ?? 'pending'));

switch ($status) {
    case 'approved':
        $statusClass = 'bg-success text-white';
        $statusIcon = 'bi-check-circle-fill';
        break;
    case 'third party approved':
        $statusClass = 'bg-primary text-white';
        $statusIcon = 'bi-person-check-fill';
        break;
    case 'rejected':
        $statusClass = 'bg-danger text-white';
        $statusIcon = 'bi-x-circle-fill';
        break;
    case 'third party rejected':
        $statusClass = 'bg-danger text-white';
        $statusIcon = 'bi-x-octagon-fill';
        break;
    case 'resubmitted':
        $statusClass = 'bg-warning text-dark';
        $statusIcon = 'bi-arrow-repeat';
        break;
    case 'expired':
        $statusClass = 'bg-secondary text-white';
        $statusIcon = 'bi-clock-history';
        break;
    case 'completed':
        $statusClass = 'bg-info text-white';
        $statusIcon = 'bi-check2-all';
        break;
    default: // pending third party
        $statusClass = 'bg-warning text-dark';
        $statusIcon = 'bi-hourglass-split';
        break;
}

function showValue($value, $default = 'Not provided') {
    if ($value === null || trim((string)$value) === '') {
        return $default;
    }
    return htmlspecialchars($value);
}

// Extract Supervisor Name & Contact from concatenated supervisor_info string if necessary
$supervisor_name = $permit['supervisor_info'] ?? '';
$supervisor_contact = '';
if (preg_match('/^(.*?)\s*\((.*?)\)$/', $supervisor_name, $matches)) {
    $supervisor_name = $matches[1];
    $supervisor_contact = $matches[2];
}

$sup_photo_done = !empty($permit['supervisor_work_image']);
$admin_photo_done = !empty($permit['admin_work_image']);

// Photo uploads only open once the permit is FULLY approved (Third Party + Admin).
// Supervisor must upload first; Admin can only upload after that.
$can_upload_supervisor = ($status === 'approved') && $is_supervisor && !$sup_photo_done;
$can_upload_admin      = ($status === 'approved') && $is_admin && $sup_photo_done && !$admin_photo_done;
$can_upload = $can_upload_supervisor || $can_upload_admin;
$can_mark_complete = ($status === 'approved') && $is_admin && $sup_photo_done && $admin_photo_done;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Work At Height Permit Details | Hayleys Fentons QHSE</title>

<!-- Bootstrap 5.3.3 & Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">

<style>
:root {
    --brand-yellow: #FFC107;
    --brand-yellow-dark: #E0A800;
    --brand-yellow-light: #FFF9DB;
    --surface-bg: #F8FAFC;
    --card-bg: #FFFFFF;
    --text-main: #1E293B;
    --text-muted: #64748B;
    --border-color: #E2E8F0;
    --shadow-soft: 0 10px 25px -5px rgba(255, 193, 7, 0.08), 0 8px 10px -6px rgba(255, 193, 7, 0.08);
}

body {
    background: linear-gradient(135deg, #FFFDF5 0%, #FFF9DB 100%);
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    color: var(--text-main);
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
    padding: 15px 0;
}

h1, h3, h4, h5, h6 {
    font-family: 'Manrope', sans-serif;
    letter-spacing: -.01em;
}

.permit-main-header {
    font-family: 'Syne', sans-serif;
    font-weight: 800;
    color: var(--brand-yellow-dark) !important;
    letter-spacing: -.02em;
    text-transform: uppercase;
}

.container {
    max-width: 1300px !important;
    width: 100% !important;
}

.card {
    border-radius: 18px;
    border: 1px solid var(--border-color);
    background: var(--card-bg);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    box-shadow: var(--shadow-soft);
    padding: 20px !important;
}

h2.permit-main-header {
    position: relative;
    padding-bottom: 8px;
    margin-bottom: 4px;
    font-size: 20px;
}

h2.permit-main-header::after {
    content: "";
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 50px;
    height: 3px;
    border-radius: 3px;
    background: var(--brand-yellow-dark);
}

.section-title {
    background: linear-gradient(120deg, var(--brand-yellow-dark), var(--brand-yellow) 65%, #FFE066);
    color: #1E293B;
    padding: 8px 14px;
    border-radius: 8px;
    margin-top: 18px;
    margin-bottom: 10px;
    font-weight: 700;
    font-size: 13px;
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.2);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Input / Floating Control Styling */
.form-control {
    font-size: 11.5px !important;
}

.form-floating > .form-control {
    border-radius: 8px;
    height: calc(2.6rem + 2px);
    padding: 0.5rem 0.75rem;
    background: var(--surface-bg) !important;
    color: var(--text-main);
    font-weight: 600;
    font-size: 11.5px !important;
}

.form-floating > label {
    padding: 0.5rem 0.75rem;
    color: var(--text-muted);
    font-weight: 500;
    font-size: 11px;
}

.summary-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.summary-chip.chip-yes { background: #d1fae5; color: #065f46; }
.summary-chip.chip-no { background: #fee2e2; color: #991b1b; }
.summary-chip.chip-na { background: #f1f5f9; color: #475569; }

.table {
    border-radius: 8px;
    overflow: hidden;
    font-size: 11.5px;
    background: rgba(255, 255, 255, .6);
}

.table thead th {
    background: linear-gradient(120deg, var(--brand-yellow-dark), var(--brand-yellow)) !important;
    color: #1E293B !important;
    font-family: 'Manrope', sans-serif;
    font-weight: 700;
    font-size: 11px;
    border: none !important;
}

.status-header-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.image-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 10px;
}

.image-grid a {
    display: block;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    aspect-ratio: 1/1;
}

.image-grid img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.btn-custom-action {
    border-radius: 8px;
    font-family: 'Manrope', sans-serif;
    font-weight: 700;
    padding: 8px 20px;
    font-size: 13px;
}

.row {
    --bs-gutter-x: 0.5rem;
    --bs-gutter-y: 0.5rem;
}
</style>
</head>
<body>

<div class="container my-2">
<div class="card">

<div class="d-flex justify-content-between align-items-center mb-2">
    
    <span class="status-header-badge <?= $statusClass ?>">
        <i class="bi <?= $statusIcon ?>"></i> Status: <?= strtoupper(showValue($permit['status'], 'Pending')) ?>
    </span>
</div>

<h2 class="text-center permit-main-header fw-bold">
<i class="bi bi-shield-fill-exclamation"></i> Work At Height Permit Details
</h2>
<p class="text-center text-muted mb-2" style="font-size: 11.5px;">Hayleys Fentons QHSE Safety Management System</p>

<?php if ($status === 'third party rejected' && !empty($permit['third_party_reject_reason'])): ?>
    <div class="alert alert-danger rounded-3 mt-2 mb-1" style="font-size: 11.5px;">
        <i class="bi bi-x-octagon-fill me-1"></i> <strong>Third Party Rejection Reason:</strong> <?= showValue($permit['third_party_reject_reason']) ?>
    </div>
<?php endif; ?>

<?php if ($status === 'rejected' && !empty($permit['rejection_reason'])): ?>
    <div class="alert alert-danger rounded-3 mt-2 mb-1" style="font-size: 11.5px;">
        <i class="bi bi-x-octagon-fill me-1"></i> <strong>Admin Rejection Reason:</strong> <?= showValue($permit['rejection_reason']) ?>
    </div>
<?php endif; ?>

<?php if ($status === 'third party approved'): ?>
    <div class="alert alert-primary rounded-3 mt-2 mb-1" style="font-size: 11.5px;">
        <i class="bi bi-person-check-fill me-1"></i> Approved by Third Party. Waiting for final Admin approval.
    </div>
<?php endif; ?>

<?php if ($status === 'approved' && !empty($permit['expires_at'])): ?>
    <div class="alert alert-success rounded-3 mt-2 mb-1" style="font-size: 11.5px;">
        <i class="bi bi-clock-fill me-1"></i> This permit is valid for 12 hours from final approval and will expire on
        <strong><?= date('Y-m-d h:i A', strtotime($permit['expires_at'])) ?></strong>.
    </div>
<?php endif; ?>

<?php if ($status === 'expired'): ?>
    <div class="alert alert-secondary rounded-3 mt-2 mb-1" style="font-size: 11.5px;">
        <i class="bi bi-clock-history me-1"></i> This permit's 12-hour validity window has ended and it is now expired.
    </div>
<?php endif; ?>

<?php if ($status === 'completed'): ?>
    <div class="alert alert-info rounded-3 mt-2 mb-1" style="font-size: 11.5px;">
        <i class="bi bi-check2-all me-1"></i> Work completed. Photos verified by both Division and Admin.
    </div>
<?php endif; ?>

<h5 class="section-title">
<i class="bi bi-briefcase-fill"></i> Project & Subcontractor Details
</h5>

<div class="row g-2">
    <div class="col-md-4">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['project']) ?>" readonly>
            <label>Project Name</label>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['permit_number']) ?>" readonly>
            <label>Permit Number</label>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['company_name'] ?? 'N/A') ?>" readonly>
            <label>Subcontractor Company</label>
        </div>
    </div>
</div>

<div class="row mt-1 g-2">
    <div class="col-md-4">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['address']) ?>" readonly>
            <label>Company Address</label>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['division']) ?>" readonly>
            <label>Company Division</label>
        </div>
    </div>

    <div class="col-md-4">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['contact_number']) ?>" readonly>
            <label>Company Contact</label>
        </div>
    </div>
</div>

<h5 class="section-title">
<i class="bi bi-person-badge-fill"></i> Supervisor Details
</h5>

<div class="row g-2">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($supervisor_name) ?>" readonly>
            <label>Supervisor Name</label>
        </div>
    </div>

    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($supervisor_contact) ?>" readonly>
            <label>Supervisor Contact</label>
        </div>
    </div>
</div>

<h5 class="section-title">
<i class="bi bi-tools"></i> Work Details
</h5>

<div class="row g-2">
    <div class="col-md-12">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['work_location']) ?>" readonly>
            <label>Work Location</label>
        </div>
    </div>
</div>

<div class="mt-2 mb-2">
    <label class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11px;">Description Of Work</label>
    <textarea class="form-control" rows="2" style="background:var(--surface-bg) !important;" readonly><?= showValue($permit['description']) ?></textarea>
</div>

<h5 class="section-title">
<i class="bi bi-shield-check"></i> Safety Controls Checklist
</h5>

<div class="table-responsive mt-1">
    <table class="table table-bordered mb-1">
        <thead>
            <tr>
                <th width="85%">Control Item</th>
                <th width="15%" class="text-center">Answer</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($controlLabels as $num => $label): ?>
                <?php 
                    $ans = trim($controlAnswers[$num] ?? $controlAnswers["control_$num"] ?? '');
                    $chipClass = ($ans === 'Yes') ? 'chip-yes' : (($ans === 'No') ? 'chip-no' : 'chip-na');
                ?>
                <tr>
                    <td><b><?= $num ?>.</b> <?= htmlspecialchars($label) ?></td>
                    <td class="text-center">
                        <span class="summary-chip <?= $chipClass ?>"><?= showValue($ans, '-') ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($workers)): ?>
<h5 class="section-title">
<i class="bi bi-people-fill"></i> Authorized Workers List
</h5>

<div class="table-responsive mt-1">
    <table class="table table-bordered mb-1">
        <thead>
            <tr>
                <th width="10%">#</th>
                <th width="45%">Worker Name</th>
                <th width="45%">NIC Number</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($workers as $index => $w): ?>
            <tr>
                <td><?= $index + 1 ?></td>
                <td><?= showValue($w['worker_name']) ?></td>
                <td><?= showValue($w['worker_nic']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<h5 class="section-title">
<i class="bi bi-envelope-check-fill"></i> Third Party Approver Details
</h5>

<div class="row g-2">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['tp_approved_by']) ?>" readonly>
            <label>Approved Person Name</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['tp_designation']) ?>" readonly>
            <label>Designation</label>
        </div>
    </div>
</div>

<div class="row g-2 mt-1">
    <div class="col-md-8">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['third_party_email']) ?>" readonly>
            <label>Email Address</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-floating">
            <input type="text" class="form-control" value="<?= showValue($permit['tp_contact']) ?>" readonly>
            <label>Contact Number</label>
        </div>
    </div>
</div>

<!-- Uploaded Completion Photos Section -->
<?php if ($status === 'approved' || $status === 'completed'): ?>
<h5 class="section-title">
<i class="bi bi-images"></i> Work Completion Photos
</h5>

<?php if ($can_upload): ?>
<form action="view_wah.php?id=<?= $permit_id ?>" method="POST" enctype="multipart/form-data" class="p-3 border rounded-3 bg-light mb-3">
    <input type="hidden" name="action" value="upload_image">
    <label class="fw-bold mb-1" style="font-size: 11px;">Upload Work Photos (<?= $is_admin ? 'Admin' : 'Division' ?>):</label>
    <div class="input-group">
        <input type="file" name="permit_images[]" accept="image/*" multiple required class="form-control form-control-sm">
        <button type="submit" class="btn btn-sm btn-submit-custom" style="background:var(--brand-yellow-dark); color:#1E293B; font-weight:700;">
            <i class="bi bi-upload me-1"></i> Upload Photos
        </button>
    </div>
</form>
<?php elseif ($status === 'approved' && $is_admin && !$sup_photo_done): ?>
    <div class="alert alert-warning rounded-3" style="font-size: 11.5px;">
        <i class="bi bi-hourglass-split me-1"></i> Waiting for the Division/Supervisor to upload work photos first.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <h6 class="fw-bold text-muted mb-2"><i class="bi bi-building me-1"></i> Division Uploads</h6>
        <?php if (!empty($division_images)): ?>
            <div class="image-grid">
                <?php foreach ($division_images as $img): ?>
                    <a href="<?= htmlspecialchars($img) ?>" target="_blank"><img src="<?= htmlspecialchars($img) ?>" alt="Division Upload"></a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted italic" style="font-size: 11.5px;">No photos uploaded by division.</p>
        <?php endif; ?>
    </div>

    <div class="col-md-6">
        <h6 class="fw-bold text-muted mb-2"><i class="bi bi-shield-check me-1"></i> Admin Uploads</h6>
        <?php if (!empty($admin_images)): ?>
            <div class="image-grid">
                <?php foreach ($admin_images as $img): ?>
                    <a href="<?= htmlspecialchars($img) ?>" target="_blank"><img src="<?= htmlspecialchars($img) ?>" alt="Admin Upload"></a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted italic" style="font-size: 11.5px;">No photos uploaded by admin.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($can_mark_complete): ?>
<div class="text-center mt-3 mb-2">
    <a href="view_wah.php?id=<?= $permit_id ?>&action=complete" class="btn btn-info text-white btn-custom-action">
        <i class="bi bi-check2-all me-1"></i> Mark Work as Complete / Finish
    </a>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Admin Controls (only after Third Party has approved) -->
<?php if ($is_admin && $status === 'third party approved'): ?>
<div class="text-center mt-4 mb-2">
    <a href="view_wah.php?id=<?= $permit_id ?>&action=approve" class="btn btn-success btn-custom-action me-2">
        <i class="bi bi-check-circle-fill me-1"></i> Approve Permit
    </a>
    <button type="button" class="btn btn-danger btn-custom-action" onclick="confirmReject()">
        <i class="bi bi-x-circle-fill me-1"></i> Reject Permit
    </button>
</div>
<?php endif; ?>

<!-- Division Resend Option -->
<?php if ($is_supervisor && ($status === 'rejected' || $status === 'third party rejected')): ?>
<div class="text-center mt-4 mb-2">
    <a href="wah_permit.php?resubmit_id=<?= $permit_id ?>" class="btn btn-warning text-dark btn-custom-action">
        <i class="bi bi-arrow-repeat me-1"></i> Resubmit Permit
    </a>
</div>
<?php endif; ?>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmReject() {
    Swal.fire({
        title: 'Reject Permit',
        input: 'textarea',
        inputLabel: 'Please enter the reason for rejection:',
        inputPlaceholder: 'Type reason here...',
        showCancelButton: true,
        confirmButtonText: 'Submit Rejection',
        confirmButtonColor: '#dc2626',
        cancelButtonText: 'Cancel',
        preConfirm: (reason) => {
            if (!reason) { Swal.showValidationMessage('Rejection reason cannot be empty!'); }
            return reason;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'view_wah.php?id=<?= $permit_id ?>';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'reject';
            form.appendChild(actionInput);

            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'reason';
            reasonInput.value = result.value;
            form.appendChild(reasonInput);

            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>

</body>
</html>
<?php $conn->close(); ?>