<?php
/**
 * login.php
 *
 * Login-Seite für das System. Da der Benutzer hier noch nicht eingeloggt ist,
 * verzichten wir auf die normalen Zugriffsprüfungen.
 *
 * Änderungen:
 * - Nach erfolgreichem Login wird geprüft, ob ein Redirect-Ziel in $_SESSION['redirect_to'] gesetzt ist.
 *   Falls ja, erfolgt die Weiterleitung dorthin; andernfalls wird zu index.php weitergeleitet.
 */

include 'db.php';
global $conn;

// Wartungsmodus aktivieren/deaktivieren
$wartungsmodus = false; // auf true setzen, um den Wartungsmodus zu aktivieren
if ($wartungsmodus) {
    header("Location: under_construction.php");
    exit;
}

// Session starten, falls noch nicht geschehen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Zufälliges Hintergrundbild auswählen
$backgroundImages = [
    'assets/bilder/background-blue.png',
    'assets/bilder/background-black.png'
];
$randomBackground = $backgroundImages[array_rand($backgroundImages)];

// Formularauswertung
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'];
    $password = trim($_POST['password']);

    // Benutzer in der Tabelle benutzer_matool suchen
    $stmt = $conn->prepare("SELECT id, username, password, mitarbeiter_id FROM benutzer_matool WHERE username = ?");
    if (!$stmt) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    // Benutzer gefunden?
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Passwort überprüfen (gehasht in der DB)
        if (password_verify($password, $user['password'])) {
            // Session-Variablen setzen
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['mitarbeiter_id'] = $user['mitarbeiter_id'];
            // Beispiel: admin-Flag, wenn username = 'admin'
            $_SESSION['ist_admin'] = ($user['username'] === 'admin');

            // Weiterleitung: Falls ein Redirect-Ziel gesetzt wurde, dorthin springen, ansonsten zu index.php
            $redirect = isset($_SESSION['redirect_to']) ? $_SESSION['redirect_to'] : 'index.php';
            unset($_SESSION['redirect_to']);
            header("Location: " . $redirect);
            exit;
        } else {
            $error = "Falsches Passwort.";
        }
    } else {
        $error = "Benutzername nicht gefunden.";
    }
    $stmt->close();
}
?>

    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Anmeldung - PRiME</title>
        <!-- Lokale Bootstrap 5 und Font Awesome CSS -->
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="assets/fontawesome/css/all.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
        <link rel="icon" href="assets/bilder/ball-logo.ico" type="image/x-icon">
        <style>
            :root {
                --primary-color: #1140fe;
                --primary-dark: #0a2fb9;
                --secondary-color: #6c757d;
                --accent-color: #64d6ff;
                --card-bg: rgba(255, 255, 255, 0.8);
                --dark-overlay: rgba(10, 20, 45, 0.55);
                --box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
                --backdrop-blur: blur(10px);
                --border-radius: 16px;
            }

            body {
                margin: 0;
                height: 100vh;
                background-image: url('<?php echo $randomBackground; ?>');
                background-size: cover;
                background-position: center top;
                background-attachment: fixed;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                overflow: hidden;
            }

            body::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: var(--dark-overlay);
                z-index: -1;
            }

            .login-container {
                width: 100%;
                max-width: 420px;
                position: relative;
                z-index: 10;
            }

            .glass-card {
                background: var(--card-bg);
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                backdrop-filter: var(--backdrop-blur);
                -webkit-backdrop-filter: var(--backdrop-blur);
                border: 1px solid rgba(255, 255, 255, 0.18);
                padding: 2.5rem;
                text-align: center;
                overflow: hidden;
                position: relative;
            }

            .logo-container {
                margin-bottom: 2rem;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .logo-container img {
                width: 100px;
                height: 100px;
                object-fit: contain;
            }

            .divider {
                width: 4px;
                height: 60px;
                background-color: var(--primary-color);
                margin: 0 1rem;
            }

            .app-title {
                font-size: 2.5rem;
                font-weight: 700;
                color: var(--primary-color);
                margin: 0;
                letter-spacing: 1px;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            }

            .input-group {
                position: relative;
                margin-bottom: 1.5rem;
            }

            .form-control {
                height: 50px;
                padding-left: 3rem;
                background-color: rgba(255, 255, 255, 0.9);
                border: none;
                border-radius: 25px !important;
                font-size: 1rem;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }

            .form-control:focus {
                background-color: white;
                box-shadow: 0 4px 15px rgba(17, 64, 254, 0.25);
            }

            .form-icon {
                position: absolute;
                left: 15px;
                top: 50%;
                transform: translateY(-50%);
                color: var(--primary-color);
                font-size: 1.2rem;
                z-index: 10;
            }

            .btn-login {
                height: 50px;
                background: linear-gradient(45deg, var(--primary-color), var(--primary-dark));
                border: none;
                border-radius: 25px;
                color: white;
                font-weight: 600;
                letter-spacing: 1px;
                text-transform: uppercase;
                box-shadow: 0 4px 15px rgba(17, 64, 254, 0.3);
                width: 100%;
                margin-top: 1rem;
            }

            .btn-login:hover, .btn-login:focus {
                background: linear-gradient(45deg, var(--primary-dark), var(--primary-color));
                box-shadow: 0 6px 20px rgba(17, 64, 254, 0.4);
            }

            .alert {
                border-radius: 12px;
                padding: 1rem;
                margin-bottom: 1.5rem;
                position: relative;
            }

            .help-links {
                margin-top: 1.5rem;
            }

            .help-link {
                color: var(--secondary-color);
                background: none;
                border: none;
                padding: 0;
                font-size: 0.875rem;
                text-decoration: underline;
                cursor: pointer;
                margin-top: 0.5rem;
                display: inline-block;
            }

            .help-link:hover {
                color: var(--primary-color);
            }

            @media (max-width: 576px) {
                .glass-card {
                    padding: 1.5rem;
                    margin: 0 1rem;
                }

                .app-title {
                    font-size: 2rem;
                }
            }
        </style>
    </head>
    <body>
    <div class="login-container">
        <div class="glass-card">
            <div class="logo-container">
                <img src="assets/bilder/ball-logo.png" alt="Ball Logo" class="logo">
                <div class="divider"></div>
                <h1 class="app-title">PRiME</h1>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="input-group">
                    <i class="fas fa-user form-icon"></i>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Benutzername"
                           required autocomplete="username">
                </div>

                <div class="input-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Passwort"
                           required autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Anmelden
                </button>
            </form>

            <div class="help-links">
                <button class="help-link" data-bs-toggle="modal" data-bs-target="#helpModal1">
                    <i class="fas fa-question-circle me-1"></i>Wie bekomme ich meine Daten?
                </button>
                <br>
                <button class="help-link" data-bs-toggle="modal" data-bs-target="#helpModal2">
                    <i class="fas fa-key me-1"></i>Benutzername/Passwort vergessen?
                </button>
            </div>
        </div>
    </div>

    <!-- Help Modals -->
    <div class="modal fade" id="helpModal1" tabindex="-1" aria-labelledby="helpModalLabel1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="helpModalLabel1">Wie bekomme ich meine Daten?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Die Zugangsdaten bekommt jeder Mitarbeiter normalerweise beim Eintritt. Falls du deine Daten
                        vergessen hast, oder gar nicht bekommen hast, wende dich bitte an deinen Vorgesetzten.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="helpModal2" tabindex="-1" aria-labelledby="helpModalLabel2" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="helpModalLabel2">Benutzername/Passwort vergessen?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Wenn du deinen Benutzernamen/dein Passwort vergessen hast, wende dich bitte an die HR-Abteilung
                        (Info → Team). Diese kann dein Passwort zurücksetzen, und deinen Benutzernamen einsehen. Für
                        gewöhnlich ist der Benutzername gleich wie dein Benutzername für die Ball-Systeme.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Lokales Bootstrap 5 JavaScript Bundle -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>

<?php
$conn->close();
?>