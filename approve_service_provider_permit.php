<?php

session_start();


if(!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true){

    header("Location:index.php");
    exit();

}



require_once __DIR__ . '/db_config.php';



if(isset($_GET['id'])){


$permit_id = $_GET['id'];



// Approval time

$permit_start = date("Y-m-d H:i:s");


// 6 days validity

$permit_finish = date(
    "Y-m-d H:i:s",
    strtotime("+6 days")
);



$status="Approved";



$stmt=$conn->prepare("

UPDATE service_provider_permits

SET

status=?,

period_from=?,

period_to=?

WHERE permit_id=?

");



$stmt->bind_param(

"sssi",

$status,

$permit_start,

$permit_finish,

$permit_id

);



if($stmt->execute()){


echo "

<script>

alert('Permit Approved Successfully');

window.location='admin_dashboard.php';

</script>

";


}
else{


echo "Approval Failed : ".$stmt->error;


}


$stmt->close();


}


$conn->close();


?>