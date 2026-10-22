<?php
/*
====================================================================
 submit_service_provider_permit.php
 ALL permit types (General / Hot Work / Work At Height)
   -> status 'Pending'  (goes straight to Admin, NO third party, NO mail)

 PERMIT NUMBER FORMAT:  HAY-YYYY-TYPE-NNNN
   TYPE = GEN (General) | HW (Hot Work) | WAH (Work At Height)
   NNNN = running count, separate for each TYPE and each YEAR

 EDIT (resubmit_of > 0) allowed while:
   Rejected / Pending  (and old legacy Third Party statuses)
   -> same permit number, unless the category was changed
      (then a new number for the new category is generated)

 PPE is validated per category (server side whitelist).
====================================================================
*/

session_start();
header('Content-Type: text/plain');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$is_admin      = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$is_supervisor = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;

if (!$is_admin && !$is_supervisor) {
    http_response_code(403);
    echo "Session expired. Please login again.";
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Invalid request method.";
    exit();
}

require_once __DIR__ . '/db_config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---------------- Helpers ---------------- */
function post($key, $default = '') { return isset($_POST[$key]) ? trim($_POST[$key]) : $default; }
function postArr($key) { return isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : []; }

/* Category -> permit number type code */
function permitTypeCode($category) {
    if ($category === 'hot_work')       return 'HW';
    if ($category === 'work_at_height') return 'WAH';
    return 'GEN';
}

/* Next number for HAY-YYYY-TYPE-NNNN (call inside a transaction; rows are locked) */
function generatePermitNumber($conn, $category) {
    $prefix   = "HAY-" . date('Y') . "-" . permitTypeCode($category) . "-";
    $next_seq = 1;

    $pn = $conn->prepare(
        "SELECT permit_number FROM service_provider_permits
         WHERE permit_number LIKE CONCAT(?, '%')
         ORDER BY CAST(SUBSTRING_INDEX(permit_number, '-', -1) AS UNSIGNED) DESC
         LIMIT 1 FOR UPDATE"
    );
    $pn->bind_param("s", $prefix);
    $pn->execute();
    if ($row = $pn->get_result()->fetch_assoc()) {
        if (preg_match('/(\d+)$/', $row['permit_number'], $m)) {
            $next_seq = (int)$m[1] + 1;
        }
    }
    $pn->close();

    return $prefix . str_pad($next_seq, 4, '0', STR_PAD_LEFT);
}

/* ---------------- Inputs ---------------- */
$resubmit_of      = intval(post('resubmit_of', 0));
$subcontractor_id = intval(post('subcontractor_id', 0));
$company_name     = post('company_name');
$company_contact  = post('company_contact');

$supervisor_name    = post('supervisor_name');
$supervisor_contact = post('supervisor_contact');
$division           = post('division');
$division_address   = post('division_address');

$permit_category   = post('permit_category');
$cat_project       = post('cat_project');
$cat_work_location = post('cat_work_location');
$cat_description   = post('cat_description');

$declaration_name = post('declaration_name_designation');
$declaration_date = post('declaration_date');

$tasks        = postArr('task');
$hazards      = postArr('hazard');
$workers_name = postArr('worker_name');
$workers_nic  = postArr('worker_nic');
$ppe_selected = postArr('ppe');

if ($subcontractor_id <= 0) { echo "Please select a subcontractor."; exit(); }

if (!in_array($permit_category, ['normal', 'hot_work', 'work_at_height'], true)) {
    echo "Please select a valid permit category.";
    exit();
}
$work_category = $permit_category;   // DB value stays 'normal' (shown as "General" in the UI)

/* ---------------- PPE whitelist per category ---------------- */
$allowed_ppe = ['Hard Hat', 'Goggles', 'Safety Shoes', 'High Visibility Vest'];
if ($permit_category === 'hot_work') {
    $allowed_ppe = array_merge($allowed_ppe, [
        'Welding Gloves', 'Face Shield', 'Fire Protective Clothes',   // Welding work
        'Dotted Gloves'                                               // Grinding work
    ]);
} elseif ($permit_category === 'work_at_height') {
    $allowed_ppe[] = 'Full Body Harness';                             // Platform above 1.8m
}
$ppe_selected = array_values(array_unique(array_intersect(array_map('trim', $ppe_selected), $allowed_ppe)));

/* General permit: at least one task + hazard control */
$clean_tasks = [];
if ($permit_category === 'normal') {
    if (count($tasks) !== count($hazards)) { echo "Task / Hazard control data is invalid."; exit(); }
    foreach ($tasks as $i => $tv) {
        $tv = trim($tv); $hv = trim($hazards[$i] ?? '');
        if ($tv !== '' && $hv !== '') $clean_tasks[] = [$tv, $hv];
    }
    if (count($clean_tasks) === 0) { echo "Please add at least one Task and Hazard Control."; exit(); }
}

if (count($workers_name) !== count($workers_nic)) { echo "Worker data is invalid."; exit(); }

