<?php
// Session eka kisima time limit ekak nathuwa (browser eka close karana thuru) inna denawa
ini_set('session.gc_maxlifetime', 86400);          // 24 hours
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

require_once __DIR__ . '/s3_helper.php';
$tutorialQuestions = require __DIR__ . '/tutorial_questions.php';

// Keep-alive ping (page eka open wela inna kalaye session eka alive thiyanawa)
if (($_GET['action'] ?? '') === 'ping') {
    header('Content-Type: text/plain');
    echo 'ok';
    exit();
}

// Register wela nathnam register page ekata yawanna
$pending_registration = $_SESSION['pending_subcontractor_registration'] ?? null;
$is_pending_registration = !empty($_SESSION['registration_pending_tutorial']) && is_array($pending_registration);
if (!isset($_SESSION['registered_company']) && !$is_pending_registration) {
    header("Location: register.php");
    exit();
}
$company = $is_pending_registration
    ? ($pending_registration['company_name'] ?? '')
    : $_SESSION['registered_company'];

// Subcontractor ID eka hoyaganeema
$sub_id_found = null;
if (!$is_pending_registration) {
    foreach (['registered_id', 'registered_sub_id', 'subcontractor_id', 'sub_id', 'id'] as $k) {
        if (!empty($_SESSION[$k])) { $sub_id_found = (int)$_SESSION[$k]; break; }
    }
}

// Database Connection function
function getDBConnection() {
    require __DIR__ . '/db_config.php';
    return $conn;
}

// ===== 1. Video Watch Progress & Resume Time DB ekata save kirima (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save_video_progress') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$sub_id_found && !$is_pending_registration) {
        echo json_encode(['ok' => false, 'error' => 'Subcontractor ID not found']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $watched_indices = $input['watched'] ?? [];
    $video_progress  = $input['progress'] ?? []; 

    $_SESSION['watched_videos'] = $watched_indices;
    $_SESSION['video_progress'] = $video_progress;

    try {
        if ($sub_id_found) {
            $conn = getDBConnection();
            $watched_json = json_encode($watched_indices);
            $progress_json = json_encode($video_progress);

            $stmt = $conn->prepare("UPDATE quiz_results SET videos_watched = ?, video_progress = ? WHERE subcontractor_id = ?");
            $stmt->bind_param("ssi", $watched_json, $progress_json, $sub_id_found);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $stmt_ins = $conn->prepare("INSERT INTO quiz_results (subcontractor_id, score, total_questions, percentage, answers, videos_watched, video_progress) VALUES (?, 0, 0, 0, '[]', ?, ?)");
                $stmt_ins->bind_param("iss", $sub_id_found, $watched_json, $progress_json);
                $stmt_ins->execute();
                $stmt_ins->close();
            }
            $stmt->close();
            $conn->close();
        }

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// Database eken dath laba ganeema
$db_watched_videos = [];
$db_video_progress = [];
if ($sub_id_found) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT videos_watched, video_progress FROM quiz_results WHERE subcontractor_id = ? LIMIT 1");
        $stmt->bind_param("i", $sub_id_found);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            if (!empty($row['videos_watched'])) {
                $decoded = json_decode($row['videos_watched'], true);
                if (is_array($decoded)) $db_watched_videos = $decoded;
            }
            if (!empty($row['video_progress'])) {
                $decoded_prog = json_decode($row['video_progress'], true);
                if (is_array($decoded_prog)) $db_video_progress = $decoded_prog;
            }
        }
        $stmt->close();
        $conn->close();
    } catch (Throwable $e) {}
}

$initial_watched = !empty($_SESSION['watched_videos']) ? $_SESSION['watched_videos'] : $db_watched_videos;
$initial_progress = !empty($_SESSION['video_progress']) ? $_SESSION['video_progress'] : $db_video_progress;


