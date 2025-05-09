<?php
/**
 * employee_onboarding.php
 *
 * This page allows reception staff to process a new employee's initial onboarding:
 * - Assign a badge ID
 * - Upload a photo
 * - Complete the reception stage and forward to HR
 */

include 'access_control.php';
global $conn;

// Ensure user is logged in and has appropriate permissions
pruefe_benutzer_eingeloggt();

// Check if the user has reception access
if (!ist_empfang() && !ist_hr() && !ist_admin()) {
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
        e.badge_id,
        e.social_security_number,
        e.shoe_size
    FROM 
        employees e
    WHERE 
        e.employee_id = ? AND e.onboarding_status = 0
");

$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

// Check if employee exists and is in onboarding status 0
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
    // Validate and process badge ID
    $badge_id = isset($_POST['badge_id']) ? (int)$_POST['badge_id'] : 0;

    // Check if badge ID is valid (should be a number greater than 0)
    if ($badge_id <= 0) {
        $status_message = "Bitte geben Sie eine gültige Ausweisnummer ein.";
        $status_type = "danger";
    } // Check if badge ID already exists for another employee
    else {
        $badge_check = $conn->prepare("
            SELECT employee_id FROM employees 
            WHERE badge_id = ? AND employee_id != ?
        ");
        $badge_check->bind_param("ii", $badge_id, $employee_id);
        $badge_check->execute();
        $badge_result = $badge_check->get_result();

        if ($badge_result->num_rows > 0) {
            $status_message = "Die Ausweisnummer {$badge_id} ist bereits einem anderen Mitarbeiter zugewiesen. Bitte wählen Sie eine andere Nummer.";
            $status_type = "danger";
        }
        $badge_check->close();
    }

    // Check if either file is uploaded or skip upload is checked
    if (empty($status_message) && (!isset($_FILES['employee_image']) || $_FILES['employee_image']['error'] !== UPLOAD_ERR_OK)) {
        if (!isset($_POST['skip_upload']) || $_POST['skip_upload'] != 1) {
            $status_message = "Bitte laden Sie ein Foto hoch oder aktivieren Sie die Option 'Im Moment kein Foto verfügbar'.";
            $status_type = "danger";
        }
    }

    // If no errors yet, proceed with update
    if (empty($status_message)) {
        // Update badge ID and image if provided
        $bild = $employee['bild']; // Default to current image

        // Process image upload if a file was selected
        if (isset($_FILES['employee_image']) && $_FILES['employee_image']['error'] === UPLOAD_ERR_OK) {
            // Validate image type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (in_array($_FILES['employee_image']['type'], $allowed_types)) {
                // Use original filename
                $file_name = $_FILES['employee_image']['name'];
                $upload_dir = '../mitarbeiter-anzeige/fotos/';
                $target_file = $upload_dir . $file_name;

                // Move uploaded file
                if (move_uploaded_file($_FILES['employee_image']['tmp_name'], $target_file)) {
                    $bild = $file_name;
                } else {
                    $status_message = "Fehler beim Hochladen des Bildes.";
                    $status_type = "danger";
                }
            } else {
                $status_message = "Ungültiger Dateityp. Bitte laden Sie ein Bild im JPEG, PNG oder GIF-Format hoch.";
                $status_type = "danger";
            }
        }

        // Check if upload is skipped
        if (isset($_POST['skip_upload']) && $_POST['skip_upload'] == 1) {
            // Keep default 'kein-bild.jpg' or current image
        }

        // Complete the reception stage if no errors
        if (empty($status_message)) {
            $stmt = $conn->prepare("
                UPDATE employees 
                SET badge_id = ?, 
                    bild = ?, 
                    onboarding_status = 1 
                WHERE employee_id = ?
            ");
            $stmt->bind_param("isi", $badge_id, $bild, $employee_id);

            if ($stmt->execute()) {
                $_SESSION['status_message'] = "Empfang-Onboarding für " . htmlspecialchars($employee['name']) . " erfolgreich abgeschlossen.";
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
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitarbeiter Onboarding (Empfang)</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-box2 me-2"></i>Mitarbeiter Onboarding (Empfang)</h1>
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
                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label mb-0">Name:</label>
                        <p class="form-control-plaintext py-0"><?php echo htmlspecialchars($employee['name']); ?></p>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-0">Position:</label>
                        <p class="form-control-plaintext py-0"><?php echo htmlspecialchars($employee['position']); ?></p>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-0">Eintrittsdatum:</label>
                        <p class="form-control-plaintext py-0"><?php echo date('d.m.Y', strtotime($employee['entry_date'])); ?></p>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-2">
                        <label class="form-label mb-0">Sozialversicherungsnummer:</label>
                        <p class="form-control-plaintext py-0">
                            <?php echo !empty($employee['social_security_number']) ?
                                htmlspecialchars($employee['social_security_number']) :
                                '<span class="text-muted">Nicht angegeben</span>'; ?>
                        </p>
                    </div>

                    <div class="mb-2">
                        <label class="form-label mb-0">Schuhgröße:</label>
                        <p class="form-control-plaintext py-0">
                            <?php echo !empty($employee['shoe_size']) ?
                                htmlspecialchars($employee['shoe_size']) :
                                '<span class="text-muted">Nicht angegeben</span>'; ?>
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

    <form action="" method="post" enctype="multipart/form-data" id="onboardingForm">
        <div class="row">
            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Ausweisnummer zuweisen</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="badge_id" class="form-label text-danger">Ausweisnummer: *</label>
                            <input type="number" class="form-control" id="badge_id" name="badge_id"
                                   value="<?php echo ($employee['badge_id'] > 0) ? $employee['badge_id'] : ''; ?>"
                                   min="1" required>
                            <div class="form-text">Bitte weisen Sie dem Mitarbeiter eine eindeutige Ausweisnummer zu.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Mitarbeiterfoto <span class="text-danger">*</span></h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3" id="imagePreview">
                            <?php if (!empty($employee['bild']) && $employee['bild'] !== 'kein-bild.jpg'): ?>
                                <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($employee['bild']); ?>"
                                     alt="Mitarbeiterbild" id="previewImage"
                                     class="img-thumbnail" style="max-height: 200px;">
                            <?php else: ?>
                                <div class="border border-2 rounded p-4 d-inline-block" id="previewPlaceholder">
                                    <i class="bi bi-person-fill" style="font-size: 3rem; color: #dee2e6;"></i>
                                    <p class="mb-0 text-muted">Kein Bild</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="text-center">
                            <label for="employee_image" class="btn btn-outline-primary mb-2">
                                <i class="bi bi-cloud-arrow-up me-1"></i>Foto auswählen
                            </label>
                            <input type="file" class="form-control d-none" id="employee_image" name="employee_image"
                                   accept="image/jpeg, image/png, image/gif">

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="skip_upload" name="skip_upload"
                                       value="1">
                                <label class="form-check-label" for="skip_upload">
                                    Im Moment kein Foto verfügbar
                                </label>
                            </div>

                            <div class="form-text mt-2 text-danger">Bitte laden Sie ein Foto hoch oder aktivieren Sie
                                die Option oben.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="onboarding_list.php" class="btn btn-secondary">
                <i class="bi bi-x-circle me-1"></i>Abbrechen
            </a>
            <button type="submit" id="submitButton" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Empfang abschließen
            </button>
        </div>
    </form>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const fileInput = document.getElementById('employee_image');
        const previewImage = document.getElementById('previewImage');
        const previewPlaceholder = document.getElementById('previewPlaceholder');
        const skipUploadCheckbox = document.getElementById('skip_upload');
        const submitButton = document.getElementById('submitButton');
        const form = document.getElementById('onboardingForm');

        // Prüfen, ob bereits ein Bild vorhanden ist
        const hasExistingImage = <?php echo (!empty($employee['bild']) && $employee['bild'] !== 'kein-bild.jpg') ? 'true' : 'false'; ?>;

        // Variable, die angibt, ob ein neues Bild hochgeladen wurde
        let hasImageSelected = false;

        // Wenn bereits ein Bild vorhanden ist, Fehlermeldung ausblenden
        if (hasExistingImage) {
            // Entferne die Fehlermeldung am Seitenanfang (alert-box)
            const alertBoxes = document.querySelectorAll('.alert');
            alertBoxes.forEach(box => {
                if (box.textContent.includes('Bitte laden Sie ein Foto hoch') ||
                    box.textContent.includes('Im Moment kein Foto verfügbar')) {
                    box.style.display = 'none';
                }
            });

            // Entferne den Hinweistext unter der Checkbox, falls vorhanden
            const warningTexts = document.querySelectorAll('.form-text.text-danger');
            warningTexts.forEach(text => {
                if (text.textContent.includes('Bitte laden Sie ein Foto hoch')) {
                    text.style.display = 'none';
                }
            });
        }

        // Funktion zur Validierung des Formulars
        function validateForm() {
            // Das Formular ist gültig, wenn entweder ein neues Bild ausgewählt wurde,
            // die "kein Foto"-Checkbox aktiviert ist, oder bereits ein Bild existiert
            return hasImageSelected || skipUploadCheckbox.checked || hasExistingImage;
        }

        // Event-Listener für Änderungen am Datei-Input
        fileInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                hasImageSelected = true;
                const reader = new FileReader();

                reader.onload = function (e) {
                    // Create or update image
                    if (!previewImage) {
                        const newImage = document.createElement('img');
                        newImage.id = 'previewImage';
                        newImage.alt = 'Mitarbeiterbild';
                        newImage.className = 'img-thumbnail';
                        newImage.style.maxHeight = '200px';
                        document.getElementById('imagePreview').innerHTML = '';
                        document.getElementById('imagePreview').appendChild(newImage);
                        newImage.src = e.target.result;
                    } else {
                        previewImage.src = e.target.result;
                        previewImage.style.display = 'inline-block';
                    }

                    // Hide placeholder if it exists
                    if (previewPlaceholder) {
                        previewPlaceholder.style.display = 'none';
                    }

                    // Uncheck skip upload if file is selected
                    skipUploadCheckbox.checked = false;
                }

                reader.readAsDataURL(this.files[0]);
            } else {
                hasImageSelected = false;
            }
        });

        // Event-Listener für die "kein Foto"-Checkbox
        skipUploadCheckbox.addEventListener('change', function () {
            fileInput.disabled = this.checked;
            if (this.checked) {
                fileInput.value = ''; // Clear file input
                hasImageSelected = false;

                // Show placeholder, hide image
                if (previewPlaceholder) {
                    previewPlaceholder.style.display = 'inline-block';
                }

                if (previewImage) {
                    previewImage.style.display = 'none';
                }
            } else {
                // Show image if exists, otherwise show placeholder
                if (previewImage && previewImage.src && previewImage.src.includes('data:image') ||
                    (previewImage && previewImage.src && previewImage.src.includes('fotos/'))) {
                    previewImage.style.display = 'inline-block';
                    if (previewPlaceholder) {
                        previewPlaceholder.style.display = 'none';
                    }
                } else if (previewPlaceholder) {
                    previewPlaceholder.style.display = 'inline-block';
                }
            }
        });

        // Form validation on submit
        form.addEventListener('submit', function (event) {
            if (!validateForm()) {
                event.preventDefault();
                alert('Bitte laden Sie ein Foto hoch oder aktivieren Sie die Option "Im Moment kein Foto verfügbar".');
            }
        });
    });
</script>
</body>
</html>