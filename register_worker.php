<?php
session_start();
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

// Load tutorial questions if available
$tutorialQuestions = [];
if (file_exists(__DIR__ . '/tutorial_questions.php')) {
    $tutorialQuestions = require __DIR__ . '/tutorial_questions.php';
}

$token = trim($_GET['token'] ?? '');
$invalid_link = false;
$error_reason = "";
$subcontractor = null;
$error_message = "";
$success_message = "";

if (empty($token)) {
    $invalid_link = true;
    $error_reason = "No token provided in the URL.";
} else {
    // Check if token exists, is active, and not expired (Using s.mobile_number or correct column)
    $stmt = $conn->prepare("SELECT l.*, s.id as sub_id, s.company_name, s.business_reg_number, s.vendor_code, s.mobile_number 
                            FROM worker_registration_links l 
                            JOIN subcontractors s ON s.id = l.subcontractor_id 
                            WHERE l.token = ?");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $row = $res->fetch_assoc();
            if ($row['status'] !== 'active') {
                $invalid_link = true;
                $error_reason = "This registration link has already been used or deactivated.";
            } elseif (strtotime($row['expires_at']) < time()) {
                $invalid_link = true;
                $error_reason = "This registration link expired on " . $row['expires_at'];
            } else {
                $subcontractor = $row;
            }
        } else {
            $invalid_link = true;
            $error_reason = "Invalid token. No matching link record found in database.";
        }
        $stmt->close();
    } else {
        $invalid_link = true;
        $error_reason = "Database query preparation failed: " . $conn->error;
    }
}

