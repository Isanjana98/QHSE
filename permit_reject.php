<?php

session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location:index.php");
    exit();
}


require_once __DIR__ . '/db_config.php';



if(isset($_GET['id']) && isset($_GET['type'])){


    $id = intval($_GET['id']);
    $type = $_GET['type'];


    $table = "";
    $id_column = "";


    if($type == "hot_work"){

        $table = "hot_work_permits";
        $id_column = "id";

    }
    elseif($type == "wah"){

        $table = "work_at_height_permits";
        $id_column = "id";

    }
    elseif($type == "service_provider"){

        $table = "service_provider_permits";
        $id_column = "permit_id";

    }



    if($table != ""){


        $status = "Rejected";


        $sql = "UPDATE $table 
                SET status=? 
                WHERE $id_column=?";


        $stmt = $conn->prepare($sql);



        if(!$stmt){

            die("SQL Prepare Error : ".$conn->error);

        }



        $stmt->bind_param(
            "si",
            $status,
            $id
        );


        if($stmt->execute()){

            echo "<script>
            alert('Permit Rejected Successfully');
            window.location='admin_dashboard.php';
            </script>";

        }
        else{

            echo "Update Error : ".$stmt->error;

        }


        $stmt->close();

    }


}


$conn->close();

?>