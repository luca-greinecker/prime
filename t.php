<?php
/**
 * allnet_test.php
 *
 * Minimaler Test für eine Allnet-ALL3075-Steckdose:
 * - "schalte()" nutzt switch + action=0/1 zum Ein/Aus
 * - "holeStatus()" nutzt list + id=... zum Auslesen
 */

// --- Konfiguration ---
define('ALLNET_IP', '10.134.178.15'); // IP der Steckdose
define('ALLNET_ID', 1);               // Ausgangs-ID (0, 1, 2, ...)

// holeStatus(): Liest den Zustand (0=Aus, 1=An, -1=Fehler)
function holeStatus(&$curlError, &$rawResult, &$verboseLog) {
    // Wir fragen hier type=list ab, plus id=..., um nur den gewünschten Actor zu bekommen.
    $url = sprintf(
        'http://%s/xml/?mode=actor&type=list&id=%d',
        ALLNET_IP,
        ALLNET_ID
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    // Verbose-Log in php://temp
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $result    = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    fclose($verbose);

    $rawResult = $result ?: '';

    if ($result === false || empty($result)) {
        return -1; // Fehler
    }

    // Beispiel-Antwort:
    // <actors><actor><id>1</id><name>...</name><state>1</state></actor></actors>
    // Wir suchen also <state>0</state> oder <state>1</state>
    if (preg_match('/<state>(\d+)<\/state>/', $result, $m)) {
        // $m[1] wäre "0" oder "1"
        return (int)$m[1];
    }
    return -1; // Kein passendes state-Tag gefunden
}

// schalte(): Steckdose EIN(1) oder AUS(0)
function schalte($action, &$curlError, &$rawResult, &$verboseLog) {
    // switch braucht id und action
    $url = sprintf(
        'http://%s/xml/?mode=actor&type=switch&id=%d&action=%d',
        ALLNET_IP,
        ALLNET_ID,
        $action
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $result    = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    fclose($verbose);

    $rawResult = $result ?: '';

    // Wenn leer oder false => Fehler
    return (!empty($result));
}

// --- Ablauf ---
$actionResult = '';
$curlError    = '';
$rawResult    = '';
$verboseLog   = '';
$status       = -1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['einschalten'])) {
        // EIN
        $ok = schalte(1, $curlError, $rawResult, $verboseLog);
        $actionResult = $ok ? 'Einschalten erfolgreich' : 'Einschalten fehlgeschlagen';
    } elseif (isset($_POST['ausschalten'])) {
        // AUS
        $ok = schalte(0, $curlError, $rawResult, $verboseLog);
        $actionResult = $ok ? 'Ausschalten erfolgreich' : 'Ausschalten fehlgeschlagen';
    }
}

// Jetzt (oder immer) den Status abfragen
$status = holeStatus($curlError, $rawResult, $verboseLog);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>ALLNET Steckdose Test</title>
</head>
<body>
<h1>ALLNET Testseite</h1>

<p><strong>Status:</strong>
    <?php
    if ($status === 1) {
        echo 'AN';
    } elseif ($status === 0) {
        echo 'AUS';
    } else {
        echo 'UNBEKANNT (Fehler)';
    }
    ?>
</p>

<form method="post">
    <button type="submit" name="einschalten">Einschalten</button>
    <button type="submit" name="ausschalten">Ausschalten</button>
</form>

<?php if ($actionResult): ?>
    <p><strong>Aktion:</strong> <?php echo htmlspecialchars($actionResult); ?></p>
<?php endif; ?>

<?php if ($curlError): ?>
    <h2>cURL-Fehler</h2>
    <pre><?php echo htmlspecialchars($curlError); ?></pre>
<?php endif; ?>

<?php if ($rawResult): ?>
    <h2>Roh-Antwort vom Gerät</h2>
    <pre><?php echo htmlspecialchars($rawResult); ?></pre>
<?php endif; ?>

<?php if ($verboseLog): ?>
    <h2>Verbose-Log</h2>
    <pre><?php echo htmlspecialchars($verboseLog); ?></pre>
<?php endif; ?>
</body>
</html>
