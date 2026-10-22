<?php
session_start();

if (!isset($_SESSION['subcontractor_id'])) {
    header("Location: index.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

$sub_id = $_SESSION['subcontractor_id'];
if (empty($_SESSION['worker_link_csrf'])) {
    $_SESSION['worker_link_csrf'] = bin2hex(random_bytes(32));
}
$stmt = $conn->prepare("SELECT * FROM subcontractors WHERE id = ?");
if (!$stmt) {
    die("Database query failed: " . $conn->error);
}
$stmt->bind_param("i", $sub_id);
$stmt->execute();
$result = $stmt->get_result();
$sub = $result->fetch_assoc();
$stmt->close();

$sub_phone = $sub['phone'] ?? 'N/A';

// Vendor code of this subcontractor (shown in the header)
$vendor_code = trim($sub['vendor_code'] ?? '');

// 1. Pending Permits Count
$pending_count = 0;
$pending_stmt = $conn->prepare("SELECT * FROM service_provider_permits WHERE subcontractor_id = ? AND status = 'Pending'");
if ($pending_stmt) {
    $pending_stmt->bind_param("i", $sub_id);
    $pending_stmt->execute();
    $pending_permits_result = $pending_stmt->get_result();
    $pending_count = $pending_permits_result->num_rows;
    $pending_stmt->close();
} else {
    error_log("Pending permits count query failed: " . $conn->error);
}

// 2. Registered (Approved) Workers Count + Workers waiting for admin approval
$total_workers = 0;
$pending_workers_count = 0;
$workers_count_stmt = $conn->prepare("SELECT
        SUM(status = 'Approved') AS approved_total,
        SUM(status = 'Pending')  AS pending_total
    FROM workers WHERE subcontractor_id = ?");
if ($workers_count_stmt) {
    $workers_count_stmt->bind_param("i", $sub_id);
    $workers_count_stmt->execute();
    $workers_count_row = $workers_count_stmt->get_result()->fetch_assoc();
    $total_workers = intval($workers_count_row['approved_total'] ?? 0);
    $pending_workers_count = intval($workers_count_row['pending_total'] ?? 0);
    $workers_count_stmt->close();
} else {
    error_log("Workers count query failed: " . $conn->error);
}

$success_message = "";
$error_message = "";

// Handle Delete Request
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $del_stmt = $conn->prepare("DELETE FROM workers WHERE id = ? AND subcontractor_id = ?");
    if ($del_stmt) {
        $del_stmt->bind_param("ii", $delete_id, $sub_id);
        if ($del_stmt->execute()) {
            $del_stmt->close();
            header("Location: " . $current_page . "?deleted=1");
            exit();
        } else {
            $error_message = "Error deleting worker: " . $del_stmt->error;
        }
        $del_stmt->close();
    } else {
        $error_message = "Error deleting worker: " . $conn->error;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $subcontractor_id = $_SESSION['subcontractor_id'];
    $company_name = $sub['company_name'] ?? '';
    
    $worker_name = $_POST['worker_name'];
    $address = $_POST['address'];
    $nic_number = $_POST['nic_number'];
    $dob = $_POST['dob'];
    $mobile_number = $_POST['mobile_number'];
    $gender = $_POST['gender'];
    $email = $_POST['email'];
    $police_date = $_POST['police_date'];
    $gs_date = $_POST['gs_date'];
    $blood_group = $_POST['blood_group'];
    $emergency_contact = $_POST['emergency_contact'];
    $sub_id_number = $_POST['sub_id_number'];
    $company_contact = $_POST['company_contact'];
    $division_hired = isset($_POST['division_hired']) ? implode(', ', $_POST['division_hired']) : '';
    $scope = (isset($_POST['scope']) && is_array($_POST['scope'])) ? implode(', ', $_POST['scope']) : '';
    $designation = $_POST['designation'];
    $work_experience = $_POST['work_experience'];
    $edu_qualifications = $_POST['edu_qualifications'];
    $voc_qualifications = $_POST['voc_qualifications'];
    
    $allergies = $_POST['allergies'];
    // "Yes" nam user type karapu details gannawa, nathnam "None" kiyala save wenawa
    $allergy_details = ($allergies === 'Yes') ? trim($_POST['allergy_details'] ?? '') : 'None';

    // Age validation (18 to 60 years old check)
    $birthDate = new DateTime($dob);
    $today = new DateTime('today');
    $age = $birthDate->diff($today)->y;

    if ($scope === '') {
        $error_message = "Error: Please select at least one Division / Scope.";
    } elseif ($age < 18 || $age > 60) {
        $error_message = "Error: Worker must be between 18 and 60 years old. Calculated age is " . $age . " years.";
    } else {
        // Handle ID Card Photos Uploads (Front & Back).
        // These are national identity documents. When S3 is enabled they
        // go to the private bucket and are only ever reachable through
        // media.php, which checks for a live session first.
        $id_front_path = "";
        $id_back_path = "";

        if (isset($_FILES['id_front']) && $_FILES['id_front']['error'] == 0) {
            $stored = s3_store_upload(
                $_FILES['id_front']['tmp_name'],
                $_FILES['id_front']['name'],
                'id',
                'front'
            );
            $id_front_path = $stored ?: "";
        }

        if (isset($_FILES['id_back']) && $_FILES['id_back']['error'] == 0) {
            $stored = s3_store_upload(
                $_FILES['id_back']['tmp_name'],
                $_FILES['id_back']['name'],
                'id',
                'back'
            );
            $id_back_path = $stored ?: "";
        }

        // 1. NCD Diseases Data Collection
        $ncd_diseases = [
            "Asthma (ඇදුම)", "Shortness of breath (හුස්ම ගැනීමේ අපහසුතාව)", "Lung problems (පෙනහළු ගැටලු)", 
            "Tuberculosis (ක්ෂය රෝගය)", "Chest pain (පපුවේ වේදනාව)", "Heart attack (හෘදයාබාධ)", 
            "Diabetic (දියවැඩියාව)", "High blood pressure (අධි රුධිර පීඩනය)", "Stroke (ආඝාතය)", 
            "Liver diseases (අක්මා රෝග)", "Hepatitis (හෙපටයිටිස්)", "Migraine (ඔළුවාරදය)", "Regular fainting (නිතර ඇතිවන ක්ලාන්තය)"
        ];
        $ncd_data = [];
        foreach($ncd_diseases as $index => $disease) {
            if(isset($_POST["ncd_$index"])) {
                $ncd_data[$disease] = $_POST["ncd_$index"];
            }
        }
        $ncd_details = json_encode($ncd_data, JSON_UNESCAPED_UNICODE);

        // 2. Chronic Diseases Data Collection
        $chronic_diseases = [
            "Kidney diseases (වකුගඩු ආබාධ)", "Thyroid disorders (තයිරොයිඩ් ගැටලු)", "Skin Problem/rash (සමේ ගැටලු/ ආසාදන)", 
            "Hearing Loss or ear Problems (ශ්‍රවණ දුබලතා)", "Eye Problems (අක්ෂි ගැටලු)", "Hemophilia (හීමොෆීලියාව)", "Anemia (රක්තහීනතාව)"
        ];
        $chronic_data = [];
        foreach($chronic_diseases as $index => $disease) {
            if(isset($_POST["chronic_$index"])) {
                $chronic_data[$disease] = $_POST["chronic_$index"];
            }
        }
        $chronic_details = json_encode($chronic_data, JSON_UNESCAPED_UNICODE);

        // 3. Mental Health Conditions Data Collection
        $mental_conditions = [
            "Acrophobia (fear of height)", "Achluophobia (fear of darkness)", "Ancraophobia (fear of wind)", 
            "Dystychiphobia (fear of accident)", "Electrophobia (fear of electricity)", "Ligrophobia (fear of loud noises)", 
            "Pyrophobia (fear of fire)", "Phengophobia (fear of daylight)", "Mechanophobia (fear of machine)", 
            "Climacophobia (fear of stairs)", "Chemophobia (fear of chemical)", "Bathophobia (fear of depth)"
        ];
        $mental_data = [];
        foreach($mental_conditions as $index => $condition) {
            if(isset($_POST["mental_$index"])) {
                $mental_data[$condition] = $_POST["mental_$index"];
            }
        }
        $mental_details = json_encode($mental_data, JSON_UNESCAPED_UNICODE);

        // Insert query updated with allergy_details
        $insert_stmt = $conn->prepare("INSERT INTO workers (subcontractor_id, worker_name, address, nic_number, dob, mobile_number, gender, email, police_date, gs_date, blood_group, emergency_contact, sub_id_number, company_name, company_contact, division_hired, scope, designation, work_experience, edu_qualifications, voc_qualifications, allergies, allergy_details, ncd_details, chronic_details, mental_details, id_front_path, id_back_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

        if ($insert_stmt) {
            $insert_stmt->bind_param("i" . str_repeat("s", 27), $subcontractor_id, $worker_name, $address, $nic_number, $dob, $mobile_number, $gender, $email, $police_date, $gs_date, $blood_group, $emergency_contact, $sub_id_number, $company_name, $company_contact, $division_hired, $scope, $designation, $work_experience, $edu_qualifications, $voc_qualifications, $allergies, $allergy_details, $ncd_details, $chronic_details, $mental_details, $id_front_path, $id_back_path);

            if ($insert_stmt->execute()) {
                $insert_stmt->close();
                $conn->close();
                header("Location: " . $current_page . "?success=1");
                exit();
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
    <title>Worker Management | QHSE EN</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
:root {
    --primary-orange: #e15e13;
    --primary-orange-dark: #bb4c14;
    --bg-app: #f4f6f9;
    --card-bg: #ffffff;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --border-color: #cbd5e1;
    --card-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.05);
}

body {
    min-height: 100vh;
    font-family: 'Poppins', sans-serif;
    background-color: var(--bg-app);
    color: var(--text-main);
    margin: 0;
    display: flex;
    font-size: 12.5px;
    -webkit-font-smoothing: antialiased;
}

h1, h2, h3, h4, h5 {
    font-family: 'Poppins', sans-serif;
    letter-spacing: -0.01em;
}

.sidebar-outer-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0px;
    margin: 4px 0 20px 15px;
    position: sticky;
    top: 4px;
    height: calc(100vh - 24px);
    flex-shrink: 0;
    z-index: 1000;
}

.sidebar-logo-outside {
    width: 90px;
    height: 90px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: -8px;
    margin-bottom: -15px;
    transition: transform 0.3s ease;
}

.sidebar-logo-outside:hover {
    transform: scale(1.05);
}

.sidebar-logo-outside img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.navbar-custom {
    background: linear-gradient(180deg, var(--primary-orange) 0%, var(--primary-orange-dark) 100%);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 35px;
    margin: 0;
    padding: 22px 10px;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.08);
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #fff;
    font-family: 'Inter', sans-serif;
    width: 85px; 
    flex: 1;
    min-height: 0;
    position: static;
    flex-shrink: 0;
    transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-x: hidden;
}

.navbar-custom:hover {
    width: 230px;
    padding: 22px 16px;
}

.navbar-inner-content {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
}

.mobile-dropdown-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    height: 100%;
}

.sidebar-menu {
    display: flex;
    flex-direction: column;
    gap: 14px;
    justify-content: center;
    align-items: center;
    width: 100%;
    padding: 10px 0;
    margin: auto 0;
}

.sidebar-menu-item {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 14px;
    padding: 10px 12px;
    border-radius: 16px;
    color: #ffffff; 
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    width: 100%;
    white-space: nowrap;
}

.sidebar-menu-item i {
    font-size: 18px;
    color: #ffffff;
    min-width: 24px;
    text-align: center;
    transition: transform 0.3s ease;
}

.sidebar-menu-item span.nav-text,
.logout-btn span {
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

.navbar-custom:hover .sidebar-menu-item span.nav-text,
.navbar-custom:hover .logout-btn span {
    opacity: 1;
    visibility: visible;
}

.sidebar-menu-item:hover {
    background: rgba(255, 255, 255, 0.22);
    transform: translateX(4px);
}

.sidebar-menu-item:hover i {
    transform: scale(1.15);
}

.sidebar-menu-item.active {
    background: rgba(255, 255, 255, 0.32);
    box-shadow: 0 10px 22px rgba(0, 0, 0, 0.22);
    font-weight: 700;
}

.right-section { 
    display: flex; 
    flex-direction: column; 
    align-items: center; 
    width: 100%; 
    border-top: 1px solid rgba(255, 255, 255, 0.18); 
    padding-top: 16px; 
    margin-top: auto; 
    flex-shrink: 0;
}

.logout-container {
    display: flex;
    justify-content: center;
    width: 100%;
}

.logout-btn {
    width: 100%;
    height: 44px;
    padding: 0 12px;
    border-radius: 14px;
    background: rgba(0, 0, 0, 0.2); 
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 14px;
    text-decoration: none;
    border: 1px solid rgba(255, 255, 255, 0.2);
    font-weight: 600;
    font-size: 13px;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.logout-btn:hover { 
    background: #ef4444; 
    border-color: #ef4444; 
    color: #fff;
    transform: translateX(4px);
}

.logout-btn i { 
    font-size: 16px; 
    color: #fff;
    min-width: 24px;
    text-align: center;
}

.mobile-toggle-btn {
    display: none;
    background: transparent;
    border: none;
    color: #fff;
    font-size: 24px;
    cursor: pointer;
    padding: 0 4px;
}

.main-content-wrapper {
    flex: 1;
    padding: 15px;
    max-width: calc(100vw - 100px);
    overflow-y: auto;
}

.dashboard-header {
    background: transparent;
    border-radius: 0;
    padding: 10px 0;
    box-shadow: none;
    border: none;
    margin-bottom: 20px;
    position: relative;
}

.header-title h2 {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 2px;
}

.typing-text {
    color: #e15e13 !important;
}

.header-title p {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 0;
}

.pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 50px;
    font-size: 11.5px;
    font-weight: 500;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.pill-badge.alert {
    background: rgba(249, 115, 22, 0.08);
    color: #c2410c;
    border-color: rgba(249, 115, 22, 0.15);
}

.stat-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 15px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
    display: flex;
    align-items: center;
    gap: 15px;
    height: 100%;
}

.stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.stat-icon.workers {
    background: rgba(2, 132, 199, 0.1);
    color: #0284c7;
}

.stat-info h3 {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 0;
    color: var(--text-main);
}

.stat-info p {
    font-size: 11px;
    color: var(--text-muted);
    margin-bottom: 0;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    font-weight: 600;
}

.content-box {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 15px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-bottom: 15px;
}

.form-label {
    font-weight: 600;
    color: #1e293b;
    font-size: 13px;
    margin-bottom: 4px;
}

.form-control, .form-select {
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 7px 12px;
    font-size: 13px;
    color: #0f172a;
    background-color: #fff;
    transition: all 0.2s ease-in-out;
}

.form-control:focus, .form-select:focus {
    border-color: #e15e13;
    box-shadow: 0 0 0 3px rgba(225, 94, 19, 0.15);
}

.form-step { display: none; }
.form-step.active { display: block; }

.reg-code-box {
    background: #fff;
    border: 1px dashed var(--primary-orange);
    border-radius: 10px;
    padding: 6px 12px;
}

@media (min-width: 1200px) {
    .modal-xl-custom {
        max-width: 1140px !important;
    }
}

.btn-custom-action {
    background-color: var(--primary-orange);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 10px 24px;
    font-weight: 600;
    font-size: 14px;
    width: 100%;
    box-shadow: 0 4px 12px rgba(225, 94, 19, 0.25);
    transition: all 0.2s ease;
}

.btn-custom-action:hover {
    background-color: var(--primary-orange-dark);
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(225, 94, 19, 0.35);
}

.btn-nav-action {
    background-color: var(--primary-orange);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 20px;
    font-weight: 600;
    font-size: 13.5px;
    box-shadow: 0 4px 12px rgba(225, 94, 19, 0.25);
    transition: all 0.2s ease;
}

.btn-nav-action:hover {
    background-color: var(--primary-orange-dark);
    color: #fff;
}

#workersTable {
    border-collapse: separate;
    border-spacing: 0;
}

#workersTable thead.table-dark-custom th {
    background-color: #0d1117;
    color: #ffffff;
    font-weight: 600;
    font-size: 12px;
    letter-spacing: 0.05em;
    padding: 12px 16px;
    border: none;
    text-transform: uppercase;
}

#workersTable thead.table-dark-custom tr:first-child th:first-child {
    border-top-left-radius: 12px;
}
#workersTable thead.table-dark-custom tr:first-child th:last-child {
    border-top-right-radius: 12px;
}

@media (max-width: 992px) {
    body { flex-direction: column; }
    
    .sidebar-outer-wrap {
        flex-direction: row;
        align-items: center;
        width: calc(100% - 24px) !important;
        height: auto !important;
        margin: 12px;
        position: sticky;
        top: 12px;
        gap: 10px;
    }
    
    .sidebar-logo-outside {
        width: 42px;
        height: 42px;
        margin-bottom: 0;
    }
    
    .navbar-custom {
        width: 100% !important;
        flex: 1;
        height: auto !important;
        margin: 0;
        padding: 10px 16px !important;
        border-radius: 22px;
        overflow: visible;
        position: static;
    }
    
    .navbar-custom:hover {
        width: 100% !important;
        padding: 10px 16px !important;
    }
    
    .navbar-inner-content {
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }
    
    .mobile-toggle-btn {
        display: block;
        margin-left: auto;
    }
    
    .mobile-dropdown-wrapper {
        display: none;
        position: absolute;
        top: calc(100% + 10px);
        left: 0;
        right: 0;
        background: linear-gradient(180deg, var(--primary-orange) 0%, var(--primary-orange-dark) 100%);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.25);
        border-radius: 22px;
        padding: 20px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        flex-direction: column;
        gap: 14px;
        height: auto;
        z-index: 1001;
        justify-content: flex-start;
        align-items: stretch;
    }
    
    .mobile-dropdown-wrapper.show {
        display: flex;
    }
    
    .sidebar-menu {
        display: flex !important;
        width: 100%;
        gap: 10px;
        align-items: stretch;
        margin: 0;
        padding-top: 0;
        flex-grow: 0;
    }
    
    .sidebar-menu-item span.nav-text,
    .logout-btn span {
        opacity: 1 !important;
        visibility: visible !important;
    }
    
    .sidebar-menu-item {
        justify-content: flex-start;
        padding: 12px 16px;
    }
    
    .right-section {
        border-top: 1px solid rgba(255, 255, 255, 0.22);
        padding-top: 16px;
        margin-top: 0;
        width: 100%;
    }
    
    .logout-btn {
        height: 46px;
        padding: 0 16px;
        justify-content: center;
    }

    .main-content-wrapper { max-width: 100%; padding: 12px; }
}
</style>
<link rel="stylesheet" href="subcontractor_navbar.css">
</head>
<body>

