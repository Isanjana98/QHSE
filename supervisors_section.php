<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/s3_helper.php';   // media_url() for profile pictures

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$self = basename($_SERVER['PHP_SELF']);          // page works whatever the file is named
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* =====================================================
   Approve / Reject (POST + CSRF token)
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $map = ['accept' => 'Approved', 'reject' => 'Rejected'];
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? '')) && isset($map[$_POST['action']])) {
        $status = $map[$_POST['action']];
        $id     = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE supervisors SET status = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            $stmt->close();
        }
    }
    $tab = (($_POST['tab'] ?? '') === 'completed') ? 'completed' : 'pending';
    header("Location: $self?tab=$tab");
    exit();
}

$active_tab = (($_GET['tab'] ?? '') === 'completed') ? 'completed' : 'pending';

/* =====================================================
   Counts + pagination
   ===================================================== */
$PENDING_SQL   = "(status IS NULL OR status NOT IN ('Approved','Rejected'))";
$COMPLETED_SQL = "status IN ('Approved','Rejected')";

$cnt = $conn->query("SELECT SUM($PENDING_SQL) p, SUM(status='Approved') a, SUM(status='Rejected') r, COUNT(*) t FROM supervisors")->fetch_assoc();
$pending_total   = (int)$cnt['p'];
$approved_total  = (int)$cnt['a'];
$rejected_total  = (int)$cnt['r'];
$completed_total = $approved_total + $rejected_total;

$limit_raw = $_GET['limit'] ?? '10';
$limit = ($limit_raw === 'all') ? 0 : (in_array((int)$limit_raw, [5, 10, 25, 50], true) ? (int)$limit_raw : 10);

$p_page = max(1, (int)($_GET['p_page'] ?? 1));
$c_page = max(1, (int)($_GET['c_page'] ?? 1));
$p_pages = $limit ? max(1, (int)ceil($pending_total / $limit))   : 1;
$c_pages = $limit ? max(1, (int)ceil($completed_total / $limit)) : 1;
$p_page = min($p_page, $p_pages);
$c_page = min($c_page, $c_pages);

function fetch_sups($conn, $where, $limit, $page) {
    // password is deliberately never selected
    $sql = "SELECT id, name, nic, division, division_address, phone, email, username, created_at, status, profile_pic
              FROM supervisors WHERE $where ORDER BY id DESC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)(($page - 1) * $limit);
    }
    return $conn->query($sql);
}
$pending_rows   = fetch_sups($conn, $PENDING_SQL,   $limit, $p_page);
$completed_rows = fetch_sups($conn, $COMPLETED_SQL, $limit, $c_page);

/* =====================================================
   Render helpers
   ===================================================== */
function act_form($id, $action, $cls, $title, $tab) {
    global $self, $csrf;
    $iconPath = $action === 'accept' ? 'assets/images/a.png' : 'assets/images/r.png';
    $iconAlt = $action === 'accept' ? 'Approve' : 'Reject';
    echo '<form method="post" action="' . h($self) . '" class="d-inline js-act-form" data-action="' . h($action) . '">'
       . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
       . '<input type="hidden" name="id" value="' . (int)$id . '">'
       . '<input type="hidden" name="action" value="' . h($action) . '">'
       . '<input type="hidden" name="tab" value="' . h($tab) . '">'
       . '<button type="submit" class="act-btn ' . $cls . '" title="' . h($title) . '"><img src="' . h($iconPath) . '" alt="' . h($iconAlt) . '" width="18" height="18"></button></form>';
}

