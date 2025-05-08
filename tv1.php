<?php
/**
 * tv1.php
 *
 * TV-Anzeige für das Schichtmeisterbüro. Zeigt aktuelle Schicht- und Tagschicht-Mitarbeiter,
 * ihren Anwesenheits-/Status sowie anwesende Ersthelfer.
 * Daten kommen aus employees, updatelog etc.
 */

require_once 'db.php';
global $conn;

// updatelog-Daten laden
$sql = "
    SELECT 
        lastUpdated,
        countEmpPresent,
        currentTeam,
        nextTeam,
        nextPresenceL,
        nextPresenceShiftL,
        nextPresenceM,
        nextPresenceShiftM,
        nextPresenceN,
        nextPresenceShiftN,
        nextPresenceO,
        nextPresenceShiftO,
        nextPresenceP,
        nextPresenceShiftP
    FROM updatelog
    WHERE id = 1
";
$resUT = $conn->query($sql);

$lastUpdated = '';
$countEmpPresent = 0;
$currentTeam = '';
$nextTeam = '';
$teamNextPresence = [];

if ($resUT && $resUT->num_rows > 0) {
    $rowUT = $resUT->fetch_assoc();
    $lastUpdated = $rowUT['lastUpdated'];
    $countEmpPresent = (int)$rowUT['countEmpPresent'];
    $currentTeam = $rowUT['currentTeam'];
    $nextTeam = $rowUT['nextTeam'];

    // Datum und Schicht für die Teams L..P
    $teamNextPresence = [
        "Team L" => [
            "date" => $rowUT['nextPresenceL'],
            "shift" => $rowUT['nextPresenceShiftL']
        ],
        "Team M" => [
            "date" => $rowUT['nextPresenceM'],
            "shift" => $rowUT['nextPresenceShiftM']
        ],
        "Team N" => [
            "date" => $rowUT['nextPresenceN'],
            "shift" => $rowUT['nextPresenceShiftN']
        ],
        "Team O" => [
            "date" => $rowUT['nextPresenceO'],
            "shift" => $rowUT['nextPresenceShiftO']
        ],
        "Team P" => [
            "date" => $rowUT['nextPresenceP'],
            "shift" => $rowUT['nextPresenceShiftP']
        ]
    ];
}
if ($resUT) $resUT->close();

// Warnung, wenn Daten älter als 20 Minuten
$oldDataWarning = false;
if (!empty($lastUpdated)) {
    $lastUpdatedTs = strtotime($lastUpdated);
    $diffMinutes = round((time() - $lastUpdatedTs) / 60);
    if ($diffMinutes > 20) {
        $oldDataWarning = true;
    }
}

// Status-Kreissymbol
function getStatusCircle($status, $anwesend)
{
    if ($status == 1) {
        $color = "red";
    } elseif ($status == 2) {
        $color = "blue";
    } elseif ($status == 3) {
        $color = "yellow";
    } elseif ($anwesend == 1) {
        $color = "#00ff00";
    } else {
        $color = "gray";
    }

    return '<span style="
        display:inline-block;
        width:0.9rem;
        height:0.9rem;
        border-radius:50%;
        margin-right:8px;
        background-color:' . $color . ';
        border:1px solid black;">
    </span>';
}

// Sortierkriterium
function getSortKey(array $p, bool $isTagschicht)
{
    $pos = $p['position'];
    $anw = (int)$p['anwesend'];
    $st = (int)$p['status'];

    if ($isTagschicht) {
        $leader = (strpos($pos, "AL") !== false);
    } else {
        $isTL = (strpos($pos, "TL") !== false);
        $isSM = (strpos($pos, "Schichtmeister") !== false && strpos($pos, "Stv") === false);
        $leader = ($isTL || $isSM);
    }

    if ($leader) {
        $prio = 0;
    } elseif ($anw === 1) {
        $prio = 1;
    } elseif ($st === 0) {
        $prio = 2;
    } elseif ($st === 1) {
        $prio = 3;
    } elseif ($st === 2) {
        $prio = 4;
    } elseif ($st === 3) {
        $prio = 5;
    } else {
        $prio = 9;
    }
    $sortName = !empty($p['ind_name']) ? $p['ind_name'] : $p['name'];
    return [$prio, $sortName];
}

