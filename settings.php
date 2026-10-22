<?php

session_start();

if (!isset($_SESSION['supervisor_logged']) || $_SESSION['supervisor_logged'] !== true) {
    header("Location: index.php");
    exit();
}
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

if($conn->connect_error){
    die("Database Connection Failed : ".$conn->connect_error);
}

$sup_name      = $_SESSION['sup_name'] ?? "Supervisor";
$sup_division  = $_SESSION['sup_division'] ?? "General";
$supervisor_id = $_SESSION['supervisor_id'] ?? 1;

$success_msg = "";
$error_msg   = "";

// Fetch Current Supervisor Details from Database for Display
$query = "SELECT name, email, phone, nic, division, username, profile_pic FROM supervisors WHERE id = ?";
$stmt = $conn->prepare($query);

if (!$stmt) {
    die("SQL Prepare Error: " . $conn->error);
}

$stmt->bind_param("i", $supervisor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $supervisor = $result->fetch_assoc();
} else {
    $supervisor = [
        'name' => $sup_name,
        'email' => 'supervisor@hayleys.com',
        'phone' => '+94 77 123 4567',
        'nic' => 'N/A',
        'division' => $sup_division,
        'username' => 'supervisor',
        'profile_pic' => ''
    ];
}
$stmt->close();

// Handle Single Unified Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // 1. Profile Picture Upload Handling with Robust Image & Format Check
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {

        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'jfif', 'heic', 'avif', 'gif'];

        // Real MIME types we accept (checked with finfo, not just the file extension)
        $allowed_mimes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/heic',
            'image/heif',
            'image/avif'
        ];

        $filename = $_FILES['profile_pic']['name'];
        $filetmp  = $_FILES['profile_pic']['tmp_name'];
        $filesize = $_FILES['profile_pic']['size'];
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $max_size = 5 * 1024 * 1024; // 5MB

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? finfo_file($finfo, $filetmp) : false;
        if ($finfo) finfo_close($finfo);

        if (!in_array($ext, $allowed_ext)) {
            $error_msg = "Invalid file extension. Allowed: " . strtoupper(implode(', ', $allowed_ext)) . ".";
        } elseif ($filesize > $max_size) {
            $error_msg = "Image is too large. Maximum allowed size is 5MB.";
        } elseif (!$mime || strpos($mime, 'image/') !== 0) {
            $error_msg = "The uploaded file is not a valid image.";
        } else {
            $profile_path = s3_store_upload(
                $filetmp,
                $filename,
                'profiles',
                'profile_' . $supervisor_id
            );

            if ($profile_path) {

                // Update profile picture in DB
                $pic_stmt = $conn->prepare("UPDATE supervisors SET profile_pic = ? WHERE id = ?");
                $pic_stmt->bind_param("si", $profile_path, $supervisor_id);
                $pic_stmt->execute();
                $pic_stmt->close();

                // Update local array so it shows immediately (replaces the letter avatar)
                $supervisor['profile_pic'] = $profile_path;
                $success_msg = "Profile picture updated successfully!";
            } else {
                $error_msg = "Failed to upload profile picture. Please try again, or check the media storage configuration.";
            }
        }
    }

    // 2. Password Change Handling (if filled)
    if (!empty($_POST['current_password']) || !empty($_POST['new_password']) || !empty($_POST['confirm_password'])) {
        $current_password = $_POST['current_password'];
        $new_password     = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg .= "Please fill in all password fields to change it. ";
        } elseif ($new_password !== $confirm_password) {
            $error_msg .= "New passwords do not match! ";
        } elseif (strlen($new_password) < 6) {
            $error_msg .= "New password must be at least 6 characters long. ";
        } else {
            // Verify current password from database
            $pass_check = $conn->prepare("SELECT password FROM supervisors WHERE id = ?");
            $pass_check->bind_param("i", $supervisor_id);
            $pass_check->execute();
            $pass_check->bind_result($db_hashed_password);
            $pass_check->fetch();
            $pass_check->close();

            if (password_verify($current_password, $db_hashed_password)) {
                $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $update_pass = $conn->prepare("UPDATE supervisors SET password = ? WHERE id = ?");
                $update_pass->bind_param("si", $new_hashed_password, $supervisor_id);

                if ($update_pass->execute()) {
                    $success_msg .= "Password changed and updated in database successfully!";
                } else {
                    $error_msg .= "Database Error updating password: " . $conn->error;
                }
                $update_pass->close();
            } else {
                $error_msg .= "Current password is incorrect!";
            }
        }
    } else {
        if(empty($success_msg) && empty($error_msg)) {
            $success_msg = "Settings checked. No changes were made.";
        }
    }
}