// ===== 2. Quiz result eka DB ekata save karanawa (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save_quiz') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$sub_id_found && !$is_pending_registration) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => "Registration session not found."]);
        exit();
    }
    if (!empty($_SESSION['quiz_saved'])) {            
        echo json_encode(['ok' => true, 'already' => true]);
        exit();
    }

    $answer_key = array_column($tutorialQuestions, 'a');

    $input   = json_decode(file_get_contents('php://input'), true);
    $answers = $input['answers'] ?? null;
    if (!is_array($answers) || count($answers) !== count($answer_key)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid answers.']);
        exit();
    }

    $answers = array_values($answers);
    foreach ($answers as $answer) {
        $answerValue = filter_var($answer, FILTER_VALIDATE_INT);
        if ($answerValue === false || $answerValue < 0 || $answerValue > 3) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid quiz answer.']);
            exit();
        }
    }
    $answers = array_map('intval', $answers);
    $score = 0;
    foreach ($answer_key as $i => $correct) {
        if ($answers[$i] === $correct) $score++;
    }
    $total        = count($answer_key);
    $percentage   = round($score / $total * 100, 2);
    $answers_json = json_encode($answers);
    $conn = null;
    try {
        $conn = getDBConnection();

        if ($is_pending_registration) {
            $registration = $_SESSION['pending_subcontractor_registration'];
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('Could not begin registration submission.', $conn->errno);
            }

            $sql = "INSERT INTO subcontractors(
                vendor_code, company_name, company_address, business_reg_number, business_reg_date,
                epf_number, etf_number, owner_name, owner_address, owner_id_number,
                owner_gender, owner_dob, mobile_number, email, username, password,
                agreement_effective_date, agreement_termination_date, division, scope,
                machineries, tools, male_workers, female_workers, agreement_accept, status
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending')";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Could not prepare registration submission.', $conn->errno);
            }

            $vendor_code = $registration['vendor_code'];
            $company_name = $registration['company_name'];
            $company_address = $registration['company_address'];
            $business_reg_number = $registration['business_reg_number'];
            $business_reg_date = $registration['business_reg_date'];
            $epf_number = $registration['epf_number'];
            $etf_number = $registration['etf_number'];
            $owner_name = $registration['owner_name'];
            $owner_address = $registration['owner_address'];
            $owner_id_number = $registration['owner_id_number'];
            $owner_gender = $registration['owner_gender'];
            $owner_dob = $registration['owner_dob'];
            $mobile_number = $registration['mobile_number'];
            $email = $registration['email'];
            $username = $registration['username'];
            $password_hash = $registration['password_hash'];
            $agreement_effective_date = $registration['agreement_effective_date'];
            $agreement_termination_date = $registration['agreement_termination_date'];
            $division = $registration['division'];
            $scope = $registration['scope'];
            $machineries = $registration['machineries'];
            $tools = $registration['tools'];
            $male_workers = (int)$registration['male_workers'];
            $female_workers = (int)$registration['female_workers'];
            $agreement_accept = (int)$registration['agreement_accept'];

            $stmt->bind_param(
                str_repeat('s', 22) . 'iii',
                $vendor_code, $company_name, $company_address, $business_reg_number, $business_reg_date,
                $epf_number, $etf_number, $owner_name, $owner_address, $owner_id_number,
                $owner_gender, $owner_dob, $mobile_number, $email, $username, $password_hash,
                $agreement_effective_date, $agreement_termination_date, $division, $scope,
                $machineries, $tools, $male_workers, $female_workers, $agreement_accept
            );
            if (!$stmt->execute()) {
                $errorCode = $stmt->errno;
                $stmt->close();
                throw new RuntimeException('Could not save registration.', $errorCode);
            }
            $sub_id = (int)$conn->insert_id;
            $stmt->close();

            $watched_json = json_encode($_SESSION['watched_videos'] ?? []);
            $progress_json = json_encode($_SESSION['video_progress'] ?? new stdClass());
            $stmt = $conn->prepare("INSERT INTO quiz_results (subcontractor_id, score, total_questions, percentage, answers, videos_watched, video_progress) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare quiz result submission.', $conn->errno);
            }
            $stmt->bind_param("iiidsss", $sub_id, $score, $total, $percentage, $answers_json, $watched_json, $progress_json);
            if (!$stmt->execute()) {
                $errorCode = $stmt->errno;
                $stmt->close();
                throw new RuntimeException('Could not save quiz results.', $errorCode);
            }
            $stmt->close();
            $conn->commit();
            $_SESSION['registered_id'] = $sub_id;
            unset($_SESSION['pending_subcontractor_registration'], $_SESSION['registration_pending_tutorial']);
        } else {
            $sub_id = $sub_id_found;
            $stmt = $conn->prepare("UPDATE quiz_results SET score = ?, total_questions = ?, percentage = ?, answers = ? WHERE subcontractor_id = ?");
            $stmt->bind_param("iiids", $score, $total, $percentage, $answers_json, $sub_id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $stmt_ins = $conn->prepare("INSERT INTO quiz_results (subcontractor_id, score, total_questions, percentage, answers, videos_watched) VALUES (?, ?, ?, ?, ?, '1')");
                $stmt_ins->bind_param("iiids", $sub_id, $score, $total, $percentage, $answers_json);
                $stmt_ins->execute();
                $stmt_ins->close();
            }
            $stmt->close();
        }
        $conn->close();
        $_SESSION['quiz_saved']   = true;
        $_SESSION['quiz_answers'] = $answers;
        echo json_encode(['ok' => true, 'score' => $score, 'total' => $total]);
    } catch (Throwable $e) {
        if ($conn instanceof mysqli) {
            $conn->rollback();
            $conn->close();
        }
        error_log('tutorial.php: save_quiz failed - ' . $e->getMessage());
        $duplicate = (int)$e->getCode() === 1062;
        http_response_code($duplicate ? 409 : 500);
        echo json_encode(['ok' => false, 'error' => $duplicate
            ? 'This username or registration already exists. Please return to the registration form and check your details.'
            : 'Could not submit the registration. Please try again.']);
    }
    exit();
}

