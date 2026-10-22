<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    echo "<p style='font-family:sans-serif;padding:12px;font-size:12px;'>Access denied. Please log in as admin.</p>";
    exit();
}

// Database connection details
require_once __DIR__ . '/db_config.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sup_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->prepare("SELECT * FROM supervisors WHERE id = ?");
$stmt->bind_param("i", $sup_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    echo "<p style='font-family:sans-serif;padding:12px;font-size:12px;'>Supervisor record not found.</p>";
    exit();
}

$row = $result->fetch_assoc();
$stmt->close();
$conn->close();

$status = $row['status'] ?? 'Pending';

$status_class = 'sv-status-pending';
$status_icon  = 'bi-clock-fill';
if ($status === 'Approved') { $status_class = 'sv-status-approved'; $status_icon = 'bi-check-circle-fill'; }
if ($status === 'Rejected') { $status_class = 'sv-status-rejected'; $status_icon = 'bi-x-circle-fill'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Supervisor Summary</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
    --amber: #ff7a1a;
    --amber-deep: #c2410c;
    --gold: #f2b545;
    --ink: #1f2937;
    --muted: #6b7280;
    --line: #eef0f3;
}

*{ margin:0; padding:0; box-sizing:border-box; }

body{
    font-family:'Inter',sans-serif;
    background:#ffffff;
    color: var(--ink);
    padding: 10px 12px 14px;
}

h1,h2,h3,h4{ font-family:'Manrope',sans-serif; }

.sv-header{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--line);
}

.sv-avatar{
    width:32px;
    height:32px;
    border-radius:8px;
    background: linear-gradient(135deg, rgba(255,122,26,0.15), rgba(242,181,69,0.12));
    border: 1px solid rgba(255,122,26,0.25);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:14px;
    color: var(--amber-deep);
    flex-shrink:0;
}

.sv-header h2{
    font-size: 0.85rem;
    font-weight: 800;
    color: var(--ink);
    margin-bottom: 0px;
}

.sv-header p{
    font-size: 10px;
    color: var(--muted);
}

.sv-status-pill{
    display:inline-flex;
    align-items:center;
    gap:3px;
    padding: 2px 6px;
    border-radius: 50px;
    font-size: 9.5px;
    font-weight: 700;
    margin-left:auto;
    white-space:nowrap;
}

