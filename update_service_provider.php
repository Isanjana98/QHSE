<?php
session_start();

/* =====================================================
   ACCESS CONTROL
   Admin OR Supervisor ට දෙන්නටම access දෙනවා.
===================================================== */

$is_admin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$is_supervisor = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;

if (!$is_admin && !$is_supervisor) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

require_once "permit_helpers.php";

$id = intval($_POST['permit_id'] ?? 0);
if (!$id) die("Invalid permit id.");

$check = $conn->prepare("SELECT status FROM service_provider_permits WHERE permit_id=?");
$check->bind_param("i", $id);
$check->execute();
$current = $check->get_result()->fetch_assoc();
$check->close();

if (!$current || strtolower(trim($current['status'])) !== 'approved') {
    die("This permit cannot be updated in its current status.");
}

$conn->begin_transaction();

try {

    $company_name_address = $_POST['company_name_address'] ?? '';
    $contact_number         = $_POST['contact_number'] ?? '';
    $period_from            = $_POST['period_from'] ?: NULL;
    $period_to              = $_POST['period_to'] ?: NULL;
    $signoff_name           = $_POST['signoff_name'] ?? '';
    $hse_officer            = $_POST['hse_officer'] ?? '';
    $compliance_remarks     = $_POST['compliance_remarks'] ?? '';
    $new_status             = "Updated";

    $stmt = $conn->prepare("
        UPDATE service_provider_permits SET
            company_name_address = ?,
            contact_number = ?,
            period_from = ?,
            period_to = ?,
            signoff_name = ?,
            hse_officer = ?,
            compliance_remarks = ?,
            status = ?,
            updated_at = NOW()
        WHERE permit_id = ?
    ");

    $stmt->bind_param(
        "ssssssssi",
        $company_name_address,
        $contact_number,
        $period_from,
        $period_to,
        $signoff_name,
        $hse_officer,
        $compliance_remarks,
        $new_status,
        $id
    );

    if (!$stmt->execute()) throw new Exception($stmt->error);
    $stmt->close();

    // Tasks
    $conn->query("DELETE FROM service_provider_tasks WHERE permit_id=" . $id);
    if (isset($_POST['task'])) {
        $tstmt = $conn->prepare("INSERT INTO service_provider_tasks (permit_id, task_desc, hazard_control) VALUES (?,?,?)");
        foreach ($_POST['task'] as $i => $task) {
            $task = trim($task);
            if ($task === "") continue;
            $hazard = $_POST['hazard_control'][$i] ?? '';
            $tstmt->bind_param("iss", $id, $task, $hazard);
            $tstmt->execute();
        }
        $tstmt->close();
    }

    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        savePermitImages($conn, 'Service Provider', $id);
    }

    $conn->commit();

    // Update සාර්ථක වූ පසු view page එකට යැවීම
    echo "<script>
            alert('Service Provider Permit updated successfully.'); 
            window.location.href = 'view_service_provider.php?id=$id';
          </script>";

} catch (Exception $e) {
    $conn->rollback();
    echo "<script>
            alert('Error: " . addslashes($e->getMessage()) . "');
            window.location.href = 'edit_service_provider.php?id=$id';
          </script>";
}

$conn->close();
?>