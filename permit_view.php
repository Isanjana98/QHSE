/* =========================================================================
   PERMIT VIEW — SHARED DESIGN SYSTEM
   Used by: view_hot_work.php · view_service_provider.php · view_wah.php

   CONCEPT
   A work permit is a legal safety document — this is styled like one:
   a clean printed certificate with a rotated inspector's stamp as the
   one signature element, rather than another generic admin card page.

   PER-PERMIT ACCENT
   Put one of these classes on <body>:
     .permit-hot      → ember  (Hot Work)
     .permit-height   → cobalt (Work At Height)
     .permit-service  → teal   (Service Provider)
   ========================================================================= */

@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap');

:root{
    /* Paper + ink */
    --paper:        #EEF1EF;
    --surface:      #FFFFFF;
    --ink:          #12191C;
    --ink-soft:     #52605E;
    --ink-faint:    #8D9995;
    --line:         #E2E7E3;
    --line-strong:  #CBD3CE;

    /* Status */
    --success:      #157F3C;
    --success-soft: #E3F5E9;
    --warning:      #B8790A;
    --warning-soft: #FBEEDC;
    --danger:       #B3261E;
    --danger-soft:  #FBE6E4;

    /* Default accent (overridden per permit type below) */
    --accent:       #1D5FA8;
    --accent-soft:  #E3EDF9;
    --accent-ink:   #10386B;

    --radius-lg: 20px;
    --radius-md: 14px;
    --radius-sm: 9px;

    --shadow-sm: 0 2px 10px rgba(18,25,28,.06);
    --shadow-md: 0 14px 34px rgba(18,25,28,.10);
    --shadow-lg: 0 30px 70px rgba(18,25,28,.16);
}

/* ---- per-type accents -------------------------------------------------- */
body.permit-hot{
    --accent:      #C4501C;
    --accent-soft: #FBEADF;
    --accent-ink:  #7C3110;
}
body.permit-height{
    --accent:      #1D5FA8;
    --accent-soft: #E3EDF9;
    --accent-ink:  #10386B;
}
body.permit-service{
    --accent:      #0F766E;
    --accent-soft: #E1F2F0;
    --accent-ink:  #0B4B46;
}

/* ---- reset --------------------------------------------------------------*/
*{ margin:0; padding:0; box-sizing:border-box; }

body{
    background:
        radial-gradient(1100px 460px at 6% -8%, color-mix(in srgb, var(--accent) 8%, transparent), transparent 60%),
        var(--paper);
    color:var(--ink);
    font-family:'Inter',sans-serif;
    -webkit-font-smoothing:antialiased;
    padding-bottom:110px; /* room for sticky action bar */
}

h1,h2,h3,h4,h5,h6{ font-family:'Space Grotesk',sans-serif; letter-spacing:-.01em; }

a{ color:inherit; }

/* ---- top utility bar ------------------------------------------------- */
.doc-topbar{
    max-width:980px;
    margin:22px auto 0;
    padding:0 20px;
    display:flex;
    align-items:center;
    justify-content:space-between;
}

.doc-brand{
    display:flex;
    align-items:center;
    gap:10px;
    font-family:'Space Grotesk',sans-serif;
    font-weight:600;
    font-size:13px;
    letter-spacing:.06em;
    text-transform:uppercase;
    color:var(--ink-soft);
}
.doc-brand i{ color:var(--accent); font-size:16px; }

.btn-back{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:13px;
    font-weight:600;
    color:var(--ink-soft);
    text-decoration:none;
    padding:8px 14px;
    border-radius:100px;
    border:1px solid var(--line-strong);
    background:var(--surface);
    transition:.2s ease;
}
.btn-back:hover{ border-color:var(--accent); color:var(--accent-ink); background:var(--accent-soft); }

/* ---- document shell ---------------------------------------------------*/
.doc{
    max-width:980px;
    margin:16px auto 40px;
    padding:0 20px;
}

.doc-sheet{
    background:var(--surface);
    border:1px solid var(--line);
    border-radius:var(--radius-lg);
    box-shadow:var(--shadow-lg);
    overflow:hidden;
}

