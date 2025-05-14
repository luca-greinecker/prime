<?php
/**
 * hr_dashboard_helpers.php
 *
 * Helper functions specifically for the HR dashboard
 * Handles queries and data processing for HR metrics
 */

// Common condition for active employees (non-archived)
$active_employees_condition = "WHERE status != 9999";

/**
 * Gets total employee count (excluding archived)
 *
 * @param mysqli $conn
 * @return int
 */
function getTotalEmployeesCount($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees $active_employees_condition");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total = $row['total'] ?? 0;
    $stmt->close();
    return $total;
}

/**
 * Gets gender distribution among employees
 *
 * @param mysqli $conn
 * @return array
 */
function getGenderDistribution($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT gender, COUNT(*) as count
        FROM employees
        $active_employees_condition
        GROUP BY gender
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $gender = $row['gender'] ?: 'Nicht angegeben';
        $distribution[$gender] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets distribution by employee groups
 *
 * @param mysqli $conn
 * @return array
 */
function getGroupDistribution($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT gruppe, COUNT(*) as count
        FROM employees
        $active_employees_condition
        GROUP BY gruppe
        ORDER BY count DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['gruppe']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets distribution by teams (for shift work)
 *
 * @param mysqli $conn
 * @return array
 */
function getTeamDistribution($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT crew, COUNT(*) as count
        FROM employees
        $active_employees_condition AND crew != '---' AND crew != ''
        GROUP BY crew
        ORDER BY crew ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['crew']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets onboarding statistics
 *
 * @param mysqli $conn
 * @return array
 */
function getOnboardingStats($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT onboarding_status, COUNT(*) as count
        FROM employees
        $active_employees_condition AND onboarding_status < 3
        GROUP BY onboarding_status
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $stats[$row['onboarding_status']] = $row['count'];
        $total += $row['count'];
    }
    $stmt->close();
    return ['stats' => $stats, 'total' => $total];
}

/**
 * Gets employees in onboarding process
 *
 * @param mysqli $conn
 * @return array
 */
function getOnboardingEmployees($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT employee_id, name, badge_id, gender, birthdate, entry_date, gruppe, crew, position, onboarding_status
        FROM employees
        $active_employees_condition AND onboarding_status < 3
        ORDER BY entry_date DESC, name ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $employees;
}

/**
 * Gets count of new employees (joined in last X months)
 *
 * @param mysqli $conn
 * @param int $months
 * @return int
 */
function getNewEmployeesCount($conn, $months = 3) {
    global $active_employees_condition;
    $cutoff_date = date('Y-m-d', strtotime("-$months months"));
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM employees
        $active_employees_condition AND entry_date >= ?
    ");
    $stmt->bind_param("s", $cutoff_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $count = $row['count'] ?? 0;
    $stmt->close();
    return $count;
}

/**
 * Gets details of new employees
 *
 * @param mysqli $conn
 * @param int $months
 * @return array
 */
function getNewEmployees($conn, $months = 3) {
    global $active_employees_condition;
    $cutoff_date = date('Y-m-d', strtotime("-$months months"));
    $stmt = $conn->prepare("
        SELECT employee_id, name, entry_date, gruppe, crew, position
        FROM employees
        $active_employees_condition AND entry_date >= ?
        ORDER BY entry_date DESC, name ASC
    ");
    $stmt->bind_param("s", $cutoff_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $employees;
}

/**
 * Gets monthly hires for the last X months
 *
 * @param mysqli $conn
 * @param int $months
 * @return array
 */
function getMonthlyHires($conn, $months = 12) {
    global $active_employees_condition;
    $monthly_hires = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $month_start = date('Y-m-01', strtotime("-$i months"));
        $month_end = date('Y-m-t', strtotime("-$i months"));
        $month_label = date('M Y', strtotime("-$i months"));

        $stmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM employees
            $active_employees_condition AND entry_date BETWEEN ? AND ?
        ");
        $stmt->bind_param("ss", $month_start, $month_end);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $monthly_hires[$month_label] = $row['count'];
        $stmt->close();
    }

    return $monthly_hires;
}

/**
 * Gets monthly departures for the last X months
 *
 * @param mysqli $conn
 * @param int $months
 * @return array
 */
function getMonthlyDepartures($conn, $months = 12) {
    $monthly_departures = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $month_start = date('Y-m-01', strtotime("-$i months"));
        $month_end = date('Y-m-t', strtotime("-$i months"));
        $month_label = date('M Y', strtotime("-$i months"));

        $stmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM employees
            WHERE leave_date BETWEEN ? AND ?
        ");
        $stmt->bind_param("ss", $month_start, $month_end);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $monthly_departures[$month_label] = $row['count'];
        $stmt->close();
    }

    return $monthly_departures;
}

/**
 * Gets detailed recent departure data
 *
 * @param mysqli $conn
 * @param int $months
 * @return array
 */
function getRecentDepartures($conn, $months = 3) {
    $cutoff_date = date('Y-m-d', strtotime("-$months months"));

    $stmt = $conn->prepare("
        SELECT employee_id, name, leave_date, leave_reason, gruppe, crew, position
        FROM employees
        WHERE leave_date >= ?
        ORDER BY leave_date DESC, name ASC
    ");
    $stmt->bind_param("s", $cutoff_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $departures = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $departures;
}

/**
 * Gets departure reasons statistics
 *
 * @param mysqli $conn
 * @param int $months
 * @return array
 */
function getDepartureReasons($conn, $months = 12) {
    $cutoff_date = date('Y-m-d', strtotime("-$months months"));

    $stmt = $conn->prepare("
        SELECT leave_reason, COUNT(*) as count
        FROM employees
        WHERE leave_date >= ?
        GROUP BY leave_reason
        ORDER BY count DESC
    ");
    $stmt->bind_param("s", $cutoff_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $reasons = [];
    while ($row = $result->fetch_assoc()) {
        $reason = $row['leave_reason'] ?: 'Nicht angegeben';
        $reasons[$reason] = $row['count'];
    }
    $stmt->close();

    return $reasons;
}

/**
 * Gets age groups distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getAgeGroups($conn) {
    global $active_employees_condition;
    $age_groups = [
        '< 20' => 0,
        '20-29' => 0,
        '30-39' => 0,
        '40-49' => 0,
        '50-59' => 0,
        '60+' => 0,
        'Keine Angabe' => 0
    ];

    $stmt = $conn->prepare("SELECT birthdate FROM employees $active_employees_condition AND birthdate IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $birthdate = new DateTime($row['birthdate']);
        $today = new DateTime();
        $age = $birthdate->diff($today)->y;

        if ($age < 20) {
            $age_groups['< 20']++;
        } elseif ($age < 30) {
            $age_groups['20-29']++;
        } elseif ($age < 40) {
            $age_groups['30-39']++;
        } elseif ($age < 50) {
            $age_groups['40-49']++;
        } elseif ($age < 60) {
            $age_groups['50-59']++;
        } else {
            $age_groups['60+']++;
        }
    }
    $stmt->close();

    // Get employees without birthdate
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees $active_employees_condition AND birthdate IS NULL");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $age_groups['Keine Angabe'] = $row['count'];
    $stmt->close();

    return $age_groups;
}

/**
 * Gets company tenure distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getTenureGroups($conn) {
    global $active_employees_condition;
    $tenure_groups = [
        '< 1 Jahr' => 0,
        '1-2 Jahre' => 0,
        '3-5 Jahre' => 0,
        '6-10 Jahre' => 0,
        '11-15 Jahre' => 0,
        '16+ Jahre' => 0,
        'Keine Angabe' => 0
    ];

    $stmt = $conn->prepare("SELECT entry_date FROM employees $active_employees_condition AND entry_date IS NOT NULL");
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $entry_date = new DateTime($row['entry_date']);
        $today = new DateTime();
        $years = $entry_date->diff($today)->y;

        if ($years < 1) {
            $tenure_groups['< 1 Jahr']++;
        } elseif ($years < 3) {
            $tenure_groups['1-2 Jahre']++;
        } elseif ($years < 6) {
            $tenure_groups['3-5 Jahre']++;
        } elseif ($years < 11) {
            $tenure_groups['6-10 Jahre']++;
        } elseif ($years < 16) {
            $tenure_groups['11-15 Jahre']++;
        } else {
            $tenure_groups['16+ Jahre']++;
        }
    }
    $stmt->close();

    // Get employees without entry date
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM employees $active_employees_condition AND entry_date IS NULL");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $tenure_groups['Keine Angabe'] = $row['count'];
    $stmt->close();

    return $tenure_groups;
}

/**
 * Gets education qualifications distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getEducationDistribution($conn) {
    $stmt = $conn->prepare("
        SELECT ee.education_type, COUNT(*) as count
        FROM employee_education ee
        JOIN employees e ON ee.employee_id = e.employee_id
        WHERE e.status != 9999
        GROUP BY ee.education_type
        ORDER BY count DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['education_type']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets count of employees with vs without education records
 *
 * @param mysqli $conn
 * @param int $total_employees
 * @return array
 */
function getEmployeesWithEducationCount($conn, $total_employees) {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT ee.employee_id) as count
        FROM employee_education ee
        JOIN employees e ON ee.employee_id = e.employee_id
        WHERE e.status != 9999
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $with_education = $row['count'];
    $stmt->close();

    return [
        'with_education' => $with_education,
        'without_education' => $total_employees - $with_education
    ];
}

/**
 * Gets safety roles statistics
 *
 * @param mysqli $conn
 * @return array
 */
function getSafetyRoles($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN ersthelfer = 1 THEN 1 ELSE 0 END) as ersthelfer_count,
            SUM(CASE WHEN svp = 1 THEN 1 ELSE 0 END) as svp_count,
            SUM(CASE WHEN brandschutzwart = 1 THEN 1 ELSE 0 END) as brandschutzwart_count,
            SUM(CASE WHEN sprinklerwart = 1 THEN 1 ELSE 0 END) as sprinklerwart_count
        FROM employees
        $active_employees_condition
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $roles = $result->fetch_assoc();
    $stmt->close();
    return $roles;
}

/**
 * Gets top positions by count
 *
 * @param mysqli $conn
 * @param int $limit
 * @return array
 */
function getTopPositions($conn, $limit = 5) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT position, COUNT(*) as count
        FROM employees
        $active_employees_condition
        GROUP BY position
        ORDER BY count DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $positions = [];
    while ($row = $result->fetch_assoc()) {
        $positions[$row['position']] = $row['count'];
    }
    $stmt->close();
    return $positions;
}

/**
 * Gets talent distribution from employee reviews
 *
 * @param mysqli $conn
 * @param string $start_date
 * @param string $end_date
 * @return array
 */
function getTalentDistribution($conn, $start_date, $end_date) {
    $result = holeTalentsHR($conn, $start_date, $end_date);
    $distribution = [];

    while ($row = $result->fetch_assoc()) {
        if (!isset($distribution[$row['tr_talent']])) {
            $distribution[$row['tr_talent']] = 0;
        }
        $distribution[$row['tr_talent']]++;
    }

    return $distribution;
}

/**
 * Gets performance assessment distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getPerformanceDistribution($conn) {
    $stmt = $conn->prepare("
        SELECT er.tr_performance_assessment, COUNT(*) as count
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id
        WHERE er.tr_performance_assessment IS NOT NULL
        AND e.status != 9999
        GROUP BY er.tr_performance_assessment
        ORDER BY count DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['tr_performance_assessment']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets career planning distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getCareerDistribution($conn) {
    $stmt = $conn->prepare("
        SELECT er.tr_career_plan, COUNT(*) as count
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id
        WHERE er.tr_career_plan IS NOT NULL
        AND e.status != 9999
        GROUP BY er.tr_career_plan
        ORDER BY count DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['tr_career_plan']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets employee satisfaction distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getSatisfactionDistribution($conn) {
    $stmt = $conn->prepare("
        SELECT er.zufriedenheit, COUNT(*) as count
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id
        WHERE er.zufriedenheit IS NOT NULL
        AND e.status != 9999
        GROUP BY er.zufriedenheit
        ORDER BY 
            CASE 
                WHEN er.zufriedenheit = 'Zufrieden' THEN 1
                WHEN er.zufriedenheit = 'Grundsätzlich zufrieden' THEN 2
                WHEN er.zufriedenheit = 'Unzufrieden' THEN 3
            END
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['zufriedenheit']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets top employees by training participation
 *
 * @param mysqli $conn
 * @param int $limit
 * @return array
 */
function getTopTrainingParticipation($conn, $limit = 10) {
    $stmt = $conn->prepare("
        SELECT 
            e.employee_id, 
            e.name, 
            COUNT(et.training_id) as training_count
        FROM 
            employees e
        LEFT JOIN 
            employee_training et ON e.employee_id = et.employee_id
        WHERE
            e.status != 9999
        GROUP BY 
            e.employee_id
        ORDER BY 
            training_count DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $participation = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $participation;
}

/**
 * Gets average training count per employee
 *
 * @param mysqli $conn
 * @return float
 */
function getAvgTrainingsPerEmployee($conn) {
    $stmt = $conn->prepare("
        SELECT 
            AVG(training_count) as avg_trainings
        FROM (
            SELECT 
                e.employee_id, 
                COUNT(et.training_id) as training_count
            FROM 
                employees e
            LEFT JOIN 
                employee_training et ON e.employee_id = et.employee_id
            WHERE
                e.status != 9999
            GROUP BY 
                e.employee_id
        ) as training_counts
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $avg = round($row['avg_trainings'], 1);
    $stmt->close();
    return $avg;
}

/**
 * Gets wage schema distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getLohnschemaDist($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT lohnschema, COUNT(*) as count
        FROM employees
        $active_employees_condition
        GROUP BY lohnschema
        ORDER BY count DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['lohnschema']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets qualification bonus distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getBoniDistribution($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN pr_lehrabschluss = 1 THEN 1 ELSE 0 END) as lehrabschluss,
            SUM(CASE WHEN pr_anfangslohn = 1 THEN 1 ELSE 0 END) as anfangslohn,
            SUM(CASE WHEN pr_grundlohn = 1 THEN 1 ELSE 0 END) as grundlohn,
            SUM(CASE WHEN pr_qualifikationsbonus = 1 THEN 1 ELSE 0 END) as qualifikationsbonus,
            SUM(CASE WHEN pr_expertenbonus = 1 THEN 1 ELSE 0 END) as expertenbonus,
            SUM(CASE WHEN tk_qualifikationsbonus_1 = 1 THEN 1 ELSE 0 END) as tk_qual_1,
            SUM(CASE WHEN tk_qualifikationsbonus_2 = 1 THEN 1 ELSE 0 END) as tk_qual_2,
            SUM(CASE WHEN tk_qualifikationsbonus_3 = 1 THEN 1 ELSE 0 END) as tk_qual_3,
            SUM(CASE WHEN tk_qualifikationsbonus_4 = 1 THEN 1 ELSE 0 END) as tk_qual_4
        FROM employees
        $active_employees_condition
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = $result->fetch_assoc();
    $stmt->close();
    return $distribution;
}

/**
 * Gets area allowance distribution
 *
 * @param mysqli $conn
 * @return array
 */
function getZulageDistribution($conn) {
    global $active_employees_condition;
    $stmt = $conn->prepare("
        SELECT ln_zulage, COUNT(*) as count
        FROM employees
        $active_employees_condition AND ln_zulage IS NOT NULL
        GROUP BY ln_zulage
        ORDER BY count DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $distribution = [];
    while ($row = $result->fetch_assoc()) {
        $distribution[$row['ln_zulage']] = $row['count'];
    }
    $stmt->close();
    return $distribution;
}

/**
 * Gets training needs
 *
 * @param mysqli $conn
 * @return array
 */
function getTrainingNeeds($conn) {
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN er.tr_action_extra_tasks = 1 THEN 1 ELSE 0 END) as extra_tasks,
            SUM(CASE WHEN er.tr_action_on_job_training = 1 THEN 1 ELSE 0 END) as on_job_training,
            SUM(CASE WHEN er.tr_action_school_completion = 1 THEN 1 ELSE 0 END) as school_completion,
            SUM(CASE WHEN er.tr_action_specialist_knowledge = 1 THEN 1 ELSE 0 END) as specialist_knowledge,
            SUM(CASE WHEN er.tr_action_generalist_knowledge = 1 THEN 1 ELSE 0 END) as generalist_knowledge,
            SUM(CASE WHEN er.tr_external_training_industry_foreman = 1 THEN 1 ELSE 0 END) as industry_foreman,
            SUM(CASE WHEN er.tr_external_training_industry_master = 1 THEN 1 ELSE 0 END) as industry_master,
            SUM(CASE WHEN er.tr_external_training_german = 1 THEN 1 ELSE 0 END) as german_training,
            SUM(CASE WHEN er.tr_external_training_qs_basics = 1 THEN 1 ELSE 0 END) as qs_basics,
            SUM(CASE WHEN er.tr_external_training_qs_assistant = 1 THEN 1 ELSE 0 END) as qs_assistant,
            SUM(CASE WHEN er.tr_external_training_qs_technician = 1 THEN 1 ELSE 0 END) as qs_technician,
            SUM(CASE WHEN er.tr_external_training_sps_basics = 1 THEN 1 ELSE 0 END) as sps_basics,
            SUM(CASE WHEN er.tr_external_training_sps_advanced = 1 THEN 1 ELSE 0 END) as sps_advanced,
            SUM(CASE WHEN er.tr_external_training_forklift = 1 THEN 1 ELSE 0 END) as forklift,
            SUM(CASE WHEN er.tr_external_training_other = 1 THEN 1 ELSE 0 END) as other_training,
            SUM(CASE WHEN er.tr_internal_training_best_leadership = 1 THEN 1 ELSE 0 END) as leadership_training,
            SUM(CASE WHEN er.tr_internal_training_jbs = 1 THEN 1 ELSE 0 END) as jbs_training,
            SUM(CASE WHEN er.tr_department_training = 1 THEN 1 ELSE 0 END) as department_training
        FROM employee_reviews er
        JOIN employees e ON er.employee_id = e.employee_id
        WHERE e.status != 9999
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $needs = $result->fetch_assoc();
    $stmt->close();
    return $needs;
}