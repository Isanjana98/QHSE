<?php
$current_page = basename($_SERVER['PHP_SELF']);
$subcontractorNavItems = [
    ['page' => 'subcontractor_dashboard.php', 'label' => 'Dashboard', 'icon' => 'bi-house-door-fill'],
    ['page' => 'add_worker.php', 'label' => 'Worker Portal', 'icon' => 'bi-people-fill'],
    ['page' => 'subcontractor_history.php', 'label' => 'Permit History', 'icon' => 'bi-clock-history'],
    ['page' => 'subcontractor_inspections.php', 'label' => 'Tool Inspections', 'icon' => 'bi-tools'],
    ['page' => 'subcontractor_tutorials.php', 'label' => 'Tutorials', 'icon' => 'bi-play-circle-fill'],
];
?>
<div class="sidebar-outer-wrap">
    <a href="subcontractor_dashboard.php" class="sidebar-logo-outside" title="Hayleys Fentons Dashboard">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons" onerror="this.style.display='none'">
    </a>
    <nav class="navbar-custom" aria-label="Subcontractor navigation">
        <div class="navbar-inner-content">
            <div class="mobile-dropdown-wrapper" id="subcontractorNavDropdown">
                <div class="sidebar-menu">
                    <?php foreach ($subcontractorNavItems as $item): ?>
                        <?php $isActive = $current_page === $item['page']; ?>
                        <a href="<?= htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-menu-item <?= $isActive ? 'active' : ''; ?>" title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>" <?= $isActive ? 'aria-current="page"' : ''; ?>>
                            <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                            <span class="nav-text"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="right-section">
                    <div class="logout-container">
                        <a class="logout-btn" href="logout.php" title="Logout"><i class="bi bi-power"></i><span>Logout</span></a>
                    </div>
                </div>
            </div>
            <button type="button" class="mobile-toggle-btn" id="subcontractorNavToggle" aria-label="Toggle Navigation" aria-expanded="false" aria-controls="subcontractorNavDropdown">
                <i class="bi bi-list" id="subcontractorNavToggleIcon"></i>
            </button>
        </div>
    </nav>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleButton = document.getElementById('subcontractorNavToggle');
    const dropdown = document.getElementById('subcontractorNavDropdown');
    const toggleIcon = document.getElementById('subcontractorNavToggleIcon');
    if (!toggleButton || !dropdown || !toggleIcon) return;

    toggleButton.addEventListener('click', function () {
        const isOpen = dropdown.classList.toggle('show');
        toggleButton.setAttribute('aria-expanded', String(isOpen));
        toggleIcon.classList.toggle('bi-list', !isOpen);
        toggleIcon.classList.toggle('bi-x-lg', isOpen);
    });
});
</script>