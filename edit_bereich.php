<?php
/**
 * edit_bereich.php
 *
 * Bearbeitet den Namen einer vorhandenen Position in der Tabelle 'positionen'.
 * Erwartet per POST:
 *  - position_id (int): Die ID der zu bearbeitenden Position
 *  - position_name (string): Der neue Name der Position
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';
global $conn;

$response = ['success' => false, 'message' => 'Unbekannter Fehler'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['position_id'], $_POST['position_name'])) {
    $position_id = $_POST['position_id'];
    $position_name = $_POST['position_name'];

    $stmt = $conn->prepare("UPDATE positionen SET name = ? WHERE id = ?");
    if ($stmt === false) {
        $response['message'] = "Prepare failed: " . htmlspecialchars($conn->error);
        echo json_encode($response);
        exit;
    }
    $stmt->bind_param("si", $position_name, $position_id);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = "Position erfolgreich bearbeitet.";
    } else {
        $response['message'] = "Fehler beim Bearbeiten der Position: " . htmlspecialchars($conn->error);
    }
    $stmt->close();
} else {
    $response['message'] = "Ungültige oder fehlende Parameter.";
}

echo json_encode($response);