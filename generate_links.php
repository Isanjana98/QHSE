<?php
require_once __DIR__ . '/db_config.php';


$sql = "SELECT id, company_name, email, mobile_number, update_token FROM subcontractors";
$result = $conn->query($sql);

echo "<h3>Subcontractor Update Links</h3>";
echo "<ul>";

while ($row = $result->fetch_assoc()) {
   
    if (empty($row['update_token'])) {
        $token = bin2hex(random_bytes(16)); 
        $update_stmt = $conn->prepare("UPDATE subcontractors SET update_token = ? WHERE id = ?");
        $update_stmt->bind_param("si", $token, $row['id']);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        $token = $row['update_token'];
    }

   
    $link = "https://yourdomain.com/update_subcontractor.php?token=" . $token;

    echo "<li><strong>" . htmlspecialchars($row['company_name']) . "</strong> (" . htmlspecialchars($row['email']) . ") - <a href='" . $link . "' target='_blank'>Link eka methanින් ගන්න</a></li>";
}

echo "</ul>";
$conn->close();
?>