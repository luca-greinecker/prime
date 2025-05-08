<?php
/**
 * training_overview.php
 *
 * Übersichtsseite für alle verfügbaren Trainings mit umfangreichen Such- und Filtermöglichkeiten.
 */

include 'access_control.php';
include 'training_functions.php';
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_trainings_zugriff();

// Standardwerte für Paginierung und Filter
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$items_per_page = 20;
$offset = ($page - 1) * $items_per_page;

// Filter-Parameter aus GET-Request auslesen
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$main_category = isset($_GET['main_category']) ? (int)$_GET['main_category'] : 0;
$sub_category = isset($_GET['sub_category']) ? (int)$_GET['sub_category'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$created_date_from = isset($_GET['created_date_from']) ? $_GET['created_date_from'] : ''; // Neuer Filter: Erstelldatum von
$created_date_to = isset($_GET['created_date_to']) ? $_GET['created_date_to'] : ''; // Neuer Filter: Erstelldatum bis
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$creator = isset($_GET['creator']) ? (int)$_GET['creator'] : 0;
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date_desc';

// Verfügbare Sortieroptionen
$sort_options = [
    'date_desc' => 'Datum (neueste zuerst)',
    'date_asc' => 'Datum (älteste zuerst)',
    'name_asc' => 'Name (A-Z)',
    'name_desc' => 'Name (Z-A)',
    'category_asc' => 'Kategorie (A-Z)',
    'units_desc' => 'Einheiten (höchste zuerst)',
    'units_asc' => 'Einheiten (niedrigste zuerst)',
    'created_desc' => 'Erstelldatum (neueste zuerst)', // Neue Option
    'created_asc' => 'Erstelldatum (älteste zuerst)', // Neue Option
];

// SQL-Query für Trainings mit Filter, Suche und Paginierung
$sql_conditions = [];
$sql_params = [];
$param_types = '';

// Suchbegriff
if (!empty($search_query)) {
    $sql_conditions[] = "(t.training_name LIKE ? OR t.display_id LIKE ? OR mc.name LIKE ? OR sc.name LIKE ?)";
    $search_param = "%{$search_query}%";
    $sql_params[] = $search_param;
    $sql_params[] = $search_param;
    $sql_params[] = $search_param;
    $sql_params[] = $search_param;
    $param_types .= 'ssss';
}

// Filter für Kategorien und Datum
if ($main_category > 0) {
    $sql_conditions[] = "t.main_category_id = ?";
    $sql_params[] = $main_category;
    $param_types .= 'i';
}

if ($sub_category > 0) {
    $sql_conditions[] = "t.sub_category_id = ?";
    $sql_params[] = $sub_category;
    $param_types .= 'i';
}

if (!empty($date_from)) {
    $sql_conditions[] = "t.start_date >= ?";
    $sql_params[] = $date_from;
    $param_types .= 's';
}

if (!empty($date_to)) {
    $sql_conditions[] = "t.end_date <= ?";
    $sql_params[] = $date_to;
    $param_types .= 's';
}

// Neue Filter: Erstelldatum von/bis
if (!empty($created_date_from)) {
    $sql_conditions[] = "DATE(t.created_at) >= ?";
    $sql_params[] = $created_date_from;
    $param_types .= 's';
}

if (!empty($created_date_to)) {
    $sql_conditions[] = "DATE(t.created_at) <= ?";
    $sql_params[] = $created_date_to;
    $param_types .= 's';
}

if ($year > 0) {
    $sql_conditions[] = "YEAR(t.start_date) = ?";
    $sql_params[] = $year;
    $param_types .= 'i';
}

// Filter für Ersteller
if ($creator > 0) {
    $sql_conditions[] = "t.created_by = ?";
    $sql_params[] = $creator;
    $param_types .= 'i';
}

// WHERE-Klausel bauen
$where_clause = !empty($sql_conditions) ? "WHERE " . implode(" AND ", $sql_conditions) : "";

// Sortierung
switch ($sort_by) {
    case 'date_asc':
        $order_by = "t.start_date ASC";
        break;
    case 'name_asc':
        $order_by = "t.training_name ASC";
        break;
    case 'name_desc':
        $order_by = "t.training_name DESC";
        break;
    case 'category_asc':
        $order_by = "mc.name ASC, sc.name ASC";
        break;
    case 'units_desc':
        $order_by = "t.training_units DESC";
        break;
    case 'units_asc':
        $order_by = "t.training_units ASC";
        break;
    case 'created_desc':
        $order_by = "t.created_at DESC"; // Neue Sortierung
        break;
    case 'created_asc':
        $order_by = "t.created_at ASC"; // Neue Sortierung
        break;
    default:
        $order_by = "t.start_date DESC";
        break;
}

// Anzahl der gefilterten Trainings zählen (für Paginierung)
$count_sql = "
    SELECT COUNT(*) as total
    FROM trainings t
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    $where_clause
";

$count_stmt = $conn->prepare($count_sql);
if (!empty($param_types)) {
    $count_stmt->bind_param($param_types, ...$sql_params);
}
$count_stmt->execute();
$total_count_result = $count_stmt->get_result();
$total_count = $total_count_result->fetch_assoc()['total'];
$total_pages = ceil($total_count / $items_per_page);
$count_stmt->close();

// Trainings abrufen
$sql = "
    SELECT 
        t.id,
        t.display_id,
        t.training_name,
        t.start_date,
        t.end_date,
        t.training_units,
        t.created_at,
        mc.name AS main_category_name,
        mc.id AS main_category_id,
        sc.name AS sub_category_name,
        sc.id AS sub_category_id,
        e.name AS created_by,
        e.employee_id AS created_by_id,
        COUNT(et.employee_id) AS participants_count
    FROM trainings t
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    LEFT JOIN employees e ON t.created_by = e.employee_id
    LEFT JOIN employee_training et ON t.id = et.training_id
    $where_clause
    GROUP BY t.id
    ORDER BY $order_by
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$stmt_params = $sql_params;
$stmt_params[] = $items_per_page;
$stmt_params[] = $offset;
$param_types .= 'ii';

$stmt->bind_param($param_types, ...$stmt_params);
$stmt->execute();
$result = $stmt->get_result();
$trainings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Kategorien und Jahre für Filter laden
$main_categories = getActiveMainCategories($conn);
$main_categories_map = array_column($main_categories, 'name', 'id');

// Unterkategorien laden
$sub_categories_stmt = $conn->prepare("
    SELECT id, main_category_id, name
    FROM training_sub_categories
    WHERE active = 1
    ORDER BY main_category_id, name
");
$sub_categories_stmt->execute();
$sub_categories_result = $sub_categories_stmt->get_result();
$all_sub_categories = [];
while ($row = $sub_categories_result->fetch_assoc()) {
    $all_sub_categories[] = $row;
}
$sub_categories_stmt->close();

// Verfügbare Jahre für Filter
$years_stmt = $conn->prepare("
    SELECT DISTINCT YEAR(start_date) AS year
    FROM trainings
    ORDER BY year DESC
");
$years_stmt->execute();
$years_result = $years_stmt->get_result();
$available_years = [];
while ($year_row = $years_result->fetch_assoc()) {
    $available_years[] = $year_row['year'];
}
$years_stmt->close();

// Verfügbare Ersteller für Filter
$creators_stmt = $conn->prepare("
    SELECT DISTINCT e.employee_id, e.name 
    FROM trainings t
    JOIN employees e ON t.created_by = e.employee_id
    ORDER BY e.name ASC
");
$creators_stmt->execute();
$creators_result = $creators_stmt->get_result();
$available_creators = [];
while ($creator_row = $creators_result->fetch_assoc()) {
    $available_creators[] = $creator_row;
}
$creators_stmt->close();

// Statistiken: Anzahl der Trainings pro Hauptkategorie
$category_stats_stmt = $conn->prepare("
    SELECT 
        mc.id,
        mc.name,
        COUNT(t.id) AS training_count
    FROM training_main_categories mc
    LEFT JOIN trainings t ON mc.id = t.main_category_id
    WHERE mc.active = 1
    GROUP BY mc.id
    ORDER BY training_count DESC
");
$category_stats_stmt->execute();
$category_stats_result = $category_stats_stmt->get_result();
$category_stats = $category_stats_result->fetch_all(MYSQLI_ASSOC);
$category_stats_stmt->close();

// Status-Farben für Kategorien (Bootstrap-Farbklassen)
$category_colors = [
    1 => 'danger',   // Rot
    2 => 'success',   // Grün
    3 => 'warning',   // Gelb
    4 => 'indigo',    // Indigo
    5 => 'info',      // Cyan
    6 => 'purple',    // Violett
    7 => 'orange',    // Orange
    8 => 'secondary', // Grau
    9 => 'teal',      // Türkisgrün
    10 => 'primary',   // Blau
];

// Kategorie-Farbe nach ID konsistent zuweisen
foreach ($category_stats as $index => $stat) {
    $cat_id = $stat['id'];
    $color_index = ($cat_id % 10) == 0 ? 10 : ($cat_id % 10);
    $category_stats[$index]['color'] = $category_colors[$color_index];
}

// Hilfsfunktion: Subklasse für Bootstrap-Badges erstellen
function getBadgeClass($color)
{
    // Standard Bootstrap-Farben direkt verwenden
    if (in_array($color, ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'])) {
        return "bg-{$color}";
    }

    // Für andere Farben entsprechende Bootstrap-Klassen oder benutzerdefinierte Klassen
    $color_map = [
        'purple' => 'bg-purple',
        'indigo' => 'bg-indigo',
        'teal' => 'bg-info',
        'orange' => 'bg-warning'
    ];

    return isset($color_map[$color]) ? $color_map[$color] : "bg-secondary";
}

// Aktive Filter zusammenstellen
$active_filters = [];
if (!empty($search_query)) $active_filters[] = ['label' => 'Suche: ' . $search_query, 'param' => 'search'];
if ($main_category > 0) $active_filters[] = ['label' => 'Hauptkategorie: ' . $main_categories_map[$main_category], 'param' => 'main_category'];
if ($sub_category > 0) {
    foreach ($all_sub_categories as $sc) {
        if ($sc['id'] == $sub_category) {
            $active_filters[] = ['label' => 'Unterkategorie: ' . $sc['name'], 'param' => 'sub_category'];
            break;
        }
    }
}
if (!empty($date_from)) $active_filters[] = ['label' => 'Von: ' . date('d.m.Y', strtotime($date_from)), 'param' => 'date_from'];
if (!empty($date_to)) $active_filters[] = ['label' => 'Bis: ' . date('d.m.Y', strtotime($date_to)), 'param' => 'date_to'];
// Neue Filter für Erstelldatum
if (!empty($created_date_from)) $active_filters[] = ['label' => 'Erstellt von: ' . date('d.m.Y', strtotime($created_date_from)), 'param' => 'created_date_from'];
if (!empty($created_date_to)) $active_filters[] = ['label' => 'Erstellt bis: ' . date('d.m.Y', strtotime($created_date_to)), 'param' => 'created_date_to'];
if ($year > 0) $active_filters[] = ['label' => 'Jahr: ' . $year, 'param' => 'year'];
if ($creator > 0) {
    foreach ($available_creators as $c) {
        if ($c['employee_id'] == $creator) {
            $active_filters[] = ['label' => 'Erstellt von: ' . $c['name'], 'param' => 'creator'];
            break;
        }
    }
}

// Hilfsfunktion zum Erstellen von Paginierungslinks
function buildPaginationUrl($page)
{
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weiterbildungen - Übersicht</title>
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .training-category {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            color: white;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
            font-size: 0.75rem;
        }

        .filter-tag .close {
            cursor: pointer;
        }

        .filter-tag .close:hover {
            color: #dc3545;
        }

        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15) !important;
            transition: all .2s ease-in-out;
        }

        .bg-purple {
            background-color: #6f42c1;
        }

        .bg-indigo {
            background-color: #6610f2;
        }

        .bg-teal {
            background-color: #20c997;
        }

        .bg-orange {
            background-color: #fd7e14;
        }

        #filterCollapse {
            transition: all 0.3s ease;
        }

        .stats-pill {
            transition: all 0.2s ease;
        }

        .stats-pill:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">Weiterbildungen - Übersicht</h1>
        <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse"
                data-bs-target="#filterCollapse">
            <i class="bi bi-funnel me-2"></i>Filter anzeigen/ausblenden
        </button>
    </div>

    <!-- Filter Panel -->
    <div class="collapse show bg-light p-4 rounded shadow-sm mb-4" id="filterCollapse">
        <h5 class="border-bottom pb-2 mb-3 text-secondary"><i class="bi bi-funnel me-2"></i> Filter und Suche</h5>
        <form action="training_overview.php" method="GET" id="filterForm">
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="search" class="form-label">Suchbegriff</label>
                    <input type="text" class="form-control" id="search" name="search"
                           value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Name, ID, Kategorie...">
                </div>
                <div class="col-md-3">
                    <label for="main_category" class="form-label">Hauptkategorie</label>
                    <select class="form-select" id="main_category" name="main_category">
                        <option value="0">Alle Hauptkategorien</option>
                        <?php foreach ($main_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($main_category == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="sub_category" class="form-label">Unterkategorie</label>
                    <select class="form-select" id="sub_category" name="sub_category">
                        <option value="0">Alle Unterkategorien</option>
                        <?php if ($main_category > 0): ?>
                            <?php foreach ($all_sub_categories as $sub_cat): ?>
                                <?php if ($sub_cat['main_category_id'] == $main_category): ?>
                                    <option value="<?php echo $sub_cat['id']; ?>" <?php echo ($sub_category == $sub_cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sub_cat['name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="creator" class="form-label">Erstellt von</label>
                    <select class="form-select" id="creator" name="creator">
                        <option value="0">Alle Ersteller</option>
                        <?php foreach ($available_creators as $c): ?>
                            <option value="<?php echo $c['employee_id']; ?>" <?php echo ($creator == $c['employee_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-2">
                    <label for="date_from" class="form-label">Trainingsdatum von</label>
                    <input type="date" class="form-control" id="date_from" name="date_from"
                           value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">Trainingsdatum bis</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-2">
                    <label for="year" class="form-label">Jahr</label>
                    <select class="form-select" id="year" name="year">
                        <option value="0">Alle Jahre</option>
                        <?php foreach ($available_years as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($year == $yr) ? 'selected' : ''; ?>>
                                <?php echo $yr; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="sort_by" class="form-label">Sortieren nach</label>
                    <select class="form-select" id="sort_by" name="sort_by">
                        <?php foreach ($sort_options as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo ($sort_by == $value) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex flex-column">
                    <div class="mt-auto">
                        <div class="d-flex mt-4">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="bi bi-search me-2"></i>Filtern
                            </button>
                            <a href="training_overview.php" class="btn btn-outline-secondary ms-2">
                                <i class="bi bi-x-circle me-1"></i>Zurücksetzen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Aktive Filter anzeigen -->
        <?php if (!empty($active_filters)): ?>
            <div class="mt-3">
                <?php foreach ($active_filters as $filter): ?>
                    <span class="badge bg-light text-dark border me-2 mb-2 filter-tag">
                        <?php echo htmlspecialchars($filter['label']); ?>
                        <span class="close ms-1" data-param="<?php echo $filter['param']; ?>">&times;</span>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <!-- Statistik und Aktionsbereich - Bei mobilen Geräten oben anzeigen -->
        <div class="col-md-3 order-md-2 mb-4">
            <!-- Aktionen -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0 fs-5"><i class="bi bi-lightning-charge me-2"></i> Aktionen</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="trainings.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-2"></i>Neue Weiterbildung anlegen
                        </a>
                        <a href="manage_categories.php" class="btn btn-outline-secondary">
                            <i class="bi bi-folder me-2"></i>Kategorien verwalten
                        </a>
                        <button class="btn btn-outline-info" id="exportCsvBtn">
                            <i class="bi bi-file-earmark-excel me-2"></i>Als CSV exportieren
                        </button>
                    </div>
                </div>
            </div>

            <!-- Schnellfilter für häufige Kategorien -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0 fs-5"><i class="bi bi-filter me-2"></i> Schnellfilter</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap">
                        <?php foreach (array_slice($category_stats, 0, 5) as $stat): ?>
                            <?php if ($stat['training_count'] > 0): ?>
                                <a href="?main_category=<?php echo $stat['id']; ?>"
                                   class="badge me-2 mb-2 stats-pill <?php echo getBadgeClass($stat['color']); ?>">
                                    <?php echo htmlspecialchars($stat['name']); ?>
                                    <span class="badge bg-light text-dark rounded-pill ms-1"><?php echo $stat['training_count']; ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <hr>
                    <div class="d-flex flex-wrap">
                        <?php if (!empty($available_years)): ?>
                            <a href="?year=<?php echo $available_years[0]; ?>"
                               class="badge bg-primary me-2 mb-2 stats-pill">
                                Aktuelles Jahr (<?php echo $available_years[0]; ?>)
                            </a>
                        <?php endif; ?>
                        <a href="?sort_by=date_desc" class="badge bg-secondary me-2 mb-2 stats-pill">
                            Neueste zuerst
                        </a>
                        <a href="?sort_by=created_desc" class="badge bg-info me-2 mb-2 stats-pill">
                            Neueste angelegt
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistiken -->
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h5 class="mb-0 fs-5"><i class="bi bi-bar-chart-line me-2"></i> Statistiken</h5>
                </div>
                <div class="card-body">
                    <!-- Kategorieverteilung -->
                    <h6 class="card-subtitle mb-3">Weiterbildungen nach Kategorie</h6>
                    <?php foreach ($category_stats as $stat): ?>
                        <div class="mb-3 pb-2 border-bottom">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-medium"><?php echo htmlspecialchars($stat['name']); ?></span>
                                <span class="badge bg-light text-dark rounded-pill">
                                    <?php echo $stat['training_count']; ?>
                                </span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <?php
                                $percentage = ($total_count > 0) ? ($stat['training_count'] / $total_count) * 100 : 0;
                                $bg_class = getBadgeClass($stat['color']);
                                ?>
                                <div class="progress-bar <?php echo $bg_class; ?>" role="progressbar"
                                     style="width: <?php echo $percentage; ?>%;"
                                     aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0"
                                     aria-valuemax="100"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Gesamtzahl -->
                    <div class="mt-4">
                        <p><strong>Gesamtzahl:</strong> <?php echo $total_count; ?> Weiterbildungen</p>
                        <?php
                        // Berechne Durchschnitt der Trainingseinheiten
                        $units_sum = 0;
                        foreach ($trainings as $training) {
                            $units_sum += $training['training_units'];
                        }
                        $avg_units = count($trainings) > 0 ? round($units_sum / count($trainings), 1) : 0;
                        ?>
                        <p><strong>Durchschnitt:</strong> <?php echo $avg_units; ?> Einheiten pro Training</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ergebnisse und Paginierung -->
        <div class="col-md-9 order-md-1">
            <?php if (count($trainings) > 0): ?>
                <!-- Ergebnisanzeige und Paginierung oben -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="text-muted small">
                        Zeige <?php echo min($total_count, ($page - 1) * $items_per_page + 1); ?> -
                        <?php echo min($total_count, $page * $items_per_page); ?>
                        von <?php echo $total_count; ?> Weiterbildungen
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Weiterbildungen Navigation">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page - 1); ?>"
                                       aria-label="Vorherige">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo buildPaginationUrl($i); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page + 1); ?>"
                                       aria-label="Nächste">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

                <!-- Trainings als Karten -->
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4 mb-4">
                    <?php foreach ($trainings as $training): ?>
                        <?php
                        $cat_id = $training['main_category_id'];
                        $color_index = ($cat_id % 10) == 0 ? 10 : ($cat_id % 10);
                        $main_cat_color = $category_colors[$color_index];
                        ?>
                        <div class="col">
                            <div class="card h-100 shadow-sm border-0 card-hover">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                    <h5 class="card-title fs-6 text-truncate mb-0"
                                        title="<?php echo htmlspecialchars($training['training_name']); ?>">
                                        <?php echo htmlspecialchars($training['training_name']); ?>
                                    </h5>
                                    <span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($training['display_id'] ?? $training['id']); ?></span>
                                </div>
                                <div class="card-body">
                                    <!-- Kategorien -->
                                    <div class="mb-3">
                                        <a href="?main_category=<?php echo $training['main_category_id']; ?>"
                                           class="text-decoration-none">
                                            <span class="training-category <?php echo getBadgeClass($main_cat_color); ?>">
                                                <?php echo htmlspecialchars($training['main_category_name']); ?>
                                            </span>
                                        </a>
                                        <?php if (!empty($training['sub_category_name'])): ?>
                                            <a href="?sub_category=<?php echo $training['sub_category_id']; ?>"
                                               class="text-decoration-none">
                                                <span class="training-category <?php echo getBadgeClass($main_cat_color); ?> opacity-75">
                                                    <?php echo htmlspecialchars($training['sub_category_name']); ?>
                                                </span>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Metadaten -->
                                    <div class="text-muted small">
                                        <div class="mb-1"><i class="bi bi-calendar-event me-2"></i>
                                            <?php
                                            echo htmlspecialchars(date('d.m.Y', strtotime($training['start_date'])));
                                            if ($training['start_date'] != $training['end_date']) {
                                                echo ' - ' . htmlspecialchars(date('d.m.Y', strtotime($training['end_date'])));
                                            }
                                            ?>
                                        </div>
                                        <div class="mb-1">
                                            <i class="bi bi-clock me-2"></i> <?php echo htmlspecialchars($training['training_units']); ?>
                                            Einheiten
                                        </div>
                                        <div class="mb-1">
                                            <i class="bi bi-people-fill me-2"></i> <?php echo htmlspecialchars($training['participants_count']); ?>
                                            Teilnehmer
                                        </div>
                                        <div class="mb-1">
                                            <i class="bi bi-person me-2"></i>
                                            <a href="?creator=<?php echo $training['created_by_id']; ?>"
                                               class="text-muted text-decoration-none">
                                                Erstellt von: <?php echo htmlspecialchars($training['created_by']); ?>
                                            </a>
                                        </div>
                                        <div>
                                            <i class="bi bi-calendar-check me-2"></i>
                                            <a href="?created_date_from=<?php echo date('Y-m-d', strtotime($training['created_at'])); ?>&created_date_to=<?php echo date('Y-m-d', strtotime($training['created_at'])); ?>"
                                               class="text-muted text-decoration-none">
                                                Erstellt
                                                am: <?php echo date('d.m.Y', strtotime($training['created_at'])); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top">
                                    <div class="d-flex justify-content-between">
                                        <a href="view_training.php?id=<?php echo $training['id']; ?>"
                                           class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-eye me-1"></i> Details
                                        </a>
                                        <a href="edit_training.php?id=<?php echo $training['id']; ?>"
                                           class="btn btn-primary btn-sm">
                                            <i class="bi bi-pencil-square me-1"></i> Bearbeiten
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Paginierung unten -->
                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <nav aria-label="Weiterbildungen Navigation">
                            <ul class="pagination">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page - 1); ?>"
                                       aria-label="Vorherige">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>

                                <?php if ($page > 3): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo buildPaginationUrl(1); ?>">1</a>
                                    </li>
                                    <?php if ($page > 4): ?>
                                        <li class="page-item disabled">
                                            <a class="page-link">...</a>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo buildPaginationUrl($i); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages - 2): ?>
                                    <?php if ($page < $total_pages - 3): ?>
                                        <li class="page-item disabled">
                                            <a class="page-link">...</a>
                                        </li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link"
                                           href="<?php echo buildPaginationUrl($total_pages); ?>"><?php echo $total_pages; ?></a>
                                    </li>
                                <?php endif; ?>

                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page + 1); ?>"
                                       aria-label="Nächste">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Keine Ergebnisse gefunden -->
                <div class="bg-light rounded text-center p-5 mt-3">
                    <i class="bi bi-search display-4 text-muted"></i>
                    <h4 class="mt-3">Keine Weiterbildungen gefunden</h4>
                    <p class="text-muted">Versuche, deine Suchkriterien zu ändern oder setze die Filter zurück.</p>
                    <a href="training_overview.php" class="btn btn-primary mt-2">
                        <i class="bi bi-x-circle me-2"></i>Filter zurücksetzen
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Unterkategorien nach Hauptkategorie filtern
        const mainCategorySelect = document.getElementById('main_category');
        const subCategorySelect = document.getElementById('sub_category');
        const allSubCategories = <?php echo json_encode($all_sub_categories); ?>;

        mainCategorySelect.addEventListener('change', function () {
            const mainCategoryId = this.value;

            // Alle Optionen entfernen
            subCategorySelect.innerHTML = '';

            // Default Option hinzufügen
            const defaultOption = document.createElement('option');
            defaultOption.value = "0";
            defaultOption.text = "Alle Unterkategorien";
            subCategorySelect.appendChild(defaultOption);

            if (mainCategoryId > 0) {
                // Passende Unterkategorien filtern und hinzufügen
                allSubCategories.forEach(function (subCat) {
                    if (subCat.main_category_id == mainCategoryId) {
                        const option = document.createElement('option');
                        option.value = subCat.id;
                        option.text = subCat.name;
                        subCategorySelect.appendChild(option);
                    }
                });
            }
        });

        // Filter-Tags - entfernen einzelner Filter
        document.querySelectorAll('.filter-tag .close').forEach(closeBtn => {
            closeBtn.addEventListener('click', function () {
                const param = this.getAttribute('data-param');
                const url = new URL(window.location.href);
                url.searchParams.delete(param);
                window.location.href = url.toString();
            });
        });

        // Exportfunktion für CSV
        document.getElementById('exportCsvBtn').addEventListener('click', function () {
            // Aktuelle Parameter für den Filter beibehalten
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.append('export', 'csv'); // CSV-Export-Parameter hinzufügen

            // Zur Export-URL navigieren
            window.location.href = 'export_trainings.php?' + currentParams.toString();
        });

        // Tooltips initialisieren
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    });
</script>
</body>
</html>