/* ---- header ------------------------------------------------------------*/
.doc-header{
    position:relative;
    padding:34px 40px 28px;
    background:
        linear-gradient(180deg, color-mix(in srgb, var(--accent) 6%, transparent), transparent 70%);
    border-bottom:1px solid var(--line);
}

.doc-eyebrow{
    display:inline-flex;
    align-items:center;
    gap:8px;
    font-size:12px;
    font-weight:700;
    letter-spacing:.14em;
    text-transform:uppercase;
    color:var(--accent-ink);
    background:var(--accent-soft);
    padding:6px 12px;
    border-radius:100px;
    margin-bottom:16px;
}

.doc-title{
    font-size:30px;
    font-weight:700;
    line-height:1.15;
    max-width:70%;
}

.doc-subtitle{
    margin-top:8px;
    font-size:14.5px;
    color:var(--ink-soft);
    max-width:70%;
}

.doc-permit-no{
    font-family:'JetBrains Mono',monospace;
    font-weight:600;
    color:var(--ink);
    background:var(--paper);
    border:1px solid var(--line-strong);
    padding:2px 8px;
    border-radius:6px;
    font-size:13px;
}

/* ---- the stamp — signature element ------------------------------------*/
.stamp{
    position:absolute;
    top:26px;
    right:36px;
    width:112px;
    height:112px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    transform:rotate(-9deg);
    user-select:none;
}
.stamp-ring{
    position:absolute;
    inset:0;
    border-radius:50%;
    border:2.5px dashed currentColor;
    opacity:.55;
}
.stamp-ring::before{
    content:"";
    position:absolute;
    inset:8px;
    border-radius:50%;
    border:1px solid currentColor;
    opacity:.7;
}
.stamp-text{
    font-family:'Space Grotesk',sans-serif;
    font-weight:700;
    font-size:12.5px;
    letter-spacing:.09em;
    text-transform:uppercase;
    line-height:1.25;
    padding:0 10px;
}
.stamp.is-approved{ color:var(--success); }
.stamp.is-pending{  color:var(--warning); }
.stamp.is-rejected{ color:var(--danger); }

@media(max-width:640px){
    .stamp{ width:88px; height:88px; top:20px; right:20px; }
    .stamp-text{ font-size:10.5px; }
    .doc-title, .doc-subtitle{ max-width:62%; }
}

/* ---- body sections -------------------------------------------------- */
.doc-body{ padding:8px 40px 36px; }

.doc-section{ padding:26px 0; border-top:1px solid var(--line); }
.doc-section:first-child{ border-top:none; }

.doc-section-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:14px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    color:var(--accent-ink);
    margin-bottom:18px;
}
.doc-section-title .icon-badge{
    width:30px; height:30px;
    border-radius:9px;
    display:flex; align-items:center; justify-content:center;
    background:var(--accent-soft);
    color:var(--accent);
    font-size:15px;
    flex-shrink:0;
}

/* field grid — scannable label/value pairs instead of stacked rows */
.field-grid{
    display:grid;
    grid-template-columns:repeat(2, 1fr);
    gap:18px 28px;
}
.field-grid.cols-3{ grid-template-columns:repeat(3, 1fr); }
.field-grid.full{ grid-template-columns:1fr; }

.field{ min-width:0; }
.field-label{
    font-size:11.5px;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:var(--ink-faint);
    margin-bottom:4px;
}
.field-value{
    font-size:14.5px;
    color:var(--ink);
    font-weight:500;
    word-wrap:break-word;
}
.field-value.muted{ color:var(--ink-faint); font-weight:400; font-style:italic; }
.field-value.mono{ font-family:'JetBrains Mono',monospace; font-size:13.5px; }
.field-value.prose{ font-weight:400; line-height:1.6; color:var(--ink-soft); }

.field.span-2{ grid-column:span 2; }

@media(max-width:640px){
    .field-grid, .field-grid.cols-3{ grid-template-columns:1fr; }
    .field.span-2{ grid-column:span 1; }
    .doc-body{ padding:4px 22px 28px; }
    .doc-header{ padding:28px 22px 24px; }
}

/* ---- chips ---------------------------------------------------------- */
.chip-row{ display:flex; flex-wrap:wrap; gap:8px; }
.chip{
    display:inline-flex;
    align-items:center;
    background:var(--accent-soft);
    color:var(--accent-ink);
    padding:6px 13px;
    border-radius:100px;
    font-size:12.5px;
    font-weight:600;
}

