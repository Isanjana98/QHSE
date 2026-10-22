<?php
session_start();

$is_admin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$is_supervisor = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;

if (!$is_admin && !$is_supervisor) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

// Get Supervisor Division from session
$sup_division = $_SESSION['division'] ?? $_SESSION['sup_division'] ?? '';
if(empty($sup_division)){
    die("Supervisor Division not found. Please login again.");
}

// Get supervisor details
$stmt = $conn->prepare("SELECT name, phone, division, division_address FROM supervisors WHERE division = ? LIMIT 1");
$stmt->bind_param("s", $sup_division);
$stmt->execute();
$supervisor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$supervisor){
    die("Supervisor details not found for this division.");
}

// Get Approved Subcontractors (vendor_code added for worker lookup)
$subcontractors = $conn->query("SELECT id, vendor_code, company_name, company_address, mobile_number, division FROM subcontractors WHERE status = 'Approved' ORDER BY company_name ASC");

// ==========================================
// AUTO-GENERATE PERMIT NUMBER  (HAY-YYYY-TYPE-NNNN)
//   TYPE = GEN (General) | HW (Hot Work) | WAH (Work At Height)
//   NNNN = running count, separate for each TYPE and each YEAR
// (The final number is always generated again on the server at submit time.)
// ==========================================
function getNextPermitNumber($conn, $year, $typeCode) {
    $prefix = "HAY-{$year}-{$typeCode}-";
    $next_seq = 1;

    $pn_stmt = $conn->prepare(
        "SELECT permit_number FROM service_provider_permits
         WHERE permit_number LIKE CONCAT(?, '%')
         ORDER BY CAST(SUBSTRING_INDEX(permit_number, '-', -1) AS UNSIGNED) DESC
         LIMIT 1"
    );
    if ($pn_stmt) {
        $pn_stmt->bind_param("s", $prefix);
        $pn_stmt->execute();
        $pn_res = $pn_stmt->get_result();
        if ($pn_row = $pn_res->fetch_assoc()) {
            if (preg_match('/(\d+)$/', $pn_row['permit_number'], $mnum)) {
                $next_seq = (int)$mnum[1] + 1;
            }
        }
        $pn_stmt->close();
    }
    return $prefix . str_pad($next_seq, 4, '0', STR_PAD_LEFT);
}

$permit_year = date('Y');
// Next number for every category (JS switches between these when the category changes)
$permit_numbers_by_category = [
    'normal'         => getNextPermitNumber($conn, $permit_year, 'GEN'),
    'hot_work'       => getNextPermitNumber($conn, $permit_year, 'HW'),
    'work_at_height' => getNextPermitNumber($conn, $permit_year, 'WAH'),
];

// ==========================================
// RESUBMIT EDIT LOGIC (EXISTING DATA FETCH)
// ==========================================
$edit_id = intval($_GET['edit_id'] ?? 0);
$existing_permit = null;
$existing_tasks = [];
$existing_checklist = [];
$existing_ppe = [];
$existing_workers = [];
$existing_hw_work_types = [];
$existing_hw_precautions = [];
$existing_wah_controls = [];

if ($edit_id > 0) {
    $p_stmt = $conn->prepare("SELECT * FROM service_provider_permits WHERE permit_id = ?");
    $p_stmt->bind_param("i", $edit_id);
    $p_stmt->execute();
    $existing_permit = $p_stmt->get_result()->fetch_assoc();
    $p_stmt->close();

    if ($existing_permit) {
        $t_stmt = $conn->prepare("SELECT task_description, hazard_control FROM service_provider_tasks WHERE permit_id = ?");
        $t_stmt->bind_param("i", $edit_id);
        $t_stmt->execute();
        $res_t = $t_stmt->get_result();
        while($r = $res_t->fetch_assoc()) { $existing_tasks[] = $r; }
        $t_stmt->close();

        $c_stmt = $conn->prepare("SELECT item_index, answer FROM service_provider_checklist WHERE permit_id = ?");
        $c_stmt->bind_param("i", $edit_id);
        $c_stmt->execute();
        $res_c = $c_stmt->get_result();
        while($r = $res_c->fetch_assoc()) { $existing_checklist[$r['item_index']] = $r['answer']; }
        $c_stmt->close();

        $p_stmt2 = $conn->prepare("SELECT ppe_item FROM service_provider_ppe WHERE permit_id = ?");
        $p_stmt2->bind_param("i", $edit_id);
        $p_stmt2->execute();
        $res_p = $p_stmt2->get_result();
        while($r = $res_p->fetch_assoc()) { $existing_ppe[] = $r['ppe_item']; }
        $p_stmt2->close();

        $w_stmt = $conn->prepare("SELECT worker_name, worker_nic FROM service_provider_workers WHERE permit_id = ?");
        $w_stmt->bind_param("i", $edit_id);
        $w_stmt->execute();
        $res_w = $w_stmt->get_result();
        while($r = $res_w->fetch_assoc()) { $existing_workers[] = $r; }
        $w_stmt->close();

        // Fetch Hot Work Specific saved data if table exists / data present
        $hw_stmt = $conn->prepare("SELECT work_type FROM service_provider_hot_work_types WHERE permit_id = ?");
        if($hw_stmt) {
            $hw_stmt->bind_param("i", $edit_id);
            $hw_stmt->execute();
            $res_hw = $hw_stmt->get_result();
            while($r = $res_hw->fetch_assoc()) { $existing_hw_work_types[] = $r['work_type']; }
            $hw_stmt->close();
        }

        $hwp_stmt = $conn->prepare("SELECT precaution_text FROM service_provider_hot_work_precautions WHERE permit_id = ?");
        if($hwp_stmt) {
            $hwp_stmt->bind_param("i", $edit_id);
            $hwp_stmt->execute();
            $res_hwp = $hwp_stmt->get_result();
            while($r = $res_hwp->fetch_assoc()) { $existing_hw_precautions[] = $r['precaution_text']; }
            $hwp_stmt->close();
        }

        // Fetch Work At Height Specific saved data (DB column is `status`, aliased as `answer`)
        $wah_stmt = $conn->prepare("SELECT control_index, status AS answer FROM service_provider_wah_controls WHERE permit_id = ?");
        if($wah_stmt) {
            $wah_stmt->bind_param("i", $edit_id);
            $wah_stmt->execute();
            $res_wah = $wah_stmt->get_result();
            while($r = $res_wah->fetch_assoc()) { $existing_wah_controls[$r['control_index']] = $r['answer']; }
            $wah_stmt->close();
        }
    }
}

// ==========================================
// EDIT GUARD: only editable until Admin approves
// ==========================================
$action_word = 'Submit';   // Submit (new) / Update (pending edit) / Resubmit (after rejection)
$action_past = 'Submitted';
if ($edit_id > 0) {
    if (!$existing_permit) {
        die("Permit not found.");
    }
    $edit_status = strtolower(trim($existing_permit['status'] ?? ''));
    $editable_statuses = ['pending', 'pending third party', 'third party approved', 'rejected', 'third party rejected'];
    if (!in_array($edit_status, $editable_statuses, true)) {
        die("This permit can no longer be edited (current status: " . htmlspecialchars($existing_permit['status']) . ").");
    }
    if (strpos($edit_status, 'rejected') !== false) {
        $action_word = 'Resubmit';
        $action_past = 'Resubmitted';
    } else {
        $action_word = 'Update';
        $action_past = 'Updated';
    }
}

