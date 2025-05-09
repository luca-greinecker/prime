<?php
// tv_anzeige.php

include 'db.php';
global $conn;

/**
 * Holt drei zufällige Mitarbeiter, die ein Bild haben (nicht "kein-bild.jpg").
 * Archivierte Mitarbeiter (status = 9999) werden ausgeblendet.
 */
function getEmployees()
{
    global $conn;
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $stmt = $conn->prepare("
        SELECT employee_id, name, ind_name, crew, position, bild
        FROM employees
        WHERE bild <> 'kein-bild.jpg' AND status != 9999
        ORDER BY RAND()
        LIMIT 3
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $employees;
}

/**
 * Entfernt den "Schicht - " Präfix aus der Positionsbeschreibung.
 * Erfasst unterschiedlich viele Leerzeichen,
 * z. B. "Schicht -Mechanik" => "Mechanik"
 */
function removeSchichtPrefix($pos)
{
    return preg_replace('/^schicht\s*-\s*/i', '', $pos);
}

// ------------------------------------------------------
// AJAX-Modus: Gibt nur das HTML für die Mitarbeiter-Karten aus
// ------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    $employees = getEmployees();

    foreach ($employees as $person):
        // 1) Name vs. ind_name
        $displayName = !empty($person['ind_name']) ? $person['ind_name'] : $person['name'];
        // 2) Position-Präfix entfernen
        $pos = removeSchichtPrefix($person['position']);
        ?>
        <div class="mitarbeiter-card">
            <img src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($person['bild']); ?>"
                 alt="Bild von <?php echo htmlspecialchars($displayName); ?>"
                 class="mitarbeiter-bild">
            <div class="mitarbeiter-name"><?php echo htmlspecialchars($displayName); ?></div>
            <div class="mitarbeiter-details">
                <?php
                if (!empty($person['crew']) && $person['crew'] !== '---') {
                    echo htmlspecialchars($person['crew']) . ' – ';
                }
                echo htmlspecialchars($pos);
                ?>
            </div>
        </div>
    <?php
    endforeach;
    exit;
}

// ------------------------------------------------------
// Normaler Seitenaufruf
// ------------------------------------------------------
$employees = getEmployees();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mitarbeiteranzeige</title>
    <link href="navbar.css" rel="stylesheet">
    <style>
        /* Körper: */
        html, body {
            margin: 0;
            padding: 0;
            background-color: #1140FE;
            color: #fff;
            width: 100%;
            height: 100%;
        }

        /* Überschrift */
        .header-text {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            margin: 30px auto 20px auto;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.3);
        }

        /* Container, in dem wir fade-in/out machen */
        .mitarbeiter-container-wrapper {
            display: flex;
            justify-content: center;
        }

        .mitarbeiter-container {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            opacity: 0;
            transition: opacity 1s ease;
        }

        .mitarbeiter-container.show {
            opacity: 1;
        }

        .mitarbeiter-container.hide {
            opacity: 0;
        }

        /* Einzelne Karte */
        .mitarbeiter-card {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 35px;
            border-radius: 12px;
            width: 310px;
            height: 490px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
        }

        .mitarbeiter-bild {
            width: 100%;
            height: 400px;
            object-fit: cover;
            margin-bottom: 15px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .mitarbeiter-name {
            font-size: 1.65rem;
            font-weight: bold;
            color: #ffffff;
            text-align: center;
        }

        .mitarbeiter-details {
            font-size: 1.15rem;
            color: #f0f0f0;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="header-text">
    WIR SIND&nbsp;&nbsp;
    <img src="../mitarbeiter-anzeige/fotos/ball-logo.png" alt="Ball Logo" width="120">
    &nbsp;&nbsp;LUDESCH
</div>

<div class="mitarbeiter-container-wrapper">
    <div class="mitarbeiter-container" id="employeeContainer">
        <?php foreach ($employees as $person): ?>
            <?php
            $displayName = !empty($person['ind_name']) ? $person['ind_name'] : $person['name'];
            $pos = removeSchichtPrefix($person['position']);
            ?>
            <div class="mitarbeiter-card">
                <img
                        src="../mitarbeiter-anzeige/fotos/<?php echo htmlspecialchars($person['bild']); ?>"
                        alt="Bild von <?php echo htmlspecialchars($displayName); ?>"
                        class="mitarbeiter-bild">
                <div class="mitarbeiter-name"><?php echo htmlspecialchars($displayName); ?></div>
                <div class="mitarbeiter-details">
                    <?php
                    if (!empty($person['crew']) && $person['crew'] !== '---') {
                        echo htmlspecialchars($person['crew']) . ' – ';
                    }
                    echo htmlspecialchars($pos);
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    /**
     * Prelädt eine Liste von Bildern, bevor der Callback aufgerufen wird.
     */
    function preloadImages(urls, callback) {
        let loaded = 0;
        const images = [];
        urls.forEach(url => {
            const img = new Image();
            img.src = url;
            img.onload = () => {
                loaded++;
                if (loaded === urls.length) {
                    callback();
                }
            };
            images.push(img);
        });
    }

    /**
     * Sammelt alle Bild-URLs aus dem aktuellen Container.
     */
    function getImageUrls() {
        const imgs = document.querySelectorAll('.mitarbeiter-bild');
        const urls = [];
        imgs.forEach(img => urls.push(img.src));
        return urls;
    }

    // Container reference
    const container = document.getElementById('employeeContainer');

    // Erstmal Bilder laden, dann .show => Fade-In
    const initialUrls = getImageUrls();
    preloadImages(initialUrls, () => {
        container.classList.add('show');
    });

    /**
     * AJAX: Neue Mitarbeiter-Karten laden, Bilder preladen,
     * Fade-Out + Fade-In
     */
    function loadNewEmployees() {
        // Fade-Out
        container.classList.remove('show');
        container.classList.add('hide');

        // Warte, bis die Animation "sichtbar" ausgeführt ist (1s)
        setTimeout(() => {
            fetch('?ajax=1')
                .then(res => res.text())
                .then(html => {
                    container.innerHTML = html;
                    const newUrls = [];
                    container.querySelectorAll('.mitarbeiter-bild').forEach(img => {
                        newUrls.push(img.src);
                    });
                    // Neue Bilder erst preloaden => dann fade-in
                    preloadImages(newUrls, () => {
                        container.classList.remove('hide');
                        container.classList.add('show');
                    });
                })
                .catch(err => {
                    console.error('Fehler beim Neuladen:', err);
                });
        }, 1000);
    }

    // Alle 20 Sekunden: Neue Mitarbeiter
    setInterval(loadNewEmployees, 20000);
</script>
</body>
</html>