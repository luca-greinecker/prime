<?php
/**
 * hr_dashboard.php
 *
 * HR-Dashboard mit Kennzahlen zur Personalstruktur, Onboarding, Ausbildung und weiteren HR-Metriken.
 *
 * Archivierte Mitarbeiter (status = 9999) werden in allen Anzeigen ausgeblendet.
 */

include 'access_control.php';
include_once 'dashboard_helpers.php';     // Allgemeine Helper-Funktionen
include_once 'hr_dashboard_helpers.php';  // HR-spezifische Helper-Funktionen

global $conn;
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// Aktuelle Periode bestimmen
$current_year = date('Y');
$period = getReviewPeriodForYear($conn, $current_year);

// Grundlegende Mitarbeiterstatistiken abrufen
$total_employees = getTotalEmployeesCount($conn);
$gender_distribution = getGenderDistribution($conn);
$group_distribution = getGroupDistribution($conn);
$team_distribution = getTeamDistribution($conn);

// Onboarding-Statistiken
$onboarding_data = getOnboardingStats($conn);
$onboarding_stats = $onboarding_data['stats'];
$total_onboarding = $onboarding_data['total'];
$onboarding_employees = getOnboardingEmployees($conn);

// Neue Mitarbeiter (eingetreten in den letzten 3 Monaten)
$new_employees_count = getNewEmployeesCount($conn, 3);
$new_employees = getNewEmployees($conn, 3);
$monthly_hires = getMonthlyHires($conn, 12);

// Mitarbeiterdaten für Austritte (neue Funktion)
$departed_employees_count = count(getRecentDepartures($conn, 3));
$departed_employees = getRecentDepartures($conn, 3);
$monthly_departures = getMonthlyDepartures($conn, 12);
$departure_reasons = getDepartureReasons($conn, 12);

// Altersstruktur und Betriebszugehörigkeit
$age_groups = getAgeGroups($conn);
$tenure_groups = getTenureGroups($conn);

// Bildungsabschlüsse
$education_distribution = getEducationDistribution($conn);
$education_counts = getEmployeesWithEducationCount($conn, $total_employees);
$employees_with_education = $education_counts['with_education'];
$employees_without_education = $education_counts['without_education'];

// Sicherheitsrollen
$safety_roles = getSafetyRoles($conn);

// Top Positionen
$top_positions = getTopPositions($conn, 5);

// Talententwicklung
$talent_distribution = getTalentDistribution($conn, $period['start_date'], $period['end_date']);

// Performance-Bewertungen und weitere Metriken
$performance_distribution = getPerformanceDistribution($conn);
$career_distribution = getCareerDistribution($conn);
$satisfaction_distribution = getSatisfactionDistribution($conn);

// Trainingsteilnahmen
$top_training_participation = getTopTrainingParticipation($conn, 10);
$avg_trainings_per_employee = getAvgTrainingsPerEmployee($conn);

// Lohnschema
$lohnschema_distribution = getLohnschemaDist($conn);
$boni_distribution = getBoniDistribution($conn);
$zulage_distribution = getZulageDistribution($conn);