// Determine Profile Picture URL
$display_pic = media_exists($supervisor['profile_pic'] ?? '')
                ? media_url($supervisor['profile_pic'])
                : 'assets/images/default-avatar.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | QHSE Pro</title>
    <meta name="description" content="QHSC System - Supervisor Settings for profile and password management.">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">

    <style>
    :root{
        --bg-app:#f4f6f9; --card-bg:#ffffff; --text-main:#0f172a; --text-muted:#64748b;
        --accent-color:#f97316; --accent-dark:#ea580c; --accent-soft:#fff7ed; --border:#e2e8f0;
        --primary-gradient:linear-gradient(135deg,#f97316 0%,#ea580c 100%);
        --card-shadow:0 4px 12px -2px rgba(15,23,42,.05),0 2px 4px -2px rgba(15,23,42,.03);
        --card-shadow-hover:0 14px 24px -8px rgba(249,115,22,.18);
        --danger:#dc2626; --danger-soft:#fef2f2; --warn:#c2410c; --warn-soft:#fff7ed; --ok:#15803d; --ok-soft:#f0fdf4;
    }
    *:focus-visible{ outline:2px solid var(--accent-color); outline-offset:2px; }
    body{ background:var(--bg-app); font-family:'Poppins',sans-serif; color:var(--text-main); min-height:100vh; font-size:14px; -webkit-font-smoothing:antialiased; overflow-x:hidden; }
    h1,h2,h3,h4,h5,h6{ font-family:'Poppins',sans-serif; letter-spacing:-.02em; }
    a{ text-decoration:none !important; }

    .page-layout-wrapper{ display:flex; flex-direction:column; min-height:100vh; width:100%; }
    .main-content-area{ flex:1; padding:14px; width:100%; max-width:100%; overflow-x:hidden; }

    /* ---------- Page header ---------- */
    .page-header{
        background:var(--card-bg); border:1px solid var(--border); border-radius:18px; box-shadow:var(--card-shadow);
        padding:18px 22px; margin-bottom:18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;
        position:relative; overflow:hidden;
    }
    .page-header::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--primary-gradient); }
    .breadcrumb-mini{ display:flex; align-items:center; gap:6px; font-size:11.5px; color:var(--text-muted); margin-bottom:4px; }
    .breadcrumb-mini i{ font-size:10px; }
    .breadcrumb-mini .current{ color:var(--accent-dark); font-weight:600; }
    .page-header h2{ font-size:21px; font-weight:700; margin:0; display:flex; align-items:center; gap:10px; }
    .title-icon{ width:38px; height:38px; border-radius:11px; background:var(--primary-gradient); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:17px; box-shadow:0 6px 14px rgba(249,115,22,.28); }
    .page-sub{ margin:4px 0 0; color:var(--text-muted); font-size:12.5px; }
    .page-title-meta{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

    .pill-badge{ display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:50px; font-size:12px; font-weight:500; background:var(--bg-app); color:#334155; border:1px solid var(--border); white-space:nowrap; }
    .user-chip{ display:flex; align-items:center; gap:10px; padding:5px 14px 5px 5px; background:var(--bg-app); border:1px solid var(--border); border-radius:50px; }
    .user-chip .avatar{ width:36px; height:36px; background:var(--primary-gradient); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; }
    .user-chip .avatar-img{ width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 0 0 1px var(--border); }
    .user-chip .u-name{ font-weight:600; color:var(--text-main); line-height:1.15; display:block; font-size:12.5px; }
    .user-chip .u-div{ color:var(--text-muted); font-size:10.5px; display:block; }

    /* ---------- Stat cards ---------- */
    .stat-card{ background:var(--card-bg); border:1px solid var(--border); border-radius:16px; padding:16px 18px; box-shadow:var(--card-shadow); display:flex; align-items:center; gap:14px; height:100%; transition:box-shadow .2s ease, transform .2s ease; }
    .stat-card:hover{ box-shadow:var(--card-shadow-hover); transform:translateY(-2px); }
    .stat-icon{ width:46px; height:46px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    .stat-icon.orange{ background:var(--accent-soft); color:var(--accent-dark); }
    .stat-icon.green{ background:var(--ok-soft); color:var(--ok); }
    .stat-icon.red{ background:var(--danger-soft); color:var(--danger); }
    .stat-icon.slate{ background:#f1f5f9; color:#334155; }
    .stat-value{ font-size:24px; font-weight:700; line-height:1.1; color:var(--text-main); }
    .stat-label{ font-size:12px; color:var(--text-muted); font-weight:500; margin-top:2px; }

    
    .stat-value.sm{ font-size:16px; word-break:break-word; }
    .alert{ border:none; border-radius:12px; box-shadow:var(--card-shadow); font-size:13px; }
    @media (min-width:768px){
        .page-layout-wrapper{ flex-direction:row; align-items:flex-start; }
        .main-content-area{ padding:22px; }
        .page-header h2{ font-size:23px; }
    }
    @media (prefers-reduced-motion:reduce){ *{ transition:none !important; } }


    /* ---------- Settings page ---------- */
    .settings-main-box{ background:var(--card-bg); border-radius:18px; box-shadow:var(--card-shadow); border:1px solid rgba(226,232,240,.9); margin:20px 0 30px; overflow:hidden; }
    .settings-main-header{ background:#fff; color:var(--text-main); border-bottom:1px solid var(--border); padding:16px 22px; font-weight:700; font-size:14.5px; display:flex; align-items:center; gap:10px; }
    .settings-main-header i{ color:var(--accent-dark); font-size:17px; }
    .settings-main-body{ padding:18px; }

    .section-title{ font-size:12.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--accent-dark); font-weight:700; border-bottom:2px solid #fff2eb; padding-bottom:8px; margin-bottom:18px; display:flex; align-items:center; gap:7px; }

    .profile-img-container{ position:relative; width:96px; height:96px; margin:0 auto 10px; }
    .profile-img{ width:96px; height:96px; border-radius:50%; object-fit:cover; border:3px solid #fff; box-shadow:0 0 0 2px #fed7aa, 0 8px 18px rgba(249,115,22,.18); }
    .photo-badge{ position:absolute; right:0; bottom:2px; width:30px; height:30px; border-radius:50%; background:var(--primary-gradient); color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; border:2px solid #fff; cursor:pointer; transition:transform .2s ease; }
    .photo-badge:hover{ transform:scale(1.08); }
    .photo-hint{ font-size:11.5px; color:var(--text-muted); }

    .detail-group{ margin-bottom:14px; }
    .detail-label{ font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); margin-bottom:4px; display:flex; align-items:center; gap:5px; }
    .detail-label i{ color:var(--accent-color); font-size:12px; }
    .detail-value{ font-size:13.5px; font-weight:600; color:var(--text-main); background:#f8fafc; border:1px solid var(--border); padding:9px 12px; border-radius:10px; word-break:break-all; }

    .form-label{ font-size:12px; font-weight:600; color:#334155; }
    .form-control{ border-radius:10px; border:1px solid var(--border); font-family:'Poppins',sans-serif; font-size:13px; padding:9px 12px; }
    .form-control:focus{ border-color:#fb923c; box-shadow:0 0 0 4px rgba(249,115,22,.12); }
    .pw-field{ position:relative; }
    .pw-field .form-control{ padding-right:42px; }
    .pw-toggle{ position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); width:34px; height:34px; border-radius:8px; }
    .pw-toggle:hover{ background:#f1f5f9; color:var(--accent-dark); }
    .match-hint{ font-size:11.5px; margin-top:5px; min-height:16px; }

    .note-box{ background:var(--accent-soft); border:1px solid rgba(249,115,22,.22); border-radius:12px; padding:12px 14px; font-size:12px; color:#7c2d12; display:flex; gap:8px; align-items:flex-start; }
    .note-box i{ color:var(--accent-color); margin-top:1px; }

    .btn-save-all{ background:var(--primary-gradient); color:#fff; border:none; border-radius:10px; font-weight:600; padding:11px 18px; box-shadow:0 6px 14px rgba(249,115,22,.25); transition:all .2s ease; }
    .btn-save-all:hover{ color:#fff; filter:brightness(1.05); transform:translateY(-1px); }

    @media (min-width:768px){ .settings-main-body{ padding:26px; } }
    @media (min-width:992px){
        .form-divider{ border-right:1px solid var(--border); padding-right:30px; }
        .form-content-right{ padding-left:14px; }
        .settings-main-body{ padding:30px; }
    }

    /* ===== FIT-TO-SCREEN (desktop): the whole settings page fits one screen, page does not scroll ===== */
    @media (min-width:992px) and (min-height:700px){
        body{ height:100vh; overflow:hidden; min-height:0; }
        .page-layout-wrapper{ height:100vh; }
        .main-content-area{ height:100vh; display:flex; flex-direction:column; padding:14px 20px !important; overflow:hidden; }
        .main-content-area > .container-fluid{ flex:1 1 auto; min-height:0; width:100%; display:flex; flex-direction:column; }
        .main-content-area > .container-fluid > *{ flex-shrink:0; }
        .main-content-area > .container-fluid > form{ flex:1 1 0; min-height:0; display:flex; flex-direction:column; }

        .page-header{ padding:12px 20px; margin-bottom:12px; }
        .alert{ margin-bottom:10px; padding:8px 14px; }
        form .settings-main-box{ flex:1 1 auto; min-height:0; margin:0; display:flex; flex-direction:column; }
        .settings-main-header{ padding:12px 22px; flex-shrink:0; }
        .settings-main-body{ flex:1 1 auto; min-height:0; overflow-y:auto; padding:18px 26px; }

        .section-title{ margin-bottom:12px; }
        .settings-main-body .text-center.mb-4{ margin-bottom:.7rem !important; }
        .profile-img-container, .profile-img{ width:80px; height:80px; }
        .profile-img-container{ margin-bottom:6px; }
        .detail-group{ margin-bottom:10px; }
        .detail-value{ padding:7px 12px; }
        .form-content-right .mb-3{ margin-bottom:.6rem !important; }
        .form-content-right .mb-4{ margin-bottom:.75rem !important; }
        .note-box{ padding:9px 12px; }
    }
    </style>
</head>
<body>

<div class="page-layout-wrapper">

<?php include "navbar.php"; ?>

<div class="main-content-area">

<div class="container-fluid px-0" style="max-width: 1400px; margin: 0 auto;">

    <!-- PAGE HEADER -->
    <header class="page-header">
        <div>
         
            <h2>
                <span class="title-icon"><i class="bi bi-gear-fill"></i></span>
                Supervisor Settings
            </h2>
            <p class="page-sub">Manage your profile photo and account password.</p>
        </div>

        <div class="page-title-meta">
            <div class="pill-badge">
                <i class="bi bi-calendar3" style="color: #fb923c;"></i> <span id="liveDate"></span>
            </div>
            <div class="pill-badge">
                <i class="bi bi-clock-history" style="color: #f97316;"></i> <span id="liveTime"></span>
            </div>
            <div class="user-chip">
                <?php if (!empty($display_pic) && $display_pic !== 'assets/images/default-avatar.png'): ?>
                    <img src="<?php echo htmlspecialchars($display_pic); ?>" alt="Avatar" class="avatar-img">
                <?php else: ?>
                    <span class="avatar"><?php echo strtoupper(substr($sup_name, 0, 1)); ?></span>
                <?php endif; ?>
                <span>
                    <span class="u-name"><?php echo htmlspecialchars($sup_name); ?></span>
                    <span class="u-div"><?php echo htmlspecialchars($sup_division); ?> Division</span>
                </span>
            </div>
        </div>
    </header>

    <!-- Alert Messages -->
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo nl2br(htmlspecialchars($success_msg)); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($error_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- SINGLE UNIFIED FORM WRAPPING BOTH SIDES INSIDE ONE MAIN BOX -->
    <form action="" method="POST" enctype="multipart/form-data">
        <div class="settings-main-box">
            <div class="settings-main-header">
                <i class="bi bi-sliders"></i> Account Settings & Profile Management
            </div>

            <div class="settings-main-body">
                <div class="row g-4">

                    <!-- LEFT COLUMN: Profile Details & Picture Upload -->
                    <div class="col-lg-6 form-divider">
                        <div class="section-title"><i class="bi bi-person-badge"></i> Profile Information</div>

                        <div class="text-center mb-4">
                            <div class="profile-img-container">
                                <img src="<?php echo htmlspecialchars($display_pic); ?>" alt="Profile Picture" class="profile-img" id="profilePreview">
                                <label for="profile_pic" class="photo-badge" title="Change photo"><i class="bi bi-camera-fill"></i></label>
                            </div>
                            <input type="file" id="profile_pic" name="profile_pic" class="d-none" accept="image/*" onchange="previewImage(event)">
                            <div class="photo-hint">JPG, PNG, WEBP, GIF, HEIC or AVIF &middot; max 5MB</div>
                        </div>

                        <div class="detail-group">
                            <div class="detail-label"><i class="bi bi-person"></i> Full Name</div>
                            <div class="detail-value"><?php echo htmlspecialchars($supervisor['name']); ?></div>
                        </div>

                        <div class="detail-group">
                            <div class="detail-label"><i class="bi bi-envelope"></i> Email Address</div>
                            <div class="detail-value"><?php echo htmlspecialchars($supervisor['email']); ?></div>
                        </div>

                        <div class="detail-group">
                            <div class="detail-label"><i class="bi bi-telephone"></i> Phone Number</div>
                            <div class="detail-value"><?php echo htmlspecialchars($supervisor['phone']); ?></div>
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6 detail-group">
                                <div class="detail-label"><i class="bi bi-diagram-3"></i> Assigned Division</div>
                                <div class="detail-value"><?php echo htmlspecialchars($supervisor['division']); ?></div>
                            </div>
                            <div class="col-sm-6 detail-group">
                                <div class="detail-label"><i class="bi bi-at"></i> Username</div>
                                <div class="detail-value"><?php echo htmlspecialchars($supervisor['username']); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: Password Change & Global Save -->
                    <div class="col-lg-6 form-content-right">
                        <div class="section-title"><i class="bi bi-shield-lock"></i> Security & Password Change</div>

                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <div class="pw-field">
                                <input type="password" class="form-control" id="current_password" name="current_password" placeholder="Enter current password">
                                <button type="button" class="pw-toggle" data-target="current_password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password (Min 6 chars)</label>
                            <div class="pw-field">
                                <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password" minlength="6">
                                <button type="button" class="pw-toggle" data-target="new_password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="pw-field">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" minlength="6">
                                <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="match-hint" id="matchHint"></div>
                        </div>

                        <div class="note-box mb-4">
                            <i class="bi bi-info-circle-fill"></i>
                            <span>Leave password fields blank if you only wish to update your profile photo.</span>
                        </div>

                        <!-- Single Unified Submit Button for Both Sides -->
                        <button type="submit" class="btn btn-save-all w-100">
                            <i class="bi bi-check2-all me-1"></i> Save All Changes
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </form>

</div>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function updateDateTime() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    const dateStr = now.toLocaleDateString('en-US', options);
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    if(document.getElementById('liveDate')) document.getElementById('liveDate').innerText = dateStr;
    if(document.getElementById('liveTime')) document.getElementById('liveTime').innerText = timeStr;
}
setInterval(updateDateTime, 1000);
updateDateTime();

function previewImage(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(){
        document.getElementById('profilePreview').src = reader.result;
    };
    reader.readAsDataURL(file);
}

// Show / hide password
document.querySelectorAll('.pw-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
        const input = document.getElementById(this.dataset.target);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        this.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
});

// Live "passwords match" hint
(function(){
    const np = document.getElementById('new_password');
    const cp = document.getElementById('confirm_password');
    const hint = document.getElementById('matchHint');
    function check(){
        if (!cp.value) { hint.textContent = ''; return; }
        if (np.value === cp.value) {
            hint.style.color = 'var(--ok)';
            hint.innerHTML = '<i class="bi bi-check-circle-fill"></i> Passwords match';
        } else {
            hint.style.color = 'var(--danger)';
            hint.innerHTML = '<i class="bi bi-x-circle-fill"></i> Passwords do not match';
        }
    }
    np.addEventListener('input', check);
    cp.addEventListener('input', check);
})();
</script>

</body>
</html>