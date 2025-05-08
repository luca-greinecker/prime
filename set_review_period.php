<?php
/**
 * set_review_period.php
 *
 * Dieses Skript verarbeitet ein POST-Formular, das einen neuen Gesprächszeitraum
 * (z. B. für Mitarbeitergespräche / Talent Review) anlegt oder aktualisiert.
 * Am Ende wird zur settings_gespraeche.php umgeleitet.
 */

include_once 'access_control.php';
pruefe_benutzer_eingeloggt();

global $conn;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Formulardaten holen
    $start_month = (int)$_POST['start_month'];
    $start_year = (int)$_POST['start_year'];
    $end_month = (int)$_POST['end_month'];
    $end_year = (int)$_POST['end_year'];

    // Annahme: Wir nutzen das start_year als "Hauptjahr" (-> 'year'-Feld in der DB)
    // Alternativ könntest du ein separates Feld post_year haben, falls gewünscht.
    $year = $start_year;

    // 2) Prüfen, ob schon ein Zeitraum für dieses "year" existiert
    $checkStmt = $conn->prepare("SELECT id FROM review_periods WHERE year = ? LIMIT 1");
    if (!$checkStmt) {
        die('Prepare failed: ' . htmlspecialchars($conn->error));
    }
    $checkStmt->bind_param("i", $year);
    $checkStmt->execute();
    $checkStmt->store_result();

    // 3) Wenn bereits vorhanden -> UPDATE, sonst -> INSERT
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        // UPDATE
        $updateStmt = $conn->prepare("
            UPDATE review_periods
            SET start_month = ?,
                start_year  = ?,
                end_month   = ?,
                end_year    = ?
            WHERE year = ?
        ");
        if (!$updateStmt) {
            die('Prepare failed: ' . htmlspecialchars($conn->error));
        }
        $updateStmt->bind_param("iiiii", $start_month, $start_year, $end_month, $end_year, $year);

        if ($updateStmt->execute()) {
            $_SESSION['message'] = 'success';
        } else {
            $_SESSION['message'] = 'error';
        }
        $updateStmt->close();

    } else {
        $checkStmt->close();
        // INSERT
        $insertStmt = $conn->prepare("
            INSERT INTO review_periods
                (year, start_month, start_year, end_month, end_year)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$insertStmt) {
            die('Prepare failed: ' . htmlspecialchars($conn->error));
        }
        $insertStmt->bind_param("iiiii", $year, $start_month, $start_year, $end_month, $end_year);

        if ($insertStmt->execute()) {
            $_SESSION['message'] = 'success';
        } else {
            $_SESSION['message'] = 'error';
        }
        $insertStmt->close();
    }

    // 4) Weiterleitung
    header("Location: settings_gespraeche.php");
    exit;
}
?>