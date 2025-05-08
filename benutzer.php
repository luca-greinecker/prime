<?php
/**
 * benutzer.php
 *
 * Diese Seite zeigt die Benutzerübersicht an und ermöglicht das Zurücksetzen von Passwörtern.
 * Nur Admins oder HR-Mitarbeiter haben Zugriff auf diese Seite.
 *
 * Bei einem Klick auf "Passwort zurücksetzen" wird das Passwort für den jeweiligen Benutzer auf das Standardpasswort
 * "Ball1234" gesetzt (nachdem es gehasht wurde).
 *
 * Voraussetzung: In der Session ist der interne Mitarbeiter-Schlüssel (employee_id) unter $_SESSION['mitarbeiter_id'] gespeichert.
 */

include 'access_control.php'; // Übernimmt Session-Management und Zugriffskontrolle

global $conn;

// Sicherstellen, dass der Benutzer eingeloggt ist
pruefe_benutzer_eingeloggt();

// Zugriffskontrolle: Nur Admins oder HR dürfen diese Seite nutzen
pruefe_admin_oder_hr_zugriff();

// Status-Nachricht initialisieren
$status_message = '';
$status_type = '';

// Passwort zurücksetzen, falls das Formular abgesendet wurde
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reset_password'])) {
    $username = $_POST['username'];
    $mitarbeiter_name = $_POST['mitarbeiter_name'];

    // Neues Standardpasswort festlegen und hashen
    $new_password = 'Ball1234';
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    // Update-Statement zum Zurücksetzen des Passworts in der Tabelle benutzer_matool
    $stmt = $conn->prepare("UPDATE benutzer_matool SET password = ? WHERE username = ?");
    if (!$stmt) {
        $status_message = "Fehler beim Vorbereiten des Statements: " . $conn->error;
        $status_type = "danger";
    } else {
        $stmt->bind_param("ss", $hashed_password, $username);
        if ($stmt->execute()) {
            $status_message = "Das Passwort für <strong>" . htmlspecialchars($mitarbeiter_name) . "</strong> (" . htmlspecialchars($username) . ") wurde erfolgreich zurückgesetzt.";
            $status_type = "success";
        } else {
            $status_message = "Fehler beim Zurücksetzen des Passworts: " . $stmt->error;
            $status_type = "danger";
        }
        $stmt->close();
    }
}

// SQL-Abfrage: Benutzer und zugehörige Mitarbeiternamen (sowie Gruppe und Crew) abrufen
// Achtung: Es wird nun über den internen Schlüssel (employee_id) gejoint –
// da in der neuen Struktur der alte Primärschlüssel (id) in employees zu badge_id geändert wurde.
$sql = "SELECT b.username, e.name AS mitarbeiter_name, e.gruppe, e.crew, e.bild
        FROM benutzer_matool b
        JOIN employees e ON b.mitarbeiter_id = e.employee_id
        ORDER BY 
            CASE 
                WHEN e.gruppe = 'Schichtarbeit' THEN 1
                WHEN e.gruppe = 'Tagschicht' THEN 2
                WHEN e.gruppe = 'Verwaltung' THEN 3
                ELSE 4
            END,
            CASE 
                WHEN e.crew = 'Team L' THEN 1
                WHEN e.crew = 'Team M' THEN 2
                WHEN e.crew = 'Team N' THEN 3
                WHEN e.crew = 'Team O' THEN 4
                WHEN e.crew = 'Team P' THEN 5
                ELSE 6
            END,
            e.gruppe ASC, e.crew ASC, e.name ASC";

$result = $conn->query($sql);

// Zähle Benutzer nach Gruppe
$benutzer_count = [
    'total' => 0,
    'Schichtarbeit' => 0,
    'Tagschicht' => 0,
    'Verwaltung' => 0
];

