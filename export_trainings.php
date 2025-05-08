<?php
/**
 * export_trainings.php
 *
 * Exportiert Trainingsdaten als CSV-Datei basierend auf den angewendeten Filtern.
 * Übernimmt die Filtereinstellungen aus der URL (identisch mit training_overview.php).
 */

include 'access_control.php';
include 'training_functions.php';
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_trainings_zugriff();

// Prüfen, ob CSV-Export angefordert wurde
if (!isset($_GET['export']) || $_GET['export'] !== 'csv') {
    header("Location: training_overview.php");
    exit;
}

// Filter-Parameter aus GET-Request auslesen (identisch mit training_overview.php)
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

// SQL-Query für Trainings mit Filter, Suche
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

// Keine Limitierung, alle gefilterten Trainings abrufen
$sql = "
    SELECT 
        t.id,
        t.display_id,
        t.training_name,
        t.start_date,
        t.end_date,
        t.created_at,
        t.main_category_id,
        t.training_units,
        mc.name AS main_category_name,
        sc.name AS sub_category_name,
        e.name AS created_by,
        COUNT(et.employee_id) AS participants_count,
        GROUP_CONCAT(emp.name SEPARATOR ', ') AS participants
    FROM trainings t
    JOIN training_main_categories mc ON t.main_category_id = mc.id
    LEFT JOIN training_sub_categories sc ON t.sub_category_id = sc.id
    LEFT JOIN employees e ON t.created_by = e.employee_id
    LEFT JOIN employee_training et ON t.id = et.training_id
    LEFT JOIN employees emp ON et.employee_id = emp.employee_id
    $where_clause
    GROUP BY t.id
    ORDER BY $order_by
";

$stmt = $conn->prepare($sql);

if (!empty($param_types)) {
    $stmt->bind_param($param_types, ...$sql_params);
}

$stmt->execute();
$result = $stmt->get_result();
$trainings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Trainer für Technical Trainings abrufen
$training_trainers = [];
$technical_training_ids = [];

foreach ($trainings as $training) {
    if ($training['main_category_id'] == 3) { // Technical Trainings (Kategorie 03)
        $technical_training_ids[] = $training['id'];
    }
}

if (!empty($technical_training_ids)) {
    $placeholders = implode(',', array_fill(0, count($technical_training_ids), '?'));
    $trainers_sql = "
        SELECT 
            tt.training_id,
            GROUP_CONCAT(e.name SEPARATOR ', ') AS trainer_names
        FROM training_trainers tt
        JOIN employees e ON tt.trainer_id = e.employee_id
        WHERE tt.training_id IN ($placeholders)
        GROUP BY tt.training_id
    ";

    $trainers_stmt = $conn->prepare($trainers_sql);

    if ($trainers_stmt) {
        $types = str_repeat('i', count($technical_training_ids));
        $trainers_stmt->bind_param($types, ...$technical_training_ids);
        $trainers_stmt->execute();
        $trainers_result = $trainers_stmt->get_result();

        while ($row = $trainers_result->fetch_assoc()) {
            $training_trainers[$row['training_id']] = $row['trainer_names'];
        }

        $trainers_stmt->close();
    }
}

// CSV-Header vorbereiten
$filename = "weiterbildungen_export_" . date("Y-m-d_H-i-s") . ".csv";
$headers = [
    'Training-ID',
    'Hauptkategorie',
    'Unterkategorie',
    'Name',
    'Startdatum',
    'Enddatum',
    'Einheiten',
    'Erstellt von',
    'Erstelldatum',
    'Teilnehmeranzahl',
    'Teilnehmer'
];

// Füge Trainer-Spalte für Technical Trainings hinzu, wenn vorhanden
if (!empty($technical_training_ids)) {
    $headers[] = 'Trainer';
}

// HTTP-Header für CSV-Download setzen
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Ausgabe-Stream öffnen
$output = fopen('php://output', 'w');

// BOM für Excel UTF-8-Erkennung
fprintf($output, "\xEF\xBB\xBF");

// CSV-Header schreiben
fputcsv($output, $headers, ';');

// CSV-Daten schreiben
foreach ($trainings as $training) {
    $row = [
        $training['display_id'],
        $training['main_category_name'],
        $training['sub_category_name'] ?? '',
        $training['training_name'],
        date('d.m.Y', strtotime($training['start_date'])),
        date('d.m.Y', strtotime($training['end_date'])),
        str_replace('.', ',', $training['training_units']), // Für deutsche Excel-Formatierung
        $training['created_by'],
        date('d.m.Y', strtotime($training['created_at'])),
        $training['participants_count'],
        $training['participants'] ?? ''
    ];

    // Füge Trainer hinzu, wenn es sich um ein Technical Training handelt
    if (!empty($technical_training_ids)) {
        $row[] = isset($training_trainers[$training['id']]) ? $training_trainers[$training['id']] : '';
    }

    fputcsv($output, $row, ';');
}

// Stream schließen
fclose($output);
exit;