<?php
/* =====================================================================
   action_permit.php
   Third Party review + approve / reject for service_provider_permits

   - Mail link : action_permit.php?action=approve|reject&id=..&token=..
   - The page ALWAYS shows the LIVE permit details from the database, so if
     the supervisor edits the permit after the Third Party approval, the
     same mail link shows the edited details.
   - Approve -> 'Third Party Approved'   (supervisor gets the Edit option)
   - Reject  -> 'Third Party Rejected'   (supervisor can Resubmit)
   - Nothing is saved on GET. The confirm / reason popup posts by AJAX.
===================================================================== */

require_once __DIR__ . '/db_config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Get ID, Token and Action safely
$id     = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$token  = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$table         = "service_provider_permits";
$pk_column     = "permit_id";
$reason_column = "rejection_reason";

if ($id <= 0 || $token === '') {
    die("Invalid permit reference.");
}

// ---------------------------------------------------------------------
// Load the permit and verify the token from the e-mail link
// ---------------------------------------------------------------------
$stmt = $conn->prepare("SELECT * FROM `$table` WHERE `$pk_column` = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit || empty($permit['approval_token']) || !hash_equals($permit['approval_token'], $token)) {
    die("This approval link is invalid or has expired.");
}

$current_status = $permit['status'];
$permit_number  = $permit['permit_number'];
$tp_name        = $permit['tp_approved_by'] ?: $permit['tp_email'];

// =====================================================================
// 1. AJAX (POST): save Approve / Reject
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain');

    if ($current_status !== 'Pending Third Party') {
        echo "already";
        $conn->close();
        exit();
    }

    try {
        $conn->begin_transaction();

        if ($action === 'approve') {
            $new_status = "Third Party Approved";
            $stmt = $conn->prepare("UPDATE `$table`
                    SET status = ?, approved_by = ?, approved_at = NOW(),
                        `$reason_column` = NULL, status_updated_at = NOW()
                    WHERE `$pk_column` = ? AND status = 'Pending Third Party'");
            $stmt->bind_param("ssi", $new_status, $tp_name, $id);
            $log_reason = "Approved by Third Party";

        } elseif ($action === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                $conn->rollback();
                echo "error";
                $conn->close();
                exit();
            }
            $new_status = "Third Party Rejected";
            $stmt = $conn->prepare("UPDATE `$table`
                    SET status = ?, `$reason_column` = ?, status_updated_at = NOW()
                    WHERE `$pk_column` = ? AND status = 'Pending Third Party'");
            $stmt->bind_param("ssi", $new_status, $reason, $id);
            $log_reason = $reason;

        } else {
            $conn->rollback();
            echo "error";
            $conn->close();
            exit();
        }

        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed < 1) {
            $conn->rollback();
            echo "already";
            $conn->close();
            exit();
        }

        $changed_by = 'third_party:' . $tp_name;
        $lg = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by) VALUES (?,?,?,?,?)");
        $lg->bind_param("issss", $id, $current_status, $new_status, $log_reason, $changed_by);
        $lg->execute();
        $lg->close();

        $conn->commit();
        echo "success";

    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $x) {}
        error_log('action_permit error (permit_id ' . $id . '): ' . $e->getMessage());
        echo "error";
    }

    $conn->close();
    exit();
}

// =====================================================================
// 2. GET: live review page
// =====================================================================

// Company address is not stored on the permit row -> read it from subcontractors
$company_address = '';
if (!empty($permit['subcontractor_id'])) {
    $sa = $conn->prepare("SELECT company_address FROM subcontractors WHERE id = ?");
    $sa->bind_param("i", $permit['subcontractor_id']);
    $sa->execute();
    $sa_row = $sa->get_result()->fetch_assoc();
    $sa->close();
    $company_address = $sa_row['company_address'] ?? '';
}
$conn->close();