// DB column is `work_category`
$existing_category = $existing_permit['work_category'] ?? '';
$original_permit_number = $existing_permit['permit_number'] ?? '';
$initial_category = !empty($existing_category) ? $existing_category : 'normal';
$display_permit_number = !empty($original_permit_number)
    ? $original_permit_number
    : ($permit_numbers_by_category[$initial_category] ?? $permit_numbers_by_category['normal']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title id="pageTitle">General Permit</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">

<style>
:root {
    --brand-blue: #fe7f08e2;          
    --brand-blue-dark: #fe7f08e2;     
    --brand-blue-light: #FFF4EC;    
    --surface-bg: #F8FAFC;
    --card-bg: #FFFFFF;
    --text-main: #1E293B;
    --text-muted: #64748B;
    --border-color: #E2E8F0;
    --input-bg: #F8FAFC;
    --shadow-soft: 0 10px 25px -5px rgba(255, 122, 0, 0.08), 0 8px 10px -6px rgba(255, 122, 0, 0.08);
    --hw-color: #E11D48;
    --hw-color-dark: #BE123C;
    --hw-color-light: #FFF1F2;
    --wah-color: #D97706;
    --wah-color-dark: #B45309;
    --wah-color-light: #FFFBEB;
    --btn-green: #21a21e;
    --btn-green-dark: #157347;
}
.btn-submit-custom, .btn-sm {
    border-radius: 30px !important;
}
body{ background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%); font-family:'Inter',sans-serif; font-size:13px; color:var(--text-main); min-height: 100vh; padding: 15px 0; }
h1,h3,h4,h5,h6{ font-family:'Manrope',sans-serif; letter-spacing:-.01em; }
.permit-main-header { font-family: 'Syne', sans-serif; font-weight: 800; color: var(--brand-blue) !important; letter-spacing: -.02em; text-transform: uppercase; }
.container{ max-width:1300px !important; width:100% !important; }
.card{ border-radius:18px; border:1px solid var(--border-color); background: var(--card-bg); box-shadow:var(--shadow-soft); padding: 20px !important; }
h2.permit-main-header{ position:relative; padding-bottom:8px; margin-bottom:4px; font-size: 20px; }
h2.permit-main-header::after{ content:""; position:absolute; bottom:0; left:0; width:100%; height:3px; border-radius:3px; background:var(--brand-blue); }
.section-title{ background:transparent; color:var(--brand-blue-dark); padding:8px 0 8px 0; border-bottom:1.5px solid var(--brand-blue); border-radius:0; margin-top:18px; margin-bottom:10px; font-weight:700; font-size:13px; display:flex; align-items:center; gap:8px; }
.section-title.hw{ color:var(--hw-color-dark); border-bottom-color:var(--hw-color); }
.section-title.wah{ color:var(--wah-color-dark); border-bottom-color:var(--wah-color); }
.form-control, .form-select{ border-radius:8px; border:1px solid var(--border-color); font-size:13px; background:var(--input-bg); padding: 7px 10px; }
.form-control:focus, .form-select:focus{ background:#fff; border-color:var(--brand-blue); box-shadow:0 0 0 3px rgba(255,122,0,.15); }
.form-control[readonly], .form-control.bg-light{ background:var(--surface-bg) !important; color:var(--text-muted); }
.input-group-text{ background:var(--surface-bg); border:1px solid var(--border-color); border-radius:8px 0 0 8px !important; color:var(--text-muted); padding: 0 10px; font-size: 13px; }
.detail-labels-lg label{ font-size: 13.5px !important; }
.detail-labels-lg .form-floating > label{ font-size: 13.5px !important; }
.btn-submit-custom { background: linear-gradient(135deg, var(--btn-green) 0%, var(--btn-green-dark) 100%) !important; color: #FFFFFF !important; border: none; font-size: 13px; padding: 8px 20px; }
.table{ border-radius:8px; overflow:hidden; font-size:12px; background:rgba(255,255,255,.6); }
.table thead th{ background:linear-gradient(120deg,var(--brand-blue-dark),var(--brand-blue)) !important; color:white !important; font-size:11px; }
.table td, .table th{ padding:6px 8px; vertical-align:middle; border-color:var(--border-color); }
.form-check { background: rgba(255, 255, 255, 0.7); border: 1px solid var(--border-color); border-radius: 8px; padding: 6px 10px 6px 32px !important; margin-bottom: 6px; }
.form-check-input { width: 16px; height: 16px; margin-top: 0.15em; margin-left: -22px; border: 2px solid var(--border-color); }
.form-check-input:checked{ background-color:var(--brand-blue); border-color:var(--brand-blue); }

#summaryCard{ border-radius:16px; padding:20px; background: linear-gradient(135deg, #FFFFFF 0%, #FFF9F5 100%); border:2px solid rgba(254, 127, 8, 0.2); box-shadow: 0 15px 35px -5px rgba(254, 127, 8, 0.12); position: relative; overflow: hidden; }
#summaryCard::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 5px; background: linear-gradient(90deg, var(--brand-blue-dark), var(--brand-blue), #FFA733); }
.summary-row{ display:flex; align-items:flex-start; padding:10px 12px; border-bottom:1px dashed #E2E8F0; background: #FFFFFF; margin-bottom:6px; border-radius:8px; border-left:3px solid var(--brand-blue); transition: all 0.2s ease; }
.summary-row:hover { background: #FFF4EC; border-left-color: var(--brand-blue-dark); transform: translateX(3px); }
.summary-label{ width:160px; flex-shrink:0; font-weight:700; color:#1E293B; font-size:11.5px; text-transform: uppercase; }
.summary-value{ flex:1; color:#0F172A; font-weight:600; font-size:12px; }
.summary-value.is-empty{ color:#94A3B8; font-weight:400; font-style:italic; background: #F8FAFC; padding: 2px 10px; border-radius: 6px; display: inline-block; border: 1px dashed #E2E8F0; }

.summary-table-grid { display: inline-flex; gap: 6px; flex-wrap: wrap; align-items: center; background: #F8FAFC; padding: 4px 8px; border-radius: 6px; border: 1px solid #E2E8F0; }
.summary-chip{ display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:4px; font-size:10.5px; font-weight:700; }
.summary-chip.chip-yes{ background:#ECFDF5; color:#047857; border: 1px solid #A7F3D0; }
.summary-chip.chip-no{ background:#FFF1F2; color:#BE123C; border: 1px solid #FECDD3; }
.summary-chip.chip-na{ background:#F1F5F9; color:#475569; border: 1px solid #E2E8F0; }

.category-choice{
    display:flex;
    align-items:center;
    gap:10px;
    border:2px solid var(--border-color);
    border-radius:12px;
    padding:14px 16px;
    cursor:pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background:#fff;
    height:100%;
    position: relative;
    overflow: hidden;
}
.category-choice input[type="radio"]{ width:18px; height:18px; margin:0; }
.category-choice .cat-icon{ font-size:22px; }
.category-choice .cat-text{ font-weight:700; font-size:13.5px; }

.category-choice:active { transform: scale(0.97); }
.category-choice.hw:hover, .category-choice.hw.active{ border-color:var(--hw-color); background:var(--hw-color-light); box-shadow: 0 0 15px rgba(225, 29, 72, 0.25); }
.category-choice.hw .cat-icon{ color:var(--hw-color-dark); }
.category-choice.wah:hover, .category-choice.wah.active{ border-color:var(--wah-color); background:var(--wah-color-light); box-shadow: 0 0 15px rgba(217, 119, 6, 0.25); }
.category-choice.wah .cat-icon{ color:var(--wah-color-dark); }
.category-choice.normal:hover, .category-choice.normal.active{ border-color:var(--brand-blue); background:var(--brand-blue-light); box-shadow: 0 0 15px rgba(255, 122, 0, 0.25); }
.category-choice.normal .cat-icon{ color:var(--brand-blue-dark); }

/* Subcontractor searchable dropdown */
.company-search-wrap{ position:relative; }
.company-list{ display:none; position:absolute; top:100%; left:0; right:0; z-index:1050; background:#fff; border:1px solid var(--border-color); border-radius:8px; margin-top:2px; max-height:230px; overflow-y:auto; box-shadow:0 10px 25px -5px rgba(0,0,0,.15); }
.company-item{ padding:8px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid #F1F5F9; display:flex; justify-content:space-between; align-items:center; gap:8px; }
.company-item:last-child{ border-bottom:none; }
.company-item small{ color:var(--text-muted); font-size:10.5px; }
.company-item:hover, .company-item.hl{ background:var(--brand-blue-light); color:var(--brand-blue-dark); }
.company-item mark{ background:#FFE0C2; padding:0; color:inherit; font-weight:700; }
.company-empty{ padding:10px 12px; color:var(--text-muted); font-style:italic; font-size:12px; }
#subcontractor{ display:block; height:0; padding:0; border:0; margin:0; opacity:0; }

/* Approved worker picker */
.worker-picker{ background:#fff; border:1px solid var(--border-color); border-radius:10px; padding:10px 12px; }
.worker-picker .form-check{ margin-bottom:4px; }
.worker-picker .worker-sub{ color:var(--text-muted); font-size:10.5px; }
</style>
</head>

<body>

<div class="container my-2">
<div class="card">

<h2 class="text-center permit-main-header fw-bold" id="mainHeaderTitle">
<i class="bi bi-file-earmark-text-fill"></i> <span id="headerTitleText"><?= ($edit_id > 0) ? $action_word . ' General Permit' : 'General Permit'; ?></span>    
</h2>
<p class="text-center text-muted mb-2" style="font-size: 11.5px;">Hayleys Fentons QHSE Safety Management System</p>

<?php if ($edit_id > 0 && !empty($existing_permit['rejection_reason'])): ?>
    <div class="alert alert-danger mb-3">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Rejection Reason:</strong>
        <?= htmlspecialchars($existing_permit['rejection_reason']); ?>    
    </div>
<?php endif; ?>

<form id="serviceProviderForm">

<input type="hidden" name="resubmit_of" value="<?= $edit_id; ?>">    

<div class="row g-2 mb-2 justify-content-start">
    <div class="col-md-6 text-start">
        <label class="fw-bold mb-1" style="color:var(--text-muted); font-size:14px;">Permit Number</label>    
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>    
            <input type="text" name="permit_number" id="permit_number" class="form-control bg-light fw-bold"
                value="<?= htmlspecialchars($display_permit_number) ?>" readonly>    
        </div>
        <small class="text-muted" style="font-size:10.5px;">Automatically generated by the system (HAY-Year-Type-Number). This field cannot be edited.</small>    
    </div>
</div>

<h5 class="section-title"><i class="bi bi-briefcase-fill"></i> Subcontractor Details</h5>    

<div class="detail-labels-lg">
<div class="row g-2 align-items-center mt-1">
    <div class="col-md-6">
        <label class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Search Subcontractor</label>    
        <div class="company-search-wrap">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="companySearch" class="form-control" placeholder="Click or type to search company..." autocomplete="off" oninput="searchCompany()" onfocus="searchCompany()">
            </div>
            <div id="companyList" class="company-list"></div>
        </div>
        <select name="subcontractor_id" id="subcontractor" class="form-select" onchange="loadCompanyData()" required>    
            <option value="">-- Select Company --</option>    
            <?php 
            while($row = $subcontractors->fetch_assoc()){ 
                $selected = ($existing_permit && $existing_permit['subcontractor_id'] == $row['id']) ? 'selected' : '';
            ?>
            <option value="<?= $row['id']; ?>" <?= $selected ?>    
                data-name="<?= htmlspecialchars($row['company_name']); ?>"    
                data-vendor="<?= htmlspecialchars($row['vendor_code']); ?>"    
                data-address="<?= htmlspecialchars($row['company_address']); ?>"    
                data-contact="<?= htmlspecialchars($row['mobile_number']); ?>"    
                data-division="<?= htmlspecialchars($row['division']); ?>">    
                <?= htmlspecialchars($row['company_name']); ?>    
            </option>    
            <?php } ?>
        </select>
    </div>

    <div class="col-md-6">
        <label class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Company Name</label>
        <input type="text" id="company_name" name="company_name" class="form-control" placeholder="Select a subcontractor" readonly>
    </div>
</div>

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" id="company_address" name="company_address" class="form-control" placeholder="Company Address" readonly>    
            <label>Company Address</label>    
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-floating">
            <input type="text" id="company_division" name="company_division" class="form-control" placeholder="Division" readonly>    
            <label>Division</label>    
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-floating">
            <input type="text" id="company_contact" name="company_contact" class="form-control" placeholder="Contact Number" readonly>    
            <label>Contact Number</label>    
        </div>
    </div>
</div>

<h5 class="section-title"><i class="bi bi-person-badge-fill"></i> Supervisor Details</h5>    

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" name="supervisor_name" class="form-control" value="<?= htmlspecialchars($supervisor['name']); ?>" readonly>    
            <label>Supervisor Name</label>    
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" name="supervisor_contact" class="form-control" value="<?= htmlspecialchars($supervisor['phone']); ?>" readonly>    
            <label>Contact Number</label>    
        </div>
    </div>
</div>

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" name="division" class="form-control" value="<?= htmlspecialchars($supervisor['division']); ?>" readonly>    
            <label>Division</label>    
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" name="division_address" class="form-control" value="<?= htmlspecialchars($supervisor['division_address']); ?>" readonly>    
            <label>Division Address</label>    
        </div>
    </div>
</div>
</div>

<h5 class="section-title"><i class="bi bi-card-checklist"></i> Job Safety Induction Checklist</h5>    

<div class="table-responsive mt-1">
<table class="table table-bordered mb-1">
<thead>
<tr>
    <th width="5%">No</th>    
    <th width="75%">Items Covered</th>    
    <th width="6%" class="text-center">Yes</th>    
    <th width="6%" class="text-center">No</th>    
    <th width="8%" class="text-center">N/A</th>    
</tr>
</thead>
<tbody>
<?php
$items = [
    "Have you checked the competencies and qualifications of inductees?",
    "Have you discussed and clearly stated the procedures for reporting incidents,injuries and hazards?",
    "Have you discussed the company QHSE En Rules?",    
    "Is specialized equipment required and have they been trained to use the equipment?",        
    "Do people have the correct PPE available?",    
    "Have you shown the person what to do in an emergency and identified the location of the assembly point and evacuation route,closest medical facility,contact details of emergency services, and provisions for emergency communications?",    
    "Have you shown the person where all relevant firefighting equipment is located, for example fire extinguishers and hose reels?",    
    "Have you shown the person where all the facilities are located, including: toilets, and drinking water?",    
    "Have you explained workplace security procedures?"    
];

foreach($items as $k => $item){
    $saved_ans = $existing_checklist[$k] ?? '';
?>
<tr>
    <td><?= $k + 1 ?></td>    
    <td><?= htmlspecialchars($item) ?></td>    
    <td class="text-center"><input class="form-check-input" type="radio" name="check_<?= $k ?>" value="Yes" <?= ($saved_ans === 'Yes') ? 'checked' : '' ?> required style="margin-left:0 !important; float:none;"></td>    
    <td class="text-center"><input class="form-check-input" type="radio" name="check_<?= $k ?>" value="No" <?= ($saved_ans === 'No') ? 'checked' : '' ?> required style="margin-left:0 !important; float:none;"></td>    
    <td class="text-center"><input class="form-check-input" type="radio" name="check_<?= $k ?>" value="NA" <?= ($saved_ans === 'NA') ? 'checked' : '' ?> required style="margin-left:0 !important; float:none;"></td>    
</tr>
<?php } ?>
</tbody>
</table>
</div>

<h5 class="section-title"><i class="bi bi-patch-check-fill"></i> Supervisor Declaration</h5>    

<div class="alert alert-warning mt-2 mb-2 shadow-sm" style="font-size: 11.5px;">
    I confirm that I have verified the above information and ensured that necessary safety precautions have been taken.    
</div>

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" name="declaration_name_designation" class="form-control bg-light" value="<?= htmlspecialchars($supervisor['name']); ?> - Divisional Supervisor" readonly>    
            <label>Name & Designation</label>    
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="date" name="declaration_date" class="form-control bg-light" value="<?= date('Y-m-d') ?>" readonly>    
            <label>Date</label>    
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- WORKERS DECLARATION (approved workers of selected subcontractor) -->
<!-- ============================================================= -->
<h5 class="section-title"><i class="bi bi-people-fill"></i> Workers Declaration</h5>    

<div class="worker-picker mb-2">
    <div id="workerPickerMsg" class="text-muted" style="font-size:12px;">Select a subcontractor first to load approved workers.</div>
    <div id="workerPickerHead" style="display:none;" class="mb-2">
        <label class="fw-semibold" style="font-size:12px; cursor:pointer;">
            <input type="checkbox" id="selectAllWorkers" onchange="toggleAllWorkers(this.checked)"> Select All
        </label>
    </div>
    <div id="workerList" class="row g-1"></div>
</div>

<div class="table-responsive mt-1">
<table class="table table-bordered mb-1" id="workerTable">
<thead>
<tr>
    <th width="15%">Worker Code</th>    
    <th width="38%">Name</th>    
    <th width="37%">NIC Number</th>    
    <th width="10%" class="text-center">Action</th>    
</tr>
</thead>
<tbody id="workerBody"></tbody>
</table>
</div>

<!-- ============================================================= -->
<!-- PERMIT CATEGORY SELECTION -->
<!-- ============================================================= -->
<h5 class="section-title"><i class="bi bi-signpost-split-fill"></i> Permit Category Selection</h5>    

<div class="alert alert-info" style="font-size: 11.5px;">
    <i class="bi bi-info-circle-fill"></i> Please select whether this task requires a <strong>General Permit</strong>, <strong>Hot Work</strong> permit, or a <strong>Work At Height</strong> permit.
</div>

<div class="row g-2">
    <div class="col-md-4">
        <label class="category-choice normal" id="labelNormalWork">
            <input class="form-check-input" type="radio" name="permit_category" id="cat_normal" value="normal"
                onchange="togglePermitCategory()" <?= ($existing_category === 'normal' || empty($existing_category)) ? 'checked' : '' ?>>    
            <span class="cat-icon"><i class="bi bi-clipboard2-check"></i></span>    
            <span class="cat-text">General Permit</span>
        </label>
    </div>
    <div class="col-md-4">
        <label class="category-choice hw" id="labelHotWork">
            <input class="form-check-input" type="radio" name="permit_category" id="cat_hot_work" value="hot_work"
                onchange="togglePermitCategory()" <?= ($existing_category === 'hot_work') ? 'checked' : '' ?>>    
            <span class="cat-icon"><i class="bi bi-fire"></i></span>    
            <span class="cat-text">Hot Work</span>    
        </label>
    </div>
    <div class="col-md-4">
        <label class="category-choice wah" id="labelWorkAtHeight">
            <input class="form-check-input" type="radio" name="permit_category" id="cat_work_at_height" value="work_at_height"
                onchange="togglePermitCategory()" <?= ($existing_category === 'work_at_height') ? 'checked' : '' ?>>    
            <span class="cat-icon"><i class="bi bi-building-up"></i></span>    
            <span class="cat-text">Work At Height</span>    
        </label>
    </div>
</div>

<!-- ===================== GENERAL PERMIT SPECIFIC (TASKS) ===================== -->
<div id="generalPermitFields" style="display:none;">
    <h5 class="section-title"><i class="bi bi-tools"></i> Tasks & Hazard Controls</h5>    

    <div class="table-responsive mt-1">
    <table class="table table-bordered mb-1" id="taskTable">
    <thead>
    <tr>
        <th width="45%">Task</th>    
        <th width="45%">Hazard Control</th>    
        <th width="10%" class="text-center">Action</th>    
    </tr>
    </thead>
    <tbody id="taskBody">
    <?php if(!empty($existing_tasks)): ?>
        <?php foreach($existing_tasks as $t): ?>
        <tr>
            <td><input type="text" name="task[]" class="form-control" value="<?= htmlspecialchars($t['task_description']) ?>" required></td>    
            <td><input type="text" name="hazard[]" class="form-control" value="<?= htmlspecialchars($t['hazard_control']) ?>" required></td>    
            <td class="text-center"><button type="button" class="btn btn-danger btn-sm py-1 px-2" onclick="removeTask(this)"><i class="bi bi-trash"></i></button></td>    
        </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td><input type="text" name="task[]" class="form-control" placeholder="Enter Task" required></td>    
            <td><input type="text" name="hazard[]" class="form-control" placeholder="Enter Hazard Control" required></td>    
            <td class="text-center"><button type="button" class="btn btn-danger btn-sm py-1 px-2" onclick="removeTask(this)"><i class="bi bi-trash"></i></button></td>    
        </tr>
    <?php endif; ?>
    </tbody>
    </table>
    </div>

    <div class="text-center mt-1">
    <button type="button" class="btn btn-sm py-1 px-3" style="background:var(--btn-green); color:#fff;" onclick="addTask()">    
        <i class="bi bi-plus-circle"></i> Add Task    
    </button>
    </div>
</div>

<h5 class="section-title"><i class="bi bi-briefcase-fill"></i> Additional Work Details</h5>    

<div class="row g-2">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" name="cat_project" id="cat_project" class="form-control" placeholder="Project Name" value="<?= htmlspecialchars($existing_permit['cat_project'] ?? '') ?>">    
            <label for="cat_project">Project Name</label>    
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" name="cat_work_location" id="cat_work_location" class="form-control" placeholder="Work Location" value="<?= htmlspecialchars($existing_permit['cat_work_location'] ?? '') ?>">    
            <label for="cat_work_location">Work Location</label>    
        </div>
    </div>
</div>

<div class="mt-1">
    <label class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Description Of Work</label>    
    <textarea name="cat_description" id="cat_description" rows="2" class="form-control" placeholder="Provide detailed description of the work..."><?= htmlspecialchars($existing_permit['cat_description'] ?? '') ?></textarea>    
</div>

<div id="permitCategoryDetails" style="display:none;">

    <!-- ===================== HOT WORK SPECIFIC ===================== -->
    <div id="hotWorkFields" style="display:none;">

        <h5 class="section-title hw"><i class="bi bi-fire"></i> Hot Work Details</h5>    

        <div class="mt-1">
            <label class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Type Of Work</label>    
            <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
            <?php 
            $standard_types = ["Welding", "Cutting", "Grinding", "Brazing", "Torching"];
            $other_selected_val = "";
            $is_other_checked = false;
            foreach($existing_hw_work_types as $wt) {
                if(!in_array($wt, $standard_types)) {
                    $is_other_checked = true;
                    $other_selected_val = $wt;
                }
            }

            foreach($standard_types as $wType){ 
                $isChecked = in_array($wType, $existing_hw_work_types) ? 'checked' : '';
            ?>
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="hw_work_type[]" value="<?= $wType ?>" id="hw_wt_<?= $wType ?>" <?= $isChecked ?>>    
                <label class="form-check-label" for="hw_wt_<?= $wType ?>"><?= $wType ?></label>    
            </div>
            <?php } ?>

            <!-- Other Checkbox -->
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="hw_work_type[]" value="Other" id="hw_wt_Other" onchange="toggleOtherInput(this)" <?= $is_other_checked ? 'checked' : '' ?>>    
                <label class="form-check-label" for="hw_wt_Other">Other (Please Specify):</label>    
            </div>

            <!-- Other Text Input -->
            <div id="other_input_container" style="display: <?= $is_other_checked ? 'inline-block' : 'none' ?>;" class="ms-2">
                <input type="text" class="form-control form-control-sm" name="hw_work_type_other" placeholder="Please specify" value="<?= htmlspecialchars($other_selected_val) ?>">
            </div>
            </div>
        </div>

       <div class="alert alert-warning mt-2 mb-2 shadow-sm" style="font-size: 11.5px;">
            <b><i class="bi bi-exclamation-triangle-fill"></i> A Fire Watch should be posted if:</b>    
            <ul class="mb-0 ps-3">
                <li>Combustible (and flammable) materials within a 35 foot radius of the work cannot be removed or adequately protected.</li>    
                <li>Wall or floor openings within a 35 foot radius of hot work expose unprotected combustible materials in other areas, including concealed spaces in walls and floors.</li>    
                <li>Combustible materials are adjacent to the opposite side of partitions, walls, ceilings or roofs and are likely to be ignited.</li>    
                <li>Required by the client, consultant, Main contractor.</li>    
            </ul>
        </div>

        <div class="row g-2 mt-1">
            <div class="col-md-6">
                <div class="form-floating">
                    <input type="text" name="hw_fire_watch_name" class="form-control" placeholder="Fire Watch Name" value="<?= htmlspecialchars($existing_permit['hw_fire_watch_name'] ?? '') ?>">    
                    <label>Fire Watch Name</label>    
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-floating">
                    <input type="text" name="hw_fire_watch_contact" class="form-control" onkeypress="return isNumberKey(event)" placeholder="Numbers only" value="<?= htmlspecialchars($existing_permit['hw_fire_watch_contact'] ?? '') ?>">    
                    <label>Fire Watch Contact</label>    
                </div>
            </div>
        </div>

        <div class="mt-2">
            <p class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Following conditions/safety precautions should be observed.</p>
            <?php
            $hw_precautions_group1 = [
                "All floor and wall openings within 35 foot must be covered to prevent transmission of sparks.",
                "Smoke detectors must be isolated in the work area and procedures followed.",
                "Barricades, warning signs/spark/flash screens must be provided and utilised.",
                "All combustible materials located within 35 foot must be removed or made safe with non-combustible curtains, metal guards or flame proof covers.",
                "Work area, trenches, pits, etc. must be clear of flammable liquids, gases, or vapours.",
                "The person carrying out the hot work must be trained in using the Fire Extinguishers/Hose Reel and in raising/sounding the Alarm."
            ];
            foreach($hw_precautions_group1 as $i => $val){ ?>
            <div class="mb-1 ps-3" style="font-size: 12px;">
                • <?= htmlspecialchars($val) ?>
            </div>
            <?php } ?>

            <div class="mb-2 mt-2" style="font-size: 12px;">
                The hot work area and any adjoining areas must be patrolled from the start of work and a further minimum 60 minutes after the work has completed.
            </div>

            <p class="fw-semibold mb-1 mt-2" style="color:var(--text-muted); font-size:11.5px;">Permit shall not be issued until the following precautions have been checked.</p>
            <?php
            $hw_precautions_group2 = [
                "Means other than Hot Work or moving to a safer location have been considered.",
                "All walls, ceilings, or floors being worked on are of non-combustible construction (including internally).",
                "Hot work equipment in good repair.",
                "All floor and wall openings within 35 foot of the operation have been tightly covered.",
                "Sprinklers, where provided, are operational and will not be taken out of service while this work is being done.",
                "Ample portable fire extinguishing equipment has been provided.",
                "All combustibles have been relocated 35 foot (further for elevated work) from the operation including areas on opposite sides of walls if heat can be transferred to them through the work piece, any which cannot be",
                "A fire watch has been assigned to watch for fires or the potential for fires in the work area, on floors above and below, and on the opposite side of walls. This watch shall continue during any lunch or rest period and for at least 60 minutes after the work has been completed.",
                "The work will be confined to the area or equipment specified on this permit.",
                "Surrounding floors have been swept clean, and if combustible, wet down where possible.",
                "No combustible fibres, dusts, vapours, gases, or liquids in the area. Tanks and equipment previously containing such have been purged. Where normally present, the absence of gases or vapours has been verified by a combustible gas detection instrument. If there is a possibility of a leak developing in nearby piping, equipment, or tanks, area is continuously monitored."
            ];
            foreach($hw_precautions_group2 as $i => $val){ 
                $pn = count($hw_precautions_group1) + $i + 1; 
                $is_prec_checked = in_array($val, $existing_hw_precautions) ? 'checked' : '';
            ?>
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="hw_precautions[]" value="<?= htmlspecialchars($val) ?>" id="hw_prec_<?= $pn ?>" <?= $is_prec_checked ?>>    
                <label class="form-check-label" for="hw_prec_<?= $pn ?>" style="font-size: 12px;"><?= htmlspecialchars($val) ?></label>    
            </div>
            <?php } ?>

            <div class="mb-2 mt-2" style="font-size: 12px;">
                The proposed works do not have the potential to become a nuisance to the property and the area has been inspected for the presence of any building air intake systems. In the event that the works can become a nuisance and/or there are building air intake systems present, a property impact minimisation plan has been agreed with the Client, Consultant, Main contractor, Manager - Compliance.
            </div>
        </div>
    </div>

    <!-- ===================== WORK AT HEIGHT SPECIFIC ===================== -->
    <div id="wahFields" style="display:none;">

        <h5 class="section-title wah"><i class="bi bi-building-up"></i> Work At Height Controls</h5>    

        <div class="table-responsive mt-1">
        <table class="table table-bordered table-hover mb-1">
        <thead>
        <tr>
            <th width="65%">WAH Control Implemented</th>    
            <th class="text-center">Yes</th>    
            <th class="text-center">No</th>    
            <th class="text-center">N/A</th>    
        </tr>
        </thead>
        <tbody>
        <?php
        $wah_controls = [
            "Health evaluation of persons going at height is done.",    
            "Safe means of access / egress provided.",    
            "Edge protection provided wherever there is falling hazards.",    
            "Fall prevention equipment used to access / egress work platform.",    
            "Fall prevention equipment used are adequate and in good condition.",    
            "Anchorage / Lifeline installed and inspected.",    
            "Travel restraint system used to safeguard persons from falling hazards.",    
            "All persons subjected to falling hazards are equipped with Personal Fall Arrest System .",    
            "Electrical power and/or other energy is isolated (if working near/on it).",    
            "Area under the working at height is barricaded.",    
            "All persons subjected to falling hazards are adequately trained to perform work at heights.",    
            "Fall Protection Plan & Risk Assessment conducted and communicated.",    
            "Emergency Response Procedure for WAH activities developed and communicated to workers."    
        ];
        foreach($wah_controls as $i => $control){ 
            $no = $i + 1; 
            $saved_wah_ans = $existing_wah_controls[$no] ?? '';
        ?>
        <tr>
            <td><b><?= $no ?>.</b> <?= htmlspecialchars($control) ?></td>    
            <td class="text-center"><input class="form-check-input" type="radio" name="wah_control_<?= $no ?>" value="Yes" <?= ($saved_wah_ans === 'Yes') ? 'checked' : '' ?>></td>    
            <td class="text-center"><input class="form-check-input" type="radio" name="wah_control_<?= $no ?>" value="No" <?= ($saved_wah_ans === 'No') ? 'checked' : '' ?>></td>    
            <td class="text-center"><input class="form-check-input" type="radio" name="wah_control_<?= $no ?>" value="N/A" <?= ($saved_wah_ans === 'N/A') ? 'checked' : '' ?>></td>    
        </tr>
        <?php } ?>
        </tbody>
        </table>
        </div>
    </div>

</div>

<!-- ============================================================= -->
<!-- PERSONAL PROTECTIVE EQUIPMENT (category based)                 -->
<!-- ============================================================= -->
<h5 class="section-title"><i class="bi bi-shield-fill-check"></i> Personal Protective Equipment (PPE)</h5>    

<!-- Common PPE (shown for every category) -->
<div class="row g-2 mt-1">
    <?php 
    $ppes = ["Hard Hat", "Goggles", "Safety Shoes", "High Visibility Vest"];
    foreach($ppes as $idx => $p_item):
        $is_checked = in_array($p_item, $existing_ppe) ? 'checked' : '';
    ?>
    <div class="col-md-3">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="ppe[]" value="<?= $p_item ?>" id="ppe_<?= $idx ?>" <?= $is_checked ?>>    
            <label class="form-check-label" for="ppe_<?= $idx ?>"><?= $p_item ?></label>    
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- HOT WORK extra PPE -->
<div id="hwPpeBox" style="display:none;">
    <?php
    $hw_ppe_groups = [
        "Welding Work"  => ["Welding Gloves", "Face Shield", "Fire Protective Clothes"],
        "Grinding Work" => ["Dotted Gloves"]
    ];
    foreach($hw_ppe_groups as $grpName => $grpItems):
    ?>
    <p class="fw-semibold mb-1 mt-2" style="color:var(--hw-color-dark); font-size:11.5px;">
        <i class="bi bi-fire"></i> Hot Work PPE – <?= $grpName ?>
        <?php if($grpName === "Grinding Work"): ?>
            <span class="text-muted fw-normal">(Goggles are already included in the standard PPE above)</span>
        <?php endif; ?>
    </p>
    <div class="row g-2">
        <?php foreach($grpItems as $gi => $p_item):
            $is_checked = in_array($p_item, $existing_ppe) ? 'checked' : '';
            $pid = 'hwppe_' . md5($p_item);
        ?>
        <div class="col-md-3">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="ppe[]" value="<?= $p_item ?>" id="<?= $pid ?>" <?= $is_checked ?>>
                <label class="form-check-label" for="<?= $pid ?>"><?= $p_item ?></label>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- WORK AT HEIGHT extra PPE -->
<div id="wahPpeBox" style="display:none;">
    <p class="fw-semibold mb-1 mt-2" style="color:var(--wah-color-dark); font-size:11.5px;">
        <i class="bi bi-building-up"></i> Work At Height PPE
    </p>
    <div class="row g-2">
        <?php $harness_checked = in_array("Full Body Harness", $existing_ppe) ? 'checked' : ''; ?>
        <div class="col-md-6">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="ppe[]" value="Full Body Harness" id="wahppe_harness" <?= $harness_checked ?>>
                <label class="form-check-label" for="wahppe_harness">
                    Full Body Harness <small class="text-muted">(working platform above 1.8m)</small>
                </label>
            </div>
        </div>
    </div>
</div>

<h5 class="section-title"><i class="bi bi-clipboard-data-fill"></i> Live Permit Summary</h5>    

<div class="card p-2" id="summaryCard">
<div class="row">
    <div class="col-md-6">
        <div class="summary-row"><div class="summary-label">Permit Number</div><div class="summary-value" id="sum_permit_number"><?= htmlspecialchars($display_permit_number) ?></div></div>
        <div class="summary-row"><div class="summary-label">Permit Category</div><div class="summary-value is-empty" id="sum_category">Not selected yet</div></div>    
        <div class="summary-row"><div class="summary-label">Subcontractor</div><div class="summary-value is-empty" id="sum_subcontractor">Not selected yet</div></div>    
        <div class="summary-row"><div class="summary-label">Company Address</div><div class="summary-value is-empty" id="sum_company_address">—</div></div>    
        <div class="summary-row"><div class="summary-label">Division</div><div class="summary-value is-empty" id="sum_company_division">—</div></div>    
        <div class="summary-row"><div class="summary-label">Contact Number</div><div class="summary-value is-empty" id="sum_company_contact">—</div></div>    
        <div class="summary-row"><div class="summary-label">Project / Location</div><div class="summary-value is-empty" id="sum_cat_details">—</div></div>    

        <div class="summary-row" id="row_hw_worktypes" style="display:none;"><div class="summary-label">Type Of Work</div><div class="summary-value is-empty" id="sum_hw_worktypes">None selected yet</div></div>    
        <div class="summary-row" id="row_hw_firewatch" style="display:none;"><div class="summary-label">Fire Watch</div><div class="summary-value is-empty" id="sum_hw_firewatch">Not entered</div></div>    
        <div class="summary-row" id="row_hw_precautions" style="display:none;"><div class="summary-label">Safety Precautions</div><div class="summary-value is-empty" id="sum_hw_precautions">0 of 22 selected</div></div>    

        <div class="summary-row" id="row_wah_controls" style="display:none;"><div class="summary-label">WAH Controls</div><div class="summary-value is-empty" id="sum_wah_controls">0 of 13 answered</div></div>    
    </div>
    <div class="col-md-6">
        <div class="summary-row"><div class="summary-label">Supervisor</div><div class="summary-value is-empty" id="sum_supervisor">—</div></div>    
        <div class="summary-row"><div class="summary-label">Tasks Added</div><div class="summary-value is-empty" id="sum_tasks">No tasks added yet</div></div>    
        <div class="summary-row"><div class="summary-label">Safety Induction</div><div class="summary-value is-empty" id="sum_checklist">0 of 9 answered</div></div>    
        <div class="summary-row"><div class="summary-label">PPE Selected</div><div class="summary-value is-empty" id="sum_ppe">None selected yet</div></div>    
        <div class="summary-row"><div class="summary-label">Workers Added</div><div class="summary-value is-empty" id="sum_workers">No workers added yet</div></div>    
        <div class="summary-row"><div class="summary-label">Declaration By</div><div class="summary-value is-empty" id="sum_declaration">Not entered yet</div></div>    
    </div>
</div>
</div>

<div class="text-center mt-3 d-flex justify-content-center gap-2">
    <button type="button" onclick="submitPermitForm()" class="btn btn-submit-custom" id="submitBtnText">    
        <i class="bi <?= $action_word === 'Resubmit' ? 'bi-arrow-repeat' : 'bi-check-circle-fill' ?> me-1"></i> <?= $action_word ?> General Permit    
    </button>
</div>

</form>
</div>
</div>

<script>
/* ============================================================= */
/* PERMIT NUMBER  (HAY-YYYY-TYPE-NNNN)                            */
/* The next number for each category is calculated on the server; */
/* the field switches when the category radio changes.            */
/* ============================================================= */
const PERMIT_NUMBERS = <?= json_encode($permit_numbers_by_category) ?>;
const ORIGINAL_PERMIT_NUMBER = <?= json_encode($original_permit_number) ?>;
const IS_EDIT_MODE = <?= ($edit_id > 0) ? 'true' : 'false' ?>;

function updatePermitNumber(catVal){
    let num;
    // Resubmitting keeps the permit number even if the category is changed.
    if(IS_EDIT_MODE && ORIGINAL_PERMIT_NUMBER){
        num = ORIGINAL_PERMIT_NUMBER;
    } else {
        num = PERMIT_NUMBERS[catVal] || PERMIT_NUMBERS['normal'];
    }
    let field = document.getElementById("permit_number");
    if(field) field.value = num;
    let sum = document.getElementById("sum_permit_number");
    if(sum) sum.textContent = num;
}

function isNumberKey(evt){
    let charCode = (evt.which) ? evt.which : event.keyCode;
    if (charCode > 31 && (charCode < 48 || charCode > 57)) return false;
    return true;
}

function addTask(){
    let row = `
    <tr>
        <td><input type="text" name="task[]" class="form-control" placeholder="Enter Task" required></td>    
        <td><input type="text" name="hazard[]" class="form-control" placeholder="Enter Hazard Control" required></td>    
        <td class="text-center"><button type="button" class="btn btn-danger btn-sm py-1 px-2" onclick="removeTask(this)"><i class="bi bi-trash"></i></button></td>    
    </tr>`;
    document.getElementById("taskBody").insertAdjacentHTML('beforeend', row);
    updateSummary();
}

function removeTask(btn){
    let rows = document.querySelectorAll("#taskBody tr");
    if(rows.length > 1){
        btn.closest("tr").remove();
        updateSummary();
    }
}

/* ============================================================= */
/* APPROVED WORKERS (loaded from DB by subcontractor vendor code) */
/* ============================================================= */
const EXISTING_WORKERS = <?= json_encode(array_values($existing_workers)) ?>;
let workerCache = [];
let selectedNics = new Set((EXISTING_WORKERS || []).map(w => w.worker_nic));
let lastVendor = null;

function loadApprovedWorkers(vendor){
    if(vendor === lastVendor) return;
    // Subcontractor changed by the user -> clear the previous selection
    if(lastVendor !== null) selectedNics.clear();
    lastVendor = vendor;

    const msg = document.getElementById("workerPickerMsg");

    if(!vendor){
        workerCache = [];
        msg.style.display = "block";
        msg.textContent = "Select a subcontractor first to load approved workers.";
        renderWorkers();
        return;
    }

    msg.style.display = "block";
    msg.textContent = "Loading approved workers...";

    fetch("get_approved_workers.php?vendor_code=" + encodeURIComponent(vendor))
    .then(r => r.json())
    .then(list => {
        workerCache = Array.isArray(list) ? list : [];

        // Edit mode: keep workers saved on the old permit even if not in the approved list
        (EXISTING_WORKERS || []).forEach(w => {
            if(selectedNics.has(w.worker_nic) && !workerCache.some(c => c.nic === w.worker_nic)){
                workerCache.push({code:'-', name:w.worker_name, nic:w.worker_nic});
            }
        });

        if(workerCache.length){
            msg.style.display = "none";
        } else {
            msg.style.display = "block";
            msg.textContent = "No approved workers found for this subcontractor.";
        }
        renderWorkers();
    })
    .catch(() => {
        msg.style.display = "block";
        msg.textContent = "Failed to load workers. Please try again.";
    });
}

function renderWorkers(){
    const box = document.getElementById("workerList");
    document.getElementById("workerPickerHead").style.display = workerCache.length ? "block" : "none";
    document.getElementById("selectAllWorkers").checked = false;

    box.innerHTML = workerCache.map((w, i) => `
        <div class="col-md-4">
            <div class="form-check mb-1">
                <input class="form-check-input worker-pick" type="checkbox" id="wp_${i}" data-idx="${i}"
                    ${selectedNics.has(w.nic) ? 'checked' : ''} onchange="syncWorkers()">
                <label class="form-check-label" for="wp_${i}">
                    <b>${escapeHtml(w.name)}</b><br>
                    <span class="worker-sub">${escapeHtml(w.code)} · ${escapeHtml(w.nic)}</span>
                </label>
            </div>
        </div>`).join("");

    syncWorkers();
}

function toggleAllWorkers(state){
    document.querySelectorAll(".worker-pick").forEach(cb => cb.checked = state);
    syncWorkers();
}

function syncWorkers(){
    const body = document.getElementById("workerBody");
    let html = "";
    selectedNics.clear();

    document.querySelectorAll(".worker-pick:checked").forEach(cb => {
        const w = workerCache[cb.dataset.idx];
        selectedNics.add(w.nic);
        html += `<tr>
            <td>${escapeHtml(w.code)}</td>
            <td><input type="text" name="worker_name[]" class="form-control" value="${escapeHtml(w.name)}" readonly required></td>
            <td><input type="text" name="worker_nic[]" class="form-control" value="${escapeHtml(w.nic)}" readonly required></td>
            <td class="text-center"><button type="button" class="btn btn-danger btn-sm py-1 px-2" onclick="uncheckWorker('${cb.id}')"><i class="bi bi-trash"></i></button></td>
        </tr>`;
    });
    body.innerHTML = html;

    const all = document.querySelectorAll(".worker-pick");
    const sel = document.querySelectorAll(".worker-pick:checked");
    const selectAll = document.getElementById("selectAllWorkers");
    if(selectAll) selectAll.checked = (all.length > 0 && all.length === sel.length);

    updateSummary();
}

function uncheckWorker(id){
    document.getElementById(id).checked = false;
    syncWorkers();
}

function loadCompanyData(){
    let s = document.getElementById("subcontractor");
    let o = s.options[s.selectedIndex];
    document.getElementById("company_name").value = o.dataset.name || "";
    document.getElementById("company_address").value = o.dataset.address || "";
    document.getElementById("company_contact").value = o.dataset.contact || "";
    document.getElementById("company_division").value = o.dataset.division || "";
    loadApprovedWorkers(o.dataset.vendor || "");
    updateSummary();
}

function escapeHtml(str){
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* Build the dropdown list from the DB-loaded <option>s, filtered by what is typed */
function buildCompanyList(){
    let select = document.getElementById("subcontractor");
    let list = document.getElementById("companyList");
    let q = document.getElementById("companySearch").value.toLowerCase().trim();
    let html = "", count = 0;
    for(let i = 1; i < select.options.length; i++){
        let o = select.options[i];
        let name = o.text.trim();
        let pos = q ? name.toLowerCase().indexOf(q) : -1;
        if(q && pos === -1) continue;
        count++;
        let label = q
            ? escapeHtml(name.substring(0,pos)) + "<mark>" + escapeHtml(name.substring(pos,pos+q.length)) + "</mark>" + escapeHtml(name.substring(pos+q.length))
            : escapeHtml(name);
        html += `<div class="company-item" data-value="${escapeHtml(o.value)}"><span>${label}</span><small>${escapeHtml(o.dataset.division || "")}</small></div>`;
    }
    if(!count) html = '<div class="company-empty">No matching company found</div>';
    list.innerHTML = html;
    list.style.display = "block";
}

function searchCompany(){
    let select = document.getElementById("subcontractor");
    let input = document.getElementById("companySearch");
    // If the user edits the text after choosing a company, clear the old selection
    if(select.value !== "" && input.value.trim() !== select.options[select.selectedIndex].text.trim()){
        select.value = "";
        loadCompanyData();
    }
    buildCompanyList();
}

function chooseCompany(value){
    let select = document.getElementById("subcontractor");
    select.value = value;
    document.getElementById("companySearch").value = select.options[select.selectedIndex].text.trim();
    document.getElementById("companyList").style.display = "none";
    loadCompanyData();
}

function initCompanySearch(){
    let select = document.getElementById("subcontractor");
    let input = document.getElementById("companySearch");
    let list = document.getElementById("companyList");

    // Edit mode: show the already selected company in the search box
    if(select.selectedIndex > 0){
        input.value = select.options[select.selectedIndex].text.trim();
    }

    list.addEventListener("mousedown", function(e){
        let item = e.target.closest(".company-item");
        if(item){ e.preventDefault(); chooseCompany(item.dataset.value); }
    });

    input.addEventListener("keydown", function(e){
        if(e.key === "Enter"){
            e.preventDefault();
            let first = list.querySelector(".company-item");
            if(first) chooseCompany(first.dataset.value);
        } else if(e.key === "Escape"){
            list.style.display = "none";
        }
    });

    document.addEventListener("click", function(e){
        if(!e.target.closest(".company-search-wrap")) list.style.display = "none";
    });
}

function toggleOtherInput(checkbox) {
    var container = document.getElementById('other_input_container');
    if (checkbox.checked) {
        container.style.display = 'inline-block';
    } else {
        container.style.display = 'none';
        container.querySelector('input').value = '';
    }
}

/* Show/hide a category-specific PPE box. When hidden, its checkboxes are unchecked
   so PPE of another category is never submitted. */
function setPpeBox(id, show){
    let box = document.getElementById(id);
    if(!box) return;
    box.style.display = show ? "block" : "none";
    if(!show){
        box.querySelectorAll("input[type='checkbox']").forEach(cb => cb.checked = false);
    }
}

function togglePermitCategory(){
    let selected = document.querySelector("input[name='permit_category']:checked");
    let detailsBox = document.getElementById("permitCategoryDetails");
    let hotWorkBox = document.getElementById("hotWorkFields");
    let wahBox = document.getElementById("wahFields");
    let generalFieldsBox = document.getElementById("generalPermitFields"); 
    let labelHW = document.getElementById("labelHotWork");
    let labelWAH = document.getElementById("labelWorkAtHeight");
    let labelNormal = document.getElementById("labelNormalWork");
    let headerTitleText = document.getElementById("headerTitleText");
    let pageTitle = document.getElementById("pageTitle");
    let submitBtnText = document.getElementById("submitBtnText");

    labelHW.classList.remove("active");
    labelWAH.classList.remove("active");
    labelNormal.classList.remove("active");

    // Category based extra PPE show / hide
    let catVal = selected ? selected.value : "normal";
    setPpeBox("hwPpeBox", catVal === "hot_work");
    setPpeBox("wahPpeBox", catVal === "work_at_height");

    // Permit number follows the selected category (GEN / HW / WAH)
    updatePermitNumber(catVal);

    let isResubmit = <?= ($edit_id > 0) ? 'true' : 'false' ?>;
    let actionPrefix = isResubmit ? ACTION_VERB + " " : "";
    let actionIcon = isResubmit && ACTION_VERB === "Resubmit" ? "bi-arrow-repeat" : "bi-check-circle-fill";

    let currentCatName = "General Permit";
    if(selected){
        if(selected.value === "hot_work"){
            currentCatName = "Hot Work Permit";
        } else if(selected.value === "work_at_height"){
            currentCatName = "Work At Height Permit";
        } else {
            currentCatName = "General Permit";
        }
    }

    let fullHeaderTitle = actionPrefix + currentCatName;
    headerTitleText.textContent = fullHeaderTitle;
    pageTitle.textContent = currentCatName;
    submitBtnText.innerHTML = `<i class="bi ${actionIcon} me-1"></i> ${fullHeaderTitle}`;

    if(!selected || selected.value === "normal"){
        detailsBox.style.display = "none";
        
        if(generalFieldsBox) generalFieldsBox.style.display = "block"; 
        toggleTaskRequired(true);

        if(selected && selected.value === "normal") labelNormal.classList.add("active");
        updateSummary();
        return;
    }

    if(generalFieldsBox) generalFieldsBox.style.display = "none";
    toggleTaskRequired(false);

    detailsBox.style.display = "block";

    if(selected.value === "hot_work"){
        hotWorkBox.style.display = "block";
        wahBox.style.display = "none";
        labelHW.classList.add("active");
    } else if(selected.value === "work_at_height"){
        hotWorkBox.style.display = "none";
        wahBox.style.display = "block";
        labelWAH.classList.add("active");
    }

    detailsBox.scrollIntoView({behavior:"smooth", block:"start"});
    updateSummary();
}

function toggleTaskRequired(isRequired) {
    let taskInputs = document.querySelectorAll("#taskBody input");
    taskInputs.forEach(input => {
        if(isRequired) {
            input.setAttribute("required", "true");
        } else {
            input.removeAttribute("required");
        }
    });
}

const ACTION_VERB = <?= json_encode($action_word) ?>;
const ACTION_PAST = <?= json_encode($action_past) ?>;
const TOTAL_CHECKLIST_ITEMS = <?= count($items) ?>;

function setSummary(id, value, emptyText){
    let el = document.getElementById(id);
    if(!el) return;
    if(!value){
        el.textContent = emptyText;
        el.classList.add("is-empty");
    } else {
        el.textContent = value;
        el.classList.remove("is-empty");
    }
}

function updateSummary(){
    let form = document.getElementById("serviceProviderForm");
    if(!form) return;

    let catSelected = document.querySelector("input[name='permit_category']:checked");
    let catValue = catSelected ? catSelected.value : "";
    let catLabel = "";
    if(catValue === "hot_work") catLabel = "Hot Work";
    else if(catValue === "work_at_height") catLabel = "Work At Height";
    else if(catValue === "normal") catLabel = "General Permit";
    setSummary("sum_category", catLabel, "Not selected yet");

    let catProject = document.getElementById("cat_project") ? document.getElementById("cat_project").value.trim() : "";
    let catLocation = document.getElementById("cat_work_location") ? document.getElementById("cat_work_location").value.trim() : "";
    let catDetailsText = [catProject, catLocation].filter(Boolean).join(" · ");
    setSummary("sum_cat_details", catDetailsText, "—");

    let rowHwWorktypes = document.getElementById("row_hw_worktypes");
    let rowHwFirewatch = document.getElementById("row_hw_firewatch");
    let rowHwPrecautions = document.getElementById("row_hw_precautions");
    let rowWahControls = document.getElementById("row_wah_controls");

    if(catValue === "hot_work"){
        rowHwWorktypes.style.display = "flex";
        rowHwFirewatch.style.display = "flex";
        rowHwPrecautions.style.display = "flex";
        rowWahControls.style.display = "none";

        let workTypes = Array.from(form.querySelectorAll("input[name='hw_work_type[]']:checked")).map(cb => {
            if(cb.value === "Other") {
                let otherVal = form.querySelector("input[name='hw_work_type_other']").value.trim();
                return otherVal ? "Other: " + otherVal : "Other";
            }
            return cb.value;
        });
        setSummary("sum_hw_worktypes", workTypes.length ? workTypes.join(", ") : "", "None selected yet");

        let fwName = form.querySelector("input[name='hw_fire_watch_name']") ? form.querySelector("input[name='hw_fire_watch_name']").value.trim() : "";
        let fwContact = form.querySelector("input[name='hw_fire_watch_contact']") ? form.querySelector("input[name='hw_fire_watch_contact']").value.trim() : "";
        let fwText = fwName ? (fwName + (fwContact ? " (" + fwContact + ")" : "")) : "";
        setSummary("sum_hw_firewatch", fwText, "Not entered");

        let precChecked = form.querySelectorAll("input[name='hw_precautions[]']:checked").length;
        let precTotal = form.querySelectorAll("input[name='hw_precautions[]']").length;
        document.getElementById("sum_hw_precautions").textContent = precChecked + " of " + precTotal + " selected";
        document.getElementById("sum_hw_precautions").classList.toggle("is-empty", precChecked === 0);

    } else if(catValue === "work_at_height"){
        rowHwWorktypes.style.display = "none";
        rowHwFirewatch.style.display = "none";
        rowHwPrecautions.style.display = "none";
        rowWahControls.style.display = "flex";

        let wahAnswered = 0, wahYes = 0, wahNo = 0, wahNa = 0;
        for(let i = 1; i <= 13; i++){
            let checked = form.querySelector("input[name='wah_control_" + i + "']:checked");
            if(checked){
                wahAnswered++;
                if(checked.value === "Yes") wahYes++;
                else if(checked.value === "No") wahNo++;
                else wahNa++;
            }
        }
        let wahEl = document.getElementById("sum_wah_controls");
        if(wahAnswered === 0){
            wahEl.textContent = "0 of 13 answered";
            wahEl.classList.add("is-empty");
        } else {
            wahEl.classList.remove("is-empty");
            wahEl.innerHTML = `<div class="summary-table-grid"><span class='summary-chip chip-yes'>✓ Yes: ${wahYes}</span><span class='summary-chip chip-no'>✕ No: ${wahNo}</span><span class='summary-chip chip-na'>- N/A: ${wahNa}</span></div>`;
        }

    } else {
        rowHwWorktypes.style.display = "none";
        rowHwFirewatch.style.display = "none";
        rowHwPrecautions.style.display = "none";
        rowWahControls.style.display = "none";
    }

    let subSelect = document.getElementById("subcontractor");
    let subText = (subSelect.selectedIndex > 0) ? subSelect.options[subSelect.selectedIndex].text.trim() : "";
    setSummary("sum_subcontractor", subText, "Not selected yet");

    setSummary("sum_company_address", document.getElementById("company_address").value.trim(), "—");
    setSummary("sum_company_division", document.getElementById("company_division").value.trim(), "—");
    setSummary("sum_company_contact", document.getElementById("company_contact").value.trim(), "—");

    let supName = form.supervisor_name.value.trim();
    let supContact = form.supervisor_contact.value.trim();
    setSummary("sum_supervisor", supName ? (supName + (supContact ? " (" + supContact + ")" : "")) : "", "—");

    let taskInputs = Array.from(form.querySelectorAll("input[name='task[]']")).map(i => i.value.trim()).filter(v => v !== "");
    setSummary("sum_tasks", taskInputs.length > 0 ? (taskInputs.length + " task(s): " + taskInputs.join(", ")) : "", "No tasks added yet");

    let answered = 0, yesCount = 0, noCount = 0, naCount = 0;
    for(let i = 0; i < TOTAL_CHECKLIST_ITEMS; i++){
        let checked = form.querySelector("input[name='check_" + i + "']:checked");
        if(checked){
            answered++;
            if(checked.value === "Yes") yesCount++;
            else if(checked.value === "No") noCount++;
            else naCount++;
        }
    }

    let checklistEl = document.getElementById("sum_checklist");
    if(answered === 0){
        checklistEl.textContent = "0 of " + TOTAL_CHECKLIST_ITEMS + " answered";
        checklistEl.classList.add("is-empty");
    } else {
        checklistEl.classList.remove("is-empty");
        checklistEl.innerHTML = `<div class="summary-table-grid"><span class='summary-chip chip-yes'>✓ Yes: ${yesCount}</span><span class='summary-chip chip-no'>✕ No: ${noCount}</span><span class='summary-chip chip-na'>- N/A: ${naCount}</span></div>`;
    }

    let ppeChecked = Array.from(form.querySelectorAll("input[name='ppe[]']:checked")).map(cb => cb.value);
    let ppeEl = document.getElementById("sum_ppe");
    if(ppeChecked.length === 0){
        ppeEl.textContent = "None selected yet";
        ppeEl.classList.add("is-empty");
    } else {
        ppeEl.classList.remove("is-empty");
        ppeEl.innerHTML = ppeChecked.map(p => `<span class='summary-chip chip-yes'><i class='bi bi-check-circle-fill'></i> ${p}</span>`).join("");
    }

    let workerNames = Array.from(form.querySelectorAll("input[name='worker_name[]']")).map(i => i.value.trim()).filter(v => v !== "");
    setSummary("sum_workers", workerNames.length > 0 ? (workerNames.length + " worker(s): " + workerNames.join(", ")) : "", "No workers added yet");

    let declName = form.declaration_name_designation.value.trim();
    let declDate = form.declaration_date.value.trim();
    setSummary("sum_declaration", [declName, declDate].filter(Boolean).join(" · "), "Not entered yet");
}

document.getElementById("serviceProviderForm").addEventListener("input", updateSummary);
document.getElementById("serviceProviderForm").addEventListener("change", updateSummary);
document.addEventListener("DOMContentLoaded", () => {
    initCompanySearch();
    loadCompanyData();
    togglePermitCategory();
    updateSummary();
});

function submitPermitForm() {
    let form = document.getElementById("serviceProviderForm");
    if(!form.checkValidity()){
        form.reportValidity();
        return;
    }

    // At least one approved worker must be selected
    if(document.querySelectorAll("#workerBody tr").length === 0){
        Swal.fire({
            icon: 'warning',
            title: 'Workers Required',
            text: 'Please select at least one approved worker for this subcontractor.'
        });
        return;
    }

    let catSelected = document.querySelector("input[name='permit_category']:checked");
    if(!catSelected){
        Swal.fire({
            icon: 'warning',
            title: 'Permit Category Required',
            text: 'Please select a permit category before submitting.'
        });
        return;
    }

    let isResubmit = <?= ($edit_id > 0) ? 'true' : 'false' ?>;
    let actionText = isResubmit ? ACTION_VERB : "Submit";
    let actionPast = isResubmit ? ACTION_PAST : "Submitted";
    let selectedCatValue = catSelected.value;
    let permitTypeName = "General Permit";
    if(selectedCatValue === "hot_work") permitTypeName = "Hot Work Permit";
    else if(selectedCatValue === "work_at_height") permitTypeName = "Work At Height Permit";

    Swal.fire({
        title: isResubmit && actionText === 'Resubmit' ? 'Confirm Resubmission' : 'Are you sure?',
        text: isResubmit && actionText === 'Resubmit'
            ? `Resubmit this ${permitTypeName} using the same permit number (${ORIGINAL_PERMIT_NUMBER})?`
            : `Do you want to ${actionText.toLowerCase()} this ${permitTypeName}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#64748B',
        confirmButtonText: `Yes, ${actionText}!`,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {

            // Show a "Submitting..." loading modal right away so the
            // user knows the form is being processed.
            Swal.fire({
                title: isResubmit && actionText === 'Resubmit' ? 'Resubmitting Permit...' : 'Submitting Form...',
                text: isResubmit && actionText === 'Resubmit'
                    ? `Please wait while we resubmit permit ${ORIGINAL_PERMIT_NUMBER}.`
                    : 'Please wait while we save your permit.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            let formData = new FormData(form);

            fetch("submit_service_provider_permit.php", {
                method: "POST",
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if(data.trim() === "Success"){
                    Swal.fire({
                        title: `${actionPast} Successfully!`,
                        text: `${permitTypeName} has been ${actionPast.toLowerCase()} successfully.`,
                        icon: "success",
                        confirmButtonText: "OK"
                    }).then(() => {
                        goBackToPermitsPage();
                    });
                } else {
                    Swal.fire("Database Error!", data, "error");
                }
            })
            .catch(error => {
                Swal.fire("Error!", "Something went wrong. Please try again.", "error");
            });
        }
    });
}

/* Redirects back to permits.php — works whether this form is loaded
   standalone (own browser tab) or inside the iframe modal used by
   permits.php's "Service Provider Permit" hero card / resubmit link. */
function goBackToPermitsPage() {
    if (window.self !== window.top) {
        // We're inside the permitModal iframe on permits.php — reload
        // the TOP page so the modal closes and the list refreshes.
        window.top.location.href = "permits.php";
    } else {
        // Opened directly as its own page.
        window.location.href = "permits.php";
    }
}
</script>

</body>
</html>