function renderPerson(array $p, bool $isTagschicht): string
{
    $circle = getStatusCircle($p['status'], $p['anwesend']);
    $pos = $p['position'];
    $svp = (!empty($p['svp']) && $p['svp'] == 1)
        ? ' <img src="assets/bilder/svp.png" alt="SVP" style="height:1rem;vertical-align:middle;">'
        : '';

    $name = !empty($p['ind_name']) ? $p['ind_name'] : $p['name'];
    $name = htmlspecialchars($name);

    if ($isTagschicht) {
        $leader = (strpos($pos, "AL") !== false);
    } else {
        $isTL = (strpos($pos, "TL") !== false);
        $isSM = (strpos($pos, "Schichtmeister") !== false && strpos($pos, "Stv") === false);
        $leader = ($isTL || $isSM);
    }

    if ($leader) {
        return "<strong>{$circle}{$name}{$svp}</strong><br>";
    }
    return "{$circle}{$name}{$svp}<br>";
}

function kuerzeBereich(string $bereich): string
{
    return $bereich === "Schichtmeister" ? "SM" : $bereich;
}

// Anwesende Ersthelfer laden
$sqlErsthelfer = "
    SELECT name, ind_name
    FROM employees
    WHERE ersthelfer = 1
      AND anwesend   = 1
    ORDER BY name ASC
";
$resEh = $conn->query($sqlErsthelfer);
$ersthelferAnwesend = [];
while ($row = $resEh->fetch_assoc()) {
    $ehName = !empty($row['ind_name']) ? $row['ind_name'] : $row['name'];
    $ersthelferAnwesend[] = $ehName;
}
$resEh->close();

// Schicht-Teams
$teams = ["Team L", "Team M", "Team N", "Team O", "Team P"];

// Schicht-Gruppen => Positionen
$shiftGroups = [
    "Schichtmeister" => ["Schichtmeister", "Schichtmeister - Stv."],
    "Frontend" => ["Frontend - TL", "Frontend"],
    "Printer" => ["Druckmaschine - TL", "Druckmaschine"],
    "ISA/Neck" => ["ISA/Necker"],
    "Pal/MGA" => ["Palletierer/MGA - TL", "Palletierer/MGA"],
    "QS" => ["Qualitätssicherung"],
    "TIH" => ["Schicht - Elektrik", "Schicht - Mechanik"],
    "CPO" => ["Schicht - CPO"]
];

// Tagschicht-Bereiche
$tagSubGroups = [
    "Elektrik" => [
        "Tagschicht - Elektrik",
        "Tagschicht - Elektrik Lehrling",
        "Tagschicht - Elektrik | AL",
        "Tagschicht - Elektrik | AL Stv.",
        "Tagschicht - Elektrik Spezialist"
    ],
    "Mechanik" => [
        "Tagschicht - Mechanik",
        "Tagschicht - Mechanik Tool & Die",
        "Tagschicht - Mechanik Lehrling",
        "Tagschicht - Mechanik | AL",
        "Tagschicht - Mechanik FE",
        "Tagschicht - Mechanik | TL FE",
        "Tagschicht - Mechanik BE",
        "Tagschicht - Mechanik | TL BE",
        "Tagschicht - Produktion Spezialist"
    ],
    "CPO" => [
        "Tagschicht - CPO",
        "Tagschicht - CPO Lehrling",
        "Tagschicht - CPO | AL",
        "Tagschicht - CPO | AL Stv."
    ]
];
$allKnownTagPositions = [];
foreach ($tagSubGroups as $posArr) {
    $allKnownTagPositions = array_merge($allKnownTagPositions, $posArr);
}
$allKnownTagPositions = array_unique($allKnownTagPositions);

