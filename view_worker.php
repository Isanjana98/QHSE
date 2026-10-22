<?php
session_start();

// Admin login check
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$worker_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->prepare("SELECT w.*, s.vendor_code, s.company_name AS sub_company
                        FROM workers w
                        LEFT JOIN subcontractors s ON s.id = w.subcontractor_id
                        WHERE w.id = ?");
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$worker = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$worker) {
    header("Location: workers_section.php");
    exit();
}

$status = $worker['status'] ?? 'Pending';
$back_tab = ($status === 'Pending') ? 'pending' : 'completed';

function e($v) {
    $v = trim((string)($v ?? ''));
    return $v === '' ? '-' : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function renderHealth($json) {
    if (empty($json)) {
        return '<span class="text-muted">None specified</span>';
    }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) {
        return '<span class="text-muted">None specified</span>';
    }
    $html = '<ul class="list-unstyled mb-0">';
    foreach ($data as $key => $val) {
        $cls = ($val === 'Not infected') ? 'text-success' : 'text-danger fw-bold';
        $html .= '<li class="health-row"><span>' . htmlspecialchars($key) . '</span><span class="' . $cls . '">'
              . htmlspecialchars($val) . '</span></li>';
    }
    return $html . '</ul>';
}

function renderPhoto($path, $label) {
    if (!empty($path)) {
        $p = htmlspecialchars(media_url($path), ENT_QUOTES, 'UTF-8');
        return '<a href="' . $p . '" target="_blank"><img src="' . $p . '" alt="' . $label . '" class="img-thumbnail id-photo"></a>';
    }
    return '<span class="text-muted">Not uploaded</span>';
}

