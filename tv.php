<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>TV-Anzeige</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
        }

        #mainFrame {
            width: 100%;
            height: 100%;
            border: none;
        }

        #errorMessage {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            background: red;
            color: white;
            text-align: center;
            padding: 1em;
            z-index: 1000;
        }
    </style>
</head>
<body>
<!-- Diese Meldung wird angezeigt, wenn der iFrame nicht richtig geladen werden kann -->
<div id="errorMessage">Server nicht erreichbar. Versuche neu zu laden...</div>

<!-- iFrame, der deine TV-Seite lädt -->
<iframe id="mainFrame" src="tv1.php"></iframe>

<script>
    var iframe = document.getElementById('mainFrame');
    var errorMessage = document.getElementById('errorMessage');

    // Funktion, um den iFrame zu prüfen
    function checkIframe() {
        try {
            // Greife auf das Dokument im iFrame zu (funktioniert nur, wenn gleiche Domain)
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            // Falls der iFrame-Dokumentinhalt vorhanden ist und mindestens etwas enthält,
            // gehen wir davon aus, dass die Seite korrekt geladen wurde
            if (doc && doc.body && doc.body.innerHTML.length > 0) {
                errorMessage.style.display = "none";
                return true;
            }
        } catch (e) {
            // Falls ein Fehler auftritt (z. B. bei einem Serverfehler) bleibt hier der Catch-Zweig
        }
        errorMessage.style.display = "block";
        return false;
    }

    // Überprüfe alle 5 Sekunden, ob der iFrame geladen ist
    setInterval(function () {
        if (!checkIframe()) {
            // Falls die Prüfung fehlschlägt, den iFrame neu laden
            iframe.src = iframe.src;
        }
    }, 5000);

    // onload-Event: Sobald der iFrame lädt, wird die Fehleranzeige entfernt
    iframe.onload = function () {
        errorMessage.style.display = "none";
    };
</script>
</body>
</html>