// Hilfsfunktionen zum DB-Fetch
function fetchShiftEmployees(mysqli $conn, string $crew, array $positions): array
{
    if (empty($positions)) return [];
    $ph = implode(',', array_fill(0, count($positions), '?'));
    $sql = "
        SELECT name, ind_name, anwesend, status, position, svp
        FROM employees
        WHERE crew=?
          AND position IN ($ph)
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler SHIFT: " . $conn->error);
    }
    $types = str_repeat('s', count($positions) + 1);
    $params = array_merge([$crew], $positions);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $arr = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    usort($arr, function ($a, $b) {
        $kA = getSortKey($a, false);
        $kB = getSortKey($b, false);
        return $kA <=> $kB;
    });
    return $arr;
}

function fetchTagEmployees(mysqli $conn, array $positions): array
{
    if (empty($positions)) return [];
    $ph = implode(',', array_fill(0, count($positions), '?'));
    $sql = "
        SELECT name, ind_name, anwesend, status, position, svp
        FROM employees
        WHERE gruppe='Tagschicht'
          AND position IN ($ph)
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler TAG: " . $conn->error);
    }
    $types = str_repeat('s', count($positions));
    $stmt->bind_param($types, ...$positions);
    $stmt->execute();
    $res = $stmt->get_result();
    $arr = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    usort($arr, function ($a, $b) {
        $kA = getSortKey($a, true);
        $kB = getSortKey($b, true);
        return $kA <=> $kB;
    });
    return $arr;
}

$sqlSonst = "
    SELECT name, ind_name, anwesend, status, position, svp
    FROM employees
    WHERE gruppe='Tagschicht'
      AND position NOT IN ('" . implode("','", array_map('addslashes', $allKnownTagPositions)) . "')
";
$resSonst = $conn->query($sqlSonst);
$sonstigesArr = $resSonst->fetch_all(MYSQLI_ASSOC);
$resSonst->close();

usort($sonstigesArr, function ($a, $b) {
    $kA = getSortKey($a, true);
    $kB = getSortKey($b, true);
    return $kA <=> $kB;
});

$tagschichtHtml = '';
foreach ($tagSubGroups as $subName => $subPositions) {
    $subList = fetchTagEmployees($conn, $subPositions);

    $tagschichtHtml .= '<div>';
    $tagschichtHtml .= '<h5 style="
        background-color:#1140FE;
        color:white;
        border-radius:3px;
        text-align:center;
        margin:4px 0 2px 0;
        font-size:1rem;
        font-weight:bold;
    ">' . htmlspecialchars($subName) . '</h5>';

    if (empty($subList)) {
        $tagschichtHtml .= '<span style="color:#999;font-style:italic;">– keine –</span>';
    } else {
        foreach ($subList as $p) {
            $tagschichtHtml .= renderPerson($p, true);
        }
    }
    $tagschichtHtml .= '</div>';
}

if (!empty($sonstigesArr)) {
    $tagschichtHtml .= '<div>';
    $tagschichtHtml .= '<h5 style="
        background-color:#1140FE;
        color:white;
        border-radius:3px;
        text-align:center;
        margin:4px 0 2px 0;
        font-size:1rem;
        font-weight:bold;
    ">Sonstiges</h5>';
    foreach ($sonstigesArr as $p) {
        $tagschichtHtml .= renderPerson($p, true);
    }
    $tagschichtHtml .= '</div>';
}

$shiftGroupNames = array_keys($shiftGroups);
$rowspan = count($shiftGroupNames);

