<?php
/**
 * manage_categories.php
 *
 * Verwaltung der Trainings-Kategorien (Haupt- und Unterkategorien)
 * Ermöglicht das Hinzufügen neuer Kategorien sowie das Aktivieren/Deaktivieren bestehender Kategorien.
 * Nur für Administratoren zugänglich.
 */

include 'access_control.php';
include 'training_functions.php';
global $conn;

pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// Ergebnis-Variablen für das Modal
$result_message = '';
$result_type = '';

// POST-Anfragen verarbeiten
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Neue Hauptkategorie hinzufügen
    if (isset($_POST['add_main_category'])) {
        $name = trim($_POST['main_category_name']);
        $code = trim($_POST['main_category_code']);

        if (empty($name) || empty($code)) {
            $result_message = 'Bitte geben Sie Name und Code für die Hauptkategorie ein.';
            $result_type = 'error';
        } else if (strlen($code) != 2 || !is_numeric($code)) {
            $result_message = 'Der Code muss genau 2 Ziffern lang sein.';
            $result_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO training_main_categories (name, code) VALUES (?, ?)");

            if (!$stmt) {
                $result_message = 'Fehler bei der Datenbankabfrage: ' . htmlspecialchars($conn->error);
                $result_type = 'error';
            } else {
                $stmt->bind_param("ss", $name, $code);

                if ($stmt->execute()) {
                    $result_message = 'Hauptkategorie wurde erfolgreich hinzugefügt.';
                    $result_type = 'success';
                } else {
                    // Fehler beim Ausführen - wahrscheinlich UNIQUE-Constraint verletzt
                    if ($conn->errno == 1062) {
                        $result_message = 'Fehler: Der Code "' . htmlspecialchars($code) . '" wird bereits verwendet.';
                    } else {
                        $result_message = 'Fehler beim Hinzufügen der Hauptkategorie: ' . htmlspecialchars($stmt->error);
                    }
                    $result_type = 'error';
                }
                $stmt->close();
            }
        }
    } // Neue Unterkategorie hinzufügen
    else if (isset($_POST['add_sub_category'])) {
        $name = trim($_POST['sub_category_name']);
        $code = trim($_POST['sub_category_code']);
        $main_category_id = $_POST['main_category_id'];

        if (empty($name) || empty($code) || empty($main_category_id)) {
            $result_message = 'Bitte geben Sie Name, Code und Hauptkategorie ein.';
            $result_type = 'error';
        } else if (strlen($code) != 2 || !is_numeric($code)) {
            $result_message = 'Der Code muss genau 2 Ziffern lang sein.';
            $result_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO training_sub_categories (main_category_id, code, name) VALUES (?, ?, ?)");

            if (!$stmt) {
                $result_message = 'Fehler bei der Datenbankabfrage: ' . htmlspecialchars($conn->error);
                $result_type = 'error';
            } else {
                $stmt->bind_param("iss", $main_category_id, $code, $name);

                if ($stmt->execute()) {
                    $result_message = 'Unterkategorie wurde erfolgreich hinzugefügt.';
                    $result_type = 'success';
                } else {
                    // Fehler beim Ausführen - wahrscheinlich UNIQUE-Constraint verletzt
                    if ($conn->errno == 1062) {
                        $result_message = 'Fehler: Der Code "' . htmlspecialchars($code) . '" wird für diese Hauptkategorie bereits verwendet.';
                    } else {
                        $result_message = 'Fehler beim Hinzufügen der Unterkategorie: ' . htmlspecialchars($stmt->error);
                    }
                    $result_type = 'error';
                }
                $stmt->close();
            }
        }
    } // Hauptkategorie aktivieren/deaktivieren
    else if (isset($_POST['toggle_main_category'])) {
        $id = $_POST['main_category_id'];
        $active = isset($_POST['activate']) ? 1 : 0;

        $stmt = $conn->prepare("UPDATE training_main_categories SET active = ? WHERE id = ?");

        if (!$stmt) {
            $result_message = 'Fehler bei der Datenbankabfrage: ' . htmlspecialchars($conn->error);
            $result_type = 'error';
        } else {
            $stmt->bind_param("ii", $active, $id);

            if ($stmt->execute()) {
                $result_message = $active ? 'Hauptkategorie wurde aktiviert.' : 'Hauptkategorie wurde deaktiviert.';
                $result_type = 'success';
            } else {
                $result_message = 'Fehler beim Aktualisieren der Hauptkategorie: ' . htmlspecialchars($stmt->error);
                $result_type = 'error';
            }
            $stmt->close();
        }
    } // Unterkategorie aktivieren/deaktivieren
    else if (isset($_POST['toggle_sub_category'])) {
        $id = $_POST['sub_category_id'];
        $active = isset($_POST['activate']) ? 1 : 0;

        $stmt = $conn->prepare("UPDATE training_sub_categories SET active = ? WHERE id = ?");

        if (!$stmt) {
            $result_message = 'Fehler bei der Datenbankabfrage: ' . htmlspecialchars($conn->error);
            $result_type = 'error';
        } else {
            $stmt->bind_param("ii", $active, $id);

            if ($stmt->execute()) {
                $result_message = $active ? 'Unterkategorie wurde aktiviert.' : 'Unterkategorie wurde deaktiviert.';
                $result_type = 'success';
            } else {
                $result_message = 'Fehler beim Aktualisieren der Unterkategorie: ' . htmlspecialchars($stmt->error);
                $result_type = 'error';
            }
            $stmt->close();
        }
    }
}

