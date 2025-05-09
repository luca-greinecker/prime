<?php
/**
 * hr_uebersicht.php
 *
 * Diese Seite steht ausschließlich Admins und HR-Mitarbeitern zur Verfügung.
 * Hier werden die Mitarbeiter in den Teams (z. B. Schicht, Tagschicht, Verwaltung)
 * gruppiert und in entsprechenden Bereichsgruppen angezeigt.
 */

include 'access_control.php';
include 'team_definitions.php';

global $conn;
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_oder_empfang_zugriff();

// Die $teams, $additional_groups, $bereichsgruppen und $tagschicht_bereichsgruppen
// werden aus team_definitions.php importiert
?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mitarbeiterübersicht</title>
        <!-- Lokales Bootstrap 5 CSS -->
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
        <style>
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

            /* Bereichsgruppen-Überschrift */
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

            .info-message {
                background-color: #f8d7da;
                color: #721c24;
                padding: 5px;
                margin-top: 10px;
                border-radius: 3px;
                font-size: 0.85em;
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>
    <div class="content container-xxl">
        <h1>Mitarbeiterübersicht</h1>
        <div class="divider"></div>
        <div class="row row-cols-7 w-100">
            <?php
            // Prüfen, ob es Mitarbeiter gibt, die keiner Gruppe oder Crew zugeordnet sind
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees WHERE (crew IS NULL OR crew = '') AND (gruppe IS NULL OR gruppe = '')");
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $unassigned_count = $row['count'];
            $stmt->close();

            // Wenn nicht zugeordnete Mitarbeiter gefunden wurden, zeige eine Info-Meldung
            if ($unassigned_count > 0) {
                echo '<div class="col-12 mb-3">';
                echo '<div class="info-message">Es gibt ' . $unassigned_count . ' Mitarbeiter, die keinem Team oder keiner Gruppe zugeordnet sind. Bitte überprüfen Sie die <a href="employee_list.php">Mitarbeiterliste</a>.</div>';
                echo '</div>';
            }

            // Ausgabe aller Teams (Schicht)
            foreach ($teams as $team) {
                echo '<div class="col">';
                echo '<div class="team">';
                echo '<h2>' . htmlspecialchars($team) . '</h2>';

                // Für jede Bereichsgruppe in den Schicht-Teams
                foreach ($bereichsgruppen as $group_name => $positionen) {
                    render_employee_group($conn, $group_name, $positionen, 'crew', $team);
                }

                // Mitarbeiter, die in keiner Kategorie der Teams fallen, unter "Sonstiges"
                // Bei den Teams werden alle Mitarbeiter festen Bereichen zugeordnet,
                // daher prüfen wir erst, ob überhaupt Mitarbeiter in "Sonstiges" fallen würden
                $all_positions = array_merge(...array_values($bereichsgruppen));
                $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM employees 
                WHERE crew = ? AND (position NOT IN (" . implode(',', array_fill(0, count($all_positions), '?')) . ") OR position IS NULL)
            ");
                $params = array_merge([$team], $all_positions);
                $types = str_repeat('s', count($params));
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $others_count = $row['count'];
                $stmt->close();

                // Nur wenn es Mitarbeiter in "Sonstiges" gibt, zeigen wir diese Gruppe an
                if ($others_count > 0) {
                    render_other_employees($conn, 'crew', $team, $all_positions);
                }

                echo '</div>';
                echo '</div>';
            }

            // Zusätzliche Gruppen: Tagschicht und Verwaltung
            foreach ($additional_groups as $group) {
                echo '<div class="col">';
                echo '<div class="team">';
                echo '<h2>' . htmlspecialchars($group) . '</h2>';

                if ($group === 'Tagschicht') {
                    foreach ($tagschicht_bereichsgruppen as $group_name => $positionen) {
                        render_employee_group($conn, $group_name, $positionen, 'gruppe', $group);
                    }

                    // Bei Tagschicht zeigen wir immer die "Sonstiges"-Kategorie, da hier alle
                    // verbleibenden Tagschicht-Mitarbeiter angezeigt werden sollen
                    $all_positions = array_merge(...array_values($tagschicht_bereichsgruppen));
                    render_other_employees($conn, 'gruppe', $group, $all_positions);

                } else {
                    // Für die Gruppe "Verwaltung": einfache Ausgabe aller Mitarbeiter
                    render_group_employees($conn, $group);

                    // In der Verwaltung zusätzlich eine Sonstiges-Kategorie für Mitarbeiter ohne Position
                    // Hier prüfen wir ebenfalls, ob es überhaupt Mitarbeiter in dieser Kategorie gibt
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees WHERE gruppe = ? AND position IS NULL");
                    $stmt->bind_param("s", $group);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $others_count = $row['count'];
                    $stmt->close();

                    if ($others_count > 0) {
                        echo '<div class="employee-group sonstiges" data-title="Sonstiges">';
                        echo '<ul class="employee">';
                        $stmt = $conn->prepare("SELECT employee_id, name, anwesend FROM employees WHERE gruppe = ? AND position IS NULL ORDER BY name ASC");
                        $stmt->bind_param("s", $group);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $presentClass = $row['anwesend'] ? 'present' : '';
                            echo '<li class="' . $presentClass . '"><a href="employee_details.php?employee_id=' . htmlspecialchars($row['employee_id']) . '">' . htmlspecialchars($row['name']) . '</a></li>';
                        }
                        $stmt->close();
                        echo '</ul>';
                        echo '</div>';
                    }
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

        var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
        var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
            return new bootstrap.Dropdown(dropdownToggleEl);
        });
    </script>
    </body>
    </html>
<?php
$conn->close();
?>