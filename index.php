<?php
/**
 * index.php
 *
 * Startseite/Dashboard für eingeloggte Benutzer.
 * Zeigt neben einer zufällig ausgewählten Begrüßung und einem Headerbild
 * auch die neueste aktive Nachricht (News) an sowie weitere Informationen,
 * die z. B. für Führungskräfte von Interesse sind.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Benutzerinformationen (Position und Name) aus der Tabelle employees abrufen
$mitarbeiter_id = $_SESSION['mitarbeiter_id'];
$stmt = $conn->prepare("SELECT position, name FROM employees WHERE employee_id = ?");
if (!$stmt) {
    die("Fehler bei der Vorbereitung des Statements: " . $conn->error);
}
$stmt->bind_param("i", $mitarbeiter_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$position = $user['position'] ?? '';
$fullname = $user['name'] ?? '';

// Prüfen, ob der Benutzer Admin ist (via Session)
$ist_admin = isset($_SESSION['ist_admin']) && $_SESSION['ist_admin'];

// Username, Standardfall 'Gast'
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Gast';

// Zugriff auf den Gesprächsbereich "Führung" – falls nicht Admin, wird geprüft
$has_access_to_fuehrung = false;
if (!$ist_admin) {
    $zugriff_stmt = $conn->prepare("
        SELECT 1 
        FROM position_zu_gespraechsbereich 
        WHERE position_id = (SELECT id FROM positionen WHERE name = ?) 
          AND gesprächsbereich_id = (SELECT id FROM gesprächsbereiche WHERE name = 'Führung')
    ");
    if ($zugriff_stmt) {
        $zugriff_stmt->bind_param("s", $position);
        $zugriff_stmt->execute();
        $zugriff_result = $zugriff_stmt->get_result();
        $has_access_to_fuehrung = $zugriff_result->num_rows > 0;
        $zugriff_stmt->close();
    }
}

// Zufälliges Headerbild auswählen
$header_images = ['assets/bilder/header-1.png', 'assets/bilder/header-2.png', 'assets/bilder/header-3.png'];
$selected_image = $header_images[array_rand($header_images)];

// Begrüßungs-Array in den 10 meistgenutzten Sprachen in Österreich
$greetings = [
    'Deutsch' => [
        'allgemein' => ['Hallo', 'Servus', 'Habidere', 'Grüaß di'],
        'morgen' => ['Guten Morgen', 'Moin'],
        'abend' => ['Guten Abend', 'Grüß Gott', 'An schöna Obad']
    ],
    'Türkisch' => [
        'allgemein' => ['Merhaba', 'Selam'],
        'morgen' => ['Günaydın', 'Hayırlı sabahlar'],
        'abend' => ['İyi akşamlar', 'Hayırlı akşamlar']
    ],
    'Bosnisch/Kroatisch/Serbisch' => [
        'allgemein' => ['Zdravo', 'Bok', 'Ćao'],
        'morgen' => ['Dobro jutro', 'Jutro dobro'],
        'abend' => ['Dobra večer', 'Laku noć']
    ],
    'Englisch' => [
        'allgemein' => ['Hello', 'Hi', 'Hey'],
        'morgen' => ['Good Morning', 'Morning'],
        'abend' => ['Good Evening', 'Good Night']
    ],
    'Ungarisch' => [
        'allgemein' => ['Helló', 'Szia'],
        'morgen' => ['Jó reggelt', 'Szép napot'],
        'abend' => ['Jó estét', 'Szép estét']
    ],
    'Polnisch' => [
        'allgemein' => ['Cześć', 'Hej'],
        'morgen' => ['Dzień dobry', 'Miłego dnia'],
        'abend' => ['Dobry wieczór', 'Spokojnego wieczoru']
    ],
    'Rumänisch' => [
        'allgemein' => ['Salut', 'Bună', 'Hei'],
        'morgen' => ['Bună dimineața', 'O zi bună'],
        'abend' => ['Bună seara', 'Noapte bună']
    ],
    'Albanisch' => [
        'allgemein' => ['Përshëndetje', 'Tungjatjeta'],
        'morgen' => ['Mirëmëngjes', 'Ditë e mirë'],
        'abend' => ['Mirëmbrëma', 'Natë e mirë']
    ],
    'Arabisch' => [
        'allgemein' => ['مرحبا', 'أهلا'],
        'morgen' => ['صباح الخير'],
        'abend' => ['مساء الخير']
    ],
    'Tschechisch/Slowakisch' => [
        'allgemein' => ['Ahoj', 'Čau'],
        'morgen' => ['Dobré ráno', 'Krásné ráno'],
        'abend' => ['Dobrý večer', 'Dobrou noc']
    ]
];

// Aktuelle Stunde ermitteln
$currentHour = (int)date('H');

// Je nach Uhrzeit werden entsprechende Begrüßungen zusammengestellt
$availableGreetings = [];
if ($currentHour >= 5 && $currentHour < 12) { // Morgen
    foreach ($greetings as $language => $types) {
        $availableGreetings = array_merge($availableGreetings, $types['morgen']);
    }
} elseif ($currentHour >= 16 && $currentHour < 24) { // Abend
    foreach ($greetings as $language => $types) {
        $availableGreetings = array_merge($availableGreetings, $types['abend']);
    }
} else { // Allgemein (Zwischenzeit oder Nacht)
    foreach ($greetings as $language => $types) {
        $availableGreetings = array_merge($availableGreetings, $types['allgemein']);
    }
}

// Zufällige Begrüßung auswählen
$randomGreeting = $availableGreetings[array_rand($availableGreetings)];

/**
 * Aktuelle Nachricht (News) abrufen:
 * Es wird die zuletzt erstellte aktive (nicht abgelaufene) Nachricht aus der Tabelle news geholt.
 */
