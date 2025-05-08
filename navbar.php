<?php
/**
 * navbar.php
 *
 * Diese Datei stellt die Navigationsleiste (Navbar) für die gesamte Anwendung bereit.
 * Sie übernimmt die Session- und Zugriffskontrolle, lädt Benutzerinformationen und
 * zeigt je nach Zugriffsrechten verschiedene Menüpunkte an.
 */

include_once 'access_control.php';
global $conn;

// Sicherstellen, dass der Benutzer eingeloggt ist
pruefe_benutzer_eingeloggt();

// Aus der Session den internen Mitarbeiter-Schlüssel (employee_id) und den Benutzernamen holen
$mitarbeiter_id = $_SESSION['mitarbeiter_id'];
$username = $_SESSION['username'] ?? 'Gast';

// Benutzerinformationen (Position und Name) abrufen – über den internen Schlüssel
try {
    $stmt = $conn->prepare("SELECT position, name FROM employees WHERE employee_id = ?");
    if (!$stmt) {
        throw new Exception("Fehler bei der Vorbereitung des Statements: " . $conn->error);
    }
    $stmt->bind_param("i", $mitarbeiter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    error_log($e->getMessage());
    $user = [];
}

$position = $user['position'] ?? '';
$fullname = $user['name'] ?? '';

// Zugriffsrechte prüfen
$ist_admin = ist_admin();

$has_access_to_fuehrung = pruefe_fuehrung_zugriff($position);
$has_access_to_uebersicht = hat_zugriff_auf_uebersicht();
$has_access_to_trainings = hat_zugriff_auf_trainings();
$has_access_to_hr = hat_zugriff_auf_hr();
$has_access_to_onboarding = hat_zugriff_auf_onboarding();
$has_access_to_safety = hat_zugriff_auf_safety();
$has_access_to_reception = ist_empfang();

// Prüfe, ob der Benutzer erweiterte Berechtigungen hat (HR, viele Menüpunkte)
$has_extended_rights = $has_access_to_hr || $ist_admin;

// Hauptmenüpunkte definieren (wichtigste zuerst)
$primary_menu_items = [
    'fuehrung' => [
        'access' => $has_access_to_fuehrung,
        'items' => [
            ['url' => 'meine_mitarbeiter.php', 'title' => 'Meine Mitarbeiter', 'icon' => 'fa-users'],
            ['url' => 'dashboard.php', 'title' => 'Dashboard', 'icon' => 'fa-tachometer-alt']
        ]
    ],
    'uebersicht' => [
        'access' => $has_access_to_uebersicht,
        'items' => [
            ['url' => 'uebersicht.php', 'title' => 'Übersicht', 'icon' => 'fa-table']
        ]
    ],
    'hr' => [
        'access' => $has_access_to_hr,
        'items' => [
            ['url' => 'hr_dashboard.php', 'title' => 'HR-Dashboard', 'icon' => 'fa-chart-bar']
        ]
    ],
    'onboarding' => [
        'access' => $has_access_to_onboarding,
        'items' => [
            ['url' => 'onboarding_list.php', 'title' => 'Onboarding', 'icon' => 'fa-clipboard-list']
        ]
    ],
    'trainings' => [
        'access' => $has_access_to_trainings,
        'items' => [
            ['url' => 'training_overview.php', 'title' => 'Trainings', 'icon' => 'fa-graduation-cap']
        ]
    ],
    'safety' => [
        'access' => $has_access_to_safety,
        'items' => [
            ['url' => 'safety_dashboard.php', 'title' => 'EHS', 'icon' => 'fa-hard-hat']
        ]
    ]

];

// Sekundäre Menüpunkte für das "Mehr" Dropdown (nur wenn viele Rechte)
$secondary_menu_items = [
//    'uebersicht' => [
//        'access' => $has_access_to_uebersicht,
//        'items' => [
//            ['url' => 'uebersicht.php', 'title' => 'Übersicht', 'icon' => 'fa-table']
//        ]
//    ]
];

// HR-spezifische Menüpunkte
$hr_menu_items = [
    ['url' => 'hr_uebersicht.php', 'title' => 'MA-Übersicht', 'icon' => 'fa-users'],
    ['url' => 'benutzer.php', 'title' => 'Benutzer', 'icon' => 'fa-user-cog'],
    ['url' => 'gepraeche_dashboard.php', 'title' => 'Gespräche-Dashboard', 'icon' => 'fa-tachometer-alt'],
    ['url' => 'mitarbeitergespräch_history.php', 'title' => 'Gespräche-History', 'icon' => 'fa-comments'],
    [
        'url' => 'tv.php',
        'title' => 'TV-Anzeige (MA)',
        'icon' => 'fa-list-check',
        'target' => '_blank',
        'extra' => '<i class="fas fa-external-link-alt fa-fw text-danger"></i>'
    ],
    [
        'url' => 'ehemalige_mitarbeiter.php',
        'title' => 'Ehemalige Mitarbeiter',
        'icon' => 'fa-user-slash'
    ]
];


// Einstellungs-Menüpunkte
$settings_menu_items = [
    ['url' => 'settings_kompetenzen.php', 'title' => 'Kompetenzen', 'icon' => 'fa-list-check'],
    ['url' => 'settings_positionen.php', 'title' => 'Positionen', 'icon' => 'fa-sitemap'],
    ['url' => 'settings_gespraeche.php', 'title' => 'Gespräche/Talent Review', 'icon' => 'fa-comments'],
    ['url' => 'settings_bereichsgruppen.php', 'title' => 'Bereichsgruppen', 'icon' => 'fa-layer-group']
];

// Info-Menüpunkte
$info_menu_items = [
    ['url' => 'team.php', 'title' => 'Team', 'icon' => 'fa-users']
];

if ($has_access_to_hr || $has_access_to_reception) {
    $info_menu_items[] = ['url' => 'jubilare.php', 'title' => 'Jubilare', 'icon' => 'fa-medal'];
    $info_menu_items[] = ['url' => 'geburtstage.php', 'title' => 'Geburtstage', 'icon' => 'fa-birthday-cake'];
}

// Links-Menüpunkte
$links_menu_items = [
    ['url' => 'http://195.128.171.170:8771/zeit_webp/zpn0011r.pgm?s1_dtalib=ZEITDTA&s1_pgmlib=ZEITLIB', 'title' => 'ZeitNet', 'icon' => 'fa-clock', 'target' => '_blank'],
    ['url' => 'https://projectveritas.newsweaver.com/101xku73uf/o54grwzlhj0lrixwx2e4ur/external?email=true&a=6&p=455527&t=157056', 'title' => 'Success Factors', 'icon' => 'fa-rocket', 'target' => '_blank'],
    ['url' => 'https://ballcorp.sharepoint.com/sites/GTP/SitePages/BP-Ludesch-Training-Portal.aspx', 'title' => 'JBS Portal', 'icon' => 'fa-globe', 'target' => '_blank'],
    ['url' => 'http://atludweb01/schichtplan/plan.php', 'title' => 'Online-Schichtplan/JZK', 'icon' => 'fa-calendar-alt', 'target' => '_blank']
];
?>

<!-- Lokales Bootstrap 5 CSS und Font Awesome -->
<link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="navbar.css" rel="stylesheet">
<link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
<link rel="icon" href="assets/bilder/ball-logo.ico" type="image/x-icon">
<style>
    .navbar {
        background: linear-gradient(135deg, var(--ball-blue), var(--ball-blue-dark));
        box-shadow: 0 2px 15px var(--ball-shadow);
        padding: 0.4rem 1rem;
    }

    .navbar-brand img {
        height: 44px;
        transition: transform 0.3s;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
    }

    .navbar-brand:hover img {
        transform: scale(1.05);
    }

    .nav-link {
        color: var(--ball-white);
        font-weight: 500;
        padding: 0.7rem 1rem !important; /* Erhöht von 0.5rem */
        position: relative;
        transition: all 0.3s;
        margin: 0 0.1rem;
        border-radius: 4px;
        white-space: nowrap; /* Verhindert Umbruch bei Text */
    }

    /* Kompaktere Anzeige bei mittleren Bildschirmen */
    @media (max-width: 1200px) and (min-width: 992px) {
        .nav-link {
            padding: 0.7rem 0.7rem !important;
            font-size: 0.9rem;
        }

        .nav-link i {
            margin-right: 0.3rem;
        }
    }

    .nav-link:hover {
        background-color: var(--ball-highlight);
        transform: translateY(-2px);
    }

    .nav-link::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        width: 0;
        height: 2px;
        background-color: var(--ball-white);
        transition: all 0.3s;
        transform: translateX(-50%);
    }

    .nav-link:hover::after {
        width: 80%;
    }

    /* Icon styling für Nav-Links */
    .nav-link i {
        margin-right: 0.5rem;
        transition: transform 0.3s;
    }

    .nav-link:hover i {
        transform: scale(1.2);
    }

    /* Dropdown-Styling */
    .dropdown-menu {
        background-color: var(--ball-white);
        border: none;
        border-radius: 8px;
        box-shadow: 0 5px 25px rgba(0, 0, 0, 0.2);
        margin-top: 0.5rem;
        padding: 0.5rem 0;
        overflow: hidden;
    }

    /* Größere Dropdowns für komplexere Menüs */
    .dropdown-menu.dropdown-menu-grid {
        width: auto;
        min-width: 320px;
        padding: 1rem;
    }

    .dropdown-menu-grid .dropdown-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
    }

    .dropdown-menu-grid .dropdown-item {
        padding: 0.6rem 1rem;
        border-radius: 6px;
    }

    .dropdown-item {
        color: var(--ball-charcoal);
        padding: 0.6rem 1.5rem;
        transition: all 0.2s;
        position: relative;
    }

    .dropdown-item:hover {
        background-color: rgba(17, 64, 254, 0.08);
        color: var(--ball-blue) !important;
        padding-left: 1.7rem;
    }

    .dropdown-menu-grid .dropdown-item:hover {
        padding-left: 1.2rem;
    }

    .dropdown-toggle::after {
        margin-left: 0.5em;
        vertical-align: 0.15em;
    }

    /* Benutzerinfo und Logout-Button */
    .user-info {
        font-weight: bold;
        color: var(--ball-white);
        display: flex;
        align-items: center;
    }

    #date-time {
        background-color: rgba(255, 255, 255, 0.15); /* Etwas deutlicher */
        padding: 0.4rem 0.8rem; /* Größer */
        border-radius: 20px;
        font-size: 0.95rem; /* Größer */
        backdrop-filter: blur(5px);
    }

    .btn-logout {
        background-color: #dc3545;
        color: white;
        border: none;
        border-radius: 6px;
        padding: 0.45rem 1.2rem; /* Größer */
        transition: all 0.3s;
        font-weight: 500;
        box-shadow: 0 2px 5px rgba(220, 53, 69, 0.4);
    }

    .btn-logout:hover {
        background-color: #bd2130;
        box-shadow: 0 4px 8px rgba(220, 53, 69, 0.6);
        transform: translateY(-2px);
    }

    /* Suche Styling */
    .search-form input {
        border-radius: 20px 0 0 20px;
        border: none;
        padding-left: 1rem;
        transition: all 0.3s;
        box-shadow: inset 0 0 5px rgba(0, 0, 0, 0.1);
        width: 180px; /* Etwas breiter */
    }

    .search-form input:focus {
        box-shadow: inset 0 0 8px rgba(0, 0, 0, 0.2);
    }

    .search-form button {
        border-radius: 0 20px 20px 0;
        background-color: var(--ball-white);
        color: var(--ball-blue);
        border: none;
        transition: all 0.3s;
    }

    .search-form button:hover {
        background-color: var(--ball-blue-light);
        color: var(--ball-white);
    }

    /* Mobile Navigation */
    @media (max-width: 991.98px) {
        .navbar-collapse {
            background: linear-gradient(180deg, var(--ball-blue), var(--ball-blue-dark));
            border-radius: 0 0 15px 15px;
            padding: 1rem;
            margin-top: 0.5rem;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            max-height: 80vh;
            overflow-y: auto;
        }

        .nav-link::after {
            display: none;
        }

        .nav-link {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 0.8rem 1rem !important;
        }

        .nav-link:hover {
            background-color: var(--ball-highlight);
            transform: none;
        }

        .dropdown-menu {
            background: transparent;
            border: none;
            box-shadow: none;
            padding-left: 1.5rem;
        }

        .dropdown-menu-grid {
            width: 100% !important;
            min-width: auto !important;
        }

        .dropdown-menu-grid .dropdown-grid {
            grid-template-columns: 1fr;
        }

        .dropdown-item {
            color: var(--ball-white) !important;
            padding: 0.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .dropdown-item:hover {
            background-color: var(--ball-highlight);
            color: var(--ball-white) !important;
        }

        .user-info, .search-form, .btn-logout {
            margin-top: 1rem;
        }

        .search-form {
            width: 100%;
        }

        .search-form input {
            width: 100%;
        }
    }

    /* Navbar Toggler */
    .navbar-toggler {
        border: none;
        padding: 0.5rem;
        transition: all 0.3s;
        position: relative;
    }

    .navbar-toggler:focus {
        box-shadow: none;
        outline: none;
    }

    .navbar-toggler-icon {
        background-image: none;
        position: relative;
        height: 1.5em;
        display: flex;
        align-items: center;
    }

    .navbar-toggler-icon::before,
    .navbar-toggler-icon::after,
    .navbar-toggler-icon span {
        content: '';
        display: block;
        width: 25px;
        height: 2px;
        background-color: var(--ball-white);
        position: absolute;
        left: 0;
        transition: all 0.3s;
    }

    .navbar-toggler-icon::before {
        top: 5px;
    }

    .navbar-toggler-icon span {
        top: 50%;
        transform: translateY(-50%);
    }

    .navbar-toggler-icon::after {
        bottom: 5px;
    }

    .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon::before {
        transform: rotate(45deg);
        top: 50%;
    }

    .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon span {
        opacity: 0;
    }

    .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon::after {
        transform: rotate(-45deg);
        bottom: 45%;
    }
</style>

<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container-fluid">
        <!-- Navbar-Logo -->
        <a class="navbar-brand" href="index.php">
            <img src="assets/bilder/ball-logo-white.png" alt="Ball Logo" id="navbar-logo">
        </a>

        <!-- Navbar Toggler -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent"
                aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon">
                <span></span>
            </span>
        </button>

        <!-- Navigationslinks -->
        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav me-auto">
                <!-- Primäre Menüpunkte (wichtigste) -->
                <?php foreach ($primary_menu_items as $group): ?>
                    <?php if ($group['access']): ?>
                        <?php foreach ($group['items'] as $item): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo htmlspecialchars($item['url']); ?>">
                                    <i class="fas <?php echo htmlspecialchars($item['icon']); ?> fa-fw"></i>
                                    <?php echo htmlspecialchars($item['title']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- HR-Dropdown kompakter gestaltet -->
                <?php if ($has_access_to_hr): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-white" href="#" id="hrDropdown" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-tie fa-fw"></i> HR
                        </a>
                        <ul class="dropdown-menu dropdown-menu-grid" aria-labelledby="hrDropdown">
                            <div class="dropdown-grid">
                                <?php foreach ($hr_menu_items as $item): ?>
                                    <a class="dropdown-item" href="<?php echo htmlspecialchars($item['url']); ?>"
                                        <?php echo isset($item['target']) ? 'target="' . htmlspecialchars($item['target']) . '"' : ''; ?>>
                                        <i class="fas <?php echo htmlspecialchars($item['icon']); ?> fa-fw"></i>
                                        <?php echo htmlspecialchars($item['title']); ?>
                                        <?php echo isset($item['extra']) ? $item['extra'] : ''; ?>
                                    </a>

                                <?php endforeach; ?>
                            </div>
                        </ul>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-white"
                           href="#"
                           id="einstellungenDropdown"
                           role="button"
                           data-bs-toggle="dropdown"
                           aria-expanded="false">
                            <i class="fas fa-cog fa-fw"></i> Einstellungen
                        </a>
                        <ul class="dropdown-menu dropdown-menu-grid" aria-labelledby="einstellungenDropdown">
                            <div class="dropdown-grid">
                                <?php foreach ($settings_menu_items as $item): ?>
                                    <a class="dropdown-item" href="<?= htmlspecialchars($item['url']) ?>">
                                        <i class="fas <?= htmlspecialchars($item['icon']) ?> fa-fw"></i>
                                        <?= htmlspecialchars($item['title']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- Info-Dropdown - immer in der Hauptnavigation bei normalen Benutzern -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" id="infoDropdown" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-info-circle fa-fw"></i> Info
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="infoDropdown">
                        <?php foreach ($info_menu_items as $item): ?>
                            <li>
                                <a class="dropdown-item" href="<?php echo htmlspecialchars($item['url']); ?>">
                                    <i class="fas <?php echo htmlspecialchars($item['icon']); ?> fa-fw"></i>
                                    <?php echo htmlspecialchars($item['title']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>

                <!-- Links-Dropdown - immer in der Hauptnavigation bei normalen Benutzern -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" id="linksDropdown" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-external-link-alt fa-fw"></i> Links
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="linksDropdown">
                        <?php foreach ($links_menu_items as $item): ?>
                            <li>
                                <a class="dropdown-item" href="<?php echo htmlspecialchars($item['url']); ?>"
                                    <?php echo isset($item['target']) ? 'target="' . htmlspecialchars($item['target']) . '"' : ''; ?>>
                                    <i class="fas <?php echo htmlspecialchars($item['icon']); ?> fa-fw"></i>
                                    <?php echo htmlspecialchars($item['title']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>

                <!-- Mehr-Dropdown nur für Benutzer mit vielen Zugriffsrechten -->
                <?php
                $has_secondary_items = false;
                foreach ($secondary_menu_items as $group) {
                    if ($group['access']) {
                        $has_secondary_items = true;
                        break;
                    }
                }

                if ($has_secondary_items):
                    ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-white" href="#" id="moreDropdown" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-ellipsis-h fa-fw"></i> Mehr
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="moreDropdown">
                            <?php foreach ($secondary_menu_items as $group): ?>
                                <?php if ($group['access']): ?>
                                    <?php foreach ($group['items'] as $item): ?>
                                        <li>
                                            <a class="dropdown-item"
                                               href="<?php echo htmlspecialchars($item['url']); ?>">
                                                <i class="fas <?php echo htmlspecialchars($item['icon']); ?> fa-fw"></i>
                                                <?php echo htmlspecialchars($item['title']); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- Rechts-Sektion -->
            <ul class="navbar-nav ms-auto d-flex flex-row align-items-center">
                <!-- Datum/Zeit -->
                <!--                <li class="nav-item me-3">-->
                <!--                    <span id="date-time" class="text-white"></span>-->
                <!--                </li>-->

                <!-- Benutzer Dropdown -->
                <li class="nav-item dropdown me-3">
                    <a class="nav-link dropdown-toggle text-white" href="#" id="userDropdown" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($username); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><a class="dropdown-item" href="profil.php">Mein Profil</a></li>
                        <li><a class="dropdown-item" href="change_password.php">Passwort ändern</a></li>
                    </ul>
                </li>

                <!-- Suchfeld - nur wenn benötigt -->
                <?php if ($has_access_to_hr || $has_access_to_trainings): ?>
                    <li class="nav-item me-3">
                        <form class="d-flex search-form" action="search.php" method="GET">
                            <input class="form-control form-control-sm" type="search" name="query"
                                   placeholder="Suchen..." aria-label="Suchen">
                            <button class="btn btn-sm btn-light" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </li>
                <?php endif; ?>

                <!-- Logout Button -->
                <li class="nav-item">
                    <a href="logout.php" class="btn btn-danger btn-sm btn-logout" data-method="post">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- JavaScript für die Navbar -->
<script>

    /*    Navbar Datums/Zeitanzeige auskommentiert

        // Datum/Zeit aktualisieren
        function updateDateTime() {
            const now = new Date();
            const options = {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            document.getElementById('date-time').textContent = now.toLocaleString('de-AT', options);
        }

        // Initial und dann regelmäßig aktualisieren
        updateDateTime();
        setInterval(updateDateTime, 60000);

        */

    // Aktuelle Seite hervorheben
    document.addEventListener('DOMContentLoaded', function () {
        const currentLocation = location.pathname.split('/').slice(-1)[0];

        // Prüfe alle Haupt-Links
        document.querySelectorAll('.nav-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentLocation) {
                link.classList.add('active');
                link.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
            }
        });

        // Prüfe auch alle Dropdown-Einträge
        document.querySelectorAll('.dropdown-item').forEach(link => {
            const href = link.getAttribute('href');
            if (href === currentLocation) {
                link.classList.add('active');
                link.style.backgroundColor = 'rgba(17, 64, 254, 0.08)';
                link.style.color = 'var(--ball-blue)';

                // Markiere auch den übergeordneten Dropdown als aktiv
                const parentDropdown = link.closest('.dropdown').querySelector('.dropdown-toggle');
                if (parentDropdown) {
                    parentDropdown.classList.add('active');
                    parentDropdown.style.backgroundColor = 'rgba(255, 255, 255, 0.2)';
                }
            }
        });
    });

    // Automatisches Schließen des Hamburger-Menüs bei Klick auf einen Link im mobilen Modus
    document.addEventListener('DOMContentLoaded', function () {
        if (window.innerWidth < 992) {
            document.querySelectorAll('.navbar-nav a').forEach(link => {
                link.addEventListener('click', () => {
                    // Ausnahme für Dropdowns
                    if (!link.classList.contains('dropdown-toggle')) {
                        const navbarToggler = document.querySelector('.navbar-toggler');
                        if (navbarToggler && !navbarToggler.classList.contains('collapsed')) {
                            navbarToggler.click();
                        }
                    }
                });
            });
        }
    });
</script>