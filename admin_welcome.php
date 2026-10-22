<div class="admin-shared-welcome">
    <div class="admin-shared-welcome-copy">
        <div class="admin-shared-greeting">
            <i class="bi bi-cloud-sun-fill" aria-hidden="true"></i>
            <span>Good Afternoon !</span>
        </div>
        <div class="admin-shared-subtitle"><i class="bi bi-shield-check text-warning"></i> Compliance Division &bull; Manage permits and team activities efficiently</div>
    </div>
    <div class="admin-shared-controls">
        <div class="admin-shared-pill"><i class="bi bi-calendar3 text-warning"></i><span><?= date('D, d M Y'); ?></span></div>
        <div class="admin-shared-pill"><i class="bi bi-clock-history text-warning"></i><span id="adminWelcomeClock">Loading time...</span></div>
    </div>
</div>
<script>
(function () {
    const clock = document.getElementById('adminWelcomeClock');
    if (!clock) return;
    function updateAdminWelcomeClock() {
        const now = new Date();
        let hours = now.getHours() % 12 || 12;
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        const ampm = now.getHours() >= 12 ? 'PM' : 'AM';
        clock.textContent = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
    }
    updateAdminWelcomeClock();
    setInterval(updateAdminWelcomeClock, 1000);
})();
</script>