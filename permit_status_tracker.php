<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

$table_name = "service_provider_permits";
$id_column  = "permit_id";

/* =====================================================
   HOLD  (POST + hold reason)  -- Approved permits only
   Remaining time is saved into remaining_seconds
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hold' && isset($_POST['id'])) {
    $permit_id   = intval($_POST['id']);
    $hold_reason = trim($_POST['hold_reason'] ?? '');

    if ($hold_reason !== '') {
        $chk = $conn->prepare("SELECT status,
                          GREATEST(TIMESTAMPDIFF(SECOND, NOW(), expiry_date), 0) AS remaining_seconds
                     FROM `$table_name`
                    WHERE `$id_column` = ?");
        $chk->bind_param("i", $permit_id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($row && strtolower(trim($row['status'])) === 'approved') {
            $rem_secs = max(0, (int)($row['remaining_seconds'] ?? 0));

            $stmt = $conn->prepare("UPDATE `$table_name`
                SET status = 'Hold', remaining_seconds = ?, hold_reason = ?, status_updated_at = NOW()
                WHERE `$id_column` = ?");
            $stmt->bind_param("isi", $rem_secs, $hold_reason, $permit_id);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed > 0) {
                $old_status = $row['status'];
                $new_status = 'Hold';
                $changed_by = 'admin:' . ($_SESSION['admin_name'] ?? 'Administrator');
                $log = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())");
                if ($log) {
                    $log->bind_param("issss", $permit_id, $old_status, $new_status, $hold_reason, $changed_by);
                    $log->execute();
                    $log->close();
                }
            }
        }
    }

    header("Location: permit_status_tracker.php");
    exit();
}

/* =====================================================
   UNHOLD  (GET)  -- Hold permits only
   New expiry = NOW() + remaining_seconds
   ===================================================== */
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'unhold') {
    $permit_id = intval($_GET['id']);

    $chk = $conn->prepare("SELECT remaining_seconds, hold_reason, status_updated_at FROM `$table_name` WHERE `$id_column` = ? AND status = 'Hold'");
    $chk->bind_param("i", $permit_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($row) {
        $rem_secs = intval($row['remaining_seconds'] ?? 0);
        $hold_reason = trim((string)($row['hold_reason'] ?? ''));

        if ($hold_reason !== '') {
            $history_check = $conn->prepare("SELECT log_id FROM permit_status_log WHERE permit_id = ? AND LOWER(new_status) LIKE '%hold%' AND reason = ? LIMIT 1");
            $history_check->bind_param("is", $permit_id, $hold_reason);
            $history_check->execute();
            $has_history = $history_check->get_result()->fetch_assoc();
            $history_check->close();

            if (!$has_history) {
                $old_status = 'Approved';
                $new_status = 'Hold';
                $changed_by = 'legacy:unknown';
                $changed_at = $row['status_updated_at'] ?? null;
                $history_insert = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, COALESCE(?, NOW()))");
                $history_insert->bind_param("isssss", $permit_id, $old_status, $new_status, $hold_reason, $changed_by, $changed_at);
                $history_insert->execute();
                $history_insert->close();
            }
        }

        if ($rem_secs > 0) {
            $stmt = $conn->prepare("UPDATE `$table_name`
                SET status = 'Approved',
                    expiry_date = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    remaining_seconds = 0,
                    hold_reason = NULL,
                    status_updated_at = NOW()
                WHERE `$id_column` = ?");
            $stmt->bind_param("ii", $rem_secs, $permit_id);
        } else {
            // Old hold records that have no saved remaining time
            $stmt = $conn->prepare("UPDATE `$table_name`
                SET status = 'Pending', hold_reason = NULL, status_updated_at = NOW()
                WHERE `$id_column` = ?");
            $stmt->bind_param("i", $permit_id);
        }
        $stmt->execute();
        $stmt->close();
    }

    header("Location: permit_status_tracker.php");
    exit();
}

/* =====================================================
   APPROVE  (GET)
   ===================================================== */
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'approve') {
    $permit_id = intval($_GET['id']);

    $chk = $conn->prepare("SELECT remaining_seconds, work_category FROM `$table_name` WHERE `$id_column` = ?");
    $chk->bind_param("i", $permit_id);
    $chk->execute();
    $res = $chk->get_result();
    $rem_secs = 0;
    $work_cat = 'normal';
    if ($row = $res->fetch_assoc()) {
        $rem_secs = intval($row['remaining_seconds'] ?? 0);
        if (isset($row['work_category'])) {
            $work_cat = strtolower(trim($row['work_category']));
        }
    }
    $chk->close();

    if ($rem_secs > 0) {
        $stmt = $conn->prepare("UPDATE `$table_name` SET status = 'Approved', expiry_date = DATE_ADD(NOW(), INTERVAL ? SECOND), remaining_seconds = 0, rejection_reason = NULL, hold_reason = NULL, status_updated_at = NOW() WHERE `$id_column` = ?");
        if ($stmt === false) {
            die("SQL Prepare Failed (resume): " . $conn->error);
        }
        $stmt->bind_param("ii", $rem_secs, $permit_id);
    } else {
        $is_twelve_hours = ($work_cat === 'hot_work' || $work_cat === 'work_at_height');

        if ($is_twelve_hours) {
            $stmt = $conn->prepare("UPDATE `$table_name` SET status = 'Approved', expiry_date = DATE_ADD(NOW(), INTERVAL 12 HOUR), remaining_seconds = 0, rejection_reason = NULL, hold_reason = NULL, status_updated_at = NOW() WHERE `$id_column` = ?");
        } else {
            $stmt = $conn->prepare("UPDATE `$table_name` SET status = 'Approved', expiry_date = DATE_ADD(NOW(), INTERVAL 12 DAY), remaining_seconds = 0, rejection_reason = NULL, hold_reason = NULL, status_updated_at = NOW() WHERE `$id_column` = ?");
        }

        if ($stmt === false) {
            die("SQL Prepare Failed (new approval): " . $conn->error);
        }
        $stmt->bind_param("i", $permit_id);
    }

    $stmt->execute();
    $stmt->close();

    header("Location: permit_status_tracker.php");
    exit();
}

// Auto-expire (Approved only, so Hold permits never expire while on hold)
$conn->query("UPDATE service_provider_permits SET status = 'Expired' WHERE status = 'Approved' AND expiry_date IS NOT NULL AND expiry_date < NOW()");

/* =====================================================
   Helper functions
   ===================================================== */
function formatDuration($secs) {
    $secs  = max(0, intval($secs));
    $hours = floor($secs / 3600);
    $days  = floor($hours / 24);
    if ($days >= 1) {
        return $days . "d " . ($hours % 24) . "h";
    }
    $mins = floor(($secs % 3600) / 60);
    return $hours . "h " . $mins . "m";
}

function renderExpiryCell($status, $expiry_date, $remaining_seconds = 0) {
    $status_lower = strtolower(trim($status));

    // Hold: show the frozen remaining time
    if ($status_lower === 'hold' || $status_lower === 'on hold') {
        if (intval($remaining_seconds) > 0) {
            return "<td>
                        <span class='status-pill status-hold'><i class='bi bi-pause-circle-fill'></i> " . htmlspecialchars(formatDuration($remaining_seconds)) . " paused</span>
                    </td>";
        }
        return "<td><span class='info-text'>-</span></td>";
    }

    $no_expiry_statuses = ['pending', 'rejected', 'completed', 'complete'];

    if (empty($expiry_date) || in_array($status_lower, $no_expiry_statuses, true)) {
        return "<td><span class='info-text'>-</span></td>";
    }

    $expiryTs = strtotime($expiry_date);
    $nowTs    = time();
    $niceDate = date('Y-m-d h:i A', $expiryTs);

    if ($status_lower === 'expired' || $expiryTs < $nowTs) {
        return "<td>
                    <span class='status-pill status-rejected'><i class='bi bi-hourglass-bottom'></i> Expired</span>
                    <div class='expiry-date-text'>" . htmlspecialchars($niceDate) . "</div>
                </td>";
    }

    $remaining = formatDuration($expiryTs - $nowTs) . " left";

    return "<td>
                <span class='status-pill status-approved'><i class='bi bi-clock-history'></i> " . htmlspecialchars($remaining) . "</span>
                <div class='expiry-date-text'>" . htmlspecialchars($niceDate) . "</div>
            </td>";
}

function renderActionCell($viewUrl, $printUrl, $holdId, $unholdUrl, $status) {
    $html  = "<td class='text-center'><div class='action-btn-group'>";

    // View
    $html .= "<a href='javascript:void(0)' class='view-permit-btn' data-url='" . htmlspecialchars($viewUrl, ENT_QUOTES) . "' title='View'>
                <img src='assets/images/v.png' alt='View' width='15' height='15'>
              </a>";

    // Print (opens clean print page in a new tab)
    $html .= "<a href='" . htmlspecialchars($printUrl, ENT_QUOTES) . "' target='_blank' rel='noopener' class='print-btn' title='Print'>
                <i class='bi bi-printer-fill' style='font-size:15px;color:#1d4ed8;'></i>
              </a>";

    // Hold: only for approved permits
    if ($status === 'approved') {
        $html .= "<a href='javascript:void(0)' class='hold-btn' data-id='" . intval($holdId) . "' title='Hold'>
                    <img src='assets/images/h.png' alt='Hold' width='15' height='15' onerror=\"this.outerHTML='<i class=&quot;bi bi-pause-circle-fill&quot; style=&quot;font-size:16px;color:#da6913;&quot;></i>'\">
                  </a>";
    }

    // Unhold: only for hold permits
    if ($status === 'hold' || $status === 'on hold') {
        $html .= "<a href='" . htmlspecialchars($unholdUrl, ENT_QUOTES) . "' class='unhold-confirm-btn' title='Unhold'>
                    <i class='bi bi-play-circle-fill' style='font-size:16px;color:#16803a;'></i>
                  </a>";
    }

    $html .= "</div></td>";
    return $html;
}

function getStatusBadge($status, $hold_reason = '') {
    $status_lower = strtolower(trim($status));
    if ($status_lower === 'completed' || $status_lower === 'complete') {
        return "<span class='status-pill status-completed'><i class='bi bi-check2-all'></i> Completed</span>";
    }
    if ($status_lower === 'approved') {
        return "<span class='status-pill status-approved'><i class='bi bi-check-circle-fill'></i> Approved</span>";
    }
    if ($status_lower === 'hold' || $status_lower === 'on hold') {
        $html = "<span class='status-pill status-hold'><i class='bi bi-pause-circle-fill'></i> Hold</span>";
        if (!empty($hold_reason)) {
            $html .= "<span class='hold-reason-text'><b>Reason:</b> " . htmlspecialchars($hold_reason) . "</span>";
        }
        return $html;
    }
    if ($status_lower === 'expired') {
        return "<span class='status-pill status-rejected'><i class='bi bi-hourglass-bottom'></i> Expired</span>";
    }
    if (strpos($status_lower, 'rejected') !== false) {
        $label = ucwords($status_lower);
        return "<span class='status-pill status-rejected'><i class='bi bi-x-circle-fill'></i> " . htmlspecialchars($label) . "</span>";
    }
    return "<span class='status-pill status-pending'><i class='bi bi-clock-fill'></i> Pending</span>";
}

/* =====================================================
   Load permits + summary counts
   ===================================================== */
$permits = [];
$cnt_total = $cnt_pending = $cnt_approved = $cnt_hold = 0;

$serviceResult = $conn->query("SELECT * FROM service_provider_permits ORDER BY permit_id DESC");
if ($serviceResult) {
    while ($r = $serviceResult->fetch_assoc()) {
        $permits[] = $r;
        $s = strtolower(trim($r['status'] ?? 'pending'));
        $cnt_total++;
        if ($s === 'approved') {
            $cnt_approved++;
        } elseif ($s === 'hold' || $s === 'on hold') {
            $cnt_hold++;
        } elseif (strpos($s, 'pending') !== false || $s === '') {
            $cnt_pending++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit Status Tracker | Admin Panel</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #da6913;
            --primary-dark: #bb4c14;
            --success: #16803a;
            --danger: #d9483c;
            --background: #F6F8F5;
            --surface: #FFFFFF;
            --border: #E4E9E2;
            --text: #0E1F14;
        }
        body {
            background: var(--background);
            font-family: 'Inter', sans-serif;
            color: var(--text);
            margin: 0;
            overflow-x: hidden;
        }

        /* Layout with Flex Sidebar */
        .app-layout {
            display: flex;
            min-height: 100vh;
        }

        /* Left Vertical Sidebar styling - Fixed flex behavior so it pushes content */
        .sidebar-wrap {
            width: 100px;
            flex-shrink: 0;
            background: var(--background);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            padding: 20px 0px 20px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 1000;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-wrap:hover {
            width: 240px;
        }

        .sidebar-logo {
            height: 60px;
            width: auto;
            object-fit: contain;
            margin-bottom: 25px;
            margin-left: -6px;
            transition: all 0.3s ease;
        }

        .sidebar-wrap:hover .sidebar-logo {
            margin-left: 45px;
        }

        /* Expandable Vertical Nav Bar */
        .vertical-nav {
            background: linear-gradient(180deg, #ea580c 0%, #b45309 50%, #15803d 100%);
            width: 75px;
            height: calc(100vh - 120px);
            border-radius: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 25px 0;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            white-space: nowrap;
        }

        .sidebar-wrap:hover .vertical-nav {
            width: 210px;
            align-items: flex-start;
            padding-left: 20px;
            padding-right: 20px;
        }

        .nav-links-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 15px;
            width: 100%;
            flex: 1;
        }

        .sidebar-wrap:hover .nav-links-group {
            align-items: flex-start;
        }

        .nav-icon-link {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 20px;
            border-radius: 18px;
            text-decoration: none;
            transition: all 0.25s ease;
            position: relative;
            gap: 15px;
        }

        .sidebar-wrap:hover .nav-icon-link {
            width: 100%;
            justify-content: flex-start;
            padding-left: 12px;
            border-radius: 14px;
        }

        .nav-text {
            display: none;
            font-size: 0.95rem;
            font-weight: 600;
            color: #ffffff;
        }

        .sidebar-wrap:hover .nav-text {
            display: inline-block;
        }

        .nav-icon-link:hover, .nav-icon-link.active {
            background: rgba(255, 255, 255, 0.35);
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .logout-pill-btn {
            width: 50px;
            height: 50px;
            background: #a13d07;
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-decoration: none;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            gap: 12px;
        }

        .sidebar-wrap:hover .logout-pill-btn {
            width: 100%;
            border-radius: 14px;
            justify-content: flex-start;
            padding-left: 14px;
        }

        .logout-pill-btn:hover {
            background: #873105;
            color: #fff;
        }

        /* Main Content Wrapper - Flex auto adjust */
        .main-content-wrap {
            flex: 1;
            min-width: 0;
            padding: 25px 35px;
            box-sizing: border-box;
        }

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

        /* HEADER */
        .section-title {
            font-family: 'Manrope', sans-serif;
            font-weight: 800; font-size: 1.6rem; color: #1a1a1a;
        }
        .section-subtitle { color: #6c757d; font-size: 0.9rem; }

        .compact-back-btn {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff; border: 1px solid var(--border);
            padding: 8px 16px; border-radius: 10px; color: #333;
            font-weight: 600; font-size: 0.85rem; text-decoration: none;
            transition: all .2s;
        }
        .compact-back-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

        /* SUMMARY CARDS */
        .mini-status-card {
            background: #fff; border: 1px solid var(--border); border-radius: 14px;
            padding: 10px 14px; display: flex; align-items: center; gap: 12px; height: 100%;
        }
        .mini-card-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .pending-card .mini-card-icon  { background: rgba(218,105,19,.12); color: var(--primary); }
        .approved-card .mini-card-icon { background: rgba(22,128,58,.12); color: var(--success); }
        .hold-card .mini-card-icon     { background: rgba(255,193,7,.18); color: #856404; }
        .total-card .mini-card-icon    { background: rgba(14,31,20,.08); color: #333; }
        .mini-card-label {
            font-size: 0.72rem; color: #6c757d; font-weight: 600;
            text-transform: uppercase; letter-spacing: .3px;
        }
        .mini-card-value {
            font-family: 'Manrope', sans-serif; font-weight: 800;
            font-size: 1.3rem; color: #1a1a1a;
        }

        /* MANAGEMENT CARD & HEADER */
        .management-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .management-card-header {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 15px; padding: 18px 22px; border-bottom: 1px solid var(--border);
        }
        .management-title { display: flex; align-items: center; gap: 12px; }
        .title-icon {
            width: 42px; height: 42px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: #fff; flex-shrink: 0;
            background: var(--primary);
        }
        .management-title h5 { margin: 0; font-family: 'Manrope', sans-serif; font-weight: 700; font-size: 1rem; }
        .management-title small { color: #6c757d; }

        /* Filters + search in card header */
        .header-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-select {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 7px 30px 7px 12px;
            font-size: 0.85rem;
            color: var(--text);
            outline: none;
            height: 36px;
        }
        .filter-select:focus, .custom-search-input-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(218, 105, 19, 0.1);
        }
        .custom-search-input-box { position: relative; width: 240px; }
        .custom-search-input-box input {
            width: 100%;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 7px 14px 7px 36px;
            font-size: 0.85rem;
            color: var(--text);
            outline: none;
            transition: all 0.2s;
            height: 36px;
        }
        .custom-search-input-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 0.95rem;
        }

        /* TABLE */
        .management-table thead th {
            background: #111827 !important; color: #fff !important; font-size: 0.72rem;
            font-weight: 600; padding: 8px 10px !important; border: none;
            text-transform: none;
        }
        .management-table thead th:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
        .management-table thead th:last-child  { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
        .management-table-wrapper { padding: 15px; }
        .management-table tbody td {
            padding: 6px 10px !important; font-size: 0.75rem;
            vertical-align: middle; border-bottom: 1px solid var(--border);
        }
        .management-table tbody tr:last-child td { border-bottom: none; }
        .management-table tbody tr:hover { background: #fff8f2; }

        .info-text { color: #444; font-size: 0.75rem; }
        .permit-no { font-weight: 700; color: #1a1a1a; white-space: nowrap; }
        .expiry-date-text { color: #6c757d; font-size: 0.68rem; margin-top: 2px; }
        .hold-reason-text { display: block; margin-top: 3px; font-size: 10px; color: #856404; max-width: 200px; white-space: normal; }

        .company-cell { display: flex; align-items: center; gap: 8px; }
        .company-name { font-weight: 700; color: #1a1a1a; font-size: 0.75rem; line-height: 1.25; }

        /* PILLS */
        .status-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 20px; font-size: 0.68rem; font-weight: 700;
            white-space: nowrap;
        }
        .status-pending   { background: rgba(218,105,19,.12); color: var(--primary); }
        .status-approved  { background: rgba(22,128,58,.12);  color: var(--success); }
        .status-rejected  { background: rgba(217,72,60,.12);  color: var(--danger); }
        .status-hold      { background: #fff3cd; color: #856404; }
        .status-completed { background: rgba(37,99,235,.12);  color: #1d4ed8; }
        .type-hot         { background: rgba(217,72,60,.12);  color: var(--danger); }
        .type-height      { background: rgba(218,105,19,.12); color: var(--primary-dark); }
        .type-general     { background: #e0f2fe; color: #0284c7; }

        /* ACTION BUTTONS */
        .action-btn-group { display: flex; align-items: center; justify-content: center; gap: 5px; }
        .action-btn-group a {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 7px;
            border: 1px solid var(--border); background: #fff;
            text-decoration: none !important; transition: all .2s;
        }
        .action-btn-group a:hover { background: rgba(218,105,19,.1); border-color: var(--primary); }
        .action-btn-group a.print-btn:hover { background: rgba(29,78,216,.1); border-color: #1d4ed8; }
        .action-btn-group img { object-fit: contain; }

        /* DataTables pagination / info */
        .dataTables_wrapper .dataTables_info { font-size: 0.8rem; color: #6c757d; padding-top: 12px; }
        .page-link { color: var(--primary); }
        .page-item.active .page-link { background: var(--primary); border-color: var(--primary); color: #fff; }

        /* VIEW MODAL */
        .modal-backdrop.show {
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            background-color: rgba(14,31,20,.55);
            opacity: 1 !important;
        }
        #viewPermitModal .modal-content { border-radius: 16px; border: 1px solid var(--border); overflow: hidden; }
        #viewPermitModal .modal-header {
            background: linear-gradient(90deg, #ea580c 0%, #a3810e 50%, #16803a 100%);
            color: #fff; border-bottom: none; padding: 14px 20px;
        }
        #viewPermitModal .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        #viewPermitModal .modal-body { padding: 0; height: 78vh; position: relative; }
        #viewPermitModal iframe { width: 100%; height: 100%; border: none; display: block; }
        #viewPermitModal .modal-loading {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            display: flex; align-items: center; justify-content: center;
            background: #fff; font-family: 'Manrope', sans-serif;
            color: var(--primary-dark); font-weight: 700; gap: 10px;
        }
    </style>
    <link rel="stylesheet" href="admin_sidebar.css">
</head>
<body class="bg-light">

<div class="app-layout">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <!-- Main Content Area -->
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

            <!-- PAGE HEADER -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <div>
                    <h2 class="section-title mb-1">Permit Status Tracker</h2>
                    <p class="section-subtitle mb-0">Monitor, approve, hold, print, or reject service provider permits.</p>
                </div>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="mini-status-card total-card">
                        <div class="mini-card-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                        <div>
                            <div class="mini-card-label">Total Permits</div>
                            <div class="mini-card-value"><?= $cnt_total; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="mini-status-card pending-card">
                        <div class="mini-card-icon"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <div class="mini-card-label">Pending</div>
                            <div class="mini-card-value"><?= $cnt_pending; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="mini-status-card approved-card">
                        <div class="mini-card-icon"><i class="bi bi-check-circle-fill"></i></div>
                        <div>
                            <div class="mini-card-label">Approved</div>
                            <div class="mini-card-value"><?= $cnt_approved; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="mini-status-card hold-card">
                        <div class="mini-card-icon"><i class="bi bi-pause-circle-fill"></i></div>
                        <div>
                            <div class="mini-card-label">On Hold</div>
                            <div class="mini-card-value"><?= $cnt_hold; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PERMITS MANAGEMENT CARD -->
            <div class="management-card">
                <div class="management-card-header">
                    <div class="management-title">
                        <span class="title-icon"><i class="bi bi-file-earmark-text"></i></span>
                        <div>
                            <h5>Permits Management List</h5>
                            <small>All submitted permits and their approval status</small>
                        </div>
                    </div>

                    <div class="header-controls">
                        <select id="entriesLength" class="filter-select">
                            <option value="5" selected>Show 5</option>
                            <option value="10">Show 10</option>
                            <option value="15">Show 15</option>
                            <option value="20">Show 20</option>
                            <option value="-1">Show All</option>
                        </select>

                        <select id="typeFilter" class="filter-select">
                            <option value="all">All Permit Types</option>
                            <option value="Hot Work">Hot Work</option>
                            <option value="Work at Height">Work at Height</option>
                            <option value="General Permit">General Permit</option>
                        </select>

                        <select id="statusFilter" class="filter-select">
                            <option value="all">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="complete">Complete</option>
                            <option value="expired">Expired</option>
                            <option value="hold">Hold</option>
                            <option value="pending third party">Pending Third Party</option>
                            <option value="third party approved">Third Party Approved</option>
                            <option value="third party rejected">Third Party Rejected</option>
                        </select>

                        <div class="custom-search-input-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="permitSearch" placeholder="Search permit...">
                        </div>
                    </div>
                </div>

                <div class="management-table-wrapper">
                    <div class="table-responsive">
                        <table id="permitTable" class="table management-table align-middle">
                            <thead>
                                <tr>
                                    <th>Permit Type</th>
                                    <th>Permit Number</th>
                                    <th>Project / Company Name</th>
                                    <th>Work Location / Details</th>
                                    <th>Contact Number</th>
                                    <th>Status</th>
                                    <th>Expiry</th>
                                    <th class="text-center" width="140">Action</th>
                                </tr>
                            </thead>
                            <tbody>
<?php
foreach ($permits as $row) {
    $status        = strtolower(trim($row['status'] ?? 'pending'));
    $work_category = strtolower(trim($row['work_category'] ?? 'normal'));

    if ($work_category === 'hot_work') {
        $permitTypeLabel = 'Hot Work';
        $badgeHtml = "<span class='status-pill type-hot'>Hot Work</span>";
    } elseif ($work_category === 'work_at_height') {
        $permitTypeLabel = 'Work at Height';
        $badgeHtml = "<span class='status-pill type-height'> Work at Height</span>";
    } else {
        $permitTypeLabel = 'General Permit';
        $badgeHtml = "<span class='status-pill type-general'> General </span>";
    }

    echo "<tr data-permit-type='" . htmlspecialchars($permitTypeLabel) . "' data-status='" . htmlspecialchars($status) . "'>";
    echo "<td>" . $badgeHtml . "</td>";
    echo "<td><span class='permit-no'>" . htmlspecialchars($row['permit_number'] ?? '') . "</span></td>";
    echo "<td>
            <div class='company-cell'>
                <div class='company-name'>" . htmlspecialchars($row['cat_project'] ?? '') . "</div>
            </div>
          </td>";
    echo "<td><span class='info-text'>" . htmlspecialchars($row['cat_work_location'] ?? '') . "</span></td>";
    echo "<td><span class='info-text'>" . htmlspecialchars($row['contact_number'] ?? '') . "</span></td>";
    echo "<td>" . getStatusBadge($status, $row['hold_reason'] ?? '') . "</td>";
    echo renderExpiryCell($status, $row['expiry_date'] ?? null, $row['remaining_seconds'] ?? 0);
    echo renderActionCell(
        "view_service_provider.php?id=" . urlencode($row['permit_id']),
        "print_permit.php?id=" . urlencode($row['permit_id']),
        $row['permit_id'],
        "permit_status_tracker.php?action=unhold&id=" . urlencode($row['permit_id']),
        $status
    );
    echo "</tr>";
}
?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- VIEW PERMIT MODAL -->
<div class="modal fade" id="viewPermitModal" tabindex="-1" aria-labelledby="viewPermitModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewPermitModalLabel"><i class="bi bi-file-earmark-text"></i> Permit Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="modal-loading" id="viewPermitLoading">
            <div class="spinner-border" role="status"></div>
            <span>Loading permit details...</span>
        </div>
        <iframe id="viewPermitFrame" src="" title="Permit Details"></iframe>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function(){
    let permitTable = $('#permitTable').DataTable({
        pageLength: 5,
        lengthMenu: [[5,10,15,20,-1],[5,10,15,20,"All"]],
        ordering: true,
        searching: true,
        dom: 'rtip'
    });

    $.fn.dataTable.ext.search.push(function(settings, searchData, index){
        if(settings.nTable.id !== "permitTable") return true;
        let row = permitTable.row(index).node();
        if(!row) return true;

        let rowType = $(row).data("permitType");
        let rowStatus = $(row).data("status");
        let type = $("#typeFilter").val();
        let status = $("#statusFilter").val();

        return (type === "all" || rowType === type) && (status === "all" || rowStatus === status);
    });

    $("#permitSearch").on("keyup", function(){
        permitTable.search(this.value).draw();
    });

    $("#typeFilter, #statusFilter").on("change", function(){
        permitTable.draw();
    });

    $("#entriesLength").on("change", function(){
        permitTable.page.len(parseInt(this.value)).draw();
    });

    // HOLD (asks for a reason)
    $(document).on("click", ".hold-btn", function(e){
        e.preventDefault();
        let permitId = $(this).data("id");

        Swal.fire({
            title: 'Hold this permit',
            input: 'textarea',
            inputLabel: 'Hold reason',
            inputPlaceholder: 'Enter the reason for holding...',
            showCancelButton: true,
            confirmButtonText: 'Hold',
            confirmButtonColor: '#da6913',
            cancelButtonColor: '#d33',
            inputValidator: (value) => {
                if (!value || !value.trim()) return 'Hold reason is required!';
            }
        }).then((result) => {
            if (result.isConfirmed) {
                let form = $('<form method="POST" action="permit_status_tracker.php"></form>');
                form.append($('<input type="hidden" name="action" value="hold">'));
                form.append($('<input type="hidden" name="id">').val(permitId));
                form.append($('<input type="hidden" name="hold_reason">').val(result.value.trim()));
                $('body').append(form);
                form.submit();
            }
        });
    });

    // UNHOLD (confirm)
    $(document).on("click", ".unhold-confirm-btn", function(e){
        e.preventDefault();
        let targetUrl = $(this).attr("href");

        Swal.fire({
            title: 'Unhold this permit?',
            text: "The permit will be approved again and the remaining time will continue.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Unhold',
            confirmButtonColor: '#16803a',
            cancelButtonColor: '#d33'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = targetUrl;
            }
        });
    });

    // VIEW permit in modal
    $(document).on("click", ".view-permit-btn", function(e){
        e.preventDefault();
        let url = $(this).data("url");

        $("#viewPermitLoading").show();
        $("#viewPermitFrame").css("visibility", "hidden").attr("src", url);

        new bootstrap.Modal(document.getElementById('viewPermitModal')).show();
    });

    $("#viewPermitFrame").on("load", function(){
        $("#viewPermitLoading").hide();
        $(this).css("visibility", "visible");
    });

    $('#viewPermitModal').on('hidden.bs.modal', function () {
        $("#viewPermitFrame").attr("src", "").css("visibility", "hidden");
    });
});

// Live Clock Function
function updateClock() {
    const now = new Date();
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const clockEl = document.getElementById('liveClock');
    if (clockEl) {
        clockEl.textContent = `${String(hours).padStart(2, '0')}:${minutes}:${seconds} ${ampm}`;
    }
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
</script>

</body>
</html>