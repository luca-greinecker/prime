<?php
/**
 * dashboard.php
 *
 * Dashboard für Führungskräfte, das die ausgelagerten Funktionen aus dashboard_helpers.php nutzt.
 * Zusätzlich werden in den Diagrammen nur Mitarbeiter berücksichtigt, die nicht zu neu sind
 * (d.h. deren entry_date <= Cutoff-Datum, hier 1. Oktober des Gesprächsjahres).
 */

// 1) Zugriffskontrolle, Session etc.
include_once 'access_control.php';
pruefe_benutzer_eingeloggt();

// 2) Helper-Funktionen laden (Gesprächsjahr, UNION-Abfragen etc.)
include_once 'dashboard_helpers.php';

global $conn;

// 3) Gesprächsjahr ermitteln
$conversation_year = ermittleAktuellesGespraechsjahr($conn);
if (!$conversation_year) {
    echo "<p>Kein aktives Gesprächsjahr definiert (review_periods ist leer?).</p>";
    exit;
}

// 4) Cutoff-Datum: 1.10. des ermittelten Gesprächsjahres
$cutoff_date = $conversation_year . '-10-01';

// 5) Prüfen, ob das Dashboard für dieses Jahr noch aktiv ist
$dashboard_active = dashboardIstAktiv($conversation_year);

// 6) Unterstellte Mitarbeiter ermitteln (z.B. aus access_control)
$manager_id = $_SESSION['mitarbeiter_id'];
$unterstellte_mitarbeiter = hole_alle_unterstellten_mitarbeiter($manager_id);

// Zusätzlich: Falls der Manager "Schichtmeister - Stv." ist,
// sollen nur Mitarbeiter mit Position "ISA/Necker" berücksichtigt werden.
$managerData = $conn->query("SELECT position FROM employees WHERE employee_id = $manager_id")->fetch_assoc();
if ($managerData['position'] === 'Schichtmeister - Stv.') {
    $filtered = [];
    foreach ($unterstellte_mitarbeiter as $emp_id) {
        $empData = $conn->query("SELECT position FROM employees WHERE employee_id = $emp_id")->fetch_assoc();
        if ($empData && $empData['position'] === 'ISA/Necker') {
            $filtered[] = $emp_id;
        }
    }
    $unterstellte_mitarbeiter = $filtered;
}

if (empty($unterstellte_mitarbeiter)) {
    echo "<p>Keine unterstellten Mitarbeiter gefunden.</p>";
    exit;
}

// 7) Filtere nur Mitarbeiter, die laut entry_date gültig sind
$gueltige_mitarbeiter = filterGueltigeMitarbeiterIDs($conn, $unterstellte_mitarbeiter, $cutoff_date);
if (empty($gueltige_mitarbeiter)) {
    echo "<p>Keine unterstellten Mitarbeiter gefunden, die alt genug für Gespräche sind.</p>";
    exit;
}

// 8) Abfragen: Gespräche & Talent Reviews (beachten, dass die Helper-Queries bereits in ihren WHERE-Bedingungen entry_date prüfen)
$gespraecheData = holeGespraecheTalentReviews($conn, $gueltige_mitarbeiter, $cutoff_date);
$gespraeche_durchgefuehrt = $gespraecheData['gespraeche'];
$talent_reviews_durchgefuehrt = $gespraecheData['talent_reviews'];

// 9) Lohnerhöhungen
$lohnerhoehungen = holeLohnerhoehungen($conn, $gueltige_mitarbeiter, $cutoff_date);

// 10) Weiterbildungen (UNION)
$weiterbildungen = holeWeiterbildungen($conn, $gueltige_mitarbeiter, $cutoff_date);

// 11) Zufriedenheit
$zufriedenheit = holeZufriedenheit($conn, $gueltige_mitarbeiter, $cutoff_date);
$zufrieden = $zufriedenheit['zufrieden'];
$grundsaetzlich_zufrieden = $zufriedenheit['grundsaetzlich_zufrieden'];
$unzufrieden = $zufriedenheit['unzufrieden'];

