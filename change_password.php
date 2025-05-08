<?php
/**
 * change_password.php
 *
 * Diese Seite ermöglicht es einem angemeldeten Benutzer, sein Passwort zu ändern.
 * Voraussetzung: Der interne Mitarbeiter-Schlüssel (employee_id bzw. in der Session als mitarbeiter_id)
 * ist in der Session gespeichert.
 *
 * Es wird überprüft, ob das aktuelle Passwort korrekt ist, bevor das neue Passwort gesetzt wird.
 * Nach erfolgreicher Änderung wird eine Bestätigung angezeigt.
 */

// Session starten, falls noch nicht aktiv
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php'; // Datenbank-Verbindung einbinden

global $conn;

// Sicherstellen, dass der Benutzer eingeloggt ist
if (!isset($_SESSION['mitarbeiter_id'])) {
    header("Location: login.php");
    exit;
}

$mitarbeiter_id = $_SESSION['mitarbeiter_id']; // Interner Mitarbeiter-Schlüssel
$message = '';

// Verarbeitung des Formular-POST-Requests
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Überprüfen, ob alle erforderlichen Felder ausgefüllt wurden
    if (!empty($_POST['current_password']) && !empty($_POST['new_password']) && !empty($_POST['confirm_password'])) {
        $current_password = $_POST['current_password'];
        $new_password     = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Überprüfen, ob das neue Passwort und dessen Bestätigung übereinstimmen
        if ($new_password === $confirm_password) {
            // Das aktuelle Passwort des Benutzers aus der Datenbank abrufen
            $stmt = $conn->prepare("SELECT password FROM benutzer_matool WHERE mitarbeiter_id = ?");
            if (!$stmt) {
                die("Fehler bei der Vorbereitung des SELECT-Statements: " . $conn->error);
            }
            $stmt->bind_param("i", $mitarbeiter_id);
            $stmt->execute();
            $stmt->store_result();
            $stmt->bind_result($hashed_password);
            $stmt->fetch();

            if ($stmt->num_rows > 0) {
                // Prüfen, ob das eingegebene aktuelle Passwort korrekt ist
                if (password_verify($current_password, $hashed_password)) {
                    // Neues Passwort hashen
                    $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                    // Update-Statement zum Aktualisieren des Passworts ausführen
                    $update_stmt = $conn->prepare("UPDATE benutzer_matool SET password = ? WHERE mitarbeiter_id = ?");
                    if (!$update_stmt) {
                        die("Fehler bei der Vorbereitung des UPDATE-Statements: " . $conn->error);
                    }
                    $update_stmt->bind_param("si", $new_hashed_password, $mitarbeiter_id);

                    if ($update_stmt->execute()) {
                        $message = "Passwort erfolgreich geändert!";
                    } else {
                        $message = "Fehler beim Aktualisieren des Passworts.";
                    }
                    $update_stmt->close();
                } else {
                    $message = "Das aktuelle Passwort ist falsch.";
                }
            } else {
                $message = "Benutzerkonto nicht gefunden.";
            }
            $stmt->close();
        } else {
            $message = "Die neuen Passwörter stimmen nicht überein.";
        }
    } else {
        $message = "Bitte füllen Sie alle Felder aus.";
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort ändern</title>
    <!-- Lokales Bootstrap 5 CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <style>
        /* Grundlegende Styles für die Passwortänderungsseite */
        body {
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            width: 350px;
            padding: 20px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        .container h2 {
            margin-bottom: 20px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
            color: #343a40;
        }
        .mb-3 {
            margin-bottom: 15px;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            width: 100%;
        }
        .btn-secondary {
            width: 100%;
            margin-top: 10px;
        }
        .alert {
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Passwort ändern</h2>
    <!-- Anzeige von Meldungen, wenn das Formular abgeschickt wurde -->
    <?php if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($message)): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <label for="current_password">Aktuelles Passwort:</label>
            <input type="password" class="form-control" id="current_password" name="current_password" required>
        </div>
        <div class="mb-3">
            <label for="new_password">Neues Passwort:</label>
            <input type="password" class="form-control" id="new_password" name="new_password" required>
        </div>
        <div class="mb-3">
            <label for="confirm_password">Neues Passwort bestätigen:</label>
            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn btn-primary">Passwort ändern</button>
    </form>
    <form action="index.php">
        <button type="submit" class="btn btn-secondary">Zurück zur Startseite</button>
    </form>
</div>

<!-- Einbindung von Bootstrap JS und Abhängigkeiten (via CDN) -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
