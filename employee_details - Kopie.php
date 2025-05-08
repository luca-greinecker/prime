<?php
/**
 * employee_details.php
 *
 * Diese Seite zeigt detaillierte Informationen zu einem Mitarbeiter an – einschließlich
 * letzter Mitarbeitergespräche, Bewertungen, Ausbildung, Weiterbildungen und weiteren Daten.
 * Zugriffskontrolle: Nur Benutzer mit entsprechenden Rechten (z. B. Führungskräfte)
 * dürfen die Daten einsehen und bearbeiten.
 */

include 'access_control.php'; // Session-Management und Zugriffskontrolle
global $conn;
pruefe_benutzer_eingeloggt();

// Prüfen, ob Ergebnismeldungen in der Session vorliegen
$has_result_message = isset($_SESSION['result_message']) && !empty($_SESSION['result_message']);
$result_message = $has_result_message ? $_SESSION['result_message'] : '';
$result_type = isset($_SESSION['result_type']) ? $_SESSION['result_type'] : 'success';

// Session-Meldungen nach dem Abrufen zurücksetzen
if ($has_result_message) {
    unset($_SESSION['result_message']);
    unset($_SESSION['result_type']);
}

// Bestimme den Referer (falls vorhanden) für "Zurück"-Links
$previous_page = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';

