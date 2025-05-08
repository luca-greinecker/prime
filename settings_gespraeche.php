<?php
/**
 * settings_gespraeche.php
 *
 * Verwaltung der Gesprächszeiträume für Mitarbeitergespräche / Talent Reviews.
 * Admin/HR können hier neue Zeiträume anlegen oder bestehende bearbeiten.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// 1) POST => Speichern (INSERT oder UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $period_id = isset($_POST['period_id']) ? (int)$_POST['period_id'] : 0;
    $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
    $startMonth = isset($_POST['start_month']) ? (int)$_POST['start_month'] : 0;
    $startYear = isset($_POST['start_year']) ? (int)$_POST['start_year'] : 0;
    $endMonth = isset($_POST['end_month']) ? (int)$_POST['end_month'] : 0;
    $endYear = isset($_POST['end_year']) ? (int)$_POST['end_year'] : 0;

    // A) Bearbeiten (update)
    if ($period_id > 0) {
        // year bleibt unverändert, falls man es nicht ändern darf
        // optional kann man year aus DB holen, um es zu fixieren
        $sqlGetYear = "SELECT year FROM review_periods WHERE id = ? LIMIT 1";
        $stmt = $conn->prepare($sqlGetYear);
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $resY = $stmt->get_result();
        $rowY = $resY->fetch_assoc();
        $stmt->close();

        // $fixedYear = ($rowY) ? (int)$rowY['year'] : $year; // Falls wir year beibehalten wollen

        $sqlUpdate = "
            UPDATE review_periods
            SET start_month = ?,
                start_year  = ?,
                end_month   = ?,
                end_year    = ?
            WHERE id = ?
        ";
        $stmt2 = $conn->prepare($sqlUpdate);
        $stmt2->bind_param("iiiii", $startMonth, $startYear, $endMonth, $endYear, $period_id);
        $stmt2->execute();
        $stmt2->close();

    } else {
        // B) Neuer Zeitraum (insert)
        $sqlInsert = "
            INSERT INTO review_periods (year, start_month, start_year, end_month, end_year)
            VALUES (?, ?, ?, ?, ?)
        ";
        $stmt2 = $conn->prepare($sqlInsert);
        $stmt2->bind_param("iiiii", $year, $startMonth, $startYear, $endMonth, $endYear);
        $stmt2->execute();
        $stmt2->close();
    }

    // Erfolgsmeldung
    $_SESSION['message'] = 'Gesprächszeitraum erfolgreich gespeichert!';
    header("Location: settings_gespraeche.php");
    exit;
}

// 2) Bearbeiten-Modus?
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editData = null;
if ($edit_id > 0) {
    $stmtE = $conn->prepare("SELECT * FROM review_periods WHERE id = ? LIMIT 1");
    $stmtE->bind_param("i", $edit_id);
    $stmtE->execute();
    $resE = $stmtE->get_result();
    $editData = $resE->fetch_assoc();
    $stmtE->close();
}

// 3) Alle Zeiträume auflisten
$sqlAll = "SELECT * FROM review_periods ORDER BY year DESC, start_year DESC, start_month DESC";
$resAll = $conn->query($sqlAll);
$allPeriods = [];
while ($row = $resAll->fetch_assoc()) {
    $allPeriods[] = $row;
}
$resAll->close();
?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Einstellungen - MA-Gespräche/Talent Reviews</title>
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
    </head>
    <body>
    <?php include 'navbar.php'; ?>
    <div class="container content">
        <h1 class="text-center mb-3">Einstellungen - MA-Gespräche/Talent Reviews</h1>
        <div class="divider"></div>

        <?php
        if (!empty($_SESSION['message'])) {
            echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>';
            unset($_SESSION['message']);
        }
        ?>

        <!-- Liste vorhandener Zeiträume -->
        <h2 class="mt-4">Vorhandene Zeiträume</h2>
        <?php if (empty($allPeriods)): ?>
            <div class="alert alert-warning">Keine Gesprächszeiträume vorhanden.</div>
        <?php else: ?>
            <table class="table table-bordered">
                <thead class="table-light">
                <tr>
                    <th>Gesprächsjahr</th>
                    <th>Start (Monat.Jahr)</th>
                    <th>Ende (Monat.Jahr)</th>
                    <th>Aktion</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($allPeriods as $p): ?>
                    <tr>
                        <td><?php echo (int)$p['year']; ?></td>
                        <td>
                            <?php echo sprintf('%02d.%04d',
                                (int)$p['start_month'],
                                (int)$p['start_year']
                            ); ?>
                        </td>
                        <td>
                            <?php echo sprintf('%02d.%04d',
                                (int)$p['end_month'],
                                (int)$p['end_year']
                            ); ?>
                        </td>
                        <td>
                            <a href="settings_gespraeche.php?edit_id=<?php echo (int)$p['id']; ?>"
                               class="btn btn-sm btn-secondary">
                                Bearbeiten
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="divider my-4"></div>

        <!-- Formular zum Anlegen/Bearbeiten -->
        <?php
        $period_id = $editData ? (int)$editData['id'] : 0;
        $year = $editData ? (int)$editData['year'] : (int)date('Y');
        $startMonth = $editData ? (int)$editData['start_month'] : 1;
        $startYear = $editData ? (int)$editData['start_year'] : (int)date('Y');
        $endMonth = $editData ? (int)$editData['end_month'] : 12;
        $endYear = $editData ? (int)$editData['end_year'] : (int)date('Y');

        $yearDisabled = $editData ? 'disabled' : '';
        ?>
        <h2><?php echo $editData ? 'Zeitraum bearbeiten' : 'Neuen Zeitraum anlegen'; ?></h2>
        <form action="settings_gespraeche.php" method="POST" class="row gy-3">
            <input type="hidden" name="period_id" value="<?php echo $period_id; ?>">

            <div class="col-md-2">
                <label for="year" class="form-label">Gesprächsjahr</label>
                <input type="number"
                       class="form-control"
                       id="year"
                       name="year"
                       min="2000"
                       max="2100"
                       value="<?php echo htmlspecialchars($year); ?>"
                    <?php echo $yearDisabled; ?>
                       required>
            </div>
            <div class="col-md-2">
                <label for="start_month" class="form-label">Startmonat</label>
                <input type="number"
                       class="form-control"
                       id="start_month"
                       name="start_month"
                       min="1"
                       max="12"
                       value="<?php echo htmlspecialchars($startMonth); ?>"
                       required>
            </div>
            <div class="col-md-2">
                <label for="start_year" class="form-label">Startjahr</label>
                <input type="number"
                       class="form-control"
                       id="start_year"
                       name="start_year"
                       min="2000"
                       max="2100"
                       value="<?php echo htmlspecialchars($startYear); ?>"
                       required>
            </div>
            <div class="col-md-2">
                <label for="end_month" class="form-label">Endmonat</label>
                <input type="number"
                       class="form-control"
                       id="end_month"
                       name="end_month"
                       min="1"
                       max="12"
                       value="<?php echo htmlspecialchars($endMonth); ?>"
                       required>
            </div>
            <div class="col-md-2">
                <label for="end_year" class="form-label">Endjahr</label>
                <input type="number"
                       class="form-control"
                       id="end_year"
                       name="end_year"
                       min="2000"
                       max="2100"
                       value="<?php echo htmlspecialchars($endYear); ?>"
                       required>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <?php echo $editData ? 'Änderungen speichern' : 'Neuen Zeitraum anlegen'; ?>
                </button>
            </div>
        </form>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
<?php
$conn->close();
?>