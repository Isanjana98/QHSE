<?php
// get_inspection_answers.php
// tool_inspection.php eke "View" click karama checklist questions + answers JSON widiha denawa.
session_start();
header('Content-Type: application/json; charset=utf-8');

$is_sup   = isset($_SESSION['supervisor_logged']) && $_SESSION['supervisor_logged'] === true;
$is_admin = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$is_sub   = !empty($_SESSION['subcontractor_id']);
$publicToken = (string)($_GET['token'] ?? '');
$hasPublicToken = (bool)preg_match('/^[a-f0-9]{64}$/', $publicToken);
$publicTool = trim((string)($_GET['tool'] ?? ''));
$publicToolTypes = ['Welding Machine', 'Portable Drill', 'Concrete Cutting', 'Hand Grinder', 'Extension Cord'];
$hasPublicTool = in_array($publicTool, $publicToolTypes, true);
if (!$is_sup && !$is_admin && !$is_sub && !$hasPublicToken && !$hasPublicTool) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit();
}

require_once __DIR__ . "/db_config.php";

$BANK = [
    'Portable Drill' => [
        'table' => 'drill_submissions',
        'questions' => [
            'q1' => ['Is the machine surface free of visible defects, missing parts or damages?', 'යන්ත්‍රයේ මතුපිට දෘශ්‍ය දෝෂ, නැතිවූ කොටස් හෝ හානිවලින් තොරද?'],
            'q2' => ['Is the machine double insulated or having an electrical earthing system?', 'විදුම් යන්ත්‍රය ද්විත්ව පරිවරණය කර තිබේද? හෝ විදුයුත් භූගත පද්ධතියක් සහිතද?'],
            'q3' => ['Is the power cord/plug free of visible damage?', 'විදුලි රැහැන්/ප්ලග් හානියකින් තොරව තිබේද?'],
            'q4' => ['Is the machine handle in proper working condition?', 'යන්ත්‍ර හැඬලය නිසි ක්‍රියාකාරී තත්වයෙන් පවතීද?'],
            'q5' => ['Does the drill bit correctly fit to the machine?', 'විදුම් කටුව, යන්ත්‍රය සමඟ නිවැරදිව ගැළපේද?'],
            'q6' => ['If the machine is equipped with an automatic cut-off switch, is it operating properly?', 'යන්ත්‍රය ස්වයංක්‍රීය කැපුම් ස්විචයකින් සමන්විත නම්, එය නිසි ලෙස ක්‍රියාත්මක වේද?'],
        ],
    ],
    'Concrete Cutting' => [
        'table' => 'concrete_cutting_submissions',
        'questions' => [
            'q1' => ['Is the machine surface free of visible defects, missing parts or damages?', 'යන්ත්‍රය මතුපිට, දෘශ්‍ය දෝෂ, නැතිවූ කොටස් හෝ හානි වලින් තොරද?'],
            'q2' => ['Is the power cord or plug free of visible damages?', 'විදුලි රැහැන් හෝ ප්ලග් දෘශ්‍ය හානි වලින් තොරද?'],
            'q3' => ['Is the power supply compatible with the specifications of the machine?', 'බල සැපයුම, යන්ත්‍රයේ නිර්දේශයන්ට අනුකූලද?'],
            'q4' => ['If the machine is equipped with an automatic cut-off switch, is it operating properly?', 'යන්ත්‍රය ස්වයංක්‍රීය කැපුම් ස්විචයකින් සමන්විත නම්, එය නිසි ලෙස ක්‍රියාත්මක වේද?'],
            'q5' => ['Are the bit size and type best suited for the machine spindle?', 'කටුවේ ප්‍රමාණය සහ වර්ගය යන්ත්‍ර ස්පින්ඩ්ලයට වඩාත් නිවැරදිව ගැළපේද?'],
            'q6' => ['Is the bit compatible with the maximum speed (RPM) of the machine?', 'කටුව, යන්ත්‍රයේ උපරිම වේගය (RPM) සමඟ ගැළපේද?'],
            'q7' => ['Is the bit free of visible damages?', 'කටුව දෘශ්‍ය හානිවලින් තොරද?'],
            'q8' => ['Sufficient flow of water hoses are available for coolant and dust suppression?', 'සිසිලනය සහ ධූවි මර්දනය සඳහා ප්‍රමාණවත් ජල හෝස් ප්‍රවාහයක් තිබේද?'],
        ],
    ],
    'Hand Grinder' => [
        'table' => 'grinder_submissions',
        'questions' => [
            'q1' => ['Is the machine surface free of visible defects, missing parts or damages?', 'යන්ත්‍රය මතුපිට, දෘශ්‍ය දෝෂ, නැතිවූ කොටස් හෝ හානිවලින් තොරද?'],
            'q2' => ['Is the power cord or plug free of visible damages?', 'විදුලි රැහැන් හෝ ප්ලග් දෘශ්‍ය හානිවලින් තොරද?'],
            'q3' => ['Is the power supply compatible with the specifications of the machine?', 'බල සැපයුම, යන්ත්‍රයේ පිරිවිතරයන්ට අනුකූලද?'],
            'q4' => ['Are machine guards in correct place and free of damages?', 'ආරක්ෂණ ආවරණ නිවැරදි ස්ථානයේ පවතීද? සහ හානිවලින් තොරද?'],
            'q5' => ['If the machine is equipped with an automatic cut-off switch, is it operating properly?', 'යන්ත්‍රය ස්වයංක්‍රීය කැපුම් ස්විචයකින් සමන්විත නම්, එය නිසි ලෙස ක්‍රියාත්මක වේද?'],
            'q6' => ['Is the wheel compatible with the maximum speed (RPM) of the machine?', 'තලය, යන්ත්‍රයේ උපරිම වේගය සමඟ ගැළපේද?'],
            'q7' => ['Is the wheel free of visible damages?', 'තලය දෘශ්‍ය හානිවලින් තොරද?'],
            'q8' => ['Is the wheel attached to the spindle with the correct wheel flange and locking nuts?', 'තලය, නිවැරදි තල ෆ්ලේංජ් සහ අගුලු නට් සහිතව ස්පින්ඩ්ලයට සවි කර තිබේද?'],
        ],
    ],
    'Extension Cord' => [
        'table' => 'extension_submissions',
        'questions' => [
            'q1' => ['Is the cord free of cracks and damages?', 'වයරය ඉරිතැලීම් සහ හානිවීම් වලින් තොරද?'],
            'q2' => ['Are the electrical outlet and plug free of visible cracks/breaks or loose pins?', 'විදුලි සැපයුම් මූලය සහ ප්ලගය, දෘශ්‍ය ඉරිතැලීම්/කැඩීම් හෝ ලිහිල් පින්ස් වලින් තොරද?'],
            'q3' => ['Is the earth wire correctly fixed to the plug top?', 'භූගත වයරය, ප්ලගයට නිවැරදිව සවි කර තිබේද?'],
            'q4' => ['Do the total watt and ampere values of the connected electrical equipment compatible with the values supplied by the extension cord?', 'සම්බන්ධ කරන විදුලි උපකරණ වල මුල් වෝට් සහ ඇම්පියර් අගයන් දිගු විදුලි රැහැන් සැපයනු ලබන අගයන් සමඟ ගැළපේද?'],
        ],
    ],
    'Ladder' => [
        'table' => 'ladder_submissions',
        'questions' => [
            'q1' => ['Are any parts broken, cracked or splintered?', 'කිසියම් කොටසක් කැඩී, ඉරිමලා හෝ පළුදුවී තිබේද?'],
            'q2' => ['Are there any defects in the side rails, rungs or other parts?', 'පැති වැටවල්, පාද තබන කොටස් හෝ වෙනත් කොටස්වල දෝෂ තිබේද?'],
            'q3' => ['Are there any makeshift repairs on the ladder?', 'ඉණිමඟෙහි තාවකාලික අලුත්වැඩියා කර තිබේද?'],
            'q4' => ['Are there any unworkable (dilapidated or damaged) extension ladder locks, pulleys or other fittings?', 'දිගු කළ හැකි ඉණිමඟක නුසුදුසු (අබලන්වූ හෝ හානිවූ) අගුල්, කපි හෝ වෙනත් සවි කිරීම් තිබේද?'],
            'q5' => ['Is the rope on extension ladder dilapidated or broken?', 'දිගු කළ හැකි ඉණිමඟෙහි කඹය ගෙවී හෝ කැඩී තිබේද?'],
            'q6' => ['Is the ladder twisted, warped or bowed?', 'ඉණිමඟ ඇඹරී, විකෘති වී හෝ නැමී තිබේද?'],
        ],
    ],
    'Scaffold' => [
        'table' => 'scaffold_submissions',
        'questions' => [
            'q1' => ['Are handrails, mid rails and toe boards fixed?', 'අත් රේල්ස්, මැද රේල්ස්, පා පුවරු සවි කර තිබේද?'],
            'q2' => ['Is the deck complete?', 'තට්ටුව සම්පූර්ණද?'],
            'q3' => ['Are all scaffolding components present, tight and secure?', 'සියලුම පලංචි උපාංග ඉදිරිපත් කර, තද කර සහ ආරක්ෂිතව තිබේද?'],
            'q4' => ['Are the feet firmly attached to the supports? Or are the wheels of a wheeled scaffold fitted with locks?', 'පාදයන හොඳින් ආධාරක මත සවි කර තිබේද? හෝ රෝද සහිත පලංචියක රෝද සඳහා අගුලු සවි කර තිබේද?'],
            'q5' => ['Is the scaffold tie to rigid support horizontally and vertically or Free-standing tower scaffold steady with guy wire or out triggers?', 'පලංචිය, ස්ථාවර ලක්ෂ්‍යයන් සමඟ සිරස්ව සහ තිරස්ව ගැටගසා තිබේද? / නිදහස් පලංචි කුළුනක් නම් ගයි වයර් හෝ පිටතින් ආධාරක සවි කර තිබේද?'],
            'q6' => ['Is the vertical ladder securely fastened?', 'සිරස් ඉණිමඟ ආරක්ෂිත ලෙස සවි කර තිබේද?'],
            'q7' => ['Are there separate ropes for raising and lowering tools or materials? (If more than 6ft height)', 'මෙවලම් හෝ ද්‍රව්‍ය ඉහළ නැංවීමට සහ පහත් කිරීමට වෙනම ලණු තිබේද? (උස අඩි 6ට වැඩි නම්)'],
            'q8' => ['Are warning devices/signs or barrier tapes fixed?', 'අනතුරු ඇඟවීමේ උපාංග/සලකුණු හෝ බාධක පටි සවි කර තිබේද?'],
            'q9' => ['Is the minimum clearance maintain? (from overhead power lines)', 'අවම නිෂ්කාශනය පවත්වා ගෙන යන්නේද? (උඩිස් විදුලි රැහැන් වලින්)'],
        ],
    ],
    'Welding Machine' => [
        'table' => 'welding_submissions',
        'questions' => [
            'q1' => ['Is the machine free of abnormal noise or abnormal heat?', 'යන්ත්‍රය අසාමාන්‍ය ශබ්දයකින් හෝ අසාමාන්‍ය ලෙස රත්වීමකින් තොර වේද?'],
            'q2' => ['Can the welding machine be properly earthed?', 'වෙල්ඩින් යන්ත්‍රය නිසි පරිදි භූගත කල හැකිද?'],
            'q3' => ['Are the wheels and brakes of the welding machine working properly?', 'වෙල්ඩින් යන්ත්‍රයේ රෝද හා තිරිංග නිසි ලෙස ක්‍රියාකරයිද?'],
            'q4' => ['Does the power controlling handle/switch work properly?', 'විදුලි බලය හසුරුවන හැඬලය/ස්විචය නිසි පරිදි ක්‍රියාකරයිද?'],
            'q5' => ['Are electrical cables and welding cables free of damages/cracks?', 'විදුලි කේබල් සහ වෙල්ඩින් කේබල් හානි වීම්/ඉරිතැලීම් වලින් තොරද?'],
            'q6' => ['Are cable connections free of conjectures?', 'කේබල් සම්බන්ධවීම්, අනුමාන සම්බන්දවීම් වලින් තොරවේද?'],
            'q7' => ['Are cable connection points covered and free of damages?', 'කේබල්, යන්ත්‍රයට සවිවන ස්ථාන ආවරණය කර තිබේද? සහ පලුදු වීම් වලින් තොරද?'],
            'q8' => ['Is the welding holder free of damages?', 'වෙල්ඩින් හෝල්ඩරය හානිවීම් වලින් තොරද?'],
            'q9' => ['Are fuses or circuit breakers correctly installed?', 'විලායක හෝ පරිපථ බිදින නිවැරදිව සවි කර තිබේද?'],
            'q10' => ['Are the relevant fire extinguishers ready? Are those in operation?', 'අදාල ගිනි නිවන උපකරන සුදානමින් තිබේද? ඒවා ක්‍රියාකාරීත්වයේ පවතීද?'],
            'q11' => ['Have machine operators trained in firefighting?', 'යන්ත්‍රය ක්‍රියා කරන්නන් ගිනි නිවීමේ පුහුණුව ලබා තිබේද?'],
        ],
    ],
];

