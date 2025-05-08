<?php
/**
 * safety_history.php
 *
 * Historie-Seite für Sicherheitsschulungen mit Filterung nach Jahr und Quartal
 * sowie Teilnehmerstatistiken.
 */

include 'access_control.php';
include 'training_functions.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Zugriffskontrolle: Nur EHS-Manager, HR und Admins haben Zugriff
if (!ist_ehs() && !ist_hr() && !ist_admin()) {
    header("Location: access_denied.php");
    exit;
}

// Ermittle verfügbare Jahre und Quartale aus vorhandenen Schulungen
$years_query = "
    SELECT DISTINCT YEAR(start_date) as year
    FROM trainings t
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    WHERE mc.name = 'Sicherheit, Gesundheit, Umwelt, Hygiene'
    AND sc.name = 'Sicherheitsschulungen'
    AND t.training_name LIKE '%Q%/%'
    ORDER BY year DESC
";

$stmt = $conn->prepare($years_query);
$stmt->execute();
$result = $stmt->get_result();
$available_years = [];
while ($row = $result->fetch_assoc()) {
    $available_years[] = $row['year'];
}
$stmt->close();

// Wenn keine Jahre gefunden wurden, setze das aktuelle Jahr als Standard
if (empty($available_years)) {
    $available_years[] = date('Y');
}

// Standardwerte für Filter
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : $available_years[0];
$selected_quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : ceil(date('n') / 3);

// Quartalsnamen konstruieren
$quarter_names = [
    1 => "Q1/$selected_year",
    2 => "Q2/$selected_year",
    3 => "Q3/$selected_year",
    4 => "Q4/$selected_year"
];

// Team-Definitionen
$teams = ["Team L", "Team M", "Team N", "Team O", "Team P"];
$departments = ["Tagschicht", "Verwaltung"];
$tagschicht_areas = ["Elektrik", "Mechanik", "CPO", "Qualitätssicherung", "Sortierung", "Sonstiges"];

// Abrufen der Sicherheitsschulungen für das ausgewählte Jahr und Quartal
$search_pattern = "%Q$selected_quarter/$selected_year%";
$trainings_query = "
    SELECT 
        t.id, 
        t.display_id, 
        t.training_name, 
        t.start_date, 
        t.end_date, 
        COUNT(DISTINCT et.employee_id) AS teilnehmer_anzahl
    FROM trainings t
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    LEFT JOIN employee_training et ON t.id = et.training_id
    WHERE mc.name = 'Sicherheit, Gesundheit, Umwelt, Hygiene'
    AND sc.name = 'Sicherheitsschulungen'
    AND t.training_name LIKE ?
    GROUP BY t.id
    ORDER BY t.start_date ASC
";

$stmt = $conn->prepare($trainings_query);
$stmt->bind_param("s", $search_pattern);
$stmt->execute();
$result = $stmt->get_result();
$trainings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Gesamtteilnehmeranzahl im ausgewählten Quartal
$total_participants_query = "
    SELECT COUNT(DISTINCT et.employee_id) as total_participants
    FROM employee_training et
    JOIN trainings t ON et.training_id = t.id
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    WHERE mc.name = 'Sicherheit, Gesundheit, Umwelt, Hygiene'
    AND sc.name = 'Sicherheitsschulungen'
    AND t.training_name LIKE ?
";

$stmt = $conn->prepare($total_participants_query);
$stmt->bind_param("s", $search_pattern);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_participants = $row['total_participants'];
$stmt->close();

// Gesamtmitarbeiteranzahl zum Zeitpunkt des Quartals
// Hier vereinfacht: aktuelle Mitarbeiteranzahl
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_employees = $row['total'];
$stmt->close();

// Teilnahmequote
$participation_rate = ($total_employees > 0) ? ($total_participants / $total_employees) * 100 : 0;

// Teilnehmer nach Teams/Bereichen
$team_participation = [];

