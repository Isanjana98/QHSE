<?php
session_start();
require_once __DIR__ . '/db_config.php';

$success_message = "";
$error_message = "";
$subcontractor = null;

// 1. Link eken ena token eka gaththa
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $conn->prepare("SELECT * FROM subcontractors WHERE update_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $subcontractor = $result->fetch_assoc();
    } else {
        $error_message = "Valueless or Expired Link! (අවලංගු හෝ කල්ඉකුත් වූ සබැඳියකි).";
    }
    $stmt->close();
} else {
    $error_message = "No token provided! (සබැඳිය තහවුරු කළ නොහැක).";
}

// 2. Form eka submit kalama DB eka UPDATE karanna
if ($_SERVER["REQUEST_METHOD"] == "POST" && $subcontractor) {

    $vendor_code                = trim($_POST['vendor_code']);   
    $company_name               = trim($_POST['company_name']);
    $company_address            = trim($_POST['company_address']);
    $business_reg_number        = trim($_POST['business_reg_number']);
    $business_reg_date          = $_POST['business_reg_date'];
    $epf_number                 = trim($_POST['epf_number']);
    $etf_number                 = trim($_POST['etf_number']);
    $owner_name                 = trim($_POST['owner_name']);
    $owner_address              = trim($_POST['owner_address']);
    $owner_id_number            = trim($_POST['owner_id_number']);
    $owner_gender               = $_POST['owner_gender'] ?? '';
    $owner_dob                  = $_POST['owner_dob'];
    $mobile_number              = trim($_POST['mobile_number']);
    $email                      = trim($_POST['email']);
    $agreement_effective_date   = $_POST['agreement_effective_date'];
    $agreement_termination_date = $_POST['agreement_termination_date'];
    $division                   = isset($_POST['division']) ? implode(', ', $_POST['division']) : '';
    $scope                      = $_POST['scope'] ?? '';
    $machineries                = trim($_POST['machineries']);
    $tools                      = trim($_POST['tools']);
    $male_workers               = intval($_POST['male_workers']);
    $female_workers             = intval($_POST['female_workers']);
    $agreement_accept           = isset($_POST['agreement_accept']) ? 1 : 0;

    // Database Update query
    $update_sql = "UPDATE subcontractors SET 
        vendor_code = ?, company_name = ?, company_address = ?, business_reg_number = ?, business_reg_date = ?,
        epf_number = ?, etf_number = ?, owner_name = ?, owner_address = ?, owner_id_number = ?,
        owner_gender = ?, owner_dob = ?, mobile_number = ?, email = ?,
        agreement_effective_date = ?, agreement_termination_date = ?, division = ?, scope = ?,
        machineries = ?, tools = ?, male_workers = ?, female_workers = ?, agreement_accept = ?
        WHERE id = ?";

    try {
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param(
            'sssssssssssssssssssiii' . 'i',
            $vendor_code, $company_name, $company_address, $business_reg_number, $business_reg_date,
            $epf_number, $etf_number, $owner_name, $owner_address, $owner_id_number,
            $owner_gender, $owner_dob, $mobile_number, $email,
            $agreement_effective_date, $agreement_termination_date, $division, $scope,
            $machineries, $tools, $male_workers, $female_workers, $agreement_accept,
            $subcontractor['id']
        );

        if ($stmt->execute()) {
            $success_message = "Your details have been updated successfully! (ඔබගේ විස්තර සාර්ථකව යාවත්කාලීන කරන ලදී).";
            
            // Auto refresh state
            $stmt_refresh = $conn->prepare("SELECT * FROM subcontractors WHERE id = ?");
            $stmt_refresh->bind_param("i", $subcontractor['id']);
            $stmt_refresh->execute();
            $subcontractor = $stmt_refresh->get_result()->fetch_assoc();
            $stmt_refresh->close();
        }
        $stmt->close();
    } catch (Exception $e) {
        $error_message = "Update Error: " . $e->getMessage();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Subcontractor Details | Hayleys Fentons</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-size: 13px; }
        .card { max-width: 800px; margin: 30px auto; border-radius: 10px; }
        .btn-update { background-color: #098336; color: white; font-weight: bold; }
        .btn-update:hover { background-color: #04451c; color: white; }
    </style>
</head>
<body>

<div class="card shadow-sm p-4">
    <h3 class="text-center text-success mb-3">Update Subcontractor Profile</h3>
    <p class="text-center text-muted mb-4">කරුණාකර පහත පෝරමයේ අඩුවෙන් ඇති විස්තර සම්පූර්ණ කර Update කරන්න.</p>

    <?php if(!empty($success_message)): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if(!empty($error_message)): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <?php if($subcontractor): ?>
    <form action="" method="POST">

        <!-- Vendor Code -->
        <div class="mb-3">
            <label class="form-label">Vendor Code *</label>
            <input type="text" name="vendor_code" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['vendor_code'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">1. Company Name *</label>
            <input type="text" name="company_name" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['company_name'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">2. Company Address *</label>
            <input type="text" name="company_address" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['company_address'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">3. Business Registration Number *</label>
            <input type="text" name="business_reg_number" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['business_reg_number'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">4. Business Registration Issued Date *</label>
            <input type="date" name="business_reg_date" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['business_reg_date'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">5. EPF Register number</label>
            <input type="text" name="epf_number" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['epf_number'] ?? ''); ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">6. ETF Register Number</label>
            <input type="text" name="etf_number" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['etf_number'] ?? ''); ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">7. Name of the company owner *</label>
            <input type="text" name="owner_name" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['owner_name'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">8. Address of the company owner *</label>
            <input type="text" name="owner_address" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['owner_address'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">9. ID number company owner *</label>
            <input type="text" name="owner_id_number" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['owner_id_number'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">10. Gender of the Owner *</label>
            <select name="owner_gender" class="form-select" required>
                <?php $g = $subcontractor['owner_gender'] ?? ''; ?>
                <option value="Male" <?php echo ($g == 'Male') ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo ($g == 'Female') ? 'selected' : ''; ?>>Female</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">11. Date of Birth *</label>
            <input type="date" name="owner_dob" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['owner_dob'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">12. Mobile Number *</label>
            <input type="tel" name="mobile_number" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['mobile_number'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">13. Email Address *</label>
            <input type="email" name="email" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['email'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">14. Agreement effective date *</label>
            <input type="date" name="agreement_effective_date" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['agreement_effective_date'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">15. Agreement termination date *</label>
            <input type="date" name="agreement_termination_date" class="form-control" 
                   value="<?php echo htmlspecialchars($subcontractor['agreement_termination_date'] ?? ''); ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">18. Machineries available</label>
            <textarea name="machineries" class="form-control" rows="2"><?php echo htmlspecialchars($subcontractor['machineries'] ?? ''); ?></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">19. Tools available</label>
            <textarea name="tools" class="form-control" rows="2"><?php echo htmlspecialchars($subcontractor['tools'] ?? ''); ?></textarea>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">20. Male Workers *</label>
                <input type="number" name="male_workers" class="form-control" 
                       value="<?php echo htmlspecialchars($subcontractor['male_workers'] ?? '0'); ?>" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">21. Female Workers *</label>
                <input type="number" name="female_workers" class="form-control" 
                       value="<?php echo htmlspecialchars($subcontractor['female_workers'] ?? '0'); ?>" required>
            </div>
        </div>

        <div class="d-grid mt-3">
            <button type="submit" class="btn btn-update py-2">Update Details</button>
        </div>

    </form>
    <?php endif; ?>
</div>

</body>
</html>