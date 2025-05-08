<?php
include 'access_control.php';

global $conn;
pruefe_benutzer_eingeloggt();

$tr_reviewer_id = $_SESSION['mitarbeiter_id'];

// Mitarbeiter-ID aus der URL
if (!isset($_GET['employee_id'])) {
    echo '<p>Keine Mitarbeiter-ID angegeben</p>';
    exit;
}

$employee_id = (int)$_GET['employee_id'];

// Zugriff prüfen
if (!hat_zugriff_auf_mitarbeiter($employee_id)) {
    header("Location: access_denied.php");
    exit;
}

// Mitarbeiter-Daten holen
$stmt = $conn->prepare("SELECT name, crew, position, pr_anfangslohn, pr_grundlohn, pr_qualifikationsbonus, pr_expertenbonus, tk_qualifikationsbonus_1, tk_qualifikationsbonus_2, tk_qualifikationsbonus_3, tk_qualifikationsbonus_4, lohnschema FROM employees WHERE employee_id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $fullname = $user['name'];
    $lohnschema = $user['lohnschema'];
    $pr_anfangslohn = $user['pr_anfangslohn'];
    $pr_grundlohn = $user['pr_grundlohn'];
    $pr_qualifikationsbonus = $user['pr_qualifikationsbonus'];
    $pr_expertenbonus = $user['pr_expertenbonus'];
    $tk_qualifikationsbonus_1 = $user['tk_qualifikationsbonus_1'];
    $tk_qualifikationsbonus_2 = $user['tk_qualifikationsbonus_2'];
    $tk_qualifikationsbonus_3 = $user['tk_qualifikationsbonus_3'];
    $tk_qualifikationsbonus_4 = $user['tk_qualifikationsbonus_4'];
} else {
    // Fallback-Werte
    $fullname = 'Unbekannt';
    $lohnschema = '---';
    $pr_anfangslohn = 0;
    $pr_grundlohn = 0;
    $pr_qualifikationsbonus = 0;
    $pr_expertenbonus = 0;
    $tk_qualifikationsbonus_1 = 0;
    $tk_qualifikationsbonus_2 = 0;
    $tk_qualifikationsbonus_3 = 0;
    $tk_qualifikationsbonus_4 = 0;
}
$stmt->close();

// Letztes Review holen
$stmt = $conn->prepare("SELECT * FROM employee_reviews WHERE employee_id = ? ORDER BY date DESC LIMIT 1");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $review = $result->fetch_assoc();
} else {
    echo '<p>Keine Daten gefunden für die angegebene Mitarbeiter-ID.</p>';
    exit;
}
$stmt->close();

