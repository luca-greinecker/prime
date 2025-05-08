<?php
/**
 * edit_training.php
 *
 * Ermöglicht das Bearbeiten eines bestehenden Trainings, inkl. Hinzufügen oder Entfernen von Teilnehmern.
 * Verwendet den Primärschlüssel `id` in der Tabelle `trainings` sowie `employee_id` in der Tabelle `employees`.
 */

include 'access_control.php';
include 'training_functions.php'; // Neue Funktionen einbinden
global $conn;
pruefe_benutzer_eingeloggt();

// Session starten, falls noch nicht geschehen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ergebnis-Variablen für das Modal
$result_message = '';
$result_type = '';

// Wenn das Formular abgeschickt wurde (POST), werden Änderungen am Training oder Teilnehmern vorgenommen
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Training bearbeiten
    if (isset($_POST['update_training'])) {
        $id = $_POST['id'];
        $original_main_category_id = $_POST['original_main_category_id'];
        $original_sub_category_id = $_POST['original_sub_category_id'];
        $main_category_id = $_POST['main_category_id'];
        $sub_category_id = $_POST['sub_category_id'];
        $training_name = $_POST['training_name'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $training_units = $_POST['training_units'];

        // Für Technical Trainings (Kategorie 03) prüfen, ob Trainer angegeben wurden
        if ($main_category_id == 3 && (!isset($_POST['trainer_ids']) || !is_array($_POST['trainer_ids']) || empty($_POST['trainer_ids']))) {
            $_SESSION['result_message'] = 'Für Technical Trainings muss mindestens ein Trainer angegeben werden.';
            $_SESSION['result_type'] = 'error';
            header("Location: edit_training.php?id=" . urlencode($id));
            exit;
        }

        // Prüfen, ob Kategorie oder Unterkategorie geändert wurden
        $regenerate_id = ($original_main_category_id != $main_category_id || $original_sub_category_id != $sub_category_id);
        $new_display_id = null;

        // Bei Kategorieänderung neue ID generieren
        if ($regenerate_id) {
            // Kategorie-Codes abrufen
            $main_cat_code = getMainCategoryCode($main_category_id, $conn);
            $sub_cat_code = getSubCategoryCode($sub_category_id, $conn);

            if (!$main_cat_code || !$sub_cat_code) {
                $_SESSION['result_message'] = 'Fehler: Kategoriecode nicht gefunden. Bitte stellen Sie sicher, dass sowohl die Haupt- als auch die Unterkategorie einen gültigen Code haben.';
                $_SESSION['result_type'] = 'error';
                header("Location: edit_training.php?id=" . urlencode($id));
                exit;
            }

            // Neue Display-ID generieren (basierend auf Enddatum)
            $new_display_id = generateDisplayId($main_cat_code, $sub_cat_code, $end_date, $conn);
        }

        // Training aktualisieren
        if ($regenerate_id) {
            // Mit neuer ID aktualisieren
            $stmt = $conn->prepare("
                UPDATE trainings
                SET main_category_id = ?, sub_category_id = ?, training_name = ?, 
                    start_date = ?, end_date = ?, training_units = ?, display_id = ?
                WHERE id = ?
            ");
            if (!$stmt) {
                $_SESSION['result_message'] = 'Fehler beim Aktualisieren der Weiterbildung: ' . htmlspecialchars($conn->error);
                $_SESSION['result_type'] = 'error';
            } else {
                $stmt->bind_param("iisssdsi", $main_category_id, $sub_category_id, $training_name,
                    $start_date, $end_date, $training_units, $new_display_id, $id);

                if ($stmt->execute()) {
                    $_SESSION['result_message'] = 'Weiterbildung erfolgreich aktualisiert! Die Display-ID wurde geändert. WICHTIG: Falls Dateien mit der alten ID verknüpft sind, müssen diese umbenannt werden.';
                    $_SESSION['result_type'] = 'warning';
                } else {
                    $_SESSION['result_message'] = 'Fehler beim Aktualisieren der Weiterbildung: ' . htmlspecialchars($stmt->error);
                    $_SESSION['result_type'] = 'error';
                }
                $stmt->close();
            }
        } else {
            // Ohne ID-Änderung aktualisieren
            $stmt = $conn->prepare("
                UPDATE trainings
                SET main_category_id = ?, sub_category_id = ?, training_name = ?, 
                    start_date = ?, end_date = ?, training_units = ?
                WHERE id = ?
            ");
            if (!$stmt) {
                $_SESSION['result_message'] = 'Fehler beim Aktualisieren der Weiterbildung: ' . htmlspecialchars($conn->error);
                $_SESSION['result_type'] = 'error';
            } else {
                $stmt->bind_param("iisssdi", $main_category_id, $sub_category_id, $training_name,
                    $start_date, $end_date, $training_units, $id);

                if ($stmt->execute()) {
                    $_SESSION['result_message'] = 'Weiterbildung erfolgreich aktualisiert!';
                    $_SESSION['result_type'] = 'success';
                } else {
                    $_SESSION['result_message'] = 'Fehler beim Aktualisieren der Weiterbildung: ' . htmlspecialchars($stmt->error);
                    $_SESSION['result_type'] = 'error';
                }
                $stmt->close();
            }
        }

        // Trainer aktualisieren, falls es ein Technical Training ist (Hauptkategorie 03)
        if ($main_category_id == 3 && isset($_POST['trainer_ids']) && is_array($_POST['trainer_ids'])) {
            // Zuerst alle vorhandenen Trainer für dieses Training löschen
            $delete_stmt = $conn->prepare("DELETE FROM training_trainers WHERE training_id = ?");
            $delete_stmt->bind_param("i", $id);
            $delete_stmt->execute();
            $delete_stmt->close();

            // Dann neue Trainer hinzufügen
            $trainer_ids = $_POST['trainer_ids'];

            // Vorbereiten des Prepared Statements für Trainer
            $values = [];
            $types = '';

            foreach ($trainer_ids as $trainer_id) {
                $values[] = $id;  // training_id
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
                $_SESSION['result_message'] = 'Weiterbildung wurde aktualisiert, aber es gab Probleme beim Aktualisieren der Trainer: ' .
                    htmlspecialchars($conn->error);
                $_SESSION['result_type'] = 'warning';
            } else {
                $trainer_stmt->bind_param($types, ...$values);

                if (!$trainer_stmt->execute()) {
                    $_SESSION['result_message'] = 'Weiterbildung wurde aktualisiert, aber es gab Probleme beim Aktualisieren der Trainer: ' .
                        htmlspecialchars($trainer_stmt->error);
                    $_SESSION['result_type'] = 'warning';
                }

                $trainer_stmt->close();
            }
        }

        // Teilnehmer hinzufügen
    } elseif (isset($_POST['add_participant'])) {
        $training_id = $_POST['id'];
        $new_employee_id = $_POST['new_employee'];

        if (empty($new_employee_id)) {
            $_SESSION['result_message'] = 'Bitte wählen Sie einen Mitarbeiter aus.';
            $_SESSION['result_type'] = 'error';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO employee_training (employee_id, training_id)
                VALUES (?, ?)
            ");
            if (!$stmt) {
                $_SESSION['result_message'] = 'Fehler beim Hinzufügen des Teilnehmers: ' . htmlspecialchars($conn->error);
                $_SESSION['result_type'] = 'error';
            } else {
                $stmt->bind_param("ii", $new_employee_id, $training_id);

                if ($stmt->execute()) {
                    $_SESSION['result_message'] = 'Teilnehmer erfolgreich hinzugefügt!';
                    $_SESSION['result_type'] = 'success';
                } else {
                    $_SESSION['result_message'] = 'Fehler beim Hinzufügen des Teilnehmers: ' . htmlspecialchars($stmt->error);
                    $_SESSION['result_type'] = 'error';
                }
                $stmt->close();
            }
        }

        // Teilnehmer entfernen
    } elseif (isset($_POST['remove_participant'])) {
        $training_id = $_POST['id'];
        $participant_id_to_remove = $_POST['participant_id'];

        $stmt = $conn->prepare("
            DELETE FROM employee_training
            WHERE employee_id = ? AND training_id = ?
        ");
        if (!$stmt) {
            $_SESSION['result_message'] = 'Fehler beim Entfernen des Teilnehmers: ' . htmlspecialchars($conn->error);
            $_SESSION['result_type'] = 'error';
        } else {
            $stmt->bind_param("ii", $participant_id_to_remove, $training_id);

            if ($stmt->execute()) {
                $_SESSION['result_message'] = 'Teilnehmer erfolgreich entfernt!';
                $_SESSION['result_type'] = 'success';
            } else {
                $_SESSION['result_message'] = 'Fehler beim Entfernen des Teilnehmers: ' . htmlspecialchars($stmt->error);
                $_SESSION['result_type'] = 'error';
            }
            $stmt->close();
        }
    } // Trainer hinzufügen
    elseif (isset($_POST['add_trainer'])) {
        $training_id = $_POST['id'];
        $new_trainer_id = $_POST['new_trainer'];

        if (empty($new_trainer_id)) {
            $_SESSION['result_message'] = 'Bitte wählen Sie einen Trainer aus.';
            $_SESSION['result_type'] = 'error';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO training_trainers (training_id, trainer_id)
                VALUES (?, ?)
            ");
            if (!$stmt) {
                $_SESSION['result_message'] = 'Fehler beim Hinzufügen des Trainers: ' . htmlspecialchars($conn->error);
                $_SESSION['result_type'] = 'error';
            } else {
                $stmt->bind_param("ii", $training_id, $new_trainer_id);

                if ($stmt->execute()) {
                    $_SESSION['result_message'] = 'Trainer erfolgreich hinzugefügt!';
                    $_SESSION['result_type'] = 'success';
                } else {
                    $_SESSION['result_message'] = 'Fehler beim Hinzufügen des Trainers: ' . htmlspecialchars($stmt->error);
                    $_SESSION['result_type'] = 'error';
                }
                $stmt->close();
            }
        }
    } // Trainer entfernen
    elseif (isset($_POST['remove_trainer'])) {
        $training_id = $_POST['id'];
        $trainer_id_to_remove = $_POST['trainer_id'];

        $stmt = $conn->prepare("
            DELETE FROM training_trainers
            WHERE trainer_id = ? AND training_id = ?
        ");
        if (!$stmt) {
            $_SESSION['result_message'] = 'Fehler beim Entfernen des Trainers: ' . htmlspecialchars($conn->error);
            $_SESSION['result_type'] = 'error';
        } else {
            $stmt->bind_param("ii", $trainer_id_to_remove, $training_id);

            if ($stmt->execute()) {
                $_SESSION['result_message'] = 'Trainer erfolgreich entfernt!';
                $_SESSION['result_type'] = 'success';
            } else {
                $_SESSION['result_message'] = 'Fehler beim Entfernen des Trainers: ' . htmlspecialchars($stmt->error);
                $_SESSION['result_type'] = 'error';
            }
            $stmt->close();
        }
    }

    // Nach Verarbeitung zurück zur selben Seite
    header("Location: edit_training.php?id=" . urlencode($_POST['id']));
    exit;
}

// GET-Parameter: id des Trainings
$id = $_GET['id'] ?? null;
if (!$id) {
    echo '<p>Keine Trainings-ID übergeben.</p>';
    exit;
}

// Alle aktiven Hauptkategorien laden
$main_categories = getActiveMainCategories($conn);

// Trainingsinformationen abrufen
$stmt = $conn->prepare("
    SELECT display_id, main_category_id, sub_category_id, training_name, start_date, end_date, training_units
    FROM trainings
    WHERE id = ?
");
if (!$stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<p>Keine Daten gefunden.</p>';
    exit;
}
$row = $result->fetch_assoc();
$stmt->close();

// Alle Unterkategorien laden (auch inaktive, falls das Training eine inaktive Kategorie verwendet)
$sub_categories_stmt = $conn->prepare("
    SELECT id, main_category_id, code, name, active 
    FROM training_sub_categories 
    WHERE main_category_id = ? OR id = ?
    ORDER BY main_category_id, active DESC, name ASC
");
$sub_categories_stmt->bind_param("ii", $row['main_category_id'], $row['sub_category_id']);
$sub_categories_stmt->execute();
$sub_categories_result = $sub_categories_stmt->get_result();
$sub_categories = $sub_categories_result->fetch_all(MYSQLI_ASSOC);
$sub_categories_stmt->close();

// Teilnehmer abrufen (employee_training + employees)
$participants_stmt = $conn->prepare("
    SELECT 
        e.employee_id,
        e.name,
        e.gruppe,
        e.crew,
        e.position
    FROM employee_training et
    JOIN employees e ON et.employee_id = e.employee_id
    WHERE et.training_id = ?
");
if (!$participants_stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$participants_stmt->bind_param("i", $id);
$participants_stmt->execute();
$participants_result = $participants_stmt->get_result();

$participants = [];
$participant_ids = [];  // IDs der bereits zugeordneten Teilnehmer
while ($participant = $participants_result->fetch_assoc()) {
    $participants[] = $participant;
    $participant_ids[] = $participant['employee_id'];
}
$participants_stmt->close();

// Trainer abrufen (für Technical Trainings)
$trainers = [];
$trainer_ids = []; // IDs der bereits zugeordneten Trainer

if ($row['main_category_id'] == 3) { // Für Technical Trainings (Kategorie 03)
    $trainers_stmt = $conn->prepare("
        SELECT 
            e.employee_id,
            e.name,
            e.gruppe,
            e.crew,
            e.position
        FROM training_trainers tt
        JOIN employees e ON tt.trainer_id = e.employee_id
        WHERE tt.training_id = ?
    ");

    if ($trainers_stmt) {
        $trainers_stmt->bind_param("i", $id);
        $trainers_stmt->execute();
        $trainers_result = $trainers_stmt->get_result();

        while ($trainer = $trainers_result->fetch_assoc()) {
            $trainers[] = $trainer;
            $trainer_ids[] = $trainer['employee_id'];
        }

        $trainers_stmt->close();
    }
}

// Um leere IN-Klauseln zu vermeiden
if (empty($participant_ids)) {
    // Platzhalter 0 (wird nicht existieren) -> schließt keine realen Datensätze aus
    $participant_ids[] = 0;
}

// Alle möglichen Mitarbeiter abrufen, die noch nicht Teilnehmer sind
$placeholders = implode(",", array_fill(0, count($participant_ids), "?"));
$employees_stmt = $conn->prepare("
    SELECT
        employee_id,
        name,
        gruppe,
        crew,
        position
    FROM employees
    WHERE employee_id NOT IN ($placeholders)
    ORDER BY gruppe ASC, crew ASC, name ASC
");
if (!$employees_stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$types = str_repeat('i', count($participant_ids));
$employees_stmt->bind_param($types, ...$participant_ids);
$employees_stmt->execute();
$employees_result = $employees_stmt->get_result();

// Zur Gruppierung der möglichen Mitarbeiter (Schichtarbeit, Tagschicht, Verwaltung)
$schichtarbeit = [];
$tagschicht = [];
$verwaltung = [];

while ($employee = $employees_result->fetch_assoc()) {
    // Auf Basis von e.gruppe sortieren
    if (stripos($employee['gruppe'], 'schichtarbeit') !== false) {
        $schichtarbeit[$employee['crew']][] = $employee;
    } elseif (stripos($employee['gruppe'], 'tagschicht') !== false) {
        $tagschicht[] = $employee;
    } else {
        $verwaltung[] = $employee;
    }
}
$employees_stmt->close();

// Prüfen, ob eine Ergebnismeldung vorliegt
if (isset($_SESSION['result_message'])) {
    $result_message = $_SESSION['result_message'];
    $result_type = $_SESSION['result_type'];

    // Session-Nachrichten löschen
    unset($_SESSION['result_message']);
    unset($_SESSION['result_type']);
}
?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Weiterbildung bearbeiten</title>
        <!-- Lokales Bootstrap 5 CSS + Navbar -->
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
        <style>
            .card {
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                margin-bottom: 20px;
            }

            .card-header {
                background-color: #f8f9fa;
            }

            .participant-item {
                transition: all 0.2s ease;
            }

            .participant-item:hover {
                background-color: #f8f9fa;
            }

            .action-buttons {
                display: flex;
                gap: 8px;
            }

            .readonly-input {
                background-color: #e9ecef;
            }

            .badge-role {
                margin-right: 5px;
                font-size: 0.85rem;
                padding: 0.25em 0.6em;
            }

            .badge-id {
                font-size: 1rem;
                padding: 0.3em 0.7em;
            }

            .category-notice {
                font-size: 0.85rem;
                color: #dc3545;
                margin-top: 0.5rem;
                display: none;
            }

            /* Trainer-Bereich Styles */
            .trainer-card {
                margin-top: 20px;
                margin-bottom: 20px;
            }

            .trainer-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0.5rem 1rem;
                background-color: #f8f9fa;
                border-left: 4px solid #0d6efd;
                border-radius: 4px;
                margin-bottom: 0.5rem;
                transition: all 0.2s ease;
            }

            .trainer-item:hover {
                background-color: #e9ecef;
                transform: translateX(5px);
            }

            .trainer-info {
                display: flex;
                align-items: center;
                gap: 10px;
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>

    <div class="container content">
        <h1 class="text-center mb-3">Weiterbildung bearbeiten</h1>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Trainingsinformationen</h5>
                <span class="badge bg-secondary badge-id"><?php echo htmlspecialchars($row['display_id'] ?? $id); ?></span>
            </div>
            <div class="card-body">
                <form action="edit_training.php" method="POST">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                    <input type="hidden" name="original_main_category_id"
                           value="<?php echo htmlspecialchars($row['main_category_id']); ?>">
                    <input type="hidden" name="original_sub_category_id"
                           value="<?php echo htmlspecialchars($row['sub_category_id']); ?>">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="main_category_id" class="form-label">Hauptkategorie:</label>
                            <select class="form-select" id="main_category_id" name="main_category_id" required>
                                <option value="" disabled>Bitte wählen...</option>
                                <?php foreach ($main_categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category['id']); ?>"
                                        <?php echo ($row['main_category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                        (<?php echo htmlspecialchars($category['code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="sub_category_id" class="form-label">Unterkategorie:</label>
                            <select class="form-select" id="sub_category_id" name="sub_category_id" required>
                                <option value="" disabled>Bitte wählen...</option>
                                <?php foreach ($sub_categories as $sub_cat): ?>
                                    <option value="<?php echo htmlspecialchars($sub_cat['id']); ?>"
                                            data-main-category="<?php echo htmlspecialchars($sub_cat['main_category_id']); ?>"
                                        <?php echo ($row['sub_category_id'] == $sub_cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sub_cat['name']); ?>
                                        (<?php echo htmlspecialchars($sub_cat['code']); ?>)
                                        <?php if (!$sub_cat['active']): ?> (inaktiv)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="category-notice" class="category-notice alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i> Achtung: Bei Änderung der Kategorie wird eine
                        neue ID generiert. Falls Dateien mit der alten ID verknüpft sind, müssen diese umbenannt werden.
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label for="training_name" class="form-label">Name der Weiterbildung:</label>
                            <input type="text" class="form-control" id="training_name" name="training_name"
                                   value="<?php echo htmlspecialchars($row['training_name']); ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="start_date" class="form-label">Startdatum:</label>
                            <input type="date" class="form-control" id="start_date" name="start_date"
                                   value="<?php echo htmlspecialchars($row['start_date']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="end_date" class="form-label">Enddatum:</label>
                            <input type="date" class="form-control" id="end_date" name="end_date"
                                   value="<?php echo htmlspecialchars($row['end_date']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="training_units" class="form-label">Anzahl der Einheiten:</label>
                            <input type="number" step="0.1" class="form-control" id="training_units"
                                   name="training_units"
                                   value="<?php echo htmlspecialchars($row['training_units']); ?>" required>
                        </div>
                    </div>

                    <!-- Trainer-Bereich für Technical Trainings (Kategorie 03) -->
                    <div id="trainer-section" class="mt-4 mb-3"
                         style="display: <?php echo ($row['main_category_id'] == 3) ? 'block' : 'none'; ?>">
                        <div class="alert alert-info" id="trainer-info-alert">
                            <i class="bi bi-info-circle me-2"></i>
                            Für Technical Trainings (Kategorie 03) müssen Trainer hinzugefügt werden.
                        </div>

                        <div id="trainer-inputs">
                            <?php foreach ($trainer_ids as $trainer_id): ?>
                                <input type="hidden" name="trainer_ids[]" value="<?php echo $trainer_id; ?>">
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" name="update_training" class="btn btn-primary">
                            <i class="bi bi-save"></i> Speichern
                        </button>
                        <?php if (ist_hr() || ist_trainingsmanager() || ist_ehs()): ?>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal"
                                    data-bs-target="#confirmDeleteModal">
                                <i class="bi bi-trash"></i> Löschen
                            </button>
                        <?php endif; ?>
                        <a href="javascript:history.back()" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Zurück
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($row['main_category_id'] == 3): // Trainer-Liste für Technical Trainings (Kategorie 03) ?>
            <div class="card trainer-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-check me-2"></i>Trainer</h5>
                    <span class="badge bg-primary"><?php echo count($trainers); ?> Trainer</span>
                </div>
                <div class="card-body">
                    <!-- Formular zum Hinzufügen eines neuen Trainers -->
                    <form action="edit_training.php" method="POST" class="row g-3 mb-4">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                        <div class="col-md-9">
                            <label for="new_trainer" class="form-label">Trainer hinzufügen:</label>
                            <select class="form-select" id="new_trainer" name="new_trainer">
                                <option value="" disabled selected>Trainer auswählen</option>

                                <?php
                                // Alle Mitarbeiter als potenzielle Trainer anzeigen, die noch nicht Trainer dieses Trainings sind
                                $all_employees = array_merge(
                                    array_reduce($schichtarbeit, function ($carry, $crew) {
                                        return array_merge($carry, $crew);
                                    }, []),
                                    $tagschicht,
                                    $verwaltung
                                );

                                // Sortiere alphabetisch nach Namen
                                usort($all_employees, function ($a, $b) {
                                    return strcmp($a['name'], $b['name']);
                                });

                                foreach ($all_employees as $employee):
                                    // Nur anzeigen, wenn nicht bereits Trainer
                                    if (!in_array($employee['employee_id'], $trainer_ids)):
                                        ?>
                                        <option value="<?php echo htmlspecialchars($employee['employee_id']); ?>">
                                            <?php echo htmlspecialchars($employee['name']); ?>
                                            (<?php echo htmlspecialchars($employee['position']); ?>)
                                        </option>
                                    <?php
                                    endif;
                                endforeach;
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" name="add_trainer" class="btn btn-success w-100">
                                <i class="bi bi-plus-circle me-2"></i>Hinzufügen
                            </button>
                        </div>
                    </form>

                    <!-- Liste der aktuellen Trainer -->
                    <?php if (empty($trainers)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i> Keine Trainer vorhanden. Für Technical
                            Trainings muss mindestens ein Trainer hinzugefügt werden.
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($trainers as $trainer): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="trainer-item">
                                        <div class="trainer-info">
                                            <i class="bi bi-person-fill fs-5"></i>
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($trainer['name']); ?></div>
                                                <div class="small text-muted">
                                                    <?php echo htmlspecialchars($trainer['position']); ?>
                                                    (<?php echo htmlspecialchars($trainer['gruppe']); ?>
                                                    <?php if (!empty($trainer['crew'])): ?>, <?php echo htmlspecialchars($trainer['crew']); ?><?php endif; ?>
                                                    )
                                                </div>
                                            </div>
                                        </div>
                                        <form action="edit_training.php" method="POST" style="margin: 0;">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                                            <input type="hidden" name="trainer_id"
                                                   value="<?php echo htmlspecialchars($trainer['employee_id']); ?>">
                                            <button type="submit" name="remove_trainer" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Sind Sie sicher, dass Sie diesen Trainer entfernen möchten?');">
                                                <i class="bi bi-trash"></i> Entfernen
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Teilnehmer</h5>
                <span class="badge bg-primary"><?php echo count($participants); ?> Teilnehmer</span>
            </div>
            <div class="card-body">
                <!-- Formular zum Hinzufügen eines neuen Teilnehmers -->
                <form action="edit_training.php" method="POST" class="row g-3 mb-4">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                    <div class="col-md-9">
                        <label for="new_employee" class="form-label">Mitarbeiter hinzufügen:</label>
                        <select class="form-select" id="new_employee" name="new_employee">
                            <option value="" disabled selected>Mitarbeiter auswählen</option>
                            <optgroup label="Schichtarbeit">
                                <?php foreach ($schichtarbeit

                                as $crew => $employees): ?>
                            <optgroup label="<?php echo htmlspecialchars($crew); ?>">
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo htmlspecialchars($employee['employee_id']); ?>">
                                        <?php echo htmlspecialchars($employee['name']); ?>
                                        (<?php echo htmlspecialchars($employee['position']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                            </optgroup>

                            <optgroup label="Tagschicht">
                                <?php foreach ($tagschicht as $employee): ?>
                                    <option value="<?php echo htmlspecialchars($employee['employee_id']); ?>">
                                        <?php echo htmlspecialchars($employee['name']); ?>
                                        (<?php echo htmlspecialchars($employee['position']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>

                            <optgroup label="Verwaltung">
                                <?php foreach ($verwaltung as $employee): ?>
                                    <option value="<?php echo htmlspecialchars($employee['employee_id']); ?>">
                                        <?php echo htmlspecialchars($employee['name']); ?>
                                        (<?php echo htmlspecialchars($employee['position']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" name="add_participant" class="btn btn-success w-100">
                            <i class="bi bi-plus-circle"></i> Hinzufügen
                        </button>
                    </div>
                </form>

                <!-- Liste der aktuellen Teilnehmer -->
                <?php if (empty($participants)): ?>
                    <div class="alert alert-info">Keine Teilnehmer vorhanden. Fügen Sie Teilnehmer mit dem Formular oben
                        hinzu.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Gruppe</th>
                                <th>Crew</th>
                                <th>Position</th>
                                <th>Aktion</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($participants as $participant): ?>
                                <tr class="participant-item">
                                    <td><?php echo htmlspecialchars($participant['name']); ?></td>
                                    <td>
                                    <span class="badge bg-secondary badge-role">
                                        <?php echo htmlspecialchars($participant['gruppe']); ?>
                                    </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($participant['crew']); ?></td>
                                    <td><?php echo htmlspecialchars($participant['position']); ?></td>
                                    <td>
                                        <form action="edit_training.php" method="POST" style="margin: 0;">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                                            <input type="hidden" name="participant_id"
                                                   value="<?php echo htmlspecialchars($participant['employee_id']); ?>">
                                            <button type="submit" name="remove_participant"
                                                    class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Sind Sie sicher, dass Sie diesen Teilnehmer entfernen möchten?');">
                                                <i class="bi bi-trash"></i> Entfernen
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal für Erfolg oder Fehler -->
    <div class="modal fade" id="resultModal" tabindex="-1" aria-labelledby="resultModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resultModalLabel">Ergebnis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="resultMessage"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal zur Bestätigung des Löschens -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel"
         aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmDeleteModalLabel">Training löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="fw-bold">Möchten Sie das Training wirklich löschen?</p>
                    <p class="mb-0">
                        <strong>Training-ID:</strong> <?php echo htmlspecialchars($row['display_id'] ?? $id); ?><br>
                        <strong>Name:</strong> <?php echo htmlspecialchars($row['training_name']); ?><br>
                        <strong>Anzahl Teilnehmer:</strong> <?php echo count($participants); ?>
                    </p>
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        Diese Aktion kann nicht rückgängig gemacht werden. Das Training wird unwiderruflich
                        gelöscht und alle Zuordnungen zu Mitarbeitern werden entfernt.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <a href="delete_training.php?id=<?php echo htmlspecialchars($id); ?>&confirm=1<?php echo isset($_GET['employee_id']) ? '&employee_id=' . htmlspecialchars($_GET['employee_id']) : ''; ?>"
                       class="btn btn-danger">
                        Training endgültig löschen
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Lokales Bootstrap 5 JavaScript Bundle -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const originalMainCategory = <?php echo $row['main_category_id']; ?>;
            const originalSubCategory = <?php echo $row['sub_category_id']; ?>;
            const mainCategorySelect = document.getElementById('main_category_id');
            const subCategorySelect = document.getElementById('sub_category_id');
            const categoryNotice = document.getElementById('category-notice');
            const trainerSection = document.getElementById('trainer-section');
            const trainerInfoAlert = document.getElementById('trainer-info-alert');

            // Liste der ausgewählten Trainer IDs
            let selectedTrainerIds = <?php echo json_encode($trainer_ids); ?>;

            // Function to update hidden trainer inputs
            function updateTrainerInputs() {
                const container = document.getElementById('trainer-inputs');
                container.innerHTML = '';

                selectedTrainerIds.forEach(trainerId => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'trainer_ids[]';
                    input.value = trainerId;
                    container.appendChild(input);
                });
            }

            // Update Trainer Eingabefelder beim Laden
            updateTrainerInputs();

            // Überprüfen, ob sich die Kategorie geändert hat und die Warnung anzeigen
            function checkCategoryChanged() {
                const mainChanged = mainCategorySelect.value != originalMainCategory;
                const subChanged = subCategorySelect.value != originalSubCategory;
                const isTechnicalTraining = mainCategorySelect.value == 3;

                if (mainChanged || subChanged) {
                    categoryNotice.style.display = 'block';
                } else {
                    categoryNotice.style.display = 'none';
                }

                // Zeige/verstecke Trainer-Sektion basierend auf ausgewählter Hauptkategorie
                trainerSection.style.display = isTechnicalTraining ? 'block' : 'none';

                // Wenn es jetzt ein Technical Training ist, muss mindestens ein Trainer angegeben werden
                if (isTechnicalTraining && selectedTrainerIds.length === 0) {
                    trainerInfoAlert.classList.remove('alert-info');
                    trainerInfoAlert.classList.add('alert-warning');
                } else {
                    trainerInfoAlert.classList.remove('alert-warning');
                    trainerInfoAlert.classList.add('alert-info');
                }
            }

            // Unterkategorien laden, wenn die Hauptkategorie geändert wird
            mainCategorySelect.addEventListener('change', function () {
                const selectedMainCategory = this.value;

                // Bestehende Unterkategorien entfernen
                while (subCategorySelect.firstChild) {
                    subCategorySelect.removeChild(subCategorySelect.firstChild);
                }

                // Standardoption hinzufügen
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = 'Bitte wählen...';
                defaultOption.disabled = true;
                defaultOption.selected = true;
                subCategorySelect.appendChild(defaultOption);

                // Unterkategorien via AJAX laden
                if (selectedMainCategory) {
                    fetch(`get_subcategories.php?main_category_id=${selectedMainCategory}`)
                        .then(response => response.json())
                        .then(data => {
                            console.log("Received data:", data); // Debug-Ausgabe
                            if (data && data.length > 0) {
                                data.forEach(subCat => {
                                    const option = document.createElement('option');
                                    option.value = subCat.id;
                                    // Sicherstellen, dass der Code-Wert existiert
                                    const codeDisplay = subCat.code ? ` (${subCat.code})` : '';
                                    option.textContent = `${subCat.name}${codeDisplay}`;
                                    subCategorySelect.appendChild(option);
                                });
                            } else {
                                // Keine Unterkategorien gefunden
                                const emptyOption = document.createElement('option');
                                emptyOption.disabled = true;
                                emptyOption.textContent = 'Keine Unterkategorien verfügbar';
                                subCategorySelect.appendChild(emptyOption);
                            }
                            // Nach dem Laden Änderung prüfen
                            checkCategoryChanged();
                        })
                        .catch(error => {
                            console.error('Fehler beim Laden der Unterkategorien:', error);
                            const errorOption = document.createElement('option');
                            errorOption.disabled = true;
                            errorOption.textContent = 'Fehler beim Laden der Unterkategorien';
                            subCategorySelect.appendChild(errorOption);
                        });
                }

                // Nach der Änderung prüfen
                checkCategoryChanged();
            });

            // Auch bei Änderung der Unterkategorie prüfen
            subCategorySelect.addEventListener('change', checkCategoryChanged);

            // Führe Kategorieprüfung beim Laden durch
            checkCategoryChanged();

            // Datums-Validierung: Enddatum muss nach oder gleich Startdatum sein
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');

            function validateDates() {
                if (startDateInput.value && endDateInput.value) {
                    if (new Date(endDateInput.value) < new Date(startDateInput.value)) {
                        endDateInput.setCustomValidity('Das Enddatum muss nach dem Startdatum liegen');
                    } else {
                        endDateInput.setCustomValidity('');
                    }
                }
            }

            startDateInput.addEventListener('change', validateDates);
            endDateInput.addEventListener('change', validateDates);

            // Form-Validierung vor dem Absenden
            document.querySelector('form[name="update_training"]').addEventListener('submit', function (e) {
                const isTechnicalTraining = mainCategorySelect.value == 3;

                // Wenn Technical Training, aber keine Trainer ausgewählt
                if (isTechnicalTraining && selectedTrainerIds.length === 0) {
                    e.preventDefault();
                    alert('Für Technical Trainings muss mindestens ein Trainer angegeben werden.');
                }
            });

            // Bestätigungsmeldung oder Fehlermeldung anzeigen
            <?php if (!empty($result_message)): ?>
            const resultModal = new bootstrap.Modal(document.getElementById('resultModal'));
            const resultMessage = document.getElementById('resultMessage');

            <?php if ($result_type === 'success'): ?>
            resultMessage.innerHTML = '<div class="alert alert-success"><?php echo addslashes($result_message); ?></div>';
            <?php elseif ($result_type === 'warning'): ?>
            resultMessage.innerHTML = '<div class="alert alert-warning"><?php echo addslashes($result_message); ?></div>';
            <?php else: ?>
            resultMessage.innerHTML = '<div class="alert alert-danger"><?php echo addslashes($result_message); ?></div>';
            <?php endif; ?>

            resultModal.show();
            <?php endif; ?>
        });
    </script>
    </body>
    </html>

<?php
$conn->close();
?>