$benutzer_list = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $benutzer_list[] = $row;
        $benutzer_count['total']++;
        if (isset($row['gruppe']) && !empty($row['gruppe'])) {
            $benutzer_count[$row['gruppe']]++;
        }
    }
}
?>

    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Benutzerübersicht</title>
        <!-- Lokales Bootstrap 5 CSS und Navbar CSS -->
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
        <style>
            .page-header {
                position: relative;
                padding: 2rem 1.5rem;
                margin-bottom: 1.5rem;
                background-color: var(--ball-white);
                border-radius: 0 0 20px 20px;
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
                min-width: 150px;
                text-align: center;
                box-shadow: 0 2px 8px var(--ball-shadow);
                transition: transform 0.3s ease;
            }

            .stat-card:hover {
                transform: translateY(-5px);
            }

            .stat-icon {
                font-size: 1.75rem;
                margin-bottom: 0.5rem;
            }

            .stat-value {
                font-size: 1.5rem;
                font-weight: 700;
                color: var(--ball-charcoal);
                margin-bottom: 0.25rem;
            }

            .stat-label {
                font-size: 0.85rem;
                color: var(--ball-grey);
            }

            .content-card {
                background-color: white;
                border-radius: 15px;
                box-shadow: 0 4px 12px var(--ball-shadow);
                padding: 1.5rem;
                margin-bottom: 2rem;
                overflow: hidden;
            }

            .table-container {
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            }

            .table {
                margin-bottom: 0;
            }

            .table th {
                background-color: var(--ball-blue);
                color: white;
                font-weight: 500;
                padding: 0.75rem 1rem;
                border: none;
                white-space: nowrap;
            }

            .table td {
                padding: 0.75rem 1rem;
                vertical-align: middle;
                border-color: rgba(0, 0, 0, 0.05);
            }

            .table tr:hover {
                background-color: rgba(17, 64, 254, 0.03);
            }

            .user-item {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }

            .user-avatar {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background-size: 150%;
                background-position: center 20%;
                flex-shrink: 0;
                border: 2px solid var(--ball-blue-light);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            .user-placeholder {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                border: 2px solid var(--ball-blue-light);
                background-color: #e9ecef;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #adb5bd;
                flex-shrink: 0;
            }

            .gruppe-badge {
                display: inline-block;
                padding: 0.35em 0.65em;
                font-size: 0.75em;
                font-weight: 500;
                border-radius: 20px;
                white-space: nowrap;
            }

            .gruppe-badge.schichtarbeit {
                background-color: #dc3545;
                color: white;
            }

            .gruppe-badge.tagschicht {
                background-color: #fd7e14;
                color: white;
            }

            .gruppe-badge.verwaltung {
                background-color: #6610f2;
                color: white;
            }

            .crew-badge {
                display: inline-block;
                padding: 0.35em 0.65em;
                font-size: 0.75em;
                font-weight: 500;
                background-color: #e9ecef;
                color: #212529;
                border-radius: 20px;
            }

            .reset-btn {
                background-color: #dc3545;
                color: white;
                border: none;
                border-radius: 6px;
                padding: 0.375rem 0.75rem;
                font-size: 0.875rem;
                transition: all 0.2s ease-in-out;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
            }

            .reset-btn:hover {
                background-color: #bb2d3b;
                box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
            }

            .reset-btn:active {
                background-color: #a52834;
            }

            .alert {
                border-radius: 10px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .info-banner {
                display: flex;
                align-items: center;
                gap: 1rem;
                background-color: rgba(13, 202, 240, 0.1);
                border-left: 4px solid #0dcaf0;
                padding: 1rem;
                border-radius: 8px;
                margin-bottom: 1.5rem;
            }

            .search-box {
                position: relative;
                margin-bottom: 1.5rem;
            }

            .search-input {
                height: 45px;
                border-radius: 10px;
                border: 1px solid rgba(0, 0, 0, 0.1);
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
                padding: 0.75rem 1rem;
                transition: all 0.2s ease;
            }

            .search-input:focus {
                border-color: var(--ball-blue);
                box-shadow: 0 0 0 0.25rem rgba(17, 64, 254, 0.25);
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>

    <div class="container content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>Benutzerübersicht</h1>

            <!-- Statistik-Karten -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people-fill text-primary"></i>
                    </div>
                    <div class="stat-value"><?php echo $benutzer_count['total']; ?></div>
                    <div class="stat-label">Benutzer gesamt</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history text-danger"></i>
                    </div>
                    <div class="stat-value"><?php echo $benutzer_count['Schichtarbeit']; ?></div>
                    <div class="stat-label">Schichtarbeit</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-sun-fill text-warning"></i>
                    </div>
                    <div class="stat-value"><?php echo $benutzer_count['Tagschicht']; ?></div>
                    <div class="stat-label">Tagschicht</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-briefcase-fill text-purple"></i>
                    </div>
                    <div class="stat-value"><?php echo $benutzer_count['Verwaltung']; ?></div>
                    <div class="stat-label">Verwaltung</div>
                </div>
            </div>
        </div>

        <!-- Status Message -->
        <?php if (!empty($status_message)): ?>
            <div class="alert alert-<?php echo $status_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $status_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>-fill me-2"></i>
                <?php echo $status_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Hauptinhalt -->
        <div class="content-card">
            <!-- Info-Banner -->
            <div class="info-banner">
                <i class="bi bi-info-circle-fill text-info fs-4"></i>
                <div>
                    <strong>Hinweis:</strong> Das Standardpasswort zum Zurücksetzen lautet <strong>Ball1234</strong>.
                    Bitte teile dem Benutzer mit, dass er/sie ihr Passwort nach dem ersten Login ändern sollte.
                </div>
            </div>

            <!-- Suchfeld -->
            <div class="row mb-4">
                <div class="col-md-6 col-lg-4">
                    <input type="text" class="form-control search-input" id="userSearchInput"
                           placeholder="Benutzer suchen...">
                </div>
            </div>

            <!-- Benutzertabelle -->
            <div class="table-container">
                <?php if (!empty($benutzer_list)): ?>
                    <table class="table table-hover" id="userTable">
                        <thead>
                        <tr>
                            <th>Mitarbeiter</th>
                            <th>Benutzername</th>
                            <th>Gruppe</th>
                            <th>Team</th>
                            <th class="text-center">Aktion</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($benutzer_list as $benutzer): ?>
                            <tr>
                                <td>
                                    <div class="user-item">
                                        <?php if (!empty($benutzer['bild']) && $benutzer['bild'] !== 'kein-bild.jpg'): ?>
                                            <div class="user-avatar"
                                                 style="background-image: url('../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($benutzer['bild']); ?>')"></div>
                                        <?php else: ?>
                                            <div class="user-placeholder">
                                                <i class="bi bi-person-fill"></i>
                                            </div>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($benutzer['mitarbeiter_name']); ?>
                                    </div>
                                </td>
                                <td><code><?php echo htmlspecialchars($benutzer['username']); ?></code></td>
                                <td>
                                    <?php
                                    $gruppe_class = '';
                                    switch ($benutzer['gruppe']) {
                                        case 'Schichtarbeit':
                                            $gruppe_class = 'schichtarbeit';
                                            break;
                                        case 'Tagschicht':
                                            $gruppe_class = 'tagschicht';
                                            break;
                                        case 'Verwaltung':
                                            $gruppe_class = 'verwaltung';
                                            break;
                                    }
                                    ?>
                                    <span class="gruppe-badge <?php echo $gruppe_class; ?>">
                                        <?php echo htmlspecialchars($benutzer['gruppe']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($benutzer['crew'])): ?>
                                        <span class="crew-badge">
                                            <?php echo htmlspecialchars($benutzer['crew']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <!-- Formular zum Zurücksetzen des Passworts -->
                                    <form method="post" action="" class="d-inline"
                                          onsubmit="return confirm('Sind Sie sicher, dass Sie das Passwort für <?php echo htmlspecialchars($benutzer['mitarbeiter_name']); ?> zurücksetzen möchten?');">
                                        <input type="hidden" name="username"
                                               value="<?php echo htmlspecialchars($benutzer['username']); ?>">
                                        <input type="hidden" name="mitarbeiter_name"
                                               value="<?php echo htmlspecialchars($benutzer['mitarbeiter_name']); ?>">
                                        <button type="submit" name="reset_password" class="reset-btn">
                                            <i class="bi bi-key-fill"></i> Passwort zurücksetzen
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert alert-warning" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Keine Benutzer gefunden.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Lokales Bootstrap 5 JavaScript Bundle -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Suchfunktion -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('userSearchInput');
            const userTable = document.getElementById('userTable');

            if (searchInput && userTable) {
                searchInput.addEventListener('keyup', function () {
                    const searchTerm = this.value.toLowerCase();
                    const rows = userTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

                    for (let i = 0; i < rows.length; i++) {
                        const row = rows[i];
                        const text = row.textContent.toLowerCase();

                        if (text.indexOf(searchTerm) > -1) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            }
        });
    </script>
    </body>
    </html>

<?php
// Verbindung zur Datenbank schließen
$conn->close();
?>