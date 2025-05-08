<?php
if (!defined('SESSION_MANAGER_INCLUDED')) {
    define('SESSION_MANAGER_INCLUDED', true);

    // Session-Timeout in Sekunden festlegen
    if (!defined('SESSION_TIMEOUT')) {
        define('SESSION_TIMEOUT', 2700); // 45 Minuten
    }

    // Setze die Lebensdauer der Sitzung und des Cookies basierend auf SESSION_TIMEOUT
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
    ini_set('session.cookie_lifetime', SESSION_TIMEOUT);

    session_start();

    // Funktion zum Überprüfen und Behandeln des Session-Timeouts
    function handle_session_timeout() {
        if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
            // Session ist abgelaufen: Alle Sessionvariablen löschen und die Session zerstören
            session_unset();
            session_destroy();

            // Setze ein Cookie, das signalisiert, dass die Session abgelaufen ist (optional, falls du diese Unterscheidung treffen möchtest)
            setcookie("session_expired", "1", time()+3600, "/");

            // Falls es sich nicht um einen Ajax-Request handelt, leite um:
            if (!(isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
                header("Location: session_expired.php");
                exit;
            } else {
                http_response_code(440); // Timeout-Code für Ajax
                echo json_encode(['status' => 'session_expired']);
                exit;
            }
        }
        // Aktualisiere die Aktivitätszeit, wenn die Session noch aktiv ist
        $_SESSION['LAST_ACTIVITY'] = time();
    }

    // Funktion für "Keep Alive"
    function handle_keep_alive() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_SESSION['mitarbeiter_id'])) {
                $_SESSION['LAST_ACTIVITY'] = time(); // Session aktiv halten
                http_response_code(200);
                echo json_encode(['status' => 'active']);
            } else {
                http_response_code(440); // Login Time-out
                echo json_encode(['status' => 'session_expired']);
            }
            exit;
        }
    }
}
?>