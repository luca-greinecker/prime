<?php
/**
 * dashboard_helpers.php
 *
 * Enthält zentrale Logik/Funktionen für Dashboards:
 *  - Ermittlung des aktuellen Gesprächsjahres (mit Nachlauf bis 01.08. des Folgejahres)
 *  - Prüfung, ob Dashboard noch aktiv ist
 *  - Abfragen (UNION) für Weiterbildungen
 *  - Abfragen für Lohnerhöhungen, Zufriedenheit, etc.
 *
 * Archivierte Mitarbeiter (status = 9999) werden in allen Abfragen ausgeblendet.
 */

/**
 * Ermittelt das aktuell anzuzeigende Gesprächsjahr aus review_periods.
 * Berücksichtigt die Regel, dass bis 01.08. des Folgejahres
 * das "alte" Gesprächsjahr noch aktiv bleibt.
 *
 * WICHTIG: Wir sortieren die Jahre aufsteigend, durchlaufen sie von klein nach groß
 * und nehmen das ERSTE Jahr, das noch aktiv ist (heute <= Umschaltpunkt).
 * So wird im Februar 2025 noch 2024 ausgewählt, falls 2024 bis 01.08.2025 aktiv bleiben soll.
 */
function ermittleAktuellesGespraechsjahr(mysqli $conn): ?int
{
    // review_periods enthält mehrere Jahre (2023, 2024, 2025 ...)
    // Wir laden sie und entscheiden, welches Jahr derzeit angezeigt werden soll.

    $sql = "SELECT year FROM review_periods ORDER BY year ASC";
    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        // Keine Einträge
        return null;
    }

    $heute = new DateTime();
    $alleJahre = [];

    while ($row = $result->fetch_assoc()) {
        $year = (int)$row['year'];
        // Umschaltpunkt = 1.8.(year+1)
        // Bis zu diesem Datum bleibt das Jahr noch "aktiv".
        $umschaltDatum = new DateTime(($year + 1) . "-08-01");

        $alleJahre[] = [
            'year' => $year,
            'umschaltDatum' => $umschaltDatum
        ];
    }

    // Sortieren aufsteigend (kleinstes Jahr zuerst)
    usort($alleJahre, function ($a, $b) {
        return $a['year'] - $b['year'];
    });

    // Durchlaufen von unten nach oben:
    // Nehmen das ERSTE Jahr, das noch aktiv ist (heute <= UmschaltDatum).
    foreach ($alleJahre as $item) {
        if ($heute <= $item['umschaltDatum']) {
            return $item['year'];
        }
    }

    // Falls wir hier landen, ist alles "verpasst": nimm das höchste Jahr
    // (oder return null, je nach gewünschter Logik)
    return $alleJahre[count($alleJahre) - 1]['year'] ?? null;
}

/**
 * Prüft, ob das Dashboard für ein gegebenes Jahr noch aktiv ist
 * (bis zum 01.08. des Folgejahres).
 */
function dashboardIstAktiv(int $conversation_year): bool
{
    $heute = new DateTime();
    $umschalt = new DateTime(($conversation_year + 1) . "-08-01");
    return $heute <= $umschalt;
}

/**
 * Ermittelt Anzahl durchgeführter Mitarbeitergespräche und Talent Reviews.
 */
function holeGespraecheTalentReviews(mysqli $conn, array $unterstellte_mitarbeiter, string $cutoff_date): array
{
    if (empty($unterstellte_mitarbeiter)) {
        return ['gespraeche' => 0, 'talent_reviews' => 0];
    }

    $platzhalter = implode(',', array_fill(0, count($unterstellte_mitarbeiter), '?'));

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999) in der Subquery
    $sql = "
        SELECT 
            COUNT(*) AS total_gespraeche,
            SUM(CASE WHEN tr_date IS NOT NULL THEN 1 ELSE 0 END) AS total_talent_reviews
        FROM employee_reviews
        WHERE employee_id IN ($platzhalter)
          AND employee_id IN (
              SELECT employee_id 
              FROM employees 
              WHERE entry_date <= ?
              AND status != 9999
          )
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Fehler bei holeGespraecheTalentReviews: ' . $conn->error);
    }

    // Param: IDs + cutoff_date
    $types = str_repeat('i', count($unterstellte_mitarbeiter)) . 's';
    $params = array_merge($unterstellte_mitarbeiter, [$cutoff_date]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'gespraeche' => (int)($row['total_gespraeche'] ?? 0),
        'talent_reviews' => (int)($row['total_talent_reviews'] ?? 0)
    ];
}

