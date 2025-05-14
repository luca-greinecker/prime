<?php
/**
 * safety_dashboard.php
 *
 * Sicherheits-Dashboard für den EHS-Manager mit Übersicht über Sicherheitsschulungen,
 * Ersthelfer und weiteren sicherheitsrelevanten Kennzahlen.
 */

// Basis-Files einbinden und Benutzer prüfen
include 'access_control.php';
include 'training_functions.php';
include_once 'safety_dashboard_helpers.php';  // Helper-Funktionen für das Safety-Dashboard

global $conn;
pruefe_benutzer_eingeloggt();
pruefe_ehs_zugriff();

// Statusmeldungen
$success_message = '';
$error_message = '';

// Formularverarbeitung: Aktualisierung der Sicherheitsdaten eines Mitarbeiters (Ersthelfer/SVP)
if (isset($_POST['update_employee_safety'])) {
    $employee_id = (int)$_POST['employee_id'];
    $ersthelfer = isset($_POST['ersthelfer']) ? 1 : 0;
    $svp = isset($_POST['svp']) ? 1 : 0;
    $ersthelfer_zertifikat_ablauf = !empty($_POST['ersthelfer_zertifikat_ablauf']) ? $_POST['ersthelfer_zertifikat_ablauf'] : NULL;

    if (updateEmployeeSafetyData($conn, $employee_id, $ersthelfer, $svp, $ersthelfer_zertifikat_ablauf)) {
        $success_message = "Die Sicherheitsdaten für den Mitarbeiter wurden erfolgreich aktualisiert.";
    } else {
        $error_message = "Fehler beim Aktualisieren der Daten: " . $conn->error;
    }
}

// Aktuelle Quartalsdaten
$quarter_info = getCurrentQuarterInfo();
$current_year = $quarter_info['year'];
$current_quarter = $quarter_info['quarter'];
$quarter_names = $quarter_info['quarter_names'];
$current_quarter_name = $quarter_info['current_quarter_name'];

// Sicherheitsschulungen des aktuellen Quartals abrufen
$security_trainings = getSecurityTrainings($conn, $current_quarter_name);

// Team-Definitionen
$teams = ["Team L", "Team M", "Team N", "Team O", "Team P"];
$departments = ["Tagschicht", "Verwaltung"];
$tagschicht_areas = ["Elektrik", "Mechanik", "CPO", "Qualitätssicherung", "Sortierung", "Sonstiges"];

// Alle Teams für die Statistiken
$all_teams = ["Team L", "Team M", "Team N", "Team O", "Team P", "Tagschicht", "Verwaltung"];

// Gesamtanzahl der Teilnehmer an Sicherheitsschulungen im aktuellen Quartal
$total_participants = getTotalParticipants($conn, $current_quarter_name);

// Gesamtanzahl aktiver Mitarbeiter
$total_employees = getTotalActiveEmployees($conn);

// Berechnung der Teilnahmequote
$participation_rate = ($total_employees > 0) ? ($total_participants / $total_employees) * 100 : 0;

// Anzahl der aktiven Ersthelfer
$total_first_aiders = getTotalFirstAiders($conn);

// Berechnung der Ersthelfer-Quote (Soll ca. 10% der Belegschaft sein)
$first_aider_rate = ($total_employees > 0) ? ($total_first_aiders / $total_employees) * 100 : 0;
$required_first_aiders = ceil($total_employees * 0.1); // mind. 10%

// Anzahl der Sicherheitsvertrauenspersonen (SVP)
$total_svp = getTotalSVPs($conn);

// Liste aller Ersthelfer mit Zertifikatsablauf
$first_aiders = getFirstAiders($conn);

// Liste aller Sicherheitsvertrauenspersonen
$svp_list = getSVPs($conn);

// Anzahl der anwesenden Ersthelfer heute
$present_first_aiders = getPresentFirstAiders($conn);

// Anzahl der anwesenden Mitarbeiter heute
$present_employees = getPresentEmployees($conn);

// Berechnung der Ersthelfer-Quote der anwesenden Mitarbeiter
$present_first_aider_rate = ($present_employees > 0) ? ($present_first_aiders / $present_employees) * 100 : 0;

// Ersthelfer, deren Zertifikat in den nächsten 3 Monaten ausläuft
$expiring_certificates = getExpiringCertificates($conn, 3);

// Fehlende Teilnehmer für aktuelle Quartalssicherheitsschulung
$missing_participants = getMissingParticipants($conn, $current_quarter_name);