$locked_answers = !empty($_SESSION['quiz_saved']) ? ($_SESSION['quiz_answers'] ?? null) : null;

/* Tutorial videos are managed in admin_tutorials.php and stored in the
   tutorial_videos table. Only file-type videos appear here: this page
   gates the quiz on watch progress, which a YouTube embed cannot report. */
$videos = [];
try {
    $conn_vid = getDBConnection();
    $vres = $conn_vid->query("
        SELECT id, title, description, src, duration
          FROM tutorial_videos
         WHERE is_active = 1 AND type = 'file'
         ORDER BY sort_order ASC, id ASC
    ");
    while ($vres && $vrow = $vres->fetch_assoc()) {
        $videos[] = $vrow;
    }
    $conn_vid->close();
} catch (Throwable $e) {
    error_log('tutorial.php: could not load tutorial_videos - ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Safety Tutorial & Quiz | Hayleys Fentons</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Noto+Sans+Sinhala:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--orange:#e15e13;--orange-dark:#bb4c14;--bg:#f4f6f9;--text:#0f172a;--muted:#64748b;--ok:#16a34a;--bad:#dc2626;}
body{font-family:'Poppins','Noto Sans Sinhala',sans-serif;background:var(--bg);color:var(--text);font-size:12.5px;padding:20px;}
.wrap{max-width:1000px;margin:0 auto;}
.head{text-align:center;margin-bottom:18px;}
.head img{max-width:150px;margin-bottom:6px;}
.head h1{font-size:21px;font-weight:700;margin:0;}
.head p{color:var(--muted);margin:2px 0 0;}
.card-box{background:#fff;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 4px 12px -2px rgba(15,23,42,.05);padding:18px;margin-bottom:15px;}
.steps{display:flex;gap:10px;justify-content:center;margin-bottom:15px;}
.step{padding:5px 14px;border-radius:50px;background:#fff;border:1px solid #e2e8f0;font-weight:500;color:var(--muted);}
.step.active{background:#fdece3;color:var(--orange);border-color:#f3c6ab;font-weight:600;}
.step.done{background:#dcfce7;color:var(--ok);border-color:#bbf7d0;}
.progress{height:8px;border-radius:10px;}
.progress-bar{background:var(--orange);}

.tut-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:15px;}
.tut-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;cursor:pointer;padding:0;text-align:left;font:inherit;width:100%;transition:box-shadow .2s,border-color .2s;position:relative;}
.tut-card:hover{box-shadow:0 8px 20px rgba(201,80,15,.15);border-color:#f3c6ab;}
.tut-thumb{position:relative;aspect-ratio:16/9;background:#1c2733;overflow:hidden;}
.tut-thumb video{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;}
.tut-play{position:absolute;inset:0;margin:auto;width:42px;height:42px;border-radius:50%;background:var(--orange);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;}
.tut-time{position:absolute;right:8px;bottom:8px;background:rgba(0,0,0,.75);color:#fff;font-size:10px;padding:2px 6px;border-radius:6px;}

.card-progress-container{position:absolute;bottom:0;left:0;right:0;height:4px;background:rgba(255,255,255,0.3);z-index:3;}
.card-progress-bar{height:100%;background:var(--ok);width:0%;transition:width 0.3s ease;}

.tut-body{padding:10px 13px 13px;}
.tut-body h3{margin:0 0 4px;font-size:12.5px;font-weight:600;}
.tut-body p{margin:0;font-size:11.5px;color:var(--muted);}
.badge-watched{position:absolute;top:8px;left:8px;background:var(--ok);color:#fff;font-size:10.5px;padding:3px 8px;border-radius:50px;display:none;z-index:2;}
.tut-card.watched .badge-watched{display:block;}
.tut-card.watched{border-color:#bbf7d0;}

.modal-bg{position:fixed;inset:0;background:rgba(10,14,20,.78);display:none;align-items:center;justify-content:center;padding:20px;z-index:2000;}
.modal-bg.open{display:flex;}
.modal-box{width:min(940px,100%);background:#0f1720;border-radius:14px;overflow:hidden;}
.modal-top{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;color:#fff;}
.modal-top h3{margin:0;font-size:13px;font-weight:600;}
.modal-top button{background:none;border:0;color:#fff;font-size:1.6rem;cursor:pointer;line-height:1;}
.player{aspect-ratio:16/9;background:#000;}
.player video{width:100%;height:100%;display:block;}

.btn-main{background:var(--orange);color:#fff;border:none;border-radius:8px;font-weight:600;font-size:13px;height:42px;padding:0 24px;}
.btn-main:hover{background:var(--orange-dark);color:#fff;}
.btn-main:disabled{background:#cbd5e1;color:#64748b;cursor:not-allowed;}

/* Quiz modal styles */
.quiz-box{width:min(900px,100%);max-height:92vh;background:var(--bg);border-radius:14px;display:flex;flex-direction:column;overflow:hidden;}
.quiz-top{background:var(--orange);color:#fff;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;}
.quiz-top h3{margin:0;font-size:14px;font-weight:600;}
.quiz-top button{background:none;border:0;color:#fff;font-size:1.6rem;cursor:pointer;line-height:1;}
.quiz-body{overflow-y:auto;padding:16px;}
body.no-scroll{overflow:hidden;}

.q-card{border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:12px;background:#fff;}
.q-title{font-weight:600;font-size:13.5px;margin-bottom:8px;line-height:1.6;}
.q-stem{background:#f8fafc;border-radius:8px;padding:8px 12px;margin-bottom:8px;line-height:1.7;}
.opt{display:flex;align-items:flex-start;gap:10px;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:6px;cursor:pointer;line-height:1.6;transition:background .15s;}
.opt:hover{background:#fff7f1;}
.opt input{margin-top:4px;accent-color:var(--orange);}
.opt .mark{margin-left:auto;font-size:16px;font-weight:700;display:none;}
.q-card.checked .opt{cursor:default;}
.opt.correct{background:#dcfce7;border-color:#86efac;}
.opt.correct .mark{display:block;color:var(--ok);}
.opt.wrong{background:#fee2e2;border-color:#fca5a5;}
.opt.wrong .mark{display:block;color:var(--bad);}
.result{text-align:center;font-size:16px;font-weight:700;}
.result small{display:block;font-weight:400;color:var(--muted);font-size:12px;margin-top:4px;}
</style>
</head>
<body>
<div class="wrap">

<div class="head">
    <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons">
    <h1>Safety Tutorial &amp; Quiz</h1>
    <p>Welcome, <b><?= htmlspecialchars($company) ?></b>. Please watch all videos, then answer the questions.</p>
</div>

<div class="steps">
    <span class="step active" id="stepVideos"><i class="bi bi-play-circle-fill me-1"></i>1. Videos</span>
    <span class="step" id="stepQuiz"><i class="bi bi-ui-checks me-1"></i>2. Quiz</span>
</div>

<!-- ================= STEP 1 : VIDEOS ================= -->
<section id="videoSection">
    <div class="card-box">
        <div class="d-flex justify-content-between mb-2">
            <b>Videos watched</b><span id="watchText">0 / <?= count($videos) ?></span>
        </div>
        <div class="progress"><div class="progress-bar" id="watchBar" style="width:0%"></div></div>
        <p class="text-muted mt-2 mb-0">Click a video to play it. Watch each one until the end (you can't skip ahead). A video is marked <b>Watched</b> when it finishes.</p>
    </div>

    <div class="card-box">
        <div class="tut-grid">
            <?php foreach ($videos as $i => $v): ?>
            <button type="button" class="tut-card" data-vid="<?= (int)$v['id'] ?>" data-url="<?= htmlspecialchars(media_url($v['src'])) ?>" data-title="<?= htmlspecialchars($v['title']) ?>">
                <span class="badge-watched"><i class="bi bi-check-lg"></i> Watched</span>
                <div class="tut-thumb">
                    <video src="<?= htmlspecialchars(media_url($v['src'])) ?>#t=1" preload="metadata" muted></video>
                    <span class="tut-play"><i class="bi bi-play-fill"></i></span>
                    <span class="tut-time"><?= htmlspecialchars($v['duration']) ?></span>
                    <div class="card-progress-container">
                        <div class="card-progress-bar" id="cardProg_<?= (int)$v['id'] ?>" style="width: 0%;"></div>
                    </div>
                </div>
                <div class="tut-body">
                    <h3><?= ($i + 1) . '. ' . htmlspecialchars($v['title']) ?></h3>
                    <p><?= htmlspecialchars($v['description']) ?></p>
                </div>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="text-center">
        <button class="btn-main" id="startQuizBtn" disabled><i class="bi bi-lock-fill me-1"></i>Watch all videos to unlock the quiz</button>
    </div>
</section>

</div>

<!-- Video modal -->
<div class="modal-bg" id="tutModal">
    <div class="modal-box">
        <div class="modal-top"><h3 id="tutTitle"></h3><button type="button" id="tutClose" aria-label="Close">&times;</button></div>
        <div class="player" id="tutPlayer"></div>
    </div>
</div>

<!-- Quiz modal -->
<div class="modal-bg" id="quizModal">
    <div class="quiz-box">
        <div class="quiz-top">
            <h3>ප්‍රශ්නාවලිය (Quiz)</h3>
            <button type="button" id="quizClose" aria-label="Close">&times;</button>
        </div>
        <div class="quiz-body">
            <div class="card-box">
                <p class="text-muted mb-0">සෑම ප්‍රශ්නයකටම නිවැරදි පිළිතුර තෝරන්න. අවසානයේ "Submit Answers" ඔබන්න. Submit කළාට පසු පිළිතුරු වෙනස් කළ නොහැක.</p>
            </div>
            <div id="quizContainer"></div>
            <div class="alert alert-success text-center fw-semibold" id="successBox" style="display:none;">
                <i class="bi bi-check-circle-fill me-1"></i>ඔබේ පිළිතුරු සාර්ථකව ඉදිරිපත් කරන ලදී!<br>
                <small>Submitted successfully. Your answers have been saved.</small>
            </div>
            <div class="card-box result" id="resultBox" style="display:none;"></div>
            <div class="text-center mb-2">
                <button class="btn-main" id="submitQuizBtn"><i class="bi bi-check2-circle me-1"></i>Submit Answers</button>
                <button type="button" class="btn-main d-none" id="finishBtn"><i class="bi bi-check-circle-fill me-1"></i>Finish &amp; Submit</button>
            </div>
        </div>
    </div>
</div>

<script>
/* ============ QUESTIONS ============ */
const QUESTIONS = <?= json_encode($tutorialQuestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

/* ============ SESSION KEEP-ALIVE ============ */
function pingServer() { fetch('tutorial.php?action=ping', {cache: 'no-store', credentials: 'same-origin'}).catch(() => {}); }
setInterval(pingServer, 3 * 60 * 1000);                                   
document.addEventListener('visibilitychange', () => { if (!document.hidden) pingServer(); });   

/* ============ VIDEO & PROGRESS TRACKING ============ */
const TOTAL = <?= count($videos) ?>;
const cards = document.querySelectorAll('.tut-card');
const modal = document.getElementById('tutModal');
const player = document.getElementById('tutPlayer');
const startBtn = document.getElementById('startQuizBtn');

let watched = new Set();
let videoProgress = {}; 

<?php foreach ($initial_watched as $idx): ?>
watched.add(<?= (int)$idx ?>);
<?php endforeach; ?>

<?php if (!empty($initial_progress)): ?>
videoProgress = <?= json_encode($initial_progress) ?>;
<?php endif; ?>

try { 
    JSON.parse(sessionStorage.getItem('watchedVideos') || '[]').forEach(i => watched.add(i));
    let sp = JSON.parse(sessionStorage.getItem('videoProgress') || '{}');
    videoProgress = {...sp, ...videoProgress};
} catch(e) {}

async function saveProgressToServer() {
    try {
        await fetch('tutorial.php?action=save_video_progress', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ watched: [...watched], progress: videoProgress })
        });
    } catch(e) {}
}

function refreshProgress() {
    cards.forEach(c => {
        const idx = +c.dataset.vid;          // tutorial_videos.id, not a position
        c.classList.toggle('watched', watched.has(idx));
        
        const bar = document.getElementById('cardProg_' + idx);
        if (bar) {
            let progData = videoProgress[idx];
            let pct = 0;
            if (progData !== undefined) {
                pct = (typeof progData === 'object' && progData !== null) ? (progData.percent || 0) : progData;
            } else if (watched.has(idx)) {
                pct = 100;
            }
            bar.style.width = pct + '%';
        }
    });

    document.getElementById('watchText').textContent = watched.size + ' / ' + TOTAL;
    document.getElementById('watchBar').style.width = (watched.size / TOTAL * 100) + '%';
    
    if (watched.size >= TOTAL) {
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="bi bi-arrow-right-circle-fill me-1"></i>Start Quiz';
    }
    
    try { 
        sessionStorage.setItem('watchedVideos', JSON.stringify([...watched])); 
        sessionStorage.setItem('videoProgress', JSON.stringify(videoProgress));
    } catch(e) {}
    
    saveProgressToServer(); 
}

function closeModal() {
    modal.classList.remove('open');
    player.innerHTML = '';
}

cards.forEach(c => c.addEventListener('click', () => {
    const idx = +c.dataset.vid;              // tutorial_videos.id, not a position
    document.getElementById('tutTitle').textContent = c.dataset.title;
    // data-url is resolved server side: a presigned media.php link when
    // S3 is on, or the plain assets/videos/ path when it is off.
    player.innerHTML = '<video src="' + c.dataset.url + '" controls autoplay controlsList="nodownload"></video>';
    const vid = player.querySelector('video');
    
    // Video eka load wuna gaman, kalin nawaththapu thana (time eka) idan play wenna salasseeema (Secure handling for both object & number formats)
    vid.addEventListener('loadedmetadata', () => {
        let prog = videoProgress[idx];
        if (prog !== undefined && prog !== null) {
            let savedTime = (typeof prog === 'object') ? (prog.time || 0) : 0;
            let savedPercent = (typeof prog === 'object') ? (prog.percent || 0) : prog;
            
            if (savedTime > 0 && savedPercent < 99) {
                vid.currentTime = savedTime;
            }
        }
    });

    let maxTime = 0;
    vid.addEventListener('timeupdate', () => {
        if (!vid.duration) return;
        if (!vid.seeking && vid.currentTime > maxTime) maxTime = vid.currentTime;
        
        let percent = Math.round((vid.currentTime / vid.duration) * 100);
        let prog = videoProgress[idx];
        let existingPercent = 0;
        if (prog !== undefined && prog !== null) {
            existingPercent = (typeof prog === 'object') ? (prog.percent || 0) : prog;
        }
        
        videoProgress[idx] = {
            percent: Math.max(existingPercent, percent),
            time: vid.currentTime
        };
        
        const bar = document.getElementById('cardProg_' + idx);
        if (bar) bar.style.width = videoProgress[idx].percent + '%';
    });

    vid.addEventListener('seeking', () => { 
        if (vid.currentTime > maxTime + 1) vid.currentTime = maxTime; 
    });   
    
    vid.addEventListener('ended', () => { 
        watched.add(idx); 
        videoProgress[idx] = { percent: 100, time: 0 };
        refreshProgress(); 
    });
    
    modal.classList.add('open');
}));

document.getElementById('tutClose').onclick = () => { closeModal(); refreshProgress(); };
modal.addEventListener('click', e => { if (e.target === modal) { closeModal(); refreshProgress(); } });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); refreshProgress(); } });

refreshProgress();

/* ============ QUIZ (modal) ============ */
const LOCKED = <?= json_encode($locked_answers) ?>;
const quizModal = document.getElementById('quizModal');
const letters = ['A','B','C','D','E'];
let locked = false;

function buildQuiz() {
    const box = document.getElementById('quizContainer');
    box.innerHTML = '';
    QUESTIONS.forEach((q, qi) => {
        const card = document.createElement('div');
        card.className = 'q-card';
        card.id = 'q' + qi;
        let html = '<div class="q-title">' + (qi + 1) + '. ' + q.q + '</div>';
        if (q.stem) html += '<div class="q-stem">' + q.stem.join('<br>') + '</div>';
        q.o.forEach((opt, oi) => {
            const label = q.stem ? (oi + 1) + '. ' : letters[oi] + '. ';
            html += '<label class="opt" data-o="' + oi + '"><input type="radio" name="q' + qi + '" value="' + oi + '"><span>' + label + opt + '</span><span class="mark"></span></label>';
        });
        card.innerHTML = html;
        box.appendChild(card);
    });
}

function openQuiz() {
    quizModal.classList.add('open');
    document.body.classList.add('no-scroll');
    document.getElementById('stepVideos').className = 'step done';
    document.getElementById('stepQuiz').className = 'step active';
}
function closeQuiz() {
    if (locked) return;                       
    quizModal.classList.remove('open');
    document.body.classList.remove('no-scroll');
    document.getElementById('stepVideos').className = 'step active';
    document.getElementById('stepQuiz').className = 'step';
}

startBtn.addEventListener('click', () => {
    if (startBtn.disabled) return;
    buildQuiz();
    openQuiz();
});
document.getElementById('quizClose').onclick = closeQuiz;

function applyResult(answers) {
    locked = true;
    let score = 0;
    QUESTIONS.forEach((q, qi) => {
        const card = document.getElementById('q' + qi);
        const chosen = answers[qi];
        card.classList.add('checked');
        const radio = card.querySelector('input[value="' + chosen + '"]');
        if (radio) radio.checked = true;
        card.querySelectorAll('input').forEach(i => i.disabled = true);
        card.querySelectorAll('.opt').forEach(opt => {
            const oi = +opt.dataset.o;
            const mark = opt.querySelector('.mark');
            if (oi === q.a) { opt.classList.add('correct'); mark.innerHTML = '<i class="bi bi-check-circle-fill"></i>'; }
            else if (oi === chosen) { opt.classList.add('wrong'); mark.innerHTML = '<i class="bi bi-x-circle-fill"></i>'; }
        });
        if (chosen === q.a) score++;
    });
    document.getElementById('successBox').style.display = 'block';
    const rb = document.getElementById('resultBox');
    rb.style.display = 'block';
    rb.innerHTML = 'ඔබේ ලකුණු: ' + score + ' / ' + QUESTIONS.length + '<small>Your score: ' + Math.round(score / QUESTIONS.length * 100) + '%  (නිවැරදි පිළිතුරු ✔ ලකුණින් පෙන්වා ඇත)</small>';
    document.getElementById('submitQuizBtn').style.display = 'none';
    document.getElementById('finishBtn').classList.remove('d-none');
    document.getElementById('quizClose').style.display = 'none';

    history.pushState(null, '', location.href);
    window.addEventListener('popstate', () => history.pushState(null, '', location.href));
}

document.getElementById('submitQuizBtn').addEventListener('click', async function () {
    const unanswered = [];
    QUESTIONS.forEach((q, qi) => { if (!document.querySelector('input[name="q' + qi + '"]:checked')) unanswered.push(qi + 1); });
    if (unanswered.length) {
        alert('කරුණාකර සියලුම ප්‍රශ්නවලට පිළිතුරු දෙන්න.\nUnanswered: ' + unanswered.join(', '));
        document.getElementById('q' + (unanswered[0] - 1)).scrollIntoView({behavior: 'smooth', block: 'center'});
        return;
    }
    const answers = QUESTIONS.map((q, qi) => +document.querySelector('input[name="q' + qi + '"]:checked').value);

    const btn = this, original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    try {
        const res = await fetch('tutorial.php?action=save_quiz', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({answers: answers})
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Save failed');
        if (data.already) { location.reload(); return; }
        try { sessionStorage.removeItem('watchedVideos'); sessionStorage.removeItem('videoProgress'); } catch(e) {}
        applyResult(answers);
        const qb = document.querySelector('.quiz-body');
        qb.scrollTo({top: qb.scrollHeight, behavior: 'smooth'});
    } catch (err) {
        alert('ප්‍රතිඵල save කිරීමට නොහැකි වුණා / Could not save results: ' + err.message + '\nPlease try again.');
        btn.disabled = false;
        btn.innerHTML = original;
    }
});

document.getElementById('finishBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';

    const quizBody = document.querySelector('.quiz-body');
    const successPopup = document.createElement('div');
    successPopup.className = 'alert alert-success text-center fw-bold shadow-sm p-3 mt-3 animate-fade';
    successPopup.innerHTML = '<i class="bi bi-check-circle-fill fs-3 text-success d-block mb-1"></i> Form Successfully Submitted!<br><small class="text-muted fw-normal">ඔබගේ දත්ත සාර්ථකව යොමු කරන ලදී. Redirecting...</small>';
    quizBody.appendChild(successPopup);
    successPopup.scrollIntoView({ behavior: 'smooth', block: 'center' });

    setTimeout(() => {
        window.location.href = 'index.php';
    }, 2000);
});

window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
if (LOCKED) {
    for (let i = 0; i < TOTAL; i++) watched.add(i);
    refreshProgress();
    buildQuiz();
    openQuiz();
    applyResult(LOCKED.map(Number));
}
</script>
</body>
</html>