// Schulungsbedarf
$training_needs = getTrainingNeeds($conn);
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
        /* Minimal custom CSS - verwendet hauptsächlich Bootstrap-Klassen */
        .dashboard-card {
            height: 100%;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .card-icon {
            font-size: 2.5rem;
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

        .search-icon {
            position: absolute;
            right: 10px;
            top: 10px;
            color: #6c757d;
        }

        .tab-buttons {
            display: flex;
            margin-bottom: 1rem;
        }

        .tab-button {
            padding: 0.5rem 1rem;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
            border-top-left-radius: 0.25rem;
            border-top-right-radius: 0.25rem;
            cursor: pointer;
            margin-right: 0.25rem;
        }

        .tab-button.active {
            background-color: #fff;
            border-bottom: 1px solid #fff;
            margin-bottom: -1px;
            font-weight: 500;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1><i class="bi bi-people me-2"></i>HR-Dashboard</h1>
    </div>

    <!-- Hauptkennzahlen -->
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4 mb-4">
        <!-- Mitarbeiteranzahl -->
        <div class="col">
            <div class="card dashboard-card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="card-icon text-primary mb-2">
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

            <!-- Neueinstellungen und Austritte -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="tab-buttons">
                            <div class="tab-button active" data-tab="hires">
                                <i class="bi bi-person-plus me-1"></i>Neueinstellungen
                            </div>
                            <div class="tab-button" data-tab="departures">
                                <i class="bi bi-person-dash me-1"></i>Austritte
                            </div>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary" id="hiresDetailBtn" data-bs-toggle="modal"
                                    data-bs-target="#newEmployeesModal">
                                Details anzeigen
                            </button>
                            <button class="btn btn-sm btn-outline-danger d-none" id="departuresDetailBtn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#departedEmployeesModal">
                                Details anzeigen
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div id="hiresTab">
                        <div style="height: 300px;">
                            <canvas id="hiringChart"></canvas>
                        </div>
                    </div>
                    <div id="departuresTab" style="display: none;">
                        <div style="height: 300px;">
                            <canvas id="departuresChart"></canvas>
                        </div>
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
            <div class="card bg-light h-100 p-2">
                <div class="card-body text-center p-2">
                    <h3 class="fs-3 fw-bold"><?php echo $boni_distribution['lehrabschluss']; ?></h3>
                    <small class="text-muted">Lehrabschluss</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-light h-100 p-2">
                <div class="card-body text-center p-2">
                    <h3 class="fs-3 fw-bold"><?php echo $boni_distribution['grundlohn']; ?></h3>
                    <small class="text-muted">Grundlohn</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-light h-100 p-2">
                <div class="card-body text-center p-2">
                    <h3 class="fs-3 fw-bold"><?php echo $boni_distribution['qualifikationsbonus']; ?></h3>
                    <small class="text-muted">Qualifikationsbonus</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-light h-100 p-2">
                <div class="card-body text-center p-2">
                    <h3 class="fs-3 fw-bold"><?php echo $boni_distribution['expertenbonus']; ?></h3>
                    <small class="text-muted">Expertenbonus</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-light h-100 p-2">
                <div class="card-body text-center p-2">
                    <h3 class="fs-3 fw-bold"><?php echo $zulage_distribution['5% Zulage'] ?? 0; ?></h3>
                    <small class="text-muted">5% Zulage</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-light h-100 p-2">
                <div class="card-body text-center p-2">
                    <h3 class="fs-3 fw-bold"><?php echo $zulage_distribution['10% Zulage'] ?? 0; ?></h3>
                    <small class="text-muted">10% Zulage</small>
                </div>
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
                <div class="search-container position-relative mb-3">
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
                                        case 0:
                                            $status_badge = '<span class="badge bg-danger">Neu</span>';
                                            break;
                                        case 1:
                                            $status_badge = '<span class="badge bg-warning">Empfang</span>';
                                            break;
                                        case 2:
                                            $status_badge = '<span class="badge bg-info">HR</span>';
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

<!-- Modal für Mitarbeiter-Austritte (NEU) -->
<div class="modal fade" id="departedEmployeesModal" tabindex="-1" aria-labelledby="departedEmployeesModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="departedEmployeesModalLabel">
                    <i class="bi bi-person-dash-fill me-2"></i>Mitarbeiter-Austritte
                    (<?php echo $departed_employees_count; ?>)
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
                            <th>Austrittsdatum</th>
                            <th>Grund</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($departed_employees as $emp): ?>
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
                                <td><?php echo date('d.m.Y', strtotime($emp['leave_date'])); ?></td>
                                <td>
                                    <?php
                                    echo !empty($emp['leave_reason']) ?
                                        htmlspecialchars($emp['leave_reason']) :
                                        '<span class="text-muted">Nicht angegeben</span>';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (count($departed_employees) === 0): ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>In den letzten 3 Monaten sind keine Mitarbeiter
                        ausgetreten.
                    </div>
                <?php endif; ?>

                <?php if (count($departure_reasons) > 0): ?>
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="mb-3">Austrittsgründe (letzte 12 Monate)</h6>
                        <?php foreach ($departure_reasons as $reason => $count): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>
                                <?php echo !empty($reason) ?
                                    htmlspecialchars($reason) :
                                    '<span class="text-muted">Nicht angegeben</span>'; ?>
                            </span>
                                <span class="badge bg-secondary"><?php echo $count; ?></span>
                            </div>
                        <?php endforeach; ?>
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

        // Tab Wechsel Logik für Neueinstellungen/Austritte
        const tabButtons = document.querySelectorAll('.tab-button');
        const hiresTab = document.getElementById('hiresTab');
        const departuresTab = document.getElementById('departuresTab');
        const hiresDetailBtn = document.getElementById('hiresDetailBtn');
        const departuresDetailBtn = document.getElementById('departuresDetailBtn');

        tabButtons.forEach(button => {
            button.addEventListener('click', function () {
                // Remove active class from all buttons
                tabButtons.forEach(btn => btn.classList.remove('active'));

                // Add active class to clicked button
                this.classList.add('active');

                // Show appropriate tab content
                if (this.dataset.tab === 'hires') {
                    hiresTab.style.display = '';
                    departuresTab.style.display = 'none';
                    hiresDetailBtn.classList.remove('d-none');
                    departuresDetailBtn.classList.add('d-none');
                } else {
                    hiresTab.style.display = 'none';
                    departuresTab.style.display = '';
                    hiresDetailBtn.classList.add('d-none');
                    departuresDetailBtn.classList.remove('d-none');
                }
            });
        });

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

        // Mitarbeiter-Austritt Chart (NEU)
        const departuresCtx = document.getElementById('departuresChart');
        if (departuresCtx) {
            new Chart(departuresCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php echo "'" . implode("', '", array_keys($monthly_departures)) . "'"; ?>
                    ],
                    datasets: [{
                        label: 'Austritte',
                        data: [
                            <?php echo implode(", ", array_values($monthly_departures)); ?>
                        ],
                        backgroundColor: 'rgba(220, 53, 69, 0.5)',
                        borderColor: 'rgba(220, 53, 69, 1)',
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