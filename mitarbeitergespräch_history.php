<?php
/**
 * mitarbeitergespraeche_history.php
 *
 * Zeigt eine Übersicht aller durchgeführten Mitarbeitergespräche in einem
 * bestimmten Jahr/Zeitraum, basierend auf den Einträgen in `review_periods`.
 * Admins oder HR können per Dropdown das Jahr auswählen.
 * Das Skript findet den zugehörigen Zeitraum und zeigt alle Mitarbeitergespräche
 * an, die im Bereich start_date..end_date liegen.
 */

include 'access_control.php';
global $conn;

// Nur eingeloggt + Admin/HR
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// 1) Alle Jahre aus review_periods laden
$sqlYears = "SELECT year FROM review_periods ORDER BY year DESC";
$resY = $conn->query($sqlYears);

$availableYears = [];
while ($row = $resY->fetch_assoc()) {
    $availableYears[] = (int)$row['year'];
}

// Standard = aktuelles Jahr
$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

// Falls das gewählte Jahr nicht in $availableYears existiert,
// nimm (z. B.) den neuesten Eintrag, sofern vorhanden
if (!in_array($selectedYear, $availableYears) && !empty($availableYears)) {
    $selectedYear = $availableYears[0];
}

// 2) Aus review_periods das Jahr holen
$stmt = $conn->prepare("SELECT * FROM review_periods WHERE year = ? LIMIT 1");
$stmt->bind_param("i", $selectedYear);
$stmt->execute();
$resPeriod = $stmt->get_result();
$period = $resPeriod->fetch_assoc();
$stmt->close();

// In $period sind start_month, start_year, end_month, end_year
// Falls nicht vorhanden => leere Liste
$reviews = [];

if ($period) {
    // Start- und Enddatum berechnen
    $startDateStr = sprintf('%04d-%02d-01', $period['start_year'], $period['start_month']);
    $endDay = cal_days_in_month(CAL_GREGORIAN, $period['end_month'], $period['end_year']);
    $endDateStr = sprintf('%04d-%02d-%02d', $period['end_year'], $period['end_month'], $endDay);

    // 3) Mitarbeitergespräche in diesem Zeitraum
    $stmt2 = $conn->prepare("
        SELECT er.id, er.employee_id, er.date, er.tr_date, er.reviewer_id,
               e.name AS employee_name,
               r.name AS reviewer_name
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id
        JOIN employees r ON er.reviewer_id = r.employee_id
        WHERE er.date BETWEEN ? AND ?
        ORDER BY er.date DESC
    ");
    $stmt2->bind_param("ss", $startDateStr, $endDateStr);
    $stmt2->execute();
    $resReviews = $stmt2->get_result();
    while ($row = $resReviews->fetch_assoc()) {
        $reviews[] = $row;
    }
    $stmt2->close();
}

// Hilfsfunktion: "Neu?" = <= 2 Tage
function is_recent($dateStr)
{
    $reviewTs = strtotime($dateStr);
    $now = time();
    $diffDays = ($now - $reviewTs) / (60 * 60 * 24);
    return ($diffDays <= 2 && $diffDays >= 0);
}

?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Mitarbeitergespräch-History</title>
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
        <style>
            .divider {
                margin: 2rem 0;
                border-bottom: 1px solid #dee2e6;
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>

    <div class="container content">
        <h1 class="text-center mb-3">Mitarbeitergespräch-History</h1>
        <div class="divider"></div>

        <!-- Dropdown Jahresauswahl -->
        <form method="get" class="mb-4">
            <div class="row align-items-center">
                <div class="col-auto">
                    <label for="year" class="form-label mb-0 me-2">Gesprächsjahr wählen:</label>
                </div>
                <div class="col-auto">
                    <select id="year" name="year" class="form-select">
                        <?php foreach ($availableYears as $y): ?>
                            <option value="<?php echo $y; ?>"
                                <?php if ($y === $selectedYear) echo 'selected'; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Anzeigen</button>
                </div>
            </div>
        </form>

        <!-- Meldung, falls kein Zeitraum -->
        <?php if (!$period && !empty($availableYears)): ?>
            <div class="alert alert-warning">
                Für das Jahr <?php echo htmlspecialchars($selectedYear); ?>
                wurde kein Gesprächszeitraum definiert.
            </div>
        <?php endif; ?>

        <!-- Liste der Mitarbeitergespräche -->
        <?php if (!empty($reviews)): ?>
            <ul class="list-group">
                <?php foreach ($reviews as $rev): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <a href="mitarbeitergespräch_detail.php?id=<?php echo (int)$rev['id']; ?>">
                                <strong><?php echo htmlspecialchars($rev['employee_name']); ?></strong>
                                – geführt von <?php echo htmlspecialchars($rev['reviewer_name']); ?>
                                am <?php echo date("d.m.Y", strtotime($rev['date'])); ?>
                            </a>
                        </div>
                        <div>
                            <?php if (is_recent($rev['date'])): ?>
                                <span class="badge bg-warning text-dark me-1">Neu</span>
                            <?php endif; ?>
                            <?php if (!empty($rev['tr_date'])): ?>
                                <span class="badge bg-success">TR</span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Keine Mitarbeitergespräche in diesem Zeitraum gefunden.</p>
        <?php endif; ?>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
<?php
$conn->close();
?>