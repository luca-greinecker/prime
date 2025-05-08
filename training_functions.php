<?php
/**
 * training_functions.php
 *
 * Zentrale Funktionen für die Trainings-Verwaltung, die in verschiedenen Dateien verwendet werden.
 * Enthält Logik für ID-Generierung, Kategoriezugriff und andere wiederverwendbare Funktionalitäten.
 */

/**
 * Generiert eine formatierte Display-ID für ein neues Training im Format YY-XX-YY-NNNN
 * Verwendet Transaktionen, um die Eindeutigkeit auch bei gleichzeitigen Zugriffen zu garantieren
 *
 * @param string $main_cat_code Der 2-stellige Code der Hauptkategorie (z.B. "01")
 * @param string $sub_cat_code Der 2-stellige Code der Unterkategorie (z.B. "02")
 * @param string $end_date Das Enddatum des Trainings im Format YYYY-MM-DD
 * @param object $conn Die Datenbankverbindung
 * @return string Die generierte Display-ID
 */
function generateDisplayId($main_cat_code, $sub_cat_code, $end_date, $conn) {
    // Transaktion starten, um Race Conditions zu vermeiden
    $conn->begin_transaction();
    try {
        // Format: YY-XX-YY-NNNN (z.B. 25-01-02-0001)
        $year = date('y', strtotime($end_date)); // z.B. "25" für 2025
        $pattern = "$year-$main_cat_code-$sub_cat_code-%";

        // FOR UPDATE sperrt die betroffenen Zeilen während der Abfrage
        $stmt = $conn->prepare("
            SELECT COALESCE(
                MAX(CAST(SUBSTRING_INDEX(display_id, '-', -1) AS UNSIGNED)) + 1,
                1
            ) AS next_id
            FROM trainings
            WHERE display_id LIKE ?
            FOR UPDATE
        ");
        $stmt->bind_param("s", $pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $next_id = $row['next_id'];
        $stmt->close();

        // Display ID formatieren (z.B. 25-01-02-0001)
        $display_id = sprintf("%s-%s-%s-%04d", $year, $main_cat_code, $sub_cat_code, $next_id);

        // Transaktion bestätigen
        $conn->commit();
        return $display_id;
    } catch (Exception $e) {
        // Bei Fehlern Transaktion zurückrollen
        $conn->rollback();
        throw $e;
    }
}

/**
 * Lädt alle aktiven Hauptkategorien aus der Datenbank
 *
 * @param object $conn Die Datenbankverbindung
 * @return array Array mit den Hauptkategorien
 */
function getActiveMainCategories($conn) {
    $stmt = $conn->prepare("
        SELECT id, name, code 
        FROM training_main_categories 
        WHERE active = TRUE
        ORDER BY code ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $categories = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $categories;
}

/**
 * Lädt alle aktiven Unterkategorien für eine bestimmte Hauptkategorie
 *
 * @param int $main_category_id ID der Hauptkategorie
 * @param object $conn Die Datenbankverbindung
 * @return array Array mit den Unterkategorien
 */
function getActiveSubCategories($main_category_id, $conn) {
    $stmt = $conn->prepare("
        SELECT id, name 
        FROM training_sub_categories 
        WHERE main_category_id = ? AND active = TRUE
        ORDER BY name ASC
    ");
    $stmt->bind_param("i", $main_category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $categories = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $categories;
}

/**
 * Lädt alle aktiven Unterkategorien, gruppiert nach Hauptkategorie
 *
 * @param object $conn Die Datenbankverbindung
 * @return array Array mit Unterkategorien, gruppiert nach Hauptkategorie-ID
 */
function getAllActiveSubCategoriesGrouped($conn) {
    $stmt = $conn->prepare("
        SELECT id, main_category_id, name 
        FROM training_sub_categories 
        WHERE active = TRUE
        ORDER BY main_category_id, name ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $grouped_categories = [];
    while ($row = $result->fetch_assoc()) {
        $grouped_categories[$row['main_category_id']][] = $row;
    }
    $stmt->close();

    return $grouped_categories;
}

/**
 * Holt den Code einer Hauptkategorie anhand ihrer ID
 *
 * @param int $main_category_id ID der Hauptkategorie
 * @param object $conn Die Datenbankverbindung
 * @return string Der Code der Hauptkategorie oder NULL wenn nicht gefunden
 */
function getMainCategoryCode($main_category_id, $conn) {
    $stmt = $conn->prepare("
        SELECT code 
        FROM training_main_categories 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $main_category_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return null;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['code'];
}

/**
 * Holt den Code einer Unterkategorie anhand ihrer ID
 *
 * @param int $sub_category_id ID der Unterkategorie
 * @param object $conn Die Datenbankverbindung
 * @return string Der Code der Unterkategorie oder NULL wenn nicht gefunden
 */
function getSubCategoryCode($sub_category_id, $conn) {
    $stmt = $conn->prepare("
        SELECT code 
        FROM training_sub_categories 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $sub_category_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return null;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['code'];
}

/**
 * Holt die Trainer für ein bestimmtes Training
 *
 * @param int $training_id ID des Trainings
 * @param object $conn Die Datenbankverbindung
 * @return array Array mit Trainer-Informationen
 */
function getTrainersForTraining($training_id, $conn) {
    $trainers = [];

    $stmt = $conn->prepare("
        SELECT 
            e.employee_id,
            e.name,
            e.gruppe,
            e.crew,
            e.position,
            e.bild
        FROM training_trainers tt
        JOIN employees e ON tt.trainer_id = e.employee_id
        WHERE tt.training_id = ?
        ORDER BY e.name ASC
    ");

    if (!$stmt) {
        return $trainers;
    }

    $stmt->bind_param("i", $training_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($trainer = $result->fetch_assoc()) {
        $trainers[] = $trainer;
    }

    $stmt->close();

    return $trainers;
}

/**
 * Prüft, ob ein Training mindestens einen Trainer hat
 *
 * @param int $training_id ID des Trainings
 * @param object $conn Die Datenbankverbindung
 * @return bool True, wenn das Training mindestens einen Trainer hat, sonst False
 */
function hasTrainers($training_id, $conn) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM training_trainers
        WHERE training_id = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $training_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row['count'] > 0;
}
?>