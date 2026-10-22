<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

<style>
:root {
    --primary-orange: #e15e13;
    --primary-orange-dark: #bb4c14;
}

/* =====================================================
   OUTER WRAPPER — holds the logo OUTSIDE/ABOVE the pill,
   plus the navbar itself below it. This wrapper is what's
   sticky now (navbar-custom itself no longer is).
===================================================== */
.sidebar-outer-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0px;
    margin: 4px 0 20px 20px;
    position: sticky;
    top: 4px;
    height: calc(100vh - 24px);
    flex-shrink: 0;
    z-index: 1000;
}

.sidebar-logo-outside {
    width: 100px;
    height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: -12px;
    margin-bottom: -20px;
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

/* Main Navbar Wrapper - Collapsed by default, expands on hover */
.navbar-custom {
    background: linear-gradient(180deg, #ea580c 0%, #2f7106 50%, #15803d 100%);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 35px;
    margin: 0;
    padding: 25px 10px;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.08);
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #fff;
    font-family: 'Inter', sans-serif;
    width: 75px;
    flex: 1;
    min-height: 0;
    position: static;
    flex-shrink: 0;
    transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-x: hidden;
}

/* Expand navbar on hover (Desktop) */
.navbar-custom:hover {
    width: 210px;
    padding: 25px 14px;
}

.navbar-inner-content {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
}

/* Container for Links - Main menu items centered vertically */
.mobile-dropdown-wrapper {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    height: 100%;
}

/* Sidebar Menu Links */
.sidebar-menu {
    display: flex;
    flex-direction: column;
    gap: 16px;
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
    gap: 16px;
    padding: 12px 14px;
    border-radius: 18px;
    color: #ffffff; 
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    width: 100%;
    white-space: nowrap;
}

.sidebar-menu-item i {
    font-size: 20px;
    color: #ffffff;
    min-width: 26px;
    text-align: center;
    transition: transform 0.3s ease;
}

/* Text hidden by default, appears on navbar hover */
.sidebar-menu-item span.nav-text {
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
    font-size: 14px;
    font-weight: 600;
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

/* Toggle Menu Button - Mobile */
.mobile-toggle-btn {
    display: none;
    background: transparent;
    border: none;
    color: #fff;
    font-size: 28px;
    cursor: pointer;
    padding: 0 8px;
}

/* Logout Section (Pushed completely to the bottom) */
.right-section { 
    display: flex; 
    flex-direction: column; 
    align-items: center; 
    width: 100%; 
    border-top: 1px solid rgba(255, 255, 255, 0.18); 
    padding-top: 18px; 
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
    height: 48px;
    padding: 0 14px;
    border-radius: 16px;
    background: rgba(0, 0, 0, 0.2); 
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 16px;
    text-decoration: none;
    border: 1px solid rgba(255, 255, 255, 0.2);
    font-weight: 600;
    font-size: 14px;
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
    font-size: 18px; 
    color: #fff;
    min-width: 26px;
    text-align: center;
}

.logout-btn span {
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.2s ease, visibility 0.2s ease;
}

/* MOBILE RESPONSIVE STYLING */
@media (max-width: 992px) {

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

    .sidebar-menu-item:hover {
        transform: none;
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
    
    .logout-btn:hover {
        transform: none;
    }
}
</style>

<div class="sidebar-outer-wrap">

    <a href="supervisor_dashboard.php" class="sidebar-logo-outside" title="Dashboard">
        <img src="assets/images/hayleys_logo.png" alt="Hayleys Logo" onerror="this.src='assets/images/logout.png'">
    </a>

    <nav class="navbar-custom">
        <div class="navbar-inner-content">

            <div class="mobile-dropdown-wrapper" id="navDropdown">
                <div class="sidebar-menu">
                    <a href="supervisor_dashboard.php" class="sidebar-menu-item <?= ($current_page == 'supervisor_dashboard.php') ? 'active' : ''; ?>" title="Dashboard">
                        <i class="bi bi-house-door-fill"></i> <span class="nav-text">Dashboard</span>
                    </a>
                    
                    <a href="permits.php" class="sidebar-menu-item <?= ($current_page == 'permits.php') ? 'active' : ''; ?>" title="Requests / Permits">
                        <i class="bi bi-grid-fill"></i> <span class="nav-text">Requests</span>
                    </a>

                    <!-- NEW: Tool Inspection -->
                    <a href="tool_inspection.php" class="sidebar-menu-item <?= ($current_page == 'tool_inspection.php') ? 'active' : ''; ?>" title="Tool Inspection">
                        <i class="bi bi-tools"></i> <span class="nav-text">Tool Inspection</span>
                    </a>
                    
                    <a href="subcontractors.php" class="sidebar-menu-item <?= ($current_page == 'subcontractors.php') ? 'active' : ''; ?>" title="Subcontractors">
                        <i class="bi bi-person-fill"></i> <span class="nav-text">Sub Contractors</span>
                    </a>
                    
                    <a href="workers.php" class="sidebar-menu-item <?= ($current_page == 'workers.php') ? 'active' : ''; ?>" title="Workers">
                        <i class="bi bi-people-fill"></i> <span class="nav-text">Workers</span>
                    </a>

                    <a href="settings.php" class="sidebar-menu-item <?= ($current_page == 'settings.php') ? 'active' : ''; ?>" title="Settings">
                        <i class="bi bi-gear-fill"></i> <span class="nav-text">Settings</span>
                    </a>
                </div>

                <!-- Logout Section at Absolute Bottom -->
                <div class="right-section">
                    <div class="logout-container">
                        <a class="logout-btn" href="logout.php" title="Logout">
                            <i class="bi bi-power"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>

            <button class="mobile-toggle-btn" id="navbarToggleBtn" aria-label="Toggle Navigation">
                <i class="bi bi-list" id="toggleIcon"></i>
            </button>

        </div>
    </nav>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('navbarToggleBtn');
    const dropdownWrapper = document.getElementById('navDropdown');
    const toggleIcon = document.getElementById('toggleIcon');

    if(toggleBtn) {
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