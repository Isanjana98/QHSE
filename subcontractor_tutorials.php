<?php
session_start();

// User කෙනෙක් Login වී ඇත්දැයි බැලීම
if (!isset($_SESSION['subcontractor_id'])) {
    header("Location: index.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

// Database Connection
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ඩේටාබේස් එකෙන් අදාළ Subcontractor ගේ විස්තර ලබා ගැනීම
$sub_id =$_SESSION['subcontractor_id'];
$stmt =$conn->prepare("SELECT * FROM subcontractors WHERE id = ?");
if (!$stmt) {
    die("Database query failed: " . $conn->error);
}
$stmt->bind_param("i", $sub_id);
$stmt->execute();$result = $stmt->get_result();$sub = $result->fetch_assoc();$stmt->close();

$company_name_db =$sub['company_name'] ?? 'Subcontractor';
$br_number =$sub['business_reg_number'] ?? 'N/A';

// 1. Pending Permits Count
$pending_count = 0;
$pending_stmt =$conn->prepare("SELECT * FROM service_provider_permits WHERE subcontractor_id = ? AND status = 'Pending'");
if ($pending_stmt) {$pending_stmt->bind_param("i", $sub_id);$pending_stmt->execute();
    $pending_permits_result =$pending_stmt->get_result();
    $pending_count =$pending_permits_result->num_rows;
    $pending_stmt->close();
} else {
    error_log("Pending permits count query failed: " . $conn->error);
}

/* Tutorial videos are managed in admin_tutorials.php and stored in the
   tutorial_videos table. Both file and YouTube entries show here. */
$videos = [];
$vres = $conn->query("
    SELECT id, title, description, type, src, duration
      FROM tutorial_videos
     WHERE is_active = 1
     ORDER BY sort_order ASC, id ASC
");
while ($vres && $vrow = $vres->fetch_assoc()) {
    $videos[] = $vrow;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutorials | QHSE EN</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --primary-orange: #e15e13;
            --primary-orange-dark: #bb4c14;
            --bg-app: #f4f6f9;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --card-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.05);
            --line: #eceff4;
        }

        body {
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-app);
            color: var(--text-main);
            margin: 0;
            display: flex;
            font-size: 12.5px;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Poppins', sans-serif;
            letter-spacing: -0.01em;
        }

        /* =====================================================
           OUTER WRAPPER & SIDEBAR NAVBAR STYLING (Dashboard Compatible)
        ===================================================== */
        .sidebar-outer-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0px;
            margin: 4px 0 20px 15px;
            position: sticky;
            top: 4px;
            height: calc(100vh - 24px);
            flex-shrink: 0;
            z-index: 1000;
        }

        .sidebar-logo-outside {
            width: 90px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: -8px;
            margin-bottom: -15px;
            transition: transform 0.3s ease;
        }

        .sidebar-logo-outside:hover {
            transform: scale(1.05);
        }

        .sidebar-logo-outside img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .navbar-custom {
            background: linear-gradient(180deg, var(--primary-orange) 0%, var(--primary-orange-dark) 100%);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 35px;
            margin: 0;
            padding: 22px 10px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.08);
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #fff;
            font-family: 'Inter', sans-serif;
            width: 85px; 
            flex: 1;
            min-height: 0;
            position: static;
            flex-shrink: 0;
            transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-x: hidden;
        }

        .navbar-custom:hover {
            width: 230px;
            padding: 22px 16px;
        }

        .navbar-inner-content {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
        }

        .mobile-dropdown-wrapper {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            height: 100%;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 14px;
            justify-content: center;
            align-items: center;
            width: 100%;
            padding: 10px 0;
            margin: auto 0;
        }

        .sidebar-menu-item {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 14px;
            padding: 10px 12px;
            border-radius: 16px;
            color: #ffffff; 
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            white-space: nowrap;
        }

        .sidebar-menu-item i {
            font-size: 18px;
            color: #ffffff;
            min-width: 24px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .sidebar-menu-item span.nav-text,
        .logout-btn span {
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .navbar-custom:hover .sidebar-menu-item span.nav-text,
        .navbar-custom:hover .logout-btn span {
            opacity: 1;
            visibility: visible;
        }

        .sidebar-menu-item:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: translateX(4px);
        }

        .sidebar-menu-item:hover i {
            transform: scale(1.15);
        }

        .sidebar-menu-item.active {
            background: rgba(255, 255, 255, 0.32);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.22);
            font-weight: 700;
        }

        .right-section { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            width: 100%; 
            border-top: 1px solid rgba(255, 255, 255, 0.18); 
            padding-top: 16px; 
            margin-top: auto; 
            flex-shrink: 0;
        }

        .logout-container {
            display: flex;
            justify-content: center;
            width: 100%;
        }

        .logout-btn {
            width: 100%;
            height: 44px;
            padding: 0 12px;
            border-radius: 14px;
            background: rgba(0, 0, 0, 0.2); 
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 14px;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .logout-btn:hover { 
            background: #ef4444; 
            border-color: #ef4444; 
            color: #fff;
            transform: translateX(4px);
        }

        .logout-btn i { 
            font-size: 16px; 
            color: #fff;
            min-width: 24px;
            text-align: center;
        }

        .mobile-toggle-btn {
            display: none;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            padding: 0 4px;
        }

        /* =====================================================
           TUTORIALS CONTENT STYLING
        ===================================================== */
        .page { flex: 1; padding: 15px; max-width: calc(100vw - 100px); overflow-y: auto; }
        .page-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .page-head h1 { margin:0; font-size:22px; font-weight:700; color:var(--text-main); line-height:1.2; }
        .page-head p { margin:2px 0 0; color:var(--text-muted); font-size:12.5px; }
        .page-head p b { color:var(--text-main); }
        .pills { display:flex; gap:10px; align-items:center; }
        .pill { padding:5px 12px; border-radius:50px; font-size:11.5px; font-weight:500; background:#fff; border:1px solid #e2e8f0; }
        .pill.pending { background:#fdece3; color:var(--primary-orange); font-weight:600; border-color: #f3c6ab; }
        .pill i { color:#f5a524; margin-right:6px; }

        .card-box { background:var(--card-bg); border-radius:12px; border:1px solid rgba(226, 232, 240, 0.8); box-shadow:var(--card-shadow); padding:15px; margin-bottom:15px; }
        .stat { display:flex; align-items:center; gap:15px; }
        .stat-icon { width:45px; height:45px; border-radius:10px; background:rgba(2, 132, 199, 0.1); color:#0284c7; display:flex; align-items:center; justify-content:center; font-size:20px; }
        .stat b { display:block; font-size:18px; line-height:1.2; color:var(--text-main); }
        .stat span { font-size:11px; font-weight:600; letter-spacing:0.03em; text-transform:uppercase; color:var(--text-muted); }

        .list-head { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:15px; }
        .list-head h2 { margin:0; font-size:13px; font-weight:700; color:var(--text-main); }
        .list-head h2 i { color:#f5a524; font-size:13px; margin-right:6px; }
        .list-head p { margin:0; color:var(--text-muted); font-size:11.5px; }
        .search { position:relative; }
        .search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); }
        .search input { width:260px; max-width:100%; height:38px; padding:0 12px 0 36px; border:1px solid #d9dee6; border-radius:10px; font:inherit; font-size:11.5px; background:#fff; }
        .search input:focus { outline:2px solid rgba(225,94,19,.35); border-color:var(--primary-orange); }

        .tut-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:15px; }
        .tut-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; cursor:pointer; padding:0; text-align:left; font:inherit; width:100%; transition:box-shadow .2s, border-color .2s; }
        .tut-card:hover, .tut-card:focus-visible { box-shadow:0 8px 20px rgba(201,80,15,.15); border-color:#f3c6ab; outline:none; }
        .tut-thumb { position:relative; aspect-ratio:16/9; background:#1c2733; overflow:hidden; }
        .tut-thumb video, .tut-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .tut-play { position:absolute; inset:0; margin:auto; width:45px; height:45px; border-radius:50%; background:var(--primary-orange); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 4px 12px rgba(0,0,0,.3); }
        .tut-time { position:absolute; right:8px; bottom:8px; background:rgba(0,0,0,.75); color:#fff; font-size:10px; padding:2px 6px; border-radius:6px; }
        .tut-body { padding:12px 14px 14px; }
        .tut-body h3 { margin:0 0 4px; font-size:12.5px; font-weight:600; color:var(--text-main); }
        .tut-body p { margin:0; font-size:11.5px; color:var(--text-muted); }
        .empty { color:var(--text-muted); padding:15px 0; }

        /* Player modal */
        .modal-bg { position:fixed; inset:0; background:rgba(10,14,20,.75); display:none; align-items:center; justify-content:center; padding:20px; z-index:2000; }
        .modal-bg.open { display:flex; }
        .modal-box { width:min(960px,100%); background:#0f1720; border-radius:14px; overflow:hidden; }
        .modal-top { display:flex; justify-content:space-between; align-items:center; padding:12px 18px; color:#fff; }
        .modal-top h3 { margin:0; font-size:13px; font-weight:600; }
        .modal-top button { background:none; border:0; color:#fff; font-size:1.6rem; cursor:pointer; line-height:1; }
        .player { aspect-ratio:16/9; background:#000; }
        .player video, .player iframe { width:100%; height:100%; border:0; display:block; }

        /* ===== Responsive Media Queries (Dashboard matched) ===== */
        @media (max-width: 992px) {
            body { flex-direction: column; }
            
            .sidebar-outer-wrap {
                flex-direction: row;
                align-items: center;
                width: calc(100% - 24px) !important;
                height: auto !important;
                margin: 12px;
                position: sticky;
                top: 12px;
                gap: 10px;
            }
            
            .sidebar-logo-outside {
                width: 42px;
                height: 42px;
                margin-bottom: 0;
            }
            
            .navbar-custom {
                width: 100% !important;
                flex: 1;
                height: auto !important;
                margin: 0;
                padding: 10px 16px !important;
                border-radius: 22px;
                overflow: visible;
                position: static;
            }
            
            .navbar-custom:hover {
                width: 100% !important;
                padding: 10px 16px !important;
            }
            
            .navbar-inner-content {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                width: 100%;
            }
            
            .mobile-toggle-btn {
                display: block;
                margin-left: auto;
            }
            
            .mobile-dropdown-wrapper {
                display: none;
                position: absolute;
                top: calc(100% + 10px);
                left: 0;
                right: 0;
                background: linear-gradient(180deg, var(--primary-orange) 0%, var(--primary-orange-dark) 100%);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                border: 1px solid rgba(255, 255, 255, 25);
                border-radius: 22px;
                padding: 20px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.4);
                flex-direction: column;
                gap: 14px;
                height: auto;
                z-index: 1001;
                justify-content: flex-start;
                align-items: stretch;
            }
            
            .mobile-dropdown-wrapper.show {
                display: flex;
            }
            
            .sidebar-menu {
                display: flex !important;
                width: 100%;
                gap: 10px;
                align-items: stretch;
                margin: 0;
                padding-top: 0;
                flex-grow: 0;
            }
            
            .sidebar-menu-item span.nav-text,
            .logout-btn span {
                opacity: 1 !important;
                visibility: visible !important;
            }
            
            .sidebar-menu-item {
                justify-content: flex-start;
                padding: 12px 16px;
            }
            
            .right-section {
                border-top: 1px solid rgba(255, 255, 255, 0.22);
                padding-top: 16px;
                margin-top: 0;
                width: 100%;
            }
            
            .logout-btn {
                height: 46px;
                padding: 0 16px;
                justify-content: center;
            }

            .page { max-width: 100%; padding: 12px; }
            .search input { width: 100%; } 
            .search { width: 100%; }
        }
    </style>
    <link rel="stylesheet" href="subcontractor_navbar.css">
</head>
<body>

<?php include __DIR__ . '/subcontractor_navbar.php'; ?>

<!-- Main Content Area -->
<main class="page">
    <header class="page-head">
        <div>
            <h1>Tutorials</h1>
            <p>BR Number: <b><?= htmlspecialchars($br_number) ?></b> &bull; Video Guides &amp; How-to</p>
        </div>
        <div class="pills">
            <span class="pill pending">Pending: <?= (int)$pending_count ?></span>
            <span class="pill"><i class="bi bi-calendar3"></i><?= date('D, M j, Y') ?></span>
        </div>
    </header>

    <section class="card-box">
        <div class="stat">
            <div class="stat-icon"><i class="bi bi-collection-play-fill"></i></div>
            <div><b><?= count($videos) ?></b><span>Total tutorial videos</span></div>
        </div>
    </section>

    <section class="card-box">
        <div class="list-head">
            <div>
                <h2><i class="bi bi-list-ul"></i>Tutorial Videos</h2>
                <p>Watch these videos to learn how to use the portal.</p>
            </div>
            <div class="search">
                <i class="bi bi-search"></i>
                <input type="search" id="tutSearch" placeholder="Search tutorials...">
            </div>
        </div>

        <?php if (empty($videos)): ?>
            <p class="empty">No tutorials added yet.</p>
        <?php else: ?>
        <div class="tut-grid">
            <?php foreach ($videos as$v): ?>
            <button type="button" class="tut-card" data-type="<?= htmlspecialchars($v['type']) ?>" data-src="<?= htmlspecialchars($v['src']) ?>" data-url="<?= htmlspecialchars($v['type'] === 'youtube' ? '' : media_url($v['src'])) ?>" data-title="<?= htmlspecialchars($v['title']) ?>">
                <div class="tut-thumb">
                    <?php if ($v['type'] === 'youtube'): ?>
                        <img src="https://img.youtube.com/vi/<?= htmlspecialchars($v['src']) ?>/hqdefault.jpg" alt="">
                    <?php else: ?>
                        <video src="<?= htmlspecialchars(media_url($v['src'])) ?>#t=1" preload="metadata" muted></video>
                    <?php endif; ?>
                    <span class="tut-play"><i class="bi bi-play-fill"></i></span>
                    <span class="tut-time"><?= htmlspecialchars($v['duration']) ?></span>
                </div>
                <div class="tut-body">
                    <h3><?= htmlspecialchars($v['title']) ?></h3>
                    <p><?= htmlspecialchars($v['description'] ?? '') ?></p>
                </div>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</main>

<!-- Video Player Modal -->
<div class="modal-bg" id="tutModal">
    <div class="modal-box">
        <div class="modal-top">
            <h3 id="tutTitle"></h3>
            <button type="button" id="tutClose" aria-label="Close">&times;</button>
        </div>
        <div class="player" id="tutPlayer"></div>
    </div>
</div>

<script>
// Video modal and search functionality
(function () {
    const modal = document.getElementById('tutModal'), player = document.getElementById('tutPlayer');
    const close = () => { modal.classList.remove('open'); player.innerHTML = ''; };

    document.querySelectorAll('.tut-card').forEach(c => c.addEventListener('click', () => {
        const { type, src, title, url } = c.dataset;
        document.getElementById('tutTitle').textContent = title;
        player.innerHTML = type === 'youtube'
            ? `<iframe src="https://www.youtube.com/embed/${encodeURIComponent(src)}?autoplay=1&rel=0" allow="autoplay; fullscreen" allowfullscreen></iframe>`
            : `<video src="${url}" controls autoplay controlsList="nodownload"></video>`;
        modal.classList.add('open');
    }));

    document.getElementById('tutClose').onclick = close;
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

    document.getElementById('tutSearch').addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        document.querySelectorAll('.tut-card').forEach(c => c.style.display = c.textContent.toLowerCase().includes(q) ? '' : 'none');
    });
})();

// Mobile sidebar toggle script
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('navbarToggleBtn');
    const dropdownWrapper = document.getElementById('navDropdown');
    const toggleIcon = document.getElementById('toggleIcon');

    (function () {
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                dropdownWrapper.classList.toggle('show');
                
                if (dropdownWrapper.classList.contains('show')) {
                    toggleIcon.classList.remove('bi-list');
                    toggleIcon.classList.add('bi-x-lg');
                } else {
                    toggleIcon.classList.remove('bi-x-lg');
                    toggleIcon.classList.add('bi-list');
                }
            });
        }
    })();
});
</script>
</body>
</html>