if ($hasPublicTool) {
    $questions = [];
    foreach ($BANK[$publicTool]['questions'] as $key => $texts) {
        $questions[] = [
            'no' => (int)substr($key, 1),
            'en' => $texts[0],
            'si' => $texts[1],
        ];
    }
    echo json_encode(['ok' => true, 'linked' => true, 'tool' => $publicTool, 'answers' => $questions], JSON_UNESCAPED_UNICODE);
    exit();
}

$publicAccess = false;
$id = (int)($_GET['id'] ?? 0);
if ($hasPublicToken) {
    $tokenStmt = $conn->prepare('SELECT record_id FROM tool_inspection_qr_tokens WHERE public_token = ? LIMIT 1');
    if (!$tokenStmt) { echo json_encode(['ok' => false, 'error' => 'Could not validate QR code']); exit(); }
    $tokenStmt->bind_param('s', $publicToken);
    $tokenStmt->execute();
    $tokenRecord = $tokenStmt->get_result()->fetch_assoc();
    $tokenStmt->close();
    if (!$tokenRecord) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Inspection record not found']);
        exit();
    }
    $id = (int)$tokenRecord['record_id'];
    $publicAccess = true;
} elseif ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit();
}

$stmt = $conn->prepare("SELECT tool_type, submission_id
                          FROM tool_inspection_records WHERE id = ?");
if (!$stmt) { echo json_encode(['ok' => false, 'error' => 'Query failed']); exit(); }
$stmt->bind_param("i", $id);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rec) { echo json_encode(['ok' => false, 'error' => 'Record not found']); exit(); }

