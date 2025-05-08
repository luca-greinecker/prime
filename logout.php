<?php
/**
 * logout.php
 *
 * Beendet die aktuelle Benutzersitzung und leitet den Benutzer zurück auf die Login-Seite.
 */

// Session starten (falls noch nicht aktiv)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Alle Session-Variablen löschen
session_unset();

// Die Session zerstören
session_destroy();

// Zum Login umleiten
header("Location: login.php");
exit;
?>