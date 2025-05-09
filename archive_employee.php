<?php
/**
 * archive_employee.php
 *
 * Handles archiving an employee who has left the company.
 * Only accessible to HR staff.
 */

include 'access_control.php';
global $conn;

// Ensure user is logged in and has appropriate permissions
pruefe_benutzer_eingeloggt();

// Check if the user has HR access
if (!ist_hr() && !ist_admin()) {
    header("Location: access_denied.php");
    exit;
}

// Check if employee ID is provided
if (!isset($_POST['employee_id']) || !is_numeric($_POST['employee_id'])) {
    $_SESSION['result_message'] = "Ungültige Mitarbeiter-ID.";
    $_SESSION['result_type'] = "danger";
    header("Location: index.php");
    exit;
}

$employee_id = (int)$_POST['employee_id'];
$leave_date = $_POST['leave_date'] ?? date('Y-m-d');
$leave_reason = $_POST['leave_reason'] ?? '';

// Transaktion starten
$conn->begin_transaction();

try {
    // Update employee status to archived (9999)
    $stmt = $conn->prepare("
        UPDATE employees 
        SET status = 9999, 
            anwesend = 0,
            leave_date = ?,
            leave_reason = ?
        WHERE employee_id = ?
    ");

    $stmt->bind_param("ssi", $leave_date, $leave_reason, $employee_id);

    if (!$stmt->execute()) {
        throw new Exception("Fehler beim Archivieren des Mitarbeiters: " . $stmt->error);
    }
    $stmt->close();

    // Löschen des Benutzers in der benutzer_matool Tabelle
    $delete_stmt = $conn->prepare("DELETE FROM benutzer_matool WHERE mitarbeiter_id = ?");
    $delete_stmt->bind_param("i", $employee_id);
    $delete_stmt->execute();
    $deleted_user = $delete_stmt->affected_rows > 0;
    $delete_stmt->close();

    // Transaktion abschließen
    $conn->commit();

    $_SESSION['result_message'] = "Mitarbeiter erfolgreich archiviert." .
        ($deleted_user ? " Zugehöriger Benutzer wurde entfernt." : "");
    $_SESSION['result_type'] = "success";

} catch (Exception $e) {
    // Fehler -> Rollback
    $conn->rollback();
    $_SESSION['result_message'] = $e->getMessage();
    $_SESSION['result_type'] = "danger";
}

// Redirect back to employee list or another appropriate page
header("Location: archived_employee_details.php?employee_id=" . $employee_id);
exit;
?>