if ($is_sub && !$publicAccess) {
    $subcontractorId = (int)$_SESSION['subcontractor_id'];
    $profileStmt = $conn->prepare('SELECT company_name, owner_name FROM subcontractors WHERE id = ? LIMIT 1');
    if (!$profileStmt) { echo json_encode(['ok' => false, 'error' => 'Could not verify account']); exit(); }
    $profileStmt->bind_param('i', $subcontractorId);
    $profileStmt->execute();
    $profile = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();

    $accessStmt = $conn->prepare("SELECT id FROM tool_inspection_records
                                   WHERE id = ?
                                     AND (subcontractor_id = ?
                                                                                    OR LOWER(TRIM(company_name)) = LOWER(TRIM(?)))
                                   LIMIT 1");
    if (!$profile || !$accessStmt) {
        echo json_encode(['ok' => false, 'error' => 'Could not verify account']);
        exit();
    }
    $recordId = $id;
    $companyName = (string)$profile['company_name'];
    $accessStmt->bind_param('iis', $recordId, $subcontractorId, $companyName);
    $accessStmt->execute();
    $belongsToSubcontractor = (bool)$accessStmt->get_result()->fetch_assoc();
    $accessStmt->close();
    if (!$belongsToSubcontractor) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You cannot access this inspection record']);
        exit();
    }
}

