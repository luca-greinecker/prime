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

    // Überprüfen, ob der Mitarbeiter existiert und archiviert ist
    $stmt = $conn->prepare("SELECT name FROM employees WHERE employee_id = ? AND status = 9999");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $employee_name = $result->fetch_assoc()['name'];
        $stmt->close();

        // Mitarbeiter wieder aktivieren
        $stmt = $conn->prepare("
            UPDATE employees 
            SET status = 0, 
                anwesend = 0,
                onboarding_status = 0,
                leave_date = NULL,
                leave_reason = NULL,
                entry_date = ?,
                last_present = NULL
            WHERE employee_id = ?
        ");
        $stmt->bind_param("si", $new_entry_date, $employee_id);

        if ($stmt->execute()) {
            $_SESSION['result_message'] = "Mitarbeiter " . htmlspecialchars($employee_name) . " wurde erfolgreich wieder eingestellt.";
            $_SESSION['result_type'] = "success";
            header("Location: employee_onboarding.php?id=" . $employee_id);
            exit;
        } else {
            $_SESSION['result_message'] = "Fehler beim Wiedereinstellen des Mitarbeiters: " . $stmt->error;
            $_SESSION['result_type'] = "danger";
        }
        $stmt->close();
    } else {
        $_SESSION['result_message'] = "Mitarbeiter nicht gefunden oder nicht archiviert.";
        $_SESSION['result_type'] = "danger";
        $stmt->close();
    }
} else {
    $_SESSION['result_message'] = "Ungültige Anfrage.";
    $_SESSION['result_type'] = "danger";
}

// Fallback, falls etwas schief geht
header("Location: ehemalige_mitarbeiter.php");
exit;
?>