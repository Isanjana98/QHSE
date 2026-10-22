<?php
/* =====================================================
   VIEW SERVICE PROVIDER PERMIT
   UI mirrors the Service Provider Permit FORM (read-only, filled).
   + Supervisor "Edit Permit" button (until Admin approves)
   + Admin Approve / Reject with confirmation + success popups (AJAX)
   NOTE: Third Party approval has been removed. Every permit
         (General / Hot Work / Work At Height) goes straight to Admin.
===================================================== */
session_start();

$is_admin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$is_supervisor = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;

if (!$is_admin && !$is_supervisor) {
    header("Location: index.php");
    exit();
}

/* File name of the permit FORM page (the one that accepts ?edit_id=) */
$edit_form_url = 'service_provider_permit.php';

/* Expiry rules applied when Admin approves a permit */
$tp_permit_hours     = 12;  // Hot Work / Work at Height
$normal_permit_days  = 6;   // General permits

/* Do not throw exceptions on SQL errors (so fallbacks below can work) */
mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

$permit_id = intval($_GET['id'] ?? 0);
if ($permit_id <= 0) {
    die("Invalid or missing permit ID.");
}

function json_out($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit();
}

/* Statuses the Admin can approve / reject from
   (legacy Third Party statuses kept so older permits are not stuck) */
$ADMIN_ACTIONABLE_SQL = "LOWER(status) IN ('pending','pending review','pending third party','third party approved')";

/* =========================================================
   HANDLE COMPLETE WORK ACTION
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_work') {
    if (!$is_admin) {
        json_out(['status' => 'error', 'message' => 'Only admin can complete this work.']);
    }

    $upd = $conn->prepare("UPDATE service_provider_permits SET status = 'Complete' WHERE permit_id = ? AND status = 'Approved'");
    if ($upd) {
        $upd->bind_param("i", $permit_id);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();
        if ($changed < 1) {
            json_out(['status' => 'error', 'message' => 'Work can only be completed after the Admin has approved the permit.']);
        }
        json_out(['status' => 'success']);
    }
    json_out(['status' => 'error', 'message' => 'Database update failed.']);
}

/* =========================================================
   HANDLE MULTIPLE IMAGES UPLOAD (UP TO 5+ IMAGES)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    $uploader_role = $is_admin ? 'admin' : ($is_supervisor ? 'supervisor' : '');

    if ($uploader_role === '') {
        header("Location: view_service_provider.php?id=" . $permit_id . "&upload=error");
        exit();
    }

    $sc = $conn->prepare("SELECT status FROM service_provider_permits WHERE permit_id = ?");
    $sc->bind_param("i", $permit_id);
    $sc->execute();
    $sc_row = $sc->get_result()->fetch_assoc();
    $sc->close();
    if (!$sc_row || strtolower(trim($sc_row['status'])) !== 'approved') {
        header("Location: view_service_provider.php?id=" . $permit_id . "&upload=error");
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

            $dest_path = s3_store_upload($fileTmpPath, $fileName, 'permits', 'sp_' . $permit_id);

            if ($dest_path) {
                $ins = $conn->prepare("INSERT INTO permit_images (permit_type, permit_id, image_path, uploaded_by) VALUES ('Service Provider', ?, ?, ?)");
                if ($ins) {
                    $ins->bind_param("iss", $permit_id, $dest_path, $uploader_role);
                    $ins->execute();
                    $ins->close();
                }
                $anyUploaded = true;
                $lastPath = $dest_path;
            }
        }

        if ($anyUploaded) {
            $col = ($uploader_role === 'admin') ? 'admin_work_image' : 'supervisor_work_image';
            $upd = @$conn->prepare("UPDATE service_provider_permits SET `$col` = ? WHERE permit_id = ?");
            if ($upd) {
                $upd->bind_param("si", $lastPath, $permit_id);
                $upd->execute();
                $upd->close();
            }

            header("Location: view_service_provider.php?id=" . $permit_id . "&upload=success");
            exit();
        }
    }

    header("Location: view_service_provider.php?id=" . $permit_id . "&upload=error");
    exit();
}

/* =========================================================
   HANDLE APPROVE (AJAX / JSON)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve') {
    if (!$is_admin) {
        json_out(['status' => 'error', 'message' => 'Only an admin can approve this permit.']);
    }

    $cq = $conn->prepare("SELECT work_category, status FROM service_provider_permits WHERE permit_id = ?");
    $cq->bind_param("i", $permit_id);
    $cq->execute();
    $crow = $cq->get_result()->fetch_assoc();
    $cq->close();

    if (!$crow) {
        json_out(['status' => 'error', 'message' => 'Permit not found.']);
    }

    $cat = strtolower(trim($crow['work_category'] ?? 'normal'));
    if ($cat === 'hot_work' || $cat === 'work_at_height') {
        $expiry_sql = "DATE_ADD(NOW(), INTERVAL " . intval($tp_permit_hours) . " HOUR)";
    } else {
        $expiry_sql = "DATE_ADD(NOW(), INTERVAL " . intval($normal_permit_days) . " DAY)";
    }

    $allowed = $ADMIN_ACTIONABLE_SQL;

    $stmt = $conn->prepare("UPDATE service_provider_permits
        SET status = 'Approved', expiry_date = $expiry_sql, remaining_seconds = 0,
            rejection_reason = NULL, status_updated_at = NOW()
        WHERE permit_id = ? AND $allowed");

    if (!$stmt) {
        $stmt = $conn->prepare("UPDATE service_provider_permits
            SET status = 'Approved', expiry_date = $expiry_sql, rejection_reason = NULL
            WHERE permit_id = ? AND $allowed");
    }

    if (!$stmt) {
        json_out(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }

    $stmt->bind_param("i", $permit_id);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if (!$ok) {
        json_out(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
    if ($affected < 1) {
        json_out(['status' => 'error', 'message' => 'This permit cannot be approved in its current status.']);
    }
    json_out(['status' => 'success']);
}

/* =========================================================
   HANDLE REJECT (AJAX / JSON)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject') {
    if (!$is_admin) {
        json_out(['status' => 'error', 'message' => 'Only an admin can reject this permit.']);
    }

    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        json_out(['status' => 'error', 'message' => 'Rejection reason is required.']);
    }

    $old_status_stmt = $conn->prepare("SELECT status FROM service_provider_permits WHERE permit_id = ?");
    $old_status_stmt->bind_param("i", $permit_id);
    $old_status_stmt->execute();
    $old_status_row = $old_status_stmt->get_result()->fetch_assoc();
    $old_status_stmt->close();

    $rejected_by = $_SESSION['admin_name'] ?? 'Administrator';
    $allowed = $ADMIN_ACTIONABLE_SQL;

    $stmt = $conn->prepare("UPDATE service_provider_permits
        SET status = 'Rejected', rejection_reason = ?, rejected_by = ?, status_updated_at = NOW()
        WHERE permit_id = ? AND $allowed");
    if ($stmt) {
        $stmt->bind_param("ssi", $reason, $rejected_by, $permit_id);
    } else {
        $stmt = $conn->prepare("UPDATE service_provider_permits
            SET status = 'Rejected', rejection_reason = ?, rejected_by = ?
            WHERE permit_id = ? AND $allowed");
        if ($stmt) {
            $stmt->bind_param("ssi", $reason, $rejected_by, $permit_id);
        } else {
            $stmt = $conn->prepare("UPDATE service_provider_permits
                SET status = 'Rejected', rejection_reason = ?
                WHERE permit_id = ? AND $allowed");
            if ($stmt) {
                $stmt->bind_param("si", $reason, $permit_id);
            }
        }
    }

    if (!$stmt) {
        json_out(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }

    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if (!$ok) {
        json_out(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
    if ($affected < 1) {
        json_out(['status' => 'error', 'message' => 'This permit cannot be rejected in its current status.']);
    }

    $old_status = $old_status_row['status'] ?? 'Pending';
    $new_status = 'Rejected';
    $changed_by = 'admin:' . $rejected_by;
    $log = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if ($log) {
        $log->bind_param("issss", $permit_id, $old_status, $new_status, $reason, $changed_by);
        $log->execute();
        $log->close();
    }
    json_out(['status' => 'success']);
}

/* =====================================================
   FETCH PERMIT MAIN DATA
===================================================== */
$stmt = $conn->prepare("SELECT * FROM service_provider_permits WHERE permit_id = ?");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
    die("Service Provider Permit not found.");
}

