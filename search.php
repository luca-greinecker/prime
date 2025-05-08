<?php
/**
 * search.php
 *
 * Ermöglicht das Suchen von Mitarbeitern (employees) oder Weiterbildungen (trainings),
 * basierend auf einem Suchbegriff (GET-Parameter "query").
 * Nutzt das neue Session- / Zugriffs-Control via access_control.php.
 */

include 'access_control.php';
global $conn;

// Benutzer muss eingeloggt sein
pruefe_benutzer_eingeloggt();

// Suchbegriff holen
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$like = '%' . $query . '%';

// Arrays für Suchergebnisse
$employees = [];
$trainings = [];
$counts = ['employees' => 0, 'trainings' => 0];

if (!empty($query)) {
    // Mitarbeiter-Suche
    $empStmt = $conn->prepare("
        SELECT employee_id, name, position, crew, gruppe
        FROM employees 
        WHERE name LIKE ? 
           OR position LIKE ?
           OR crew LIKE ?
           OR gruppe LIKE ?
    ");

    if ($empStmt) {
        $empStmt->bind_param("ssss", $like, $like, $like, $like);
        $empStmt->execute();
        $employees = $empStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $counts['employees'] = count($employees);
        $empStmt->close();
    } else {
        $employees_error = "Fehler bei der Mitarbeitersuche: " . $conn->error;
    }

    // Weiterbildungen-Suche mit JOIN zu den Kategorietabellen
    $trainStmt = $conn->prepare("
        SELECT 
            t.id, 
            t.display_id,
            t.training_name, 
            t.start_date,
            t.end_date,
            mc.name AS main_category_name,
            sc.name AS sub_category_name
        FROM trainings t
        JOIN training_main_categories mc ON t.main_category_id = mc.id
        LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
        WHERE t.training_name LIKE ? 
           OR mc.name LIKE ?
           OR sc.name LIKE ?
           OR t.display_id LIKE ?
    ");

    if ($trainStmt) {
        $trainStmt->bind_param("ssss", $like, $like, $like, $like);
        $trainStmt->execute();
        $trainings = $trainStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $counts['trainings'] = count($trainings);
        $trainStmt->close();
    } else {
        $trainings_error = "Fehler bei der Weiterbildungssuche: " . $conn->error;
    }
}

// Set initial active tab based on results
$active_tab = 'employees';
if ($counts['employees'] == 0 && $counts['trainings'] > 0) {
    $active_tab = 'trainings';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Suchergebnisse für "<?php echo htmlspecialchars($query); ?>"</title>
    <!-- Lokales Bootstrap 5 CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .search-result-card {
            transition: all 0.2s ease;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            height: 100%;
        }

        .search-result-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
        }

        .badge-category {
            font-size: 0.8rem;
            padding: 0.25em 0.5em;
            margin-right: 0.5rem;
            border-radius: 20px;
        }

        .search-meta {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .search-header {
            background-color: #f8f9fa;
            padding: 20px 0;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .nav-tabs .nav-link {
            font-weight: 500;
            color: #6c757d !important;
            border: none;
            padding: 12px 20px;
            border-radius: 0;
            position: relative;
        }

        .nav-tabs .nav-link.active {
            color: #0d6efd !important;
            background-color: transparent;
            border-bottom: 3px solid #0d6efd;
        }

        .nav-tabs .nav-link .badge {
            position: relative;
            top: -1px;
            margin-left: 5px;
        }

        .tab-content {
            padding-top: 25px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            border-radius: 10px;
            background-color: #f8f9fa;
            margin: 20px 0;
        }

        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 15px;
        }

        .empty-state h4 {
            color: #6c757d;
            margin-bottom: 10px;
        }

        .card-body {
            display: flex;
            flex-direction: column;
        }

        .card-body .mt-auto {
            margin-top: auto;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container content">
    <div class="search-header">
        <div class="container">
            <h1 class="text-center mb-4">
                <i class="bi bi-search me-2"></i>
                Suchergebnisse für "<?php echo htmlspecialchars($query); ?>"
            </h1>

            <?php if (!empty($query)): ?>
                <div class="d-flex justify-content-center mb-3">
                    <span class="badge bg-primary me-3">
                        <i class="bi bi-people me-1"></i> <?php echo $counts['employees']; ?> Mitarbeiter
                    </span>
                    <span class="badge bg-info">
                        <i class="bi bi-journal-text me-1"></i> <?php echo $counts['trainings']; ?> Weiterbildungen
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($query)): ?>
        <div class="empty-state">
            <i class="bi bi-search"></i>
            <h4>Bitte geben Sie einen Suchbegriff ein</h4>
            <p class="text-muted">Verwenden Sie die Suchleiste oben, um nach Mitarbeitern oder Weiterbildungen zu
                suchen.</p>
        </div>
    <?php else: ?>
        <!-- Tabs Navigation -->
        <ul class="nav nav-tabs" id="searchTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab == 'employees' ? 'active' : ''; ?>"
                        id="employees-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#employees-results"
                        type="button"
                        role="tab">
                    <i class="bi bi-people me-2"></i>Mitarbeiter
                    <span class="badge bg-primary"><?php echo $counts['employees']; ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab == 'trainings' ? 'active' : ''; ?>"
                        id="trainings-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#trainings-results"
                        type="button"
                        role="tab">
                    <i class="bi bi-journal-text me-2"></i>Weiterbildungen
                    <span class="badge bg-info"><?php echo $counts['trainings']; ?></span>
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="searchTabContent">
            <!-- Mitarbeiter Tab -->
            <div class="tab-pane fade <?php echo $active_tab == 'employees' ? 'show active' : ''; ?>"
                 id="employees-results"
                 role="tabpanel">

                <?php if (isset($employees_error)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($employees_error); ?>
                    </div>
                <?php elseif (count($employees) > 0): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-4">
                        <?php foreach ($employees as $emp): ?>
                            <div class="col">
                                <div class="card search-result-card">
                                    <div class="card-body">
                                        <h5 class="card-title">
                                            <a href="employee_details.php?employee_id=<?php echo (int)$emp['employee_id']; ?>"
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($emp['name']); ?>
                                            </a>
                                        </h5>
                                        <p class="card-text">
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($emp['position']); ?></span>

                                            <?php if (!empty($emp['crew']) && $emp['crew'] !== '---'): ?>
                                                <span class="badge bg-info text-dark"><?php echo htmlspecialchars($emp['crew']); ?></span>
                                            <?php endif; ?>

                                            <?php if (!empty($emp['gruppe'])): ?>
                                                <span class="badge bg-dark"><?php echo htmlspecialchars($emp['gruppe']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <div class="mt-auto">
                                            <a href="employee_details.php?employee_id=<?php echo (int)$emp['employee_id']; ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-person-badge"></i> Profil anzeigen
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-people"></i>
                        <h4>Keine Mitarbeiter gefunden</h4>
                        <p class="text-muted">Versuchen Sie, Ihre Suche zu verfeinern oder nach anderen Begriffen zu
                            suchen.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Weiterbildungen Tab -->
            <div class="tab-pane fade <?php echo $active_tab == 'trainings' ? 'show active' : ''; ?>"
                 id="trainings-results"
                 role="tabpanel">

                <?php if (isset($trainings_error)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($trainings_error); ?>
                    </div>
                <?php elseif (count($trainings) > 0): ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                        <?php foreach ($trainings as $train): ?>
                            <div class="col">
                                <div class="card search-result-card">
                                    <div class="card-body">
                                        <h5 class="card-title">
                                            <a href="edit_training.php?id=<?php echo (int)$train['id']; ?>"
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($train['training_name']); ?>
                                            </a>
                                        </h5>
                                        <p class="card-text search-meta">
                                            <span class="badge bg-secondary badge-category">
                                                <?php echo htmlspecialchars($train['display_id'] ?? $train['id']); ?>
                                            </span>

                                            <?php if (!empty($train['main_category_name'])): ?>
                                                <span class="badge bg-primary badge-category">
                                                    <?php echo htmlspecialchars($train['main_category_name']); ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($train['sub_category_name'])): ?>
                                                <span class="badge bg-info text-dark badge-category">
                                                    <?php echo htmlspecialchars($train['sub_category_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="card-text">
                                            <small class="text-muted">
                                                <i class="bi bi-calendar-event me-1"></i>
                                                <?php
                                                echo htmlspecialchars(date('d.m.Y', strtotime($train['start_date'])));
                                                if ($train['start_date'] != $train['end_date']) {
                                                    echo ' - ' . htmlspecialchars(date('d.m.Y', strtotime($train['end_date'])));
                                                }
                                                ?>
                                            </small>
                                        </p>
                                        <div class="mt-auto">
                                            <a href="edit_training.php?id=<?php echo (int)$train['id']; ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-pencil-square"></i> Bearbeiten
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-journal-text"></i>
                        <h4>Keine Weiterbildungen gefunden</h4>
                        <p class="text-muted">Versuchen Sie, Ihre Suche zu verfeinern oder nach anderen Begriffen zu
                            suchen.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>