<?php
session_start();
require_once __DIR__ . '/db_config.php';
if ($conn->connect_error) {
    // Handle error quietly or let it pass
}

// Get the currently logged-in user's email from the session
$logged_in_email = $_SESSION['email'] ?? $_SESSION['sup_email'] ?? "ashen.s@fentons.lk";

$sup_name  = "";
$sup_nic   = "";
$sup_site  = ""; 

// Fetch the correct supervisor details dynamically from the database based on the logged-in email
$stmt = $conn->prepare("SELECT name, nic, site FROM supervisors WHERE email = ?");
if ($stmt) {
    $stmt->bind_param("s", $logged_in_email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $sup_name  = $row['name'] ?? "";
        $sup_nic   = $row['nic'] ?? "";
        $sup_site  = $row['site'] ?? ""; 
    }
    $stmt->close();
}

// අද දින ස්වයංක්‍රීයව ලබා ගැනීම
$current_date = date('Y-m-d'); 
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
        
        .gform-header { 
            position: relative;
            background: linear-gradient(rgba(15, 23, 42, 0.55), rgba(30, 41, 59, 0.65)), url('assets/images/w.jpg') no-repeat center center;
            background-size: cover;
            color: #fff; 
            padding: 50px 15px; 
            border-top: 6px solid #ea580c; 
            text-align: center; 
        }

        .gform-body { padding: 15px 20px; }
        
        /* Reduced padding to decrease box height */
        .question-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; margin-bottom: 8px; transition: all 0.2s ease; }
        .question-card:hover { border-color: #cbd5e1; box-shadow: 0 1px 4px rgba(0,0,0,0.02); }
        
        .q-title { font-weight: 600; font-size: 13px; color: #0f172a; margin-bottom: 1px; }
        .q-sinhala { font-size: 11.5px; color: #475569; margin-bottom: 2px; }
        
        /* Compact image styling for holder */
        .question-image {
            max-width: 110px;
            height: auto;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            display: block;
        }

        .warning-box { background: #fff7ed; border-left: 3px solid #f97316; padding: 10px 12px; border-radius: 6px; font-size: 12px; color: #9a3412; font-weight: 500; margin-bottom: 15px; }
        .form-control, .form-select { border-radius: 6px; padding: 6px 10px; font-size: 12.5px; border-color: #cbd5e1; }
        .form-control:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,0.15); }
        .form-check-input { width: 14px; height: 14px; margin-top: 2px; }
        .form-check-label { font-size: 12.5px; }

        /* Custom Curved Orange Submit Button Style */
        .btn-submit-custom {
            background: linear-gradient(135deg, #f97316, #ea580c);
            border: none;
            border-radius: 30px;
            color: #fff;
            font-weight: 600;
            font-size: 13.5px;
            padding: 10px 20px;
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
            transition: all 0.2s ease;
        }
        .btn-submit-custom:hover {
            background: linear-gradient(135deg, #ea580c, #c2410c);
            color: #fff;
            box-shadow: 0 6px 15px rgba(249, 115, 22, 0.4);
            transform: translateY(-1px);
        }
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
        <form action="save_welding_checklist.php" method="POST" id="weldingForm">
            
            <!-- Safety Notice -->
            <div class="warning-box">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Be smart about safety. Use this Safety Checklist at least once in a month.<br>
                <span class="fw-normal">ආරක්ෂාව ගැන හොඳට සිතන්න. අවම වශයෙන් මසකට වරක් මෙම පිරික්සුම් ලැයිස්තුව භාවිතා කරන්න.</span>
            </div>

            <!-- Date Auto-fill Field -->
            <div class="question-card">
                <label class="q-title">Date / දිනය <span class="text-danger">*</span></label>
                <input type="date" name="inspection_date" class="form-control" required value="<?php echo htmlspecialchars($current_date); ?>">
            </div>

            <!-- Site or Division Auto-fill Field -->
            <div class="question-card">
                <label class="q-title">Site or Division / ස්ථානය හෝ අංශය <span class="text-danger">*</span></label>
                <input type="text" name="site_division" class="form-control" required value="<?php echo htmlspecialchars($sup_site); ?>" placeholder="Enter site or division">
            </div>

            <!-- Q1 -->
            <div class="question-card">
                <div class="q-title">Is the machine free of abnormal noise or abnormal heat?</div>
                <div class="q-sinhala">යන්ත්‍රය අසාමාන්‍ය ශබ්දයකින් හෝ අසාමාන්‍ය ලෙස රත්වීමකින් තොර වේද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q1" value="Yes" required id="q1_yes"><label class="form-check-label" for="q1_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q1" value="No" id="q1_no"><label class="form-check-label" for="q1_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q2 -->
            <div class="question-card">
                <div class="q-title">Can the welding machine be properly earthed?</div>
                <div class="q-sinhala">වෙල්ඩින් යන්ත්‍රය නිසි පරිදි භූගත කල හැකිද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q2" value="Yes" required id="q2_yes"><label class="form-check-label" for="q2_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q2" value="No" id="q2_no"><label class="form-check-label" for="q2_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q3 -->
            <div class="question-card">
                <div class="q-title">Are the wheels and brakes of the welding machine working properly?</div>
                <div class="q-sinhala">වෙල්ඩින් යන්ත්‍රයේ රෝද හා තිරිංග නිසි ලෙස ක්‍රියාකරයිද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="Yes" required id="q3_yes"><label class="form-check-label" for="q3_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q3" value="No" id="q3_no"><label class="form-check-label" for="q3_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q4 -->
            <div class="question-card">
                <div class="q-title">Does the power controlling handle/switch work properly?</div>
                <div class="q-sinhala">විදුලි බලය හසුරුවන හැඬලය/ස්විචය නිසි පරිදි ක්‍රියාකරයිද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q4" value="Yes" required id="q4_yes"><label class="form-check-label" for="q4_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q4" value="No" id="q4_no"><label class="form-check-label" for="q4_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q5 -->
            <div class="question-card">
                <div class="q-title">Are electrical cables and welding cables free of damages/cracks?</div>
                <div class="q-sinhala">විදුලි කේබල් සහ වෙල්ඩින් කේබල් හානි වීම්/ඉරිතැලීම් වලින් තොරද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q5" value="Yes" required id="q5_yes"><label class="form-check-label" for="q5_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q5" value="No" id="q5_no"><label class="form-check-label" for="q5_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q6 -->
            <div class="question-card">
                <div class="q-title">Are cable connections free of conjectures?</div>
                <div class="q-sinhala">කේබල් සම්බන්ධවීම්, අනුමාන සම්බන්දවීම් වලින් තොරවේද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="Yes" required id="q6_yes"><label class="form-check-label" for="q6_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q6" value="No" id="q6_no"><label class="form-check-label" for="q6_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q7 -->
            <div class="question-card">
                <div class="q-title">Are cable connection points covered and free of damages?</div>
                <div class="q-sinhala">කේබල්, යන්ත්‍රයට සවිවන ස්ථාන ආවරණය කර තිබේද? සහ පලුදු වීම් වලින් තොරද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q7" value="Yes" required id="q7_yes"><label class="form-check-label" for="q7_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q7" value="No" id="q7_no"><label class="form-check-label" for="q7_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q8 (Welding Holder with Right-side Image) -->
            <div class="question-card">
                <div class="row align-items-center">
                    <div class="col-md-10">
                        <div class="q-title">Is the welding holder free of damages? <span class="text-danger">*</span></div>
                        <div class="q-sinhala">වෙල්ඩින් හෝල්ඩරය හානිවීම් වලින් තොරද?</div>
                    </div>
                    <div class="col-md-2 text-md-end text-center">
                        <img src="assets/images/holder.JPG" alt="Welding Holder" class="question-image ms-md-auto">
                    </div>
                </div>

                <div class="d-flex gap-4 mt-1">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q8" value="Yes" required id="q8_yes"><label class="form-check-label" for="q8_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q8" value="No" id="q8_no"><label class="form-check-label" for="q8_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q9 -->
            <div class="question-card">
                <div class="q-title">Are fuses or circuit breakers correctly installed?</div>
                <div class="q-sinhala">විලායක හෝ පරිපථ බිදින නිවැරදිව සවි කර තිබේද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q9" value="Yes" required id="q9_yes"><label class="form-check-label" for="q9_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q9" value="No" id="q9_no"><label class="form-check-label" for="q9_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q10 -->
            <div class="question-card">
                <div class="q-title">Are the relevant fire extinguishers ready? Are those in operation?</div>
                <div class="q-sinhala">අදාල ගිනි නිවන උපකරන සුදානමින් තිබේද? ඒවා ක්‍රියාකාරීත්වයේ පවතීද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q10" value="Yes" required id="q10_yes"><label class="form-check-label" for="q10_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q10" value="No" id="q10_no"><label class="form-check-label" for="q10_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Q11 -->
            <div class="question-card">
                <div class="q-title">Have machine operators trained in firefighting?</div>
                <div class="q-sinhala">යන්ත්‍රය ක්‍රියා කරන්නන් ගිනි නිවීමේ පුහුණුව ලබා තිබේද?</div>
                <div class="d-flex gap-4">
                    <div class="form-check"><input class="form-check-input" type="radio" name="q11" value="Yes" required id="q11_yes"><label class="form-check-label" for="q11_yes">Yes / ඔව්</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="q11" value="No" id="q11_no"><label class="form-check-label" for="q11_no">No / නැත</label></div>
                </div>
            </div>

            <!-- Crucial Warning Footer -->
            <div class="alert alert-danger font-monospace mb-3 py-2 px-3" style="font-size: 11px;" role="alert">
                <strong>IF THE ANSWER TO ANY OF THE ABOVE IS ‘NO’, THE WELDING MACHINE SHOULD BE TAGGED AND IMMEDIATELY TAKEN OUT OF SERVICE.</strong><br>
                ඉහත සඳහන් එකකට හෝ පිලිතුර "නැත" නම් වෙල්ඩින් යන්ත්‍රය ටැග් කර, වහාම භාවිතයෙන් ඉවත්කල යුතුය.
            </div>

            <!-- Completed By Section -->
            <h6 class="fw-bold text-dark mb-2 mt-3" style="font-size: 13px;">Completed by / සම්පූර්ණ කිරීම</h6>
            
            <div class="question-card">
                <label class="q-title">Name / නම <span class="text-danger">*</span></label>
                <input type="text" name="completed_name" class="form-control" required value="<?php echo htmlspecialchars($sup_name); ?>" placeholder="Enter your name">
            </div>

            <div class="question-card mb-3">
                <label class="q-title">EPF/NIC No. / EPF/NIC අංකය <span class="text-danger">*</span></label>
                <input type="text" name="epf_nic" class="form-control" required value="<?php echo htmlspecialchars($sup_nic); ?>" placeholder="Enter EPF or NIC number">
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-submit-custom w-100 py-2.5 shadow-sm">
                <i class="bi bi-send-fill me-1"></i> Submit Inspection Checklist
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
document.getElementById('weldingForm').addEventListener('submit', function () {
    // Form eka valid nam pamanak popup eka pennanawa
    if (this.checkValidity()) {
        document.getElementById('submitOverlay').style.display = 'flex';
    }
});
</script>

</body>
</html>