// Speichern bei POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tr_date = date('Y-m-d');
    $tr_performance_assessment = $_POST['tr_performance_assessment'];
    $tr_performance_comment = $_POST['tr_performance_comment'] ?? null;
    $tr_talent = $_POST['tr_talent'];
    $tr_career_plan = $_POST['tr_career_plan'] ?? null;
    $tr_career_plan_other = $_POST['tr_career_plan_other'] ?? null;

    $tr_action_extra_tasks = isset($_POST['tr_action_extra_tasks']) ? 1 : 0;
    $tr_action_extra_tasks_comment = $_POST['tr_action_extra_tasks_comment'] ?? null;

    $tr_action_on_job_training = isset($_POST['tr_action_on_job_training']) ? 1 : 0;
    $tr_action_on_job_training_comment = $_POST['tr_action_on_job_training_comment'] ?? null;

    $tr_action_school_completion = isset($_POST['tr_action_school_completion']) ? 1 : 0;
    $tr_action_school_completion_comment = $_POST['tr_action_school_completion_comment'] ?? null;

    $tr_action_specialist_knowledge = isset($_POST['tr_action_specialist_knowledge']) ? 1 : 0;
    $tr_action_specialist_knowledge_comment = $_POST['tr_action_specialist_knowledge_comment'] ?? null;

    $tr_action_generalist_knowledge = isset($_POST['tr_action_generalist_knowledge']) ? 1 : 0;
    $tr_action_generalist_knowledge_comment = $_POST['tr_action_generalist_knowledge_comment'] ?? null;

    $tr_external_training_industry_foreman = isset($_POST['tr_external_training_industry_foreman']) ? 1 : 0;
    $tr_external_training_industry_master = isset($_POST['tr_external_training_industry_master']) ? 1 : 0;
    $tr_external_training_german = isset($_POST['tr_external_training_german']) ? 1 : 0;
    $tr_external_training_german_comment = $_POST['tr_external_training_german_comment'] ?? null;
    $tr_external_training_qs_basics = isset($_POST['tr_external_training_qs_basics']) ? 1 : 0;
    $tr_external_training_qs_assistant = isset($_POST['tr_external_training_qs_assistant']) ? 1 : 0;
    $tr_external_training_qs_technician = isset($_POST['tr_external_training_qs_technician']) ? 1 : 0;
    $tr_external_training_sps_basics = isset($_POST['tr_external_training_sps_basics']) ? 1 : 0;
    $tr_external_training_sps_advanced = isset($_POST['tr_external_training_sps_advanced']) ? 1 : 0;
    $tr_external_training_forklift = isset($_POST['tr_external_training_forklift']) ? 1 : 0;
    $tr_external_training_other = isset($_POST['tr_external_training_other']) ? 1 : 0;
    $tr_external_training_other_comment = $_POST['tr_external_training_other_comment'] ?? null;

    $tr_internal_training_best_leadership = isset($_POST['tr_internal_training_best_leadership']) ? 1 : 0;
    $tr_internal_training_jbs = isset($_POST['tr_internal_training_jbs']) ? 1 : 0;
    $tr_internal_training_jbs_comment = $_POST['tr_internal_training_jbs_comment'] ?? null;

    $tr_department_training = isset($_POST['tr_department_training']) ? 1 : 0;
    $tr_department_training_comment = $_POST['tr_department_training_comment'] ?? null;

    $tr_relevant_for_raise = isset($_POST['tr_relevant_for_raise']) ? 1 : 0;
    $tr_pr_anfangslohn = isset($_POST['tr_pr_anfangslohn']) ? 1 : 0;
    $tr_pr_grundlohn = isset($_POST['tr_pr_grundlohn']) ? 1 : 0;
    $tr_pr_qualifikationsbonus = isset($_POST['tr_pr_qualifikationsbonus']) ? 1 : 0;
    $tr_pr_expertenbonus = isset($_POST['tr_pr_expertenbonus']) ? 1 : 0;
    $tr_tk_qualifikationsbonus_1 = isset($_POST['tr_tk_qualifikationsbonus_1']) ? 1 : 0;
    $tr_tk_qualifikationsbonus_2 = isset($_POST['tr_tk_qualifikationsbonus_2']) ? 1 : 0;
    $tr_tk_qualifikationsbonus_3 = isset($_POST['tr_tk_qualifikationsbonus_3']) ? 1 : 0;
    $tr_tk_qualifikationsbonus_4 = isset($_POST['tr_tk_qualifikationsbonus_4']) ? 1 : 0;
    $tr_salary_increase_argumentation = $_POST['tr_salary_increase_argumentation'] ?? null;

    $stmt = $conn->prepare("
        UPDATE employee_reviews 
        SET 
            tr_date = ?, 
            tr_performance_assessment = ?, 
            tr_performance_comment = ?, 
            tr_talent = ?, 
            tr_career_plan = ?, 
            tr_career_plan_other = ?, 
            tr_action_extra_tasks = ?, 
            tr_action_extra_tasks_comment = ?, 
            tr_action_on_job_training = ?, 
            tr_action_on_job_training_comment = ?, 
            tr_action_school_completion = ?, 
            tr_action_school_completion_comment = ?, 
            tr_action_specialist_knowledge = ?, 
            tr_action_specialist_knowledge_comment = ?, 
            tr_action_generalist_knowledge = ?, 
            tr_action_generalist_knowledge_comment = ?, 
            tr_external_training_industry_foreman = ?, 
            tr_external_training_industry_master = ?, 
            tr_external_training_german = ?, 
            tr_external_training_german_comment = ?, 
            tr_external_training_qs_basics = ?, 
            tr_external_training_qs_assistant = ?, 
            tr_external_training_qs_technician = ?, 
            tr_external_training_sps_basics = ?, 
            tr_external_training_sps_advanced = ?, 
            tr_external_training_forklift = ?, 
            tr_external_training_other = ?, 
            tr_external_training_other_comment = ?, 
            tr_internal_training_best_leadership = ?, 
            tr_internal_training_jbs = ?, 
            tr_internal_training_jbs_comment = ?, 
            tr_department_training = ?, 
            tr_department_training_comment = ?, 
            tr_reviewer_id = ?,
            tr_relevant_for_raise = ?, 
            tr_pr_anfangslohn = ?,
            tr_pr_grundlohn = ?,
            tr_pr_qualifikationsbonus = ?,
            tr_pr_expertenbonus = ?,
            tr_tk_qualifikationsbonus_1 = ?,
            tr_tk_qualifikationsbonus_2 = ?,
            tr_tk_qualifikationsbonus_3 = ?,
            tr_tk_qualifikationsbonus_4 = ?,
            tr_salary_increase_argumentation = ?
        WHERE 
            employee_id = ? 
            AND id = ?
    ");

    $stmt->bind_param(
        "ssssssisisisisisiiisiiiiiiisiisisiiiiiiiiiisii",
        $tr_date,
        $tr_performance_assessment,
        $tr_performance_comment,
        $tr_talent,
        $tr_career_plan,
        $tr_career_plan_other,
        $tr_action_extra_tasks,
        $tr_action_extra_tasks_comment,
        $tr_action_on_job_training,
        $tr_action_on_job_training_comment,
        $tr_action_school_completion,
        $tr_action_school_completion_comment,
        $tr_action_specialist_knowledge,
        $tr_action_specialist_knowledge_comment,
        $tr_action_generalist_knowledge,
        $tr_action_generalist_knowledge_comment,
        $tr_external_training_industry_foreman,
        $tr_external_training_industry_master,
        $tr_external_training_german,
        $tr_external_training_german_comment,
        $tr_external_training_qs_basics,
        $tr_external_training_qs_assistant,
        $tr_external_training_qs_technician,
        $tr_external_training_sps_basics,
        $tr_external_training_sps_advanced,
        $tr_external_training_forklift,
        $tr_external_training_other,
        $tr_external_training_other_comment,
        $tr_internal_training_best_leadership,
        $tr_internal_training_jbs,
        $tr_internal_training_jbs_comment,
        $tr_department_training,
        $tr_department_training_comment,
        $tr_reviewer_id,
        $tr_relevant_for_raise,
        $tr_pr_anfangslohn,
        $tr_pr_grundlohn,
        $tr_pr_qualifikationsbonus,
        $tr_pr_expertenbonus,
        $tr_tk_qualifikationsbonus_1,
        $tr_tk_qualifikationsbonus_2,
        $tr_tk_qualifikationsbonus_3,
        $tr_tk_qualifikationsbonus_4,
        $tr_salary_increase_argumentation,
        $employee_id,
        $review['id']
    );

    $exec_result = $stmt->execute();
    if ($exec_result === false) {
        die('Execute failed: ' . htmlspecialchars($stmt->error));
    }

    $stmt->close();
    header("Location: bestaetigung.php?employee_id=$employee_id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Talent Review - <?php echo htmlspecialchars($fullname); ?></title>
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        .container {
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            box-shadow: 0px 0px 10px rgba(0,0,0,0.1);
        }
        .conditional-section { display: none; }
        label { font-weight: bold; }
        .divider { border-bottom: 2px solid #dee2e6; margin: 30px 0; }
        .form-check-label { font-weight: normal; }
        .form-control { margin-top: 8px; }
        textarea { resize: vertical; }
        .popover { max-width: 600px; }
        .info-btn {
            background: none;
            border: none;
            padding: 0;
            color: #0056b3;
            cursor: pointer;
            outline: none;
        }
        .info-btn:hover {
            color: #004080;
        }
        .disabled-check {
            pointer-events: none;
            opacity: 0.6;
        }
    </style>
</head>
<body>
<div class="container">
    <h1 class="text-center mb-4">Talent Review von <?php echo htmlspecialchars($fullname); ?></h1>
    <div class="divider"></div>
    <form id="talentReviewForm" action="talent_review.php?employee_id=<?php echo htmlspecialchars($employee_id); ?>" method="POST">
        <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($employee_id); ?>">

        <!-- Leistungseinschätzung -->
        <div class="mb-3">
            <label for="tr_performance_assessment">
                Leistungseinschätzung durch Führungskraft:
                <button type="button" class="info-btn" data-toggle="popover" data-html="true" title="Leistungseinschätzung-Stufen" data-content="
                    <strong>Entwicklung:</strong> Die Person zeigt Potenzial, benötigt jedoch gezielte Unterstützung oder Training, um die Leistung zu steigern. Dies kann durch Coaching durch die Führungskraft, Schulungen oder verlängertes/weiteres Mentoring geschehen, um spezifische Fähigkeiten zu fördern.
                    <br> <i>(In der Regel 5 % der Mitarbeitenden)</i>
                    <br><br>
                    <strong>Erfüllt:</strong> Die Person erfüllt die Erwartungen und Anforderungen der aktuellen Rolle. Sie zeigt eine solide Leistung und trägt positiv zum Team bei.
                    <br> <i>(In der Regel 90 % der Mitarbeitenden)</i>
                    <br><br>
                    <strong>Überdurchschnittlich:</strong> Die Person übertrifft die Erwartungen und liefert regelmäßig qualitativ hochwertige Ergebnisse. Sie bringt innovative Ideen ein und hat einen positiven Einfluss auf das Team und/oder das Unternehmen.
                    <br> <i>(In der Regel 5 % der Mitarbeitenden)</i>
                ">
                    <i class="fas fa-info-circle"></i>
                </button>
            </label>
            <select id="tr_performance_assessment" name="tr_performance_assessment" class="form-control" required>
                <option value="">Bitte auswählen</option>
                <option value="Entwicklung" <?php if ($review['tr_performance_assessment'] == 'Entwicklung') echo 'selected'; ?>>Entwicklung</option>
                <option value="erfüllt" <?php if ($review['tr_performance_assessment'] == 'erfüllt') echo 'selected'; ?>>Erfüllt</option>
                <option value="überdurchschnittlich" <?php if ($review['tr_performance_assessment'] == 'überdurchschnittlich') echo 'selected'; ?>>Überdurchschnittlich</option>
            </select>
        </div>

        <div class="mb-3 conditional-section" id="tr_performance_comment_section">
            <label for="tr_performance_comment">Kommentar zur Leistungseinschätzung:</label>
            <textarea id="tr_performance_comment" name="tr_performance_comment" class="form-control" rows="3"><?php echo htmlspecialchars($review['tr_performance_comment']); ?></textarea>
        </div>

        <!-- Talent -->
        <div class="mb-3">
            <label for="tr_talent">Talent:
                <button type="button" class="info-btn" data-toggle="popover" data-html="true" title="Talent-Status" data-content="
                    <strong>Neu in der Rolle:</strong> Die Person hat kürzlich eine neue Position übernommen und befindet sich in der Einarbeitungsphase. Sie benötigt Zeit und Unterstützung, um sich in die Rolle einzuarbeiten und sich an die Anforderungen anzupassen.
                    <br><br>
                    <strong>Braucht Entwicklung:</strong> Die Person zeigt Potenzial, hat jedoch spezifischen Entwicklungsbedarf (persönlich oder fachlich). Sie benötigt gezielte Unterstützung, um ihre Fähigkeiten zu verbessern und die Leistung zu steigern.
                    <br><br>
                    <strong>Performing Talent:</strong> Die Person zeigt bereits hohe Leistungen und hat das Potenzial, sich weiterzuentwickeln. Sie ist motiviert und bringt wertvolle Fähigkeiten mit, die sie für neue Herausforderungen qualifizieren. Hierzu zählen auch Personen, die zwar Potenzial haben, sich jedoch bewusst entschieden haben, keine weiteren Positionen anzustreben (= zufrieden mit der Position).
                    <br><br>
                    <strong>Aufstrebendes Talent:</strong> Die Person hat großes Potenzial und zeigt den Wunsch, sich weiterzuentwickeln. Mit der richtigen Förderung könnte sie bald für höhere Positionen geeignet sein.
                    <br><br>
                    <strong>Fertiges Talent:</strong> Die Person hat die erforderlichen Fähigkeiten und Erfahrungen, um in Führungs- oder Spezialistenrollen zu arbeiten. Sie ist bereit, größere Verantwortung zu übernehmen und kann als Mentor für andere fungieren.
                ">
                    <i class="fas fa-info-circle"></i>
                </button>
            </label>
            <select id="tr_talent" name="tr_talent" class="form-control" required>
                <option value="">Bitte auswählen</option>
                <option value="Neu in der Rolle" <?php if ($review['tr_talent'] == 'Neu in der Rolle') echo 'selected'; ?>>Neu in der Rolle</option>
                <option value="Braucht Entwicklung" <?php if ($review['tr_talent'] == 'Braucht Entwicklung') echo 'selected'; ?>>Braucht Entwicklung</option>
                <option value="Performing Talent" <?php if ($review['tr_talent'] == 'Performing Talent') echo 'selected'; ?>>Performing Talent</option>
                <option value="Aufstrebendes Talent" <?php if ($review['tr_talent'] == 'Aufstrebendes Talent') echo 'selected'; ?>>Aufstrebendes Talent</option>
                <option value="Fertiges Talent" <?php if ($review['tr_talent'] == 'Fertiges Talent') echo 'selected'; ?>>Fertiges Talent</option>
            </select>
        </div>

        <!-- Karriereplanung -->
        <div class="mb-3 conditional-section" id="tr_career_plan_section">
            <label for="tr_career_plan">Karriereplanung:</label>
            <select id="tr_career_plan" name="tr_career_plan" class="form-control">
                <option value="">Bitte auswählen</option>
                <option value="Weiterentwicklung Führungskarriere" <?php if ($review['tr_career_plan'] == 'Weiterentwicklung Führungskarriere') echo 'selected'; ?>>Weiterentwicklung Führungskarriere</option>
                <option value="Fachkarriere/Wechsel Bereich Produktion" <?php if ($review['tr_career_plan'] == 'Fachkarriere/Wechsel Bereich Produktion') echo 'selected'; ?>>Fachkarriere/Wechsel Bereich Produktion</option>
                <option value="Fachkarriere/Wechsel Bereich Technik" <?php if ($review['tr_career_plan'] == 'Fachkarriere/Wechsel Bereich Technik') echo 'selected'; ?>>Fachkarriere/Wechsel Bereich Technik</option>
                <option value="Fachkarriere/Wechsel Bereich QS" <?php if ($review['tr_career_plan'] == 'Fachkarriere/Wechsel Bereich QS') echo 'selected'; ?>>Fachkarriere/Wechsel Bereich QS</option>
                <option value="Fachkarriere/Wechsel Bereich CPO" <?php if ($review['tr_career_plan'] == 'Fachkarriere/Wechsel Bereich CPO') echo 'selected'; ?>>Fachkarriere/Wechsel Bereich CPO</option>
                <option value="Sonstiges" <?php if ($review['tr_career_plan'] == 'Sonstiges') echo 'selected'; ?>>Sonstiges</option>
            </select>
        </div>

        <div class="mb-3 conditional-section" id="tr_career_plan_other_section">
            <label for="tr_career_plan_other">Sonstiges (Karriereplanung):</label>
            <textarea id="tr_career_plan_other" name="tr_career_plan_other" class="form-control" rows="3"><?php echo htmlspecialchars($review['tr_career_plan_other']); ?></textarea>
        </div>

        <!-- Aktionen zur Entwicklung -->
        <div class="mb-3 conditional-section" id="tr_development_actions_section">
            <label>Aktionen zur Entwicklung:</label>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_action_extra_tasks" name="tr_action_extra_tasks" value="1" <?php if ($review['tr_action_extra_tasks']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_action_extra_tasks">Übernahme von zusätzlichen Aufgaben/Projekte</label>
                <textarea id="tr_action_extra_tasks_comment" name="tr_action_extra_tasks_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_action_extra_tasks_comment']); ?></textarea>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_action_on_job_training" name="tr_action_on_job_training" value="1" <?php if ($review['tr_action_on_job_training']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_action_on_job_training">Zusätzliches "On Job Training"</label>
                <textarea id="tr_action_on_job_training_comment" name="tr_action_on_job_training_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_action_on_job_training_comment']); ?></textarea>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_action_school_completion" name="tr_action_school_completion" value="1" <?php if ($review['tr_action_school_completion']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_action_school_completion">Schul-/Lehrabschluss nachholen</label>
                <textarea id="tr_action_school_completion_comment" name="tr_action_school_completion_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_action_school_completion_comment']); ?></textarea>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_action_specialist_knowledge" name="tr_action_specialist_knowledge" value="1" <?php if ($review['tr_action_specialist_knowledge']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_action_specialist_knowledge">Aufbau Spezialistenkenntnisse</label>
                <textarea id="tr_action_specialist_knowledge_comment" name="tr_action_specialist_knowledge_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_action_specialist_knowledge_comment']); ?></textarea>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_action_generalist_knowledge" name="tr_action_generalist_knowledge" value="1" <?php if ($review['tr_action_generalist_knowledge']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_action_generalist_knowledge">Aufbau Generalistenkenntnisse</label>
                <textarea id="tr_action_generalist_knowledge_comment" name="tr_action_generalist_knowledge_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_action_generalist_knowledge_comment']); ?></textarea>
            </div>
        </div>

        <!-- Externe Aus-/Weiterbildung -->
        <div class="mb-3 conditional-section" id="tr_external_training_section">
            <label>EMPFEHLUNG - Externe Aus-/Weiterbildung:</label>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_industry_foreman" name="tr_external_training_industry_foreman" value="1" <?php if ($review['tr_external_training_industry_foreman']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_industry_foreman">Industrievorarbeiter</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_industry_master" name="tr_external_training_industry_master" value="1" <?php if ($review['tr_external_training_industry_master']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_industry_master">Industriemeister</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_german" name="tr_external_training_german" value="1" <?php if ($review['tr_external_training_german']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_german">Deutsch</label>
                <textarea id="tr_external_training_german_comment" name="tr_external_training_german_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_external_training_german_comment']); ?></textarea>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_qs_basics" name="tr_external_training_qs_basics" value="1" <?php if ($review['tr_external_training_qs_basics']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_qs_basics">QS Grundlagen</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_qs_assistant" name="tr_external_training_qs_assistant" value="1" <?php if ($review['tr_external_training_qs_assistant']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_qs_assistant">QS Assistent</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_qs_technician" name="tr_external_training_qs_technician" value="1" <?php if ($review['tr_external_training_qs_technician']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_qs_technician">QS Techniker</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_sps_basics" name="tr_external_training_sps_basics" value="1" <?php if ($review['tr_external_training_sps_basics']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_sps_basics">SPS Grundlagen</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_sps_advanced" name="tr_external_training_sps_advanced" value="1" <?php if ($review['tr_external_training_sps_advanced']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_sps_advanced">SPS Fortgeschrittene</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_forklift" name="tr_external_training_forklift" value="1" <?php if ($review['tr_external_training_forklift']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_forklift">Stapler</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_external_training_other" name="tr_external_training_other" value="1" <?php if ($review['tr_external_training_other']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_external_training_other">Sonstiges</label>
                <textarea id="tr_external_training_other_comment" name="tr_external_training_other_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_external_training_other_comment']); ?></textarea>
            </div>
        </div>

        <div class="alert alert-danger conditional-section" id="external_training_alert" role="alert">
            Achtung! Wenn hier eine Aus-/Weiterbildung bei "Sonstiges" eingetragen wird, müssen auch die Kurskosten und andere Infos wie davor im Powerpoint (Talent Review) eingetragen werden!<br>
            <strong>Format: Kursname | Anbieter | Gesamtkosten netto</strong>
        </div>

        <!-- Interne Aus-/Weiterbildung -->
        <div class="mb-3 conditional-section" id="tr_internal_training_section">
            <label>EMPFEHLUNG - Interne Aus-/Weiterbildung:</label>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_internal_training_best_leadership" name="tr_internal_training_best_leadership" value="1" <?php if ($review['tr_internal_training_best_leadership']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_internal_training_best_leadership">BEST - Führung</label>
            </div>

            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="tr_internal_training_jbs" name="tr_internal_training_jbs" value="1" <?php if ($review['tr_internal_training_jbs']) echo 'checked'; ?>>
                <label class="form-check-label" for="tr_internal_training_jbs">JBS Training (Technical Training Manager)</label>
                <textarea id="tr_internal_training_jbs_comment" name="tr_internal_training_jbs_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_internal_training_jbs_comment']); ?></textarea>
            </div>
        </div>

        <!-- Abteilungsorganisierte Aus-/Weiterbildung -->
        <div class="mb-3">
            <label for="tr_department_training">Abteilungsorganisierte Aus-/Weiterbildung:</label>
            <input type="checkbox" id="tr_department_training" name="tr_department_training" value="1" <?php if ($review['tr_department_training']) echo 'checked'; ?>>
            <button type="button" class="info-btn" data-toggle="popover" data-html="true" title="Was ist das?" data-content="
        <strong>Abteilungsorganisierte Aus-/Weiterbildungen</strong> sind Kurse/Weiterbildungen, die die ganze Abteilung (bzw. zumindest mehrere Mitarbeiter) betreffen. Zum Beispiel: Henkel Schulung CPO oder interne SPS Schulung bei den Elektrikern.
        Den Kurs bitte dementsprechend bei jedem Mitarbeiter angeben. Es reicht aber, bei jedem Mitarbeiter die Gesamtsumme anzugeben (kein Einzelpreis notwendig).
    ">
                <i class="fas fa-info-circle"></i>
            </button>
            <textarea id="tr_department_training_comment" name="tr_department_training_comment" class="form-control mt-2"><?php echo htmlspecialchars($review['tr_department_training_comment']); ?></textarea>
        </div>

        <!-- Alert für Abteilungsorganisierte Aus-/Weiterbildung -->
        <div class="alert alert-danger conditional-section" role="alert" id="department_training_alert">
            Achtung! Auch hier alles nötige angeben!<br>
            <strong>Format: Kursname | Anbieter | Gesamtkosten netto</strong>
        </div>


        <!-- Gehaltserhöhung relevant -->
        <?php if ($lohnschema === 'Produktion' || $lohnschema === 'Technik'): ?>
            <div class="mb-3">
                <label for="tr_relevant_for_raise">Relevant für Gehaltserhöhung:</label>
                <input type="checkbox" id="tr_relevant_for_raise" name="tr_relevant_for_raise" value="1" <?php if (!empty($review['tr_relevant_for_raise'])) echo 'checked'; ?>>
            </div>

            <?php
            // Produktions-Logik
            if ($lohnschema === 'Produktion') {
                if ($pr_grundlohn == 1) {
                    // Bereits Grundlohn vorhanden
                    $anfangslohnDisabled = 'disabled';
                    $grundlohnDisabled = 'checked disabled';
                    $qualifikationsbonusDisabled = ($pr_qualifikationsbonus == 1) ? 'checked disabled' : '';
                    $expertenbonusDisabled = ($pr_expertenbonus == 1) ? 'checked disabled' : '';
                } else if ($pr_anfangslohn == 1) {
                    // Nur Anfangslohn vorhanden, noch kein Grundlohn
                    $anfangslohnDisabled = 'checked disabled';
                    $grundlohnDisabled = ''; // jetzt freigegeben
                    $qualifikationsbonusDisabled = 'disabled'; // Boni noch nicht erlaubt
                    $expertenbonusDisabled = 'disabled'; // Boni noch nicht erlaubt
                } else {
                    // Noch kein Anfangslohn, keine Stufe
                    $anfangslohnDisabled = ($pr_anfangslohn == 1) ? 'checked disabled' : '';
                    $grundlohnDisabled = ($pr_grundlohn == 1 ? 'checked disabled' : ($pr_anfangslohn == 1 ? 'disabled' : ''));
                    $qualifikationsbonusDisabled = ($pr_qualifikationsbonus == 1) ? 'checked disabled' : '';
                    $expertenbonusDisabled = ($pr_expertenbonus == 1) ? 'checked disabled' : '';
                }
            }

            // Technik-Logik
            if ($lohnschema === 'Technik') {
                if($tk_qualifikationsbonus_4 == 1) {
                    $tk1Props = 'checked disabled';
                    $tk2Props = 'checked disabled';
                    $tk3Props = 'checked disabled';
                    $tk4Props = 'checked disabled';
                } else if($tk_qualifikationsbonus_3 == 1) {
                    $tk1Props = 'checked disabled';
                    $tk2Props = 'checked disabled';
                    $tk3Props = 'checked disabled';
                    $tk4Props = '';
                } else if($tk_qualifikationsbonus_2 == 1) {
                    $tk1Props = 'checked disabled';
                    $tk2Props = 'checked disabled';
                    $tk3Props = '';
                    $tk4Props = 'disabled';
                } else if($tk_qualifikationsbonus_1 == 1) {
                    $tk1Props = 'checked disabled';
                    $tk2Props = '';
                    $tk3Props = 'disabled';
                    $tk4Props = 'disabled';
                } else {
                    $tk1Props = '';
                    $tk2Props = 'disabled';
                    $tk3Props = 'disabled';
                    $tk4Props = 'disabled';
                }
            }
            ?>

            <?php if ($lohnschema === 'Produktion'): ?>
                <div class="mb-3 conditional-section" id="salary_increase_production_section">
                    <label>Gehaltserhöhung - Produktion:
                        <button type="button" class="info-btn" data-toggle="popover" data-html="true" title="Gehaltserhöhung - Kriterien" data-content="
            <strong><u>Umstufung in Grundlohn:</u></strong><br>
            Der Mitarbeiter hat alle notwendigen Fähigkeiten erworben, um die Tätigkeiten im entsprechenden Bereich selbstständig auszuführen. Zudem hat er das <strong>Qualifikationslevel 5</strong> in seinem Fachgebiet erreicht.
            <br><br>
            <strong><u>Qualifikationsbonus:</u></strong><br>
            <strong>Es gibt zwei Möglichkeiten für die Zuteilung des Qualifikationsbonus:</strong>
            <br>
            <em><u>1. Fachlicher Spezialist in einem Bereich:</u></em><br>
            Der Mitarbeiter verfügt über tiefgreifende Kenntnisse in allen Maschinen und Prozessen des Bereichs, schult andere Mitarbeitende und leitet kleinere Projekte. Das <strong>Qualifikationslevel 7</strong> oder höher wurde in seinem Fachgebiet erreicht.
            <br><br>
            ODER
            <br><br>
            <em><u>2. Generalist in mehreren Bereichen:</u></em><br>
            Der Mitarbeiter ist in mindestens zwei Bereichen voll einsetzbar. Er hat das <strong>Qualifikationslevel 5</strong> in seinem Hauptbereich und einem zusätzlichen Bereich erreicht (z.B. vollständige Einsetzbarkeit im Frontend und Backend).
            <br><br>
            <strong><u>Expertenbonus:</u></strong><br>
            Voraussetzungen für den Expertenbonus sind:
            <ul>
                <li>Eine <em>überdurchschnittliche</em> Leistungseinschätzung im Talent Review.</li>
                <li>Besonderes Engagement, wie z.B. Trainertätigkeiten, die Leitung von Projekten, Expertentätigkeiten oder zusätzliche disziplinarische Aufgaben.</li>
            </ul>
        ">
                            <i class="fas fa-info-circle"></i>
                        </button>
                    </label>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="tr_pr_anfangslohn" name="tr_pr_anfangslohn" value="1" <?php echo $anfangslohnDisabled; ?>>
                        <label class="form-check-label" for="tr_pr_anfangslohn">Anfangslohn</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="tr_pr_grundlohn" name="tr_pr_grundlohn" value="1" <?php echo $grundlohnDisabled; ?>>
                        <label class="form-check-label" for="tr_pr_grundlohn">Grundlohn</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="tr_pr_qualifikationsbonus" name="tr_pr_qualifikationsbonus" value="1" <?php echo $qualifikationsbonusDisabled; ?>>
                        <label class="form-check-label" for="tr_pr_qualifikationsbonus">Qualifikationsbonus</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="tr_pr_expertenbonus" name="tr_pr_expertenbonus" value="1" <?php echo $expertenbonusDisabled; ?>>
                        <label class="form-check-label" for="tr_pr_expertenbonus">Expertenbonus</label>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($lohnschema === 'Technik'): ?>
                <div class="mb-3 conditional-section" id="salary_increase_technology_section">
                    <label>Gehaltserhöhung - Technik:</label>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input tk_bonus_cb" id="tr_tk_qualifikationsbonus_1" name="tr_tk_qualifikationsbonus_1" value="1" <?php echo $tk1Props; ?>>
                        <label class="form-check-label" for="tr_tk_qualifikationsbonus_1">1. Qualifikationsbonus</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input tk_bonus_cb" id="tr_tk_qualifikationsbonus_2" name="tr_tk_qualifikationsbonus_2" value="1" <?php echo $tk2Props; ?>>
                        <label class="form-check-label" for="tr_tk_qualifikationsbonus_2">2. Qualifikationsbonus</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input tk_bonus_cb" id="tr_tk_qualifikationsbonus_3" name="tr_tk_qualifikationsbonus_3" value="1" <?php echo $tk3Props; ?>>
                        <label class="form-check-label" for="tr_tk_qualifikationsbonus_3">3. Qualifikationsbonus</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input tk_bonus_cb" id="tr_tk_qualifikationsbonus_4" name="tr_tk_qualifikationsbonus_4" value="1" <?php echo $tk4Props; ?>>
                        <label class="form-check-label" for="tr_tk_qualifikationsbonus_4">4. Qualifikationsbonus</label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-3 conditional-section" id="salary_increase_argumentation_section">
                <label for="tr_salary_increase_argumentation">Argumentation für Gehaltserhöhung:</label>
                <textarea id="tr_salary_increase_argumentation" name="tr_salary_increase_argumentation" class="form-control" rows="3"><?php echo htmlspecialchars($review['tr_salary_increase_argumentation']); ?></textarea>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</div>

<!-- jQuery, Popper.js, and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
    $(document).ready(function () {
        // Popover-Init
        $('[data-toggle="popover"]').popover({
            html: true,
            trigger: 'focus',
            placement: 'right'
        });

        // Hilfsfunktionen
        function showSection(selector, condition) {
            $(selector).toggle(condition);
        }

        function isRelevantForRaise() {
            return $('#tr_relevant_for_raise').is(':checked');
        }

        function isRaiseOptionSelected() {
            // Produktion
            if ($('#tr_pr_anfangslohn').is(':checked:not(:disabled)') ||
                $('#tr_pr_grundlohn').is(':checked:not(:disabled)') ||
                $('#tr_pr_qualifikationsbonus').is(':checked:not(:disabled)') ||
                $('#tr_pr_expertenbonus').is(':checked:not(:disabled)')) {
                return true;
            }

            // Technik
            if ($('#tr_tk_qualifikationsbonus_1').is(':checked:not(:disabled)') ||
                $('#tr_tk_qualifikationsbonus_2').is(':checked:not(:disabled)') ||
                $('#tr_tk_qualifikationsbonus_3').is(':checked:not(:disabled)') ||
                $('#tr_tk_qualifikationsbonus_4').is(':checked:not(:disabled)')) {
                return true;
            }
            return false;
        }

        function toggleSalarySections() {
            if (isRelevantForRaise()) {
                if ('<?php echo $lohnschema; ?>' === 'Produktion') {
                    showSection('#salary_increase_production_section', true);
                    showSection('#salary_increase_technology_section', false);
                } else if ('<?php echo $lohnschema; ?>' === 'Technik') {
                    showSection('#salary_increase_technology_section', true);
                    showSection('#salary_increase_production_section', false);
                } else {
                    // anderes Schema
                    showSection('#salary_increase_production_section', false);
                    showSection('#salary_increase_technology_section', false);
                }
            } else {
                showSection('#salary_increase_production_section', false);
                showSection('#salary_increase_technology_section', false);
            }
        }

        function toggleConditionalSections() {
            const perf = $('#tr_performance_assessment').val();
            const talent = $('#tr_talent').val();

            // Schauen, ob wir den Entwicklungs-Block anzeigen
            const showDevelopment = (talent === 'Aufstrebendes Talent' || talent === 'Fertiges Talent' || talent === 'Braucht Entwicklung');

            // Kommentar bei Leistung nur anzeigen bei 'überdurchschnittlich' oder 'Entwicklung'
            showSection('#tr_performance_comment_section', (perf === 'überdurchschnittlich' || perf === 'Entwicklung'));

            // Externe und interne Weiterbildung nur bei showDevelopment
            showSection('#tr_development_actions_section', showDevelopment);
            showSection('#tr_external_training_section', showDevelopment);
            showSection('#tr_internal_training_section', showDevelopment);
            showSection('#external_training_alert', showDevelopment); // NEU: alert an gleiche Logik koppeln

            // Karriereplan
            const showCareerPlan = (talent === 'Aufstrebendes Talent' || talent === 'Fertiges Talent');
            showSection('#tr_career_plan_section', showCareerPlan);

            const careerPlan = $('#tr_career_plan').val();
            showSection('#tr_career_plan_other_section', (careerPlan === 'Sonstiges'));

            // Kommentare für einzelne Checkboxen:
            showSection('#tr_action_extra_tasks_comment', $('#tr_action_extra_tasks').is(':checked'));
            showSection('#tr_action_on_job_training_comment', $('#tr_action_on_job_training').is(':checked'));
            showSection('#tr_action_school_completion_comment', $('#tr_action_school_completion').is(':checked'));
            showSection('#tr_action_specialist_knowledge_comment', $('#tr_action_specialist_knowledge').is(':checked'));
            showSection('#tr_action_generalist_knowledge_comment', $('#tr_action_generalist_knowledge').is(':checked'));
            showSection('#tr_external_training_german_comment', $('#tr_external_training_german').is(':checked'));
            showSection('#tr_external_training_other_comment', $('#tr_external_training_other').is(':checked'));
            showSection('#tr_internal_training_jbs_comment', $('#tr_internal_training_jbs').is(':checked'));
            showSection('#tr_department_training_comment', $('#tr_department_training').is(':checked'));
            showSection('#department_training_alert', $('#tr_department_training').is(':checked'));

            // Argumentation für Gehaltserhöhung, wenn relevant und etwas ausgewählt
            showSection('#salary_increase_argumentation_section', isRelevantForRaise() && isRaiseOptionSelected());
        }


        // Nur eine Erhöhungsoption zulassen:
        function uncheckProductionIfTechChosen() {
            if ($('.tk_bonus_cb:checked').length > 0) {
                // Produktion abwählen
                $('#tr_pr_anfangslohn:not(:disabled), #tr_pr_grundlohn:not(:disabled), #tr_pr_qualifikationsbonus:not(:disabled), #tr_pr_expertenbonus:not(:disabled)').prop('checked', false);
            }
        }

        function uncheckTechnologyIfProductionChosen() {
            if ($('#tr_pr_anfangslohn:checked, #tr_pr_grundlohn:checked, #tr_pr_qualifikationsbonus:checked, #tr_pr_expertenbonus:checked').length > 0) {
                // Technik abwählen (wenn möglich)
                $('.tk_bonus_cb:not(:disabled)').prop('checked', false);
            }
        }

        // Initial
        toggleSalarySections();
        toggleConditionalSections();

        // Event-Listener
        $('#tr_relevant_for_raise, #tr_performance_assessment, #tr_talent, #tr_career_plan, #tr_action_extra_tasks, #tr_action_on_job_training, #tr_action_school_completion, #tr_action_specialist_knowledge, #tr_action_generalist_knowledge, #tr_external_training_german, #tr_external_training_other, #tr_internal_training_jbs, #tr_department_training, #tr_pr_anfangslohn, #tr_pr_grundlohn, #tr_pr_qualifikationsbonus, #tr_pr_expertenbonus, #tr_tk_qualifikationsbonus_1, #tr_tk_qualifikationsbonus_2, #tr_tk_qualifikationsbonus_3, #tr_tk_qualifikationsbonus_4').change(function() {
            // Falls Technik ausgewählt wird, Produktion abwählen
            if ($(this).is('.tk_bonus_cb') && $(this).is(':checked')) {
                uncheckTechnologyIfProductionChosen();
                var currentId = $(this).attr('id');
                // NUR Bonus-Checkboxen abwählen, die nicht bereits disabled (also bestehende Boni) sind.
                $('.tk_bonus_cb').not('#'+currentId).not(':disabled').prop('checked', false);
                uncheckProductionIfTechChosen();
            }

            if ($(this).is('#tr_pr_anfangslohn, #tr_pr_grundlohn, #tr_pr_qualifikationsbonus, #tr_pr_expertenbonus') && $(this).is(':checked')) {
                $('.tk_bonus_cb:not(:disabled)').prop('checked', false);
            }

            toggleSalarySections();
            toggleConditionalSections();
        });


        $('#talentReviewForm').submit(function(e) {
            // Wenn relevant für Gehaltserhöhung, prüfen ob Erhöhung gewählt ...
            if (isRelevantForRaise()) {
                if (!isRaiseOptionSelected()) {
                    alert("Bitte wählen Sie eine Option für die Gehaltserhöhung aus.");
                    e.preventDefault();
                    return;
                }
                if ($('#tr_salary_increase_argumentation').val().trim() === '') {
                    alert("Bitte geben Sie eine Argumentation für die Gehaltserhöhung ein.");
                    e.preventDefault();
                    return;
                }
            }

            // Kommentar-Leistung Prüfung:
            // Nur prüfen, wenn '#tr_performance_comment_section' sichtbar ist
            if ($('#tr_performance_comment_section').is(':visible')) {
                var comment = $('#tr_performance_comment').val().trim();
                if (comment === '') {
                    alert("Bitte geben Sie einen Kommentar zur Leistungseinschätzung ein.");
                    e.preventDefault();
                    return;
                }
            }
        });
    });
</script>
</body>
</html>