// Alle Hauptkategorien laden (aktiv und inaktiv)
$main_categories_stmt = $conn->prepare("
    SELECT id, name, code, active
    FROM training_main_categories
    ORDER BY active DESC, code ASC
");
$main_categories_stmt->execute();
$main_categories_result = $main_categories_stmt->get_result();
$main_categories = $main_categories_result->fetch_all(MYSQLI_ASSOC);
$main_categories_stmt->close();

// Alle Unterkategorien laden (aktiv und inaktiv)
$sub_categories_stmt = $conn->prepare("
    SELECT sc.id, sc.main_category_id, sc.code, sc.name, sc.active, mc.name as main_category_name
    FROM training_sub_categories sc
    JOIN training_main_categories mc ON sc.main_category_id = mc.id
    ORDER BY mc.code ASC, sc.active DESC, sc.name ASC
");
$sub_categories_stmt->execute();
$sub_categories_result = $sub_categories_stmt->get_result();
$sub_categories = $sub_categories_result->fetch_all(MYSQLI_ASSOC);
$sub_categories_stmt->close();

// Statistiken: Anzahl der aktiven/inaktiven Kategorien
$active_main_cats = array_filter($main_categories, function ($cat) {
    return $cat['active'] == 1;
});
$inactive_main_cats = array_filter($main_categories, function ($cat) {
    return $cat['active'] == 0;
});
$active_sub_cats = array_filter($sub_categories, function ($cat) {
    return $cat['active'] == 1;
});
$inactive_sub_cats = array_filter($sub_categories, function ($cat) {
    return $cat['active'] == 0;
});

// Anzahl Trainings pro Kategorie (für Info)
$training_counts = [];
$training_counts_stmt = $conn->prepare("
    SELECT main_category_id, COUNT(*) as count
    FROM trainings
    GROUP BY main_category_id
");
$training_counts_stmt->execute();
$training_counts_result = $training_counts_stmt->get_result();
while ($row = $training_counts_result->fetch_assoc()) {
    $training_counts[$row['main_category_id']] = $row['count'];
}
$training_counts_stmt->close();

// Unterkategorien nach Hauptkategorie gruppieren (für JS)
$sub_categories_by_main = [];
foreach ($sub_categories as $sub_cat) {
    if ($sub_cat['active'] == 1) { // Nur aktive für das Dropdown
        if (!isset($sub_categories_by_main[$sub_cat['main_category_id']])) {
            $sub_categories_by_main[$sub_cat['main_category_id']] = [];
        }
        $sub_categories_by_main[$sub_cat['main_category_id']][] = [
            'id' => $sub_cat['id'],
            'name' => $sub_cat['name']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kategorienverwaltung</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .card-header {
            background-color: #f8f9fa;
        }

        .inactive-row {
            background-color: #f7f7f7;
            color: #6c757d;
            font-style: italic;
        }

        .category-stats {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }

        .table-stats {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .form-help {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container content">
    <h1 class="text-center mb-3">Kategorieverwaltung</h1>
    <div class="alert alert-info mb-4">
        <h5><i class="bi bi-info-circle"></i> Über die Kategorieverwaltung</h5>
        <p>Hier können Sie Haupt- und Unterkategorien für Trainings verwalten. Anstatt Kategorien zu löschen, werden
            inaktive Kategorien weiterhin in der Datenbank gespeichert, um die Integrität früherer Trainings zu
            erhalten.</p>
        <ul>
            <li>Inaktive Kategorien werden nicht in den Auswahlmenüs angezeigt, wenn neue Trainings erstellt werden.
            </li>
            <li>Bestehende Trainings behalten ihre Kategorien, selbst wenn diese deaktiviert werden.</li>
            <li>Jede Haupt- und Unterkategorie benötigt einen einzigartigen 2-stelligen Code (z.B. "01", "02").</li>
        </ul>
    </div>

    <div class="row">
        <!-- Hauptkategorien -->
        <div class="col-md-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Hauptkategorien</h5>
                    <div class="category-stats">
                        <span class="badge bg-success"><?php echo count($active_main_cats); ?> aktiv</span>
                        <span class="badge bg-secondary"><?php echo count($inactive_main_cats); ?> inaktiv</span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Formular zum Hinzufügen von Hauptkategorien -->
                    <form method="post" class="mb-4">
                        <h6>Neue Hauptkategorie hinzufügen:</h6>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <input type="text" class="form-control" name="main_category_name" placeholder="Name"
                                       required>
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control" name="main_category_code" placeholder="Code"
                                       maxlength="2" required>
                                <div class="form-help">2-stellig</div>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="add_sub_category" class="btn btn-primary w-100">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Liste der Hauptkategorien -->
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Aktionen</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($main_categories as $category): ?>
                                <tr class="<?php echo $category['active'] ? '' : 'inactive-row'; ?>">
                                    <td><code><?php echo htmlspecialchars($category['code']); ?></code></td>
                                    <td>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                        <?php if (isset($training_counts[$category['id']])): ?>
                                            <span class="table-stats">(<?php echo $training_counts[$category['id']]; ?> Trainings)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                            <span class="badge <?php echo $category['active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $category['active'] ? 'Aktiv' : 'Inaktiv'; ?>
                                            </span>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="main_category_id"
                                                   value="<?php echo $category['id']; ?>">
                                            <?php if ($category['active']): ?>
                                                <button type="submit" name="toggle_main_category"
                                                        class="btn btn-sm btn-warning">Deaktivieren
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" name="toggle_main_category"
                                                        class="btn btn-sm btn-success">
                                                    <input type="hidden" name="activate" value="1">
                                                    Aktivieren
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($main_categories)): ?>
                                <tr>
                                    <td colspan="4" class="text-center">Keine Hauptkategorien vorhanden</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unterkategorien -->
        <div class="col-md-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Unterkategorien</h5>
                    <div class="category-stats">
                        <span class="badge bg-success"><?php echo count($active_sub_cats); ?> aktiv</span>
                        <span class="badge bg-secondary"><?php echo count($inactive_sub_cats); ?> inaktiv</span>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Formular zum Hinzufügen von Unterkategorien -->
                    <form method="post" class="mb-4">
                        <h6>Neue Unterkategorie hinzufügen:</h6>
                        <div class="row g-3">
                            <div class="col-md-5">
                                <input type="text" class="form-control" name="sub_category_name" placeholder="Name"
                                       required>
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control" name="sub_category_code" placeholder="Code"
                                       maxlength="2" required>
                                <div class="form-help">2-stellig</div>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select" name="main_category_id" required>
                                    <option value="" disabled selected>Hauptkategorie...</option>
                                    <?php foreach ($active_main_cats as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" name="add_sub_category" class="btn btn-primary w-100">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Liste der Unterkategorien -->
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Hauptkategorie</th>
                                <th>Status</th>
                                <th>Aktionen</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sub_categories as $category): ?>
                                <tr class="<?php echo $category['active'] ? '' : 'inactive-row'; ?>">
                                    <td><code><?php echo htmlspecialchars($category['code']); ?></code></td>
                                    <td><?php echo htmlspecialchars($category['name']); ?></td>
                                    <td><?php echo htmlspecialchars($category['main_category_name']); ?></td>
                                    <td>
                <span class="badge <?php echo $category['active'] ? 'bg-success' : 'bg-secondary'; ?>">
                    <?php echo $category['active'] ? 'Aktiv' : 'Inaktiv'; ?>
                </span>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="sub_category_id"
                                                   value="<?php echo $category['id']; ?>">
                                            <?php if ($category['active']): ?>
                                                <button type="submit" name="toggle_sub_category"
                                                        class="btn btn-sm btn-warning">Deaktivieren
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" name="toggle_sub_category"
                                                        class="btn btn-sm btn-success">
                                                    <input type="hidden" name="activate" value="1">
                                                    Aktivieren
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal für Meldungen -->
<?php if (!empty($result_message)): ?>
    <div class="modal fade" id="resultModal" tabindex="-1" aria-labelledby="resultModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resultModalLabel">Meldung</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-<?php echo $result_type === 'success' ? 'success' : 'danger'; ?>">
                        <?php echo $result_message; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Modal automatisch anzeigen, wenn eine Meldung vorliegt
        <?php if (!empty($result_message)): ?>
        var resultModal = new bootstrap.Modal(document.getElementById('resultModal'));
        resultModal.show();
        <?php endif; ?>
    });
</script>
</body>
</html>