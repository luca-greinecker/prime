<?php
/**
 * profil.php
 *
 * Zeigt das Profil des aktuell eingeloggten Benutzers (Mitarbeiter).
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// 1) Aktueller Mitarbeiter ermitteln
if (!isset($_SESSION['mitarbeiter_id'])) {
    header("Location: login.php");
    exit;
}
$mitarbeiter_id = (int)$_SESSION['mitarbeiter_id'];

// 2) Stammdaten aus "employees" holen
$stmtEmp = $conn->prepare("
    SELECT
        employee_id, name, birthdate, crew, position, leasing, ersthelfer,
        svp, brandschutzwart, sprinklerwart, bild, entry_date, first_entry_date,
        gender, lohnschema, pr_lehrabschluss, pr_anfangslohn, pr_grundlohn,
        pr_qualifikationsbonus, pr_expertenbonus, tk_qualifikationsbonus_1,
        tk_qualifikationsbonus_2, tk_qualifikationsbonus_3, tk_qualifikationsbonus_4,
        ln_zulage, social_security_number, phone_number, email_private, 
        email_business, gruppe
    FROM employees
    WHERE employee_id = ?
");
$stmtEmp->bind_param("i", $mitarbeiter_id);
$stmtEmp->execute();
$resEmp = $stmtEmp->get_result();
if ($resEmp->num_rows === 0) {
    echo '<div class="alert alert-danger">Keine Profildaten gefunden.</div>';
    exit;
}
$row = $resEmp->fetch_assoc();
$stmtEmp->close();

// 3) Ausbildungsdaten (employee_education)
$stmtEdu = $conn->prepare("
    SELECT id, education_type, education_field
    FROM employee_education
    WHERE employee_id = ?
");
$stmtEdu->bind_param("i", $mitarbeiter_id);
$stmtEdu->execute();
$resEdu = $stmtEdu->get_result();
$education = [];
while ($rEdu = $resEdu->fetch_assoc()) {
    $education[] = $rEdu;
}
$stmtEdu->close();

// 4) Weiterbildungsdaten mit neuer Tabellenstruktur
$stmtTrain = $conn->prepare("
    SELECT 
        et.training_id,
        t.training_name,
        t.start_date,
        t.end_date,
        t.training_units,
        mc.name AS main_category_name,
        sc.name AS sub_category_name
    FROM employee_training et
    JOIN trainings t ON et.training_id = t.id
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    WHERE et.employee_id = ?
    ORDER BY t.start_date DESC
");

if (!$stmtTrain) {
    // Fallback für den Fall, dass die Abfrage nicht funktioniert
    $training = [];
} else {
    $stmtTrain->bind_param("i", $mitarbeiter_id);
    $stmtTrain->execute();
    $resTrain = $stmtTrain->get_result();
    $training = [];
    while ($rT = $resTrain->fetch_assoc()) {
        $training[] = $rT;
    }
    $stmtTrain->close();
}

// 5) Letzte Mitarbeiterbewertung (employee_reviews)
$stmtRev = $conn->prepare("
    SELECT
        er.id, er.date, er.rueckblick, er.entwicklung, er.feedback,
        er.brandschutzwart, er.sprinklerwart, er.ersthelfer, er.svp,
        er.trainertaetigkeiten, er.trainertaetigkeiten_kommentar,
        er.zufriedenheit, er.unzufriedenheit_grund, er.reviewer_id
    FROM employee_reviews er
    WHERE er.employee_id = ?
    ORDER BY er.date DESC
    LIMIT 1
");
$stmtRev->bind_param("i", $mitarbeiter_id);
$stmtRev->execute();
$resRev = $stmtRev->get_result();

$review_row = null;
$skills = [];
$reviewer_name = 'Unbekannt';

if ($resRev->num_rows > 0) {
    $review_row = $resRev->fetch_assoc();
    $review_id = (int)$review_row['id'];
    $reviewerId = (int)$review_row['reviewer_id'];

    // Reviewer (Name)
    if ($reviewerId > 0) {
        $stmtReviewer = $conn->prepare("SELECT name FROM employees WHERE employee_id = ?");
        $stmtReviewer->bind_param("i", $reviewerId);
        $stmtReviewer->execute();
        $resReviewer = $stmtReviewer->get_result();
        if ($resReviewer->num_rows > 0) {
            $reviewer_name = $resReviewer->fetch_assoc()['name'];
        }
        $stmtReviewer->close();
    }

    // Skills zur letzten Bewertung
    $stmtSkills = $conn->prepare("
        SELECT s.name, s.kategorie, es.rating
        FROM employee_skills es
        JOIN skills s ON es.skill_id = s.id
        WHERE es.review_id = ?
        ORDER BY s.kategorie, s.name
    ");
    $stmtSkills->bind_param("i", $review_id);
    $stmtSkills->execute();
    $resSkills = $stmtSkills->get_result();
    while ($rSkill = $resSkills->fetch_assoc()) {
        $skills[] = $rSkill;
    }
    $stmtSkills->close();
}
$stmtRev->close();

// Zusätzliche Informationen nur anzeigen, wenn mindestens einer der Werte vorhanden ist
$hasSonderfunktionen = $row['ersthelfer'] || $row['svp'] || $row['brandschutzwart'] || $row['sprinklerwart'] || $row['leasing'];
$hasProduktion = $row['lohnschema'] === 'Produktion' &&
    ($row['pr_lehrabschluss'] || $row['pr_anfangslohn'] || $row['pr_grundlohn'] ||
        $row['pr_qualifikationsbonus'] || $row['pr_expertenbonus']);
$hasTechnik = $row['lohnschema'] === 'Technik' &&
    ($row['tk_qualifikationsbonus_1'] || $row['tk_qualifikationsbonus_2'] ||
        $row['tk_qualifikationsbonus_3'] || $row['tk_qualifikationsbonus_4']);
$hasZulage = ($row['lohnschema'] === 'Produktion' || $row['lohnschema'] === 'Technik') &&
    ($row['ln_zulage'] === '5% Zulage' || $row['ln_zulage'] === '10% Zulage');
$hasAdditionalInfo = $hasSonderfunktionen || $hasProduktion || $hasTechnik || $hasZulage;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mein Profil</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Verbesserte Styles für ein kompakteres, ansprechenderes Layout */
        .profile-container {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        /* Kompakteres, ansprechenderes Layout für die Formularfelder */
        .field-group {
            display: flex;
            margin-bottom: 0.5rem;
            align-items: center;
            border-bottom: 1px solid #f0f0f0;
            padding: 0.5rem 0;
        }

        .field-group:last-child {
            border-bottom: none;
        }

        .field-label {
            width: 30%;
            font-weight: 500;
            color: #555;
            padding-right: 1rem;
        }

        .field-value {
            width: 70%;
            font-weight: normal;
        }

        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: contain; /* Zeigt das gesamte Bild ohne Abschneiden */
            border-radius: 8px;
        }

        /* No Image Platzhalter */
        .no-image {
            width: 100%;
            height: 350px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 8px;
        }

        /* Optionale Verbesserungen für die Kartenansicht */
        .info-card {
            height: 100%;
            transition: all 0.2s ease;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        }

        .section-heading {
            border-bottom: 2px solid var(--ball-blue);
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
            color: var(--ball-blue);
        }

        /* Verbessert die Abstände in den Karten */
        .card-body {
            padding: 1.25rem;
        }

        /* Für den responsiven Infobereich */
        .info-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        /* Listenelemente mit Icons */
        .icon-list li {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .icon-list li i {
            margin-right: 0.5rem;
            flex-shrink: 0;
        }

        /* Responsive Anpassungen */
        @media (max-width: 768px) {
            .field-group {
                flex-direction: column;
                align-items: flex-start;
            }

            .field-label, .field-value {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container content">
    <h1 class="text-center mb-4">Mein Profil</h1>
    <hr class="mb-4">

    <!-- Hauptbereich -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row">
                <!-- Profilbild (links) -->
                <div class="col-md-3 mb-3">
                    <?php if (!empty($row['bild'])): ?>
                        <div>
                            <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($row['bild']); ?>"
                                 alt="Mitarbeiterfoto" class="profile-image">
                        </div>
                    <?php else: ?>
                        <div class="no-image alert alert-warning">
                            <div class="text-center">
                                <i class="bi bi-person-x fs-1"></i>
                                <p class="mt-2 mb-0">Kein Bild vorhanden</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Persönliche Daten (rechts vom Bild) -->
                <div class="col-md-9">
                    <h5 class="section-heading">Persönliche Daten</h5>

                    <div class="row">
                        <div class="col-md-6">
                            <!-- Erste Spalte mit Informationen -->
                            <div class="field-group">
                                <div class="field-label">Name:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['name']) ? htmlspecialchars($row['name']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Geburtsdatum:</div>
                                <div class="field-value">
                                    <?php
                                    if (!empty($row['birthdate']) && $row['birthdate'] != '0000-00-00' && $row['birthdate'] != '1970-01-01') {
                                        echo date("d.m.Y", strtotime($row['birthdate']));
                                    } else {
                                        echo '<span class="text-muted">Nicht angegeben</span>';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Geschlecht:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['gender']) ? htmlspecialchars($row['gender']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">SV-Nummer:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['social_security_number']) && $row['social_security_number'] != '0' ?
                                        htmlspecialchars($row['social_security_number']) :
                                        '<span class="text-muted">Nicht angegeben</span>';
                                    ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Telefon:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['phone_number']) ? htmlspecialchars($row['phone_number']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <!-- Zweite Spalte mit Informationen -->
                            <div class="field-group">
                                <div class="field-label">Position:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['position']) ? htmlspecialchars($row['position']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Gruppe:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['gruppe']) ? htmlspecialchars($row['gruppe']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Team:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['crew']) ? htmlspecialchars($row['crew']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Lohnschema:</div>
                                <div class="field-value">
                                    <?php echo !empty($row['lohnschema']) ? htmlspecialchars($row['lohnschema']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Eintritt:</div>
                                <div class="field-value">
                                    <?php
                                    if (!empty($row['entry_date']) && $row['entry_date'] != '0000-00-00' && $row['entry_date'] != '1970-01-01') {
                                        echo date("d.m.Y", strtotime($row['entry_date']));
                                    } else {
                                        echo '<span class="text-muted">Nicht angegeben</span>';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="field-group">
                                <div class="field-label">Ersteintritt:</div>
                                <div class="field-value">
                                    <?php
                                    if (!empty($row['first_entry_date']) && $row['first_entry_date'] != '0000-00-00' && $row['first_entry_date'] != '1970-01-01') {
                                        echo date("d.m.Y", strtotime($row['first_entry_date']));
                                    } else {
                                        echo '<span class="text-muted">Nicht angegeben</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- E-Mail-Adressen in eigener Zeile -->
                    <div class="row mt-3">
                        <div class="col-12">
                            <h5 class="section-heading">Kontakt</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="field-group">
                                        <div class="field-label">E-Mail (privat):</div>
                                        <div class="field-value">
                                            <?php echo !empty($row['email_private']) ? htmlspecialchars($row['email_private']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="field-group">
                                        <div class="field-label">E-Mail (geschäftlich):</div>
                                        <div class="field-value">
                                            <?php echo !empty($row['email_business']) ? htmlspecialchars($row['email_business']) : '<span class="text-muted">Nicht angegeben</span>'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Zusätzliche Informationen nur anzeigen, wenn etwas vorhanden ist -->
            <?php if ($hasAdditionalInfo): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <h5 class="section-heading">Zusätzliche Informationen</h5>
                        <div class="info-section">
                            <!-- Sonderfunktionen -->
                            <?php if ($hasSonderfunktionen): ?>
                                <div class="info-card p-3">
                                    <h6 class="mb-2">Sonderfunktionen</h6>
                                    <ul class="list-unstyled mb-0 icon-list">
                                        <?php if ($row['ersthelfer']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Ersthelfer</li>
                                        <?php endif; ?>
                                        <?php if ($row['svp']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> SVP</li>
                                        <?php endif; ?>
                                        <?php if ($row['brandschutzwart']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Brandschutzwart
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($row['sprinklerwart']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Sprinklerwart</li>
                                        <?php endif; ?>
                                        <?php if ($row['leasing']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Leasing</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <!-- Lohnschema Produktion (falls zutreffend) -->
                            <?php if ($hasProduktion): ?>
                                <div class="info-card p-3">
                                    <h6 class="mb-2">Lohnschema Produktion</h6>
                                    <ul class="list-unstyled mb-0 icon-list">
                                        <?php if ($row['pr_lehrabschluss']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Lehrabschluss</li>
                                        <?php endif; ?>
                                        <?php if ($row['pr_anfangslohn']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Anfangslohn</li>
                                        <?php endif; ?>
                                        <?php if ($row['pr_grundlohn']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Grundlohn</li>
                                        <?php endif; ?>
                                        <?php if ($row['pr_qualifikationsbonus']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Qualifikationsbonus
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($row['pr_expertenbonus']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Expertenbonus</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <!-- Lohnschema Technik (falls zutreffend) -->
                            <?php if ($hasTechnik): ?>
                                <div class="info-card p-3">
                                    <h6 class="mb-2">Lohnschema Technik</h6>
                                    <ul class="list-unstyled mb-0 icon-list">
                                        <?php if ($row['tk_qualifikationsbonus_1']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Qualifikationsbonus
                                                1
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($row['tk_qualifikationsbonus_2']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Qualifikationsbonus
                                                2
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($row['tk_qualifikationsbonus_3']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Qualifikationsbonus
                                                3
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($row['tk_qualifikationsbonus_4']): ?>
                                            <li><i class="bi bi-check-circle-fill text-success"></i> Qualifikationsbonus
                                                4
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <!-- Zulage (falls zutreffend) -->
                            <?php if ($hasZulage): ?>
                                <div class="info-card p-3">
                                    <h6 class="mb-2">Zulage</h6>
                                    <p class="mb-0"><i
                                                class="bi bi-percent text-primary me-2"></i><?php echo htmlspecialchars($row['ln_zulage']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ausbildung -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="card-title mb-0">
                <i class="bi bi-mortarboard me-2"></i>Ausbildung
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($education)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                        <tr>
                            <th>Art der Ausbildung</th>
                            <th>Ausbildung</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($education as $edu): ?>
                            <tr>
                                <td class="fw-medium"><?php echo htmlspecialchars($edu['education_type']); ?></td>
                                <td><?php echo htmlspecialchars($edu['education_field']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info">Keine Ausbildungsdaten vorhanden.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Weiterbildung -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="card-title mb-0">
                <i class="bi bi-journal-text me-2"></i>Weiterbildung
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($training)): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Kategorie</th>
                            <th>Name</th>
                            <th>Datum</th>
                            <th>Einheiten</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($training as $tr): ?>
                            <tr>
                                <td class="text-center"><?php echo (int)$tr['training_id']; ?></td>
                                <td>
                                    <span class="fw-medium"><?php echo htmlspecialchars($tr['main_category_name']); ?></span>
                                    <?php if (!empty($tr['sub_category_name'])): ?>
                                        <div class="text-muted small"><?php echo htmlspecialchars($tr['sub_category_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($tr['training_name']); ?></td>
                                <td>
                                    <?php
                                    echo date("d.m.Y", strtotime($tr['start_date']));
                                    if ($tr['start_date'] != $tr['end_date']) {
                                        echo ' - ' . date("d.m.Y", strtotime($tr['end_date']));
                                    }
                                    ?>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars($tr['training_units']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info">Keine Weiterbildungsdaten vorhanden.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Letztes Mitarbeitergespräch -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <h5 class="card-title mb-0">
                <i class="bi bi-chat-dots me-2"></i>
                Letztes Mitarbeitergespräch
                <?php if ($review_row): ?>
                    <span class="badge bg-secondary ms-2"><?php echo date("d.m.Y", strtotime($review_row['date'])); ?></span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if ($review_row): ?>
                <p class="mb-3"><strong>Durchgeführt von:</strong> <?php echo htmlspecialchars($reviewer_name); ?></p>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6 class="mb-2">Rückblick:</h6>
                            <p class="border-start border-primary ps-3"><?php echo nl2br(htmlspecialchars($review_row['rueckblick'])); ?></p>
                        </div>

                        <div class="mb-3">
                            <h6 class="mb-2">Entwicklung:</h6>
                            <p class="border-start border-primary ps-3"><?php echo nl2br(htmlspecialchars($review_row['entwicklung'])); ?></p>
                        </div>

                        <div class="mb-3">
                            <h6 class="mb-2">Feedback:</h6>
                            <p class="border-start border-primary ps-3"><?php echo nl2br(htmlspecialchars($review_row['feedback'])); ?></p>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h6 class="card-title mb-3">Sonderfunktionen</h6>

                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <i class="bi <?php echo $review_row['brandschutzwart'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?> me-2"></i>
                                            Brandschutzwart
                                        </p>
                                        <p class="mb-2">
                                            <i class="bi <?php echo $review_row['sprinklerwart'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?> me-2"></i>
                                            Sprinklerwart
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <i class="bi <?php echo $review_row['ersthelfer'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?> me-2"></i>
                                            Ersthelfer
                                        </p>
                                        <p class="mb-2">
                                            <i class="bi <?php echo $review_row['svp'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'; ?> me-2"></i>
                                            SVP
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <h6 class="mb-2">Trainertätigkeiten:</h6>
                            <p>
                                <span class="badge <?php echo $review_row['trainertaetigkeiten'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $review_row['trainertaetigkeiten'] ? 'Ja' : 'Nein'; ?>
                                </span>
                            </p>
                            <?php if ($review_row['trainertaetigkeiten_kommentar']): ?>
                                <p class="border-start border-primary ps-3 mt-2">
                                    <?php echo nl2br(htmlspecialchars($review_row['trainertaetigkeiten_kommentar'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <h6 class="mb-2">Zufriedenheit:</h6>
                            <p>
                                <span class="badge <?php echo $review_row['zufriedenheit'] === 'Zufrieden' ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo htmlspecialchars($review_row['zufriedenheit']); ?>
                                </span>
                            </p>
                            <?php if ($review_row['zufriedenheit'] === 'Unzufrieden' && $review_row['unzufriedenheit_grund']): ?>
                                <p class="border-start border-danger ps-3 mt-2">
                                    <?php echo nl2br(htmlspecialchars($review_row['unzufriedenheit_grund'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Skills -->
                <h5 class="mb-4">Kompetenzen und Fähigkeiten</h5>
                <div class="row">
                    <?php
                    $skill_categories = [
                        'Anwenderkenntnisse' => [],
                        'Positionsspezifische Kompetenzen' => [],
                        'Führungskompetenzen' => [],
                        'Persönliche Kompetenzen' => []
                    ];

                    foreach ($skills as $skill) {
                        if (isset($skill_categories[$skill['kategorie']])) {
                            $skill_categories[$skill['kategorie']][] = $skill;
                        }
                    }

                    foreach ($skill_categories as $category => $category_skills):
                        if (!empty($category_skills)):
                            ?>
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><?php echo $category; ?></h6>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($category_skills as $skill): ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <?php echo htmlspecialchars($skill['name']); ?>
                                                    <span class="badge bg-primary rounded-pill"><?php echo (int)$skill['rating']; ?>/9</span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info">Keine Mitarbeitergesprächsdaten vorhanden.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>
