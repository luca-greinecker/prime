<?php
/**
 * history.php
 *
 * Zeigt eine Liste aller durchgeführten Mitarbeitergespräche (Reviews) für einen Mitarbeiter an,
 * inklusive Details zu Rückblick, Entwicklung, Feedback sowie etwaige Zusatzfunktionen (z.B. Ersthelfer).
 *
 * Nur Administratoren, HR- oder Bereichsleiter haben Zugriff.
 */

include_once 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Nur Admin, HR oder Bereichsleiter
pruefe_admin_oder_hr_oder_bereichsleiter_zugriff();

// Mitarbeiter-ID aus GET holen
if (!isset($_GET['employee_id']) || !is_numeric($_GET['employee_id'])) {
    echo '<div class="alert alert-danger">Keine gültige Mitarbeiter-ID angegeben.</div>';
    exit;
}
$employee_id = (int)$_GET['employee_id'];

// Mitarbeiterinformationen abrufen
try {
    $stmt = $conn->prepare("SELECT name, position, crew FROM employees WHERE employee_id = ?");
    if (!$stmt) {
        throw new Exception("Fehler beim Vorbereiten des Statements: " . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    $stmt->close();

    if (!$employee) {
        echo '<div class="alert alert-danger">Kein Mitarbeiter mit dieser ID gefunden.</div>';
        exit;
    }
} catch (Exception $e) {
    die('<div class="alert alert-danger">' . $e->getMessage() . '</div>');
}

// Mitarbeitergespräch-Historie abrufen
try {
    $stmt = $conn->prepare("
        SELECT 
            id,
            date,
            rueckblick,
            entwicklung,
            feedback,
            brandschutzwart,
            sprinklerwart,
            ersthelfer,
            svp,
            trainertaetigkeiten,
            trainertaetigkeiten_kommentar,
            zufriedenheit,
            unzufriedenheit_grund,
            reviewer_id
        FROM employee_reviews
        WHERE employee_id = ?
        ORDER BY date DESC
    ");
    if (!$stmt) {
        throw new Exception("Fehler beim Vorbereiten des Statements: " . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $history_result = $stmt->get_result();
    $reviews = $history_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    die('<div class="alert alert-danger">' . $e->getMessage() . '</div>');
}

/**
 * Hilfsfunktion zum Abrufen der Skills für ein Review nach Kategorie
 * @param mysqli $conn - Datenbankverbindung
 * @param int $review_id - Review ID
 * @param string $kategorie - Kategorie der Skills
 * @return array - Skills
 */
function getSkillsByCategory($conn, $review_id, $kategorie)
{
    $skills = [];

    $condition = "s.kategorie = ?";
    $params = [$review_id, $kategorie];
    $types = "is";

    if ($kategorie === 'führung_persönlich') {
        $condition = "(s.kategorie = 'Führungskompetenzen' OR s.kategorie = 'Persönliche Kompetenzen')";
        $params = [$review_id];
        $types = "i";
    }

    $query = "
        SELECT s.name, s.kategorie, es.rating
        FROM employee_skills es
        JOIN skills s ON es.skill_id = s.id
        WHERE es.review_id = ? AND $condition
        ORDER BY s.kategorie, s.name
    ";

    try {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Fehler beim Vorbereiten des Statements: " . htmlspecialchars($conn->error));
        }

        if ($kategorie === 'führung_persönlich') {
            $stmt->bind_param("i", $params[0]);
        } else {
            $stmt->bind_param($types, $params[0], $params[1]);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $skills[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        // Bei Fehler leeres Array zurückgeben
        error_log($e->getMessage());
    }

    return $skills;
}

/**
 * Hilfsfunktion zum Abrufen des Reviewer-Namens
 * @param mysqli $conn - Datenbankverbindung
 * @param int $reviewer_id - Reviewer ID
 * @return string - Reviewer Name
 */
function getReviewerName($conn, $reviewer_id)
{
    if (!$reviewer_id) {
        return 'Unbekannt';
    }

    try {
        $stmt = $conn->prepare("SELECT name FROM employees WHERE employee_id = ?");
        if (!$stmt) {
            throw new Exception("Fehler beim Vorbereiten des Statements: " . htmlspecialchars($conn->error));
        }
        $stmt->bind_param("i", $reviewer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reviewer_name = 'Unbekannt';
        if ($result->num_rows > 0) {
            $reviewer_row = $result->fetch_assoc();
            $reviewer_name = $reviewer_row['name'];
        }
        $stmt->close();
        return $reviewer_name;
    } catch (Exception $e) {
        error_log($e->getMessage());
        return 'Unbekannt';
    }
}

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitarbeitergespräch-Historie</title>
    <!-- Bootstrap CSS & Icons -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="navbar.css" rel="stylesheet">
    <link href="history-styles.css" rel="stylesheet">
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container">
    <div class="page-header d-flex justify-content-between align-items-center">
        <h1>
            <i class="bi bi-clock-history me-2"></i>
            Mitarbeitergespräch-Historie
        </h1>
        <a href="employee_details.php?employee_id=<?php echo htmlspecialchars($employee_id); ?>"
           class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurück zur Mitarbeiterbearbeitung
        </a>
    </div>

    <div class="card employee-card mb-4">
        <div class="card-body">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="bi bi-person-circle text-primary" style="font-size: 3rem;"></i>
                </div>
                <div>
                    <h2 class="mb-1"><?php echo htmlspecialchars($employee['name']); ?></h2>
                    <p class="mb-0 text-muted">
                        <span class="badge bg-info"><?php echo htmlspecialchars($employee['position'] ?? '-'); ?></span>
                        <?php if (!empty($employee['crew']) && $employee['crew'] !== '---'): ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($employee['crew']); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($reviews)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i> Keine Mitarbeitergespräche vorhanden.
        </div>
    <?php else: ?>
        <?php foreach ($reviews as $review): ?>
            <?php $reviewer_name = getReviewerName($conn, $review['reviewer_id']); ?>
            <div class="review-card">
                <div class="review-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="review-date">
                            <i class="bi bi-calendar-event me-2"></i>
                            <?php echo date("d.m.Y", strtotime($review['date'])); ?>
                        </div>
                        <div>
                            <span class="badge bg-primary">
                                <i class="bi bi-person me-1"></i> <?php echo htmlspecialchars($reviewer_name); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="review-body">
                    <div class="row mb-4">
                        <div class="col-md-5">
                            <div class="mb-3">
                                <div class="section-title">Rückblick</div>
                                <p><?php echo nl2br(htmlspecialchars($review['rueckblick'])); ?></p>
                            </div>
                            <div class="mb-3">
                                <div class="section-title">Entwicklung</div>
                                <p><?php echo nl2br(htmlspecialchars($review['entwicklung'])); ?></p>
                            </div>
                            <div>
                                <div class="section-title">Feedback</div>
                                <p><?php echo nl2br(htmlspecialchars($review['feedback'])); ?></p>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="section-title">Zusatzfunktionen</div>
                            <div class="status-item d-flex align-items-center">
                                <span class="me-2">Brandschutzwart:</span>
                                <span class="badge <?php echo $review['brandschutzwart'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $review['brandschutzwart'] ? 'Ja' : 'Nein'; ?>
                                </span>
                            </div>
                            <div class="status-item d-flex align-items-center">
                                <span class="me-2">Sprinklerwart:</span>
                                <span class="badge <?php echo $review['sprinklerwart'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $review['sprinklerwart'] ? 'Ja' : 'Nein'; ?>
                                </span>
                            </div>
                            <div class="status-item d-flex align-items-center">
                                <span class="me-2">Ersthelfer:</span>
                                <span class="badge <?php echo $review['ersthelfer'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $review['ersthelfer'] ? 'Ja' : 'Nein'; ?>
                                </span>
                            </div>
                            <div class="status-item d-flex align-items-center">
                                <span class="me-2">Sicherheitsvertrauensperson:</span>
                                <span class="badge <?php echo $review['svp'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $review['svp'] ? 'Ja' : 'Nein'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="section-title">Status</div>
                            <div class="status-item d-flex align-items-center">
                                <span class="me-2">Trainertätigkeiten:</span>
                                <span class="badge <?php echo $review['trainertaetigkeiten'] ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $review['trainertaetigkeiten'] ? 'Ja' : 'Nein'; ?>
                                </span>
                            </div>
                            <?php if ($review['trainertaetigkeiten'] && $review['trainertaetigkeiten_kommentar']): ?>
                                <div class="mb-3 mt-2">
                                    <div class="comment-box">
                                        <small><strong>Kommentar zu Trainertätigkeiten:</strong></small><br>
                                        <?php echo nl2br(htmlspecialchars($review['trainertaetigkeiten_kommentar'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="status-item d-flex align-items-center mt-3">
                                <span class="me-2">Zufriedenheit:</span>
                                <span class="badge <?php echo $review['zufriedenheit'] === 'Zufrieden' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                    <?php echo htmlspecialchars($review['zufriedenheit']); ?>
                                </span>
                            </div>
                            <?php if ($review['zufriedenheit'] === 'Unzufrieden' && $review['unzufriedenheit_grund']): ?>
                                <div class="comment-box mt-2">
                                    <small><strong>Gründe für Unzufriedenheit:</strong></small><br>
                                    <?php echo nl2br(htmlspecialchars($review['unzufriedenheit_grund'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="thin-divider"></div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="section-title">Anwenderkenntnisse</div>
                            <?php $skills = getSkillsByCategory($conn, $review['id'], 'Anwenderkenntnisse'); ?>
                            <?php if (!empty($skills)): ?>
                                <ul class="skill-list">
                                    <?php foreach ($skills as $skill): ?>
                                        <li class="skill-item">
                                            <span class="skill-name"><?php echo htmlspecialchars($skill['name']); ?></span>
                                            <div class="skill-rating">
                                                <span class="rating-value"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                <div class="rating-bar">
                                                    <div class="rating-fill"
                                                         style="width: <?php echo(intval($skill['rating']) / 9 * 100); ?>%"></div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted">Keine Anwenderkenntnisse bewertet.</p>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <div class="section-title">Positionsspezifische Kompetenzen</div>
                            <?php $skills = getSkillsByCategory($conn, $review['id'], 'Positionsspezifische Kompetenzen'); ?>
                            <?php if (!empty($skills)): ?>
                                <ul class="skill-list">
                                    <?php foreach ($skills as $skill): ?>
                                        <li class="skill-item">
                                            <span class="skill-name"><?php echo htmlspecialchars($skill['name']); ?></span>
                                            <div class="skill-rating">
                                                <span class="rating-value"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                <div class="rating-bar">
                                                    <div class="rating-fill"
                                                         style="width: <?php echo(intval($skill['rating']) / 9 * 100); ?>%"></div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted">Keine positionsspezifischen Kompetenzen bewertet.</p>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <div class="section-title">Führungs- & Persönliche Kompetenzen</div>
                            <?php $skills = getSkillsByCategory($conn, $review['id'], 'führung_persönlich'); ?>
                            <?php if (!empty($skills)): ?>
                                <ul class="skill-list">
                                    <?php foreach ($skills as $skill): ?>
                                        <li class="skill-item">
                                            <span class="skill-name"><?php echo htmlspecialchars($skill['name']); ?></span>
                                            <div class="skill-rating">
                                                <span class="rating-value"><?php echo htmlspecialchars($skill['rating']); ?>/9</span>
                                                <div class="rating-bar">
                                                    <div class="rating-fill"
                                                         style="width: <?php echo(intval($skill['rating']) / 9 * 100); ?>%"></div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted">Keine Führungs- oder persönlichen Kompetenzen bewertet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Bootstrap JavaScript Bundle -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>