$current_date = date('Y-m-d');
$news_stmt = $conn->prepare("
    SELECT news.title, news.content, news.created_at, employees.name AS created_by_name
    FROM news
    JOIN employees ON news.created_by = employees.employee_id
    WHERE news.expiration_date IS NULL OR news.expiration_date >= ?
    ORDER BY news.created_at DESC
    LIMIT 1
");

$has_active_news = false;
$latest_news = null;

if ($news_stmt) {
    $news_stmt->bind_param("s", $current_date);
    $news_stmt->execute();
    $news_result = $news_stmt->get_result();
    if ($news_result->num_rows > 0) {
        $latest_news = $news_result->fetch_assoc();
        $has_active_news = true;
    }
    $news_stmt->close();
}

// Bestimme, ob der News-Bereich angezeigt werden soll
$show_news = $has_active_news || (ist_hr() || ist_it());
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Startseite</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <link href="navbar.css" rel="stylesheet">
    <style>
        .main-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: calc(100vh - 70px); /* Höhe der Navbar anpassen */
            width: 100%;
        }

        /* Zentriert den Inhalt, wenn keine News vorhanden sind */
        .centered {
            justify-content: center;
        }

        .welcome-card {
            width: 100%;
            max-width: 1140px;
            margin-bottom: 2rem;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.5rem 1.5rem var(--ball-shadow);
            position: relative;
            border: none;
        }

        .welcome-image {
            width: 100%;
            height: 300px;
            object-fit: cover;
            filter: brightness(0.7);
        }

        .welcome-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.6) 0%, rgba(0, 0, 0, 0.3) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .welcome-text {
            color: white;
            font-size: 2.5rem;
            font-weight: bold;
            text-align: center;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            padding: 1rem;
            border-radius: 1rem;
        }

        .news-card {
            width: 100%;
            max-width: 1140px;
            border: none;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.5rem 1.5rem var(--ball-shadow);
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-in-out;
        }

        .news-header {
            background: linear-gradient(135deg, var(--ball-blue), var(--ball-blue-dark));
            color: white;
            padding: 1.5rem;
            border-bottom: none;
            display: flex;
            align-items: center;
        }

        .news-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .news-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
        }

        .news-content {
            padding: 2rem;
            background-color: white;
        }

        .news-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--ball-blue-dark);
        }

        .news-body {
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            color: #333;
        }

        .news-meta {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }

        .news-footer {
            background-color: #f8f9fa;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn-manage-news {
            background-color: var(--ball-blue);
            color: white;
            border: none;
            padding: 0.5rem 1.2rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-manage-news:hover {
            background-color: var(--ball-blue-dark);
            transform: translateY(-2px);
            box-shadow: 0 0.3rem 0.5rem rgba(0, 0, 0, 0.2);
            color: white;
        }

        .btn-add-news {
            background-color: white;
            color: var(--ball-blue);
            border: 1px solid var(--ball-blue);
        }

        .btn-add-news:hover {
            background-color: var(--ball-blue);
            color: white;
        }

        .dashboard-section {
            width: 100%;
            max-width: 1140px;
            margin-bottom: 2rem;
        }

        .dashboard-title {
            font-size: 1.6rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #333;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--ball-blue);
        }

        .dashboard-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .dashboard-card {
            flex: 1;
            min-width: 250px;
            border: none;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.3rem 0.8rem var(--ball-shadow);
            transition: all 0.3s;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1.2rem rgba(0, 0, 0, 0.2);
        }

        .dashboard-card-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
        }

        .dashboard-card-body {
            padding: 1.5rem;
            background-color: white;
        }

        .dashboard-card-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: white;
        }

        .dashboard-card-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .dashboard-card-text {
            color: #6c757d;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .welcome-text {
                font-size: 2rem;
                padding: 0.8rem;
            }

            .welcome-image {
                height: 200px;
            }

            .dashboard-card {
                flex: 1 0 100%;
            }
        }

        @media (max-width: 576px) {
            .welcome-text {
                font-size: 1.5rem;
                padding: 0.6rem;
            }

            .news-header h2 {
                font-size: 1.2rem;
            }

            .news-title {
                font-size: 1.2rem;
            }

            .news-body {
                font-size: 1rem;
            }

            .dashboard-title {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<!-- Hauptcontainer -->
<div class="main-container content <?php echo !$show_news ? 'centered' : ''; ?>">
    <!-- Header-Bereich mit Bild und Begrüßungstext -->
    <div class="welcome-card">
        <img src="<?php echo $selected_image; ?>" alt="Header Bild" class="welcome-image">
        <div class="welcome-overlay">
            <div class="welcome-text">
                <?php echo htmlspecialchars($randomGreeting) . ", " . htmlspecialchars($fullname); ?>!
            </div>
        </div>
    </div>

    <!-- Aktuelle Nachricht - nur anzeigen, wenn aktive Nachrichten vorhanden sind -->
    <?php if ($has_active_news): ?>
        <div class="news-card">
            <div class="news-header">
                <i class="fas fa-bullhorn news-icon"></i>
                <h2>Aktuelle Mitteilung</h2>
            </div>
            <div class="news-content">
                <div class="news-title">
                    <?php echo htmlspecialchars($latest_news['title']); ?>
                </div>
                <div class="news-body">
                    <?php echo nl2br(htmlspecialchars($latest_news['content'])); ?>
                </div>
                <div class="news-meta">
                    <i class="far fa-clock me-1"></i>
                    Veröffentlicht am: <?php echo date('d.m.Y H:i', strtotime($latest_news['created_at'])); ?> |
                    <i class="far fa-user me-1"></i>
                    <?php echo htmlspecialchars($latest_news['created_by_name']); ?>
                </div>
            </div>
            <!-- Nur für berechtigte Benutzer: Footer mit Verwaltungsoptionen -->
            <?php if (ist_hr() || ist_it()): ?>
                <div class="news-footer">
                    <a href="news_management.php" class="btn btn-manage-news">
                        <i class="fas fa-tasks me-1"></i> Nachrichten verwalten
                    </a>
                    <a href="add_news.php" class="btn btn-manage-news btn-add-news">
                        <i class="fas fa-plus me-1"></i> Neue Nachricht
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif (ist_hr() || ist_it()): ?>
        <!-- Falls keine aktiven Nachrichten, aber berechtigter Benutzer: Nur die Verwaltungsoptionen anzeigen -->
        <div class="news-card">
            <div class="news-header">
                <i class="fas fa-bullhorn news-icon"></i>
                <h2>Mitteilungen</h2>
            </div>
            <div class="news-content text-center py-5">
                <i class="fas fa-info-circle fs-1 text-muted mb-3"></i>
                <p class="fs-5 mb-4">Derzeit sind keine aktuellen Mitteilungen vorhanden.</p>
                <div class="d-flex justify-content-center gap-3">
                    <a href="add_news.php" class="btn btn-manage-news">
                        <i class="fas fa-plus me-1"></i> Neue Nachricht erstellen
                    </a>
                    <a href="news_management.php" class="btn btn-manage-news btn-add-news">
                        <i class="fas fa-tasks me-1"></i> Nachrichten verwalten
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>