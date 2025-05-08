<?php
/**
 * settings_bereichsgruppen.php
 *
 * Diese Seite ermöglicht der HR-Abteilung, die Bereichsgruppen und deren Positionszuordnungen
 * dynamisch zu verwalten.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff(); // Zugriffskontrolle für Admin und HR

// Initialisierung von Variablen
$success_message = '';
$error_message = '';
$current_category = isset($_GET['category']) ? $_GET['category'] : 'schicht';

// Pfad zur team_definitions.php - bitte bei Bedarf anpassen
$team_definitions_path = 'team_definitions.php';

// Kategorien für die Datenbank-Benennung
$db_category_map = [
    'schicht' => 'Schichtarbeit',
    'tagschicht' => 'Tagschicht',
    'verwaltung' => 'Verwaltung'
];

// Funktionen für die Datenbankverwaltung
function get_bereichsgruppen($conn, $category_key)
{
    global $db_category_map;
    $category = $db_category_map[$category_key];

    $stmt = $conn->prepare("
        SELECT bg.id, bg.name, bg.reihenfolge, GROUP_CONCAT(p.id) as position_ids, GROUP_CONCAT(p.name) as position_names
        FROM bereichsgruppen bg
        LEFT JOIN bereichsgruppen_positionen bp ON bg.id = bp.bereichsgruppe_id
        LEFT JOIN positionen p ON bp.position_id = p.id
        WHERE bg.kategorie = ?
        GROUP BY bg.id
        ORDER BY bg.reihenfolge ASC, bg.name ASC
    ");
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();

    $bereichsgruppen = [];
    while ($row = $result->fetch_assoc()) {
        $position_ids = $row['position_ids'] ? explode(',', $row['position_ids']) : [];
        $position_names = $row['position_names'] ? explode(',', $row['position_names']) : [];

        $positionen = [];
        if (!empty($position_ids) && !empty($position_names)) {
            foreach ($position_ids as $key => $id) {
                if (isset($position_names[$key])) {
                    $positionen[$id] = $position_names[$key];
                }
            }
        }

        $bereichsgruppen[$row['id']] = [
            'name' => $row['name'],
            'reihenfolge' => $row['reihenfolge'],
            'positionen' => $positionen
        ];
    }

    $stmt->close();
    return $bereichsgruppen;
}

function save_bereichsgruppe($conn, $category_key, $name, $position_ids, $reihenfolge = 999, $id = null)
{
    global $db_category_map;
    $category = $db_category_map[$category_key];

    $conn->begin_transaction();

    try {
        if ($id) {
            // Update bestehende Bereichsgruppe
            $stmt = $conn->prepare("UPDATE bereichsgruppen SET name = ?, reihenfolge = ? WHERE id = ?");
            $stmt->bind_param("sii", $name, $reihenfolge, $id);
            $stmt->execute();
            $stmt->close();

            // Bestehende Zuordnungen löschen
            $stmt = $conn->prepare("DELETE FROM bereichsgruppen_positionen WHERE bereichsgruppe_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $bereichsgruppe_id = $id;
        } else {
            // Neue Bereichsgruppe erstellen
            $stmt = $conn->prepare("INSERT INTO bereichsgruppen (name, kategorie, reihenfolge) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $category, $reihenfolge);
            $stmt->execute();
            $bereichsgruppe_id = $conn->insert_id;
            $stmt->close();
        }

        // Positionen zuordnen
        if (!empty($position_ids)) {
            $values = [];
            $types = "";
            $params = [];

            foreach ($position_ids as $position_id) {
                $values[] = "(?, ?)";
                $types .= "ii";
                $params[] = $bereichsgruppe_id;
                $params[] = $position_id;
            }

            $query = "INSERT INTO bereichsgruppen_positionen (bereichsgruppe_id, position_id) VALUES " . implode(", ", $values);
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

function delete_bereichsgruppe($conn, $id)
{
    $conn->begin_transaction();

    try {
        // Zuerst die Zuordnungen löschen
        $stmt = $conn->prepare("DELETE FROM bereichsgruppen_positionen WHERE bereichsgruppe_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // Dann die Bereichsgruppe löschen
        $stmt = $conn->prepare("DELETE FROM bereichsgruppen WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

function update_bereichsgruppen_order($conn, $category_key, $order_data)
{
    global $db_category_map;
    $category = $db_category_map[$category_key];

    $conn->begin_transaction();

    try {
        foreach ($order_data as $id => $order) {
            // Sicherstellen, dass ID und Reihenfolge numerisch sind
            $id = (int)$id;
            $order = (int)$order;

            $stmt = $conn->prepare("UPDATE bereichsgruppen SET reihenfolge = ? WHERE id = ? AND kategorie = ?");
            $stmt->bind_param("iis", $order, $id, $category);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

// Funktion zum Generieren von team_definitions PHP-Code
function generate_team_definitions()
{
    global $conn;

    // Teams und Zusatzgruppen
    $teams = ["Team L", "Team M", "Team N", "Team O", "Team P"];
    $additional_groups = ["Tagschicht", "Verwaltung"];

    // Schicht-Bereichsgruppen laden
    $schicht_gruppen = [];
    $schicht_gruppen_order = [];

    $stmt = $conn->prepare("
        SELECT bg.id, bg.name, bg.reihenfolge, p.name as position_name
        FROM bereichsgruppen bg
        JOIN bereichsgruppen_positionen bp ON bg.id = bp.bereichsgruppe_id
        JOIN positionen p ON bp.position_id = p.id
        WHERE bg.kategorie = 'Schichtarbeit'
        ORDER BY bg.reihenfolge ASC, bg.name ASC, p.name ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (!isset($schicht_gruppen[$row['name']])) {
            $schicht_gruppen[$row['name']] = [];
            // Füge den Gruppennamen zur Reihenfolge-Liste hinzu
            $schicht_gruppen_order[] = $row['name'];
        }
        $schicht_gruppen[$row['name']][] = $row['position_name'];
    }
    $stmt->close();

    // Entferne Duplikate aus der Reihenfolge-Liste
    $schicht_gruppen_order = array_unique($schicht_gruppen_order);

    // Tagschicht-Bereichsgruppen laden
    $tagschicht_gruppen = [];
    $tagschicht_gruppen_order = [];

    $stmt = $conn->prepare("
        SELECT bg.id, bg.name, bg.reihenfolge, p.name as position_name
        FROM bereichsgruppen bg
        JOIN bereichsgruppen_positionen bp ON bg.id = bp.bereichsgruppe_id
        JOIN positionen p ON bp.position_id = p.id
        WHERE bg.kategorie = 'Tagschicht'
        ORDER BY bg.reihenfolge ASC, bg.name ASC, p.name ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (!isset($tagschicht_gruppen[$row['name']])) {
            $tagschicht_gruppen[$row['name']] = [];
            // Füge den Gruppennamen zur Reihenfolge-Liste hinzu
            $tagschicht_gruppen_order[] = $row['name'];
        }
        $tagschicht_gruppen[$row['name']][] = $row['position_name'];
    }
    $stmt->close();

    // Entferne Duplikate aus der Reihenfolge-Liste
    $tagschicht_gruppen_order = array_unique($tagschicht_gruppen_order);

    // PHP-Code-Template für team_definitions.php erstellen
    $php_code = "<?php\n";
    $php_code .= "/**\n";
    $php_code .= " * team_definitions.php\n";
    $php_code .= " *\n";
    $php_code .= " * Automatisch generiert von settings_bereichsgruppen.php am " . date('d.m.Y H:i:s') . "\n";
    $php_code .= " * Enthält die Definitionen für Teams, Bereichsgruppen und Positionen.\n";
    $php_code .= " * Diese Datei wird von mehreren Seiten verwendet, die Mitarbeiterstrukturen anzeigen.\n";
    $php_code .= " */\n\n";

    // Teams definieren
    $php_code .= "// Teams definieren\n";
    $php_code .= '$teams = ' . var_export($teams, true) . ";\n";
    $php_code .= '$additional_groups = ' . var_export($additional_groups, true) . ";\n\n";

    // Reihenfolge der Bereichsgruppen
    $php_code .= "// Reihenfolge der Bereichsgruppen für die Anzeige (Teams/Schicht)\n";
    $php_code .= '$bereichsgruppen_order = ' . var_export($schicht_gruppen_order, true) . ";\n\n";

    $php_code .= "// Reihenfolge der Bereichsgruppen für die Tagschicht\n";
    $php_code .= '$tagschicht_bereichsgruppen_order = ' . var_export($tagschicht_gruppen_order, true) . ";\n\n";

    // Bereichsgruppen für Teams (Schicht)
    $php_code .= "// Bereichsgruppen für Teams (Schicht)\n";
    $php_code .= '$bereichsgruppen = array(' . "\n";
    foreach ($schicht_gruppen as $name => $positionen) {
        $php_code .= '    "' . addslashes($name) . '" => ' . var_export($positionen, true) . ",\n";
    }
    $php_code .= ");\n\n";

    // Bereichsgruppen für die Tagschicht
    $php_code .= "// Bereichsgruppen für die Tagschicht\n";
    $php_code .= '$tagschicht_bereichsgruppen = array(' . "\n";
    foreach ($tagschicht_gruppen as $name => $positionen) {
        $php_code .= '    "' . addslashes($name) . '" => ' . var_export($positionen, true) . ",\n";
    }
    $php_code .= ");\n\n";