$highlightCurrentStyle = "background-color:#c9ffc9;";
$highlightNextHeader = "background-color:#b2ffff;";
?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta http-equiv="refresh" content="135"> <!-- alle 135 Sek. Reload -->
        <title>TV-Anzeige</title>
        <!-- Lokale Bootstrap CSS -->
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body {
                background-color: #f9f9f9;
                font-size: 1.3rem;
                margin: 0;
                padding: 0;
            }

            .old-data-warning {
                position: fixed;
                top: 10px;
                left: 50%;
                transform: translateX(-50%);
                background-color: red;
                color: #fff;
                font-weight: bold;
                padding: 10px 20px;
                border-radius: 5px;
                z-index: 9999;
                animation: blink 1s infinite alternate;
            }

            @keyframes blink {
                0% {
                    opacity: 1;
                }
                100% {
                    opacity: 0;
                }
            }

            .info-bar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 98%;
                margin: 10px auto;
                gap: 10px;
            }

            .info-box {
                flex: 1;
                border: 1px solid #ccc;
                background-color: #fff;
                border-radius: 5px;
                text-align: center;
                min-height: 2.7rem;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .info-box-center {
                flex: 2;
                border: 1px solid red;
                background-color: #fff9c4;
                font-weight: bold;
                max-height: 2.7rem;
            }

            .carousel-item {
                padding: 0.5rem 0;
            }

            .no-wrap {
                white-space: nowrap;
            }

            table {
                width: 98%;
                margin: 0 auto;
                border-collapse: collapse;
                table-layout: fixed;
                border: none;
            }

            thead th, th, td {
                border: none;
                border-bottom: 1px solid #ccc;
                padding: 8px;
                vertical-align: middle;
                line-height: 1.17;
            }

            thead th:not(:first-child) {
                background-color: #e9ecef;
                text-align: center;
                font-size: 1.2rem;
            }

            thead th:first-child {
                width: 3%;
                background-color: #e9ecef;
            }

            .tagschicht-cell {
                width: 20%;
                vertical-align: top;
                border-left: 1px solid #ccc;
                padding-top: 0 !important;
            }

            .vertical-text {
                writing-mode: vertical-rl;
                text-orientation: sideways;
            }
        </style>
    </head>
    <body>

    <?php if ($oldDataWarning): ?>
        <div class="old-data-warning">
            Achtung! Daten nicht aktuell! Bitte lokale IT kontaktieren!
        </div>
    <?php endif; ?>

    <div class="info-bar">
        <!-- Linke Box: Datum & Uhr -->
        <div class="info-box">
            <div>
                <?php echo date("d.m.Y"); ?> &nbsp;-&nbsp; <span id="clock"></span>
            </div>
        </div>

        <!-- Mittlere Box: Ersthelfer-Anzeige -->
        <div class="info-box info-box-center">
            <?php if (!empty($ersthelferAnwesend)): ?>
                <?php if (count($ersthelferAnwesend) < 2): ?>
                    <!-- Falls nur ein Name vorhanden ist: -->
                    <div class="d-flex justify-content-center align-items-center no-wrap">
                        <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz" style="height:1.3rem; margin:0 1rem;">
                        <span class="no-wrap"><?php echo htmlspecialchars($ersthelferAnwesend[0]); ?></span>
                        <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz" style="height:1.3rem; margin:0 1rem;">
                    </div>
                <?php else:
                    // Gruppiere die Namen in 3er-Blöcke
                    $chunks = array_chunk($ersthelferAnwesend, 3);
                    ?>
                    <div id="ersthelferCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="5000">
                        <div class="carousel-inner">
                            <?php foreach ($chunks as $index => $chunk): ?>
                                <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                    <div class="d-flex flex-nowrap justify-content-center align-items-center no-wrap">
                                        <?php if (count($chunk) == 3): ?>
                                            <!-- Bei 3 Namen: Rotes Kreuz vor, zwischen und nach den Namen -->
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                            <span class="no-wrap"><?php echo htmlspecialchars($chunk[0]); ?></span>
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                            <span class="no-wrap"><?php echo htmlspecialchars($chunk[1]); ?></span>
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                            <span class="no-wrap"><?php echo htmlspecialchars($chunk[2]); ?></span>
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                        <?php elseif (count($chunk) == 2): ?>
                                            <!-- Bei 2 Namen: Kreuz vor, zwischen und nach den Namen -->
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                            <span class="no-wrap"><?php echo htmlspecialchars($chunk[0]); ?></span>
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                            <span class="no-wrap"><?php echo htmlspecialchars($chunk[1]); ?></span>
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                        <?php elseif (count($chunk) == 1): ?>
                                            <!-- Bei nur 1 Namen: Kreuz links und rechts -->
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                            <span class="no-wrap"><?php echo htmlspecialchars($chunk[0]); ?></span>
                                            <img src="assets/bilder/kreuz.png" alt="Rotes Kreuz"
                                                 style="height:1.3rem; margin:0 1rem;">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:#666;">Keine Ersthelfer anwesend</span>
            <?php endif; ?>
        </div>

        <!-- Rechte Box: Anwesende Mitarbeiter -->
        <div class="info-box">
            Anwesende Mitarbeiter: <?php echo (int)$countEmpPresent; ?>
        </div>
    </div>

    <!-- TABELLE FÜR TEAMS L..P + TAGSCHICHT -->
    <table>
        <thead>
        <tr>
            <th></th>
            <?php
            foreach ($teams as $t) {
                $style = '';
                if ($t === $currentTeam) {
                    $style = $highlightCurrentStyle;
                } elseif ($t === $nextTeam) {
                    $style = $highlightNextHeader;
                }
                $teamCaption = $t;
                if ($t !== $currentTeam && $t !== $nextTeam) {
                    $np = $teamNextPresence[$t] ?? null;
                    $dt = $np['date'] ?? '';
                    $sh = $np['shift'] ?? '';
                    if ($dt && $sh) {
                        $formatted = date("d.m.", strtotime($dt));
                        $teamCaption .= " ({$formatted} - {$sh})";
                    }
                }
                echo '<th style="' . $style . '">' . htmlspecialchars($teamCaption) . '</th>';
            }
            ?>
            <th>Tagschicht</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $shiftGroupNames = array_keys($shiftGroups);
        $rowspan = count($shiftGroupNames);
        foreach ($shiftGroupNames as $i => $groupName):
            $positions = $shiftGroups[$groupName];
            $anzeigeName = kuerzeBereich($groupName);
            ?>
            <tr>
                <th>
                    <div class="vertical-text"><?php echo htmlspecialchars($anzeigeName); ?></div>
                </th>
                <?php foreach ($teams as $t): ?>
                    <?php
                    $tdStyle = ($t === $currentTeam) ? $highlightCurrentStyle : '';
                    $people = fetchShiftEmployees($conn, $t, $positions);
                    ?>
                    <td style="<?php echo $tdStyle; ?>">
                        <?php
                        if (empty($people)) {
                            echo '<span style="color:#999;font-style:italic;">– keine –</span>';
                        } else {
                            foreach ($people as $p) {
                                echo renderPerson($p, false);
                            }
                        }
                        ?>
                    </td>
                <?php endforeach; ?>
                <?php if ($i === 0): ?>
                    <td class="tagschicht-cell" rowspan="<?php echo $rowspan; ?>">
                        <?php echo $tagschichtHtml; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var carouselElement = document.getElementById('ersthelferCarousel');
            var carousel = new bootstrap.Carousel(carouselElement, {
                interval: 5000  // Wechsel alle 5000ms
            });

            // Deine Live-Uhr:
            function updateClock() {
                const now = new Date();
                let hh = String(now.getHours()).padStart(2, '0');
                let mm = String(now.getMinutes()).padStart(2, '0');
                let ss = String(now.getSeconds()).padStart(2, '0');
                document.getElementById('clock').textContent = hh + ":" + mm + ":" + ss;
            }

            setInterval(updateClock, 1000);
            updateClock();
        });
    </script>

    </body>
    </html>
<?php
$conn->close();
?>