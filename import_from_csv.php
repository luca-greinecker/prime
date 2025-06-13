<?php
/**
 * import_from_csv.php
 *
 * Vergleicht Personaldaten aus einer CSV-Datei mit der Tabelle employees.
 * - Zeigt Mitarbeiter aus der CSV an, die nicht in der Datenbank existieren.
 * - Ergänzt fehlende Daten bei eindeutig gefundenen Mitarbeitern.
 *
 * Erwartet eine Datei "Downloads/Personaldaten.csv" mit den Spalten:
 * Vorname, Name, Geburtsdatum, Geschlecht, Eintritt, Versicherungsnummer
 */

include 'db.php';
global $conn;

// Pfad zur CSV-Datei ggf. anpassen
$csvPath = __DIR__ . '/assets/Personaldaten.csv';

$missing    = [];
$ambiguous  = [];
$updated    = [];

if (!is_readable($csvPath)) {
    die('CSV-Datei nicht gefunden: ' . htmlspecialchars($csvPath));
}

if (($handle = fopen($csvPath, 'r')) === false) {
    die('Konnte CSV-Datei nicht öffnen.');
}

// Kopfzeile überspringen
$header = fgetcsv($handle, 0, ';');

while (($data = fgetcsv($handle, 0, ';')) !== false) {
    [$vorname, $nachname, $gebDatum, $geschlecht, $eintritt, $sv] = array_map('trim', $data);

    // Datumskonvertierung (dd.mm.yyyy -> yyyy-mm-dd)
    $gebDatum = $gebDatum ? date('Y-m-d', strtotime(str_replace('.', '-', $gebDatum))) : null;
    $eintritt = $eintritt ? date('Y-m-d', strtotime(str_replace('.', '-', $eintritt))) : null;

    // Geschlecht vereinheitlichen
    if ($geschlecht) {
        $g = strtolower($geschlecht);
        if ($g === 'm' || $g === 'männlich') {
            $geschlecht = 'männlich';
        } elseif ($g === 'w' || $g === 'weiblich') {
            $geschlecht = 'weiblich';
        } else {
            $geschlecht = 'divers';
        }
    } else {
        $geschlecht = null;
    }

    // Erstelle den erwarteten Namen im DB-Format "Nachname Vorname"
    $expectedName = $nachname . ' ' . $vorname;

    // Suche nach exakter Übereinstimmung
    $stmt = $conn->prepare('SELECT employee_id, name, birthdate, gender, entry_date, social_security_number FROM employees WHERE name = ?');
    $stmt->bind_param('s', $expectedName);
    $stmt->execute();
    $result = $stmt->get_result();

    // Falls keine exakte Übereinstimmung, versuche flexiblere Suche
    if ($result->num_rows === 0) {
        $stmt->close();

        // Suche mit LIKE für Variationen (z.B. mehrere Vornamen)
        $searchPattern = $nachname . '%' . $vorname . '%';
        $stmt = $conn->prepare('SELECT employee_id, name, birthdate, gender, entry_date, social_security_number FROM employees WHERE name LIKE ?');
        $stmt->bind_param('s', $searchPattern);
        $stmt->execute();
        $result = $stmt->get_result();

        // Falls immer noch nichts gefunden, versuche nur mit Nachname und prüfe manuell
        if ($result->num_rows === 0) {
            $stmt->close();

            $searchPattern = $nachname . '%';
            $stmt = $conn->prepare('SELECT employee_id, name, birthdate, gender, entry_date, social_security_number FROM employees WHERE name LIKE ?');
            $stmt->bind_param('s', $searchPattern);
            $stmt->execute();
            $result = $stmt->get_result();

            // Manuelle Prüfung ob Vorname im Ergebnis enthalten ist
            $matches = [];
            while ($row = $result->fetch_assoc()) {
                // Prüfe ob der Vorname irgendwo im Namen vorkommt
                if (stripos($row['name'], $vorname) !== false) {
                    $matches[] = $row;
                }
            }

            if (count($matches) === 0) {
                $missing[] = "$vorname $nachname (gesucht als: $expectedName)";
                $stmt->close();
                continue;
            } elseif (count($matches) === 1) {
                $emp = $matches[0];
            } else {
                $ambiguous[] = "$vorname $nachname (mehrere Treffer gefunden)";
                $stmt->close();
                continue;
            }
        } elseif ($result->num_rows === 1) {
            $emp = $result->fetch_assoc();
        } else {
            $ambiguous[] = "$vorname $nachname (mehrere Treffer gefunden)";
            $stmt->close();
            continue;
        }
    } elseif ($result->num_rows === 1) {
        $emp = $result->fetch_assoc();
    } else {
        $ambiguous[] = "$vorname $nachname (mehrere Treffer gefunden)";
        $stmt->close();
        continue;
    }

    $employeeId = $emp['employee_id'];
    $dbName = $emp['name'];
    $stmt->close();

    $fields = [];
    $values = '';
    $params = [];

    if (!$emp['birthdate'] && $gebDatum) {
        $fields[] = 'birthdate = ?';
        $values .= 's';
        $params[] = $gebDatum;
    }
    if (!$emp['gender'] && $geschlecht) {
        $fields[] = 'gender = ?';
        $values .= 's';
        $params[] = $geschlecht;
    }
    if (!$emp['entry_date'] && $eintritt) {
        $fields[] = 'entry_date = ?';
        $values .= 's';
        $params[] = $eintritt;
    }
    if (!$emp['social_security_number'] && $sv) {
        $fields[] = 'social_security_number = ?';
        $values .= 's';
        $params[] = $sv;
    }

    if (!empty($fields)) {
        $sql = 'UPDATE employees SET ' . implode(', ', $fields) . ' WHERE employee_id = ?';
        $values .= 'i';
        $params[] = $employeeId;
        $update = $conn->prepare($sql);
        $update->bind_param($values, ...$params);
        $update->execute();
        $update->close();
        $updated[] = "$vorname $nachname (DB: $dbName)";
    }
}

fclose($handle);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>CSV-Abgleich</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        ul { background: #f5f5f5; padding: 20px; border-radius: 5px; }
        li { margin: 5px 0; }
        .info { color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
<h1>Ergebnis des CSV-Abgleichs</h1>

<h2>Fehlende Mitarbeiter</h2>
<?php if ($missing): ?>
    <ul>
        <?php foreach ($missing as $name): ?>
            <li><?php echo htmlspecialchars($name); ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="info">Diese Mitarbeiter wurden in der Datenbank nicht gefunden.</p>
<?php else: ?>
    <p>Keine fehlenden Mitarbeiter gefunden.</p>
<?php endif; ?>

<?php if ($ambiguous): ?>
    <h2>Mehrdeutige Treffer</h2>
    <ul>
        <?php foreach ($ambiguous as $name): ?>
            <li><?php echo htmlspecialchars($name); ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="info">Für diese Mitarbeiter wurden mehrere mögliche Einträge gefunden.</p>
<?php endif; ?>

<h2>Aktualisierte Mitarbeiter</h2>
<?php if ($updated): ?>
    <ul>
        <?php foreach ($updated as $name): ?>
            <li><?php echo htmlspecialchars($name); ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="info">Bei diesen Mitarbeitern wurden fehlende Daten ergänzt.</p>
<?php else: ?>
    <p>Keine Aktualisierungen notwendig.</p>
<?php endif; ?>
</body>
</html>