/**
 * Abfrage für Lohnerhöhungen (inkl. LEFT JOIN auf Führungskraft).
 */
function holeLohnerhoehungen(mysqli $conn, array $unterstellte_mitarbeiter, string $cutoff_date)
{
    if (empty($unterstellte_mitarbeiter)) {
        return false;
    }

    $platzhalter = implode(',', array_fill(0, count($unterstellte_mitarbeiter), '?'));

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999) für alle JOINs
    $sql = "
        SELECT er.employee_id, e.name AS mitarbeiter_name,
               CASE
                   WHEN er.tr_pr_anfangslohn = 1 THEN 'Anfangslohn'
                   WHEN er.tr_pr_grundlohn = 1 THEN 'Grundlohn'
                   WHEN er.tr_pr_qualifikationsbonus = 1 THEN 'Qualifikationsbonus'
                   WHEN er.tr_pr_expertenbonus = 1 THEN 'Expertenbonus'
                   WHEN er.tr_tk_qualifikationsbonus_1 = 1 THEN '1. Qualifikationsbonus'
                   WHEN er.tr_tk_qualifikationsbonus_2 = 1 THEN '2. Qualifikationsbonus'
                   WHEN er.tr_tk_qualifikationsbonus_3 = 1 THEN '3. Qualifikationsbonus'
                   WHEN er.tr_tk_qualifikationsbonus_4 = 1 THEN '4. Qualifikationsbonus'
                   ELSE NULL
               END AS lohnart,
               er.tr_salary_increase_argumentation,
               r.name AS fuehrungskraft_name
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_relevant_for_raise = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
        ORDER BY e.name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Fehler bei holeLohnerhoehungen: ' . $conn->error);
    }

    $types = str_repeat('i', count($unterstellte_mitarbeiter)) . 's';
    $params = array_merge($unterstellte_mitarbeiter, [$cutoff_date]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * UNION-Abfrage für Weiterbildungen (13 Blöcke).
 */
function holeWeiterbildungen(mysqli $conn, array $unterstellte_mitarbeiter, string $cutoff_date)
{
    if (empty($unterstellte_mitarbeiter)) {
        return false;
    }

    $platzhalter = implode(',', array_fill(0, count($unterstellte_mitarbeiter), '?'));
    $num_unions = 13; // Anzahl der UNION-Blöcke

    // Alle 13 Blöcke - MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999) in jedem Block
    $sql = "
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'Industrievorarbeiter' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_industry_foreman = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'Industriemeister' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_industry_master = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'Deutsch' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_german = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'QS Grundlagen' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_qs_basics = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'QS Assistent' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_qs_assistant = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'QS Techniker' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_qs_technician = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'SPS Steuerung Grundlagen' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_sps_basics = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'SPS Steuerung Fortgeschrittene' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_sps_advanced = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 'Stapler' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_forklift = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, CONCAT('Sonstiges: ', er.tr_external_training_other_comment) AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_external_training_other = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Interne Weiterbildung' AS typ, 'BEST - Führung' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_internal_training_best_leadership = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Interne Weiterbildung' AS typ, 'JBS Training' AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_internal_training_jbs = 1
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    UNION ALL
    (
        SELECT e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, 'Abteilungsorganisierte Weiterbildung' AS typ, er.tr_department_training_comment AS weiterbildung
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE er.tr_department_training = 1
          AND er.tr_department_training_comment IS NOT NULL
          AND er.tr_department_training_comment <> ''
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    )
    ORDER BY mitarbeiter_name ASC
    ";

    // Param-Typen:
    // Pro UNION-Block:
    //   - (count($unterstellte_mitarbeiter) * 'i') + 's'
    // => In Summe $num_unions mal.
    $singleBlockTypes = str_repeat('i', count($unterstellte_mitarbeiter)) . 's';
    $param_types = str_repeat($singleBlockTypes, $num_unions);

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Fehler bei holeWeiterbildungen: ' . $conn->error);
    }

    // Params aufbauen
    $params = [];
    for ($i = 0; $i < $num_unions; $i++) {
        // Erst alle unterstellten IDs, dann cutoff_date
        $params = array_merge($params, $unterstellte_mitarbeiter, [$cutoff_date]);
    }

    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Aggregierte Zufriedenheit.
 */
function holeZufriedenheit(mysqli $conn, array $unterstellte_mitarbeiter, string $cutoff_date): array
{
    if (empty($unterstellte_mitarbeiter)) {
        return ['zufrieden' => 0, 'grundsaetzlich_zufrieden' => 0, 'unzufrieden' => 0];
    }

    $platzhalter = implode(',', array_fill(0, count($unterstellte_mitarbeiter), '?'));

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999) in der Subquery
    $sql = "
        SELECT 
            SUM(CASE WHEN zufriedenheit = 'Zufrieden' THEN 1 ELSE 0 END) AS zufrieden,
            SUM(CASE WHEN zufriedenheit = 'Grundsätzlich zufrieden' THEN 1 ELSE 0 END) AS grundsaetzlich_zufrieden,
            SUM(CASE WHEN zufriedenheit = 'Unzufrieden' THEN 1 ELSE 0 END) AS unzufrieden
        FROM employee_reviews
        WHERE employee_id IN ($platzhalter)
          AND employee_id IN (
              SELECT employee_id FROM employees WHERE entry_date <= ? AND status != 9999
          )
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Fehler bei holeZufriedenheit: ' . $conn->error);
    }

    $types = str_repeat('i', count($unterstellte_mitarbeiter)) . 's';
    $params = array_merge($unterstellte_mitarbeiter, [$cutoff_date]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'zufrieden' => (int)($row['zufrieden'] ?? 0),
        'grundsaetzlich_zufrieden' => (int)($row['grundsaetzlich_zufrieden'] ?? 0),
        'unzufrieden' => (int)($row['unzufrieden'] ?? 0)
    ];
}

