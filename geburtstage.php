<?php
/**
 * geburtstage.php
 *
 * Diese Seite zeigt eine Liste aller Mitarbeiter, die im aktuellen Monat Geburtstag haben, inklusive ihres Alters,
 * das sie in diesem Jahr erreichen werden. Nur Administratoren oder HR-Mitarbeiter haben Zugriff auf diese Seite.
 */

include 'access_control.php'; // Übernimmt Session-Management und Zugriffskontrolle

global $conn;

pruefe_benutzer_eingeloggt();
pruefe_geburtstagjubilare_zugriff();

// Aktuelles Datum, Monat und Jahr abrufen
$current_month = date('m');
$current_month_name = date('F'); // Name des aktuellen Monats
$current_year = date('Y');

// Deutsche Monatsnamen
$month_names = [
    1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
    5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
];

$current_month_name_de = $month_names[intval($current_month)];

// Prepared Statement zum Abruf aller Mitarbeiter, deren Geburtstag im aktuellen Monat liegt
// Sortierung nach Tag des Geburtstags
$stmt = $conn->prepare("
    SELECT 
        employee_id,
        name, 
        crew, 
        birthdate,
        bild,
        gruppe
    FROM employees
    WHERE MONTH(birthdate) = ?
    ORDER BY DAY(birthdate) ASC
");

if (!$stmt) {
    die("Fehler beim Vorbereiten des Statements: " . $conn->error);
}

$stmt->bind_param("i", $current_month);
$stmt->execute();
$result = $stmt->get_result();

$birthdays = [];
while ($row = $result->fetch_assoc()) {
    // Alter berechnen: aktuelles Alter + 1, weil es das Alter ist, das der Mitarbeiter in diesem Jahr erreicht
    $birthdate = new DateTime($row['birthdate']);
    $birth_day = $birthdate->format('d');
    $birth_month = $birthdate->format('m');

    // Wir berechnen das Alter, das die Person in diesem Jahr erreicht
    $age = $current_year - $birthdate->format('Y');

    // Wenn der Geburtstag in diesem Jahr noch nicht stattgefunden hat, ist das aktuelle Alter noch eins weniger
    $current_age = $age;
    if ($birth_month > date('m') || ($birth_month == date('m') && $birth_day > date('d'))) {
        $current_age--;
    }

    $row['age'] = $age;
    $row['current_age'] = $current_age;
    $row['birth_day'] = $birth_day;

    // Ist der Geburtstag heute?
    $row['is_today'] = ($birth_day == date('d') && $birth_month == date('m'));

    // Ist der Geburtstag in den nächsten 7 Tagen?
    $today = new DateTime();
    $birthday_this_year = new DateTime($current_year . '-' . $birth_month . '-' . $birth_day);

    // Wenn der Geburtstag dieses Jahr schon vorbei ist, aufs nächste Jahr setzen
    if ($birthday_this_year < $today) {
        $birthday_this_year->modify('+1 year');
    }

    $days_until = $today->diff($birthday_this_year)->days;
    $row['upcoming'] = ($days_until <= 7 && $days_until > 0);
    $row['days_until'] = $days_until;

    $birthdays[] = $row;
}

$stmt->close();

// Nach Gruppen sortieren
$schichtarbeit_teams = [];
$tagschicht_birthdays = [];
$verwaltung_birthdays = [];

// Gruppieren nach Gruppe und Team
foreach ($birthdays as $birthday) {
    $gruppe = $birthday['gruppe'];

    if ($gruppe == 'Schichtarbeit') {
        $crew = $birthday['crew'] ?: 'Ohne Team';
        $schichtarbeit_teams[$crew][] = $birthday;
    } elseif ($gruppe == 'Tagschicht') {
        $tagschicht_birthdays[] = $birthday;
    } elseif ($gruppe == 'Verwaltung') {
        $verwaltung_birthdays[] = $birthday;
    }
}

// Anzahl der Geburtstage
$total_birthdays = count($birthdays);

// Icons und Farben für die Gruppen
$gruppe_config = [
    'Schichtarbeit' => [
        'icon' => 'clock-history',
        'color' => '#dc3545' // Rot
    ],
    'Tagschicht' => [
        'icon' => 'sun-fill',
        'color' => '#fd7e14' // Orange
    ],
    'Verwaltung' => [
        'icon' => 'briefcase-fill',
        'color' => '#6610f2' // Lila
    ]
];
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geburtstage im <?php echo $current_month_name_de; ?></title>
    <link href="navbar.css" rel="stylesheet">
    <!-- Lokales Bootstrap 5 CSS -->
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

        .stats-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .stat-card {
            background-color: var(--ball-white);
            border-radius: 10px;
            padding: 1rem;
            min-width: 180px;
            text-align: center;
            box-shadow: 0 4px 8px var(--ball-shadow);
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--ball-blue);
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--ball-charcoal);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--ball-grey);
        }

        .birthdays-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        @media (min-width: 992px) {
            .birthdays-grid {
                grid-template-columns: 1fr 1fr 1fr;
            }
        }

        .gruppe-column {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .gruppe-header {
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

        .gruppe-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .gruppe-count {
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

        .gruppe-content {
            padding: 0;
        }

        .team-header {
            padding: 0.5rem 0.75rem;
            background-color: rgba(0, 0, 0, 0.05);
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .birthday-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .birthday-item {
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 0.7rem;
            position: relative;
        }

        .birthday-item:last-child {
            border-bottom: none;
        }

        .birthday-item.today {
            background-color: rgba(25, 135, 84, 0.1);
        }

        .birthday-item.upcoming {
            background-color: rgba(255, 193, 7, 0.1);
        }

        .birthday-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .birthday-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-size: 150%;
            background-position: center 20%;
            flex-shrink: 0;
            border: 2px solid var(--ball-blue-light);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .birthday-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            flex-shrink: 0;
            border: 2px solid var(--ball-blue-light);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .birthday-info {
            flex-grow: 1;
            min-width: 0;
        }

        .birthday-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .birthday-meta {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .birthday-badge {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.7rem;
            padding: 0.15rem 0.5rem;
            border-radius: 12px;
        }

        .badge-today {
            background-color: #198754;
            color: white;
        }

        .badge-upcoming {
            background-color: #ffc107;
            color: black;
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
        <h1>Geburtstage im <?php echo $current_month_name_de; ?></h1>

        <!-- Statistik-Karten -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-calendar-event"></i>
                </div>
                <div class="stat-value"><?php echo $total_birthdays; ?></div>
                <div class="stat-label">Geburtstage diesen Monat</div>
            </div>
            <?php if (count(array_filter($birthdays, function ($b) {
                    return $b['is_today'];
                })) > 0): ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-stars text-success"></i>
                    </div>
                    <div class="stat-value"><?php echo count(array_filter($birthdays, function ($b) {
                            return $b['is_today'];
                        })); ?></div>
                    <div class="stat-label">Heute feiern</div>
                </div>
            <?php endif; ?>
            <?php if (count(array_filter($birthdays, function ($b) {
                    return $b['upcoming'] && !$b['is_today'];
                })) > 0): ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-hourglass-split text-warning"></i>
                    </div>
                    <div class="stat-value"><?php echo count(array_filter($birthdays, function ($b) {
                            return $b['upcoming'] && !$b['is_today'];
                        })); ?></div>
                    <div class="stat-label">In den nächsten 7 Tagen</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($birthdays)): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h4>Keine Geburtstage im <?php echo $current_month_name_de; ?></h4>
            <p class="text-muted">In diesem Monat stehen keine Geburtstage an.</p>
        </div>
    <?php else: ?>
        <!-- 3-Spalten-Layout für Geburtstage -->
        <div class="birthdays-grid">
            <!-- Spalte 1: Schichtarbeit -->
            <div class="gruppe-column">
                <div class="gruppe-header"
                     style="background-color: <?php echo $gruppe_config['Schichtarbeit']['color']; ?>;">
                    <i class="bi bi-<?php echo $gruppe_config['Schichtarbeit']['icon']; ?>"></i>
                    <h3>Schichtarbeit</h3>

                    <?php
                    $schichtarbeit_count = 0;
                    foreach ($schichtarbeit_teams as $team => $team_birthdays) {
                        $schichtarbeit_count += count($team_birthdays);
                    }
                    ?>
                    <?php if ($schichtarbeit_count > 0): ?>
                        <div class="gruppe-count">
                            <?php echo $schichtarbeit_count; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="gruppe-content">
                    <?php if ($schichtarbeit_count > 0): ?>
                        <?php
                        $teams = ['Team L', 'Team M', 'Team N', 'Team O', 'Team P', 'Ohne Team'];
                        foreach ($teams as $team):
                            if (!empty($schichtarbeit_teams[$team])):
                                ?>
                                <div class="team-section">
                                    <div class="team-header">
                                        <i class="bi bi-people-fill me-1"></i>
                                        <?php echo $team; ?>
                                    </div>
                                    <ul class="birthday-list">
                                        <?php foreach ($schichtarbeit_teams[$team] as $birthday): ?>
                                            <li class="birthday-item <?php echo $birthday['is_today'] ? 'today' : ($birthday['upcoming'] ? 'upcoming' : ''); ?>">
                                                <?php if (!empty($birthday['bild']) && $birthday['bild'] !== 'kein-bild.jpg'): ?>
                                                    <div class="birthday-img"
                                                         style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($birthday['bild']); ?>')"></div>
                                                <?php else: ?>
                                                    <div class="birthday-placeholder">
                                                        <i class="bi bi-person-fill"></i>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="birthday-info">
                                                    <div class="birthday-name">
                                                        <a href="employee_details.php?employee_id=<?php echo $birthday['employee_id']; ?>"
                                                           class="text-decoration-none">
                                                            <?php echo htmlspecialchars($birthday['name']); ?>
                                                        </a>
                                                    </div>
                                                    <div class="birthday-meta">
                                                        <i class="bi bi-calendar3 me-1"></i>
                                                        <?php echo $birthday['birth_day'] . '.' . date('m', strtotime($birthday['birthdate'])); ?>
                                                        (wird <?php echo $birthday['age']; ?>)
                                                    </div>
                                                </div>

                                                <?php if ($birthday['is_today']): ?>
                                                    <span class="birthday-badge badge-today">Heute!</span>
                                                <?php elseif ($birthday['upcoming']): ?>
                                                    <span class="birthday-badge badge-upcoming">
                                                        In <?php echo $birthday['days_until']; ?> Tag<?php echo $birthday['days_until'] > 1 ? 'en' : ''; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <p>Keine Geburtstage in der Schichtarbeit diesen Monat</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Spalte 2: Tagschicht -->
            <div class="gruppe-column">
                <div class="gruppe-header"
                     style="background-color: <?php echo $gruppe_config['Tagschicht']['color']; ?>;">
                    <i class="bi bi-<?php echo $gruppe_config['Tagschicht']['icon']; ?>"></i>
                    <h3>Tagschicht</h3>

                    <?php if (!empty($tagschicht_birthdays)): ?>
                        <div class="gruppe-count">
                            <?php echo count($tagschicht_birthdays); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="gruppe-content">
                    <?php if (!empty($tagschicht_birthdays)): ?>
                        <ul class="birthday-list">
                            <?php foreach ($tagschicht_birthdays as $birthday): ?>
                                <li class="birthday-item <?php echo $birthday['is_today'] ? 'today' : ($birthday['upcoming'] ? 'upcoming' : ''); ?>">
                                    <?php if (!empty($birthday['bild']) && $birthday['bild'] !== 'kein-bild.jpg'): ?>
                                        <div class="birthday-img"
                                             style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($birthday['bild']); ?>')"></div>
                                    <?php else: ?>
                                        <div class="birthday-placeholder">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="birthday-info">
                                        <div class="birthday-name">
                                            <a href="employee_details.php?employee_id=<?php echo $birthday['employee_id']; ?>"
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($birthday['name']); ?>
                                            </a>
                                        </div>
                                        <div class="birthday-meta">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?php echo $birthday['birth_day'] . '.' . date('m', strtotime($birthday['birthdate'])); ?>
                                            (wird <?php echo $birthday['age']; ?>)
                                        </div>
                                    </div>

                                    <?php if ($birthday['is_today']): ?>
                                        <span class="birthday-badge badge-today">Heute!</span>
                                    <?php elseif ($birthday['upcoming']): ?>
                                        <span class="birthday-badge badge-upcoming">
                                            In <?php echo $birthday['days_until']; ?> Tag<?php echo $birthday['days_until'] > 1 ? 'en' : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <p>Keine Geburtstage in der Tagschicht diesen Monat</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Spalte 3: Verwaltung -->
            <div class="gruppe-column">
                <div class="gruppe-header"
                     style="background-color: <?php echo $gruppe_config['Verwaltung']['color']; ?>;">
                    <i class="bi bi-<?php echo $gruppe_config['Verwaltung']['icon']; ?>"></i>
                    <h3>Verwaltung</h3>

                    <?php if (!empty($verwaltung_birthdays)): ?>
                        <div class="gruppe-count">
                            <?php echo count($verwaltung_birthdays); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="gruppe-content">
                    <?php if (!empty($verwaltung_birthdays)): ?>
                        <ul class="birthday-list">
                            <?php foreach ($verwaltung_birthdays as $birthday): ?>
                                <li class="birthday-item <?php echo $birthday['is_today'] ? 'today' : ($birthday['upcoming'] ? 'upcoming' : ''); ?>">
                                    <?php if (!empty($birthday['bild']) && $birthday['bild'] !== 'kein-bild.jpg'): ?>
                                        <div class="birthday-img"
                                             style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($birthday['bild']); ?>')"></div>
                                    <?php else: ?>
                                        <div class="birthday-placeholder">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="birthday-info">
                                        <div class="birthday-name">
                                            <a href="employee_details.php?employee_id=<?php echo $birthday['employee_id']; ?>"
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($birthday['name']); ?>
                                            </a>
                                        </div>
                                        <div class="birthday-meta">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?php echo $birthday['birth_day'] . '.' . date('m', strtotime($birthday['birthdate'])); ?>
                                            (wird <?php echo $birthday['age']; ?>)
                                        </div>
                                    </div>

                                    <?php if ($birthday['is_today']): ?>
                                        <span class="birthday-badge badge-today">Heute!</span>
                                    <?php elseif ($birthday['upcoming']): ?>
                                        <span class="birthday-badge badge-upcoming">
                                            In <?php echo $birthday['days_until']; ?> Tag<?php echo $birthday['days_until'] > 1 ? 'en' : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <p>Keine Geburtstage in der Verwaltung diesen Monat</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Lokales Bootstrap 5 JavaScript Bundle -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>