/* Checklist (9 items) */
$checklist_answers = [];
for ($i = 0; $i < 9; $i++) {
    $val = post("check_{$i}", '');
    if ($val === '') { echo "Please answer all Safety Induction Checklist items."; exit(); }
    $checklist_answers[$i] = $val;
}

/* Hot Work fields */
$hw_work_type = ''; $hw_work_type_other = '';
$hw_fire_watch_name = ''; $hw_fire_watch_contact = '';
$hw_precautions_str = ''; $hw_precautions_list = []; $hw_types_clean = [];

if ($permit_category === 'hot_work') {
    $hw_types = postArr('hw_work_type');
    $hw_work_type          = implode(', ', $hw_types);
    $hw_work_type_other    = post('hw_work_type_other');
    $hw_fire_watch_name    = post('hw_fire_watch_name');
    $hw_fire_watch_contact = post('hw_fire_watch_contact');
    $hw_precautions_list   = postArr('hw_precautions');
    $hw_precautions_str    = implode(" | ", $hw_precautions_list);

    foreach ($hw_types as $wt) {
        if ($wt === 'Other') { $wt = $hw_work_type_other; }
        $wt = trim($wt);
        if ($wt !== '') $hw_types_clean[] = $wt;
    }
}

/* Work At Height fields */
$wah_controls_json = ''; $wah_controls_arr = [];
if ($permit_category === 'work_at_height') {
    for ($i = 1; $i <= 13; $i++) { $wah_controls_arr[$i] = post("wah_control_{$i}", ''); }
    $wah_controls_json = json_encode($wah_controls_arr, JSON_UNESCAPED_UNICODE);
}

/* Status: always goes straight to Admin */
$status     = 'Pending';
$changed_by = 'supervisor:' . ($_SESSION['sup_name'] ?? ($is_admin ? 'admin' : 'unknown'));