/**
 * Liste unzufriedener Mitarbeiter (Name + Begründung).
 */
function holeUnzufriedene(mysqli $conn, array $unterstellte_mitarbeiter, string $cutoff_date): array
{
    if (empty($unterstellte_mitarbeiter)) {
        return [];
    }

    $platzhalter = implode(',', array_fill(0, count($unterstellte_mitarbeiter), '?'));

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $sql = "
        SELECT e.name, er.unzufriedenheit_grund
        FROM employee_reviews er
        LEFT JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        WHERE er.zufriedenheit = 'Unzufrieden'
          AND er.employee_id IN ($platzhalter)
          AND e.entry_date <= ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Fehler bei holeUnzufriedene: ' . $conn->error);
    }

    $types = str_repeat('i', count($unterstellte_mitarbeiter)) . 's';
    $params = array_merge($unterstellte_mitarbeiter, [$cutoff_date]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $unzufriedene = [];
    while ($row = $result->fetch_assoc()) {
        $unzufriedene[] = $row;
    }
    $stmt->close();

    return $unzufriedene;
}

/**
 * Prüft, ob der Mitarbeiter bereits lange genug im Unternehmen ist,
 * um ein Mitarbeitergespräch durchzuführen.
 * Mitarbeiter, die nach dem 1. Oktober des Gesprächsjahres eingetreten sind,
 * gelten als zu neu.
 *
 * @param string $entry_date Im Format 'YYYY-MM-DD'
 * @param string $cutoff_date Im Format 'YYYY-MM-DD'
 * @return bool True, wenn der Mitarbeiter alt genug ist, sonst false.
 */
function istMitarbeiterGueltigFuerGespraech(string $entry_date, string $cutoff_date): bool
{
    return strtotime($entry_date) <= strtotime($cutoff_date);
}

/**
 * Filtert eine Liste von Mitarbeiter-IDs und gibt nur jene IDs zurück,
 * deren entry_date (in der Tabelle employees) kleiner oder gleich dem Cutoff-Datum ist.
 * Außerdem werden archivierte Mitarbeiter (status = 9999) ausgeblendet.
 *
 * @param mysqli $conn
 * @param array $employee_ids Array von Mitarbeiter-IDs (int)
 * @param string $cutoff_date Im Format 'YYYY-MM-DD'
 * @return array Gefilterte Mitarbeiter-IDs
 */
function filterGueltigeMitarbeiterIDs(mysqli $conn, array $employee_ids, string $cutoff_date): array
{
    if (empty($employee_ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
    $types = str_repeat('i', count($employee_ids)) . 's';

    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $sql = "SELECT employee_id FROM employees WHERE employee_id IN ($placeholders) AND entry_date <= ? AND status != 9999";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler bei filterGueltigeMitarbeiterIDs: " . $conn->error);
    }
    $params = array_merge($employee_ids, [$cutoff_date]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid_ids = [];
    while ($row = $result->fetch_assoc()) {
        $valid_ids[] = $row['employee_id'];
    }
    $stmt->close();
    return $valid_ids;
}

/**
 * Ermittelt die Review-Periode für ein gegebenes Jahr.
 * Falls kein Eintrag existiert, wird der komplette Kalenderjahrzeitraum zurückgegeben.
 *
 * @param mysqli $conn
 * @param int $year
 * @return array ['start_date' => 'YYYY-MM-DD', 'end_date' => 'YYYY-MM-DD']
 */
function getReviewPeriodForYear(mysqli $conn, int $year): array
{
    $sql = "SELECT * FROM review_periods WHERE year = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler bei getReviewPeriodForYear: " . $conn->error);
    }
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $period = $result->fetch_assoc();
    $stmt->close();
    if ($period) {
        $start_year = (int)$period['start_year'];
        $start_month = (int)$period['start_month'];
        $end_year = (int)$period['end_year'];
        $end_month = (int)$period['end_month'];
        $end_day = cal_days_in_month(CAL_GREGORIAN, $end_month, $end_year);
        return [
            'start_date' => sprintf('%04d-%02d-01', $start_year, $start_month),
            'end_date' => sprintf('%04d-%02d-%02d', $end_year, $end_month, $end_day)
        ];
    }
    return [
        'start_date' => "$year-01-01",
        'end_date' => "$year-12-31"
    ];
}