// 12) Unzufriedene Mitarbeiter
$unzufriedene_details = holeUnzufriedene($conn, $gueltige_mitarbeiter, $cutoff_date);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chart-container {
            position: relative;
        }

        .chart-note {
            position: absolute;
            top: 90%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: .8rem;
            color: #fff;
            background: rgba(0, 0, 0, 0.6);
            padding: 6px 12px;
            border-radius: 5px;
            pointer-events: none;
            text-align: center;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <h1 class="text-center">Dashboard für Führungskräfte</h1>
    <h2 class="text-center">Gesprächsjahr <?php echo $conversation_year; ?></h2>
    <hr>

    <?php if (!$dashboard_active): ?>
        <div class="alert alert-info text-center">
            Das Dashboard für das Gesprächsjahr <?php echo $conversation_year; ?> ist abgelaufen.<br>
            Ab dem 01.08.<?php echo $conversation_year + 1; ?> gilt das neue Jahr.
        </div>
    <?php else: ?>
        <!-- Diagramme in Cards oder Grid -->
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header text-center">
                        <strong>Mitarbeitergespräche</strong>
                    </div>
                    <div class="card-body">
                        <canvas id="gespraecheChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header text-center">
                        <strong>Talent Reviews</strong>
                    </div>
                    <div class="card-body">
                        <canvas id="talentReviewsChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header text-center">
                        <strong>Mitarbeiterzufriedenheit</strong>
                    </div>
                    <div class="card-body position-relative">
                        <div class="chart-container">
                            <canvas id="zufriedenheitChart"></canvas>
                            <?php if ($unzufrieden > 0): ?>
                                <div class="chart-note">
                                    Klicken Sie auf den roten Bereich für Details
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabelle: Lohnerhöhungen -->
        <div class="card mb-4">
            <div class="card-header">
                <strong>Angeforderte Lohnerhöhungen</strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Mitarbeiter</th>
                        <th>Führungskraft</th>
                        <th>Lohnart</th>
                        <th>Begründung</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $lohnerhoehungen->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['mitarbeiter_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['fuehrungskraft_name'] ?: 'Keine Angabe'); ?></td>
                            <td><?php echo htmlspecialchars($row['lohnart'] ?: 'Keine Angabe'); ?></td>
                            <td><?php echo htmlspecialchars($row['tr_salary_increase_argumentation'] ?: 'Keine Begründung'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tabelle: Weiterbildungen -->
        <div class="card mb-4">
            <div class="card-header">
                <strong>Angeforderte Weiterbildungen</strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Mitarbeiter</th>
                        <th>Führungskraft</th>
                        <th>Typ</th>
                        <th>Weiterbildung</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $weiterbildungen->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['mitarbeiter_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['fuehrungskraft_name'] ?: 'Keine Angabe'); ?></td>
                            <td><?php echo htmlspecialchars($row['typ']); ?></td>
                            <td><?php echo htmlspecialchars($row['weiterbildung'] ?: 'Keine Angabe'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal: Unzufriedene Mitarbeiter -->
        <div class="modal fade" id="unzufriedenModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Unzufriedene Mitarbeiter</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($unzufriedene_details)): ?>
                            <table class="table table-striped">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Begründung</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($unzufriedene_details as $detail): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($detail['name']); ?></td>
                                        <td><?php echo htmlspecialchars($detail['unzufriedenheit_grund']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>Keine unzufriedenen Mitarbeiter gefunden.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    // Chart.js: Mitarbeitergespräche
    var gespraecheCtx = document.getElementById('gespraecheChart').getContext('2d');
    var gespraecheChart = new Chart(gespraecheCtx, {
        type: 'doughnut',
        data: {
            labels: ['Durchgeführt', 'Ausstehend'],
            datasets: [{
                data: [
                    <?php echo $gespraeche_durchgefuehrt; ?>,
                    <?php echo count($gueltige_mitarbeiter) - $gespraeche_durchgefuehrt; ?>
                ],
                backgroundColor: ['#28a745', '#dc3545']
            }]
        }
    });

    // Chart.js: Talent Reviews
    var talentReviewsCtx = document.getElementById('talentReviewsChart').getContext('2d');
    var talentReviewsChart = new Chart(talentReviewsCtx, {
        type: 'doughnut',
        data: {
            labels: ['Durchgeführt', 'Ausstehend'],
            datasets: [{
                data: [
                    <?php echo $talent_reviews_durchgefuehrt; ?>,
                    <?php echo count($gueltige_mitarbeiter) - $talent_reviews_durchgefuehrt; ?>
                ],
                backgroundColor: ['#28a745', '#dc3545']
            }]
        }
    });

    // Chart.js: Zufriedenheit (mit Overlay-Hinweis)
    var zufriedenheitCtx = document.getElementById('zufriedenheitChart').getContext('2d');
    var zufriedenheitChart = new Chart(zufriedenheitCtx, {
        type: 'doughnut',
        data: {
            labels: ['Zufrieden', 'Grundsätzlich zufrieden', 'Unzufrieden'],
            datasets: [{
                data: [
                    <?php echo $zufrieden; ?>,
                    <?php echo $grundsaetzlich_zufrieden; ?>,
                    <?php echo $unzufrieden; ?>
                ],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545']
            }]
        },
        options: {
            onClick: function (evt, activeEls) {
                if (activeEls.length > 0) {
                    var firstPoint = activeEls[0];
                    var label = zufriedenheitChart.data.labels[firstPoint.index];
                    if (label === 'Unzufrieden') {
                        var modal = new bootstrap.Modal(document.getElementById('unzufriedenModal'));
                        modal.show();
                    }
                }
            }
        }
    });
</script>
</body>
</html>
<?php
$conn->close();
?>
