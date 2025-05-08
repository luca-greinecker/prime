<?php
/**
 * edit_skill.php
 *
 * Ermöglicht das Bearbeiten einer vorhandenen Kompetenz (Skill) in der Datenbank.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Skill-ID aus der URL holen
if (!isset($_GET['id'])) {
    echo '<p>Keine Skill-ID angegeben</p>';
    exit;
}

$skill_id = $_GET['id'];

// Skill-Daten abrufen
$stmt = $conn->prepare("
    SELECT 
        s.id,
        s.name,
        s.kategorie,
        GROUP_CONCAT(sg.gesprächsbereich_id) AS bereich_ids
    FROM skills s
    LEFT JOIN skills_zu_gesprächsbereich sg ON s.id = sg.skill_id
    WHERE s.id = ?
    GROUP BY s.id
");
if (!$stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $skill_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $skill = $result->fetch_assoc();
    $skill['bereich_ids'] = explode(',', $skill['bereich_ids']);
} else {
    echo '<p>Keine Daten gefunden für die angegebene Skill-ID.</p>';
    exit;
}
$stmt->close();

// Gesprächsbereiche abrufen
$stmt_bereich = $conn->prepare("SELECT id, name FROM gesprächsbereiche");
if (!$stmt_bereich) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$stmt_bereich->execute();
$result_bereich = $stmt_bereich->get_result();

$gesprächsbereiche = [];
while ($row = $result_bereich->fetch_assoc()) {
    $gesprächsbereiche[] = $row;
}
$stmt_bereich->close();

// Skill bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['kategorie'], $_POST['positionen'])) {
    $name       = $_POST['name'];
    $kategorie  = $_POST['kategorie'];
    $positionen = $_POST['positionen'];

    // Skill aktualisieren
    $stmt_update = $conn->prepare("UPDATE skills SET name = ?, kategorie = ? WHERE id = ?");
    if (!$stmt_update) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt_update->bind_param("ssi", $name, $kategorie, $skill_id);
    if (!$stmt_update->execute()) {
        die("Execute failed: " . htmlspecialchars($stmt_update->error));
    }
    $stmt_update->close();

    // Vorhandene Zuordnungen entfernen
    $stmt_del = $conn->prepare("DELETE FROM skills_zu_gesprächsbereich WHERE skill_id = ?");
    if (!$stmt_del) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt_del->bind_param("i", $skill_id);
    if (!$stmt_del->execute()) {
        die("Execute failed: " . htmlspecialchars($stmt_del->error));
    }
    $stmt_del->close();

    // Neue Zuordnungen setzen
    foreach ($positionen as $bereich_id) {
        $stmt_insert = $conn->prepare("INSERT INTO skills_zu_gesprächsbereich (skill_id, gesprächsbereich_id) VALUES (?, ?)");
        if (!$stmt_insert) {
            die("Prepare failed: " . htmlspecialchars($conn->error));
        }
        $stmt_insert->bind_param("ii", $skill_id, $bereich_id);
        if (!$stmt_insert->execute()) {
            die("Execute failed: " . htmlspecialchars($stmt_insert->error));
        }
        $stmt_insert->close();
    }

    header("Location: settings_kompetenzen.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kompetenz bearbeiten</title>
    <link href="navbar.css" rel="stylesheet">
    <!-- Lokales Bootstrap 5 CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style>
        select[multiple] {
            height: auto;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <h1 class="text-center mb-3">Kompetenz bearbeiten</h1>
    <div class="divider"></div>
    <form method="post">
        <div class="mb-3">
            <label for="name" class="form-label">Kompetenz:</label>
            <input type="text" class="form-control" id="name" name="name"
                   value="<?php echo htmlspecialchars($skill['name']); ?>" required>
        </div>
        <div class="mb-3">
            <label for="kategorie" class="form-label">Kategorie:</label>
            <select class="form-control" id="kategorie" name="kategorie" required>
                <?php
                $kategorien = ["Anwenderkenntnisse", "Positionsspezifische Kompetenzen", "Persönliche Kompetenzen", "Führungskompetenzen"];
                foreach ($kategorien as $kategorieOption):
                    $selected = ($kategorieOption === $skill['kategorie']) ? 'selected' : '';
                    ?>
                    <option value="<?php echo htmlspecialchars($kategorieOption); ?>" <?php echo $selected; ?>>
                        <?php echo htmlspecialchars($kategorieOption); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="positionen" class="form-label">Gesprächsbereiche:</label>
            <select multiple class="form-control" id="positionen" name="positionen[]" required>
                <?php foreach ($gesprächsbereiche as $bereich): ?>
                    <option value="<?php echo htmlspecialchars($bereich['id']); ?>"
                        <?php echo (in_array($bereich['id'], $skill['bereich_ids'])) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($bereich['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</div>

<!-- Lokales Bootstrap 5 JavaScript Bundle -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    // Höhe des Mehrfachauswahlfeldes anpassen
    document.addEventListener("DOMContentLoaded", function() {
        var selectElement = document.getElementById('positionen');
        selectElement.size = selectElement.options.length;
    });
</script>
</body>
</html>