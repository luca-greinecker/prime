<?php
/**
 * archived_employee_details.php
 *
 * Zeigt vereinfachte Informationen zu einem archivierten Mitarbeiter an.
 * Enthält die Möglichkeit, einen Mitarbeiter wieder eintreten zu lassen.
 * Nur für HR und Admin zugänglich.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Nur HR und Admin dürfen diese Seite sehen
if (!ist_hr() && !ist_admin()) {
    header("Location: access_denied.php");
    exit;
}

// Prüfen, ob Ergebnismeldungen in der Session vorliegen
$has_result_message = isset($_SESSION['result_message']) && !empty($_SESSION['result_message']);
$result_message = $has_result_message ? $_SESSION['result_message'] : '';
$result_type = isset($_SESSION['result_type']) ? $_SESSION['result_type'] : 'success';

// Session-Meldungen nach dem Abrufen zurücksetzen
if ($has_result_message) {
    unset($_SESSION['result_message']);
    unset($_SESSION['result_type']);
}

// Prüfen, ob eine Mitarbeiter-ID übergeben wurde
if (isset($_GET['employee_id']) && is_numeric($_GET['employee_id'])) {
    $id = (int)$_GET['employee_id'];

    // Prüfen, ob der Mitarbeiter existiert und archiviert ist
    $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ? AND status = 9999");
    if (!$stmt) {
        die("Fehler bei der Vorbereitung des Statements: " . $conn->error);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
    } else {
        $_SESSION['result_message'] = "Mitarbeiter nicht gefunden oder nicht archiviert.";
        $_SESSION['result_type'] = "danger";
        header("Location: ehemalige_mitarbeiter.php");
        exit;
    }
    $stmt->close();
} else {
    $_SESSION['result_message'] = "Ungültige Anfrage";
    $_SESSION['result_type'] = "danger";
    header("Location: ehemalige_mitarbeiter.php");
    exit;
}

// Ausbildungsdaten abrufen
$education_stmt = $conn->prepare("SELECT id, education_type, education_field FROM employee_education WHERE employee_id = ?");
$education_stmt->bind_param("i", $id);
$education_stmt->execute();
$education_result = $education_stmt->get_result();
$education = [];
while ($education_row = $education_result->fetch_assoc()) {
    $education[] = $education_row;
}
$education_stmt->close();

// Weiterbildungsdaten abrufen
$training_stmt = $conn->prepare("
    SELECT 
        t.id AS training_id, 
        t.display_id,
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
$training_stmt->bind_param("i", $id);
$training_stmt->execute();
$training_result = $training_stmt->get_result();
$training = [];
while ($training_row = $training_result->fetch_assoc()) {
    $training[] = $training_row;
}
$training_stmt->close();

// Letzte Bewertung und zugehörige Skills des Mitarbeiters abrufen
$review_stmt = $conn->prepare("
    SELECT employee_reviews.employee_id, employee_reviews.date, employee_reviews.rueckblick, employee_reviews.entwicklung,
           employee_reviews.feedback, employee_reviews.brandschutzwart, employee_reviews.sprinklerwart, 
           employee_reviews.ersthelfer, employee_reviews.svp, employee_reviews.trainertaetigkeiten, 
           employee_reviews.trainertaetigkeiten_kommentar, employee_reviews.zufriedenheit, 
           employee_reviews.unzufriedenheit_grund, employee_reviews.reviewer_id, employee_reviews.id AS review_id
    FROM employee_reviews
    WHERE employee_id = ?
    ORDER BY date DESC
    LIMIT 1
");
$review_stmt->bind_param("i", $id);
$review_stmt->execute();
$review_result = $review_stmt->get_result();

$review_row = null;
$skills = [];
$reviewer_name = 'Unbekannt';  // Standardwert
if ($review_result->num_rows > 0) {
    $review_row = $review_result->fetch_assoc();
    $review_id = $review_row['review_id'];

    // Reviewer-Name abrufen anhand des internen Schlüssels
    if (!empty($review_row['reviewer_id'])) {
        $reviewer_stmt = $conn->prepare("SELECT name FROM employees WHERE employee_id = ?");
        $reviewer_stmt->bind_param("i", $review_row['reviewer_id']);
        $reviewer_stmt->execute();
        $reviewer_result = $reviewer_stmt->get_result();
        if ($reviewer_result->num_rows > 0) {
            $reviewer_name = $reviewer_result->fetch_assoc()['name'];
        }
        $reviewer_stmt->close();
    }

    // Skills zur letzten Bewertung abrufen
    $skills_stmt = $conn->prepare("
        SELECT s.name, s.kategorie, employee_skills.rating 
        FROM employee_skills 
        JOIN skills s ON employee_skills.skill_id = s.id 
        WHERE employee_skills.review_id = ?
    ");
    $skills_stmt->bind_param("i", $review_id);
    $skills_stmt->execute();
    $skills_result = $skills_stmt->get_result();
    while ($skill_row = $skills_result->fetch_assoc()) {
        $skills[] = $skill_row;
    }
    $skills_stmt->close();
}
$review_stmt->close();

// Bestimme den Referer für "Zurück"-Links
$previous_page = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'ehemalige_mitarbeiter.php';
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archivierter Mitarbeiter: <?php echo htmlspecialchars($row['name']); ?></title>
    <!-- Cache-Steuerung -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate"/>
    <meta http-equiv="Pragma" content="no-cache"/>
    <meta http-equiv="Expires" content="0"/>
    <!-- Bootstrap CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .badge-archived {
            background-color: #dc3545;
            color: white;
            padding: 0.35rem 0.6rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .employee-image {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid var(--ball-blue-light);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .no-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background-color: #f8f9fa;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 4px solid var(--ball-blue-light);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .employee-image-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container">
    <div class="page-header d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-archive me-2"></i>Archivierter Mitarbeiter</h1>
        <a href="<?php echo htmlspecialchars($previous_page); ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurück
        </a>
    </div>

    <?php if (!empty($result_message)): ?>
        <div class="alert alert-<?php echo $result_type; ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-info-circle me-2"></i><?php echo $result_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Hauptinformationen -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Persönliche Informationen</h5>
                <span class="badge-archived">
                    <i class="bi bi-archive-fill me-1"></i> Archiviert
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 text-center">
                    <div class="employee-image-container">
                        <?php if (!empty($row['bild']) && $row['bild'] !== 'kein-bild.jpg'): ?>
                            <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($row['bild']); ?>"
                                 alt="Mitarbeiterfoto" class="employee-image mb-3">
                        <?php else: ?>
                            <div class="no-image mb-3">
                                <i class="bi bi-person-fill display-4"></i>
                                <p class="mb-0 mt-2">Kein Bild</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-9">
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h3><?php echo htmlspecialchars($row['name']); ?></h3>
                            <p class="text-muted"><?php echo htmlspecialchars($row['position']); ?></p>

                            <div class="mb-3">
                                <strong>Geburtsdatum:</strong>
                                <?php echo !empty($row['birthdate']) ? date('d.m.Y', strtotime($row['birthdate'])) : '-'; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="alert alert-danger">
                                <div class="mb-2"><strong>Austrittsdatum:</strong>
                                    <?php echo !empty($row['leave_date']) ? date('d.m.Y', strtotime($row['leave_date'])) : 'Nicht angegeben'; ?>
                                </div>
                                <div><strong>Austrittsgrund:</strong>
                                    <?php echo !empty($row['leave_reason']) ? htmlspecialchars($row['leave_reason']) : 'Nicht angegeben'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <strong>Eintritt:</strong>
                                <?php echo !empty($row['entry_date']) ? date('d.m.Y', strtotime($row['entry_date'])) : '-'; ?>
                            </div>

                            <?php if (!empty($row['first_entry_date']) && $row['first_entry_date'] != $row['entry_date']): ?>
                                <div class="mb-3">
                                    <strong>Ersteintritt:</strong>
                                    <?php echo date('d.m.Y', strtotime($row['first_entry_date'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6 text-md-end">
                            <button type="button" class="btn btn-success" data-bs-toggle="modal"
                                    data-bs-target="#rehireModal">
                                <i class="bi bi-person-plus-fill me-1"></i> Mitarbeiter wieder einstellen
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ausbildung -->
    <div class="card mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Ausbildung</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th>Art der Ausbildung</th>
                        <th>Ausbildung</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($education)): ?>
                        <tr>
                            <td colspan="2" class="text-center">
                                Keine Ausbildungsdaten vorhanden
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($education as $edu): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($edu['education_type']); ?></td>
                                <td><?php echo htmlspecialchars($edu['education_field']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Weiterbildung -->
    <div class="card mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-book me-2"></i>Weiterbildung</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hauptkategorie</th>
                        <th>Unterkategorie</th>
                        <th>Name der Weiterbildung</th>
                        <th>Zeitraum</th>
                        <th>Einheiten</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($training)): ?>
                        <tr>
                            <td colspan="6" class="text-center">
                                Keine Weiterbildungen vorhanden
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($training as $train): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($train['display_id'] ?? $train['training_id']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($train['main_category_name']); ?></td>
                                <td><?php echo htmlspecialchars($train['sub_category_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($train['training_name']); ?></td>
                                <td>
                                    <?php
                                    $training_date = htmlspecialchars(date('d.m.Y', strtotime($train['start_date'])));
                                    if ($train['start_date'] != $train['end_date']) {
                                        $training_date .= ' - ' . htmlspecialchars(date('d.m.Y', strtotime($train['end_date'])));
                                    }
                                    echo $training_date;
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($train['training_units']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Mitarbeitergespräch -->
    <?php if ($review_row): ?>
        <div class="card mt-4">
            <div class="card-header bg-light">
                <h5 class="mb-0">
                    <i class="bi bi-chat-text me-2"></i>
                    Letztes Mitarbeitergespräch am <?php echo date("d.m.Y", strtotime($review_row['date'])); ?>
                </h5>
            </div>
            <div class="card-body">
                <p class="mb-3">
                    <span class="badge bg-info">Durchgeführt von: <?php echo htmlspecialchars($reviewer_name); ?></span>
                </p>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-light">Rückmeldungen</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <h6>Rückblick:</h6>
                                    <p><?php echo htmlspecialchars($review_row['rueckblick']); ?></p>
                                </div>
                                <div class="mb-3">
                                    <h6>Entwicklung:</h6>
                                    <p><?php echo htmlspecialchars($review_row['entwicklung']); ?></p>
                                </div>
                                <div>
                                    <h6>Feedback:</h6>
                                    <p><?php echo htmlspecialchars($review_row['feedback']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-light">Status und Zufriedenheit</div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <p><strong>Brandschutzwart:</strong>
                                            <span class="badge <?php echo $review_row['brandschutzwart'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $review_row['brandschutzwart'] ? 'Ja' : 'Nein'; ?>
                                            </span>
                                        </p>
                                        <p><strong>Sprinklerwart:</strong>
                                            <span class="badge <?php echo $review_row['sprinklerwart'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $review_row['sprinklerwart'] ? 'Ja' : 'Nein'; ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Ersthelfer:</strong>
                                            <span class="badge <?php echo $review_row['ersthelfer'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $review_row['ersthelfer'] ? 'Ja' : 'Nein'; ?>
                                            </span>
                                        </p>
                                        <p><strong>Sicherheitsvertrauensperson:</strong>
                                            <span class="badge <?php echo $review_row['svp'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $review_row['svp'] ? 'Ja' : 'Nein'; ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <p><strong>Trainertätigkeiten:</strong>
                                        <span class="badge <?php echo $review_row['trainertaetigkeiten'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $review_row['trainertaetigkeiten'] ? 'Ja' : 'Nein'; ?>
                                        </span>
                                    </p>
                                    <?php if ($review_row['trainertaetigkeiten_kommentar']): ?>
                                        <div class="alert alert-info p-2">
                                            <small><strong>Kommentar zu Trainertätigkeiten:</strong></small><br>
                                            <?php echo htmlspecialchars($review_row['trainertaetigkeiten_kommentar']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <p><strong>Zufriedenheit:</strong>
                                        <span class="badge <?php echo $review_row['zufriedenheit'] == 'Zufrieden' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                            <?php echo htmlspecialchars($review_row['zufriedenheit']); ?>
                                        </span>
                                    </p>
                                    <?php if ($review_row['zufriedenheit'] == 'Unzufrieden' && $review_row['unzufriedenheit_grund']): ?>
                                        <div class="alert alert-warning p-2">
                                            <small><strong>Gründe für Unzufriedenheit:</strong></small><br>
                                            <?php echo htmlspecialchars($review_row['unzufriedenheit_grund']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($skills)): ?>
                    <div class="row mt-4">
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header bg-light">Anwenderkenntnisse</div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <?php
                                        $shown = false;
                                        foreach ($skills as $skill):
                                            if ($skill['kategorie'] == 'Anwenderkenntnisse'):
                                                $shown = true;
                                                ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <?php echo htmlspecialchars($skill['name']); ?>
                                                    <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                </li>
                                            <?php
                                            endif;
                                        endforeach;
                                        if (!$shown):
                                            ?>
                                            <li class="list-group-item text-center text-muted">Keine Daten vorhanden
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header bg-light">Positionsspezifische Kompetenzen</div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <?php
                                        $shown = false;
                                        foreach ($skills as $skill):
                                            if ($skill['kategorie'] == 'Positionsspezifische Kompetenzen'):
                                                $shown = true;
                                                ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <?php echo htmlspecialchars($skill['name']); ?>
                                                    <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                </li>
                                            <?php
                                            endif;
                                        endforeach;
                                        if (!$shown):
                                            ?>
                                            <li class="list-group-item text-center text-muted">Keine Daten vorhanden
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header bg-light">Führungs- und Persönliche Kompetenzen</div>
                                <div class="card-body">
                                    <ul class="list-group list-group-flush">
                                        <?php
                                        $shown = false;
                                        foreach ($skills as $skill):
                                            if ($skill['kategorie'] == 'Führungskompetenzen' || $skill['kategorie'] == 'Persönliche Kompetenzen'):
                                                $shown = true;
                                                ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <?php echo htmlspecialchars($skill['name']); ?>
                                                    <span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                </li>
                                            <?php
                                            endif;
                                        endforeach;
                                        if (!$shown):
                                            ?>
                                            <li class="list-group-item text-center text-muted">Keine Daten vorhanden
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info mt-4">
            <i class="bi bi-info-circle me-2"></i>
            Keine Mitarbeitergespräche vorhanden.
        </div>
    <?php endif; ?>
</div>

<!-- Wieder-Einstellen Modal -->
<div class="modal fade" id="rehireModal" tabindex="-1" aria-labelledby="rehireModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="rehireModalLabel">
                    <i class="bi bi-person-plus-fill me-2"></i>Mitarbeiter wieder einstellen
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>
            <form action="rehire_employee.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($id); ?>">

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Information:</strong> Sie sind dabei, <?php echo htmlspecialchars($row['name']); ?>
                        wieder einzustellen.
                        <p class="mb-0 mt-2">
                            Der Mitarbeiterstatus wird auf "Aktiv" gesetzt und die Austrittsinformationen werden
                            gelöscht. <strong>Bitte weisen Sie dem Mitarbeiter eine Ausweisnummer zu.</strong>
                        </p>
                    </div>

                    <!-- Fehlermeldung im Modal anzeigen, wenn vorhanden -->
                    <?php if (isset($_SESSION['rehire_data']['error'])): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['rehire_data']['error']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="new_entry_date" class="form-label">Neues Eintrittsdatum:</label>
                        <input type="date" class="form-control" id="new_entry_date" name="new_entry_date"
                               value="<?php echo isset($_SESSION['rehire_data']['new_entry_date']) ?
                                   htmlspecialchars($_SESSION['rehire_data']['new_entry_date']) : date('Y-m-d'); ?>"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="badge_id" class="form-label text-danger">Ausweisnummer: *</label>
                        <input type="number" class="form-control" id="badge_id" name="badge_id"
                               value="<?php echo isset($_SESSION['rehire_data']['badge_id']) ?
                                   htmlspecialchars($_SESSION['rehire_data']['badge_id']) : ''; ?>"
                               min="1" required>
                        <div class="form-text">Bitte weisen Sie dem Mitarbeiter eine eindeutige Ausweisnummer zu.</div>
                    </div>

                    <div class="mb-3">
                        <label for="username" class="form-label text-danger">Benutzername: *</label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?php echo isset($_SESSION['rehire_data']['username']) ?
                                   htmlspecialchars($_SESSION['rehire_data']['username']) : ''; ?>"
                               required>
                        <div class="form-text">Das Passwort wird auf 'Ball1234' gesetzt. Bitte informieren Sie den
                            Mitarbeiter.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-person-plus-fill me-1"></i> Mitarbeiter wieder einstellen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript um Modal automatisch zu öffnen wenn Fehler aufgetreten ist -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if (isset($_SESSION['rehire_data']['error'])): ?>
        // Modal automatisch öffnen, wenn es einen Fehler gab
        var rehireModal = new bootstrap.Modal(document.getElementById('rehireModal'));
        rehireModal.show();
        <?php endif; ?>
    });
</script>

<!-- JavaScript -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Am Ende der Datei nach dem Modal und JavaScript

// Session-Daten für das Rehire-Modal löschen, nachdem sie verwendet wurden
if (isset($_SESSION['rehire_data'])) {
    unset($_SESSION['rehire_data']);
}
?>