$reason_history = [];
$history_stmt = $conn->prepare("SELECT new_status, reason, changed_by, changed_at
    FROM permit_status_log
    WHERE permit_id = ? AND (LOWER(new_status) LIKE '%reject%' OR LOWER(new_status) LIKE '%hold%')
    ORDER BY changed_at ASC, log_id ASC");
if ($history_stmt) {
    $history_stmt->bind_param("i", $permit_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();
    while ($history_row = $history_result->fetch_assoc()) {
        if (trim((string)($history_row['reason'] ?? '')) !== '') {
            $history_row['event_type'] = stripos($history_row['new_status'], 'hold') !== false ? 'On Hold' : 'Rejected';
            $reason_history[] = $history_row;
        }
    }
    $history_stmt->close();
}

$current_reason = '';
$current_reason_type = '';
if (strpos(strtolower(trim($permit['status'] ?? '')), 'reject') !== false) {
    $current_reason = trim((string)($permit['rejection_reason'] ?? ''));
    $current_reason_type = 'Rejected';
} elseif (in_array(strtolower(trim($permit['status'] ?? '')), ['hold', 'on hold'], true)) {
    $current_reason = trim((string)($permit['hold_reason'] ?? ''));
    $current_reason_type = 'On Hold';
}
if ($current_reason !== '') {
    $current_reason_by = $current_reason_type === 'Rejected'
        ? ($permit['rejected_by'] ?? 'Administrator')
        : 'Administrator';
    $current_reason_is_logged = false;
    foreach ($reason_history as $history_row) {
        if ($history_row['event_type'] === $current_reason_type && $history_row['reason'] === $current_reason) {
            $current_reason_is_logged = true;
            break;
        }
    }
    if (!$current_reason_is_logged) {
        $reason_history[] = [
            'event_type' => $current_reason_type,
            'reason' => $current_reason,
            'changed_by' => $current_reason_by,
            'changed_at' => $permit['status_updated_at'] ?? ($permit['updated_at'] ?? $permit['created_at'] ?? '')
        ];
        usort($reason_history, function ($a, $b) {
            return strcmp((string)$a['changed_at'], (string)$b['changed_at']);
        });
    }
}

$work_category = strtolower(trim($permit['work_category'] ?? 'normal'));
$is_hot_work   = ($work_category === 'hot_work');
$is_wah        = ($work_category === 'work_at_height');
$is_normal     = (!$is_hot_work && !$is_wah);
$status        = strtolower(trim($permit['status'] ?? 'pending'));

$is_complete = ($status === 'complete');
$is_approved = ($status === 'approved');

$can_admin_act = false;
if ($is_admin && !$is_complete) {
    $can_admin_act = in_array($status, ['pending', 'pending review', 'pending third party', 'third party approved'], true);
}

$can_supervisor_edit = false;
if ($is_supervisor) {
    $can_supervisor_edit = in_array($status, ['pending', 'pending third party', 'third party approved'], true);
}
$can_supervisor_resubmit = $is_supervisor && strpos($status, 'reject') !== false;

/* Plain escaped value for read-only inputs */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Short preview of long text (full text stays in the tooltip) */
function short_text($text, $max = 70) {
    $text = trim((string)$text);
    return (mb_strlen($text) > $max) ? mb_substr($text, 0, $max) . '…' : $text;
}

/* Child table data fetches */
$tasks_list = [];
if ($is_normal) {
    $t = $conn->prepare("SELECT task_description, hazard_control FROM service_provider_tasks WHERE permit_id = ?");
    if ($t) {
        $t->bind_param("i", $permit_id);
        $t->execute();
        $tRes = $t->get_result();
        while ($row = $tRes->fetch_assoc()) { $tasks_list[] = $row; }
        $t->close();
    }
}

$checklist_data = [];
$c = $conn->prepare("SELECT item_index, answer FROM service_provider_checklist WHERE permit_id = ?");
if ($c) {
    $c->bind_param("i", $permit_id);
    $c->execute();
    $cRes = $c->get_result();
    while ($row = $cRes->fetch_assoc()) { $checklist_data[(int)$row['item_index']] = $row['answer']; }
    $c->close();
}

$ppe_selected = [];
$p = $conn->prepare("SELECT ppe_item FROM service_provider_ppe WHERE permit_id = ?");
if ($p) {
    $p->bind_param("i", $permit_id);
    $p->execute();
    $pRes = $p->get_result();
    while ($row = $pRes->fetch_assoc()) { $ppe_selected[] = $row['ppe_item']; }
    $p->close();
}

$workers_list = [];
$w = $conn->prepare("SELECT worker_name, worker_nic FROM service_provider_workers WHERE permit_id = ?");
if ($w) {
    $w->bind_param("i", $permit_id);
    $w->execute();
    $wRes = $w->get_result();
    while ($row = $wRes->fetch_assoc()) { $workers_list[] = ['name' => $row['worker_name'], 'nic' => $row['worker_nic']]; }
    $w->close();
}

$hw_types_list = [];
$hw_precautions_list = [];
if ($is_hot_work) {
    $s = $conn->prepare("SELECT work_type FROM service_provider_hot_work_types WHERE permit_id = ?");
    if ($s) {
        $s->bind_param("i", $permit_id);
        $s->execute();
        $sRes = $s->get_result();
        while ($row = $sRes->fetch_assoc()) { $hw_types_list[] = $row['work_type']; }
        $s->close();
    }

    $s = $conn->prepare("SELECT precaution_text FROM service_provider_hot_work_precautions WHERE permit_id = ?");
    if ($s) {
        $s->bind_param("i", $permit_id);
        $s->execute();
        $sRes = $s->get_result();
        while ($row = $sRes->fetch_assoc()) { $hw_precautions_list[] = $row['precaution_text']; }
        $s->close();
    }

    // Fallback: older permits that stored work types as one text column
    if (empty($hw_types_list) && !empty($permit['hw_work_type'])) {
        $hw_types_list = array_map('trim', explode(',', $permit['hw_work_type']));
    }
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
            $sRes = $s->get_result();
            while ($row = $sRes->fetch_assoc()) { $wah_controls[(int)$row['control_index']] = $row['status']; }
            $s->close();
        }
    }
}

