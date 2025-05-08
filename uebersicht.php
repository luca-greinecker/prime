<?php
/**
 * uebersicht.php
 *
 * Diese Seite zeigt eine Übersicht der Mitarbeiter, gruppiert nach Teams und Bereichsgruppen.
 * Sie ist für berechtigte Benutzer (z. B. Admin, HR, Schichtmeister, TL etc.) zugänglich.
 * Alle Abfragen nutzen nun den internen Schlüssel (employee_id) aus der Tabelle employees.
 */

include 'access_control.php';
include 'team_definitions.php'; // Einbinden der Team-Definitionen und Funktionen

global $conn;
pruefe_benutzer_eingeloggt();

// Benutzerinformationen anhand des internen Schlüssels abrufen
$mitarbeiter_id = $_SESSION['mitarbeiter_id'];
$stmt = $conn->prepare("SELECT position FROM employees WHERE employee_id = ?");
$stmt->bind_param("i", $mitarbeiter_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$position = $user['position'] ?? '';

// Zugriffsprüfung: Admin, HR, Bereichsleiter oder über TL-Funktion
$ist_admin = ist_admin();
$ist_hr = ist_hr();
$ist_bereichsleiter = ist_bereichsleiter();
$ist_leiter = ist_leiter();

$has_access =
    $ist_admin ||
    $ist_hr ||
    $ist_bereichsleiter ||
    $ist_leiter ||
    pruefe_tl_tagschicht_zugriff() ||
    $position === 'Schichtmeister' ||
    $position === 'Verwaltung - Training Manager' ||
    $position === 'Verwaltung - Continuous Improvement/Lean Leader' ||
    $position === 'Verwaltung - EHS Manager';

if (!$has_access) {
    header("Location: access_denied.php");
    exit;
}

// Überschreibe die Zusatzgruppen für diese Ansicht - nur Tagschicht anzeigen
$additional_groups = ["Tagschicht"];
// Die restlichen Team-Definitionen ($teams, $bereichsgruppen, $tagschicht_bereichsgruppen)
// werden aus team_functions.php importiert
?>

    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Übersicht</title>
        <!-- Einbindung von CSS: Navbar und Bootstrap -->
        <link href="navbar.css" rel="stylesheet">
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <style>
            .team-col {
                flex: 1 0 200px;
                max-width: calc(100% / 6);
                box-sizing: border-box;
                padding: 10px;
            }

            .team {
                border: 1px solid #dee2e6;
                border-radius: 5px;
                background-color: #ffffff;
                padding: 10px;
                text-align: center;
            }

            .team h2 {
                font-size: 1.2em;
                color: #007bff;
                padding-bottom: 4px;
                margin-bottom: 5px;
                border-bottom: 1px solid #007bff;
            }

            .employee {
                list-style-type: none;
                padding: 0;
                margin: 0;
            }

            .employee li a {
                color: inherit;
                text-decoration: none;
            }

            .employee li a:hover {
                text-decoration: underline;
            }

            .employee li.present a {
                color: #32CD32;
            }

            .employee li.tl a, .employee li.al a, .employee li.schichtmeister a {
                font-weight: bold;
            }

            .employee-group {
                position: relative;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 1px solid #dee2e6;
            }

            .employee-group:before {
                content: attr(data-title);
                position: absolute;
                top: -0.75rem;
                left: 50%;
                transform: translateX(-50%);
                background-color: #fff;
                padding: 0 5px;
                font-size: 0.8rem;
                color: #6c757d;
                white-space: nowrap;
                max-width: 100%;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .thin-divider {
                margin-bottom: 10px;
            }

            .popup {
                display: none;
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                padding: 10px;
                background-color: #90EE90;
                color: black;
                border-radius: 5px;
                z-index: 1000;
                text-align: center;
            }

            .content h1 {
                text-align: center;
                width: 100%;
            }

            .row {
                margin-right: 0 !important;
                margin-left: 0 !important;
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>
    <div class="content container-xxl">
        <h1>Übersicht</h1>
        <div class="divider"></div>
        <div class="row w-100">
            <?php
            // Ausgabe aller Teams (Schicht)
            foreach ($teams as $team) {
                echo '<div class="team-col">';
                echo '<div class="team">';
                echo '<h2>' . htmlspecialchars($team) . '</h2>';

                // Für jede Bereichsgruppe der Schicht
                foreach ($bereichsgruppen as $group_name => $positionen) {
                    render_employee_group($conn, $group_name, $positionen, 'crew', $team);
                }

                // Bei den Teams werden alle Mitarbeiter in feste Bereiche zugeordnet,
                // daher brauchen wir keine "Sonstiges"-Kategorie hier

                echo '</div>';
                echo '</div>';
            }

            // Zusätzliche Gruppen: Nur Tagschicht in dieser Ansicht
            foreach ($additional_groups as $group) {
                echo '<div class="team-col">';
                echo '<div class="team">';
                echo '<h2>' . htmlspecialchars($group) . '</h2>';

                if ($group === 'Tagschicht') {
                    // Für jede Bereichsgruppe in der Tagschicht
                    foreach ($tagschicht_bereichsgruppen as $group_name => $positionen) {
                        render_employee_group($conn, $group_name, $positionen, 'gruppe', $group);
                    }

                    // Bei Tagschicht zeigen wir immer die "Sonstiges"-Kategorie, da hier
                    // alle verbleibenden Tagschicht-Mitarbeiter angezeigt werden sollen
                    $all_positions = array_merge(...array_values($tagschicht_bereichsgruppen));
                    render_other_employees($conn, 'gruppe', $group, $all_positions);
                } else {
                    // Für andere Gruppen: Einfache Ausgabe aller Mitarbeiter
                    render_group_employees($conn, $group);
                }
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>
    </div>
    <div id="popup" class="popup">Änderungen erfolgreich gespeichert</div>

    <!-- Lokales Bootstrap 5 JavaScript Bundle -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        function showPopup() {
            var popup = document.getElementById("popup");
            popup.style.display = "block";
            setTimeout(function () {
                popup.style.display = "none";
            }, 3000);
        }

        if (new URLSearchParams(window.location.search).has('success')) {
            showPopup();
        }
    </script>
    </body>
    </html>
<?php
$conn->close();
?>