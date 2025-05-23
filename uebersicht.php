<?php
/**
 * uebersicht.php
 *
 * Diese Seite zeigt eine Übersicht der Mitarbeiter, gruppiert nach Teams und Bereichsgruppen.
 * Sie ist für berechtigte Benutzer (z. B. Admin, HR, Schichtmeister, TL etc.) zugänglich.
 * Alle Abfragen nutzen den internen Schlüssel (employee_id) aus der Tabelle employees.
 *
 * HINWEIS: Archivierte Mitarbeiter (status = 9999) werden in allen Mitarbeiterübersichten
 * ausgeblendet, da die Render-Funktionen in team_definitions.php entsprechend angepasst wurden.
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
        <div class="content container-xxl">
            <div class="content container-xxl">
                <!-- Dashboard-Style Header mit Icon -->
                <div class="d-flex align-items-center justify-content-between bg-light rounded-3 p-3 mb-2 border">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0"
                             style="width: 50px; height: 50px; min-width: 50px;">
                            <svg width="28" height="28" fill="currentColor" viewBox="0 0 16 16">
                                <use xlink:href="#people"/>
                            </svg>
                        </div>
                        <h1 class="mb-0 h3">Mitarbeiter-Übersicht</h1>
                    </div>

                    <!-- Dropdown Button wie vorher -->
                    <div class="dropdown">
                        <button class="btn btn-warning dropdown-toggle fw-bold" type="button"
                                id="hrInfoDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <svg width="16" height="16" fill="currentColor" class="me-1">
                                <use xlink:href="#exclamation-triangle"/>
                            </svg>
                            Falsche Zuordnung?
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 300px;">
                            <h6 class="dropdown-header text-warning">
                                <svg width="20" height="20" fill="currentColor" class="me-1">
                                    <use xlink:href="#exclamation-triangle"/>
                                </svg>
                                Wichtiger Hinweis
                            </h6>
                            <p class="text-muted mb-2 small">
                                <strong>Falsche Team-Zuordnung oder fehlerhafte Mitarbeiterdaten?</strong><br>
                                Bitte wende dich direkt an die HR-Abteilung - nicht an die IT!
                            </p>
                            <div class="d-grid">
                                <a href="mailto:personal.ludesch@ball.com?subject=Falsche Team-Zuordnung - Mitarbeiterübersicht"
                                   class="btn btn-primary btn-sm">
                                    <svg width="16" height="16" fill="currentColor" class="me-1">
                                        <use xlink:href="#envelope"/>
                                    </svg>
                                    HR kontaktieren
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bootstrap Icons -->
                <svg xmlns="http://www.w3.org/2000/svg" style="display: none;">
                    <symbol id="people" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8Zm-7.978-1A.261.261 0 0 1 7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002A.274.274 0 0 1 15 13H7ZM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm3-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM6.936 9.28a5.88 5.88 0 0 0-1.23-.247A7.35 7.35 0 0 0 5 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 0 1 5 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816ZM4.92 10A5.493 5.493 0 0 0 4 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275ZM1.5 5.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0Zm3-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/>
                    </symbol>
                    <symbol id="exclamation-triangle" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M7.938 2.016A.13.13 0 0 1 8.002 2a.13.13 0 0 1 .063.016.146.146 0 0 1 .054.057l6.857 11.667c.036.06.035.124.002.183a.163.163 0 0 1-.054.06.116.116 0 0 1-.066.017H1.146a.115.115 0 0 1-.066-.017.163.163 0 0 1-.054-.06.176.176 0 0 1 .002-.183L7.884 2.073a.147.147 0 0 1 .054-.057zm1.044-.45a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566z"/>
                        <path d="M7.002 12a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 5.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995z"/>
                    </symbol>
                    <symbol id="envelope" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V4Zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1H2Zm13 2.383-4.708 2.825L15 11.105V5.383Zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741ZM1 11.105l4.708-2.897L1 5.383v5.722Z"/>
                    </symbol>
                </svg>

                <div class="row w-100">
                    <?php
                    // Ausgabe aller Teams (Schicht)
                    foreach ($teams as $team) {
                        echo '<div class="team-col">';
                        echo '<div class="team">';
                        echo '<h2>' . htmlspecialchars($team) . '</h2>';

                        // Für jede Bereichsgruppe der Schicht
                        foreach ($bereichsgruppen as $group_name => $positionen) {
                            // Diese Funktion wurde in team_definitions.php angepasst,
                            // um archivierte Mitarbeiter (status != 9999) auszufiltern
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
                                // Diese Funktion wurde in team_definitions.php angepasst,
                                // um archivierte Mitarbeiter (status != 9999) auszufiltern
                                render_employee_group($conn, $group_name, $positionen, 'gruppe', $group);
                            }

                            // Bei Tagschicht zeigen wir immer die "Sonstiges"-Kategorie, da hier
                            // alle verbleibenden Tagschicht-Mitarbeiter angezeigt werden sollen
                            $all_positions = array_merge(...array_values($tagschicht_bereichsgruppen));

                            // Diese Funktion wurde in team_definitions.php angepasst,
                            // um archivierte Mitarbeiter (status != 9999) auszufiltern
                            render_other_employees($conn, 'gruppe', $group, $all_positions);
                        } else {
                            // Für andere Gruppen: Einfache Ausgabe aller Mitarbeiter
                            // Diese Funktion wurde in team_definitions.php angepasst,
                            // um archivierte Mitarbeiter (status != 9999) auszufiltern
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
                // Erfolgsmeldung anzeigen, wenn URL-Parameter 'success' vorhanden ist
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