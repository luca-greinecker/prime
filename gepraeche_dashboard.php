<?php
// hr_dashboard.php
include_once 'access_control.php';
global $conn;

// Zugriff nur für eingeloggte Admins oder HR
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// Helper-Funktionen laden
include_once 'dashboard_helpers.php';


/**
 * Erzeugt eine "sichere" ID für Tabs (z. B. ersetzt "/" und Leerzeichen).
 */
if (!function_exists('safeTabId')) {
    function safeTabId(string $groupKey): string
    {
        return strtolower(str_replace(['/', ' '], '', $groupKey));
    }
}

/**
 * Liefert die Subkategorie für CPO/QS: gibt "CPO", "QS" oder "Sortierung" zurück,
 * je nachdem, was in der Positionsbezeichnung enthalten ist.
 */
if (!function_exists('getCpoqsSubcategory')) {
    function getCpoqsSubcategory($position): string
    {
        $posLower = mb_strtolower($position);
        if (strpos($posLower, 'cpo') !== false) {
            return 'CPO';
        } elseif (strpos($posLower, 'qualitätssicherung') !== false) {
            return 'QS';
        } elseif (strpos($posLower, 'sortierung') !== false) {
            return 'Sortierung';
        }
        return $position ?: 'Keine Angabe';
    }
}

/**
 * Sortiert ein Array von Zeilen aus dem Technik-Bereich so, dass zuerst "Elektrik" und dann "Mechanik" erscheinen.
 */
if (!function_exists('sortTechnikRows')) {
    function sortTechnikRows(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $aPos = bereinigePosition($a['position'] ?? '');
            $bPos = bereinigePosition($b['position'] ?? '');
            $order = ['Elektrik', 'Mechanik'];
            $aIndex = array_search($aPos, $order);
            $bIndex = array_search($bPos, $order);
            if ($aIndex === false) {
                $aIndex = count($order);
            }
            if ($bIndex === false) {
                $bIndex = count($order);
            }
            if ($aIndex === $bIndex) {
                return strcmp($a['mitarbeiter_name'], $b['mitarbeiter_name']);
            }
            return $aIndex - $bIndex;
        });
        return $rows;
    }
}

/**
 * Sortiert ein Array von Zeilen aus dem CPO/QS-Bereich so, dass zuerst "CPO", dann "QS" und dann "Sortierung" erscheint.
 */
if (!function_exists('sortCpoqsRows')) {
    function sortCpoqsRows(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $order = ['CPO' => 1, 'QS' => 2, 'Sortierung' => 3];
            $aSub = getCpoqsSubcategory($a['position'] ?? '');
            $bSub = getCpoqsSubcategory($b['position'] ?? '');
            $aOrder = $order[$aSub] ?? 4;
            $bOrder = $order[$bSub] ?? 4;
            if ($aOrder === $bOrder) {
                return strcmp($a['mitarbeiter_name'], $b['mitarbeiter_name']);
            }
            return $aOrder - $bOrder;
        });
        return $rows;
    }
}

// ------------------------------
// Jahr und Review-Zeitraum ermitteln
// ------------------------------
$sql_years = "SELECT year FROM review_periods ORDER BY year DESC";
$result = $conn->query($sql_years);
$years = [];
while ($y = $result->fetch_assoc()) {
    $years[] = (int)$y['year'];
}

$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
if (!in_array($selectedYear, $years, true)) {
    $selectedYear = !empty($years) ? $years[0] : $currentYear;
}

$reviewPeriod = getReviewPeriodForYear($conn, $selectedYear);
$start_date = $reviewPeriod['start_date'];
$end_date = $reviewPeriod['end_date'];

// ------------------------------
// Alle Mitarbeitergespräche im Zeitraum abrufen
// ------------------------------
$query = "
    SELECT e.employee_id, e.name AS mitarbeiter_name, e.lohnschema, e.position, e.crew, 
           er.date, er.tr_date, er.zufriedenheit, er.unzufriedenheit_grund,
           er.tr_talent, er.tr_performance_assessment, er.tr_salary_increase_argumentation,
           er.tr_pr_anfangslohn, er.tr_pr_grundlohn, er.tr_pr_qualifikationsbonus, er.tr_pr_expertenbonus,
           er.tr_tk_qualifikationsbonus_1, er.tr_tk_qualifikationsbonus_2, er.tr_tk_qualifikationsbonus_3, er.tr_tk_qualifikationsbonus_4
    FROM employee_reviews er
    JOIN employees e ON er.employee_id = e.employee_id
    WHERE er.date BETWEEN ? AND ?
