<?php
/**
 * team_definitions.php
 *
 * Automatisch generiert von settings_bereichsgruppen.php am 12.03.2025 13:39:24
 * Enthält die Definitionen für Teams, Bereichsgruppen und Positionen.
 * Diese Datei wird von mehreren Seiten verwendet, die Mitarbeiterstrukturen anzeigen.
 */

// Teams definieren
$teams = array(
    0 => 'Team L',
    1 => 'Team M',
    2 => 'Team N',
    3 => 'Team O',
    4 => 'Team P',
);
$additional_groups = array(
    0 => 'Tagschicht',
    1 => 'Verwaltung',
);

// Reihenfolge der Bereichsgruppen für die Anzeige (Teams/Schicht)
$bereichsgruppen_order = array(
    0 => 'Schichtmeister',
    1 => 'Frontend',
    2 => 'Druckmaschine',
    3 => 'ISA/Necker',
    4 => 'Pal/MGA',
    5 => 'QS',
    6 => 'Technik',
    7 => 'CPO',
);

// Reihenfolge der Bereichsgruppen für die Tagschicht
$tagschicht_bereichsgruppen_order = array(
    0 => 'Elektrik',
    1 => 'Mechanik',
    2 => 'CPO',
);

// Bereichsgruppen für Teams (Schicht)
$bereichsgruppen = array(
    "Schichtmeister" => array(
        0 => 'Schichtmeister',
        1 => 'Schichtmeister - Stv.',
    ),
    "Frontend" => array(
        0 => 'Frontend',
        1 => 'Frontend - TL',
    ),
    "Druckmaschine" => array(
        0 => 'Druckmaschine',
        1 => 'Druckmaschine - TL',
    ),
    "ISA/Necker" => array(
        0 => 'ISA/Necker',
    ),
    "Pal/MGA" => array(
        0 => 'Palletierer/MGA',
        1 => 'Palletierer/MGA - TL',
    ),
    "QS" => array(
        0 => 'Qualitätssicherung',
    ),
    "Technik" => array(
        0 => 'Schicht - Elektrik',
        1 => 'Schicht - Mechanik',
    ),
    "CPO" => array(
        0 => 'Schicht - CPO',
    ),
);

// Bereichsgruppen für die Tagschicht
$tagschicht_bereichsgruppen = array(
    "Elektrik" => array(
        0 => 'Tagschicht - Elektrik',
        1 => 'Tagschicht - Elektrik Lehrling',
        2 => 'Tagschicht - Elektrik Spezialist',
        3 => 'Tagschicht - Elektrik | AL',
        4 => 'Tagschicht - Elektrik | AL Stv.',
    ),
    "Mechanik" => array(
        0 => 'Tagschicht - Mechanik',
        1 => 'Tagschicht - Mechanik BE',
        2 => 'Tagschicht - Mechanik FE',
        3 => 'Tagschicht - Mechanik Lehrling',
        4 => 'Tagschicht - Mechanik Tool & Die',
        5 => 'Tagschicht - Mechanik | AL',
        6 => 'Tagschicht - Mechanik | TL BE',
        7 => 'Tagschicht - Mechanik | TL FE',
        8 => 'Tagschicht - Produktion Spezialist',
    ),
    "CPO" => array(
        0 => 'Tagschicht - CPO',
        1 => 'Tagschicht - CPO Lehrling',
        2 => 'Tagschicht - CPO | AL',
        3 => 'Tagschicht - CPO | AL Stv.',
    ),
);

/**
 * Hilfsfunktion: Erstellt eine Mitarbeiter-Bereichsgruppe
 */
