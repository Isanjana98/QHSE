<?php
session_start();
if (!isset($_SESSION['supervisor_logged']) || $_SESSION['supervisor_logged'] !== true) {
    header('Location: index.php');
    exit();
}

$tools = [
    ['name' => 'Welding Machine', 'slug' => 'welding-machine'],
    ['name' => 'Portable Drill', 'slug' => 'portable-drill'],
    ['name' => 'Concrete Cutting', 'slug' => 'concrete-cutting'],
    ['name' => 'Hand Grinder', 'slug' => 'hand-grinder'],
    ['name' => 'Extension Cord', 'slug' => 'extension-cord'],
];
$host = $_SERVER['HTTP_HOST'] ?? '';
$hostIsValid = (bool)preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($hostIsValid) {
    foreach ($tools as &$tool) {
        $tool['qr_url'] = $scheme . '://' . $host . $basePath . '/tool_inspection_public.php?tool=' . rawurlencode($tool['name']);
    }
    unset($tool);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspection QR Codes | QHSE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --ink:#17211a; --muted:#667085; --line:#e5e7eb; --orange:#ea580c; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:#f6f8f5; color:var(--ink); font-family:'Inter',sans-serif; }
        .page-layout-wrapper { display:flex; min-height:100vh; }
        .main-content-area { flex:1; min-width:0; padding:24px 28px; }
        .qr-page-head { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:20px; }
        .qr-page-head h1 { margin:0; font:800 22px 'Manrope',sans-serif; }
        .qr-page-head p { margin:5px 0 0; color:var(--muted); font-size:12px; }
        .qr-back { display:inline-flex; align-items:center; gap:7px; padding:8px 11px; border:1px solid var(--line); border-radius:7px; background:#fff; color:#344054; font-size:12px; font-weight:700; text-decoration:none; white-space:nowrap; }
        .qr-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
        .qr-card { display:flex; flex-direction:column; align-items:center; padding:17px; border:1px solid var(--line); border-radius:9px; background:#fff; }
        .qr-card h2 { align-self:stretch; margin:0; font:700 14px 'Manrope',sans-serif; }
        .qr-card p { align-self:stretch; margin:5px 0 0; color:var(--muted); font-size:11px; }
        .qr-image { width:210px; height:210px; display:grid; place-items:center; margin:13px 0; padding:8px; border:1px solid #eef0f2; border-radius:7px; background:#fff; }
        .qr-image img,.qr-image canvas { display:block; max-width:100%; height:auto; }
        .qr-download { width:100%; display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:8px 12px; border:0; border-radius:7px; background:var(--orange); color:#fff; font-size:12px; font-weight:700; }
        .qr-download:hover { background:#c2410c; }
        .empty-state { padding:34px 20px; border:1px solid var(--line); border-radius:9px; background:#fff; color:var(--muted); text-align:center; }
        @media(max-width:992px) { .page-layout-wrapper { display:block; } .main-content-area { padding:16px; } }
        @media(max-width:560px) { .qr-page-head { align-items:flex-start; flex-direction:column; } }
    </style>
</head>
<body>
<div class="page-layout-wrapper">
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="main-content-area">
        <header class="qr-page-head">
            <div><h1>Tool Checklist QR Codes</h1><p>5 standard Power Tool checklists</p></div>
            <a class="qr-back" href="tool_inspection.php"><i class="bi bi-arrow-left"></i> Tool Inspection</a>
        </header>
        <?php if (!$hostIsValid): ?>
            <div class="alert alert-danger" role="alert">Could not determine the QR link address.</div>
        <?php else: ?>
            <div class="qr-grid">
                <?php foreach ($tools as $index => $tool): ?>
                    <article class="qr-card">
                        <h2><?= htmlspecialchars($tool['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p>Standard inspection checklist</p>
                        <div class="qr-image" id="qr-<?= (int)$index; ?>" data-qr-url="<?= htmlspecialchars($tool['qr_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <button type="button" class="qr-download" data-qr-target="qr-<?= (int)$index; ?>" data-tool-name="<?= htmlspecialchars($tool['name'], ENT_QUOTES, 'UTF-8'); ?>" data-file-name="<?= htmlspecialchars($tool['slug'], ENT_QUOTES, 'UTF-8'); ?>-checklist-sticker"><i class="bi bi-file-earmark-pdf"></i> Download Sticker PDF</button>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div id="qrPdfError" class="alert alert-danger mt-3" role="alert" hidden></div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pdfError = document.getElementById('qrPdfError');

    function loadImageData(url) {
        return fetch(url)
            .then(function (response) {
                if (!response.ok) throw new Error('Could not load the Hayleys logo.');
                return response.blob();
            })
            .then(function (blob) {
                return new Promise(function (resolve, reject) {
                    const reader = new FileReader();
                    reader.onload = function () { resolve(reader.result); };
                    reader.onerror = function () { reject(new Error('Could not read the Hayleys logo.')); };
                    reader.readAsDataURL(blob);
                });
            });
    }

    document.querySelectorAll('.qr-image[data-qr-url]').forEach(function (container) {
        const url = container.dataset.qrUrl;
        if (url && typeof QRCode !== 'undefined') {
            new QRCode(container, { text: url, width: 512, height: 512, correctLevel: QRCode.CorrectLevel.M });
        }
    });

    document.querySelectorAll('.qr-download').forEach(function (button) {
        button.addEventListener('click', async function () {
            const container = document.getElementById(this.dataset.qrTarget);
            const canvas = container && container.querySelector('canvas');
            const image = container && container.querySelector('img');
            if (!window.jspdf || (!canvas && !(image && image.complete && image.naturalWidth > 0))) {
                pdfError.textContent = 'The PDF or QR generator could not load. Refresh the page and try again.';
                pdfError.hidden = false;
                return;
            }

            const button = this;
            const originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Preparing PDF...';
            try {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a6' });
                const qrData = canvas ? canvas.toDataURL('image/png') : image.src;
                const logoData = await loadImageData('assets/images/hayleys_logo.png');
                const qrSize = 58;
                const pageWidth = 105;
                pdf.setDrawColor(190, 198, 205);
                pdf.setLineWidth(0.4);
                pdf.roundedRect(7, 7, 91, 134, 3, 3, 'S');
                pdf.addImage(logoData, 'PNG', 28, 11, 49, 24.5);
                pdf.setTextColor(23, 33, 26);
                pdf.setFont('helvetica', 'bold');
                pdf.setFontSize(15);
                pdf.text(this.dataset.toolName, pageWidth / 2, 42, { align: 'center', maxWidth: 83 });
                pdf.setFont('helvetica', 'normal');
                pdf.setTextColor(102, 112, 133);
                pdf.setFontSize(8);
                pdf.text('Scan to view inspection checklist', pageWidth / 2, 49, { align: 'center' });
                pdf.addImage(qrData, 'PNG', (pageWidth - qrSize) / 2, 53, qrSize, qrSize);
                pdf.setTextColor(71, 84, 103);
                pdf.setFontSize(8);
                pdf.text('QHSE', pageWidth / 2, 117, { align: 'center' });
                pdf.setFontSize(7);
                pdf.text('Tool inspection checklist', pageWidth / 2, 124, { align: 'center' });
                pdf.save(this.dataset.fileName + '.pdf');
            } catch (error) {
                pdfError.textContent = error.message || 'Could not create this sticker PDF.';
                pdfError.hidden = false;
            } finally {
                button.disabled = false;
                button.innerHTML = originalText;
            }
        });
    });
});
</script>
</body>
</html>