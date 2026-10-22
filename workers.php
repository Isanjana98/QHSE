<?php
session_start();

// Only a logged-in SUBCONTRACTOR can open this page
if (!isset($_SESSION['subcontractor_id'])) {
    header("Location: index.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

$sub_id = (int)$_SESSION['subcontractor_id'];

if (empty($_SESSION['worker_link_csrf'])) {
    $_SESSION['worker_link_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['worker_link_csrf'];
$csrf_html  = htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8');

/* =====================================================
   HELPERS
===================================================== */
function wk_old($key) {
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? htmlspecialchars($_POST[$key], ENT_QUOTES, 'UTF-8') : '';
}
function wk_sel($key, $val) {
    return (isset($_POST[$key]) && $_POST[$key] === $val) ? 'selected' : '';
}
function wk_in($key, $val) {
    return (isset($_POST[$key]) && is_array($_POST[$key]) && in_array($val, $_POST[$key], true)) ? 'checked' : '';
}
function wk_radio($key, $val) {
    return (isset($_POST[$key]) && $_POST[$key] === $val) ? 'checked' : '';
}
function wk_post($key) {
    return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim($_POST[$key]) : '';
}

/**
 * Validate + store one ID card photo. Returns stored path or '' and sets $err on failure.
 */
function wk_handle_id_upload($field, $side, &$err) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        $err = "Please upload the ID card $side side photo.";
        return '';
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $err = "ID card $side side upload failed (error code {$f['error']}). Please try again.";
        return '';
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        $err = "ID card $side side photo is too large (max 5 MB).";
        return '';
    }
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if ($info === false || !in_array($info[2], $allowed, true)) {
        $err = "ID card $side side must be a valid image (JPG, PNG, GIF or WEBP).";
        return '';
    }
    $stored = s3_store_upload($f['tmp_name'], $f['name'], 'id', $side);
    if (!$stored) {
        $err = "Could not save the ID card $side side photo. Please try again.";
        return '';
    }
    return $stored;
}

/* =====================================================
   FORM OPTION LISTS (used for both display and validation)
===================================================== */
$divisions = ["Solar", "MEP", "FIT", "Equipment Trading (EQT)", "FSF", "UPS", "HEL", "Supply Chain", "HR", "S & C", "Transport", "BT", "Marketing", "Compliance", "NEX-GEN", "Other"];

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

$genders      = ["Male", "Female", "Other"];
$blood_groups = ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-", "Don't Know"];

$ncd_diseases = [
    "Asthma (ඇදුම)", "Shortness of breath (හුස්ම ගැනීමේ අපහසුතාව)", "Lung problems (පෙනහළු ගැටලු)",
    "Tuberculosis (ක්ෂය රෝගය)", "Chest pain (පපුවේ වේදනාව)", "Heart attack (හෘදයාබාධ)",
    "Diabetic (දියවැඩියාව)", "High blood pressure (අධි රුධිර පීඩනය)", "Stroke (ආඝාතය)",
    "Liver diseases (අක්මා රෝග)", "Hepatitis (හෙපටයිටිස්)", "Migraine (ඔළුවාරදය)", "Regular fainting (නිතර ඇතිවන ක්ලාන්තය)"
];
$chronic_diseases = [
    "Kidney diseases (වකුගඩු ආබාධ)", "Thyroid disorders (තයිරොයිඩ් ගැටලු)", "Skin Problem/rash (සමේ ගැටලු/ ආසාදන)",
    "Hearing Loss or ear Problems (ශ්‍රවණ දුබලතා)", "Eye Problems (අක්ෂි ගැටලු)", "Hemophilia (හීමොෆීලියාව)", "Anemia (රක්තහීනතාව)"
];
// Keys stored in the database
$mental_conditions = [
    "Acrophobia (fear of height)", "Achluophobia (fear of darkness)", "Ancraophobia (fear of wind)",
    "Dystychiphobia (fear of accident)", "Electrophobia (fear of electricity)", "Ligrophobia (fear of loud noises)",
    "Pyrophobia (fear of fire)", "Phengophobia (fear of daylight)", "Mechanophobia (fear of machine)",
    "Climacophobia (fear of stairs)", "Chemophobia (fear of chemical)", "Bathophobia (fear of depth)"
];
// Labels shown on the form (same order as above)
$mental_labels = [
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
$health_answers = ['Previously diagnosed', 'Currently treatment', 'Not infected'];

/* =====================================================
   SUBCONTRACTOR + DASHBOARD COUNTS
===================================================== */
$stmt = $conn->prepare("SELECT * FROM subcontractors WHERE id = ?");
if (!$stmt) {
    die("Database query failed: " . $conn->error);
}
$stmt->bind_param("i", $sub_id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();
$stmt->close();

$sub_phone   = $sub['phone'] ?? 'N/A';
$vendor_code = trim($sub['vendor_code'] ?? '');

// 1. Pending permits count
$pending_count = 0;
$pending_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM service_provider_permits WHERE subcontractor_id = ? AND status = 'Pending'");
if ($pending_stmt) {
    $pending_stmt->bind_param("i", $sub_id);
    $pending_stmt->execute();
    $pending_count = (int)($pending_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $pending_stmt->close();
} else {
    error_log("Pending permits count query failed: " . $conn->error);
}

// 2. Approved workers + workers waiting for admin approval
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
    $total_workers = (int)($workers_count_row['approved_total'] ?? 0);
    $pending_workers_count = (int)($workers_count_row['pending_total'] ?? 0);
    $workers_count_stmt->close();
} else {
    error_log("Workers count query failed: " . $conn->error);
}

$success_message = "";
$error_message   = "";
$open_modal      = false; // re-open the Add Worker modal when validation fails

/* =====================================================
   POST HANDLING (delete worker / add worker)
===================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrf_ok = isset($_POST['csrf']) && hash_equals($csrf_token, (string)$_POST['csrf']);

    if (!$csrf_ok) {
        $error_message = "Your session token has expired. Please refresh the page and try again.";

    /* ---------- DELETE WORKER ---------- */
    } elseif (isset($_POST['delete_id'])) {
        $delete_id = (int)$_POST['delete_id'];
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

    /* ---------- ADD WORKER ---------- */
    } else {
        $open_modal = true;

        $company_name       = $sub['company_name'] ?? '';
        $worker_name        = wk_post('worker_name');
        $address            = wk_post('address');
        $nic_number         = wk_post('nic_number');
        $dob                = wk_post('dob');
        $mobile_number      = wk_post('mobile_number');
        $gender             = wk_post('gender');
        $email              = wk_post('email');
        $police_date        = wk_post('police_date');
        $gs_date            = wk_post('gs_date');
        $blood_group        = wk_post('blood_group');
        $emergency_contact  = wk_post('emergency_contact');
        $sub_id_number      = wk_post('sub_id_number');
        $company_contact    = wk_post('company_contact');
        $designation        = wk_post('designation');
        $work_experience    = wk_post('work_experience');
        $edu_qualifications = wk_post('edu_qualifications');
        $voc_qualifications = wk_post('voc_qualifications');
        $allergies          = wk_post('allergies');
        $allergy_details    = ($allergies === 'Yes') ? wk_post('allergy_details') : 'None';

        // Only accept values that exist in our lists
        $division_arr = (isset($_POST['division_hired']) && is_array($_POST['division_hired']))
            ? array_values(array_intersect($divisions, $_POST['division_hired'])) : [];
        $scope_arr = (isset($_POST['scope']) && is_array($_POST['scope']))
            ? array_values(array_intersect(array_keys($scopes), $_POST['scope'])) : [];
        $division_hired = implode(', ', $division_arr);
        $scope          = implode(', ', $scope_arr);

        // --- Required fields ---
        $required = [$worker_name, $address, $nic_number, $dob, $mobile_number, $gender, $police_date,
                     $gs_date, $blood_group, $emergency_contact, $sub_id_number, $company_contact, $allergies];
        foreach ($required as $r) {
            if ($r === '') {
                $error_message = "Error: Please fill in all required fields.";
                break;
            }
        }

        // --- Dropdown values ---
        if ($error_message === '' && (!in_array($gender, $genders, true) || !in_array($blood_group, $blood_groups, true) || !in_array($allergies, ['Yes', 'No'], true))) {
            $error_message = "Error: Invalid selection in the form. Please check Gender, Blood Group and Allergies.";
        }

        // --- Scope ---
        if ($error_message === '' && $scope === '') {
            $error_message = "Error: Please select at least one Division / Scope.";
        }

        // --- Allergy details ---
        if ($error_message === '' && $allergies === 'Yes' && $allergy_details === '') {
            $error_message = "Error: Please specify the allergies.";
        }

        // --- Date of birth + age (18 to 60) ---
        if ($error_message === '') {
            $dobObj = DateTime::createFromFormat('Y-m-d', $dob);
            if (!$dobObj || $dobObj->format('Y-m-d') !== $dob) {
                $error_message = "Error: Invalid date of birth.";
            } else {
                $age = $dobObj->diff(new DateTime('today'))->y;
                if ($dobObj > new DateTime('today') || $age < 18 || $age > 60) {
                    $error_message = "Error: Worker must be between 18 and 60 years old. Calculated age is " . $age . " years.";
                }
            }
        }

        // --- Health answers (every question must have a valid answer) ---
        $ncd_data = []; $chronic_data = []; $mental_data = [];
        if ($error_message === '') {
            foreach ($ncd_diseases as $i => $name) {
                $v = $_POST["ncd_$i"] ?? '';
                if (!in_array($v, $health_answers, true)) { $error_message = "Error: Please answer all Health Information questions."; break; }
                $ncd_data[$name] = $v;
            }
            if ($error_message === '') {
                foreach ($chronic_diseases as $i => $name) {
                    $v = $_POST["chronic_$i"] ?? '';
                    if (!in_array($v, $health_answers, true)) { $error_message = "Error: Please answer all Health Information questions."; break; }
                    $chronic_data[$name] = $v;
                }
            }
            if ($error_message === '') {
                foreach ($mental_conditions as $i => $name) {
                    $v = $_POST["mental_$i"] ?? '';
                    if (!in_array($v, $health_answers, true)) { $error_message = "Error: Please answer all Health Information questions."; break; }
                    $mental_data[$name] = $v;
                }
            }
        }

        // --- Duplicate NIC check (ignore rejected records) ---
        if ($error_message === '') {
            $dup = $conn->prepare("SELECT id FROM workers WHERE nic_number = ? AND status <> 'Rejected' LIMIT 1");
            if ($dup) {
                $dup->bind_param("s", $nic_number);
                $dup->execute();
                $dup->store_result();
                if ($dup->num_rows > 0) {
                    $error_message = "Error: A worker with this NIC number is already registered.";
                }
                $dup->close();
            }
        }

        // --- ID photos ---
        $id_front_path = "";
        $id_back_path  = "";
        if ($error_message === '') {
            $id_front_path = wk_handle_id_upload('id_front', 'front', $error_message);
        }
        if ($error_message === '') {
            $id_back_path = wk_handle_id_upload('id_back', 'back', $error_message);
        }

        // --- Insert ---
        if ($error_message === '') {
            $ncd_details     = json_encode($ncd_data, JSON_UNESCAPED_UNICODE);
            $chronic_details = json_encode($chronic_data, JSON_UNESCAPED_UNICODE);
            $mental_details  = json_encode($mental_data, JSON_UNESCAPED_UNICODE);

            $insert_stmt = $conn->prepare("INSERT INTO workers (subcontractor_id, worker_name, address, nic_number, dob, mobile_number, gender, email, police_date, gs_date, blood_group, emergency_contact, sub_id_number, company_name, company_contact, division_hired, scope, designation, work_experience, edu_qualifications, voc_qualifications, allergies, allergy_details, ncd_details, chronic_details, mental_details, id_front_path, id_back_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

            if ($insert_stmt) {
                $insert_stmt->bind_param("i" . str_repeat("s", 27), $sub_id, $worker_name, $address, $nic_number, $dob, $mobile_number, $gender, $email, $police_date, $gs_date, $blood_group, $emergency_contact, $sub_id_number, $company_name, $company_contact, $division_hired, $scope, $designation, $work_experience, $edu_qualifications, $voc_qualifications, $allergies, $allergy_details, $ncd_details, $chronic_details, $mental_details, $id_front_path, $id_back_path);

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
}

/* =====================================================
   WORKERS LIST (server-side search + pagination)
===================================================== */
$q = trim($_GET['q'] ?? '');
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$where  = "subcontractor_id = ?";
$types  = "i";
$params = [$sub_id];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= " AND (worker_unique_code LIKE ? OR worker_name LIKE ? OR nic_number LIKE ? OR mobile_number LIKE ?)";
    $types .= "ssss";
    array_push($params, $like, $like, $like, $like);
}

$total_records = 0;
$count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM workers WHERE $where");
if ($count_stmt) {
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total_records = (int)($count_stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $count_stmt->close();
}
$total_pages = max(1, (int)ceil($total_records / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

$list_params = $params;
$list_params[] = $limit;
$list_params[] = $offset;
$result_workers_table = null;
$workers_stmt_table = $conn->prepare("SELECT * FROM workers WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?");
if ($workers_stmt_table) {
    $workers_stmt_table->bind_param($types . "ii", ...$list_params);
    $workers_stmt_table->execute();
    $result_workers_table = $workers_stmt_table->get_result();
}
$qs = ($q !== '') ? '&q=' . urlencode($q) : '';
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
                    <!-- Server-side search (searches ALL pages) -->
                    <form method="GET" action="" class="d-flex align-items-center gap-1 m-0">
                        <div class="input-group" style="width: 270px;">
                            <span class="input-group-text bg-white border-end-0" style="border-color: var(--border-color);">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" name="q" id="workerSearchInput" class="form-control border-start-0 ps-0" placeholder="Search code, name, NIC, mobile..." value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="btn btn-outline-secondary" type="submit" title="Search"><i class="bi bi-arrow-return-left"></i></button>
                        </div>
                        <?php if ($q !== ''): ?>
                            <a href="<?= $current_page; ?>" class="btn btn-outline-danger btn-sm" title="Clear search"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </form>

                    <button type="button" class="btn btn-outline-warning" id="createWorkerInviteBtn" data-csrf="<?= $csrf_html; ?>">
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
                            <th>Worker Code</th>
                            <th>Worker Name</th>
                            <th>NIC Number</th>
                            <th>Mobile Number</th>
                            <th>Division Hired</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($result_workers_table && $result_workers_table->num_rows > 0) {
                            while ($row = $result_workers_table->fetch_assoc()) {
                                $workerJson = htmlspecialchars(json_encode(media_rowify($row, ['id_front_path', 'id_back_path'])), ENT_QUOTES, 'UTF-8');
                                $row_code = !empty($row['worker_unique_code']) ? htmlspecialchars($row['worker_unique_code']) : '-';
                                $st = $row['status'] ?? 'Pending';
                                $st_class = ($st === 'Approved') ? 'bg-success' : (($st === 'Rejected') ? 'bg-danger' : 'bg-secondary');
                                $row_id = (int)$row['id'];

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
                                            <form method='POST' action='' class='d-inline m-0' onsubmit='return confirm(\"Are you sure you want to delete this worker record?\");'>
                                                <input type='hidden' name='csrf' value='$csrf_html'>
                                                <input type='hidden' name='delete_id' value='$row_id'>
                                                <button type='submit' class='btn btn-outline-danger btn-sm rounded-pill px-2 py-1' title='Delete Worker'><i class='bi bi-trash'></i></button>
                                            </form>
                                        </div>
                                      </td>";
                                echo "</tr>";
                            }
                        } else {
                            if ($q !== '') {
                                echo "<tr><td colspan='7' class='text-center text-muted py-4'><i class='bi bi-search fs-3 d-block mb-1 text-warning'></i>No matching workers found.</td></tr>";
                            } else {
                                echo "<tr><td colspan='7' class='text-center text-muted py-4'><i class='bi bi-inbox fs-3 d-block mb-1 text-warning'></i>No workers submitted yet. Click 'Add Workers' to register.</td></tr>";
                            }
                        }
                        if ($workers_stmt_table) { $workers_stmt_table->close(); }
                        ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-end mb-0" style="font-size: 12px;">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?= $page - 1; ?><?= $qs; ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo; Previous</span>
                            </a>
                        </li>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?= $i; ?><?= $qs; ?>"><?= $i; ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?= $page + 1; ?><?= $qs; ?>" aria-label="Next">
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
                <p class="text-muted small">Share this shareable link with the worker so they can fill their own details, watch safety videos, and complete the induction quiz.</p>
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
                            <span class="fw-bold text-dark" id="v_work_experience">-</span> Yrs &bull; Allergies: <span class="text-danger fw-bold" id="v_allergies">-</span> <span id="v_allergy_details" class="text-dark"></span>
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
                                <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1" style="font-size: 10px;">Medical Records</span>
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
                <button type="button" class="btn btn-outline-secondary px-3 py-1 rounded-3" style="font-size: 12.5px;" data-bs-dismiss="modal">Close</button>
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

                <?php if ($open_modal && !empty($error_message)): ?>
                    <div class="alert alert-danger py-2 px-3 mb-3" style="font-size: 12px;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($error_message); ?>
                        <?php if (!empty($_FILES)): ?><br><small>Please select the ID card photos again.</small><?php endif; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data" id="inductionForm" onsubmit="return handleFormSubmit(this)">
                    <input type="hidden" name="csrf" value="<?= $csrf_html; ?>">

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
                                <input type="text" name="worker_name" class="form-control" placeholder="Enter your full name" value="<?= wk_old('worker_name'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Address *</label>
                                <input type="text" name="address" class="form-control" placeholder="Enter your address" value="<?= wk_old('address'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">NIC Number *</label>
                                <input type="text" name="nic_number" class="form-control" placeholder="Enter your NIC number" value="<?= wk_old('nic_number'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Date of Birth * (Must be between 18 & 60 years)</label>
                                <input type="date" name="dob" id="dobInput" class="form-control" onchange="validateAge()" value="<?= wk_old('dob'); ?>" required>
                                <small id="ageErrorMsg" class="text-danger fw-bold" style="font-size: 11px; display: none;"></small>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">ID Card Front Side Photo * <small class="text-muted fw-normal">(JPG/PNG, max 5 MB)</small></label>
                                <input type="file" name="id_front" class="form-control" accept="image/*" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">ID Card Back Side Photo * <small class="text-muted fw-normal">(JPG/PNG, max 5 MB)</small></label>
                                <input type="file" name="id_back" class="form-control" accept="image/*" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Mobile Number *</label>
                                <input type="tel" name="mobile_number" class="form-control" placeholder="Enter mobile number" value="<?= wk_old('mobile_number'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Gender *</label>
                                <select name="gender" class="form-select" required>
                                    <option value="" disabled <?= wk_old('gender') === '' ? 'selected' : ''; ?>>Select your gender</option>
                                    <?php foreach ($genders as $g): ?>
                                        <option value="<?= htmlspecialchars($g); ?>" <?= wk_sel('gender', $g); ?>><?= htmlspecialchars($g); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="Enter email address" value="<?= wk_old('email'); ?>">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Police Report Issue Date *</label>
                                <input type="date" name="police_date" class="form-control" value="<?= wk_old('police_date'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">GS Report Issue Date *</label>
                                <input type="date" name="gs_date" class="form-control" value="<?= wk_old('gs_date'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Blood Group *</label>
                                <select name="blood_group" class="form-select" required>
                                    <option value="" disabled <?= wk_old('blood_group') === '' ? 'selected' : ''; ?>>Select blood group</option>
                                    <?php foreach ($blood_groups as $bg): ?>
                                        <option value="<?= htmlspecialchars($bg); ?>" <?= wk_sel('blood_group', $bg); ?>><?= htmlspecialchars($bg); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Emergency Contact *</label>
                                <input type="text" name="emergency_contact" class="form-control" placeholder="Name & contact number" value="<?= wk_old('emergency_contact'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Subcontractor ID Number *</label>
                                <input type="text" name="sub_id_number" class="form-control" placeholder="Enter subcontractor ID" value="<?= wk_old('sub_id_number'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Company Name *</label>
                                <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($sub['company_name'] ?? ''); ?>" placeholder="Enter company name" readonly required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label">Company Contact Number *</label>
                                <input type="tel" name="company_contact" class="form-control" placeholder="Enter company contact" value="<?= wk_old('company_contact'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">Division Hired By</label>
                            <div class="row">
                                <?php foreach ($divisions as $div): ?>
                                <div class="col-md-3 mb-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="division_hired[]" value="<?= htmlspecialchars($div); ?>" id="div_<?= md5($div); ?>" <?= wk_in('division_hired', $div); ?>>
                                        <label class="form-check-label text-muted" for="div_<?= md5($div); ?>" style="font-size: 12px;"><?= htmlspecialchars($div); ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Division / Scope * <small class="text-muted fw-normal">(ekak ho godak thoranna puluwn / select one or more)</small></label>
                            <div class="row p-2 bg-white rounded-3 border mx-0" id="scopeGroup">
                                <?php foreach ($scopes as $val => $label):
                                    $sid = 'scope_' . md5($val);
                                ?>
                                <div class="col-md-4 mb-1">
                                    <div class="form-check">
                                        <input class="form-check-input scope-checkbox" type="checkbox" name="scope[]"
                                               value="<?= htmlspecialchars($val); ?>" id="<?= $sid; ?>" onchange="checkScope()" <?= wk_in('scope', $val); ?>>
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
                                    <input type="text" class="form-control" name="designation" placeholder="Enter designation" value="<?= wk_old('designation'); ?>">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="work_experience" class="form-label">Work Experience</label>
                                    <input type="text" class="form-control" name="work_experience" placeholder="Enter work experience" value="<?= wk_old('work_experience'); ?>">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="edu_qualifications" class="form-label">Educational Qualifications</label>
                                    <input type="text" class="form-control" name="edu_qualifications" placeholder="Enter educational qualifications" value="<?= wk_old('edu_qualifications'); ?>">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label for="voc_qualifications" class="form-label">Vocational Qualifications</label>
                                    <input type="text" class="form-control" name="voc_qualifications" placeholder="Enter vocational qualifications" value="<?= wk_old('voc_qualifications'); ?>">
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
                                <?php foreach ($ncd_diseases as $index => $disease): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="card p-2 border-0 bg-white rounded-3 h-100 shadow-sm">
                                        <p class="mb-1 fw-medium text-dark" style="font-size: 12px;"><?= ($index + 1) . ". " . htmlspecialchars($disease); ?></p>
                                        <div class="d-flex justify-content-between flex-wrap gap-1">
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?= $index; ?>" value="Previously diagnosed" <?= wk_radio("ncd_$index", 'Previously diagnosed'); ?> required><label class="form-check-label text-muted" style="font-size: 11px;">කලින් රෝග විනිශ්චය කර ඇත</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?= $index; ?>" value="Currently treatment" <?= wk_radio("ncd_$index", 'Currently treatment'); ?>><label class="form-check-label text-muted" style="font-size: 11px;">දැනට ප්‍රතිකාර ගනී</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="ncd_<?= $index; ?>" value="Not infected" <?= wk_radio("ncd_$index", 'Not infected'); ?>><label class="form-check-label text-muted" style="font-size: 11px;">නැත</label></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">22. Chronic diseases (නිධන්ගත රෝග) *</label>
                            <div class="row">
                                <?php foreach ($chronic_diseases as $index => $disease): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="card p-2 border-0 bg-white rounded-3 h-100 shadow-sm">
                                        <p class="mb-1 fw-medium text-dark" style="font-size: 12px;"><?= htmlspecialchars($disease); ?></p>
                                        <div class="d-flex justify-content-between flex-wrap gap-1">
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?= $index; ?>" value="Previously diagnosed" <?= wk_radio("chronic_$index", 'Previously diagnosed'); ?> required><label class="form-check-label text-muted" style="font-size: 11px;">කලින් රෝග විනිශ්චය කර ඇත</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?= $index; ?>" value="Currently treatment" <?= wk_radio("chronic_$index", 'Currently treatment'); ?>><label class="form-check-label text-muted" style="font-size: 11px;">දැනට ප්‍රතිකාර ගනී</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="chronic_<?= $index; ?>" value="Not infected" <?= wk_radio("chronic_$index", 'Not infected'); ?>><label class="form-check-label text-muted" style="font-size: 11px;">නැත</label></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">23. Mental Health Condition (මානසික සෞඛ්‍ය තත්ත්ව) *</label>
                            <div class="row">
                                <?php foreach ($mental_labels as $index => $condition): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="card p-2 border-0 bg-white rounded-3 h-100 shadow-sm">
                                        <p class="mb-1 fw-medium text-dark" style="font-size: 12px;"><?= htmlspecialchars($condition); ?></p>
                                        <div class="d-flex justify-content-between flex-wrap gap-1">
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?= $index; ?>" value="Previously diagnosed" <?= wk_radio("mental_$index", 'Previously diagnosed'); ?> required><label class="form-check-label text-muted" style="font-size: 11px;">කලින් රෝග විනිශ්චය කර ඇත</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?= $index; ?>" value="Currently treatment" <?= wk_radio("mental_$index", 'Currently treatment'); ?>><label class="form-check-label text-muted" style="font-size: 11px;">දැනට ප්‍රතිකාර ගනී</label></div>
                                            <div class="form-check mb-0"><input class="form-check-input" type="radio" name="mental_<?= $index; ?>" value="Not infected" <?= wk_radio("mental_$index", 'Not infected'); ?>><label class="form-check-label text-muted" style="font-size: 11px;">නැත</label></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Allergies -->
                        <div class="mb-3">
                            <label class="form-label">24. Any allergies? (ආසාත්මිකතා තිබේද) *</label>
                            <select name="allergies" id="allergiesSelect" class="form-select" onchange="toggleAllergyInput()" required>
                                <option value="" disabled <?= wk_old('allergies') === '' ? 'selected' : ''; ?>>Select your answer</option>
                                <option value="Yes" <?= wk_sel('allergies', 'Yes'); ?>>Yes</option>
                                <option value="No" <?= wk_sel('allergies', 'No'); ?>>No</option>
                            </select>
                        </div>

                        <!-- Allergy details (shown only when "Yes") -->
                        <div class="mb-3" id="allergyDetailsDiv" style="display: none;">
                            <label class="form-label">Please specify your allergies (ආසාත්මිකතා මොනවාදැයි සඳහන් කරන්න) *</label>
                            <input type="text" name="allergy_details" id="allergyDetailsInput" class="form-control" placeholder="Enter your allergies here..." value="<?= ($_POST['allergies'] ?? '') === 'Yes' ? wk_old('allergy_details') : ''; ?>">
                        </div>

                        <div class="d-flex justify-content-between mb-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary px-4 py-2 rounded-3" onclick="prevStep()">Back</button>
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
/* Escape any text before putting it into innerHTML */
function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
/* Block javascript:/data: style URLs */
function safeUrl(u) {
    u = String(u || '').trim();
    if (/^(javascript|data|vbscript):/i.test(u)) return '';
    return u;
}

function updateDate() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    if (document.getElementById('liveDate')) {
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

function parseHealthJson(jsonStr) {
    if (!jsonStr) return '<span class="text-muted">None specified</span>';
    try {
        let data = JSON.parse(jsonStr);
        let html = '<ul class="list-unstyled mb-0">';
        for (let key in data) {
            let badgeColor = data[key] === 'Not infected' ? 'text-success' : 'text-danger fw-bold';
            html += '<li class="py-1 border-bottom border-light"><small>' + esc(key) + ': <span class="' + badgeColor + ' float-end">' + esc(data[key]) + '</span></small></li>';
        }
        html += '</ul>';
        return html;
    } catch (e) {
        return esc(jsonStr);
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
        errorMsg.innerText = 'Error: Worker must be between 18 and 60 years old. Current calculated age is ' + age + '.';
        return false;
    } else {
        errorMsg.style.display = 'none';
        errorMsg.innerText = '';
        return true;
    }
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

/* View worker details */
document.querySelectorAll('.view-worker-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        const worker = JSON.parse(this.getAttribute('data-worker'));
        const set = function (id, val) { document.getElementById(id).innerText = val; };

        set('v_worker_unique_code', worker.worker_unique_code || 'Will be assigned after admin approval');
        set('v_status', worker.status || 'Pending');
        set('v_id', worker.id || '-');
        set('v_sub_id_number', worker.sub_id_number || '-');
        set('v_worker_name', worker.worker_name || '-');
        set('v_nic_number', worker.nic_number || '-');
        set('v_dob', worker.dob || '-');
        set('v_gender', worker.gender || '-');
        set('v_mobile_number', worker.mobile_number || '-');
        set('v_email', worker.email || '-');
        set('v_address', worker.address || '-');
        set('v_police_date', worker.police_date || '-');
        set('v_gs_date', worker.gs_date || '-');
        set('v_blood_group', worker.blood_group || '-');
        set('v_emergency_contact', worker.emergency_contact || '-');
        set('v_company_name', worker.company_name || '-');
        set('v_company_contact', worker.company_contact || '-');
        set('v_division_hired', worker.division_hired || '-');
        set('v_scope', worker.scope || '-');
        set('v_designation', worker.designation || '-');
        set('v_work_experience', worker.work_experience || '-');
        set('v_allergies', worker.allergies || '-');
        set('v_allergy_details', worker.allergy_details ? '(' + worker.allergy_details + ')' : '');
        set('v_edu_qualifications', worker.edu_qualifications || '-');
        set('v_voc_qualifications', worker.voc_qualifications || '-');
        set('v_created_at', worker.created_at || '-');

        let front = safeUrl(worker.id_front_path);
        document.getElementById('v_id_front_container').innerHTML = front
            ? '<a href="' + esc(front) + '" target="_blank" rel="noopener"><img src="' + esc(front) + '" alt="ID Front" class="img-thumbnail" style="max-height: 100px; object-fit: cover;"></a>'
            : '<span class="text-muted">Not uploaded</span>';

        let back = safeUrl(worker.id_back_path);
        document.getElementById('v_id_back_container').innerHTML = back
            ? '<a href="' + esc(back) + '" target="_blank" rel="noopener"><img src="' + esc(back) + '" alt="ID Back" class="img-thumbnail" style="max-height: 100px; object-fit: cover;"></a>'
            : '<span class="text-muted">Not uploaded</span>';

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
    if (typingElement) {
        setTimeout(typeText, 300);
    }

    // Restore form state after a server-side validation error
    checkScope();
    toggleAllergyInput_keep();
    <?php if ($open_modal): ?>
    new bootstrap.Modal(document.getElementById('workerModal')).show();
    <?php endif; ?>

    // Share registration link
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

    // NOTE: the mobile menu toggle (#navbarToggleBtn) is handled inside subcontractor_navbar.php,
    // so it is NOT bound again here (binding twice made the menu open and close instantly).
});

/* Show/hide allergy details WITHOUT clearing the value (used when restoring the form) */
function toggleAllergyInput_keep() {
    const sel = document.getElementById('allergiesSelect');
    const div = document.getElementById('allergyDetailsDiv');
    const inp = document.getElementById('allergyDetailsInput');
    if (sel.value === 'Yes') {
        div.style.display = 'block';
        inp.setAttribute('required', 'required');
    } else {
        div.style.display = 'none';
        inp.removeAttribute('required');
    }
}
</script>
</body>
</html>