.sv-status-pending{ background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
.sv-status-approved{ background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
.sv-status-rejected{ background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

.sv-grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px 10px;
    margin-bottom: 10px;
}

@media (max-width:520px){
    .sv-grid{ grid-template-columns: 1fr; }
}

.sv-field{
    background:#fafafa;
    border:1px solid var(--line);
    border-radius:6px;
    padding: 6px 8px;
}

.sv-field.full{ grid-column: 1 / -1; }

.sv-field-label{
    font-size:9px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    color: var(--muted);
    display:flex;
    align-items:center;
    gap:3px;
    margin-bottom:1px;
}

.sv-field-label i{ color: var(--amber); }

.sv-field-value{
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ink);
    word-break: break-word;
}

.sv-actions{
    display:flex;
    gap:6px;
    padding-top: 8px;
    border-top: 1px solid var(--line);
}

.sv-btn{
    flex:1;
    height: 30px;
    border:none;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:4px;
    cursor:pointer;
    text-decoration:none;
    transition:.2s ease;
}

.sv-btn-approve{
    background: linear-gradient(135deg,#22c55e,#16a34a);
    color:#fff;
    box-shadow: 0 2px 6px rgba(22,163,74,0.2);
}

.sv-btn-approve:hover{ transform: translateY(-1px); color:#fff; box-shadow: 0 3px 10px rgba(22,163,74,0.25); }

.sv-btn-reject{
    background: linear-gradient(135deg,#ef4444,#dc2626);
    color:#fff;
    box-shadow: 0 2px 6px rgba(220,38,38,0.2);
}

.sv-btn-reject:hover{ transform: translateY(-1px); color:#fff; box-shadow: 0 3px 10px rgba(220,38,38,0.25); }

.sv-processed-note{
    text-align:center;
    padding: 8px;
    border-radius: 6px;
    background:#f8fafc;
    color: var(--muted);
    font-size: 11px;
    border: 1px dashed var(--line);
}
</style>
</head>
<body>

<div class="sv-header">
    <div class="sv-avatar"><i class="bi bi-person-badge-fill"></i></div>
    <div>
        <h2><?= htmlspecialchars($row['name'] ?? 'N/A'); ?></h2>
        <p>Supervisor Registration Request</p>
    </div>
    <span class="sv-status-pill <?= $status_class; ?>">
        <i class="bi <?= $status_icon; ?>"></i> <?= htmlspecialchars($status); ?>
    </span>
</div>

<div class="sv-grid">

    <div class="sv-field">
        <div class="sv-field-label"><i class="bi bi-card-text"></i> NIC Number</div>
        <div class="sv-field-value"><?= htmlspecialchars($row['nic'] ?? 'N/A'); ?></div>
    </div>

    <div class="sv-field">
        <div class="sv-field-label"><i class="bi bi-person-badge"></i> Username</div>
        <div class="sv-field-value"><?= htmlspecialchars($row['username'] ?? 'N/A'); ?></div>
    </div>

    <div class="sv-field">
        <div class="sv-field-label"><i class="bi bi-geo-alt"></i> Division</div>
        <div class="sv-field-value"><?= htmlspecialchars($row['division'] ?? 'N/A'); ?></div>
    </div>

    <div class="sv-field">
        <div class="sv-field-label"><i class="bi bi-telephone"></i> Phone Number</div>
        <div class="sv-field-value"><?= htmlspecialchars($row['phone'] ?? 'N/A'); ?></div>
    </div>

    <div class="sv-field full">
        <div class="sv-field-label"><i class="bi bi-building"></i> Division Address</div>
        <div class="sv-field-value"><?= htmlspecialchars($row['division_address'] ?? 'N/A'); ?></div>
    </div>

    <div class="sv-field full">
        <div class="sv-field-label"><i class="bi bi-envelope"></i> Email Address</div>
        <div class="sv-field-value"><?= htmlspecialchars($row['email'] ?? 'N/A'); ?></div>
    </div>

</div>

<?php if ($status === 'Pending'): ?>

    <div class="sv-actions">
        <a
            href="#"
            class="sv-btn sv-btn-approve sv-confirm-action"
            data-action-url="admin_dashboard.php?section=supervisors&sup_action=accept&sup_id=<?= urlencode($row['id']); ?>"
            data-confirm-text="Do you really want to approve this supervisor?"
        >
            <i class="bi bi-check-lg"></i> Approve
        </a>
        <a
            href="#"
            class="sv-btn sv-btn-reject sv-confirm-action"
            data-action-url="admin_dashboard.php?section=supervisors&sup_action=reject&sup_id=<?= urlencode($row['id']); ?>"
            data-confirm-text="Do you really want to reject this supervisor?"
        >
            <i class="bi bi-x-lg"></i> Reject
        </a>
    </div>

<?php else: ?>

    <div class="sv-processed-note">
        This request has already been <strong><?= htmlspecialchars($status); ?></strong>.
    </div>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('click', function (e) {
    var target = e.target.closest('.sv-confirm-action');
    if (!target) return;

    e.preventDefault();

    var actionUrl = target.getAttribute('data-action-url');
    var confirmText = target.getAttribute('data-confirm-text') || 'Are you sure?';
    var isApprove = target.classList.contains('sv-btn-approve');

    Swal.fire({
        title: 'Are you sure?',
        text: confirmText,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: isApprove ? '#16a34a' : '#dc2626',
        cancelButtonColor: '#6c757d',
        confirmButtonText: isApprove ? 'Yes, Approve it!' : 'Yes, Reject it!'
    }).then(function (result) {
        if (result.isConfirmed) {
            if (window.top) {
                window.top.location.href = actionUrl;
            } else {
                window.location.href = actionUrl;
            }
        }
    });
});s
</script>

</body>
</html>