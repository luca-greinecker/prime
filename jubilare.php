<?php
/**
 * jubilare.php
 *
 * Zeigt eine Liste aller Mitarbeiter, die in einem ausgewählten Jahr (Standard: aktuelles Jahr)
 * ein Jubiläum (5, 10, 15 oder 20 Jahre) haben. Mitarbeiter werden nach Gruppe und Crew sortiert ausgegeben.
 */

include 'access_control.php';
global $conn;

pruefe_benutzer_eingeloggt();
pruefe_geburtstagjubilare_zugriff();

// Standard: aktuelles Jahr
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$current_year = (int)date('Y');

// Zu berücksichtigende Jubiläumsjahre
$jubilaeumsjahre = [5, 10, 15, 20];

// Array zum Sammeln aller Jubilare
$jubilare = [];
$total_jubilare = 0;

// Für jedes Jubiläumsjahr die entsprechenden Mitarbeiter abrufen
foreach ($jubilaeumsjahre as $jahre) {
    $eintrittsjahr = $selected_year - $jahre;

    // Abfrage für Mitarbeiter, die im Eintrittsjahr begonnen haben
    $stmt = $conn->prepare("
        SELECT 
            employee_id,
            name,
            entry_date,
            gruppe,
            crew,
            bild
        FROM employees 
        WHERE YEAR(entry_date) = ?
        ORDER BY 
            CASE 
                WHEN gruppe = 'Tagschicht' THEN 1
                WHEN gruppe = 'Verwaltung' THEN 2
                WHEN gruppe = 'Schichtarbeit' THEN 3
                ELSE 4
            END,
            CASE 
                WHEN gruppe = 'Schichtarbeit' THEN FIELD(crew, 'Team L', 'Team M', 'Team N', 'Team O', 'Team P')
                ELSE 0
            END,
            name ASC
    ");
    if (!$stmt) {
        die("Fehler beim Vorbereiten des Statements: " . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("i", $eintrittsjahr);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // entry_date formatiert zur Übersicht
        $row['entry_date'] = (new DateTime($row['entry_date']))->format('Y-m-d');
        $jubilare[$jahre][] = $row;
        $total_jubilare++;
    }
    $stmt->close();
}

// Icons und Farben für die Jubiläumsjahre
$jubilaeum_config = [
    5 => [
        'icon' => 'award',
        'color' => '#3498db',
        'title' => '5 Jahre'
    ],
    10 => [
        'icon' => 'trophy',
        'color' => '#2ecc71',
        'title' => '10 Jahre'
    ],
    15 => [
        'icon' => 'gem',
        'color' => '#f1c40f',
        'title' => '15 Jahre'
    ],
    20 => [
        'icon' => 'stars',
        'color' => '#9b59b6',
        'title' => '20 Jahre'
    ]
];

// Gruppen-Icons
$gruppe_icons = [
    'Tagschicht' => 'sun-fill',
    'Verwaltung' => 'briefcase-fill',
    'Schichtarbeit' => 'clock-history'
];
?>

    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Jubilare <?php echo $selected_year; ?></title>
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

            .year-selector {
                background-color: white;
                padding: 1rem;
                border-radius: 10px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                margin-bottom: 1.5rem;
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.5rem;
            }

            .jubilare-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.5rem;
            }

            @media (min-width: 992px) {
                .jubilare-grid {
                    grid-template-columns: repeat(4, 1fr);
                }
            }

            .jubilaeum-column {
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }

            .jubilaeum-header {
                padding: 0.75rem;
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                position: sticky;
                top: 0;
                z-index: 10;
            }

            .jubilaeum-header h3 {
                margin: 0;
                font-size: 1.25rem;
                font-weight: 600;
            }

            .jubilaeum-count {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                background-color: rgba(255, 255, 255, 0.3);
                padding: 0.15rem 0.5rem;
                border-radius: 10px;
                font-size: 0.8rem;
                font-weight: 600;
            }

            .jubilaeum-content {
                padding: 0;
            }

            .gruppe-header {
                padding: 0.5rem 0.75rem;
                background-color: rgba(0, 0, 0, 0.05);
                font-size: 0.9rem;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            }

            .team-header {
                padding: 0.4rem 0.75rem 0.4rem 1.5rem;
                font-size: 0.85rem;
                color: #666;
                background-color: rgba(0, 0, 0, 0.02);
                border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            }

            .mitarbeiter-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }

            .mitarbeiter-item {
                padding: 0.6rem 0.75rem;
                border-bottom: 1px solid rgba(0, 0, 0, 0.05);
                display: flex;
                align-items: center;
                gap: 0.7rem;
            }

            .mitarbeiter-item:last-child {
                border-bottom: none;
            }

            .mitarbeiter-item:hover {
                background-color: rgba(0, 0, 0, 0.02);
            }

            .mitarbeiter-img {
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background-size: 150%;
                background-position: center 20%;
                flex-shrink: 0;
                border: 2px solid var(--ball-blue-light);
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

            .mitarbeiter-info {
                flex-grow: 1;
                min-width: 0;
            }

            .mitarbeiter-name {
                font-weight: 600;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                font-size: 0.9rem;
                margin-bottom: 0.2rem;
            }

            .mitarbeiter-date {
                font-size: 0.8rem;
                color: #6c757d;
            }

            .mitarbeiter-actions {
                flex-shrink: 0;
            }

            .no-jubilare {
                padding: 1rem;
                text-align: center;
                color: #6c757d;
                font-style: italic;
            }

            .empty-state {
                height: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 2rem 1rem;
                text-align: center;
                color: #6c757d;
            }

            .empty-state i {
                font-size: 2rem;
                margin-bottom: 0.5rem;
                opacity: 0.5;
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>

    <div class="container content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>Jubilare <?php echo $selected_year; ?></h1>
            <p class="text-muted mt-2 mb-0">Gesamtzahl: <?php echo $total_jubilare; ?> Mitarbeiter</p>
        </div>

        <!-- Jahr-Auswahl Formular -->
        <div class="year-selector">
            <form method="GET" class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                <div class="d-flex align-items-center">
                    <label for="year" class="form-label mb-0 me-2">Jahr:</label>
                    <select name="year" id="year" class="form-select form-select-sm" style="width: auto;">
                        <?php for ($i = (int)date('Y') - 20; $i <= (int)date('Y') + 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php if ($selected_year === $i) echo 'selected'; ?>>
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-search me-1"></i> Anzeigen
                </button>
                <?php if ($selected_year !== $current_year): ?>
                    <a href="jubilare.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-calendar-check me-1"></i> Aktuelles Jahr
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- 4-Spalten-Layout für Jubilare -->
        <div class="jubilare-grid">
            <?php foreach ($jubilaeumsjahre as $jahre): ?>
                <div class="jubilaeum-column">
                    <div class="jubilaeum-header" style="background-color: <?php echo $jubilaeum_config[$jahre]['color']; ?>;">
                        <i class="bi bi-<?php echo $jubilaeum_config[$jahre]['icon']; ?>"></i>
                        <h3><?php echo $jubilaeum_config[$jahre]['title']; ?></h3>

                        <?php if (!empty($jubilare[$jahre])): ?>
                            <div class="jubilaeum-count">
                                <?php echo count($jubilare[$jahre]); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="jubilaeum-content">
                        <?php if (!empty($jubilare[$jahre])): ?>
                            <?php
                            // Gruppieren nach Gruppe
                            $gruppen = ['Tagschicht', 'Verwaltung', 'Schichtarbeit'];

                            foreach ($gruppen as $gruppe):
                                $gruppe_mitarbeiter = array_filter(
                                    $jubilare[$jahre],
                                    function($m) use ($gruppe) {
                                        return $m['gruppe'] === $gruppe;
                                    }
                                );

                                if (!empty($gruppe_mitarbeiter)):
                                    ?>
                                    <div class="gruppe-section">
                                        <div class="gruppe-header">
                                            <i class="bi bi-<?php echo $gruppe_icons[$gruppe]; ?>"></i>
                                            <?php echo $gruppe; ?>
                                        </div>

                                        <?php if ($gruppe === 'Schichtarbeit'): ?>
                                            <?php
                                            $teams = ['Team L', 'Team M', 'Team N', 'Team O', 'Team P'];
                                            foreach ($teams as $team):
                                                $team_mitarbeiter = array_filter(
                                                    $gruppe_mitarbeiter,
                                                    function($m) use ($team) {
                                                        return $m['crew'] === $team;
                                                    }
                                                );

                                                if (!empty($team_mitarbeiter)):
                                                    ?>
                                                    <div class="team-section">
                                                        <div class="team-header">
                                                            <i class="bi bi-people-fill me-1"></i>
                                                            <?php echo $team; ?>
                                                        </div>
                                                        <ul class="mitarbeiter-list">
                                                            <?php foreach ($team_mitarbeiter as $mitarbeiter): ?>
                                                                <li class="mitarbeiter-item">
                                                                    <?php if (!empty($mitarbeiter['bild']) && $mitarbeiter['bild'] !== 'kein-bild.jpg'): ?>
                                                                        <div class="mitarbeiter-img" style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($mitarbeiter['bild']); ?>')"></div>
                                                                    <?php else: ?>
                                                                        <div class="mitarbeiter-placeholder">
                                                                            <i class="bi bi-person-fill"></i>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <div class="mitarbeiter-info">
                                                                        <div class="mitarbeiter-name"><?php echo htmlspecialchars($mitarbeiter['name']); ?></div>
                                                                        <div class="mitarbeiter-date"><?php echo date("d.m.Y", strtotime($mitarbeiter['entry_date'])); ?></div>
                                                                    </div>

                                                                    <div class="mitarbeiter-actions">
                                                                        <a href="employee_details.php?employee_id=<?php echo $mitarbeiter['employee_id']; ?>" class="btn btn-sm btn-outline-secondary btn-icon" title="Details anzeigen">
                                                                            <i class="bi bi-eye-fill"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <ul class="mitarbeiter-list">
                                                <?php foreach ($gruppe_mitarbeiter as $mitarbeiter): ?>
                                                    <li class="mitarbeiter-item">
                                                        <?php if (!empty($mitarbeiter['bild']) && $mitarbeiter['bild'] !== 'kein-bild.jpg'): ?>
                                                            <div class="mitarbeiter-img" style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($mitarbeiter['bild']); ?>')"></div>
                                                        <?php else: ?>
                                                            <div class="mitarbeiter-placeholder">
                                                                <i class="bi bi-person-fill"></i>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="mitarbeiter-info">
                                                            <div class="mitarbeiter-name"><?php echo htmlspecialchars($mitarbeiter['name']); ?></div>
                                                            <div class="mitarbeiter-date"><?php echo date("d.m.Y", strtotime($mitarbeiter['entry_date'])); ?></div>
                                                        </div>

                                                        <div class="mitarbeiter-actions">
                                                            <a href="employee_details.php?employee_id=<?php echo $mitarbeiter['employee_id']; ?>" class="btn btn-sm btn-outline-secondary btn-icon" title="Details anzeigen">
                                                                <i class="bi bi-eye-fill"></i>
                                                            </a>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <p>Keine Mitarbeiter mit <?php echo $jahre; ?>-jährigem Jubiläum in <?php echo $selected_year; ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Lokales Bootstrap 5 JavaScript Bundle -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>

<?php
$conn->close();
?>