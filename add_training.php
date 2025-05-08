<?php
/**
 * add_training.php
 *
 * Diese Seite ermöglicht es berechtigten Benutzern (Admin, HR, Bereichsleiter, EHS oder Trainingsmanager),
 * einer bestimmten Person eine Weiterbildung zuzuweisen. Dabei wird zunächst ein neuer Trainingseintrag
 * in der Tabelle trainings erstellt und anschließend dieser Weiterbildungseintrag dem Mitarbeiter (über employee_training)
 * zugewiesen.
 *
 * Es wird vorausgesetzt, dass:
 * - Die Session bereits gestartet wurde (oder via access_control.php gestartet wird).
 * - In der Session der interne Mitarbeiter-Schlüssel (employee_id) unter $_SESSION['mitarbeiter_id'] gespeichert ist.
 */

include 'db.php';              // Datenbank-Verbindung
include 'access_control.php';  // Zugriffskontrolle & Session-Handling
include 'training_functions.php'; // Trainings-Hilfsfunktionen

global $conn;

// Sicherstellen, dass der Benutzer eingeloggt ist und Zugriff auf Trainings hat
pruefe_benutzer_eingeloggt();
pruefe_trainings_zugriff();

// Mitarbeiter-ID aus dem GET-Parameter auslesen
// Wichtig: Auch numerische 0-Werte prüfen, die als falsy gelten würden
if (!isset($_GET['employee_id']) || $_GET['employee_id'] === '') {
    echo 'Keine Mitarbeiter-ID angegeben';
    exit;
}
$employee_id = intval($_GET['employee_id']);

// Validiere, dass der Mitarbeiter existiert
$check_employee = $conn->prepare("SELECT employee_id, name FROM employees WHERE employee_id = ?");
$check_employee->bind_param("i", $employee_id);
$check_employee->execute();
$employee_result = $check_employee->get_result();

if ($employee_result->num_rows === 0) {
    echo 'Mitarbeiter mit dieser ID nicht gefunden';
    exit;
}

$employee_data = $employee_result->fetch_assoc();
$check_employee->close();

// Lade alle aktiven Hauptkategorien
$main_categories = getActiveMainCategories($conn);

// Alle aktiven Unterkategorien nach Hauptkategorie gruppiert laden
$sub_categories_grouped = getAllActiveSubCategoriesGrouped($conn);

