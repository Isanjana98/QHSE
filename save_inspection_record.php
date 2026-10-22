<?php
// save_inspection_record.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// tool_inspection.php eken iframe URL eke ena sub_* params session ekata gannawa
foreach ([$_GET, $_POST] as $src) {
    foreach ($src as $k => $v) {
        if (strpos($k, 'sub_') === 0 && is_string($v) && trim($v) !== '') {
            $_SESSION['sub_ctx'][$k] = trim($v);
        }
    }
}

function sub_val($key, $default = '') {
    $v = $_SESSION['sub_ctx'][$key] ?? '';
    return $v !== '' ? $v : $default;
}

// Checklist form eka ethule render_sub_hidden_fields() kiyala call karanawa.
// Subcontractor details POST ekath ekka yawanna hidden inputs hadanawa.
function render_sub_hidden_fields() {
    $keys = ['sub_company','sub_person','sub_phone','sub_division','sub_id',
             'sub_location','sub_date','sub_supervisor'];
    foreach ($keys as $k) {
        echo '<input type="hidden" name="' . $k . '" value="'
           . htmlspecialchars(sub_val($k), ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
}

function save_inspection_record($conn, $tool_type, $sup_name_fallback = '') {
    // checklist INSERT eka karapu gaman id eka (mekata kalin wena query ekak karanna epa)
    $submission_id = (int)$conn->insert_id;

    $tables = [
        'Welding Machine'  => 'welding_submissions',
        'Concrete Cutting' => 'concrete_cutting_submissions',
        'Portable Drill'   => 'drill_submissions',
        'Hand Grinder'     => 'grinder_submissions',
        'Extension Cord'   => 'extension_submissions',
        'Ladder'           => 'ladder_submissions',
        'Scaffold'         => 'scaffold_submissions',
    ];
    $checklist_table = $tables[$tool_type] ?? '';

    // supervisor_division column eka thiyenawanam witharak ekath ekka save karanawa
    $chk = $conn->query("SHOW COLUMNS FROM tool_inspection_records LIKE 'supervisor_division'");
    $has_div = $chk && $chk->num_rows > 0;

    $cols = "subcontractor_id, supervisor_id, submission_id, checklist_table, company_name, owner_name,
             mobile_number, division, project_location, tool_type, inspection_date, supervisor_name"
          . ($has_div ? ", supervisor_division" : "");
    $marks = rtrim(str_repeat("?, ", $has_div ? 13 : 12), ", ");
    $stmt = $conn->prepare("INSERT INTO tool_inspection_records ($cols) VALUES ($marks)");
    if (!$stmt) { error_log("save_inspection_record prepare failed: " . $conn->error); return false; }

    $sid      = sub_val('sub_id') !== '' ? (int)sub_val('sub_id') : null;
    $sup_id   = isset($_SESSION['supervisor_id']) ? (int)$_SESSION['supervisor_id'] : null;
    // Supervisor name: login session eken first, passe landing page eken, kalin ekata fallback
    $super    = $_SESSION['sup_name'] ?? sub_val('sub_supervisor', $sup_name_fallback);
    $sup_div  = $_SESSION['sup_division'] ?? '';
    $company  = sub_val('sub_company');
    $owner    = sub_val('sub_person');
    $phone    = sub_val('sub_phone');
    $division = sub_val('sub_division');
    $location = sub_val('sub_location');
    $date     = sub_val('sub_date', date('Y-m-d'));

    if ($has_div) {
        $stmt->bind_param("iiissssssssss", $sid, $sup_id, $submission_id, $checklist_table, $company,
                          $owner, $phone, $division, $location, $tool_type, $date, $super, $sup_div);
    } else {
        $stmt->bind_param("iiisssssssss", $sid, $sup_id, $submission_id, $checklist_table, $company,
                          $owner, $phone, $division, $location, $tool_type, $date, $super);
    }
    $ok = $stmt->execute();
    if (!$ok) { error_log("save_inspection_record failed: " . $stmt->error); }
    $stmt->close();

    if ($ok) { unset($_SESSION['sub_ctx']); }
    return $ok;
}