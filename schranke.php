<?php
/**
 * schranke.php
 *
 * Direkte Ansteuerung einer Allnet ALL3075‑Steckdose zur Notöffnung.
 * Gerät erlaubt HTTP‑Zugriff ohne Login.
 *
 */

include 'access_control.php';      // Session‑Management & Zugriffskontrolle
pruefe_benutzer_eingeloggt();

/* ───────── Konfiguration ─────────────────────────────────────────────── */

define('ALLNET_IP', '10.134.178.15');  // IP des ALL3075
define('ALLNET_ID', 1);                // Ausgangs‑ID (1 = Notöffnung Schranke)
define('MAIL_TO',  'schranke@ball.com'); // sichtbarer Empfänger

global $conn;

/**
 * Gibt eine komma‑separierte Liste der E‑Mails aller Mitarbeitenden zurück,
 * deren Position den Management‑Rollen entspricht.
 */
function hole_management_emails(mysqli $conn): string
{
    $mgmtPositions = [
        'Verwaltung - Werksleiter',
        'Verwaltung - Engineering Manager | BL',
        'Verwaltung - Production Manager | BL',
        'Verwaltung - Quality Manager | BL',
        'Verwaltung - EHS Manager',
        'Verwaltung - IT',
        'Verwaltung - Trainee/Graduate Intern',
    ];

    $placeholders = implode(',', array_fill(0, count($mgmtPositions), '?'));
    $types        = str_repeat('s', count($mgmtPositions));

    $stmt = $conn->prepare("
        SELECT email_business
        FROM employees
        WHERE position IN ($placeholders)
          AND email_business IS NOT NULL
          AND email_business <> ''
    ");
    if (!$stmt) {
        throw new RuntimeException('DB‑Prepare fehlgeschlagen: '.$conn->error);
    }
    $stmt->bind_param($types, ...$mgmtPositions);
    $stmt->execute();
    $res = $stmt->get_result();

    $emails = [];
    while ($row = $res->fetch_assoc()) {
        $emails[] = $row['email_business'];
    }
    $stmt->close();

    return implode(',', $emails);
}

/* ───────── Initialisierung ───────────────────────────────────────────── */

$email_gesendet    = false;
$fehler_nachricht  = '';
$erfolgs_nachricht = '';

/* ───────── Mitarbeiter‑Infos ─────────────────────────────────────────── */

$mitarbeiter_id   = $_SESSION['mitarbeiter_id'] ?? 0;
$username         = $_SESSION['username']       ?? 'Unbekannt';
$mitarbeiter_name = 'Unbekannt';
if ($mitarbeiter_id > 0) {
    $stmt = $conn->prepare('SELECT name FROM employees WHERE employee_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $mitarbeiter_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows) {
            $mitarbeiter_name = $result->fetch_assoc()['name'];
        }
        $stmt->close();
    }
}

/* ───────── Allnet‑Hilfsroutinen ──────────────────────────────────────── */

function holeSchrankenStatus(): int
{
    $url = sprintf('http://%s/xml/?mode=actor&type=list&id=%d', ALLNET_IP, ALLNET_ID);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false || empty($result)) return -1;
    return preg_match('/<state>(\d+)<\/state>/', $result, $m) ? (int)$m[1] : -1;
}

function schalteSchranke(int $action): bool
{
    $url = sprintf('http://%s/xml/?mode=actor&type=switch&id=%d&action=%d', ALLNET_IP, ALLNET_ID, $action);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $result = curl_exec($ch);
    curl_close($ch);
    return !($result === false || empty($result));
}

