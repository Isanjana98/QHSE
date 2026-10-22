<?php
session_start();

// Direct Database Connection
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $id   = intval($_POST['id'] ?? 0);
    
    // Anith code tika meeta pahathin ikmanata danna...
    
    // Admin da Supervisor da kiyala check karagadeema
    $isAdmin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
    $isSupervisor = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;

    if (!$isAdmin && !$isSupervisor) {
        exit("Unauthorized access.");
    }

    $table_name = "";
    $id_column  = "id";
    $column_to_update = "";

    // Permit type eka anuwa table saha column names venas vima
    if ($type == 'hot_work') {
        $table_name = "hot_work_permits";
        $id_column  = "permit_id";
    } elseif ($type == 'wah') {
        $table_name = "work_at_height_permits";
        $id_column  = "id";
    } elseif ($type == 'service_provider') {
        $table_name = "service_provider_permits";
        $id_column  = "permit_id";
    } else {
        exit("Invalid permit type.");
    }

    // Role eka anuwa update karanna ona database column eka theeranaya kirima
    if ($isAdmin) {
        $column_to_update = "admin_work_images"; 
    } else {
        $column_to_update = "supervisor_work_images"; 
    }

    $uploadedFiles = [];
    
    // Multiple files upload handling
    if (!empty($_FILES['work_images']['name'][0])) {
        $totalFiles = count($_FILES['work_images']['name']);
        
        for ($i = 0; $i < $totalFiles; $i++) {
            $fileName = $_FILES['work_images']['name'][$i];
            $fileTmpName = $_FILES['work_images']['tmp_name'][$i];
            $fileError = $_FILES['work_images']['error'][$i];
            
            if ($fileError === UPLOAD_ERR_OK) {
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($fileExt, $allowed)) {
                    // Store the full resolvable path ("s3:work-completion/..."
                    // or "uploads/...") rather than a bare filename, so the
                    // value can be handed straight to media_url() when these
                    // columns are eventually rendered.
                    $storedPath = s3_store_upload($fileTmpName, $fileName, 'work', 'work');

                    if ($storedPath) {
                        $uploadedFiles[] = $storedPath;
                    }
                }
            }
        }
    }

    // Files successfully upload unanam database ekata JSON string ekak lesa save kirima
    if (!empty($uploadedFiles)) {
        $jsonImages = json_encode($uploadedFiles);
        
        $stmt = $conn->prepare("UPDATE `$table_name` SET `$column_to_update` = ? WHERE `$id_column` = ?");
        $stmt->bind_param("si", $jsonImages, $id);
        $stmt->execute();
        $stmt->close();
    }

    // Role eka anuwa adala page ekatama redirect kirima
    if ($isAdmin) {
        header("Location: permit_status_tracker.php?success=images_uploaded");
    } else {
        header("Location: supervisor_dashboard.php?success=images_uploaded"); 
    }
    exit();
}
else {
    header("Location: index.php");
    exit();
}
?>