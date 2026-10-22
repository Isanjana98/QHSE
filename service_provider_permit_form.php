<?php
session_start();

// Database Connection
require_once __DIR__ . '/db_config.php';

// URL madhun ID kinva permit_number dynamically ghene
$permit_id = $_GET['id'] ?? $_GET['permit_id'] ?? 0;
$permit_no =$_GET['permit_number'] ?? '';

// AJAX dwara request aliyavars database update karnyasathi
if (isset($_POST['permit_number']) && isset($_POST['action'])) {
    $post_permit_number =$_POST['permit_number'];
    $action =$_POST['action']; // 'approve' kinva 'reject'
    
    if ($action === 'approve') {$status = 'Approved';
        $stmt =$conn->prepare("UPDATE service_provider_permits SET status = ? WHERE permit_number = ? OR permit_id = ?");
        $stmt->bind_param("ssi", $status, $post_permit_number,$post_permit_number);
    } else {
        $status = 'Rejected';
        $reason =$_POST['reason'] ?? '';
        $stmt =$conn->prepare("UPDATE service_provider_permits SET status = ?, reject_reason = ? WHERE permit_number = ? OR permit_id = ?");
        $stmt->bind_param("sssi", $status,$reason, $post_permit_number,$post_permit_number);
    }

    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
    
    $stmt->close();$conn->close();
    exit;
}

// Database madhun current status check karne
$current_status = 'Pending';$db_permit_number = 'SP-2026-0011'; // Default fallback

if (!empty($permit_id)) {
    $stmt_check =$conn->prepare("SELECT permit_number, status FROM service_provider_permits WHERE permit_id = ? OR permit_number = ?");
    $stmt_check->bind_param("ss", $permit_id,$permit_id);
    $stmt_check->execute();$stmt_check->bind_result($fetched_num,$db_status);
    if ($stmt_check->fetch()) {
        if (!empty($fetched_num)) { $db_permit_number =$fetched_num; }
        if (!empty($db_status)) { $current_status =$db_status; }
    }
    $stmt_check->close();
} elseif (!empty($permit_no)) {
    $stmt_check =$conn->prepare("SELECT permit_number, status FROM service_provider_permits WHERE permit_number = ?");
    $stmt_check->bind_param("s", $permit_no);
    $stmt_check->execute();$stmt_check->bind_result($fetched_num,$db_status);
    if ($stmt_check->fetch()) {
        if (!empty($fetched_num)) { $db_permit_number =$fetched_num; }
        if (!empty($db_status)) { $current_status =$db_status; }
    }
    $stmt_check->close();
}

