<?php
/**
 * news_management.php
 *
 * Diese Seite ermöglicht es berechtigten Benutzern (HR oder IT),
 * alle Nachrichten zu verwalten: anzeigen, bearbeiten und löschen.
 */

include_once 'access_control.php';
global $conn;

// Zugriffskontrolle: Nur HR oder IT dürfen Nachrichten verwalten
if (!ist_hr() && !ist_it()) {
    header("Location: access_denied.php");
    exit;
}

// Nachrichten aus der Datenbank abrufen
$news_items = [];
$error_message = '';
$success_message = '';

// Erfolgsmeldung, wenn vom Löschen zurückgekehrt
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $success_message = "Die Nachricht wurde erfolgreich gelöscht.";
}

// Nachrichten abfragen
$sql = "SELECT n.*, e.name AS created_by_name
        FROM news n
        JOIN employees e ON n.created_by = e.employee_id
        ORDER BY n.created_at DESC";

try {
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $news_items[] = $row;
        }
    } else {
        $error_message = "Fehler beim Abrufen der Nachrichten: " . $conn->error;
    }
} catch (Exception $e) {
    $error_message = "Exception: " . $e->getMessage();
}

// Funktion, um den Status einer Nachricht zu ermitteln
function getNewsStatus($news) {
    $today = date('Y-m-d');

    if (empty($news['expiration_date'])) {
        return ['status' => 'Aktiv', 'class' => 'success'];
    } elseif ($news['expiration_date'] >= $today) {
        return ['status' => 'Aktiv bis ' . date('d.m.Y', strtotime($news['expiration_date'])), 'class' => 'success'];
    } else {
        return ['status' => 'Abgelaufen', 'class' => 'danger'];
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nachrichten verwalten</title>
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
            margin-bottom: 2rem;
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
            padding: 1.5rem;
        }

        .btn-add {
            background-color: var(--ball-blue);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 0.375rem;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-add:hover {
            background-color: var(--ball-blue-dark);
            transform: translateY(-2px);
            box-shadow: 0 0.3rem 0.5rem rgba(0,0,0,0.2);
            color: white;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
        }

        .table td {
            vertical-align: middle;
        }

        .news-title {
            font-weight: 500;
            color: #212529;
        }

        .news-content {
            color: #6c757d;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .badge {
            font-weight: 500;
            padding: 0.4rem 0.6rem;
        }

        .action-btn {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 0.25rem;
        }

        .btn-edit {
            color: #fff;
            background-color: #17a2b8;
            border-color: #17a2b8;
        }

        .btn-edit:hover {
            color: #fff;
            background-color: #138496;
            border-color: #117a8b;
        }

        .alert {
            margin-bottom: 1.5rem;
            border-radius: 0.375rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-state-icon {
            font-size: 3rem;
            color: #adb5bd;
            margin-bottom: 1rem;
        }

        .empty-state-text {
            color: #6c757d;
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container py-4">
    <div class="row">
        <div class="col-12">
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
                    <h2 class="text-white"><i class="fas fa-newspaper me-2"></i>Nachrichten verwalten</h2>
                    <a href="add_news.php" class="btn btn-add text-white">
                        <i class="fas fa-plus me-1"></i> Neue Nachricht
                    </a>
                </div>
                <div class="card-body">
                    <?php if (count($news_items) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                <tr>
                                    <th style="width: 35%">Titel</th>
                                    <th style="width: 20%">Erstellt am</th>
                                    <th style="width: 15%">Erstellt von</th>
                                    <th style="width: 15%">Status</th>
                                    <th style="width: 15%">Aktionen</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($news_items as $news): ?>
                                    <?php $status = getNewsStatus($news); ?>
                                    <tr>
                                        <td>
                                            <div class="news-title"><?php echo htmlspecialchars($news['title']); ?></div>
                                            <div class="news-content"><?php echo htmlspecialchars($news['content']); ?></div>
                                        </td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($news['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($news['created_by_name']); ?></td>
                                        <td>
                                                <span class="badge bg-<?php echo $status['class']; ?>">
                                                    <?php echo $status['status']; ?>
                                                </span>
                                        </td>
                                        <td>
                                            <a href="edit_news.php?id=<?php echo $news['id']; ?>" class="btn btn-sm btn-edit action-btn">
                                                <i class="fas fa-edit me-1"></i> Bearbeiten
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-newspaper empty-state-icon"></i>
                            <p class="empty-state-text">Noch keine Nachrichten vorhanden</p>
                            <a href="add_news.php" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i> Erste Nachricht erstellen
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>