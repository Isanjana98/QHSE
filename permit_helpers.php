<?php
/**
 * permit_helpers.php
 * Shared helper functions for permit images and status
 */

require_once __DIR__ . '/s3_helper.php';

define('PERMIT_UPLOAD_DIR', __DIR__ . '/uploads/permits/');
define('PERMIT_UPLOAD_URL', 'uploads/permits/');



/*
|--------------------------------------------------------------------------
| SAVE PERMIT IMAGES
|--------------------------------------------------------------------------
*/

function savePermitImages($conn, $permit_type, $permit_id)
{

    if (empty($_FILES['images']['name'][0])) {
        return;
    }


    // Storage location is decided by s3_store_upload(): the bucket when
    // S3 is enabled, otherwise PERMIT_UPLOAD_DIR, which it creates itself.


    $allowed = [
        'jpg',
        'jpeg',
        'png',
        'webp'
    ];



    $stmt = $conn->prepare("
        INSERT INTO permit_images
        (
            permit_type,
            permit_id,
            image_path
        )
        VALUES (?,?,?)
    ");


    if(!$stmt){
        die("IMAGE INSERT ERROR : ".$conn->error);
    }



    foreach($_FILES['images']['name'] as $i=>$name)
    {


        if($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK){
            continue;
        }



        $ext = strtolower(
            pathinfo($name, PATHINFO_EXTENSION)
        );



        if(!in_array($ext,$allowed)){
            continue;
        }



        $storedPath = s3_store_upload(
            $_FILES['images']['tmp_name'][$i],
            $name,
            'permits',
            $permit_type."_".$permit_id
        );



        if($storedPath)
        {


            $stmt->bind_param(
                "sis",
                $permit_type,
                $permit_id,
                $storedPath
            );


            $stmt->execute();

        }

    }



    $stmt->close();

}






/*
|--------------------------------------------------------------------------
| GET PERMIT IMAGES
|--------------------------------------------------------------------------
*/

function getPermitImages($conn, $permit_type, $permit_id)
{


    $stmt = $conn->prepare("
        SELECT
            image_id,
            image_path
        FROM permit_images
        WHERE permit_type = ?
        AND permit_id = ?
        ORDER BY created_at DESC
    ");



    if(!$stmt){

        die(
            "IMAGE SELECT ERROR : ".$conn->error
        );

    }



    $stmt->bind_param(
        "si",
        $permit_type,
        $permit_id
    );



    $stmt->execute();



    $result = $stmt->get_result();



    $rows = $result->fetch_all(
        MYSQLI_ASSOC
    );



    $stmt->close();



    return $rows;

}






/*
|--------------------------------------------------------------------------
| DELETE PERMIT IMAGE
|--------------------------------------------------------------------------
*/

function deletePermitImage($conn,$image_id)
{


    $stmt = $conn->prepare("
        SELECT image_path
        FROM permit_images
        WHERE image_id=?
    ");



    if(!$stmt){
        die("IMAGE DELETE SELECT ERROR : ".$conn->error);
    }



    $stmt->bind_param(
        "i",
        $image_id
    );



    $stmt->execute();



    $row =
    $stmt->get_result()
    ->fetch_assoc();



    $stmt->close();




    if($row)
    {

        // Handles both S3 objects and legacy files on local disk.
        media_delete($row['image_path']);

    }




    $del = $conn->prepare("
        DELETE FROM permit_images
        WHERE image_id=?
    ");



    if(!$del){
        die("IMAGE DELETE ERROR : ".$conn->error);
    }



    $del->bind_param(
        "i",
        $image_id
    );



    $del->execute();



    $del->close();

}







/*
|--------------------------------------------------------------------------
| STATUS BADGE
|--------------------------------------------------------------------------
*/

function statusBadge($status)
{


    $status =
    strtolower(trim($status));



    switch($status)
    {


        case 'approved':

            return "
            <span class='badge bg-success'>
            <i class='bi bi-check-circle-fill me-1'></i>
            Approved
            </span>";



        case 'rejected':

            return "
            <span class='badge bg-danger'>
            <i class='bi bi-x-circle-fill me-1'></i>
            Rejected
            </span>";



        case 'updated':

            return "
            <span class='badge bg-primary'>
            <i class='bi bi-arrow-repeat me-1'></i>
            Updated
            </span>";



        default:

            return "
            <span class='badge bg-warning text-dark'>
            <i class='bi bi-hourglass-split me-1'></i>
            Pending
            </span>";

    }

}

?>