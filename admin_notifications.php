<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/db_config.php';

$seenAt = $_SESSION['admin_notifications_seen_at'] ?? time() - 86400;
if (($_GET['mark_read'] ?? '') === '1') {
    $seenAt = time();
    $_SESSION['admin_notifications_seen_at'] = $seenAt;
}

$notifications = [];
$addNotifications = static function ($sql, $formatter) use ($conn, &$notifications) {
    $result = $conn->query($sql);
    if (!$result) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $timestamp = strtotime($row['event_at'] ?? $row['created_at'] ?? '');
        if (!$timestamp) {
            continue;
        }

        $notification = $formatter($row);
        $notification['timestamp'] = date(DATE_ATOM, $timestamp);
        $notification['sort_timestamp'] = $timestamp;
        $notifications[] = $notification;
    }
};

$addNotifications(
    "SELECT permit_id, permit_number, cat_project, created_at FROM service_provider_permits WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 20",
    static function ($row) {
        $permitNumber = trim($row['permit_number'] ?? '');
        $project = trim($row['cat_project'] ?? '');
        return [
            'title' => 'New permit request',
            'detail' => $permitNumber !== '' ? $permitNumber : ($project !== '' ? $project : 'Permit application'),
            'icon' => 'bi-file-earmark-text',
            'href' => 'permit_status_tracker.php',
        ];
    }
);

$addNotifications(
    "SELECT id, tool_type, company_name, created_at FROM tool_inspection_records WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 20",
    static function ($row) {
        return [
            'title' => 'New tool inspection',
            'detail' => trim(($row['tool_type'] ?? 'Tool') . ' · ' . ($row['company_name'] ?? '')),
            'icon' => 'bi-tools',
            'href' => 'admin_tool_inspection.php',
        ];
    }
);

$addNotifications(
    "SELECT id, company_name, created_at FROM subcontractors WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 20",
    static function ($row) {
        return [
            'title' => 'New subcontractor registration',
            'detail' => trim($row['company_name'] ?? 'Subcontractor'),
            'icon' => 'bi-building-fill-gear',
            'href' => 'subcontractors_section.php',
        ];
    }
);

$addNotifications(
    "SELECT id, worker_name, company_name, created_at FROM workers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 20",
    static function ($row) {
        $company = trim($row['company_name'] ?? '');
        return [
            'title' => 'New worker registration',
            'detail' => trim($row['worker_name'] ?? 'Worker') . ($company !== '' ? ' · ' . $company : ''),
            'icon' => 'bi-people-fill',
            'href' => 'view_worker.php?id=' . (int)$row['id'],
        ];
    }
);

$addNotifications(
    "SELECT id, name, division, created_at FROM supervisors WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 20",
    static function ($row) {
        $division = trim($row['division'] ?? '');
        return [
            'title' => 'New divisional login request',
            'detail' => trim($row['name'] ?? 'Supervisor') . ($division !== '' ? ' · ' . $division : ''),
            'icon' => 'bi-person-badge-fill',
            'href' => 'supervisors_section.php?tab=pending',
        ];
    }
);

$addNotifications(
    "SELECT id, title, created_at, GREATEST(created_at, updated_at) AS event_at FROM tutorial_videos WHERE GREATEST(created_at, updated_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY event_at DESC LIMIT 20",
    static function ($row) {
        return [
            'title' => 'New or updated tutorial video',
            'detail' => trim($row['title'] ?? 'Tutorial video'),
            'icon' => 'bi-play-btn-fill',
            'href' => 'admin_tutorials.php',
        ];
    }
);

usort($notifications, static function ($left, $right) {
    return $right['sort_timestamp'] <=> $left['sort_timestamp'];
});

$unreadCount = 0;
foreach ($notifications as &$notification) {
    if ($notification['sort_timestamp'] > $seenAt) {
        $unreadCount++;
        $notification['is_unread'] = true;
    } else {
        $notification['is_unread'] = false;
    }
    unset($notification['sort_timestamp']);
}
unset($notification);

$notifications = array_slice($notifications, 0, 12);
echo json_encode([
    'items' => $notifications,
    'unread_count' => $unreadCount,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
