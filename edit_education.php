<?php
/**
 * edit_education.php
 *
 * Ermöglicht das Bearbeiten eines bestehenden Bildungseintrags (employee_education).
 * Dabei wird der Eintrag anhand seiner id (in employee_education) identifiziert.
 * Der zugehörige Mitarbeiter wird über employee_id übergeben,
 * um nach erfolgreichem Update wieder zu dessen Detailseite weiterzuleiten.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Wenn das Formular abgeschickt wurde, Eintrag aktualisieren
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Primärschlüssel in employee_education
    $id = $_POST['id'];
    // Mitarbeiter-ID (employee_id) für die Rückleitung auf die Detailseite
    $employee_id = $_POST['employee_id'];

    $education_type = $_POST['education_type'];
    $education_field = $_POST['education_field'];

    $stmt = $conn->prepare("UPDATE employee_education SET education_type = ?, education_field = ? WHERE id = ?");
    if (!$stmt) {
        die("Fehler beim Vorbereiten des Statements: " . $conn->error);
    }
    $stmt->bind_param("ssi", $education_type, $education_field, $id);
    $stmt->execute();
    $stmt->close();

    // Zurück zur Mitarbeiter-Detailseite
    header("Location: employee_details.php?employee_id=" . urlencode($employee_id));
    exit;
}

// GET-Parameter auslesen, um das Formular mit bestehenden Werten zu füllen
$id = $_GET['id'];             // PK aus employee_education
$employee_id = $_GET['employee_id']; // employee_id aus employees

$stmt = $conn->prepare("SELECT education_type, education_field FROM employee_education WHERE id = ?");
if (!$stmt) {
    die("Fehler beim Vorbereiten des Statements: " . $conn->error);
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
} else {
    echo '<p>Keine Daten gefunden.</p>';
    exit;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ausbildung bearbeiten</title>
    <!-- Lokales Bootstrap 5 CSS + Navbar -->
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <h1 class="text-center mb-3">Ausbildung bearbeiten</h1>
    <div class="divider"></div>

    <form action="edit_education.php" method="POST">
        <!-- Versteckte Felder für PK und employee_id -->
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
        <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee_id); ?>">

        <div class="mb-3">
            <label for="education_type" class="form-label">Art der Ausbildung:</label>
            <input type="text" class="form-control" id="education_type" name="education_type"
                   value="<?php echo htmlspecialchars($row['education_type']); ?>" required>
        </div>

        <div class="mb-3">
            <label for="education_field" class="form-label">Ausbildung:</label>
            <input type="text" class="form-control" id="education_field" name="education_field"
                   value="<?php echo htmlspecialchars($row['education_field']); ?>" required>
        </div>

        <button type="submit" class="btn btn-primary">Speichern</button>
        <a href="employee_details.php?employee_id=<?php echo htmlspecialchars($employee_id); ?>"
           class="btn btn-secondary">Abbrechen</a>
    </form>
</div>

<!-- Lokales Bootstrap 5 JavaScript Bundle -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
