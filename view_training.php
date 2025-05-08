<?php
/**
 * view_training.php
 *
 * Zeigt Detailinformationen zu einem Training an, ohne Bearbeitungsmöglichkeiten.
 * Ermöglicht das Betrachten der Trainingsdaten und Teilnehmerliste.
 */

include 'access_control.php';
include 'training_functions.php';
global $conn;
pruefe_benutzer_eingeloggt();

// GET-Parameter: id des Trainings
$id = $_GET['id'] ?? null;
if (!$id) {
    echo '<p>Keine Trainings-ID übergeben.</p>';
    exit;
}

// Trainingsinformationen abrufen
$stmt = $conn->prepare("
    SELECT 
        t.display_id, 
        t.main_category_id, 
        t.sub_category_id, 
        t.training_name, 
        t.start_date, 
        t.end_date, 
        t.training_units,
        t.created_at,
        mc.name AS main_category_name,
        sc.name AS sub_category_name,
        e.name AS created_by_name,
        e.employee_id AS created_by_id 
    FROM trainings t
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    LEFT JOIN employees e ON t.created_by = e.employee_id
    WHERE t.id = ?
");

if (!$stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<p>Keine Daten gefunden.</p>';
    exit;
}
$training = $result->fetch_assoc();
$stmt->close();

// Teilnehmer abrufen
$participants_stmt = $conn->prepare("
    SELECT 
        e.employee_id,
        e.name,
        e.gruppe,
        e.crew,
        e.position,
        e.bild
    FROM employee_training et
    JOIN employees e ON et.employee_id = e.employee_id
    WHERE et.training_id = ?
    ORDER BY e.name ASC
");

if (!$participants_stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$participants_stmt->bind_param("i", $id);
$participants_stmt->execute();
$participants_result = $participants_stmt->get_result();
$participants = $participants_result->fetch_all(MYSQLI_ASSOC);
$participants_stmt->close();

// Trainer abrufen (für Technical Trainings - Kategorie 03)
$trainers = [];
if ($training['main_category_id'] == 3) { // Für Technical Trainings
    $trainers_stmt = $conn->prepare("
        SELECT 
            e.employee_id,
            e.name,
            e.gruppe,
            e.crew,
            e.position,
            e.bild
        FROM training_trainers tt
        JOIN employees e ON tt.trainer_id = e.employee_id
        WHERE tt.training_id = ?
        ORDER BY e.name ASC
    ");

    if (!$trainers_stmt) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }

    $trainers_stmt->bind_param("i", $id);
    $trainers_stmt->execute();
    $trainers_result = $trainers_stmt->get_result();
    $trainers = $trainers_result->fetch_all(MYSQLI_ASSOC);
    $trainers_stmt->close();
}

// Berechne die Dauer in Tagen
$startDate = new DateTime($training['start_date']);
$endDate = new DateTime($training['end_date']);
$duration = $startDate->diff($endDate)->days + 1; // +1 weil inklusiv (erster und letzter Tag zählen)

// Gruppiere Teilnehmer nach Gruppe/Abteilung
$grouped_participants = [];
foreach ($participants as $participant) {
    $group = $participant['gruppe'];
    if (!isset($grouped_participants[$group])) {
        $grouped_participants[$group] = [];
    }
    $grouped_participants[$group][] = $participant;
}

// Prüfe, ob der Benutzer Trainings bearbeiten darf
$can_edit = ist_admin() || ist_hr() || ist_trainingsmanager() || ist_ehs();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weiterbildung: <?php echo htmlspecialchars($training['training_name']); ?></title>
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border-radius: 0.5rem;
            overflow: hidden;
        }

        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }

        .badge-id {
            font-size: 1rem;
            padding: 0.3em 0.7em;
            background-color: #6c757d;
            color: white;
            border-radius: 0.25rem;
        }

        .training-detail-row {
            display: flex;
            margin-bottom: 1rem;
            align-items: flex-start;
        }

        .detail-label {
            font-weight: 500;
            min-width: 180px;
            color: #495057;
        }

        .detail-value {
            flex: 1;
        }

        .category-badge {
            display: inline-block;
            padding: 0.35em 0.65em;
            font-size: 0.85em;
            font-weight: 500;
            color: white;
            background-color: #0d6efd;
            border-radius: 0.375rem;
            margin-right: 0.5rem;
        }

        .participant-card {
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
            padding: 0.5rem 0.75rem;
        }

        .participant-card:hover {
            transform: translateX(5px);
            border-left-color: #0d6efd;
            background-color: #f8f9fa;
        }

        .participant-badge {
            border-radius: 20px;
            padding: 0.3em 0.6em;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .meta-info {
            font-size: 0.85rem;
            color: #6c757d;
            border-top: 1px solid #dee2e6;
            padding-top: 1rem;
            margin-top: 1rem;
        }

        .mitarbeiter-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-size: 150%;
            background-position: center 20%;
            flex-shrink: 0;
            border: 2px solid #0d6efd;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .mitarbeiter-placeholder {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            flex-shrink: 0;
        }

        /* Trainer Styles */
        .trainer-card {
            border-left: 4px solid #ffc107; /* gelb für Trainer */
        }

        .trainer-badge {
            background-color: #ffc107;
            color: #343a40;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">Weiterbildung - Details</h1>
        <div>
            <a href="training_overview.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Zurück
            </a>
            <?php if ($can_edit): ?>
                <a href="edit_training.php?id=<?php echo htmlspecialchars($id); ?>" class="btn btn-primary">
                    <i class="bi bi-pencil-square"></i> Bearbeiten
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?php echo htmlspecialchars($training['training_name']); ?></h5>
            <span class="badge-id"><?php echo htmlspecialchars($training['display_id']); ?></span>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="training-detail-row">
                        <div class="detail-label">
                            <i class="bi bi-tag me-2"></i> Kategorien:
                        </div>
                        <div class="detail-value">
                            <span class="category-badge"><?php echo htmlspecialchars($training['main_category_name']); ?></span>
                            <?php if ($training['sub_category_name']): ?>
                                <span class="category-badge bg-opacity-75"><?php echo htmlspecialchars($training['sub_category_name']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="training-detail-row">
                        <div class="detail-label">
                            <i class="bi bi-calendar-event me-2"></i> Zeitraum:
                        </div>
                        <div class="detail-value">
                            <?php
                            echo date('d.m.Y', strtotime($training['start_date']));
                            if ($training['start_date'] != $training['end_date']) {
                                echo ' bis ' . date('d.m.Y', strtotime($training['end_date']));
                                echo ' (' . $duration . ' Tage)';
                            } else {
                                echo ' (1 Tag)';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="training-detail-row">
                        <div class="detail-label">
                            <i class="bi bi-clock me-2"></i> Einheiten:
                        </div>
                        <div class="detail-value">
                            <?php echo htmlspecialchars($training['training_units']); ?> Einheiten
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="training-detail-row">
                        <div class="detail-label">
                            <i class="bi bi-person me-2"></i> Erstellt von:
                        </div>
                        <div class="detail-value">
                            <a href="employee_details.php?employee_id=<?php echo $training['created_by_id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($training['created_by_name']); ?>
                            </a>
                        </div>
                    </div>

                    <div class="training-detail-row">
                        <div class="detail-label">
                            <i class="bi bi-clock-history me-2"></i> Erstellt am:
                        </div>
                        <div class="detail-value">
                            <?php echo date('d.m.Y H:i', strtotime($training['created_at'])); ?> Uhr
                        </div>
                    </div>

                    <div class="training-detail-row">
                        <div class="detail-label">
                            <i class="bi bi-people-fill me-2"></i> Teilnehmer:
                        </div>
                        <div class="detail-value">
                            <?php echo count($participants); ?> Personen
                        </div>
                    </div>

                    <?php if ($training['main_category_id'] == 3 && !empty($trainers)): ?>
                        <div class="training-detail-row">
                            <div class="detail-label">
                                <i class="bi bi-person-check-fill me-2"></i> Trainer:
                            </div>
                            <div class="detail-value">
                                <?php echo count($trainers); ?> Trainer
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($can_edit): ?>
                <div class="meta-info">
                    <div><strong>Interne ID:</strong> <?php echo htmlspecialchars($id); ?></div>
                    <div>
                        <strong>Kategorie-IDs:</strong>
                        Hauptkategorie: <?php echo htmlspecialchars($training['main_category_id']); ?>,
                        Unterkategorie: <?php echo htmlspecialchars($training['sub_category_id']); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($training['main_category_id'] == 3 && !empty($trainers)): ?>
        <!-- Trainer-Liste für Technical Trainings -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-person-check-fill me-2"></i> Trainer
                </h5>
                <span class="badge trainer-badge"><?php echo count($trainers); ?> Trainer</span>
            </div>
            <div class="card-body">
                <div class="row g-1">
                    <?php foreach ($trainers as $trainer): ?>
                        <div class="col-md-6 mb-1">
                            <div class="card trainer-card participant-card p-0">
                                <div class="d-flex align-items-center py-2">
                                    <?php if (!empty($trainer['bild']) && $trainer['bild'] !== 'kein-bild.jpg'): ?>
                                        <div class="mitarbeiter-img ms-2" style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($trainer['bild']); ?>')"></div>
                                    <?php else: ?>
                                        <div class="mitarbeiter-placeholder ms-2">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="ms-3">
                                        <h6 class="mb-1 fw-bold fs-6"><?php echo htmlspecialchars($trainer['name']); ?></h6>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php if (!empty($trainer['crew'])): ?>
                                                <span class="badge bg-info bg-opacity-25 text-dark">
                                                <?php echo htmlspecialchars($trainer['crew']); ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if (!empty($trainer['position'])): ?>
                                                <span class="badge bg-light text-dark">
                                                <?php echo htmlspecialchars($trainer['position']); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="ms-auto me-2">
                                        <a href="employee_details.php?employee_id=<?php echo $trainer['employee_id']; ?>"
                                           class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bi bi-people-fill me-2"></i> Teilnehmerliste
            </h5>
            <span class="badge bg-primary"><?php echo count($participants); ?> Teilnehmer</span>
        </div>
        <div class="card-body">
            <?php if (empty($participants)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i> Keine Teilnehmer für diese Weiterbildung vorhanden.
                </div>
            <?php else: ?>
                <div class="accordion" id="participantsAccordion">
                    <?php foreach ($grouped_participants as $group => $group_participants): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading-<?php echo md5($group); ?>">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#collapse-<?php echo md5($group); ?>" aria-expanded="true">
                                    <?php echo htmlspecialchars($group); ?>
                                    <span class="badge bg-secondary ms-2"><?php echo count($group_participants); ?></span>
                                </button>
                            </h2>
                            <div id="collapse-<?php echo md5($group); ?>" class="accordion-collapse collapse show"
                                 aria-labelledby="heading-<?php echo md5($group); ?>">
                                <div class="accordion-body p-0">
                                    <div class="row g-1 p-3">
                                        <?php foreach ($group_participants as $participant): ?>
                                            <div class="col-md-6 mb-1">
                                                <div class="card participant-card p-0">
                                                    <div class="d-flex align-items-center py-2">
                                                        <?php if (!empty($participant['bild']) && $participant['bild'] !== 'kein-bild.jpg'): ?>
                                                            <div class="mitarbeiter-img ms-2" style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($participant['bild']); ?>')"></div>
                                                        <?php else: ?>
                                                            <div class="mitarbeiter-placeholder ms-2">
                                                                <i class="bi bi-person-fill"></i>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="ms-3">
                                                            <h6 class="mb-1 fw-bold fs-6"><?php echo htmlspecialchars($participant['name']); ?></h6>
                                                            <div class="d-flex gap-2 flex-wrap">
                                                                <?php if (!empty($participant['crew'])): ?>
                                                                    <span class="badge bg-info bg-opacity-25 text-dark">
                                                                        <?php echo htmlspecialchars($participant['crew']); ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                                <?php if (!empty($participant['position'])): ?>
                                                                    <span class="badge bg-light text-dark">
                                                                        <?php echo htmlspecialchars($participant['position']); ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>

                                                        <div class="ms-auto me-2">
                                                            <a href="employee_details.php?employee_id=<?php echo $participant['employee_id']; ?>"
                                                               class="btn btn-sm btn-outline-primary">
                                                                <i class="bi bi-arrow-right"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>