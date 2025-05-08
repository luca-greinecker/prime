<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>TV-Anzeige (Slideshow)</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link href="navbar.css" rel="stylesheet">
    <style>
        body {
        background-color: #1140FE;
        color: #ffffff;
        overflow: hidden;
        margin: 0 auto;
        padding: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        max-width: 1300px;
        }

        .header-text {
            display: flex;
            align-items: center;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 2rem 0;
        }

        .mitarbeiter-container {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .mitarbeiter-card {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 40px;
            border-radius: 12px;
            width: 300px;
            height: 480px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            text-align: center;
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
            font-size: 1.5rem;
            font-weight: bold;
            color: #ffffff;
        }

        .mitarbeiter-details {
            font-size: 1rem;
            color: #f0f0f0;
        }
        .fade-enter {
            opacity: 0;
            transform: translateX(30px);
        }
        .fade-enter-active {
            transition: all 0.5s;
            opacity: 1;
            transform: translateX(0);
        }
        .fade-exit {
            opacity: 1;
            transform: translateX(0);
        }
        .fade-exit-active {
            transition: all 0.5s;
            opacity: 0;
            transform: translateX(-30px);
        }
    </style>
</head>
<body style="background-color:#1140FE; color:#fff;">
<div class="header-text">
    WIR SIND&nbsp;&nbsp;<img src="../mitarbeiter-anzeige/fotos/ball-logo.png" alt="Ball Logo" width="100">&nbsp;&nbsp;LUDESCH
</div>
<div id="mitarbeiterWrapper" class="mitarbeiter-container"></div>

<script>
    const wrapper = document.getElementById('mitarbeiterWrapper');
    let currentData = [];

    /**
     * Funktion: Läd per Ajax drei neue MA und updated DOM
     */
    function loadRandomEmployees() {
        fetch('random_employees.php')
            .then(res => res.json())
            .then(data => {
                // Exit-Animation (optional):
                wrapper.classList.remove('fade-enter-active');
                wrapper.classList.add('fade-exit');
                setTimeout(() => {
                    wrapper.classList.remove('fade-exit');
                    wrapper.classList.add('fade-exit-active');
                }, 10);

                // Nach Wartezeit die neuen Daten einsetzen:
                setTimeout(() => {
                    wrapper.innerHTML = '';

                    // Fallback, falls < 3 Datensätze existieren
                    if (data.length === 0) {
                        wrapper.innerHTML = '<div>Keine Mitarbeiter mit Bild.</div>';
                        return;
                    }

                    // Neue Cards erzeugen
                    data.forEach(person => {
                        let card = document.createElement('div');
                        card.className = 'mitarbeiter-card';

                        let img = document.createElement('img');
                        img.className = 'mitarbeiter-bild';
                        img.src = '../mitarbeiter-anzeige/fotos/' + person.bild;
                        card.appendChild(img);

                        let nameDiv = document.createElement('div');
                        nameDiv.className = 'mitarbeiter-name';
                        nameDiv.textContent = person.name;
                        card.appendChild(nameDiv);

                        let detailDiv = document.createElement('div');
                        detailDiv.className = 'mitarbeiter-details';
                        let crew = person.crew && person.crew !== '---' ? person.crew + ' – ' : '';
                        detailDiv.textContent = crew + person.position;
                        card.appendChild(detailDiv);

                        wrapper.appendChild(card);
                    });

                    // Neue Enter-Animation
                    wrapper.classList.remove('fade-exit-active');
                    wrapper.classList.add('fade-enter');
                    setTimeout(() => {
                        wrapper.classList.remove('fade-enter');
                        wrapper.classList.add('fade-enter-active');
                    }, 10);
                }, 500); // Warte 500ms, um Exit-Animation abspielen zu lassen
            })
            .catch(err => {
                console.error('Fehler beim Laden', err);
            });
    }

    // Intervall: alle 10s neu laden
    setInterval(loadRandomEmployees, 10000);

    // Direkt beim Start
    loadRandomEmployees();
</script>
</body>
</html>