// Logged-in Benutzer: Position und Crew anhand des internen Schlüssels (employee_id)
$stmt = $conn->prepare("SELECT position, crew FROM employees WHERE employee_id = ?");
$stmt->bind_param("i", $_SESSION['mitarbeiter_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$position = $user['position'] ?? '';
$crew = $user['crew'] ?? '';
$ist_admin = ist_admin();
$ist_sm = ist_sm();
$ist_smstv = ist_smstv();
$ist_hr = ist_hr();
$ist_bereichsleiter = ist_bereichsleiter();
$ist_trainingsmanager = ist_trainingsmanager();
$ist_ehs = ist_ehs();

// Mitarbeiterinformationen abrufen (Parameter 'id' entspricht nun employee_id)
if (isset($_GET['employee_id']) && is_numeric($_GET['employee_id'])) {
    $id = (int)$_GET['employee_id'];

    // Daten des Mitarbeiters aus der Tabelle employees anhand des internen Schlüssels abrufen
    $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ?");
    if (!$stmt) {
        die("Fehler bei der Vorbereitung des Statements: " . $conn->error);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Zugriffskontrolle: Prüfe, ob der eingeloggte Benutzer Zugriff auf diesen Mitarbeiter hat
        if (!hat_zugriff_auf_mitarbeiter($id)) {
            header("Location: access_denied.php");
            exit;
        }
    } else {
        echo '<p>Keine Daten gefunden</p>';
        exit;
    }
    $stmt->close();
} else {
    echo '<p>Ungültige Anfrage</p>';
    exit;
}

// Für Formularfelder, die für Nicht-HR bzw. Nicht-Schichtmeister gesperrt sein sollen
$disabled = !$ist_hr ? 'disabled' : '';
$disabled_sm = (!$ist_hr && !$ist_sm) ? 'disabled' : '';

// Letzte Bewertung und zugehörige Skills des Mitarbeiters abrufen
$review_stmt = $conn->prepare("
    SELECT employee_reviews.employee_id, employee_reviews.date, employee_reviews.rueckblick, employee_reviews.entwicklung,
           employee_reviews.feedback, employee_reviews.brandschutzwart, employee_reviews.sprinklerwart, 
           employee_reviews.ersthelfer, employee_reviews.svp, employee_reviews.trainertaetigkeiten, 
           employee_reviews.trainertaetigkeiten_kommentar, employee_reviews.zufriedenheit, 
           employee_reviews.unzufriedenheit_grund, employee_reviews.reviewer_id, employee_reviews.id AS review_id
    FROM employee_reviews
    WHERE employee_id = ?
    ORDER BY date DESC
    LIMIT 1
");
$review_stmt->bind_param("i", $id);
$review_stmt->execute();
$review_result = $review_stmt->get_result();

$review_row = null;
$skills = [];
$reviewer_name = 'Unbekannt';  // Standardwert
if ($review_result->num_rows > 0) {
    $review_row = $review_result->fetch_assoc();
    $review_id = $review_row['review_id'];

    // Reviewer-Name abrufen anhand des internen Schlüssels
    if (!empty($review_row['reviewer_id'])) {
        $reviewer_stmt = $conn->prepare("SELECT name FROM employees WHERE employee_id = ?");
        $reviewer_stmt->bind_param("i", $review_row['reviewer_id']);
        $reviewer_stmt->execute();
        $reviewer_result = $reviewer_stmt->get_result();
        if ($reviewer_result->num_rows > 0) {
            $reviewer_name = $reviewer_result->fetch_assoc()['name'];
        }
        $reviewer_stmt->close();
    }

    // Skills zur letzten Bewertung abrufen
    $skills_stmt = $conn->prepare("
        SELECT s.name, s.kategorie, employee_skills.rating 
        FROM employee_skills 
        JOIN skills s ON employee_skills.skill_id = s.id 
        WHERE employee_skills.review_id = ?
    ");
    $skills_stmt->bind_param("i", $review_id);
    $skills_stmt->execute();
    $skills_result = $skills_stmt->get_result();
    while ($skill_row = $skills_result->fetch_assoc()) {
        $skills[] = $skill_row;
    }
    $skills_stmt->close();
}
$review_stmt->close();

// Ausbildungsdaten abrufen
$education_stmt = $conn->prepare("SELECT id, education_type, education_field FROM employee_education WHERE employee_id = ?");
$education_stmt->bind_param("i", $id);
$education_stmt->execute();
$education_result = $education_stmt->get_result();
$education = [];
while ($education_row = $education_result->fetch_assoc()) {
    $education[] = $education_row;
}
$education_stmt->close();

// Weiterbildungsdaten abrufen
$training_stmt = $conn->prepare("
    SELECT 
        t.id AS training_id, 
        t.display_id,
        t.training_name, 
        t.start_date, 
        t.end_date, 
        t.training_units,
        mc.name AS main_category_name,
        sc.name AS sub_category_name
    FROM employee_training et
    JOIN trainings t ON et.training_id = t.id
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    WHERE et.employee_id = ?
    ORDER BY t.start_date DESC
");
$training_stmt->bind_param("i", $id);
$training_stmt->execute();
$training_result = $training_stmt->get_result();
$training = [];
while ($training_row = $training_result->fetch_assoc()) {
    $training[] = $training_row;
}
$training_stmt->close();

// Teams und Gruppen abrufen
$teams = ["Team L", "Team M", "Team N", "Team O", "Team P"];
$gruppen_stmt = $conn->prepare("SELECT DISTINCT gruppe FROM positionen");
$gruppen_stmt->execute();
$gruppen_result = $gruppen_stmt->get_result();
$gruppen = [];
while ($row_gruppe = $gruppen_result->fetch_assoc()) {
    $gruppen[] = $row_gruppe['gruppe'];
}
$gruppen_stmt->close();

// Funktion: Dynamisch Positionen anhand der Gruppe abrufen
function getPositionenByGruppe($gruppe)
{
    global $conn;
    $positionen_stmt = $conn->prepare("SELECT id, name FROM positionen WHERE gruppe = ?");
    if (!$positionen_stmt) {
        die("Fehler bei der Vorbereitung der Positionsabfrage: " . $conn->error);
    }
    $positionen_stmt->bind_param("s", $gruppe);
    $positionen_stmt->execute();
    $positionen_result = $positionen_stmt->get_result();
    if (!$positionen_result) {
        die("Fehler bei der Abfrage der Positionen: " . $conn->error);
    }
    $positionen = [];
    while ($row = $positionen_result->fetch_assoc()) {
        $positionen[] = $row;
    }
    $positionen_stmt->close();
    return $positionen;
}

$initial_areas = getPositionenByGruppe($row['gruppe']);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitarbeiterdetails</title>
    <!-- Lokales Bootstrap 5 CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .mb-3 img {
            max-width: 100%;
            height: auto;
        }

        .employee-image {
            border: 1px solid #000;
            border-radius: 8px;
            max-height: 100%;
            width: auto;
        }

        .no-image {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            height: 100%;
            padding: 50px;
            background-color: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
            border-radius: 8px;
            text-align: center;
        }

        .dropdown-header {
            font-weight: bold;
            padding: 0.5rem 1.5rem;
            color: #343a40;
            background-color: #e9ecef;
        }

        .additional-info, .other-selects {
            background-color: #f8f9fa;
            border: 1px solid #007bff;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin: 0 !important;
        }

        .additional-info h4 {
            margin-top: 0;
            font-weight: bold;
        }

        .form-check {
            margin-bottom: 0.1rem;
        }

        .form-check-input {
            margin-right: 0.5rem;
        }

        .btn-save {
            float: right;
        }

        .rand-oben {
            margin-top: 1.5rem;
        }

        .email-btn {
            width: 100%;
        }

        .flex-column {
            margin-bottom: 1rem;
        }

        .thin-divider {
            margin: 2rem 0;
            border-top: 1px solid #dee2e6;
        }

        .training-info {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="content container">
    <h1 class="text-center mb-3">Mitarbeiter bearbeiten</h1>
    <div class="divider"></div>
    <form id="employeeForm" action="update_employee.php" method="POST">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
        <div class="row">
            <div class="col-md-9">
                <!-- Allgemeine Informationen -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="name">Name:</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?php echo htmlspecialchars($row['name']); ?>" disabled>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="gender">Geschlecht:</label>
                            <select class="form-control" name="gender" id="gender" <?php echo $disabled; ?>>
                                <option value="männlich" <?php if ($row['gender'] == 'männlich') echo 'selected'; ?>>
                                    männlich
                                </option>
                                <option value="weiblich" <?php if ($row['gender'] == 'weiblich') echo 'selected'; ?>>
                                    weiblich
                                </option>
                                <option value="divers" <?php if ($row['gender'] == 'divers') echo 'selected'; ?>>
                                    divers
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="lohnschema">Lohnschema:</label>
                            <select class="form-control" id="lohnschema" name="lohnschema" <?php echo $disabled; ?>>
                                <option value="---" <?php if ($row['lohnschema'] == '---' || empty($row['lohnschema'])) echo 'selected'; ?>>
                                    ---
                                </option>
                                <option value="Technik" <?php if ($row['lohnschema'] == 'Technik') echo 'selected'; ?>>
                                    Technik
                                </option>
                                <option value="Produktion" <?php if ($row['lohnschema'] == 'Produktion') echo 'selected'; ?>>
                                    Produktion
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Gruppe, Team und Position -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="gruppe">Gruppe:</label>
                            <select class="form-control" id="gruppe" name="gruppe" <?php echo $disabled; ?>>
                                <?php foreach ($gruppen as $gruppe): ?>
                                    <option value="<?php echo htmlspecialchars($gruppe); ?>" <?php if ($row['gruppe'] == $gruppe) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($gruppe); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="crew">Team:</label>
                            <select class="form-control" id="crew" name="crew" <?php echo $disabled; ?>>
                                <option value="---" <?php if ($row['crew'] == '---') echo 'selected'; ?>>---</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo htmlspecialchars($team); ?>" <?php if ($row['crew'] == $team) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($team); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="position">Position:</label>
                            <select class="form-control" id="position" name="position" <?php echo $disabled_sm; ?>>
                                <?php foreach ($initial_areas as $area): ?>
                                    <option value="<?php echo htmlspecialchars($area['name']); ?>" <?php if (htmlspecialchars($row['position']) == htmlspecialchars($area['name'])) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($area['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Datum-Felder -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="last_present">Zuletzt anwesend:</label>
                            <input type="date" class="form-control" id="last_present" name="last_present"
                                   value="<?php echo htmlspecialchars($row['last_present']); ?>" disabled>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="entry_date">Eintritt:</label>
                            <input type="date" class="form-control" id="entry_date" name="entry_date"
                                   value="<?php echo htmlspecialchars($row['entry_date']); ?>" <?php echo !$ist_hr ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="first_entry_date">Ersteintritt:</label>
                            <input type="date" class="form-control" id="first_entry_date" name="first_entry_date"
                                   value="<?php echo htmlspecialchars($row['first_entry_date']); ?>" disabled>
                        </div>
                    </div>
                </div>

                <!-- Kontakt-Informationen -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="social_security_number">SV-Nummer:</label>
                            <input type="text" class="form-control" id="social_security_number"
                                   name="social_security_number"
                                   value="<?php echo htmlspecialchars($row['social_security_number']); ?>" disabled>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label for="phone_number">Telefonnummer:</label>
                            <input type="text" class="form-control" id="phone_number" name="phone_number"
                                   value="<?php echo htmlspecialchars($row['phone_number']); ?>" <?php echo !$ist_hr ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="badge_id">Ausweisnummer:</label>
                            <input type="text" class="form-control" id="badge_id" name="badge_id"
                                   value="<?php echo htmlspecialchars($row['badge_id']); ?>" <?php echo !$ist_hr ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="birthdate">Geburtsdatum:</label>
                            <input type="date" class="form-control" id="birthdate" name="birthdate"
                                   value="<?php echo htmlspecialchars($row['birthdate']); ?>" disabled>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="mb-3">
                            <label for="status">Status:</label>
                            <?php if ($row['anwesend']): ?>
                                <input type="text" class="form-control" value="Anwesend" disabled>
                                <small class="text-muted">Status kann nicht geändert werden (anwesend).</small>
                            <?php else: ?>
                                <select class="form-control" id="status" name="status">
                                    <?php
                                    $status_options = [
                                        0 => '-',
                                        1 => 'Krank',
                                        2 => 'Urlaub',
                                        3 => 'Schulung'
                                    ];
                                    foreach ($status_options as $key => $label) {
                                        $selected = ($row['status'] == $key) ? 'selected' : '';
                                        echo "<option value=\"$key\" $selected>$label</option>";
                                    }
                                    ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Image Section -->
            <div class="col-md-3 d-flex flex-column">
                <?php if (!empty($row['bild'])): ?>
                    <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($row['bild']); ?>"
                         alt="Mitarbeiterfoto" class="img-fluid employee-image flex-grow-1">
                <?php else: ?>
                    <div class="no-image flex-grow-1">Kein Bild vorhanden!</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- HR und Admin spezifische Felder -->
        <?php if ($ist_hr): ?>
            <!-- Private und Geschäftliche E-Mail -->
            <div class="row">
                <div class="col-md-2">
                    <div class="mb-3">
                        <label for="email_private">Mail (privat):</label>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="mb-3">
                        <input type="email" class="form-control" id="email_private" name="email_private"
                               value="<?php echo htmlspecialchars($row['email_private']); ?>">
                    </div>
                </div>
                <div class="col-md-3 text-right">
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary email-btn"
                                onclick="window.location.href='mailto:<?php echo htmlspecialchars($row['email_private']); ?>'">
                            Email senden
                        </button>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-2">
                    <div class="mb-3">
                        <label for="email_business">Mail (geschäftlich):</label>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="mb-3">
                        <input type="email" class="form-control" id="email_business" name="email_business"
                               value="<?php echo htmlspecialchars($row['email_business']); ?>">
                    </div>
                </div>
                <div class="col-md-3 text-right">
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary email-btn"
                                onclick="window.location.href='mailto:<?php echo htmlspecialchars($row['email_business']); ?>'">
                            Email senden
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Sonstige Checkbox-Felder -->
        <div class="row other-selects">
            <div class="col-md-3">
                <div class="mb-3">
                    <label>Sonstiges:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="leasing" id="leasing"
                               value="1" <?php if ($row['leasing']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="leasing">Leasing</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="ersthelfer" id="ersthelfer"
                               value="1" <?php if ($row['ersthelfer']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="ersthelfer">Ersthelfer</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="svp" id="svp"
                               value="1" <?php if ($row['svp']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="svp">Sicherheitsvertrauensperson</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="brandschutzwart" id="brandschutzwart"
                               value="1" <?php if ($row['brandschutzwart']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="brandschutzwart">Brandschutzwart</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sprinklerwart" id="sprinklerwart"
                               value="1" <?php if ($row['sprinklerwart']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="sprinklerwart">Sprinklerwart</label>
                    </div>
                </div>
            </div>

            <!-- Produktionsfelder -->
            <div class="col-md-3">
                <div id="production-fields" class="mb-3" style="display: none;">
                    <label>Lohnschema - Produktion:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pr_lehrabschluss" name="pr_lehrabschluss"
                               value="1" <?php if ($row['pr_lehrabschluss']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="pr_lehrabschluss">Einstufung mit Lehrabschluss</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pr_anfangslohn" name="pr_anfangslohn"
                               value="1" <?php if ($row['pr_anfangslohn']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="pr_anfangslohn">Anfangslohn</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pr_grundlohn" name="pr_grundlohn"
                               value="1" <?php if ($row['pr_grundlohn']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="pr_grundlohn">Grundlohn</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pr_qualifikationsbonus"
                               name="pr_qualifikationsbonus"
                               value="1" <?php if ($row['pr_qualifikationsbonus']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="pr_qualifikationsbonus">Qualifikationsbonus</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pr_expertenbonus" name="pr_expertenbonus"
                               value="1" <?php if ($row['pr_expertenbonus']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="pr_expertenbonus">Expertenbonus</label>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <!-- Technikfelder -->
                <div id="tech-fields" class="mb-3" style="display: none;">
                    <label>Lohnschema - Technik:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_1"
                               name="tk_qualifikationsbonus_1"
                               value="1" <?php if ($row['tk_qualifikationsbonus_1']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="tk_qualifikationsbonus_1">Qualifikationsbonus 1</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_2"
                               name="tk_qualifikationsbonus_2"
                               value="1" <?php if ($row['tk_qualifikationsbonus_2']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="tk_qualifikationsbonus_2">Qualifikationsbonus 2</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_3"
                               name="tk_qualifikationsbonus_3"
                               value="1" <?php if ($row['tk_qualifikationsbonus_3']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="tk_qualifikationsbonus_3">Qualifikationsbonus 3</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_4"
                               name="tk_qualifikationsbonus_4"
                               value="1" <?php if ($row['tk_qualifikationsbonus_4']) echo 'checked'; ?> <?php echo $disabled; ?>>
                        <label class="form-check-label" for="tk_qualifikationsbonus_4">Qualifikationsbonus 4</label>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <!-- Allgemeine Zulagen -->
                <div id="general-zulage" class="mb-3 mt-3" style="display: none;">
                    <label for="ln_zulage">Zulage:</label>
                    <select class="form-control" id="ln_zulage" name="ln_zulage" <?php echo $disabled; ?>>
                        <option value="Keine Zulage" <?php if ($row['ln_zulage'] == 'Keine Zulage') echo 'selected'; ?>>
                            Keine Zulage
                        </option>
                        <option value="5% Zulage" <?php if ($row['ln_zulage'] == '5% Zulage') echo 'selected'; ?>>5%
                            Zulage
                        </option>
                        <option value="10% Zulage" <?php if ($row['ln_zulage'] == '10% Zulage') echo 'selected'; ?>>10%
                            Zulage
                        </option>
                    </select>
                </div>
            </div>

            <?php if (!empty($row['leasing']) && $row['leasing'] == 1): ?>
                <div class="alert alert-danger text-center fw-bold mb-0">
                    Achtung! Leasing-Mitarbeiter - Keine Lohndaten hinterlegt!
                </div>
            <?php endif; ?>
        </div>

        <!-- Form Actions -->
        <div class="row mt-3">
            <div class="col-md-12 d-flex justify-content-between">
                <a href="<?php echo htmlspecialchars($previous_page); ?>" class="btn btn-secondary">Zurück</a>
                <?php if ($ist_hr || $ist_sm || $ist_smstv): ?>
                    <button type="submit" class="btn btn-warning btn-save">Änderungen speichern</button>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <div class="divider"></div>
    <br>
    <div class="additional-info">
        <h4>Ausbildung</h4>
        <div class="thin-divider"></div>
        <table class="table">
            <thead>
            <tr>
                <th>Art der Ausbildung</th>
                <th>Ausbildung</th>
                <?php if ($ist_hr): ?>
                    <th>Aktionen</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($education as $edu): ?>
                <tr>
                    <td><?php echo htmlspecialchars($edu['education_type']); ?></td>
                    <td><?php echo htmlspecialchars($edu['education_field']); ?></td>
                    <?php if ($ist_hr): ?>
                        <td>
                            <a href="edit_education.php?id=<?php echo $edu['id']; ?>&employee_id=<?php echo $id; ?>"
                               class="btn btn-warning btn-sm">Bearbeiten</a>
                            <a href="delete_education.php?id=<?php echo $edu['id']; ?>&employee_id=<?php echo $id; ?>"
                               class="btn btn-danger btn-sm">Löschen</a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($ist_hr): ?>
            <a href="add_education.php?employee_id=<?php echo $id; ?>" class="btn btn-success">Ausbildung hinzufügen</a>
        <?php endif; ?>
    </div>
    <br>
    <div class="additional-info">
        <h4>Weiterbildung</h4>
        <div class="thin-divider"></div>
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Hauptkategorie</th>
                <th>Unterkategorie</th>
                <th>Name der Weiterbildung</th>
                <th>Zeitraum</th>
                <th>Einheiten</th>
                <?php if ($ist_hr || $ist_trainingsmanager || $ist_ehs): ?>
                    <th>Aktionen</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($training as $train): ?>
                <tr>
                    <td>
                    <span class="badge bg-secondary">
                        <?php echo htmlspecialchars($train['display_id'] ?? $train['training_id']); ?>
                    </span>
                    </td>
                    <td><?php echo htmlspecialchars($train['main_category_name']); ?></td>
                    <td><?php echo htmlspecialchars($train['sub_category_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($train['training_name']); ?></td>
                    <td>
                        <?php
                        $training_date = htmlspecialchars(date('d.m.Y', strtotime($train['start_date'])));
                        if ($train['start_date'] != $train['end_date']) {
                            $training_date .= ' - ' . htmlspecialchars(date('d.m.Y', strtotime($train['end_date'])));
                        }
                        echo $training_date;
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($train['training_units']); ?></td>
                    <?php if ($ist_hr || $ist_trainingsmanager || $ist_ehs): ?>
                        <td>
                            <a href="edit_training.php?id=<?php echo $train['training_id']; ?>&employee_id=<?php echo $id; ?>"
                               class="btn btn-warning btn-sm">Bearbeiten</a>
                            <a href="#"
                               class="btn btn-danger btn-sm delete-training-btn"
                               data-training-id="<?php echo $train['training_id']; ?>"
                               data-training-display-id="<?php echo htmlspecialchars($train['display_id'] ?? $train['training_id']); ?>"
                               data-training-name="<?php echo htmlspecialchars($train['training_name']); ?>"
                               data-training-date="<?php echo $training_date; ?>"
                               data-delete-url="delete_training.php?id=<?php echo $train['training_id']; ?>&employee_id=<?php echo $id; ?>">
                                <i class="bi bi-trash"></i> Löschen
                            </a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($training)): ?>
                <tr>
                    <td colspan="<?php echo ($ist_hr || $ist_trainingsmanager || $ist_ehs) ? '7' : '6'; ?>"
                        class="text-center">
                        Keine Weiterbildungen vorhanden
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php if ($ist_hr || $ist_trainingsmanager || $ist_ehs): ?>
            <a href="add_training.php?employee_id=<?php echo $id; ?>" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Weiterbildung hinzufügen
            </a>
        <?php endif; ?>
    </div>
    <br>
    <?php if (!$ist_trainingsmanager && !$ist_ehs && !$ist_smstv): ?>
        <div class="additional-info">
            <h4>Letztes Mitarbeitergespräch am <?php echo date("d.m.Y", strtotime($review_row['date'])); ?></h4>
            <p><strong>Durchgeführt von:</strong> <?php echo htmlspecialchars($reviewer_name); ?></p>
            <div class="thin-divider"></div>
            <?php if ($review_row): ?>
                <div class="row">
                    <div class="col-md-5">
                        <p><strong>Rückblick:</strong> <?php echo htmlspecialchars($review_row['rueckblick']); ?></p>
                        <p><strong>Entwicklung:</strong> <?php echo htmlspecialchars($review_row['entwicklung']); ?></p>
                        <p><strong>Feedback:</strong> <?php echo htmlspecialchars($review_row['feedback']); ?></p>
                    </div>
                    <div class="col-md-3">
                        <p>
                            <strong>Brandschutzwart:</strong> <?php echo $review_row['brandschutzwart'] ? 'Ja' : 'Nein'; ?>
                        </p>
                        <p><strong>Sprinklerwart:</strong> <?php echo $review_row['sprinklerwart'] ? 'Ja' : 'Nein'; ?>
                        </p>
                        <p><strong>Ersthelfer:</strong> <?php echo $review_row['ersthelfer'] ? 'Ja' : 'Nein'; ?></p>
                        <p>
                            <strong>Sicherheitsvertrauensperson:</strong> <?php echo $review_row['svp'] ? 'Ja' : 'Nein'; ?>
                        </p>
                    </div>
                    <div class="col-md-4">
                        <p>
                            <strong>Trainertätigkeiten:</strong> <?php echo $review_row['trainertaetigkeiten'] ? 'Ja' : 'Nein'; ?>
                        </p>
                        <?php if ($review_row['trainertaetigkeiten_kommentar']): ?>
                            <p><strong>Kommentar zu
                                    Trainertätigkeiten:</strong> <?php echo htmlspecialchars($review_row['trainertaetigkeiten_kommentar']); ?>
                            </p>
                        <?php endif; ?>
                        <p><strong>Zufriedenheit:</strong> <?php echo htmlspecialchars($review_row['zufriedenheit']); ?>
                        </p>
                        <?php if ($review_row['zufriedenheit'] == 'Unzufrieden' && $review_row['unzufriedenheit_grund']): ?>
                            <p><strong>Gründe für
                                    Unzufriedenheit:</strong> <?php echo htmlspecialchars($review_row['unzufriedenheit_grund']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="thin-divider"></div>
                <div class="row">
                    <div class="col-md-4">
                        <h5>Anwenderkenntnisse:</h5>
                        <ul>
                            <?php foreach ($skills as $skill): ?>
                                <?php if ($skill['kategorie'] == 'Anwenderkenntnisse'): ?>
                                    <li><?php echo htmlspecialchars($skill['name']); ?>
                                        : <?php echo htmlspecialchars($skill['rating']); ?>/9
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h5>Positionsspezifische Kompetenzen:</h5>
                        <ul>
                            <?php foreach ($skills as $skill): ?>
                                <?php if ($skill['kategorie'] == 'Positionsspezifische Kompetenzen'): ?>
                                    <li><?php echo htmlspecialchars($skill['name']); ?>
                                        : <?php echo htmlspecialchars($skill['rating']); ?>/9
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h5>Führungskompetenzen bzw. Persönliche Kompetenzen:</h5>
                        <ul>
                            <?php foreach ($skills as $skill): ?>
                                <?php if ($skill['kategorie'] == 'Führungskompetenzen' || $skill['kategorie'] == 'Persönliche Kompetenzen'): ?>
                                    <li><?php echo htmlspecialchars($skill['name']); ?>
                                        : <?php echo htmlspecialchars($skill['rating']); ?>/9
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php else: ?>
                <p>Keine Bewertungen vorhanden.</p>
            <?php endif; ?>
            <?php if ($ist_hr): ?>
                <div class="thin-divider"></div>
                <div>
                    <a href="history.php?employee_id=<?php echo htmlspecialchars($id); ?>" class="btn btn-info">Mitarbeitergespräch-History</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Löschbestätigungs-Modal für Weiterbildungen -->
<div class="modal fade" id="confirmDeleteTrainingModal" tabindex="-1" aria-labelledby="confirmDeleteTrainingModalLabel"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmDeleteTrainingModalLabel">Weiterbildung löschen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><strong>Möchten Sie diese Weiterbildung wirklich entfernen?</strong></p>

                <div class="training-info mb-3">
                    <p class="mb-1"><strong>ID:</strong> <span id="training-id-display"></span></p>
                    <p class="mb-1"><strong>Name:</strong> <span id="training-name-display"></span></p>
                    <p class="mb-0"><strong>Zeitraum:</strong> <span id="training-date-display"></span></p>
                </div>

                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <span>
                        Hinweis: Wenn dieser Mitarbeiter der einzige Teilnehmer an dieser Weiterbildung ist,
                        wird die Weiterbildung vollständig aus dem System gelöscht.
                    </span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <a href="#" id="confirm-delete-training-link" class="btn btn-danger">
                    <i class="bi bi-trash"></i> Weiterbildung entfernen
                </a>
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
            <div class="modal-body" id="resultMessage">
                <?php if ($has_result_message): ?>
                    <div class="alert alert-<?php echo $result_type === 'success' ? 'success' : 'danger'; ?>">
                        <?php echo $result_message; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Lokales Bootstrap 5 JavaScript Bundle -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // -----------------------------------------------------------------
        // Dynamische Aktualisierung der Positionsauswahl anhand der Gruppe
        // -----------------------------------------------------------------
        const groupSelect = document.getElementById('gruppe');
        const areaSelect = document.getElementById('position');
        const teamSelect = document.getElementById('crew');
        const initialArea = "<?php echo htmlspecialchars($row['position']); ?>";

        function updateAreaOptions() {
            const selectedGroup = groupSelect.value;
            if (selectedGroup === "Schichtarbeit") {
                teamSelect.disabled = false;
                const placeholderOption = Array.from(teamSelect.options).find(option => option.value === "---");
                if (placeholderOption) {
                    placeholderOption.disabled = true;
                }
            } else {
                teamSelect.disabled = true;
                teamSelect.value = "---";
            }
            const encodedGroup = encodeURIComponent(selectedGroup);
            fetch(`get_positionen_by_gruppe.php?gruppe=${encodedGroup}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Fehler beim Abrufen der Positionen');
                    }
                    return response.json();
                })
                .then(data => {
                    areaSelect.innerHTML = '';
                    data.forEach(area => {
                        const option = document.createElement('option');
                        option.value = area.name;
                        option.textContent = area.name;
                        const decodedInitialArea = initialArea.replace(/&amp;/g, '&');
                        if (area.name === decodedInitialArea) {
                            option.selected = true;
                        }
                        areaSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Fehler:', error);
                    alert('Es ist ein Fehler beim Abrufen der Positionen aufgetreten.');
                });
        }

        if (groupSelect) {
            groupSelect.addEventListener('change', updateAreaOptions);
            updateAreaOptions();
        }

        // -----------------------------------------------------------------
        // Lohnschema-bezogene Felder: Anzeigen/Verstecken
        // -----------------------------------------------------------------
        const lohnschemaSelect = document.getElementById('lohnschema');
        const productionFields = document.getElementById('production-fields');
        const techFields = document.getElementById('tech-fields');
        const generalZulage = document.getElementById('general-zulage');
        const techBonusCheckboxes = [
            document.getElementById('tk_qualifikationsbonus_1'),
            document.getElementById('tk_qualifikationsbonus_2'),
            document.getElementById('tk_qualifikationsbonus_3'),
            document.getElementById('tk_qualifikationsbonus_4')
        ].filter(el => el !== null); // Filter out null elements

        const pr_lehrabschluss = document.getElementById('pr_lehrabschluss');
        const pr_anfangslohn = document.getElementById('pr_anfangslohn');
        const pr_grundlohn = document.getElementById('pr_grundlohn');
        const pr_qualifikationsbonus = document.getElementById('pr_qualifikationsbonus');
        const pr_expertenbonus = document.getElementById('pr_expertenbonus');
        const isTrainingsmanager = <?php echo json_encode($ist_trainingsmanager); ?>;
        const isEhsmanager = <?php echo json_encode($ist_ehs); ?>;

        function toggleManagerFieldsNoSalary() {
            if (isTrainingsmanager || isEhsmanager) {
                if (productionFields) productionFields.style.display = 'none';
                if (techFields) techFields.style.display = 'none';
                if (generalZulage) generalZulage.style.display = 'none';
            }
        }

        function toggleFields() {
            if (!lohnschemaSelect) return;

            const selectedLohnschema = lohnschemaSelect.value;
            toggleManagerFieldsNoSalary();
            if (!isTrainingsmanager && !isEhsmanager) {
                if (productionFields) productionFields.style.display = (selectedLohnschema === 'Produktion') ? 'block' : 'none';
                if (techFields) techFields.style.display = (selectedLohnschema === 'Technik') ? 'block' : 'none';
                if (generalZulage) generalZulage.style.display = (selectedLohnschema === 'Produktion' || selectedLohnschema === 'Technik') ? 'block' : 'none';
            }
        }

        function updateTechBonusAvailability() {
            if (!techBonusCheckboxes.length) return;

            techBonusCheckboxes.forEach((checkbox, index) => {
                if (!checkbox) return;
                checkbox.disabled = index > 0 && !techBonusCheckboxes[index - 1].checked;
            });
        }

        function updateProductionAvailability() {
            if (!pr_lehrabschluss || !pr_anfangslohn || !pr_grundlohn || !pr_qualifikationsbonus || !pr_expertenbonus) return;

            pr_lehrabschluss.disabled = false;
            pr_anfangslohn.disabled = false;
            pr_grundlohn.disabled = false;
            pr_qualifikationsbonus.disabled = false;
            pr_expertenbonus.disabled = !pr_qualifikationsbonus.checked;
        }

        // Setup event listeners for checkboxes and selects
        const setupProductionEvents = () => {
            const checkboxes = [
                ...techBonusCheckboxes.filter(el => el !== null),
                pr_anfangslohn,
                pr_qualifikationsbonus
            ].filter(el => el !== null);

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    updateTechBonusAvailability();
                    updateProductionAvailability();
                });
            });

            if (lohnschemaSelect) {
                lohnschemaSelect.addEventListener('change', () => {
                    toggleFields();
                    updateTechBonusAvailability();
                    updateProductionAvailability();
                });
            }
        };

        function disableFieldsForNonHR() {
            <?php if (!$ist_hr): ?>
            if (lohnschemaSelect) lohnschemaSelect.disabled = true;
            if (pr_lehrabschluss) pr_lehrabschluss.disabled = true;
            if (pr_anfangslohn) pr_anfangslohn.disabled = true;
            if (pr_grundlohn) pr_grundlohn.disabled = true;
            if (pr_qualifikationsbonus) pr_qualifikationsbonus.disabled = true;
            if (pr_expertenbonus) pr_expertenbonus.disabled = true;
            if (teamSelect) teamSelect.disabled = true;
            techBonusCheckboxes.forEach(checkbox => {
                if (checkbox) checkbox.disabled = true;
            });
            const ln_zulage = document.getElementById('ln_zulage');
            if (ln_zulage) ln_zulage.disabled = true;
            <?php endif; ?>
        }

        toggleFields();
        updateTechBonusAvailability();
        updateProductionAvailability();
        setupProductionEvents();
        disableFieldsForNonHR();

        // -----------------------------------------------------------------
        // AJAX-Formular-Submit für update_employee.php
        // -----------------------------------------------------------------
        const form = document.getElementById('employeeForm');
        const resultModal = new bootstrap.Modal(document.getElementById('resultModal'));

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                fetch('update_employee.php', {
                    method: 'POST',
                    body: new FormData(form)
                })
                    .then(response => response.text())
                    .then(data => {
                        document.getElementById('resultMessage').innerHTML = data;
                        resultModal.show();
                    })
                    .catch(error => {
                        document.getElementById('resultMessage').innerHTML = '<div class="alert alert-danger">Fehler beim Speichern.</div>';
                        resultModal.show();
                        console.error('Error:', error);
                    });
            });
        }

        // -----------------------------------------------------------------
        // Training-Löschfunktion mit Bestätigungsmodal
        // -----------------------------------------------------------------
        const deleteTrainingButtons = document.querySelectorAll('.delete-training-btn');
        const confirmDeleteTrainingModal = document.getElementById('confirmDeleteTrainingModal');

        if (deleteTrainingButtons.length > 0 && confirmDeleteTrainingModal) {
            const deleteModal = new bootstrap.Modal(confirmDeleteTrainingModal);

            deleteTrainingButtons.forEach(button => {
                button.addEventListener('click', function (event) {
                    event.preventDefault();

                    // Daten aus data-* Attributen extrahieren
                    const trainingId = this.getAttribute('data-training-id');
                    const trainingDisplayId = this.getAttribute('data-training-display-id');
                    const trainingName = this.getAttribute('data-training-name');
                    const trainingDate = this.getAttribute('data-training-date');
                    const deleteUrl = this.getAttribute('data-delete-url');

                    // Modal-Inhalte aktualisieren
                    document.getElementById('training-id-display').textContent = trainingDisplayId || trainingId;
                    document.getElementById('training-name-display').textContent = trainingName;
                    document.getElementById('training-date-display').textContent = trainingDate;

                    // Delete-Link im Modal aktualisieren
                    document.getElementById('confirm-delete-training-link').setAttribute('href', deleteUrl);

                    // Modal anzeigen
                    deleteModal.show();
                });
            });
        }

        // Show result modal if there's a message from a previous action
        <?php if ($has_result_message): ?>
        if (resultModal) {
            resultModal.show();
        }
        <?php endif; ?>
    });
</script>
</body>
</html>