/* Fetch Images */
$division_images = [];
$admin_images = [];
$imgStmt = $conn->prepare("SELECT image_path, uploaded_by FROM permit_images WHERE permit_id = ? ORDER BY image_id ASC");
if ($imgStmt) {
    $imgStmt->bind_param("i", $permit_id);
    $imgStmt->execute();
    $imgRes = $imgStmt->get_result();
    while ($imgRow = $imgRes->fetch_assoc()) {
        // media_url() yields "media.php?p=..." for S3-backed rows and
        // passes legacy local paths straight through unchanged.
        $path = media_url($imgRow['image_path'] ?? '');
        if ($path !== '') {
            if (!preg_match('/^(http:\/\/|https:\/\/|\/|\.\/|media\.php)/', $path)) {
                $path = './' . $path;
            }
            $uploader = strtolower(trim($imgRow['uploaded_by'] ?? ''));
            if ($uploader === 'admin') {
                $admin_images[] = $path;
            } else {
                $division_images[] = $path;
            }
        }
    }
    $imgStmt->close();
}

$can_supervisor_upload = ($is_supervisor && $is_approved && !$is_complete);
$can_admin_upload = ($is_admin && $is_approved && !$is_complete);

/* ---------- Same lists as the permit FORM ---------- */
$checklist_items = [
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

$wah_control_items = [
    1  => "Health evaluation of persons going at height is done.",
    2  => "Safe means of access / egress provided.",
    3  => "Edge protection provided wherever there is falling hazards.",
    4  => "Fall prevention equipment used to access / egress work platform.",
    5  => "Fall prevention equipment used are adequate and in good condition.",
    6  => "Anchorage / Lifeline installed and inspected.",
    7  => "Travel restraint system used to safeguard persons from falling hazards.",
    8  => "All persons subjected to falling hazards are equipped with Personal Fall Arrest System .",
    9  => "Electrical power and/or other energy is isolated (if working near/on it).",
    10 => "Area under the working at height is barricaded.",
    11 => "All persons subjected to falling hazards are adequately trained to perform work at heights.",
    12 => "Fall Protection Plan & Risk Assessment conducted and communicated.",
    13 => "Emergency Response Procedure for WAH activities developed and communicated to workers."
];

$standard_hw_types = ["Welding", "Cutting", "Grinding", "Brazing", "Torching"];

$hw_precautions_group1 = [
    "All floor and wall openings within 35 foot must be covered to prevent transmission of sparks.",
    "Smoke detectors must be isolated in the work area and procedures followed.",
    "Barricades, warning signs/spark/flash screens must be provided and utilised.",
    "All combustible materials located within 35 foot must be removed or made safe with non-combustible curtains, metal guards or flame proof covers.",
    "Work area, trenches, pits, etc. must be clear of flammable liquids, gases, or vapours.",
    "The person carrying out the hot work must be trained in using the Fire Extinguishers/Hose Reel and in raising/sounding the Alarm."
];

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

$common_ppes = ["Hard Hat", "Goggles", "Safety Shoes", "High Visibility Vest"];
$hw_ppe_groups = [
    "Welding Work"  => ["Welding Gloves", "Face Shield", "Fire Protective Clothes"],
    "Grinding Work" => ["Dotted Gloves"]
];

/* Declaration date */
$decl_raw = $permit['declaration_date'] ?? ($permit['created_at'] ?? '');
$decl_date = $decl_raw ? date('Y-m-d', strtotime($decl_raw)) : '';

$page_title_map = ['hot_work' => 'Hot Work Permit', 'work_at_height' => 'Work At Height Permit'];
$page_title = $page_title_map[$work_category] ?? 'General Permit';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?> - <?= e($permit['permit_number'] ?? '') ?></title>

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
.btn-sm, .btn-custom { border-radius: 30px !important; }
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
.form-control[readonly], .form-control.bg-light{ background:var(--surface-bg) !important; color:var(--text-muted); }
.form-floating > .form-control{ padding-top:1.625rem; padding-bottom:.625rem; }
.input-group-text{ background:var(--surface-bg); border:1px solid var(--border-color); border-radius:8px 0 0 8px !important; color:var(--text-muted); padding: 0 10px; font-size: 13px; }
.table{ border-radius:8px; overflow:hidden; font-size:12px; background:rgba(255,255,255,.6); }
.table thead th{ background:linear-gradient(120deg,var(--brand-blue-dark),var(--brand-blue)) !important; color:white !important; font-size:11px; }
.table td, .table th{ padding:6px 8px; vertical-align:middle; border-color:var(--border-color); }
.form-check { background: rgba(255, 255, 255, 0.7); border: 1px solid var(--border-color); border-radius: 8px; padding: 6px 10px 6px 32px !important; margin-bottom: 6px; }
.form-check-input { width: 16px; height: 16px; margin-top: 0.15em; margin-left: -22px; border: 2px solid var(--border-color); }
.form-check-input:checked{ background-color:var(--brand-blue); border-color:var(--brand-blue); }
/* read-only view: keep checked/unchecked controls fully visible */
.form-check-input:disabled{ opacity:1; }
.form-check-input:disabled ~ .form-check-label{ opacity:1; }
.table .form-check-input{ margin-left:0 !important; float:none; }

/* Category cards (selected one highlighted, others dimmed) */
.category-choice{ display:flex; align-items:center; gap:10px; border:2px solid var(--border-color); border-radius:12px; padding:14px 16px; background:#fff; height:100%; opacity:.45; }
.category-choice .cat-icon{ font-size:22px; }
.category-choice .cat-text{ font-weight:700; font-size:13.5px; }
.category-choice.active{ opacity:1; }
.category-choice.normal.active{ border-color:var(--brand-blue); background:var(--brand-blue-light); box-shadow:0 0 15px rgba(255,122,0,.25); }
.category-choice.normal .cat-icon{ color:var(--brand-blue-dark); }
.category-choice.hw.active{ border-color:var(--hw-color); background:var(--hw-color-light); box-shadow:0 0 15px rgba(225,29,72,.25); }
.category-choice.hw .cat-icon{ color:var(--hw-color-dark); }
.category-choice.wah.active{ border-color:var(--wah-color); background:var(--wah-color-light); box-shadow:0 0 15px rgba(217,119,6,.25); }
.category-choice.wah .cat-icon{ color:var(--wah-color-dark); }

.status-badge{ background:#fff; color:var(--text-main); font-weight:800; text-transform:uppercase; font-size:11px; padding:6px 14px; border-radius:30px; border:1px solid var(--border-color); }
.btn-submit-custom { background: linear-gradient(135deg, var(--btn-green) 0%, var(--btn-green-dark) 100%) !important; color:#fff !important; border:none; font-size:13px; padding:8px 20px; }
.img-thumb{ width:80px; height:80px; object-fit:cover; }
</style>
</head>
<body>

<div class="container my-2">
<div class="card">

<h2 class="text-center permit-main-header fw-bold">
    <i class="bi bi-file-earmark-text-fill"></i> <?= e($page_title) ?>
</h2>
<p class="text-center text-muted mb-2" style="font-size: 11.5px;">Hayleys Fentons QHSE Safety Management System</p>

<!-- Status + Edit button -->
<div class="d-flex justify-content-end align-items-center gap-2 mb-2">
    <?php if ($can_supervisor_edit): ?>
        <a href="#" onclick="handleEditClick(event, '<?= e($edit_form_url) ?>?edit_id=<?= $permit_id ?>')" class="btn btn-sm btn-outline-warning fw-bold px-3" style="color:#B45309;">
            <i class="bi bi-pencil-square"></i> Edit Permit
        </a>
    <?php endif; ?>
    <span class="status-badge"><?= e(strtoupper($status)) ?></span>
</div>

<?php if (!empty($reason_history)): ?>
<div class="mb-3">
    <h6 class="fw-bold mb-2"><i class="bi bi-clock-history me-1"></i> Rejection / Hold History</h6>
    <?php foreach ($reason_history as $history_row):
        $is_hold_reason = ($history_row['event_type'] === 'On Hold');
        $history_date = !empty($history_row['changed_at']) ? date('Y-m-d H:i', strtotime($history_row['changed_at'])) : 'Date unavailable';
    ?>
    <div class="alert <?= $is_hold_reason ? 'alert-warning' : 'alert-danger' ?> py-2 px-3 mb-2">
        <div class="d-flex justify-content-between flex-wrap gap-1">
            <strong><?= e($history_row['event_type']) ?></strong>
            <small><?= e($history_date) ?></small>
        </div>
        <div class="small mt-1"><strong>Reason:</strong> <?= e($history_row['reason']) ?></div>
        <?php if (!empty($history_row['changed_by'])): ?>
        <div class="small mt-1">By: <?= e($history_row['changed_by']) ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($can_supervisor_resubmit): ?>
<div class="text-end mb-3">
    <a href="<?= e($edit_form_url) ?>?edit_id=<?= $permit_id ?>" onclick="handleEditClick(event, '<?= e($edit_form_url) ?>?edit_id=<?= $permit_id ?>', 'Loading Resubmission Form...', 'Please wait while the rejected permit is opened for resubmission.')" class="btn btn-warning fw-bold">
        <i class="bi bi-arrow-repeat"></i> Resubmit Permit
    </a>
</div>
<?php endif; ?>

<?php if ($can_supervisor_edit): ?>
<div class="alert alert-info d-flex align-items-center mb-3 py-2" style="font-size:12.5px;">
    <i class="bi bi-pencil-square me-2"></i>
    <div>This permit is waiting for Admin review. You can still edit it until the Admin approves.</div>
</div>
<?php endif; ?>

<!-- PERMIT NUMBER -->
<div class="row g-2 mb-2 justify-content-start">
    <div class="col-md-6 text-start">
        <label class="fw-bold mb-1" style="color:var(--text-muted); font-size:14px;">Permit Number</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input type="text" class="form-control bg-light fw-bold" value="<?= e($permit['permit_number'] ?? '') ?>" readonly>
        </div>
    </div>
</div>

<!-- SUBCONTRACTOR DETAILS -->
<h5 class="section-title"><i class="bi bi-briefcase-fill"></i> Subcontractor Details</h5>

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Company Name" value="<?= e($permit['company_name'] ?? '') ?>" readonly>
            <label>Company Name</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Company Address" value="<?= e($permit['company_address'] ?? '') ?>" readonly>
            <label>Company Address</label>
        </div>
    </div>
</div>
<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Division" value="<?= e($permit['company_division'] ?? '') ?>" readonly>
            <label>Division</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Contact Number" value="<?= e($permit['contact_number'] ?? '') ?>" readonly>
            <label>Contact Number</label>
        </div>
    </div>
</div>

<!-- SUPERVISOR DETAILS -->
<h5 class="section-title"><i class="bi bi-person-badge-fill"></i> Supervisor Details</h5>

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Supervisor Name" value="<?= e($permit['supervisor_name'] ?? '') ?>" readonly>
            <label>Supervisor Name</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Contact Number" value="<?= e($permit['supervisor_contact'] ?? '') ?>" readonly>
            <label>Contact Number</label>
        </div>
    </div>
</div>
<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Division" value="<?= e($permit['division'] ?? '') ?>" readonly>
            <label>Division</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Division Address" value="<?= e($permit['division_address'] ?? '') ?>" readonly>
            <label>Division Address</label>
        </div>
    </div>
</div>

<!-- JOB SAFETY INDUCTION CHECKLIST -->
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
<?php foreach($checklist_items as $k => $item):
    $ans = $checklist_data[$k] ?? '';
    if ($ans === 'N/A') $ans = 'NA';
?>
<tr>
    <td><?= $k + 1 ?></td>
    <td><?= e($item) ?></td>
    <td class="text-center"><input class="form-check-input" type="radio" disabled <?= ($ans === 'Yes') ? 'checked' : '' ?>></td>
    <td class="text-center"><input class="form-check-input" type="radio" disabled <?= ($ans === 'No') ? 'checked' : '' ?>></td>
    <td class="text-center"><input class="form-check-input" type="radio" disabled <?= ($ans === 'NA') ? 'checked' : '' ?>></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- SUPERVISOR DECLARATION -->
<h5 class="section-title"><i class="bi bi-patch-check-fill"></i> Supervisor Declaration</h5>

<div class="alert alert-warning mt-2 mb-2 shadow-sm" style="font-size: 11.5px;">
    I confirm that I have verified the above information and ensured that necessary safety precautions have been taken.
</div>

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control bg-light" placeholder="Name & Designation" value="<?= e(($permit['supervisor_name'] ?? '') . ' - Divisional Supervisor') ?>" readonly>
            <label>Name & Designation</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="date" class="form-control bg-light" value="<?= e($decl_date) ?>" readonly>
            <label>Date</label>
        </div>
    </div>
</div>

<!-- WORKERS DECLARATION -->
<h5 class="section-title"><i class="bi bi-people-fill"></i> Workers Declaration</h5>

<div class="table-responsive mt-1">
<table class="table table-bordered mb-1">
<thead>
<tr>
    <th width="6%">#</th>
    <th width="50%">Name</th>
    <th width="44%">NIC Number</th>
</tr>
</thead>
<tbody>
<?php if (!empty($workers_list)): foreach($workers_list as $i => $wk): ?>
<tr>
    <td><?= $i + 1 ?></td>
    <td><input type="text" class="form-control" value="<?= e($wk['name']) ?>" readonly></td>
    <td><input type="text" class="form-control" value="<?= e($wk['nic']) ?>" readonly></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="3" class="text-center text-muted">No workers recorded</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- TASKS (General) -->
<?php if ($is_normal): ?>
<h5 class="section-title"><i class="bi bi-tools"></i> Tasks & Hazard Controls</h5>
<div class="table-responsive mt-1">
<table class="table table-bordered mb-1">
<thead>
<tr>
    <th width="6%">#</th>
    <th width="47%">Task</th>
    <th width="47%">Hazard Control</th>
</tr>
</thead>
<tbody>
<?php if (!empty($tasks_list)): foreach($tasks_list as $i => $tk): ?>
<tr>
    <td><?= $i + 1 ?></td>
    <td><input type="text" class="form-control" value="<?= e($tk['task_description']) ?>" readonly></td>
    <td><input type="text" class="form-control" value="<?= e($tk['hazard_control']) ?>" readonly></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="3" class="text-center text-muted">No tasks recorded</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<!-- ADDITIONAL WORK DETAILS -->
<h5 class="section-title"><i class="bi bi-briefcase-fill"></i> Additional Work Details</h5>

<div class="row g-2">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Project Name" value="<?= e($permit['cat_project'] ?? '') ?>" readonly>
            <label>Project Name</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Work Location" value="<?= e($permit['cat_work_location'] ?? '') ?>" readonly>
            <label>Work Location</label>
        </div>
    </div>
</div>

<div class="mt-1">
    <label class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Description Of Work</label>
    <textarea rows="2" class="form-control" readonly><?= e($permit['cat_description'] ?? '') ?></textarea>
</div>

<!-- HOT WORK DETAILS -->
<?php if ($is_hot_work):
    $selected_types = [];
    foreach($hw_types_list as $wt){
        if($wt === 'Other') continue;
        $selected_types[] = $wt;
    }
    $checked_precautions = array_values(array_filter($hw_precautions_group2, function($v) use ($hw_precautions_list){
        return in_array($v, $hw_precautions_list, true);
    }));
?>
<h5 class="section-title hw"><i class="bi bi-fire"></i> Hot Work Details</h5>

<div class="mt-1">
    <label class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Type Of Work</label>
    <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
        <?php if (!empty($selected_types)): foreach($selected_types as $wType): ?>
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" disabled checked>
            <label class="form-check-label"><?= e($wType) ?></label>
        </div>
        <?php endforeach; else: ?>
        <span class="text-muted small fst-italic">No work type recorded.</span>
        <?php endif; ?>
    </div>
</div>

<div class="row g-2 mt-1">
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Fire Watch Name" value="<?= e($permit['hw_fire_watch_name'] ?? '') ?>" readonly>
            <label>Fire Watch Name</label>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-floating">
            <input type="text" class="form-control" placeholder="Fire Watch Contact" value="<?= e($permit['hw_fire_watch_contact'] ?? '') ?>" readonly>
            <label>Fire Watch Contact</label>
        </div>
    </div>
</div>

<div class="mt-2">
    <p class="fw-semibold mb-1" style="color:var(--text-muted); font-size:11.5px;">Safety precautions checked</p>
    <?php if (!empty($checked_precautions)): foreach($checked_precautions as $val): ?>
    <div class="form-check mb-1" title="<?= e($val) ?>">
        <input class="form-check-input" type="checkbox" disabled checked>
        <label class="form-check-label" style="font-size: 12px;"><?= e(short_text($val)) ?></label>
    </div>
    <?php endforeach; else: ?>
    <span class="text-muted small fst-italic">No precautions recorded.</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- WORK AT HEIGHT CONTROLS -->
<?php if ($is_wah): ?>
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
<?php foreach($wah_control_items as $no => $control):
    $ans = $wah_controls[$no] ?? '';
    if ($ans === 'NA') $ans = 'N/A';
?>
<tr>
    <td><b><?= $no ?>.</b> <?= e($control) ?></td>
    <td class="text-center"><input class="form-check-input" type="radio" disabled <?= ($ans === 'Yes') ? 'checked' : '' ?>></td>
    <td class="text-center"><input class="form-check-input" type="radio" disabled <?= ($ans === 'No') ? 'checked' : '' ?>></td>
    <td class="text-center"><input class="form-check-input" type="radio" disabled <?= ($ans === 'N/A') ? 'checked' : '' ?>></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<!-- PPE -->
<h5 class="section-title"><i class="bi bi-shield-fill-check"></i> Personal Protective Equipment (PPE)</h5>

<div class="row g-2 mt-1">
    <?php foreach($common_ppes as $p_item): ?>
    <div class="col-md-3">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" disabled <?= in_array($p_item, $ppe_selected, true) ? 'checked' : '' ?>>
            <label class="form-check-label"><?= e($p_item) ?></label>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($is_hot_work): foreach($hw_ppe_groups as $grpName => $grpItems): ?>
<p class="fw-semibold mb-1 mt-2" style="color:var(--hw-color-dark); font-size:11.5px;">
    <i class="bi bi-fire"></i> Hot Work PPE – <?= e($grpName) ?>
</p>
<div class="row g-2">
    <?php foreach($grpItems as $p_item): ?>
    <div class="col-md-3">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" disabled <?= in_array($p_item, $ppe_selected, true) ? 'checked' : '' ?>>
            <label class="form-check-label"><?= e($p_item) ?></label>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; endif; ?>

<?php if ($is_wah): ?>
<p class="fw-semibold mb-1 mt-2" style="color:var(--wah-color-dark); font-size:11.5px;">
    <i class="bi bi-building-up"></i> Work At Height PPE
</p>
<div class="row g-2">
    <div class="col-md-6">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" disabled <?= in_array("Full Body Harness", $ppe_selected, true) ? 'checked' : '' ?>>
            <label class="form-check-label">Full Body Harness <small class="text-muted">(working platform above 1.8m)</small></label>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- WORK PROGRESS IMAGES & COMPLETION -->
<h5 class="section-title"><i class="bi bi-images"></i> Work Progress Images & Completion</h5>

<?php if (!$is_approved && !$is_complete): ?>
<div class="alert alert-secondary py-2 mb-3" style="font-size:12px;">
    <i class="bi bi-lock-fill"></i> Image upload will be available after the Admin approves this permit.
</div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-6">
        <h6 class="fw-bold text-muted">Supervisor Uploaded Images</h6>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <?php if (!empty($division_images)): foreach($division_images as $img): ?>
                <a href="<?= e($img) ?>" class="img-modal-link" data-group="supervisor"><img src="<?= e($img) ?>" class="img-thumb rounded border shadow-sm"></a>
            <?php endforeach; else: ?>
                <p class="text-muted small fst-italic">No images uploaded yet by supervisor.</p>
            <?php endif; ?>
        </div>

        <?php if ($can_supervisor_upload && !$is_complete): ?>
        <form action="view_service_provider.php?id=<?= $permit_id ?>" method="POST" enctype="multipart/form-data" class="mt-2" onsubmit="showUploadLoader(this)">
            <input type="hidden" name="action" value="upload_image">
            <label class="form-label small fw-bold">Upload Progress Images (Up to 5+)</label>
            <input type="file" name="permit_images[]" class="form-control form-control-sm mb-2" accept="image/*" multiple required>
            <button type="submit" class="btn btn-sm btn-primary">Upload Images</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="col-md-6">
        <h6 class="fw-bold text-muted">Admin Uploaded Images</h6>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <?php if (!empty($admin_images)): foreach($admin_images as $img): ?>
                <a href="<?= e($img) ?>" class="img-modal-link" data-group="admin"><img src="<?= e($img) ?>" class="img-thumb rounded border shadow-sm"></a>
            <?php endforeach; else: ?>
                <p class="text-muted small fst-italic">No images uploaded yet by admin.</p>
            <?php endif; ?>
        </div>

        <?php if ($can_admin_upload && !$is_complete): ?>
        <form action="view_service_provider.php?id=<?= $permit_id ?>" method="POST" enctype="multipart/form-data" class="mt-2" onsubmit="showUploadLoader(this)">
            <input type="hidden" name="action" value="upload_image">
            <label class="form-label small fw-bold">Upload Verification Images (Up to 5+)</label>
            <input type="file" name="permit_images[]" class="form-control form-control-sm mb-2" accept="image/*" multiple required>
            <button type="submit" class="btn btn-sm btn-success">Upload Images</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_admin && $is_approved && count($division_images) > 0 && !$is_complete): ?>
<div class="text-center mt-3">
    <button type="button" class="btn btn-submit-custom btn-custom" onclick="completeWork()">
        <i class="bi bi-check-all"></i> Complete This Work
    </button>
</div>
<?php endif; ?>

<!-- ADMIN APPROVAL BUTTONS -->
<?php if ($can_admin_act && !$is_complete): ?>
<div class="text-center mt-4 d-flex justify-content-center gap-2">
    <button type="button" class="btn btn-submit-custom btn-custom" onclick="confirmApprove()"><i class="bi bi-check-lg"></i> Approve Permit</button>
    <button type="button" class="btn btn-danger btn-custom" onclick="confirmReject()"><i class="bi bi-x-lg"></i> Reject Permit</button>
</div>
<?php endif; ?>

</div>
</div>

<!-- Image Preview Modal -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-dark border-0">
      <div class="modal-header border-0 py-2">
        <span class="text-white-50 small" id="imgCounter"></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center p-2 position-relative">
        <button type="button" id="imgPrev" class="btn btn-light btn-sm rounded-circle position-absolute top-50 start-0 translate-middle-y ms-2" style="z-index:5;"><i class="bi bi-chevron-left"></i></button>
        <img id="imagePreviewTarget" src="" alt="Preview" class="img-fluid rounded" style="max-height:75vh;">
        <button type="button" id="imgNext" class="btn btn-light btn-sm rounded-circle position-absolute top-50 end-0 translate-middle-y me-2" style="z-index:5;"><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const modalEl = document.getElementById('imagePreviewModal');
    const modal = new bootstrap.Modal(modalEl);
    const target = document.getElementById('imagePreviewTarget');
    const counter = document.getElementById('imgCounter');
    let list = [], idx = 0;

    function show() {
        target.src = list[idx].href;
        counter.textContent = (idx + 1) + ' / ' + list.length;
        document.getElementById('imgPrev').style.display = list.length > 1 ? '' : 'none';
        document.getElementById('imgNext').style.display = list.length > 1 ? '' : 'none';
    }

    document.querySelectorAll('.img-modal-link').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            list = Array.from(document.querySelectorAll('.img-modal-link[data-group="' + a.dataset.group + '"]'));
            idx = list.indexOf(a);
            show();
            modal.show();
        });
    });

    document.getElementById('imgPrev').addEventListener('click', function () { idx = (idx - 1 + list.length) % list.length; show(); });
    document.getElementById('imgNext').addEventListener('click', function () { idx = (idx + 1) % list.length; show(); });
    document.addEventListener('keydown', function (e) {
        if (!modalEl.classList.contains('show') || list.length < 2) return;
        if (e.key === 'ArrowLeft') document.getElementById('imgPrev').click();
        if (e.key === 'ArrowRight') document.getElementById('imgNext').click();
    });
})();
</script>

