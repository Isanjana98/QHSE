<?php
session_start();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/worker_registration_schema.php';
$tutorialQuestions = require __DIR__ . '/tutorial_questions.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$validToken = (bool)preg_match('/^[a-f0-9]{64}$/', $token);
$invitation = null;
$subcontractor = null;
$videos = [];
$formError = '';
$submitted = false;
$connError = '';

if (!ensureWorkerRegistrationSchema($conn)) {
    $connError = 'Worker registration is temporarily unavailable.';
} elseif ($validToken) {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT l.id, l.subcontractor_id, s.company_name, s.mobile_number, s.phone
                              FROM worker_registration_links l
                              JOIN subcontractors s ON s.id = l.subcontractor_id
                             WHERE l.token_hash = ? AND l.used_at IS NULL AND l.expires_at > NOW()
                             LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $invitation = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    if ($invitation) {
        $subcontractor = $invitation;
        $_SESSION['worker_training_invitation_id'] = (int)$invitation['id'];
        if (empty($_SESSION['worker_registration_csrf'])) {
            $_SESSION['worker_registration_csrf'] = bin2hex(random_bytes(32));
        }
        $videoResult = $conn->query("SELECT id, title, description, src, duration
                                       FROM tutorial_videos
                                      WHERE is_active = 1 AND type = 'file'
                                      ORDER BY sort_order ASC, id ASC");
        while ($videoResult && $video = $videoResult->fetch_assoc()) {
            $videos[] = $video;
        }
    }
}

function workerRegistrationHealth(string $field, array $conditions): string
{
    $data = [];
    $allowed = ['Previously diagnosed', 'Currently treatment', 'Not infected'];
    foreach ($conditions as $index => $condition) {
        $value = (string)($_POST[$field . '_' . $index] ?? '');
        if (!in_array($value, $allowed, true)) {
            throw new RuntimeException('Please complete every health information question.');
        }
        $data[$condition] = $value;
    }
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function storeWorkerRegistrationPhoto(string $field): string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please upload both sides of your ID card.');
    }
    if ((int)$_FILES[$field]['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Each ID photo must be smaller than 8 MB.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($_FILES[$field]['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('ID photos must be JPG, PNG, or WebP images.');
    }

    $stored = s3_store_upload(
        $_FILES[$field]['tmp_name'],
        pathinfo($_FILES[$field]['name'], PATHINFO_FILENAME) . '.' . $extensions[$mimeType],
        'id',
        $field === 'id_front' ? 'front' : 'back'
    );
    if (!$stored) {
        throw new RuntimeException('Could not save an ID photo. Please try again.');
    }
    return $stored;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitation && $connError === '') {
    $transactionStarted = false;
    try {
        if (empty($_SESSION['worker_registration_csrf'])
            || !hash_equals($_SESSION['worker_registration_csrf'], (string)($_POST['csrf'] ?? ''))
        ) {
            throw new RuntimeException('Your form session expired. Reload the registration link and try again.');
        }
        if (empty($_POST['understood'])) {
            throw new RuntimeException('Please confirm that you watched and understood the safety training.');
        }
        if (!$videos) {
            throw new RuntimeException('The safety training videos are unavailable. Please contact your subcontractor.');
        }

        $watchedIds = json_decode((string)($_POST['videos_watched'] ?? ''), true);
        if (!is_array($watchedIds)) {
            throw new RuntimeException('Please watch every safety video to the end.');
        }
        $watchedIds = array_values(array_unique(array_map('intval', $watchedIds)));
        $requiredVideoIds = array_map('intval', array_column($videos, 'id'));
        if (array_diff($requiredVideoIds, $watchedIds)) {
            throw new RuntimeException('Please watch every safety video to the end before submitting.');
        }

        $submittedAnswers = $_POST['answers'] ?? null;
        if (!is_array($submittedAnswers) || count($submittedAnswers) !== count($tutorialQuestions)) {
            throw new RuntimeException('Please answer every safety quiz question.');
        }

        $answersForReview = [];
        $score = 0;
        foreach ($tutorialQuestions as $index => $question) {
            $selected = filter_var($submittedAnswers[$index] ?? null, FILTER_VALIDATE_INT);
            if ($selected === false || $selected < 0 || $selected >= count($question['o'])) {
                throw new RuntimeException('Please select an answer for every quiz question.');
            }
            $correctIndex = (int)$question['a'];
            $isCorrect = $selected === $correctIndex;
            if ($isCorrect) $score++;
            $answersForReview[] = [
                'question' => $question['q'],
                'selected_index' => $selected,
                'selected_answer' => $question['o'][$selected],
                'correct_index' => $correctIndex,
                'correct_answer' => $question['o'][$correctIndex],
                'is_correct' => $isCorrect,
            ];
        }

        $requiredFields = ['worker_name', 'address', 'nic_number', 'dob', 'mobile_number', 'gender', 'police_date', 'gs_date', 'blood_group', 'emergency_contact', 'sub_id_number'];
        $values = [];
        foreach ($requiredFields as $field) {
            $values[$field] = trim((string)($_POST[$field] ?? ''));
            if ($values[$field] === '') {
                throw new RuntimeException('Please fill all required worker details.');
            }
        }

        $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['dob']);
        if (!$birthDate || $birthDate->format('Y-m-d') !== $values['dob']) {
            throw new RuntimeException('Please enter a valid date of birth.');
        }
        $age = $birthDate->diff(new DateTimeImmutable('today'))->y;
        if ($age < 18 || $age > 60) {
            throw new RuntimeException('Worker must be between 18 and 60 years old.');
        }

        $divisionOptions = ['Solar', 'MEP', 'FIT', 'Equipment Trading (EQT)', 'FSF', 'UPS', 'HEL', 'Supply Chain', 'HR', 'S & C', 'Transport', 'BT', 'Marketing', 'Compliance', 'NEX-GEN', 'Other'];
        $scopeOptions = ['HVAC', 'Fire protection Systems', 'Plumbing service', 'Electrical works', 'Solar PV system services', 'Networking', 'Civil Engineering', 'Steel works', 'Scaffolding', 'Life Line', 'Transportation', 'Stores Management', 'Tea Making', 'ELV', 'Security', 'Camera System', 'Cleaning', 'Kitchen Services', 'Pest Control', 'Glass Cleaning', 'Telecommunication', 'Supervising', 'Other'];
        $divisionValues = array_values(array_intersect($divisionOptions, (array)($_POST['division_hired'] ?? [])));
        $scopeValues = array_values(array_intersect($scopeOptions, (array)($_POST['scope'] ?? [])));
        if (!$scopeValues) {
            throw new RuntimeException('Please choose at least one division or scope.');
        }

        $allergies = (string)($_POST['allergies'] ?? '');
        if (!in_array($allergies, ['Yes', 'No'], true)) {
            throw new RuntimeException('Please answer the allergies question.');
        }
        $allergyDetails = $allergies === 'Yes' ? trim((string)($_POST['allergy_details'] ?? '')) : 'None';
        if ($allergies === 'Yes' && $allergyDetails === '') {
            throw new RuntimeException('Please describe the allergy.');
        }

        $ncdConditions = ['Asthma (ඇදුම)', 'Shortness of breath (හුස්ම ගැනීමේ අපහසුතාව)', 'Lung problems (පෙනහළු ගැටලු)', 'Tuberculosis (ක්ෂය රෝගය)', 'Chest pain (පපුවේ වේදනාව)', 'Heart attack (හෘදයාබාධ)', 'Diabetic (දියවැඩියාව)', 'High blood pressure (අධි රුධිර පීඩනය)', 'Stroke (ආඝාතය)', 'Liver diseases (අක්මා රෝග)', 'Hepatitis (හෙපටයිටිස්)', 'Migraine (ඔළුවාරදය)', 'Regular fainting (නිතර ඇතිවන ක්ලාන්තය)'];
        $chronicConditions = ['Kidney diseases (වකුගඩු ආබාධ)', 'Thyroid disorders (තයිරොයිඩ් ගැටලු)', 'Skin Problem/rash (සමේ ගැටලු/ ආසාදන)', 'Hearing Loss or ear Problems (ශ්‍රවණ දුබලතා)', 'Eye Problems (අක්ෂි ගැටලු)', 'Hemophilia (හීමොෆීලියාව)', 'Anemia (රක්තහීනතාව)'];
        $mentalConditions = ['Acrophobia (fear of height)', 'Achluophobia (fear of darkness)', 'Ancraophobia (fear of wind)', 'Dystychiphobia (fear of accident)', 'Electrophobia (fear of electricity)', 'Ligrophobia (fear of loud noises)', 'Pyrophobia (fear of fire)', 'Phengophobia (fear of daylight)', 'Mechanophobia (fear of machine)', 'Climacophobia (fear of stairs)', 'Chemophobia (fear of chemical)', 'Bathophobia (fear of depth)'];
        $ncdDetails = workerRegistrationHealth('ncd', $ncdConditions);
        $chronicDetails = workerRegistrationHealth('chronic', $chronicConditions);
        $mentalDetails = workerRegistrationHealth('mental', $mentalConditions);

        $workerName = $values['worker_name'];
        $address = $values['address'];
        $nicNumber = $values['nic_number'];
        $dob = $values['dob'];
        $mobileNumber = $values['mobile_number'];
        $gender = $values['gender'];
        $email = trim((string)($_POST['email'] ?? ''));
        $policeDate = $values['police_date'];
        $gsDate = $values['gs_date'];
        $bloodGroup = $values['blood_group'];
        $emergencyContact = $values['emergency_contact'];
        $subIdNumber = $values['sub_id_number'];
        $companyName = (string)$subcontractor['company_name'];
        $companyContact = trim((string)($subcontractor['mobile_number'] ?? $subcontractor['phone'] ?? ''));
        $divisionHired = implode(', ', $divisionValues);
        $scope = implode(', ', $scopeValues);
        $designation = trim((string)($_POST['designation'] ?? ''));
        $workExperience = trim((string)($_POST['work_experience'] ?? ''));
        $education = trim((string)($_POST['edu_qualifications'] ?? ''));
        $vocational = trim((string)($_POST['voc_qualifications'] ?? ''));
        $idFrontPath = storeWorkerRegistrationPhoto('id_front');
        $idBackPath = storeWorkerRegistrationPhoto('id_back');
        $answersJson = json_encode($answersForReview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $watchedVideoDetails = [];
        foreach ($videos as $video) {
            if (in_array((int)$video['id'], $requiredVideoIds, true)) {
                $watchedVideoDetails[] = ['id' => (int)$video['id'], 'title' => (string)$video['title']];
            }
        }
        $videosJson = json_encode($watchedVideoDetails, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $subcontractorId = (int)$invitation['subcontractor_id'];
        $invitationId = (int)$invitation['id'];
        $totalQuestions = count($tutorialQuestions);

        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start registration submission.');
        }
        $transactionStarted = true;
        $lock = $conn->prepare('SELECT id FROM worker_registration_links WHERE id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
        if (!$lock) throw new RuntimeException('Could not validate the registration link.');
        $lock->bind_param('is', $invitationId, $tokenHash);
        $lock->execute();
        $linkStillValid = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$linkStillValid) throw new RuntimeException('This registration link has expired or has already been used.');

        $insertWorker = $conn->prepare("INSERT INTO workers
            (subcontractor_id, worker_name, address, nic_number, dob, mobile_number, gender, email, police_date, gs_date, blood_group, emergency_contact, sub_id_number, company_name, company_contact, division_hired, scope, designation, work_experience, edu_qualifications, voc_qualifications, allergies, allergy_details, ncd_details, chronic_details, mental_details, id_front_path, id_back_path, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
        if (!$insertWorker) throw new RuntimeException('Could not prepare worker registration.');
        $insertWorker->bind_param('i' . str_repeat('s', 27), $subcontractorId, $workerName, $address, $nicNumber, $dob, $mobileNumber, $gender, $email, $policeDate, $gsDate, $bloodGroup, $emergencyContact, $subIdNumber, $companyName, $companyContact, $divisionHired, $scope, $designation, $workExperience, $education, $vocational, $allergies, $allergyDetails, $ncdDetails, $chronicDetails, $mentalDetails, $idFrontPath, $idBackPath);
        if (!$insertWorker->execute()) throw new RuntimeException('Could not save worker details.');
        $workerId = (int)$conn->insert_id;
        $insertWorker->close();

        $trainingInsert = $conn->prepare('INSERT INTO worker_training_results (worker_id, subcontractor_id, invitation_id, answers, videos_watched, score, total_questions, acknowledged_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        if (!$trainingInsert) throw new RuntimeException('Could not prepare safety training results.');
        $trainingInsert->bind_param('iiissii', $workerId, $subcontractorId, $invitationId, $answersJson, $videosJson, $score, $totalQuestions);
        if (!$trainingInsert->execute()) throw new RuntimeException('Could not save safety training results.');
        $trainingInsert->close();

        $markUsed = $conn->prepare('UPDATE worker_registration_links SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        if (!$markUsed) throw new RuntimeException('Could not complete registration link.');
        $markUsed->bind_param('i', $invitationId);
        $markUsed->execute();
        $linkUsed = $markUsed->affected_rows === 1;
        $markUsed->close();
        if (!$linkUsed) throw new RuntimeException('This registration link has already been used.');

        $conn->commit();
        $transactionStarted = false;
        $submitted = true;
        unset($_SESSION['worker_training_invitation_id']);
        unset($_SESSION['worker_registration_csrf']);
    } catch (Throwable $error) {
        if ($transactionStarted) $conn->rollback();
        error_log('worker_registration.php: ' . $error->getMessage());
        $formError = $error->getMessage();
    }
}

if (!$submitted && !$invitation && $connError === '') {
    $connError = 'This worker registration link is invalid, expired, or already used.';
}

if ($conn instanceof mysqli) $conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Induction | Hayleys Fentons</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&family=Noto+Sans+Sinhala:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --orange:#e15e13; --ink:#17211a; --muted:#667085; --line:#e5e7eb; --green:#15803d; }
        * { box-sizing:border-box; }
        body { margin:0; padding:24px 14px 50px; background:#f4f6f9; color:var(--ink); font:14px 'Inter','Noto Sans Sinhala',sans-serif; }
        .page { width:min(100%,1000px); margin:0 auto; }
        .brand-head,.form-section,.training-section { margin-bottom:16px; padding:20px; border:1px solid var(--line); border-radius:10px; background:#fff; box-shadow:0 4px 12px rgba(15,23,42,.04); }
        .brand-head { text-align:center; }
        .brand-head img { width:140px; height:44px; object-fit:contain; }
        .brand-head h1 { margin:8px 0 4px; font:800 21px 'Manrope',sans-serif; }
        .brand-head p,.muted { color:var(--muted); font-size:12px; }
        .section-title { display:flex; align-items:center; gap:8px; margin:0 0 15px; font:700 15px 'Manrope',sans-serif; }
        .section-title i { color:var(--orange); }
        .company-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px; }
        .company-grid label,.form-label { color:#475467; font-size:12px; font-weight:600; }
        .company-grid input { background:#f8fafc; font-weight:700; }
        .health-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:10px; }
        .health-item { padding:12px; border:1px solid var(--line); border-radius:8px; }
        .health-item-title { margin-bottom:8px; font-size:12px; font-weight:600; }
        .health-choices { display:flex; flex-wrap:wrap; gap:8px 14px; }
        .health-choices label { font-size:11px; }
        .video-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; }
        .video-card { position:relative; display:flex; flex-direction:column; padding:0; overflow:hidden; border:1px solid var(--line); border-radius:8px; background:#fff; text-align:left; }
        .video-thumb { height:124px; display:grid; place-items:center; background:#17211a; color:#fff; font-size:28px; }
        .video-card.watched { border-color:#22c55e; }
        .video-card.watched .video-thumb { background:#166534; }
        .video-copy { padding:10px; }
        .video-copy strong { display:block; font-size:12px; }
        .video-copy small { color:var(--muted); font-size:10px; }
        .video-state { position:absolute; top:8px; right:8px; padding:4px 7px; border-radius:20px; background:#fff; color:#475467; font-size:10px; font-weight:700; }
        .video-card.watched .video-state { color:#166534; }
        .question-card { margin-bottom:12px; padding:14px; border:1px solid var(--line); border-radius:8px; background:#fff; }
        .question-card h3 { margin:0 0 10px; font-size:13px; line-height:1.6; }
        .answer-option { display:flex; align-items:flex-start; gap:8px; padding:8px; border-radius:6px; font-size:12px; cursor:pointer; }
        .answer-option:hover { background:#fff7ed; }
        .video-modal { position:fixed; inset:0; z-index:1055; display:none; align-items:center; justify-content:center; padding:16px; background:rgba(15,23,42,.78); }
        .video-modal.show { display:flex; }
        .video-modal-box { width:min(900px,100%); padding:14px; border-radius:10px; background:#fff; }
        .video-modal-top { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px; }
        .video-modal video { width:100%; max-height:70vh; background:#000; }
        .submit-row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
        .error-banner { padding:12px 14px; border-radius:8px; background:#fef2f2; color:#b91c1c; font-size:12px; }
        @media(max-width:600px) { body { padding:12px 8px 30px; } .brand-head,.form-section,.training-section { padding:14px; } .company-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<main class="page">
<?php if ($submitted): ?>
    <section class="brand-head mt-5">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons">
        <h1>Worker Registration Submitted</h1>
        <p>Your induction form and safety quiz have been sent to the administrator for review.</p>
        <span class="badge text-bg-warning">Pending admin approval</span>
    </section>
<?php elseif ($connError !== ''): ?>
    <section class="brand-head mt-5">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons">
        <h1>Worker Registration</h1>
        <div class="alert alert-danger mt-3 mb-0"><?= htmlspecialchars($connError, ENT_QUOTES, 'UTF-8'); ?></div>
    </section>
<?php else: ?>
    <header class="brand-head">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons">
        <h1>Worker Induction</h1>
        <p>Complete your worker details, watch each safety video, and answer the quiz for admin approval.</p>
    </header>
    <?php if ($formError !== ''): ?><div class="error-banner mb-3" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="workerRegistrationForm">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['worker_registration_csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="videos_watched" id="videosWatchedInput" value="[]">

        <section class="form-section">
            <h2 class="section-title"><i class="bi bi-person-vcard"></i> Personal &amp; Company Details</h2>
            <div class="company-grid">
                <div><label for="companyName" class="form-label">Company</label><input id="companyName" class="form-control" value="<?= htmlspecialchars($subcontractor['company_name'], ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
                <div><label for="companyContact" class="form-label">Subcontractor Contact</label><input id="companyContact" class="form-control" value="<?= htmlspecialchars($subcontractor['mobile_number'] ?: ($subcontractor['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
            </div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Full Name *</label><input class="form-control" name="worker_name" required value="<?= htmlspecialchars($_POST['worker_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Address *</label><input class="form-control" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">NIC Number *</label><input class="form-control" name="nic_number" required value="<?= htmlspecialchars($_POST['nic_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Date of Birth (18-60) *</label><input class="form-control" type="date" name="dob" required value="<?= htmlspecialchars($_POST['dob'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">ID Card Front Photo *</label><input class="form-control" type="file" name="id_front" accept="image/jpeg,image/png,image/webp" required></div>
                <div class="col-md-6"><label class="form-label">ID Card Back Photo *</label><input class="form-control" type="file" name="id_back" accept="image/jpeg,image/png,image/webp" required></div>
                <div class="col-md-6"><label class="form-label">Mobile Number *</label><input class="form-control" type="tel" name="mobile_number" required value="<?= htmlspecialchars($_POST['mobile_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Gender *</label><select class="form-select" name="gender" required><option value="">Select</option><?php foreach (['Male','Female','Other'] as $option): ?><option value="<?= $option; ?>" <?= ($_POST['gender'] ?? '') === $option ? 'selected' : ''; ?>><?= $option; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Police Report Issue Date *</label><input class="form-control" type="date" name="police_date" required value="<?= htmlspecialchars($_POST['police_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">GS Report Issue Date *</label><input class="form-control" type="date" name="gs_date" required value="<?= htmlspecialchars($_POST['gs_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Blood Group *</label><select class="form-select" name="blood_group" required><option value="">Select</option><?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-','Don\'t Know'] as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>" <?= ($_POST['blood_group'] ?? '') === $option ? 'selected' : ''; ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Emergency Contact *</label><input class="form-control" name="emergency_contact" required value="<?= htmlspecialchars($_POST['emergency_contact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Subcontractor ID Number *</label><input class="form-control" name="sub_id_number" required value="<?= htmlspecialchars($_POST['sub_id_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Allergies *</label><select class="form-select" name="allergies" id="allergiesSelect" required><option value="">Select</option><option value="Yes">Yes</option><option value="No">No</option></select></div>
                <div class="col-12" id="allergyDetailsWrap" hidden><label class="form-label">Describe allergies</label><textarea class="form-control" name="allergy_details" rows="2"></textarea></div>
                <div class="col-md-6"><label class="form-label">Designation</label><input class="form-control" name="designation" value="<?= htmlspecialchars($_POST['designation'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Work Experience</label><input class="form-control" name="work_experience" value="<?= htmlspecialchars($_POST['work_experience'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Educational Qualifications</label><input class="form-control" name="edu_qualifications" value="<?= htmlspecialchars($_POST['edu_qualifications'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Vocational Qualifications</label><input class="form-control" name="voc_qualifications" value="<?= htmlspecialchars($_POST['voc_qualifications'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-md-6"><label class="form-label">Division Hired By</label><select class="form-select" name="division_hired[]" multiple size="4"><?php foreach (['Solar','MEP','FIT','Equipment Trading (EQT)','FSF','UPS','HEL','Supply Chain','HR','S & C','Transport','BT','Marketing','Compliance','NEX-GEN','Other'] as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Division / Scope *</label><select class="form-select" name="scope[]" multiple size="6" required><?php foreach (['HVAC','Fire protection Systems','Plumbing service','Electrical works','Solar PV system services','Networking','Civil Engineering','Steel works','Scaffolding','Life Line','Transportation','Stores Management','Tea Making','ELV','Security','Camera System','Cleaning','Kitchen Services','Pest Control','Glass Cleaning','Telecommunication','Supervising','Other'] as $option): ?><option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><small class="muted">Select one or more.</small></div>
            </div>
        </section>

        <section class="form-section">
            <h2 class="section-title"><i class="bi bi-heart-pulse"></i> Health Information</h2>
            <?php
            $healthGroups = [
                'ncd' => ['Non-Communicable Diseases', ['Asthma (ඇදුම)', 'Shortness of breath (හුස්ම ගැනීමේ අපහසුතාව)', 'Lung problems (පෙනහළු ගැටලු)', 'Tuberculosis (ක්ෂය රෝගය)', 'Chest pain (පපුවේ වේදනාව)', 'Heart attack (හෘදයාබාධ)', 'Diabetic (දියවැඩියාව)', 'High blood pressure (අධි රුධිර පීඩනය)', 'Stroke (ආඝාතය)', 'Liver diseases (අක්මා රෝග)', 'Hepatitis (හෙපටයිටිස්)', 'Migraine (ඔළුවාරදය)', 'Regular fainting (නිතර ඇතිවන ක්ලාන්තය)']],
                'chronic' => ['Chronic Diseases', ['Kidney diseases (වකුගాడු ආබාධ)', 'Thyroid disorders (තයිරොයිඩ් ගැටලු)', 'Skin Problem/rash (සමේ ගැටලු/ ආසාදන)', 'Hearing Loss or ear Problems (ශ්‍රවණ දුබලතා)', 'Eye Problems (අක්ෂි ගැටලු)', 'Hemophilia (හීමොෆීලියාව)', 'Anemia (රක්තහීනතාව)']],
                'mental' => ['Mental Health Conditions', ['Acrophobia (fear of height)', 'Achluophobia (fear of darkness)', 'Ancraophobia (fear of wind)', 'Dystychiphobia (fear of accident)', 'Electrophobia (fear of electricity)', 'Ligrophobia (fear of loud noises)', 'Pyrophobia (fear of fire)', 'Phengophobia (fear of daylight)', 'Mechanophobia (fear of machine)', 'Climacophobia (fear of stairs)', 'Chemophobia (fear of chemical)', 'Bathophobia (fear of depth)']],
            ];
            $healthChoices = ['Previously diagnosed', 'Currently treatment', 'Not infected'];
            ?>
            <div class="health-grid">
                <?php foreach ($healthGroups as $prefix => [$groupTitle, $conditions]): ?>
                    <div class="health-item"><div class="fw-bold small mb-2"><?= htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php foreach ($conditions as $index => $condition): ?>
                            <div class="health-item-title"><?= htmlspecialchars($condition, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="health-choices mb-2"><?php foreach ($healthChoices as $choiceIndex => $choice): ?><label><input type="radio" name="<?= $prefix . '_' . $index; ?>" value="<?= htmlspecialchars($choice, ENT_QUOTES, 'UTF-8'); ?>" required> <?= ['Previously','Treatment','No'][$choiceIndex]; ?></label><?php endforeach; ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="training-section">
            <h2 class="section-title"><i class="bi bi-play-circle"></i> Safety Training Videos</h2>
            <p class="muted">Watch every video to the end. The quiz unlocks after all videos are completed.</p>
            <?php if (!$videos): ?>
                <div class="alert alert-warning mb-0">Training videos are currently unavailable. Please ask your subcontractor to try again later.</div>
            <?php else: ?>
                <div class="progress mb-3" role="progressbar" aria-label="Videos watched"><div class="progress-bar bg-success" id="videoProgressBar" style="width:0%"></div></div>
                <p class="muted" id="videoProgressText">0 / <?= count($videos); ?> videos watched</p>
                <div class="video-grid">
                    <?php foreach ($videos as $video): ?>
                        <button type="button" class="video-card" data-video-id="<?= (int)$video['id']; ?>" data-video-url="<?= htmlspecialchars(media_url($video['src']), ENT_QUOTES, 'UTF-8'); ?>" data-video-title="<?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="video-state">Not watched</span><span class="video-thumb"><i class="bi bi-play-circle-fill"></i></span>
                            <span class="video-copy"><strong><?= htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?= htmlspecialchars($video['duration'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small><small class="d-block"><?= htmlspecialchars($video['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="training-section" id="quizSection" hidden>
            <h2 class="section-title"><i class="bi bi-ui-checks"></i> Safety Quiz</h2>
            <p class="muted">Answer every question. The administrator will review your answers.</p>
            <?php foreach ($tutorialQuestions as $index => $question): ?>
                <fieldset class="question-card"><h3><?= ($index + 1) . '. ' . htmlspecialchars($question['q'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <?php if (!empty($question['stem'])): ?><div class="small mb-2"><?php foreach ($question['stem'] as $stem): ?><?= htmlspecialchars($stem, ENT_QUOTES, 'UTF-8'); ?><br><?php endforeach; ?></div><?php endif; ?>
                    <?php foreach ($question['o'] as $optionIndex => $option): ?>
                        <label class="answer-option"><input type="radio" name="answers[<?= (int)$index; ?>]" value="<?= (int)$optionIndex; ?>" required><span><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
        </section>

        <section class="training-section">
            <label class="d-flex align-items-start gap-2 fw-semibold">
                <input type="checkbox" name="understood" value="1" id="understoodCheck" required>
                <span>I confirm that I watched all the safety videos, understood the information, and answered the quiz myself.</span>
            </label>
            <p class="muted mt-2 mb-3">Submitting sends your worker details and training answers to the administrator for approval.</p>
            <div class="submit-row"><span class="muted" id="submitRequirements">Watch all videos, answer every question, and confirm understanding.</span><button class="btn btn-warning text-white fw-bold" type="submit" id="submitWorkerButton" disabled><i class="bi bi-send me-1"></i> Submit for Approval</button></div>
        </section>
    </form>
    <div class="video-modal" id="videoModal" aria-hidden="true"><div class="video-modal-box"><div class="video-modal-top"><strong id="activeVideoTitle"></strong><button type="button" class="btn-close" id="closeVideoModal" aria-label="Close video"></button></div><video id="activeVideo" controls controlsList="nodownload" playsinline></video><small class="muted d-block mt-2">The video is marked watched when it finishes. Seeking ahead is disabled.</small></div></div>
<?php endif; ?>
</main>
<?php if (!$submitted && $connError === '' && $invitation && $videos): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cards = Array.from(document.querySelectorAll('.video-card'));
    const watched = new Set();
    const videoModal = document.getElementById('videoModal');
    const activeVideo = document.getElementById('activeVideo');
    const watchedInput = document.getElementById('videosWatchedInput');
    const quizSection = document.getElementById('quizSection');
    const submitButton = document.getElementById('submitWorkerButton');
    const understood = document.getElementById('understoodCheck');
    const form = document.getElementById('workerRegistrationForm');
    let activeCard = null;
    let maxTime = 0;

    function updateCompletion() {
        cards.forEach(function (card) {
            const done = watched.has(Number(card.dataset.videoId));
            card.classList.toggle('watched', done);
            card.querySelector('.video-state').textContent = done ? 'Watched' : 'Not watched';
        });
        const percent = cards.length ? watched.size / cards.length * 100 : 0;
        document.getElementById('videoProgressBar').style.width = percent + '%';
        document.getElementById('videoProgressText').textContent = watched.size + ' / ' + cards.length + ' videos watched';
        watchedInput.value = JSON.stringify(Array.from(watched));
        quizSection.hidden = watched.size !== cards.length;
        updateSubmitState();
    }

    function updateSubmitState() {
        const questions = Array.from(document.querySelectorAll('#quizSection fieldset'));
        const answered = questions.every(function (question) { return question.querySelector('input:checked'); });
        submitButton.disabled = watched.size !== cards.length || !answered || !understood.checked;
        if (!submitButton.disabled) document.getElementById('submitRequirements').textContent = 'Training complete. Your submission will be reviewed by an administrator.';
    }

    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            activeCard = card;
            maxTime = 0;
            document.getElementById('activeVideoTitle').textContent = card.dataset.videoTitle;
            activeVideo.src = card.dataset.videoUrl;
            videoModal.classList.add('show');
            videoModal.setAttribute('aria-hidden', 'false');
            activeVideo.play().catch(function () {});
        });
    });

    activeVideo.addEventListener('timeupdate', function () {
        if (!activeVideo.seeking && activeVideo.currentTime > maxTime) maxTime = activeVideo.currentTime;
    });
    activeVideo.addEventListener('seeking', function () {
        if (activeVideo.currentTime > maxTime + 1) activeVideo.currentTime = maxTime;
    });
    activeVideo.addEventListener('ended', function () {
        if (activeCard) watched.add(Number(activeCard.dataset.videoId));
        updateCompletion();
    });

    function closeVideo() {
        activeVideo.pause();
        activeVideo.removeAttribute('src');
        activeVideo.load();
        videoModal.classList.remove('show');
        videoModal.setAttribute('aria-hidden', 'true');
        activeCard = null;
    }
    document.getElementById('closeVideoModal').addEventListener('click', closeVideo);
    videoModal.addEventListener('click', function (event) { if (event.target === videoModal) closeVideo(); });
    document.querySelectorAll('#quizSection input[type=radio]').forEach(function (input) { input.addEventListener('change', updateSubmitState); });
    understood.addEventListener('change', updateSubmitState);

    const allergies = document.getElementById('allergiesSelect');
    allergies.addEventListener('change', function () {
        const wrap = document.getElementById('allergyDetailsWrap');
        const input = wrap.querySelector('textarea');
        wrap.hidden = allergies.value !== 'Yes';
        input.required = allergies.value === 'Yes';
        if (!input.required) input.value = '';
    });

    form.addEventListener('submit', function (event) {
        if (submitButton.disabled) {
            event.preventDefault();
            alert('Please complete all training videos, quiz questions, and the understanding confirmation.');
        }
    });

    updateCompletion();
});
</script>
<?php endif; ?>
</body>
</html>