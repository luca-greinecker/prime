<?php
/**
 * get_positionen_by_gruppe.php
 *
 * Liefert alle Positionen aus der Tabelle 'positionen' für eine bestimmte Gruppe als JSON zurück.
 * Parameter: gruppe (z.B. ?gruppe=Schichtarbeit)
 */

include 'db.php';
global $conn;

if (isset($_GET['gruppe'])) {
    $gruppe = $_GET['gruppe'];

    $stmt = $conn->prepare("SELECT id, name FROM positionen WHERE gruppe = ?");
    if (!$stmt) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("s", $gruppe);
    $stmt->execute();
    $result = $stmt->get_result();

    $positionen = [];
    while ($row = $result->fetch_assoc()) {
        $positionen[] = $row;
    }
    $stmt->close();

    echo json_encode($positionen);
} else {
    echo json_encode([]);
}
?>