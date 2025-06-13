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
$csvPath = __DIR__ . '/Downloads/Personaldaten.csv';

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

    // Mitarbeiter anhand Vor- und Nachname suchen
    $stmt = $conn->prepare('SELECT employee_id, birthdate, gender, entry_date, social_security_number FROM employees WHERE ind_name = ? AND name = ?');
    $stmt->bind_param('ss', $vorname, $nachname);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $missing[] = "$vorname $nachname";
        $stmt->close();
        continue;
    }
    if ($result->num_rows > 1) {
        $ambiguous[] = "$vorname $nachname";
        $stmt->close();
        continue;
    }

    $emp = $result->fetch_assoc();
    $employeeId = $emp['employee_id'];
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
        $updated[] = "$vorname $nachname";
    }
}

fclose($handle);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>CSV-Abgleich</title>
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
<?php endif; ?>

<h2>Aktualisierte Mitarbeiter</h2>
<?php if ($updated): ?>
    <ul>
        <?php foreach ($updated as $name): ?>
            <li><?php echo htmlspecialchars($name); ?></li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <p>Keine Aktualisierungen notwendig.</p>
<?php endif; ?>
</body>
</html>
