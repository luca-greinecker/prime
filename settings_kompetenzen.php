<?php
/**
 * settings_kompetenzen.php
 *
 * Ermöglicht das Hinzufügen neuer Kompetenzen (Skills) inklusive Zuordnung zu verschiedenen Gesprächsbereichen.
 * Zudem werden alle vorhandenen Kompetenzen angezeigt, um sie bei Bedarf zu bearbeiten.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// Gesprächsbereiche laden
$gespraechsbereiche = [];
$stmtBereiche = $conn->prepare("SELECT id, name FROM gesprächsbereiche");
$stmtBereiche->execute();
$resBereiche = $stmtBereiche->get_result();
while ($row = $resBereiche->fetch_assoc()) {
    $gespraechsbereiche[] = $row;
}
$stmtBereiche->close();

// Wenn POST: Neue Kompetenz hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['new_skill'], $_POST['kategorie'], $_POST['positionen'])) {
    $newSkill = $_POST['new_skill'];
    $kategorie = $_POST['kategorie'];
    $positions = $_POST['positionen'];

    // 1) Einfügen in skills
    $stmtIns = $conn->prepare("INSERT INTO skills (name, kategorie) VALUES (?, ?)");
    $stmtIns->bind_param("ss", $newSkill, $kategorie);
    $stmtIns->execute();
    $newSkillId = $stmtIns->insert_id;
    $stmtIns->close();

    // 2) Zuordnung zu Gesprächsbereichen
    foreach ($positions as $gesprId) {
        $stmtLink = $conn->prepare("
            INSERT INTO skills_zu_gesprächsbereich (skill_id, gesprächsbereich_id)
            VALUES (?, ?)
        ");
        $stmtLink->bind_param("ii", $newSkillId, $gesprId);
        $stmtLink->execute();
        $stmtLink->close();
    }
}

// Bestehende Kompetenzen laden (mit Gesprächsbereich-Liste)
$stmtSkills = $conn->prepare("
    SELECT
        s.id,
        s.name,
        s.kategorie,
        GROUP_CONCAT(gb.name SEPARATOR ', ') AS bereiche
    FROM skills s
    JOIN skills_zu_gesprächsbereich sg
         ON s.id = sg.skill_id
    JOIN gesprächsbereiche gb
         ON sg.gesprächsbereich_id = gb.id
    GROUP BY s.id, s.name, s.kategorie
");
$stmtSkills->execute();
$resSkills = $stmtSkills->get_result();
$allSkills = [];
while ($row = $resSkills->fetch_assoc()) {
    $allSkills[] = $row;
}
$stmtSkills->close();

// Kategorien definieren
$kategorien = [
    "Anwenderkenntnisse",
    "Positionsspezifische Kompetenzen",
    "Persönliche Kompetenzen",
    "Führungskompetenzen"
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kompetenzen-Verwaltung</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <style>
        select[multiple] {
            height: auto;
        }

        .divider {
            margin: 2rem 0;
            border-bottom: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container content">
    <h1 class="text-center mb-3">Kompetenzen-Verwaltung</h1>
    <div class="divider"></div>

    <!-- Neues Skill hinzufügen -->
    <form method="post" class="mb-3">
        <div class="mb-3">
            <label for="new_skill" class="form-label">Neue Kompetenz:</label>
            <input type="text" class="form-control" id="new_skill" name="new_skill" required>
        </div>
        <div class="mb-3">
            <label for="kategorie" class="form-label">Kategorie:</label>
            <select class="form-select" id="kategorie" name="kategorie" required>
                <?php foreach ($kategorien as $kat): ?>
                    <option value="<?php echo htmlspecialchars($kat); ?>">
                        <?php echo htmlspecialchars($kat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="positionen" class="form-label">Zugeordnete Gesprächsbereiche:</label>
            <select multiple class="form-select" id="positionen" name="positionen[]" required>
                <?php foreach ($gespraechsbereiche as $gb): ?>
                    <option value="<?php echo (int)$gb['id']; ?>">
                        <?php echo htmlspecialchars($gb['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Hinzufügen</button>
    </form>

    <div class="divider"></div>
    <h2 class="mb-3">Vorhandene Kompetenzen</h2>
    <table class="table table-striped table-bordered">
        <thead>
        <tr>
            <th>Kompetenz</th>
            <th>Kategorie</th>
            <th>Gesprächsbereiche</th>
            <th>Aktionen</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($allSkills as $skill): ?>
            <tr>
                <td><?php echo htmlspecialchars($skill['name']); ?></td>
                <td><?php echo htmlspecialchars($skill['kategorie']); ?></td>
                <td><?php echo htmlspecialchars($skill['bereiche']); ?></td>
                <td>
                    <a href="edit_skill.php?id=<?php echo (int)$skill['id']; ?>"
                       class="btn btn-warning btn-sm">Bearbeiten</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Dynamische Höhe für Multiple-Select
        var sel = document.getElementById('positionen');
        if (sel) {
            sel.size = sel.options.length;
        }
    });
</script>
</body>
</html>