";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result_all = $stmt->get_result();
$all_reviews = [];
while ($row = $result_all->fetch_assoc()) {
    // Kategorie ermitteln – später zur Gruppierung genutzt
    $row['category'] = getMitarbeiterKategorie($row);
    $all_reviews[] = $row;
}
$stmt->close();

// Gruppierung in die Bereiche "Gesamt", "Technik", "CPO/QS" und "Produktion"
$dataGroups = [
    'Gesamt' => $all_reviews,
    'Technik' => [],
    'CPO/QS' => [],
    'Produktion' => []
];
foreach ($all_reviews as $review) {
    if (isset($dataGroups[$review['category']])) {
        $dataGroups[$review['category']][] = $review;
    }
}

// ------------------------------
// Kennzahlen für Diagramme berechnen
// ------------------------------
$chartData = [];
foreach ($dataGroups as $groupName => $reviews) {
    $total = count($reviews);
    $countGespräche = count($reviews);
    $countTalentReviews = 0;
    $zufrieden = 0;
    $grundsZufrieden = 0;
    $unzufrieden = 0;
    $unzufReason = [
        'arbeitsbedingungen' => 0,
        'entwicklung' => 0,
        'klima' => 0,
        'persoenlich' => 0,
    ];
    $talentCount = 0;
    $performanceCount = 0;

    foreach ($reviews as $r) {
        if (!empty($r['tr_date'])) {
            $countTalentReviews++;
        }
        if ($r['zufriedenheit'] === 'Zufrieden') {
            $zufrieden++;
        } elseif ($r['zufriedenheit'] === 'Grundsätzlich zufrieden') {
            $grundsZufrieden++;
        } elseif ($r['zufriedenheit'] === 'Unzufrieden') {
            $unzufrieden++;
            $grund = strtolower($r['unzufriedenheit_grund'] ?? '');
            if (strpos($grund, 'arbeitsbedingungen') !== false) {
                $unzufReason['arbeitsbedingungen']++;
            }
            if (strpos($grund, 'entwicklung') !== false) {
                $unzufReason['entwicklung']++;
            }
            if (strpos($grund, 'klima') !== false) {
                $unzufReason['klima']++;
            }
            if (strpos($grund, 'persönlich') !== false) {
                $unzufReason['persoenlich']++;
            }
        }
        if (isset($r['tr_talent']) && in_array($r['tr_talent'], ['Aufstrebendes Talent', 'Fertiges Talent'])) {
            $talentCount++;
        }
        if (isset($r['tr_performance_assessment']) && in_array($r['tr_performance_assessment'], ['überdurchschnittlich', 'Entwicklung'])) {
            $performanceCount++;
        }
    }
    $chartData[$groupName] = [
        'total' => $total,
        'gespraeche' => $countGespräche,
        'talent_reviews' => $countTalentReviews,
        'zufrieden' => $zufrieden,
        'grunds_zufrieden' => $grundsZufrieden,
        'unzufrieden' => $unzufrieden,
        'unzuf_reason' => $unzufReason,
        'talent_count' => $talentCount,
        'performance_count' => $performanceCount
    ];
}

// ------------------------------
// Talent- und Leistungsverteilung (alle Werte zählen) für Bar-Charts
// ------------------------------
$talentDistribution = [];
$performanceDistribution = [];
foreach ($dataGroups as $groupName => $reviews) {
    $talentDist = [];
    $performanceDist = [];
    foreach ($reviews as $r) {
        if (!empty($r['tr_talent'])) {
            $talentDist[$r['tr_talent']] = ($talentDist[$r['tr_talent']] ?? 0) + 1;
        }
        if (!empty($r['tr_performance_assessment'])) {
            $performanceDist[$r['tr_performance_assessment']] = ($performanceDist[$r['tr_performance_assessment']] ?? 0) + 1;
        }
    }
    $talentDistribution[$groupName] = $talentDist;
    $performanceDistribution[$groupName] = $performanceDist;
}

