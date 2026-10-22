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

$conn->query("CREATE TABLE IF NOT EXISTS welding_submissions (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255), submission_date DATE NULL, project VARCHAR(255), q1 VARCHAR(10), q2 VARCHAR(10), q3 VARCHAR(10), q4 VARCHAR(10), q5 VARCHAR(10), q6 VARCHAR(10), q7 VARCHAR(10), q8 VARCHAR(10), q9 VARCHAR(10), q10 VARCHAR(10), q11 VARCHAR(10), employee_name VARCHAR(255), epf_nic VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $vals = [
        $_POST['email'] ?? $logged_in_email,
        $_POST['inspection_date'] ?? $current_date,
        $_POST['site_division'] ?? '',
    ];
    for ($i = 1; $i <= 11; $i++) { $vals[] = $_POST["q$i"] ?? ''; }
    $vals[] = $_POST['completed_name'] ?? '';
    $vals[] = $_POST['epf_nic'] ?? '';

    $insert_stmt = $conn->prepare("INSERT INTO welding_submissions (email, submission_date, project, q1, q2, q3, q4, q5, q6, q7, q8, q9, q10, q11, employee_name, epf_nic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($insert_stmt) {
        $insert_stmt->bind_param(str_repeat("s", 16), ...$vals);
        if ($insert_stmt->execute()) {
            // Subcontractor + tool eka records table ekata save karanawa
            save_inspection_record($conn, 'Welding Machine', $sup_name);
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

// [English, Sinhala, image(optional)]
$questions = [
    1  => ["Is the machine free of abnormal noise or abnormal heat?", "යන්ත්‍රය අසාමාන්‍ය ශබ්දයකින් හෝ අසාමාන්‍ය ලෙස රත්වීමකින් තොර වේද?"],
    2  => ["Can the welding machine be properly earthed?", "වෙල්ඩින් යන්ත්‍රය නිසි පරිදි භූගත කල හැකිද?"],
    3  => ["Are the wheels and brakes of the welding machine working properly?", "වෙල්ඩින් යන්ත්‍රයේ රෝද හා තිරිංග නිසි ලෙස ක්‍රියාකරයිද?"],
    4  => ["Does the power controlling handle/switch work properly?", "විදුලි බලය හසුරුවන හැඬලය/ස්විචය නිසි පරිදි ක්‍රියාකරයිද?"],
    5  => ["Are electrical cables and welding cables free of damages/cracks?", "විදුලි කේබල් සහ වෙල්ඩින් කේබල් හානි වීම්/ඉරිතැලීම් වලින් තොරද?"],
    6  => ["Are cable connections free of conjectures?", "කේබල් සම්බන්ධවීම්, අනුමාන සම්බන්දවීම් වලින් තොරවේද?"],
    7  => ["Are cable connection points covered and free of damages?", "කේබල්, යන්ත්‍රයට සවිවන ස්ථාන ආවරණය කර තිබේද? සහ පලුදු වීම් වලින් තොරද?"],
    8  => ["Is the welding holder free of damages?", "වෙල්ඩින් හෝල්ඩරය හානිවීම් වලින් තොරද?", "assets/images/holder.JPG"],
    9  => ["Are fuses or circuit breakers correctly installed?", "විලායක හෝ පරිපථ බිදින නිවැරදිව සවි කර තිබේද?"],
    10 => ["Are the relevant fire extinguishers ready? Are those in operation?", "අදාල ගිනි නිවන උපකරන සුදානමින් තිබේද? ඒවා ක්‍රියාකාරීත්වයේ පවතීද?"],
    11 => ["Have machine operators trained in firefighting?", "යන්ත්‍රය ක්‍රියා කරන්නන් ගිනි නිවීමේ පුහුණුව ලබා තිබේද?"],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welding machine – Check list</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f0f4f8; font-family: 'Poppins', sans-serif; color: #1e293b; padding: 0; margin: 0; font-size: 13px; }
        .gform-container { width: 100% !important; max-width: 100% !important; margin: 0; background: #fff; border-radius: 0; border: none; box-shadow: none; }
        .gform-header { position: relative; background: linear-gradient(rgba(15,23,42,0.55), rgba(30,41,59,0.65)), url('assets/images/w.jpg') no-repeat center center; background-size: cover; color: #fff; padding: 50px 15px; border-top: 6px solid #ea580c; text-align: center; }
        .gform-body { padding: 15px 20px; }
        .question-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; margin-bottom: 8px; transition: all 0.2s ease; }
        .question-card:hover { border-color: #cbd5e1; box-shadow: 0 1px 4px rgba(0,0,0,0.02); }
        .q-title { font-weight: 600; font-size: 13px; color: #0f172a; margin-bottom: 1px; }
        .q-sinhala { font-size: 11.5px; color: #475569; margin-bottom: 2px; }
        .question-image { max-width: 110px; height: auto; border-radius: 4px; border: 1px solid #cbd5e1; display: block; }
        .warning-box { background: #fff7ed; border-left: 3px solid #f97316; padding: 10px 12px; border-radius: 6px; font-size: 12px; color: #9a3412; font-weight: 500; margin-bottom: 15px; }
        .form-control, .form-select { border-radius: 6px; padding: 6px 10px; font-size: 12.5px; border-color: #cbd5e1; }
        .form-control:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,0.15); }
        .form-check-input { width: 14px; height: 14px; margin-top: 2px; }
        .form-check-label { font-size: 12.5px; }
        .btn-submit-custom { background: linear-gradient(135deg, #f97316, #ea580c); border: none; border-radius: 30px; color: #fff; font-weight: 600; font-size: 13.5px; padding: 10px 20px; box-shadow: 0 4px 12px rgba(249,115,22,0.3); transition: all 0.2s ease; }
        .btn-submit-custom:hover { background: linear-gradient(135deg, #ea580c, #c2410c); color: #fff; box-shadow: 0 6px 15px rgba(249,115,22,0.4); transform: translateY(-1px); }
    </style>
</head>
<body>

<div class="gform-container">
    <div class="gform-header">
        <h3 class="fw-bold mb-1" style="font-size: 22px; text-shadow: 0 2px 4px rgba(0,0,0,0.7);">Welding machine – Check list</h3>
        <h4 class="fw-semibold mb-1" style="font-size: 17px; color: #fdba74; text-shadow: 0 1px 3px rgba(0,0,0,0.7);">වෙල්ඩින් යන්ත්‍රය - පිරික්සුම් ලැයිස්තුව</h4>
        <p class="mb-0 text-light font-monospace" style="font-size: 12px; text-shadow: 0 1px 2px rgba(0,0,0,0.7);">Document Number: HSE/CL/WM/Rev-00</p>
    </div>

    <div class="gform-body">
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success py-2 font-monospace" style="font-size: 12px;"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger py-2 font-monospace" style="font-size: 12px;"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="" method="POST" id="weldingForm">
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
                <input type="text" name="site_division" class="form-control" required value="<?php echo htmlspecialchars($_POST['site_division'] ?? $default_site); ?>" placeholder="Enter site or division">
            </div>

            <?php foreach ($questions as $n => $q): ?>
            <div class="question-card">
                <?php if (!empty($q[2])): ?>
                <div class="row align-items-center">
                    <div class="col-md-10">
                        <div class="q-title"><?php echo $q[0]; ?> <span class="text-danger">*</span></div>
                        <div class="q-sinhala"><?php echo $q[1]; ?></div>
                    </div>
                    <div class="col-md-2 text-md-end text-center">
                        <img src="<?php echo htmlspecialchars($q[2]); ?>" alt="" class="question-image ms-md-auto">
                    </div>
                </div>
                <?php else: ?>
                <div class="q-title"><?php echo $q[0]; ?></div>
                <div class="q-sinhala"><?php echo $q[1]; ?></div>
                <?php endif; ?>
                <div class="d-flex gap-4 mt-1">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q<?php echo $n; ?>" value="Yes" required id="q<?php echo $n; ?>_yes"><label class="form-check-label" for="q<?php echo $n; ?>_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q<?php echo $n; ?>" value="No" id="q<?php echo $n; ?>_no"><label class="form-check-label" for="q<?php echo $n; ?>_no">No / නැත</label></div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="alert alert-danger font-monospace mb-3 py-2 px-3" style="font-size: 11px;" role="alert">
                <strong>IF THE ANSWER TO ANY OF THE ABOVE IS ‘NO’, THE WELDING MACHINE SHOULD BE TAGGED AND IMMEDIATELY TAKEN OUT OF SERVICE.</strong><br>
                ඉහත සඳහන් එකකට හෝ පිලිතුර "නැත" නම් වෙල්ඩින් යන්ත්‍රය ටැග් කර, වහාම භාවිතයෙන් ඉවත්කල යුතුය.
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

            <button type="submit" class="btn btn-submit-custom w-100 py-2.5 shadow-sm">
                <i class="bi bi-send-fill me-1"></i> Submit Inspection Checklist
            </button>
        </form>
    </div>
</div>

<div id="submitOverlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; padding:28px 36px; border-radius:16px; text-align:center; box-shadow:0 12px 30px rgba(0,0,0,.25); min-width:240px;">
        <div class="spinner-border" role="status" style="color:#ea580c; width:2.5rem; height:2.5rem;"></div>
        <div style="margin-top:14px; font-weight:600; font-size:15px; color:#0f172a;">Submitting form...</div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">Please wait</div>
    </div>
</div>

<script>
document.getElementById('weldingForm').addEventListener('submit', function () {
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