// Schichtteams
foreach ($teams as $team) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT e.employee_id) as total_members,
            COUNT(DISTINCT et.employee_id) as participants
        FROM employees e
        LEFT JOIN employee_training et ON e.employee_id = et.employee_id
        LEFT JOIN trainings t ON et.training_id = t.id
        WHERE e.crew = ?
        AND (t.training_name LIKE ? OR t.id IS NULL)
    ");
    $stmt->bind_param("ss", $team, $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $team_participation[$team] = [
        'total' => $row['total_members'],
        'participants' => $row['participants'],
        'rate' => ($row['total_members'] > 0) ? ($row['participants'] / $row['total_members'] * 100) : 0
    ];
    $stmt->close();
}

// Tagschicht und Verwaltung
foreach ($departments as $department) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT e.employee_id) as total_members,
            COUNT(DISTINCT et.employee_id) as participants
        FROM employees e
        LEFT JOIN employee_training et ON e.employee_id = et.employee_id
        LEFT JOIN trainings t ON et.training_id = t.id
        WHERE e.gruppe = ?
        AND (t.training_name LIKE ? OR t.id IS NULL)
    ");
    $stmt->bind_param("ss", $department, $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $team_participation[$department] = [
        'total' => $row['total_members'],
        'participants' => $row['participants'],
        'rate' => ($row['total_members'] > 0) ? ($row['participants'] / $row['total_members'] * 100) : 0
    ];
    $stmt->close();
}

// Teilnehmer in der Tagschicht nach Bereichen
$tagschicht_area_participation = [];

foreach ($tagschicht_areas as $area) {
    $like_area = "%$area%";
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT e.employee_id) as total_members,
            COUNT(DISTINCT et.employee_id) as participants
        FROM employees e
        LEFT JOIN employee_training et ON e.employee_id = et.employee_id
        LEFT JOIN trainings t ON et.training_id = t.id
        WHERE e.gruppe = 'Tagschicht'
        AND e.position LIKE ?
        AND (t.training_name LIKE ? OR t.id IS NULL)
    ");
    $stmt->bind_param("ss", $like_area, $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $tagschicht_area_participation[$area] = [
        'total' => $row['total_members'],
        'participants' => $row['participants'],
        'rate' => ($row['total_members'] > 0) ? ($row['participants'] / $row['total_members'] * 100) : 0
    ];
    $stmt->close();
}

// Fehlende Teilnehmer nach Gruppen
$missing_participants_query = "
    SELECT e.employee_id, e.name, e.crew, e.gruppe, e.position
    FROM employees e
    WHERE e.employee_id NOT IN (
        SELECT DISTINCT et.employee_id
        FROM employee_training et
        JOIN trainings t ON et.training_id = t.id
        JOIN training_main_categories mc ON t.main_category_id = mc.id
        JOIN training_sub_categories sc ON t.sub_category_id = sc.id
        WHERE mc.name = 'Sicherheit, Gesundheit, Umwelt, Hygiene'
        AND sc.name = 'Sicherheitsschulungen'
        AND t.training_name LIKE ?
    )
    ORDER BY e.gruppe, e.crew, e.name
";

$stmt = $conn->prepare($missing_participants_query);
$stmt->bind_param("s", $search_pattern);
$stmt->execute();
$result = $stmt->get_result();
$missing_participants = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Gruppieren der fehlenden Teilnehmer nach Bereich
$missing_by_group = [];

