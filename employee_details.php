<?php
/**
 * employee_details.php
 *
 * Diese Seite zeigt detaillierte Informationen zu einem Mitarbeiter an – einschließlich
 * letzter Mitarbeitergespräche, Bewertungen, Ausbildung, Weiterbildungen und weiteren Daten.
 * Zugriffskontrolle: Nur Benutzer mit entsprechenden Rechten dürfen die Daten einsehen und bearbeiten.
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

// Benutzerberechtigungen
$ist_admin = ist_admin();
$ist_sm = ist_sm();
$ist_smstv = ist_smstv();
$ist_hr = ist_hr();
$ist_bereichsleiter = ist_bereichsleiter();
$ist_trainingsmanager = ist_trainingsmanager();
$ist_ehs = ist_ehs();
$ist_leiter = ist_leiter();
$ist_empfang = ist_empfang();

// Mitarbeiterinformationen abrufen
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

// Für Formularfelder, die für bestimmte Benutzerrollen gesperrt sein sollen
$disabled = !$ist_hr ? 'disabled' : '';
$disabled_sm = (!$ist_hr && !$ist_sm) ? 'disabled' : '';
$empfang_disabled = (!$ist_hr && !$ist_empfang) ? 'disabled' : '';

// Wenn der Benutzer nicht im HR oder Empfang ist, sollten sie die Daten nur lesen
$view_only = (!$ist_hr && !$ist_sm && !$ist_smstv && !$ist_leiter && !$ist_empfang);

// Nur wenn Benutzer nicht Empfangsmitarbeiter, Trainings Manager oder EHS Manager ist
$can_view_details = !$ist_empfang && !$ist_trainingsmanager && !$ist_ehs;

// Letzte Bewertung und zugehörige Skills des Mitarbeiters abrufen
if ($can_view_details) {
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
}

// Ausbildungsdaten abrufen, wenn nötig
if (!$ist_empfang) {
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
}

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

// JavaScript-Datenattribute für die Zugriffskontrolle
$js_data_attributes = "data-is-hr=\"" . ($ist_hr ? 'true' : 'false') . "\" " .
    "data-is-sm=\"" . ($ist_sm ? 'true' : 'false') . "\" " .
    "data-is-smstv=\"" . ($ist_smstv ? 'true' : 'false') . "\" " .
    "data-is-trainingsmanager=\"" . ($ist_trainingsmanager ? 'true' : 'false') . "\" " .
    "data-is-ehsmanager=\"" . ($ist_ehs ? 'true' : 'false') . "\" " .
    "data-is-empfang=\"" . ($ist_empfang ? 'true' : 'false') . "\" " .
    "data-has-result-message=\"" . ($has_result_message ? 'true' : 'false') . "\"";
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitarbeiterdetails</title>
    <!-- Cache-Steuerung, um bfcache-Probleme zu vermeiden -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate"/>
    <meta http-equiv="Pragma" content="no-cache"/>
    <meta http-equiv="Expires" content="0"/>
    <!-- Bootstrap CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="employee-styles.css" rel="stylesheet">
</head>
<body <?php echo $js_data_attributes; ?>>
<?php include 'navbar.php'; ?>

<div class="content container">

    <div class="page-header d-flex justify-content-between align-items-center">
        <h1><i class="bi bi-person-badge me-2"></i>Mitarbeiterdetails</h1>
        <a href="<?php echo htmlspecialchars($previous_page); ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurück
        </a>
    </div>

    <form id="employeeForm" action="update_employee.php" method="POST">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">

        <!-- Hauptinformationen -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Persönliche Informationen</h5>
                    <?php if ($ist_hr): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal"
                                data-bs-target="#archiveModal">
                            <i class="bi bi-archive"></i> Mitarbeiter archivieren
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Linke Spalte - Mitarbeiterdaten -->
                    <div class="col-md-9">
                        <!-- Erste Zeile - Allgemeine Informationen -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Name:</label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="<?php echo htmlspecialchars($row['name']); ?>" disabled>
                            </div>
                            <div class="col-md-3">
                                <label for="gender" class="form-label">Geschlecht:</label>
                                <select class="form-select" name="gender" id="gender" <?php echo $disabled; ?>>
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
                            <div class="col-md-3">
                                <label for="lohnschema" class="form-label">Lohnschema:</label>
                                <select class="form-select" id="lohnschema" name="lohnschema" <?php echo $disabled; ?>>
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

                        <!-- Zweite Zeile - Gruppe, Team und Position -->
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="gruppe" class="form-label">Gruppe:</label>
                                <select class="form-select" id="gruppe" name="gruppe" <?php echo $disabled; ?>>
                                    <?php foreach ($gruppen as $gruppe): ?>
                                        <option value="<?php echo htmlspecialchars($gruppe); ?>" <?php if ($row['gruppe'] == $gruppe) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($gruppe); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <div class="mb-3" id="crew-container">
                                    <label for="crew" class="form-label">Team:</label>
                                    <select class="form-select" id="crew"
                                            name="crew" <?php echo ($row['gruppe'] == 'Schichtarbeit' && !$ist_hr) ? 'disabled' : ''; ?>>
                                        <option value="---" <?php if ($row['crew'] == '---') echo 'selected'; ?>>---
                                        </option>
                                        <?php foreach ($teams as $team): ?>
                                            <option value="<?php echo htmlspecialchars($team); ?>" <?php if ($row['crew'] == $team) echo 'selected'; ?>>
                                                <?php echo htmlspecialchars($team); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="position" class="form-label">Position:</label>
                                <select class="form-select" id="position" name="position" <?php echo $disabled_sm; ?>>
                                    <?php foreach ($initial_areas as $area): ?>
                                        <option value="<?php echo htmlspecialchars($area['name']); ?>" <?php if (htmlspecialchars($row['position']) == htmlspecialchars($area['name'])) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($area['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Dritte Zeile - Datum-Felder -->
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="last_present" class="form-label">Zuletzt anwesend:</label>
                                <input type="date" class="form-control" id="last_present" name="last_present"
                                       value="<?php echo htmlspecialchars($row['last_present']); ?>" disabled>
                            </div>
                            <div class="col-md-4">
                                <label for="entry_date" class="form-label">Eintritt:</label>
                                <input type="date" class="form-control" id="entry_date" name="entry_date"
                                       value="<?php echo htmlspecialchars($row['entry_date']); ?>" <?php echo !$ist_hr ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-4">
                                <label for="first_entry_date" class="form-label">Ersteintritt:</label>
                                <input type="date" class="form-control" id="first_entry_date" name="first_entry_date"
                                       value="<?php echo htmlspecialchars($row['first_entry_date']); ?>" disabled>
                            </div>
                        </div>

                        <!-- Vierte Zeile - Kontakt-Informationen -->
                        <div class="row">
                            <div class="col-md-2">
                                <label for="social_security_number" class="form-label">SV-Nummer:</label>
                                <input type="text" class="form-control" id="social_security_number"
                                       name="social_security_number"
                                       value="<?php echo htmlspecialchars($row['social_security_number']); ?>" disabled>
                            </div>
                            <div class="col-md-3">
                                <label for="phone_number" class="form-label">Telefonnummer:</label>
                                <input type="text" class="form-control" id="phone_number" name="phone_number"
                                       value="<?php echo htmlspecialchars($row['phone_number']); ?>" <?php echo !$ist_hr ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-2">
                                <label for="badge_id" class="form-label">Ausweisnummer:</label>
                                <input type="text" class="form-control" id="badge_id" name="badge_id"
                                       value="<?php echo htmlspecialchars($row['badge_id']); ?>" <?php echo $empfang_disabled; ?>>
                            </div>
                            <div class="col-md-2">
                                <label for="birthdate" class="form-label">Geburtsdatum:</label>
                                <input type="date" class="form-control" id="birthdate" name="birthdate"
                                       value="<?php echo htmlspecialchars($row['birthdate']); ?>" disabled>
                            </div>
                            <!-- Status-Feld -->
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status:</label>
                                    <?php if ($row['anwesend']): ?>
                                        <div class="input-group">
                                            <input type="text" class="form-control" value="Anwesend" disabled>
                                            <span class="input-group-text bg-success text-white">
                                                <i class="bi bi-check-circle"></i>
                                            </span>
                                        </div>
                                        <small class="text-muted">Kann nicht geändert werden</small>
                                    <?php else: ?>
                                        <?php if ($ist_hr || $ist_sm || $ist_smstv || $ist_leiter): ?>
                                            <select class="form-select" id="status" name="status">
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
                                        <?php else: ?>
                                            <input type="text" class="form-control" value="<?php
                                            $status_options = [
                                                0 => '-',
                                                1 => 'Krank',
                                                2 => 'Urlaub',
                                                3 => 'Schulung'
                                            ];
                                            echo $status_options[$row['status']] ?? '-';
                                            ?>" disabled>
                                            <input type="hidden" name="status" value="<?php echo $row['status']; ?>">
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Rechte Spalte - Bild -->
                    <div class="col-md-3">
                        <div class="employee-image-container">
                            <?php if (!empty($row['bild']) && $row['bild'] !== 'kein-bild.jpg'): ?>
                                <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($row['bild']); ?>"
                                     alt="Mitarbeiterfoto" class="employee-image" id="employee-photo">
                                <?php if ($ist_hr || ist_empfang()): ?>
                                    <div class="mt-2 text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal" data-bs-target="#photoModal">
                                            <i class="bi bi-camera"></i> Foto ändern
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="no-image">
                                    <div class="text-center">
                                        <i class="bi bi-person-fill display-4"></i>
                                        <p class="mb-0">Kein Bild vorhanden</p>
                                        <?php if ($ist_hr || ist_empfang()): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary mt-2"
                                                    data-bs-toggle="modal" data-bs-target="#photoModal">
                                                <i class="bi bi-camera"></i> Foto hinzufügen
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- HR-spezifische E-Mail-Felder -->
                <?php if ($ist_hr): ?>
                    <div class="card mt-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Kontaktinformationen</h6>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-md-2">
                                    <label for="email_private" class="form-label">Mail (privat):</label>
                                </div>
                                <div class="col-md-7">
                                    <input type="email" class="form-control" id="email_private" name="email_private"
                                           value="<?php echo htmlspecialchars($row['email_private']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-primary w-100"
                                            onclick="window.location.href='mailto:<?php echo htmlspecialchars($row['email_private']); ?>'">
                                        <i class="bi bi-envelope"></i> E-Mail senden
                                    </button>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-2">
                                    <label for="email_business" class="form-label">Mail (geschäftlich):</label>
                                </div>
                                <div class="col-md-7">
                                    <input type="email" class="form-control" id="email_business" name="email_business"
                                           value="<?php echo htmlspecialchars($row['email_business']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-primary w-100"
                                            onclick="window.location.href='mailto:<?php echo htmlspecialchars($row['email_business']); ?>'">
                                        <i class="bi bi-envelope"></i> E-Mail senden
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-end">
                <?php if ($ist_hr || $ist_sm || $ist_smstv || $ist_leiter): ?>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save"></i> Änderungen speichern
                    </button>
                <?php elseif ($ist_empfang): ?>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save"></i> Ausweisnummer speichern
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if (!$ist_empfang): ?>
        <!-- Zusätzliche Informationen - Checkbox-Sektion -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">Erweiterte Informationen</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Sonstiges:</label>
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
                                <input class="form-check-input" type="checkbox" name="brandschutzwart"
                                       id="brandschutzwart"
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
                            <label class="form-label fw-bold">Lohnschema - Produktion:</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pr_lehrabschluss"
                                       name="pr_lehrabschluss"
                                       value="1" <?php if ($row['pr_lehrabschluss']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                <label class="form-check-label" for="pr_lehrabschluss">Einstufung mit
                                    Lehrabschluss</label>
                            </div>
                            <div class="border-start border-3 border-primary ps-3 my-2">
                                <?php if ($ist_hr): ?>
                                    <p class="small text-muted mb-1">Lohntyp (bitte eine Option wählen):</p>
                                <?php endif; ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" id="pr_anfangslohn" name="lohn_type"
                                           value="pr_anfangslohn" <?php if ($row['pr_anfangslohn']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                    <label class="form-check-label" for="pr_anfangslohn">Anfangslohn</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" id="pr_grundlohn" name="lohn_type"
                                           value="pr_grundlohn" <?php if ($row['pr_grundlohn']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                    <label class="form-check-label" for="pr_grundlohn">Grundlohn</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pr_qualifikationsbonus"
                                       name="pr_qualifikationsbonus"
                                       value="1" <?php if ($row['pr_qualifikationsbonus']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                <label class="form-check-label" for="pr_qualifikationsbonus">Qualifikationsbonus</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pr_expertenbonus"
                                       name="pr_expertenbonus"
                                       value="1" <?php if ($row['pr_expertenbonus']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                <label class="form-check-label" for="pr_expertenbonus">Expertenbonus</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div id="tech-fields" class="mb-3" style="display: none;">
                            <label class="form-label fw-bold">Lohnschema - Technik:</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_1"
                                       name="tk_qualifikationsbonus_1"
                                       value="1" <?php if ($row['tk_qualifikationsbonus_1']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                <label class="form-check-label" for="tk_qualifikationsbonus_1">Qualifikationsbonus
                                    1</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_2"
                                       name="tk_qualifikationsbonus_2"
                                       value="1" <?php if ($row['tk_qualifikationsbonus_2']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                <label class="form-check-label" for="tk_qualifikationsbonus_2">Qualifikationsbonus
                                    2</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_3"
                                       name="tk_qualifikationsbonus_3"
                                       value="1" <?php if ($row['tk_qualifikationsbonus_3']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                <label class="form-check-label" for="tk_qualifikationsbonus_3">Qualifikationsbonus
                                    3</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="tk_qualifikationsbonus_4"
                                       name="tk_qualifikationsbonus_4"
                                       value="1" <?php if ($row['tk_qualifikationsbonus_4']) echo 'checked'; ?> <?php echo $disabled; ?>>
                                <label class="form-check-label" for="tk_qualifikationsbonus_4">Qualifikationsbonus
                                    4</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div id="general-zulage" class="mb-3" style="display: none;">
                            <label for="ln_zulage" class="form-label fw-bold">Zulage:</label>
                            <select class="form-select" id="ln_zulage" name="ln_zulage" <?php echo $disabled; ?>>
                                <option value="Keine Zulage" <?php if ($row['ln_zulage'] == 'Keine Zulage') echo 'selected'; ?>>
                                    Keine Zulage
                                </option>
                                <option value="5% Zulage" <?php if ($row['ln_zulage'] == '5% Zulage') echo 'selected'; ?>>
                                    5% Zulage
                                </option>
                                <option value="10% Zulage" <?php if ($row['ln_zulage'] == '10% Zulage') echo 'selected'; ?>>
                                    10% Zulage
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if (!empty($row['leasing']) && $row['leasing'] == 1): ?>
                    <div class="alert alert-danger text-center fw-bold mt-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Achtung! Leasing-Mitarbeiter - Keine Lohndaten hinterlegt!
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-end">
                <?php if ($ist_hr || $ist_sm || $ist_smstv || $ist_leiter): ?>
                    <button type="submit" form="employeeForm" class="btn btn-warning">
                        <i class="bi bi-save"></i> Änderungen speichern
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ausbildung -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Ausbildung</h5>
                <?php if ($ist_hr): ?>
                    <a href="add_education.php?employee_id=<?php echo $id; ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-plus-circle"></i> Ausbildung hinzufügen
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                        <tr>
                            <th>Art der Ausbildung</th>
                            <th>Ausbildung</th>
                            <?php if ($ist_hr): ?>
                                <th class="text-end">Aktionen</th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($education)): ?>
                            <tr>
                                <td colspan="<?php echo $ist_hr ? '3' : '2'; ?>" class="text-center">
                                    Keine Ausbildungsdaten vorhanden
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($education as $edu): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($edu['education_type']); ?></td>
                                    <td><?php echo htmlspecialchars($edu['education_field']); ?></td>
                                    <?php if ($ist_hr): ?>
                                        <td class="text-end">
                                            <a href="edit_education.php?id=<?php echo $edu['id']; ?>&employee_id=<?php echo $id; ?>"
                                               class="btn btn-warning btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_education.php?id=<?php echo $edu['id']; ?>&employee_id=<?php echo $id; ?>"
                                               class="btn btn-danger btn-sm">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Weiterbildung -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-book me-2"></i>Weiterbildung</h5>
                <?php if ($ist_hr || $ist_trainingsmanager || $ist_ehs): ?>
                    <a href="add_training.php?employee_id=<?php echo $id; ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-plus-circle"></i> Weiterbildung hinzufügen
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Hauptkategorie</th>
                            <th>Unterkategorie</th>
                            <th>Name der Weiterbildung</th>
                            <th>Zeitraum</th>
                            <th>Einheiten</th>
                            <?php if ($ist_hr || $ist_trainingsmanager || $ist_ehs): ?>
                                <th class="text-end">Aktionen</th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($training)): ?>
                            <tr>
                                <td colspan="<?php echo ($ist_hr || $ist_trainingsmanager || $ist_ehs) ? '7' : '6'; ?>"
                                    class="text-center">
                                    Keine Weiterbildungen vorhanden
                                </td>
                            </tr>
                        <?php else: ?>
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
                                        <td class="text-end">
                                            <a href="edit_training.php?id=<?php echo $train['training_id']; ?>&employee_id=<?php echo $id; ?>"
                                               class="btn btn-warning btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="#"
                                               class="btn btn-danger btn-sm delete-training-btn"
                                               data-training-id="<?php echo $train['training_id']; ?>"
                                               data-training-display-id="<?php echo htmlspecialchars($train['display_id'] ?? $train['training_id']); ?>"
                                               data-training-name="<?php echo htmlspecialchars($train['training_name']); ?>"
                                               data-training-date="<?php echo $training_date; ?>"
                                               data-delete-url="delete_training.php?id=<?php echo $train['training_id']; ?>&employee_id=<?php echo $id; ?>">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Mitarbeitergespräch -->
        <?php if (!$ist_trainingsmanager && !$ist_ehs && !$ist_smstv): ?>
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-chat-text me-2"></i>
                        <?php if (isset($review_row) && isset($review_row['date'])): ?>
                            Letztes Mitarbeitergespräch am <?php echo date("d.m.Y", strtotime($review_row['date'])); ?>
                        <?php else: ?>
                            Mitarbeitergespräch
                        <?php endif; ?>
                    </h5>
                    <?php if ($ist_hr): ?>
                        <a href="history.php?employee_id=<?php echo htmlspecialchars($id); ?>"
                           class="btn btn-info btn-sm">
                            <i class="bi bi-clock-history"></i> Gespräch-History
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (isset($review_row)): ?>
                        <p class="mb-3">
                            <span class="badge bg-info">Durchgeführt von: <?php echo htmlspecialchars($reviewer_name); ?></span>
                        </p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header bg-light">Rückmeldungen</div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <h6>Rückblick:</h6>
                                            <p><?php echo htmlspecialchars($review_row['rueckblick']); ?></p>
                                        </div>
                                        <div class="mb-3">
                                            <h6>Entwicklung:</h6>
                                            <p><?php echo htmlspecialchars($review_row['entwicklung']); ?></p>
                                        </div>
                                        <div>
                                            <h6>Feedback:</h6>
                                            <p><?php echo htmlspecialchars($review_row['feedback']); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header bg-light">Status und Zufriedenheit</div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <p><strong>Brandschutzwart:</strong>
                                                    <span class="badge <?php echo $review_row['brandschutzwart'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $review_row['brandschutzwart'] ? 'Ja' : 'Nein'; ?>
                                                </span>
                                                </p>
                                                <p><strong>Sprinklerwart:</strong>
                                                    <span class="badge <?php echo $review_row['sprinklerwart'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $review_row['sprinklerwart'] ? 'Ja' : 'Nein'; ?>
                                                </span>
                                                </p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Ersthelfer:</strong>
                                                    <span class="badge <?php echo $review_row['ersthelfer'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $review_row['ersthelfer'] ? 'Ja' : 'Nein'; ?>
                                                </span>
                                                </p>
                                                <p><strong>Sicherheitsvertrauensperson:</strong>
                                                    <span class="badge <?php echo $review_row['svp'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $review_row['svp'] ? 'Ja' : 'Nein'; ?>
                                                </span>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <p><strong>Trainertätigkeiten:</strong>
                                                <span class="badge <?php echo $review_row['trainertaetigkeiten'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $review_row['trainertaetigkeiten'] ? 'Ja' : 'Nein'; ?>
                                            </span>
                                            </p>
                                            <?php if ($review_row['trainertaetigkeiten_kommentar']): ?>
                                                <div class="alert alert-info p-2">
                                                    <small><strong>Kommentar zu Trainertätigkeiten:</strong></small><br>
                                                    <?php echo htmlspecialchars($review_row['trainertaetigkeiten_kommentar']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <p><strong>Zufriedenheit:</strong>
                                                <span class="badge <?php echo $review_row['zufriedenheit'] == 'Zufrieden' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                <?php echo htmlspecialchars($review_row['zufriedenheit']); ?>
                                            </span>
                                            </p>
                                            <?php if ($review_row['zufriedenheit'] == 'Unzufrieden' && $review_row['unzufriedenheit_grund']): ?>
                                                <div class="alert alert-warning p-2">
                                                    <small><strong>Gründe für Unzufriedenheit:</strong></small><br>
                                                    <?php echo htmlspecialchars($review_row['unzufriedenheit_grund']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-light">Anwenderkenntnisse</div>
                                    <div class="card-body">
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($skills as $skill): ?>
                                                <?php if ($skill['kategorie'] == 'Anwenderkenntnisse'): ?>
                                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                                        <?php echo htmlspecialchars($skill['name']); ?>
                                                        <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-light">Positionsspezifische Kompetenzen</div>
                                    <div class="card-body">
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($skills as $skill): ?>
                                                <?php if ($skill['kategorie'] == 'Positionsspezifische Kompetenzen'): ?>
                                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                                        <?php echo htmlspecialchars($skill['name']); ?>
                                                        <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-light">Führungs- und Persönliche Kompetenzen</div>
                                    <div class="card-body">
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($skills as $skill): ?>
                                                <?php if ($skill['kategorie'] == 'Führungskompetenzen' || $skill['kategorie'] == 'Persönliche Kompetenzen'): ?>
                                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                                        <?php echo htmlspecialchars($skill['name']); ?>
                                                        <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Keine Bewertungen vorhanden.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Löschbestätigungs-Modal für Weiterbildungen -->
<div class="modal fade" id="confirmDeleteTrainingModal" tabindex="-1" aria-labelledby="confirmDeleteTrainingModalLabel"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="confirmDeleteTrainingModalLabel">
                    <i class="bi bi-exclamation-triangle me-2"></i>Weiterbildung löschen
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><strong>Möchten Sie diese Weiterbildung wirklich entfernen?</strong></p>
                <div class="card mb-3">
                    <div class="card-body">
                        <p class="mb-1"><strong>ID:</strong> <span id="training-id-display"></span></p>
                        <p class="mb-1"><strong>Name:</strong> <span id="training-name-display"></span></p>
                        <p class="mb-0"><strong>Zeitraum:</strong> <span id="training-date-display"></span></p>
                    </div>
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
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Abbrechen
                </button>
                <a href="#" id="confirm-delete-training-link" class="btn btn-danger">
                    <i class="bi bi-trash"></i> Weiterbildung entfernen
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modales Fenster für Erfolg oder Fehler -->
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

<!-- Photo Upload Modal -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="photoModalLabel">Mitarbeiterfoto ändern</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="photoUploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($id); ?>">

                    <div class="text-center mb-3" id="photoPreviewContainer">
                        <?php if (!empty($row['bild']) && $row['bild'] !== 'kein-bild.jpg'): ?>
                            <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($row['bild']); ?>"
                                 alt="Mitarbeiterfoto" class="img-thumbnail" id="photoPreview"
                                 style="max-height: 200px;">
                        <?php else: ?>
                            <div class="border border-2 rounded p-4 d-inline-block" id="photoPreviewPlaceholder">
                                <i class="bi bi-person-fill" style="font-size: 3rem; color: #dee2e6;"></i>
                                <p class="mb-0 text-muted">Kein Bild</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="employee_photo" class="form-label">Neues Foto auswählen:</label>
                        <input type="file" class="form-control" id="employee_photo" name="employee_photo"
                               accept="image/jpeg, image/png, image/gif">
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="remove_photo" name="remove_photo" value="1">
                        <label class="form-check-label" for="remove_photo">
                            Foto entfernen (Standardbild verwenden)
                        </label>
                    </div>
                </form>
                <div class="alert" id="photoUploadMessage" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-primary" id="savePhotoBtn">
                    <i class="bi bi-save"></i> Speichern
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Archive Employee Modal -->
<?php if ($ist_hr): ?>
    <div class="modal fade" id="archiveModal" tabindex="-1" aria-labelledby="archiveModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="archiveModalLabel">
                        <i class="bi bi-archive me-2"></i>Mitarbeiter archivieren
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>
                <form action="archive_employee.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($id); ?>">

                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Achtung!</strong> Sie sind dabei, <?php echo htmlspecialchars($row['name']); ?> zu
                            archivieren.
                            <p class="mb-0 mt-2">
                                Dies sollte nur für Mitarbeiter durchgeführt werden, die das Unternehmen verlassen
                                haben.
                                Archivierte Mitarbeiter werden nicht gelöscht, aber sie werden aus den aktiven Listen
                                entfernt.
                            </p>
                        </div>

                        <div class="mb-3">
                            <label for="leave_date" class="form-label">Austrittsdatum:</label>
                            <input type="date" class="form-control" id="leave_date" name="leave_date"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="leave_reason" class="form-label">Grund für Austritt (optional):</label>
                            <select class="form-select" id="leave_reason" name="leave_reason">
                                <option value="Kündigung Arbeitnehmer">Kündigung Arbeitnehmer</option>
                                <option value="Kündigung Arbeitgeber">Kündigung Arbeitgeber</option>
                                <option value="Einvernehmlich">Einvernehmlich</option>
                                <option value="Pension">Pension</option>
                                <option value="Befristetes Arbeitsverhältnis">Befristetes Arbeitsverhältnis</option>
                                <option value="Sonstiges">Sonstiges</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-archive"></i> Mitarbeiter archivieren
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- JavaScript -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="employee-scripts.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Photo preview functionality
        const photoInput = document.getElementById('employee_photo');
        const photoPreview = document.getElementById('photoPreview');
        const photoPreviewPlaceholder = document.getElementById('photoPreviewPlaceholder');
        const removePhotoCheckbox = document.getElementById('remove_photo');
        const photoPreviewContainer = document.getElementById('photoPreviewContainer');
        const photoUploadMessage = document.getElementById('photoUploadMessage');

        if (photoInput) {
            photoInput.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();

                    reader.onload = function (e) {
                        // Create or show image preview
                        if (!photoPreview) {
                            // Create image if it doesn't exist
                            const newImage = document.createElement('img');
                            newImage.id = 'photoPreview';
                            newImage.alt = 'Mitarbeiterfoto';
                            newImage.className = 'img-thumbnail';
                            newImage.style.maxHeight = '200px';
                            photoPreviewContainer.innerHTML = '';
                            photoPreviewContainer.appendChild(newImage);
                            newImage.src = e.target.result;
                        } else {
                            // Update existing image
                            photoPreview.src = e.target.result;
                            photoPreview.style.display = 'inline-block';
                        }

                        // Hide placeholder if it exists
                        if (photoPreviewPlaceholder) {
                            photoPreviewPlaceholder.style.display = 'none';
                        }

                        // Uncheck remove option if file is selected
                        if (removePhotoCheckbox) {
                            removePhotoCheckbox.checked = false;
                        }
                    }

                    reader.readAsDataURL(this.files[0]);
                }
            });
        }

        // Handle remove photo checkbox
        if (removePhotoCheckbox) {
            removePhotoCheckbox.addEventListener('change', function () {
                if (this.checked) {
                    // Disable file input
                    if (photoInput) {
                        photoInput.value = '';
                        photoInput.disabled = true;
                    }

                    // Show placeholder, hide image
                    if (photoPreview) {
                        photoPreview.style.display = 'none';
                    }

                    if (photoPreviewPlaceholder) {
                        photoPreviewPlaceholder.style.display = 'inline-block';
                    } else {
                        // Create placeholder if it doesn't exist
                        const newPlaceholder = document.createElement('div');
                        newPlaceholder.id = 'photoPreviewPlaceholder';
                        newPlaceholder.className = 'border border-2 rounded p-4 d-inline-block';
                        newPlaceholder.innerHTML = `
                        <i class="bi bi-person-fill" style="font-size: 3rem; color: #dee2e6;"></i>
                        <p class="mb-0 text-muted">Kein Bild</p>
                    `;
                        photoPreviewContainer.innerHTML = '';
                        photoPreviewContainer.appendChild(newPlaceholder);
                    }
                } else {
                    // Enable file input
                    if (photoInput) {
                        photoInput.disabled = false;
                    }

                    // Restore previous state
                    if (photoPreview && photoPreview.src) {
                        photoPreview.style.display = 'inline-block';
                        if (photoPreviewPlaceholder) {
                            photoPreviewPlaceholder.style.display = 'none';
                        }
                    }
                }
            });
        }

        // Handle photo upload
        const savePhotoBtn = document.getElementById('savePhotoBtn');
        const photoUploadForm = document.getElementById('photoUploadForm');

        if (savePhotoBtn && photoUploadForm) {
            savePhotoBtn.addEventListener('click', function () {
                const formData = new FormData(photoUploadForm);

                // Show loading state
                savePhotoBtn.disabled = true;
                savePhotoBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Speichern...';

                fetch('update_employee_photo.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        // Reset button state
                        savePhotoBtn.disabled = false;
                        savePhotoBtn.innerHTML = '<i class="bi bi-save"></i> Speichern';

                        // Show message
                        photoUploadMessage.style.display = 'block';
                        photoUploadMessage.className = 'alert ' + (data.success ? 'alert-success' : 'alert-danger');
                        photoUploadMessage.textContent = data.message;

                        // Update page image if successful
                        if (data.success) {
                            const mainPhoto = document.getElementById('employee-photo');
                            if (mainPhoto && data.new_photo) {
                                mainPhoto.src = '../mitarbeiter-anzeige/fotos/' + data.new_photo;
                            }

                            // Auto close modal after delay
                            setTimeout(() => {
                                const modal = bootstrap.Modal.getInstance(document.getElementById('photoModal'));
                                if (modal) {
                                    modal.hide();
                                    // Reload page for complete refresh
                                    window.location.reload();
                                }
                            }, 1500);
                        }
                    })
                    .catch(error => {
                        // Reset button state
                        savePhotoBtn.disabled = false;
                        savePhotoBtn.innerHTML = '<i class="bi bi-save"></i> Speichern';

                        // Show error message
                        photoUploadMessage.style.display = 'block';
                        photoUploadMessage.className = 'alert alert-danger';
                        photoUploadMessage.textContent = 'Fehler beim Upload: ' + error.message;
                        console.error('Error:', error);
                    });
            });
        }
    });
</script>
</body>
</html>