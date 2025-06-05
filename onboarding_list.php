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

// Handle AJAX delete request for missing employees
if (isset($_POST['action']) && $_POST['action'] === 'delete_missing' && isset($_POST['id'])) {
    if (ist_hr() || ist_admin()) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM missing_employees WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Fehler beim Löschen']);
        }
        $stmt->close();
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
        exit;
    }
}

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

// ===================================================
// Fetch employees detected by external system but missing in PRIME
// ===================================================
$missing_employees = [];
if (ist_hr() || ist_admin()) {
    $stmt = $conn->prepare(
        "SELECT id, name, last_present, badge_id FROM missing_employees ORDER BY last_present DESC, name"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $missing_employees[] = $row;
    }
    $stmt->close();
}
$total_missing = count($missing_employees);

// Determine which tab should be active by default based on user role
$active_tab = '';
if (ist_empfang() && !ist_hr() && !ist_admin()) {
    $active_tab = 'status0';
} elseif (ist_hr() || ist_admin()) {
    $active_tab = 'status1';
}

// Helper function for employee image
function renderEmployeeImage($employee, $size = '50px', $classes = 'rounded-circle')
{
    if (!empty($employee['bild']) && $employee['bild'] !== 'kein-bild.jpg') {
        return sprintf(
            '<img src="../mitarbeiter-anzeige/fotos/%s" alt="Bild von %s" class="%s" style="width: %s; height: %s; object-fit: cover;">',
            htmlspecialchars($employee['bild']),
            htmlspecialchars($employee['name']),
            $classes,
            $size,
            $size
        );
    } else {
        return sprintf(
            '<div class="bg-light %s d-flex align-items-center justify-content-center" style="width: %s; height: %s;"><i class="bi bi-person-fill"></i></div>',
            $classes,
            $size,
            $size
        );
    }
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
        /* Tab-Styling mit wichtigen Overrides für Lesbarkeit */
        .nav-tabs .nav-link {
            color: #495057 !important;
            background-color: #f8f9fa;
            border-color: #dee2e6;
            transition: all 0.3s ease;
        }

        .nav-tabs .nav-link:hover {
            color: #0d6efd !important;
            background-color: #e9ecef;
            border-color: #dee2e6;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd !important;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
            font-weight: 600;
        }

        /* Sicherstellen dass Badge-Farben korrekt sind */
        .nav-tabs .badge {
            vertical-align: middle;
        }

        /* Card Hover-Effekt */
        .employee-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid rgba(0, 0, 0, .125);
        }

        .employee-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15) !important;
        }

        /* Table Styling */
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, .02);
        }

        /* Info Alert Styling */
        .info-alert {
            background-color: #e7f3ff;
            border-color: #b3d9ff;
            color: #0c5460;
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            .nav-tabs .nav-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.9rem;
            }

            .nav-tabs .badge {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h1"><i class="bi bi-person-plus-fill me-2"></i>Onboarding-Übersicht</h1>
    </div>

    <?php if (!empty($status_message)): ?>
        <div class="alert alert-<?php echo $status_type; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle me-2"></i><?php echo htmlspecialchars($status_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs for different stages -->
    <ul class="nav nav-tabs mb-4" id="onboardingTabs" role="tablist">
        <?php if (ist_empfang() || ist_hr() || ist_admin()): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo ($active_tab == 'status0') ? 'active' : ''; ?>"
                        id="status0-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#status0"
                        type="button"
                        role="tab"
                        aria-controls="status0"
                        aria-selected="<?php echo ($active_tab == 'status0') ? 'true' : 'false'; ?>">
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

            <li class="nav-item" role="presentation">
                <button class="nav-link"
                        id="missing-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#missing"
                        type="button"
                        role="tab"
                        aria-controls="missing"
                        aria-selected="false">
                    <i class="bi bi-question-diamond me-1"></i>Fehlende MA
                    <?php if ($total_missing > 0): ?>
                        <span class="badge bg-secondary ms-1"><?php echo $total_missing; ?></span>
                    <?php endif; ?>
                </button>
            </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content" id="onboardingTabsContent">
        <!-- Empfang Tab -->
        <?php if (ist_empfang() || ist_hr() || ist_admin()): ?>
            <div class="tab-pane fade <?php echo ($active_tab == 'status0') ? 'show active' : ''; ?>"
                 id="status0"
                 role="tabpanel"
                 aria-labelledby="status0-tab">
                <?php if (count($employees_status0) > 0): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                        <?php foreach ($employees_status0 as $employee): ?>
                            <div class="col">
                                <div class="card h-100 employee-card">
                                    <div class="card-body">
                                        <div class="d-flex mb-3">
                                            <div class="me-3">
                                                <?php echo renderEmployeeImage($employee); ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h5 class="card-title mb-1"><?php echo htmlspecialchars($employee['name']); ?></h5>
                                                <p class="card-text text-muted mb-0"><?php echo htmlspecialchars($employee['position']); ?></p>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <i class="bi bi-calendar-event me-1"></i>
                                                <?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?>
                                            </small>

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
                                <div class="card h-100 employee-card">
                                    <div class="card-body">
                                        <div class="d-flex mb-3">
                                            <div class="me-3">
                                                <?php echo renderEmployeeImage($employee); ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h5 class="card-title mb-1"><?php echo htmlspecialchars($employee['name']); ?></h5>
                                                <p class="card-text text-muted mb-0"><?php echo htmlspecialchars($employee['position']); ?></p>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">
                                                <i class="bi bi-calendar-event me-1"></i>
                                                <?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?>
                                            </small>

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
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Position</th>
                                        <th>Eintrittsdatum</th>
                                        <th>Team</th>
                                        <th>Status</th>
                                        <th class="text-center">Aktion</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($all_onboarding as $employee): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2">
                                                        <?php echo renderEmployeeImage($employee, '30px'); ?>
                                                    </div>
                                                    <span><?php echo htmlspecialchars($employee['name']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($employee['position']); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($employee['crew']); ?></td>
                                            <td>
                                                <?php if ($employee['onboarding_status'] == 0): ?>
                                                    <span class="badge bg-warning text-dark">Empfang</span>
                                                <?php elseif ($employee['onboarding_status'] == 1): ?>
                                                    <span class="badge bg-info text-white">HR</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($employee['onboarding_status'] == 0): ?>
                                                    <a href="employee_onboarding.php?id=<?php echo $employee['employee_id']; ?>"
                                                       class="btn btn-sm btn-outline-primary"
                                                       title="Bearbeiten">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                <?php elseif ($employee['onboarding_status'] == 1): ?>
                                                    <a href="hr_onboarding.php?id=<?php echo $employee['employee_id']; ?>"
                                                       class="btn btn-sm btn-outline-primary"
                                                       title="Bearbeiten">
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

        <!-- Missing Employees Tab -->
        <?php if (ist_hr() || ist_admin()): ?>
            <div class="tab-pane fade" id="missing" role="tabpanel" aria-labelledby="missing-tab">
                <!-- Info Alert -->
                <div class="alert info-alert d-flex align-items-start mb-4" role="alert">
                    <i class="bi bi-info-circle-fill me-3 fs-5 flex-shrink-0"></i>
                    <div>
                        <h6 class="alert-heading mb-2">Was sind fehlende Mitarbeiter?</h6>
                        <p class="mb-1">Diese Liste zeigt Mitarbeiter, die im Zeit+/Infoniqa System erfasst (und anwesend) sind, aber
                            noch nicht in PRiME angelegt wurden.</p>
                        <p class="mb-1">Ein automatisiertes Script prüft alle 5 Minuten die Anwesenheitsdaten und
                            gleicht sie mit PRiME ab.
                            Wird eine Person im Zeiterfassungssystem gefunden, die in PRiME fehlt, erscheint sie
                            hier.</p>
                        <p class="mb-0"><strong>Empfehlung:</strong> Diese Mitarbeiter sollten zeitnah in PRiME erfasst
                            werden, um eine vollständige und korrekte Datenbasis zu gewährleisten. Danach kann sie hier gelöscht werden - das ist eine unabhängige/unwichtige Auflistung.</p>
                    </div>
                </div>

                <?php if (count($missing_employees) > 0): ?>
                    <div class="card shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">Fehlende Mitarbeiter</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Zuletzt anwesend</th>
                                        <th>Ausweisnummer</th>
                                        <th class="text-center">Aktion</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($missing_employees as $memp): ?>
                                        <tr id="missing-row-<?php echo $memp['id']; ?>">
                                            <td><?php echo htmlspecialchars($memp['name']); ?></td>
                                            <td><?php echo htmlspecialchars(date('d.m.Y', strtotime($memp['last_present']))); ?></td>
                                            <td><?php echo htmlspecialchars($memp['badge_id']); ?></td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-danger delete-missing"
                                                        data-id="<?php echo $memp['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($memp['name']); ?>"
                                                        title="Entfernen">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
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
                        <h4 class="mt-3">Keine fehlenden Mitarbeiter</h4>
                        <p class="text-muted">Alle im Zeiterfassungssystem vorhandenen Mitarbeiter sind auch in PRiME
                            angelegt.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Handle delete button clicks for missing employees
        document.querySelectorAll('.delete-missing').forEach(button => {
            button.addEventListener('click', function () {
                const id = this.dataset.id;
                const name = this.dataset.name;

                if (confirm(`Möchten Sie "${name}" wirklich aus der Liste entfernen?\n\nHinweis: Dies entfernt nur den Eintrag aus dieser Liste. Der Mitarbeiter bleibt im Zeiterfassungssystem bestehen.`)) {
                    // Send AJAX request
                    fetch('onboarding_list.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=delete_missing&id=${id}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Remove the row from the table
                                const row = document.getElementById(`missing-row-${id}`);
                                if (row) {
                                    row.remove();

                                    // Update badge count
                                    const badge = document.querySelector('#missing-tab .badge');
                                    if (badge) {
                                        const currentCount = parseInt(badge.textContent);
                                        if (currentCount > 1) {
                                            badge.textContent = currentCount - 1;
                                        } else {
                                            badge.remove();
                                        }
                                    }

                                    // Check if table is now empty
                                    const tbody = document.querySelector('#missing tbody');
                                    if (!tbody || tbody.children.length === 0) {
                                        location.reload(); // Reload to show empty state
                                    }
                                }
                            } else {
                                alert('Fehler beim Entfernen: ' + (data.error || 'Unbekannter Fehler'));
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Es ist ein Fehler aufgetreten.');
                        });
                }
            });
        });
    });
</script>
</body>
</html>