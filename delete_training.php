<?php
/**
 * delete_training.php
 *
 * Diese Seite dient zum Löschen von Trainings-Einträgen mit zwei verschiedenen Modi:
 *
 * 1. Mitarbeiter-spezifischer Modus (ohne confirm=1):
 *    - Löscht nur die Zuordnung eines bestimmten Mitarbeiters zu einem Training
 *    - Löscht das Training selbst nur, wenn keine weiteren Zuordnungen existieren
 *    - Wird über "Löschen" Button in employee_details.php aufgerufen
 *
 * 2. Vollständiger Löschmodus (mit confirm=1):
 *    - Löscht das komplette Training sowie alle Zuordnungen zu Mitarbeitern und Trainern
 *    - Wird über das Bestätigungsmodal in edit_training.php aufgerufen
 *
 * Parameter:
 * - id: Die ID des zu löschenden Trainings (erforderlich)
 * - employee_id: Der interne Schlüssel des Mitarbeiters (optional im vollständigen Löschmodus)
 * - confirm: Wenn auf 1 gesetzt, wird das Training vollständig gelöscht
 */

include 'access_control.php'; // Für Zugriffskontrolle und Sessions
global $conn; // Datenbankverbindung

// Sicherstellen, dass der Benutzer eingeloggt ist und Trainings-Zugriff hat
pruefe_benutzer_eingeloggt();
pruefe_trainings_zugriff();

// Training-ID ist immer erforderlich
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['result_message'] = 'Fehler: Ungültige Training-ID';
    $_SESSION['result_type'] = 'error';
    header("Location: trainings.php");
    exit;
}

$training_id = (int)$_GET['id'];
$confirm = isset($_GET['confirm']) && $_GET['confirm'] == 1;
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : null;

try {
    // Transaktionsstart - um Datenintegrität zu gewährleisten
    $conn->begin_transaction();

    // Modus 1: Vollständiges Löschen des Trainings (mit allen Zuordnungen)
    if ($confirm) {
        // 1. Zuerst alle Trainer-Zuordnungen löschen (für Technical Trainings)
        $trainer_stmt = $conn->prepare("DELETE FROM training_trainers WHERE training_id = ?");
        if (!$trainer_stmt) {
            throw new Exception("Fehler bei der Vorbereitung des DELETE-Statements für Trainer-Zuordnungen: " . $conn->error);
        }
        $trainer_stmt->bind_param("i", $training_id);
        $trainer_stmt->execute();
        $trainer_stmt->close();

        // 2. Dann alle Teilnehmer-Zuordnungen löschen
        $stmt = $conn->prepare("DELETE FROM employee_training WHERE training_id = ?");
        if (!$stmt) {
            throw new Exception("Fehler bei der Vorbereitung des DELETE-Statements für Mitarbeiterzuordnungen: " . $conn->error);
        }
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $stmt->close();

        // 3. Zuletzt das Training selbst löschen
        $stmt = $conn->prepare("DELETE FROM trainings WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Fehler bei der Vorbereitung des DELETE-Statements für Trainings: " . $conn->error);
        }
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        if ($affected_rows === 0) {
            throw new Exception("Das Training mit der ID $training_id existiert nicht oder konnte nicht gelöscht werden.");
        }

        // Erfolgsmeldung
        $_SESSION['result_message'] = 'Das Training wurde erfolgreich gelöscht.';
        $_SESSION['result_type'] = 'success';
    }
    // Modus 2: Löschen nur einer Mitarbeiter-Training-Zuordnung
    else {
        if (!$employee_id) {
            throw new Exception("Fehler: Mitarbeiter-ID fehlt für das Löschen einer spezifischen Zuordnung.");
        }

        // 1. Zuordnung zwischen Training und Mitarbeiter löschen
        $stmt = $conn->prepare("DELETE FROM employee_training WHERE training_id = ? AND employee_id = ?");
        if (!$stmt) {
            throw new Exception("Fehler bei der Vorbereitung des DELETE-Statements für die Mitarbeiterzuordnung: " . $conn->error);
        }
        $stmt->bind_param("ii", $training_id, $employee_id);
        $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        if ($affected_rows === 0) {
            throw new Exception("Die Zuordnung zwischen Mitarbeiter und Training konnte nicht gefunden oder gelöscht werden.");
        }

        // 2. Prüfen, ob noch weitere Zuordnungen für dieses Training existieren
        $stmt = $conn->prepare("SELECT COUNT(*) FROM employee_training WHERE training_id = ?");
        if (!$stmt) {
            throw new Exception("Fehler bei der Abfrage der verbleibenden Zuordnungen: " . $conn->error);
        }
        $stmt->bind_param("i", $training_id);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        // 3. Falls keine weiteren Zuordnungen existieren, das Training selbst und Trainer-Zuordnungen löschen
        if ($count === 0) {
            // Trainer-Zuordnungen löschen
            $trainer_stmt = $conn->prepare("DELETE FROM training_trainers WHERE training_id = ?");
            if ($trainer_stmt) {
                $trainer_stmt->bind_param("i", $training_id);
                $trainer_stmt->execute();
                $trainer_stmt->close();
            }

            // Training löschen
            $stmt = $conn->prepare("DELETE FROM trainings WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Fehler bei der Vorbereitung des DELETE-Statements für das Training: " . $conn->error);
            }
            $stmt->bind_param("i", $training_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['result_message'] = 'Die Zuordnung wurde gelöscht. Da keine weiteren Mitarbeiter zugeordnet waren, wurde das Training ebenfalls gelöscht.';
        } else {
            $_SESSION['result_message'] = 'Die Zuordnung wurde erfolgreich gelöscht. Das Training existiert weiterhin für andere Mitarbeiter.';
        }
        $_SESSION['result_type'] = 'success';
    }

    // Transaktion bestätigen
    $conn->commit();

    // Weiterleitung nach erfolgreicher Ausführung
    if ($employee_id) {
        header("Location: employee_details.php?employee_id=" . $employee_id);
    } else {
        header("Location: trainings.php");
    }
    exit;
} catch (Exception $e) {
    // Bei Fehlern: Transaktion zurückrollen
    $conn->rollback();

    // Fehlermeldung speichern
    $_SESSION['result_message'] = 'Fehler beim Löschen: ' . $e->getMessage();
    $_SESSION['result_type'] = 'error';

    // Weiterleitung mit Fehlermeldung
    if ($employee_id) {
        header("Location: employee_details.php?employee_id=" . $employee_id);
    } else {
        header("Location: trainings.php");
    }
    exit;
}
?>