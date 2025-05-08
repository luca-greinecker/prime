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

if ($stmt->execute()) {
    $_SESSION['result_message'] = "Mitarbeiter erfolgreich archiviert.";
    $_SESSION['result_type'] = "success";
} else {
    $_SESSION['result_message'] = "Fehler beim Archivieren des Mitarbeiters: " . $stmt->error;
    $_SESSION['result_type'] = "danger";
}
$stmt->close();

// Redirect back to employee list or another appropriate page
header("Location: employee_details.php?employee_id=" . $employee_id);
exit;
?>