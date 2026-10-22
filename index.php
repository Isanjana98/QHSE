<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | QHSE EN</title>

    <!-- Fonts: Manrope + Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
:root{
    --paper:        #ffffff;
    --amber:        #ff7a1a;
    --amber-deep:   #c2410c;
    --hazard:       #ffb703;
    --muted:        #94a3b8;
    --radius-lg:    20px;

    --bark:         #241407;
    --bark-light:   #3a2211;
    --gold:         #f2b545;
    --cream:        #f3e6d4;
    --cream-dim:    rgba(243, 230, 212, 0.72);
}

*{ margin:0; padding:0; box-sizing:border-box; }

body{
    min-height:100vh;
    font-family:'Inter',sans-serif;
    overflow: hidden;
}

h1,h2,h3,h4{ font-family:'Manrope',sans-serif; letter-spacing:-.01em; }

/* PAGE LAYOUT */
.page-wrapper{
    position: relative;
    width: 100vw;
    height: 100vh;
    display: flex;
}

/* ---------------- FULL PAGE BACKGROUND SLIDER ---------------- */
.left-slider-section{
    position: absolute;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 1;
    overflow: hidden;
    border-radius: 0;
    clip-path: none;
    -webkit-clip-path: none;
}

.slide{
    position: absolute;
    inset: 0;
    z-index: 2;
    background-size: cover;
    background-position: center;
    opacity: 0;
    transform: scale(1.02);
    transition: opacity 1.3s ease-in-out;
}

.slide.active{
    opacity: 1;
    animation: kenburns 7s ease-out forwards;
}

@keyframes kenburns{
    from{ transform: scale(1.02); }
    to{ transform: scale(1.11); }
}

/* Full Screen Overlay with dark gradient */
.slide-overlay{
    position: absolute;
    inset: 0;
    z-index: 3;
    background:
        linear-gradient(90deg, rgba(20,10,3,0.35) 0%, rgba(20,10,3,0) 55%, rgba(20,10,3,0.55) 100%),
        linear-gradient(0deg, rgba(20,10,3,0.75) 0%, rgba(20,10,3,0.15) 45%, rgba(20,10,3,0.1) 100%);
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: 48px 64px 76px;
    pointer-events: none;
}

.slide-eyebrow{
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: max-content;
    color: var(--hazard);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.slide-title{
    color: #fff;
    font-size: clamp(26px, 2.6vw, 36px);
    font-weight: 800;
    max-width: 520px;
    margin-bottom: 6px;
}

.slide-desc{
    color: rgba(255,255,255,.8);
    font-size: 14.5px;
    max-width: 440px;
    margin-bottom: 26px;
}

.slide-ticks{
    display: flex;
    gap: 6px;
    pointer-events: auto;
}

.slide-ticks button{
    appearance: none;
    border: none;
    height: 4px;
    width: 26px;
    border-radius: 3px;
    background: rgba(255,255,255,.3);
    cursor: pointer;
    padding: 0;
    transition: background .3s ease;
}

.slide-ticks button.active{ background: var(--hazard); }

/* ---------------- TOP-LEFT LOGO CONTAINER ---------------- */
.top-left-logo-container{
    position: absolute;
    top: 30px;
    left: 45px;
    z-index: 20;
}

.top-brand-logo{
    max-width: 200px;
    height: auto;
    object-fit: contain;
    background: transparent !important;
    padding: 0;
    border-radius: 0;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.4));
}

/* ---------------- RIGHT: Form ---------------- */
.right-form-section{
    position: absolute;
    top: 0;
    right: 0;
    width: 45vw;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10;
}

.login-container{
    width: 100%;
    max-width: 480px;
    padding: 20px;
}

.eyebrow-label{
    text-align: center;
    color: var(--gold);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    margin-bottom: 24px;
    text-shadow: 0 1px 2px rgba(0,0,0,0.5);
}

.login-card{
    position: relative;
    border-radius: 24px;
    overflow: hidden;
    background: linear-gradient(165deg, rgba(58, 34, 17, 0.55) 0%, rgba(36, 20, 7, 0.6) 100%);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(242, 181, 69, 0.25);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45), inset 0 1px 1px rgba(255, 200, 130, 0.15);
}

.login-card::before{
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold), var(--amber), transparent);
    opacity: .8;
}

.card-header{
    text-align: center;
    padding: 30px 32px 8px;
}

.card-header h2{
    color: #ffffff;
    font-size: 1.8rem;
    font-weight: 800;
    margin-bottom: 4px;
}

.card-header p{
    color: var(--cream-dim);
    font-size: 13.5px;
}

.card-body{ padding: 18px 36px 30px; }

.form-label{
    color: #f1f5f9;
    font-weight: 600;
    font-size: 13.5px;
    margin-bottom: 6px;
}

