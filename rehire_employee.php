<?php
/**
 * rehire_employee.php
 *
 * Verarbeitet den Prozess der Wiedereinstellung eines ehemaligen Mitarbeiters.
 * Setzt den Status zurück und löscht die Austrittsinformationen.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Nur HR und Admin dürfen diese Aktion ausführen
if (!ist_hr() && !ist_admin()) {
    header("Location: access_denied.php");
    exit;
}

// Prüfen, ob notwendige Daten vorhanden sind
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['employee_id']) && is_numeric($_POST['employee_id'])) {
    $employee_id = (int)$_POST['employee_id'];
    $new_entry_date = $_POST['new_entry_date'] ?? date('Y-m-d');
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $badge_id = isset($_POST['badge_id']) ? (int)$_POST['badge_id'] : 0;

    // Eingegebene Werte für Fehlerfall speichern
    $_SESSION['rehire_data'] = [
        'new_entry_date' => $new_entry_date,
        'username' => $username,
        'badge_id' => $badge_id,
        'show_modal' => true
    ];

    // Transaktion starten
    $conn->begin_transaction();

    try {
        // Überprüfen, ob der Mitarbeiter existiert und archiviert ist
        $stmt = $conn->prepare("SELECT name FROM employees WHERE employee_id = ? AND status = 9999");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            throw new Exception("Mitarbeiter nicht gefunden oder nicht archiviert.");
        }

        $employee_name = $result->fetch_assoc()['name'];
        $stmt->close();

        // Prüfen, ob Badge-ID bereits verwendet wird
        if ($badge_id > 0) {
            $badge_check = $conn->prepare("
                SELECT employee_id FROM employees 
                WHERE badge_id = ? AND employee_id != ?
            ");
            $badge_check->bind_param("ii", $badge_id, $employee_id);
            $badge_check->execute();
            $badge_result = $badge_check->get_result();

            if ($badge_result->num_rows > 0) {
                throw new Exception("Die Ausweisnummer {$badge_id} ist bereits einem anderen Mitarbeiter zugewiesen. Bitte wählen Sie eine andere Nummer.");
            }
            $badge_check->close();
        } else {
            throw new Exception("Bitte geben Sie eine gültige Ausweisnummer ein.");
        }

        // Prüfen, ob Benutzername angegeben ist
        if (empty($username)) {
            throw new Exception("Bitte geben Sie einen Benutzernamen ein.");
        }

        // Prüfen, ob Benutzername bereits existiert
        $check_stmt = $conn->prepare("SELECT id FROM benutzer_matool WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            throw new Exception("Der Benutzername '" . htmlspecialchars($username) . "' ist bereits vergeben. Bitte wählen Sie einen anderen Namen.");
        }
        $check_stmt->close();

        // Mitarbeiter wieder aktivieren
        $stmt = $conn->prepare("
            UPDATE employees 
            SET status = 0, 
                anwesend = 0,
                badge_id = ?,
                leave_date = NULL,
                leave_reason = NULL,
                entry_date = ?,
                last_present = NULL
            WHERE employee_id = ?
        ");
        $stmt->bind_param("isi", $badge_id, $new_entry_date, $employee_id);

        if (!$stmt->execute()) {
            throw new Exception("Fehler beim Wiedereinstellen des Mitarbeiters: " . $stmt->error);
        }
        $stmt->close();

        // Benutzer anlegen mit Standardpasswort Ball1234
        $standard_password = 'Ball1234';
        $hashed_password = password_hash($standard_password, PASSWORD_DEFAULT);

        $user_stmt = $conn->prepare("INSERT INTO benutzer_matool (username, password, mitarbeiter_id) VALUES (?, ?, ?)");
        $user_stmt->bind_param("ssi", $username, $hashed_password, $employee_id);

        if (!$user_stmt->execute()) {
            throw new Exception("Fehler beim Anlegen des Benutzerkontos: " . $user_stmt->error);
        }
        $user_stmt->close();

        // Transaktion abschließen
        $conn->commit();

        // Nach erfolgreichem Abschluss die temporären Daten löschen
        unset($_SESSION['rehire_data']);

        $_SESSION['result_message'] = "Mitarbeiter " . htmlspecialchars($employee_name) . " wurde erfolgreich wieder eingestellt. Benutzerkonto mit dem Standardpasswort 'Ball1234' angelegt.";
        $_SESSION['result_type'] = "success";

        // Direkt zur Mitarbeiterdetailseite weiterleiten statt zum Onboarding
        header("Location: employee_details.php?employee_id=" . $employee_id);
        exit;

    } catch (Exception $e) {
        // Fehler -> Rollback
        $conn->rollback();

        // Fehlermeldung direkt in den rehire_data speichern statt in result_message
        $_SESSION['rehire_data']['error'] = $e->getMessage();

        // Bei Fehler zurück zur Detailseite, nicht zur Übersicht
        header("Location: archived_employee_details.php?employee_id=" . $employee_id);
        exit;
    }
} else {
    $_SESSION['result_message'] = "Ungültige Anfrage.";
    $_SESSION['result_type'] = "danger";
    header("Location: ehemalige_mitarbeiter.php");
    exit;
}
?>