/* ───────── POST‑Aktionen ─────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notoeffnung'])) {

    // Schaltversuch
    $schalt_erfolg = schalteSchranke(1);

    if ($schalt_erfolg) {
        // Log
        $datum_zeit = date('d.m.Y H:i:s');
        if (!is_dir('logs')) mkdir('logs', 0777, true);
        file_put_contents(
            'logs/schranke_protokoll.txt',
            "NOTÖFFNUNG DER SCHRANKE\nDatum/Uhrzeit: $datum_zeit\nAusgelöst von: $mitarbeiter_name (Benutzername: $username)\nIP-Adresse: {$_SERVER['REMOTE_ADDR']}\n-----------------------------------\n\n",
            FILE_APPEND
        );

        // Mail
        $bcc        = hole_management_emails($conn);
        $empfaenger = MAIL_TO; // sichtbar
        $betreff    = "NOTÖFFNUNG SCHRANKE - $datum_zeit";
        $inhalt     = "NOTÖFFNUNG DER SCHRANKE\n\nDatum/Uhrzeit: $datum_zeit\nAusgelöst von: $mitarbeiter_name (Benutzername: $username)\n\nDiese E-Mail wurde automatisch vom System generiert.";
        $header  = 'From: '.MAIL_TO."\r\n";
        $header .= 'Bcc: '.$bcc."\r\n";
        $header .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $header .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
        $header .= 'X-Mailer: PHP/'.phpversion();

        $email_gesendet = mail($empfaenger, $betreff, $inhalt, $header);
        $_SESSION['schranke_email_gesendet'] = true;
    }

    // Feedback
    if ($schalt_erfolg && $email_gesendet) {
        $erfolgs_nachricht = 'Notöffnung aktiviert – Management informiert.';
    } elseif (!$schalt_erfolg) {
        $fehler_nachricht = 'Schaltvorgang fehlgeschlagen – Notöffnung nicht möglich.';
    } else {
        $fehler_nachricht = 'E-Mail-Versand fehlgeschlagen – bitte IT informieren.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['einschalten'])) {
    if (schalteSchranke(0)) {
        $erfolgs_nachricht = 'Die Schranke wurde wieder auf Automatikbetrieb gestellt.';
        unset($_SESSION['schranke_email_gesendet']);
    } else {
        $fehler_nachricht = 'Konnte die Schranke nicht in Automatikbetrieb stellen!';
    }
}

/* ───────── Aktueller Status ─────────────────────────────────────────── */

$aktueller_status = holeSchrankenStatus();  // 0 = Automatik, 1 = Notöffnung, -1 = unbekannt / offline
$allnet_online    = $aktueller_status !== -1;

