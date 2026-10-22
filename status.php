<?php
session_start();

if (
    !isset($_SESSION['supervisor_logged']) ||
    $_SESSION['supervisor_logged'] !== true
) {
    header("Location: index.php");
    exit();
}

// =====================================================
// DATABASE CONNECTION
// =====================================================

require_once __DIR__ . '/db_config.php';


// =====================================================
// STATUS BADGE
// =====================================================

function status_badge_html($status)
{
    $status = trim((string)$status);

    if ($status === 'Completed') {
        return "<span class='badge bg-primary px-2 py-2'><i class='bi bi-check2-all me-1'></i>Completed</span>";
    }

    if ($status === 'Rejected') {
        return "<span class='badge bg-danger px-2 py-2'><i class='bi bi-x-circle-fill me-1'></i>Rejected</span>";

    }

    return "
        <span class='badge bg-warning text-dark px-2 py-2'>
            <i class='bi bi-clock-history me-1'></i>
            Pending
        </span>
    ";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Permit Status Tracker | QHSE</title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >
    <link rel="stylesheet" href="table_pagination.css">

    <style>

        body {
            background: #f5f7f6;
            color: #243830;
        }

        .page-header {
            margin-top: 35px;
            margin-bottom: 25px;
        }

        .page-title {
            font-weight: 700;
            color: #0E1F14;
        }

        .tracker-card {
            border: 0;
            border-radius: 18px;
            overflow: hidden;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: #0E1F14;
            color: #ffffff;
            border: none;
            white-space: nowrap;
            font-size: 14px;
            padding: 14px 12px;
        }

        .table tbody td {
            padding: 13px 12px;
            vertical-align: middle;
            font-size: 14px;
        }

        .table tbody tr {
            transition: 0.2s ease;
        }

        .table tbody tr:hover {
            background: #f1f5f3;
        }

        .permit-id {
            font-weight: 700;
            color: #0E1F14;
        }

        .permit-type {
            font-weight: 600;
            white-space: nowrap;
        }

        .permit-number {
            font-weight: 600;
            color: #243830;
        }

        .empty-state {
            padding: 50px 20px !important;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 42px;
            opacity: 0.5;
            display: block;
            margin-bottom: 10px;
        }

        .summary-card {
            border: 0;
            border-radius: 15px;
            background: #ffffff;
        }

        .summary-number {
            font-size: 25px;
            font-weight: 700;
        }

        .summary-label {
            font-size: 13px;
            color: #6c757d;
        }

        .summary-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .icon-total {
            background: #e9f5ed;
            color: #198754;
        }

        .icon-pending {
            background: #fff3cd;
            color: #997404;
        }

        .icon-approved {
            background: #d1e7dd;
            color: #146c43;
        }

        .icon-rejected {
            background: #f8d7da;
            color: #b02a37;
        }

    </style>

</head>

<body>


<?php
// =====================================================
// NAVBAR
// =====================================================
// navbar.php එක මේ file එක තියෙන folder එකේම තිබිය යුතුයි.

include_once "navbar.php";
?>


<!-- =====================================================
     MAIN CONTENT
====================================================== -->

<div class="container-fluid px-4 px-lg-5">

    <!-- PAGE HEADER -->
    <div class="page-header">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div>

                <h2 class="page-title mb-1">

                    <i class="bi bi-file-earmark-text text-warning me-2"></i>

                    All Submitted Permits Tracker

                </h2>

                <div class="text-muted small">

                    Monitor submitted permit status

                </div>

            </div>

        </div>

    </div>


    <!-- =================================================
         FETCH ALL PERMITS
    ================================================== -->

    <?php

    $total_count    = 0;
    $pending_count  = 0;
    $approved_count = 0;
    $rejected_count = 0;

    $permits = [];


    // =================================================
    // 1. HOT WORK PERMITS
    // =================================================

    $hw_result = $conn->query("
        SELECT
            permit_id,
            project,
            permit_number,
            division,
            work_location,
            contact_number,
            status
        FROM hot_work_permits
        ORDER BY permit_id DESC
    ");

    if ($hw_result) {

        while ($row = $hw_result->fetch_assoc()) {

            $status = $row['status'] ?? 'Pending';

            $permits[] = [
                'id'           => $row['permit_id'],
                'type'         => 'Hot Work',
                'type_class'   => 'bg-danger',
                'project'      => $row['project'] ?? 'N/A',
                'permit_no'    => $row['permit_number'] ?? 'N/A',
                'division'     => $row['division'] ?? 'N/A',
                'location'     => $row['work_location'] ?? 'N/A',
                'contact'      => $row['contact_number'] ?? 'N/A',
                'status'       => $status
            ];
        }

    } else {

        echo "
        <div class='alert alert-danger'>
            <i class='bi bi-exclamation-triangle-fill me-2'></i>
            Hot Work permits query failed:
            " . htmlspecialchars($conn->error) . "
        </div>
        ";
    }


    // =================================================
    // 2. WORK AT HEIGHT PERMITS
    // =================================================

    $wah_result = $conn->query("
        SELECT
            id,
            project,
            permit_number,
            division,
            work_location,
            contact_number,
            status
        FROM work_at_height_permits
        ORDER BY id DESC
    ");

    if ($wah_result) {

        while ($row = $wah_result->fetch_assoc()) {

            $status = $row['status'] ?? 'Pending';

            $permits[] = [
                'id'           => $row['id'],
                'type'         => 'Work at Height',
                'type_class'   => 'bg-warning text-dark',
                'project'      => $row['project'] ?? 'N/A',
                'permit_no'    => $row['permit_number'] ?? 'N/A',
                'division'     => $row['division'] ?? 'N/A',
                'location'     => $row['work_location'] ?? 'N/A',
                'contact'      => $row['contact_number'] ?? 'N/A',
                'status'       => $status
            ];
        }

    } else {

        echo "
        <div class='alert alert-danger'>
            <i class='bi bi-exclamation-triangle-fill me-2'></i>
            Work At Height permits query failed:
            " . htmlspecialchars($conn->error) . "
        </div>
        ";
    }


    // =================================================
    // 3. SERVICE PROVIDER PERMITS
    // =================================================

    $sp_result = $conn->query("
        SELECT
            permit_id,
            company_name_address,
            permit_number,
            contact_number,
            status
        FROM service_provider_permits
        ORDER BY permit_id DESC
    ");

    if ($sp_result) {

        while ($row = $sp_result->fetch_assoc()) {

            $status = $row['status'] ?? 'Pending';

            $company = $row['company_name_address'] ?? 'N/A';

            $permits[] = [
                'id'           => $row['permit_id'],
                'type'         => 'Service Provider',
                'type_class'   => 'bg-primary',
                'project'      => $company,
                'permit_no'    => $row['permit_number'] ?? 'N/A',
                'division'     => '-',
                'location'     => '-',
                'contact'      => $row['contact_number'] ?? 'N/A',
                'status'       => $status
            ];
        }

    } else {

        echo "
        <div class='alert alert-danger'>
            <i class='bi bi-exclamation-triangle-fill me-2'></i>
            Service Provider permits query failed:
            " . htmlspecialchars($conn->error) . "
        </div>
        ";
    }


    // =================================================
    // SORT ALL PERMITS
    // =================================================

    usort($permits, function ($a, $b) {

        return $b['id'] <=> $a['id'];

    });


    // =================================================
    // CALCULATE SUMMARY
    // =================================================

    $total_count = count($permits);

    foreach ($permits as $permit) {

        $status = strtolower(trim($permit['status']));

        if ($status === 'approved') {

            $approved_count++;

        } elseif ($status === 'rejected') {

            $rejected_count++;

        } else {

            $pending_count++;
        }
    }

    ?>


    <!-- =================================================
         SUMMARY CARDS
    ================================================== -->

    <div class="row g-3 mb-4">

        <!-- TOTAL -->
        <div class="col-xl-3 col-md-6">

            <div class="card summary-card shadow-sm h-100">

                <div class="card-body d-flex align-items-center">

                    <div class="summary-icon icon-total me-3">

                        <i class="bi bi-files"></i>

                    </div>

                    <div>

                        <div class="summary-number">
                            <?= $total_count ?>
                        </div>

                        <div class="summary-label">
                            Total Permits
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- PENDING -->
        <div class="col-xl-3 col-md-6">

            <div class="card summary-card shadow-sm h-100">

                <div class="card-body d-flex align-items-center">

                    <div class="summary-icon icon-pending me-3">

                        <i class="bi bi-clock-history"></i>

                    </div>

                    <div>

                        <div class="summary-number">
                            <?= $pending_count ?>
                        </div>

                        <div class="summary-label">
                            Pending
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- APPROVED -->
        <div class="col-xl-3 col-md-6">

            <div class="card summary-card shadow-sm h-100">

                <div class="card-body d-flex align-items-center">

                    <div class="summary-icon icon-approved me-3">

                        <i class="bi bi-check-circle-fill"></i>

                    </div>

                    <div>

                        <div class="summary-number">
                            <?= $approved_count ?>
                        </div>

                        <div class="summary-label">
                            Approved
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- REJECTED -->
        <div class="col-xl-3 col-md-6">

            <div class="card summary-card shadow-sm h-100">

                <div class="card-body d-flex align-items-center">

                    <div class="summary-icon icon-rejected me-3">

                        <i class="bi bi-x-circle-fill"></i>

                    </div>

                    <div>

                        <div class="summary-number">
                            <?= $rejected_count ?>
                        </div>

                        <div class="summary-label">
                            Rejected
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =================================================
         PERMIT TABLE
    ================================================== -->

    <div class="card tracker-card shadow-sm mb-5">

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle" data-table-pagination>

                    <thead>

                        <tr>

                            <th>ID</th>

                            <th>Permit Type</th>

                            <th>Project / Company</th>

                            <th>Permit Number</th>

                            <th>Division</th>

                            <th>Work Location / Details</th>

                            <th>Contact Number</th>

                            <th>Status</th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (!empty($permits)): ?>

                        <?php foreach ($permits as $permit): ?>

                            <tr>

                                <!-- ID -->
                                <td class="permit-id">

                                    #<?= htmlspecialchars($permit['id']) ?>

                                </td>


                                <!-- TYPE -->
                                <td>

                                    <span class="badge permit-type <?= $permit['type_class'] ?>">

                                        <?php

                                        if ($permit['type'] === 'Hot Work') {

                                            echo '<i class="bi bi-fire me-1"></i>';

                                        } elseif ($permit['type'] === 'Work at Height') {

                                            echo '<i class="bi bi-building-up me-1"></i>';

                                        } else {

                                            echo '<i class="bi bi-person-workspace me-1"></i>';

                                        }

                                        ?>

                                        <?= htmlspecialchars($permit['type']) ?>

                                    </span>

                                </td>


                                <!-- PROJECT / COMPANY -->
                                <td>

                                    <?= htmlspecialchars($permit['project']) ?>

                                </td>


                                <!-- PERMIT NUMBER -->
                                <td class="permit-number">

                                    <?= htmlspecialchars($permit['permit_no']) ?>

                                </td>


                                <!-- DIVISION -->
                                <td>

                                    <?= htmlspecialchars($permit['division']) ?>

                                </td>


                                <!-- LOCATION -->
                                <td>

                                    <?= htmlspecialchars($permit['location']) ?>

                                </td>


                                <!-- CONTACT -->
                                <td>

                                    <?= htmlspecialchars($permit['contact']) ?>

                                </td>


                                <!-- STATUS -->
                                <td>

                                    <?= status_badge_html($permit['status']) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                    <?php else: ?>

                        <tr>

                            <td
                                colspan="8"
                                class="text-center empty-state"
                            >

                                <i class="bi bi-folder2-open"></i>

                                <strong>No permits found</strong>

                                <div class="small mt-1">
                                    No submitted permits are available at the moment.
                                </div>

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<!-- Bootstrap JS -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>
<script src="table_pagination.js"></script>

</body>
</html>