.input-group{
    display: flex;
    align-items: center;
    width: 100%;
    background: rgba(20, 11, 4, 0.55);
    border-radius: 10px;
    overflow: hidden;
    border: 1.5px solid rgba(255, 122, 26, 0.4);
    transition: .25s ease;
}

.input-group:focus-within{
    border-color: #ff7a1a;
    background: rgba(20, 11, 4, 0.75);
    box-shadow: 0 0 0 3px rgba(255, 122, 26, 0.25);
}

.input-group-text{
    background: transparent;
    border: none !important;
    color: #ff7a1a;
    width: 44px;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 15px;
}

.form-control{
    background: transparent !important;
    border: none !important;
    color: #ffffff !important;
    height: 42px;
    font-size: 14px;
    font-weight: 500;
    box-shadow: none !important;
}

.form-control::placeholder{ color: #94a3b8 !important; }

.form-control:-webkit-autofill,
.form-control:-webkit-autofill:hover,
.form-control:-webkit-autofill:focus,
.form-control:-webkit-autofill:active{
    -webkit-box-shadow: 0 0 0 1000px rgba(20, 11, 4, 0.85) inset !important;
    box-shadow: 0 0 0 1000px rgba(20, 11, 4, 0.85) inset !important;
    -webkit-text-fill-color: #ffffff !important;
    caret-color: #ffffff;
    transition: background-color 9999s ease-in-out 0s;
}

.password-toggle-btn{
    background: transparent !important;
    border: none !important;
    color: #ff7a1a;
    width: 44px;
    font-size: 15px;
}

.btn-login{
    height: 38px;
    border: none;
    border-radius: 50px;
    font-size: 14px;
    font-weight: 700;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: linear-gradient(135deg, #ff7a1a 0%, #ff5500 100%) !important;
    box-shadow: 0 4px 12px rgba(255, 85, 0, 0.3);
    transition: .25s ease;
}

.btn-login:hover{
    transform: translateY(-1px);
    background: linear-gradient(135deg, #e66a00 0%, #e64d00 100%) !important;
    box-shadow: 0 6px 16px rgba(255, 85, 0, 0.4);
    color: #fff;
}

.alert{
    border-radius: 10px;
    font-size: .85rem;
    background: rgba(254, 242, 242, 0.9);
    color: #dc2626;
    border: 1px solid #fecaca;
}

.register-prompt{
    color: var(--cream-dim);
    font-size: 13px;
}

.btn-outline-custom{
    color: var(--gold);
    border: 1.5px solid rgba(255, 122, 26, 0.6);
    background: rgba(255, 122, 26, 0.08);
    border-radius: 50px;
    font-weight: 600;
    font-size: 13.5px;
    height: 36px;
    transition: .25s ease;
}

.btn-outline-custom:hover{
    background: #ff7a1a;
    color: #fff;
    border-color: #ff7a1a;
}

.footer-credit{
    text-align: center;
    margin-top: 24px;
    font-size: 11.5px;
    color: var(--cream-dim);
    font-weight: 500;
    text-shadow: 0 1px 2px rgba(0,0,0,0.5);
}

@media (max-width: 992px) {
    .right-form-section {
        width: 100vw;
        background: rgba(0, 0, 0, 0.4);
    }
    .slide-overlay {
        display: none;
    }
    .top-left-logo-container {
        top: 20px;
        left: 20px;
    }
}
</style>
</head>
<body>

<div class="page-wrapper">

<!-- Top-Left Logo -->
<div class="top-left-logo-container">
    <img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons Logo" class="top-brand-logo">
</div>

<!-- Full Page Background Slider -->
<div class="left-slider-section">
    <div class="slide active"
         data-eyebrow="Safety & Compliance"
         data-title="Operational excellence across every site"
         data-desc="Streamlining hazard controls, safety audits, and real-time incident tracking."
         style="background-image:url('assets/images/p.jpg');"></div>

    <div class="slide"
         data-eyebrow="Quality Assurance"
         data-title="Maintaining the highest industry standards"
         data-desc="Standardized inspection workflows ensuring quality and regulatory adherence."
         style="background-image:url('assets/images/q.jpg');"></div>

    <div class="slide"
         data-eyebrow="Environmental Management"
         data-title="Sustainable and responsible operations"
         data-desc="Proactive risk assessment and environmental impact monitoring."
         style="background-image:url('assets/images/r.jpg');"></div>

    <div class="slide"
         data-eyebrow="Risk Mitigation"
         data-title="Advanced safety monitoring systems"
         data-desc="Ensuring complete protection and compliance across all engineering operations."
         style="background-image:url('assets/images/s.jpg');"></div>

    <div class="slide"
         data-eyebrow="Health & Safety"
         data-title="Prioritizing workforce well-being"
         data-desc="Implementing rigorous protocols to maintain a zero-incident work environment."
         style="background-image:url('assets/images/t.jpg');"></div>

    <div class="slide"
         data-eyebrow="Engineering Excellence"
         data-title="Precision and reliability in execution"
         data-desc="Delivering top-tier technical solutions with strict adherence to safety codes."
         style="background-image:url('assets/images/u.jpg');"></div>

    <div class="slide"
         data-eyebrow="Continuous Improvement"
         data-title="Driving innovation in QHSE standards"
         data-desc="Regular performance reviews and continuous training for optimal site safety."
         style="background-image:url('assets/images/v.jpg');"></div>

    <div class="slide-overlay">
        <span class="slide-eyebrow"><i class="bi bi-shield-check"></i> <span id="slideEyebrow">Safety & Compliance</span></span>
        <h3 class="slide-title" id="slideTitle">Operational excellence across every site</h3>
        <p class="slide-desc" id="slideDesc">Streamlining hazard controls, safety audits, and real-time incident tracking.</p>
        <div class="slide-ticks" id="slideTicks">
            <button type="button" class="active" data-index="0" aria-label="Show slide 1"></button>
            <button type="button" data-index="1" aria-label="Show slide 2"></button>
            <button type="button" data-index="2" aria-label="Show slide 3"></button>
            <button type="button" data-index="3" aria-label="Show slide 4"></button>
            <button type="button" data-index="4" aria-label="Show slide 5"></button>
            <button type="button" data-index="5" aria-label="Show slide 6"></button>
            <button type="button" data-index="6" aria-label="Show slide 7"></button>
        </div>
    </div>
</div>

    <!-- Right Section: Login Form -->
    <div class="right-form-section">
        <div class="login-container">
            <p class="eyebrow-label">QHSE EN Management System</p>

            <div class="card login-card">
                <div class="card-header">
                    <h2>Welcome back</h2>
                    <p class="mb-0">Sign in to continue to your dashboard</p>
                </div>

                <div class="card-body">
                    <?php
                    if (isset($_SESSION['login_error'])) {
                        echo '<div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> ' . $_SESSION['login_error'] . '
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                              </div>';
                        unset($_SESSION['login_error']);
                    }
                    ?>

                    <form action="login_process.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" class="form-control" placeholder="Enter your username" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" id="passwordField" class="form-control" placeholder="Enter your password" required>
                                <button type="button" class="password-toggle-btn" id="togglePassword">
                                    <i class="bi bi-eye" id="toggleIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-login">
                                 Sign In
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-3">
                        <span class="register-prompt">Don't have an account?</span>
                        <a href="register.php" class="btn btn-outline-custom mt-2 w-100 d-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-person-plus"></i> Register
                        </a>
                    </div>
                </div>
            </div>

            <p class="footer-credit">&copy; <?= date('Y'); ?> Hayleys Fentons Limited — QHSE EN Management System</p>
        </div>
    </div>
</div>

<!-- Scripts -->
<script>
    // Password visibility toggle
    const togglePassword = document.querySelector('#togglePassword');
    const passwordField  = document.querySelector('#passwordField');
    const toggleIcon     = document.querySelector('#toggleIcon');

    togglePassword.addEventListener('click', function () {
        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordField.setAttribute('type', type);
        toggleIcon.classList.toggle('bi-eye');
        toggleIcon.classList.toggle('bi-eye-slash');
    });

    // Background slider logic
    const slides    = document.querySelectorAll('.slide');
    const ticks     = document.querySelectorAll('.slide-ticks button');
    const eyebrowEl = document.getElementById('slideEyebrow');
    const titleEl   = document.getElementById('slideTitle');
    const descEl    = document.getElementById('slideDesc');
    let currentSlide = 0;
    let sliderTimer;

    function showSlide(index) {
        slides[currentSlide].classList.remove('active');
        ticks[currentSlide].classList.remove('active');

        currentSlide = index;

        slides[currentSlide].classList.add('active');
        ticks[currentSlide].classList.add('active');

        const data = slides[currentSlide].dataset;
        eyebrowEl.textContent = data.eyebrow;
        titleEl.textContent   = data.title;
        descEl.textContent    = data.desc;
    }

    function nextSlide() {
        showSlide((currentSlide + 1) % slides.length);
    }

    function restartTimer() {
        clearInterval(sliderTimer);
        sliderTimer = setInterval(nextSlide, 5500);
    }

    ticks.forEach(function (tick) {
        tick.addEventListener('click', function () {
            showSlide(parseInt(tick.dataset.index, 10));
            restartTimer();
        });
    });

    restartTimer();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>