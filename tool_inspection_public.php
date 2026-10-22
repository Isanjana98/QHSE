<?php
$toolType = trim((string)($_GET['tool'] ?? ''));

// Map questions for each tool (Questions with English & Sinhala text)
$checklists = [
    'Welding Machine' => [
        ['no' => 1, 'en' => 'Is the machine free of abnormal noise or abnormal heat?', 'si' => 'යන්ත්‍රය අසාමාන්‍ය ශබ්දයකින් හෝ අසාමාන්‍ය ලෙස රත්වීමකින් තොර වේද?'],
        ['no' => 2, 'en' => 'Can the welding machine be properly earthed?', 'si' => 'වෙල්ඩින් යන්ත්‍රය නිසි පරිදි භූගත කල හැකිද?'],
        ['no' => 3, 'en' => 'Are the wheels and brakes of the welding machine working properly?', 'si' => 'වෙල්ඩින් යන්ත්‍රයේ රෝද හා තිරිංග නිසි ලෙස ක්‍රියාකරයිද?'],
        ['no' => 4, 'en' => 'Does the power controlling handle/switch work properly?', 'si' => 'විදුලි බලය හසුරුවන හැඬලය/ස්විචය නිසි පරිදි ක්‍රියාකරයිද?'],
        ['no' => 5, 'en' => 'Are electrical cables and welding cables free of damages/cracks?', 'si' => 'විදුලි කේබල් සහ වෙල්ඩින් කේබල් හානි වීම්/ඉරිතැලීම් වලින් තොරද?'],
        ['no' => 6, 'en' => 'Are cable connections free of conjectures?', 'si' => 'කේබල් සම්බන්ධවීම්, අනුමාන සම්බන්දවීම් වලින් තොරවේද?'],
        ['no' => 7, 'en' => 'Are cable connection points covered and free of damages?', 'si' => 'කේබල්, යන්ත්‍රයට සවිවන ස්ථාන ආවරණය කර තිබේද? සහ පලුදු වීම් වලින් තොරද?'],
        ['no' => 8, 'en' => 'Is the welding holder free of damages?', 'si' => 'වෙල්ඩින් හෝල්ඩරය හානිවීම් වලින් තොරද?'],
        ['no' => 9, 'en' => 'Are fuses or circuit breakers correctly installed?', 'si' => 'විලායක හෝ පරිපථ බිදින නිවැරදිව සවි කර තිබේද?'],
        ['no' => 10, 'en' => 'Are the relevant fire extinguishers ready? Are those in operation?', 'si' => 'අදාල ගිනි නිවන උපකරන සුදානමින් තිබේද? ඒවා ක්‍රියාකාරීත්වයේ පවතීද?'],
        ['no' => 11, 'en' => 'Have machine operators trained in firefighting?', 'si' => 'යන්ත්‍රය ක්‍රියා කරන්නන් ගිනි නිවීමේ පුහුණුව ලබා තිබේද?']
    ],
    'Portable Drill' => [
        ['no' => 1, 'en' => 'Is the machine surface free of visible defects, missing parts or damages?', 'si' => 'යන්ත්‍රයේ මතුපිට දෘශ්‍ය දෝෂ, නැතිවූ කොටස් හෝ හානිවලින් තොරද?'],
        ['no' => 2, 'en' => 'Is the machine double insulated or having an electrical earthing system?', 'si' => 'විදුම් යන්ත්‍රය ද්විත්ව පරිවරණය කර තිබේද? හෝ විදුයුත් භූගත පද්ධතියක් සහිතද?'],
        ['no' => 3, 'en' => 'Is the power cord/plug free of visible damage?', 'si' => 'විදුලි රැහැන්/ප්ලග් හානියකින් තොරව තිබේද?'],
        ['no' => 4, 'en' => 'Is the machine handle in proper working condition?', 'si' => 'යන්ත්‍ර හැඬලය නිසි ක්‍රියාකාරී තත්වයෙන් පවතීද?'],
        ['no' => 5, 'en' => 'Does the drill bit correctly fit to the machine?', 'si' => 'විදුම් කටුව, යන්ත්‍රය සමඟ නිවැරදිව ගැළපේද?'],
        ['no' => 6, 'en' => 'If the machine is equipped with an automatic cut-off switch, is it operating properly?', 'si' => 'යන්ත්‍රය ස්වයංක්‍රීය කැපුම් ස්විචයකින් සමන්විත නම්, එය නිසි ලෙස ක්‍රියාත්මක වේද?']
    ],
    'Concrete Cutting' => [
        ['no' => 1, 'en' => 'Is the machine surface free of visible defects, missing parts or damages?', 'si' => 'යන්ත්‍රයේ මතුපිට දෘශ්‍ය දෝෂ, නැතිවූ කොටස් හෝ හානිවලින් තොරද?'],
        ['no' => 2, 'en' => 'Is the blade free of visible damages or cracks?', 'si' => 'තලය දෘශ්‍ය හානි හෝ ඉරිතැලීම් වලින් තොරද?'],
        ['no' => 3, 'en' => 'Is the blade guard correctly positioned and secured?', 'si' => 'තල ආවරණය නිවැරදිව සවි කර ආරක්ෂිතව තිබේද?']
    ],
    'Hand Grinder' => [
        ['no' => 1, 'en' => 'Is the machine surface free of visible defects, missing parts or damages?', 'si' => 'යන්ත්‍රය මතුපිට, දෘශ්‍ය දෝෂ, නැතිවූ කොටස් හෝ හානිවලින් තොරද?'],
        ['no' => 2, 'en' => 'Is the power cord or plug free of visible damages?', 'si' => 'විදුලි රැහැන් හෝ ප්ලග් දෘශ්‍ය හානිවලින් තොරද?'],
        ['no' => 3, 'en' => 'Is the power supply compatible with the specifications of the machine?', 'si' => 'බල සැපයුම, යන්ත්‍රයේ පිරිවිතරයන්ට අනුකූලද?'],
        ['no' => 4, 'en' => 'Are machine guards in correct place and free of damages?', 'si' => 'ආරක්ෂණ ආවරණ නිවැරදි ස්ථානයේ පවතීද? සහ හානිවලින් තොරද?'],
        ['no' => 5, 'en' => 'If the machine is equipped with an automatic cut-off switch, is it operating properly?', 'si' => 'යන්ත්‍රය ස්වයංක්‍රීය කැපුම් ස්විචයකින් සමන්විත නම්, එය නිසි ලෙස ක්‍රියාත්මක වේද?'],
        ['no' => 6, 'en' => 'Is the wheel compatible with the maximum speed (RPM) of the machine?', 'si' => 'තලය, යන්ත්‍රයේ උපරිම වේගය සමඟ ගැළපේද?'],
        ['no' => 7, 'en' => 'Is the wheel free of visible damages?', 'si' => 'තලය දෘශ්‍ය හානිවලින් තොරද?'],
        ['no' => 8, 'en' => 'Is the wheel attached to the spindle with the correct wheel flange and locking nuts?', 'si' => 'තලය, නිවැරදි තල ෆ්ලේංජ් සහ අගුලු නට් සහිතව ස්පින්ඩ්ලයට සවි කර තිබේද?']
    ],
    'Extension Cord' => [
        ['no' => 1, 'en' => 'Is the cord free of cracks and damages?', 'si' => 'වයරය ඉරිතැලීම් සහ හානිවීම් වලින් තොරද?'],
        ['no' => 2, 'en' => 'Are the electrical outlet and plug free of visible cracks/breaks or loose pins?', 'si' => 'විදුලි සැපයුම් මූලය සහ ප්ලගය, දෘශ්‍ය ඉරිතැලීම්/කැඩීම් හෝ ලිහිල් පින්ස් වලින් තොරද?'],
        ['no' => 3, 'en' => 'Is the earth wire correctly fixed to the plug top?', 'si' => 'භූගත වයරය, ප්ලගයට නිවැරදිව සවි කර තිබේද?'],
        ['no' => 4, 'en' => 'Do total watt and ampere values compatible with the cord?', 'si' => 'වෝට් සහ ඇම්පියර් අගයන් දිගු විදුලි රැහැන් සැපයනු ලබන අගයන් සමඟ ගැළපේද?']
    ]
];

