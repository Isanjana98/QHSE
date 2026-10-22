<?php
session_start();
require_once __DIR__ . "/db_config.php";
require_once __DIR__ . "/save_inspection_record.php";

if (!isset($_SESSION['supervisor_logged']) || $_SESSION['supervisor_logged'] !== true) {
    header("Location: index.php");
    exit();
}

$logged_in_email = $_SESSION['email'] ?? $_SESSION['sup_email'] ?? "ashen.s@fentons.lk";

$sup_name = "";
$sup_nic  = "";
$sup_site = "";

$stmt = $conn->prepare("SELECT name, nic, site FROM supervisors WHERE email = ?");
if ($stmt) {
    $stmt->bind_param("s", $logged_in_email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $sup_name = $row['name'] ?? "";
        $sup_nic  = $row['nic'] ?? "";
        $sup_site = $row['site'] ?? "";
    }
    $stmt->close();
}

$current_date    = date('Y-m-d');
$default_date    = sub_val('sub_date', $current_date);
$default_site    = sub_val('sub_location', $sup_site);
$success_message = "";
$error_message   = "";

$redirect_page = "tool_inspection.php";
$do_redirect   = false;

$conn->query("CREATE TABLE IF NOT EXISTS drill_submissions (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255), submission_date DATE NULL, project VARCHAR(255), reference VARCHAR(255), q1 VARCHAR(10), q2 VARCHAR(10), q3 VARCHAR(10), q4 VARCHAR(10), q5 VARCHAR(10), q6 VARCHAR(10), employee_name VARCHAR(255), epf_nic VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $vals = [
        $_POST['email'] ?? $logged_in_email,
        $_POST['inspection_date'] ?? $current_date,
        $_POST['site_division'] ?? '',
        $_POST['reference_no'] ?? '',
        $_POST['q1'] ?? '',
        $_POST['q2'] ?? '',
        $_POST['q3'] ?? '',
        $_POST['q4'] ?? '',
        $_POST['q5'] ?? '',
        $_POST['q6'] ?? '',
        $_POST['completed_name'] ?? '',
        $_POST['epf_nic'] ?? '',
    ];

    $insert_stmt = $conn->prepare("INSERT INTO drill_submissions (email, submission_date, project, reference, q1, q2, q3, q4, q5, q6, employee_name, epf_nic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($insert_stmt) {
        $insert_stmt->bind_param("ssssssssssss", ...$vals);
        if ($insert_stmt->execute()) {
            // Subcontractor + tool eka records table ekata save karanawa
            save_inspection_record($conn, 'Portable Drill', $sup_name);
            $success_message = "පිරික්සුම් ලැයිස්තුව සාර්ථකව සුරැකුණි! (Checklist submitted successfully!)";
            $do_redirect = true;
        } else {
            $error_message = "දෝෂයකි (Error): " . $insert_stmt->error;
        }
        $insert_stmt->close();
    } else {
        $error_message = "දෝෂයකි (Error): " . $conn->error;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drilling Machine – Checklist</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f0f4f8; font-family: 'Poppins', sans-serif; color: #1e293b; padding: 0; margin: 0; font-size: 13px; }
        .gform-container { width: 100% !important; max-width: 100% !important; margin: 0; background: #fff; border-radius: 0; border: none; box-shadow: none; }
        .gform-header {
            position: relative;
            background: linear-gradient(rgba(15, 23, 42, 0.55), rgba(30, 41, 59, 0.65)), url('assets/images/d.jpg') no-repeat center center;
            background-size: cover; color: #fff; padding: 45px 15px; border-top: 5px solid #ea580c; text-align: center;
        }
        .gform-body { padding: 15px 20px; }
        .question-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 7px; padding: 8px 13px; margin-bottom: 9px; transition: all 0.2s ease; }
        .question-card:hover { border-color: #cbd5e1; box-shadow: 0 1px 4px rgba(0,0,0,0.02); }
        .q-title { font-weight: 600; font-size: 13px; color: #0f172a; margin-bottom: 1px; }
        .q-sinhala { font-size: 11.5px; color: #475569; margin-bottom: 4px; }
        .warning-box { background: #fff7ed; border-left: 3px solid #f97316; padding: 8px 12px; border-radius: 6px; font-size: 11.5px; color: #9a3412; font-weight: 500; margin-bottom: 12px; }
        .form-control { border-radius: 6px; padding: 5px 9px; font-size: 12.5px; border-color: #cbd5e1; }
        .form-control:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,0.15); }
        .form-check-input { width: 14px; height: 14px; margin-top: 3px; }
        .form-check-label { font-size: 12.5px; }
        .q-flex-container { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .q-text-content { flex: 1; }
        .img-container-right { flex-shrink: 0; text-align: right; }
        .img-container-right img { max-height: 90px; width: auto; border-radius: 5px; border: 1px solid #cbd5e1; background: #fff; padding: 2px; }
        .img-container-block { margin-top: 8px; text-align: center; background: #fff; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; }
        .img-container-block img { max-width: 45%; width: 100%; height: auto; border-radius: 4px; display: block; margin: 0 auto; }
        .btn-submit-custom { background: linear-gradient(135deg, #f97316, #ea580c); border: none; border-radius: 30px; color: #fff; font-weight: 600; font-size: 13px; padding: 9px 18px; box-shadow: 0 4px 12px rgba(249,115,22,0.3); transition: all 0.2s ease; }
        .btn-submit-custom:hover { background: linear-gradient(135deg, #ea580c, #c2410c); color: #fff; box-shadow: 0 6px 15px rgba(249,115,22,0.4); transform: translateY(-1px); }
    </style>
</head>
<body>

<div class="gform-container">
    <div class="gform-header">
        <h3 class="fw-bold mb-1" style="font-size: 22px; text-shadow: 0 2px 4px rgba(0,0,0,0.7);">Drilling Machine – Checklist</h3>
        <h4 class="fw-semibold mb-1" style="font-size: 17px; color: #fdba74; text-shadow: 0 1px 3px rgba(0,0,0,0.7);">විදුම් යන්ත්‍රය - පිරික්සුම් ලැයිස්තුව</h4>
        <p class="mb-0 text-light font-monospace" style="font-size: 11px; text-shadow: 0 1px 2px rgba(0,0,0,0.7);">Document Number: HSE/CL/D/Rev-0</p>
    </div>

    <div class="gform-body">
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success py-2 font-monospace" style="font-size: 12px;"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger py-2 font-monospace" style="font-size: 12px;"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="" method="POST" id="checklistForm">

            <input type="hidden" name="email" value="<?php echo htmlspecialchars($logged_in_email); ?>">
            <?php render_sub_hidden_fields(); ?>

            <div class="warning-box">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Be smart about safety. Use this Safety Checklist at least once in a month.<br>
                <span class="fw-normal">ආරක්ෂාව ගැන හොඳට සිතන්න. අවම වශයෙන් මසකට වරක් මෙම පිරික්සුම් ලැයිස්තුව භාවිතා කරන්න.</span>
            </div>

            <div class="question-card">
                <label class="q-title">Date / දිනය <span class="text-danger">*</span></label>
                <input type="date" name="inspection_date" class="form-control" required value="<?php echo htmlspecialchars($_POST['inspection_date'] ?? $default_date); ?>">
            </div>

            <div class="question-card">
                <label class="q-title">Site or Division / ස්ථානය හෝ අංශය <span class="text-danger">*</span></label>
                <input type="text" name="site_division" class="form-control" required value="<?php echo htmlspecialchars($_POST['site_division'] ?? $default_site); ?>" placeholder="Enter project or workplace">
            </div>

            <div class="question-card">
                <label class="q-title">Reference / අදාළ අංකය</label>
                <input type="text" name="reference_no" class="form-control" value="<?php echo htmlspecialchars($_POST['reference_no'] ?? ''); ?>" placeholder="Enter reference number">
            </div>

            <div class="question-card">
                <div class="q-title">Is the machine surface free of visible defects, missing parts or damages? <span class="text-danger">*</span></div>
                <div class="q-sinhala">යන්ත්‍රයේ මතුපිට දෘශ්‍ය දෝෂ, නැතිවූ කොටස් හෝ හානිවලින් තොරද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q1" value="Yes" required id="q1_yes"><label class="form-check-label" for="q1_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q1" value="No" id="q1_no"><label class="form-check-label" for="q1_no">No / නැත</label></div>
                </div>
            </div>

            <div class="question-card">
                <div class="q-title">Is the machine double insulated or having an electrical earthing system? <span class="text-danger">*</span></div>
                <div class="q-sinhala">විදුම් යන්ත්‍රය ද්විත්ව පරිවරණය කර තිබේද? හෝ විදුයුත් භූගත පද්ධතියක් සහිතද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q2" value="Yes" required id="q2_yes"><label class="form-check-label" for="q2_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q2" value="No" id="q2_no"><label class="form-check-label" for="q2_no">No / නැත</label></div>
                </div>
            </div>

            <div class="question-card">
                <div class="q-title">Is the power cord/plug free of visible damage? <span class="text-danger">*</span></div>
                <div class="q-sinhala">විදුලි රැහැන්/ප්ලග් හානියකින් තොරව තිබේද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Yes" required id="q3_yes"><label class="form-check-label" for="q3_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="No" id="q3_no"><label class="form-check-label" for="q3_no">No / නැත</label></div>
                </div>
            </div>

            <div class="question-card">
                <div class="q-title">Is the machine handle in proper working condition? <span class="text-danger">*</span></div>
                <div class="q-sinhala">යන්ත්‍ර හැඬලය නිසි ක්‍රියාකාරී තත්වයෙන් පවතීද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q4" value="Yes" required id="q4_yes"><label class="form-check-label" for="q4_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q4" value="No" id="q4_no"><label class="form-check-label" for="q4_no">No / නැත</label></div>
                </div>
            </div>

            <div class="question-card">
                <div class="q-flex-container">
                    <div class="q-text-content">
                        <div class="q-title">Does the drill bit correctly fit to the machine? <span class="text-danger">*</span></div>
                <div class="q-sinhala">විදුම් කටුව, යන්ත්‍රය සමඟ නිවැරදිව ගැළපේද?</div>
                        <div class="d-flex gap-4 mt-2">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q5" value="Yes" required id="q5_yes"><label class="form-check-label" for="q5_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q5" value="No" id="q5_no"><label class="form-check-label" for="q5_no">No / නැත</label></div>
                </div>
                    </div>
                    <div class="img-container-right"><img src="assets/images/drill.webp" alt="Reference image"></div>
                </div>
            </div>

            <div class="question-card">
                <div class="q-title">If the machine is equipped with an automatic cut-off switch, is it operating properly? <span class="text-danger">*</span></div>
                <div class="q-sinhala">යන්ත්‍රය ස්වයංක්‍රීය කැපුම් ස්විචයකින් සමන්විත නම්, එය නිසි ලෙස ක්‍රියාත්මක වේද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="Yes" required id="q6_yes"><label class="form-check-label" for="q6_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="No" id="q6_no"><label class="form-check-label" for="q6_no">No / නැත</label></div>
                </div>
            </div>

            <div class="alert alert-danger font-monospace mb-3 py-2 px-3" style="font-size: 11px;" role="alert">
                <strong>IF THE ANSWER TO ANY OF THE ABOVE IS ‘NO’, THE HAND DRILL SHOULD BE TAGGED AND IMMEDIATELY TAKEN OUT OF SERVICE.</strong><br>
                ඉහත සඳහන් එකකට හෝ පිළිතුර 'නැත' නම්, විදුම් යන්ත්‍රය ටැග් කර, වහාම භාවිතයෙන් ඉවත් කළ යුතුය.
            </div>

            <h6 class="fw-bold text-dark mb-2 mt-3" style="font-size: 13px;">Completed by / සම්පූර්ණ කිරීම</h6>

            <div class="question-card">
                <label class="q-title">Name / නම <span class="text-danger">*</span></label>
                <input type="text" name="completed_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['completed_name'] ?? $sup_name); ?>" placeholder="Enter your name">
            </div>

            <div class="question-card mb-3">
                <label class="q-title">EPF/NIC No. / EPF/NIC අංකය <span class="text-danger">*</span></label>
                <input type="text" name="epf_nic" class="form-control" required value="<?php echo htmlspecialchars($_POST['epf_nic'] ?? $sup_nic); ?>" placeholder="Enter EPF or NIC number">
            </div>

            <button type="submit" class="btn btn-submit-custom w-100 py-2 shadow-sm">
                <i class="bi bi-send-fill me-1"></i> Submit / ඉදිරිපත් කරන්න
            </button>

        </form>
    </div>
</div>

<!-- Submitting Popup -->
<div id="submitOverlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:28px 36px; border-radius:16px; text-align:center; box-shadow:0 12px 30px rgba(0,0,0,.25); min-width:240px;">
        <div class="spinner-border" role="status" style="color:#ea580c; width:2.5rem; height:2.5rem;"></div>
        <div style="margin-top:14px; font-weight:600; font-size:15px; color:#0f172a;">Submitting form...</div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">Please wait</div>
    </div>
</div>

<script>
document.getElementById('checklistForm').addEventListener('submit', function () {
    if (this.checkValidity()) {
        document.getElementById('submitOverlay').style.display = 'flex';
    }
});
</script>

<?php if ($do_redirect): ?>
<script>
    (function () {
        var overlay = document.getElementById('submitOverlay');
        overlay.innerHTML =
            '<div style="background:#fff; padding:28px 36px; border-radius:16px; text-align:center; box-shadow:0 12px 30px rgba(0,0,0,.25); min-width:260px;">' +
            '<div style="font-size:54px; line-height:1; color:#16a34a;"><i class="bi bi-check-circle-fill"></i></div>' +
            '<div style="margin-top:12px; font-weight:600; font-size:16px; color:#0f172a;">Form submitted successfully!</div>' +
            '<div style="font-size:12px; color:#64748b; margin-top:4px;">Redirecting to Tool Inspection...</div>' +
            '</div>';
        overlay.style.display = 'flex';
        setTimeout(function () {
            if (window.parent && window.parent !== window) {
                window.parent.location.href = "<?php echo $redirect_page; ?>";
            } else {
                window.location.href = "<?php echo $redirect_page; ?>";
            }
        }, 2000);
    })();
</script>
<?php endif; ?>

</body>
</html>