$tool = $rec['tool_type'];
$sid  = (int)($rec['submission_id'] ?? 0);

// table nama user input eken nemei, whitelist ekaken witharai ganne
if (!isset($BANK[$tool]) || $sid <= 0) {
    echo json_encode(['ok' => true, 'linked' => false]);
    exit();
}

$table = $BANK[$tool]['table'];
$q = $conn->prepare("SELECT * FROM `$table` WHERE id = ?");
if (!$q) { echo json_encode(['ok' => true, 'linked' => false]); exit(); }
$q->bind_param("i", $sid);
$q->execute();
$row = $q->get_result()->fetch_assoc();
$q->close();

if (!$row) { echo json_encode(['ok' => true, 'linked' => false]); exit(); }

$answers = [];
foreach ($BANK[$tool]['questions'] as $key => $texts) {
    $answers[] = [
        'no'  => (int)substr($key, 1),
        'en'  => $texts[0],
        'si'  => $texts[1],
        'ans' => $row[$key] ?? '',
    ];
}

$response = [
    'ok'      => true,
    'linked'  => true,
    'answers' => $answers,
];
if (!$publicAccess) {
    $response['extra'] = [
        'completed_by' => $row['employee_name'] ?? '',
        'epf_nic'      => $row['epf_nic'] ?? '',
        'workplace'    => $row['project_workplace'] ?? '',
        'reference'    => $row['reference'] ?? '',
    ];
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);