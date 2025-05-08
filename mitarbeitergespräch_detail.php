<?php
/**
 * mitarbeitergespraech_detail.php
 *
 * Zeigt die Details eines abgeschlossenen Mitarbeitergesprächs (employee_reviews)
 * inklusive optionalem Talent Review.
 *
 * Zugriffskontrolle:
 * - Admin oder HR => immer Zugriff
 * - Reviewer selbst => Zugriff
 * - Abteilungs-/Bereichsleiter => Zugriff, wenn der aufrufende Mitarbeiter
 *   den betroffenen Mitarbeiter (employee_id) als (direkt oder indirekt) unterstellten hat
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();

// Ggf. für Navigation oder Jahr-Filter (optional)
$selectedYear = isset($selectedYear) ? $selectedYear : date('Y');

// Prüfen, ob review_id übergeben
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<p>Ungültige Anfrage: Keine review_id.</p>";
    exit;
}
$review_id = (int)$_GET['id'];

// Gesprächsdaten laden
$stmt = $conn->prepare("
    SELECT 
        er.*,
        e.name AS employee_name,
        e.employee_id AS employee_id,
        r.name AS reviewer_name,
        tr.name AS tr_reviewer_name
    FROM employee_reviews er
    JOIN employees e  ON er.employee_id = e.employee_id
    JOIN employees r  ON er.reviewer_id = r.employee_id
    LEFT JOIN employees tr ON er.tr_reviewer_id = tr.employee_id
    WHERE er.id = ?
");
if (!$stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $review_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<p>Keine Daten gefunden für die angegebene review_id.</p>";
    exit;
}
$review = $result->fetch_assoc();
$stmt->close();

// Zugriff prüfen
$mitarbeiter_id = $_SESSION['mitarbeiter_id']; // Der eingeloggte User

if (ist_admin() || ist_hr()) {
    // Admin/HR haben Zugriff
} else {
    // Prüfen, ob eingeloggter Nutzer = Reviewer
    if ($mitarbeiter_id == $review['reviewer_id']) {
        // Zugriff ok
    } else {
        // Bereichs-/Abteilungsleiter?
        if (ist_leiter()) {
            // Alle Unterstellten laden
            $subordinates = hole_alle_unterstellten_mitarbeiter($mitarbeiter_id);
            if (!in_array($review['employee_id'], $subordinates)) {
                // Nicht unterstellt => kein Zugriff
                header("Location: access_denied.php");
                exit;
            }
        } else {
            // Weder HR/Admin, noch Reviewer, noch Leiter => abweisen
            header("Location: access_denied.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mitarbeitergespräch-Details</title>
    <!-- Lokales Bootstrap 5 CSS -->
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="navbar.css" rel="stylesheet">
    <style>
        p {
            white-space: pre-line; /* Damit Zeilenumbrüche aus der DB erhalten bleiben */
            margin: 0.3rem 0 !important;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="content container">
    <h2 class="text-center mb-3">
        Mitarbeitergespräch-Details: <strong><?php echo htmlspecialchars($review['employee_name']); ?></strong>
    </h2>
    <div class="divider"></div>

    <!-- Basisdaten: Reviewer, Datum -->
    <div class="row mb-2">
        <div class="col-md-6">
            <p>
                <strong>Geführt von:</strong>
                <?php echo htmlspecialchars($review['reviewer_name']); ?>
            </p>
        </div>
        <div class="col-md-6">
            <p>
                <strong>Datum:</strong>
                <?php echo date("d.m.Y", strtotime($review['date'])); ?>
            </p>
        </div>
    </div>

    <!-- Gesprächs-Inhalte -->
    <div class="row">
        <div class="col-md-6">
            <p><strong>Rückblick:</strong> <?php echo htmlspecialchars($review['rueckblick']); ?></p>
            <p><strong>Entwicklung:</strong> <?php echo htmlspecialchars($review['entwicklung']); ?></p>
            <p><strong>Feedback:</strong> <?php echo htmlspecialchars($review['feedback']); ?></p>
        </div>
        <div class="col-md-6">
            <p><strong>Brandschutzwart:</strong> <?php echo $review['brandschutzwart'] ? 'Ja' : 'Nein'; ?></p>
            <p><strong>Sprinklerwart:</strong> <?php echo $review['sprinklerwart'] ? 'Ja' : 'Nein'; ?></p>
            <p><strong>Ersthelfer:</strong> <?php echo $review['ersthelfer'] ? 'Ja' : 'Nein'; ?></p>
            <p><strong>Sicherheitsvertrauensperson:</strong> <?php echo $review['svp'] ? 'Ja' : 'Nein'; ?></p>
        </div>
    </div>

    <p>
        <strong>Trainertätigkeiten:</strong>
        <?php echo $review['trainertaetigkeiten'] ? 'Ja' : 'Nein'; ?>
        <?php if (!empty($review['trainertaetigkeiten_kommentar'])): ?>
            •• <?php echo htmlspecialchars($review['trainertaetigkeiten_kommentar']); ?>
        <?php endif; ?>
    </p>
    <p>
        <strong>Zufriedenheit:</strong>
        <?php echo htmlspecialchars($review['zufriedenheit']); ?>
        <?php if ($review['zufriedenheit'] === 'Unzufrieden' && !empty($review['unzufriedenheit_grund'])): ?>
            •• <?php echo htmlspecialchars($review['unzufriedenheit_grund']); ?>
        <?php endif; ?>
    </p>

    <div class="mt-4">
        <h2 class="text-center mb-3">Talent Review-Details</h2>
        <div class="divider"></div>

        <?php if (empty($review['tr_date'])): ?>
            <div class="alert alert-info">
                Talent Review wurde noch nicht durchgeführt.
            </div>
        <?php else: ?>
            <div class="row mb-2">
                <?php if (!empty($review['tr_reviewer_name'])): ?>
                    <div class="col-md-6">
                        <p>
                            <strong>Durchgeführt von:</strong>
                            <?php echo htmlspecialchars($review['tr_reviewer_name']); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <p>
                        <strong>Datum:</strong>
                        <?php echo date("d.m.Y", strtotime($review['tr_date'])); ?>
                    </p>
                </div>
            </div>
            <p>
                <strong>Leistungseinschätzung:</strong>
                <?php echo htmlspecialchars($review['tr_performance_assessment']); ?>
                <?php if (!empty($review['tr_performance_comment'])): ?>
                    •• <?php echo htmlspecialchars($review['tr_performance_comment']); ?>
                <?php endif; ?>
            </p>
            <p>
                <strong>Talent-Einschätzung:</strong>
                <?php echo htmlspecialchars($review['tr_talent']); ?>
            </p>
            <?php if (!empty($review['tr_career_plan'])): ?>
                <p>
                    <strong>Karriereplanung:</strong>
                    <?php echo htmlspecialchars($review['tr_career_plan']); ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($review['tr_career_plan_other'])): ?>
                <p>
                    <strong>Weitere Karrierepläne:</strong>
                    <?php echo htmlspecialchars($review['tr_career_plan_other']); ?>
                </p>
            <?php endif; ?>

            <h4 class="mt-3">Entwicklungsmaßnahmen:</h4>
            <?php if ($review['tr_action_extra_tasks']): ?>
                <p>
                    <strong>Zusätzliche Aufgaben:</strong>
                    <?php echo htmlspecialchars($review['tr_action_extra_tasks_comment']); ?>
                </p>
            <?php endif; ?>
            <?php if ($review['tr_action_on_job_training']): ?>
                <p>
                    <strong>On Job Training:</strong>
                    <?php echo htmlspecialchars($review['tr_action_on_job_training_comment']); ?>
                </p>
            <?php endif; ?>
            <?php if ($review['tr_action_school_completion']): ?>
                <p>
                    <strong>Schul-/Lehrabschluss:</strong>
                    <?php echo htmlspecialchars($review['tr_action_school_completion_comment']); ?>
                </p>
            <?php endif; ?>
            <?php if ($review['tr_action_specialist_knowledge']): ?>
                <p>
                    <strong>Spezialistenkenntnisse:</strong>
                    <?php echo htmlspecialchars($review['tr_action_specialist_knowledge_comment']); ?>
                </p>
            <?php endif; ?>
            <?php if ($review['tr_action_generalist_knowledge']): ?>
                <p>
                    <strong>Generalistenkenntnisse:</strong>
                    <?php echo htmlspecialchars($review['tr_action_generalist_knowledge_comment']); ?>
                </p>
            <?php endif; ?>

            <h4 class="mt-3">Empfohlene Trainings:</h4>
            <?php if ($review['tr_external_training_industry_foreman']): ?>
                <p>Industrievorarbeiter</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_industry_master']): ?>
                <p>Industriemeister</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_german']): ?>
                <p>Deutsch (Kommentar: <?php echo htmlspecialchars($review['tr_external_training_german_comment']); ?>
                    )</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_qs_basics']): ?>
                <p>QS Grundlagen</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_qs_assistant']): ?>
                <p>QS Assistent</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_qs_technician']): ?>
                <p>QS Techniker</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_sps_basics']): ?>
                <p>SPS Grundlagen</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_sps_advanced']): ?>
                <p>SPS Fortgeschritten</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_forklift']): ?>
                <p>Stapler</p>
            <?php endif; ?>
            <?php if ($review['tr_external_training_other']): ?>
                <p>Sonstige externe Trainings:
                    <?php echo htmlspecialchars($review['tr_external_training_other_comment']); ?>
                </p>
            <?php endif; ?>

            <div class="mt-3">
                <h4>Lohnerhöhung:</h4>
                <p>
                    <strong>Relevant für Gehaltserhöhung:</strong>
                    <?php echo $review['tr_relevant_for_raise'] ? 'Ja' : 'Nein'; ?>

                    <?php
                    // Sammle beantragte Lohnarten
                    $lohnarten = [];
                    if ($review['tr_pr_anfangslohn']) {
                        $lohnarten[] = 'Anfangslohn';
                    }
                    if ($review['tr_pr_grundlohn']) {
                        $lohnarten[] = 'Grundlohn';
                    }
                    if ($review['tr_pr_qualifikationsbonus']) {
                        $lohnarten[] = 'Qualifikationsbonus';
                    }
                    if ($review['tr_pr_expertenbonus']) {
                        $lohnarten[] = 'Expertenbonus';
                    }
                    if ($review['tr_tk_qualifikationsbonus_1']) {
                        $lohnarten[] = 'Quali-Bonus 1';
                    }
                    if ($review['tr_tk_qualifikationsbonus_2']) {
                        $lohnarten[] = 'Quali-Bonus 2';
                    }
                    if ($review['tr_tk_qualifikationsbonus_3']) {
                        $lohnarten[] = 'Quali-Bonus 3';
                    }
                    if ($review['tr_tk_qualifikationsbonus_4']) {
                        $lohnarten[] = 'Quali-Bonus 4';
                    }

                    if (!empty($lohnarten)) {
                        echo ' •• beantragt: ' . implode(', ', $lohnarten);
                    }
                    ?>
                </p>
                <?php if (!empty($review['tr_salary_increase_argumentation'])): ?>
                    <p>
                        <strong>Argumentation für Gehaltserhöhung:</strong>
                        <?php echo htmlspecialchars($review['tr_salary_increase_argumentation']); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="divider"></div>
    <a href="javascript:history.back();" class="btn btn-secondary mt-3">Zurück</a>
</div>

<!-- Lokales Bootstrap 5 JavaScript Bundle -->
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>