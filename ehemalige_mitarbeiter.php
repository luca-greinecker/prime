<?php
/**
 * ehemalige_mitarbeiter.php
 *
 * Zeigt eine Liste aller archivierten/ehemaligen Mitarbeiter.
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

// Sortierung: Standard nach Austrittsdatum absteigend (neueste zuerst)
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'leave_date';
$sort_dir = isset($_GET['dir']) ? $_GET['dir'] : 'desc';

// Gültige Sortiermöglichkeiten
$valid_sort_fields = ['leave_date', 'name', 'entry_date'];
$valid_sort_dirs = ['asc', 'desc'];

// Validierung
if (!in_array($sort_by, $valid_sort_fields)) {
    $sort_by = 'leave_date';
}
if (!in_array($sort_dir, $valid_sort_dirs)) {
    $sort_dir = 'desc';
}

// Abfrage für ehemalige Mitarbeiter
$sql = "
    SELECT 
        employee_id,
        name,
        entry_date,
        leave_date,
        leave_reason,
        gruppe,
        crew,
        bild,
        position
    FROM employees 
    WHERE status = 9999
    ORDER BY " . $sort_by . " " . strtoupper($sort_dir);

$result = $conn->query($sql);
$ehemalige = [];
$total_ehemalige = 0;

// Daten auslesen
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ehemalige[] = $row;
        $total_ehemalige++;
    }
}

// Jahre für Gruppierung
$years = [];
if (!empty($ehemalige)) {
    foreach ($ehemalige as $ma) {
        if (!empty($ma['leave_date'])) {
            $year = date('Y', strtotime($ma['leave_date']));
            if (!in_array($year, $years)) {
                $years[] = $year;
            }
        }
    }
    // Jahre sortieren (neueste zuerst)
    rsort($years);
}

// Funktion für URL-Erstellung mit Sortierung
function get_sort_url($field) {
    global $sort_by, $sort_dir;
    $new_dir = ($sort_by === $field && $sort_dir === 'asc') ? 'desc' : 'asc';
    return '?sort=' . $field . '&dir=' . $new_dir;
}

// Funktion für Sortier-Pfeil
function get_sort_icon($field) {
    global $sort_by, $sort_dir;
    if ($sort_by !== $field) {
        return '<i class="bi bi-arrow-down-up text-muted"></i>';
    } else {
        return ($sort_dir === 'asc')
            ? '<i class="bi bi-sort-down-alt text-primary"></i>'
            : '<i class="bi bi-sort-down text-primary"></i>';
    }
}
?>

    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ehemalige Mitarbeiter</title>
        <!-- Lokales Bootstrap 5 CSS + Navbar -->
        <link href="navbar.css" rel="stylesheet">
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

        <style>
            .page-header {
                position: relative;
                padding: 2rem 1.5rem;
                margin-bottom: 1.5rem;
                background-color: var(--ball-white);
                border-radius: 10px;
                box-shadow: 0 4px 12px var(--ball-shadow);
                text-align: center;
            }

            .sort-controls {
                background-color: white;
                padding: 1rem;
                border-radius: 10px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                margin-bottom: 1.5rem;
            }

            .year-header {
                background-color: #f8f9fa;
                padding: 0.8rem 1rem;
                margin-bottom: 1rem;
                border-radius: 8px;
                font-weight: 600;
                display: flex;
                align-items: center;
                border-left: 4px solid var(--ball-blue);
            }

            .year-count {
                margin-left: auto;
                background-color: var(--ball-blue);
                color: white;
                padding: 0.2rem 0.6rem;
                border-radius: 20px;
                font-size: 0.8rem;
            }

            .mitarbeiter-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 1.25rem;
                margin-bottom: 2rem;
            }

            .mitarbeiter-card {
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                height: 100%;
            }

            .mitarbeiter-header {
                padding: 1rem;
                position: relative;
                display: flex;
                flex-direction: column;
                align-items: center;
                border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            }

            .mitarbeiter-img-container {
                position: relative;
                width: 100px;
                height: 100px;
                margin-bottom: 0.5rem;
            }

            .mitarbeiter-img {
                width: 100%;
                height: 100%;
                border-radius: 50%;
                object-fit: cover;
                border: 3px solid var(--ball-blue-light);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            .mitarbeiter-placeholder {
                width: 100%;
                height: 100%;
                border-radius: 50%;
                background-color: #e9ecef;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #adb5bd;
                font-size: 2.5rem;
                border: 3px solid var(--ball-blue-light);
            }

            .mitarbeiter-name {
                font-weight: 600;
                font-size: 1.1rem;
                text-align: center;
                margin-bottom: 0.25rem;
                word-break: break-word;
            }

            .mitarbeiter-position {
                color: #6c757d;
                font-size: 0.9rem;
                text-align: center;
                margin-bottom: 0.5rem;
            }

            .mitarbeiter-body {
                padding: 1rem;
                flex-grow: 1;
                display: flex;
                flex-direction: column;
            }

            .mitarbeiter-info {
                margin-bottom: 0.5rem;
                font-size: 0.9rem;
            }

            .mitarbeiter-info .label {
                font-weight: 600;
                display: inline-block;
                min-width: 100px;
            }

            .mitarbeiter-actions {
                margin-top: auto;
                padding-top: 1rem;
                display: flex;
                justify-content: center;
            }

            .mitarbeiter-badge {
                display: inline-block;
                padding: 0.25rem 0.5rem;
                font-size: 0.8rem;
                font-weight: 600;
                border-radius: 4px;
                margin-left: 0.5rem;
            }

            .no-results {
                text-align: center;
                padding: 3rem;
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            }

            .no-results i {
                font-size: 3rem;
                color: #dee2e6;
                margin-bottom: 1rem;
            }

            .sort-link {
                text-decoration: none;
                color: inherit;
                display: inline-flex;
                align-items: center;
                gap: 0.25rem;
            }

            .sort-link:hover {
                color: var(--ball-blue);
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>

    <div class="container content">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="bi bi-archive me-2"></i>Ehemalige Mitarbeiter</h1>
            <p class="text-muted mt-2 mb-0">Gesamtzahl: <?php echo $total_ehemalige; ?> Mitarbeiter</p>
        </div>

        <!-- Sortierkontrollen -->
        <div class="sort-controls">
            <div class="d-flex flex-wrap align-items-center">
                <div class="me-3">
                    <strong>Sortieren nach:</strong>
                </div>
                <div class="d-flex gap-3 flex-wrap">
                    <a href="<?php echo get_sort_url('leave_date'); ?>" class="sort-link">
                        Austrittsdatum <?php echo get_sort_icon('leave_date'); ?>
                    </a>
                    <a href="<?php echo get_sort_url('name'); ?>" class="sort-link">
                        Name <?php echo get_sort_icon('name'); ?>
                    </a>
                    <a href="<?php echo get_sort_url('entry_date'); ?>" class="sort-link">
                        Eintrittsdatum <?php echo get_sort_icon('entry_date'); ?>
                    </a>
                </div>
            </div>
        </div>

        <?php if ($total_ehemalige > 0): ?>
            <?php
            // Nach Jahren gruppieren, wenn nach Austrittsdatum sortiert wird
            if ($sort_by === 'leave_date'):
                foreach ($years as $year):
                    // Mitarbeiter für dieses Jahr filtern
                    $year_employees = array_filter($ehemalige, function($e) use ($year) {
                        return !empty($e['leave_date']) && date('Y', strtotime($e['leave_date'])) == $year;
                    });

                    if (!empty($year_employees)):
                        ?>
                        <div class="year-section mb-4">
                            <div class="year-header">
                                <i class="bi bi-calendar-event me-2"></i>
                                <?php echo $year; ?>
                                <div class="year-count"><?php echo count($year_employees); ?></div>
                            </div>

                            <div class="mitarbeiter-grid">
                                <?php foreach ($year_employees as $mitarbeiter): ?>
                                    <div class="mitarbeiter-card">
                                        <div class="mitarbeiter-header">
                                            <div class="mitarbeiter-img-container">
                                                <?php if (!empty($mitarbeiter['bild']) && $mitarbeiter['bild'] !== 'kein-bild.jpg'): ?>
                                                    <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($mitarbeiter['bild']); ?>"
                                                         class="mitarbeiter-img" alt="Mitarbeiterfoto">
                                                <?php else: ?>
                                                    <div class="mitarbeiter-placeholder">
                                                        <i class="bi bi-person-fill"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <h3 class="mitarbeiter-name"><?php echo htmlspecialchars($mitarbeiter['name']); ?></h3>
                                            <div class="mitarbeiter-position"><?php echo htmlspecialchars($mitarbeiter['position']); ?></div>
                                        </div>

                                        <div class="mitarbeiter-body">
                                            <div class="mitarbeiter-info">
                                                <span class="label">Eintritt:</span>
                                                <?php echo !empty($mitarbeiter['entry_date']) ? date('d.m.Y', strtotime($mitarbeiter['entry_date'])) : '-'; ?>
                                            </div>

                                            <div class="mitarbeiter-info">
                                                <span class="label">Austritt:</span>
                                                <?php echo !empty($mitarbeiter['leave_date']) ? date('d.m.Y', strtotime($mitarbeiter['leave_date'])) : '-'; ?>
                                            </div>

                                            <div class="mitarbeiter-info">
                                                <span class="label">Austrittsgrund:</span><br>
                                                <?php echo !empty($mitarbeiter['leave_reason']) ? htmlspecialchars($mitarbeiter['leave_reason']) : 'Nicht angegeben'; ?>
                                            </div>

                                            <div class="mitarbeiter-actions">
                                                <a href="archived_employee_details.php?employee_id=<?php echo $mitarbeiter['employee_id']; ?>"
                                                   class="btn btn-outline-primary">
                                                    <i class="bi bi-eye-fill me-1"></i> Details anzeigen
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php
                    endif;
                endforeach;
                ?>
            <?php else: ?>
                <!-- Wenn nicht nach Austrittsdatum sortiert, alle anzeigen ohne Jahr-Gruppierung -->
                <div class="mitarbeiter-grid">
                    <?php foreach ($ehemalige as $mitarbeiter): ?>
                        <div class="mitarbeiter-card">
                            <div class="mitarbeiter-header">
                                <div class="mitarbeiter-img-container">
                                    <?php if (!empty($mitarbeiter['bild']) && $mitarbeiter['bild'] !== 'kein-bild.jpg'): ?>
                                        <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($mitarbeiter['bild']); ?>"
                                             class="mitarbeiter-img" alt="Mitarbeiterfoto">
                                    <?php else: ?>
                                        <div class="mitarbeiter-placeholder">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h3 class="mitarbeiter-name"><?php echo htmlspecialchars($mitarbeiter['name']); ?></h3>
                                <div class="mitarbeiter-position"><?php echo htmlspecialchars($mitarbeiter['position']); ?></div>
                            </div>

                            <div class="mitarbeiter-body">
                                <div class="mitarbeiter-info">
                                    <span class="label">Eintritt:</span>
                                    <?php echo !empty($mitarbeiter['entry_date']) ? date('d.m.Y', strtotime($mitarbeiter['entry_date'])) : '-'; ?>
                                </div>

                                <div class="mitarbeiter-info">
                                    <span class="label">Austritt:</span>
                                    <?php echo !empty($mitarbeiter['leave_date']) ? date('d.m.Y', strtotime($mitarbeiter['leave_date'])) : '-'; ?>
                                </div>

                                <div class="mitarbeiter-info">
                                    <span class="label">Austrittsgrund:</span><br>
                                    <?php echo !empty($mitarbeiter['leave_reason']) ? htmlspecialchars($mitarbeiter['leave_reason']) : 'Nicht angegeben'; ?>
                                </div>

                                <div class="mitarbeiter-actions">
                                    <a href="employee_details.php?employee_id=<?php echo $mitarbeiter['employee_id']; ?>"
                                       class="btn btn-outline-primary">
                                        <i class="bi bi-eye-fill me-1"></i> Details anzeigen
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-results">
                <i class="bi bi-search"></i>
                <h3>Keine ehemaligen Mitarbeiter gefunden</h3>
                <p class="text-muted">Es wurden keine archivierten Mitarbeiter in der Datenbank gefunden.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Lokales Bootstrap 5 JavaScript Bundle -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>

<?php
$conn->close();
?>