<?php
/*
====================================================================
 permit_action.php  (simple version)
 --------------------------------------------------------------
 Handles the "Approve" and "Reject" links from the permit email.
 Just updates the "status" column directly - no fancy result pages.

 URL shape used in the email:
   permit_action.php?action=approve&id=123&token=xxxx
   permit_action.php?action=reject&id=123&token=xxxx   (asks for a reason first, then updates status)
====================================================================
*/

require_once __DIR__ . '/db_config.php';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$action    = strtolower(trim($_GET['action'] ?? $_POST['action'] ?? ''));
$permit_id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$token     = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (!in_array($action, ['approve', 'reject'], true) || $permit_id <= 0 || $token === '') {
    die("Invalid link.");
}

$stmt = $conn->prepare("SELECT permit_number, status, approval_token FROM service_provider_permits WHERE permit_id = ?");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
    die("Permit not found.");
}
if (empty($permit['approval_token']) || !hash_equals($permit['approval_token'], $token)) {
    die("This link has expired or already been used.");
}
if ($permit['status'] !== 'Pending') {
    die("This permit's status is already \"" . h($permit['status']) . "\".");
}

/* ---------------- APPROVE ---------------- */
if ($action === 'approve') {
    $upd = $conn->prepare("UPDATE service_provider_permits
                            SET status = 'Approved', rejection_reason = NULL, approval_token = NULL, updated_at = NOW()
                            WHERE permit_id = ?");
    $upd->bind_param("i", $permit_id);
    $upd->execute();
    $upd->close();
    $conn->close();

    echo "Permit " . h($permit['permit_number']) . " status changed to Approved.";
    exit();
}

/* ---------------- REJECT ---------------- */
if ($action === 'reject') {

    // POST = reason submitted -> update status now
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            die("Please enter a rejection reason.");
        }

        $upd = $conn->prepare("UPDATE service_provider_permits
                                SET status = 'Rejected', rejection_reason = ?, approval_token = NULL, updated_at = NOW()
                                WHERE permit_id = ?");
        $upd->bind_param("si", $reason, $permit_id);
        $upd->execute();
        $upd->close();
        $conn->close();

        echo "Permit " . h($permit['permit_number']) . " status changed to Rejected.";
        exit();
    }

    // GET = just ask for the reason first (status not changed yet)
    $conn->close();
    ?>
    <form method="POST" action="permit_action.php">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="id" value="<?= h($permit_id) ?>">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <p>Reason for rejecting permit <?= h($permit['permit_number']) ?>:</p>
        <textarea name="reason" required style="width:100%;height:100px;"></textarea><br><br>
        <button type="submit">Reject</button>
    </form>
    <?php
    exit();
}