foreach ($missing_participants as $emp) {
    $group = $emp['gruppe'];

    if (!isset($missing_by_group[$group])) {
        $missing_by_group[$group] = [];
    }

    if ($group === 'Schichtarbeit') {
        $crew = $emp['crew'];
        if (!isset($missing_by_group[$group][$crew])) {
            $missing_by_group[$group][$crew] = [];
        }
        $missing_by_group[$group][$crew][] = $emp;
    } elseif ($group === 'Tagschicht') {
        $area = 'Sonstiges';  // Standard
        foreach ($tagschicht_areas as $tagschicht_area) {
            if (strpos($emp['position'], $tagschicht_area) !== false) {
                $area = $tagschicht_area;
                break;
            }
        }

        if (!isset($missing_by_group[$group][$area])) {
            $missing_by_group[$group][$area] = [];
        }
        $missing_by_group[$group][$area][] = $emp;
    } else {  // Verwaltung
        if (!isset($missing_by_group[$group]['Alle'])) {
            $missing_by_group[$group]['Alle'] = [];
        }
        $missing_by_group[$group]['Alle'][] = $emp;
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Sicherheitsschulungen - Historie</title>
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .dashboard-card {
            height: 100%;
            transition: transform 0.2s;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .card-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .progress {
            height: 10px;
            margin-top: 8px;
        }

        .team-chart {
            height: 15px;
            margin-top: 5px;
            border-radius: 5px;
            overflow: hidden;
            background-color: #e9ecef;
        }

        .team-chart-bar {
            height: 100%;
            float: left;
            background-color: #28a745;
        }

        .section-divider {
            margin: 2rem 0;
            display: flex;
            align-items: center;
        }

        .section-divider:before,
        .section-divider:after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }

        .section-divider-text {
            padding: 0 1rem;
            color: #6c757d;
            font-weight: 500;
        }

        .filter-container {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .missing-employee-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .team-card {
            border-left: 4px solid;
        }

        .team-card.team-l {
            border-left-color: #007bff;
        }

        .team-card.team-m {
            border-left-color: #28a745;
        }

        .team-card.team-n {
            border-left-color: #fd7e14;
        }

        .team-card.team-o {
            border-left-color: #6f42c1;
        }

        .team-card.team-p {
            border-left-color: #e83e8c;
        }

        .team-card.tagschicht {
            border-left-color: #20c997;
        }

        .team-card.verwaltung {
            border-left-color: #6c757d;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-clock-history me-2"></i>Sicherheitsschulungen - Historie</h1>
        <div>
            <a href="safety_dashboard.php" class="btn btn-outline-primary">
                <i class="bi bi-shield-check me-2"></i>Zurück zum Dashboard
            </a>
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-container">
        <form method="get" action="safety_history.php" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="year" class="form-label">Jahr:</label>
                <select class="form-select" id="year" name="year">
                    <?php foreach ($available_years as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo ($year == $selected_year) ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="quarter" class="form-label">Quartal:</label>
                <select class="form-select" id="quarter" name="quarter">
                    <option value="1" <?php echo ($selected_quarter == 1) ? 'selected' : ''; ?>>Q1</option>
                    <option value="2" <?php echo ($selected_quarter == 2) ? 'selected' : ''; ?>>Q2</option>
                    <option value="3" <?php echo ($selected_quarter == 3) ? 'selected' : ''; ?>>Q3</option>
                    <option value="4" <?php echo ($selected_quarter == 4) ? 'selected' : ''; ?>>Q4</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-2"></i>Anzeigen
                </button>
            </div>
        </form>
    </div>

    <!-- Ergebnisanzeige für das ausgewählte Quartal -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Übersicht:
                Sicherheitsschulungen <?php echo $quarter_names[$selected_quarter]; ?></h5>
        </div>
        <div class="card-body">
            <?php if (count($trainings) > 0): ?>
                <!-- Gesamtstatistik -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card dashboard-card">
                            <div class="card-body text-center">
                                <div class="card-icon text-primary">
                                    <i class="bi bi-people-fill"></i>
                                </div>
                                <h5 class="card-title">Teilnahmequote</h5>
                                <h2 class="mb-0"><?php echo round($participation_rate, 1); ?>%</h2>
                                <p class="text-muted mb-2"><?php echo $total_participants; ?>
                                    von <?php echo $total_employees; ?> Mitarbeitern</p>
                                <div class="progress">
                                    <div class="progress-bar bg-primary" role="progressbar"
                                         style="width: <?php echo $participation_rate; ?>%"
                                         aria-valuenow="<?php echo $participation_rate; ?>" aria-valuemin="0"
                                         aria-valuemax="100"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card dashboard-card">
                            <div class="card-body text-center">
                                <div class="card-icon text-success">
                                    <i class="bi bi-calendar-check"></i>
                                </div>
                                <h5 class="card-title">Sicherheitsschulungen</h5>
                                <h2 class="mb-0"><?php echo count($trainings); ?></h2>
                                <p class="text-muted mb-2">Termine
                                    in <?php echo $quarter_names[$selected_quarter]; ?></p>
                                <div class="progress">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: 100%"
                                         aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste der Schulungen -->
                <h5 class="mb-3">Schulungstermine:</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Datum</th>
                            <th>Teilnehmer</th>
                            <th>Aktionen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($trainings as $training): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($training['display_id'] ?? $training['id']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($training['training_name']); ?></td>
                                <td>
                                    <?php
                                    echo date('d.m.Y', strtotime($training['start_date']));
                                    if ($training['start_date'] != $training['end_date']) {
                                        echo ' - ' . date('d.m.Y', strtotime($training['end_date']));
                                    }
                                    ?>
                                </td>
                                <td><?php echo $training['teilnehmer_anzahl']; ?></td>
                                <td>
                                    <a href="edit_training.php?id=<?php echo $training['id']; ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>Keine Sicherheitsschulungen
                    für <?php echo $quarter_names[$selected_quarter]; ?> gefunden.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Teilnahmestatistik nach Teams/Bereichen -->
    <?php if (count($trainings) > 0): ?>
        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Teilnahme nach Teams/Bereichen</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>Team/Bereich</th>
                                    <th>Teilnehmer</th>
                                    <th>Quote</th>
                                    <th>Verteilung</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($teams as $team): ?>
                                    <?php $team_class = strtolower(str_replace(' ', '-', $team)); ?>
                                    <tr>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($team); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo $team_participation[$team]['participants']; ?></span>
                                            <span class="text-muted ms-1 small">von <?php echo $team_participation[$team]['total']; ?></span>
                                        </td>
                                        <td>
                                            <?php $rate_class = ($team_participation[$team]['rate'] >= 90) ? 'success' : (($team_participation[$team]['rate'] >= 70) ? 'warning' : 'danger'); ?>
                                            <span class="badge bg-<?php echo $rate_class; ?>"><?php echo round($team_participation[$team]['rate'], 1); ?>%</span>
                                        </td>
                                        <td style="width: 30%;">
                                            <div class="team-chart">
                                                <div class="team-chart-bar"
                                                     style="width: <?php echo $team_participation[$team]['rate']; ?>%;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php foreach ($departments as $dept): ?>
                                    <?php $dept_class = strtolower(str_replace(' ', '-', $dept)); ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($dept); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo $team_participation[$dept]['participants']; ?></span>
                                            <span class="text-muted ms-1 small">von <?php echo $team_participation[$dept]['total']; ?></span>
                                        </td>
                                        <td>
                                            <?php $rate_class = ($team_participation[$dept]['rate'] >= 90) ? 'success' : (($team_participation[$dept]['rate'] >= 70) ? 'warning' : 'danger'); ?>
                                            <span class="badge bg-<?php echo $rate_class; ?>"><?php echo round($team_participation[$dept]['rate'], 1); ?>%</span>
                                        </td>
                                        <td style="width: 30%;">
                                            <div class="team-chart">
                                                <div class="team-chart-bar"
                                                     style="width: <?php echo $team_participation[$dept]['rate']; ?>%;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tagschicht-Bereiche -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Tagschicht nach Bereichen</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>Bereich</th>
                                    <th>Teilnehmer</th>
                                    <th>Quote</th>
                                    <th>Verteilung</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($tagschicht_areas as $area): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($area); ?></td>
                                        <td>
                                            <span class="badge bg-success"><?php echo $tagschicht_area_participation[$area]['participants']; ?></span>
                                            <span class="text-muted ms-1 small">von <?php echo $tagschicht_area_participation[$area]['total']; ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $area_rate = $tagschicht_area_participation[$area]['rate'];
                                            $rate_class = ($area_rate >= 90) ? 'success' : (($area_rate >= 70) ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?php echo $rate_class; ?>"><?php echo round($area_rate, 1); ?>%</span>
                                        </td>
                                        <td style="width: 30%;">
                                            <div class="team-chart">
                                                <div class="team-chart-bar"
                                                     style="width: <?php echo $area_rate; ?>%;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fehlende Teilnehmer -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-person-x me-2"></i>Fehlende Teilnehmer</h5>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#missingParticipantsModal">
                            <i class="bi bi-list-ul me-1"></i>Details anzeigen
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (count($missing_participants) > 0): ?>
                            <div class="d-flex flex-column">
                                <?php foreach (['Schichtarbeit', 'Tagschicht', 'Verwaltung'] as $group): ?>
                                    <?php if (isset($missing_by_group[$group])): ?>
                                        <div class="mb-3">
                                            <h6><?php echo $group; ?></h6>
                                            <div class="progress mb-3" style="height: 20px;">
                                                <?php
                                                $group_total = 0;
                                                $group_missing = 0;

                                                foreach ($team_participation as $team => $data) {
                                                    if (($group === 'Schichtarbeit' && in_array($team, $teams)) ||
                                                        ($group === $team)) {
                                                        $group_total += $data['total'];
                                                        $group_missing += ($data['total'] - $data['participants']);
                                                    }
                                                }

                                                $missing_rate = ($group_total > 0) ? ($group_missing / $group_total * 100) : 0;
                                                ?>
                                                <div class="progress-bar bg-danger" role="progressbar"
                                                     style="width: <?php echo $missing_rate; ?>%"
                                                     aria-valuenow="<?php echo $missing_rate; ?>" aria-valuemin="0"
                                                     aria-valuemax="100">
                                                    <?php echo $group_missing; ?> Mitarbeiter
                                                </div>
                                            </div>

                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ($missing_by_group[$group] as $subgroup => $employees): ?>
                                                    <span class="badge bg-light text-dark border">
                                                        <?php echo $subgroup; ?>: <?php echo count($employees); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mb-0">
                                <i class="bi bi-check-circle me-2"></i>Alle Mitarbeiter haben an der Schulung
                                teilgenommen!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal für fehlende Teilnehmer -->
<div class="modal fade" id="missingParticipantsModal" tabindex="-1" aria-labelledby="missingParticipantsModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="missingParticipantsModalLabel">
                    Fehlende Teilnehmer - <?php echo $quarter_names[$selected_quarter]; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (count($missing_participants) > 0): ?>
                    <div class="row">
                        <?php foreach (['Schichtarbeit', 'Tagschicht', 'Verwaltung'] as $group): ?>
                            <?php if (isset($missing_by_group[$group])): ?>
                                <div class="col-12 mb-4">
                                    <h5><?php echo $group; ?></h5>
                                    <div class="row">
                                        <?php foreach ($missing_by_group[$group] as $subgroup => $employees): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <?php
                                                $card_class = 'team-card ';
                                                if (in_array($subgroup, $teams)) {
                                                    $card_class .= strtolower(str_replace(' ', '-', $subgroup));
                                                } elseif ($group === 'Tagschicht') {
                                                    $card_class .= 'tagschicht';
                                                } else {
                                                    $card_class .= 'verwaltung';
                                                }
                                                ?>
                                                <div class="card <?php echo $card_class; ?>">
                                                    <div class="card-header bg-light">
                                                        <h6 class="mb-0"><?php echo $subgroup; ?> <span
                                                                    class="badge bg-secondary"><?php echo count($employees); ?></span>
                                                        </h6>
                                                    </div>
                                                    <div class="card-body p-0">
                                                        <div class="list-group list-group-flush missing-employee-list">
                                                            <?php foreach ($employees as $emp): ?>
                                                                <div class="list-group-item">
                                                                    <div class="d-flex justify-content-between">
                                                                        <div>
                                                                            <?php echo htmlspecialchars($emp['name']); ?>
                                                                        </div>
                                                                        <small class="text-muted">
                                                                            <?php echo htmlspecialchars($emp['position']); ?>
                                                                        </small>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle me-2"></i>Alle Mitarbeiter haben an der Schulung teilgenommen!
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>