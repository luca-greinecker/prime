<?php
/**
 * edit_news.php
 *
 * Diese Seite ermöglicht es berechtigten Benutzern (HR oder IT),
 * eine bestehende Nachricht zu bearbeiten oder zu löschen.
 */

include_once 'access_control.php';
global $conn;

// Zugriffskontrolle: Nur HR oder IT dürfen Nachrichten bearbeiten
if (!ist_hr() && !ist_it()) {
    header("Location: access_denied.php");
    exit;
}

$success_message = '';
$error_message = '';
$news_item = null;
$news_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Prüfen, ob eine gültige News-ID übergeben wurde
if ($news_id <= 0) {
    header("Location: news_management.php");
    exit;
}

// News-Eintrag aus der Datenbank holen
$stmt = $conn->prepare("SELECT * FROM news WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $news_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $news_item = $result->fetch_assoc();
    } else {
        header("Location: news_management.php");
        exit;
    }
    $stmt->close();
} else {
    $error_message = "Fehler beim Abrufen der Nachricht: " . $conn->error;
}

// Löschen einer Nachricht
if (isset($_POST['delete']) && $_POST['delete'] == 1) {
    $stmt = $conn->prepare("DELETE FROM news WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $news_id);
        if ($stmt->execute()) {
            header("Location: news_management.php?deleted=1");
            exit;
        } else {
            $error_message = "Fehler beim Löschen der Nachricht: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Fehler beim Vorbereiten der Löschabfrage: " . $conn->error;
    }
}

// Aktualisieren einer Nachricht
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete'])) {
    $title = $_POST['title'];
    $content = $_POST['content'];
    $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : NULL;

    $stmt = $conn->prepare("UPDATE news SET title = ?, content = ?, expiration_date = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("sssi", $title, $content, $expiration_date, $news_id);
        if ($stmt->execute()) {
            $success_message = "Nachricht wurde erfolgreich aktualisiert!";
            // Nachricht neu laden
            $stmt = $conn->prepare("SELECT * FROM news WHERE id = ?");
            $stmt->bind_param("i", $news_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $news_item = $result->fetch_assoc();
        } else {
            $error_message = "Fehler beim Aktualisieren der Nachricht: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Fehler beim Vorbereiten des Update-Statements: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nachricht bearbeiten</title>
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

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            padding: 0.5rem 1.5rem;
            font-weight: 500;
        }

        .alert {
            margin-bottom: 1.5rem;
            border-radius: 0.375rem;
        }

        .modal-content {
            border-radius: 0.5rem;
            border: none;
        }

        .modal-header {
            background-color: #dc3545;
            color: white;
            border-radius: 0.5rem 0.5rem 0 0;
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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="text-white"><i class="fas fa-edit me-2"></i>Nachricht bearbeiten</h2>
                    <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#deleteModal">
                        <i class="fas fa-trash-alt me-1"></i> Löschen
                    </button>
                </div>
                <div class="card-body">
                    <form action="edit_news.php?id=<?php echo $news_id; ?>" method="post">
                        <div class="mb-3">
                            <label for="title" class="form-label">Titel</label>
                            <input type="text" name="title" id="title" class="form-control"
                                   value="<?php echo htmlspecialchars($news_item['title']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="content" class="form-label">Inhalt</label>
                            <textarea name="content" id="content" class="form-control" rows="6" required><?php echo htmlspecialchars($news_item['content']); ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="expiration_date" class="form-label">Ablaufdatum (optional)</label>
                            <input type="date" name="expiration_date" id="expiration_date" class="form-control"
                                   value="<?php echo !empty($news_item['expiration_date']) ? $news_item['expiration_date'] : ''; ?>">
                            <div class="form-text text-muted">
                                Wenn kein Datum angegeben wird, läuft die Nachricht nicht ab.
                            </div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <a href="news_management.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Zurück zur Übersicht
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Änderungen speichern
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Lösch-Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Nachricht löschen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Sind Sie sicher, dass Sie diese Nachricht löschen möchten?</p>
                <p class="fw-bold"><?php echo htmlspecialchars($news_item['title']); ?></p>
                <p class="text-muted small">Diese Aktion kann nicht rückgängig gemacht werden.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <form action="edit_news.php?id=<?php echo $news_id; ?>" method="post">
                    <input type="hidden" name="delete" value="1">
                    <button type="submit" class="btn btn-danger">Löschen bestätigen</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>