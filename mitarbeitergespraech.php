<?php
/**
 * mitarbeitergespraech.php
 *
 * Seite für das Mitarbeitergespräch mit einem bestimmten Mitarbeiter (employee_id).
 * Funktionen:
 *  - Keep-Alive (alle 5 Min), damit die Session nicht nach z.B. 600s abläuft.
 *  - Auto-Save ins LocalStorage alle 2,5 Min.
 *  - Wiederherstellen der Felder bei Seiten-Neuladen (z.B. nach Browsercrash).
 *  - Zeichenzähler & Range-Slider.
 */

include 'access_control.php'; // Steuert Session, Zugriff
global $conn;
pruefe_benutzer_eingeloggt();

// Mitarbeiter-ID (employee_id) aus GET
if (!isset($_GET['employee_id'])) {
    echo '<p>Keine employee_id angegeben.</p>';
    exit;
}
$employee_id = (int)$_GET['employee_id'];

// Zugriffsprüfung
if (!hat_zugriff_auf_mitarbeiter($employee_id)) {
    header("Location: access_denied.php");
    exit;
}

// Eingeloggter User (Reviewer) ermitteln
$mitarbeiter_id = $_SESSION['mitarbeiter_id']; // PK in employees
$stmt = $conn->prepare("
    SELECT name
    FROM employees
    WHERE employee_id = ?
");
if (!$stmt) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $mitarbeiter_id);
$stmt->execute();
$result = $stmt->get_result();
$fullname = 'Unbekannt';
if ($result->num_rows > 0) {
    $userRow = $result->fetch_assoc();
    $fullname = $userRow['name'];
}
$stmt->close();

// Zieldaten (Mitarbeiter) abrufen
$stmt = $conn->prepare("
    SELECT name, position
    FROM employees
    WHERE employee_id = ?
");
if (!$stmt) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo '<p>Keine Daten für die angegebene Mitarbeiter-ID gefunden.</p>';
    exit;
}
$employee = $result->fetch_assoc();
$stmt->close();

