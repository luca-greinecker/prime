<?php
/**
 * save_safety_data.php
 *
 * Verarbeitet POST-Anfragen für die Aktualisierung von Sicherheitsdaten
 * wie Ersthelfer-Status und Sicherheitsvertrauenspersonen.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Zugriffskontrolle: Nur EHS-Manager und Admins haben Zugriff
if (!ist_ehs() && !ist_admin()) {
    header("Location: access_denied.php");
    exit;
}

// Ergebnis-Variablen
$success = false;
$message = '';

// Ersthelfer/SVP-Daten aktualisieren
if (isset($_POST['update_safety_data'])) {
    $employee_id = (int)$_POST['employee_id'];
    $ersthelfer = isset($_POST['ersthelfer']) ? 1 : 0;
    $svp = isset($_POST['svp']) ? 1 : 0;
    $ersthelfer_zertifikat_ablauf = !empty($_POST['ersthelfer_zertifikat_ablauf']) ?
        $_POST['ersthelfer_zertifikat_ablauf'] : NULL;

    // Validieren des Ablaufdatums
    if ($ersthelfer && empty($ersthelfer_zertifikat_ablauf)) {
        $_SESSION['result_type'] = 'error';
        $_SESSION['result_message'] = "Bei einem Ersthelfer muss ein Ablaufdatum für das Zertifikat angegeben werden.";
        header("Location: safety_dashboard.php");
        exit;
    }

    // Daten aktualisieren
    $stmt = $conn->prepare("
        UPDATE employees
        SET ersthelfer = ?,
            svp = ?,
            ersthelfer_zertifikat_ablauf = ?
        WHERE employee_id = ?
    ");

    $stmt->bind_param("iisi", $ersthelfer, $svp, $ersthelfer_zertifikat_ablauf, $employee_id);

    if ($stmt->execute()) {
        $success = true;
        $message = "Die Sicherheitsdaten für den Mitarbeiter wurden erfolgreich aktualisiert.";
    } else {
        $message = "Fehler beim Aktualisieren der Daten: " . $conn->error;
    }
    $stmt->close();

    // Ergebnis in Session speichern und zurück zum Dashboard
    $_SESSION['result_type'] = $success ? 'success' : 'error';
    $_SESSION['result_message'] = $message;
    header("Location: safety_dashboard.php");
    exit;
}

// Massenbearbeitung für Ersthelfer/SVP
if (isset($_POST['update_multiple_safety'])) {
    $employee_ids = isset($_POST['selected_employees']) ? $_POST['selected_employees'] : [];
    $action = $_POST['safety_action'];
    $ablaufdatum = !empty($_POST['mass_ablaufdatum']) ? $_POST['mass_ablaufdatum'] : NULL;

    if (empty($employee_ids)) {
        $_SESSION['result_type'] = 'error';
        $_SESSION['result_message'] = "Keine Mitarbeiter ausgewählt.";
        header("Location: safety_dashboard.php");
        exit;
    }

    $success_count = 0;
    $error_count = 0;

    foreach ($employee_ids as $emp_id) {
        $emp_id = (int)$emp_id;

        switch ($action) {
            case 'add_ersthelfer':
                $stmt = $conn->prepare("
                    UPDATE employees
                    SET ersthelfer = 1,
                        ersthelfer_zertifikat_ablauf = ?
                    WHERE employee_id = ?
                ");
                $stmt->bind_param("si", $ablaufdatum, $emp_id);
                break;

            case 'remove_ersthelfer':
                $stmt = $conn->prepare("
                    UPDATE employees
                    SET ersthelfer = 0,
                        ersthelfer_zertifikat_ablauf = NULL
                    WHERE employee_id = ?
                ");
                $stmt->bind_param("i", $emp_id);
                break;

            case 'add_svp':
                $stmt = $conn->prepare("
                    UPDATE employees
                    SET svp = 1
                    WHERE employee_id = ?
                ");
                $stmt->bind_param("i", $emp_id);
                break;

            case 'remove_svp':
                $stmt = $conn->prepare("
                    UPDATE employees
                    SET svp = 0
                    WHERE employee_id = ?
                ");
                $stmt->bind_param("i", $emp_id);
                break;

            case 'update_ablaufdatum':
                $stmt = $conn->prepare("
                    UPDATE employees
                    SET ersthelfer_zertifikat_ablauf = ?
                    WHERE employee_id = ? AND ersthelfer = 1
                ");
                $stmt->bind_param("si", $ablaufdatum, $emp_id);
                break;

            default:
                continue 2; // Unbekannte Aktion überspringen
        }

        if ($stmt->execute()) {
            $success_count++;
        } else {
            $error_count++;
        }
        $stmt->close();
    }

    if ($success_count > 0) {
        $_SESSION['result_type'] = 'success';
        $_SESSION['result_message'] = "$success_count Mitarbeiter erfolgreich aktualisiert.";
        if ($error_count > 0) {
            $_SESSION['result_message'] .= " $error_count Aktualisierungen fehlgeschlagen.";
        }
    } else {
        $_SESSION['result_type'] = 'error';
        $_SESSION['result_message'] = "Fehler beim Aktualisieren der Mitarbeiterdaten.";
    }

    header("Location: safety_dashboard.php");
    exit;
}

// Wenn keine gültige POST-Anfrage, zurück zum Dashboard
header("Location: safety_dashboard.php");
exit;
?>