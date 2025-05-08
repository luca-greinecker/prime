// session_check.js
// Prüft in regelmäßigen Abständen per Ajax, ob die Session noch gültig ist.
// Läuft die Session ab, wird automatisch auf session_expired.php weitergeleitet.

// Hinweis: Dieses Skript benötigt jQuery.
// Binde es in deine Seite ein, nachdem jQuery geladen wurde,
// z. B. <script src="session_check.js"></script> ganz unten im Body.
// Passe das Intervall (30 Sekunden) oder die URL (keep_alive.php) nach Bedarf an.

function checkSession() {
    $.ajax({
        url: 'keep_alive.php',
        method: 'POST',
        dataType: 'json',
        success: function (response) {
            if (response.status === 'session_expired') {
                window.location.href = 'session_expired.php';
            }
        },
        error: function () {
            // Falls es einen Fehler bei der Anfrage gibt,
            // gehen wir davon aus, dass die Session evtl. abgelaufen ist
            window.location.href = 'session_expired.php';
        }
    });
}

// Alle 30 Sekunden überprüfen (z. B. die Hälfte der Session-Lebensdauer)
setInterval(checkSession, 30000);

// Beim Laden der Seite einmal initial checken
$(document).ready(function () {
    checkSession();
});