// Gesprächsbereich via position -> position_zu_gespraechsbereich
$bereich_stmt = $conn->prepare("
    SELECT gesprächsbereich_id
    FROM position_zu_gespraechsbereich
    WHERE position_id = (
        SELECT id FROM positionen WHERE name = ?
    )
");
if (!$bereich_stmt) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$bereich_stmt->bind_param("s", $employee['position']);
$bereich_stmt->execute();
$bereich_res = $bereich_stmt->get_result();
if ($bereich_res->num_rows === 0) {
    echo '<p>Keine Gesprächsbereich-Zuordnung für die Position gefunden.</p>';
    exit;
}
$bereich_row = $bereich_res->fetch_assoc();
$gespraechsbereich_id = $bereich_row['gesprächsbereich_id'];
$bereich_stmt->close();

// Vorjahr-Ratings laden (z.B. um Defaultwerte zu setzen)
$last_year = date('Y') - 1;
$last_year_ratings = [];
$ly_stmt = $conn->prepare("
    SELECT es.skill_id, es.rating
    FROM employee_reviews er
    JOIN employee_skills es ON er.id = es.review_id
    WHERE er.employee_id = ?
      AND YEAR(er.date) = ?
");
if (!$ly_stmt) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$ly_stmt->bind_param("ii", $employee_id, $last_year);
$ly_stmt->execute();
$ly_res = $ly_stmt->get_result();
while ($row = $ly_res->fetch_assoc()) {
    $last_year_ratings[$row['skill_id']] = $row['rating'];
}
$ly_stmt->close();

// Skills je nach Kategorie holen
$skills = [];
$s_stmt = $conn->prepare("
    SELECT s.id, s.name, s.kategorie
    FROM skills s
    JOIN skills_zu_gesprächsbereich sg ON s.id = sg.skill_id
    WHERE sg.gesprächsbereich_id = ?
    ORDER BY 
        FIELD(
            s.kategorie, 
            'Anwenderkentnisse',
            'Positionsspezifische Kompetenzen',
            'Persönliche Kompetenzen',
            'Führungskompetenzen'
        ),
        s.id ASC
");
if (!$s_stmt) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$s_stmt->bind_param("i", $gespraechsbereich_id);
$s_stmt->execute();
$s_res = $s_stmt->get_result();
while ($skillRow = $s_res->fetch_assoc()) {
    $skills[$skillRow['kategorie']][] = $skillRow;
}
$s_stmt->close();

// Formularverarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brandschutzwart = isset($_POST['brandschutzwart']) ? 1 : 0;
    $sprinklerwart = isset($_POST['sprinklerwart']) ? 1 : 0;
    $ersthelfer = isset($_POST['ersthelfer']) ? 1 : 0;
    $svp = isset($_POST['svp']) ? 1 : 0;
    $trainertaetigkeiten = isset($_POST['trainertaetigkeiten']) ? 1 : 0;
    $trainertaetigkeiten_kommentar = $_POST['trainertaetigkeiten_kommentar'] ?? '';

    $zufriedenheit = $_POST['zufriedenheit'] ?? 'Zufrieden';
    $unzufriedenheit_grund = '';
    if (!empty($_POST['unzufriedenheit_grund']) && is_array($_POST['unzufriedenheit_grund'])) {
        $unzufriedenheit_grund = implode(', ', $_POST['unzufriedenheit_grund']);
    }

    $rueckblick = $_POST['rueckblick'] ?? '';
    $entwicklung = $_POST['entwicklung'] ?? '';
    $feedback = $_POST['feedback'] ?? '';

    $review_date = date('Y-m-d');
    // Insert in employee_reviews
    $review_stmt = $conn->prepare("
        INSERT INTO employee_reviews (
            employee_id, date, 
            brandschutzwart, sprinklerwart, ersthelfer, svp,
            trainertaetigkeiten, trainertaetigkeiten_kommentar,
            zufriedenheit, unzufriedenheit_grund,
            rueckblick, entwicklung, feedback,
            reviewer_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$review_stmt) {
        die('Prepare failed: ' . htmlspecialchars($conn->error));
    }
    $review_stmt->bind_param(
        "isiiiisssssssi",
        $employee_id, $review_date,
        $brandschutzwart, $sprinklerwart, $ersthelfer, $svp,
        $trainertaetigkeiten, $trainertaetigkeiten_kommentar,
        $zufriedenheit, $unzufriedenheit_grund,
        $rueckblick, $entwicklung, $feedback,
        $mitarbeiter_id
    );
    if (!$review_stmt->execute()) {
        die('Execute failed: ' . htmlspecialchars($review_stmt->error));
    }
    $review_id = $review_stmt->insert_id;
    $review_stmt->close();

    // Skill-Ratings
    foreach ($skills as $cat => $catSkills) {
        foreach ($catSkills as $sk) {
            $skillId = $sk['id'];
            $rating = isset($_POST['skill_' . $skillId]) ? (int)$_POST['skill_' . $skillId] : 1;

            $skill_stmt = $conn->prepare("
                INSERT INTO employee_skills (review_id, skill_id, rating)
                VALUES (?, ?, ?)
            ");
            if (!$skill_stmt) {
                die('Prepare failed: ' . htmlspecialchars($conn->error));
            }
            $skill_stmt->bind_param("iii", $review_id, $skillId, $rating);
            if (!$skill_stmt->execute()) {
                die('Execute failed: ' . htmlspecialchars($skill_stmt->error));
            }
            $skill_stmt->close();
        }
    }

    // Auf Bestätigungsseite
    header("Location: bestaetigung.php?employee_id=$employee_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mitarbeitergespräch</title>
    <!-- Lokales Bootstrap 5 CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <style>
        .slider {
            width: 100%;
            height: 1rem;
        }

        .mb-3 {
            margin-bottom: 1.5rem;
        }

        h4 {
            margin-top: 1rem;
            color: #007bff;
        }

        .thin-divider {
            margin: 1.5rem 0;
            border-bottom: 1px solid #dee2e6;
        }

        .range-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .range-container {
            margin-bottom: 1rem;
        }

        .range-value {
            display: inline-block;
            width: 3rem;
            text-align: center;
            font-weight: bold;
            font-size: 1.4rem;
        }

        .range-description {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .range-description-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
        }

        .btn-container {
            margin-top: 2rem;
            text-align: center;
        }

        .disabled-btn {
            pointer-events: none;
            opacity: 0.6;
        }

        .form-check-input {
            margin-top: 0.3rem;
            margin-left: -1.25rem;
        }

        /* Zeichenzähler */
        .char-counter {
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .char-counter.invalid {
            color: red;
        }

        .char-counter.valid {
            color: green;
        }

        /* Slider-Grafik */
        input[type="range"] {
            -webkit-appearance: none;
            width: 100%;
            height: 12px;
            background: linear-gradient(to right, #007bff 0%, #007bff 50%, #ddd 50%, #ddd 100%);
            border-radius: 5px;
            outline: none;
            transition: background 0.2s;
        }

        input[type="range"]::-webkit-slider-runnable-track {
            height: 12px;
            background: transparent;
        }

        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 24px;
            height: 24px;
            background: #fff;
            border: 2px solid #007bff;
            border-radius: 50%;
            cursor: pointer;
            position: relative;
            z-index: 3;
        }

        input[type="range"]::-moz-range-thumb {
            width: 24px;
            height: 24px;
            background: #fff;
            border: 2px solid #007bff;
            border-radius: 50%;
            cursor: pointer;
            z-index: 3;
        }

        input[type="range"]::-ms-thumb {
            width: 24px;
            height: 24px;
            background: #fff;
            border: 2px solid #007bff;
            border-radius: 50%;
            cursor: pointer;
            z-index: 3;
        }

        /* Print-Ausblendungen */
        @media print {
            button, .navbar, .char-counter, .modal, .btn, .divider, .no-print {
                display: none !important;
            }

            @page {
                margin: 1.8cm;
                size: A4;
            }

            body {
                margin: 0;
                padding: 0;
                font-size: 11pt;
            }

            .container {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0 1cm;
            }

            h1 {
                font-size: 18pt;
                margin: 0.5cm 0;
            }

            h4 {
                font-size: 12pt;
                margin: 0.5cm 0 0.2cm 0;
                page-break-before: always;
            }

            h4:first-of-type {
                page-break-before: avoid;
            }

            .range-description-container {
                display: none;
            }

            input[type="range"] {
                display: none;
            }

            .range-value {
                font-size: 11pt;
                margin-left: auto;
            }
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">
    <h1 class="text-center my-4">
        Mitarbeitergespräch mit <?php echo htmlspecialchars($employee['name']); ?>
    </h1>
    <div class="thin-divider"></div>
    <h3 class="text-center mb-4 no-print">
        Durchgeführt von <?php echo htmlspecialchars($fullname); ?>
        am <?php echo date('d.m.Y'); ?>
    </h3>
    <div class="thin-divider"></div>

    <form method="post" id="gespraechForm">
        <div class="mb-3">
            <label for="rueckblick" class="form-label">Rückblick auf das vergangene Jahr:</label>
            <textarea class="form-control" id="rueckblick" name="rueckblick" rows="4" minlength="10"
                      required></textarea>
            <span class="char-counter" id="rueckblick-counter">0 / 10 Zeichen</span>
        </div>

        <div class="thin-divider"></div>

        <?php foreach ($skills as $category => $category_skills): ?>
            <h4><?php echo htmlspecialchars($category); ?></h4>
            <?php
            // Beschreibungen
            if ($category === 'Persönliche Kompetenzen' || $category === 'Führungskompetenzen') {
                $descriptions = [
                    "1 - Anforderungen nicht erfüllt",
                    "3 - Anforderungen teilweise erfüllt",
                    "5 - Anforderungen erfüllt",
                    "7 - Anforderungen überdurchschnittlich erfüllt",
                    "9 - Vorbild"
                ];
            } else {
                $descriptions = [
                    "1 - Basiswissen",
                    "3 - kann unter Anleitung anwenden",
                    "5 - selbstständig durchführen (Operator-Level)",
                    "7 - überdurchschnittlich, kann andere anleiten",
                    "9 - Trainer/Spezialist"
                ];
            }
            ?>
            <?php foreach ($category_skills as $skill): ?>
                <?php
                $skillId = $skill['id'];
                $defaultVal = $last_year_ratings[$skillId] ?? 1;
                ?>
                <div class="range-container">
                    <label class="form-label" for="skill_<?php echo $skillId; ?>">
                        <?php echo htmlspecialchars($skill['name']); ?>:
                    </label>
                    <div class="range-wrapper">
                        <input type="range"
                               class="slider"
                               id="skill_<?php echo $skillId; ?>"
                               name="skill_<?php echo $skillId; ?>"
                               min="1" max="9" step="2"
                               value="<?php echo $defaultVal; ?>"
                               required>
                        <span class="range-value" id="value_<?php echo $skillId; ?>">
                            <?php echo $defaultVal; ?>
                        </span>
                    </div>
                    <div class="range-description-container">
                        <?php foreach ($descriptions as $desc): ?>
                            <span class="range-description"><?php echo $desc; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="thin-divider"></div>
        <?php endforeach; ?>

        <div class="mb-3">
            <label for="entwicklung" class="form-label">
                Möchtest du dich mittelfristig entwickeln, bzw. welche Aufgaben/Bereiche interessieren dich zusätzlich?
            </label>
            <textarea class="form-control" id="entwicklung" name="entwicklung" rows="4" minlength="10"
                      required></textarea>
            <span class="char-counter" id="entwicklung-counter">0 / 10 Zeichen</span>
        </div>

        <div class="mb-3">
            <label for="feedback" class="form-label">
                Feedback an die Führungskraft – Wie können wir unsere Zusammenarbeit stärken?
            </label>
            <textarea class="form-control" id="feedback" name="feedback" rows="4" minlength="10" required></textarea>
            <span class="char-counter" id="feedback-counter">0 / 10 Zeichen</span>
        </div>

        <div class="thin-divider"></div>

        <div class="mb-3">
            <label>Möchtest du eine Ausbildung zum Beauftragten absolvieren?</label><br>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="brandschutzwart" name="brandschutzwart">
                <label class="form-check-label" for="brandschutzwart">Brandschutzwart</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="sprinklerwart" name="sprinklerwart">
                <label class="form-check-label" for="sprinklerwart">Sprinklerwart</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="ersthelfer" name="ersthelfer">
                <label class="form-check-label" for="ersthelfer">Ersthelfer</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="svp" name="svp">
                <label class="form-check-label" for="svp">Sicherheitsvertrauensperson</label>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">
                Möchtest du Trainertätigkeiten übernehmen?
            </label>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="trainertaetigkeiten" name="trainertaetigkeiten">
                <label class="form-check-label" for="trainertaetigkeiten">Ja</label>
            </div>
            <textarea class="form-control mt-2"
                      id="trainertaetigkeiten_kommentar"
                      name="trainertaetigkeiten_kommentar"
                      rows="2"
                      placeholder="Kommentar"
                      disabled></textarea>
        </div>

        <div class="mb-3">
            <label>Mitarbeiterzufriedenheit: Bist du zufrieden?</label><br>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="zufrieden" name="zufriedenheit" value="Zufrieden"
                       required>
                <label class="form-check-label" for="zufrieden">Zufrieden</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="grundsätzlich_zufrieden" name="zufriedenheit"
                       value="Grundsätzlich zufrieden" required>
                <label class="form-check-label" for="grundsätzlich_zufrieden">Grundsätzlich zufrieden</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" id="unzufrieden" name="zufriedenheit"
                       value="Unzufrieden" required>
                <label class="form-check-label" for="unzufrieden">Unzufrieden</label>
            </div>
            <div id="unzufriedenheit_grund_container" class="mt-2" style="display: none;">
                <label>Grund für Unzufriedenheit (bitte auswählen):</label><br>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="arbeitsbedingungen"
                           name="unzufriedenheit_grund[]" value="Arbeitsbedingungen">
                    <label class="form-check-label" for="arbeitsbedingungen">Arbeitsbedingungen</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="fehlende_entwicklungsmöglichkeiten"
                           name="unzufriedenheit_grund[]" value="Fehlende Entwicklungsmöglichkeiten">
                    <label class="form-check-label" for="fehlende_entwicklungsmöglichkeiten">Fehlende
                        Entwicklungsmöglichkeiten</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="arbeitsklima"
                           name="unzufriedenheit_grund[]" value="Arbeitsklima">
                    <label class="form-check-label" for="arbeitsklima">Arbeitsklima</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="persönliche_themen"
                           name="unzufriedenheit_grund[]" value="Persönliche Themen">
                    <label class="form-check-label" for="persönliche_themen">Persönliche Themen</label>
                </div>
            </div>
        </div>

        <div class="thin-divider"></div>

        <!-- Modal-Triggers -->
        <div class="btn-container">
            <button type="button" class="btn btn-danger btn-confirm" data-toggle="modal"
                    data-target="#confirmationModal">
                Mitarbeiter bestätigen
            </button>
        </div>

        <div class="btn-container">
            <button type="submit" class="btn btn-primary disabled-btn" id="submitBtn" disabled>
                Gespräch abschließen
            </button>
            <a href="meine_mitarbeiter.php" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>

    <div class="btn-container">
        <button type="button" class="btn btn-secondary" id="printBtn">Seite drucken</button>
    </div>
</div>

<!-- Bestätigungs-Modal -->
<div class="modal fade" id="confirmationModal" tabindex="-1" role="dialog"
     aria-labelledby="confirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5>Bestätigung</h5>
                <button type="button" class="close" data-dismiss="modal"
                        aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Hiermit bestätige ich, <?php echo htmlspecialchars($employee['name']); ?>,
                dass ich alle Antworten zusammen mit meinem Vorgesetzten beantwortet habe
                und mit dem Abschluss des Mitarbeitergesprächs einverstanden bin.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nein</button>
                <button type="button" class="btn btn-primary" id="confirmBtn">Ja</button>
            </div>
        </div>
    </div>
</div>

<!-- jQuery + Bootstrap + Popper.js -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
    // ---------------------------------------------------------------------
    // KEEP-ALIVE alle 5 Minuten, um Session aktiv zu halten
    // ---------------------------------------------------------------------
    setInterval(() => {
        fetch('keep_alive.php', {method: 'POST'})
            .then(response => {
                if (response.status === 440) {
                    alert('Deine Session ist abgelaufen. Bitte neu einloggen.');
                    window.location.reload();
                }
            })
            .catch(err => {
                console.error('Keep-Alive Fehler:', err);
            });
    }, 5 * 60 * 1000); // alle 5 Minuten

    // ---------------------------------------------------------------------
    // Auto-Save alle 2,5 Minuten ins LocalStorage
    // ---------------------------------------------------------------------
    function autoSaveForm() {
        const formData = {
            rueckblick: $('#rueckblick').val(),
            entwicklung: $('#entwicklung').val(),
            feedback: $('#feedback').val(),
            checkboxes: {
                brandschutzwart: $('#brandschutzwart').is(':checked'),
                sprinklerwart: $('#sprinklerwart').is(':checked'),
                ersthelfer: $('#ersthelfer').is(':checked'),
                svp: $('#svp').is(':checked'),
                trainertaetigkeiten: $('#trainertaetigkeiten').is(':checked')
            },
            trainertaetigkeiten_kommentar: $('#trainertaetigkeiten_kommentar').val(),
            zufriedenheit: $('input[name="zufriedenheit"]:checked').val() || '',
            unzufriedenheit_grund: [],
            skills: {},
            timestamp: new Date().getTime()
        };

        // Unzufriedenheit-Gründe
        $('input[name="unzufriedenheit_grund[]"]:checked').each(function () {
            formData.unzufriedenheit_grund.push($(this).val());
        });

        // Skill-Bewertungen
        $('input[type="range"]').each(function () {
            formData.skills[$(this).attr('id')] = $(this).val();
        });

        const key = 'mitarbeitergespraech_<?php echo $employee_id; ?>';
        localStorage.setItem(key, JSON.stringify(formData));
    }

    setInterval(autoSaveForm, 150000); // alle 2,5 Minuten (150.000 ms)

    // ---------------------------------------------------------------------
    // Beim Laden: gespeicherte Daten wiederherstellen
    // ---------------------------------------------------------------------
    $(document).ready(function () {
        const key = 'mitarbeitergespraech_<?php echo $employee_id; ?>';
        const savedData = localStorage.getItem(key);

        // Slider-Hintergrund updaten
        function updateSliderBackground(slider) {
            const min = parseInt(slider.min);
            const max = parseInt(slider.max);
            const val = parseInt(slider.value);
            const pct = ((val - min) / (max - min)) * 100;
            slider.style.background = `linear-gradient(to right, #007bff 0%, #007bff ${pct}%, #ddd ${pct}%, #ddd 100%)`;
        }

        if (savedData) {
            const formData = JSON.parse(savedData);
            // Daten nur laden, wenn sie nicht älter als 24h sind
            const now = new Date().getTime();
            if (now - formData.timestamp < (24 * 60 * 60 * 1000)) {
                // Textfelder
                $('#rueckblick').val(formData.rueckblick);
                $('#entwicklung').val(formData.entwicklung);
                $('#feedback').val(formData.feedback);

                // Checkboxen
                $('#brandschutzwart').prop('checked', formData.checkboxes.brandschutzwart);
                $('#sprinklerwart').prop('checked', formData.checkboxes.sprinklerwart);
                $('#ersthelfer').prop('checked', formData.checkboxes.ersthelfer);
                $('#svp').prop('checked', formData.checkboxes.svp);
                $('#trainertaetigkeiten').prop('checked', formData.checkboxes.trainertaetigkeiten);

                // Kommentar Trainertätigkeiten
                if (formData.checkboxes.trainertaetigkeiten) {
                    $('#trainertaetigkeiten_kommentar').prop('disabled', false).val(formData.trainertaetigkeiten_kommentar);
                }

                // Zufriedenheit
                if (formData.zufriedenheit) {
                    $('input[name="zufriedenheit"][value="' + formData.zufriedenheit + '"]').prop('checked', true);
                    if (formData.zufriedenheit === 'Unzufrieden') {
                        $('#unzufriedenheit_grund_container').show();
                    }
                }

                // Unzufriedenheit-Gründe
                formData.unzufriedenheit_grund.forEach(val => {
                    $('input[name="unzufriedenheit_grund[]"][value="' + val + '"]').prop('checked', true);
                });

                // Skills
                for (let sid in formData.skills) {
                    const el = document.getElementById(sid);
                    if (el) {
                        el.value = formData.skills[sid];
                        updateSliderBackground(el);
                        // Range-Wert aktualisieren
                        const valSpan = $(el).next('.range-value');
                        if (valSpan.length > 0) {
                            valSpan.text(formData.skills[sid]);
                        }
                    }
                }
            }
        }

        // Slider init
        $('input[type="range"]').each(function () {
            updateSliderBackground(this);
        }).on('input', function () {
            updateSliderBackground(this);
            $(this).next('.range-value').text($(this).val());
        });

        // Zeichenzähler
        function updateCharCount(textarea, counterId, minLength = 10) {
            const len = textarea.value.length;
            const ctr = document.getElementById(counterId);
            ctr.textContent = `${len} / ${minLength} Zeichen`;
            if (len < minLength) {
                ctr.classList.remove('valid');
                ctr.classList.add('invalid');
            } else {
                ctr.classList.remove('invalid');
                ctr.classList.add('valid');
            }
        }

        $('#rueckblick').on('input', function () {
            updateCharCount(this, 'rueckblick-counter');
        });
        $('#entwicklung').on('input', function () {
            updateCharCount(this, 'entwicklung-counter');
        });
        $('#feedback').on('input', function () {
            updateCharCount(this, 'feedback-counter');
        });
        // Initial
        updateCharCount(document.getElementById('rueckblick'), 'rueckblick-counter');
        updateCharCount(document.getElementById('entwicklung'), 'entwicklung-counter');
        updateCharCount(document.getElementById('feedback'), 'feedback-counter');

        // Checkboxes/Radio
        $('#trainertaetigkeiten').on('change', function () {
            if ($(this).is(':checked')) {
                $('#trainertaetigkeiten_kommentar').prop('disabled', false);
            } else {
                $('#trainertaetigkeiten_kommentar').prop('disabled', true).val('');
            }
        });
        $('input[name="zufriedenheit"]').on('change', function () {
            if ($(this).val() === 'Unzufrieden') {
                $('#unzufriedenheit_grund_container').show();
            } else {
                $('#unzufriedenheit_grund_container').hide();
                $('#unzufriedenheit_grund_container input[type="checkbox"]').prop('checked', false);
            }
        });

        // "Ja" im Modal -> Submit-Button aktivieren
        $('#confirmBtn').on('click', function () {
            $('#confirmationModal').modal('hide');
            $('#submitBtn').prop('disabled', false).removeClass('disabled-btn');
        });

        // Bei Submit: autosave wegwerfen
        $('#gespraechForm').on('submit', function () {
            localStorage.removeItem('mitarbeitergespraech_<?php echo $employee_id; ?>');
        });

        // Print
        $('#printBtn').on('click', function () {
            window.print();
        });
    });
</script>
</body>
</html>