function render_rows($res, $tab) {
    if (!$res || $res->num_rows === 0) {
        echo '<tr><td colspan="8" class="empty-cell"><div class="empty-icon"><i class="bi bi-inbox"></i></div><strong>'
           . ($tab === 'pending' ? 'No Pending Requests' : 'No Processed Records')
           . '</strong><span>' . ($tab === 'pending' ? 'All supervisor registrations have been processed.' : 'No approved or rejected supervisors found.')
           . '</span></td></tr>';
        return;
    }
    while ($row = $res->fetch_assoc()) {
        $status = ($row['status'] === 'Approved' || $row['status'] === 'Rejected') ? $row['status'] : 'Pending';
        $cls  = ['Pending' => 'status-pending', 'Approved' => 'status-approved', 'Rejected' => 'status-rejected'][$status];
        $icon = ['Pending' => 'bi-clock-fill', 'Approved' => 'bi-check-circle-fill', 'Rejected' => 'bi-x-circle-fill'][$status];
        $pic  = media_url($row['profile_pic'] ?? '');
        $detail = media_rowify($row, ['profile_pic']);
        $detail['status'] = $status;
        $created = $row['created_at'] ? date('d M Y', strtotime($row['created_at'])) : '-';

        echo '<tr><td><div class="person-cell"><div class="person-avatar">'
           . ($pic !== '' ? '<img src="' . h($pic) . '" alt="">' : '<i class="bi bi-person-fill"></i>')
           . '</div><div class="person-name">' . h($row['name'] ?: 'N/A') . '</div></div></td>'
           . '<td>' . h($row['nic'] ?: '-') . '</td>'
           . '<td>' . h($row['division'] ?: '-') . '</td>'
           . '<td>' . h($row['phone'] ?: '-') . '</td>'
           . '<td>' . h($row['email'] ?: '-') . '</td>'
           . '<td>' . h($created) . '</td>'
           . '<td><span class="status-pill ' . $cls . '"><i class="bi ' . $icon . '"></i> ' . h($status) . '</span></td>'
           . '<td class="text-center"><div class="action-btn-group">'
           . '<button type="button" class="act-btn view js-view-sup" title="View Details" data-row="'
           . h(json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) . '"><img src="assets/images/v.png" alt="View" width="18" height="18"></button>';

        if ($status !== 'Approved') act_form($row['id'], 'accept', 'ok', 'Approve', $tab);
        if ($status !== 'Rejected') act_form($row['id'], 'reject', 'no', $status === 'Approved' ? 'Revoke / Reject' : 'Reject', $tab);

        echo '</div></td></tr>';
    }
}

function render_pager($tab, $page, $pages, $total, $pkey) {
    global $self, $limit;
    $url = function ($pg) use ($self, $tab, $limit, $pkey) {
        return $self . '?' . http_build_query(['tab' => $tab, 'limit' => $limit ?: 'all', $pkey => $pg]);
    };
    echo '<div class="pagination-container"><div class="pagination-info">Showing page <b>' . $page . '</b> of <b>' . $pages
       . '</b> (Total ' . $total . ' records)</div>';
    if ($pages > 1) {
        echo '<ul class="custom-pagination"><li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . h($url($page - 1)) . '">Previous</a></li>';
        for ($i = 1; $i <= $pages; $i++) {
            echo '<li class="page-item ' . ($i === $page ? 'active' : '') . '"><a class="page-link" href="' . h($url($i)) . '">' . $i . '</a></li>';
        }
        echo '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '"><a class="page-link" href="' . h($url($page + 1)) . '">Next</a></li></ul>';
    }
    echo '</div>';
}

