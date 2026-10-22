<?php
if (isset($_POST['permit_number'])) {
    require_once __DIR__ . '/db_config.php';

    $permit_number = trim($_POST['permit_number']);
    
    $stmt = $conn->prepare("SELECT id FROM hot_work_permits WHERE permit_number = ? LIMIT 1");
    $stmt->bind_param("s", $permit_number);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo "exists";
    } else {
        echo "does_not_exist";
    }

    $stmt->close();
    $conn->close();
}
?>