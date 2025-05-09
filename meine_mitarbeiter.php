<?php
/**
 * meine_mitarbeiter.php
 *
 * Zeigt dem aktuellen Benutzer (z.B. Teamleiter, Abteilungsleiter) seine direkt unterstellten Mitarbeiter an,
 * inklusive Status zum Mitarbeitergespräch und Talent Review für den aktuellen oder kommenden Zeitraum.
 * Zusätzlich wird bei zu neu eingestellten Mitarbeitern (Entry nach dem Cutoff) statt des Start-Buttons ein Hinweis angezeigt.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Zentrale Helper-Funktionen laden (inkl. Gesprächsjahr-Logik und Prüfungen)
include_once 'dashboard_helpers.php';

// Aktuelles Gesprächsjahr ermitteln und Cutoff-Datum (1. Oktober)
$conversation_year = ermittleAktuellesGespraechsjahr($conn);
$cutoff_date = $conversation_year . '-10-01';

// Mitarbeiter-ID (employee_id) aus der Session
$mitarbeiter_id = $_SESSION['mitarbeiter_id'];

// Mitarbeiterinformationen abrufen (crew, position, gruppe)
$stmt = $conn->prepare("
    SELECT crew, position, gruppe
    FROM employees
    WHERE employee_id = ?
");
if (!$stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $mitarbeiter_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    // Falls kein Datensatz: Sicherheits-Exit
    header("Location: access_denied.php");
    exit;
}

$position = $user['position'];
$crew = $user['crew'];
$gruppe = $user['gruppe'];

// Überprüfen, ob der Benutzer Zugriff auf den Führungsbereich hat
if (!pruefe_fuehrung_zugriff($position)) {
    header("Location: access_denied.php");
    exit;
}

// Unterstellte Mitarbeiter ermitteln (liefert IDs)
$employee_ids = hole_unterstellte_mitarbeiter($mitarbeiter_id);
// Doppelte IDs entfernen
$employee_ids = array_unique($employee_ids);

$employees = [];
if (!empty($employee_ids)) {
    // Mitarbeiter-Datensätze abrufen – zusätzlich entry_date berücksichtigen
    $placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
    $types = str_repeat('i', count($employee_ids));
    $query = "
        SELECT employee_id, name, birthdate, entry_date, anwesend, status
        FROM employees
        WHERE employee_id IN ($placeholders)
        ORDER BY name ASC
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        die("Abfrage konnte nicht vorbereitet werden: " . $conn->error);
    }
    $stmt->bind_param($types, ...$employee_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 1) Daten für Review-Zeiträume laden
$sql = "SELECT * FROM review_periods ORDER BY start_year, start_month ASC";
$period_stmt = $conn->prepare($sql);
$period_stmt->execute();
$result = $period_stmt->get_result();
$allPeriods = $result->fetch_all(MYSQLI_ASSOC);
$period_stmt->close();

$today = new DateTime();
$inReviewPeriod = false;
$currentPeriod = null;
$nextPeriod = null;

// 2) Aktuellen Zeitraum suchen
foreach ($allPeriods as $period) {
    $startDateStr = sprintf('%04d-%02d-01', $period['start_year'], $period['start_month']);
    $endDateStr = sprintf('%04d-%02d-31', $period['end_year'], $period['end_month']); // vereinfacht "31"

    $startDate = new DateTime($startDateStr);
    $endDate = new DateTime($endDateStr);

    // Prüfen, ob heute in [startDate, endDate] liegt
    if ($today >= $startDate && $today <= $endDate) {
        $currentPeriod = $period;
        $inReviewPeriod = true;
        break;
    }
}

// 3) Falls kein aktueller Zeitraum, nächsten Zeitraum ermitteln
if (!$inReviewPeriod) {
    foreach ($allPeriods as $period) {
        $startDateStr = sprintf('%04d-%02d-01', $period['start_year'], $period['start_month']);
        $startDate = new DateTime($startDateStr);
        if ($startDate > $today) {
            $nextPeriod = $period;
            break;
        }
    }
}

// 4) Start-/Endwerte vorbereiten
$startMonth = null;
$startYear = null;
$endMonth = null;
$endYear = null;

if ($currentPeriod) {
    $startMonth = $currentPeriod['start_month'];
    $startYear = $currentPeriod['start_year'];
    $endMonth = $currentPeriod['end_month'];
    $endYear = $currentPeriod['end_year'];
} elseif ($nextPeriod) {
    $startMonth = $nextPeriod['start_month'];
    $startYear = $nextPeriod['start_year'];
    $endMonth = $nextPeriod['end_month'];
    $endYear = $nextPeriod['end_year'];
}

// 5) Deutsche Monatsnamen
$monatsnamen = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März',
    4 => 'April', 5 => 'Mai', 6 => 'Juni',
    7 => 'Juli', 8 => 'August', 9 => 'September',
    10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
];

// 6) Start/End-Monatsnamen
$startMonthName = $startMonth ? $monatsnamen[$startMonth] : null;
$endMonthName = $endMonth ? $monatsnamen[$endMonth] : null;

// Gesprächs- und Talent Review-Status für jeden Mitarbeiter
foreach ($employees as &$employee) {
    $startDate = sprintf('%04d-%02d-01', $startYear, $startMonth);
    $endDate = sprintf('%04d-%02d-31', $endYear, $endMonth);

    // Mitarbeitergespräch in diesem Zeitraum?
    $stmt = $conn->prepare("
        SELECT id, date, tr_date
        FROM employee_reviews
        WHERE employee_id = ?
          AND date BETWEEN ? AND ?
    ");
    if (!$stmt) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("iss", $employee['employee_id'], $startDate, $endDate);
    $stmt->execute();
    $review_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $employee['review_exists'] = !empty($review_data);
    $employee['review_id'] = $review_data['id'] ?? null;
    $employee['review_date'] = $review_data['date'] ?? null;
    $employee['tr_date'] = $review_data['tr_date'] ?? null;
    $employee['tr_exists'] = !empty($employee['tr_date']);

    // Geburtstag im aktuellen Monat?
    $geburtstag = new DateTime($employee['birthdate']);
    $employee['hat_geburtstag'] = ($geburtstag->format('m') == date('m'));
    $employee['geburtstagsdatum'] = $geburtstag->format('d.m');

    // Anwesenheitsstatus und Label
    $statusLabels = [
        0 => 'Unbekannt',
        1 => 'Krank',
        2 => 'Urlaub',
        3 => 'Schulung'
    ];

    $employee['anwesend_label'] = $employee['anwesend'] ? 'Anwesend' : $statusLabels[$employee['status']] ?? 'Abwesend';
}
unset($employee); // Referenz lösen

// Prüfen, ob der Benutzer ein Leiter ist
$ist_leiter = ist_leiter();

// Alle Mitarbeiter der Abteilung/des Bereichs ermitteln, falls Leiter
$leiter_mitarbeiter = [];
if ($ist_leiter) {
    $leiter_mitarbeiter_ids = hole_alle_unterstellten_mitarbeiter($mitarbeiter_id);
    $leiter_mitarbeiter_ids = array_unique($leiter_mitarbeiter_ids);

    if (!empty($leiter_mitarbeiter_ids)) {
        $placeholders = implode(',', array_fill(0, count($leiter_mitarbeiter_ids), '?'));
        $types = str_repeat('i', count($leiter_mitarbeiter_ids));

        $query = "
            SELECT employee_id, name, birthdate, entry_date, anwesend, status
            FROM employees
            WHERE employee_id IN ($placeholders)
            ORDER BY name ASC
        ";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            die("Abfrage konnte nicht vorbereitet werden: " . $conn->error);
        }
        $stmt->bind_param($types, ...$leiter_mitarbeiter_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $leiter_mitarbeiter = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // Gesprächs- und Talent Review-Status für jeden dieser Mitarbeiter
    foreach ($leiter_mitarbeiter as &$employee) {
        $startDate = sprintf('%04d-%02d-01', $startYear, $startMonth);
        $endDate = sprintf('%04d-%02d-31', $endYear, $endMonth);

        $stmt = $conn->prepare("
            SELECT id, date, tr_date
            FROM employee_reviews
            WHERE employee_id = ?
              AND date BETWEEN ? AND ?
        ");
        if (!$stmt) {
            die("Prepare failed: " . htmlspecialchars($conn->error));
        }
        $stmt->bind_param("iss", $employee['employee_id'], $startDate, $endDate);
        $stmt->execute();
        $review_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $employee['review_exists'] = !empty($review_data);
        $employee['review_id'] = $review_data['id'] ?? null;
        $employee['review_date'] = $review_data['date'] ?? null;
        $employee['tr_date'] = $review_data['tr_date'] ?? null;
        $employee['tr_exists'] = !empty($employee['tr_date']);

        // Geburtstag
        $geburtstag = new DateTime($employee['birthdate']);
        $employee['hat_geburtstag'] = ($geburtstag->format('m') == date('m'));
        $employee['geburtstagsdatum'] = $geburtstag->format('d.m');

        // Anwesenheitsstatus und Label
        $statusLabels = [
            0 => 'Unbekannt',
            1 => 'Krank',
            2 => 'Urlaub',
            3 => 'Schulung'
        ];

        $employee['anwesend_label'] = $employee['anwesend'] ? 'Anwesend' : $statusLabels[$employee['status']] ?? 'Abwesend';
    }
    unset($employee);
}

/**
 * Prüft, ob ein Datum innerhalb der letzten X Tage liegt. Default: 5 Tage
 * @param string $date
 * @param int $daysBack
 * @return bool
 */
