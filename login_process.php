<?php

session_start();


require_once __DIR__ . '/db_config.php';

function open_android_tablet_app(mysqli $conn, int $subcontractorId): void
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $platform = trim($_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? '', '"');
    $mobileHint = trim($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '');
    $isAndroid = stripos($userAgent, 'Android') !== false || strcasecmp($platform, 'Android') === 0;
    $isTablet = $mobileHint === '?0' || stripos($userAgent, 'Mobile') === false;

    if (!$isAndroid || !$isTablet) {
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/i', $host)) {
        return;
    }

    $ticket = bin2hex(random_bytes(32));
    $ticketHash = hash('sha256', $ticket);
    $stmt = $conn->prepare(
        "INSERT INTO api_tokens (token_hash, kind, user_role, user_id, expires_at)
         VALUES (?, 'app_login', 'subcontractor', ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('si', $ticketHash, $subcontractorId);
    $stmt->execute();
    $ticketCreated = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$ticketCreated) {
        return;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $fallbackUrl = $scheme . '://' . $host . $basePath . '/subcontractor_dashboard.php';
    $intentUrl = 'intent://login?ticket=' . $ticket
        . '#Intent;scheme=qhse-subcontractor;package=com.example.subcontractor_app;S.browser_fallback_url='
        . rawurlencode($fallbackUrl) . ';end';

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Opening QHSE app</title><p>Opening the QHSE mobile app...</p>';
    echo '<p><a href="' . htmlspecialchars($intentUrl, ENT_QUOTES, 'UTF-8') . '">Open the QHSE mobile app</a></p>';
    echo '<script>window.location.replace(' . json_encode($intentUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
    echo '</html>';
    exit();
}


// =====================================================
// LOGIN PROCESS
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $user_input = trim($_POST['username'] ?? '');
    $pass_input = $_POST['password'] ?? '';


    // Empty fields
    if ($user_input === '' || $pass_input === '') {

        $_SESSION['login_error'] = "Please enter username and password.";

        header("Location: index.php");
        exit();
    }


    /*
    =====================================================
    IMPORTANT
    Clear old login role/session values
    =====================================================
    */

    unset(
        $_SESSION['admin_logged'],
        $_SESSION['admin_username'],

        $_SESSION['supervisor_logged'],
        $_SESSION['sup_id'],
        $_SESSION['supervisor_id'],
        $_SESSION['sup_name'],
        $_SESSION['sup_division'],
        $_SESSION['sup_username'],

        $_SESSION['subcontractor_id'],
        $_SESSION['username'],
        $_SESSION['company_name'],

        $_SESSION['user_role']
    );


    // =================================================
    // 1. ADMIN LOGIN
    // =================================================

    if (
        $user_input === 'admin' &&
        $pass_input === '1234'
    ) {

        $_SESSION['admin_logged']   = true;
        $_SESSION['admin_username'] = 'admin';

        // Main role
        $_SESSION['user_role'] = 'admin';


        // Admin Dashboard
        header("Location: admin_dashboard.php");
        exit();
    }


    // =================================================
    // 2. SUPERVISOR LOGIN
    // =================================================

    $sup_stmt = $conn->prepare("
        SELECT
            id,
            name,
            division,
            username,
            password,
            status
        FROM supervisors
        WHERE username = ?
        LIMIT 1
    ");

    if (!$sup_stmt) {

        die("Supervisor SQL Error: " . $conn->error);
    }


    $sup_stmt->bind_param(
        "s",
        $user_input
    );

    $sup_stmt->execute();

    $sup_result = $sup_stmt->get_result();


    if (
        $sup_result &&
        $sup_result->num_rows === 1
    ) {

        $sup_row = $sup_result->fetch_assoc();


        // Verify password
        if (
            password_verify(
                $pass_input,
                $sup_row['password']
            )
        ) {

            // =========================================
            // ACCOUNT STATUS CHECK
            // Admin approve karala thiyennata "Approved"
            // status ekak thiyenna ona login wenna.
            // =========================================

            $sup_status = $sup_row['status'] ?? 'Pending';


            if ($sup_status === 'Pending') {

                $_SESSION['login_error'] =
                    "Your account is still pending admin approval. Please wait for approval before logging in.";

                $sup_stmt->close();

                header("Location: index.php");
                exit();
            }


            if ($sup_status === 'Rejected') {

                $_SESSION['login_error'] =
                    "Your registration request has been rejected by the admin.";

                $sup_stmt->close();

                header("Location: index.php");
                exit();
            }


            // =========================================
            // SUPERVISOR SESSION
            // (Only reached when status === 'Approved')
            // =========================================

            $_SESSION['supervisor_logged'] = true;

            $_SESSION['sup_id'] = $sup_row['id'];

            $_SESSION['supervisor_id'] = $sup_row['id'];

            $_SESSION['sup_name'] = $sup_row['name'];

            $_SESSION['sup_division'] = $sup_row['division'];

            $_SESSION['sup_username'] = $sup_row['username'];


            // Main role
            $_SESSION['user_role'] = 'supervisor';


            $sup_stmt->close();

            // Supervisor Dashboard
            header("Location: supervisor_dashboard.php");
            exit();

        } else {

            $_SESSION['login_error'] =
                "Invalid Password. Please try again.";

            $sup_stmt->close();

            header("Location: index.php");
            exit();
        }
    }


    $sup_stmt->close();


    // =================================================
    // 3. SUBCONTRACTOR LOGIN
    // =================================================

    $sub_stmt = $conn->prepare("
        SELECT
            id,
            username,
            password,
            company_name,
            status
        FROM subcontractors
        WHERE username = ?
        LIMIT 1
    ");

    if (!$sub_stmt) {

        die("Subcontractor SQL Error: " . $conn->error);
    }


    $sub_stmt->bind_param(
        "s",
        $user_input
    );

    $sub_stmt->execute();

    $sub_result = $sub_stmt->get_result();


    if (
        $sub_result &&
        $sub_result->num_rows === 1
    ) {

        $sub_row = $sub_result->fetch_assoc();


        // Verify password
        if (
            password_verify(
                $pass_input,
                $sub_row['password']
            )
        ) {

            // =========================================
            // ACCOUNT STATUS
            // =========================================

            $status = $sub_row['status'] ?? 'Pending';


            if ($status === 'Pending') {

                $_SESSION['login_error'] =
                    "Your account is still pending admin approval.";

                header("Location: index.php");
                exit();
            }


            if ($status === 'Rejected') {

                $_SESSION['login_error'] =
                    "Your account has been rejected by the admin.";

                header("Location: index.php");
                exit();
            }


            // =========================================
            // SUBCONTRACTOR SESSION
            // =========================================

            $_SESSION['subcontractor_id'] =
                $sub_row['id'];

            $_SESSION['username'] =
                $sub_row['username'];

            $_SESSION['company_name'] =
                $sub_row['company_name'];


            // Main role
            $_SESSION['user_role'] =
                'subcontractor';


            open_android_tablet_app($conn, (int)$sub_row['id']);

            // Subcontractor Dashboard
            header("Location: subcontractor_dashboard.php");
            exit();

        } else {

            $_SESSION['login_error'] =
                "Invalid Password. Please try again.";

            header("Location: index.php");
            exit();
        }

    } else {

        $_SESSION['login_error'] =
            "Username not found. Please register or check again.";

        header("Location: index.php");
        exit();
    }


    $sub_stmt->close();
}


$conn->close();

?>