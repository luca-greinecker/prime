<?php
/**
 * onboarding_list.php
 *
 * Manages the onboarding process for new employees across multiple stages:
 * - Status 0: Initial processing (Reception)
 * - Status 1: HR department processing
 * - Status 3: Completed (not shown in this view)
 *
 * Permissions are role-based, with different actions available to different user types.
 *
 * Archivierte Mitarbeiter (status = 9999) werden in allen Anzeigen ausgeblendet.
 */

include 'access_control.php';
global $conn;

// Ensure user is logged in and has appropriate permissions
pruefe_benutzer_eingeloggt();

// Current user ID for permissions and filtering
$current_user_id = $_SESSION['mitarbeiter_id'];

// Initialize error/success messages
$status_message = '';
$status_type = '';

// Show appropriate message if set in session
if (isset($_SESSION['status_message']) && isset($_SESSION['status_type'])) {
    $status_message = $_SESSION['status_message'];
    $status_type = $_SESSION['status_type'];
    unset($_SESSION['status_message']);
    unset($_SESSION['status_type']);
}

// ===================================================
// Fetch employees in onboarding process
// ===================================================

// Status 0: Reception processing
$employees_status0 = [];
if (ist_empfang() || ist_hr() || ist_admin()) {
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $stmt = $conn->prepare("
        SELECT 
            e.employee_id, 
            e.name, 
            e.position, 
            e.entry_date,
            e.crew,
            e.onboarding_status,
            e.bild,
            e.badge_id,
            e.social_security_number,
            e.shoe_size
        FROM 
            employees e
        WHERE 
            e.onboarding_status = 0
            AND e.status != 9999
        ORDER BY 
            e.entry_date DESC, e.name
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees_status0[] = $row;
    }
    $stmt->close();
}

// Status 1: HR processing
$employees_status1 = [];
if (ist_hr() || ist_admin()) {
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $stmt = $conn->prepare("
        SELECT 
            e.employee_id, 
            e.name, 
            e.position, 
            e.entry_date,
            e.crew,
            e.onboarding_status,
            e.bild,
            e.badge_id
        FROM 
            employees e
        WHERE 
            e.onboarding_status = 1
            AND e.status != 9999
        ORDER BY 
            e.entry_date DESC, e.name
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees_status1[] = $row;
    }
    $stmt->close();
}

// Combined for the overview
$all_onboarding = [];
if (ist_hr() || ist_admin()) {
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $sql = "
        SELECT 
            e.employee_id, 
            e.name, 
            e.position, 
            e.entry_date,
            e.crew,
            e.onboarding_status,
            e.bild,
            e.badge_id
        FROM 
            employees e
        WHERE 
            e.onboarding_status IN (0, 1)
            AND e.status != 9999
        ORDER BY 
            e.onboarding_status, e.entry_date DESC, e.name
    ";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $all_onboarding[] = $row;
    }
}

// Total counts
$total_status0 = count($employees_status0);
$total_status1 = count($employees_status1);
$total_onboarding = $total_status0 + $total_status1;