// Gruppieren der fehlenden Teilnehmer nach Bereich
$missing_by_group = groupMissingParticipants($missing_participants, $tagschicht_areas);

// Liste aller Mitarbeiter für die Ersthelfer/SVP-Verwaltung
$all_employees = getAllEmployees($conn);

// Sicherheitsstatistiken pro Team/Bereich
$team_statistics = getTeamStatistics($conn, $all_teams);

// Verteilung der Ersthelfer nach Bereich (Schichtarbeit, Tagschicht, Verwaltung)
$area_distribution = getFirstAiderDistribution($first_aiders);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Sicherheits-Dashboard</title>
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Basis-Stile für die Karten und Icons */
        .dashboard-card {
            height: 100%;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .card-icon {
            font-size: 2.5rem;
        }

        .alert-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.8rem;
        }

        .status-indicator {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }

        .certificate-expiring {
            color: #dc3545;
            font-weight: bold;
        }

        .certificate-warning {
            color: #ffc107;
            font-weight: bold;
        }

        .certificate-ok {
            color: #28a745;
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

        .search-icon {
            position: absolute;
            right: 10px;
            top: 10px;
            color: #6c757d;
        }

        /* Styles für die Team-Karten */
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
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-4"><i class="bi bi-shield-check me-2"></i>Sicherheits-Dashboard</h1>
        <div>
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#safetyModal">
                <i class="bi bi-person-plus-fill me-2"></i>Ersthelfer/SVP verwalten
            </button>
            <a href="training_overview.php" class="btn btn-outline-primary">
                <i class="bi bi-list-check me-2"></i>Alle Schulungen anzeigen
            </a>
        </div>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Kennzahlen-Übersicht -->
    <div class="row mb-4">
        <!-- Teilnahme Sicherheitsschulung -->
        <div class="col-md-3">
            <div class="card dashboard-card">
                <div class="card-body text-center">
                    <div class="card-icon text-primary mb-2">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <h5 class="card-title">Teilnahme Sicherheitsschulung</h5>
                    <h2 class="mb-0"><?php echo round($participation_rate, 1); ?>%</h2>
                    <p class="text-muted mb-2"><?php echo $total_participants; ?> von <?php echo $total_employees; ?>
                        Mitarbeitern</p>
                    <div class="progress">
                        <div class="progress-bar bg-primary" role="progressbar"
                             style="width: <?php echo $participation_rate; ?>%"
                             aria-valuenow="<?php echo $participation_rate; ?>" aria-valuemin="0"
                             aria-valuemax="100"></div>
                    </div>
                    <p class="mt-2 mb-0 small">Aktuelle Schulung: <?php echo $current_quarter_name; ?></p>
                </div>
            </div>
        </div>

        <!-- Ersthelfer-Quote -->
        <div class="col-md-3">
            <div class="card dashboard-card">
                <div class="card-body text-center">
                    <div class="card-icon text-success position-relative mb-2">
                        <i class="bi bi-bandaid-fill"></i>
                        <?php if ($first_aider_rate < 10): ?>
                            <span class="alert-badge bg-danger">!</span>
                        <?php endif; ?>
                    </div>
                    <h5 class="card-title">Ersthelfer-Quote</h5>
                    <h2 class="mb-0"><?php echo round($first_aider_rate, 1); ?>%</h2>
                    <p class="text-muted mb-2"><?php echo $total_first_aiders; ?>
                        von <?php echo $required_first_aiders; ?> benötigt</p>
                    <div class="progress">
                        <div class="progress-bar bg-<?php echo ($first_aider_rate >= 10) ? 'success' : 'warning'; ?>"
                             role="progressbar" style="width: <?php echo min($first_aider_rate * 10, 100); ?>%"
                             aria-valuenow="<?php echo $first_aider_rate; ?>" aria-valuemin="0"
                             aria-valuemax="10"></div>
                    </div>
                    <p class="mt-2 mb-0 small">Empfohlen: mind. 10% der Belegschaft</p>
                </div>
            </div>
        </div>

        <!-- Anwesende Ersthelfer -->
        <div class="col-md-3">
            <div class="card dashboard-card">
                <div class="card-body text-center">
                    <div class="card-icon text-info position-relative mb-2">
                        <i class="bi bi-person-check-fill"></i>
                        <?php if ($present_first_aider_rate < 10): ?>
                            <span class="alert-badge bg-danger">!</span>
                        <?php endif; ?>
                    </div>
                    <h5 class="card-title">Anwesende Ersthelfer</h5>
                    <h2 class="mb-0"><?php echo $present_first_aiders; ?></h2>
                    <p class="text-muted mb-2">von <?php echo $present_employees; ?> anwesenden Mitarbeitern</p>
                    <div class="progress">
                        <div class="progress-bar bg-info" role="progressbar"
                             style="width: <?php echo min($present_first_aider_rate * 10, 100); ?>%"
                             aria-valuenow="<?php echo $present_first_aider_rate; ?>" aria-valuemin="0"
                             aria-valuemax="10"></div>
                    </div>
                    <p class="mt-2 mb-0 small">Quote: <?php echo round($present_first_aider_rate, 1); ?>%</p>
                </div>
            </div>
        </div>

        <!-- Ablaufende Zertifikate -->
        <div class="col-md-3">
            <div class="card dashboard-card">
                <div class="card-body text-center">
                    <div class="card-icon text-warning position-relative mb-2">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php if ($expiring_certificates > 0): ?>
                            <span class="alert-badge bg-danger"><?php echo $expiring_certificates; ?></span>
                        <?php endif; ?>
                    </div>
                    <h5 class="card-title">Ablaufende Zertifikate</h5>
                    <h2 class="mb-0"><?php echo $expiring_certificates; ?></h2>
                    <p class="text-muted mb-2">in den nächsten 3 Monaten</p>
                    <div class="progress">
                        <div class="progress-bar bg-warning" role="progressbar"
                             style="width: <?php echo min(($expiring_certificates / max(1, $total_first_aiders)) * 100, 100); ?>%"
                             aria-valuenow="<?php echo $expiring_certificates; ?>" aria-valuemin="0"
                             aria-valuemax="<?php echo $total_first_aiders; ?>"></div>
                    </div>
                    <p class="mt-2 mb-0 small">
                        <a href="#ablaufende-zertifikate" class="text-decoration-none">Details anzeigen</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Linke Spalte: Sicherheitsschulungen und Ersthelfer nach Team -->
        <div class="col-lg-7">
            <!-- Aktuelle Sicherheitsschulungen -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <div class="d-flex align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-calendar-check me-2"></i>
                            Aktuelle Sicherheitsschulungen (<?php echo $current_quarter_name; ?>)
                        </h5>
                        <div class="ms-auto d-flex gap-2">
                            <a href="trainings.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-plus-circle me-1"></i>Neue Schulung anlegen
                            </a>
                            <a href="safety_history.php" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-clock-history me-2"></i>Schulungshistorie
                            </a>
                            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal"
                                    data-bs-target="#missingModal">
                                <i class="bi bi-person-x-fill me-2"></i>Fehlende Mitarbeiter
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <?php if (count($security_trainings) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
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
                                <?php foreach ($security_trainings as $training): ?>
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
                            für <?php echo $current_quarter_name; ?> gefunden.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ersthelfer pro Team/Bereich -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Ersthelfer-Verteilung nach Team/Bereich</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Team/Bereich</th>
                                <th>Ersthelfer</th>
                                <th>Quote</th>
                                <th>SVP</th>
                                <th>Verteilung</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($team_statistics as $team => $stats): ?>
                                <tr>
                                    <td><strong><?php echo $team; ?></strong></td>
                                    <td>
                                        <span class="badge bg-success"><?php echo $stats['first_aiders']; ?></span>
                                        <span class="text-muted ms-1 small">von <?php echo $stats['total']; ?></span>
                                    </td>
                                    <td>
                                        <?php $rate_class = ($stats['first_aider_rate'] >= 10) ? 'success' : (($stats['first_aider_rate'] >= 5) ? 'warning' : 'danger'); ?>
                                        <span class="badge bg-<?php echo $rate_class; ?>"><?php echo round($stats['first_aider_rate'], 1); ?>%</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $stats['svp']; ?></span>
                                    </td>
                                    <td style="width: 30%;">
                                        <div class="team-chart">
                                            <div class="team-chart-bar"
                                                 style="width: <?php echo min($stats['first_aider_rate'] * 10, 100); ?>%;"></div>
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

        <!-- Mittlere Spalte: Verteilung nach Bereich (vertikal angeordnet) -->
        <div class="col-lg-2">
            <!-- Fehlende Schulungsteilnahmen -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-person-x me-2"></i>Fehlende Schulungsteilnahmen</h5>
                </div>
                <div class="card-body">
                    <?php
                    // Berechnung der fehlenden Teilnehmer pro Bereich aus $missing_by_group
                    $missing_totals = [];
                    foreach ($missing_by_group as $group => $subgroups) {
                        $missing_count = 0;
                        foreach ($subgroups as $subgroup => $employees) {
                            $missing_count += count($employees);
                        }
                        $missing_totals[$group] = $missing_count;
                    }
                    if (count($missing_totals) > 0) {
                        echo '<ul class="list-unstyled mb-0">';
                        foreach ($missing_totals as $group => $count) {
                            echo '<li><small>' . htmlspecialchars($group) . ': ' . $count . ' fehlend</small></li>';
                        }
                        echo '</ul>';
                    } else {
                        echo '<p class="mb-0"><small>Alle Mitarbeiter haben an der Schulung teilgenommen.</small></p>';
                    }
                    ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Verteilung nach Bereich</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($area_distribution as $area => $count): ?>
                        <div class="text-center mb-3">
                            <h3><?php echo $count; ?></h3>
                            <p class="text-muted mb-0"><?php echo $area; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Rechte Spalte: Ersthelfer-Zertifikate und SVP -->
        <div class="col-lg-3">
            <!-- Sicherheitsvertrauenspersonen (SVP) -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-shield me-2"></i>Sicherheitsvertrauenspersonen
                            (<?php echo $total_svp; ?>)</h5>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#safetyModal">
                            <i class="bi bi-person-plus me-1"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($svp_list as $svp): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0"><?php echo htmlspecialchars($svp['name']); ?></h6>
                                        <small class="text-muted">
                                            <?php
                                            echo (!empty($svp['crew']) && $svp['crew'] != '---')
                                                ? htmlspecialchars($svp['crew'])
                                                : htmlspecialchars($svp['gruppe']);
                                            ?>
                                        </small>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($svp['position']); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($svp_list) === 0): ?>
                            <div class="list-group-item">
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-exclamation-circle display-5"></i>
                                    <p class="mt-3">Keine Sicherheitsvertrauenspersonen vorhanden.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Ersthelfer -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-bandaid me-2"></i>Ersthelfer
                            (<?php echo $total_first_aiders; ?>)</h5>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#safetyModal">
                            <i class="bi bi-person-plus me-1"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <!-- Scrollbarer Container für Ersthelfer-Liste -->
                    <div class="list-group list-group-flush overflow-auto" style="max-height: 400px;"
                         id="ablaufende-zertifikate">
                        <?php foreach ($first_aiders as $aider): ?>
                            <div class="list-group-item list-group-item-action">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0"><?php echo htmlspecialchars($aider['name']); ?></h6>
                                        <small class="text-muted">
                                            <?php
                                            echo (!empty($aider['crew']) && $aider['crew'] != '---')
                                                ? htmlspecialchars($aider['crew'])
                                                : htmlspecialchars($aider['gruppe']);
                                            ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <?php if (!empty($aider['ersthelfer_zertifikat_ablauf'])): ?>
                                            <?php
                                            $certificate_expires = strtotime($aider['ersthelfer_zertifikat_ablauf']);
                                            $days_until_expiry = ceil(($certificate_expires - time()) / (60 * 60 * 24));
                                            if ($days_until_expiry <= 0) {
                                                echo '<span class="certificate-expiring"><i class="bi bi-exclamation-triangle-fill me-1"></i>Abgelaufen!</span>';
                                            } elseif ($days_until_expiry <= 90) {
                                                echo '<span class="certificate-warning"><i class="bi bi-clock me-1"></i>' . date('d.m.Y', $certificate_expires) . '<br><small>' . $days_until_expiry . ' Tage</small></span>';
                                            } else {
                                                echo '<span class="certificate-ok">' . date('d.m.Y', $certificate_expires) . '</span>';
                                            }
                                            ?>
                                        <?php else: ?>
                                            <span class="text-muted">Kein Datum</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($first_aiders) === 0): ?>
                            <div class="list-group-item">
                                <div class="text-center text-muted py-3">
                                    <i class="bi bi-exclamation-circle display-5"></i>
                                    <p class="mt-3">Keine Ersthelfer vorhanden.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Ersthelfer/SVP Verwaltung -->
