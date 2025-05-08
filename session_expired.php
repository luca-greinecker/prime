<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Sitzung abgelaufen</title>
    <!-- Lokales Bootstrap 5 CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa; /* freundliches, helles Design */
        }

        .session-expired-container {
            max-width: 700px;
            margin: 0 auto;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 0;
            background-color: #f8f9fa;
        }

        .alert-heading {
            color: #dc3545; /* Rot für die Überschrift */
        }
    </style>
</head>
<body>
<div class="container session-expired-container">
    <div class="alert alert-warning text-center mb-0" role="alert">
        <div class="text-center mb-3 mt-4">
            <img src="assets/bilder/session_expired1.png"
                 alt="Sitzung abgelaufen"
                 class="img-fluid mb-3"
                 style="max-height: 150px;">
        </div>
        <h4 class="alert-heading fw-bold">Sitzung abgelaufen!</h4>
        <p class="mb-4">
            Aus Sicherheitsgründen wurden Sie automatisch ausgeloggt.
            Falls Sie gerade ein Mitarbeitergespräch durchgeführt haben,
            wurden Ihre Eingaben gespeichert.
        </p>
        <hr>
        <p class="mb-2 mt-4">
            <a href="login.php" class="btn btn-warning btn-lg">Erneut anmelden</a>
        </p>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>