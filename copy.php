<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include 'db.php'; // Verbindung zur Datenbank herstellen

global $conn;

$filename = 'Geburtstage.csv'; // Pfad zur CSV-Datei
$file = fopen($filename, 'r');

if (!$file) {
    die('Fehler: Datei konnte nicht geöffnet werden.');
}

// Kopfzeile der CSV überspringen
$header = fgetcsv($file, 0, ';'); // Anpassen des Trennzeichens falls nötig

// Funktion zur Überprüfung und Umwandlung des Datums
function format_date($date) {
    $date = trim($date);
    if (empty($date) || $date == '00.00.0000') {
        return null; // Leeres oder ungültiges Datum
    }

    // Konvertierung von dd.mm.yyyy zu yyyy-mm-dd
    $dateParts = explode('.', $date);
    if (count($dateParts) == 3) {
        return $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
    }

    return null; // Falls das Datum nicht im erwarteten Format ist
}

$notFoundEmployees = []; // Array zum Speichern nicht gefundener Mitarbeiter

// Verarbeitung der Datei Zeile für Zeile
while (($row = fgetcsv($file, 0, ';')) !== false) {
    if (count($row) < 2) {
        echo "Fehler: Daten unvollständig in Zeile.<br>";
        continue;
    }

    // Variablen aus der CSV-Datei
    $name = isset($row[0]) ? trim($row[0]) : ''; // Name des Mitarbeiters
    $birthdate = isset($row[1]) ? format_date($row[1]) : null; // Geburtsdatum

    // Überprüfen, ob der Mitarbeiter in der Datenbank existiert
    $stmt = $conn->prepare("SELECT id FROM employees WHERE name = ?");
    if (!$stmt) {
        echo "Fehler bei der Vorbereitung der Abfrage: " . $conn->error . "<br>";
        continue;
    }

    $stmt->bind_param("s", $name);
    if (!$stmt->execute()) {
        echo "Fehler bei der Ausführung der Abfrage für Mitarbeiter $name: " . $stmt->error . "<br>";
        continue;
    }

    $result = $stmt->get_result();
    if ($result === false) {
        echo "Fehler beim Abrufen des Ergebnisses für Mitarbeiter $name: " . $stmt->error . "<br>";
        continue;
    }

    if ($result->num_rows === 0) {
        echo "Mitarbeiter mit Namen $name nicht in der Datenbank gefunden.<br>";
        $notFoundEmployees[] = $name; // Mitarbeiter zum Array der nicht gefundenen hinzufügen
        continue; // gehe zur nächsten Zeile
    }

    // Mitarbeiter gefunden, ID holen
    $employee = $result->fetch_assoc();
    $employee_id = $employee['id'];

    // SQL-Abfrage zum Aktualisieren des Geburtsdatums
    $updateStmt = $conn->prepare("UPDATE employees SET birthdate = ? WHERE id = ?");
    if (!$updateStmt) {
        echo "Fehler bei der Vorbereitung des Update-Statements für Mitarbeiter $name: " . $conn->error . "<br>";
        continue;
    }

    $updateStmt->bind_param("si", $birthdate, $employee_id);

    if ($updateStmt->execute()) {
        echo "Geburtsdatum für Mitarbeiter $name erfolgreich aktualisiert.<br>";
    } else {
        echo "Fehler beim Aktualisieren des Geburtsdatums für Mitarbeiter $name.<br>";
    }
}

// Datei schließen
fclose($file);
$conn->close();

// Ausgabe aller nicht gefundenen Mitarbeiter
if (!empty($notFoundEmployees)) {
    echo "<h3>Folgende Mitarbeiter wurden nicht in der Datenbank gefunden:</h3>";
    echo "<ul>";
    foreach ($notFoundEmployees as $notFound) {
        echo "<li>" . htmlspecialchars($notFound) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<h3>Alle Mitarbeiter wurden erfolgreich gefunden und aktualisiert.</h3>";
}
?>