/* ───────── HTML‑Ausgabe ─────────────────────────────────────────────── */

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schranke – Notöffnung</title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body          { background:#f5f5f5; }
        .status-badge { display:inline-block;padding:.45rem 1rem;border-radius:.4rem;font-weight:600;font-size:1.1rem; }
        .status-on      { background:#28a745;color:#fff; }
        .status-off     { background:#dc3545;color:#fff; }
        .status-unknown { background:#6c757d;color:#fff; }
        .status-card  { border:2px solid #dee2e6;border-radius:0.75rem; }
        .status-img   { width:340px;height:auto;border-radius:0.75rem;border:2px solid black; }
    </style>
</head>
<body class="p-3">
<?php include 'navbar.php'; ?>

<div class="container content">

    <!-- Kopfzeile -->
    <div class="status-card bg-white p-4 rounded shadow-sm mb-4 d-flex align-items-center justify-content-between">
        <h1 class="mb-0">Schranke – Notöffnung</h1>
        <div class="bg-dark text-white py-2 px-3 rounded-pill fw-bold">
            <i class="bi bi-person-fill me-1"></i>
            Angemeldet als: <?=htmlspecialchars($mitarbeiter_name)?>
        </div>
    </div>

    <!-- Warn‑Box -->
    <div class="alert alert-danger d-flex flex-column gap-2 p-4 rounded shadow-sm mb-4">
        <div class="d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
            <h4 class="mb-0"><strong>Wichtiger Hinweis – Notöffnung</strong></h4>
        </div>
        <p class="mb-0">
            Die Notöffnung darf <strong>ausschließlich in Notfällen</strong> ausgelöst werden. Bei Klick auf "Notöffnung einleiten" werden beide Schranken unmittelbar geöffnet.
            Jeder Vorgang wird <strong>protokolliert</strong> (Benutzer, Zeit, IP) und löst
            <strong>sofort</strong> eine E-Mail an die Geschäftsführung von Ball Ludesch aus.
        </p>
    </div>

    <!-- Status‑Block -->
    <div class="status-card bg-white p-4 rounded shadow-sm mb-4">
        <h4 class="text-center mb-4"><i class="bi bi-info-circle me-1"></i>&nbspAktueller Schrankenstatus</h4>

        <?php
        $status_text   = 'UNBEKANNT';
        $status_class  = 'status-unknown';
        $status_img    = '';
        $status_imgalt = 'Unbekannt';

        if ($aktueller_status === 0) {
            $status_text   = 'Automatikbetrieb';
            $status_class  = 'status-on';
            $status_img    = 'assets/bilder/schranke-geschlossen.png';
            $status_imgalt = 'Schranke geschlossen';
        } elseif ($aktueller_status === 1) {
            $status_text   = 'Notöffnung aktiv';
            $status_class  = 'status-off';
            $status_img    = 'assets/bilder/schranke-offen.png';
            $status_imgalt = 'Schranke offen';
        }
        ?>

        <div class="d-flex flex-column flex-md-row align-items-center justify-content-center gap-4 mb-3">
            <?php if ($status_img): ?>
                <img src="<?=htmlspecialchars($status_img)?>"
                     alt="<?=htmlspecialchars($status_imgalt)?>"
                     class="status-img shadow-sm">
            <?php endif; ?>

            <span class="status-badge p-3 ms-4 <?=$status_class?>"><?=$status_text?></span>
        </div>
    </div>

    <!-- Meldungen -->
    <?php if ($fehler_nachricht): ?>
        <div class="alert alert-danger d-flex align-items-center p-3 rounded mb-4">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <?=htmlspecialchars($fehler_nachricht)?>
        </div>
    <?php endif; ?>
    <?php if ($erfolgs_nachricht): ?>
        <div class="alert alert-success d-flex align-items-center p-3 rounded mb-4">
            <i class="bi bi-check-circle-fill fs-4 me-3"></i>
            <?=htmlspecialchars($erfolgs_nachricht)?>
        </div>
    <?php endif; ?>

    <!-- Aktionsbereich -->
    <div class="status-card bg-white p-2 rounded shadow-sm mb-4">
        <?php if (!$allnet_online): ?>
            <div class="alert alert-danger d-flex align-items-center p-3 mb-4">
                <i class="bi bi-wifi-off fs-4 me-3"></i>
                Steuergerät (ALL3075) aktuell <strong>&nbspnicht erreichbar&nbsp</strong> – Notöffnung ist deaktiviert.
            </div>
        <?php endif; ?>

        <div class="text-center my-4">
            <?php if ($aktueller_status === 0): ?>
                <?php if (!($_SESSION['schranke_email_gesendet'] ?? false) && $allnet_online): ?>
                    <form method="post">
                        <button type="submit" name="notoeffnung" class="btn btn-danger btn-lg shadow-sm">
                            <i class="bi bi-unlock-fill me-1"></i> Notöffnung einleiten
                        </button>
                    </form>
                <?php elseif (!$allnet_online): ?>
                    <button class="btn btn-danger btn-lg shadow-sm disabled" disabled>
                        <i class="bi bi-unlock-fill me-1"></i> Notöffnung nicht verfügbar
                    </button>
                <?php else: ?>
                    <div class="alert alert-info d-flex align-items-center p-3 mb-3">
                        <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                        Die Notöffnung wurde durchgeführt – die Schranke ist deaktiviert.
                    </div>
                <?php endif; ?>
            <?php else: /* Notöffnung aktiv */ ?>
                <?php if ($aktueller_status === 1): ?>
                    <div class="alert alert-secondary mb-3">
                        Die Schranke befindet sich im Notöffnungsmodus.
                    </div>
                <?php endif; ?>
                <form method="post">
                    <button type="submit" name="einschalten"
                            class="btn btn-info btn-lg shadow-sm"
                        <?= !$allnet_online ? ' disabled' : '' ?>>
                        <i class="bi bi-power me-1"></i> Notöffnung deaktivieren
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>