// Lade alle Mitarbeiter als potenzielle Trainer
$trainers_query = $conn->prepare("
    SELECT employee_id, name, position 
    FROM employees 
    ORDER BY name ASC
");
$trainers_query->execute();
$trainers_result = $trainers_query->get_result();
$all_trainers = [];
while ($trainer = $trainers_result->fetch_assoc()) {
    $all_trainers[] = $trainer;
}
$trainers_query->close();

// POST-Request: Verarbeitung des Formulars zum Hinzufügen einer Weiterbildung
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Aus POST-Daten die notwendigen Werte auslesen
    $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : 0;
    $main_category_id = isset($_POST['main_category_id']) ? $_POST['main_category_id'] : '';
    $sub_category_id = isset($_POST['sub_category_id']) ? $_POST['sub_category_id'] : '';
    $training_name = isset($_POST['training_name']) ? $_POST['training_name'] : '';
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
    $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
    $training_units = isset($_POST['training_units']) ? $_POST['training_units'] : '';
    $trainer_ids = isset($_POST['trainer_ids']) ? $_POST['trainer_ids'] : [];

    // Eingabevalidierung
    $errors = [];
    if (empty($employee_id)) $errors[] = 'Mitarbeiter-ID fehlt';
    if (empty($main_category_id)) $errors[] = 'Hauptkategorie muss ausgewählt werden';
    if (empty($sub_category_id)) $errors[] = 'Unterkategorie muss ausgewählt werden';
    if (empty($training_name)) $errors[] = 'Name der Weiterbildung ist erforderlich';
    if (empty($start_date)) $errors[] = 'Startdatum ist erforderlich';
    if (empty($end_date)) $errors[] = 'Enddatum ist erforderlich';
    if (empty($training_units)) $errors[] = 'Trainingseinheiten müssen angegeben werden';

    // Spezielle Validierung für Technical Trainings (Kategorie 03)
    if ($main_category_id == 3 && empty($trainer_ids)) {
        $errors[] = 'Für Technical Trainings muss mindestens ein Trainer ausgewählt werden';
    }

    if (!empty($errors)) {
        echo '<div class="alert alert-danger">';
        echo '<h4>Folgende Fehler sind aufgetreten:</h4>';
        echo '<ul>';
        foreach ($errors as $error) {
            echo '<li>' . htmlspecialchars($error) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    } else {
        // Hauptkategorie-Code abrufen für die ID-Generierung
        $main_cat_code = getMainCategoryCode($main_category_id, $conn);
        $sub_cat_code = getSubCategoryCode($sub_category_id, $conn);

        if (!$main_cat_code || !$sub_cat_code) {
            die("Fehler: Ungültige Kategorie-Codes.");
        }

        // Display ID generieren
        $display_id = generateDisplayId($main_cat_code, $sub_cat_code, $end_date, $conn);

        // Benutzer ID abrufen
        $user_id = $_SESSION['mitarbeiter_id'];

        // Neuen Trainingseintrag in der Tabelle trainings hinzufügen
        $stmt = $conn->prepare("
            INSERT INTO trainings (
                display_id, main_category_id, sub_category_id, training_name, 
                start_date, end_date, training_units, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            die("Fehler bei der Vorbereitung des INSERT-Statements (trainings): " . $conn->error);
        }
        $stmt->bind_param("siisssdi", $display_id, $main_category_id, $sub_category_id,
            $training_name, $start_date, $end_date, $training_units, $user_id);
        $stmt->execute();
        $training_id = $stmt->insert_id; // Neuen Trainingseintrag identifizieren
        $stmt->close();

        // Das neue Training dem Mitarbeiter in der Zuordnungstabelle employee_training zuweisen
        $stmt = $conn->prepare("INSERT INTO employee_training (employee_id, training_id) VALUES (?, ?)");
        if (!$stmt) {
            die("Fehler bei der Vorbereitung des INSERT-Statements (employee_training): " . $conn->error);
        }
        $stmt->bind_param("ii", $employee_id, $training_id);
        $stmt->execute();
        $stmt->close();

        // Für Technical Trainings (Kategorie 03) die Trainer speichern
        if ($main_category_id == 3 && !empty($trainer_ids)) {
            $values = [];
            $types = '';

            foreach ($trainer_ids as $trainer_id) {
                $values[] = $training_id;
                $values[] = $trainer_id;
                $types .= 'ii';
            }

            // Platzhalter für die Einfügung erstellen
            $placeholders = implode(',', array_fill(0, count($trainer_ids), '(?,?)'));

            $trainer_stmt = $conn->prepare("
                INSERT INTO training_trainers (training_id, trainer_id)
                VALUES $placeholders
            ");

            if (!$trainer_stmt) {
                die("Fehler bei der Vorbereitung des INSERT-Statements (trainer): " . $conn->error);
            }

            $trainer_stmt->bind_param($types, ...$values);
            $trainer_stmt->execute();
            $trainer_stmt->close();
        }

        // Nach erfolgreichem Eintrag: Umleitung zu den Mitarbeiterdetails unter Übergabe des internen Schlüssels
        header("Location: employee_details.php?employee_id=" . $employee_id);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weiterbildung hinzufügen</title>
    <!-- Lokale CSS-Dateien: Navbar und Bootstrap -->
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border-radius: 10px;
            overflow: hidden;
        }

        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 15px 20px;
        }

        /* Styles für den Unterkategorie-Popover */
        .subcategory-popover {
            max-width: 350px;
        }

        .subcategory-info h6 {
            margin-top: 10px;
            margin-bottom: 5px;
        }

        .subcategory-info ul {
            padding-left: 15px;
            margin-bottom: 5px;
        }

        .subcategory-info li {
            margin-bottom: 3px;
        }

        .form-container {
            padding: 20px;
            background-color: #fff;
            margin-bottom: 20px;
        }

        .btn-group .btn {
            font-size: 0.85rem;
        }

        /* Trainer Container Styles */
        #trainer-container {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            display: none; /* Initially hidden */
        }

        .trainer-item {
            background-color: #e9ecef;
            padding: 8px 15px;
            margin-bottom: 8px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .trainer-item .trainer-name {
            font-weight: 500;
        }

        .remove-trainer {
            color: #dc3545;
            cursor: pointer;
            font-size: 1.1rem;
        }

        .remove-trainer:hover {
            color: #bd2130;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container">
    <h1 class="text-center mb-3">Weiterbildung hinzufügen</h1>
    <hr class="mb-4">

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Neue Weiterbildung für <?php echo htmlspecialchars($employee_data['name']); ?></h5>
        </div>
        <div class="card-body">
            <!-- Formular zur Eingabe der Weiterbildung -->
            <form action="add_training.php?employee_id=<?php echo htmlspecialchars($employee_id); ?>" method="POST">
                <!-- Mitarbeiter-ID als Hidden-Feld -->
                <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee_id); ?>">

                <!-- Hauptkategorie und Unterkategorie -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="main_category_id" class="form-label">Hauptkategorie:</label>
                        <select class="form-select" id="main_category_id" name="main_category_id" required>
                            <option value="" selected disabled>Bitte wählen...</option>
                            <?php foreach ($main_categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category['id']); ?>"
                                        data-code="<?php echo htmlspecialchars($category['code'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="sub_category_id" class="form-label">
                            Unterkategorie:
                            <i class="bi bi-info-circle text-primary ms-1" id="subcategory-info"
                               data-bs-toggle="popover" data-bs-placement="right"
                               data-bs-html="true" data-bs-custom-class="subcategory-popover"></i>
                        </label>
                        <select class="form-select" id="sub_category_id" name="sub_category_id" required>
                            <option value="" selected disabled>Bitte zuerst Hauptkategorie wählen</option>
                        </select>
                    </div>
                </div>

                <!-- Name der Weiterbildung -->
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label for="training_name" class="form-label">Name der Weiterbildung:</label>
                        <input type="text" class="form-control" id="training_name" name="training_name" required>
                    </div>
                </div>

                <!-- Startdatum, Enddatum und Anzahl der Einheiten -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="start_date" class="form-label">Startdatum:</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                            <div class="btn-group mt-2" role="group">
                                <button type="button" class="btn btn-outline-secondary" id="start_date_today">
                                    Heute
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="start_date_yesterday">
                                    Gestern
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="end_date" class="form-label">Enddatum:</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required>
                            <div class="d-flex gap-1 mt-2">
                                <button type="button" class="btn btn-outline-secondary" id="end_date_same_day">
                                    Gleicher Tag
                                </button>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle" type="button"
                                            id="dropdownMenuButton1" data-bs-toggle="dropdown">
                                        + Tage
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <button type="button" class="dropdown-item" id="end_date_plus1">+1 Tag
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item" id="end_date_plus2">+2 Tage
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item" id="end_date_plus3">+3 Tage
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item" id="end_date_plus7">+7 Tage
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="training_units" class="form-label">Anzahl Trainingseinheiten:</label>
                            <input type="number" step="0.1" class="form-control" id="training_units"
                                   name="training_units" required>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <button type="button" class="btn btn-outline-secondary" id="training_units_0_5">0,5
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="training_units_1">1
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="training_units_1_5">1,5
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="training_units_2">2
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="training_units_2_5">2,5
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="training_units_3">3
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="training_units_4">4
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="training_units_8">8
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Trainer-Auswahl für Technical Trainings (Kategorie 03) -->
                <div id="trainer-container" class="mb-3">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i> Für Technical Trainings (Hauptkategorie 03) müssen
                        Trainer hinzugefügt werden.
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-8">
                            <select class="form-select" id="trainer-select">
                                <option value="" selected disabled>Trainer auswählen...</option>
                                <?php foreach ($all_trainers as $trainer): ?>
                                    <option value="<?php echo htmlspecialchars($trainer['employee_id']); ?>">
                                        <?php echo htmlspecialchars($trainer['name']); ?>
                                        <?php if (!empty($trainer['position'])): ?>
                                            (<?php echo htmlspecialchars($trainer['position']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-success w-100" id="add-trainer-btn">
                                <i class="bi bi-plus-circle me-1"></i> Trainer hinzufügen
                            </button>
                        </div>
                    </div>

                    <div id="selected-trainers" class="mb-3">
                        <!-- Hier werden die ausgewählten Trainer angezeigt -->
                        <div class="alert alert-warning" id="no-trainers-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i> Keine Trainer ausgewählt. Bitte fügen Sie
                            mindestens einen Trainer hinzu.
                        </div>
                    </div>

                    <!-- Versteckte Input-Felder für Trainer-IDs -->
                    <div id="trainer-inputs">
                        <!-- Hier werden Input-Felder für Trainer-IDs dynamisch hinzugefügt -->
                    </div>
                </div>

                <!-- Formular-Buttons -->
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Speichern
                    </button>
                    <a href="employee_details.php?employee_id=<?php echo htmlspecialchars($employee_id); ?>"
                       class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Abbrechen
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Lokales Bootstrap-JavaScript -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Unterkategorien nach Hauptkategorie gruppieren
        const subCategoriesByMainId = <?php echo json_encode($sub_categories_grouped); ?>;
        const trainerContainer = document.getElementById('trainer-container');
        const allTrainers = <?php echo json_encode($all_trainers); ?>;

        // Ausgewählte Trainer verwalten
        let selectedTrainers = [];
        const trainerSelect = document.getElementById('trainer-select');
        const addTrainerBtn = document.getElementById('add-trainer-btn');
        const selectedTrainersContainer = document.getElementById('selected-trainers');
        const noTrainersWarning = document.getElementById('no-trainers-warning');
        const trainerInputsContainer = document.getElementById('trainer-inputs');

        // Trainer hinzufügen
        function addTrainer() {
            const trainerId = trainerSelect.value;
            const trainerName = trainerSelect.options[trainerSelect.selectedIndex]?.text;

            if (!trainerId || !trainerName) return;

            // Trainer zum Array hinzufügen, falls noch nicht vorhanden
            if (!selectedTrainers.some(trainer => trainer.id === trainerId)) {
                selectedTrainers.push({
                    id: trainerId,
                    name: trainerName
                });

                // Aktualisiere die Anzeige
                updateTrainerDisplay();
            }
        }

        // Trainer entfernen
        function removeTrainer(trainerId) {
            selectedTrainers = selectedTrainers.filter(trainer => trainer.id !== trainerId);
            updateTrainerDisplay();
        }

        // Aktualisiere die Trainer-Anzeige und versteckten Inputs
        function updateTrainerDisplay() {
            // Aktualisiere die Anzeige
            if (selectedTrainers.length === 0) {
                noTrainersWarning.style.display = 'block';
                selectedTrainersContainer.querySelectorAll('.trainer-item').forEach(item => item.remove());
            } else {
                noTrainersWarning.style.display = 'none';

                // Entferne bestehende Trainer-Items
                selectedTrainersContainer.querySelectorAll('.trainer-item').forEach(item => item.remove());

                // Füge neue Trainer-Items hinzu
                selectedTrainers.forEach(trainer => {
                    const trainerItem = document.createElement('div');
                    trainerItem.className = 'trainer-item';
                    trainerItem.innerHTML = `
                        <span class="trainer-name">${trainer.name}</span>
                        <i class="bi bi-x-circle remove-trainer" data-trainer-id="${trainer.id}"></i>
                    `;
                    selectedTrainersContainer.appendChild(trainerItem);
                });
            }

            // Aktualisiere versteckte Input-Felder
            trainerInputsContainer.innerHTML = '';
            selectedTrainers.forEach(trainer => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'trainer_ids[]';
                input.value = trainer.id;
                trainerInputsContainer.appendChild(input);
            });
        }

        // Event-Listener für Trainer-Hinzufügen
        addTrainerBtn.addEventListener('click', addTrainer);

        // Event-Delegation für Trainer-Entfernen
        selectedTrainersContainer.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-trainer')) {
                const trainerId = e.target.getAttribute('data-trainer-id');
                removeTrainer(trainerId);
            }
        });

        // Hauptkategorie-Auswahl aktualisiert Unterkategorien
        document.getElementById('main_category_id').addEventListener('change', function () {
            const mainCategoryId = this.value;
            const subCategorySelect = document.getElementById('sub_category_id');

            // Prüfe, ob es sich um Technical Trainings (Kategorie 03) handelt
            const isTechnicalTraining = mainCategoryId == 3;

            // Zeige/verstecke Trainer-Auswahl entsprechend
            trainerContainer.style.display = isTechnicalTraining ? 'block' : 'none';

            // Leere aktuelle Optionen
            subCategorySelect.innerHTML = '';

            if (mainCategoryId && subCategoriesByMainId[mainCategoryId]) {
                // Füge Optionen basierend auf der Hauptkategorie hinzu
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.text = 'Bitte wählen...';
                defaultOption.disabled = true;
                defaultOption.selected = true;
                subCategorySelect.appendChild(defaultOption);

                subCategoriesByMainId[mainCategoryId].forEach(function (subCat) {
                    const option = document.createElement('option');
                    option.value = subCat.id;
                    option.text = subCat.name;
                    subCategorySelect.appendChild(option);
                });
            } else {
                // Keine Hauptkategorie ausgewählt
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.text = 'Bitte zuerst Hauptkategorie wählen';
                defaultOption.disabled = true;
                defaultOption.selected = true;
                subCategorySelect.appendChild(defaultOption);
            }
        });

        // Popover-Inhalt für Unterkategorien-Info
        const subcategoryInfoContent = `
            <div class="subcategory-info">
                <h6><strong>Sicherheit, Gesundheit, Umwelt, Hygiene</strong></h6>
                <ul>
                    <li>Allgemeine Sicherheitsschulungen: LOTO, CPO, ...</li>
                    <li>Allgemeine Schulungen Auditnachweise, ...</li>
                </ul>

                <h6><strong>Personal/Entwicklung</strong></h6>
                <ul>
                    <li>Onboarding: HR Mentoring, 3 Tage Grundeinschulung</li>
                </ul>

                <h6><strong>Technical Trainings</strong></h6>
                <ul>
                    <li>Allgemeine Schulungen: Shopfloor, Viscan, IT, ...</li>
                </ul>
            </div>
        `;

        // Popover initialisieren
        const subcategoryInfoEl = document.getElementById('subcategory-info');
        if (subcategoryInfoEl) {
            const subcategoryPopover = new bootstrap.Popover(subcategoryInfoEl, {
                title: 'Erklärungen zu Unterkategorien',
                content: subcategoryInfoContent,
                trigger: 'click',
                container: 'body'
            });
        }

        // Startdatum und Enddatum Schnellauswahl
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');

        // Hilfsfunktionen für Datumsoperationen
        function getToday() {
            return new Date().toISOString().split('T')[0];
        }

        function getYesterday() {
            let d = new Date();
            d.setDate(d.getDate() - 1);
            return d.toISOString().split('T')[0];
        }

        function addDays(baseDate, days) {
            let d = new Date(baseDate);
            d.setDate(d.getDate() + parseInt(days));
            return d.toISOString().split('T')[0];
        }

        // Setze standardmäßig das heutige Datum
        if (!startDateInput.value) {
            startDateInput.value = getToday();
            endDateInput.value = getToday();
        }

        // Datums-Validierung: Enddatum darf nicht vor dem Startdatum liegen
        function ensureEndDateNotBeforeStart() {
            if (startDateInput.value && endDateInput.value) {
                if (endDateInput.value < startDateInput.value) {
                    alert('Das Enddatum darf nicht vor dem Startdatum liegen!');
                    endDateInput.value = startDateInput.value;
                }
            }
        }

        startDateInput.addEventListener('change', ensureEndDateNotBeforeStart);
        endDateInput.addEventListener('change', ensureEndDateNotBeforeStart);

        // Startdatum Schnellauswahl
        document.getElementById('start_date_today').addEventListener('click', () => {
            startDateInput.value = getToday();
        });
        document.getElementById('start_date_yesterday').addEventListener('click', () => {
            startDateInput.value = getYesterday();
        });

        // Enddatum Schnellauswahl
        document.getElementById('end_date_same_day').addEventListener('click', () => {
            endDateInput.value = startDateInput.value;
        });
        document.getElementById('end_date_plus1').addEventListener('click', () => {
            endDateInput.value = addDays(startDateInput.value, 1);
        });
        document.getElementById('end_date_plus2').addEventListener('click', () => {
            endDateInput.value = addDays(startDateInput.value, 2);
        });
        document.getElementById('end_date_plus3').addEventListener('click', () => {
            endDateInput.value = addDays(startDateInput.value, 3);
        });
        document.getElementById('end_date_plus7').addEventListener('click', () => {
            endDateInput.value = addDays(startDateInput.value, 7);
        });

        // Trainingseinheiten Schnellauswahl
        document.getElementById('training_units_0_5').addEventListener('click', () => {
            document.getElementById('training_units').value = '0.5';
        });
        document.getElementById('training_units_1').addEventListener('click', () => {
            document.getElementById('training_units').value = '1';
        });
        document.getElementById('training_units_1_5').addEventListener('click', () => {
            document.getElementById('training_units').value = '1.5';
        });
        document.getElementById('training_units_2').addEventListener('click', () => {
            document.getElementById('training_units').value = '2';
        });
        document.getElementById('training_units_2_5').addEventListener('click', () => {
            document.getElementById('training_units').value = '2.5';
        });
        document.getElementById('training_units_3').addEventListener('click', () => {
            document.getElementById('training_units').value = '3';
        });
        document.getElementById('training_units_4').addEventListener('click', () => {
            document.getElementById('training_units').value = '4';
        });
        document.getElementById('training_units_8').addEventListener('click', () => {
            document.getElementById('training_units').value = '8';
        });

        // Form-Validierung vor dem Absenden
        document.querySelector('form').addEventListener('submit', function (e) {
            const mainCategoryId = document.getElementById('main_category_id').value;

            // Wenn Technical Training, aber keine Trainer ausgewählt
            if (mainCategoryId == 3 && selectedTrainers.length === 0) {
                e.preventDefault();
                alert('Für Technical Trainings muss mindestens ein Trainer angegeben werden.');
            }
        });
    });
</script>
</body>
</html>