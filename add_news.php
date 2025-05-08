<?php
/**
 * add_news.php
 *
 * Diese Seite ermöglicht es berechtigten Benutzern (HR oder IT),
 * eine neue Nachricht in die News-Tabelle einzufügen.
 * Die Nachricht wird mit Titel, Inhalt, Ablaufdatum und dem Ersteller (employee_id)
 * in die Datenbank geschrieben.
 */

include_once 'access_control.php';
global $conn;

// Zugriffskontrolle: Nur HR oder IT dürfen Nachrichten erstellen
if (!ist_hr() && !ist_it()) {
    header("Location: access_denied.php");
    exit;
}

$success_message = '';
$error_message = '';

// Verarbeiten des Formular-POST-Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Eingaben aus dem Formular auslesen
    $title = $_POST['title'];
    $content = $_POST['content'];
    $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : NULL;

    // Ersteller der Nachricht: Hier wird der interne Mitarbeiter-Schlüssel (employee_id) verwendet
    $created_by = $_SESSION['mitarbeiter_id'];

    // SQL-Statement vorbereiten: Neue Nachricht in die Tabelle news einfügen
    $stmt = $conn->prepare("INSERT INTO news (title, content, created_by, expiration_date) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        $error_message = "Fehler bei der Vorbereitung des Statements: " . $conn->error;
    } else {
        $stmt->bind_param("ssis", $title, $content, $created_by, $expiration_date);

        // Ausführen des Statements und bei Erfolg zur Startseite umleiten
        if ($stmt->execute()) {
            $success_message = "Nachricht wurde erfolgreich hinzugefügt!";
            // Kurze Verzögerung für die Anzeige der Erfolgsmeldung
            header("Refresh: 2; URL=index.php");
        } else {
            $error_message = 'Fehler beim Hinzufügen der Nachricht: ' . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nachricht hinzufügen</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
    <style>
        :root {
            --ball-blue: #1140FE;
            --ball-blue-light: #4169FE;
            --ball-blue-dark: #0030DD;
        }

        body {
            background-color: #f8f9fa;
            padding-top: 70px; /* Platz für die Navbar */
        }

        .card {
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border-radius: 0.5rem;
        }

        .card-header {
            background: linear-gradient(135deg, var(--ball-blue), var(--ball-blue-dark));
            color: white;
            border-radius: 0.5rem 0.5rem 0 0 !important;
            padding: 1.2rem 1.5rem;
        }

        .card-header h2 {
            margin-bottom: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .card-body {
            padding: 2rem;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-control {
            border-radius: 0.375rem;
            border: 1px solid #ced4da;
            padding: 0.5rem 0.75rem;
        }

        .form-control:focus {
            border-color: var(--ball-blue);
            box-shadow: 0 0 0 0.25rem rgba(17, 64, 254, 0.25);
        }

        .btn-primary {
            background-color: var(--ball-blue);
            border-color: var(--ball-blue);
            padding: 0.5rem 1.5rem;
            font-weight: 500;
        }

        .btn-primary:hover {
            background-color: var(--ball-blue-dark);
            border-color: var(--ball-blue-dark);
        }

        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            padding: 0.5rem 1.5rem;
            font-weight: 500;
        }

        .alert {
            margin-bottom: 1.5rem;
            border-radius: 0.375rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2 class="text-white"><i class="fas fa-newspaper me-2"></i>Neue Nachricht erstellen</h2>
                </div>
                <div class="card-body">
                    <form action="add_news.php" method="post">
                        <div class="mb-3">
                            <label for="title" class="form-label">Titel</label>
                            <input type="text" name="title" id="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="content" class="form-label">Inhalt</label>
                            <textarea name="content" id="content" class="form-control" rows="6" required></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="expiration_date" class="form-label">Ablaufdatum (optional)</label>
                            <input type="date" name="expiration_date" id="expiration_date" class="form-control">
                            <div class="form-text text-muted">
                                Wenn kein Datum angegeben wird, läuft die Nachricht nicht ab.
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <a href="index.php" class="btn btn-secondary me-2">
                                <i class="fas fa-times me-1"></i> Abbrechen
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Nachricht speichern
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>