$is_pending = ($current_status === 'Pending Third Party');
$is_hot     = ($permit['work_category'] === 'hot_work');
$cat_title  = $is_hot ? 'Hot Work Permit' : 'Work At Height Permit';

$supervisor_line = trim(($permit['supervisor_name'] ?? '') . ' (' . ($permit['supervisor_contact'] ?? '') . ')', ' ()');
$firewatch_line  = trim(($permit['hw_fire_watch_name'] ?? '') . ' (' . ($permit['hw_fire_watch_contact'] ?? '') . ')', ' ()');

$auto_action = ($is_pending && in_array($action, ['approve', 'reject'], true)) ? $action : '';

function field($label, $value, $strong = false) {
    $v = trim((string)$value) !== '' ? h($value) : '<span style="color:#94A3B8;font-style:italic;">Not provided</span>';
    $extra = $strong ? 'font-weight:700;color:#FE7F08;' : '';
    return '<div class="field-box"><div class="field-label">' . h($label) . '</div><div class="field-value" style="' . $extra . '">' . $v . '</div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($cat_title) ?> Review</title>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#F1F5F9;color:#1E293B;margin:0;padding:10px;font-size:12px;}
.permit-container{max-width:550px;margin:0 auto;background:#fff;border-radius:8px;border:1px solid #E2E8F0;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.logo{text-align:center;margin-bottom:8px;} .logo img{max-width:130px;height:auto;}
h2.form-title{color:#FE7F08;font-size:15px;font-weight:800;text-align:center;margin:0 0 12px;text-transform:uppercase;border-bottom:2px solid #FE7F08;padding-bottom:5px;}
.section-heading{font-size:11px;font-weight:700;color:#FE7F08;border-bottom:1px solid #F1F5F9;padding-bottom:3px;margin:10px 0 6px;text-transform:uppercase;}
.field-box{margin-bottom:6px;} .field-label{font-size:10px;font-weight:600;color:#334155;margin-bottom:2px;}
.field-value{background:#F8FAFC;border:1px solid #CBD5E1;border-radius:4px;padding:4px 8px;font-size:11px;min-height:22px;display:flex;align-items:center;}
.row{display:flex;gap:8px;} .col{flex:1;}
.action-buttons{display:flex;gap:10px;margin-top:16px;border-top:1px solid #E2E8F0;padding-top:12px;}
.btn{flex:1;padding:9px 12px;font-size:12px;font-weight:700;border:none;border-radius:6px;cursor:pointer;color:#fff;}
.btn-approve{background:#198754;} .btn-approve:hover{background:#157347;}
.btn-reject{background:#DC3545;} .btn-reject:hover{background:#BB2D3B;}
.banner{margin-top:16px;padding:10px;border-radius:6px;text-align:center;font-weight:700;font-size:13px;background:#E2E8F0;}
.banner.ok{background:#D1E7DD;color:#0F5132;} .banner.no{background:#F8D7DA;color:#842029;}
</style>
</head>
<body>

<div class="permit-container">
    <div class="logo"><img src="assets/images/hayleys_logo.png" alt="Hayleys Logo" onerror="this.style.display='none'"></div>
    <h2 class="form-title"><?= h($cat_title) ?> Review</h2>

    <div class="section-heading">Project &amp; Permit Details</div>
    <?= field('Permit Number', $permit['permit_number'], true) ?>
    <?= field('Project Name', $permit['cat_project']) ?>
    <?= field('Work Location', $permit['cat_work_location']) ?>
    <?= field('Description', $permit['cat_description']) ?>
    <?php if ($is_hot): ?>
        <?= field('Type Of Work', $permit['hw_work_type']) ?>
    <?php endif; ?>
    <?php if (!empty($permit['updated_at'])): ?>
        <?= field('Last Updated', $permit['updated_at']) ?>
    <?php endif; ?>

    <div class="section-heading">Company &amp; Supervisor Details</div>
    <?= field('Company', $permit['company_name']) ?>
    <?= field('Address', $company_address) ?>
    <div class="row">
        <div class="col"><?= field('Division', $permit['division']) ?></div>
        <div class="col"><?= field('Contact Number', $permit['contact_number']) ?></div>
    </div>
    <div class="row">
        <div class="col"><?= field('Supervisor', $supervisor_line) ?></div>
        <?php if ($is_hot): ?><div class="col"><?= field('Fire Watch', $firewatch_line) ?></div><?php endif; ?>
    </div>

    <?php if ($is_pending): ?>
        <div class="action-buttons">
            <button type="button" class="btn btn-approve" onclick="handleAction('approve')">&#10003; Approve Permit</button>
            <button type="button" class="btn btn-reject" onclick="handleAction('reject')">&#10005; Reject Permit</button>
        </div>
    <?php elseif ($current_status === 'Third Party Approved' || $current_status === 'Approved'): ?>
        <div class="banner ok">This permit has been Approved.</div>
    <?php elseif ($current_status === 'Third Party Rejected' || $current_status === 'Rejected'): ?>
        <div class="banner no">This permit has been Rejected.</div>
    <?php else: ?>
        <div class="banner">This permit is no longer waiting for Third Party approval (status: <?= h($current_status) ?>).</div>
    <?php endif; ?>
</div>

<script>
const PERMIT_ID     = <?= (int)$id ?>;
const TOKEN         = <?= json_encode($token) ?>;
const PERMIT_NUMBER = <?= json_encode($permit_number) ?>;
const AUTO_ACTION   = <?= json_encode($auto_action) ?>;

function send(action, reason, onSuccess) {
    const body = new URLSearchParams({ id: PERMIT_ID, token: TOKEN, action: action, reason: reason || '' });

    Swal.fire({ title: 'Please wait...', allowOutsideClick: false, allowEscapeKey: false,
                showConfirmButton: false, didOpen: () => Swal.showLoading() });

    fetch('action_permit.php', { method: 'POST', body: body })
        .then(r => r.text())
        .then(data => {
            data = data.trim();
            if (data === 'success') {
                onSuccess();
            } else if (data === 'already') {
                Swal.fire({ icon: 'info', title: 'Already Actioned',
                            text: 'This permit has already been actioned.' })
                    .then(() => window.location.reload());
            } else {
                Swal.fire('Error!', 'Database update failed.', 'error');
            }
        })
        .catch(() => Swal.fire('Error!', 'Something went wrong. Please try again.', 'error'));
}

function handleAction(type) {
    if (type === 'approve') {
        Swal.fire({
            title: 'Approve this permit?',
            text: 'Permit No: ' + PERMIT_NUMBER,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Approve',
            confirmButtonColor: '#198754',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (!result.isConfirmed) return;
            send('approve', '', () => {
                Swal.fire({
                    icon: 'success',
                    title: 'Approved Successfully!',
                    text: 'Thank you. The permit has been approved successfully.'
                }).then(() => { window.location.href = 'index.php'; });
            });
        });

    } else {
        Swal.fire({
            title: 'Third Party Rejection Reason',
            input: 'textarea',
            inputPlaceholder: 'Type the reason for rejection here...',
            inputAttributes: { 'aria-label': 'Type your reason here' },
            showCancelButton: true,
            confirmButtonText: 'Submit Rejection',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (!result.isConfirmed) return;
            const reason = (result.value || '').trim();
            if (!reason) {
                Swal.fire('Error!', 'Rejection reason cannot be empty.', 'error');
                return;
            }
            send('reject', reason, () => {
                Swal.fire({
                    icon: 'success',
                    title: 'Rejected',
                    text: 'The permit has been rejected. The reason has been recorded.'
                }).then(() => { window.location.href = 'index.php'; });
            });
        });
    }
}

// Mail button clicked -> open the matching popup on top of the live details
document.addEventListener('DOMContentLoaded', function () {
    if (AUTO_ACTION) handleAction(AUTO_ACTION);
});
</script>
</body>
</html>