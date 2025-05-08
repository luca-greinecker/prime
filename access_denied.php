<?php
/**
 * access_denied.php
 *
 * Diese Seite wird angezeigt, wenn einem Benutzer der Zugriff verweigert wird.
 * Es wird geprüft, ob bereits eine Session aktiv ist – andernfalls wird sie gestartet.
 */

// Session starten, falls noch nicht geschehen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zugriff verweigert</title>
    <!-- Bootstrap CSS einbinden -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <style>
        /* Grundlegendes Styling für die Zugriff verweigert-Seite */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f8f9fa;
            font-family: 'Roboto', sans-serif;
        }
        .container {
            text-align: center;
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 0 30px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
        }
        .container h1 {
            font-size: 2.5rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .container p {
            font-size: 1.2rem;
            color: #6c757d;
            margin-bottom: 30px;
        }
        .btn-group {
            display: flex;
            justify-content: space-around;
        }
        .btn {
            background-color: #007bff;
            color: #fff;
            padding: 10px 20px;
            font-size: 1rem;
            border-radius: 5px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }
        .btn:hover {
            background-color: #0056b3;
        }
        .btn-back {
            background-color: #6c757d;
        }
        .btn-back:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Zugriff verweigert</h1>
    <p>Sie haben keine Berechtigung, diese Seite zu sehen.</p>
    <div class="btn-group">
        <button onclick="history.back();" class="btn btn-back">Zurück</button>
        <a href="index.php" class="btn">Zur Startseite</a>
    </div>
</div>
<!-- Bootstrap JS Bundle einbinden -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
