<?php
/**
 * team.php
 *
 * Zeigt das Team für den aktuell eingeloggten Benutzer (Crew, AL, TL, etc.) und
 * zusätzlich das HR-Team. Verhindert, dass Lehrlinge (mit Substring "hr") erscheinen.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Eingeloggter Benutzer
$mitarbeiter_id = $_SESSION['mitarbeiter_id'] ?? null;
if (!$mitarbeiter_id) {
    die('Benutzer ist nicht angemeldet.');
}

// Benutzerinformationen abrufen
$stmt = $conn->prepare("
    SELECT name, crew, position, gruppe
    FROM employees
    WHERE employee_id = ?
");
$stmt->bind_param("i", $mitarbeiter_id);
$stmt->execute();
$userRes = $stmt->get_result();
$user = $userRes->fetch_assoc();
$stmt->close();

if (!$user) {
    die('Benutzer nicht gefunden.');
}

$crew = $user['crew'] ?? '';
$position = mb_strtolower($user['position'] ?? '');
$gruppe = mb_strtolower($user['gruppe'] ?? '');

// Array für Führungskräfte
$team_leaders = [];

/**
 * 1) Schichtarbeit => Schichtmeister, Schichtmeister - Stv., TL der eigenen Crew
 */
if (strpos($gruppe, 'schichtarbeit') !== false) {
    $sql = "
        SELECT name, position, email_business, bild
        FROM employees
        WHERE crew = ?
          AND status != 9999
          AND (
               LOWER(position) LIKE '%schichtmeister%' 
            OR LOWER(position) LIKE '%tl%'
          )
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $crew);
    $stmt->execute();
    $crewRes = $stmt->get_result();
    while ($row = $crewRes->fetch_assoc()) {
        $team_leaders[] = $row;
    }
    $stmt->close();
}

/**
 * 2) Department-Positionen: Elektrik / Mechanik / CPO / Qualitätssicherung / Sortierung
 */
$department_positions = ['elektrik', 'mechanik', 'cpo', 'qualitätssicherung', 'sortierung'];
foreach ($department_positions as $dept) {
    if (strpos($position, $dept) !== false) {
        $sql = "
            SELECT name, position, email_business, bild
            FROM employees
            WHERE LOWER(position) LIKE '%$dept%'
              AND status != 9999
              AND (
                   LOWER(position) LIKE '%tl%'
                OR LOWER(position) LIKE '%al%'
              )
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $depRes = $stmt->get_result();
        while ($row = $depRes->fetch_assoc()) {
            $team_leaders[] = $row;
        }
        $stmt->close();
    }
}

/**
 * 3) "Verwaltung - Production Manager | BL" => nur für Schichtarbeit
 *    (sofern nicht Elektrik/Mechanik/CPO/Quali/Sortierung)
 */
if (
    strpos($gruppe, 'schichtarbeit') !== false &&
    stripos($position, 'elektrik') === false &&
    stripos($position, 'mechanik') === false &&
    stripos($position, 'cpo') === false &&
    stripos($position, 'qualitätssicherung') === false &&
    stripos($position, 'sortierung') === false
) {
    $sql = "
        SELECT name, position, email_business, bild
        FROM employees
        WHERE LOWER(position) LIKE '%verwaltung - production manager | bl%'
          AND status != 9999
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $pmRes = $stmt->get_result();
    while ($row = $pmRes->fetch_assoc()) {
        $team_leaders[] = $row;
    }
    $stmt->close();
}

/**
 * 4) Engineering Manager => Elektrik/Mechanik
 */
if (strpos($position, 'elektrik') !== false || strpos($position, 'mechanik') !== false) {
    $sql = "
        SELECT name, position, email_business, bild
        FROM employees
        WHERE LOWER(position) LIKE '%engineering manager%'
          AND status != 9999
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $engRes = $stmt->get_result();
    while ($row = $engRes->fetch_assoc()) {
        $team_leaders[] = $row;
    }
    $stmt->close();
}

/**
 * 5) Quality Manager => cpo/qualitätssicherung/sortierung
 */
