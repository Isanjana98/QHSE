<?php
session_start();

// Admin login check
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

// Database connection details
require_once __DIR__ . '/db_config.php';
 $tutorialQuestions = require __DIR__ . '/tutorial_questions.php';
$completionFields = require __DIR__ . '/subcontractor_completion_fields.php';
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$id  = isset($_GET['id']) ? intval($_GET['id']) : 0;
$row = null;

$stmt = $conn->prepare("SELECT * FROM subcontractors WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$quizResult = null;
$quizAnswers = [];
if ($row) {
    $quizStmt = $conn->prepare("SELECT score, total_questions, percentage, answers, submitted_at
                                  FROM quiz_results
                                 WHERE subcontractor_id = ? AND total_questions > 0
                                 ORDER BY submitted_at DESC, id DESC
                                 LIMIT 1");
    if ($quizStmt) {
        $quizStmt->bind_param("i", $id);
        $quizStmt->execute();
        $quizResult = $quizStmt->get_result()->fetch_assoc();
        $quizStmt->close();
        if ($quizResult && !empty($quizResult['answers'])) {
            $decodedAnswers = json_decode($quizResult['answers'], true);
            if (is_array($decodedAnswers)) {
                $quizAnswers = array_values($decodedAnswers);
            }
        }
    }
}
$conn->close();

// ---------- helpers ----------
function is_empty_val($v) {
    return $v === null || trim((string)$v) === '';
}

// Read-only input (same look as the registration form). Empty values are highlighted in red.
function show_input($label, $value, $type = 'text') {
    $empty = is_empty_val($value);
    $cls = 'form-control' . ($empty ? ' is-empty' : '');
    $ph  = $empty ? 'Not provided' : '';
    echo '<div class="mb-3">';
    echo '<label class="form-label">' . $label . '</label>';
    echo '<input type="' . $type . '" class="' . $cls . '" value="' . htmlspecialchars((string)$value) . '" placeholder="' . $ph . '" readonly>';
    echo '</div>';
}

function show_textarea($label, $hint, $value) {
    $empty = is_empty_val($value);
    $cls = 'form-control' . ($empty ? ' is-empty' : '');
    echo '<div class="mb-3">';
    echo '<label class="form-label">' . $label . '</label>';
    if ($hint !== '') echo '<small class="text-muted d-block mb-1" style="font-size: 10.5px;">' . $hint . '</small>';
    echo '<textarea class="' . $cls . '" rows="2" placeholder="' . ($empty ? 'Not provided' : '') . '" readonly>' . htmlspecialchars((string)$value) . '</textarea>';
    echo '</div>';
}

$missingFields = $row ? subcontractorMissingProfileFields($row, $completionFields) : [];
$missing = array_column($missingFields, 'label');

$all_divisions = ["Solar", "MEP", "FIT", "HEL", "FSF", "UPS", "Transport", "EQT", "HR", "BT", "Supply Chain", "Compliance and ESG", "S & C", "NEX-GEN", "Marketing"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subcontractor Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; padding: 20px; }
    </style>
</head>
<body>

<!-- Everything inside .details-card is what the admin modal picks up (styles included) -->
<div class="details-card">
    <style>
        .details-card { font-family: 'Inter', sans-serif; font-size: 12.5px; color: #1e293b; }
        .details-card .reg-head { text-align: left; padding: 15px 18px; border: 1px solid #fed7aa; border-left: 4px solid #ea580c; border-radius: 10px; background: #fffaf5; margin-bottom: 14px; }
        .details-card .reg-head h2 { font-size: 18px; font-weight: 800; color: #7c2d12; margin-bottom: 4px; }
        .details-card .reg-head p { font-size: 11.5px; color: #64748b; line-height: 1.4; margin-bottom: 0; }
        .details-card .form-label { font-size: 11.5px; font-weight: 600; color: #334155; margin-bottom: 3px; }
        .details-card .form-control {
            font-size: 12px; border-radius: 5px; border: 1px solid #cbd5e1;
            background-color: #f8fafc; padding: 6px 12px; color: #0f172a;
        }
        .details-card input.form-control { height: 35px; }
        .details-card .form-control:focus { box-shadow: none; border-color: #cbd5e1; }
        .details-card .form-control.is-empty { border-color: #fca5a5; background-color: #fef2f2; }
        .details-card .form-control.is-empty::placeholder { color: #dc2626; opacity: .8; font-style: italic; }
        .details-card .mb-3 { margin-bottom: 10px !important; }
        .details-card .form-check-label { font-size: 11.5px; }
        .details-card .form-check-input:disabled { opacity: 1; }
        .details-card .form-check-input:disabled ~ .form-check-label { opacity: 1; color: #334155; }
        .details-card .status-chip {
            display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px;
            border-radius: 20px; font-size: 11px; font-weight: 700;
        }
        .details-card .chip-Approved { background: rgba(22,128,58,.12); color: #16803a; }
        .details-card .chip-Pending  { background: #fff0e4; color: #c2410c; }
        .details-card .chip-Rejected { background: rgba(217,72,60,.12); color: #d9483c; }
        .details-card .chip-Incomplete { background: #fff7ed; color: #c2410c; }
        .details-card .incomplete-banner {
            background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412;
            border-radius: 8px; padding: 8px 12px; font-size: 11.5px; margin-bottom: 12px;
        }
        .details-card .completion-link-panel { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:12px; margin-bottom:14px; border:1px solid #fed7aa; border-radius:8px; background:#fffaf5; }
        .details-card .completion-link-panel strong { display:block; color:#7c2d12; font-size:12px; }
        .details-card .completion-link-panel span { display:block; margin-top:2px; color:#667085; font-size:10.5px; }
        .details-card .completion-link-button { display:inline-flex; align-items:center; gap:6px; min-height:36px; padding:0 12px; border:0; border-radius:7px; background:#ea580c; color:#fff; font-size:11.5px; font-weight:700; }
        .details-card .completion-link-button:hover { background:#c2410c; }
        .details-card .section-box { background:#fff; }
        .details-card .form-control { border-radius: 7px; border-color: #e5e7eb; background: #fff; }
        .details-card input.form-control { height: 37px; }
        .details-card .form-control.is-empty { border-color: #fdba74; background: #fff7ed; }
        .details-card .quiz-review { margin: 20px 0 16px; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; background: #fff; }
        .details-card .quiz-review-head { padding: 13px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; background: #fff7ed; border-bottom: 1px solid #fed7aa; }
        .details-card .quiz-review-head h3 { margin: 0; color: #7c2d12; font-size: 14px; font-weight: 800; }
        .details-card .quiz-review-head p { margin: 3px 0 0; color: #9a3412; font-size: 11px; }
        .details-card .quiz-score { padding: 6px 10px; border-radius: 7px; background: #ea580c; color: #fff; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .details-card .quiz-answer-list { padding: 8px 14px; }
        .details-card .quiz-answer-row { padding: 11px 2px; border-bottom: 1px solid #f1f2f3; }
        .details-card .quiz-answer-row:last-child { border-bottom: 0; }
        .details-card .quiz-question { color: #1f2937; font-size: 12px; line-height: 1.6; font-weight: 700; }
        .details-card .quiz-answer { margin-top: 6px; color: #475569; font-size: 11.5px; line-height: 1.6; }
        .details-card .quiz-answer strong { color: #1f2937; }
        .details-card .answer-status { display: inline-block; margin-left: 6px; padding: 2px 7px; border-radius: 12px; font-size: 10px; font-weight: 700; }
        .details-card .answer-status.correct { background: #dcfce7; color: #166534; }
        .details-card .answer-status.incorrect { background: #fee2e2; color: #b91c1c; }
        .details-card .answer-status.missing { background: #f1f5f9; color: #64748b; }
        .details-card .quiz-empty { padding: 22px 16px; color: #64748b; font-size: 12px; text-align: center; }
    </style>

<?php if (!$row): ?>
    <div class="alert alert-danger">Subcontractor record not found.</div>
    <a href="subcontractors_section.php" class="btn btn-outline-secondary btn-sm">Back</a>
<?php else: ?>

    <div class="reg-head">
        <h2>External Service Provider Registration</h2>
        <p><strong>Document Number:</strong> HF/COM/ESPR/Rev0</p>
        <div class="mt-2 d-flex justify-content-center align-items-center gap-2 flex-wrap">
            <span class="status-chip chip-<?= htmlspecialchars($row['status'] ?? 'Pending'); ?>">
                <?= htmlspecialchars($row['status'] ?? 'Pending'); ?>
            </span>
            <?php if (($row['status'] ?? '') === 'Approved' && !empty($missing)): ?>
                <span class="status-chip chip-Incomplete"><i class="bi bi-exclamation-triangle-fill"></i> Not Completed</span>
            <?php endif; ?>
            <?php if (!empty($row['created_at'])): ?>
                <span class="text-muted" style="font-size: 11px;">Registered: <?= htmlspecialchars($row['created_at']); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($missing)): ?>
        <div class="incomplete-banner">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong><?= count($missing); ?> detail(s) not filled:</strong>
            <?= htmlspecialchars(implode(', ', $missing)); ?>
        </div>
        <div class="completion-link-panel">
            <div>
                <strong>Request missing details</strong>
                <span>Generate a secure, single-use link that expires in 14 days.</span>
            </div>
            <button type="button" class="completion-link-button" data-create-update-link data-id="<?= (int)$row['id']; ?>" data-csrf="<?= htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-email="<?= htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-company="<?= htmlspecialchars($row['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-link-45deg"></i> Create Completion Link
            </button>
        </div>
    <?php endif; ?>

    <?php
    show_input('Vendor Code (වෙන්ඩර් කේතය)', $row['vendor_code'] ?? '');
    show_input('1. Company Name (නාමය)', $row['company_name'] ?? '');
    show_input('2. Company Address (ලිපිනය)', $row['company_address'] ?? '');
    show_input('3. Business Registration Number (ලියාපදිංචි සහතික අංකය)', $row['business_reg_number'] ?? '');
    show_input('4. Business Registration Issued Date (ලියාපදිංචි සහතිකය නිකුත් කළ දිනය)', $row['business_reg_date'] ?? '');
    show_input('5. EPF Register number of the company (ආයතනයේ EPF ලියාපදිංචි අංකය)', $row['epf_number'] ?? '');
    show_input('6. ETF Register Number of the company (ආයතනයේ ETF ලියාපදිංචි අංකය)', $row['etf_number'] ?? '');
    show_input('7. Name of the company owner (හිමිකරුගේ නම)', $row['owner_name'] ?? '');
    show_input('8. Address of the company owner (හිමිකරුගේ ලිපිනය)', $row['owner_address'] ?? '');
    show_input('9. ID number company owner (හිමිකරුගේ ජා.හැ. අංකය)', $row['owner_id_number'] ?? '');
    show_input('10. Gender of the Company Owner (හිමිකරුගේ ස්ත්‍රී/ පුරුෂ භාවය)', $row['owner_gender'] ?? '');
    show_input('11. Date of Birth of the Company Owner (හිමිකරුගේ උපන් දිනය)', $row['owner_dob'] ?? '');
    show_input('12. Mobile Number (දුරකථන අංකය)', $row['mobile_number'] ?? '');
    show_input('13. E - mail address (විදුයුත් තැපැල් ලිපිනය)', $row['email'] ?? '');
    show_input('Username', $row['username'] ?? '');
    show_input('Account Password', !empty($row['password']) ? 'Password is set (hidden for security)' : 'Not set');
    show_input('14. Agreement effective date (ගිවිසුම් ගත දිනය)', $row['agreement_effective_date'] ?? '');
    show_input('15. Agreement termination date (ගිවිසුම අවසන් වන දිනය)', $row['agreement_termination_date'] ?? '');

    // 16. Division hired by (checkboxes, ticked ones are the saved ones)
    $saved_divs = array_filter(array_map('trim', explode(',', (string)($row['division'] ?? ''))), 'strlen');
    $extra_divs = array_diff($saved_divs, $all_divisions);
    ?>
    <div class="mb-3">
        <label class="form-label">16. Division hired by (බඳවා ගනු ලබන අංශය)</label>
        <div class="row ps-1 g-2">
            <?php foreach (array_merge($all_divisions, $extra_divs) as $d): ?>
                <div class="col-md-4 form-check">
                    <input class="form-check-input" type="checkbox" disabled <?= in_array($d, $saved_divs, true) ? 'checked' : ''; ?>>
                    <label class="form-check-label"><?= htmlspecialchars($d); ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (empty($saved_divs)): ?>
            <small class="text-danger fst-italic">Not provided</small>
        <?php endif; ?>
    </div>

    <?php
    show_input('17. Scope (කරනු ලබන කාර්යය)', $row['scope'] ?? '');
    show_textarea('18. Machineries available (ඔබ සතු යන්ත්‍ර - ඔබට තිබෙන ප්‍රමාණය සමඟ)', '', $row['machineries'] ?? '');
    show_textarea('19. Tools available (ඔබ සතු උපකරණ - ඔබට තිබෙන ප්‍රමාණය සමඟ)', '', $row['tools'] ?? '');
    show_input('20. Number of male workers (සේවකයන් සංඛ්‍යාව - පුරුෂ)', $row['male_workers'] ?? '');
    show_input('21. Number of female workers (සේවකයන් සංඛ්‍යාව - ස්ත්‍රී)', $row['female_workers'] ?? '');
    ?>

    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" disabled <?= !empty($row['agreement_accept']) ? 'checked' : ''; ?>>
        <label class="form-check-label">
            22. I have read and understand the guidelines given and agree (මාර්ගෝපදේශය කියවා අවබෝධ කර ගත් අතර ඒ සමඟ එකඟ වෙමි)<br>
            <strong>I accept (එකඟ වෙමි)</strong>
            <?php if (empty($row['agreement_accept'])): ?>
                <span class="text-danger fst-italic"> - Not accepted</span>
            <?php endif; ?>
        </label>
    </div>

    <section class="quiz-review" aria-labelledby="quizReviewTitle">
        <div class="quiz-review-head">
            <div>
                <h3 id="quizReviewTitle"><i class="bi bi-ui-checks me-1"></i> Tutorial Quiz Answers</h3>
                <?php if ($quizResult && !empty($quizResult['submitted_at'])): ?>
                    <p>Submitted <?= htmlspecialchars(date('d M Y, h:i A', strtotime($quizResult['submitted_at']))); ?></p>
                <?php else: ?>
                    <p>Review the answers submitted by this subcontractor.</p>
                <?php endif; ?>
            </div>
            <?php if ($quizResult): ?>
                <span class="quiz-score">
                    <?= (int)$quizResult['score']; ?> / <?= (int)$quizResult['total_questions']; ?>
                    (<?= number_format((float)$quizResult['percentage'], 1); ?>%)
                </span>
            <?php endif; ?>
        </div>

        <?php if (!$quizResult): ?>
            <div class="quiz-empty">No completed tutorial quiz answers are recorded for this subcontractor.</div>
        <?php else: ?>
            <div class="quiz-answer-list">
                <?php foreach ($tutorialQuestions as $questionIndex => $question): ?>
                    <?php
                    $selectedIndex = isset($quizAnswers[$questionIndex])
                        ? filter_var($quizAnswers[$questionIndex], FILTER_VALIDATE_INT)
                        : false;
                    $hasAnswer = $selectedIndex !== false && isset($question['o'][$selectedIndex]);
                    $isCorrect = $hasAnswer && $selectedIndex === (int)$question['a'];
                    $correctIndex = (int)$question['a'];
                    $optionPrefix = isset($question['stem']);
                    ?>
                    <article class="quiz-answer-row">
                        <div class="quiz-question">
                            <?= ($questionIndex + 1); ?>. <?= htmlspecialchars($question['q'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php if (!empty($question['stem'])): ?>
                            <div class="quiz-answer text-muted">
                                <?php foreach ($question['stem'] as $stemLine): ?>
                                    <?= htmlspecialchars($stemLine, ENT_QUOTES, 'UTF-8'); ?><br>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="quiz-answer">
                            <strong>Answer:</strong>
                            <?php if ($hasAnswer): ?>
                                <?= $optionPrefix ? ($selectedIndex + 1) . '. ' : chr(65 + $selectedIndex) . '. '; ?>
                                <?= htmlspecialchars($question['o'][$selectedIndex], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="answer-status <?= $isCorrect ? 'correct' : 'incorrect'; ?>">
                                    <?= $isCorrect ? 'Correct' : 'incorrect'; ?>
                                </span>
                            <?php else: ?>
                                <span class="answer-status missing">Not answered</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isCorrect): ?>
                            <div class="quiz-answer">
                                <strong>Correct answer:</strong>
                                <?= $optionPrefix ? ($correctIndex + 1) . '. ' : chr(65 + $correctIndex) . '. '; ?>
                                <?= htmlspecialchars($question['o'][$correctIndex], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <a href="subcontractors_section.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>

<?php endif; ?>
</div>

</body>
</html>