function render_employee_group($conn, $group_name, $positionen, $field, $field_value, $group_class = '')
{
    if (empty($group_class)) {
        $group_class = strtolower(str_replace('/', '-', $group_name));
    }

    $placeholders = implode(',', array_fill(0, count($positionen), '?'));
    $types = str_repeat('s', count($positionen) + 1);
    $params = array_merge(array($field_value), $positionen);

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $stmt = $conn->prepare("
        SELECT employee_id, name, anwesend, position 
        FROM employees 
        WHERE $field = ? AND position IN ($placeholders) AND status != 9999 
        ORDER BY 
            CASE 
                WHEN position = 'Schichtmeister' THEN -1
                WHEN position LIKE '% AL%' AND position NOT LIKE '%Spezialist%' THEN 0
                WHEN position = 'TL' OR
                     position LIKE '% TL' OR 
                     position LIKE '% - TL' OR
                     position LIKE '%/TL' OR
                     position LIKE '% | TL %' THEN 1 
                ELSE 2 
            END, 
            name ASC
    ");

    if ($stmt === false) {
        die('Prepare failed: (' . $conn->errno . ') ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    echo '<div class="employee-group ' . $group_class . '" data-title="' . htmlspecialchars($group_name) . '">';
    echo '<ul class="employee">';

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $classes = array();
            if ($row['anwesend']) {
                $classes[] = 'present';
            }

            // Neuen Check für Schichtmeister hinzufügen:
            if ($row['position'] === 'Schichtmeister') {
                $classes[] = 'schichtmeister';
            }

            $exclude_positions = array(
                'Qualitätssicherung',
                'Palletierer/MGA',
                'ISA/Necker',
                'Frontend',
                'Druckmaschine',
                'Schicht - Elektrik',
                'Schicht - Mechanik',
                'Schicht - CPO'
            );

            // Erkennung der Teamleiter-Positionen
            $is_teamleiter = false;
            if (strpos($row['position'], ' - TL') !== false ||
                $row['position'] === 'TL' ||
                strpos($row['position'], ' TL') !== false) {
                $is_teamleiter = true;
            }

            if ($is_teamleiter && !in_array($row['position'], $exclude_positions)) {
                $classes[] = 'tl';
            }

            // Erkennung der Abteilungsleiter-Positionen
            $is_abteilungsleiter = false;
            if (strpos($row['position'], ' | AL') !== false ||
                strpos($row['position'], ' AL') !== false ||
                $row['position'] === 'AL') {
                $is_abteilungsleiter = true;
            }

            if ($is_abteilungsleiter &&
                strpos($row['position'], 'Spezialist') === false &&
                !in_array($row['position'], $exclude_positions)) {
                $classes[] = 'al';
            }

            $class_attr = !empty($classes) ? ' class="' . implode(' ', $classes) . '"' : '';

            echo '<li' . $class_attr . '>
                <a href="employee_details.php?employee_id=' . htmlspecialchars($row['employee_id']) . '" 
                   title="' . htmlspecialchars($row['position']) . '">
                   ' . htmlspecialchars($row['name']) . '
                </a>
            </li>';
        }
    } else {
        echo '<li class="no-employees">Keine Mitarbeiter</li>';
    }

    $stmt->close();
    echo '</ul>';
    echo '</div>';
}

/**
 * Hilfsfunktion: Rendert eine "Sonstiges"-Gruppe mit Mitarbeitern, die nicht zu den angegebenen Positionen gehören
 */
function render_other_employees($conn, $field, $field_value, $excluded_positions)
{
    echo '<div class="employee-group sonstiges" data-title="Sonstiges">';
    echo '<ul class="employee">';

    $placeholders = implode(',', array_fill(0, count($excluded_positions), '?'));
    $params = array_merge(array($field_value), $excluded_positions);
    $types = str_repeat('s', count($params));

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $stmt = $conn->prepare("
        SELECT employee_id, name, anwesend, position 
        FROM employees 
        WHERE $field = ? AND (position NOT IN ($placeholders) OR position IS NULL) AND status != 9999 
        ORDER BY name ASC
    ");

    if ($stmt === false) {
        die('Prepare failed: (' . $conn->errno . ') ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $presentClass = $row['anwesend'] ? 'present' : '';
            echo '<li class="' . $presentClass . '">
                <a href="employee_details.php?employee_id=' . htmlspecialchars($row['employee_id']) . '">
                    ' . htmlspecialchars($row['name']) . '
                </a>
            </li>';
        }
    } else {
        echo '<li>Keine Mitarbeiter</li>';
    }

    $stmt->close();
    echo '</ul>';
    echo '</div>';
}

/**
 * Hilfsfunktion: Rendert alle Mitarbeiter einer Gruppe (z.B. Verwaltung) ohne Bereichsunterteilung
 */
function render_group_employees($conn, $group)
{
    echo '<ul class="employee">';

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $stmt = $conn->prepare("SELECT employee_id, name, anwesend FROM employees WHERE gruppe = ? AND status != 9999 ORDER BY name ASC");
    $stmt->bind_param("s", $group);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $presentClass = $row['anwesend'] ? 'present' : '';
            echo '<li class="' . $presentClass . '">
                <a href="employee_details.php?employee_id=' . htmlspecialchars($row['employee_id']) . '">
                    ' . htmlspecialchars($row['name']) . '
                </a>
            </li>';
        }
    } else {
        echo '<li>Keine Mitarbeiter</li>';
    }

    $stmt->close();
    echo '</ul>';
}

?>