try {
    $conn->begin_transaction();

    if ($resubmit_of > 0) {
        /* ---------- EDIT / RESUBMIT ---------- */
        $permit_id = $resubmit_of;

        $chk = $conn->prepare("SELECT permit_number, status, work_category, rejection_reason, status_updated_at FROM service_provider_permits WHERE permit_id = ? FOR UPDATE");
        $chk->bind_param("i", $permit_id);
        $chk->execute();
        $old = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$old) throw new Exception("Original permit not found.");

        /* Old Third Party statuses are kept here only so older permits are not stuck */
        $editable = ['Rejected', 'Third Party Rejected', 'Pending', 'Pending Third Party', 'Third Party Approved'];
        if (!in_array($old['status'], $editable, true)) {
            throw new Exception("This permit can no longer be edited (current status: " . $old['status'] . ").");
        }
        $old_status = $old['status'];

        $old_rejection_reason = trim((string)($old['rejection_reason'] ?? ''));
        if (in_array($old_status, ['Rejected', 'Third Party Rejected'], true) && $old_rejection_reason !== '') {
            $history_check = $conn->prepare("SELECT log_id FROM permit_status_log WHERE permit_id = ? AND new_status = ? AND reason = ? LIMIT 1");
            $history_check->bind_param("iss", $permit_id, $old_status, $old_rejection_reason);
            $history_check->execute();
            $has_history = $history_check->get_result()->fetch_assoc();
            $history_check->close();

            if (!$has_history) {
                $legacy_changed_by = 'legacy:unknown';
                $history_insert = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW()))");
                $changed_at = $old['status_updated_at'] ?? null;
                $history_insert->bind_param("isssss", $permit_id, $old_status, $old_status, $old_rejection_reason, $legacy_changed_by, $changed_at);
                $history_insert->execute();
                $history_insert->close();
            }
        }

        $permit_number = $old['permit_number'];

        $sql = "UPDATE service_provider_permits SET
                    permit_number = ?,
                    subcontractor_id = ?, company_name = ?, contact_number = ?,
                    supervisor_name = ?, supervisor_contact = ?,
                    division = ?, division_address = ?, work_category = ?,
                    cat_project = ?, cat_work_location = ?, cat_description = ?,
                    hw_work_type = ?, hw_work_type_other = ?, hw_fire_watch_name = ?,
                    hw_fire_watch_contact = ?, hw_precautions = ?, wah_controls = ?,
                    declaration_name = ?, declaration_date = ?,
                    status = 'Pending',
                    rejection_reason = NULL, hold_reason = NULL,
                    status_updated_at = NOW(), updated_at = NOW()
                WHERE permit_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "si" . str_repeat("s", 18) . "i",
            $permit_number,
            $subcontractor_id, $company_name, $company_contact,
            $supervisor_name, $supervisor_contact,
            $division, $division_address, $work_category,
            $cat_project, $cat_work_location, $cat_description,
            $hw_work_type, $hw_work_type_other, $hw_fire_watch_name,
            $hw_fire_watch_contact, $hw_precautions_str, $wah_controls_json,
            $declaration_name, $declaration_date,
            $permit_id
        );
        $stmt->execute();
        $stmt->close();

        /* Old child rows are removed and re-inserted below */
        foreach (['service_provider_tasks', 'service_provider_checklist', 'service_provider_ppe',
                  'service_provider_workers', 'service_provider_hot_work_types',
                  'service_provider_hot_work_precautions', 'service_provider_wah_controls'] as $tbl) {
            $del = $conn->prepare("DELETE FROM {$tbl} WHERE permit_id = ?");
            $del->bind_param("i", $permit_id);
            $del->execute();
            $del->close();
        }
        $log_reason = in_array($old_status, ['Rejected', 'Third Party Rejected'], true)
                    ? 'Resubmitted' : 'Edited by supervisor';

    } else {
        /* ---------- NEW permit: HAY-YYYY-TYPE-NNNN ---------- */
        $permit_number = generatePermitNumber($conn, $permit_category);
        $old_status = '';

        $sql = "INSERT INTO service_provider_permits
                    (permit_number, subcontractor_id, company_name, contact_number,
                     supervisor_name, supervisor_contact,
                     division, division_address, work_category,
                     cat_project, cat_work_location, cat_description,
                     hw_work_type, hw_work_type_other, hw_fire_watch_name,
                     hw_fire_watch_contact, hw_precautions, wah_controls,
                     declaration_name, declaration_date, status,
                     status_updated_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "si" . str_repeat("s", 18),
            $permit_number, $subcontractor_id, $company_name, $company_contact,
            $supervisor_name, $supervisor_contact,
            $division, $division_address, $work_category,
            $cat_project, $cat_work_location, $cat_description,
            $hw_work_type, $hw_work_type_other, $hw_fire_watch_name,
            $hw_fire_watch_contact, $hw_precautions_str, $wah_controls_json,
            $declaration_name, $declaration_date
        );
        $stmt->execute();
        $permit_id = $conn->insert_id;
        $stmt->close();
        $log_reason = 'Submitted';
    }

    /* ---------- Tasks (General only) ---------- */
    if ($permit_category === 'normal') {
        $t = $conn->prepare("INSERT INTO service_provider_tasks (permit_id, task_description, hazard_control) VALUES (?,?,?)");
        foreach ($clean_tasks as $pair) {
            $t->bind_param("iss", $permit_id, $pair[0], $pair[1]);
            $t->execute();
        }
        $t->close();
    }

    /* ---------- Checklist ---------- */
    $c = $conn->prepare("INSERT INTO service_provider_checklist (permit_id, item_index, answer) VALUES (?,?,?)");
    foreach ($checklist_answers as $idx => $ans) {
        $c->bind_param("iis", $permit_id, $idx, $ans);
        $c->execute();
    }
    $c->close();

    /* ---------- PPE (already filtered by category whitelist) ---------- */
    if (count($ppe_selected) > 0) {
        $p = $conn->prepare("INSERT INTO service_provider_ppe (permit_id, ppe_item) VALUES (?,?)");
        foreach ($ppe_selected as $ppeItem) {
            $ppeItem = trim($ppeItem);
            if ($ppeItem === '') continue;
            $p->bind_param("is", $permit_id, $ppeItem);
            $p->execute();
        }
        $p->close();
    }

    /* ---------- Workers ---------- */
    $w = $conn->prepare("INSERT INTO service_provider_workers (permit_id, worker_name, worker_nic) VALUES (?,?,?)");
    foreach ($workers_name as $i => $wName) {
        $wName = trim($wName);
        $wNic  = trim($workers_nic[$i] ?? '');
        if ($wName === '' || $wNic === '') continue;
        $w->bind_param("iss", $permit_id, $wName, $wNic);
        $w->execute();
    }
    $w->close();

    /* ---------- Hot Work child tables ---------- */
    if ($permit_category === 'hot_work') {
        $s = $conn->prepare("INSERT INTO service_provider_hot_work_types (permit_id, work_type) VALUES (?,?)");
        foreach ($hw_types_clean as $wt) { $s->bind_param("is", $permit_id, $wt); $s->execute(); }
        $s->close();

        $s = $conn->prepare("INSERT INTO service_provider_hot_work_precautions (permit_id, precaution_text) VALUES (?,?)");
        foreach ($hw_precautions_list as $pt) { $s->bind_param("is", $permit_id, $pt); $s->execute(); }
        $s->close();
    }

    /* ---------- WAH child table ---------- */
    if ($permit_category === 'work_at_height') {
        $s = $conn->prepare("INSERT INTO service_provider_wah_controls (permit_id, control_index, status) VALUES (?,?,?)");
        foreach ($wah_controls_arr as $idx => $ans) {
            if ($ans === '') continue;
            $s->bind_param("iis", $permit_id, $idx, $ans);
            $s->execute();
        }
        $s->close();
    }

    /* ---------- Status log ---------- */
    $lg = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by) VALUES (?,?,?,?,?)");
    $lg->bind_param("issss", $permit_id, $old_status, $status, $log_reason, $changed_by);
    $lg->execute();
    $lg->close();

    $conn->commit();

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $x) {}
    echo "Database Error: " . $e->getMessage();
    $conn->close();
    exit();
}

/* No mail, no Third Party. Permit is now 'Pending' for the Admin. */
echo "Success";
$conn->close();