<script>
const PERMIT_URL = 'view_service_provider.php?id=<?= $permit_id ?>';

/* Edit button: show loading popup, then go to the form */
function handleEditClick(event, url, title = 'Loading Form...', message = 'Please wait while the edit form is being loaded.') {
    event.preventDefault();
    Swal.fire({
        title: title,
        text: message,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => { Swal.showLoading(); }
    });
    setTimeout(() => { window.location.href = url; }, 500);
}

function showUploadLoader(form) {
    Swal.fire({
        title: 'Uploading Images...',
        text: 'Please wait while your images are being uploaded and saved.',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => { Swal.showLoading(); }
    });
    return true;
}

/* Send a POST action and always get a JSON result (or throw a readable error) */
function postAction(fields) {
    const formData = new FormData();
    Object.keys(fields).forEach(k => formData.append(k, fields[k]));

    return fetch(PERMIT_URL, { method: 'POST', body: formData })
        .then(res => res.text())
        .then(text => {
            let data;
            try { data = JSON.parse(text); }
            catch (e) { throw new Error('Unexpected server response. Please try again.'); }
            if (data.status !== 'success') {
                throw new Error(data.message || 'Action failed.');
            }
            return data;
        });
}

/* After a successful action: refresh the dashboard/tracker behind the modal, or this page */
function refreshAfterAction() {
    if (window.parent && window.parent !== window) {
        window.parent.location.reload();
    } else {
        location.reload();
    }
}