/* ---- tables ----------------------------------------------------------*/
.doc-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    border:1px solid var(--line);
    border-radius:var(--radius-md);
    overflow:hidden;
    font-size:13.5px;
}
.doc-table thead th{
    background:var(--ink);
    color:#fff;
    font-family:'Space Grotesk',sans-serif;
    font-weight:600;
    font-size:11.5px;
    letter-spacing:.07em;
    text-transform:uppercase;
    text-align:left;
    padding:12px 14px;
}
.doc-table tbody td{
    padding:11px 14px;
    border-top:1px solid var(--line);
    color:var(--ink);
    vertical-align:top;
}
.doc-table tbody tr:hover{ background:var(--paper); }
.doc-table .empty-cell{ text-align:center; color:var(--ink-faint); padding:22px; }

.answer-yes{ color:var(--success); font-weight:700; }
.answer-no{ color:var(--danger); font-weight:700; }

/* ---- checklist (styled list, not just a table) ------------------------*/
.checklist{ list-style:none; }
.checklist li{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:9px 0;
    border-top:1px solid var(--line);
    font-size:14px;
    color:var(--ink);
}
.checklist li:first-child{ border-top:none; }
.checklist i{ color:var(--accent); margin-top:2px; flex-shrink:0; }

/* ---- image gallery ---------------------------------------------------*/
.img-gallery{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(130px, 1fr));
    gap:12px;
}
.img-gallery a{
    display:block;
    border-radius:var(--radius-sm);
    overflow:hidden;
    border:1px solid var(--line);
    aspect-ratio:1/1;
    position:relative;
    transition:.2s ease;
}
.img-gallery a:hover{ transform:translateY(-3px); box-shadow:var(--shadow-sm); border-color:var(--accent); }
.img-gallery img{ width:100%; height:100%; object-fit:cover; display:block; }

.empty-note{
    color:var(--ink-faint);
    font-size:13.5px;
    font-style:italic;
    padding:6px 0;
}

/* ---- record-info footer strip ----------------------------------------*/
.doc-meta{
    display:flex;
    flex-wrap:wrap;
    gap:24px;
    padding:20px 40px;
    background:var(--paper);
    border-top:1px solid var(--line);
    font-size:12.5px;
    color:var(--ink-faint);
}
.doc-meta strong{ color:var(--ink-soft); font-weight:600; }

@media(max-width:640px){ .doc-meta{ padding:16px 22px; } }

/* ---- sticky action bar ------------------------------------------------*/
.action-bar{
    position:fixed;
    left:0; right:0; bottom:0;
    background:rgba(255,255,255,.92);
    backdrop-filter:blur(10px);
    border-top:1px solid var(--line);
    padding:14px 20px;
    display:flex;
    justify-content:center;
    gap:12px;
    z-index:20;
}
.btn-doc{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:11px 22px;
    border-radius:100px;
    font-size:14px;
    font-weight:600;
    font-family:'Space Grotesk',sans-serif;
    border:1px solid transparent;
    cursor:pointer;
    text-decoration:none;
    transition:.2s ease;
}
.btn-doc-primary{ background:var(--accent); color:#fff; }
.btn-doc-primary:hover{ filter:brightness(.92); }
.btn-doc-ghost{ background:var(--surface); color:var(--ink-soft); border-color:var(--line-strong); }
.btn-doc-ghost:hover{ border-color:var(--ink-faint); color:var(--ink); }

/* ---- focus visibility --------------------------------------------------*/
a:focus-visible, button:focus-visible{
    outline:2px solid var(--accent);
    outline-offset:2px;
}

/* ---- reduced motion -----------------------------------------------------*/
@media (prefers-reduced-motion: reduce){
    .img-gallery a, .btn-back, .btn-doc{ transition:none; }
}

/* ---- print ---------------------------------------------------------- */
@media print{
    body{ background:#fff; padding-bottom:0; }
    .doc-topbar, .action-bar{ display:none !important; }
    .doc{ max-width:none; margin:0; padding:0; }
    .doc-sheet{ box-shadow:none; border:none; border-radius:0; }
    .doc-header{ background:none; }
    .stamp{ position:absolute; }
}