$toolIsValid = array_key_exists($toolType, $checklists);
$questions = $toolIsValid ? $checklists[$toolType] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($toolType ?: 'Tool', ENT_QUOTES, 'UTF-8'); ?> Inspection Checklist Criteria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --orange:#ea580c; --ink:#17211a; --muted:#667085; --line:#e5e7eb; }
        * { box-sizing:border-box; }
        body { min-height:100vh; margin:0; padding:24px 16px; display:grid; place-items:center; background:#f6f8f5; color:var(--ink); font-family:'Inter',sans-serif; }
        .inspection-card { width:min(600px,100%); border:1px solid var(--line); border-radius:12px; background:#fff; box-shadow:0 16px 44px rgba(15,23,42,.08); }
        .inspection-card-head { padding:20px 24px; border-bottom:1px solid #fed7aa; border-left:5px solid var(--orange); background:#fff7ed; }
        .brand { display:flex; align-items:center; gap:8px; color:#9a3412; font-size:11px; font-weight:800; text-transform:uppercase; }
        .inspection-card-head h1 { margin:8px 0 0; color:#7c2d12; font:800 20px 'Manrope',sans-serif; }
        .inspection-card-body { padding:20px 24px; }
        .checklist-title { margin-bottom:14px; color:var(--muted); font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; }
        .question-list { display:grid; gap:10px; }
        .question-row { display:grid; grid-template-columns:28px 1fr; align-items:start; gap:12px; padding:12px; border:1px solid var(--line); border-radius:8px; background:#fafafa; }
        .question-num { width:26px; height:26px; display:grid; place-items:center; border-radius:50%; background:#fff0e4; color:#9a3412; font-size:12px; font-weight:800; }
        .question-text { color:#1e293b; font-size:13px; font-weight:600; line-height:1.4; }
        .question-text small { display:block; margin-top:3px; color:#64748b; font-size:11.5px; font-weight:400; }
        .invalid-card { padding:32px 24px; text-align:center; }
        .invalid-card i { color:var(--orange); font-size:32px; }
        .invalid-card h1 { margin:10px 0 6px; color:#7c2d12; font:800 19px 'Manrope',sans-serif; }
    </style>
</head>
<body>
    <main class="inspection-card">
        <?php if (!$toolIsValid): ?>
            <div class="invalid-card">
                <i class="bi bi-exclamation-triangle"></i>
                <h1>Checklist Criteria Unavailable</h1>
                <p class="text-muted">No tool specified or invalid tool code scanned.</p>
            </div>
        <?php else: ?>
            <header class="inspection-card-head">
                <div class="brand"><i class="bi bi-shield-check"></i> Hayleys Fentons · QHSE</div>
                <h1><?= htmlspecialchars($toolType, ENT_QUOTES, 'UTF-8'); ?> Criteria</h1>
            </header>
            <div class="inspection-card-body">
                <div class="checklist-title">Inspection Questions / පිරික්සුම් කරුණු</div>
                <div class="question-list">
                    <?php foreach ($questions as $q): ?>
                        <div class="question-row">
                            <span class="question-num"><?= $q['no']; ?></span>
                            <div class="question-text">
                                <?= htmlspecialchars($q['en'], ENT_QUOTES, 'UTF-8'); ?>
                                <small><?= htmlspecialchars($q['si'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>