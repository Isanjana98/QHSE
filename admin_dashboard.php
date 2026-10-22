<?php
session_start();

// Admin login check
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_config.php';

/* Statuses the Admin can act on (Third Party step has been removed:
   every permit type goes straight to Admin as 'Pending').
   Old legacy Third Party statuses are included only so older permits are not stuck. */
$PENDING_STATUS_SQL = "LOWER(status) IN ('pending','pending review','pending third party','third party approved')";

// Permit Action Handler
if(isset($_GET['permit_action']) && isset($_GET['permit_id'])){
    $permit_id = intval($_GET['permit_id']);
    $permit_action = $_GET['permit_action'];
    $reason = isset($_GET['reason']) ? trim($_GET['reason']) : '';

    $table_name = "service_provider_permits"; 
    $id_column = "permit_id"; 

    if(!empty($table_name)){
        if($permit_action == "reject"){
            $status = "Rejected";
            $expiry_sql = "";
        } elseif($permit_action == "hold"){
            $status = "On Hold";
            $expiry_sql = "";
        } else {
            $status = "Approved";
            // Get work_category to calculate expiry_date
            $cat_query = $conn->prepare("SELECT work_category FROM $table_name WHERE $id_column = ?");
            $cat_query->bind_param("i", $permit_id);
            $cat_query->execute();
            $cat_res = $cat_query->get_result();
            $cat_row = $cat_res->fetch_assoc();
            $work_cat = $cat_row['work_category'] ?? 'normal';
            $cat_query->close();

            // Calculate expiry based on category
            if($work_cat == 'hot_work' || $work_cat == 'work_at_height'){
                // 12 hours
                $expiry_sql = ", expiry_date = DATE_ADD(NOW(), INTERVAL 12 HOUR)";
            } else {
                // General work - 6 days
                $expiry_sql = ", expiry_date = DATE_ADD(NOW(), INTERVAL 6 DAY)";
            }
        }

        // Only permits that are still waiting for the Admin can be changed
        if($permit_action == "hold" && !empty($reason)){
            $old_status_stmt = $conn->prepare("SELECT status FROM $table_name WHERE $id_column = ?");
            $old_status_stmt->bind_param("i", $permit_id);
            $old_status_stmt->execute();
            $old_status_row = $old_status_stmt->get_result()->fetch_assoc();
            $old_status_stmt->close();

            $stmt = $conn->prepare("UPDATE $table_name SET status = ?, hold_reason = ? WHERE $id_column = ? AND $PENDING_STATUS_SQL");
            if($stmt){
                $stmt->bind_param("ssi", $status, $reason, $permit_id);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                if ($changed > 0) {
                    $changed_by = 'admin:' . ($_SESSION['admin_name'] ?? 'Administrator');
                    $log = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    if ($log) {
                        $old_status = $old_status_row['status'] ?? 'Pending';
                        $log->bind_param("issss", $permit_id, $old_status, $status, $reason, $changed_by);
                        $log->execute();
                        $log->close();
                    }
                }
            }
        } elseif($permit_action == "reject") {
            $old_status_stmt = $conn->prepare("SELECT status FROM $table_name WHERE $id_column = ?");
            $old_status_stmt->bind_param("i", $permit_id);
            $old_status_stmt->execute();
            $old_status_row = $old_status_stmt->get_result()->fetch_assoc();
            $old_status_stmt->close();

            $stmt = $conn->prepare("UPDATE $table_name SET status = ?, rejection_reason = ? WHERE $id_column = ? AND $PENDING_STATUS_SQL");
            if($stmt){
                $stmt->bind_param("ssi", $status, $reason, $permit_id);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                if ($changed > 0) {
                    $changed_by = 'admin:' . ($_SESSION['admin_name'] ?? 'Administrator');
                    $log = $conn->prepare("INSERT INTO permit_status_log (permit_id, old_status, new_status, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    if ($log) {
                        $old_status = $old_status_row['status'] ?? 'Pending';
                        $log->bind_param("issss", $permit_id, $old_status, $status, $reason, $changed_by);
                        $log->execute();
                        $log->close();
                    }
                }
            }
        } else {
            $stmt = $conn->prepare("UPDATE $table_name SET status = ? $expiry_sql WHERE $id_column = ? AND $PENDING_STATUS_SQL");
            if($stmt){
                $stmt->bind_param("si", $status, $permit_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    header("Location: admin_dashboard.php");
    exit();
}

// Counts for Summary Bar
$total_subs_res = $conn->query("SELECT COUNT(*) as cnt FROM subcontractors");
$total_subs = ($total_subs_res) ? $total_subs_res->fetch_assoc()['cnt'] : 0;

$total_workers_res = $conn->query("SELECT COUNT(*) as cnt FROM workers");
$total_workers = ($total_workers_res) ? $total_workers_res->fetch_assoc()['cnt'] : 0;

$total_supervisors_res = $conn->query("SELECT COUNT(*) as cnt FROM supervisors");
$total_supervisors = ($total_supervisors_res) ? $total_supervisors_res->fetch_assoc()['cnt'] : 0;

$total_permits_res = $conn->query("SELECT COUNT(*) as cnt FROM service_provider_permits");
$total_permits = ($total_permits_res) ? $total_permits_res->fetch_assoc()['cnt'] : 0;

// Permit status distribution (Third Party statuses removed;
// old legacy Third Party statuses are grouped into Pending / Rejected so the bar still adds up)
$status_counts = [];
$sc = $conn->query("SELECT status, COUNT(*) AS cnt FROM service_provider_permits GROUP BY status");
if ($sc) { while ($r = $sc->fetch_assoc()) { $status_counts[$r['status']] = (int)$r['cnt']; } }
$seg_map = [
    'approved'    => ['Approved',  '#16a34a', ['approved']],
    'completed'   => ['Completed', '#0d9488', ['complete','completed']],
    'pending'     => ['Pending',   '#f59e0b', ['pending','pending review','pending third party','third party approved']],
    'hold'        => ['On hold',   '#8b5cf6', ['on hold','hold']],
    'rejected'    => ['Rejected',  '#e11d48', ['rejected','reject','third party rejected']],
    'expired'     => ['Expired',   '#6b7280', ['expired']],
];
// Group DB statuses into the categories above (unknown statuses go to "Other" so the bar always adds up to the total)
$seg_counts = array_fill_keys(array_keys($seg_map), 0);
$other_count = 0;
foreach ($status_counts as $st => $cnt) {
    $norm = strtolower(trim($st)); $hit = false;
    foreach ($seg_map as $k => $m) { if (in_array($norm, $m[2], true)) { $seg_counts[$k] += $cnt; $hit = true; break; } }
    if (!$hit) { $other_count += $cnt; }
}
if ($other_count > 0) { $seg_map['other'] = ['Other', '#94a3b8', []]; $seg_counts['other'] = $other_count; }

// Pending queue count: ALL permit types waiting for the Admin
$qc = $conn->query("SELECT COUNT(*) AS cnt FROM service_provider_permits WHERE $PENDING_STATUS_SQL");
$queue_count = $qc ? (int)$qc->fetch_assoc()['cnt'] : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-dark: #bb4c14;
            --primary: #da6913;
            --background: #F6F8F5;
            --surface: #FFFFFF;
            --border: #E4E9E2;
        }
        body {
            background: var(--background);
            font-family: 'Inter', sans-serif;
            color: #0E1F14;
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

        /* Logo eka */
        .sidebar-logo {
            height: 60px;
            width: auto;
            object-fit: contain;
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        /* Expandable Vertical Nav Bar */
        .vertical-nav {
            background: linear-gradient(180deg, #ea580c 0%, #2f7106 50%, #15803d 100%);
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

        /* Mouse hover karahama expand wena eka */
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
        .notification-btn:hover {
            background: #ffedd5;
        }
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
        @media (max-width: 575.98px) {
            .notification-panel { right: -8px; width: calc(100vw - 24px); }
        }

        /* Stats Summary Bar Styles */
        .stats-summary-bar {
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 14px;
            padding: 15px 25px;
            display: flex;
            align-items: center;
            justify-content: space-around;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            flex-wrap: wrap;
            gap: 15px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon-box {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-details {
            display: flex;
            flex-direction: column;
        }

        .stat-value {
            font-family: 'Manrope', sans-serif;
            font-weight: 800;
            font-size: 1.25rem;
            color: #111827;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-divider {
            width: 1px;
            height: 35px;
            background: #e5e7eb;
        }

        @media (max-width: 992px) {
            .stat-divider { display: none; }
            .stats-summary-bar { justify-content: space-between; }
        }

        /* Custom Table Styles */
        .custom-table-card {
            border-radius: 16px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            background: #fff;
            padding: 24px;
        }

        .custom-table-card .pending-title {
            font-size: 1.2rem;
        }

        /* Fixed table area: always the height of 5 rows (header + 5 rows) */
        #pendingTable_wrapper .table-fixed-area {
            min-height: 335px;
        }

        .top-controls-bar {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .dataTables_length select {
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            padding: 6px 30px 6px 12px !important;
            font-size: 0.85rem !important;
            outline: none !important;
            background-color: #fff !important;
            color: #374151 !important;
            box-shadow: none !important;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 10px 10px;
        }

        .custom-filter-select,
        .dataTables_filter input {
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            padding: 6px 12px !important;
            font-size: 0.85rem !important;
            outline: none !important;
            background-color: #fff !important;
            color: #374151 !important;
            box-shadow: none !important;
        }

        .dataTables_filter input {
            width: 200px !important;
        }

        .dataTables_length label,
        .dataTables_filter label {
            font-size: 0.85rem;
            color: #4b5563;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0 !important;
        }

        #pendingTable {
            border-collapse: separate !important;
            border-spacing: 0 5px !important;
            width: 100% !important;
            margin-top: 0 !important;
            margin-bottom: 10px !important;
        }

        #pendingTable thead th {
            background-color: #0d1322 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            font-size: 0.75rem !important;
            letter-spacing: 0.5px;
            padding: 14px 16px !important;
            border: none !important;
        }

        #pendingTable thead th:first-child {
            border-top-left-radius: 12px !important;
            border-bottom-left-radius: 12px !important;
        }

        #pendingTable thead th:last-child {
            border-top-right-radius: 12px !important;
            border-bottom-right-radius: 12px !important;
        }

        #pendingTable tbody tr {
            background: #ffffff !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            border: 1px solid #e5e7eb;
            border-radius: 12px !important;
            transition: all 0.2s ease;
        }

        #pendingTable tbody tr:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            transform: translateY(-1px);
        }

        #pendingTable tbody td {
            height: 48px;
            padding: 0 16px !important;
            vertical-align: middle !important;
            font-size: 0.85rem !important;
            color: #1f2937 !important;
            border-top: 1px solid #e5e7eb !important;
            border-bottom: 1px solid #e5e7eb !important;
            background: transparent !important;
        }

        #pendingTable tbody td:first-child {
            border-left: 1px solid #e5e7eb !important;
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
            font-weight: 700;
        }

        #pendingTable tbody td:last-child {
            border-right: 1px solid #e5e7eb !important;
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        /* Custom Permit Type Badges matching reference style */
        .permit-badge-hot {
            background-color: #ffedd5 !important;
            color: #c2410c !important;
            font-weight: 700 !important;
            padding: 6px 14px !important;
            border-radius: 20px !important;
            font-size: 0.8rem !important;
            display: inline-block;
            border: none !important;
        }
        .permit-badge-height {
            background-color: #ffedd5 !important;
            color: #c2410c !important;
            font-weight: 700 !important;
            padding: 6px 14px !important;
            border-radius: 20px !important;
            font-size: 0.8rem !important;
            display: inline-block;
            border: none !important;
        }
        .permit-badge-normal {
            background-color: #e0f2fe !important;
            color: #0284c7 !important;
            font-weight: 700 !important;
            padding: 6px 14px !important;
            border-radius: 20px !important;
            font-size: 0.8rem !important;
            display: inline-block;
            border: none !important;
        }

        .action-btn-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .action-btn-group a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none !important;
            transition: all 0.2s;
        }
        .action-btn-group a:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .action-btn-group img {
            width: 16px;
            height: 16px;
            object-fit: contain;
        }

        .dataTables_wrapper .dataTables_info {
            font-size: 0.85rem;
            color: #6b7280;
            padding-top: 15px !important;
        }

        .dataTables_wrapper .dataTables_paginate {
            padding-top: 10px !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 8px !important;
            border: 1px solid #e5e7eb !important;
            background: #fff !important;
            color: #374151 !important;
            font-size: 0.75rem !important;
            padding: 0 !important;
            margin-left: 4px !important;
        }

        /* Smaller Previous / Next / page number buttons */
        .dataTables_wrapper .dataTables_paginate .paginate_button .page-link {
            font-size: 0.75rem !important;
            padding: 3px 10px !important;
            line-height: 1.4 !important;
            border: none !important;
            border-radius: 8px !important;
            background: transparent !important;
            color: inherit !important;
            box-shadow: none !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current, 
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: #ea580c !important;
            border-color: #ea580c !important;
            color: #fff !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #f3f4f6 !important;
            color: #111827 !important;
        }

        /* ===== View Permit Modal (same style as Permits page) ===== */
        .modal-backdrop.show { background: rgba(15, 23, 42, 0.55) !important; opacity: 1 !important; }

        #viewPermitModal .modal-dialog {
            max-width: 90vw;
            width: 900px;
            height: 90vh;
            margin: 5vh auto;
        }
        #viewPermitModal .modal-content {
            height: 100%;
            border: none !important;
            border-radius: 18px !important;
            overflow: hidden;
            background: #fff !important;
            box-shadow: 0 12px 20px -6px rgba(249, 115, 22, 0.12);
        }
        #viewPermitModal .modal-header {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%) !important;
            color: #fff;
            border: none !important;
            padding: 14px 20px;
        }
        #viewPermitModal .modal-header .modal-title {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        #viewPermitModal .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
            opacity: .85;
        }
        #viewPermitModal .modal-header .btn-close:hover { opacity: 1; }
        #viewPermitModal .modal-body {
            padding: 0 !important;
            height: calc(100% - 61px);
            position: relative;
            background: #f8fafc;
            overflow: hidden;
        }
        #viewPermitFrame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
            background: #fff;
        }
        #viewPermitLoading {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.85);
            font-weight: 600;
            color: #ea580c;
            z-index: 5;
            font-size: 13.5px;
            gap: 10px;
        }
        @media (max-width: 576px) {
            #viewPermitModal .modal-dialog { width: 100vw; height: 100vh; margin: 0; max-width: 100vw; }
            #viewPermitModal .modal-content { border-radius: 0 !important; }
        }

        /* ===== Permit overview card ===== */
        .overview-card {
            background: #ffffff;
            border: 1.5px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px 22px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 0;
            align-items: center;
            margin-bottom: 18px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }
        .overview-left h5 { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 1.05rem; margin: 0 0 2px; }
        .overview-left p { font-size: 0.85rem; color: #6b7280; margin: 0; }
        .overview-left p strong { color: #111827; }
        .btn-review {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: #fff; border-radius: 10px; padding: 8px 16px;
            font-weight: 600; font-size: 0.82rem; text-decoration: none;
            box-shadow: 0 4px 12px rgba(234,88,12,0.25); transition: transform .2s;
        }
        .btn-review:hover { color: #fff; transform: translateY(-1px); }
        .dist-title { display: flex; justify-content: space-between; font-weight: 700; font-size: 0.85rem; margin-bottom: 7px; }
        .dist-title span { color: #6b7280; font-weight: 500; }
        .dist-bar { display: flex; height: 10px; border-radius: 8px; overflow: hidden; gap: 3px; background: #f3f4f6; }
        .dist-bar i { display: block; height: 100%; border-radius: 6px; transform-origin: left; animation: distGrow .9s cubic-bezier(.2,.8,.2,1) both; }
        @keyframes distGrow { from { transform: scaleX(0); } }
        .dist-legend { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 5px 26px; margin-top: 9px; }
        .dist-legend div { display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: #6b7280; }
        .dist-legend i { width: 9px; height: 9px; border-radius: 3px; flex-shrink: 0; }
        .dist-legend b { color: #111827; margin-left: auto; }
        @media (max-width: 992px) { .overview-card { grid-template-columns: 1fr; gap: 20px; } }


        /* Title and filters on one line */
        .title-controls-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .title-controls-row .pending-title { margin: 0 !important; white-space: nowrap; }
        .title-controls-row .top-controls-bar { margin-bottom: 0; }
        .top-controls-bar .custom-filter-select,
        .top-controls-bar .dataTables_filter input,
        .top-controls-bar .dataTables_length select { height: 38px; }

        /* Show 5 = whole page fits one screen (no page scroll). 10 / 25 / All = page grows and scrolls normally */
        .main-content-wrap { padding-top: 16px; padding-bottom: 16px; }
        .welcome-top-box { margin-bottom: 14px; padding-top: 10px; padding-bottom: 10px; }
        .stats-summary-bar { margin-bottom: 14px; padding-top: 12px; padding-bottom: 12px; }
        .overview-card { margin-bottom: 14px; }
        .custom-table-card { padding: 18px 24px; }
        .title-controls-row { margin-bottom: 12px; }
        #pendingTable_wrapper .table-fixed-area { height: auto; min-height: 372px; overflow: visible; }
        #pendingTable_wrapper .bottom { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; }
        .dataTables_wrapper .dataTables_info { padding-top: 0 !important; }
        .dataTables_wrapper .dataTables_paginate { padding-top: 0 !important; }
        .dataTables_wrapper .dataTables_paginate ul.pagination { margin: 0; }
        #pendingTable { margin-bottom: 0 !important; }
    </style>
    <link rel="stylesheet" href="admin_sidebar.css">
</head>
<body class="bg-light">

    <div class="app-layout">
        <?php include __DIR__ . '/admin_sidebar.php'; ?>

        <!-- Main Content Area -->
        <div class="main-content-wrap">
            
            <!-- Top White Box with Typing Greeting -->
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

            <!-- Single Line Clean Stats Summary Bar -->
            <div class="stats-summary-bar">
                <!-- Subcontractors Count -->
                <div class="stat-item">
                    <div class="stat-icon-box bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-building-fill-gear"></i>
                    </div>
                    <div class="stat-details">
                        <span class="stat-value"><?= $total_subs; ?></span>
                        <span class="stat-label">Subcontractors</span>
                    </div>
                </div>

                <div class="stat-divider"></div>

                <!-- Workers Count -->
                <div class="stat-item">
                    <div class="stat-icon-box bg-success bg-opacity-10 text-success">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="stat-details">
                        <span class="stat-value"><?= $total_workers; ?></span>
                        <span class="stat-label">Workers</span>
                    </div>
                </div>

                <div class="stat-divider"></div>

                <!-- Division Logins Count -->
                <div class="stat-item">
                    <div class="stat-icon-box bg-info bg-opacity-10 text-info">
                        <i class="bi bi-person-badge-fill"></i>
                    </div>
                    <div class="stat-details">
                        <span class="stat-value"><?= $total_supervisors; ?></span>
                        <span class="stat-label">Division Logins</span>
                    </div>
                </div>

                <div class="stat-divider"></div>

                <!-- All Permits Count -->
                <div class="stat-item">
                    <div class="stat-icon-box bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-bar-chart-fill"></i>
                    </div>
                    <div class="stat-details">
                        <span class="stat-value"><?= $total_permits; ?></span>
                        <span class="stat-label">All Permits</span>
                    </div>
                </div>
            </div>

            <!-- Permit Overview Card -->
            <div class="overview-card">
                <div class="overview-right">
                    <div class="dist-title">Permit status <span><?= $total_permits; ?> total</span></div>
                    <div class="dist-bar">
                        <?php foreach($seg_map as $k => $m){ $n = $seg_counts[$k] ?? 0; if($n > 0 && $total_permits > 0){ echo "<i style='width:".round($n/$total_permits*100,1)."%;background:{$m[1]}' title='{$m[0]}: $n'></i>"; } } ?>
                    </div>
                    <div class="dist-legend">
                        <?php foreach($seg_map as $k => $m){ echo "<div><i style='background:{$m[1]}'></i>{$m[0]}<b>".($seg_counts[$k] ?? 0)."</b></div>"; } ?>
                    </div>
                </div>
            </div>

            <!-- Pending Permit Requests Table Section -->
            <div class="container-fluid mt-4 px-0">
                <div class="custom-table-card">
                    <h4 class="fw-bold mb-3 pending-title"><i class="bi bi-hourglass-split text-warning"></i> Pending Permit Requests</h4>
                    
                    <div id="extraFiltersContainer" style="display: none;">
                        <select id="categoryFilter" class="custom-filter-select">
                            <option value="">All Permit Types</option>
                            <option value="General">General</option>
                            <option value="Hot Work">Hot Work</option>
                            <option value="Work at Height">Work at Height</option>
                        </select>
                    </div>

                    <div class="table-responsive">
                        <table id="pendingTable" class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Permit Number</th>
                                    <th>Permit Type</th>
                                    <th>Project</th>
                                    <th>Status</th>
                                    <th>Submitted Date</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // ALL permit types (General / Hot Work / Work At Height) that are waiting for the Admin
                                $query = "SELECT permit_id, permit_number, cat_project, status, work_category, created_at 
                                          FROM service_provider_permits 
                                          WHERE $PENDING_STATUS_SQL
                                          ORDER BY created_at DESC";
                                $result = $conn->query($query);
                                if ($result && $result->num_rows > 0) {
                                    while($row = $result->fetch_assoc()){
                                        $pId = (int)$row['permit_id'];
                                        $rawCat = $row['work_category'];
                                        
                                        // Format category name & assign corresponding badge class
                                        if($rawCat == 'hot_work'){
                                            $catBadgeClass = 'permit-badge-hot';
                                            $catDisplay = 'Hot Work';
                                        } elseif($rawCat == 'work_at_height'){
                                            $catBadgeClass = 'permit-badge-height';
                                            $catDisplay = 'Work at Height';
                                        } else {
                                            $catBadgeClass = 'permit-badge-normal';
                                            $catDisplay = 'General';
                                        }

                                        echo "<tr>
                                                <td><strong>".htmlspecialchars($row['permit_number'])."</strong></td>
                                                <td><span class='".$catBadgeClass."'>".$catDisplay."</span></td>
                                                <td>".htmlspecialchars($row['cat_project'] ?? '')."</td>
                                                <td><span class='badge bg-warning text-dark px-2 py-1'>Pending</span></td>
                                                <td>".htmlspecialchars($row['created_at'])."</td>
                                                <td class='text-center'>
                                                    <div class='action-btn-group justify-content-center'>
                                                        <a href='javascript:void(0);' onclick='openViewModal(".$pId.")' title='View'>
                                                            <img src='assets/images/v.png' alt='View'>
                                                        </a>
                                                        <a href='javascript:void(0);' onclick='confirmApprove(".$pId.")' title='Approve'>
                                                            <img src='assets/images/a.png' alt='Approve'>
                                                        </a>
                                                        <a href='javascript:void(0);' onclick='confirmReject(".$pId.")' title='Reject'>
                                                            <img src='assets/images/r.png' alt='Reject'>
                                                        </a>
                                                    </div>
                                                </td>
                                              </tr>";
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ===== View Permit Modal ===== -->
    <div class="modal fade" id="viewPermitModal" tabindex="-1" aria-labelledby="viewPermitModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewPermitModalLabel"><i class="bi bi-eye"></i> Permit Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body position-relative">
                    <div id="viewPermitLoading">
                        <div class="spinner-border spinner-border-sm me-2" role="status" style="color: #ea580c;"></div> Loading details...
                    </div>
                    <iframe id="viewPermitFrame" src="about:blank" onload="hideViewLoading()"></iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- JS Scripts & DataTables Initialization -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
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
                notificationBadge.textContent = count > 9 ? '9+' : String(count);
                notificationBadge.hidden = count === 0;
                renderNotifications(data.items || []);
            } catch (error) {
                if (!notificationList.children.length || notificationList.querySelector('.notification-empty')) {
                    const empty = document.createElement('div');
                    empty.className = 'notification-empty';
                    empty.textContent = 'Updates could not be loaded.';
                    notificationList.replaceChildren(empty);
                }
            }
        }

        notificationButton.addEventListener('click', () => {
            const isOpening = !notificationPanel.classList.contains('show');
            notificationPanel.classList.toggle('show', isOpening);
            notificationButton.setAttribute('aria-expanded', String(isOpening));
            if (isOpening) loadNotifications(true);
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.notification-container')) {
                notificationPanel.classList.remove('show');
                notificationButton.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                notificationPanel.classList.remove('show');
                notificationButton.setAttribute('aria-expanded', 'false');
                notificationButton.focus();
            }
        });

        loadNotifications();
        setInterval(() => loadNotifications(), 60000);

        $(document).ready(function() {
            var table = $('#pendingTable').DataTable({
                "pageLength": 5,
                "lengthMenu": [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
                "ordering": true,
                "order": [],
                "dom": '<"top"lf>rt<"bottom"ip><"clear">'
            });

            var $wrapper = $('#pendingTable_wrapper');

            // Fixed-height area around the table so the box size stays the same for 1 or 5 rows
            $('#pendingTable').wrap('<div class="table-fixed-area"></div>');
            var $length = $wrapper.find('.dataTables_length');
            var $filter = $wrapper.find('.dataTables_filter');

            var $topControlsBar = $('<div class="top-controls-bar"></div>');
            
            if ($length.length) $topControlsBar.append($length);
            $topControlsBar.append($('#categoryFilter'));
            if ($filter.length) $topControlsBar.append($filter);

            // Title + controls on the same line
            var $titleRow = $('<div class="title-controls-row"></div>');
            var $title = $('.pending-title');
            $title.before($titleRow);
            $titleRow.append($title).append($topControlsBar);

            // Filter by the visible permit type text (General / Hot Work / Work at Height)
            $('#categoryFilter').on('change', function() {
                var val = $.fn.dataTable.util.escapeRegex($(this).val());
                table.column(1).search(val ? '^\\s*' + val + '\\s*$' : '', true, false).draw();
            });
        });

        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();
            let ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12; 
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;
            
            const timeString = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
            document.getElementById('liveClock').innerText = timeString;
        }
        setInterval(updateClock, 1000);

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
            typingElement.style.color = config.color;

            if (charIndex < greetingText.length) {
                typingElement.innerHTML += greetingText.charAt(charIndex);
                charIndex++;
                setTimeout(typeWriter, 70);
            } else {
                document.getElementById("greetingIconContainer").innerHTML = `<span class="animated-icon">${config.icon}</span>`;
            }
        }

        window.onload = function() {
            updateClock();
            typeWriter();
        };

        function confirmApprove(permitId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "Do you want to approve this permit?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#16803a',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Approve!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'admin_dashboard.php?permit_action=approve&permit_id=' + permitId;
                }
            });
        }

        function confirmReject(permitId) {
            Swal.fire({
                title: 'Provide Rejection Reason',
                input: 'textarea',
                inputPlaceholder: 'Enter the reason for rejection here...',
                showCancelButton: true,
                confirmButtonText: 'Submit Reject',
                confirmButtonColor: '#d9483c',
                cancelButtonColor: '#6c757d',
                preConfirm: (comment) => {
                    if (!comment || comment.trim() === "") {
                        Swal.showValidationMessage('Please enter a rejection reason');
                    }
                    return comment;
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    window.location.href = 'admin_dashboard.php?permit_action=reject&permit_id=' + permitId + '&reason=' + encodeURIComponent(result.value);
                }
            });
        }

        var viewPermitModalEl = document.getElementById('viewPermitModal');
        var viewPermitModal = new bootstrap.Modal(viewPermitModalEl);

        function openViewModal(permitId) {
            document.getElementById('viewPermitLoading').style.display = 'flex';
            document.getElementById('viewPermitFrame').src = 'view_service_provider.php?id=' + permitId;
            viewPermitModal.show();
        }

        function hideViewLoading() {
            document.getElementById('viewPermitLoading').style.display = 'none';
        }

        viewPermitModalEl.addEventListener('hidden.bs.modal', function () {
            document.getElementById('viewPermitFrame').src = 'about:blank';
        });
    </script>
</body>
</html>