/**
 * UNION-Abfrage für Weiterbildungen im HR-Dashboard.
 * Alle 13 Blöcke werden mit einem zusätzlichen Zeitfilter (er.date BETWEEN ? AND ?)
 * ausgeführt.
 *
 * @param mysqli $conn
 * @param string $start_date
 * @param string $end_date
 * @return mysqli_result
 */
function holeWeiterbildungenHR(mysqli $conn, string $start_date, string $end_date)
{
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999) in allen Blöcken
    $unionBlocks = [
        // 1) Industrievorarbeiter
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'Industrievorarbeiter' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_industry_foreman = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 2) Industriemeister
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'Industriemeister' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_industry_master = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 3) Deutsch
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'Deutsch' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_german = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 4) QS Grundlagen
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'QS Grundlagen' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_qs_basics = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 5) QS Assistent
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'QS Assistent' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_qs_assistant = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 6) QS Techniker
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'QS Techniker' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_qs_technician = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 7) SPS Steuerung Grundlagen
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'SPS Steuerung Grundlagen' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_sps_basics = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 8) SPS Steuerung Fortgeschrittene
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'SPS Steuerung Fortgeschrittene' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_sps_advanced = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 9) Stapler
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   'Stapler' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_forklift = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 10) Sonstiges
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Externe Weiterbildung' AS typ, 
                   CONCAT('Sonstiges: ', er.tr_external_training_other_comment) AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_external_training_other = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 11) Interne Weiterbildung: BEST - Führung
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Interne Weiterbildung' AS typ, 
                   'BEST - Führung' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_internal_training_best_leadership = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 12) Interne Weiterbildung: JBS Training
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Interne Weiterbildung' AS typ, 
                   'JBS Training' AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_internal_training_jbs = 1
              AND er.date BETWEEN ? AND ?
        )",
        // 13) Abteilungsorganisierte Weiterbildung
        "(
            SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, 
                   r.name AS fuehrungskraft_name, 'Abteilungsorganisierte Weiterbildung' AS typ, 
                   er.tr_department_training_comment AS weiterbildung, er.tr_talent
            FROM employee_reviews er
            JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
            LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
            WHERE er.tr_department_training = 1
              AND er.tr_department_training_comment IS NOT NULL
              AND er.tr_department_training_comment <> ''
              AND er.date BETWEEN ? AND ?
        )"
    ];
    $sql = implode(" UNION ALL ", $unionBlocks) . " ORDER BY FIELD(lohnschema, 'Produktion','Technik','Unbekannt'), crew ASC, mitarbeiter_name ASC";
    $param_types = str_repeat('s', count($unionBlocks) * 2);
    $params = [];
    foreach ($unionBlocks as $i => $block) {
        $params[] = $start_date;
        $params[] = $end_date;
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler bei holeWeiterbildungenHR: " . $conn->error);
    }
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

/**
 * Lohnerhöhungen für HR (mit Zeitfilter) – gibt ein mysqli_result zurück.
 *
 * @param mysqli $conn
 * @param string $start_date
 * @param string $end_date
 * @return mysqli_result
 */
