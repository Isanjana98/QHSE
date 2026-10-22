<?php
session_start();
if (empty($_SESSION['subcontractor_id'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/db_config.php';

$subcontractorId = (int)$_SESSION['subcontractor_id'];
$records = [];
$loadError = '';
$profileStmt = $conn->prepare('SELECT company_name, owner_name FROM subcontractors WHERE id = ? LIMIT 1');
if ($profileStmt) {
    $profileStmt->bind_param('i', $subcontractorId);
    $profileStmt->execute();
    $subcontractor = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();
} else {
    $subcontractor = null;
}

if (!$subcontractor) {
    $loadError = 'Could not find your subcontractor account.';
} else {
    $stmt = $conn->prepare("SELECT id, tool_type, inspection_date, division, project_location, supervisor_name, created_at
                              FROM tool_inspection_records
                             WHERE subcontractor_id = ?
                                  OR LOWER(TRIM(company_name)) = LOWER(TRIM(?))
                                ORDER BY inspection_date DESC, id DESC");
    if ($stmt) {
        $companyName = (string)$subcontractor['company_name'];
        $stmt->bind_param('is', $subcontractorId, $companyName);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
    } else {
        $loadError = 'Could not load inspection records.';
    }

}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Inspections | Subcontractor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="table_pagination.css">
    <link rel="stylesheet" href="subcontractor_navbar.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --page-bg:#f6f8f5; --line:#e5e7eb; --orange:#ea580c; --muted:#667085; }
        body { margin:0; min-height:100vh; display:flex; background:var(--page-bg); color:#17211a; font-family:'Inter',sans-serif; }
        .inspection-main { width:100%; min-width:0; padding:22px 28px; }
        .inspection-heading { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:18px; }
        .inspection-heading h1 { margin:0; color:#17211a; font:800 22px 'Manrope',sans-serif; }
        .inspection-heading p { margin:4px 0 0; color:var(--muted); font-size:12px; }
        .inspection-count { padding:6px 10px; border:1px solid #fed7aa; border-radius:20px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; white-space:nowrap; }
        .inspection-table-wrap { overflow:auto; border:1px solid var(--line); border-radius:10px; background:#fff; box-shadow:0 4px 18px rgba(15,23,42,.04); }
        .inspection-table { width:100%; min-width:740px; border-collapse:collapse; }
        .inspection-table th { padding:11px 14px; background:#fff7ed; color:#7c2d12; font-size:10px; font-weight:800; text-align:left; text-transform:uppercase; }
        .inspection-table td { padding:12px 14px; border-top:1px solid #eef0f2; color:#344054; font-size:12px; vertical-align:middle; }
        .tool-name { display:flex; align-items:center; gap:9px; color:#17211a; font-weight:700; }
        .tool-icon { width:32px; height:32px; display:grid; place-items:center; border-radius:8px; background:#fff0e4; color:#c2410c; font-size:15px; }
        .view-button { display:inline-flex; align-items:center; gap:6px; padding:7px 10px; border:1px solid #d0d5dd; border-radius:7px; background:#fff; color:#344054; font-size:11px; font-weight:700; }
        .view-button { border-color:#d0d5dd; background:#fff; color:#344054; }
        .view-button:hover { background:#f9fafb; }
        .state-panel { padding:28px 20px; border:1px solid var(--line); border-radius:10px; background:#fff; color:var(--muted); text-align:center; font-size:13px; }
        .state-panel i { display:block; margin-bottom:8px; color:var(--orange); font-size:26px; }
        .modal-header { background:#fff7ed; border-color:#fed7aa; }
        .modal-title { color:#7c2d12; font:700 16px 'Manrope',sans-serif; }
        .qr-frame { width:220px; min-height:220px; display:grid; place-items:center; margin:8px auto 16px; padding:12px; border:1px solid var(--line); border-radius:10px; background:#fff; }
        .qr-frame img, .qr-frame canvas { display:block; max-width:100%; height:auto; }
        .qr-caption { color:#475467; font-size:12px; font-weight:700; }
        .qr-public-link { display:block; overflow-wrap:anywhere; margin-top:7px; color:#667085; font-size:10px; }
        @media(max-width:992px) { body { display:block; } .inspection-main { padding:14px; } }
        @media(max-width:560px) { .inspection-heading { align-items:flex-start; flex-direction:column; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/subcontractor_navbar.php'; ?>
<main class="inspection-main">
    <header class="inspection-heading">
        <div><h1>Tool Inspections</h1><p>Inspection records registered for your company. Open a QR code to print or share it at the tool.</p></div>
        <span class="inspection-count"><?= count($records); ?> records</span>
    </header>

    <?php if ($loadError !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (!$records): ?>
        <div class="state-panel"><i class="bi bi-tools"></i>No tool inspections have been recorded for your company yet.</div>
    <?php else: ?>
        <div class="inspection-table-wrap">
            <table class="inspection-table" data-table-pagination>
                <thead><tr><th>Inspected Tool</th><th>Inspection Date</th><th>Division</th><th>Location</th><th>Supervisor</th><th>Details</th></tr></thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><span class="tool-name"><span class="tool-icon"><i class="bi bi-tools"></i></span><?= htmlspecialchars($record['tool_type'] ?? 'Tool inspection', ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?= htmlspecialchars($record['inspection_date'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($record['division'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($record['project_location'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($record['supervisor_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><button type="button" class="view-button" data-inspection-id="<?= (int)$record['id']; ?>"><i class="bi bi-eye"></i> View details</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<div class="modal fade" id="inspectionDetailsModal" tabindex="-1" aria-labelledby="inspectionDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header"><h2 class="modal-title" id="inspectionDetailsTitle">Inspection details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body p-4" id="inspectionDetailsBody"><div class="state-panel">Loading inspection details...</div></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="table_pagination.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const detailsModal = new bootstrap.Modal(document.getElementById('inspectionDetailsModal'));
    const detailsBody = document.getElementById('inspectionDetailsBody');
    let detailsRequest = 0;

    document.querySelectorAll('[data-inspection-id]').forEach((button) => {
        button.addEventListener('click', async function () {
            const request = ++detailsRequest;
            detailsBody.textContent = 'Loading inspection details...';
            detailsModal.show();
            try {
                const response = await fetch('get_inspection_answers.php?id=' + encodeURIComponent(this.dataset.inspectionId), { credentials: 'same-origin' });
                const data = await response.json();
                if (request !== detailsRequest) return;
                detailsBody.replaceChildren();

                if (!data.ok) {
                    detailsBody.textContent = data.error || 'Could not load inspection details.';
                    return;
                }
                if (!data.linked) {
                    detailsBody.textContent = 'Checklist answers are not available for this inspection record.';
                    return;
                }

                const noCount = data.answers.filter((answer) => answer.ans === 'No').length;
                if (noCount > 0) {
                    const warning = document.createElement('div');
                    warning.className = 'alert alert-warning';
                    warning.textContent = noCount + ' checklist item' + (noCount === 1 ? '' : 's') + ' marked No.';
                    detailsBody.append(warning);
                }

                const answerList = document.createElement('div');
                answerList.className = 'list-group';
                data.answers.forEach((answer) => {
                    const row = document.createElement('div');
                    row.className = 'list-group-item d-flex align-items-start justify-content-between gap-3';
                    const question = document.createElement('div');
                    question.textContent = answer.no + '. ' + answer.en + (answer.si ? ' / ' + answer.si : '');
                    const result = document.createElement('strong');
                    result.className = answer.ans === 'Yes' ? 'text-success' : (answer.ans === 'No' ? 'text-danger' : 'text-secondary');
                    result.textContent = answer.ans || '-';
                    row.append(question, result);
                    answerList.append(row);
                });
                detailsBody.append(answerList);

                const extra = data.extra || {};
                const completion = [extra.completed_by, extra.workplace, extra.reference].filter(Boolean);
                if (completion.length) {
                    const note = document.createElement('p');
                    note.className = 'text-secondary small mt-3 mb-0';
                    note.textContent = 'Completed by: ' + (extra.completed_by || '-') + (extra.workplace ? ' · Workplace: ' + extra.workplace : '') + (extra.reference ? ' · Reference: ' + extra.reference : '');
                    detailsBody.append(note);
                }
            } catch (error) {
                if (request === detailsRequest) detailsBody.textContent = 'Could not load inspection details.';
            }
        });
    });

});
</script>
</body>
</html>