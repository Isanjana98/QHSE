<?php
session_start();
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/subcontractor_update_links_schema.php';
$completionFields = require __DIR__ . '/subcontractor_completion_fields.php';

if (empty($_SESSION['subcontractor_completion_csrf'])) {
    $_SESSION['subcontractor_completion_csrf'] = bin2hex(random_bytes(32));
}

function loadCompletionRequest(mysqli $conn, string $tokenHash, bool $lock = false): ?array
{
    $sql = "SELECT l.id AS update_link_id, l.expires_at, s.*
              FROM subcontractor_update_links l
              JOIN subcontractors s ON s.id = l.subcontractor_id
             WHERE l.token_hash = ? AND l.consumed_at IS NULL AND l.expires_at > NOW()
             LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $record;
}

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$tokenIsValidFormat = (bool)preg_match('/^[a-f0-9]{64}$/', $token);
$tokenHash = $tokenIsValidFormat ? hash('sha256', $token) : '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$record = null;
$missingFields = [];
$errorMessage = '';
$successMessage = '';
$formValues = [];

if (!$tokenIsValidFormat) {
    $errorMessage = 'This update link is invalid.';
} elseif ($isPost) {
    if (!hash_equals($_SESSION['subcontractor_completion_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errorMessage = 'This form has expired. Reload the page and try again.';
    } else {
        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('Could not start the profile update.');
            }
            $record = loadCompletionRequest($conn, $tokenHash, true);
            if (!$record) {
                throw new RuntimeException('This update link has expired or has already been used.');
            }

            $missingFields = subcontractorMissingProfileFields($record, $completionFields);
            $updates = [];
            $validationErrors = [];

            foreach ($missingFields as $key => $field) {
                if ($key === 'password') {
                    continue;
                }

                $definition = $field;
                if ($definition['type'] === 'checkboxes') {
                    $selected = $_POST[$key] ?? [];
                    if (!is_array($selected)) {
                        $selected = [];
                    }
                    $selected = array_values(array_unique(array_map('strval', $selected)));
                    if (!$selected || array_diff($selected, $definition['options'])) {
                        $validationErrors[$key] = 'Select at least one valid division.';
                    } else {
                        $updates[$key] = implode(', ', $selected);
                        $formValues[$key] = $selected;
                    }
                    continue;
                }

                if ($definition['type'] === 'checkbox') {
                    if (($_POST[$key] ?? '') !== '1') {
                        $validationErrors[$key] = 'Please accept the agreement.';
                    } else {
                        $updates[$key] = 1;
                    }
                    continue;
                }

                $value = trim((string)($_POST[$key] ?? ''));
                $formValues[$key] = $value;
                if ($value === '') {
                    $validationErrors[$key] = 'This field is required.';
                } elseif ($definition['type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $validationErrors[$key] = 'Enter a valid email address.';
                } elseif ($definition['type'] === 'number'
                    && (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0)
                ) {
                    $validationErrors[$key] = 'Enter a whole number greater than or equal to zero.';
                } elseif ($definition['type'] === 'date') {
                    $date = DateTime::createFromFormat('!Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        $validationErrors[$key] = 'Enter a valid date.';
                    } else {
                        $updates[$key] = $value;
                    }
                } else {
                    $updates[$key] = $value;
                }
            }

            $password = (string)($_POST['new_password'] ?? '');
            $passwordConfirm = (string)($_POST['confirm_password'] ?? '');
            $passwordIsRequired = isset($missingFields['password']);
            if ($passwordIsRequired || $password !== '' || $passwordConfirm !== '') {
                if (strlen($password) < 8) {
                    $validationErrors['password'] = 'Use at least 8 characters for the new password.';
                } elseif (!hash_equals($password, $passwordConfirm)) {
                    $validationErrors['password'] = 'The passwords do not match.';
                } else {
                    $updates['password'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $formValues['new_password'] = '';
                $formValues['confirm_password'] = '';
            }

            if ($validationErrors) {
                $errorMessage = 'Please check the highlighted fields and try again.';
                $conn->rollback();
                $record = loadCompletionRequest($conn, $tokenHash);
                $missingFields = $record ? subcontractorMissingProfileFields($record, $completionFields) : [];
            } else {
                if ($updates) {
                    $setParts = [];
                    foreach (array_keys($updates) as $column) {
                        $setParts[] = '`' . $column . '` = ?';
                    }
                    $sql = 'UPDATE subcontractors SET ' . implode(', ', $setParts) . ' WHERE id = ?';
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new RuntimeException('Could not prepare the profile update.');
                    }
                    $values = array_values($updates);
                    $types = '';
                    foreach ($values as $columnValue) {
                        $types .= is_int($columnValue) ? 'i' : 's';
                    }
                    $values[] = (int)$record['id'];
                    $types .= 'i';
                    $bindArguments = [$types];
                    foreach ($values as &$value) {
                        $bindArguments[] = &$value;
                    }
                    $bound = call_user_func_array([$stmt, 'bind_param'], $bindArguments);
                    unset($value);
                    if (!$bound) {
                        $stmt->close();
                        throw new RuntimeException('Could not bind profile fields.');
                    }
                    if (!$stmt->execute()) {
                        $errorCode = $stmt->errno;
                        $stmt->close();
                        throw new RuntimeException('Could not save the profile details.', $errorCode);
                    }
                    $stmt->close();
                }

                $consume = $conn->prepare('UPDATE subcontractor_update_links SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL');
                if (!$consume) {
                    throw new RuntimeException('Could not complete the update link.');
                }
                $linkId = (int)$record['update_link_id'];
                $consume->bind_param('i', $linkId);
                if (!$consume->execute() || $consume->affected_rows !== 1) {
                    $consume->close();
                    throw new RuntimeException('This update link has expired or has already been used.');
                }
                $consume->close();
                $conn->commit();
                $record = null;
                $missingFields = [];
                $successMessage = 'Your details have been updated successfully. Thank you.';
            }
        } catch (Throwable $error) {
            $conn->rollback();
            if ((int)$error->getCode() === 1062) {
                $errorMessage = 'That username or email address is already in use. Please check the details and try again.';
                $record = loadCompletionRequest($conn, $tokenHash);
                $missingFields = $record ? subcontractorMissingProfileFields($record, $completionFields) : [];
            } else {
                $errorMessage = $error->getMessage() === 'This update link has expired or has already been used.'
                    ? $error->getMessage()
                    : 'We could not save your details. Please try again.';
                $record = loadCompletionRequest($conn, $tokenHash);
                $missingFields = $record ? subcontractorMissingProfileFields($record, $completionFields) : [];
                error_log('complete_subcontractor.php: ' . $error->getMessage());
            }
        }
    }
} elseif ($tokenIsValidFormat) {
    $record = loadCompletionRequest($conn, $tokenHash);
    if (!$record) {
        $errorMessage = 'This update link has expired or has already been used.';
    } else {
        $missingFields = subcontractorMissingProfileFields($record, $completionFields);
    }
}

$conn->close();

function completionValue(array $record, array $field, string $key): string
{
    if ($key === 'password' || $key === 'agreement_accept') {
        return '';
    }
    return (string)($record[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Subcontractor Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --orange:#ea580c; --orange-dark:#9a3412; --orange-pale:#fff7ed; --ink:#17211a; --muted:#667085; --line:#e5e7eb; }
        * { box-sizing:border-box; }
        body { margin:0; padding:28px 16px 48px; background:#f6f8f5; color:var(--ink); font-family:'Inter',sans-serif; }
        .completion-wrap { max-width:920px; margin:0 auto; }
        .completion-header { padding:22px 24px; border:1px solid #fed7aa; border-left:5px solid var(--orange); border-radius:10px; background:#fff; margin-bottom:16px; }
        .completion-brand { display:flex; justify-content:center; margin-bottom:12px; }
        .completion-brand img { display:block; width:auto; max-width:190px; max-height:58px; object-fit:contain; }
        .completion-header h1 { margin:0; color:#7c2d12; font:800 22px 'Manrope',sans-serif; }
        .completion-header p { margin:5px 0 0; color:var(--muted); font-size:13px; }
        .profile-section { background:#fff; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
        .profile-section-head { padding:13px 18px; background:var(--orange-pale); border-bottom:1px solid #fed7aa; color:#7c2d12; font:700 14px 'Manrope',sans-serif; }
        .profile-fields { padding:18px; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px 16px; }
        .field { min-width:0; }
        .field.span-two { grid-column:1/-1; }
        .field-label { display:block; margin-bottom:5px; color:#344054; font-size:12px; font-weight:700; }
        .field-label .required { color:#c2410c; }
        .field-control { width:100%; min-height:39px; border:1px solid #d0d5dd; border-radius:7px; padding:8px 10px; background:#fff; color:#344054; font:13px 'Inter',sans-serif; }
        .field-control:focus { outline:0; border-color:var(--orange); box-shadow:0 0 0 3px rgba(234,88,12,.12); }
        .field-control[readonly] { background:#f8fafc; color:#475467; }
        textarea.field-control { min-height:76px; resize:vertical; }
        .division-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; }
        .division-option { display:flex; gap:8px; align-items:flex-start; min-height:38px; padding:9px 10px; border:1px solid var(--line); border-radius:7px; font-size:12px; }
        .division-option input { margin-top:2px; accent-color:var(--orange); }
        .readonly-note { display:block; margin-top:4px; color:#98a2b3; font-size:10px; }
        .agreement-box { display:flex; gap:10px; align-items:flex-start; padding:13px; border:1px solid #fed7aa; border-radius:8px; background:#fffaf5; font-size:12px; line-height:1.5; }
        .agreement-box input { margin-top:3px; accent-color:var(--orange); }
        .completion-footer { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:16px 18px; border-top:1px solid var(--line); }
        .completion-footer p { margin:0; color:var(--muted); font-size:11px; }
        .submit-btn { min-height:42px; padding:0 18px; border:0; border-radius:7px; background:var(--orange); color:#fff; font-weight:700; }
        .submit-btn:hover { background:#c2410c; }
        .alert { border-radius:8px; font-size:13px; }
        .state-panel { padding:32px 24px; border:1px solid var(--line); border-radius:10px; background:#fff; text-align:center; }
        .state-panel i { display:block; margin-bottom:10px; color:var(--orange); font-size:30px; }
        .state-panel h2 { color:#7c2d12; font:800 18px 'Manrope',sans-serif; }
        .field-error { margin-top:4px; color:#b42318; font-size:11px; }
        @media(max-width:640px) { body { padding:14px 10px 28px; } .completion-header { padding:18px; } .profile-fields { grid-template-columns:1fr; padding:14px; } .field.span-two { grid-column:auto; } .division-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .completion-footer { align-items:stretch; flex-direction:column; } .submit-btn { width:100%; } }
    </style>
</head>
<body>
<main class="completion-wrap">
    <header class="completion-header">
        <div class="completion-brand"><img src="assets/images/hayleys_logo.png" alt="Hayleys Fentons" onerror="this.style.display='none'"></div>
        <h1>Complete Your Company Details</h1>
        <p><?= $record ? 'Review your saved details and fill in any highlighted missing information.' : 'Secure subcontractor profile completion'; ?></p>
    </header>

    <?php if ($successMessage !== ''): ?>
        <div class="state-panel"><i class="bi bi-check-circle-fill"></i><h2>Details updated</h2><p class="mb-0"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></p></div>
    <?php elseif (!$record): ?>
        <div class="state-panel"><i class="bi bi-link-45deg"></i><h2>Link unavailable</h2><p class="mb-0"><?= htmlspecialchars($errorMessage !== '' ? $errorMessage : 'This update link is invalid.', ENT_QUOTES, 'UTF-8'); ?> Please contact the administrator for a new link.</p></div>
    <?php else: ?>
        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger mb-3" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (!$missingFields): ?>
            <div class="state-panel"><i class="bi bi-check-circle-fill"></i><h2>Your details are complete</h2><p class="mb-0">No further information is required. Thank you.</p></div>
        <?php else: ?>
            <form method="post" action="complete_subcontractor.php" novalidate>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['subcontractor_completion_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <section class="profile-section">
                    <div class="profile-section-head"><i class="bi bi-building me-1"></i> Company and owner information</div>
                    <div class="profile-fields">
                        <?php foreach ($completionFields as $key => $field): ?>
                            <?php if ($key === 'password') continue; ?>
                            <?php
                            $isMissing = isset($missingFields[$key]);
                            $value = $formValues[$key] ?? completionValue($record, $field, $key);
                            $isWide = in_array($key, ['company_address', 'owner_address', 'machineries', 'tools', 'division', 'agreement_accept'], true);
                            $fieldId = 'field_' . $key;
                            ?>
                            <div class="field <?= $isWide ? 'span-two' : ''; ?>">
                                <label class="field-label" for="<?= htmlspecialchars($fieldId); ?>">
                                    <?= htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($isMissing): ?><span class="required">*</span><?php endif; ?>
                                </label>

                                <?php if ($field['type'] === 'checkboxes'): ?>
                                    <?php $selectedDivisions = is_array($value) ? $value : array_filter(array_map('trim', explode(',', $value))); ?>
                                    <?php if ($isMissing): ?>
                                        <div class="division-grid">
                                            <?php foreach ($field['options'] as $option): ?>
                                                <?php $optionId = $fieldId . '_' . substr(hash('sha1', $option), 0, 8); ?>
                                                <label class="division-option" for="<?= htmlspecialchars($optionId); ?>">
                                                    <input id="<?= htmlspecialchars($optionId); ?>" type="checkbox" name="division[]" value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($option, $selectedDivisions, true) ? 'checked' : ''; ?>>
                                                    <span><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <input class="field-control" id="<?= htmlspecialchars($fieldId); ?>" value="<?= htmlspecialchars(implode(', ', $selectedDivisions), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                        <small class="readonly-note">To change an existing value, contact the administrator.</small>
                                    <?php endif; ?>
                                <?php elseif ($field['type'] === 'checkbox'): ?>
                                    <label class="agreement-box" for="<?= htmlspecialchars($fieldId); ?>">
                                        <input id="<?= htmlspecialchars($fieldId); ?>" type="checkbox" name="agreement_accept" value="1" <?= !$isMissing ? 'checked disabled' : ''; ?>>
                                        <span>I confirm that the information provided is accurate and accept the agreement and guidelines.</span>
                                    </label>
                                    <?php if (!$isMissing): ?><small class="readonly-note">Agreement acceptance is already recorded.</small><?php endif; ?>
                                <?php elseif ($field['type'] === 'select'): ?>
                                    <?php if ($isMissing): ?>
                                        <select class="field-control" id="<?= htmlspecialchars($fieldId); ?>" name="<?= htmlspecialchars($key); ?>" required>
                                            <option value="">Select an option</option>
                                            <?php foreach ($field['options'] as $option): ?>
                                                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>" <?= $value === $option ? 'selected' : ''; ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input class="field-control" id="<?= htmlspecialchars($fieldId); ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                        <small class="readonly-note">To change an existing value, contact the administrator.</small>
                                    <?php endif; ?>
                                <?php elseif ($field['type'] === 'textarea'): ?>
                                    <textarea class="field-control" id="<?= htmlspecialchars($fieldId); ?>" name="<?= $isMissing ? htmlspecialchars($key) : ''; ?>" <?= $isMissing ? 'required' : 'readonly'; ?>><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    <?php if (!$isMissing): ?><small class="readonly-note">To change an existing value, contact the administrator.</small><?php endif; ?>
                                <?php else: ?>
                                    <input class="field-control" id="<?= htmlspecialchars($fieldId); ?>" type="<?= htmlspecialchars($field['type']); ?>" name="<?= $isMissing ? htmlspecialchars($key) : ''; ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?= $isMissing ? 'required' : 'readonly'; ?> <?= $field['type'] === 'number' ? 'min="0" step="1"' : ''; ?>>
                                    <?php if (!$isMissing): ?><small class="readonly-note">To change an existing value, contact the administrator.</small><?php endif; ?>
                                <?php endif; ?>

                                <?php if (!empty($errorMessage) && isset($formValues[$key]) && $isMissing && $value === ''): ?>
                                    <div class="field-error">This field is required.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php $passwordIsMissing = isset($missingFields['password']); ?>
                        <div class="field span-two">
                            <label class="field-label" for="new_password">
                                <?= $passwordIsMissing ? 'Create Account Password' : 'Set a New Password (Optional)'; ?>
                                <?php if ($passwordIsMissing): ?><span class="required">*</span><?php endif; ?>
                            </label>
                            <input class="field-control" id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" <?= $passwordIsMissing ? 'required' : ''; ?>>
                            <small class="readonly-note">Passwords are never displayed. Enter at least 8 characters<?= $passwordIsMissing ? '.' : ', or leave blank to keep the current password.'; ?></small>
                            <label class="field-label mt-2" for="confirm_password">Confirm New Password<?= $passwordIsMissing ? ' *' : ''; ?></label>
                            <input class="field-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" <?= $passwordIsMissing ? 'required' : ''; ?>>
                        </div>
                    </div>
                    <div class="completion-footer">
                        <p>Existing saved information is locked. Only missing details can be added through this link.</p>
                        <button class="submit-btn" type="submit"><i class="bi bi-check2-circle me-1"></i> Save Details</button>
                    </div>
                </section>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>