// ------------------------------
// Detailabfragen via Helper-Funktionen (Listen für Lohnerhöhungen, Weiterbildungen, besondere Leistungen, Talents)
// ------------------------------
$lohnerhoehungenResult = holeLohnerhoehungenHR($conn, $start_date, $end_date);
$weiterbildungenResult = holeWeiterbildungenHR($conn, $start_date, $end_date);
$besLeistungenResult = holeBesondereLeistungenHR($conn, $start_date, $end_date);
$talentsResult = holeTalentsHR($conn, $start_date, $end_date);

/**
 * Gruppiert ein mysqli_result anhand der Kategorie.
 */
function groupByCategory($result)
{
    $grouped = [
        'Gesamt' => [],
        'Technik' => [],
        'CPO/QS' => [],
        'Produktion' => []
    ];
    while ($row = $result->fetch_assoc()) {
        $cat = getMitarbeiterKategorie($row);
        $grouped['Gesamt'][] = $row;
        if (isset($grouped[$cat])) {
            $grouped[$cat][] = $row;
        }
    }
    return $grouped;
}

$lohnerhoehungenGrouped = groupByCategory($lohnerhoehungenResult);
$weiterbildungenGrouped = groupByCategory($weiterbildungenResult);
$besLeistungenGrouped = groupByCategory($besLeistungenResult);
$talentsGrouped = groupByCategory($talentsResult);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gespräche-Dashboard</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/fontawesome/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .nowrap {
            white-space: nowrap;
        }

        /* Zusätzliche Design-Ideen */
        .card {
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
            margin-bottom: 20px;
        }

        .card-header {
            font-weight: bold;
            background-color: #f8f9fa;
            text-align: center;
        }

        .nav-link {
            color: black !important;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container content">
    <div class="bg-primary text-white py-4 rounded mb-4 text-center">
        <h1 class="mb-3 fw-bold">Gespräche-Dashboard – Gesprächsjahr <?php echo $selectedYear; ?></h1>
        <form method="get" class="d-flex justify-content-center align-items-center">
            <select name="year" id="year" class="form-select w-auto me-2 bg-white text-dark" aria-label="Jahr auswählen">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php if($y === $selectedYear) echo 'selected'; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-light">Anzeigen</button>
        </form>
    </div>

    <!-- Bootstrap Tabs -->
    <ul class="nav nav-tabs" id="dashboardTabs" role="tablist">
        <?php
        $tabs = ['Gesamt', 'Technik', 'CPO/QS', 'Produktion'];
        foreach ($tabs as $tab):
            $tabId = safeTabId($tab);
            ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php if ($tab === 'Gesamt') echo 'active'; ?>" id="<?php echo $tabId; ?>-tab"
                        data-bs-toggle="tab" data-bs-target="#<?php echo $tabId; ?>" type="button" role="tab"
                        aria-controls="<?php echo $tabId; ?>"
                        aria-selected="<?php echo($tab === 'Gesamt' ? 'true' : 'false'); ?>">
                    <?php echo($tab === 'CPO/QS' ? 'Übersicht CPO/QS' : 'Übersicht ' . $tab); ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content" id="dashboardTabsContent">
        <?php
        /**
         * Rendert den Inhalt eines Tabs für eine gegebene Kategorie.
         * In "Gesamt" werden nur Diagramme angezeigt.
         * In den anderen Gruppen erscheinen zusätzlich Listen.
         * Im Bereich "Technik" werden in den Tabellen die Zeilen nach bereinigter Position (Elektrik vor Mechanik) sortiert.
         * Im Bereich "CPO/QS" werden in den Lohnerhöhungen und Weiterbildungen zuerst die Zeilen mit Subkategorie "CPO", dann "QS", dann "Sortierung" sortiert.
         * Zusätzlich wird in den Lohnerhöhungen-Tabellen eine Spalte "Leistungsbeurteilung" und in den Weiterbildungen-Tabellen eine Spalte "Talentstatus" ausgegeben.
         */
        function renderTabContent($groupKey, $chartData, $talentDistribution, $performanceDistribution, $lohnerhoehungenGrouped, $weiterbildungenGrouped, $besLeistungenGrouped, $talentsGrouped)
        {
            // Für Technik und CPO/QS werden die entsprechenden Listen sortiert
            if ($groupKey === 'Technik') {
                if (!empty($lohnerhoehungenGrouped[$groupKey])) {
                    $lohnerhoehungenGrouped[$groupKey] = sortTechnikRows($lohnerhoehungenGrouped[$groupKey]);
                }
                if (!empty($weiterbildungenGrouped[$groupKey])) {
                    $weiterbildungenGrouped[$groupKey] = sortTechnikRows($weiterbildungenGrouped[$groupKey]);
                }
                if (!empty($besLeistungenGrouped[$groupKey])) {
                    $besLeistungenGrouped[$groupKey] = sortTechnikRows($besLeistungenGrouped[$groupKey]);
                }
                if (!empty($talentsGrouped[$groupKey])) {
                    $talentsGrouped[$groupKey] = sortTechnikRows($talentsGrouped[$groupKey]);
                }
            } elseif ($groupKey === 'CPO/QS') {
                if (!empty($lohnerhoehungenGrouped[$groupKey])) {
                    $lohnerhoehungenGrouped[$groupKey] = sortCpoqsRows($lohnerhoehungenGrouped[$groupKey]);
                }
                if (!empty($weiterbildungenGrouped[$groupKey])) {
                    $weiterbildungenGrouped[$groupKey] = sortCpoqsRows($weiterbildungenGrouped[$groupKey]);
                }
                if (!empty($besLeistungenGrouped[$groupKey])) {
                    $besLeistungenGrouped[$groupKey] = sortCpoqsRows($besLeistungenGrouped[$groupKey]);
                }
                if (!empty($talentsGrouped[$groupKey])) {
                    $talentsGrouped[$groupKey] = sortCpoqsRows($talentsGrouped[$groupKey]);
                }
            }
            ?>
            <div class="container mt-3">
<!--
<h2 class="text-center"><?php /*echo $groupKey; */?> Übersicht</h2>
-->                <div class="row">
                    <!-- Mitarbeiterzufriedenheit Diagramm (Doughnut) -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header"><strong>Mitarbeiterzufriedenheit - <?php echo $groupKey; ?></strong></div>
                            <div class="card-body">
                                <canvas id="zufriedenheitChart_<?php echo safeTabId($groupKey); ?>"></canvas>
                            </div>
                        </div>
                    </div>
                    <!-- Gründe Unzufriedenheit Diagramm (Doughnut) -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header"><strong>Gründe für Unzufriedenheit - <?php echo $groupKey; ?></strong></div>
                            <div class="card-body">
                                <canvas id="unzufriedenheitChart_<?php echo safeTabId($groupKey); ?>"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <!-- Talent Übersicht Diagramm (Bar Chart) -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header"><strong>Talentübersicht - <?php echo $groupKey; ?></strong></div>
                            <div class="card-body">
                                <canvas id="talentChart_<?php echo safeTabId($groupKey); ?>"></canvas>
                            </div>
                        </div>
                    </div>
                    <!-- Leistungsübersicht Diagramm (Bar Chart) -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header"><strong>Leistungsübersicht - <?php echo $groupKey; ?></strong></div>
                            <div class="card-body">
                                <canvas id="leistungChart_<?php echo safeTabId($groupKey); ?>"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($groupKey !== 'Gesamt'): ?>
                    <!-- Lohnerhöhungen (volle Breite) -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header"><strong>Lohnerhöhungen – <?php echo $groupKey; ?></strong>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($lohnerhoehungenGrouped[$groupKey])):
                                        if ($groupKey === 'Technik') {
                                            $sortedLohnerhoehungen = $lohnerhoehungenGrouped[$groupKey];
                                        } elseif ($groupKey === 'CPO/QS') {
                                            $sortedLohnerhoehungen = $lohnerhoehungenGrouped[$groupKey];
                                        } else {
                                            $sortedLohnerhoehungen = $lohnerhoehungenGrouped[$groupKey];
                                        }
                                        ?>
                                        <table class="table table-bordered">
                                            <thead>
                                            <tr>
                                                <th>Mitarbeiter</th>
                                                <th>Crew</th>
                                                <th>Lohnart</th>
                                                <th>Argumentation</th>
                                                <th>Leistungsbeurteilung</th>
                                                <th>Führungskraft</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($sortedLohnerhoehungen as $row): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['mitarbeiter_name']); ?></td>
                                                    <td class="nowrap">
                                                        <?php
                                                        if ($groupKey === 'Technik') {
                                                            echo htmlspecialchars(bereinigePosition($row['position'] ?? 'Keine Angabe'));
                                                        } elseif ($groupKey === 'CPO/QS') {
                                                            echo htmlspecialchars(getCpoqsSubcategory($row['position'] ?? 'Keine Angabe'));
                                                        } else {
                                                            echo htmlspecialchars($row['crew'] ?? 'Keine Angabe');
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['lohnart'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['tr_salary_increase_argumentation'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['tr_performance_assessment'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['fuehrungskraft_name'] ?? 'Keine Angabe'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="text-center text-muted">Keine Lohnerhöhungen.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Weiterbildungen (volle Breite) -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header"><strong>Weiterbildungen – <?php echo $groupKey; ?></strong>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($weiterbildungenGrouped[$groupKey])):
                                        if ($groupKey === 'Technik') {
                                            $sortedWeiterbildungen = $weiterbildungenGrouped[$groupKey];
                                        } elseif ($groupKey === 'CPO/QS') {
                                            $sortedWeiterbildungen = $weiterbildungenGrouped[$groupKey];
                                        } else {
                                            $sortedWeiterbildungen = $weiterbildungenGrouped[$groupKey];
                                        }
                                        ?>
                                        <table class="table table-bordered">
                                            <thead>
                                            <tr>
                                                <th>Mitarbeiter</th>
                                                <th>Crew</th>
                                                <th>Typ</th>
                                                <th>Weiterbildung</th>
                                                <th>Talentstatus</th>
                                                <th>Führungskraft</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($sortedWeiterbildungen as $row): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['mitarbeiter_name']); ?></td>
                                                    <td class="nowrap">
                                                        <?php
                                                        if ($groupKey === 'Technik') {
                                                            echo htmlspecialchars(bereinigePosition($row['position'] ?? 'Keine Angabe'));
                                                        } elseif ($groupKey === 'CPO/QS') {
                                                            echo htmlspecialchars(getCpoqsSubcategory($row['position'] ?? 'Keine Angabe'));
                                                        } else {
                                                            echo htmlspecialchars($row['crew'] ?? 'Keine Angabe');
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['typ'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['weiterbildung'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['tr_talent'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['fuehrungskraft_name'] ?? 'Keine Angabe'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="text-center text-muted">Keine Weiterbildungen.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Besondere Leistungen und Talents (nebeneinander) -->
                    <div class="row">
                        <!-- Besondere Leistungen -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header"><strong>Besondere Leistungen
                                        – <?php echo $groupKey; ?></strong></div>
                                <div class="card-body">
                                    <?php if (!empty($besLeistungenGrouped[$groupKey])): ?>
                                        <table class="table table-bordered">
                                            <thead>
                                            <tr>
                                                <th>Mitarbeiter</th>
                                                <th>Crew</th>
                                                <th>Leistungsbeurteilung</th>
                                                <th>Führungskraft</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($besLeistungenGrouped[$groupKey] as $row): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['mitarbeiter_name']); ?></td>
                                                    <td class="nowrap">
                                                        <?php
                                                        if ($groupKey === 'Technik') {
                                                            echo htmlspecialchars(bereinigePosition($row['position'] ?? 'Keine Angabe'));
                                                        } else {
                                                            echo htmlspecialchars($row['crew'] ?? 'Keine Angabe');
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['tr_performance_assessment'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['fuehrungskraft_name'] ?? 'Keine Angabe'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="text-center text-muted">Keine besonderen Leistungen.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Talents -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header"><strong>Talents – <?php echo $groupKey; ?></strong></div>
                                <div class="card-body">
                                    <?php if (!empty($talentsGrouped[$groupKey])): ?>
                                        <table class="table table-bordered">
                                            <thead>
                                            <tr>
                                                <th>Mitarbeiter</th>
                                                <th>Crew</th>
                                                <th>Talent Status</th>
                                                <th>Führungskraft</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($talentsGrouped[$groupKey] as $row): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($row['mitarbeiter_name']); ?></td>
                                                    <td class="nowrap">
                                                        <?php
                                                        if ($groupKey === 'Technik') {
                                                            echo htmlspecialchars(bereinigePosition($row['position'] ?? 'Keine Angabe'));
                                                        } else {
                                                            echo htmlspecialchars($row['crew'] ?? 'Keine Angabe');
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['tr_talent'] ?? 'Keine Angabe'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['fuehrungskraft_name'] ?? 'Keine Angabe'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <p class="text-center text-muted">Keine Talents.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Diagramm-Skripte für diese Gruppe -->
            <script>
                (function () {
                    // Mitarbeiterzufriedenheit (Doughnut)
                    var ctxZuf = document.getElementById('zufriedenheitChart_<?php echo safeTabId($groupKey); ?>').getContext('2d');
                    new Chart(ctxZuf, {
                        type: 'doughnut',
                        data: {
                            labels: ['Zufrieden', 'Grundsätzlich zufrieden', 'Unzufrieden'],
                            datasets: [{
                                data: [<?php echo $chartData[$groupKey]['zufrieden']; ?>, <?php echo $chartData[$groupKey]['grunds_zufrieden']; ?>, <?php echo $chartData[$groupKey]['unzufrieden']; ?>],
                                backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                            }]
                        }
                    });

                    // Gründe Unzufriedenheit (Doughnut)
                    var ctxUnzuf = document.getElementById('unzufriedenheitChart_<?php echo safeTabId($groupKey); ?>').getContext('2d');
                    new Chart(ctxUnzuf, {
                        type: 'doughnut',
                        data: {
                            labels: ['Arbeitsbedingungen', 'Fehlende Entwicklungsmöglichkeiten', 'Arbeitsklima', 'Persönliche Themen'],
                            datasets: [{
                                data: [
                                    <?php echo $chartData[$groupKey]['unzuf_reason']['arbeitsbedingungen']; ?>,
                                    <?php echo $chartData[$groupKey]['unzuf_reason']['entwicklung']; ?>,
                                    <?php echo $chartData[$groupKey]['unzuf_reason']['klima']; ?>,
                                    <?php echo $chartData[$groupKey]['unzuf_reason']['persoenlich']; ?>
                                ],
                                backgroundColor: ['#17a2b8', '#6f42c1', '#20c997', '#e83e8c']
                            }]
                        }
                    });

                    // Talent Übersicht (Bar Chart) – alle Talentwerte
                    var ctxTalent = document.getElementById('talentChart_<?php echo safeTabId($groupKey); ?>').getContext('2d');
                    new Chart(ctxTalent, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode(array_keys($talentDistribution[$groupKey])); ?>,
                            datasets: [{
                                label: 'Anzahl Talents',
                                data: <?php echo json_encode(array_values($talentDistribution[$groupKey])); ?>,
                                backgroundColor: '#007bff'
                            }]
                        },
                        options: {
                            responsive: true,
                            scales: {y: {beginAtZero: true}}
                        }
                    });

                    // Leistungsübersicht (Bar Chart) – alle Bewertungen
                    var ctxLeistung = document.getElementById('leistungChart_<?php echo safeTabId($groupKey); ?>').getContext('2d');
                    new Chart(ctxLeistung, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode(array_keys($performanceDistribution[$groupKey])); ?>,
                            datasets: [{
                                label: 'Anzahl Leistungsbewertungen',
                                data: <?php echo json_encode(array_values($performanceDistribution[$groupKey])); ?>,
                                backgroundColor: '#ffc107'
                            }]
                        },
                        options: {
                            responsive: true,
                            scales: {y: {beginAtZero: true}}
                        }
                    });
                })();
            </script>
            <?php
        }

        foreach ($tabs as $tab):
            $tabId = safeTabId($tab);
            ?>
            <div class="tab-pane fade <?php if ($tab === 'Gesamt') echo 'show active'; ?>" id="<?php echo $tabId; ?>"
                 role="tabpanel" aria-labelledby="<?php echo $tabId; ?>-tab">
                <?php renderTabContent($tab, $chartData, $talentDistribution, $performanceDistribution, $lohnerhoehungenGrouped, $weiterbildungenGrouped, $besLeistungenGrouped, $talentsGrouped); ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
