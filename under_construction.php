<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Construction</title>
    <!-- Bootstrap CSS -->
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #1a1a1a;
            color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            font-family: 'Arial', sans-serif;
        }

        .construction-container {
            text-align: center;
            max-width: 600px;
            padding: 40px;
            background: #2c2c2c;
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0, 0, 0, 0.2);
        }

        .construction-container .icon {
            font-size: 5rem;
            color: #ffc107;
            margin-bottom: 25px;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        .construction-container h1 {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ffc107;
        }

        .construction-container p {
            font-size: 1.5rem;
            margin-bottom: 40px;
        }

        .progress {
            height: 30px;
            background-color: #343a40;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .progress-bar {
            font-size: 1.2rem;
            line-height: 30px;
        }

        .btn-home {
            background-color: #ffc107;
            border: none;
            color: #1a1a1a;
            padding: 10px 20px;
            font-size: 1rem;
            border-radius: 5px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .btn-home:hover {
            background-color: #e0a800;
        }
    </style>
</head>
<body>
<div class="construction-container">
    <div class="icon">🚧</div>
    <h1>Bald wieder online!</h1>
    <p>Die Datenbank wird gerade migriert/angepasst - PRiME ist am Nachmittag wieder erreichbar!</p>
    <div class="progress">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
             role="progressbar" style="width: 80%;"
             aria-valuenow="80" aria-valuemin="0" aria-valuemax="100">
            80% Fertig
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>