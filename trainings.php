<?php
/**
 * trainings.php
 *
 * Zeigt ein Formular zum Erfassen von Weiterbildungen (trainings) und die Zuordnung von Mitarbeitern,
 * sowie eine Liste der zuletzt angelegten Weiterbildungen. Nur Admins/HR haben Zugriff.
 */

include 'access_control.php';
include 'training_functions.php';
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_trainings_zugriff();

// Ergebnis-Variablen für das Modal
$result_message = '';
$result_type = '';

// Hauptkategorien und Unterkategorien über Hilfsfunktionen laden
$main_categories = getActiveMainCategories($conn);
$sub_categories = getAllActiveSubCategoriesGrouped($conn);

// Mitarbeiterlisten vorbereiten - mit exklusiver Kategorisierung
$teams = ["Team L", "Team M", "Team N", "Team O", "Team P"];
$groups = ["Tagschicht", "Verwaltung"];
$special_areas = ["Elektrik", "Mechanik", "CPO"];
$employees = [];
$employee_groups = [];
$assigned_employees = []; // Speichert bereits zugewiesene Mitarbeiter-IDs

// Zuerst die speziellen Bereiche verarbeiten (höhere Priorität)
foreach ($special_areas as $area) {
    $like_area = "%$area%";
    $stmt = $conn->prepare("
        SELECT employee_id, name
        FROM employees
        WHERE position LIKE ?
        ORDER BY name ASC
    ");
    $stmt->bind_param("s", $like_area);
    $stmt->execute();
    $result = $stmt->get_result();
    $employees[$area] = [];

    while ($employee = $result->fetch_assoc()) {
        $employees[$area][] = $employee;
        $assigned_employees[] = $employee['employee_id'];
    }

    $employee_groups[] = [
        'id' => str_replace(' ', '_', strtolower($area)),
        'name' => $area,
        'type' => 'areas',
        'count' => count($employees[$area])
    ];
    $stmt->close();
}

// Dann die Teams verarbeiten, aber bereits zugewiesene Mitarbeiter ausschließen
foreach ($teams as $team) {
    // IN-Klausel mit Platzhaltern für bereits zugewiesene Mitarbeiter
    $placeholders = count($assigned_employees) > 0 ?
        implode(',', array_fill(0, count($assigned_employees), '?')) : '0';

    $stmt = $conn->prepare("
        SELECT employee_id, name 
        FROM employees
        WHERE crew = ? AND employee_id NOT IN ($placeholders)
        ORDER BY name ASC
    ");

    $types = "s" . str_repeat('i', count($assigned_employees));
    $params = array_merge([$team], $assigned_employees);
    $stmt->bind_param($types, ...$params);

    $stmt->execute();
    $result = $stmt->get_result();
    $employees[$team] = [];

    while ($employee = $result->fetch_assoc()) {
        $employees[$team][] = $employee;
        $assigned_employees[] = $employee['employee_id'];
    }

    $employee_groups[] = [
        'id' => str_replace(' ', '_', strtolower($team)),
        'name' => $team,
        'type' => 'teams',
        'count' => count($employees[$team])
    ];
    $stmt->close();
}

// Dann die allgemeinen Gruppen verarbeiten
foreach ($groups as $group) {
    // IN-Klausel mit Platzhaltern für bereits zugewiesene Mitarbeiter
    $placeholders = count($assigned_employees) > 0 ?
        implode(',', array_fill(0, count($assigned_employees), '?')) : '0';

    $stmt = $conn->prepare("
        SELECT employee_id, name 
        FROM employees
        WHERE gruppe = ? AND employee_id NOT IN ($placeholders)
        ORDER BY name ASC
    ");

    $types = "s" . str_repeat('i', count($assigned_employees));
    $params = array_merge([$group], $assigned_employees);
    $stmt->bind_param($types, ...$params);

    $stmt->execute();
    $result = $stmt->get_result();
    $employees[$group] = [];

    while ($employee = $result->fetch_assoc()) {
        $employees[$group][] = $employee;
        $assigned_employees[] = $employee['employee_id'];
    }

    $employee_groups[] = [
        'id' => str_replace(' ', '_', strtolower($group)),
        'name' => $group,
        'type' => 'groups',
        'count' => count($employees[$group])
    ];
    $stmt->close();
}

// Zuletzt "Sonstiges" für alle übrigen Mitarbeiter
$placeholders = count($assigned_employees) > 0 ?
    implode(',', array_fill(0, count($assigned_employees), '?')) : '0';

$stmt = $conn->prepare("
    SELECT employee_id, name 
    FROM employees
    WHERE employee_id NOT IN ($placeholders)
    ORDER BY name ASC
");

if (count($assigned_employees) > 0) {
    $types = str_repeat('i', count($assigned_employees));
    $stmt->bind_param($types, ...$assigned_employees);
}

$stmt->execute();
$result = $stmt->get_result();
$employees["Sonstiges"] = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Nur hinzufügen, wenn es tatsächlich Mitarbeiter in "Sonstiges" gibt
if (count($employees["Sonstiges"]) > 0) {
    $employee_groups[] = [
        'id' => 'sonstiges',
        'name' => 'Sonstiges',
        'type' => 'others',
        'count' => count($employees["Sonstiges"])
    ];
}

// Die letzten 10 Trainings abrufen
$sql = "
    SELECT 
        t.id,
        t.display_id,
        t.training_name,
        mc.name AS main_category_name,
        sc.name AS sub_category_name,
        t.start_date,
        t.end_date,
        t.training_units,
        e.name AS created_by
    FROM trainings t
    JOIN employees e ON t.created_by = e.employee_id
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    ORDER BY t.id DESC
    LIMIT 10
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$last_trainings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Prüfen, ob eine Ergebnismeldung vorliegt
if (isset($_SESSION['result_message'])) {
    $result_message = $_SESSION['result_message'];
    $result_type = $_SESSION['result_type'];

    // Session-Nachrichten löschen
    unset($_SESSION['result_message']);
    unset($_SESSION['result_type']);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Weiterbildungen</title>
    <!-- Lokale Bootstrap und CSS -->
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .employee-container {
            margin-bottom: 1.5rem;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background-color: #f8f9fa;
            overflow: hidden;
        }

        .employee-group-header {
            padding: 10px 15px;
            background-color: #e9ecef;
            border-bottom: 1px solid #dee2e6;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background-color 0.2s;
        }

        .employee-group-header:hover {
            background-color: #dee2e6;
        }

        .employee-group-header .badge {
            font-size: 0.75rem;
            margin-right: 8px;
        }

        .toggle-collapse {
            margin-left: 10px;
        }

        .employee-group-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .employee-group-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .select-group-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 4px;
            background-color: #0d6efd;
            color: white;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .select-group-btn:hover {
            background-color: #0b5ed7;
        }

        .select-group-btn.btn-outline {
            background-color: transparent;
            color: #0d6efd;
            border: 1px solid #0d6efd;
        }

        .select-group-btn.btn-outline:hover {
            background-color: #f0f7ff;
        }

        .employee-list-container {
            max-height: 250px;
            overflow-y: auto;
            padding: 0.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
        }

        .employee-search-container {
            padding: 10px 15px;
            background-color: #e9ecef;
            border-bottom: 1px solid #dee2e6;
        }

        .employee-item {
            padding: 5px 10px;
            border-radius: 4px;
            background-color: #fff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .employee-item:hover {
            background-color: #f0f0f0;
        }

        .employee-item .form-check {
            margin: 0;
            width: 100%;
        }

        .employee-item .form-check-label {
            cursor: pointer;
            flex-grow: 1;
            user-select: none;
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 16px;
            border-radius: 20px;
            background-color: #e9ecef;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .filter-tab.active, .filter-tab:hover {
            background-color: #0d6efd;
            color: white;
        }

        .filter-tab .badge {
            margin-left: 5px;
            background-color: rgba(255, 255, 255, 0.2);
        }

        .btn-group .btn {
            font-size: 0.85rem;
        }

        .btn-group {
            width: 100%;
        }

        .training-link {
            text-decoration: none;
            color: #007bff;
        }

        .training-link:hover {
            text-decoration: underline;
        }

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

        .badge-category {
            font-size: 0.85rem;
            padding: 0.25em 0.6em;
            border-radius: 20px;
        }

        .form-container {
            padding: 20px;
            background-color: #fff;
            border-radius: 10px;
        }

        .step-heading {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #0d6efd;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }

        /* Custom scrollbar */
        .employee-list-container::-webkit-scrollbar {
            width: 8px;
        }

        .employee-list-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 8px;
        }

        .employee-list-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 8px;
        }

        .employee-list-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        .section-divider {
            margin: 30px 0;
            text-align: center;
            position: relative;
        }

        .section-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background-color: #dee2e6;
            z-index: 1;
        }

        .section-divider span {
            position: relative;
            padding: 0 15px;
            background-color: #fff;
            z-index: 2;
            color: #6c757d;
            font-size: 1.1rem;
        }

        .search-indicator {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .search-container {
            position: relative;
        }

        .selection-badge {
            background-color: #28a745;
            color: white;
            border-radius: 20px;
            padding: 0.25em 0.5em;
            font-size: 0.75rem;
            display: none; /* Initially hidden */
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
        #trainingInfoModal .modal-body {
            padding: 20px;
        }

        .training-info-container h6 {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 8px;
            margin-bottom: 15px;
            font-weight: 600;
            color: #0d6efd;
        }

        .training-info-container code {
            background-color: #f8f9fa;
            padding: 2px 5px;
            border-radius: 4px;
            font-weight: bold;
            color: #0d6efd;
            font-size: 1.1em;
        }

        .training-info-container ul {
            padding-left: 20px;
            margin-bottom: 10px;
        }

        .training-info-container li {
            margin-bottom: 5px;
        }

        /* Styling für Kategorien */
        .category-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .category-item {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px;
            background-color: #f8f9fa;
        }

        .category-header {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }

        .category-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            height: 30px;
            border-radius: 4px;
            color: white;
            font-weight: bold;
            margin-right: 10px;
        }

        .category-title {
            font-weight: 600;
        }

        .category-details {
            font-size: 0.95em;
            color: #555;
            padding-left: 40px;
        }

        .onedrive-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #0d6efd;
            margin-top: 15px;
        }

        .onedrive-section p {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container-xxl content">
    <h1 class="text-center mb-4">Weiterbildungen</h1>

    <!-- Formular für neues Training -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Neue Weiterbildung anlegen</h5>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#trainingForm">
                <i class="bi bi-chevron-down"></i> Formular ein-/ausklappen
            </button>
        </div>
        <div class="card-body collapse show" id="trainingForm">
            <form action="save_training.php" method="POST">
                <div class="form-container">
                    <!-- Button zum Öffnen des Modals -->
                    <div class="step-heading">
                        <div class="step-number">1</div>
                        <h5 class="mb-0">Weiterbildungsinformationen</h5>
                        <button type="button" class="btn btn-sm btn-outline-info ms-2" data-bs-toggle="modal" data-bs-target="#trainingInfoModal">
                            <i class="bi bi-info-circle me-1"></i> Weiterbildungsinfo
                        </button>
                    </div>

                    <!-- Modal für Weiterbildungsinformationen -->
                    <div class="modal fade" id="trainingInfoModal" tabindex="-1" aria-labelledby="trainingInfoModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="trainingInfoModalLabel">Informationen zum Weiterbildungssystem</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="training-info-container">
                                        <div class="categories-section mb-4">
                                            <h6><i class="bi bi-bookmark-fill me-2"></i>Hauptkategorien & Zuständigkeiten</h6>
                                            <div class="category-items">
                                                <div class="category-item">
                                                    <div class="category-header">
                                                        <span class="category-badge" style="background-color: #dc3545;">01</span>
                                                        <span class="category-title">Sicherheit, Gesundheit, Umwelt, Hygiene</span>
                                                    </div>
                                                    <div class="category-details">
                                                        <i class="bi bi-person-badge me-1"></i> <strong>Zuständig:</strong> EHS-Manager (Alexander Karg)
                                                    </div>
                                                </div>

                                                <div class="category-item">
                                                    <div class="category-header">
                                                        <span class="category-badge" style="background-color: #28a745;">02</span>
                                                        <span class="category-title">Personal-Entwicklung</span>
                                                    </div>
                                                    <div class="category-details">
                                                        <i class="bi bi-person-badge me-1"></i> <strong>Zuständig:</strong> HR-Abteilung
                                                    </div>
                                                </div>

                                                <div class="category-item">
                                                    <div class="category-header">
                                                        <span class="category-badge" style="background-color: #9932CC;">03</span>
                                                        <span class="category-title">Technical Trainings</span>
                                                    </div>
                                                    <div class="category-details">
                                                        <i class="bi bi-person-badge me-1"></i> <strong>Zuständig:</strong> Technical Training Manager (Serkan Yilmaz)
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="id-section mb-4">
                                            <h6><i class="bi bi-upc-scan me-2"></i>ID-Struktur: <code>25-01-02-0001</code></h6>
                                            <ul>
                                                <li><strong>JAHR</strong> - aktuelles Jahr (2-stellig)</li>
                                                <li><strong>HAUPTKATEGORIE</strong> - Code der Hauptkategorie (2-stellig)</li>
                                                <li><strong>UNTERKATEGORIE</strong> - Code der Unterkategorie (2-stellig)</li>
                                                <li><strong>EINDEUTIGE NUMMER</strong> - fortlaufende Nummer (4-stellig)</li>
                                            </ul>
                                        </div>

                                        <div class="functionality-section mb-4">
                                            <h6><i class="bi bi-gear-fill me-2"></i>Funktionen dieser Seite</h6>
                                            <ul>
                                                <li>Anlegen neuer Weiterbildungen mit Kategorie und Zeitraum</li>
                                                <li>Zuweisen von Mitarbeitern zu Weiterbildungen</li>
                                                <li>Bei Technical Trainings (03): Hinzufügen von Trainern</li>
                                                <li>Anzeige der letzten Weiterbildungen im unteren Bereich</li>
                                            </ul>
                                        </div>

                                        <div class="onedrive-section">
                                            <h6><i class="bi bi-cloud me-2"></i>Dokumentation & Ablage</h6>
                                            <p>Die eingescannten Schulungsunterlagen werden im OneDrive abgelegt:</p>
                                            <p class="mb-1">OneDrive > Ball Corporation > 21-Weiterbildungen</p>
                                            <p>Die Ordnerstruktur im OneDrive entspricht den Hauptkategorien in diesem Tool.</p>
                                            <div class="alert alert-warning mt-2 mb-0">
                                                <i class="bi bi-exclamation-triangle me-1"></i> Bei fehlendem Zugriff auf das OneDrive bitte bei der IT melden.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="main_category_id" class="form-label">Hauptkategorie:</label>
                                <select class="form-select" id="main_category_id" name="main_category_id" required>
                                    <option value="" selected disabled>Bitte wählen...</option>
                                    <?php foreach ($main_categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category['id']); ?>"
                                                data-code="<?php echo htmlspecialchars($category['code']); ?>">
                                            <?php echo htmlspecialchars($category['name']); ?>
                                            (<?php echo htmlspecialchars($category['code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="sub_category_id" class="form-label">
                                    Unterkategorie:
                                    <i class="bi bi-info-circle text-primary ms-1" id="subcategory-info"
                                       data-bs-toggle="popover" data-bs-placement="right" data-bs-html="true"
                                       data-bs-custom-class="subcategory-popover"></i>
                                </label>
                                <select class="form-select" id="sub_category_id" name="sub_category_id" required>
                                    <option value="" selected disabled>Bitte zuerst Hauptkategorie wählen</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="training_name" class="form-label">Name der Weiterbildung:</label>
                                <input type="text" class="form-control" id="training_name" name="training_name"
                                       required>
                            </div>
                        </div>
                        <div class="col-md-3">
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
                        <div class="col-md-3">
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
                                                <button type="button" class="dropdown-item" id="end_date_plus2">+2
                                                    Tage
                                                </button>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item" id="end_date_plus3">+3
                                                    Tage
                                                </button>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item" id="end_date_plus7">+7
                                                    Tage
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="training_units" class="form-label">Anzahl der Einheiten:</label>
                                <input type="number" step="0.1" class="form-control" id="training_units"
                                       name="training_units" required>
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    <button type="button" class="btn btn-outline-secondary" id="training_units_0_5">
                                        0,5
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="training_units_1">1
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="training_units_1_5">
                                        1,5
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="training_units_2">2
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="training_units_2_5">
                                        2,5
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="training_units_3">3
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Trainer-Auswahl für Technical Trainings (Kategorie 03) -->
                    <div id="trainer-container" class="mt-4 mb-3">
                        <div class="step-heading">
                            <div class="step-number"><i class="bi bi-person-check"></i></div>
                            <h5 class="mb-0">Trainer auswählen</h5>
                        </div>

                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i> Für Technical Trainings (Hauptkategorie 03) müssen
                            Trainer hinzugefügt werden.
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-8">
                                <select class="form-select" id="trainer-select">
                                    <option value="" selected disabled>Trainer auswählen...</option>
                                    <!-- Diese Options werden per JavaScript gefüllt -->
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
                                <i class="bi bi-exclamation-triangle me-2"></i> Keine Trainer ausgewählt. Bitte fügen
                                Sie mindestens einen Trainer hinzu.
                            </div>
                        </div>

                        <!-- Versteckte Input-Felder für Trainer-IDs -->
                        <div id="trainer-inputs">
                            <!-- Hier werden Input-Felder für Trainer-IDs dynamisch hinzugefügt -->
                        </div>
                    </div>
                </div>

                <div class="form-container">
                    <div class="step-heading">
                        <div class="step-number">2</div>
                        <h5 class="mb-0">Teilnehmer auswählen</h5>
                    </div>

                    <!-- Filter Tabs -->
                    <div class="filter-tabs">
                        <button type="button" class="filter-tab active" data-filter="all">
                            Alle <span class="badge bg-secondary" id="count-all">0</span>
                        </button>
                        <button type="button" class="filter-tab" data-filter="teams">
                            Teams <span class="badge bg-secondary" id="count-teams">0</span>
                        </button>
                        <button type="button" class="filter-tab" data-filter="groups">
                            Gruppen <span class="badge bg-secondary" id="count-groups">0</span>
                        </button>
                        <button type="button" class="filter-tab" data-filter="areas">
                            Bereiche <span class="badge bg-secondary" id="count-areas">0</span>
                        </button>
                        <?php if (isset($employees["Sonstiges"]) && count($employees["Sonstiges"]) > 0): ?>
                            <button type="button" class="filter-tab" data-filter="others">
                                Sonstiges <span class="badge bg-secondary" id="count-others">0</span>
                            </button>
                        <?php endif; ?>
                        <div class="ms-auto d-flex align-items-center">
                            <span class="me-3 badge bg-success fs-6" id="total-selected-count">0 ausgewählt</span>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="expand-all-sections">
                                    <i class="bi bi-chevron-down"></i> Alle ausklappen
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        id="collapse-all-sections">
                                    <i class="bi bi-chevron-up"></i> Alle einklappen
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Mitarbeiter-Suche -->
                    <div class="mb-3 search-container">
                        <input type="text" class="form-control" id="employee-search"
                               placeholder="Mitarbeiter suchen...">
                        <span class="search-indicator"><i class="bi bi-search"></i></span>
                    </div>

                    <!-- Mitarbeiter-Auswahl -->
                    <div id="employee-groups-container">
                        <?php foreach ($employee_groups as $index => $group): ?>
                            <div class="employee-container mb-3" data-group-type="<?php echo $group['type']; ?>"
                                 id="group-container-<?php echo $group['id']; ?>">
                                <div class="employee-group-header">
                                    <div class="employee-group-info">
                                        <i class="bi bi-people-fill me-2"></i>
                                        <?php echo htmlspecialchars($group['name']); ?>
                                    </div>
                                    <div class="employee-group-controls">
                                        <span class="selection-badge" id="selection-count-<?php echo $group['id']; ?>">0 ausgewählt</span>
                                        <span class="badge bg-primary employee-count"><?php echo $group['count']; ?></span>
                                        <button type="button" class="select-group-btn select-all-group"
                                                data-group="<?php echo $group['id']; ?>">
                                            <i class="bi bi-check-all"></i> Alle
                                        </button>
                                        <button type="button" class="select-group-btn btn-outline deselect-all-group"
                                                data-group="<?php echo $group['id']; ?>">
                                            <i class="bi bi-x-lg"></i> Keine
                                        </button>
                                        <span class="toggle-collapse" data-bs-toggle="collapse"
                                              data-bs-target="#group-<?php echo $group['id']; ?>">
                                            <i class="bi bi-chevron-down"></i>
                                        </span>
                                    </div>
                                </div>

                                <div class="collapse<?php echo $index < 2 ? ' show' : ''; ?>"
                                     id="group-<?php echo $group['id']; ?>">
                                    <div class="employee-list-container">
                                        <?php foreach ($employees[$group['name']] as $emp): ?>
                                            <div class="employee-item"
                                                 data-employee-id="<?php echo $emp['employee_id']; ?>">
                                                <div class="form-check">
                                                    <input class="form-check-input employee-checkbox"
                                                           type="checkbox"
                                                           name="employee_ids[]"
                                                           id="employee_<?php echo $emp['employee_id']; ?>"
                                                           value="<?php echo $emp['employee_id']; ?>"
                                                           data-name="<?php echo htmlspecialchars(strtolower($emp['name'])); ?>"
                                                           data-group="<?php echo $group['id']; ?>">
                                                    <label class="form-check-label"
                                                           for="employee_<?php echo $emp['employee_id']; ?>">
                                                        <?php echo htmlspecialchars($emp['name']); ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-end mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Weiterbildung speichern
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="section-divider">
        <span>Letzte Weiterbildungen</span>
    </div>

    <!-- Letzte 10 Weiterbildungen -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Letzte Weiterbildungen</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Name der Weiterbildung</th>
                        <th>Kategorie</th>
                        <th>Unterkategorie</th>
                        <th>Zeitraum</th>
                        <th>Einheiten</th>
                        <th>Angelegt von</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($last_trainings as $training): ?>
                        <tr>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo htmlspecialchars($training['display_id'] ?? $training['id']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="edit_training.php?id=<?php echo htmlspecialchars($training['id']); ?>"
                                   class="training-link">
                                    <?php echo htmlspecialchars($training['training_name']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($training['main_category_name']); ?></td>
                            <td><?php echo htmlspecialchars($training['sub_category_name'] ?? '—'); ?></td>
                            <td>
                                <?php
                                echo htmlspecialchars(date('d.m.Y', strtotime($training['start_date'])));
                                if ($training['start_date'] != $training['end_date']) {
                                    echo ' - ' . htmlspecialchars(date('d.m.Y', strtotime($training['end_date'])));
                                }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($training['training_units']); ?></td>
                            <td><?php echo htmlspecialchars($training['created_by']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal für Erfolg oder Fehler -->
<div class="modal fade" id="resultModal" tabindex="-1" aria-labelledby="resultModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="resultModalLabel">Ergebnis</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="resultMessage"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Unterkategorien nach Hauptkategorie filtern via AJAX
        const mainCategorySelect = document.getElementById('main_category_id');
        const subCategorySelect = document.getElementById('sub_category_id');
        const trainerContainer = document.getElementById('trainer-container');

        // Speichere alle Mitarbeiter als potenzielle Trainer
        const allEmployees = {};
        <?php foreach ($employee_groups as $group): ?>
        <?php foreach ($employees[$group['name']] as $emp): ?>
        allEmployees[<?php echo $emp['employee_id']; ?>] = {
            id: <?php echo $emp['employee_id']; ?>,
            name: "<?php echo htmlspecialchars($emp['name']); ?>"
        };
        <?php endforeach; ?>
        <?php endforeach; ?>

        // Trainer-Verwaltung
        let selectedTrainers = [];
        const trainerSelect = document.getElementById('trainer-select');
        const addTrainerBtn = document.getElementById('add-trainer-btn');
        const selectedTrainersContainer = document.getElementById('selected-trainers');
        const noTrainersWarning = document.getElementById('no-trainers-warning');
        const trainerInputsContainer = document.getElementById('trainer-inputs');

        // Fülle das Trainer-Dropdown
        function populateTrainerDropdown() {
            trainerSelect.innerHTML = '<option value="" selected disabled>Trainer auswählen...</option>';

            // Sortiere Mitarbeiter nach Namen
            const sortedEmployees = Object.values(allEmployees).sort((a, b) =>
                a.name.localeCompare(b.name, 'de')
            );

            // Füge alle Mitarbeiter hinzu, die noch nicht als Trainer ausgewählt wurden
            sortedEmployees.forEach(emp => {
                // Überprüfe, ob der Mitarbeiter bereits als Trainer ausgewählt wurde
                if (!selectedTrainers.some(trainer => trainer.id === emp.id)) {
                    const option = document.createElement('option');
                    option.value = emp.id;
                    option.textContent = emp.name;
                    trainerSelect.appendChild(option);
                }
            });
        }

        // Trainer hinzufügen
        function addTrainer() {
            const trainerId = trainerSelect.value;
            const trainerName = trainerSelect.options[trainerSelect.selectedIndex]?.text;

            if (!trainerId || !trainerName) return;

            // Trainer zum Array hinzufügen
            selectedTrainers.push({
                id: trainerId,
                name: trainerName
            });

            // Aktualisiere die Anzeige
            updateTrainerDisplay();

            // Aktualisiere das Dropdown
            populateTrainerDropdown();
        }

        // Trainer entfernen
        function removeTrainer(trainerId) {
            selectedTrainers = selectedTrainers.filter(trainer => trainer.id != trainerId);
            updateTrainerDisplay();
            populateTrainerDropdown();
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

        // Initialisiere Trainer-Dropdown
        populateTrainerDropdown();

        mainCategorySelect.addEventListener('change', function () {
            const mainCategoryId = this.value;

            // Prüfe, ob es sich um Technical Trainings (Kategorie 03) handelt
            const isTechnicalTraining = mainCategoryId == 3 || this.options[this.selectedIndex].text.includes('03');

            // Zeige/verstecke Trainer-Auswahl entsprechend
            trainerContainer.style.display = isTechnicalTraining ? 'block' : 'none';

            // Alle Optionen entfernen
            subCategorySelect.innerHTML = '';

            // Default Option hinzufügen
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.text = 'Bitte wählen...';
            defaultOption.disabled = true;
            defaultOption.selected = true;
            subCategorySelect.appendChild(defaultOption);

            if (mainCategoryId > 0) {
                // Unterkategorien über AJAX laden
                fetch(`get_subcategories.php?main_category_id=${mainCategoryId}`)
                    .then(response => response.json())
                    .then(data => {
                        console.log("Received data:", data); // Debug-Ausgabe
                        if (data && data.length > 0) {
                            data.forEach(subCat => {
                                const option = document.createElement('option');
                                option.value = subCat.id;
                                // Sicherstellen, dass der Code-Wert existiert
                                const codeDisplay = subCat.code ? ` (${subCat.code})` : '';
                                option.textContent = `${subCat.name}${codeDisplay}`;
                                subCategorySelect.appendChild(option);
                            });
                        } else {
                            // Keine Unterkategorien gefunden
                            const emptyOption = document.createElement('option');
                            emptyOption.disabled = true;
                            emptyOption.textContent = 'Keine Unterkategorien verfügbar';
                            subCategorySelect.appendChild(emptyOption);
                        }
                    })
                    .catch(error => {
                        console.error('Fehler beim Laden der Unterkategorien:', error);
                        const errorOption = document.createElement('option');
                        errorOption.disabled = true;
                        errorOption.textContent = 'Fehler beim Laden der Unterkategorien';
                        subCategorySelect.appendChild(errorOption);
                    });
            }
        });

        // Funktion, um die Anzahl der ausgewählten Mitarbeiter pro Gruppe zu aktualisieren
        function updateSelectedCount() {
            const groups = document.querySelectorAll('.employee-container');
            let totalSelected = 0;

            groups.forEach(group => {
                const groupId = group.id.replace('group-container-', '');
                const checkboxes = group.querySelectorAll('.employee-checkbox');
                const selectedCheckboxes = Array.from(checkboxes).filter(checkbox => checkbox.checked);
                const selectionBadge = document.getElementById(`selection-count-${groupId}`);

                totalSelected += selectedCheckboxes.length;

                if (selectedCheckboxes.length > 0) {
                    selectionBadge.textContent = `${selectedCheckboxes.length} ausgewählt`;
                    selectionBadge.style.display = 'inline-block';
                } else {
                    selectionBadge.style.display = 'none';
                }
            });

            // Aktualisiere den Gesamtzähler
            const totalSelectedCountEl = document.getElementById('total-selected-count');
            totalSelectedCountEl.textContent = `${totalSelected} ausgewählt`;
        }

        // Klickbare Mitarbeiter-Items
        document.querySelectorAll('.employee-item').forEach(item => {
            item.addEventListener('click', function (e) {
                // Verhindere den Standardklick nur wenn nicht direkt auf die Checkbox geklickt wurde
                if (!e.target.classList.contains('employee-checkbox') && e.target.type !== 'checkbox') {
                    e.preventDefault();

                    // Finde die Checkbox innerhalb des geklickten Items
                    const checkbox = this.querySelector('.employee-checkbox');

                    // Umkehren des Checkbox-Status
                    checkbox.checked = !checkbox.checked;

                    // Löse manuell ein change-Event aus, damit der Event-Listener darauf reagieren kann
                    const changeEvent = new Event('change', {bubbles: true});
                    checkbox.dispatchEvent(changeEvent);
                }
            });
        });

        // Checkbox-Änderungsereignisse verfolgen
        document.querySelectorAll('.employee-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // "Alle auswählen" und "Keine" Buttons für einzelne Gruppen
        document.querySelectorAll('.select-all-group').forEach(button => {
            button.addEventListener('click', function (e) {
                e.stopPropagation(); // Verhindert, dass das Akkordeon toggled
                const groupId = this.getAttribute('data-group');
                const checkboxes = document.querySelectorAll(`.employee-checkbox[data-group="${groupId}"]:not(:disabled)`);
                checkboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });

                // Zähler aktualisieren
                updateSelectedCount();
            });
        });

        // Neue Event-Listener für Ein-/Ausklapp-Buttons
        document.getElementById('expand-all-sections').addEventListener('click', function () {
            document.querySelectorAll('.employee-container .collapse').forEach(section => {
                new bootstrap.Collapse(section, {toggle: false}).show();
            });
        });

        document.getElementById('collapse-all-sections').addEventListener('click', function () {
            document.querySelectorAll('.employee-container .collapse').forEach(section => {
                new bootstrap.Collapse(section, {toggle: false}).hide();
            });
        });

// Initialisiere den Gesamtzähler
        updateSelectedCount();

        document.querySelectorAll('.deselect-all-group').forEach(button => {
            button.addEventListener('click', function (e) {
                e.stopPropagation(); // Verhindert, dass das Akkordeon toggled
                const groupId = this.getAttribute('data-group');
                const checkboxes = document.querySelectorAll(`.employee-checkbox[data-group="${groupId}"]:not(:disabled)`);
                checkboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });

                // Zähler aktualisieren
                updateSelectedCount();
            });
        });

        // Mitarbeiter-Filter
        const filterTabs = document.querySelectorAll('.filter-tab');
        const employeeContainers = document.querySelectorAll('.employee-container');

        // Zähle Mitarbeiter nach Typ
        function updateEmployeeCounts() {
            const counts = {
                'all': 0,
                'teams': 0,
                'groups': 0,
                'areas': 0,
                'others': 0
            };

            employeeContainers.forEach(container => {
                const type = container.dataset.groupType;
                const count = parseInt(container.querySelector('.employee-count').textContent);
                counts[type] += count;
                counts['all'] += count;
            });

            document.getElementById('count-all').textContent = counts['all'];
            document.getElementById('count-teams').textContent = counts['teams'];
            document.getElementById('count-groups').textContent = counts['groups'];
            document.getElementById('count-areas').textContent = counts['areas'];

            // Nur aktualisieren, wenn das Element existiert
            const othersCount = document.getElementById('count-others');
            if (othersCount) {
                othersCount.textContent = counts['others'];
            }
        }

        updateEmployeeCounts();

        filterTabs.forEach(tab => {
            tab.addEventListener('click', function () {
                const filter = this.dataset.filter;

                // Toggle active class
                filterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');

                // Filter employee containers
                employeeContainers.forEach(container => {
                    if (filter === 'all' || container.dataset.groupType === filter) {
                        container.style.display = 'block';
                    } else {
                        container.style.display = 'none';
                    }
                });
            });
        });

        // Hilfsfunktion zum Dekodieren von HTML-Entitäten
        function decodeHTMLEntities(text) {
            const textArea = document.createElement('textarea');
            textArea.innerHTML = text;
            return textArea.value;
        }

        // Hilfsfunktion zum Normalisieren von Umlauten und Sonderzeichen
        function normalizeString(str) {
            // Zuerst HTML-Entitäten dekodieren
            const decodedStr = decodeHTMLEntities(str);

            // Dann normalisieren
            return decodedStr.toLowerCase()
                .replace(/[äáàâã]/g, 'a')
                .replace(/[ëéèê]/g, 'e')
                .replace(/[ïíìî]/g, 'i')
                .replace(/[öóòôõ]/g, 'o')
                .replace(/[üúùû]/g, 'u')
                .replace(/[ß]/g, 'ss')
                .replace(/[ç]/g, 'c')
                .replace(/[ñ]/g, 'n');
        }

        // Mitarbeiter-Suche
        const searchInput = document.getElementById('employee-search');
        const employeeItems = document.querySelectorAll('.employee-item');

        searchInput.addEventListener('input', function () {
            // Der eingegebene Suchtext wird normalisiert
            const searchText = normalizeString(this.value.trim());

            // Debug-Info
            console.log('Searching for:', searchText);

            // Filtere Mitarbeiter
            employeeItems.forEach(item => {
                const checkbox = item.querySelector('.employee-checkbox');
                const employeeName = checkbox.dataset.name;
                const labelText = item.querySelector('.form-check-label').textContent.trim();

                // Sowohl der gespeicherte Name als auch der Labeltext werden normalisiert
                const normalizedDataName = normalizeString(employeeName);
                const normalizedLabelName = normalizeString(labelText);

                // Suche in beiden normalisierten Werten
                if (normalizedDataName.includes(searchText) || normalizedLabelName.includes(searchText)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });

            // Zeige/verstecke Container basierend auf sichtbaren Mitarbeitern und öffne sie automatisch
            employeeContainers.forEach(container => {
                const visibleEmployees = container.querySelectorAll('.employee-item[style="display: flex;"]');
                const collapseElement = container.querySelector('.collapse');

                if (visibleEmployees.length === 0 && searchText !== '') {
                    container.style.display = 'none';
                } else if (document.querySelector('.filter-tab.active').dataset.filter === 'all' ||
                    container.dataset.groupType === document.querySelector('.filter-tab.active').dataset.filter) {
                    container.style.display = 'block';

                    // Wenn wir suchen und es Ergebnisse gibt, öffnen wir die Gruppe automatisch
                    if (searchText !== '' && visibleEmployees.length > 0) {
                        new bootstrap.Collapse(collapseElement, {toggle: false}).show();
                    }
                }
            });
        });

        // Datum-Schnellauswahl
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');

        function getToday() {
            return new Date().toISOString().split('T')[0];
        }

        function getYesterday() {
            let d = new Date();
            d.setDate(d.getDate() - 1);
            return d.toISOString().split('T')[0];
        }

        // Setze standardmäßig das heutige Datum
        if (!startDateInput.value) {
            startDateInput.value = getToday();
            endDateInput.value = getToday();
        }

        document.getElementById('start_date_today').addEventListener('click', () => {
            startDateInput.value = getToday();
        });
        document.getElementById('start_date_yesterday').addEventListener('click', () => {
            startDateInput.value = getYesterday();
        });

        // Enddatum-Schnellauswahl
        function addDays(baseDate, days) {
            let d = new Date(baseDate);
            d.setDate(d.getDate() + days);
            return d.toISOString().split('T')[0];
        }

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

        // Trainingseinheiten-Schnellauswahl
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

        const subcategoryInfoEl = document.getElementById('subcategory-info');
        if (subcategoryInfoEl) {
            const subcategoryPopover = new bootstrap.Popover(subcategoryInfoEl, {
                title: 'Erklärungen zu Unterkategorien',
                content: subcategoryInfoContent,
                trigger: 'click',
                container: 'body'
            });
        }

        // Form-Validierung
        document.querySelector('form').addEventListener('submit', function (e) {
            const mainCategoryId = mainCategorySelect.value;

            // Prüfe, ob es sich um Technical Trainings (Kategorie 03) handelt
            const isTechnicalTraining = mainCategoryId == 3 || mainCategorySelect.options[mainCategorySelect.selectedIndex].text.includes('03');

            // Wenn Technical Training, aber keine Trainer ausgewählt
            if (isTechnicalTraining && selectedTrainers.length === 0) {
                e.preventDefault();
                alert('Bitte wählen Sie mindestens einen Trainer für das Technical Training aus.');
            }
        });

        // Ergebnismeldung anzeigen
        <?php if (!empty($result_message)): ?>
        const resultModal = new bootstrap.Modal(document.getElementById('resultModal'));
        const resultMessage = document.getElementById('resultMessage');

        <?php if ($result_type === 'success'): ?>
        resultMessage.innerHTML = '<div class="alert alert-success"><?php echo addslashes($result_message); ?></div>';
        <?php else: ?>
        resultMessage.innerHTML = '<div class="alert alert-danger"><?php echo addslashes($result_message); ?></div>';
        <?php endif; ?>

        resultModal.show();
        <?php endif; ?>
    });
</script>
</body>
</html>