if (
    strpos($position, 'cpo') !== false ||
    strpos($position, 'qualitätssicherung') !== false ||
    strpos($position, 'sortierung') !== false
) {
    $sql = "
        SELECT name, position, email_business, bild
        FROM employees
        WHERE LOWER(position) LIKE '%quality manager%'
          AND status != 9999
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $qmRes = $stmt->get_result();
    while ($row = $qmRes->fetch_assoc()) {
        $team_leaders[] = $row;
    }
    $stmt->close();
}

/**
 * 6) HR-Team: Nur echte Wortgrenzen von "HR".
 *    Verhindert Matches in "Lehrling" usw.
 *    => Sofern MySQL 8+ oder MariaDB => REGEXP '[[:<:]]HR[[:>:]]'
 *       Collation: wir gehen von uppercase "HR" aus.
 *       Wenn du "hr" in "HR Manager" haben willst,
 *       kann man CASE-INSENSITIVE => REGEXP '[[:<:]][Hh][Rr][[:>:]]'
 */
$hrMembers = [];
$hrSql = "
    SELECT name, position, email_business, bild
    FROM employees
    WHERE position REGEXP '[[:<:]]HR[[:>:]]'
      AND status != 9999
";
// Falls du es case-insensitive willst:
// "... WHERE position REGEXP '[[:<:]][Hh][Rr][[:>:]]' COLLATE utf8_general_ci'
$stmt = $conn->prepare($hrSql);
$stmt->execute();
$hrRes = $stmt->get_result();
while ($m = $hrRes->fetch_assoc()) {
    $hrMembers[] = $m;
}
$stmt->close();

?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Team - Ball Ludesch</title>
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card {
            margin: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: .75rem !important;
            overflow: hidden;
        }

        .card-img-top {
            height: 22rem;
            object-fit: cover;
            object-position: 100% 10%;
            border-bottom: 3px solid #007bff;
        }

        .no-image {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 300px;
            background-color: #f8d7da;
            color: #721c24;
            border-bottom: 3px solid #f5c6cb;
            text-align: center;
        }

        .card-body {
            padding: 15px;
        }

        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            transition: background-color 0.3s, border-color 0.3s;
        }

        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #004085;
        }

        .divider {
            margin: 2rem 0;
            border-bottom: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container">
    <?php if (!empty($team_leaders)): ?>
        <h1 class="text-center mb-3">Mein Team</h1>
        <div class="divider mb-4"></div>
        <div class="row">
            <?php foreach ($team_leaders as $leader): ?>
                <div class="col-md-4">
                    <div class="card">
                        <?php
                        $img = $leader['bild'] ?? '';
                        if ($img) {
                            $path = '../mitarbeiter-anzeige/fotos/' . $img;
                            echo '<img src="' . htmlspecialchars($path) . '" 
                                       alt="Bild von ' . htmlspecialchars($leader['name']) . '" 
                                       class="card-img-top">';
                        } else {
                            echo '<div class="no-image">Kein Bild vorhanden!</div>';
                        }
                        ?>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($leader['name']); ?></h5>
                            <p class="card-text"><?php echo htmlspecialchars($leader['position']); ?></p>
                            <?php if (!empty($leader['email_business'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($leader['email_business']); ?>"
                                   class="btn btn-primary">Email senden</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h1 class="text-center mb-3">HR Team</h1>
    <div class="divider mb-4"></div>
    <div class="row">
        <?php if (empty($hrMembers)): ?>
            <p class="text-center">Kein HR-Team gefunden.</p>
        <?php else: ?>
            <?php foreach ($hrMembers as $hr): ?>
                <div class="col-md-4">
                    <div class="card">
                        <?php
                        $img = $hr['bild'] ?? '';
                        if ($img) {
                            $path = '../mitarbeiter-anzeige/fotos/' . $img;
                            echo '<img src="' . htmlspecialchars($path) . '" 
                                       alt="Bild von ' . htmlspecialchars($hr['name']) . '"
                                       class="card-img-top">';
                        } else {
                            echo '<div class="no-image">Kein Bild vorhanden!</div>';
                        }
                        ?>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($hr['name']); ?></h5>
                            <p class="card-text"><?php echo htmlspecialchars($hr['position']); ?></p>
                            <?php if (!empty($hr['email_business'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($hr['email_business']); ?>"
                                   class="btn btn-primary">Email senden</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>