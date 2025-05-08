<?php
/**
 * delete_education.php
 *
 * Diese Seite ermöglicht das Löschen eines Ausbildungseintrags aus der Tabelle employee_education.
 * Es wird erwartet, dass sowohl die ID des Ausbildungseintrags als auch der interne Mitarbeiter-Schlüssel
 * (employee_id) via GET übergeben werden.
 *
 * Nach dem Löschen erfolgt eine Weiterleitung zur Mitarbeiterdetailseite, wobei der interne Schlüssel übergeben wird.
 */

// Session starten, falls noch nicht geschehen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php'; // Datenbank-Verbindung einbinden

global $conn;

// Überprüfen, ob sowohl die ID des Ausbildungseintrags als auch die employee_id vorhanden sind
if (isset($_GET['id']) && isset($_GET['employee_id'])) {
    // Die übergebenen Parameter absichern (optional: auch typecasting durchführen)
    $id = (int) $_GET['id'];
    $employee_id = (int) $_GET['employee_id'];

    // Vorbereitung des DELETE-Statements für den Ausbildungseintrag
    $stmt = $conn->prepare("DELETE FROM employee_education WHERE id = ?");
    if (!$stmt) {
        die("Fehler bei der Vorbereitung des DELETE-Statements: " . $conn->error);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Weiterleitung zur Mitarbeiterdetailseite mit dem internen Schlüssel employee_id
    header("Location: employee_details.php?employee_id=" . $employee_id);
    exit;
} else {
    echo '<p>Erforderliche Parameter fehlen.</p>';
    exit;
}
?>