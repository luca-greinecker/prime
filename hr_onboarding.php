<?php
/**
 * hr_onboarding.php
 *
 * This page allows HR staff to complete the onboarding process for a new employee by:
 * - Checking off required items (Erstunterweisung, Gespräche, etc.)
 * - Completing the onboarding process
 */

include 'access_control.php';
global $conn;

// Ensure user is logged in and has appropriate permissions
pruefe_benutzer_eingeloggt();

// Check if the user has HR access
if (!ist_hr() && !ist_admin()) {
    header("Location: access_denied.php");
    exit;
}

// Check if employee ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['status_message'] = "Ungültige Mitarbeiter-ID.";
    $_SESSION['status_type'] = "danger";
    header("Location: onboarding_list.php");
    exit;
}

$employee_id = (int)$_GET['id'];

// Initialize error/success messages
$status_message = '';
$status_type = '';

// Fetch employee data
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
        e.employee_id = ? AND e.onboarding_status = 1
");

$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if employee exists and is in onboarding status 1
if ($result->num_rows === 0) {
    $_SESSION['status_message'] = "Mitarbeiter nicht gefunden oder nicht im richtigen Onboarding-Status.";
    $_SESSION['status_type'] = "danger";
    header("Location: onboarding_list.php");
    exit;
}