$permit_number      =$db_permit_number;
$cat_project        = "ssssssssss";
$cat_work_location  = "sssssss";
$cat_description    = "sssssss";
$company_name       = "Apex Engineering Solutions (Pvt) Ltd";
$company_address    = "No. 45, Baseline Road, Colombo 09";
$company_division   = "MEP";
$company_contact    = "0771234567";
$supervisor_info    = "M. H. A. R. Silva (0763214567)";
$fire_watch_info    = "mithila (0768988758)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Permit Approval View</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background-color: #F1F5F9;
            color: #1E293B;
            margin: 0;
            padding: 10px;
            font-size: 12px;
        }
        .permit-container {
            max-width: 550px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .form-logo-wrapper {
            text-align: center;
            margin-bottom: 8px;
        }
        .form-logo-wrapper img {
            max-width: 130px;
            height: auto;
            display: inline-block;
        }
        h2.form-title {
            color: #FE7F08;
            font-size: 15px;
            font-weight: 800;
            text-align: center;
            margin-top: 0;
            margin-bottom: 12px;
            text-transform: uppercase;
            border-bottom: 2px solid #FE7F08;
            padding-bottom: 5px;
        }
        .section-heading {
            font-size: 11px;
            font-weight: 700;
            color: #FE7F08;
            border-bottom: 1px solid #F1F5F9;
            padding-bottom: 3px;
            margin: 10px 0 6px 0;
            text-transform: uppercase;
        }
        .field-box {
            margin-bottom: 6px;
        }
        .field-label {
            font-size: 10px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 2px;
        }
        .field-value {
            background-color: #F8FAFC;
            border: 1px solid #CBD5E1;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 11px;
            color: #1E293B;
            min-height: 22px;
            display: flex;
            align-items: center;
        }
        .row {
            display: flex;
            gap: 8px;
            margin-bottom: 6px;
        }
        .col {
            flex: 1;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            border-top: 1px solid #E2E8F0;
            padding-top: 12px;
        }
        .btn {
            flex: 1;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        .btn-approve {
            background-color: #198754;
            color: #ffffff;
        }
        .btn-approve:hover {
            background-color: #157347;
        }
        .btn-reject {
            background-color: #DC3545;
            color: #ffffff;
        }
        .btn-reject:hover {
            background-color: #BB2D3B;
        }
        .already-actioned {
            margin-top: 16px;
            border-top: 1px solid #E2E8F0;
            padding-top: 12px;
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            padding: 10px;
            border-radius: 6px;
        }
        .status-approved {
            background-color: #D1E7DD;
            color: #0F5132;
        }
        .status-rejected {
            background-color: #F8D7DA;
            color: #842029;
        }
    </style>
</head>
<body>

<div class="permit-container">
    <div class="form-logo-wrapper">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Logo">
    </div>

    <h2 class="form-title">Hot Work / Work Permit Review</h2>

    <div class="section-heading">Project & Permit Details</div>
    
    <div class="field-box">
        <div class="field-label">Permit Number</div>
        <div class="field-value" style="font-weight: 700; color: #FE7F08;"><?php echo htmlspecialchars($permit_number); ?></div>
    </div>

    <div class="field-box">
        <div class="field-label">Project Name</div>
        <div class="field-value"><?php echo htmlspecialchars($cat_project); ?></div>
    </div>

    <div class="field-box">
        <div class="field-label">Work Location</div>
        <div class="field-value"><?php echo htmlspecialchars($cat_work_location); ?></div>
    </div>

    <div class="field-box">
        <div class="field-label">Description</div>
        <div class="field-value"><?php echo htmlspecialchars($cat_description); ?></div>
    </div>

    <div class="section-heading">Company & Supervisor Details</div>
    
    <div class="field-box">
        <div class="field-label">Company</div>
        <div class="field-value"><?php echo htmlspecialchars($company_name); ?></div>
    </div>

    <div class="field-box">
        <div class="field-label">Address</div>
        <div class="field-value"><?php echo htmlspecialchars($company_address); ?></div>
    </div>

    <div class="row">
        <div class="col">
            <div class="field-box">
                <div class="field-label">Division</div>
                <div class="field-value"><?php echo htmlspecialchars($company_division); ?></div>
            </div>
        </div>
        <div class="col">
            <div class="field-box">
                <div class="field-label">Contact Number</div>
                <div class="field-value"><?php echo htmlspecialchars($company_contact); ?></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col">
            <div class="field-box">
                <div class="field-label">Supervisor</div>
                <div class="field-value"><?php echo htmlspecialchars($supervisor_info); ?></div>
            </div>
        </div>
        <div class="col">
            <div class="field-box">
                <div class="field-label">Fire Watch</div>
                <div class="field-value"><?php echo htmlspecialchars($fire_watch_info); ?></div>
            </div>
        </div>
    </div>

    <?php if (stripos($current_status, 'Approved') !== false): ?>
        <div class="already-actioned status-approved">
            This permit has already been <strong>Approved</strong>.
        </div>
    <?php elseif (stripos($current_status, 'Rejected') !== false): ?>
        <div class="already-actioned status-rejected">
            This permit has already been <strong>Rejected</strong>.
        </div>
    <?php else: ?>
        <div class="action-buttons">
            <button type="button" class="btn btn-approve" onclick="handleAction('approve')">&#10003; Approve Permit</button>
            <button type="button" class="btn btn-reject" onclick="handleAction('reject')">&#10005; Reject Permit</button>
        </div>
    <?php endif; ?>
</div>

<script>
function handleAction(actionType) {
    let permitNumber = "<?php echo $permit_number; ?>";

    if(actionType === 'approve') {
        if(confirm('Are you sure you want to APPROVE this permit?')) {
            sendUpdateToServer(permitNumber, 'approve', '');
        }
    } else {
        let reason = prompt('Please enter rejection reason:');
        if(reason !== null && reason.trim() !== '') {
            sendUpdateToServer(permitNumber, 'reject', reason);
        }
    }
}

function sendUpdateToServer(permitNumber, action, reason) {
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `permit_number=${encodeURIComponent(permitNumber)}&action=${action}&reason=${encodeURIComponent(reason)}`
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === 'success') {
            alert('Permit status updated successfully!');
            location.reload();
        } else {
            alert('Update failed. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}
</script>

</body>
</html>