<div class="modal fade" id="safetyModal" tabindex="-1" aria-labelledby="safetyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="safetyModalLabel">Ersthelfer und Sicherheitsvertrauenspersonen
                    verwalten</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="position-relative mb-3">
                    <input type="text" id="employeeSearch" class="form-control" placeholder="Namen eingeben...">
                    <i class="bi bi-search search-icon"></i>
                </div>
                <div class="overflow-auto" style="max-height: 500px;">
                    <?php foreach ($all_employees as $emp): ?>
                        <div class="card mb-2 employee-card"
                             data-name="<?php echo strtolower(htmlspecialchars($emp['name'])); ?>">
                            <div class="card-body p-3">
                                <form action="safety_dashboard.php" method="POST" class="employee-form">
                                    <input type="hidden" name="employee_id" value="<?php echo $emp['employee_id']; ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-5">
                                            <h6 class="mb-0"><?php echo htmlspecialchars($emp['name']); ?></h6>
                                            <small class="text-muted">
                                                <?php
                                                echo (!empty($emp['crew']) && $emp['crew'] != '---')
                                                    ? htmlspecialchars($emp['crew'])
                                                    : htmlspecialchars($emp['gruppe'] . ' - ' . $emp['position']);
                                                ?>
                                            </small>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="input-group mb-2">
                                                <span class="input-group-text bg-light">Ablauf</span>
                                                <input type="date" class="form-control form-control-sm"
                                                       name="ersthelfer_zertifikat_ablauf"
                                                       value="<?php echo $emp['ersthelfer_zertifikat_ablauf'] ?? ''; ?>"
                                                    <?php echo (!$emp['ersthelfer']) ? 'disabled' : ''; ?>>
                                            </div>
                                        </div>
                                        <div class="col-md-3 text-md-end">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="ersthelfer"
                                                       value="1"
                                                       id="ersthelfer_<?php echo $emp['employee_id']; ?>"
                                                    <?php echo ($emp['ersthelfer']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label"
                                                       for="ersthelfer_<?php echo $emp['employee_id']; ?>">Ersthelfer</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" name="svp" value="1"
                                                       id="svp_<?php echo $emp['employee_id']; ?>"
                                                    <?php echo ($emp['svp']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label"
                                                       for="svp_<?php echo $emp['employee_id']; ?>">SVP</label>
                                            </div>
                                            <button type="submit" name="update_employee_safety"
                                                    class="btn btn-primary btn-sm mt-1">
                                                <i class="bi bi-save me-1"></i>Speichern
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Fehlende Teilnehmer -->
<div class="modal fade" id="missingModal" tabindex="-1" aria-labelledby="missingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="missingModalLabel">
                    <i class="bi bi-person-x me-2"></i>Fehlende Teilnehmer - <?php echo $current_quarter_name; ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (count($missing_participants) > 0): ?>
                    <div class="row">
                        <?php foreach (['Schichtarbeit', 'Tagschicht', 'Verwaltung'] as $group): ?>
                            <?php if (isset($missing_by_group[$group])): ?>
                                <div class="col-12 mb-4">
                                    <h5>
                                        <?php echo $group; ?>
                                        <span class="badge bg-secondary">
                                                <?php
                                                $group_total = 0;
                                                foreach ($missing_by_group[$group] as $subgroup => $employees) {
                                                    $group_total += count($employees);
                                                }
                                                echo $group_total;
                                                ?>
                                            </span>
                                    </h5>
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
                                                        <div class="list-group list-group-flush overflow-auto"
                                                             style="max-height: 300px;">
                                                            <?php foreach ($employees as $emp): ?>
                                                                <div class="list-group-item">
                                                                    <div class="d-flex justify-content-between">
                                                                        <div><?php echo htmlspecialchars($emp['name']); ?></div>
                                                                        <small class="text-muted"><?php echo htmlspecialchars($emp['position']); ?></small>
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

<!-- Bootstrap JS -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    // DOMContentLoaded-Event: Mitarbeiter-Suche und Toggle für Ersthelfer-Checkboxen
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('employeeSearch');
        const employeeCards = document.querySelectorAll('.employee-card');
        searchInput.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase().trim();
            employeeCards.forEach(card => {
                const employeeName = card.dataset.name;
                card.style.display = employeeName.includes(searchTerm) ? 'block' : 'none';
            });
        });

        // Aktivierung/Deaktivierung des Ablaufdatums bei der Ersthelfer-Checkbox
        document.querySelectorAll('input[name="ersthelfer"]').forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                const form = this.closest('form');
                const dateInput = form.querySelector('input[name="ersthelfer_zertifikat_ablauf"]');
                if (this.checked) {
                    dateInput.disabled = false;
                } else {
                    dateInput.disabled = true;
                    dateInput.value = '';
                }
            });
        });
    });
</script>
</body>
</html>