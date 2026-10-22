<?php
/* =====================================================
   PRINT SERVICE PROVIDER PERMIT
   Shows all the details that view_service_provider.php shows
   (no signature boxes). Opens in a new tab and auto-prints.
===================================================== */
session_start();

$is_admin      = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$is_supervisor = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;

if (!$is_admin && !$is_supervisor) {
    header("Location: index.php");
    exit();
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

$permit_id = intval($_GET['id'] ?? 0);
if ($permit_id <= 0) {
    die("Invalid or missing permit ID.");
}

$stmt = $conn->prepare("SELECT * FROM service_provider_permits WHERE permit_id = ?");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
    die("Permit not found.");
}

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function showValue($value, $default = '-') {
    return ($value === null || trim((string)$value) === '') ? $default : htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$work_category = strtolower(trim($permit['work_category'] ?? 'normal'));
$is_hot_work   = ($work_category === 'hot_work');
$is_wah        = ($work_category === 'work_at_height');
$is_normal     = (!$is_hot_work && !$is_wah);
$status        = strtolower(trim($permit['status'] ?? 'pending'));

$categoryLabel = $is_hot_work ? 'Hot Work' : ($is_wah ? 'Work At Height' : 'General');
$typeLabel     = $categoryLabel . ' Permit';
$expiry        = !empty($permit['expiry_date']) ? date('Y-m-d h:i A', strtotime($permit['expiry_date'])) : '-';

/* ---------- Child table data ---------- */
$tasks_list = [];
if ($is_normal) {
    $t = $conn->prepare("SELECT task_description, hazard_control FROM service_provider_tasks WHERE permit_id = ?");
    if ($t) {
        $t->bind_param("i", $permit_id);
        $t->execute();
        $r = $t->get_result();
        while ($row = $r->fetch_assoc()) { $tasks_list[] = $row; }
        $t->close();
    }
}

$checklist_data = [];
$c = $conn->prepare("SELECT item_index, answer FROM service_provider_checklist WHERE permit_id = ?");
if ($c) {
    $c->bind_param("i", $permit_id);
    $c->execute();
    $r = $c->get_result();
    while ($row = $r->fetch_assoc()) { $checklist_data[(int)$row['item_index']] = $row['answer']; }
    $c->close();
}

$ppe_selected = [];
$p = $conn->prepare("SELECT ppe_item FROM service_provider_ppe WHERE permit_id = ?");
if ($p) {
    $p->bind_param("i", $permit_id);
    $p->execute();
    $r = $p->get_result();
    while ($row = $r->fetch_assoc()) { $ppe_selected[] = $row['ppe_item']; }
    $p->close();
}

$workers_list = [];
$w = $conn->prepare("SELECT worker_name, worker_nic FROM service_provider_workers WHERE permit_id = ?");
if ($w) {
    $w->bind_param("i", $permit_id);
    $w->execute();
    $r = $w->get_result();
    while ($row = $r->fetch_assoc()) { $workers_list[] = ['name' => $row['worker_name'], 'nic' => $row['worker_nic']]; }
    $w->close();
}

$wah_controls = [];
if ($is_wah) {
    $decoded = json_decode($permit['wah_controls'] ?? '', true);
    if (is_array($decoded) && count($decoded) > 0) {
        foreach ($decoded as $idx => $ans) { $wah_controls[(int)$idx] = $ans; }
    } else {
        $s = $conn->prepare("SELECT control_index, status FROM service_provider_wah_controls WHERE permit_id = ?");
        if ($s) {
            $s->bind_param("i", $permit_id);
            $s->execute();
            $r = $s->get_result();
            while ($row = $r->fetch_assoc()) { $wah_controls[(int)$row['control_index']] = $row['status']; }
            $s->close();
        }
    }
}

/* ---------- Images ---------- */
$division_images = [];
$admin_images = [];
$imgStmt = $conn->prepare("SELECT image_path, uploaded_by FROM permit_images WHERE permit_id = ? ORDER BY image_id ASC");
if ($imgStmt) {
    $imgStmt->bind_param("i", $permit_id);
    $imgStmt->execute();
    $r = $imgStmt->get_result();
    while ($imgRow = $r->fetch_assoc()) {
        // media_url() yields "media.php?p=..." for S3-backed rows and
        // passes legacy local paths straight through unchanged.
        $path = media_url($imgRow['image_path'] ?? '');
        if ($path !== '') {
            if (!preg_match('/^(http:\/\/|https:\/\/|\/|\.\/|media\.php)/', $path)) {
                $path = './' . $path;
            }
            if (strtolower(trim($imgRow['uploaded_by'] ?? '')) === 'admin') {
                $admin_images[] = $path;
            } else {
                $division_images[] = $path;
            }
        }
    }
    $imgStmt->close();
}

$checklist_items = [
    "Have you checked the competencies and qualifications of inductees?",
    "Have you discussed procedures for reporting incidents, injuries and hazards?",
    "Have you discussed the company QHSE En Rules?",
    "Is specialized equipment required and have workers been trained?",
    "Do people have the correct PPE available?",
    "Have you explained emergency procedures and assembly point?",
    "Have you shown the location of fire extinguishers and hose reels?",
    "Have you shown toilets and drinking water facilities?",
    "Have you explained workplace security procedures?"
];

$wah_control_items = [
    1  => "Health evaluation of persons going at height is done.",
    2  => "Safe means of access / egress provided.",
    3  => "Edge protection provided wherever there is falling hazards.",
    4  => "Fall prevention equipment used to access / egress work platform.",
    5  => "Fall prevention equipment used are adequate and in good condition.",
    6  => "Anchorage / Lifeline installed and inspected.",
    7  => "Travel restraint system used to safeguard persons from falling hazards.",
    8  => "All persons subjected to falling hazards are equipped with Personal Fall Arrest System.",
    9  => "Electrical power and/or other energy is isolated (if working near/on it).",
    10 => "Area under the working at height is barricaded.",
    11 => "All persons subjected to falling hazards are adequately trained to perform work at heights.",
    12 => "Fall Protection Plan & Risk Assessment conducted and communicated.",
    13 => "Emergency Response Procedure for WAH activities developed and communicated to workers."
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Permit <?= e($permit['permit_number'] ?? '') ?></title>
<style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12.5px; line-height: 1.45; margin: 0; background: #eee; }
    .sheet { width: 210mm; margin: 10px auto; background: #fff; padding: 14mm; }

    .head { display: flex; justify-content: space-between; align-items: center;
            border-bottom: 3px solid #da6913; padding-bottom: 8px; margin-bottom: 14px; }
    .head img { height: 52px; }
    .head h1 { margin: 0; font-size: 19px; text-align: right; }
    .head small { display: block; color: #555; font-size: 12px; margin-top: 4px; font-weight: normal; }

    .section { margin-bottom: 14px; page-break-inside: avoid; }
    .section-title { font-size: 13px; font-weight: bold; color: #bb4c14; border-bottom: 1.5px solid #ccc;
                     padding-bottom: 3px; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: .3px; }

    /* label : value lines */
    .kv { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; }
    .kv .full { grid-column: 1 / -1; }
    .kv .label { display: block; font-size: 10.5px; font-weight: bold; color: #666; text-transform: uppercase; }
    .kv .value { display: block; font-size: 12.5px; font-weight: bold; }

    /* plain lists */
    ul.plain { margin: 0; padding-left: 18px; }
    ul.plain li { margin-bottom: 5px; }
    ul.plain .sub { display: block; color: #333; font-size: 12px; }
    .ans { font-weight: bold; }

    table.chk { width: 100%; border-collapse: collapse; }
    table.chk th, table.chk td { border: 1px solid #999; padding: 5px 8px; text-align: left; vertical-align: top; font-size: 12px; }
    table.chk th { background: #e9e9e9; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.chk .center { text-align: center; }
    table.chk tr { page-break-inside: avoid; }

    .status-box { display: inline-block; border: 1px solid #333; padding: 1px 9px; font-weight: bold; font-size: 12px; }
    .alert { border: 1px solid #c2410c; background: #ffeee3; padding: 7px 10px; margin-bottom: 12px; font-size: 12px; }

    .imgs img { width: 90px; height: 90px; object-fit: cover; border: 1px solid #999; margin: 0 5px 5px 0; }
    .imgs h4 { margin: 4px 0; font-size: 12px; }

    .no-print { text-align: center; margin: 10px; }
    .no-print button { padding: 8px 20px; font-size: 14px; cursor: pointer; }

    @media print {
        body { background: #fff; }
        .sheet { margin: 0; padding: 0; width: auto; }
        .no-print { display: none !important; }
        .alert { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        li { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">🖨 Print</button>
    <button onclick="window.close()">Close</button>
</div>

<div class="sheet">

    <div class="head">
        <img src="assets/images/hayleys_logo.png" alt="Logo" onerror="this.style.display='none'">
        <h1><?= e($typeLabel) ?><small>Permit No: <?= e($permit['permit_number'] ?? '') ?></small></h1>
    </div>

    <?php if (strpos($status, 'rejected') !== false && !empty($permit['rejection_reason'])): ?>
    <div class="alert">
        <b>Permit Rejected<?= !empty($permit['rejected_by']) ? ' by ' . e($permit['rejected_by']) : '' ?></b><br>
        <b>Reason:</b> <?= e($permit['rejection_reason']) ?>
    </div>
    <?php endif; ?>

    <?php if (($status === 'hold' || $status === 'on hold') && !empty($permit['hold_reason'])): ?>
    <div class="alert"><b>Permit On Hold.</b> <b>Reason:</b> <?= e($permit['hold_reason']) ?></div>
    <?php endif; ?>

    <!-- Permit summary -->
    <div class="section">
        <div class="kv">
            <div><span class="label">Permit Number</span><span class="value"><?= showValue($permit['permit_number'] ?? '') ?></span></div>
            <div><span class="label">Status</span><span class="value"><span class="status-box"><?= e(strtoupper($status)) ?></span></span></div>
            <div><span class="label">Category</span><span class="value"><?= e(strtoupper($categoryLabel)) ?></span></div>
            <div><span class="label">Valid Until</span><span class="value"><?= e($expiry) ?></span></div>
        </div>
    </div>

    <!-- Subcontractor & Company Details -->
    <div class="section">
        <div class="section-title">Subcontractor &amp; Company Details</div>
        <div class="kv">
            <div><span class="label">Company Name</span><span class="value"><?= showValue($permit['company_name'] ?? '') ?></span></div>
            <div><span class="label">Contact Number</span><span class="value"><?= showValue($permit['contact_number'] ?? '') ?></span></div>
            <div><span class="label">Division</span><span class="value"><?= showValue($permit['division'] ?? '') ?></span></div>
            <div><span class="label">Division Address</span><span class="value"><?= showValue($permit['division_address'] ?? '') ?></span></div>
        </div>
    </div>

    <!-- Supervisor Details -->
    <div class="section">
        <div class="section-title">Supervisor Details</div>
        <div class="kv">
            <div><span class="label">Supervisor Name</span><span class="value"><?= showValue($permit['supervisor_name'] ?? '') ?></span></div>
            <div><span class="label">Supervisor Contact</span><span class="value"><?= showValue($permit['supervisor_contact'] ?? '') ?></span></div>
        </div>
    </div>

    <!-- Project & Work Details -->
    <div class="section">
        <div class="section-title">Project &amp; Work Details</div>
        <div class="kv">
            <div><span class="label">Project Name</span><span class="value"><?= showValue($permit['cat_project'] ?? '') ?></span></div>
            <div><span class="label">Work Location</span><span class="value"><?= showValue($permit['cat_work_location'] ?? '') ?></span></div>
            <div class="full"><span class="label">Category</span><span class="value"><?= e(strtoupper($categoryLabel)) ?></span></div>
            <div class="full"><span class="label">Description</span><span class="value"><?= showValue($permit['cat_description'] ?? '') ?></span></div>
        </div>
    </div>

    <?php if ($is_normal): ?>
    <!-- Tasks & Hazard Controls -->
    <div class="section">
        <div class="section-title">Tasks &amp; Hazard Controls</div>
        <?php if (!empty($tasks_list)): ?>
        <ul class="plain">
            <?php foreach ($tasks_list as $t): ?>
            <li>
                <b><?= e($t['task_description']) ?></b>
                <span class="sub">Hazard Control: <?= showValue($t['hazard_control']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <div>No tasks recorded</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($is_hot_work): ?>
    <!-- Hot Work Details -->
    <div class="section">
        <div class="section-title">Hot Work Details</div>
        <div class="kv">
            <div><span class="label">Fire Watch Name</span><span class="value"><?= showValue($permit['hw_fire_watch_name'] ?? '') ?></span></div>
            <div><span class="label">Fire Watch Contact</span><span class="value"><?= showValue($permit['hw_fire_watch_contact'] ?? '') ?></span></div>
            <div class="full"><span class="label">Hot Work Types</span><span class="value"><?= showValue($permit['hw_work_type'] ?? '') ?></span></div>
            <div class="full"><span class="label">Precautions</span><span class="value"><?= showValue($permit['hw_precautions'] ?? '') ?></span></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_wah): ?>
    <!-- Work At Height Controls -->
    <div class="section">
        <div class="section-title">Work At Height Controls</div>
        <ul class="plain">
            <?php foreach ($wah_control_items as $idx => $label): $ans = $wah_controls[$idx] ?? 'N/A'; ?>
            <li><?= e($label) ?> &mdash; <span class="ans"><?= e($ans) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Job Safety Induction Checklist -->
    <div class="section">
        <div class="section-title">Job Safety Induction Checklist</div>
        <table class="chk">
            <thead><tr><th>Checklist Item</th><th class="center" width="90">Answer</th></tr></thead>
            <tbody>
            <?php foreach ($checklist_items as $idx => $item): $ans = $checklist_data[$idx] ?? 'N/A'; ?>
                <tr><td><?= e($item) ?></td><td class="center"><b><?= e($ans) ?></b></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Required PPE -->
    <div class="section">
        <div class="section-title">Required PPE</div>
        <?php if (!empty($ppe_selected)): ?>
        <ul class="plain">
            <?php foreach ($ppe_selected as $ppe): ?><li><?= e($ppe) ?></li><?php endforeach; ?>
        </ul>
        <?php else: ?>
            <div>No PPE items recorded.</div>
        <?php endif; ?>
    </div>

    <!-- Workers Declaration -->
    <div class="section">
        <div class="section-title">Workers Declaration</div>
        <?php if (!empty($workers_list)): ?>
        <ul class="plain">
            <?php foreach ($workers_list as $wk): ?>
            <li><b><?= e($wk['name']) ?></b> &mdash; NIC: <?= showValue($wk['nic']) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
            <div>No workers recorded</div>
        <?php endif; ?>
    </div>

    <?php if (!empty($division_images) || !empty($admin_images)): ?>
    <!-- Work Progress Images -->
    <div class="section">
        <div class="section-title">Work Progress Images</div>
        <div class="imgs">
            <?php if (!empty($division_images)): ?>
                <h4>Supervisor Uploaded Images</h4>
                <?php foreach ($division_images as $img): ?><img src="<?= e($img) ?>" alt=""><?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($admin_images)): ?>
                <h4>Admin Uploaded Images</h4>
                <?php foreach ($admin_images as $img): ?><img src="<?= e($img) ?>" alt=""><?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <p style="margin-top:16px;font-size:10.5px;color:#777;">Printed on: <?= date('Y-m-d h:i A') ?></p>
</div>

<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 600);
    });
</script>
</body>
</html>
<?php $conn->close(); ?>