<?php
/**
 * update_employee.php
 *
 * Diese Seite verarbeitet POST-Anfragen, um Mitarbeiterdaten zu aktualisieren.
 * Für HR-Benutzer dürfen alle Felder geändert werden, während Schichtmeister
 * die Position und den Status ändern dürfen und Schichtmeister-Stv. sowie
 * Abteilungsleiter (Leiter) nur den Status ändern können.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Fehlerprotokollierung aktivieren
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Ermitteln, ob der Benutzer HR, Schichtmeister, Schichtmeister-Stv. oder Leiter ist
$ist_hr = ist_hr();
$ist_sm = ist_sm();
$ist_smstv = ist_smstv();
$ist_leiter = ist_leiter();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $position = $_POST['position'] ?? '';

    // Validierung: Bei Schichtarbeit muss ein Team ausgewählt sein
    $gruppe = $_POST['gruppe'] ?? '---';
    $crew = $_POST['crew'] ?? '---';
    if ($gruppe === 'Schichtarbeit' && ($crew === '---' || empty($crew))) {
        $_SESSION['result_message'] = "Fehler: Bei Schichtarbeit muss ein Team ausgewählt werden!";
        $_SESSION['result_type'] = "danger";
        echo '<div class="alert alert-danger">Fehler: Bei Schichtarbeit muss ein Team ausgewählt werden!</div>';
        exit;
    }

    // Zunächst: Aktuellen Status und Anwesenheit des Mitarbeiters abrufen
    $stmt = $conn->prepare("SELECT anwesend, status, gruppe FROM employees WHERE employee_id = ?");
    if (!$stmt) {
        die("Fehler bei der Vorbereitung der SELECT-Abfrage: " . $conn->error);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    $stmt->close();

    $current_status = $employee['status'];
    $anwesend = $employee['anwesend'];
    $current_gruppe = $employee['gruppe'];

    // Status-Änderung: Wird nur geändert, wenn der Mitarbeiter nicht anwesend ist
    $status = ($anwesend == 1) ? $current_status : ($_POST['status'] ?? $current_status);

    if ($ist_hr) {
        $entry_date = $_POST['entry_date'] ?? null;
        $phone_number = $_POST['phone_number'] ?? '';
        $email_private = $_POST['email_private'] ?? '';
        $email_business = $_POST['email_business'] ?? '';
        $lohnschema = $_POST['lohnschema'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $leasing = isset($_POST['leasing']) ? 1 : 0;
        $ersthelfer = isset($_POST['ersthelfer']) ? 1 : 0;
        $svp = isset($_POST['svp']) ? 1 : 0;
        $brandschutzwart = isset($_POST['brandschutzwart']) ? 1 : 0;
        $sprinklerwart = isset($_POST['sprinklerwart']) ? 1 : 0;
        $badge_id = isset($_POST['badge_id']) ? (int)$_POST['badge_id'] : 0;
        $pr_lehrabschluss = isset($_POST['pr_lehrabschluss']) ? 1 : 0;
        $lohn_type = $_POST['lohn_type'] ?? '';
        $pr_anfangslohn = ($lohn_type === 'pr_anfangslohn') ? 1 : 0;
        $pr_grundlohn = ($lohn_type === 'pr_grundlohn') ? 1 : 0;
        $pr_qualifikationsbonus = isset($_POST['pr_qualifikationsbonus']) ? 1 : 0;
        $pr_expertenbonus = isset($_POST['pr_expertenbonus']) ? 1 : 0;
        if ($pr_qualifikationsbonus && !$pr_grundlohn) {
            $pr_qualifikationsbonus = 0;
        }
        if ($pr_expertenbonus && !$pr_qualifikationsbonus) {
            $pr_expertenbonus = 0;
        }
        $tk_qualifikationsbonus_1 = isset($_POST['tk_qualifikationsbonus_1']) ? 1 : 0;
        $tk_qualifikationsbonus_2 = isset($_POST['tk_qualifikationsbonus_2']) ? 1 : 0;
        $tk_qualifikationsbonus_3 = isset($_POST['tk_qualifikationsbonus_3']) ? 1 : 0;
        $tk_qualifikationsbonus_4 = isset($_POST['tk_qualifikationsbonus_4']) ? 1 : 0;
        if ($tk_qualifikationsbonus_4 && !$tk_qualifikationsbonus_3) $tk_qualifikationsbonus_4 = 0;
        if ($tk_qualifikationsbonus_3 && !$tk_qualifikationsbonus_2) $tk_qualifikationsbonus_3 = 0;
        if ($tk_qualifikationsbonus_2 && !$tk_qualifikationsbonus_1) $tk_qualifikationsbonus_2 = 0;
        $ln_zulage = $_POST['ln_zulage'] ?? 'Keine Zulage';

        $stmt = $conn->prepare("UPDATE employees SET 
            position = ?, 
            gruppe = ?, 
            crew = ?, 
            entry_date = ?, 
            phone_number = ?, 
            email_private = ?, 
            email_business = ?, 
            lohnschema = ?, 
            gender = ?, 
            badge_id = ?, 
            leasing = ?, 
            ersthelfer = ?, 
            svp = ?, 
            brandschutzwart = ?, 
            sprinklerwart = ?, 
            pr_lehrabschluss = ?, 
            pr_anfangslohn = ?, 
            pr_grundlohn = ?, 
            pr_qualifikationsbonus = ?, 
            pr_expertenbonus = ?, 
            tk_qualifikationsbonus_1 = ?, 
            tk_qualifikationsbonus_2 = ?, 
            tk_qualifikationsbonus_3 = ?, 
            tk_qualifikationsbonus_4 = ?, 
            ln_zulage = ?, 
            status = ? 
            WHERE employee_id = ?");

        if (!$stmt) {
            die("Fehler bei der Vorbereitung des UPDATE-Statements (HR): " . $conn->error);
        }
        $stmt->bind_param(
            "sssssssssiiiiiiiiiiiiiiisii",
            $position,
            $gruppe,
            $crew,
            $entry_date,
            $phone_number,
            $email_private,
            $email_business,
            $lohnschema,
            $gender,
            $badge_id,
            $leasing,
            $ersthelfer,
            $svp,
            $brandschutzwart,
            $sprinklerwart,
            $pr_lehrabschluss,
            $pr_anfangslohn,
            $pr_grundlohn,
            $pr_qualifikationsbonus,
            $pr_expertenbonus,
            $tk_qualifikationsbonus_1,
            $tk_qualifikationsbonus_2,
            $tk_qualifikationsbonus_3,
            $tk_qualifikationsbonus_4,
            $ln_zulage,
            $status,
            $id
        );
    } elseif ($ist_sm) {
        $stmt = $conn->prepare("UPDATE employees SET position = ?, status = ? WHERE employee_id = ?");
        if (!$stmt) {
            die("Fehler bei der Vorbereitung des UPDATE-Statements (SM): " . $conn->error);
        }
        $stmt->bind_param("sii", $position, $status, $id);
    } elseif ($ist_smstv || $ist_leiter) {
        $stmt = $conn->prepare("UPDATE employees SET status = ? WHERE employee_id = ?");
        if (!$stmt) {
            die("Fehler bei der Vorbereitung des UPDATE-Statements (SMStv/Leiter): " . $conn->error);
        }
        $stmt->bind_param("ii", $status, $id);
    } else {
        header("Location: access_denied.php");
        exit;
    }

    if ($stmt->execute()) {
        $_SESSION['result_message'] = "Änderungen erfolgreich gespeichert.";
        $_SESSION['result_type'] = "success";
        echo '<div class="alert alert-success">Änderungen erfolgreich gespeichert.</div>';
    } else {
        $_SESSION['result_message'] = "Fehler beim Speichern: " . $stmt->error;
        $_SESSION['result_type'] = "danger";
        echo '<div class="alert alert-danger">Fehler beim Speichern: ' . $stmt->error . '</div>';
    }
    $stmt->close();
}
?>