$employee = $result->fetch_assoc();
$stmt->close();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if all required checkboxes are checked
    $erstunterweisung = isset($_POST['erstunterweisung']) ? 1 : 0;
    $monatgespraech_1 = isset($_POST['monatgespraech_1']) ? 1 : 0;
    $monatgespraech_3 = isset($_POST['monatgespraech_3']) ? 1 : 0;
    $grundeinschulung = isset($_POST['grundeinschulung']) ? 1 : 0;

    // Validate all checkboxes are checked
    if (!$erstunterweisung || !$monatgespraech_1 || !$monatgespraech_3 || !$grundeinschulung) {
        $status_message = "Bitte bestätigen Sie, dass alle erforderlichen Schritte abgeschlossen wurden.";
        $status_type = "danger";
    } else {
        // Complete the onboarding process (update status to 3)
        $stmt = $conn->prepare("
            UPDATE employees 
            SET onboarding_status = 3
            WHERE employee_id = ?
        ");
        $stmt->bind_param("i", $employee_id);

        if ($stmt->execute()) {
            $_SESSION['status_message'] = "Onboarding für " . htmlspecialchars($employee['name']) . " erfolgreich abgeschlossen.";
            $_SESSION['status_type'] = "success";
            header("Location: onboarding_list.php");
            exit;
        } else {
            $status_message = "Fehler beim Aktualisieren des Mitarbeiters: " . $stmt->error;
            $status_type = "danger";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitarbeiter Onboarding (HR)</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-person-vcard me-2"></i>Mitarbeiter Onboarding (HR)</h1>
        <a href="onboarding_list.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Zurück zur Übersicht
        </a>
    </div>

    <?php if (!empty($status_message)): ?>
        <div class="alert alert-<?php echo $status_type; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle me-2"></i><?php echo $status_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Mitarbeiterinformationen</h5>
        </div>
        <div class="card-body py-2">
            <div class="row">
                <div class="col-md-2 text-center mb-3 mb-md-0">
                    <?php if (!empty($employee['bild']) && $employee['bild'] !== 'kein-bild.jpg'): ?>
                        <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($employee['bild']); ?>"
                             alt="Mitarbeiterbild" class="rounded-circle img-thumbnail"
                             style="width: 80px; height: 80px; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center mx-auto"
                             style="width: 80px; height: 80px;">
                            <i class="bi bi-person-fill" style="font-size: 2rem; color: #6c757d;"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-5">
                    <div class="mb-2">
                        <label class="form-label mb-0">Name:</label>
                        <p class="form-control-plaintext py-0 fw-bold"><?php echo htmlspecialchars($employee['name']); ?></p>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-0">Position:</label>
                        <p class="form-control-plaintext py-0"><?php echo htmlspecialchars($employee['position']); ?></p>
                    </div>
                </div>

                <div class="col-md-5">
                    <div class="mb-2">
                        <label class="form-label mb-0">Eintrittsdatum:</label>
                        <p class="form-control-plaintext py-0"><?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?></p>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-0">Ausweisnummer:</label>
                        <p class="form-control-plaintext py-0">
                            <?php echo ($employee['badge_id'] > 0) ? $employee['badge_id'] : 'Nicht zugewiesen'; ?>
                        </p>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-0">Team:</label>
                        <p class="form-control-plaintext py-0">
                            <?php echo !empty($employee['crew']) && $employee['crew'] !== '---' ?
                                htmlspecialchars($employee['crew']) :
                                '<span class="text-muted">Kein Team</span>'; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info" role="alert">
        <h6 class="alert-heading"><i class="bi bi-info-circle me-2"></i>Wichtiger Hinweis</h6>
        <p class="mb-0">Bitte haken Sie die folgenden Punkte erst ab, wenn die entsprechenden Formulare vorhanden und
            vollständig sind.</p>
    </div>

    <form action="" method="post" id="onboardingForm">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">Onboarding-Checkliste</h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <div class="list-group-item list-group-item-action">
                        <label for="erstunterweisung" class="d-flex align-items-center">
                            <input type="checkbox" id="erstunterweisung" name="erstunterweisung" value="1"
                                   class="form-check-input me-3">
                            <div>
                                <div class="fw-bold">Erstunterweisung (EHS)</div>
                                <div class="text-muted small">Sicherheits- und Gesundheitsunterweisung durchgeführt
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="list-group-item list-group-item-action">
                        <label for="monatgespraech_1" class="d-flex align-items-center">
                            <input type="checkbox" id="monatgespraech_1" name="monatgespraech_1" value="1"
                                   class="form-check-input me-3">
                            <div>
                                <div class="fw-bold">1-Monatsgespräch</div>
                                <div class="text-muted small">Erstes Feedbackgespräch nach einem Monat durchgeführt
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="list-group-item list-group-item-action">
                        <label for="monatgespraech_3" class="d-flex align-items-center">
                            <input type="checkbox" id="monatgespraech_3" name="monatgespraech_3" value="1"
                                   class="form-check-input me-3">
                            <div>
                                <div class="fw-bold">3-Monatsgespräch</div>
                                <div class="text-muted small">Probezeit-Abschlussgespräch durchgeführt</div>
                            </div>
                        </label>
                    </div>

                    <div class="list-group-item list-group-item-action">
                        <label for="grundeinschulung" class="d-flex align-items-center">
                            <input type="checkbox" id="grundeinschulung" name="grundeinschulung" value="1"
                                   class="form-check-input me-3">
                            <div>
                                <div class="fw-bold">3-Tage Grundeinschulung</div>
                                <div class="text-muted small">Basis-Schulung für alle Unternehmensbereiche
                                    abgeschlossen
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="onboarding_list.php" class="btn btn-secondary">
                <i class="bi bi-x-circle me-1"></i>Abbrechen
            </a>
            <button type="submit" class="btn btn-success" id="submitButton" disabled>
                <i class="bi bi-check-circle me-1"></i>Onboarding abschließen
            </button>
        </div>
    </form>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const checkboxes = [
            document.getElementById('erstunterweisung'),
            document.getElementById('monatgespraech_1'),
            document.getElementById('monatgespraech_3'),
            document.getElementById('grundeinschulung')
        ];
        const submitButton = document.getElementById('submitButton');

        // Function to check if all checkboxes are checked
        function updateSubmitButton() {
            const allChecked = checkboxes.every(checkbox => checkbox.checked);
            submitButton.disabled = !allChecked;
        }

        // Add event listeners to all checkboxes
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSubmitButton);
        });

        // Form submission validation
        document.getElementById('onboardingForm').addEventListener('submit', function (event) {
            const allChecked = checkboxes.every(checkbox => checkbox.checked);
            if (!allChecked) {
                event.preventDefault();
                alert('Bitte bestätigen Sie, dass alle erforderlichen Schritte abgeschlossen wurden.');
            }
        });
    });
</script>
</body>
</html>