function is_recent($date, $daysBack = 5)
{
    if (!$date) {
        return false;
    }
    $reviewDate = strtotime($date);
    $now = strtotime("now");
    $diffInDays = ($now - $reviewDate) / (60 * 60 * 24);

    return ($diffInDays <= $daysBack && $diffInDays >= 0);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Meine Mitarbeiter</title>
    <!-- Lokales Bootstrap + Navbar -->
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

    <style>
        .employee-list ul {
            list-style-type: none;
            padding: 0;
        }

        .employee-list li {
            padding: 5px 0;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .names-meinema {
            width: 19rem;
            display: flex;
            align-items: center;
        }

        .names {
            width: 15rem;
            display: flex;
            align-items: center;
        }

        #badges {
            width: 14rem;
            text-align: right;
        }

        .employee-list a {
            text-decoration: none;
        }

        .btn {
            margin-left: auto;
        }

        .employee-list .btn {
            width: 13.5rem;
            font-size: .95rem;
        }

        .alert-info, .alert-success, .alert-danger {
            padding: 0.25rem 0.9rem !important;
            margin-bottom: 0 !important;
            font-size: .95rem;
        }

        .alert-info, .alert-danger {
            width: 31rem;
            text-align: center;
        }

        .alert-danger.alert-gesperrt {
            width: auto !important;
        }

        .birthday-badge {
            background-color: #ffc107;
            color: white;
            padding: 0.3em 0.7em;
            border-radius: 50px;
            font-weight: bold;
            font-size: .9rem;
            margin-left: .7rem;
        }

        h1.text-center {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        h1 .info-btn {
            margin-left: 1.3rem;
        }

        .info-btn i {
            color: white;
        }

        .popover {
            max-width: 600px !important;
        }

        .thin-divider {
            margin: 1rem 0;
            border-bottom: 1px solid #ddd;
        }

        /* Anwesenheits-Indikator Stile */
        .presence-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 10px;
            position: relative;
        }

        .presence-indicator.present {
            background-color: #28a745; /* Grün für anwesend */
            box-shadow: 0 0 5px rgba(40, 167, 69, 0.6);
        }

        .presence-indicator.absent {
            background-color: #6c757d; /* Grau für abwesend */
        }

        .presence-indicator.sick {
            background-color: #dc3545; /* Rot für krank */
        }

        .presence-indicator.vacation {
            background-color: #0000ff; /* Blau für Urlaub */
        }

        .presence-indicator.training {
            background-color: #ffc107; /* Gelb für Schulung */
        }

        .presence-tooltip {
            visibility: hidden;
            position: absolute;
            z-index: 1;
            width: 120px;
            background-color: #555;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            left: 50%;
            transform: translateX(-50%);
            bottom: 125%;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.8rem;
        }

        .presence-indicator:hover .presence-tooltip {
            visibility: visible;
            opacity: 1;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <h1 class="text-center mb-3">
        Meine Mitarbeiter
        <!-- Info-Button mit Popover -->
        <button type="button" class="btn btn-info info-btn"
                data-bs-toggle="popover"
                data-bs-html="true"
                title="Information - Meine Mitarbeiter"
                data-bs-content="
            Auf dieser Seite sind bei <strong>Meine Mitarbeiter</strong> alle direkt unterstellten Mitarbeiter aufgelistet.
            Das sind auch die Mitarbeiter, die für das Mitarbeitergespräch/Talent Review relevant sind.
            Unter der Überschrift sieht man, ob die Mitarbeitergespräche aktuell freigegeben bzw. noch gesperrt sind.
            Wird ein Mitarbeitergespräch abgeschlossen, erscheint automatisch der Button für das Talent Review.
            <br><br>
            <strong>Für Abteilungs-/Bereichsleiter:</strong>
            Ihr habt noch den Abschnitt <em>Alle Mitarbeiter</em>, wo ihr alle Mitarbeiter eurer Abteilung/eures Bereichs seht.
            <br><br>
            <strong>Allgemein:</strong>
            Falls Mitarbeiter fehlen oder zu viel angezeigt werden, wende dich bitte an Luca (IT-Büro).
            <br><br>
            <strong>Anwesenheits-Indikator:</strong>
            <span style='color: #28a745'>●</span> Anwesend
            <span style='color: #6c757d'>●</span> Abwesend (kein Status)
            <span style='color: #dc3545'>●</span> Krank
            <span style='color: #0000ff'>●</span> Urlaub
            <span style='color: #ffc107'>●</span> Schulung
        ">
            <i class="bi bi-info-circle"></i>
        </button>
    </h1>

    <div class="divider"></div>
    <!-- Status zum Review-Zeitraum -->
    <?php if ($startMonthName && $startYear && $endMonthName && $endYear): ?>
        <div class="alert <?php echo $inReviewPeriod ? 'alert-success' : 'alert-danger alert-gesperrt'; ?> text-center"
             role="alert" style="font-size: 1.5em;">
            <?php if ($inReviewPeriod): ?>
                <strong>Mitarbeitergespräche freigegeben</strong><br>
                (Zeitraum: <?php echo $startMonthName . ' ' . $startYear; ?> bis <?php echo $endMonthName . ' ' . $endYear; ?>)
            <?php else: ?>
                <strong>Mitarbeitergespräche gesperrt</strong><br>
                (Öffnet ab: <?php echo $startMonthName . ' ' . $startYear; ?> bis <?php echo $endMonthName . ' ' . $endYear; ?>)
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-danger alert-gesperrt text-center" role="alert" style="font-size: 1.2em;">
            <strong>Aktuell kein gültiger Gesprächszeitraum definiert</strong>
        </div>
    <?php endif; ?>

    <div class="thin-divider"></div>

    <!-- Direkt unterstellte Mitarbeiter -->
    <div class="row">
        <div class="col-md-12 employee-list">
            <ul>
                <?php if (empty($employees)): ?>
                    <p class="text-center">
                        Keine (direkt unterstellten) Mitarbeiter gefunden.
                        Hier werden nur die relevanten Mitarbeiter <strong>für das Mitarbeitergespräch</strong>
                        aufgelistet.
                        Bei Abweichungen bitte an Luca wenden.
                    </p>
                <?php else: ?>
                    <?php foreach ($employees as $employee): ?>
                        <li>
                            <!-- Name + Anwesenheitsindikator + evtl. Geburtstagsbadge -->
                            <div class="names-meinema">
                                <?php
                                // Bestimme die richtige Klasse für den Indikator
                                $indicatorClass = 'absent';
                                $tooltip = 'Abwesend';

                                if ($employee['anwesend']) {
                                    $indicatorClass = 'present';
                                    $tooltip = 'Anwesend';
                                } else {
                                    // Nicht anwesend - Status prüfen
                                    switch ($employee['status']) {
                                        case 1:
                                            $indicatorClass = 'sick';
                                            $tooltip = 'Krank';
                                            break;
                                        case 2:
                                            $indicatorClass = 'vacation';
                                            $tooltip = 'Urlaub';
                                            break;
                                        case 3:
                                            $indicatorClass = 'training';
                                            $tooltip = 'Schulung';
                                            break;
                                    }
                                }
                                ?>
                                <span class="presence-indicator <?php echo $indicatorClass; ?>">
                                    <span class="presence-tooltip"><?php echo $tooltip; ?></span>
                                </span>
                                <a href="employee_details.php?employee_id=<?php echo htmlspecialchars($employee['employee_id']); ?>">
                                    <?php echo htmlspecialchars($employee['name']); ?>
                                </a>
                                <?php if ($employee['hat_geburtstag']): ?>
                                    <span class="birthday-badge">
                                        🎉 <?php echo htmlspecialchars($employee['geburtstagsdatum']); ?>.
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Buttons/Status -->
                            <?php if ($inReviewPeriod && !$employee['review_exists']): ?>
                                <?php if (!istMitarbeiterGueltigFuerGespraech($employee['entry_date'], $cutoff_date)): ?>
                                    <!-- Hinweis, falls der Mitarbeiter zu neu ist -->
                                    <div class="alert alert-info ml-2">
                                        Mitarbeiter arbeitet zu kurz – Mitarbeitergespräch nicht möglich.
                                    </div>
                                <?php else: ?>
                                    <!-- Mitarbeitergespräch starten -->
                                    <a href="mitarbeitergespraech.php?employee_id=<?php echo $employee['employee_id']; ?>"
                                       class="btn btn-primary btn-sm">
                                        Mitarbeitergespräch starten
                                    </a>
                                <?php endif; ?>
                            <?php elseif ($inReviewPeriod && $employee['review_exists'] && !$employee['tr_exists']): ?>
                                <!-- Talent Review starten -->
                                <a href="talent_review.php?employee_id=<?php echo $employee['employee_id']; ?>"
                                   class="btn btn-warning btn-sm ml-2">
                                    Talent Review starten
                                </a>
                            <?php elseif ($employee['review_exists'] && $employee['tr_exists']): ?>
                                <!-- Prozess abgeschlossen -->
                                <div class="alert alert-success ml-2">
                                    Mitarbeitergespräch durchgeführt am
                                    <?php echo date('d.m.Y', strtotime($employee['review_date'])); ?>
                                    • Talent Review erledigt am
                                    <?php echo date('d.m.Y', strtotime($employee['tr_date'])); ?>
                                    •
                                    <a href="mitarbeitergespräch_detail.php?id=<?php echo $employee['review_id']; ?>"
                                       class="btn-link">
                                        Einsicht
                                    </a>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- Bereich "Alle Mitarbeiter" für Leiter -->
    <?php if ($ist_leiter): ?>
        <h1 class="text-center mb-3">Alle Mitarbeiter</h1>
        <div class="divider"></div>

        <div class="row">
            <div class="col-md-12 employee-list">
                <?php if (!empty($leiter_mitarbeiter)): ?>
                    <ul>
                        <?php foreach ($leiter_mitarbeiter as $employee): ?>
                            <li class="d-flex justify-content-between align-items-center">
                                <div class="names">
                                    <?php
                                    // Bestimme die richtige Klasse für den Indikator
                                    $indicatorClass = 'absent';
                                    $tooltip = 'Abwesend';

                                    if ($employee['anwesend']) {
                                        $indicatorClass = 'present';
                                        $tooltip = 'Anwesend';
                                    } else {
                                        // Nicht anwesend - Status prüfen
                                        switch ($employee['status']) {
                                            case 1:
                                                $indicatorClass = 'sick';
                                                $tooltip = 'Krank';
                                                break;
                                            case 2:
                                                $indicatorClass = 'vacation';
                                                $tooltip = 'Urlaub';
                                                break;
                                            case 3:
                                                $indicatorClass = 'training';
                                                $tooltip = 'Schulung';
                                                break;
                                        }
                                    }
                                    ?>
                                    <span class="presence-indicator <?php echo $indicatorClass; ?>">
                                        <span class="presence-tooltip"><?php echo $tooltip; ?></span>
                                    </span>
                                    <a href="employee_details.php?employee_id=<?php echo htmlspecialchars($employee['employee_id']); ?>">
                                        <?php echo htmlspecialchars($employee['name']); ?>
                                    </a>
                                    <?php if ($employee['hat_geburtstag']): ?>
                                        <span class="birthday-badge">
                                            🎉 <?php echo htmlspecialchars($employee['geburtstagsdatum']); ?>.
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($employee['review_exists']): ?>
                                    <div class="alert alert-info">
                                        Gespräch geführt am
                                        <?php echo date("d.m.Y", strtotime($employee['review_date'])); ?>
                                        -
                                        <a href="mitarbeitergespräch_detail.php?id=<?php echo $employee['review_id']; ?>"
                                           class="btn-link">
                                            Zum Gespräch/Talent Review
                                        </a>
                                    </div>
                                <?php elseif ($inReviewPeriod): ?>
                                    <?php if (!istMitarbeiterGueltigFuerGespraech($employee['entry_date'], $cutoff_date)): ?>
                                        <div class="alert alert-info" role="alert">
                                            Mitarbeiter arbeitet zu kurz – kein MA-Gespräch.
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-danger" role="alert">
                                            Bisher kein MA-Gespräch durchgeführt.
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div id="badges" class="text-end">
                                    <?php
                                    // Badge "Neu" bei kürzlich durchgeführtem Review
                                    if (isset($employee['review_date']) && is_recent($employee['review_date'], 5)): ?>
                                        <span class="badge bg-warning">Neu</span>
                                    <?php endif; ?>
                                    <?php if ($employee['review_exists']): ?>
                                        <span class="badge bg-primary">MAG</span>
                                    <?php endif; ?>
                                    <?php if ($employee['tr_exists']): ?>
                                        <span class="badge bg-success">TR</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <ul>
                        <li class="text-center">Keine Mitarbeiter gefunden.</li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Lokales Bootstrap 5 JavaScript Bundle -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Alle Popovers initialisieren
        const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
        popoverTriggerList.forEach(function (popoverTriggerEl) {
            new bootstrap.Popover(popoverTriggerEl, {
                html: true,
                trigger: 'focus',
                placement: 'bottom'
            });
        });
    });
</script>
</body>
</html>