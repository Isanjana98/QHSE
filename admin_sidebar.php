<?php
$current_page = basename($_SERVER['PHP_SELF']);
$active_pages = [
    'admin_dashboard.php' => 'dashboard',
    'subcontractors_section.php' => 'subcontractors',
    'workers_section.php' => 'workers',
    'view_worker.php' => 'workers',
    'supervisors_section.php' => 'supervisors',
    'permit_status_tracker.php' => 'permits',
    'admin_tool_inspection.php' => 'inspection',
    'admin_tutorials.php' => 'tutorials',
];
$active_section = $active_pages[$current_page] ?? '';
?>

<div class="sidebar-wrap">
    <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons" class="sidebar-logo">
    <nav class="vertical-nav" aria-label="Admin navigation">
        <div class="nav-links-group">
            <a href="admin_dashboard.php" class="nav-icon-link <?= $active_section === 'dashboard' ? 'active' : ''; ?>" title="Dashboard" <?= $active_section === 'dashboard' ? 'aria-current="page"' : ''; ?>><i class="bi bi-house-door-fill"></i><span class="nav-text">Dashboard</span></a>
            <a href="subcontractors_section.php" class="nav-icon-link <?= $active_section === 'subcontractors' ? 'active' : ''; ?>" title="Subcontractors" <?= $active_section === 'subcontractors' ? 'aria-current="page"' : ''; ?>><i class="bi bi-building-fill-gear"></i><span class="nav-text">Subcontractors</span></a>
            <a href="workers_section.php" class="nav-icon-link <?= $active_section === 'workers' ? 'active' : ''; ?>" title="Workers" <?= $active_section === 'workers' ? 'aria-current="page"' : ''; ?>><i class="bi bi-people-fill"></i><span class="nav-text">Workers</span></a>
            <a href="./supervisors_section.php" class="nav-icon-link <?= $active_section === 'supervisors' ? 'active' : ''; ?>" title="Divisional Logins" <?= $active_section === 'supervisors' ? 'aria-current="page"' : ''; ?>><i class="bi bi-person-badge-fill"></i><span class="nav-text">Divisional Logins</span></a>
            <a href="permit_status_tracker.php" class="nav-icon-link <?= $active_section === 'permits' ? 'active' : ''; ?>" title="Permit Status Tracker" <?= $active_section === 'permits' ? 'aria-current="page"' : ''; ?>><i class="bi bi-bar-chart-fill"></i><span class="nav-text">Permit Tracker</span></a>
            <a href="admin_tool_inspection.php" class="nav-icon-link <?= $active_section === 'inspection' ? 'active' : ''; ?>" title="Tool Inspection" <?= $active_section === 'inspection' ? 'aria-current="page"' : ''; ?>><i class="bi bi-tools"></i><span class="nav-text">Tool Inspection</span></a>
            <a href="admin_tutorials.php" class="nav-icon-link <?= $active_section === 'tutorials' ? 'active' : ''; ?>" title="Tutorial Videos" <?= $active_section === 'tutorials' ? 'aria-current="page"' : ''; ?>><i class="bi bi-play-btn-fill"></i><span class="nav-text">Tutorial Videos</span></a>
        </div>
        <a href="logout.php" class="logout-pill-btn" title="Logout"><i class="bi bi-power fs-5"></i><span class="nav-text">Logout</span></a>
    </nav>
</div>