// Fetch active tutorial videos
$videos = [];
try {
    $vres = $conn->query("SELECT id, title, description, src, duration FROM tutorial_videos WHERE is_active = 1 AND type = 'file' ORDER BY sort_order ASC, id ASC");
    while ($vres && $vrow = $vres->fetch_assoc()) {
        $videos[] = $vrow;
    }
} catch (Throwable $e) {}

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$invalid_link) {
    $subcontractor_id = (int)$subcontractor['sub_id'];
    $company_name = $subcontractor['company_name'] ?? '';
    $company_contact = trim($subcontractor['mobile_number'] ?? '');
    
    $worker_name = trim($_POST['worker_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $nic_number = trim($_POST['nic_number'] ?? '');
    $dob = $_POST['dob'] ?? '';
    $mobile_number = trim($_POST['mobile_number'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $police_date = $_POST['police_date'] ?? '';
    $gs_date = $_POST['gs_date'] ?? '';
    $blood_group = $_POST['blood_group'] ?? '';
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $sub_id_number = trim($_POST['sub_id_number'] ?? '');
    $division_hired = isset($_POST['division_hired']) ? implode(', ', $_POST['division_hired']) : '';
    $scope = (isset($_POST['scope']) && is_array($_POST['scope'])) ? implode(', ', $_POST['scope']) : '';
    $designation = trim($_POST['designation'] ?? '');
    $work_experience = trim($_POST['work_experience'] ?? '');
    $edu_qualifications = trim($_POST['edu_qualifications'] ?? '');
    $voc_qualifications = trim($_POST['voc_qualifications'] ?? '');
    
    $allergies = $_POST['allergies'] ?? 'No';
    $allergy_details = ($allergies === 'Yes') ? trim($_POST['allergy_details'] ?? '') : 'None';
    $safety_agreement = intval($_POST['safety_agreement'] ?? 0);

    // Age validation (18 to 60)
    $birthDate = new DateTime($dob);
    $today = new DateTime('today');
    $age = $birthDate->diff($today)->y;

    if ($scope === '') {
        $error_message = "Error: Please select at least one Division / Scope.";
    } elseif ($age < 18 || $age > 60) {
        $error_message = "Error: Worker must be between 18 and 60 years old. Calculated age is " . $age . " years.";
    } elseif ($safety_agreement !== 1) {
        $error_message = "Error: You must agree to the safety protocols before submitting.";
    } else {
        // ID Photos upload
        $id_front_path = "";
        $id_back_path = "";
        if (isset($_FILES['id_front']) && $_FILES['id_front']['error'] == 0) {
            $id_front_path = s3_store_upload($_FILES['id_front']['tmp_name'], $_FILES['id_front']['name'], 'id', 'front') ?: "";
        }
        if (isset($_FILES['id_back']) && $_FILES['id_back']['error'] == 0) {
            $id_back_path = s3_store_upload($_FILES['id_back']['tmp_name'], $_FILES['id_back']['name'], 'id', 'back') ?: "";
        }

        // NCD Diseases
        $ncd_diseases = [
            "Asthma (ඇදුම)", "Shortness of breath (හුස්ම ගැනීමේ අපහසුතාව)", "Lung problems (පෙනහළු ගැටලු)", 
            "Tuberculosis (ක්ෂය රෝගය)", "Chest pain (පපුවේ වේදනාව)", "Heart attack (හෘදයාබාධ)", 
            "Diabetic (දියවැඩියාව)", "High blood pressure (අධි රුධිර පීඩනය)", "Stroke (ආඝාතය)", 
            "Liver diseases (අක්මා රෝග)", "Hepatitis (හෙපටයිටිස්)", "Migraine (ඔළුවාරදය)", "Regular fainting (නිතර ඇතිවන ක්ලාන්තය)"
        ];
        $ncd_data = [];
        foreach($ncd_diseases as $index => $disease) {
            if(isset($_POST["ncd_$index"])) { $ncd_data[$disease] = $_POST["ncd_$index"]; }
        }
        $ncd_details = json_encode($ncd_data, JSON_UNESCAPED_UNICODE);

        // Chronic Diseases
        $chronic_diseases = [
            "Kidney diseases (වකුගඩු ආබාධ)", "Thyroid disorders (තයිරොයිඩ් ගැටලු)", "Skin Problem/rash (සමේ ගැටලු/ ආසාදන)", 
            "Hearing Loss or ear Problems (ශ්‍රවණ දුබලතා)", "Eye Problems (අක්ෂි ගැටලු)", "Hemophilia (හීමොෆීලියාව)", "Anemia (රක්තහීනතාව)"
        ];
        $chronic_data = [];
        foreach($chronic_diseases as $index => $disease) {
            if(isset($_POST["chronic_$index"])) { $chronic_data[$disease] = $_POST["chronic_$index"]; }
        }
        $chronic_details = json_encode($chronic_data, JSON_UNESCAPED_UNICODE);

        // Mental Health
        $mental_conditions = [
            "Acrophobia (fear of height)", "Achluophobia (fear of darkness)", "Ancraophobia (fear of wind)", 
            "Dystychiphobia (fear of accident)", "Electrophobia (fear of electricity)", "Ligrophobia (fear of loud noises)", 
            "Pyrophobia (fear of fire)", "Phengophobia (fear of daylight)", "Mechanophobia (fear of machine)", 
            "Climacophobia (fear of stairs)", "Chemophobia (fear of chemical)", "Bathophobia (fear of depth)"
        ];
        $mental_data = [];
        foreach($mental_conditions as $index => $condition) {
            if(isset($_POST["mental_$index"])) { $mental_data[$condition] = $_POST["mental_$index"]; }
        }
        $mental_details = json_encode($mental_data, JSON_UNESCAPED_UNICODE);

        // Insert into workers table
        $insert_stmt = $conn->prepare("INSERT INTO workers (subcontractor_id, worker_name, address, nic_number, dob, mobile_number, gender, email, police_date, gs_date, blood_group, emergency_contact, sub_id_number, company_name, company_contact, division_hired, scope, designation, work_experience, edu_qualifications, voc_qualifications, allergies, allergy_details, ncd_details, chronic_details, mental_details, id_front_path, id_back_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

        if ($insert_stmt) {
            $insert_stmt->bind_param("i" . str_repeat("s", 27), $subcontractor_id, $worker_name, $address, $nic_number, $dob, $mobile_number, $gender, $email, $police_date, $gs_date, $blood_group, $emergency_contact, $sub_id_number, $company_name, $company_contact, $division_hired, $scope, $designation, $work_experience, $edu_qualifications, $voc_qualifications, $allergies, $allergy_details, $ncd_details, $chronic_details, $mental_details, $id_front_path, $id_back_path);

            if ($insert_stmt->execute()) {
                // Mark link as used
                $upd = $conn->prepare("UPDATE worker_registration_links SET status = 'used' WHERE token = ?");
                $upd->bind_param("s", $token);
                $upd->execute();
                $upd->close();

                $success_message = "Worker registration and safety induction completed successfully! Awaiting admin approval.";
            } else {
                $error_message = "Error: " . $insert_stmt->error;
            }
            $insert_stmt->close();
        } else {
            $error_message = "Error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Safety Induction &amp; Registration | Hayleys Fentons</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root{--orange:#e15e13;--orange-dark:#bb4c14;--bg:#f4f6f9;--text:#0f172a;--muted:#64748b;--ok:#16a34a;--bad:#dc2626;}
        body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);font-size:12.5px;padding:20px;}
        .wrap{max-width:1000px;margin:0 auto;}
        .head{text-align:center;margin-bottom:18px;}
        .head img{max-width:150px;margin-bottom:6px;}
        .head h1{font-size:21px;font-weight:700;margin:0;}
        .card-box{background:#fff;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 4px 12px -2px rgba(15,23,42,.05);padding:18px;margin-bottom:15px;}
        .steps{display:flex;gap:8px;justify-content:center;margin-bottom:15px;flex-wrap:wrap;}
        .step{padding:5px 14px;border-radius:50px;background:#fff;border:1px solid #e2e8f0;font-weight:500;color:var(--muted);font-size:12px;}
        .step.active{background:#fdece3;color:var(--orange);border-color:#f3c6ab;font-weight:600;}
        .step.done{background:#dcfce7;color:var(--ok);border-color:#bbf7d0;}
        .progress{height:8px;border-radius:10px;}
        .progress-bar{background:var(--orange);}
        .tut-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:15px;}
        .tut-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;cursor:pointer;padding:0;text-align:left;font:inherit;width:100%;position:relative;}
        .tut-thumb{position:relative;aspect-ratio:16/9;background:#1c2733;overflow:hidden;}
        .tut-thumb video{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;}
        .tut-play{position:absolute;inset:0;margin:auto;width:42px;height:42px;border-radius:50%;background:var(--orange);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;}
        .tut-time{position:absolute;right:8px;bottom:8px;background:rgba(0,0,0,.75);color:#fff;font-size:10px;padding:2px 6px;border-radius:6px;}
        .card-prog-wrap{position:absolute;bottom:0;left:0;right:0;height:4px;background:rgba(255,255,255,0.3);}
        .card-prog-bar{height:100%;background:var(--ok);width:0%;}
        .tut-body{padding:10px 13px 13px;}
        .tut-body h3{margin:0 0 4px;font-size:12.5px;font-weight:600;}
        .badge-watched{position:absolute;top:8px;left:8px;background:var(--ok);color:#fff;font-size:10.5px;padding:3px 8px;border-radius:50px;display:none;z-index:2;}
        .tut-card.watched .badge-watched{display:block;}
        .modal-bg{position:fixed;inset:0;background:rgba(10,14,20,.78);display:none;align-items:center;justify-content:center;padding:20px;z-index:2000;}
        .modal-bg.open{display:flex;}
        .modal-box{width:min(940px,100%);background:#0f1720;border-radius:14px;overflow:hidden;}
        .modal-top{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;color:#fff;}
        .modal-top button{background:none;border:0;color:#fff;font-size:1.6rem;cursor:pointer;}
        .player{aspect-ratio:16/9;background:#000;}
        .player video{width:100%;height:100%;display:block;}
        .btn-main{background:var(--orange);color:#fff;border:none;border-radius:8px;font-weight:600;font-size:13px;height:42px;padding:0 24px;}
        .btn-main:hover{background:var(--orange-dark);color:#fff;}
        .btn-main:disabled{background:#cbd5e1;color:#64748b;cursor:not-allowed;}
        .form-section { display: none; }
        .form-section.active { display: block; }
        .q-card{border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:12px;background:#fff;}
        .q-title{font-weight:600;font-size:13.5px;margin-bottom:8px;}
        .opt{display:flex;align-items:flex-start;gap:10px;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:6px;cursor:pointer;font-size:12px;}
        .opt input{margin-top:4px;accent-color:var(--orange);}
        .opt .mark{margin-left:auto;font-weight:700;display:none;}
        .q-card.checked .opt{cursor:default;}
        .opt.correct{background:#dcfce7;border-color:#86efac;}
        .opt.correct .mark{display:block;color:var(--ok);}
        .opt.wrong{background:#fee2e2;border-color:#fca5a5;}
        .opt.wrong .mark{display:block;color:var(--bad);}
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Logo" onerror="this.src='assets/images/logout.png'">
        <h1>Worker Safety Induction &amp; Registration</h1>
        <?php if (!$invalid_link && $subcontractor): ?>
            <p class="text-muted">Registering under Subcontractor: <b><?= htmlspecialchars($subcontractor['company_name']); ?></b> (Vendor Code: <b><?= htmlspecialchars($subcontractor['vendor_code'] ?: 'N/A'); ?></b>)</p>
        <?php endif; ?>
    </div>

    <?php if ($invalid_link): ?>
        <div class="card-box text-center p-5">
            <div class="alert alert-danger mb-2">This worker registration link is invalid, expired, or already used.</div>
            <small class="text-muted"><strong>Reason:</strong> <?= htmlspecialchars($error_reason); ?></small>
        </div>
    <?php elseif (!empty($success_message)): ?>
        <div class="card-box text-center p-5">
            <div class="alert alert-success fs-5 mb-3"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_message); ?></div>
            <p class="text-muted">You can now close this window.</p>
        </div>
    <?php else: ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="steps">
            <span class="step active" id="st1"><i class="bi bi-person-fill me-1"></i>1. Full Form &amp; Health Details</span>
            <span class="step" id="st2"><i class="bi bi-play-circle-fill me-1"></i>2. Safety Videos</span>
            <span class="step" id="st3"><i class="bi bi-ui-checks me-1"></i>3. Induction Quiz &amp; Submit</span>
        </div>

        <form id="workerInductionForm" action="" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">

            <!-- STEP 1: FULL WORKER & HEALTH FORM -->
            <div class="form-section active" id="sec-1">
                <div class="card-box">
                    <h5 class="fw-bold mb-3 text-dark" style="font-size:14px;"><i class="bi bi-person-badge text-warning me-1"></i> Personal &amp; Company Details</h5>
                    <div class="row g-2">
                        <!-- Subcontractor Locked Auto-Filled Details -->
                        <div class="col-md-6">
                            <label class="form-label">Subcontractor Company Name *</label>
                            <input type="text" name="company_name" class="form-control bg-light" value="<?= htmlspecialchars($subcontractor['company_name'] ?? ''); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company Contact Number *</label>
                            <input type="tel" name="company_contact" class="form-control bg-light" value="<?= htmlspecialchars($subcontractor['mobile_number'] ?? ''); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vendor Code (Locked)</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($subcontractor['vendor_code'] ?: 'N/A'); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Business Reg Number (Locked)</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($subcontractor['business_reg_number'] ?: 'N/A'); ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="worker_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address *</label>
                            <input type="text" name="address" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">NIC Number *</label>
                            <input type="text" name="nic_number" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth * (Age 18 - 60)</label>
                            <input type="date" name="dob" id="dobInput" class="form-control" onchange="validateAge()" required>
                            <small id="ageErrorMsg" class="text-danger fw-bold" style="font-size: 11px; display: none;"></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ID Card Front Side Photo *</label>
                            <input type="file" name="id_front" class="form-control" accept="image/*" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ID Card Back Side Photo *</label>
                            <input type="file" name="id_back" class="form-control" accept="image/*" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number *</label>
                            <input type="tel" name="mobile_number" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender *</label>
                            <select name="gender" class="form-select" required>
                                <option value="" disabled selected>Select gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Police Report Issue Date *</label>
                            <input type="date" name="police_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">GS Report Issue Date *</label>
                            <input type="date" name="gs_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Blood Group *</label>
                            <select name="blood_group" class="form-select" required>
                                <option value="" disabled selected>Select blood group</option>
                                <option value="A+">A+</option><option value="A-">A-</option>
                                <option value="B+">B+</option><option value="B-">B-</option>
                                <option value="AB+">AB+</option><option value="AB-">AB-</option>
                                <option value="O+">O+</option><option value="O-">O-</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Emergency Contact *</label>
                            <input type="text" name="emergency_contact" class="form-control" placeholder="Name & contact number" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Subcontractor Worker ID / Badge Number *</label>
                            <input type="text" name="sub_id_number" class="form-control" required>
                        </div>
                    </div>

                    <!-- Division Hired By -->
                    <div class="mt-3">
                        <label class="form-label">Division Hired By</label>
                        <div class="row g-1">
                            <?php
                            $divisions = ["Solar", "MEP", "FIT", "Equipment Trading (EQT)", "FSF", "UPS", "HEL", "Supply Chain", "HR", "S & C", "Transport", "BT", "Marketing", "Compliance", "NEX-GEN", "Other"];
                            foreach($divisions as $div):
                            ?>
                            <div class="col-md-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="division_hired[]" value="<?php echo $div; ?>" id="div_<?php echo md5($div); ?>">
                                    <label class="form-check-label text-muted" for="div_<?php echo md5($div); ?>" style="font-size: 11.5px;"><?php echo $div; ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Scope -->
                    <div class="mt-3">
                        <label class="form-label">Division / Scope * <small class="text-muted">(Select at least one)</small></label>
                        <div class="row g-1 p-2 border rounded bg-light" id="scopeGroup">
                            <?php
                            $scopes = [
                                "HVAC" => "HVAC", "Fire protection Systems" => "Fire protection Systems (ගිනි ආරක්ෂණ පද්ධති)",
                                "Plumbing service" => "Plumbing service (ජලනල සේවා)", "Electrical works" => "Electrical works (විදුලිය ආශ්‍රිත)",
                                "Solar PV system services" => "Solar PV system services", "Civil Engineering" => "Civil Engineering",
                                "Scaffolding" => "Scaffolding", "Other" => "Other"
                            ];
                            foreach ($scopes as $val => $label):
                                $sid = 'scope_' . md5($val);
                            ?>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input scope-checkbox" type="checkbox" name="scope[]" value="<?= htmlspecialchars($val); ?>" id="<?= $sid; ?>" onchange="checkScope()">
                                    <label class="form-check-label text-muted" for="<?= $sid; ?>" style="font-size: 11.5px;"><?= htmlspecialchars($label); ?></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <small id="scopeErrorMsg" class="text-danger fw-bold" style="font-size: 11px; display: none;">Please select at least one Division / Scope.</small>
                    </div>

                    <div id="extra-fields" style="display: none;" class="mt-3">
                        <div class="row g-2">
                            <div class="col-md-6"><label class="form-label">Designation</label><input type="text" class="form-control" name="designation"></div>
                            <div class="col-md-6"><label class="form-label">Work Experience</label><input type="text" class="form-control" name="work_experience"></div>
                            <div class="col-md-6"><label class="form-label">Educational Qualifications</label><input type="text" class="form-control" name="edu_qualifications"></div>
                            <div class="col-md-6"><label class="form-label">Vocational Qualifications</label><input type="text" class="form-control" name="voc_qualifications"></div>
                        </div>
                    </div>
                </div>

                <!-- HEALTH INFORMATION SECTION -->
                <div class="card-box">
                    <h5 class="fw-bold mb-3 text-danger" style="font-size:14px;"><i class="bi bi-heart-pulse-fill me-1"></i> Health Information Form</h5>

                    <!-- NCD Diseases -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">21. Non-Communicable Diseases (බෝ නොවන රෝග) *</label>
                        <div class="row g-2">
                            <?php
                            $ncd_diseases = [
                                "Asthma (ඇදුම)", "Shortness of breath (හුස්ම ගැනීමේ අපහසුතාව)", "Lung problems (පෙනහළු ගැටලු)", 
                                "Tuberculosis (ක්ෂය රෝගය)", "Chest pain (පපුවේ වේදනාව)", "Heart attack (හෘදයාබාධ)", 
                                "Diabetic (දියවැඩියාව)", "High blood pressure (අධි රුධිර පීඩනය)", "Stroke (ආඝාතය)", 
                                "Liver diseases (අක්මා රෝග)", "Hepatitis (හෙපටයිටිස්)", "Migraine (ඔළුවාරදය)", "Regular fainting (නිතර ක්ලාන්තය)"
                            ];
                            foreach($ncd_diseases as $index => $disease): 
                            ?>
                            <div class="col-md-6">
                                <div class="p-2 border bg-light rounded-3 h-100">
                                    <p class="mb-1 fw-medium text-dark" style="font-size: 11.5px;"><?php echo ($index+1) . ". " . $disease; ?></p>
                                    <div class="d-flex justify-content-between gap-1 flex-wrap">
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?php echo $index; ?>" value="Previously diagnosed" required><label class="form-check-label text-muted" style="font-size: 10.5px;">කලින් ඇත</label></div>
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?php echo $index; ?>" value="Currently treatment"><label class="form-check-label text-muted" style="font-size: 10.5px;">ප්‍රතිකාර ගනී</label></div>
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?php echo $index; ?>" value="Not infected"><label class="form-check-label text-muted" style="font-size: 10.5px;">නැත</label></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Chronic Diseases -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">22. Chronic diseases (නිධන්ගත රෝග) *</label>
                        <div class="row g-2">
                            <?php
                            $chronic_diseases = [
                                "Kidney diseases (වකුගඩු ආබාධ)", "Thyroid disorders (තයිරොයිඩ් ගැටලු)", "Skin Problem/rash (සමේ ගැටලු)", 
                                "Hearing Loss or ear Problems (ශ්‍රවණ දුබලතා)", "Eye Problems (අක්ෂි ගැටලු)", "Hemophilia (හීමොෆීලියාව)", "Anemia (රක්තහීනතාව)"
                            ];
                            foreach($chronic_diseases as $index => $disease): 
                            ?>
                            <div class="col-md-6">
                                <div class="p-2 border bg-light rounded-3 h-100">
                                    <p class="mb-1 fw-medium text-dark" style="font-size: 11.5px;"><?php echo $disease; ?></p>
                                    <div class="d-flex justify-content-between gap-1 flex-wrap">
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?php echo $index; ?>" value="Previously diagnosed" required><label class="form-check-label text-muted" style="font-size: 10.5px;">කලින් ඇත</label></div>
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?php echo $index; ?>" value="Currently treatment"><label class="form-check-label text-muted" style="font-size: 10.5px;">ප්‍රතිකාර ගනී</label></div>
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?php echo $index; ?>" value="Not infected"><label class="form-check-label text-muted" style="font-size: 10.5px;">නැත</label></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Mental Health -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">24. Mental Health Conditions (මානසික සෞඛ්‍ය තත්ත්ව) *</label>
                        <div class="row g-2">
                            <?php
                            $mental_conditions = [
                                "Acrophobia (fear of height)", "Achluophobia (fear of darkness)", "Ancraophobia (fear of wind)", 
                                "Dystychiphobia (fear of accident)", "Electrophobia (fear of electricity)", "Ligrophobia (fear of loud noises)", 
                                "Pyrophobia (fear of fire)", "Phengophobia (fear of daylight)", "Mechanophobia (fear of machine)", 
                                "Climacophobia (fear of stairs)", "Chemophobia (fear of chemical)", "Bathophobia (fear of depth)"
                            ];
                            foreach($mental_conditions as $index => $condition): 
                            ?>
                            <div class="col-md-6">
                                <div class="p-2 border bg-light rounded-3 h-100">
                                    <p class="mb-1 fw-medium text-dark" style="font-size: 11.5px;"><?php echo $condition; ?></p>
                                    <div class="d-flex justify-content-between gap-1 flex-wrap">
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?php echo $index; ?>" value="Previously diagnosed" required><label class="form-check-label text-muted" style="font-size: 10.5px;">කලින් ඇත</label></div>
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?php echo $index; ?>" value="Currently treatment"><label class="form-check-label text-muted" style="font-size: 10.5px;">ප්‍රතිකාර ගනී</label></div>
                                        <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?php echo $index; ?>" value="Not infected"><label class="form-check-label text-muted" style="font-size: 10.5px;">නැත</label></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Allergies -->
                    <div class="mb-2">
                        <label class="form-label fw-bold">25. Any allergies? (ආසාත්මිකතා තිබේද) *</label>
                        <select name="allergies" id="allergiesSelect" class="form-select" onchange="toggleAllergyInput()" required>
                            <option value="" disabled selected>Select your answer</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                        </select>
                    </div>

                    <div class="mb-2" id="allergyDetailsDiv" style="display: none;">
                        <label class="form-label">Please specify your allergies *</label>
                        <input type="text" name="allergy_details" id="allergyDetailsInput" class="form-control" placeholder="Enter allergies...">
                    </div>
                </div>

                <div class="text-end">
                    <button type="button" class="btn-main" onclick="goToVideos()">Proceed to Safety Videos <i class="bi bi-arrow-right ms-1"></i></button>
                </div>
            </div>

            <!-- STEP 2: SAFETY VIDEOS -->
            <div class="form-section" id="sec-2">
                <div class="card-box">
                    <h5 class="fw-bold mb-2 text-dark" style="font-size:14px;"><i class="bi bi-play-btn-fill text-warning me-1"></i> Mandatory Safety Training Videos</h5>
                    <p class="text-muted small">Watch all videos fully. You cannot proceed to the quiz until all videos are watched.</p>
                    <div class="d-flex justify-content-between mb-2">
                        <b>Watched:</b> <span id="watchCount">0 / <?= count($videos) ?></span>
                    </div>
                    <div class="progress mb-3"><div class="progress-bar" id="watchBar" style="width:0%"></div></div>

                    <div class="tut-grid">
                        <?php foreach ($videos as $i =>$v): ?>
                        <button type="button" class="tut-card" data-vid="<?= (int)$v['id'] ?>" data-url="<?= htmlspecialchars(media_url($v['src'])) ?>" data-title="<?= htmlspecialchars($v['title']) ?>">
                            <span class="badge-watched"><i class="bi bi-check-lg"></i> Watched</span>
                            <div class="tut-thumb">
                                <video src="<?= htmlspecialchars(media_url($v['src'])) ?>#t=1" preload="metadata" muted></video>
                                <span class="tut-play"><i class="bi bi-play-fill"></i></span>
                                <span class="tut-time"><?= htmlspecialchars($v['duration'] ?? '0:00') ?></span>
                                <div class="card-prog-wrap"><div class="card-prog-bar" id="cProg_<?= (int)$v['id'] ?>"></div></div>
                            </div>
                            <div class="tut-body">
                                <h3><?= ($i + 1) . '. ' . htmlspecialchars($v['title']) ?></h3>
                            </div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="toStep(1)">Back</button>
                    <button type="button" class="btn-main" id="toQuizBtn" disabled onclick="goToQuiz()">Proceed to Quiz <i class="bi bi-arrow-right ms-1"></i></button>
                </div>
            </div>

            <!-- STEP 3: QUIZ & FINAL AGREEMENT -->
            <div class="form-section" id="sec-3">
                <div class="card-box">
                    <h5 class="fw-bold mb-2 text-dark" style="font-size:14px;"><i class="bi bi-ui-checks text-warning me-1"></i> Induction Safety Quiz</h5>
                    <p class="text-muted small">Answer all questions correctly.</p>
                    <div id="quizContainer"></div>
                </div>

                <div class="card-box border-warning bg-light">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="safety_agreement" id="safetyAgreement" value="1" required>
                        <label class="form-check-label fw-bold text-dark" for="safetyAgreement">
                            I understand and agree to all safety protocols, rules, and regulations of Hayleys Fentons.
                        </label>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="toStep(2)">Back</button>
                    <button type="submit" class="btn-main" id="finalSubmitBtn"><i class="bi bi-check-circle-fill me-1"></i> Submit Registration</button>
                </div>
            </div>
        </form>

    <?php endif; ?>
</div>

<!-- Video Play Modal -->
<div class="modal-bg" id="tutModal">
    <div class="modal-box">
        <div class="modal-top"><h3 id="tutTitle" class="m-0 fs-6"></h3><button type="button" id="tutClose">&times;</button></div>
        <div class="player" id="tutPlayer"></div>
    </div>
</div>

<script>
const QUESTIONS = <?= json_encode($tutorialQuestions, JSON_UNESCAPED_UNICODE) ?>;
const TOTAL_VIDEOS = <?= count($videos) ?>;
let watchedVideos = new Set();

function toStep(n) {
    document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
    document.getElementById('sec-' + n).classList.add('active');
    ['st1', 'st2', 'st3'].forEach((id, idx) => {
        const el = document.getElementById(id);
        el.className = 'step ' + (idx + 1 === n ? 'active' : (idx + 1 < n ? 'done' : ''));
    });
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function validateAge() {
    let dobInput = document.getElementById('dobInput').value;
    let errorMsg = document.getElementById('ageErrorMsg');
    if (!dobInput) return true;
    let dob = new Date(dobInput);
    let today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    let m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) { age--; }

    if (age < 18 || age > 60) {
        errorMsg.style.display = 'block';
        errorMsg.innerText = `Error: Worker must be between 18 and 60 years old. Current calculated age is ${age}.`;
        return false;
    } else {
        errorMsg.style.display = 'none';
        errorMsg.innerText = '';
        return true;
    }
}

function checkScope() {
    const anyChecked = document.querySelectorAll('.scope-checkbox:checked').length > 0;
    document.getElementById("extra-fields").style.display = anyChecked ? "block" : "none";
    if (anyChecked) { document.getElementById('scopeErrorMsg').style.display = 'none'; }
}

function toggleAllergyInput() {
    let selectVal = document.getElementById('allergiesSelect').value;
    let allergyDiv = document.getElementById('allergyDetailsDiv');
    let allergyInput = document.getElementById('allergyDetailsInput');
    if (selectVal === 'Yes') {
        allergyDiv.style.display = 'block';
        allergyInput.setAttribute('required', 'required');
    } else {
        allergyDiv.style.display = 'none';
        allergyInput.removeAttribute('required');
        allergyInput.value = '';
    }
}

function goToVideos() {
    const sec1 = document.getElementById('sec-1');
    const req = sec1.querySelectorAll('[required]');
    for (let el of req) {
        if (!el.checkValidity()) { el.reportValidity(); el.focus(); return; }
    }
    if (sec1.querySelectorAll('.scope-checkbox:checked').length === 0) {
        document.getElementById('scopeErrorMsg').style.display = 'block';
        return;
    }
    if (!validateAge()) {
        alert("Worker age must be between 18 and 60 years.");
        return;
    }
    toStep(2);
}

function goToQuiz() {
    buildQuiz();
    toStep(3);
}

// Video handling
const modal = document.getElementById('tutModal');
const player = document.getElementById('tutPlayer');
let currentVidId = null;

document.querySelectorAll('.tut-card').forEach(card => {
    card.addEventListener('click', () => {
        currentVidId = +card.dataset.vid;
        document.getElementById('tutTitle').textContent = card.dataset.title;
        player.innerHTML = `<video src="${card.dataset.url}" controls autoplay controlsList="nodownload"></video>`;
        const vid = player.querySelector('video');

        vid.addEventListener('ended', () => {
            watchedVideos.add(currentVidId);
            card.classList.add('watched');
            document.getElementById('cProg_' + currentVidId).style.width = '100%';
            updateVideoProgress();
        });
        modal.classList.add('open');
    });
});

document.getElementById('tutClose').onclick = () => { modal.classList.remove('open'); player.innerHTML = ''; };

function updateVideoProgress() {
    const count = watchedVideos.size;
    document.getElementById('watchCount').textContent = count + ' / ' + TOTAL_VIDEOS;
    document.getElementById('watchBar').style.width = (count / TOTAL_VIDEOS * 100) + '%';
    if (count >= TOTAL_VIDEOS) {
        const btn = document.getElementById('toQuizBtn');
        btn.disabled = false;
        btn.innerHTML = 'Proceed to Quiz <i class="bi bi-arrow-right ms-1"></i>';
    }
}

// Build Quiz
function buildQuiz() {
    const box = document.getElementById('quizContainer');
    if (box.children.length > 0) return;
    QUESTIONS.forEach((q, qi) => {
        let html = `<div class="q-card"><div class="q-title">${qi + 1}. ${q.q}</div>`;
        q.o.forEach((opt, oi) => {
            html += `<label class="opt"><input type="radio" name="quiz_ans[${qi}]" value="${oi}" required> <span>${String.fromCharCode(65 + oi)}. ${opt}</span></label>`;
        });
        html += `</div>`;
        box.insertAdjacentHTML('beforeend', html);
    });
}
</script>
</body>
</html>