function holeLohnerhoehungenHR(mysqli $conn, string $start_date, string $end_date)
{
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $sql = "
        SELECT 
            e.lohnschema,
            e.position,
            e.crew,
            e.name AS mitarbeiter_name,
            r.name AS fuehrungskraft_name,
            CASE
                WHEN er.tr_pr_anfangslohn = 1 THEN 'Anfangslohn'
                WHEN er.tr_pr_grundlohn = 1 THEN 'Grundlohn'
                WHEN er.tr_pr_qualifikationsbonus = 1 THEN 'Qualifikationsbonus'
                WHEN er.tr_pr_expertenbonus = 1 THEN 'Expertenbonus'
                WHEN er.tr_tk_qualifikationsbonus_1 = 1 THEN '1. Qualifikationsbonus'
                WHEN er.tr_tk_qualifikationsbonus_2 = 1 THEN '2. Qualifikationsbonus'
                WHEN er.tr_tk_qualifikationsbonus_3 = 1 THEN '3. Qualifikationsbonus'
                WHEN er.tr_tk_qualifikationsbonus_4 = 1 THEN '4. Qualifikationsbonus'
                ELSE NULL
            END AS lohnart,
            er.tr_salary_increase_argumentation,
            er.tr_performance_assessment
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE e.lohnschema IN ('Produktion','Technik')
          AND er.tr_relevant_for_raise = 1
          AND er.date BETWEEN ? AND ?
        ORDER BY FIELD(e.lohnschema, 'Produktion','Technik'), e.crew ASC, e.name ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler bei holeLohnerhoehungenHR: " . $conn->error);
    }
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

/**
 * Besondere Leistungen für HR – mit Zeitfilter und Anzeige der Führungskraft.
 *
 * @param mysqli $conn
 * @param string $start_date
 * @param string $end_date
 * @return mysqli_result
 */
function holeBesondereLeistungenHR(mysqli $conn, string $start_date, string $end_date)
{
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $sql = "
        SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, er.tr_performance_assessment
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE e.lohnschema IN ('Produktion','Technik')
          AND er.tr_performance_assessment IN ('überdurchschnittlich','Entwicklung')
          AND er.date BETWEEN ? AND ?
        ORDER BY FIELD(e.lohnschema, 'Produktion','Technik','Unbekannt'), e.crew ASC, e.name ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler bei holeBesondereLeistungenHR: " . $conn->error);
    }
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

/**
 * Talents für HR – mit Zeitfilter und Anzeige der Führungskraft.
 *
 * @param mysqli $conn
 * @param string $start_date
 * @param string $end_date
 * @return mysqli_result
 */
function holeTalentsHR(mysqli $conn, string $start_date, string $end_date)
{
    // MODIFIZIERT: Archivierte Mitarbeiter ausfiltern (status != 9999)
    $sql = "
        SELECT e.lohnschema, e.position, e.crew, e.name AS mitarbeiter_name, r.name AS fuehrungskraft_name, er.tr_talent
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id AND e.status != 9999
        LEFT JOIN employees r ON er.tr_reviewer_id = r.employee_id AND r.status != 9999
        WHERE e.lohnschema IN ('Produktion','Technik')
          AND er.tr_talent IN ('Aufstrebendes Talent','Fertiges Talent')
          AND er.date BETWEEN ? AND ?
        ORDER BY FIELD(e.lohnschema, 'Produktion','Technik','Unbekannt'), e.crew ASC, e.name ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Fehler bei holeTalentsHR: " . $conn->error);
    }
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

/**
 * Bereinigt die Positionsbezeichnung für die Technik.
 * Wird verwendet, um zwischen "Elektrik" und "Mechanik" zu unterscheiden.
 *
 * @param string $position
 * @return string
 */
function bereinigePosition($position)
{
    $pos_lower = mb_strtolower($position);
    if (strpos($pos_lower, 'mechanik') !== false) {
        return 'Mechanik';
    } elseif (strpos($pos_lower, 'elektrik') !== false) {
        return 'Elektrik';
    }
    return $position ?: 'Keine Angabe';
}

/**
 * Bestimmt die Kategorie für einen Mitarbeiter.
 * Kategorien:
 *   - "Technik" wenn lohnschema == 'Technik'
 *   - "CPO/QS" wenn lohnschema == 'Produktion' und in der Position "cpo", "qualitätssicherung" oder "sortierung" vorkommt
 *   - "Produktion" sonst, falls lohnschema == 'Produktion'
 *   - "Unbekannt" wenn keine Zuordnung möglich
 *
 * @param array $employee Associatives Array mit den Keys "lohnschema" und "position"
 * @return string Kategorie
 */
function getMitarbeiterKategorie(array $employee): string
{
    if ($employee['lohnschema'] === 'Technik') {
        return 'Technik';
    } elseif ($employee['lohnschema'] === 'Produktion') {
        $position = mb_strtolower($employee['position'] ?? '');
        if (strpos($position, 'cpo') !== false || strpos($position, 'qualitätssicherung') !== false || strpos($position, 'sortierung') !== false) {
            return 'CPO/QS';
        }
        return 'Produktion';
    }
    return 'Unbekannt';
}