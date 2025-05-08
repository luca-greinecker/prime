<?php
/**
 * save_training.php
 *
 * Speichert ein neues Training in der Datenbank und weist bei Bedarf Mitarbeiter zu.
 * Wird aufgerufen von trainings.php.
 */

include 'access_control.php';
include 'training_functions.php'; // Neue Funktionen einbinden
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_trainings_zugriff(); // Zugriff nur für Admin/HR

// Session starten, falls noch nicht geschehen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prüfen, ob alle Pflichtfelder vorhanden sind
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['main_category_id']) ||
        !isset($_POST['sub_category_id']) ||
        !isset($_POST['training_name']) ||
        !isset($_POST['start_date']) ||
        !isset($_POST['end_date']) ||
        !isset($_POST['training_units'])) {

        $_SESSION['result_message'] = 'Bitte füllen Sie alle Pflichtfelder aus.';
        $_SESSION['result_type'] = 'error';
        header("Location: trainings.php");
        exit;
    }

    // Formulardaten einlesen
    $main_category_id = $_POST['main_category_id'];
    $sub_category_id = $_POST['sub_category_id'];
    $training_name = $_POST['training_name'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $training_units = $_POST['training_units'];

    // Für Technical Trainings (Kategorie 03) Trainer validieren
    if ($main_category_id == 3) {
        if (!isset($_POST['trainer_ids']) || !is_array($_POST['trainer_ids']) || empty($_POST['trainer_ids'])) {
            $_SESSION['result_message'] = 'Für Technical Trainings muss mindestens ein Trainer angegeben werden.';
            $_SESSION['result_type'] = 'error';
            header("Location: trainings.php");
            exit;
        }
    }

    // Hauptkategorie-Code abrufen
    $main_cat_code = getMainCategoryCode($main_category_id, $conn);

    // Unterkategorie-Code abrufen
    $sub_cat_code = getSubCategoryCode($sub_category_id, $conn);

    if (!$main_cat_code) {
        $_SESSION['result_message'] = 'Fehler: Ungültige Hauptkategorie.';
        $_SESSION['result_type'] = 'error';
        header("Location: trainings.php");
        exit;
    }

    if (!$sub_cat_code) {
        $_SESSION['result_message'] = 'Fehler: Ungültige Unterkategorie.';
        $_SESSION['result_type'] = 'error';
        header("Location: trainings.php");
        exit;
    }

    // Display ID generieren (YY-XX-YY-NNNN) - Format: Jahr-Hauptkategorie-Unterkategorie-NNN
    $display_id = generateDisplayId($main_cat_code, $sub_cat_code, $end_date, $conn);

    // Benutzer ID abrufen
    $user_id = $_SESSION['mitarbeiter_id'];

    // Training in der Datenbank speichern
    $stmt = $conn->prepare("
        INSERT INTO trainings (
            display_id, main_category_id, sub_category_id, training_name, 
            start_date, end_date, training_units, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        $_SESSION['result_message'] = 'Fehler beim Vorbereiten der Abfrage: ' . htmlspecialchars($conn->error);
        $_SESSION['result_type'] = 'error';
        header("Location: trainings.php");
        exit;
    }

    $stmt->bind_param("siisssdi", $display_id, $main_category_id, $sub_category_id,
        $training_name, $start_date, $end_date, $training_units, $user_id);

    if (!$stmt->execute()) {
        $_SESSION['result_message'] = 'Fehler beim Speichern der Weiterbildung: ' . htmlspecialchars($stmt->error);
        $_SESSION['result_type'] = 'error';
        header("Location: trainings.php");
        exit;
    }

    $training_id = $conn->insert_id;
    $stmt->close();

    // Teilnehmer hinzufügen, falls vorhanden
    $participants_added = 0;
    if (isset($_POST['employee_ids']) && is_array($_POST['employee_ids']) && !empty($_POST['employee_ids'])) {
        $employee_ids = $_POST['employee_ids'];

        // Vorbereiten des Prepared Statements für Masseneinfügung
        $employee_values = [];
        $employee_types = '';

        foreach ($employee_ids as $employee_id) {
            $employee_values[] = $employee_id;
            $employee_values[] = $training_id;
            $employee_types .= 'ii';
        }

        // Platzhalter für die Einfügung erstellen
        $placeholders = implode(',', array_fill(0, count($employee_ids), '(?,?)'));

        $employee_stmt = $conn->prepare("
            INSERT INTO employee_training (employee_id, training_id)
            VALUES $placeholders
        ");

        if (!$employee_stmt) {
            // Trotzdem weitermachen, da das Training bereits gespeichert wurde
            $_SESSION['result_message'] = 'Weiterbildung wurde gespeichert, aber es gab Probleme beim Hinzufügen der Teilnehmer: ' .
                htmlspecialchars($conn->error);
            $_SESSION['result_type'] = 'warning';
            header("Location: trainings.php?success=1");
            exit;
        }

        $employee_stmt->bind_param($employee_types, ...$employee_values);

        if (!$employee_stmt->execute()) {
            $_SESSION['result_message'] = 'Weiterbildung wurde gespeichert, aber es gab Probleme beim Hinzufügen der Teilnehmer: ' .
                htmlspecialchars($employee_stmt->error);
            $_SESSION['result_type'] = 'warning';
        } else {
            $participants_added = count($employee_ids);
        }

        $employee_stmt->close();
    }

    // Für Technical Trainings (Kategorie 03) Trainer speichern
    $trainers_added = 0;
    if ($main_category_id == 3 && isset($_POST['trainer_ids']) && is_array($_POST['trainer_ids']) && !empty($_POST['trainer_ids'])) {
        $trainer_ids = $_POST['trainer_ids'];

        // Vorbereiten des Prepared Statements für Trainer
        $values = [];
        $types = '';

        foreach ($trainer_ids as $trainer_id) {
            $values[] = $training_id;
            $values[] = $trainer_id;
            $types .= 'ii';
        }

        // Platzhalter für die Einfügung erstellen
        $placeholders = implode(',', array_fill(0, count($trainer_ids), '(?,?)'));

        $trainer_stmt = $conn->prepare("
            INSERT INTO training_trainers (training_id, trainer_id)
            VALUES $placeholders
        ");

        if (!$trainer_stmt) {
            $_SESSION['result_message'] = 'Weiterbildung wurde gespeichert, aber es gab Probleme beim Hinzufügen der Trainer: ' .
                htmlspecialchars($conn->error);
            $_SESSION['result_type'] = 'warning';
        } else {
            $trainer_stmt->bind_param($types, ...$values);

            if (!$trainer_stmt->execute()) {
                $_SESSION['result_message'] = 'Weiterbildung wurde gespeichert, aber es gab Probleme beim Hinzufügen der Trainer: ' .
                    htmlspecialchars($trainer_stmt->error);
                $_SESSION['result_type'] = 'warning';
            } else {
                $trainers_added = count($trainer_ids);
            }

            $trainer_stmt->close();
        }
    }

    // Erfolgsmeldung zusammenstellen
    $success_message = 'Weiterbildung erfolgreich gespeichert';

    if ($participants_added > 0) {
        $success_message .= " mit $participants_added Teilnehmern";
    } else {
        $success_message .= ". Keine Teilnehmer hinzugefügt";
    }

    if ($main_category_id == 3 && $trainers_added > 0) {
        $success_message .= " und $trainers_added Trainern";
    }

    $success_message .= '.';

    $_SESSION['result_message'] = $success_message;
    $_SESSION['result_type'] = 'success';

    // Zurück zur Übersicht mit Erfolgsmeldung
    header("Location: trainings.php");
    exit;
} else {
    // Kein POST-Request, zurück zur Übersicht
    header("Location: trainings.php");
    exit;
}
?>