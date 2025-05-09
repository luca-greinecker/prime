<?php
/**
 * hr_dashboard.php
 *
 * HR-Dashboard mit Kennzahlen zur Personalstruktur, Onboarding, Ausbildung und weiteren HR-Metriken.
 *
 * Archivierte Mitarbeiter (status = 9999) werden in allen Anzeigen ausgeblendet.
 */

include 'access_control.php';
include_once 'dashboard_helpers.php'; // Include Helper-Funktionen

global $conn;
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// Gemeinsame WHERE-Bedingung für Nicht-Archivierte
$active_employees_condition = "WHERE status != 9999";

// Grundlegende Mitarbeiterstatistiken (nur aktive)
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees $active_employees_condition");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_employees = $row['total'];
$stmt->close();

// Geschlechterverteilung (nur aktive)
$stmt = $conn->prepare("
    SELECT gender, COUNT(*) as count
    FROM employees
    $active_employees_condition
    GROUP BY gender
");
$stmt->execute();
$result = $stmt->get_result();
$gender_distribution = [];
while ($row = $result->fetch_assoc()) {
    $gender = $row['gender'] ?: 'Nicht angegeben';
    $gender_distribution[$gender] = $row['count'];
}
$stmt->close();

// Verteilung nach Gruppen (nur aktive)
$stmt = $conn->prepare("
    SELECT gruppe, COUNT(*) as count
    FROM employees
    $active_employees_condition
    GROUP BY gruppe
    ORDER BY count DESC
");
$stmt->execute();
$result = $stmt->get_result();
$group_distribution = [];
while ($row = $result->fetch_assoc()) {
    $group_distribution[$row['gruppe']] = $row['count'];
}
$stmt->close();

// Verteilung nach Teams (für Schichtarbeit) (nur aktive)
$stmt = $conn->prepare("
    SELECT crew, COUNT(*) as count
    FROM employees
    $active_employees_condition AND crew != '---' AND crew != ''
    GROUP BY crew
    ORDER BY crew ASC
");
$stmt->execute();
$result = $stmt->get_result();
$team_distribution = [];
while ($row = $result->fetch_assoc()) {
    $team_distribution[$row['crew']] = $row['count'];
}
$stmt->close();

// Onboarding-Statistiken (nur aktive)
$stmt = $conn->prepare("
    SELECT onboarding_status, COUNT(*) as count
    FROM employees
    $active_employees_condition AND onboarding_status > 0
    GROUP BY onboarding_status
");
$stmt->execute();
$result = $stmt->get_result();
$onboarding_stats = [];
$total_onboarding = 0;
while ($row = $result->fetch_assoc()) {
    $onboarding_stats[$row['onboarding_status']] = $row['count'];
    $total_onboarding += $row['count'];
}
$stmt->close();

// Mitarbeiter im Onboarding-Prozess (nur aktive)
$stmt = $conn->prepare("
    SELECT employee_id, name, badge_id, gender, birthdate, entry_date, gruppe, crew, position, onboarding_status
    FROM employees
    $active_employees_condition AND onboarding_status > 0
    ORDER BY entry_date DESC, name ASC
");
$stmt->execute();
$result = $stmt->get_result();
$onboarding_employees = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Neue Mitarbeiter (eingetreten in den letzten 3 Monaten) (nur aktive)
$three_months_ago = date('Y-m-d', strtotime('-3 months'));
$stmt = $conn->prepare("
    SELECT COUNT(*) as count
    FROM employees
    $active_employees_condition AND entry_date >= ?
");
$stmt->bind_param("s", $three_months_ago);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$new_employees_count = $row['count'];
$stmt->close();

// Details zu neuen Mitarbeitern (nur aktive)
$stmt = $conn->prepare("
    SELECT employee_id, name, entry_date, gruppe, crew, position
    FROM employees
    $active_employees_condition AND entry_date >= ?
    ORDER BY entry_date DESC, name ASC
");
$stmt->bind_param("s", $three_months_ago);
$stmt->execute();
$result = $stmt->get_result();
$new_employees = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Neueinstellungen pro Monat für die letzten 12 Monate (nur aktive)
$monthly_hires = [];
for ($i = 11; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
    $month_end = date('Y-m-t', strtotime("-$i months"));
    $month_label = date('M Y', strtotime("-$i months"));

    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM employees
        $active_employees_condition AND entry_date BETWEEN ? AND ?
    ");
    $stmt->bind_param("ss", $month_start, $month_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $monthly_hires[$month_label] = $row['count'];
    $stmt->close();
}

// Altersstruktur (nur aktive)
$age_groups = [
    '< 20' => 0,
    '20-29' => 0,
    '30-39' => 0,
    '40-49' => 0,
    '50-59' => 0,
    '60+' => 0,
    'Keine Angabe' => 0
];

$stmt = $conn->prepare("SELECT birthdate FROM employees $active_employees_condition AND birthdate IS NOT NULL");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $birthdate = new DateTime($row['birthdate']);
    $today = new DateTime();
    $age = $birthdate->diff($today)->y;

    if ($age < 20) {
        $age_groups['< 20']++;
    } elseif ($age < 30) {
        $age_groups['20-29']++;
    } elseif ($age < 40) {
        $age_groups['30-39']++;
    } elseif ($age < 50) {
        $age_groups['40-49']++;
    } elseif ($age < 60) {
        $age_groups['50-59']++;
    } else {
        $age_groups['60+']++;
    }
}
$stmt->close();

// Mitarbeiter ohne Geburtsdatum (nur aktive)
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees $active_employees_condition AND birthdate IS NULL");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$age_groups['Keine Angabe'] = $row['count'];
$stmt->close();

// Betriebszugehörigkeit (nur aktive)
$tenure_groups = [
    '< 1 Jahr' => 0,
    '1-2 Jahre' => 0,
    '3-5 Jahre' => 0,
    '6-10 Jahre' => 0,
    '11-15 Jahre' => 0,
    '16+ Jahre' => 0,
    'Keine Angabe' => 0
];

$stmt = $conn->prepare("SELECT entry_date FROM employees $active_employees_condition AND entry_date IS NOT NULL");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $entry_date = new DateTime($row['entry_date']);
    $today = new DateTime();
    $years = $entry_date->diff($today)->y;

    if ($years < 1) {
        $tenure_groups['< 1 Jahr']++;
    } elseif ($years < 3) {
        $tenure_groups['1-2 Jahre']++;
    } elseif ($years < 6) {
        $tenure_groups['3-5 Jahre']++;
    } elseif ($years < 11) {
        $tenure_groups['6-10 Jahre']++;
    } elseif ($years < 16) {
        $tenure_groups['11-15 Jahre']++;
    } else {
        $tenure_groups['16+ Jahre']++;
    }
}
$stmt->close();

// Mitarbeiter ohne Eintrittsdatum (nur aktive)
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees $active_employees_condition AND entry_date IS NULL");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$tenure_groups['Keine Angabe'] = $row['count'];
$stmt->close();

// Bildungsabschlüsse (nur aktive) - mit JOIN zu aktiven Mitarbeitern
$stmt = $conn->prepare("
    SELECT ee.education_type, COUNT(*) as count
    FROM employee_education ee
    JOIN employees e ON ee.employee_id = e.employee_id
    WHERE e.status != 9999
    GROUP BY ee.education_type
    ORDER BY count DESC
");
$stmt->execute();
$result = $stmt->get_result();
$education_distribution = [];
while ($row = $result->fetch_assoc()) {
    $education_distribution[$row['education_type']] = $row['count'];
}
$stmt->close();

// Mitarbeiter mit Bildungsabschlüssen vs. ohne (nur aktive)
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT ee.employee_id) as count
    FROM employee_education ee
    JOIN employees e ON ee.employee_id = e.employee_id
    WHERE e.status != 9999
");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$employees_with_education = $row['count'];
$stmt->close();

$employees_without_education = $total_employees - $employees_with_education;

// Ersthelfer und Sicherheitsfunktionen (nur aktive)
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN ersthelfer = 1 THEN 1 ELSE 0 END) as ersthelfer_count,
        SUM(CASE WHEN svp = 1 THEN 1 ELSE 0 END) as svp_count,
        SUM(CASE WHEN brandschutzwart = 1 THEN 1 ELSE 0 END) as brandschutzwart_count,
        SUM(CASE WHEN sprinklerwart = 1 THEN 1 ELSE 0 END) as sprinklerwart_count
    FROM employees
    $active_employees_condition
");
$stmt->execute();
$result = $stmt->get_result();
$safety_roles = $result->fetch_assoc();
$stmt->close();

// Top 5 Positionen (nur aktive)
$stmt = $conn->prepare("
    SELECT position, COUNT(*) as count
    FROM employees
    $active_employees_condition
    GROUP BY position
    ORDER BY count DESC
    LIMIT 5
");
$stmt->execute();
$result = $stmt->get_result();
$top_positions = [];
while ($row = $result->fetch_assoc()) {
    $top_positions[$row['position']] = $row['count'];
}
$stmt->close();

// Talententwicklung (aus employee_reviews) - Nutzung der Helper-Funktion
// Wir definieren einen Zeitraum, z.B. das aktuelle Jahr
$current_year = date('Y');
$period = getReviewPeriodForYear($conn, $current_year);
$talent_result = holeTalentsHR($conn, $period['start_date'], $period['end_date']);

$talent_distribution = [];
while ($row = $talent_result->fetch_assoc()) {
    // Hier aggregieren wir nach tr_talent
    if (!isset($talent_distribution[$row['tr_talent']])) {
        $talent_distribution[$row['tr_talent']] = 0;
    }
    $talent_distribution[$row['tr_talent']]++;
}

// Performance-Bewertungen (aus employee_reviews) - mit JOIN zu aktiven Mitarbeitern
$stmt = $conn->prepare("
    SELECT er.tr_performance_assessment, COUNT(*) as count
    FROM employee_reviews er
    JOIN employees e ON er.employee_id = e.employee_id
    WHERE er.tr_performance_assessment IS NOT NULL
    AND e.status != 9999
    GROUP BY er.tr_performance_assessment
    ORDER BY count DESC
");
$stmt->execute();
$result = $stmt->get_result();
$performance_distribution = [];
while ($row = $result->fetch_assoc()) {
    $performance_distribution[$row['tr_performance_assessment']] = $row['count'];
}
$stmt->close();

// Karriereplanung (aus employee_reviews) - mit JOIN zu aktiven Mitarbeitern
$stmt = $conn->prepare("
    SELECT er.tr_career_plan, COUNT(*) as count
    FROM employee_reviews er
    JOIN employees e ON er.employee_id = e.employee_id
    WHERE er.tr_career_plan IS NOT NULL
    AND e.status != 9999
    GROUP BY er.tr_career_plan
    ORDER BY count DESC
");
$stmt->execute();
$result = $stmt->get_result();
$career_distribution = [];
while ($row = $result->fetch_assoc()) {
    $career_distribution[$row['tr_career_plan']] = $row['count'];
}
$stmt->close();

// Mitarbeiterzufriedenheit - Nutzung einer angepassten Version der Helper-Funktion
// Da die Originaldaten noch ausreichend sind, verwenden wir die vorhandene Abfrage mit JOIN zu aktiven Mitarbeitern
$stmt = $conn->prepare("
    SELECT er.zufriedenheit, COUNT(*) as count
    FROM employee_reviews er
    JOIN employees e ON er.employee_id = e.employee_id
    WHERE er.zufriedenheit IS NOT NULL
    AND e.status != 9999
    GROUP BY er.zufriedenheit
    ORDER BY 
        CASE 
            WHEN er.zufriedenheit = 'Zufrieden' THEN 1
            WHEN er.zufriedenheit = 'Grundsätzlich zufrieden' THEN 2
            WHEN er.zufriedenheit = 'Unzufrieden' THEN 3
        END
");
$stmt->execute();
$result = $stmt->get_result();
$satisfaction_distribution = [];
while ($row = $result->fetch_assoc()) {
    $satisfaction_distribution[$row['zufriedenheit']] = $row['count'];
}
$stmt->close();

// Trainingsteilnahmen pro Mitarbeiter - mit JOIN zu aktiven Mitarbeitern
$stmt = $conn->prepare("
    SELECT 
        e.employee_id, 
        e.name, 
        COUNT(et.training_id) as training_count
    FROM 
        employees e
    LEFT JOIN 
        employee_training et ON e.employee_id = et.employee_id
    WHERE
        e.status != 9999
    GROUP BY 
        e.employee_id
    ORDER BY 
        training_count DESC
    LIMIT 10
");
$stmt->execute();
$result = $stmt->get_result();
$top_training_participation = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Durchschnittliche Anzahl an Trainings pro Mitarbeiter - mit JOIN zu aktiven Mitarbeitern
$stmt = $conn->prepare("
    SELECT 
        AVG(training_count) as avg_trainings
    FROM (
        SELECT 
            e.employee_id, 
            COUNT(et.training_id) as training_count
        FROM 
            employees e
        LEFT JOIN 
            employee_training et ON e.employee_id = et.employee_id
        WHERE
            e.status != 9999
        GROUP BY 
            e.employee_id
    ) as training_counts
");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$avg_trainings_per_employee = round($row['avg_trainings'], 1);
$stmt->close();

// Lohnschema-Verteilung (nur aktive)
$stmt = $conn->prepare("
    SELECT lohnschema, COUNT(*) as count
    FROM employees
    $active_employees_condition
    GROUP BY lohnschema
    ORDER BY count DESC
");
$stmt->execute();
$result = $stmt->get_result();
$lohnschema_distribution = [];
while ($row = $result->fetch_assoc()) {
    $lohnschema_distribution[$row['lohnschema']] = $row['count'];
}
$stmt->close();

// Qualifikationsboni-Verteilung (nur aktive)
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN pr_lehrabschluss = 1 THEN 1 ELSE 0 END) as lehrabschluss,
        SUM(CASE WHEN pr_anfangslohn = 1 THEN 1 ELSE 0 END) as anfangslohn,
        SUM(CASE WHEN pr_grundlohn = 1 THEN 1 ELSE 0 END) as grundlohn,
        SUM(CASE WHEN pr_qualifikationsbonus = 1 THEN 1 ELSE 0 END) as qualifikationsbonus,
        SUM(CASE WHEN pr_expertenbonus = 1 THEN 1 ELSE 0 END) as expertenbonus,
        SUM(CASE WHEN tk_qualifikationsbonus_1 = 1 THEN 1 ELSE 0 END) as tk_qual_1,
        SUM(CASE WHEN tk_qualifikationsbonus_2 = 1 THEN 1 ELSE 0 END) as tk_qual_2,
        SUM(CASE WHEN tk_qualifikationsbonus_3 = 1 THEN 1 ELSE 0 END) as tk_qual_3,
        SUM(CASE WHEN tk_qualifikationsbonus_4 = 1 THEN 1 ELSE 0 END) as tk_qual_4
    FROM employees
    $active_employees_condition
");
$stmt->execute();
$result = $stmt->get_result();
$boni_distribution = $result->fetch_assoc();
$stmt->close();

// Gebiet-Zulagen (nur aktive)
$stmt = $conn->prepare("
    SELECT ln_zulage, COUNT(*) as count
    FROM employees
    $active_employees_condition AND ln_zulage IS NOT NULL
    GROUP BY ln_zulage
    ORDER BY count DESC
");
$stmt->execute();
$result = $stmt->get_result();
$zulage_distribution = [];
while ($row = $result->fetch_assoc()) {
    $zulage_distribution[$row['ln_zulage']] = $row['count'];
}
$stmt->close();

// Schulungsbedarf - Nutzung der Helper-Funktionen für Weiterbildungen
// Hier importieren wir die Daten aus der Helper-Funktion und aggregieren sie dann
$weiterbildungen_result = holeWeiterbildungenHR($conn, $period['start_date'], $period['end_date']);

// Schulungsbedarf basierend auf employee_reviews (mit JOIN zu aktiven Mitarbeitern)
$stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN er.tr_action_extra_tasks = 1 THEN 1 ELSE 0 END) as extra_tasks,
        SUM(CASE WHEN er.tr_action_on_job_training = 1 THEN 1 ELSE 0 END) as on_job_training,
        SUM(CASE WHEN er.tr_action_school_completion = 1 THEN 1 ELSE 0 END) as school_completion,
        SUM(CASE WHEN er.tr_action_specialist_knowledge = 1 THEN 1 ELSE 0 END) as specialist_knowledge,
        SUM(CASE WHEN er.tr_action_generalist_knowledge = 1 THEN 1 ELSE 0 END) as generalist_knowledge,
        SUM(CASE WHEN er.tr_external_training_industry_foreman = 1 THEN 1 ELSE 0 END) as industry_foreman,
        SUM(CASE WHEN er.tr_external_training_industry_master = 1 THEN 1 ELSE 0 END) as industry_master,
        SUM(CASE WHEN er.tr_external_training_german = 1 THEN 1 ELSE 0 END) as german_training,
        SUM(CASE WHEN er.tr_external_training_qs_basics = 1 THEN 1 ELSE 0 END) as qs_basics,
        SUM(CASE WHEN er.tr_external_training_qs_assistant = 1 THEN 1 ELSE 0 END) as qs_assistant,
        SUM(CASE WHEN er.tr_external_training_qs_technician = 1 THEN 1 ELSE 0 END) as qs_technician,
        SUM(CASE WHEN er.tr_external_training_sps_basics = 1 THEN 1 ELSE 0 END) as sps_basics,
        SUM(CASE WHEN er.tr_external_training_sps_advanced = 1 THEN 1 ELSE 0 END) as sps_advanced,
        SUM(CASE WHEN er.tr_external_training_forklift = 1 THEN 1 ELSE 0 END) as forklift,
        SUM(CASE WHEN er.tr_external_training_other = 1 THEN 1 ELSE 0 END) as other_training,
        SUM(CASE WHEN er.tr_internal_training_best_leadership = 1 THEN 1 ELSE 0 END) as leadership_training,
        SUM(CASE WHEN er.tr_internal_training_jbs = 1 THEN 1 ELSE 0 END) as jbs_training,
        SUM(CASE WHEN er.tr_department_training = 1 THEN 1 ELSE 0 END) as department_training
    FROM employee_reviews er
    JOIN employees e ON er.employee_id = e.employee_id
    WHERE e.status != 9999
");
$stmt->execute();
$result = $stmt->get_result();
$training_needs = $result->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>HR-Dashboard</title>
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

        .chart-container {
            position: relative;
            height: 200px;
        }

        .chart-placeholder {
            height: 100%;
            width: 100%;
            background-color: #f8f9fa;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: #6c757d;
        }

        .donut-chart {
            width: 150px;
            height: 150px;
            margin: 0 auto;
            position: relative;
        }

        .donut-chart-hole {
            width: 65%;
            height: 65%;
            border-radius: 50%;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .donut-chart-hole span {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .donut-chart-hole small {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .donut-segment {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            clip: rect(0px, 150px, 150px, 75px);
        }

        .badge-count {
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
        }

        .percentage-indicator {
            display: inline-block;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .employee-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .search-container {
            position: relative;
            margin-bottom: 1rem;
        }

        .search-icon {
            position: absolute;
            right: 10px;
            top: 10px;
            color: #6c757d;
        }

        .small-percentage {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .horizontal-bar {
            height: 20px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .horizontal-bar-fill {
            height: 100%;
            float: left;
            display: flex;
            align-items: center;
            padding-left: 5px;
            color: white;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        .horizontal-bar-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }

        .horizontal-bar-label .name {
            font-weight: 500;
        }

        .horizontal-bar-label .value {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .mini-card {
            border-radius: 5px;
            padding: 10px 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            transition: transform 0.2s;
        }

        .mini-card:hover {
            transform: translateY(-3px);
        }

        .mini-card .number {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .mini-card .label {
            font-size: 0.8rem;
            color: #6c757d;
            text-align: center;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-people me-2"></i>HR-Dashboard</h1>
        <div>
            <a href="training_overview.php" class="btn btn-outline-primary">
                <i class="bi bi-mortarboard me-2"></i>Schulungen verwalten
            </a>
        </div>
    </div>

    <!-- Hauptkennzahlen -->
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4 mb-4">
        <!-- Mitarbeiteranzahl -->
        <div class="col">
            <div class="card dashboard-card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="card-icon text-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <h5 class="card-title">Mitarbeiter</h5>
                    <h2 class="mb-0"><?php echo $total_employees; ?></h2>
                    <div class="row mt-3 mt-auto">
                        <div class="col">
                            <small class="text-muted d-block">Schichtarbeit</small>
                            <span class="badge bg-primary"><?php echo $group_distribution['Schichtarbeit'] ?? 0; ?></span>
                        </div>
                        <div class="col">
                            <small class="text-muted d-block">Tagschicht</small>
                            <span class="badge bg-success"><?php echo $group_distribution['Tagschicht'] ?? 0; ?></span>
                        </div>
                        <div class="col">
                            <small class="text-muted d-block">Verwaltung</small>
                            <span class="badge bg-info"><?php echo $group_distribution['Verwaltung'] ?? 0; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Geschlechterverteilung -->
        <div class="col">
            <div class="card dashboard-card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="card-icon text-success">
                        <i class="bi bi-gender-ambiguous"></i>
                    </div>
                    <h5 class="card-title">Geschlechterverteilung</h5>
                    <div class="row mt-3">
                        <div class="col">
                        <span class="badge bg-primary badge-count d-block mb-1">
                            <?php echo $gender_distribution['männlich'] ?? 0; ?>
                        </span>
                            <small class="text-muted">Männlich</small>
                            <div class="small-percentage">
                                <?php echo round((($gender_distribution['männlich'] ?? 0) / $total_employees) * 100, 1); ?>
                                %
                            </div>
                        </div>
                        <div class="col">
                        <span class="badge bg-danger badge-count d-block mb-1">
                            <?php echo $gender_distribution['weiblich'] ?? 0; ?>
                        </span>
                            <small class="text-muted">Weiblich</small>
                            <div class="small-percentage">
                                <?php echo round((($gender_distribution['weiblich'] ?? 0) / $total_employees) * 100, 1); ?>
                                %
                            </div>
                        </div>
                        <div class="col">
                        <span class="badge bg-secondary badge-count d-block mb-1">
                            <?php echo ($gender_distribution['divers'] ?? 0) + ($gender_distribution['Nicht angegeben'] ?? 0); ?>
                        </span>
                            <small class="text-muted">Divers/k.A.</small>
                            <div class="small-percentage">
                                <?php
                                $diverse_na = ($gender_distribution['divers'] ?? 0) + ($gender_distribution['Nicht angegeben'] ?? 0);
                                echo round(($diverse_na / $total_employees) * 100, 1);
                                ?>%
                            </div>
                        </div>
                    </div>
                    <div class="mt-auto pt-2">
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" role="progressbar"
                                 style="width: <?php echo round((($gender_distribution['männlich'] ?? 0) / $total_employees) * 100, 1); ?>%"></div>
                            <div class="progress-bar bg-danger" role="progressbar"
                                 style="width: <?php echo round((($gender_distribution['weiblich'] ?? 0) / $total_employees) * 100, 1); ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onboarding -->
        <div class="col">
            <div class="card dashboard-card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="card-icon text-warning position-relative">
                        <i class="bi bi-person-plus-fill"></i>
                        <?php if ($total_onboarding > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $total_onboarding; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <h5 class="card-title">Onboarding</h5>
                    <button class="btn btn-sm btn-outline-warning mt-2 mb-2" data-bs-toggle="modal"
                            data-bs-target="#onboardingModal">
                        <?php echo $total_onboarding; ?> Mitarbeiter im Onboarding
                    </button>
                    <div class="progress">
                        <div class="progress-bar bg-warning" role="progressbar"
                             style="width: <?php echo ($total_employees > 0) ? ($total_onboarding / $total_employees) * 100 : 0; ?>%"
                             aria-valuenow="<?php echo $total_onboarding; ?>" aria-valuemin="0"
                             aria-valuemax="<?php echo $total_employees; ?>">
                        </div>
                    </div>
                    <div class="text-muted mt-2 mt-auto small">
                        <i class="bi bi-calendar3 me-1"></i> <?php echo $new_employees_count; ?> neue Mitarbeiter in
                        letzten 3 Monaten
                    </div>
                </div>
            </div>
        </div>

        <!-- Sicherheitsrollen -->
        <div class="col">
            <div class="card dashboard-card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="card-icon text-danger">
                        <i class="bi bi-shield-fill-check"></i>
                    </div>
                    <h5 class="card-title">Sicherheitsrollen</h5>
                    <div class="row mt-3">
                        <div class="col-6">
                        <span class="badge bg-success badge-count mb-1">
                            <?php echo $safety_roles['ersthelfer_count']; ?>
                        </span>
                            <small class="text-muted d-block">Ersthelfer</small>
                        </div>
                        <div class="col-6">
                        <span class="badge bg-info badge-count mb-1">
                            <?php echo $safety_roles['svp_count']; ?>
                        </span>
                            <small class="text-muted d-block">SVP</small>
                        </div>
                        <div class="col-6 mt-2 mt-auto">
                        <span class="badge bg-danger badge-count mb-1">
                            <?php echo $safety_roles['brandschutzwart_count']; ?>
                        </span>
                            <small class="text-muted d-block">Brandschutz</small>
                        </div>
                        <div class="col-6 mt-2 mt-auto">
                        <span class="badge bg-warning badge-count mb-1">
                            <?php echo $safety_roles['sprinklerwart_count']; ?>
                        </span>
                            <small class="text-muted d-block">Sprinklerwart</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Linke Spalte -->
        <div class="col-lg-8">
            <!-- Mitarbeiterverteilung nach Teams -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-people me-2"></i>Mitarbeiterverteilung</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="mb-3">Nach Teams</h6>
                            <?php foreach ($team_distribution as $team => $count): ?>
                                <div class="horizontal-bar-label">
                                    <span class="name"><?php echo htmlspecialchars($team); ?></span>
                                    <span class="value"><?php echo $count; ?></span>
                                </div>
                                <div class="horizontal-bar">
                                    <?php
                                    $percentage = ($total_employees > 0) ? ($count / $total_employees) * 100 : 0;
                                    $bar_color = '';

                                    if ($team === 'Team L') $bar_color = 'primary';
                                    elseif ($team === 'Team M') $bar_color = 'success';
                                    elseif ($team === 'Team N') $bar_color = 'warning';
                                    elseif ($team === 'Team O') $bar_color = 'info';
                                    elseif ($team === 'Team P') $bar_color = 'danger';
                                    else $bar_color = 'secondary';
                                    ?>
                                    <div class="horizontal-bar-fill bg-<?php echo $bar_color; ?>"
                                         style="width: <?php echo $percentage; ?>%;">
                                        <?php echo round($percentage, 1); ?>%
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="col-md-6">
                            <h6 class="mb-3">Nach Altersgruppen</h6>
                            <?php foreach ($age_groups as $age_group => $count): ?>
                                <div class="horizontal-bar-label">
                                    <span class="name"><?php echo htmlspecialchars($age_group); ?></span>
                                    <span class="value"><?php echo $count; ?></span>
                                </div>
                                <div class="horizontal-bar">
                                    <?php
                                    $percentage = ($total_employees > 0) ? ($count / $total_employees) * 100 : 0;
                                    $bar_color = '';

                                    if ($age_group === '< 20') $bar_color = 'success';
                                    elseif ($age_group === '20-29') $bar_color = 'info';
                                    elseif ($age_group === '30-39') $bar_color = 'primary';
                                    elseif ($age_group === '40-49') $bar_color = 'warning';
                                    elseif ($age_group === '50-59') $bar_color = 'danger';
                                    elseif ($age_group === '60+') $bar_color = 'dark';
                                    else $bar_color = 'secondary';
                                    ?>
                                    <div class="horizontal-bar-fill bg-<?php echo $bar_color; ?>"
                                         style="width: <?php echo $percentage; ?>%;">
                                        <?php echo round($percentage, 1); ?>%
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Neueinstellungen pro Monat -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Neueinstellungen (letzte 12 Monate)</h5>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#newEmployeesModal">
                            Details anzeigen
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div style="height: 300px;">
                        <canvas id="hiringChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Positionen und Bildungsabschlüsse -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-briefcase me-2"></i>Top 5 Positionen</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($top_positions as $position => $count): ?>
                                <div class="horizontal-bar-label">
                                    <span class="name" title="<?php echo htmlspecialchars($position); ?>">
                                        <?php echo strlen($position) > 25 ? htmlspecialchars(substr($position, 0, 22)) . '...' : htmlspecialchars($position); ?>
                                    </span>
                                    <span class="value"><?php echo $count; ?></span>
                                </div>
                                <div class="horizontal-bar">
                                    <?php
                                    $percentage = ($total_employees > 0) ? ($count / $total_employees) * 100 : 0;
                                    ?>
                                    <div class="horizontal-bar-fill bg-primary"
                                         style="width: <?php echo $percentage; ?>%;">
                                        <?php echo round($percentage, 1); ?>%
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Bildungsabschlüsse</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3 text-center">
                                <div class="d-inline-block me-3">
                                    <span class="badge bg-success badge-count mb-1">
                                        <?php echo $employees_with_education; ?>
                                    </span>
                                    <small class="text-muted d-block">Mit Abschluss</small>
                                </div>
                                <div class="d-inline-block">
                                    <span class="badge bg-secondary badge-count mb-1">
                                        <?php echo $employees_without_education; ?>
                                    </span>
                                    <small class="text-muted d-block">Ohne Angabe</small>
                                </div>
                            </div>

                            <div style="height: 150px;">
                                <canvas id="educationChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rechte Spalte -->
        <div class="col-lg-4">
            <!-- Betriebszugehörigkeit -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-calendar2-check me-2"></i>Betriebszugehörigkeit</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($tenure_groups as $tenure_group => $count): ?>
                        <div class="horizontal-bar-label">
                            <span class="name"><?php echo htmlspecialchars($tenure_group); ?></span>
                            <span class="value"><?php echo $count; ?></span>
                        </div>
                        <div class="horizontal-bar">
                            <?php
                            $percentage = ($total_employees > 0) ? ($count / $total_employees) * 100 : 0;
                            $bar_color = '';

                            if ($tenure_group === '< 1 Jahr') $bar_color = 'danger';
                            elseif ($tenure_group === '1-2 Jahre') $bar_color = 'warning';
                            elseif ($tenure_group === '3-5 Jahre') $bar_color = 'info';
                            elseif ($tenure_group === '6-10 Jahre') $bar_color = 'primary';
                            elseif ($tenure_group === '11-15 Jahre') $bar_color = 'success';
                            elseif ($tenure_group === '16+ Jahre') $bar_color = 'dark';
                            else $bar_color = 'secondary';
                            ?>
                            <div class="horizontal-bar-fill bg-<?php echo $bar_color; ?>"
                                 style="width: <?php echo $percentage; ?>%;">
                                <?php echo round($percentage, 1); ?>%
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Performance-Übersicht -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-stars me-2"></i>Talent & Performance</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Performance-Status</h6>
                                <span class="badge bg-primary"><?php echo array_sum($performance_distribution); ?> Bewertungen</span>
                            </div>
                            <?php foreach ($performance_distribution as $assessment => $count): ?>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span><?php echo htmlspecialchars($assessment); ?></span>
                                    <?php
                                    $badge_color = '';
                                    if ($assessment === 'überdurchschnittlich') $badge_color = 'success';
                                    elseif ($assessment === 'erfüllt') $badge_color = 'primary';
                                    else $badge_color = 'warning';
                                    ?>
                                    <span class="badge bg-<?php echo $badge_color; ?>"><?php echo $count; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Talentstatus</h6>
                                <span class="badge bg-primary"><?php echo array_sum($talent_distribution); ?> Bewertungen</span>
                            </div>
                            <?php foreach ($talent_distribution as $talent => $count): ?>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span><?php echo htmlspecialchars($talent); ?></span>
                                    <?php
                                    $badge_color = 'secondary';
                                    if ($talent === 'Fertiges Talent') $badge_color = 'success';
                                    elseif ($talent === 'Performing Talent') $badge_color = 'primary';
                                    elseif ($talent === 'Aufstrebendes Talent') $badge_color = 'info';
                                    elseif ($talent === 'Neu in der Rolle') $badge_color = 'warning';
                                    elseif ($talent === 'Braucht Entwicklung') $badge_color = 'danger';
                                    ?>
                                    <span class="badge bg-<?php echo $badge_color; ?>"><?php echo $count; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Mitarbeiterzufriedenheit</h6>
                                <span class="badge bg-primary"><?php echo array_sum($satisfaction_distribution); ?> Angaben</span>
                            </div>
                            <?php foreach ($satisfaction_distribution as $satisfaction => $count): ?>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span><?php echo htmlspecialchars($satisfaction); ?></span>
                                    <?php
                                    $badge_color = 'secondary';
                                    if ($satisfaction === 'Zufrieden') $badge_color = 'success';
                                    elseif ($satisfaction === 'Grundsätzlich zufrieden') $badge_color = 'warning';
                                    elseif ($satisfaction === 'Unzufrieden') $badge_color = 'danger';
                                    ?>
                                    <span class="badge bg-<?php echo $badge_color; ?>"><?php echo $count; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schulungsbedarf-Übersicht -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-journals me-2"></i>Trainingsübersicht</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 text-center">
                        <span class="badge bg-success p-2">
                            <i class="bi bi-mortarboard-fill me-1"></i>
                            Ø <?php echo $avg_trainings_per_employee; ?> Schulungen pro Mitarbeiter
                        </span>
                    </div>

                    <h6 class="mb-2">Top-Schulungsbedarf</h6>
                    <?php
                    // Create an associative array of training needs
                    $training_needs_array = [
                        'On-the-Job Training' => $training_needs['on_job_training'] ?? 0,
                        'Fachkenntnisse' => $training_needs['specialist_knowledge'] ?? 0,
                        'Schulabschluss' => $training_needs['school_completion'] ?? 0,
                        'Allg. Kenntnisse' => $training_needs['generalist_knowledge'] ?? 0,
                        'Zusatzaufgaben' => $training_needs['extra_tasks'] ?? 0,
                        'Führungstraining' => $training_needs['leadership_training'] ?? 0,
                        'Deutsch-Training' => $training_needs['german_training'] ?? 0,
                        'Staplerführerschein' => $training_needs['forklift'] ?? 0
                    ];

                    // Sort by count in descending order
                    arsort($training_needs_array);

                    // Take top 5
                    $top_training_needs = array_slice($training_needs_array, 0, 5, true);

                    foreach ($top_training_needs as $training => $count):
                        if ($count > 0):
                            ?>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span><?php echo htmlspecialchars($training); ?></span>
                                <span class="badge bg-primary"><?php echo $count; ?></span>
                            </div>
                        <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Mini-Statistiken -->
    <div class="section-divider">
        <span class="section-divider-text">Bonifikationen & Zulagen</span>
    </div>

    <div class="row row-cols-2 row-cols-md-4 row-cols-lg-6 g-2 mb-4">
        <div class="col">
            <div class="mini-card bg-light">
                <div class="number"><?php echo $boni_distribution['lehrabschluss']; ?></div>
                <div class="label">Lehrabschluss</div>
            </div>
        </div>
        <div class="col">
            <div class="mini-card bg-light">
                <div class="number"><?php echo $boni_distribution['grundlohn']; ?></div>
                <div class="label">Grundlohn</div>
            </div>
        </div>
        <div class="col">
            <div class="mini-card bg-light">
                <div class="number"><?php echo $boni_distribution['qualifikationsbonus']; ?></div>
                <div class="label">Qualifikationsbonus</div>
            </div>
        </div>
        <div class="col">
            <div class="mini-card bg-light">
                <div class="number"><?php echo $boni_distribution['expertenbonus']; ?></div>
                <div class="label">Expertenbonus</div>
            </div>
        </div>
        <div class="col">
            <div class="mini-card bg-light">
                <div class="number"><?php echo $zulage_distribution['5% Zulage'] ?? 0; ?></div>
                <div class="label">5% Zulage</div>
            </div>
        </div>
        <div class="col">
            <div class="mini-card bg-light">
                <div class="number"><?php echo $zulage_distribution['10% Zulage'] ?? 0; ?></div>
                <div class="label">10% Zulage</div>
            </div>
        </div>
    </div>
</div>

<!-- Modal für Onboarding-Mitarbeiter -->
<div class="modal fade" id="onboardingModal" tabindex="-1" aria-labelledby="onboardingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="onboardingModalLabel">
                    <i class="bi bi-person-plus me-2"></i>Mitarbeiter im Onboarding (<?php echo $total_onboarding; ?>)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="search-container">
                    <input type="text" id="onboardingSearch" class="form-control"
                           placeholder="Suche nach Mitarbeitern...">
                    <i class="bi bi-search search-icon"></i>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Team/Gruppe</th>
                            <th>Eintrittsdatum</th>
                            <th>Onboarding-Status</th>
                        </tr>
                        </thead>
                        <tbody id="onboardingTable">
                        <?php foreach ($onboarding_employees as $emp): ?>
                            <tr data-search="<?php echo strtolower(htmlspecialchars($emp['name'])); ?>">
                                <td><?php echo $emp['badge_id']; ?></td>
                                <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['position']); ?></td>
                                <td>
                                    <?php
                                    if (!empty($emp['crew']) && $emp['crew'] != '---') {
                                        echo htmlspecialchars($emp['crew']);
                                    } else {
                                        echo htmlspecialchars($emp['gruppe']);
                                    }
                                    ?>
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($emp['entry_date'])); ?></td>
                                <td>
                                    <?php
                                    $status_badge = '';
                                    switch ($emp['onboarding_status']) {
                                        case 1:
                                            $status_badge = '<span class="badge bg-danger">Neu</span>';
                                            break;
                                        case 2:
                                            $status_badge = '<span class="badge bg-warning">Einarbeitung</span>';
                                            break;
                                        case 3:
                                            $status_badge = '<span class="badge bg-info">Fortgeschritten</span>';
                                            break;
                                        case 4:
                                            $status_badge = '<span class="badge bg-success">Fast abgeschlossen</span>';
                                            break;
                                        default:
                                            $status_badge = '<span class="badge bg-secondary">Unbekannt</span>';
                                    }
                                    echo $status_badge;
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($onboarding_employees) === 0): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>Derzeit sind keine Mitarbeiter im Onboarding-Prozess.
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal für neue Mitarbeiter -->
<div class="modal fade" id="newEmployeesModal" tabindex="-1" aria-labelledby="newEmployeesModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newEmployeesModalLabel">
                    <i class="bi bi-person-plus-fill me-2"></i>Neue Mitarbeiter (<?php echo $new_employees_count; ?>)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Team/Gruppe</th>
                            <th>Eintrittsdatum</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($new_employees as $emp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['position']); ?></td>
                                <td>
                                    <?php
                                    if (!empty($emp['crew']) && $emp['crew'] != '---') {
                                        echo htmlspecialchars($emp['crew']);
                                    } else {
                                        echo htmlspecialchars($emp['gruppe']);
                                    }
                                    ?>
                                </td>
                                <td><?php echo date('d.m.Y', strtotime($emp['entry_date'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($new_employees) === 0): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>In den letzten 3 Monaten wurden keine neuen Mitarbeiter
                        eingestellt.
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Suche für Onboarding-Mitarbeiter
        const onboardingSearch = document.getElementById('onboardingSearch');
        const onboardingRows = document.querySelectorAll('#onboardingTable tr');

        if (onboardingSearch) {
            onboardingSearch.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase();

                onboardingRows.forEach(row => {
                    const searchText = row.dataset.search;
                    if (searchText.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }

        // Neueinstellungen Chart
        const hiringCtx = document.getElementById('hiringChart');
        if (hiringCtx) {
            new Chart(hiringCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php echo "'" . implode("', '", array_keys($monthly_hires)) . "'"; ?>
                    ],
                    datasets: [{
                        label: 'Neueinstellungen',
                        data: [
                            <?php echo implode(", ", array_values($monthly_hires)); ?>
                        ],
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        // Education Chart
        const educationCtx = document.getElementById('educationChart');
        if (educationCtx && Object.keys(<?php echo json_encode($education_distribution); ?>).length > 0) {
            new Chart(educationCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php
                        $education_labels = [];
                        foreach ($education_distribution as $education => $count) {
                            $education_labels[] = "'" . $education . "'";
                        }
                        echo implode(", ", $education_labels);
                        ?>
                    ],
                    datasets: [{
                        data: [
                            <?php echo implode(", ", array_values($education_distribution)); ?>
                        ],
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.7)',
                            'rgba(54, 162, 235, 0.7)',
                            'rgba(255, 206, 86, 0.7)',
                            'rgba(75, 192, 192, 0.7)',
                            'rgba(153, 102, 255, 0.7)',
                            'rgba(255, 159, 64, 0.7)',
                            'rgba(199, 199, 199, 0.7)',
                            'rgba(83, 102, 255, 0.7)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        }
    });
</script>
</body>
</html>