<?php
/**
 * bestaetigung.php
 *
 * Diese Seite zeigt eine Bestätigung, dass ein Gespräch bzw. Talent Review
 * für einen Mitarbeiter abgeschlossen wurde.
 * Es wird der Name des Mitarbeiters aus der Tabelle employees anhand des internen
 * Schlüssels (employee_id) abgerufen.
 */

include 'access_control.php';

global $conn;

pruefe_benutzer_eingeloggt();

// Mitarbeiter-ID aus der URL auslesen
if (!isset($_GET['employee_id'])) {
    echo '<p>Keine Mitarbeiter-ID angegeben</p>';
    exit;
}
$employee_id = $_GET['employee_id'];

// Benutzerinformationen (Name) aus der Tabelle employees anhand des internen Schlüssels abrufen
$stmt = $conn->prepare("SELECT name FROM employees WHERE employee_id = ?");
if (!$stmt) {
    die("Fehler bei der Vorbereitung des Statements: " . $conn->error);
}
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
} else {
    echo '<p>Keine Daten gefunden</p>';
    exit;
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gespräch abgeschlossen</title>
    <!-- Bootstrap CSS (CDN und lokal, ggf. kann eines entfernt werden) -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.8.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin: 5rem auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .container h1 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #343a40;
        }
        .container p {
            font-size: 18px;
            margin-bottom: 20px;
            color: #6c757d;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #004085;
        }
        .btn-container {
            text-align: center;
        }
        .success-icon {
            font-size: 50px;
            color: #28a745;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="container text-center">
    <div class="success-icon">
        <i class="bi bi-check-circle-fill"></i>
    </div>
    <h1>Gespräch/Talent Review abgeschlossen</h1>
    <p>Das Mitarbeitergespräch/Talent Review mit <strong><?php echo htmlspecialchars($row['name']); ?></strong> wurde erfolgreich abgeschlossen.</p>
    <div class="btn-container">
        <a href="meine_mitarbeiter.php" class="btn btn-primary">Zurück zur Übersicht</a>
    </div>
</div>

<!-- Bootstrap JS und Abhängigkeiten -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>