function completeWork() {
    Swal.fire({
        title: 'Complete Work?',
        text: 'Are you sure you want to mark this work as fully complete? Once completed, neither admin nor supervisor can make any changes.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Complete It!',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        preConfirm: () => {
            return postAction({ action: 'complete_work' })
                .catch(err => { Swal.showValidationMessage(err.message); });
        }
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            Swal.fire({
                icon: 'success',
                title: 'Work Completed!',
                text: 'Work status has been updated to Complete.',
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false
            }).then(refreshAfterAction);
        }
    });
}

function confirmApprove() {
    Swal.fire({
        title: 'Approve this permit?',
        text: 'The permit will be approved and its expiry time will start now.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#16803a',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Approve!',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        preConfirm: () => {
            return postAction({ action: 'approve' })
                .catch(err => { Swal.showValidationMessage(err.message); });
        }
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            Swal.fire({
                icon: 'success',
                title: 'Permit Approved!',
                text: 'The permit has been approved successfully.',
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false
            }).then(refreshAfterAction);
        }
    });
}

function confirmReject() {
    Swal.fire({
        title: 'Reject this permit?',
        input: 'textarea',
        inputLabel: 'Reason for rejection',
        inputPlaceholder: 'Enter the reason for rejection here...',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Reject',
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        preConfirm: (reason) => {
            if (!reason || reason.trim() === '') {
                Swal.showValidationMessage('Reason is required!');
                return false;
            }
            return postAction({ action: 'reject', reason: reason.trim() })
                .catch(err => { Swal.showValidationMessage(err.message); });
        }
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            Swal.fire({
                icon: 'success',
                title: 'Permit Rejected',
                text: 'The permit has been rejected and the reason has been saved.',
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false
            }).then(refreshAfterAction);
        }
    });
}
</script>

</body>
</html>
<?php $conn->close(); ?>