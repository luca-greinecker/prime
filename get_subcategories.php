<?php
/**
 * get_subcategories.php
 *
 * AJAX-Endpunkt zum Laden von Unterkategorien für eine bestimmte Hauptkategorie.
 * Wird von edit_training.php und trainings.php verwendet, um die Unterkategorien
 * dynamisch zu aktualisieren, wenn die Hauptkategorie geändert wird.
 */

include 'access_control.php';
include 'training_functions.php';
global $conn;
pruefe_benutzer_eingeloggt();

// AJAX-Request validieren
header('Content-Type: application/json');

// Hauptkategorie-ID aus der Anfrage lesen
$main_category_id = isset($_GET['main_category_id']) ? (int)$_GET['main_category_id'] : 0;

if ($main_category_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Hauptkategorie-ID']);
    exit;
}

// Aktive Unterkategorien für die gewählte Hauptkategorie laden
$stmt = $conn->prepare("
    SELECT id, name, code 
    FROM training_sub_categories 
    WHERE main_category_id = ? AND active = TRUE
    ORDER BY name ASC
");
$stmt->bind_param("i", $main_category_id);
$stmt->execute();
$result = $stmt->get_result();
$sub_categories = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// JSON-Antwort senden
echo json_encode($sub_categories);
?>