<?php include __DIR__ . '/subcontractor_navbar.php'; ?>

<div class="main-content-wrapper">
    <div class="container-fluid p-0">
        
        <div class="dashboard-header d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="header-title">
                <h2 class="m-0">
                     <span id="typedWelcome" class="typing-text"></span>
                </h2>
                <p class="m-0 mt-1">BR Number: <span class="fw-bold text-dark"><?= htmlspecialchars($sub['business_reg_number'] ?? 'N/A'); ?></span> &bull; Vendor Code: <span class="fw-bold text-dark"><?= htmlspecialchars($vendor_code !== '' ? $vendor_code : 'N/A'); ?></span> &bull; Worker Portal & Management</p>
            </div>
            <div class="header-meta d-flex align-items-center gap-2 flex-wrap">
                <div class="pill-badge alert">
                    <span class="spinner-grow spinner-grow-sm text-warning" style="width: 5px; height: 5px;" role="status"></span>
                    Pending: <strong><?= $pending_count; ?></strong>
                </div>
                <div class="pill-badge">
                    <i class="bi bi-calendar3 text-warning"></i> <span id="liveDate"></span>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-icon workers">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $total_workers; ?></h3>
                        <p>Registered Workers (Approved)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(249, 115, 22, 0.1); color: #ea580c;">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $pending_workers_count; ?></h3>
                        <p>Waiting for Admin Approval</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm py-2 px-3 mb-3 fs-6" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> Worker details submitted successfully. The worker will be registered once the admin approves the request.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['deleted'])): ?>
            <div class="alert alert-warning alert-dismissible fade show rounded-3 shadow-sm py-2 px-3 mb-3 fs-6" role="alert">
                <i class="bi bi-trash-fill me-2"></i> Worker record successfully deleted from the list.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if(!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm py-2 px-3 mb-3 fs-6" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="content-box mb-3" id="workersListSection">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-list-task text-warning me-1"></i> Submitted Workers List</h5>
                    <p class="text-muted m-0" style="font-size: 12px;">Manage and review workers registered under your company</p>
                </div>
                
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="input-group" style="width: 250px;">
                        <span class="input-group-text bg-white border-end-0" style="border-color: var(--border-color);">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" id="workerSearchInput" class="form-control border-start-0 ps-0" placeholder="Search code, name, NIC, mobile..." onkeyup="filterWorkersTable()">
                    </div>

                    <button type="button" class="btn btn-outline-warning" id="createWorkerInviteBtn" data-csrf="<?= htmlspecialchars($_SESSION['worker_link_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bi bi-link-45deg me-1"></i> Share Registration Link
                    </button>
                    <button class="btn btn-nav-action" data-bs-toggle="modal" data-bs-target="#workerModal">
                        <i class="bi bi-person-plus-fill me-1"></i> Add Workers
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="workersTable">
                    <thead class="table-dark-custom">
                        <tr>
                            <th>Worker Code <i class="bi bi-unfold ms-1" style="font-size: 10px; opacity: 0.6;"></i></th>
                            <th>Worker Name <i class="bi bi-unfold ms-1" style="font-size: 10px; opacity: 0.6;"></i></th>
                            <th>NIC Number <i class="bi bi-unfold ms-1" style="font-size: 10px; opacity: 0.6;"></i></th>
                            <th>Mobile Number <i class="bi bi-unfold ms-1" style="font-size: 10px; opacity: 0.6;"></i></th>
                            <th>Division Hired <i class="bi bi-unfold ms-1" style="font-size: 10px; opacity: 0.6;"></i></th>
                            <th>Status</th>
                            <th>Action <i class="bi bi-unfold ms-1" style="font-size: 10px; opacity: 0.6;"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $conn_workers = new mysqli($servername, $username, $password, $dbname);
                        $conn_workers->set_charset("utf8mb4");

                        $limit = 10; 
                        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
                        if ($page < 1) $page = 1;
                        $offset = ($page - 1) * $limit;

                        $total_stmt = $conn_workers->prepare("SELECT COUNT(*) as count FROM workers WHERE subcontractor_id = ?");
                        $total_stmt->bind_param("i", $sub_id);
                        $total_stmt->execute();
                        $total_result = $total_stmt->get_result()->fetch_assoc();
                        $total_records = $total_result['count'];
                        $total_pages = ceil($total_records / $limit);
                        $total_stmt->close();

                        $workers_stmt_table = $conn_workers->prepare("SELECT * FROM workers WHERE subcontractor_id = ? ORDER BY id DESC LIMIT ? OFFSET ?");
                        $workers_stmt_table->bind_param("iii", $sub_id, $limit, $offset);
                        $workers_stmt_table->execute();
                        $result_workers_table = $workers_stmt_table->get_result();
                        
                        if ($result_workers_table->num_rows > 0) {
                            while($row = $result_workers_table->fetch_assoc()) {
                                $workerJson = htmlspecialchars(json_encode(media_rowify($row, ['id_front_path', 'id_back_path'])), ENT_QUOTES, 'UTF-8');
                                $row_code = !empty($row['worker_unique_code']) ? htmlspecialchars($row['worker_unique_code']) : '-';
                                $st = $row['status'] ?? 'Pending';
                                $st_class = ($st === 'Approved') ? 'bg-success' : (($st === 'Rejected') ? 'bg-danger' : 'bg-secondary');
                                echo "<tr class='worker-row'>";
                                echo "<td class='worker-code'><span class='badge bg-dark'>" . $row_code . "</span></td>";
                                echo "<td class='fw-bold worker-name'>" . htmlspecialchars($row['worker_name']) . "</td>";
                                echo "<td class='worker-nic'>" . htmlspecialchars($row['nic_number']) . "</td>";
                                echo "<td class='worker-mobile'>" . htmlspecialchars($row['mobile_number']) . "</td>";
                                echo "<td><span class='badge bg-warning text-dark'>" . htmlspecialchars($row['division_hired']) . "</span></td>";
                                echo "<td><span class='badge $st_class'>" . htmlspecialchars($st) . "</span></td>";
                                echo "<td>
                                        <div class='d-flex gap-1'>
                                            <button type='button' class='btn btn-outline-success btn-sm rounded-pill px-3 py-1 view-worker-btn' data-worker='$workerJson' data-bs-toggle='modal' data-bs-target='#viewWorkerModal'><i class='bi bi-eye me-1'></i> View</button>
                                            <a href='" . $current_page . "?delete_id=" . $row['id'] . "' class='btn btn-outline-danger btn-sm rounded-pill px-2 py-1' onclick='return confirm(\"Are you sure you want to delete this worker record?\");' title='Delete Worker'><i class='bi bi-trash'></i></a>
                                        </div>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr id='noWorkersFoundRow'><td colspan='7' class='text-center text-muted py-4'><i class='bi bi-inbox fs-3 d-block mb-1 text-warning'></i>No workers submitted yet. Click 'Add Workers' to register.</td></tr>";
                        }
                        $workers_stmt_table->close();
                        $conn_workers->close();
                        ?>
                        <tr id="noSearchResultsRow" style="display: none;">
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="bi bi-search fs-3 d-block mb-1 text-warning"></i>No matching workers found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-end mb-0" style="font-size: 12px;">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?= $page - 1; ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo; Previous</span>
                            </a>
                        </li>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?= $i; ?>"><?= $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?= $page + 1; ?>" aria-label="Next">
                                <span aria-hidden="true">Next &raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>

    </div>
</div>

<div class="modal fade" id="workerInviteLinkModal" tabindex="-1" aria-labelledby="workerInviteLinkTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title" id="workerInviteLinkTitle"><i class="bi bi-person-plus-fill me-2"></i>Worker Registration Link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small">Share this one-use link with the worker. It expires in 14 days.</p>
                <div class="input-group">
                    <input type="text" class="form-control" id="workerInviteLinkInput" readonly aria-label="Worker registration link">
                    <button type="button" class="btn btn-outline-secondary" id="copyWorkerInviteLink" title="Copy link"><i class="bi bi-clipboard"></i></button>
                </div>
                <div class="small text-danger mt-2" id="workerInviteLinkError" hidden></div>
            </div>
        </div>
    </div>
</div>

<!-- VIEW WORKER DETAILS MODAL -->
<div class="modal fade" id="viewWorkerModal" tabindex="-1" aria-labelledby="viewWorkerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-xl-custom">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="border-top: 5px solid #e15e13 !important;">
            <div class="modal-header bg-white border-bottom position-relative px-4 py-3 d-flex flex-column align-items-center text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-1" style="max-width: 140px;">
                    <img src="assets/images/hayleys_logo.png" alt="Hayleys Logo" class="img-fluid" style="max-height: 40px; object-fit: contain;" onerror="this.src='assets/images/logout.png'">
                </div>
                <h4 class="modal-title fw-bold text-dark mb-0" id="viewWorkerModalLabel" style="font-size: 1.15rem;">Worker Details Profile</h4>
                <p class="text-muted mb-0" style="font-size: 11.5px;">Complete database record for the selected worker.</p>
            </div>
            <div class="modal-body p-3 bg-light" style="max-height: 75vh; overflow-y: auto;">
                <div class="row g-2">
                    <div class="col-md-8">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Worker Unique Code</span>
                            <span class="fw-bold" style="color: #bb4c14; font-size: 15px;" id="v_worker_unique_code">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Approval Status</span>
                            <span class="fw-bold text-dark" id="v_status">-</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">ID / Sub-ID</span>
                            <span class="fw-bold text-dark" id="v_id">-</span> / <span class="fw-bold text-primary" id="v_sub_id_number">-</span>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Worker Full Name</span>
                            <span class="fw-bold text-dark" id="v_worker_name">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">NIC Number</span>
                            <span class="fw-bold text-dark" id="v_nic_number">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Date of Birth & Gender</span>
                            <span class="fw-bold text-dark" id="v_dob">-</span> (<span id="v_gender">-</span>)
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Mobile Number</span>
                            <span class="fw-bold text-dark" id="v_mobile_number">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Email Address</span>
                            <span class="fw-bold text-dark text-truncate d-block" id="v_email">-</span>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="p-2 bg-white rounded-2 shadow-sm">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Residential Address</span>
                            <span class="fw-bold text-dark" id="v_address">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Police Report Date</span>
                            <span class="fw-bold text-dark" id="v_police_date">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">GS Report Date</span>
                            <span class="fw-bold text-dark" id="v_gs_date">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Blood & Emergency</span>
                            <span class="fw-bold text-danger" id="v_blood_group">-</span> | <span class="fw-bold text-dark" id="v_emergency_contact">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Company Name & Contact</span>
                            <span class="fw-bold text-dark" id="v_company_name">-</span> (<span id="v_company_contact">-</span>)
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Division Hired By</span>
                            <span class="fw-bold text-dark" id="v_division_hired">-</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Scope & Designation</span>
                            <span class="fw-bold text-dark" id="v_scope">-</span> / <span class="fw-bold text-dark" id="v_designation">-</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Experience & Allergies</span>
                            <span class="fw-bold text-dark" id="v_work_experience">-</span> Yrs &bull; Allergies: <span class="text-danger fw-bold" id="v_allergies">-</span> (<span id="v_allergy_details" class="text-dark"></span>)
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-2 bg-white rounded-2 shadow-sm h-100">
                            <span class="text-muted d-block" style="font-size: 10.5px;">Educational & Vocational Qualifications</span>
                            <span class="fw-bold text-dark" id="v_edu_qualifications">-</span> / <span class="fw-bold text-dark" id="v_voc_qualifications">-</span>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="p-2 bg-white rounded-2 shadow-sm">
                            <span class="text-muted d-block mb-1 fw-bold" style="font-size: 11px;"><i class="bi bi-file-earmark-image text-warning me-1"></i> NIC / ID Card Photos</span>
                            <div class="row g-2">
                                <div class="col-md-6 text-center">
                                    <span class="d-block text-secondary" style="font-size: 10px;">Front Side</span>
                                    <div id="v_id_front_container">-</div>
                                </div>
                                <div class="col-md-6 text-center">
                                    <span class="d-block text-secondary" style="font-size: 10px;">Back Side</span>
                                    <div id="v_id_back_container">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="p-2 bg-white rounded-2 shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-2 border-bottom pb-1">
                                <h6 class="fw-bold text-dark m-0" style="font-size: 12px;"><i class="bi bi-heart-pulse text-danger me-1"></i> Health Information Details</h6>
                                <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-0.5" style="font-size: 10px;">Medical Records</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="p-2 rounded-2 border bg-light h-100">
                                        <div class="d-flex align-items-center mb-1"><i class="bi bi-activity text-warning me-1"></i><span class="fw-bold text-dark" style="font-size: 11px;">NCD Diseases</span></div>
                                        <div id="v_ncd_details" style="font-size: 11px;">-</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2 rounded-2 border bg-light h-100">
                                        <div class="d-flex align-items-center mb-1"><i class="bi bi-shield-exclamation text-danger me-1"></i><span class="fw-bold text-dark" style="font-size: 11px;">Chronic Diseases</span></div>
                                        <div id="v_chronic_details" style="font-size: 11px;">-</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2 rounded-2 border bg-light h-100">
                                        <div class="d-flex align-items-center mb-1"><i class="bi bi-emoji-smile text-primary me-1"></i><span class="fw-bold text-dark" style="font-size: 11px;">Mental Health Conditions</span></div>
                                        <div id="v_mental_details" style="font-size: 11px;">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="p-1 bg-white rounded-2 shadow-sm text-end">
                            <span class="text-muted" style="font-size: 10.5px;">Record Created At: <span class="fw-bold text-dark" id="v_created_at">-</span></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top px-4 py-2">
                <button type="button" class="btn btn-outline-secondary px-3 py-1 rounded-8" style="font-size: 12.5px;" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- WORKER INDUCTION FORM MODAL (Add Worker) -->
<div class="modal fade" id="workerModal" tabindex="-1" aria-labelledby="workerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-xl-custom">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="border-top: 5px solid #e15e13 !important;">
            <div class="modal-header bg-white border-bottom position-relative px-4 py-3 d-flex flex-column align-items-center text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-1" style="max-width: 140px;">
                    <img src="assets/images/hayleys_logo.png" alt="Hayleys Logo" class="img-fluid" style="max-height: 45px; object-fit: contain;" onerror="this.src='assets/images/logout.png'">
                </div>
                <h4 class="modal-title fw-bold text-dark mb-1" id="workerModalLabel" style="font-size: 1.25rem;"> Worker Induction</h4>
                <p class="text-muted mb-0" style="font-size: 12px;">Create your worker induction record. Worker age must be between 18 and 60 years. The worker will be registered after admin approval.</p>
            </div>
            <div class="modal-body p-4 bg-light" style="max-height: 75vh; overflow-y: auto;">

                <form action="" method="POST" enctype="multipart/form-data" id="inductionForm" onsubmit="return handleFormSubmit(this)">
                    <div class="form-step active" id="step-1">

                        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                            <h6 class="fw-bold m-0 text-dark" style="font-size: 13.5px;">Personal & Company Information</h6>
                            <div class="reg-code-box text-end">
                                <span class="text-muted d-block" style="font-size: 10.5px;">Worker Registration Code</span>
                                <span class="badge bg-dark px-3 py-2" style="font-size: 11.5px;">
                                    <i class="bi bi-lock-fill me-1"></i> Assigned after admin approval
                                </span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="worker_name" class="form-control" placeholder="Enter your full name" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Address *</label>
                                <input type="text" name="address" class="form-control" placeholder="Enter your address" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">NIC Number *</label>
                                <input type="text" name="nic_number" class="form-control" placeholder="Enter your NIC number" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Date of Birth * (Must be between 18 & 60 years)</label>
                                <input type="date" name="dob" id="dobInput" class="form-control" onchange="validateAge()" required>
                                <small id="ageErrorMsg" class="text-danger fw-bold" style="font-size: 11px; display: none;"></small>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">ID Card Front Side Photo *</label>
                                <input type="file" name="id_front" class="form-control" accept="image/*" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">ID Card Back Side Photo *</label>
                                <input type="file" name="id_back" class="form-control" accept="image/*" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Mobile Number *</label>
                                <input type="tel" name="mobile_number" class="form-control" placeholder="Enter mobile number" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Gender *</label>
                                <select name="gender" class="form-select" required>
                                    <option value="" disabled selected>Select your gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="Enter email address">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Police Report Issue Date *</label>
                                <input type="date" name="police_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">GS Report Issue Date *</label>
                                <input type="date" name="gs_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Blood Group *</label>
                                <select name="blood_group" class="form-select" required>
                                    <option value="" disabled selected>Select blood group</option>
                                    <option value="A+">A+</option>
                                    <option value="A-">A-</option>
                                    <option value="B+">B+</option>
                                    <option value="B-">B-</option>
                                    <option value="AB+">AB+</option>
                                    <option value="AB-">AB-</option>
                                    <option value="O+">O+</option>
                                    <option value="O-">O-</option>
                                    <option value="Don't Know">Don't Know</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Emergency Contact *</label>
                                <input type="text" name="emergency_contact" class="form-control" placeholder="Name & contact number" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Subcontractor ID Number *</label>
                                <input type="text" name="sub_id_number" class="form-control" placeholder="Enter subcontractor ID" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Company Name *</label>
                                <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($sub['company_name'] ?? ''); ?>" placeholder="Enter company name" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Company Contact Number *</label>
                                <input type="tel" name="company_contact" class="form-control" placeholder="Enter company contact" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Division Hired By</label>
                            <div class="row">
                                <?php
                                $divisions = ["Solar", "MEP", "FIT", "Equipment Trading (EQT)", "FSF", "UPS", "HEL", "Supply Chain", "HR", "S & C", "Transport", "BT", "Marketing", "Compliance", "NEX-GEN", "Other"];
                                foreach($divisions as $div):
                                ?>
                                <div class="col-md-3 mb-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="division_hired[]" value="<?php echo $div; ?>" id="div_<?php echo md5($div); ?>">
                                        <label class="form-check-label text-muted" for="div_<?php echo md5($div); ?>" style="font-size: 12px;"><?php echo $div; ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Division / Scope * <small class="text-muted fw-normal">(ekak ho godak thoranna puluwn / select one or more)</small></label>
                            <div class="row p-2 bg-white rounded-3 border mx-0" id="scopeGroup">
                                <?php
                                $scopes = [
                                    "HVAC" => "HVAC",
                                    "Fire protection Systems" => "Fire protection Systems (ගිනි ආරක්ෂණ පද්ධති)",
                                    "Plumbing service" => "Plumbing service (ජලනල සේවා)",
                                    "Electrical works" => "Electrical works (විදුලිය ආශ්‍රිත කාර්‍යනයන්)",
                                    "Solar PV system services" => "Solar PV system services (සූර්ය PV පද්ධති සේවා)",
                                    "Networking" => "Networking (ජාලකරණය)",
                                    "Civil Engineering" => "Civil Engineering (සිවිල් ඉංජිනේරු)",
                                    "Steel works" => "Steel works (වානේ සම්බන්ද කාර්‍යයන්)",
                                    "Scaffolding" => "Scaffolding (පලංචි)",
                                    "Life Line" => "Life Line (ජීවිත ආරක්ශන කේබල්)",
                                    "Transportation" => "Transportation (ප්‍රවාහන)",
                                    "Stores Management" => "Stores Management (ගබඩා කළමනාකරණය)",
                                    "Tea Making" => "Tea Making (තේ සෑදීම)",
                                    "ELV" => "ELV",
                                    "Security" => "Security (ආරක්ශක සේවා)",
                                    "Camera System" => "Camera System (කැමරා පද්ධති)",
                                    "Cleaning" => "Cleaning (පිරිසිදු කිරීම්)",
                                    "Kitchen Services" => "Kitchen Services (මුළුතැන්ගෙ සේවා)",
                                    "Pest Control" => "Pest Control (පළිබෝධ පාලනය)",
                                    "Glass Cleaning" => "Glass Cleaning (වීදුරු පිරිසිදු කිරීම)",
                                    "Telecommunication" => "Telecommunication (සන්නිවේදන)",
                                    "Supervising" => "Supervising (අධීක්ෂණය)",
                                    "Other" => "Other"
                                ];
                                foreach ($scopes as $val => $label):
                                    $sid = 'scope_' . md5($val);
                                ?>
                                <div class="col-md-4 mb-1">
                                    <div class="form-check">
                                        <input class="form-check-input scope-checkbox" type="checkbox" name="scope[]"
                                               value="<?= htmlspecialchars($val); ?>" id="<?= $sid; ?>" onchange="checkScope()">
                                        <label class="form-check-label text-muted" for="<?= $sid; ?>" style="font-size: 12px;"><?= htmlspecialchars($label); ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <small id="scopeErrorMsg" class="text-danger fw-bold" style="font-size: 11px; display: none;">Please select at least one Division / Scope.</small>
                        </div>

                        <div id="extra-fields" style="display: none;">
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label for="designation" class="form-label">Designation</label>
                                    <input type="text" class="form-control" name="designation" placeholder="Enter designation">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="work_experience" class="form-label">Work Experience</label>
                                    <input type="text" class="form-control" name="work_experience" placeholder="Enter work experience">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edu_qualifications" class="form-label">Educational Qualifications</label>
                                    <input type="text" class="form-control" name="edu_qualifications" placeholder="Enter educational qualifications">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="voc_qualifications" class="form-label">Vocational Qualifications</label>
                                    <input type="text" class="form-control" name="voc_qualifications" placeholder="Enter vocational qualifications">
                                </div>
                            </div>
                        </div>

                        <div class="mb-2 text-end mt-3">
                            <button type="button" class="btn btn-nav-action" onclick="nextStep()">Next <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <div class="form-step" id="step-2">
                        <h6 class="fw-bold mb-2 text-dark" style="font-size: 13.5px;">Health Information Form</h6>
                        <p class="text-danger small mb-2">* Required</p>

                        <div class="mb-3">
                            <label class="form-label">21. Non-Communicable Diseases (බෝ නොවන රෝග) *</label>
                            <div class="row">
                                <?php
                                $ncd_diseases = [
                                    "Asthma (ඇදුම)", "Shortness of breath (හුස්ම ගැනීමේ අපහසුතාව)", "Lung problems (පෙනහළු ගැටලු)", 
                                    "Tuberculosis (ක්ෂය රෝගය)", "Chest pain (පපුවේ වේදනාව)", "Heart attack (හෘදයාබාධ)", 
                                    "Diabetic (දියවැඩියාව)", "High blood pressure (අධි රුධිර පීඩනය)", "Stroke (ආඝාතය)", 
                                    "Liver diseases (අක්මා රෝග)", "Hepatitis (හෙපටයිටිස්)", "Migraine (ඔළුවාරදය)", "Regular fainting (නිතර ඇතිවන ක්ලාන්තය)"
                                ];
                                foreach($ncd_diseases as $index => $disease): 
                                ?>
                                <div class="col-md-6 mb-2">
                                    <div class="card p-2 border-0 bg-white rounded-3 h-100 shadow-sm">
                                        <p class="mb-1 fw-medium text-dark" style="font-size: 12px;"><?php echo ($index+1) . ". " . $disease; ?></p>
                                        <div class="d-flex justify-content-between flex-wrap gap-1">
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?php echo $index; ?>" value="Previously diagnosed" required><label class="form-check-label text-muted" style="font-size: 11px;">කලින් රෝග විනිශ්චය කර ඇත</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?php echo $index; ?>" value="Currently treatment"><label class="form-check-label text-muted" style="font-size: 11px;">දැනට ප්‍රතිකාර ගනී</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?php echo $index; ?>" value="Not infected"><label class="form-check-label text-muted" style="font-size: 11px;">නැත</label></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">22. Chronic diseases (නිධන්ගත රෝග) *</label>
                            <div class="row">
                                <?php
                                $chronic_diseases = [
                                    "Kidney diseases (වකුගඩු ආබාධ)", "Thyroid disorders (තයිරොයිඩ් ගැටලු)", "Skin Problem/rash (සමේ ගැටලු/ ආසාදන)", 
                                    "Hearing Loss or ear Problems (ශ්‍රවණ දුබලතා)", "Eye Problems (අක්ෂි ගැටලු)", "Hemophilia (හීමොෆීලියාව)", "Anemia (රක්තහීනතාව)"
                                ];
                                foreach($chronic_diseases as $index => $disease): 
                                ?>
                                <div class="col-md-6 mb-2">
                                    <div class="card p-2 border-0 bg-white rounded-3 h-100 shadow-sm">
                                        <p class="mb-1 fw-medium text-dark" style="font-size: 12px;"><?php echo $disease; ?></p>
                                        <div class="d-flex justify-content-between flex-wrap gap-1">
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?php echo $index; ?>" value="Previously diagnosed" required><label class="form-check-label text-muted" style="font-size: 11px;">කලින් රෝග විනිශ්චය කර ඇත</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?php echo $index; ?>" value="Currently treatment"><label class="form-check-label text-muted" style="font-size: 11px;">දැනට ප්‍රතිකාර ගනී</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?php echo $index; ?>" value="Not infected"><label class="form-check-label text-muted" style="font-size: 11px;">නැත</label></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">24. Mental Health Condition (මානසික සෞඛ්‍ය තත්ත්ව) *</label>
                            <div class="row">
                                <?php
                                $mental_conditions = [
                                    "Acrophobia (fear of height) - උස බිය", 
                                    "Achluophobia (fear of darkness) - අඳුරට බිය", 
                                    "Ancraophobia (fear of wind) - සුළඟට බිය", 
                                    "Dystychiphobia (fear of accident) - අනතුරුවලට බිය", 
                                    "Electrophobia (fear of electricity) - විදුලියට බිය", 
                                    "Ligrophobia (fear of loud noises) - තද ශබ්දවලට බිය", 
                                    "Pyrophobia (fear of fire) - ගින්දරට බිය", 
                                    "Phengophobia (fear of daylight) - දිවා ආලෝකයට බිය", 
                                    "Mechanophobia (fear of machine) - යන්ත්‍රෝපකරණවලට බිය", 
                                    "Climacophobia (fear of stairs) - පඩිපෙළට බිය", 
                                    "Chemophobia (fear of chemical) - රසායනික ද්‍රව්‍යවලට බිය", 
                                    "Bathophobia (fear of depth) - ගැඹුරට බිය"
                                ];
                                foreach($mental_conditions as $index => $condition): 
                                ?>
                                <div class="col-md-6 mb-2">
                                    <div class="card p-2 border-0 bg-white rounded-3 h-100 shadow-sm">
                                        <p class="mb-1 fw-medium text-dark" style="font-size: 12px;"><?php echo $condition; ?></p>
                                        <div class="d-flex justify-content-between flex-wrap gap-1">
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?php echo $index; ?>" value="Previously diagnosed" required><label class="form-check-label text-muted" style="font-size: 11px;">කලින් රෝග විනිශ්චය කර ඇත</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?php echo $index; ?>" value="Currently treatment"><label class="form-check-label text-muted" style="font-size: 11px;">දැනට ප්‍රතිකාර ගනී</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?php echo $index; ?>" value="Not infected"><label class="form-check-label text-muted" style="font-size: 11px;">නැත</label></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Step 25: Allergies Dropdown -->
                        <div class="mb-3">
                            <label class="form-label">25. Any allergies? (ආසාත්මිකතා තිබේද) *</label>
                            <select name="allergies" id="allergiesSelect" class="form-select" onchange="toggleAllergyInput()" required>
                                <option value="" disabled selected>Select your answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>

                        <!-- Hidden Allergy Details Text Box -->
                        <div class="mb-3" id="allergyDetailsDiv" style="display: none;">
                            <label class="form-label">Please specify your allergies (ආසාත්මිකතා මොනවාදැයි සඳහන් කරන්න) *</label>
                            <input type="text" name="allergy_details" id="allergyDetailsInput" class="form-control" placeholder="Enter your allergies here...">
                        </div>

                        <div class="d-flex justify-content-between mb-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary px-4 py-2 rounded-8" onclick="prevStep()">Back</button>
                            <button type="submit" class="btn btn-custom-action" style="width: auto;"><i class="bi bi-send-fill me-1"></i> Submit Worker Details</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function updateDate() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    if(document.getElementById('liveDate')) {
        document.getElementById('liveDate').innerText = now.toLocaleDateString('en-US', options);
    }
}
updateDate();

const welcomeText = "Worker Management";
let charIndex = 0;
const typingElement = document.getElementById("typedWelcome");

function typeText() {
    if (charIndex < welcomeText.length) {
        typingElement.innerHTML += welcomeText.charAt(charIndex);
        charIndex++;
        setTimeout(typeText, 70);
    }
}

window.addEventListener("DOMContentLoaded", () => {
    if (typingElement) {
        setTimeout(typeText, 300);
    }
});

function filterWorkersTable() {
    let input = document.getElementById('workerSearchInput');
    let filter = input.value.toLowerCase().trim();
    let rows = document.querySelectorAll('#workersTable tbody tr.worker-row');
    let noResultsRow = document.getElementById('noSearchResultsRow');
    let visibleCount = 0;

    rows.forEach(row => {
        let code = row.querySelector('.worker-code').textContent.toLowerCase();
        let name = row.querySelector('.worker-name').textContent.toLowerCase();
        let nic = row.querySelector('.worker-nic').textContent.toLowerCase();
        let mobile = row.querySelector('.worker-mobile').textContent.toLowerCase();

        if (code.includes(filter) || name.includes(filter) || nic.includes(filter) || mobile.includes(filter)) {
            row.style.display = "";
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });

    if (noResultsRow) {
        if (visibleCount === 0 && rows.length > 0) {
            noResultsRow.style.display = "";
        } else {
            noResultsRow.style.display = "none";
        }
    }
}

function parseHealthJson(jsonStr) {
    if (!jsonStr) return '<span class="text-muted">None specified</span>';
    try {
        let data = JSON.parse(jsonStr);
        let html = '<ul class="list-unstyled mb-0">';
        for (let key in data) {
            let badgeColor = data[key] === 'Not infected' ? 'text-success' : 'text-danger fw-bold';
            html += `<li class="py-0.5 border-bottom border-light"><small>${key}: <span class="${badgeColor} float-end">${data[key]}</span></small></li>`;
        }
        html += '</ul>';
        return html;
    } catch(e) {
        return jsonStr;
    }
}

function validateAge() {
    let dobInput = document.getElementById('dobInput').value;
    let errorMsg = document.getElementById('ageErrorMsg');
    
    if (!dobInput) return true;

    let dob = new Date(dobInput);
    let today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    let m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
        age--;
    }

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

// Toggle Function for Allergy Details Field
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

document.querySelectorAll('.view-worker-btn').forEach(button => {
    button.addEventListener('click', function() {
        const worker = JSON.parse(this.getAttribute('data-worker'));
        
        document.getElementById('v_worker_unique_code').innerText = worker.worker_unique_code || 'Will be assigned after admin approval';
        document.getElementById('v_status').innerText = worker.status || 'Pending';
        document.getElementById('v_id').innerText = worker.id || '-';
        document.getElementById('v_sub_id_number').innerText = worker.sub_id_number || '-';
        document.getElementById('v_worker_name').innerText = worker.worker_name || '-';
        document.getElementById('v_nic_number').innerText = worker.nic_number || '-';
        document.getElementById('v_dob').innerText = worker.dob || '-';
        document.getElementById('v_gender').innerText = worker.gender || '-';
        document.getElementById('v_mobile_number').innerText = worker.mobile_number || '-';
        document.getElementById('v_email').innerText = worker.email || '-';
        document.getElementById('v_address').innerText = worker.address || '-';
        document.getElementById('v_police_date').innerText = worker.police_date || '-';
        document.getElementById('v_gs_date').innerText = worker.gs_date || '-';
        document.getElementById('v_blood_group').innerText = worker.blood_group || '-';
        document.getElementById('v_emergency_contact').innerText = worker.emergency_contact || '-';
        document.getElementById('v_company_name').innerText = worker.company_name || '-';
        document.getElementById('v_company_contact').innerText = worker.company_contact || '-';
        document.getElementById('v_division_hired').innerText = worker.division_hired || '-';
        document.getElementById('v_scope').innerText = worker.scope || '-';
        document.getElementById('v_designation').innerText = worker.designation || '-';
        document.getElementById('v_work_experience').innerText = worker.work_experience || '-';
        document.getElementById('v_allergies').innerText = worker.allergies || '-';
        document.getElementById('v_allergy_details').innerText = worker.allergy_details ? '(' + worker.allergy_details + ')' : '';
        document.getElementById('v_edu_qualifications').innerText = worker.edu_qualifications || '-';
        document.getElementById('v_voc_qualifications').innerText = worker.voc_qualifications || '-';
        document.getElementById('v_created_at').innerText = worker.created_at || '-';

        let frontContainer = document.getElementById('v_id_front_container');
        if (worker.id_front_path) {
            frontContainer.innerHTML = `<a href="${worker.id_front_path}" target="_blank"><img src="${worker.id_front_path}" alt="ID Front" class="img-thumbnail" style="max-height: 100px; object-fit: cover;"></a>`;
        } else {
            frontContainer.innerHTML = '<span class="text-muted">Not uploaded</span>';
        }

        let backContainer = document.getElementById('v_id_back_container');
        if (worker.id_back_path) {
            backContainer.innerHTML = `<a href="${worker.id_back_path}" target="_blank"><img src="${worker.id_back_path}" alt="ID Back" class="img-thumbnail" style="max-height: 100px; object-fit: cover;"></a>`;
        } else {
            backContainer.innerHTML = '<span class="text-muted">Not uploaded</span>';
        }

        document.getElementById('v_ncd_details').innerHTML = parseHealthJson(worker.ncd_details);
        document.getElementById('v_chronic_details').innerHTML = parseHealthJson(worker.chronic_details);
        document.getElementById('v_mental_details').innerHTML = parseHealthJson(worker.mental_details);
    });
});

function handleFormSubmit(form) {
    if (!validateAge()) {
        alert("Worker age must be between 18 and 60 years.");
        return false;
    }

    if (document.querySelectorAll('.scope-checkbox:checked').length === 0) {
        alert("Please select at least one Division / Scope.");
        return false;
    }

    let submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...';
    return true;
}

function checkScope() {
    const anyChecked = document.querySelectorAll('.scope-checkbox:checked').length > 0;
    document.getElementById("extra-fields").style.display = anyChecked ? "block" : "none";
    if (anyChecked) {
        document.getElementById('scopeErrorMsg').style.display = 'none';
    }
}

function nextStep() {
    let step1 = document.getElementById("step-1");
    let requiredFields = step1.querySelectorAll("input[required], select[required], textarea[required]");

    for (let field of requiredFields) {
        if (!field.checkValidity()) {
            field.reportValidity();
            field.focus();
            return;
        }
    }

    if (document.querySelectorAll('.scope-checkbox:checked').length === 0) {
        document.getElementById('scopeErrorMsg').style.display = 'block';
        document.getElementById('scopeGroup').scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    if (!validateAge()) {
        alert("Worker age must be between 18 and 60 years before proceeding.");
        document.getElementById('dobInput').focus();
        return;
    }

    step1.classList.remove("active");
    document.getElementById("step-2").classList.add("active");
    document.querySelector('#workerModal .modal-body').scrollTo({ top: 0, behavior: "smooth" });
}

function prevStep() {
    document.getElementById("step-2").classList.remove("active");
    document.getElementById("step-1").classList.add("active");
    document.querySelector('#workerModal .modal-body').scrollTo({ top: 0, behavior: "smooth" });
}

document.addEventListener('DOMContentLoaded', function () {
    const inviteButton = document.getElementById('createWorkerInviteBtn');
    const inviteModalElement = document.getElementById('workerInviteLinkModal');
    const inviteLinkInput = document.getElementById('workerInviteLinkInput');
    const inviteError = document.getElementById('workerInviteLinkError');
    const inviteModal = inviteModalElement ? new bootstrap.Modal(inviteModalElement) : null;

    if (inviteButton) {
        inviteButton.addEventListener('click', async function () {
            const originalText = inviteButton.innerHTML;
            inviteButton.disabled = true;
            inviteButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Creating...';
            inviteError.hidden = true;
            try {
                const body = new FormData();
                body.append('csrf', inviteButton.dataset.csrf);
                const response = await fetch('create_worker_registration_link.php', { method: 'POST', body, credentials: 'same-origin' });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.error || 'Could not create the link.');
                inviteLinkInput.value = data.url;
                inviteModal.show();
            } catch (error) {
                inviteError.textContent = error.message || 'Could not create the link.';
                inviteError.hidden = false;
                inviteModal.show();
            } finally {
                inviteButton.disabled = false;
                inviteButton.innerHTML = originalText;
            }
        });
    }

    const copyInviteLink = document.getElementById('copyWorkerInviteLink');
    if (copyInviteLink) {
        copyInviteLink.addEventListener('click', async function () {
            inviteLinkInput.select();
            try {
                await navigator.clipboard.writeText(inviteLinkInput.value);
                copyInviteLink.innerHTML = '<i class="bi bi-check2"></i>';
            } catch (error) {
                document.execCommand('copy');
            }
        });
    }

    const toggleBtn = document.getElementById('navbarToggleBtn');
    const dropdownWrapper = document.getElementById('navDropdown');
    const toggleIcon = document.getElementById('toggleIcon');

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            dropdownWrapper.classList.toggle('show');
            
            if (dropdownWrapper.classList.contains('show')) {
                toggleIcon.classList.remove('bi-list');
                toggleIcon.classList.add('bi-x-lg');
            } else {
                toggleIcon.classList.remove('bi-x-lg');
                toggleIcon.classList.add('bi-list');
            }
        });
    }
});
</script>
</body>
</html>