// Determine which tab should be active by default based on user role
$active_tab = '';
if (ist_empfang() && !ist_hr() && !ist_admin()) {
    $active_tab = 'status0'; // Reception users see Reception tab by default
} elseif (ist_hr() || ist_admin()) {
    $active_tab = 'status1'; // HR/Admin users see HR tab by default
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onboarding-Übersicht</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .nav-tabs .nav-link {
            color: #495057;
            background-color: #f8f9fa;
            border-color: #dee2e6 #dee2e6 #fff;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
            font-weight: bold;
        }

        .employee-card {
            height: 100%;
        }

        .employee-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-person-plus-fill me-2"></i>Onboarding-Übersicht</h1>
    </div>

    <?php if (!empty($status_message)): ?>
        <div class="alert alert-<?php echo $status_type; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle me-2"></i><?php echo $status_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs for different stages -->
    <ul class="nav nav-tabs mb-4" id="onboardingTabs" role="tablist">
        <?php if (ist_empfang() && !ist_hr() && !ist_admin()): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link active"
                        id="status0-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#status0"
                        type="button"
                        role="tab"
                        aria-controls="status0"
                        aria-selected="true">
                    <i class="bi bi-box2 me-1"></i>Empfang
                    <?php if ($total_status0 > 0): ?>
                        <span class="badge bg-secondary ms-1"><?php echo $total_status0; ?></span>
                    <?php endif; ?>
                </button>
            </li>
        <?php endif; ?>

        <?php if (ist_hr() || ist_admin()): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo ($active_tab == 'status1') ? 'active' : ''; ?>"
                        id="status1-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#status1"
                        type="button"
                        role="tab"
                        aria-controls="status1"
                        aria-selected="<?php echo ($active_tab == 'status1') ? 'true' : 'false'; ?>">
                    <i class="bi bi-person-vcard me-1"></i>HR
                    <?php if ($total_status1 > 0): ?>
                        <span class="badge bg-secondary ms-1"><?php echo $total_status1; ?></span>
                    <?php endif; ?>
                </button>
            </li>
        <?php endif; ?>

        <?php if (ist_hr() || ist_admin()): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link"
                        id="overview-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#overview"
                        type="button"
                        role="tab"
                        aria-controls="overview"
                        aria-selected="false">
                    <i class="bi bi-list-ul me-1"></i>Gesamt
                    <?php if ($total_onboarding > 0): ?>
                        <span class="badge bg-secondary ms-1"><?php echo $total_onboarding; ?></span>
                    <?php endif; ?>
                </button>
            </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content" id="onboardingTabsContent">
        <!-- Empfang Tab -->
        <?php if (ist_empfang() && !ist_hr() && !ist_admin()): ?>
            <div class="tab-pane fade show active"
                 id="status0"
                 role="tabpanel"
                 aria-labelledby="status0-tab">
                <?php if (count($employees_status0) > 0): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                        <?php foreach ($employees_status0 as $employee): ?>
                            <div class="col">
                                <div class="card shadow-sm employee-card">
                                    <div class="card-body">
                                        <div class="d-flex mb-2">
                                            <?php if (!empty($employee['bild']) && $employee['bild'] !== 'kein-bild.jpg'): ?>
                                                <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($employee['bild']); ?>"
                                                     alt="Bild von <?php echo htmlspecialchars($employee['name']); ?>"
                                                     class="rounded-circle me-3 employee-image">
                                            <?php else: ?>
                                                <div class="d-flex align-items-center justify-content-center me-3 bg-light rounded-circle employee-image">
                                                    <i class="bi bi-person-fill"></i>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($employee['name']); ?></h5>
                                                <p class="card-text mb-0"><?php echo htmlspecialchars($employee['position']); ?></p>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="small text-muted">
                                                <i class="bi bi-calendar-event me-1"></i>
                                                <strong>Eintritt:</strong>
                                                <?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?>
                                            </div>

                                            <a href="employee_onboarding.php?id=<?php echo $employee['employee_id']; ?>"
                                               class="btn btn-primary btn-sm">
                                                <i class="bi bi-pencil-square me-1"></i>Bearbeiten
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h4 class="mt-3">Keine Mitarbeiter für den Empfang</h4>
                        <p class="text-muted">Derzeit gibt es keine Mitarbeiter, die vom Empfang bearbeitet werden
                            müssen.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- HR Tab -->
        <?php if (ist_hr() || ist_admin()): ?>
            <div class="tab-pane fade <?php echo ($active_tab == 'status1') ? 'show active' : ''; ?>"
                 id="status1"
                 role="tabpanel"
                 aria-labelledby="status1-tab">
                <?php if (count($employees_status1) > 0): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                        <?php foreach ($employees_status1 as $employee): ?>
                            <div class="col">
                                <div class="card shadow-sm employee-card">
                                    <div class="card-body">
                                        <div class="d-flex mb-2">
                                            <?php if (!empty($employee['bild']) && $employee['bild'] !== 'kein-bild.jpg'): ?>
                                                <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($employee['bild']); ?>"
                                                     alt="Bild von <?php echo htmlspecialchars($employee['name']); ?>"
                                                     class="rounded-circle me-3 employee-image">
                                            <?php else: ?>
                                                <div class="d-flex align-items-center justify-content-center me-3 bg-light rounded-circle employee-image">
                                                    <i class="bi bi-person-fill"></i>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($employee['name']); ?></h5>
                                                <p class="card-text mb-0"><?php echo htmlspecialchars($employee['position']); ?></p>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="small text-muted">
                                                <i class="bi bi-calendar-event me-1"></i>
                                                <strong>Eintritt:</strong>
                                                <?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?>
                                            </div>

                                            <a href="hr_onboarding.php?id=<?php echo $employee['employee_id']; ?>"
                                               class="btn btn-primary btn-sm">
                                                <i class="bi bi-pencil-square me-1"></i>Bearbeiten
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h4 class="mt-3">Keine Mitarbeiter für HR</h4>
                        <p class="text-muted">Derzeit gibt es keine Mitarbeiter, die von HR bearbeitet werden
                            müssen.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Overview Tab (HR & Admin only) -->
        <?php if (ist_hr() || ist_admin()): ?>
            <div class="tab-pane fade" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                <?php if (count($all_onboarding) > 0): ?>
                    <div class="card shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Alle Mitarbeiter im Onboarding-Prozess</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Position</th>
                                        <th>Eintrittsdatum</th>
                                        <th>Team</th>
                                        <th>Status</th>
                                        <th>Aktion</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($all_onboarding as $employee): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if (!empty($employee['bild']) && $employee['bild'] !== 'kein-bild.jpg'): ?>
                                                        <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($employee['bild']); ?>"
                                                             alt="Bild" class="rounded-circle me-2" width="30"
                                                             height="30">
                                                    <?php else: ?>
                                                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2"
                                                             style="width: 30px; height: 30px;">
                                                            <i class="bi bi-person-fill small"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($employee['name']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($employee['position']); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($employee['crew']); ?></td>
                                            <td>
                                                <?php if ($employee['onboarding_status'] == 0): ?>
                                                    <span class="badge bg-warning text-dark">Empfang</span>
                                                <?php elseif ($employee['onboarding_status'] == 1): ?>
                                                    <span class="badge bg-info">HR</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($employee['onboarding_status'] == 0): ?>
                                                    <a href="employee_onboarding.php?id=<?php echo $employee['employee_id']; ?>"
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                <?php elseif ($employee['onboarding_status'] == 1): ?>
                                                    <a href="hr_onboarding.php?id=<?php echo $employee['employee_id']; ?>"
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h4 class="mt-3">Keine Mitarbeiter im Onboarding</h4>
                        <p class="text-muted">Derzeit gibt es keine Mitarbeiter im Onboarding-Prozess.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>