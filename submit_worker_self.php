<?php
session_start();
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = trim($_POST['token'] ?? '');
    $subcontractor_id = intval($_POST['subcontractor_id'] ?? 0);

    // Verify token validity again
    $stmt = $conn->prepare("SELECT id FROM worker_registration_links WHERE token = ? AND subcontractor_id = ? AND status = 'active' AND expires_at > NOW()");
    $stmt->bind_param("si", $token, $subcontractor_id);
    $stmt->execute();
    $link_res = $stmt->get_result();
    if ($link_res->num_rows !== 1) {
        die("Invalid or expired registration link.");
    }
    $stmt->close();

    $worker_name = $_POST['worker_name'];
    $address = $_POST['address'];
    $nic_number = $_POST['nic_number'];
    $dob = $_POST['dob'];
    $mobile_number = $_POST['mobile_number'];
    $gender = $_POST['gender'];
    $email = $_POST['email'] ?? '';
    $police_date = $_POST['police_date'];
    $gs_date = $_POST['gs_date'];
    $blood_group = $_POST['blood_group'];
    $emergency_contact = $_POST['emergency_contact'];
    $sub_id_number = $_POST['sub_id_number'];
    $company_name = $_POST['company_name'];
    $company_contact = $_POST['company_contact'];
    $scope = isset($_POST['scope']) ? implode(', ', $_POST['scope']) : '';
    $safety_agreement = intval($_POST['safety_agreement'] ?? 0);

    if ($safety_agreement !== 1) {
        die("You must agree to the safety protocols before submitting.");
    }

    // Upload ID Card photos
    $id_front_path = "";
    $id_back_path = "";
    if (isset($_FILES['id_front']) && $_FILES['id_front']['error'] == 0) {
        $id_front_path = s3_store_upload($_FILES['id_front']['tmp_name'], $_FILES['id_front']['name'], 'id', 'front') ?: "";
    }
    if (isset($_FILES['id_back']) && $_FILES['id_back']['error'] == 0) {
        $id_back_path = s3_store_upload($_FILES['id_back']['tmp_name'], $_FILES['id_back']['name'], 'id', 'back') ?: "";
    }

    // Insert into workers table with 'Pending' status
    $insert_stmt = $conn->prepare("INSERT INTO workers (subcontractor_id, worker_name, address, nic_number, dob, mobile_number, gender, email, police_date, gs_date, blood_group, emergency_contact, sub_id_number, company_name, company_contact, scope, id_front_path, id_back_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
    
    if ($insert_stmt) {
        $insert_stmt->bind_param("isssssssssssssssss", $subcontractor_id, $worker_name, $address, $nic_number, $dob, $mobile_number, $gender, $email, $police_date, $gs_date, $blood_group, $emergency_contact, $sub_id_number, $company_name, $company_contact, $scope, $id_front_path, $id_back_path);
        
        if ($insert_stmt->execute()) {
            // Mark link as used
            $upd = $conn->prepare("UPDATE worker_registration_links SET status = 'used' WHERE token = ?");
            $upd->bind_param("s", $token);
            $upd->execute();
            $upd->close();

            echo "<div style='text-align:center; margin-top:50px; font-family:Poppins,sans-serif;'>
                    <h2 style='color:#16a34a;'>Registration & Safety Induction Successful!</h2>
                    <p>Your details and safety tutorial responses have been submitted. Awaiting admin approval.</p>
                  </div>";
        } else {
            echo "Error submitting registration: " . $insert_stmt->error;
        }
        $insert_stmt->close();
    }
    $conn->close();
}
?>