function render_card($tab, $title, $subtitle, $icon, $iconCls, $res, $page, $pages, $total, $pkey) {
    global $limit, $active_tab;
    $sel = function ($v) use ($limit) { return ((string)$limit === (string)$v || ($v === 'all' && $limit === 0)) ? 'selected' : ''; };
    ?>
    <div class="tab-pane fade <?= $active_tab === $tab ? 'show active' : ''; ?>" id="<?= $tab; ?>-pane" role="tabpanel">
      <div class="management-card">
        <div class="management-card-header">
          <div class="management-title">
            <span class="title-icon <?= $iconCls; ?>"><i class="bi <?= $icon; ?>"></i></span>
            <div><h5><?= h($title); ?></h5><small><?= h($subtitle); ?></small></div>
          </div>
          <div class="header-controls-group">
            <div class="rows-per-page-box"><span>Show</span>
              <select onchange="changeLimit(this.value, '<?= $tab; ?>')">
                <?php foreach ([5, 10, 25, 50] as $n): ?><option value="<?= $n; ?>" <?= $sel($n); ?>><?= $n; ?></option><?php endforeach; ?>
                <option value="all" <?= $sel('all'); ?>>All</option>
              </select>
            </div>
            <div class="custom-search-input-box"><i class="bi bi-search"></i>
              <input type="text" placeholder="Search supervisor..." onkeyup="filterTable(this, '<?= $tab; ?>Body')">
            </div>
          </div>
        </div>
        <div class="management-table-wrapper"><div class="table-responsive">
          <table class="table management-table align-middle">
            <thead><tr><th>Supervisor</th><th>NIC</th><th>Division</th><th>Phone</th><th>Email</th><th>Registered</th><th>Status</th><th class="text-center" width="150">Action</th></tr></thead>
            <tbody id="<?= $tab; ?>Body"><?php render_rows($res, $tab); ?></tbody>
          </table>
        </div></div>
        <?php render_pager($tab, $page, $pages, $total, $pkey); ?>
      </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Division Logins - Hayleys Fentons</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary: #da6913; --primary-dark: #bb4c14; --success: #16803a; --danger: #d9483c; --background: #F6F8F5; --border: #E4E9E2; --text: #0E1F14; }
        body { background: var(--background); font-family: 'Inter', sans-serif; color: var(--text); margin: 0; overflow-x: hidden; }
        .app-layout { display: flex; min-height: 100vh; }

        .sidebar-wrap { width: 100px; flex-shrink: 0; background: var(--background); display: flex; flex-direction: column; align-items: flex-start; justify-content: center; padding: 20px 0 20px 18px; position: sticky; top: 0; height: 100vh; z-index: 1000; transition: width .3s cubic-bezier(.4,0,.2,1); }
        .sidebar-wrap:hover { width: 240px; }
        .sidebar-logo { height: 60px; width: auto; object-fit: contain; margin-bottom: 25px; margin-left: -6px; transition: all .3s ease; }
        .sidebar-wrap:hover .sidebar-logo { margin-left: 45px; }
        .vertical-nav { background: linear-gradient(180deg, #ea580c 0%, #b45309 50%, #15803d 100%); width: 75px; height: calc(100vh - 120px); border-radius: 40px; display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 25px 0; box-shadow: 0 10px 25px rgba(0,0,0,.15); transition: width .3s cubic-bezier(.4,0,.2,1); overflow: hidden; white-space: nowrap; }
        .sidebar-wrap:hover .vertical-nav { width: 210px; align-items: flex-start; padding-left: 20px; padding-right: 20px; }
        .nav-links-group { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 15px; width: 100%; flex: 1; }
        .sidebar-wrap:hover .nav-links-group { align-items: flex-start; }
        .nav-icon-link { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; border-radius: 18px; text-decoration: none; transition: all .25s ease; gap: 15px; }
        .sidebar-wrap:hover .nav-icon-link { width: 100%; justify-content: flex-start; padding-left: 12px; border-radius: 14px; }
        .nav-text { display: none; font-size: .95rem; font-weight: 600; color: #fff; }
        .sidebar-wrap:hover .nav-text { display: inline-block; }
        .nav-icon-link:hover, .nav-icon-link.active { background: rgba(255,255,255,.35); color: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .logout-pill-btn { width: 50px; height: 50px; background: #a13d07; border-radius: 25px; display: flex; align-items: center; justify-content: center; color: #fff; text-decoration: none; box-shadow: inset 0 2px 4px rgba(0,0,0,.2); transition: all .3s ease; gap: 12px; }
        .sidebar-wrap:hover .logout-pill-btn { width: 100%; border-radius: 14px; justify-content: flex-start; padding-left: 14px; }
        .logout-pill-btn:hover { background: #873105; color: #fff; }

        .main-content-wrap { flex: 1; min-width: 0; padding: 25px 35px; box-sizing: border-box; }

        /* Top Welcome Box Styling */
        .welcome-top-box {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        }

        .welcome-left-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .greeting-title-area {
            font-family: 'Manrope', sans-serif;
            font-weight: 800;
            font-size: 1.35rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .animated-icon {
            display: inline-block;
            font-size: 1.25rem;
            animation: bounceIcon 1.5s infinite ease-in-out;
            transform-origin: bottom center;
        }

        @keyframes bounceIcon {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-4px) rotate(8deg); }
        }

        .greeting-sub-text {
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .welcome-right-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-pill-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            padding: 6px 12px;
            border-radius: 18px;
            color: #374151;
            font-size: 11px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .notification-btn {
            width: 38px;
            height: 38px;
            background: #fff5ed;
            border: 1px solid #fed7aa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ea580c;
            position: relative;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .notification-container { position: relative; }
        .notification-btn:hover { background: #ffedd5; }
        .notification-btn:focus-visible { outline: 3px solid rgba(234, 88, 12, .3); outline-offset: 2px; }
        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #ef4444;
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 4px;
            border-radius: 10px;
            border: 2px solid #fff;
        }
        .notification-panel {
            display: none;
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: min(390px, calc(100vw - 32px));
            max-height: min(480px, calc(100vh - 110px));
            overflow: hidden;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 18px 44px rgba(15, 23, 42, .18);
            z-index: 1100;
        }
        .notification-panel.show { display: flex; flex-direction: column; }
        .notification-panel-header { padding: 14px 16px 11px; border-bottom: 1px solid #eef0f2; }
        .notification-panel-header strong { display: block; color: #17211a; font-size: 14px; }
        .notification-panel-header span { color: #6b7280; font-size: 11px; }
        .notification-list { overflow-y: auto; }
        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 12px 16px;
            color: #1f2937;
            text-decoration: none;
            border-bottom: 1px solid #f1f2f3;
            transition: background .15s ease;
        }
        .notification-item:hover { background: #fff7ed; color: #1f2937; }
        .notification-item.is-unread { background: #fffaf5; }
        .notification-item-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            color: #c2410c;
            background: #fff0e4;
            font-size: 15px;
        }
        .notification-item-copy { min-width: 0; flex: 1; }
        .notification-item-copy strong { display: block; font-size: 12px; line-height: 1.4; }
        .notification-item-copy span { display: block; overflow: hidden; color: #667085; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
        .notification-item-copy time { display: block; margin-top: 3px; color: #98a1ad; font-size: 10px; }
        .notification-empty { padding: 24px 16px; color: #6b7280; font-size: 12px; text-align: center; }

        .section-title { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 1.6rem; color: #1a1a1a; }
        .section-subtitle { color: #6c757d; font-size: .9rem; }

        .mini-status-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 10px 14px; display: flex; align-items: center; gap: 12px; height: 100%; }
        .mini-card-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .c-orange .mini-card-icon { background: rgba(218,105,19,.12); color: var(--primary); }
        .c-green .mini-card-icon { background: rgba(22,128,58,.12); color: var(--success); }
        .c-red .mini-card-icon { background: rgba(217,72,60,.12); color: var(--danger); }
        .c-dark .mini-card-icon { background: rgba(14,31,20,.08); color: #333; }
        .mini-card-label { font-size: .72rem; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
        .mini-card-value { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 1.3rem; color: #1a1a1a; }

        .sub-tab { display: flex; align-items: center; gap: 6px; background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 6px 14px; font-weight: 600; font-size: .82rem; color: #555; }
        .sub-tab.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .sub-tab-count { background: rgba(0,0,0,.08); border-radius: 20px; padding: 1px 7px; font-size: .68rem; font-weight: 700; }
        .sub-tab.active .sub-tab-count { background: rgba(255,255,255,.25); }

        .management-card { background: #fff; border-radius: 16px; border: 1px solid var(--border); overflow: hidden; }
        .management-card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; padding: 16px 22px; border-bottom: 1px solid var(--border); }
        .management-title { display: flex; align-items: center; gap: 12px; }
        .title-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #fff; flex-shrink: 0; }
        .pending-title-icon { background: var(--primary); }
        .completed-title-icon { background: var(--success); }
        .management-title h5 { margin: 0; font-family: 'Manrope', sans-serif; font-weight: 700; font-size: 1rem; }
        .management-title small { color: #6c757d; }
        .header-controls-group { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .rows-per-page-box { display: flex; align-items: center; gap: 6px; font-size: .82rem; color: #6c757d; }
        .rows-per-page-box select { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 4px 10px; font-size: .82rem; outline: none; }
        .custom-search-input-box { position: relative; width: 240px; }
        .custom-search-input-box input { width: 100%; background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 7px 14px 7px 36px; font-size: .85rem; outline: none; }
        .custom-search-input-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(218,105,19,.1); }
        .custom-search-input-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #888; }

        .management-table thead th { background: #111827; color: #fff; font-size: .8rem; font-weight: 600; padding: 12px 16px; border: none; white-space: nowrap; }
        .management-table thead th:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
        .management-table thead th:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
        .management-table-wrapper { padding: 15px; }
        .management-table tbody td { padding: 10px 16px; font-size: .84rem; vertical-align: middle; border-bottom: 1px solid var(--border); }
        .management-table tbody tr:last-child td { border-bottom: none; }

        .person-cell { display: flex; align-items: center; gap: 10px; }
        .person-avatar { width: 38px; height: 38px; border-radius: 10px; background: rgba(218,105,19,.12); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; }
        .person-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .person-name { font-weight: 700; color: #1a1a1a; font-size: .88rem; }

        .status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; }
        .status-pending { background: rgba(218,105,19,.12); color: var(--primary); }
        .status-approved { background: rgba(22,128,58,.12); color: var(--success); }
        .status-rejected { background: rgba(217,72,60,.12); color: var(--danger); }

        .action-btn-group { display: flex; align-items: center; justify-content: center; gap: 5px; }
        .act-btn { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--border); background: #fff; cursor: pointer; transition: all .2s; padding: 0; }
        .act-btn.view { color: var(--primary); }
        .act-btn.view:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        .act-btn.ok { color: var(--success); }
        .act-btn.ok:hover { background: var(--success); color: #fff; border-color: var(--success); }
        .act-btn.no { color: var(--danger); }
        .act-btn.no:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

        .empty-cell { text-align: center; padding: 45px 20px; color: #8a8a8a; }
        .empty-cell strong { display: block; color: #555; }
        .empty-icon { font-size: 2.2rem; margin-bottom: 10px; color: #ccc; }

        .pagination-container { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--border); }
        .pagination-info { font-size: .8rem; color: #6c757d; }
        .custom-pagination { display: flex; gap: 4px; list-style: none; margin: 0; padding: 0; }
        .custom-pagination .page-link { border: 1px solid var(--border); color: var(--text); padding: 4px 10px; font-size: .8rem; border-radius: 6px; text-decoration: none; display: block; }
        .custom-pagination .active .page-link { background: var(--primary); border-color: var(--primary); color: #fff; }
        .custom-pagination .disabled .page-link { color: #ccc; pointer-events: none; }

        @media (min-width: 1200px) { .modal-xl-custom { max-width: 900px !important; } }
        .vw-box { background: #fff; border-radius: 8px; padding: 9px 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); height: 100%; }
        .vw-label { display: block; color: #6c757d; font-size: 10.5px; margin-bottom: 2px; }
        .vw-value { font-weight: 700; color: #1a1a1a; font-size: 13px; word-break: break-word; }
        .vw-photo { max-height: 180px; object-fit: cover; }
    </style>
    <link rel="stylesheet" href="admin_sidebar.css">
</head>
<body class="bg-light">

<div class="app-layout">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <div class="main-content-wrap">
        
        <!-- Top White Box with Typing Greeting, Compliance Division, Date/Time & Notification Icon -->
        <div class="welcome-top-box">
            <div class="welcome-left-content">
                <div class="greeting-title-area">
                    <span id="greetingIconContainer"></span>
                    <span id="typingGreeting"></span>
                </div>
                <div class="greeting-sub-text">
                    <i class="bi bi-shield-check text-warning"></i> Compliance Division &bull; Manage permits and team activities efficiently
                </div>
            </div>

            <div class="welcome-right-controls">
                <div class="nav-pill-box">
                    <i class="bi bi-calendar3 text-warning"></i>
                    <span><?= date('D, d M Y'); ?></span>
                </div>
                <div class="nav-pill-box">
                    <i class="bi bi-clock-history text-warning"></i>
                    <span id="liveClock">Loading time...</span>
                </div>
                <div class="notification-container">
                    <button type="button" class="notification-btn" id="notificationButton" title="Notifications" aria-label="Notifications" aria-expanded="false" aria-controls="notificationPanel">
                        <i class="bi bi-bell-fill fs-5"></i>
                        <span class="notification-badge" id="notificationBadge" hidden></span>
                    </button>
                    <section class="notification-panel" id="notificationPanel" aria-label="Recent admin updates">
                        <div class="notification-panel-header">
                            <strong>Admin updates</strong>
                            <span>Recent permits, inspections and registrations</span>
                        </div>
                        <div class="notification-list" id="notificationList" aria-live="polite">
                            <div class="notification-empty">Loading updates...</div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <div class="container-fluid my-2 px-0">
            <div class="mb-3">
                <h2 class="section-title mb-1">Division Logins</h2>
                <p class="section-subtitle mb-0">Review, approve or reject divisional supervisor registrations.</p>
            </div>

            <!-- Summary cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6"><div class="mini-status-card c-orange"><div class="mini-card-icon"><i class="bi bi-hourglass-split"></i></div><div><div class="mini-card-label">Pending</div><div class="mini-card-value"><?= $pending_total; ?></div></div></div></div>
                <div class="col-md-3 col-6"><div class="mini-status-card c-green"><div class="mini-card-icon"><i class="bi bi-check-circle-fill"></i></div><div><div class="mini-card-label">Approved</div><div class="mini-card-value"><?= $approved_total; ?></div></div></div></div>
                <div class="col-md-3 col-6"><div class="mini-status-card c-red"><div class="mini-card-icon"><i class="bi bi-x-circle-fill"></i></div><div><div class="mini-card-label">Rejected</div><div class="mini-card-value"><?= $rejected_total; ?></div></div></div></div>
                <div class="col-md-3 col-6"><div class="mini-status-card c-dark"><div class="mini-card-icon"><i class="bi bi-person-badge-fill"></i></div><div><div class="mini-card-label">Total Supervisors</div><div class="mini-card-value"><?= $pending_total + $completed_total; ?></div></div></div></div>
            </div>

            <!-- Tabs -->
            <div class="nav nav-pills mb-3 gap-2" role="tablist">
                <button class="sub-tab <?= $active_tab === 'pending' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#pending-pane" type="button" role="tab">
                    <i class="bi bi-hourglass-split"></i><span>Pending Requests</span><span class="sub-tab-count"><?= $pending_total; ?></span>
                </button>
                <button class="sub-tab <?= $active_tab === 'completed' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#completed-pane" type="button" role="tab">
                    <i class="bi bi-check2-circle"></i><span>Approved / Rejected</span><span class="sub-tab-count"><?= $completed_total; ?></span>
                </button>
            </div>

            <div class="tab-content">
                <?php
                render_card('pending', 'Pending Supervisor Requests', 'New registrations waiting for approval', 'bi-hourglass-split', 'pending-title-icon', $pending_rows, $p_page, $p_pages, $pending_total, 'p_page');
                render_card('completed', 'Approved & Rejected Supervisors', 'History of processed supervisor accounts', 'bi-check2-circle', 'completed-title-icon', $completed_rows, $c_page, $c_pages, $completed_total, 'c_page');
                ?>
            </div>
        </div>
    </div>
</div>

<!-- View modal -->
<div class="modal fade" id="viewSupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-xl-custom">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="border-top: 5px solid #e15e13 !important;">
            <div class="modal-header bg-white border-bottom position-relative px-4 py-3 d-flex flex-column align-items-center text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-1"><img src="assets/images/hayleys_logo.png" alt="Hayleys Logo" style="max-height: 40px; object-fit: contain;"></div>
                <h4 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.15rem;">Division Login Details</h4>
                <p class="text-muted mb-0" style="font-size: 11.5px;">Complete record for the selected supervisor.</p>
            </div>
            <div class="modal-body p-3 bg-light" style="max-height: 75vh; overflow-y: auto;">
                <div class="row g-2" id="supDetailGrid"></div>
            </div>
            <div class="modal-footer bg-white border-top px-4 py-2">
                <button type="button" class="btn btn-outline-secondary px-3 py-1" style="font-size: 12.5px;" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const SELF = <?= json_encode($self); ?>;

    // Live Clock Function
    function updateClock() {
        const now = new Date();
        let h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        m = m < 10 ? '0' + m : m;
        s = s < 10 ? '0' + s : s;
        const el = document.getElementById('liveClock');
        if (el) el.innerText = h + ':' + m + ':' + s + ' ' + ampm;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Dynamic Greeting Function
    function getGreetingConfig() {
        const hour = new Date().getHours();
        if (hour < 12) {
            return { text: "Good Morning !", color: "#16803a", icon: "☀️" };
        } else if (hour < 17) {
            return { text: "Good Afternoon !", color: "#ea580c", icon: "🌤️" };
        } else {
            return { text: "Good Evening !", color: "#2563eb", icon: "🌙" };
        }
    }

    const config = getGreetingConfig();
    const greetingText = config.text;
    let charIndex = 0;

    function typeWriter() {
        const typingElement = document.getElementById("typingGreeting");
        if (!typingElement) return;
        typingElement.style.color = config.color;

        if (charIndex < greetingText.length) {
            typingElement.innerHTML += greetingText.charAt(charIndex);
            charIndex++;
            setTimeout(typeWriter, 70);
        } else {
            const iconContainer = document.getElementById("greetingIconContainer");
            if (iconContainer) {
                iconContainer.innerHTML = `<span class="animated-icon">${config.icon}</span>`;
            }
        }
    }

    // Notification Panel Toggle & API Handler
    const notificationButton = document.getElementById('notificationButton');
    const notificationPanel = document.getElementById('notificationPanel');
    const notificationList = document.getElementById('notificationList');
    const notificationBadge = document.getElementById('notificationBadge');

    function formatNotificationTime(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';
        const minutes = Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
        if (minutes < 1) return 'Just now';
        if (minutes < 60) return `${minutes} min ago`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours} hr${hours === 1 ? '' : 's'} ago`;
        const days = Math.floor(hours / 24);
        if (days < 7) return `${days} day${days === 1 ? '' : 's'} ago`;
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }

    function renderNotifications(items) {
        if (!notificationList) return;
        notificationList.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'notification-empty';
            empty.textContent = 'No recent updates.';
            notificationList.appendChild(empty);
            return;
        }

        items.forEach((item) => {
            const link = document.createElement('a');
            link.className = `notification-item${item.is_unread ? ' is-unread' : ''}`;
            link.href = item.href;

            const icon = document.createElement('span');
            icon.className = 'notification-item-icon';
            const iconElement = document.createElement('i');
            iconElement.className = `bi ${item.icon}`;
            icon.appendChild(iconElement);

            const copy = document.createElement('span');
            copy.className = 'notification-item-copy';
            const title = document.createElement('strong');
            title.textContent = item.title;
            const detail = document.createElement('span');
            detail.textContent = item.detail;
            const time = document.createElement('time');
            time.dateTime = item.timestamp;
            time.textContent = formatNotificationTime(item.timestamp);
            copy.append(title, detail, time);
            link.append(icon, copy);
            notificationList.appendChild(link);
        });
    }

    async function loadNotifications(markRead = false) {
        try {
            const url = `admin_notifications.php${markRead ? '?mark_read=1' : ''}`;
            const response = await fetch(url, { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Notification request failed');
            const data = await response.json();
            const count = Number(data.unread_count) || 0;
            if (notificationBadge) {
                notificationBadge.textContent = count > 9 ? '9+' : String(count);
                notificationBadge.hidden = count === 0;
            }
            renderNotifications(data.items || []);
        } catch (error) {
            if (notificationList && (!notificationList.children.length || notificationList.querySelector('.notification-empty'))) {
                const empty = document.createElement('div');
                empty.className = 'notification-empty';
                empty.textContent = 'Updates could not be loaded.';
                notificationList.replaceChildren(empty);
            }
        }
    }

    if (notificationButton && notificationPanel) {
        notificationButton.addEventListener('click', () => {
            const isOpening = !notificationPanel.classList.contains('show');
            notificationPanel.classList.toggle('show', isOpening);
            notificationButton.setAttribute('aria-expanded', String(isOpening));
            if (isOpening) loadNotifications(true);
        });
    }

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.notification-container') && notificationPanel) {
            notificationPanel.classList.remove('show');
            if (notificationButton) notificationButton.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && notificationPanel) {
            notificationPanel.classList.remove('show');
            if (notificationButton) {
                notificationButton.setAttribute('aria-expanded', 'false');
                notificationButton.focus();
            }
        }
    });

    loadNotifications();
    setInterval(() => loadNotifications(), 60000);

    window.onload = function() {
        updateClock();
        typeWriter();
    };

    function changeLimit(val, tab) {
        const p = new URLSearchParams(window.location.search);
        p.set('tab', tab); p.set('limit', val);
        p.delete('p_page'); p.delete('c_page');
        window.location.href = SELF + '?' + p.toString();
    }

    function filterTable(input, tbodyId) {
        const f = input.value.toLowerCase();
        document.querySelectorAll('#' + tbodyId + ' tr').forEach(r => {
            r.style.display = r.textContent.toLowerCase().indexOf(f) > -1 ? '' : 'none';
        });
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = (str === null || str === undefined) ? '' : String(str);
        return d.innerHTML;
    }
    function labelOf(key) { return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }

    document.addEventListener('DOMContentLoaded', function () {
        const modal = new bootstrap.Modal(document.getElementById('viewSupModal'));
        const grid = document.getElementById('supDetailGrid');

        // View details
        document.querySelectorAll('.js-view-sup').forEach(btn => {
            btn.addEventListener('click', function () {
                let row;
                try { row = JSON.parse(this.getAttribute('data-row')); } catch (e) { return; }
                let html = '';
                Object.keys(row).forEach(key => {
                    if (key.toLowerCase() === 'id') return;
                    const val = row[key];
                    const isImage = /pic|photo|image/i.test(key);
                    const inner = (isImage && val)
                        ? '<img src="' + esc(val) + '" alt="' + esc(labelOf(key)) + '" class="img-thumbnail vw-photo">'
                        : '<span class="vw-value">' + ((val !== null && String(val).trim() !== '') ? esc(val) : '-') + '</span>';
                    html += '<div class="col-md-4"><div class="vw-box"><span class="vw-label">' + esc(labelOf(key)) + '</span>' + inner + '</div></div>';
                });
                grid.innerHTML = html;
                modal.show();
            });
        });

        // Approve / Reject confirmation
        document.querySelectorAll('.js-act-form').forEach(form => {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const accept = this.dataset.action === 'accept';
                Swal.fire({
                    title: accept ? 'Approve this supervisor?' : 'Reject this supervisor?',
                    text: accept ? 'They will be able to log in.' : 'They will not be able to log in.',
                    icon: accept ? 'question' : 'warning',
                    showCancelButton: true,
                    confirmButtonColor: accept ? '#16803a' : '#d9483c',
                    confirmButtonText: accept ? 'Yes, approve' : 'Yes, reject'
                }).then(r => { if (r.isConfirmed) form.submit(); });
            });
        });
    });
</script>
</body>
</html>