$status_class = ($status === 'Approved') ? 'status-approved' : (($status === 'Rejected') ? 'status-rejected' : 'status-pending');
$status_icon  = ($status === 'Approved') ? 'bi-check-circle-fill' : (($status === 'Rejected') ? 'bi-x-circle-fill' : 'bi-clock-fill');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Details - Hayleys Fentons</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #da6913; --primary-dark: #bb4c14; --success: #16803a; --danger: #d9483c;
            --background: #F6F8F5; --surface: #FFFFFF; --border: #E4E9E2; --text: #0E1F14;
        }
        body { background: var(--background); font-family: 'Inter', sans-serif; color: var(--text); margin: 0; overflow-x: hidden; }
        .app-layout { display: flex; min-height: 100vh; }

        /* Sidebar (same as admin pages) */
        .sidebar-wrap {
            width: 100px; flex-shrink: 0; background: var(--background); display: flex; flex-direction: column;
            align-items: flex-start; justify-content: center; padding: 20px 0 20px 18px; position: sticky; top: 0;
            height: 100vh; z-index: 1000; transition: width .3s cubic-bezier(.4,0,.2,1);
        }
        .sidebar-wrap:hover { width: 240px; }
        .sidebar-logo { height: 60px; width: auto; object-fit: contain; margin-bottom: 25px; margin-left: -6px; transition: all .3s ease; }
        .sidebar-wrap:hover .sidebar-logo { margin-left: 45px; }
        .vertical-nav {
            background: linear-gradient(180deg, #ea580c 0%, #b45309 50%, #15803d 100%); width: 75px;
            height: calc(100vh - 120px); border-radius: 40px; display: flex; flex-direction: column;
            align-items: center; justify-content: space-between; padding: 25px 0;
            box-shadow: 0 10px 25px rgba(0,0,0,.15); transition: width .3s cubic-bezier(.4,0,.2,1);
            overflow: hidden; white-space: nowrap;
        }
        .sidebar-wrap:hover .vertical-nav { width: 210px; align-items: flex-start; padding-left: 20px; padding-right: 20px; }
        .nav-links-group { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 15px; width: 100%; flex: 1; }
        .sidebar-wrap:hover .nav-links-group { align-items: flex-start; }
        .nav-icon-link {
            width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; color: #fff;
            font-size: 20px; border-radius: 18px; text-decoration: none; transition: all .25s ease; gap: 15px;
        }
        .sidebar-wrap:hover .nav-icon-link { width: 100%; justify-content: flex-start; padding-left: 12px; border-radius: 14px; }
        .nav-text { display: none; font-size: .95rem; font-weight: 600; color: #fff; }
        .sidebar-wrap:hover .nav-text { display: inline-block; }
        .nav-icon-link:hover, .nav-icon-link.active { background: rgba(255,255,255,.35); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .logout-pill-btn {
            width: 50px; height: 50px; background: #a13d07; border-radius: 25px; display: flex; align-items: center;
            justify-content: center; color: #fff; text-decoration: none; box-shadow: inset 0 2px 4px rgba(0,0,0,.2);
            transition: all .3s ease; gap: 12px;
        }
        .sidebar-wrap:hover .logout-pill-btn { width: 100%; border-radius: 14px; justify-content: flex-start; padding-left: 14px; }
        .logout-pill-btn:hover { background: #873105; color: #fff; }

        .main-content-wrap { flex: 1; min-width: 0; padding: 25px 35px; box-sizing: border-box; }
        .top-header-bar { display: flex; justify-content: flex-end; align-items: center; gap: 15px; margin-bottom: 1px; }
        .nav-pill-box {
            background: #fff; border: 1px solid var(--border); padding: 6px 14px; border-radius: 18px; font-size: 12px;
            display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(0,0,0,.02);
        }
        .section-title { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 1.6rem; color: #1a1a1a; }
        .section-subtitle { color: #6c757d; font-size: .9rem; }

        /* Details card */
        .profile-card { background: #fff; border: 1px solid var(--border); border-radius: 16px; overflow: hidden; border-top: 5px solid var(--primary); }
        .profile-head { padding: 18px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .profile-body { padding: 18px; background: #f8f9fa; }
        .info-box { background: #fff; border-radius: 8px; padding: 9px 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
        .info-label { display: block; color: #6c757d; font-size: 10.5px; margin-bottom: 2px; }
        .info-value { font-weight: 700; color: #1a1a1a; font-size: 13px; word-break: break-word; }
        .health-box { border: 1px solid var(--border); border-radius: 8px; background: #f8f9fa; padding: 10px; height: 100%; }
        .health-row { display: flex; justify-content: space-between; gap: 8px; font-size: 11px; padding: 3px 0; border-bottom: 1px solid #eee; }
        .id-photo { max-height: 180px; object-fit: cover; }

        .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 13px; border-radius: 20px; font-size: .8rem; font-weight: 700; }
        .status-pending { background: rgba(218,105,19,.12); color: var(--primary); }
        .status-approved { background: rgba(22,128,58,.12); color: var(--success); }
        .status-rejected { background: rgba(217,72,60,.12); color: var(--danger); }

        .btn-approve { background: var(--success); color: #fff; border: none; border-radius: 10px; padding: 8px 20px; font-weight: 600; font-size: .88rem; text-decoration: none; }
        .btn-approve:hover { background: #12692f; color: #fff; }
        .btn-reject { background: var(--danger); color: #fff; border: none; border-radius: 10px; padding: 8px 20px; font-weight: 600; font-size: .88rem; text-decoration: none; }
        .btn-reject:hover { background: #b83a2f; color: #fff; }
        .btn-back { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 8px 18px; font-weight: 600; font-size: .88rem; color: #333; text-decoration: none; }
        .btn-back:hover { background: #f1f1f1; color: #000; }
        .section-heading { font-size: 12px; font-weight: 700; margin: 0; }
    </style>
    <link rel="stylesheet" href="admin_sidebar.css">
</head>
<body>

<div class="app-layout">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>
    <div class="main-content-wrap">
        <?php include __DIR__ . '/admin_welcome.php'; ?>
        <div class="top-header-bar">
            <div class="nav-pill-box"><i class="bi bi-calendar3 text-warning"></i><span><?= date('D, d M Y'); ?></span></div>
            <div class="nav-pill-box"><i class="bi bi-clock-history text-warning"></i><span id="liveClock">Loading time...</span></div>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h2 class="section-title mb-1">Worker Details Profile</h2>
                <p class="section-subtitle mb-0">Complete record of the selected worker. Review everything before approving.</p>
            </div>
            <a href="workers_section.php?tab=<?= $back_tab; ?>" class="btn-back"><i class="bi bi-arrow-left me-1"></i> Back to Workers</a>
        </div>

        <div class="profile-card">
            <div class="profile-head">
                <div>
                    <div class="fw-bold" style="font-family:'Manrope',sans-serif; font-size:1.1rem;"><?= e($worker['worker_name']); ?></div>
                    <small class="text-muted"><?= e($worker['company_name'] ?: $worker['sub_company']); ?></small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="status-pill <?= $status_class; ?>"><i class="bi <?= $status_icon; ?>"></i> <?= htmlspecialchars($status); ?></span>
                    <?php if ($status === 'Pending'): ?>
                        <a href="workers_section.php?action=accept&id=<?= intval($worker['id']); ?>" class="btn-approve js-approve"><i class="bi bi-check-lg me-1"></i> Approve</a>
                        <a href="workers_section.php?action=reject&id=<?= intval($worker['id']); ?>" class="btn-reject js-reject"><i class="bi bi-x-lg me-1"></i> Reject</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="profile-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="info-box">
                            <span class="info-label">Worker Unique Code</span>
                            <span class="info-value" style="color:#bb4c14; font-size:15px;">
                                <?= !empty($worker['worker_unique_code']) ? e($worker['worker_unique_code']) : 'Will be assigned after approval'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-3"><div class="info-box"><span class="info-label">Subcontractor Vendor Code</span><span class="info-value"><?= e($worker['vendor_code']); ?></span></div></div>
                    <div class="col-md-2"><div class="info-box"><span class="info-label">ID / Sub-ID</span><span class="info-value"><?= intval($worker['id']); ?> / <span class="text-primary"><?= e($worker['sub_id_number']); ?></span></span></div></div>
                    <div class="col-md-3"><div class="info-box"><span class="info-label">Record Created At</span><span class="info-value"><?= e($worker['created_at'] ?? ''); ?></span></div></div>

                    <div class="col-md-4"><div class="info-box"><span class="info-label">Worker Full Name</span><span class="info-value"><?= e($worker['worker_name']); ?></span></div></div>
                    <div class="col-md-4"><div class="info-box"><span class="info-label">NIC Number</span><span class="info-value"><?= e($worker['nic_number']); ?></span></div></div>
                    <div class="col-md-4"><div class="info-box"><span class="info-label">Date of Birth &amp; Gender</span><span class="info-value"><?= e($worker['dob']); ?> (<?= e($worker['gender']); ?>)</span></div></div>

                    <div class="col-md-4"><div class="info-box"><span class="info-label">Mobile Number</span><span class="info-value"><?= e($worker['mobile_number']); ?></span></div></div>
                    <div class="col-md-4"><div class="info-box"><span class="info-label">Email Address</span><span class="info-value"><?= e($worker['email']); ?></span></div></div>
                    <div class="col-md-4"><div class="info-box"><span class="info-label">Blood Group &amp; Emergency Contact</span><span class="info-value"><span class="text-danger"><?= e($worker['blood_group']); ?></span> | <?= e($worker['emergency_contact']); ?></span></div></div>

                    <div class="col-md-12"><div class="info-box"><span class="info-label">Residential Address</span><span class="info-value"><?= e($worker['address']); ?></span></div></div>

                    <div class="col-md-6"><div class="info-box"><span class="info-label">Police Report Issue Date</span><span class="info-value"><?= e($worker['police_date']); ?></span></div></div>
                    <div class="col-md-6"><div class="info-box"><span class="info-label">GS Report Issue Date</span><span class="info-value"><?= e($worker['gs_date']); ?></span></div></div>

                    <div class="col-md-4"><div class="info-box"><span class="info-label">Company Name &amp; Contact</span><span class="info-value"><?= e($worker['company_name']); ?> (<?= e($worker['company_contact']); ?>)</span></div></div>
                    <div class="col-md-4"><div class="info-box"><span class="info-label">Division Hired By</span><span class="info-value"><?= e($worker['division_hired']); ?></span></div></div>
                    <div class="col-md-4"><div class="info-box"><span class="info-label">Scope &amp; Designation</span><span class="info-value"><?= e($worker['scope']); ?> / <?= e($worker['designation']); ?></span></div></div>

                    <div class="col-md-6"><div class="info-box"><span class="info-label">Experience &amp; Allergies</span><span class="info-value"><?= e($worker['work_experience']); ?> Yrs &bull; Allergies: <span class="text-danger"><?= e($worker['allergies']); ?></span></span></div></div>
                    <div class="col-md-6"><div class="info-box"><span class="info-label">Educational / Vocational Qualifications</span><span class="info-value"><?= e($worker['edu_qualifications']); ?> / <?= e($worker['voc_qualifications']); ?></span></div></div>

                    <!-- ID photos -->
                    <div class="col-md-12">
                        <div class="info-box">
                            <span class="info-label fw-bold mb-2" style="font-size:11px;"><i class="bi bi-file-earmark-image text-warning me-1"></i> NIC / ID Card Photos</span>
                            <div class="row g-2">
                                <div class="col-md-6 text-center">
                                    <span class="d-block text-secondary mb-1" style="font-size:10px;">Front Side</span>
                                    <?= renderPhoto($worker['id_front_path'] ?? '', 'ID Front'); ?>
                                </div>
                                <div class="col-md-6 text-center">
                                    <span class="d-block text-secondary mb-1" style="font-size:10px;">Back Side</span>
                                    <?= renderPhoto($worker['id_back_path'] ?? '', 'ID Back'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Health -->
                    <div class="col-md-12">
                        <div class="info-box">
                            <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-1">
                                <h6 class="section-heading"><i class="bi bi-heart-pulse text-danger me-1"></i> Health Information Details</h6>
                                <span class="badge bg-danger bg-opacity-10 text-danger" style="font-size:10px;">Medical Records</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="health-box">
                                        <div class="mb-1 fw-bold" style="font-size:11px;"><i class="bi bi-activity text-warning me-1"></i>NCD Diseases</div>
                                        <?= renderHealth($worker['ncd_details'] ?? ''); ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="health-box">
                                        <div class="mb-1 fw-bold" style="font-size:11px;"><i class="bi bi-shield-exclamation text-danger me-1"></i>Chronic Diseases</div>
                                        <?= renderHealth($worker['chronic_details'] ?? ''); ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="health-box">
                                        <div class="mb-1 fw-bold" style="font-size:11px;"><i class="bi bi-emoji-smile text-primary me-1"></i>Mental Health Conditions</div>
                                        <?= renderHealth($worker['mental_details'] ?? ''); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updateClock() {
        const now = new Date();
        let h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        m = m < 10 ? '0' + m : m;
        s = s < 10 ? '0' + s : s;
        const el = document.getElementById('liveClock');
        if (el) el.innerText = h + ':' + m + ':' + s + ' ' + ampm;
    }
    setInterval(updateClock, 1000);
    updateClock();

    function confirmAction(selector, opts) {
        document.querySelectorAll(selector).forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const url = this.getAttribute('href');
                Swal.fire(Object.assign({
                    showCancelButton: true,
                    cancelButtonColor: '#6c757d',
                    cancelButtonText: 'Cancel'
                }, opts)).then(r => { if (r.isConfirmed) window.location.href = url; });
            });
        });
    }

    confirmAction('.js-approve', {
        title: 'Approve Worker?',
        text: 'Do you want to approve this worker and generate a unique code?',
        icon: 'question',
        confirmButtonColor: '#16803a',
        confirmButtonText: 'Yes, Approve!'
    });
    confirmAction('.js-reject', {
        title: 'Reject Worker?',
        text: 'Are you sure you want to reject this worker registration?',
        icon: 'warning',
        confirmButtonColor: '#d9483c',
        confirmButtonText: 'Yes, Reject!'
    });
</script>
</body>
</html>