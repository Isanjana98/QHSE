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

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ඩේටාබේස් එකෙන් අදාළ Subcontractor ගේ විස්තර ලබා ගැනීම
$sub_id = $_SESSION['subcontractor_id'];
$stmt = $conn->prepare("SELECT * FROM subcontractors WHERE id = ?");
$stmt->bind_param("i", $sub_id);
$stmt->execute();
$result = $stmt->get_result();
$sub = $result->fetch_assoc();
$stmt->close();

$sub_phone = $sub['phone'] ?? 'N/A';

// Pending Permits Count (Badge එකට පෙන්වීමට)
$pending_stmt = $conn->prepare("SELECT * FROM service_provider_permits WHERE subcontractor_id = ? AND status = 'Pending'");
$pending_stmt->bind_param("i", $sub_id);
$pending_stmt->execute();
$pending_permits_result = $pending_stmt->get_result();
$pending_count = $pending_permits_result->num_rows;
$pending_stmt->close();

// Subcontractor ගේ සියලුම Permits / History ලබා ගැනීම (Approved, Rejected, Pending සියල්ලම)
$history_stmt = $conn->prepare("SELECT * FROM service_provider_permits WHERE subcontractor_id = ? ORDER BY permit_id DESC");
$history_stmt->bind_param("i", $sub_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result();
$history_stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit History | Subcontractor Portal</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="table_pagination.css">

<style>
:root {
    --primary-orange: #e15e13;
    --primary-orange-dark: #bb4c14;
    --bg-app: #f4f6f9;
    --card-bg: #ffffff;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --card-shadow: 0 4px 12px -2px rgba(15, 23, 42, 0.05);
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
   OUTER WRAPPER & SIDEBAR NAVBAR STYLING
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
   MAIN CONTENT STYLES
===================================================== */
.main-content-wrapper {
    flex: 1;
    padding: 15px;
    max-width: calc(100vw - 100px);
    overflow-y: auto;
}

.dashboard-header {
    background: transparent;
    border-radius: 0;
    padding: 10px 0;
    box-shadow: none;
    border: none;
    margin-bottom: 20px;
}

.header-title h2 {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 2px;
}

.header-title p {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 0;
}

/* Typing Effect Header Style (Cursor Removed) */
.typing-title {
    color: var(--primary-orange);
    display: inline-block;
    white-space: nowrap;
}

.pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 50px;
    font-size: 11.5px;
    font-weight: 500;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.pill-badge.alert {
    background: rgba(249, 115, 22, 0.08);
    color: #c2410c;
    border-color: rgba(249, 115, 22, 0.15);
}

.content-box {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 15px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(226, 232, 240, 0.8);
    margin-bottom: 15px;
}

.content-box h5 {
    font-size: 13px;
    font-weight: 700;
}

.table {
    font-size: 11.5px;
}

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
        border: 1px solid rgba(255, 255, 255, 0.25);
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

    .main-content-wrapper { max-width: 100%; padding: 12px; }
}
</style>
<link rel="stylesheet" href="subcontractor_navbar.css">
</head>
<body>

<?php include __DIR__ . '/subcontractor_navbar.php'; ?>

<!-- Main Content Area -->
<div class="main-content-wrapper">
    <div class="container-fluid p-0">
        
        <!-- HEADER BAR -->
        <div class="dashboard-header d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="header-title">
                <h2 class="m-0">
                     <span id="typingHeading" class="typing-title"></span>
                </h2>
                <p class="m-0 mt-1">Company: <span class="fw-bold text-dark"><?= htmlspecialchars($sub['company_name'] ?? 'Subcontractor'); ?></span> &bull; All Past & Present Records</p>
            </div>
            <div class="header-meta d-flex align-items-center gap-2 flex-wrap">
                <div class="pill-badge alert">
                    <span class="spinner-grow spinner-grow-sm text-warning" style="width: 5px; height: 5px;" role="status"></span>
                    Pending: <strong><?= $pending_count; ?></strong>
                </div>
                <div class="pill-badge">
                    <i class="bi bi-calendar3 text-warning"></i> <span id="liveDate"></span>
                </div>
            </div>
        </div>

        <!-- ALL PERMITS / HISTORY TABLE SECTION -->
        <div class="content-box mb-3">
            <h5 class="fw-bold mb-2 text-dark"><i class="bi bi-list-stars text-primary me-1"></i> Complete Permits & Job History</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" data-table-pagination>
                    <thead style="background-color: #0b1120; color: #ffffff;">
                        <tr>
                            <th style="padding: 12px; background-color: #0b1120; color: #ffffff; border-top-left-radius: 8px; border-bottom-left-radius: 8px;">PERMIT NUMBER</th>
                            <th style="padding: 12px; background-color: #0b1120; color: #ffffff;">PERMIT TYPE</th>
                            <th style="padding: 12px; background-color: #0b1120; color: #ffffff;">PROJECT</th>
                            <th style="padding: 12px; background-color: #0b1120; color: #ffffff;">CONTACT NO</th>
                            <th style="padding: 12px; background-color: #0b1120; color: #ffffff;">STATUS</th>
                            <th style="padding: 12px; background-color: #0b1120; color: #ffffff;">CREATED DATE</th>
                            <th style="padding: 12px; background-color: #0b1120; color: #ffffff; border-top-right-radius: 8px; border-bottom-right-radius: 8px;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history_result->num_rows > 0): ?>
                            <?php while($row = $history_result->fetch_assoc()): 
                                $status = $row['status'] ?? 'Pending';
                                $badge_bg = 'bg-secondary';
                                if ($status == 'Approved') {
                                    $badge_bg = 'bg-success';
                                } elseif ($status == 'Pending') {
                                    $badge_bg = 'bg-warning text-dark';
                                } elseif ($status == 'Rejected') {
                                    $badge_bg = 'bg-danger';
                                }
                            ?>
                                <tr>
                                    <td class="fw-bold text-primary" style="padding: 12px;"><?= htmlspecialchars($row['permit_number']); ?></td>
                                    <td style="padding: 12px;"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $row['permit_category'] ?? 'N/A'))); ?></td>
                                    <td style="padding: 12px;"><?= htmlspecialchars($row['cat_project'] ?? 'N/A'); ?></td>
                                    <td style="padding: 12px;"><?= htmlspecialchars($sub_phone); ?></td>
                                    <td style="padding: 12px;"><span class="badge <?= $badge_bg; ?>"><?= htmlspecialchars($status); ?></span></td>
                                    <td style="padding: 12px;"><?= htmlspecialchars($row['declaration_date'] ?? 'N/A'); ?></td>
                                    <td style="padding: 12px;">
                                        <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 11px;">View</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No history records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- JavaScript Includes and Scripts -->
<script src="table_pagination.js"></script>
<script>
// Live Date Updater
function updateDate() {
    const now = new Date();
    const options = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    if(document.getElementById('liveDate')) {
        document.getElementById('liveDate').innerText = now.toLocaleDateString('en-US', options);
    }
}
updateDate();

// Typing Effect for "Permit History" (Cursor completely removed)
document.addEventListener("DOMContentLoaded", function() {
    const text = "Permit History";
    const typingElement = document.getElementById("typingHeading");
    let index = 0;

    function typeWriter() {
        if (index < text.length) {
            typingElement.innerHTML += text.charAt(index);
            index++;
            setTimeout(typeWriter, 120); // Typing speed in milliseconds
        }
    }
    
    if (typingElement) {
        typeWriter();
    }
});

// Mobile sidebar toggle script
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('navbarToggleBtn');
    const dropdownWrapper = document.getElementById('navDropdown');
    const toggleIcon = document.getElementById('toggleIcon');

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
});
</script>
</body>
</html>