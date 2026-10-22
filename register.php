<?php
session_start();

$success_message = "";
$error_message   = "";

// Which form? subcontractor | supervisor | (none)
$type = $_GET['type'] ?? '';
if (!in_array($type, ['subcontractor', 'supervisor'], true)) {
    $type = '';
}

$divisions = ["Solar", "MEP", "FIT", "HEL", "FSF", "UPS", "Transport", "EQT", "HR", "BT", "Supply Chain", "Compliance and ESG", "S & C", "NEX-GEN", "Marketing"];

function old($key) {
    return isset($_POST[$key]) ? htmlspecialchars($_POST[$key]) : '';
}

/* =====================================================
   POST HANDLING
===================================================== */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    /* ---------- SUBCONTRACTOR ---------- */
    if ($type === 'subcontractor') {

        if (empty($_POST['division']) || !is_array($_POST['division'])) {
            $error_message = "Please select at least one division (අඩුම තරමින් එක් අංශයක්වත් තෝරන්න).";
        } elseif ($_POST['password'] !== $_POST['password_confirm']) {
            $error_message = "Passwords do not match (මුරපද දෙක ගැළපෙන්නේ නැත).";
        } elseif (!isset($_POST['agreement_accept'])) {
            $error_message = "Please accept the agreement before continuing.";
        } else {
            $company_name = trim($_POST['company_name'] ?? '');
            $_SESSION['pending_subcontractor_registration'] = [
                'vendor_code'                => trim($_POST['vendor_code'] ?? ''),
                'company_name'               => $company_name,
                'company_address'            => trim($_POST['company_address'] ?? ''),
                'business_reg_number'        => trim($_POST['business_reg_number'] ?? ''),
                'business_reg_date'          => $_POST['business_reg_date'] ?? '',
                'epf_number'                 => trim($_POST['epf_number'] ?? ''),
                'etf_number'                 => trim($_POST['etf_number'] ?? ''),
                'owner_name'                 => trim($_POST['owner_name'] ?? ''),
                'owner_address'              => trim($_POST['owner_address'] ?? ''),
                'owner_id_number'            => trim($_POST['owner_id_number'] ?? ''),
                'owner_gender'               => $_POST['owner_gender'] ?? '',
                'owner_dob'                  => $_POST['owner_dob'] ?? '',
                'mobile_number'              => trim($_POST['mobile_number'] ?? ''),
                'email'                      => trim($_POST['email'] ?? ''),
                'username'                   => trim($_POST['username'] ?? ''),
                'password_hash'              => password_hash($_POST['password'], PASSWORD_DEFAULT),
                'agreement_effective_date'   => $_POST['agreement_effective_date'] ?? '',
                'agreement_termination_date' => $_POST['agreement_termination_date'] ?? '',
                'division'                   => implode(', ', array_map('trim', $_POST['division'])),
                'scope'                      => $_POST['scope'] ?? '',
                'machineries'                => trim($_POST['machineries'] ?? ''),
                'tools'                      => trim($_POST['tools'] ?? ''),
                'male_workers'               => (int)($_POST['male_workers'] ?? 0),
                'female_workers'             => (int)($_POST['female_workers'] ?? 0),
                'agreement_accept'           => 1,
            ];

            $_SESSION['registered_company'] = $company_name;
            $_SESSION['registration_pending_tutorial'] = true;
            unset(
                $_SESSION['registered_id'],
                $_SESSION['quiz_saved'],
                $_SESSION['quiz_answers'],
                $_SESSION['watched_videos'],
                $_SESSION['video_progress']
            );
            header("Location: tutorial.php");
            exit();
        }
    }

    /* ---------- SUPERVISOR ---------- */
    if ($type === 'supervisor') {
        require_once __DIR__ . '/db_config.php';

        $sup_name     = trim($_POST['sup_name'] ?? '');
        $sup_nic      = trim($_POST['sup_nic'] ?? '');
        $sup_division = trim($_POST['sup_division'] ?? '');
        $sup_address  = trim($_POST['sup_division_address'] ?? '');
        $sup_phone    = trim($_POST['sup_phone'] ?? '');
        $sup_email    = trim($_POST['sup_email'] ?? '');
        $sup_username = trim($_POST['sup_username'] ?? '');
        $sup_password_raw     = $_POST['sup_password'] ?? '';
        $sup_password_confirm = $_POST['sup_password_confirm'] ?? '';

        if ($sup_name === '' || $sup_nic === '' || $sup_division === '' || $sup_username === '' || $sup_password_raw === '') {
            $error_message = "Please fill in all required fields including Division.";
        } elseif ($sup_password_raw !== $sup_password_confirm) {
            $error_message = "Passwords do not match.";
        } elseif (strlen($sup_password_raw) < 6) {
            $error_message = "Password must be at least 6 characters long.";
        } else {
            $check = $conn->prepare("SELECT id FROM supervisors WHERE username = ?");
            $check->bind_param("s", $sup_username);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error_message = "This username is already taken. Please choose another.";
                $check->close();
            } else {
                $check->close();
                $sup_password = password_hash($sup_password_raw, PASSWORD_DEFAULT);
                $status = 'Pending';

                $stmt = $conn->prepare(
                    "INSERT INTO supervisors (name, nic, division, division_address, phone, email, username, password, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("sssssssss",
                    $sup_name, $sup_nic, $sup_division, $sup_address,
                    $sup_phone, $sup_email, $sup_username, $sup_password, $status
                );

                if ($stmt->execute()) {
                    $success_message = "Registration submitted successfully.\n\nYour account is waiting for Admin Approval.\n\nYou can login only after the administrator approves your account.";
                } else {
                    $error_message = "Error: " . $conn->error;
                }
                $stmt->close();
            }
        }
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration | Hayleys Fentons</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root { --accent: #ea580c; --accent-dark: #c2410c; --accent-soft: rgba(234,88,12,.15); --bg1:#f8fafc; --bg2:#e2e8f0; }
        body.t-subcontractor { --accent:#098336; --accent-dark:#04451c; --accent-soft:rgba(22,163,74,.15); --bg1:#f0fdf4; --bg2:#dcfce7; }
        body.t-supervisor    { --accent:#ea580c; --accent-dark:#c2410c; --accent-soft:rgba(234,88,12,.15); --bg1:#f8fafc; --bg2:#e2e8f0; }

        body{
            background: linear-gradient(135deg, var(--bg1) 0%, var(--bg2) 100%);
            min-height: 100vh;
            display: flex; justify-content: center; align-items: flex-start;
            font-family: 'Inter', sans-serif;
            padding: 20px; color: #1e293b; font-size: 12.5px;
        }
        .register-card{
            width: 100%; max-width: 820px; border: none; border-radius: 12px;
            overflow: hidden; box-shadow: 0 8px 20px -4px rgba(0,0,0,.1); background: #fff;
        }
        .card-header{ background:#fff; text-align:center; padding:20px 20px 14px; border-bottom:1px solid #f1f5f9; }
        .logo-img{ max-width:180px; height:auto; margin-bottom:8px; object-fit:contain; }
        .card-header h2{ font-size:17px; font-weight:700; color:#0f172a; margin-bottom:4px; }
        .card-header p{ font-size:11.5px; color:#64748b; line-height:1.4; margin-bottom:0; }
        .card-body{ padding:20px 30px; background:#fff; }

        /* Type switcher tabs */
        .type-tabs{ display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:0; }
        .type-tab{
            display:flex; align-items:center; justify-content:center; gap:8px;
            padding:10px 12px; border-radius:8px; text-decoration:none;
            font-size:12.5px; font-weight:600; color:#475569;
            border:1.5px solid #cbd5e1; background:#f8fafc; transition:all .2s;
        }
        button.type-tab{ width:100%; cursor:pointer; font-family:inherit; margin:0; }
        .type-tab:hover{ border-color:var(--accent); color:var(--accent); }
        .type-tab.active{ background:var(--accent); border-color:var(--accent); color:#fff; }
        .type-tab.tab-sub.active{ background:#098336; border-color:#098336; }
        .type-tab.tab-sup.active{ background:#ea580c; border-color:#ea580c; }
        .hdr h2{ margin-top:4px; }

        .choose-hint{ text-align:center; padding:30px 10px; color:#64748b; font-size:13px; }
        .choose-hint i{ font-size:34px; color:#94a3b8; display:block; margin-bottom:8px; }

        .form-label{ font-size:11.5px; font-weight:600; color:#334155; margin-bottom:3px; }
        .form-control, .form-select{
            height:35px; font-size:12px; border-radius:5px;
            border:1px solid #cbd5e1; background-color:#f8fafc;
            padding:4px 12px; transition:all .2s ease-in-out;
        }
        textarea.form-control{ height:auto; }
        .form-control:focus, .form-select:focus{
            background-color:#fff; border-color:var(--accent);
            box-shadow:0 0 0 2px var(--accent-soft);
        }
        .mb-3{ margin-bottom:10px !important; }
        .mb-4{ margin-bottom:12px !important; }
        .btn-register{
            height:38px; font-size:12.5px; border-radius:5px; font-weight:600;
            background-color:var(--accent); border:none; color:#fff; transition:background-color .2s;
        }
        .btn-register:hover{ background-color:var(--accent-dark); color:#fff; }
        .alert{ padding:8px 12px; font-size:11.5px; margin-bottom:10px; }
        .form-check-label{ font-size:11.5px; }
        .back-login{ text-align:center; margin-top:14px; font-size:12px; }
        .back-login a{ color:var(--accent); font-weight:600; text-decoration:none; }
        .pw-toggle{ height:35px; border-color:#cbd5e1; background:#f8fafc; }
    </style>
</head>
<body class="<?php echo $type ? 't-' . $type : ''; ?>">

<div class="card register-card">

    <div class="card-header">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons Logo" class="logo-img">

        <div class="hdr <?php echo $type === '' ? '' : 'd-none'; ?>" data-for="">
            <h2>Create an Account</h2>
            <p>Select your registration type below to load the relevant form.</p>
        </div>

        <div class="hdr <?php echo $type === 'subcontractor' ? '' : 'd-none'; ?>" data-for="subcontractor">
            <h2>External Service Provider Registration</h2>
            <p>
                (Please enter your details below to join the service as a subcontractor affiliated with Hayleys Fentons. This must be updated once every year)<br>
                හේලිස් ෆෙන්ටන්ස් ආයතනයට අනුබද්ධ උපකොන්ත්‍රාත්කරුවෙකු ලෙස සේවයට එක් වන ඔබගේ විස්තර පහත ඇතුළත් කරන්න. මෙය සෑම වසරකටම වරක් යාවත්කාලීන කළ යුතුය.<br>
                <strong>Document Number:</strong> HF/COM/ESPR/Rev0
            </p>
        </div>

        <div class="hdr <?php echo $type === 'supervisor' ? '' : 'd-none'; ?>" data-for="supervisor">
            <h2>Supervisor Registration</h2>
            <p>Create your supervisor account. Your request will be reviewed by an administrator before you can log in.</p>
        </div>
    </div>

    <div class="card-body">

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo nl2br(htmlspecialchars($success_message)); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Registration type buttons -->
        <div class="type-tabs mb-3" id="typeButtons">
            <button type="button" class="type-tab tab-sub <?php echo $type === 'subcontractor' ? 'active' : ''; ?>" data-type="subcontractor">
                <i class="bi bi-person-plus"></i> Subcontractor
            </button>
            <button type="button" class="type-tab tab-sup <?php echo $type === 'supervisor' ? 'active' : ''; ?>" data-type="supervisor">
                <i class="bi bi-person-badge"></i> Supervisor
            </button>
        </div>

        <div id="chooseHint" class="choose-hint <?php echo $type === '' ? '' : 'd-none'; ?>">
            <i class="bi bi-hand-index-thumb"></i>
            Click <strong>Subcontractor</strong> or <strong>Supervisor</strong> above to load the form.
        </div>

        <!-- =================== SUBCONTRACTOR FORM =================== -->
        <div id="panel-subcontractor" class="reg-panel <?php echo $type === 'subcontractor' ? '' : 'd-none'; ?>">
        <!-- =================== SUBCONTRACTOR FORM =================== -->
        <form action="register.php?type=subcontractor" method="POST" id="registrationForm">

            <div class="mb-3">
                <label class="form-label">Vendor Code (වෙන්ඩර් කේතය) *</label>
                <input type="text" name="vendor_code" class="form-control" maxlength="50" value="<?php echo old('vendor_code'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">1. Company Name (නාමය) *</label>
                <input type="text" name="company_name" class="form-control" value="<?php echo old('company_name'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">2. Company Address (ලිපිනය) *</label>
                <input type="text" name="company_address" class="form-control" value="<?php echo old('company_address'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">3. Business Registration Number (ලියාපදිංචි සහතික අංකය) *</label>
                <input type="text" name="business_reg_number" class="form-control" value="<?php echo old('business_reg_number'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">4. Business Registration Issued Date (ලියාපදිංචි සහතිකය නිකුත් කළ දිනය) *</label>
                <input type="date" name="business_reg_date" class="form-control" value="<?php echo old('business_reg_date'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">5. EPF Register number of the company (ආයතනයේ EPF ලියාපදිංචි අංකය)</label>
                <input type="text" name="epf_number" class="form-control" value="<?php echo old('epf_number'); ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">6. ETF Register Number of the company (ආයතනයේ ETF ලියාපදිංචි අංකය)</label>
                <input type="text" name="etf_number" class="form-control" value="<?php echo old('etf_number'); ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">7. Name of the company owner (හිමිකරුගේ නම) *</label>
                <input type="text" name="owner_name" class="form-control" value="<?php echo old('owner_name'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">8. Address of the company owner ( හිමිකරුගේ ලිපිනය) *</label>
                <input type="text" name="owner_address" class="form-control" value="<?php echo old('owner_address'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">9. ID number company owner (හිමිකරුගේ ජා.හැ. අංකය) *</label>
                <input type="text" name="owner_id_number" class="form-control" value="<?php echo old('owner_id_number'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">10. Gender of the Company Owner ( හිමිකරුගේ ස්ත්‍රී/ පුරුෂ භාවය) *</label>
                <select name="owner_gender" class="form-select" required>
                    <option value="" disabled <?php echo !isset($_POST['owner_gender']) ? 'selected' : ''; ?>>Select your answer</option>
                    <?php
                    $genders = [
                        "Male" => "Male (පුරුෂ)",
                        "Female" => "Female (ස්ත්‍රී)",
                        "Non-binary" => "Non-binary (ස්ත්‍රී/පුරුෂ භාවයට අයත් නොවන)",
                        "Prefer not to say" => "Prefer not to say (පවසන්න කැමති නැත)"
                    ];
                    foreach ($genders as $val => $label) {
                        $sel = (isset($_POST['owner_gender']) && $_POST['owner_gender'] == $val) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($val) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">11. Date of Birth of the Company Owner ( හිමිකරුගේ උපන් දිනය) *</label>
                <input type="date" name="owner_dob" class="form-control" value="<?php echo old('owner_dob'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">12. Mobile Number (දුරකථන අංකය) *</label>
                <input type="tel" name="mobile_number" class="form-control" value="<?php echo old('mobile_number'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">13. E - mail address (විදුයුත් තැපැල් ලිපිනය) *</label>
                <input type="email" name="email" class="form-control" value="<?php echo old('email'); ?>" required>
            </div>

            <div class="row g-2">
                <div class="col-md-12 mb-2">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" value="<?php echo old('username'); ?>" required>
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label">Confirm Password *</label>
                    <input type="password" name="password_confirm" id="password_confirm" class="form-control" required>
                    <small id="passwordError" class="text-danger d-none">Passwords do not match.</small>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">14. Agreement effective date (ගිවිසුම් ගත දිනය) *</label>
                <input type="date" name="agreement_effective_date" class="form-control" value="<?php echo old('agreement_effective_date'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">15. Agreement termination date (ගිවිසුම අවසන් වන දිනය) *</label>
                <input type="date" name="agreement_termination_date" class="form-control" value="<?php echo old('agreement_termination_date'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">16. Division hired by (බඳවා ගනු ලබන අංශය) *</label>
                <div class="row ps-1 g-2">
                    <?php
                    foreach ($divisions as $index => $div_name) {
                        $div_id  = "div" . ($index + 1);
                        $checked = (isset($_POST['division']) && is_array($_POST['division']) && in_array($div_name, $_POST['division'])) ? 'checked' : '';
                        echo '<div class="col-md-4 form-check"><input class="form-check-input division-checkbox" type="checkbox" name="division[]" value="' . htmlspecialchars($div_name) . '" id="' . $div_id . '" ' . $checked . '><label class="form-check-label" for="' . $div_id . '">' . htmlspecialchars($div_name) . '</label></div>';
                    }
                    ?>
                </div>
                <small id="divisionError" class="text-danger d-none">Please select at least one division.</small>
            </div>

            <div class="mb-3">
                <label class="form-label">17. Scope (කරනු ලබන කාර්යය) *</label>
                <select name="scope" class="form-select" required>
                    <option value="" disabled <?php echo !isset($_POST['scope']) ? 'selected' : ''; ?>>Select your answer</option>
                    <?php
                    $scopes = [
                        "HVAC", "Fire protection Systems (ගිනි ආරක්ෂණ පද්ධති)", "Plumbing service (ජලනල සේවා)",
                        "Electrical works (විදුලිය ආශ්‍රිත කාර්යයන්)", "Solar PV system services (සූර්ය PV පද්ධති සේවා)", "Networking (ජාලකරණය)",
                        "Civil Engineering (සිවිල් ඉංජිනේරු)", "Steel works (වානේ සම්බන්ධ කාර්යයන්)", "Scaffolding (පළංචි)",
                        "Life Line (ජීවිත ආරක්ෂණ කේබල්)", "Transportation (ප්‍රවාහන)", "Stores Management (ගබඩා කළමනාකරණය)",
                        "Tea Making (තේ සැදීම)", "ELV", "Security Services (ආරක්ෂක සේවා)", "Camera Systems (කැමරා පද්ධති)",
                        "Cleaning (පිරිසිදු කිරීම)", "Kitchen Services (මුළුතැන්ගේ සේවා)", "Pest Control (පළිබෝධ පාලනය)",
                        "Glass Cleaning (වීදුරු පිරිසිදු කිරීම)", "Telecommunication (සන්නිවේදන)", "Other"
                    ];
                    foreach ($scopes as $s) {
                        $sel = (isset($_POST['scope']) && $_POST['scope'] == $s) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($s) . '" ' . $sel . '>' . htmlspecialchars($s) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">18. Machineries available (Enter with the quantities you have) - (ඔබ සතු යන්ත්‍ර - ඔබට තිබෙන ප්‍රමාණය සමඟ ඇතුළත් කරන්න)</label>
                <small class="text-muted d-block mb-1" style="font-size:10.5px;">ex: fork lift - 01, JCB 03, excavator- 01, auger -01</small>
                <textarea name="machineries" class="form-control" rows="2"><?php echo old('machineries'); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">19. Tools available (Enter with the quantities you have) - (ඔබ සතු උපකරණ - ඔබට තිබෙන ප්‍රමාණය සමඟ ඇතුළත් කරන්න)</label>
                <small class="text-muted d-block mb-1" style="font-size:10.5px;">ex -grinder - 01, drill - 02, multimeter - 03, welding plant-01</small>
                <textarea name="tools" class="form-control" rows="2"><?php echo old('tools'); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">20. Number of male workers ( සේවකයන් සංඛ්‍යාව -පුරුෂ) - [If none, please enter "0" සේවකයන් නොමැති නම් “0” ලෙස ඇතුළත් කරන්න.] *</label>
                <input type="number" name="male_workers" class="form-control" min="0" value="<?php echo old('male_workers'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">21. Number of female workers (සේවකයන් සංඛ්‍යාව -ස්ත්‍රී) - [If none, please enter "0" සේවකයන් නොමැති නම් “0” ලෙස ඇතුළත් කරන්න.] *</label>
                <input type="number" name="female_workers" class="form-control" min="0" value="<?php echo old('female_workers'); ?>" required>
            </div>

            <div class="mb-4 form-check">
                <input type="checkbox" name="agreement_accept" class="form-check-input" id="acceptCheck" <?php echo isset($_POST['agreement_accept']) ? 'checked' : ''; ?> required>
                <label class="form-check-label" for="acceptCheck" style="cursor:pointer;">
                    22. I have read and understand the guidelines given and agree (මාර්ගෝපදේශය කියවා අවබෝධ කර ගත් අතර ඒ සමඟ එකඟ වෙමි) * <br>
                    <strong>I accept (එකඟ වෙමි)</strong>
                </label>
            </div>

            <div class="alert alert-info py-2 px-3 mb-3" style="font-size:11px; background-color:#f0fdf4; border-color:#bbf7d0; color:#166534;">
                <i class="bi bi-info-circle-fill me-1"></i>
                <strong>Note:</strong> Please verify all entered details before clicking next. Form data will be retained if session refreshes.
            </div>

            <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:11.5px; background-color:#fefce8; border-color:#fde047; color:#854d0e;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>විශේෂ දැනුම්දීමයි:</strong> ඉදිරිබටනය (Next) ක්ලික් කළ පසු වීඩියෝ නැරඹීමේ ප්‍රශ්න මාලාවක් (Quiz) පැවැත්වේ. මෙම පෝරමය සාර්ථකව අවසන් කිරීමට නම් එම වීඩියෝ නරඹා ප්‍රශ්න මාලාවට අනිවාර්යයෙන්ම පිළිතුරු සැපයිය යුතුය.
            </div>

            <div class="d-grid mt-2">
                <button type="submit" class="btn btn-register">
                    Next <i class="bi bi-arrow-right-circle-fill ms-1"></i>
                </button>
            </div>
        </form>
        </div>

        <!-- =================== SUPERVISOR FORM =================== -->
        <div id="panel-supervisor" class="reg-panel <?php echo $type === 'supervisor' ? '' : 'd-none'; ?>">
        <?php if (empty($success_message)): ?>
        <form action="register.php?type=supervisor" method="POST">

            <div class="mb-3">
                <label class="form-label">Full Name *</label>
                <input type="text" name="sup_name" class="form-control" value="<?php echo old('sup_name'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">NIC Number *</label>
                <input type="text" name="sup_nic" class="form-control" value="<?php echo old('sup_nic'); ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Division *</label>
                <select name="sup_division" class="form-select" required>
                    <option value="" disabled <?php echo empty($_POST['sup_division']) ? 'selected' : ''; ?>>Select your division (අංශයක් තෝරන්න)</option>
                    <?php
                    foreach ($divisions as $d) {
                        $sel = (isset($_POST['sup_division']) && $_POST['sup_division'] === $d) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($d) . '" ' . $sel . '>' . htmlspecialchars($d) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Division Address</label>
                <input type="text" name="sup_division_address" class="form-control" value="<?php echo old('sup_division_address'); ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Phone Number</label>
                <input type="tel" name="sup_phone" class="form-control" value="<?php echo old('sup_phone'); ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="sup_email" class="form-control" value="<?php echo old('sup_email'); ?>">
            </div>

            <div class="row g-2">
                <div class="col-md-12 mb-2">
                    <label class="form-label">Username *</label>
                    <input type="text" name="sup_username" class="form-control" value="<?php echo old('sup_username'); ?>" required>
                </div>

                <div class="col-md-6 mb-2">
                    <label class="form-label">Password *</label>
                    <div class="input-group">
                        <input type="password" name="sup_password" id="sup_password" class="form-control" required minlength="6">
                        <button class="btn btn-outline-secondary pw-toggle" type="button" id="togglePassword"><i class="bi bi-eye" id="eyeIcon1"></i></button>
                    </div>
                </div>

                <div class="col-md-6 mb-2">
                    <label class="form-label">Confirm Password *</label>
                    <div class="input-group">
                        <input type="password" name="sup_password_confirm" id="sup_password_confirm" class="form-control" required minlength="6">
                        <button class="btn btn-outline-secondary pw-toggle" type="button" id="toggleConfirmPassword"><i class="bi bi-eye" id="eyeIcon2"></i></button>
                    </div>
                </div>
            </div>

            <div class="d-grid mt-2">
                <button type="submit" class="btn btn-register">
                    <i class="bi bi-send-check-fill me-1"></i> Submit Registration
                </button>
            </div>
        </form>
        <?php endif; ?>
        </div>

        <div class="back-login">
            Already have an account? <a href="index.php">Back to Login</a>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    /* ---------- Subcontractor form validation ---------- */
    const regForm = document.getElementById('registrationForm');
    if (regForm) {
        regForm.addEventListener('submit', function (event) {
            const checkboxes = document.querySelectorAll('.division-checkbox');
            let isChecked = false;
            checkboxes.forEach(function (cb) { if (cb.checked) isChecked = true; });

            const divisionError = document.getElementById('divisionError');
            if (!isChecked) {
                event.preventDefault();
                divisionError.classList.remove('d-none');
                window.scrollTo({ top: divisionError.offsetTop - 100, behavior: 'smooth' });
                return;
            } else {
                divisionError.classList.add('d-none');
            }

            const pw  = document.getElementById('password').value;
            const pwc = document.getElementById('password_confirm').value;
            const passwordError = document.getElementById('passwordError');
            if (pw !== pwc) {
                event.preventDefault();
                passwordError.classList.remove('d-none');
                document.getElementById('password_confirm').focus();
            } else {
                passwordError.classList.add('d-none');
            }
        });
    }

    /* ---------- Supervisor password show/hide ---------- */
    function bindToggle(btnId, inputId, iconId) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            const input = document.getElementById(inputId);
            const icon  = document.getElementById(iconId);
            const show  = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('bi-eye', !show);
            icon.classList.toggle('bi-eye-slash', show);
        });
    }
    bindToggle('togglePassword', 'sup_password', 'eyeIcon1');
    bindToggle('toggleConfirmPassword', 'sup_password_confirm', 'eyeIcon2');

    /* ---------- Registration type buttons: show only the chosen form ---------- */
    (function () {
        const buttons = document.querySelectorAll('#typeButtons .type-tab');

        function applyType(type) {
            document.body.classList.remove('t-subcontractor', 't-supervisor');
            if (type) document.body.classList.add('t-' + type);

            buttons.forEach(function (b) {
                b.classList.toggle('active', b.dataset.type === type);
            });
            document.querySelectorAll('.hdr').forEach(function (h) {
                h.classList.toggle('d-none', h.dataset.for !== type);
            });
            document.querySelectorAll('.reg-panel').forEach(function (p) {
                p.classList.toggle('d-none', p.id !== 'panel-' + type);
            });
            document.getElementById('chooseHint').classList.toggle('d-none', !!type);
        }

        buttons.forEach(function (b) {
            b.addEventListener('click', function () {
                applyType(b.dataset.type);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    })();
</script>
</body>
</html>