// Verbesserte Hilfsfunktion: Erstellt eine Mitarbeiter-Bereichsgruppe
    $php_code .= "/**\n";
    $php_code .= " * Hilfsfunktion: Erstellt eine Mitarbeiter-Bereichsgruppe\n";
    $php_code .= " */\n";
    $php_code .= 'function render_employee_group($conn, $group_name, $positionen, $field, $field_value, $group_class = \'\') {
    if (empty($group_class)) {
        $group_class = strtolower(str_replace(\'/\', \'-\', $group_name));
    }

    // Platzhalter und Parameter für die Abfrage
    $placeholders = implode(\',\', array_fill(0, count($positionen), \'?\'));
    $types = str_repeat(\'s\', count($positionen) + 1); // Crew plus alle Positionen
    $params = array_merge(array($field_value), $positionen);

    // Abfrage
    $stmt = $conn->prepare("
        SELECT employee_id, name, anwesend, position 
        FROM employees 
        WHERE $field = ? AND position IN ($placeholders) 
        ORDER BY 
            CASE 
                WHEN position LIKE \'% AL%\' AND position NOT LIKE \'%Spezialist%\' THEN 0
                WHEN position = \'TL\' OR
                     position LIKE \'% TL\' OR 
                     position LIKE \'% - TL\' OR
                     position LIKE \'%/TL\' OR
                     position LIKE \'% | TL %\' THEN 1 
                ELSE 2 
            END, 
            name ASC
    ");

    if ($stmt === false) {
        die(\'Prepare failed: (\' . $conn->errno . \') \' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    echo \'<div class="employee-group \' . $group_class . \'" data-title="\' . htmlspecialchars($group_name) . \'">\';
    echo \'<ul class="employee">\';

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $classes = array();
            if ($row[\'anwesend\']) $classes[] = \'present\';
            
            // Liste der Positionen, die explizit ausgeschlossen werden sollen
            $exclude_positions = array(
                \'Qualitätssicherung\',
                \'Palletierer/MGA\',
                \'ISA/Necker\',
                \'Frontend\',
                \'Druckmaschine\',
                \'Schicht - Elektrik\',
                \'Schicht - Mechanik\',
                \'Schicht - CPO\'
            );
            
            // TL-Position erkennen
            $is_teamleiter = false;
            if (strpos($row[\'position\'], \' - TL\') !== false || 
                $row[\'position\'] === \'TL\' || 
                strpos($row[\'position\'], \' TL\') !== false) {
                $is_teamleiter = true;
            }
            
            // Nur als TL markieren, wenn es sich wirklich um eine TL-Position handelt 
            // und nicht in der Ausschlussliste ist
            if ($is_teamleiter && !in_array($row[\'position\'], $exclude_positions)) {
                $classes[] = \'tl\';
            }
            
            // AL-Position erkennen - doppelte Prüfung
            $is_abteilungsleiter = false;
            if (strpos($row[\'position\'], \' | AL\') !== false || 
                strpos($row[\'position\'], \' AL\') !== false || 
                $row[\'position\'] === \'AL\') {
                $is_abteilungsleiter = true;
            }
            
            // Nur als AL markieren, wenn es sich wirklich um eine AL-Position handelt,
            // nicht "Spezialist" enthält und nicht in der Ausschlussliste ist
            if ($is_abteilungsleiter && 
                strpos($row[\'position\'], \'Spezialist\') === false && 
                !in_array($row[\'position\'], $exclude_positions)) {
                $classes[] = \'al\';
            }

            $class_attr = !empty($classes) ? \' class="\' . implode(\' \', $classes) . \'"\' : \'\';

            echo \'<li\' . $class_attr . \'>
                <a href="employee_details.php?employee_id=\' . htmlspecialchars($row[\'employee_id\']) . \'" 
                   title="\' . htmlspecialchars($row[\'position\']) . \'">
                   \' . htmlspecialchars($row[\'name\']) . \'
                </a>
            </li>\';
        }
    } else {
        echo \'<li class="no-employees">Keine Mitarbeiter</li>\';
    }

    $stmt->close();
    echo \'</ul>\';
    echo \'</div>\';
}' . "\n\n";
    $php_code .= "/**\n";
    $php_code .= " * Hilfsfunktion: Rendert eine \"Sonstiges\"-Gruppe mit Mitarbeitern, die nicht zu den angegebenen Positionen gehören\n";
    $php_code .= " */\n";
    $php_code .= 'function render_other_employees($conn, $field, $field_value, $excluded_positions) {
    echo \'<div class="employee-group sonstiges" data-title="Sonstiges">\';
    echo \'<ul class="employee">\';

    $placeholders = implode(\',\', array_fill(0, count($excluded_positions), \'?\'));
    $params = array_merge(array($field_value), $excluded_positions);
    $types = str_repeat(\'s\', count($params));

    $stmt = $conn->prepare("
        SELECT employee_id, name, anwesend, position 
        FROM employees 
        WHERE $field = ? AND (position NOT IN ($placeholders) OR position IS NULL) 
        ORDER BY name ASC
    ");

    if ($stmt === false) {
        die(\'Prepare failed: (\' . $conn->errno . \') \' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $presentClass = $row[\'anwesend\'] ? \'present\' : \'\';
            echo \'<li class="\' . $presentClass . \'">
                <a href="employee_details.php?employee_id=\' . htmlspecialchars($row[\'employee_id\']) . \'">
                    \' . htmlspecialchars($row[\'name\']) . \'
                </a>
            </li>\';
        }
    } else {
        echo \'<li>Keine Mitarbeiter</li>\';
    }

    $stmt->close();
    echo \'</ul>\';
    echo \'</div>\';
}' . "\n\n";

    $php_code .= "/**\n";
    $php_code .= " * Hilfsfunktion: Rendert alle Mitarbeiter einer Gruppe (z.B. Verwaltung) ohne Bereichsunterteilung\n";
    $php_code .= " */\n";
    $php_code .= 'function render_group_employees($conn, $group) {
    echo \'<ul class="employee">\';

    $stmt = $conn->prepare("SELECT employee_id, name, anwesend FROM employees WHERE gruppe = ? ORDER BY name ASC");
    $stmt->bind_param("s", $group);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $presentClass = $row[\'anwesend\'] ? \'present\' : \'\';
            echo \'<li class="\' . $presentClass . \'">
                <a href="employee_details.php?employee_id=\' . htmlspecialchars($row[\'employee_id\']) . \'">
                    \' . htmlspecialchars($row[\'name\']) . \'
                </a>
            </li>\';
        }
    } else {
        echo \'<li>Keine Mitarbeiter</li>\';
    }

    $stmt->close();
    echo \'</ul>\';
}' . "\n";

    $php_code .= "?>";

    return $php_code;
}

// Funktion zum Speichern der team_definitions.php Datei
function save_team_definitions_file($content)
{
    global $team_definitions_path;

    // Versuche, die Datei zu speichern
    $result = file_put_contents($team_definitions_path, $content);

    return ($result !== false);
}

// Laden der aktuellen Bereichsgruppen für die ausgewählte Kategorie
$bereichsgruppen = get_bereichsgruppen($conn, $current_category);

// Bearbeiten einer Bereichsgruppe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        $name = $_POST['name'] ?? '';
        $position_ids = isset($_POST['position_ids']) ? $_POST['position_ids'] : [];
        $reihenfolge = isset($_POST['reihenfolge']) ? intval($_POST['reihenfolge']) : 999;

        if (!empty($name) && !empty($position_ids)) {
            if (save_bereichsgruppe($conn, $current_category, $name, $position_ids, $reihenfolge, $id)) {
                $success_message = "Bereichsgruppe erfolgreich gespeichert.";

                // Aktualisiere direkt die team_definitions.php
                $generated_code = generate_team_definitions();
                if (!save_team_definitions_file($generated_code)) {
                    $error_message = "Bereichsgruppe gespeichert, aber team_definitions.php konnte nicht aktualisiert werden.";
                }
            } else {
                $error_message = "Fehler beim Speichern der Bereichsgruppe.";
            }
        } else {
            $error_message = "Name und mindestens eine Position sind erforderlich.";
        }
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $id = $_POST['id'];
        if (delete_bereichsgruppe($conn, $id)) {
            $success_message = "Bereichsgruppe erfolgreich gelöscht.";

            // Aktualisiere direkt die team_definitions.php
            $generated_code = generate_team_definitions();
            if (!save_team_definitions_file($generated_code)) {
                $error_message = "Bereichsgruppe gelöscht, aber team_definitions.php konnte nicht aktualisiert werden.";
            }
        } else {
            $error_message = "Fehler beim Löschen der Bereichsgruppe.";
        }
    } elseif ($action === 'update_order' && isset($_POST['orders'])) {
        $orders = $_POST['orders'];
        if (update_bereichsgruppen_order($conn, $current_category, $orders)) {
            $success_message = "Reihenfolge erfolgreich aktualisiert.";

            // Aktualisiere direkt die team_definitions.php
            $generated_code = generate_team_definitions();
            if (!save_team_definitions_file($generated_code)) {
                $error_message = "Reihenfolge aktualisiert, aber team_definitions.php konnte nicht aktualisiert werden.";
            }
        } else {
            $error_message = "Fehler beim Aktualisieren der Reihenfolge.";
        }
    }

    // Umleitung, um Formular-Resubmit zu verhindern
    header("Location: settings_bereichsgruppen.php?category=" . $current_category . "&status=" . ($success_message ? 'success' : 'error'));
    exit;
}

// Status-Meldung aus der URL verarbeiten
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success') {
        $success_message = "Aktion erfolgreich ausgeführt.";
    } elseif ($_GET['status'] === 'error') {
        $error_message = "Fehler bei der Ausführung der Aktion.";
    }
}

// Laden aller Positionen für die aktuelle Kategorie
$available_positions = [];
$stmt = $conn->prepare("SELECT id, name FROM positionen WHERE gruppe = ? ORDER BY name ASC");
$kategorie = $db_category_map[$current_category];
$stmt->bind_param("s", $kategorie);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $available_positions[$row['id']] = $row['name'];
}
$stmt->close();

?>

    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Bereichsgruppen-Verwaltung</title>
        <!-- Lokales Bootstrap 5 CSS -->
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
        <style>
            .content {
                padding: 20px;
            }

            .content h1 {
                text-align: center;
                width: 100%;
                margin-bottom: 20px;
            }

            .category-switch {
                margin-bottom: 20px;
            }

            .bereich-card {
                margin-bottom: 20px;
                border: 1px solid #dee2e6;
                border-radius: 5px;
            }

            .bereich-header {
                padding: 10px 15px;
                background-color: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .bereich-content {
                padding: 0 15px;
            }

            .bereich-content ul {
                list-style-type: none;
                padding-left: 0;
            }

            .bereich-content li {
                padding: 5px 0;
            }

            .bereich-actions {
                display: flex;
                gap: 10px;
            }

            .add-bereich-btn {
                margin: 20px 0;
            }

            .position-select {
                height: 200px !important;
            }

            .modal-footer .btn {
                margin-left: 10px;
            }

            .bereichsgruppen-container {
                position: relative;
            }

            .sortable-handle {
                cursor: move;
                padding: 5px;
                color: #6c757d;
            }

            .sortable-handle:hover {
                color: #007bff;
            }

            .sortable-item {
                position: relative;
            }

            .order-number {
                position: absolute;
                left: -20px;
                top: 10px;
                font-size: 12px;
                color: #6c757d;
            }

            .sortable-info {
                margin-bottom: 15px;
                background-color: #f8f9fa;
                padding: 10px;
                border-radius: 5px;
                border: 1px solid #dee2e6;
            }

            /* Verbesserte Hinweistexte */
            .instructions {
                padding: 15px;
                margin-bottom: 20px;
                background-color: #e9f5ff;
                border-radius: 5px;
                border-left: 5px solid #007bff;
            }

            .instructions h4 {
                color: #007bff;
                margin-bottom: 10px;
            }

            .instructions ul {
                margin-bottom: 0;
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>
    <div class="content container">
        <h1>Bereichsgruppen-Verwaltung</h1>
        <div class="divider"></div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="instructions">
            <h4>Anleitung zur Bereichsgruppen-Verwaltung</h4>
            <ul>
                <li>Verschiebe die Bereichsgruppen per Drag & Drop, um ihre Reihenfolge zu ändern</li>
                <li>Alle Änderungen werden sofort gespeichert und angewendet</li>
                <li>Diese Einstellungen betreffen die MA-Übersichten hier im PRiME.</li>
            </ul>
        </div>

        <div class="category-switch text-center">
            <div class="btn-group" role="group">
                <a href="?category=schicht"
                   class="btn btn-<?php echo $current_category === 'schicht' ? 'primary' : 'outline-primary'; ?>">
                    Schicht-Bereichsgruppen
                </a>
                <a href="?category=tagschicht"
                   class="btn btn-<?php echo $current_category === 'tagschicht' ? 'primary' : 'outline-primary'; ?>">
                    Tagschicht-Bereichsgruppen
                </a>
            </div>
        </div>

        <div class="bereichsgruppen-container">
            <h2 class="mb-4"><?php echo $current_category === 'schicht' ? 'Schicht' : 'Tagschicht'; ?>
                - Bereichsgruppen</h2>

            <div class="sortable-info">
                <p class="mb-0"><i class="bi bi-info-circle"></i> Ziehen Sie die Bereichsgruppen mit der Maus in die
                    gewünschte Reihenfolge.</p>
            </div>

            <div id="sortable-bereichsgruppen">
                <?php foreach ($bereichsgruppen as $id => $bereich): ?>
                    <div class="sortable-item" data-id="<?php echo $id; ?>"
                         data-order="<?php echo $bereich['reihenfolge']; ?>">
                        <div class="bereich-card">
                            <div class="bereich-header">
                                <span class="sortable-handle"><i class="bi bi-grip-vertical"></i>≡</span>
                                <h3 class="mb-0"><?php echo htmlspecialchars($bereich['name']); ?></h3>
                                <div class="bereich-actions">
                                    <button class="btn btn-sm btn-outline-primary edit-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editModal"
                                            data-id="<?php echo $id; ?>"
                                            data-name="<?php echo htmlspecialchars($bereich['name']); ?>"
                                            data-reihenfolge="<?php echo $bereich['reihenfolge']; ?>"
                                            data-positions='<?php echo htmlspecialchars(json_encode($bereich['positionen'])); ?>'>
                                        Bearbeiten
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger delete-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteModal"
                                            data-id="<?php echo $id; ?>"
                                            data-name="<?php echo htmlspecialchars($bereich['name']); ?>">
                                        Löschen
                                    </button>
                                </div>
                            </div>
                            <div class="bereich-content">
                                <h4 class="mb-1 mt-1">Positionen:</h4>
                                <ul>
                                    <?php foreach ($bereich['positionen'] as $id => $position): ?>
                                        <li><?php echo htmlspecialchars($position); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($bereichsgruppen)): ?>
                <div class="alert alert-info">
                    Keine Bereichsgruppen definiert. Klicken Sie auf "Neue Bereichsgruppe", um eine zu erstellen.
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end">
                <button class="btn btn-success add-bereich-btn m-0" data-bs-toggle="modal" data-bs-target="#addModal">
                    Neue Bereichsgruppe
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Neue Bereichsgruppe hinzufügen -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="settings_bereichsgruppen.php?category=<?php echo $current_category; ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addModalLabel">Neue Bereichsgruppe hinzufügen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name der Bereichsgruppe</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>

                        <div class="mb-3">
                            <input type="hidden" id="reihenfolge" name="reihenfolge" value="999">
                        </div>

                        <div class="mb-3">
                            <label for="position_ids" class="form-label">Positionen (mehrere auswählen mit
                                Strg+Klick)</label>
                            <select multiple class="form-select position-select" id="position_ids" name="position_ids[]"
                                    size="10" required>
                                <?php foreach ($available_positions as $id => $name): ?>
                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Halten Sie die Strg-Taste (oder Cmd auf Mac) gedrückt, um mehrere Positionen
                                auszuwählen.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Bereichsgruppe bearbeiten -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="settings_bereichsgruppen.php?category=<?php echo $current_category; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="reihenfolge" id="edit_reihenfolge">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel">Bereichsgruppe bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Name der Bereichsgruppe</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_position_ids" class="form-label">Positionen (mehrere auswählen mit
                                Strg+Klick)</label>
                            <select multiple class="form-select position-select" id="edit_position_ids"
                                    name="position_ids[]" size="10" required>
                                <?php foreach ($available_positions as $id => $name): ?>
                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Halten Sie die Strg-Taste (oder Cmd auf Mac) gedrückt, um mehrere Positionen
                                auszuwählen.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Bereichsgruppe löschen -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="settings_bereichsgruppen.php?category=<?php echo $current_category; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteModalLabel">Bereichsgruppe löschen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>
                    <div class="modal-body">
                        <p>Möchten Sie die Bereichsgruppe "<span id="delete_name"></span>" wirklich löschen?</p>
                        <p class="text-danger">Diese Aktion kann nicht rückgängig gemacht werden!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-danger">Löschen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Formular für die Reihenfolgeaktualisierung -->
    <form id="orderForm" method="post" action="settings_bereichsgruppen.php?category=<?php echo $current_category; ?>"
          style="display: none;">
        <input type="hidden" name="action" value="update_order">
        <div id="orderFields"></div>
    </form>

    <!-- Lokales Bootstrap 5 JavaScript Bundle + Sortable.js -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Event-Listener für "Bearbeiten"-Buttons
            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const id = this.dataset.id;
                    const name = this.dataset.name;
                    const reihenfolge = this.dataset.reihenfolge || 999;
                    const positions = JSON.parse(this.dataset.positions);

                    document.getElementById('edit_id').value = id;
                    document.getElementById('edit_name').value = name;
                    document.getElementById('edit_reihenfolge').value = reihenfolge;

                    // Positionen auswählen
                    const positionSelect = document.getElementById('edit_position_ids');
                    for (let i = 0; i < positionSelect.options.length; i++) {
                        const optionValue = positionSelect.options[i].value;
                        positionSelect.options[i].selected = Object.keys(positions).includes(optionValue);
                    }
                });
            });

            // Event-Listener für "Löschen"-Buttons
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const id = this.dataset.id;
                    const name = this.dataset.name;

                    document.getElementById('delete_id').value = id;
                    document.getElementById('delete_name').innerText = name;
                });
            });

            // Sortable-Funktionalität für die Reihenfolge der Bereichsgruppen
            const sortableList = document.getElementById('sortable-bereichsgruppen');
            if (sortableList) {
                new Sortable(sortableList, {
                    handle: '.sortable-handle',
                    animation: 150,
                    onEnd: function (evt) {
                        // Aktualisiere die Anzeige der Reihenfolge
                        updateOrderDisplay();
                        // Aktualisiere die versteckten Formularfelder
                        updateOrderFields();
                        // Automatisches Absenden des Formulars nach Drag & Drop
                        document.getElementById('orderForm').submit();
                    }
                });
            }

            // Funktion zum Aktualisieren der Anzeige der Reihenfolge
            function updateOrderDisplay() {
                document.querySelectorAll('.sortable-item').forEach((item, index) => {
                    if (!item.querySelector('.order-number')) {
                        const orderNumber = document.createElement('span');
                        orderNumber.className = 'order-number';
                        orderNumber.textContent = (index + 1);
                        item.appendChild(orderNumber);
                    } else {
                        item.querySelector('.order-number').textContent = (index + 1);
                    }
                });
            }

            // Funktion zum Aktualisieren der versteckten Formularfelder für die Reihenfolge
            function updateOrderFields() {
                const orderFields = document.getElementById('orderFields');

                // Lösche bestehende Felder
                orderFields.innerHTML = '';

                // Erstelle Felder für die aktuelle Reihenfolge
                document.querySelectorAll('.sortable-item').forEach((item, index) => {
                    const id = item.dataset.id;
                    const orderValue = (index + 1) * 10; // Multipliziere mit 10 für spätere Einfügungen

                    // Für das reguläre Formular
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'orders[' + id + ']';
                    input.value = orderValue;
                    orderFields.appendChild(input);
                });
            }

            // Initialisiere die Anzeige der Reihenfolge
            updateOrderDisplay();
        });
    </script>
    </body>
    </html>
<?php
$conn->close();
?>