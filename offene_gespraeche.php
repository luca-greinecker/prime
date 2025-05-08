<?php
/**
 * offene_gespraeche.php
 *
 * Zeigt eine Übersicht aller Mitarbeiter (jeweils gruppiert nach Führungskraft),
 * bei denen im aktuellen aktiven Zeitraum noch kein Mitarbeitergespräch durchgeführt wurde.
 * Nur für HR oder Admin.
 */

include_once 'access_control.php';
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff(); // Nur HR/Admin

global $conn;

// Aktuelles Datum und vorhandene Zeiträume
$today = new DateTime();
$sql = "SELECT * FROM review_periods ORDER BY start_year, start_month";
$stmt = $conn->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
$allPeriods = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activePeriod = null;
foreach ($allPeriods as $period) {
    $startDateStr = sprintf('%04d-%02d-01', $period['start_year'], $period['start_month']);
    $endDateStr = sprintf('%04d-%02d-31', $period['end_year'], $period['end_month']);
    $startDate = new DateTime($startDateStr);
    $endDate = new DateTime($endDateStr);

    if ($today >= $startDate && $today <= $endDate) {
        $activePeriod = $period;
        break;
    }
}

if ($activePeriod) {
    $startYear = $activePeriod['start_year'];
    $startMonth = $activePeriod['start_month'];
    $endYear = $activePeriod['end_year'];
    $endMonth = $activePeriod['end_month'];
    $endDay = cal_days_in_month(CAL_GREGORIAN, $endMonth, $endYear);

    $start_date = sprintf('%04d-%02d-01', $startYear, $startMonth);
    $end_date = sprintf('%04d-%02d-%02d', $endYear, $endMonth, $endDay);
} else {
    // Fallback: ganzes Kalenderjahr
    $y = date('Y');
    $start_date = "$y-01-01";
    $end_date = "$y-12-31";
}

// Führungskräfte anhand definierter Positionen
$fuehrungspositionen = [
    "Schichtmeister",
    "Schichtmeister - Stv.",
    "Frontend - TL",
    "Druckmaschine - TL",
    "Palletierer/MGA - TL",
    "Tagschicht - Elektrik | AL",
    "Tagschicht - Mechanik | AL",
    "Tagschicht - Mechanik | TL FE",
    "Tagschicht - Mechanik | TL BE",
    "Tagschicht - CPO | AL",
    "Tagschicht - Sortierung | TL",
    "Tagschicht - Qualitätssicherung | AL"
];
$placeholders = implode(',', array_fill(0, count($fuehrungspositionen), '?'));
$types = str_repeat('s', count($fuehrungspositionen));

$sql_manager = "
    SELECT employee_id, name, position, crew
    FROM employees
    WHERE position IN ($placeholders)
";
$stmt = $conn->prepare($sql_manager);
$stmt->bind_param($types, ...$fuehrungspositionen);
$stmt->execute();
$res = $stmt->get_result();

$alle_manager = [];
while ($mgr = $res->fetch_assoc()) {
    $alle_manager[] = $mgr;
}
$stmt->close();

// Offene Gespräche
$manager_offen = [];
foreach ($alle_manager as $manager) {
    $managerId = $manager['employee_id'];
    $unterstellte = hole_unterstellte_mitarbeiter($managerId);
    if (empty($unterstellte)) {
        continue;
    }

    $ph = implode(',', array_fill(0, count($unterstellte), '?'));
    $ty = str_repeat('i', count($unterstellte));

    $sql_offene = "
        SELECT 
            e.employee_id,
            e.name AS mitarbeiter_name,
            e.lohnschema,
            e.position,
            e.crew
        FROM employees e
        WHERE e.employee_id IN ($ph)
          AND e.lohnschema IN ('Produktion','Technik')
          AND e.employee_id NOT IN (
              SELECT employee_id
              FROM employee_reviews
              WHERE date BETWEEN ? AND ?
          )
        ORDER BY
          FIELD(e.lohnschema, 'Produktion','Technik','Unbekannt'),
          e.crew ASC,
          e.name ASC
    ";

    $stmt = $conn->prepare($sql_offene);
    $params = array_merge($unterstellte, [$start_date, $end_date]);
    $stmt->bind_param($ty . 'ss', ...$params);
    $stmt->execute();
    $res_offen = $stmt->get_result();

    $offene = [];
    while ($row = $res_offen->fetch_assoc()) {
        $offene[] = $row;
    }
    $stmt->close();

    if (!empty($offene)) {
        $manager_offen[] = [
            'manager' => $manager,
            'offene_mitarbeiter' => $offene
        ];
    }
}

// Sortierung nach Crew
$managers_by_crew = [];
foreach ($manager_offen as $entry) {
    $crewName = $entry['manager']['crew'] ?: 'Keine Angabe';
    $managers_by_crew[$crewName][] = $entry;
}
ksort($managers_by_crew, SORT_NATURAL | SORT_FLAG_CASE);

function bereinigePosition($position)
{
    if (!$position) return 'Keine Angabe';
    $posLower = mb_strtolower($position);
    if (strpos($posLower, 'mechanik') !== false) {
        return 'Mechanik';
    } elseif (strpos($posLower, 'elektrik') !== false) {
        return 'Elektrik';
    }
    return $position;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Offene Mitarbeitergespräche</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        .divider {
            margin: 2rem 0;
            border-bottom: 1px solid #ccc;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container content">
    <h1 class="text-center">Offene Mitarbeitergespräche</h1>
    <div class="divider"></div>
    <button type="button" class="btn btn-secondary mb-4" onclick="history.back()">Zurück</button>

    <?php if (!$activePeriod): ?>
        <div class="alert alert-warning">
            Kein aktiver Gesprächszeitraum definiert.<br>
            Verwende Fallback: <?php echo $start_date . ' bis ' . $end_date; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            Aktiver Zeitraum: <?php echo $start_date . ' bis ' . $end_date; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($managers_by_crew)): ?>
        <p>Keine offenen Gespräche gefunden.</p>
    <?php else: ?>
        <?php foreach ($managers_by_crew as $crewName => $managerList): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Crew:</strong> <?php echo htmlspecialchars($crewName); ?>
                </div>
                <div class="card-body">
                    <?php foreach ($managerList as $m):
                        $manager = $m['manager'];
                        $offeneList = $m['offene_mitarbeiter'];
                        ?>
                        <div class="card mb-3">
                            <div class="card-header">
                                <strong>Führungskraft:</strong>
                                <?php echo htmlspecialchars($manager['name']); ?>
                                (<?php echo htmlspecialchars($manager['position']); ?>)
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Mitarbeiter</th>
                                        <th>Crew/Position</th>
                                        <th>Lohnschema</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($offeneList as $offen): ?>
                                        <?php
                                        $info = $offen['lohnschema'] === 'Produktion'
                                            ? ($offen['crew'] ?: 'Keine Angabe')
                                            : bereinigePosition($offen['position']);
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($offen['mitarbeiter_name']); ?></td>
                                            <td><?php echo htmlspecialchars($info); ?></td>
                                            <td><?php echo htmlspecialchars($offen['lohnschema']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>