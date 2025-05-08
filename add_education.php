<?php
/**
 * add_education.php
 *
 * Diese Seite ermöglicht es, einer bestimmten Person Ausbildungsdaten hinzuzufügen.
 * Es wird zunächst geprüft, ob der Benutzer eingeloggt und berechtigt ist (Admin oder HR).
 * Anschließend wird die Mitarbeiter-ID (employee_id) aus der URL ausgelesen.
 * Bei einem POST-Request werden die übermittelten Ausbildungsdaten in die Tabelle
 * employee_education eingefügt.
 */

include 'db.php';            // Datenbankverbindung
include 'access_control.php'; // Zugriffskontrolle und Session-Handling

global $conn;

// Sicherstellen, dass der Benutzer eingeloggt ist und die nötigen Rechte besitzt
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// Mitarbeiter-ID (employee_id) aus der URL lesen
if (!isset($_GET['employee_id'])) {
    echo 'Keine Mitarbeiter-ID angegeben';
    exit;
}
$employee_id = $_GET['employee_id'];

// Verarbeiten eines POST-Requests (Formularübermittlung)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Aus den POST-Daten die Ausbildungsdaten auslesen
    $education_type  = $_POST['education_type'];
    $education_field = $_POST['education_field'];

    // SQL-Statement vorbereiten: Ausbildungsdaten in employee_education einfügen
    $stmt = $conn->prepare("INSERT INTO employee_education (employee_id, education_type, education_field) VALUES (?, ?, ?)");
    if (!$stmt) {
        die("Fehler bei der Vorbereitung des Statements: " . $conn->error);
    }
    $stmt->bind_param("iss", $employee_id, $education_type, $education_field);

    // Statement ausführen und bei Erfolg zur Detailseite des Mitarbeiters weiterleiten
    if ($stmt->execute()) {
        // Umleitung zur Mitarbeiter-Detailseite (hier wird nun employee_id als Parameter genutzt)
        header("Location: employee_details.php?employee_id=" . $employee_id);
        exit;
    } else {
        echo 'Fehler beim Hinzufügen der Ausbildung';
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ausbildung hinzufügen</title>
    <!-- Einbindung des Navigationsmenüs und der Bootstrap-Styles -->
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container">
    <h1 class="text-center mb-3">Ausbildung hinzufügen</h1>
    <hr class="mb-4">

    <!-- Formular zum Hinzufügen der Ausbildungsdaten -->
    <form action="" method="POST">
        <!-- Ausbildungsart und Ausbildungsfeld in einer Zeile -->
        <div class="row mb-3">
            <div class="col-md-6">
                <label for="education_type">Art der Ausbildung:</label>
                <select class="form-control" id="education_type" name="education_type" required>
                    <option value="Lehre">Lehre</option>
                    <option value="Meister">Meister</option>
                    <option value="Fachschule ohne Matura">Fachschule ohne Matura</option>
                    <option value="Schulausbildung mit Matura">Schulausbildung mit Matura</option>
                    <option value="andere höhere Schulausbildung">andere höhere Schulausbildung</option>
                    <option value="Bachelor">Bachelor</option>
                    <option value="Master">Master</option>
                    <option value="Magister">Magister</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="education_field">Feld der Ausbildung:</label>
                <input type="text" class="form-control" id="education_field" name="education_field" required>
            </div>
        </div>

        <!-- Formular-Buttons -->
        <div class="mb-3">
            <button type="submit" class="btn btn-primary">Hinzufügen</button>
            <a href="employee_details.php?employee_id=<?php echo htmlspecialchars($employee_id); ?>" class="btn btn-secondary">Zurück</a>
        </div>
    </form>
</div>

<!-- Lokales Bootstrap-JavaScript einbinden -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
