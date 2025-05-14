<?php
/**
 * safety_dashboard_helpers.php
 *
 * Helper functions for the safety dashboard
 * Contains database queries and other utility functions
 */

/**
 * Gets current quarter information
 *
 * @return array Current year, quarter, and quarter names
 */
function getCurrentQuarterInfo() {
    $current_year = date('Y');
    $current_quarter = ceil(date('n') / 3);
    $quarter_names = [
        1 => "Q1/$current_year",
        2 => "Q2/$current_year",
        3 => "Q3/$current_year",
        4 => "Q4/$current_year"
    ];
    $current_quarter_name = $quarter_names[$current_quarter];

    return [
        'year' => $current_year,
        'quarter' => $current_quarter,
        'quarter_names' => $quarter_names,
        'current_quarter_name' => $current_quarter_name
    ];
}

/**
 * Gets security trainings for the current quarter
 *
 * @param mysqli $conn Database connection
 * @param string $quarter_name Current quarter name (e.g. "Q1/2025")
 * @return array List of security trainings
 */
function getSecurityTrainings($conn, $quarter_name) {
    $security_trainings_query = "
        SELECT t.id, t.display_id, t.training_name, t.start_date, t.end_date, 
               COUNT(et.employee_id) AS teilnehmer_anzahl
        FROM trainings t
        JOIN training_main_categories mc ON t.main_category_id = mc.id
        JOIN training_sub_categories sc ON t.sub_category_id = sc.id
        LEFT JOIN employee_training et ON t.id = et.training_id
        WHERE mc.name = 'Sicherheit, Gesundheit, Umwelt, Hygiene'
          AND sc.name = 'Sicherheitsschulungen'
          AND t.training_name LIKE ?
        GROUP BY t.id
        ORDER BY t.start_date DESC
    ";
    $search_pattern = "%$quarter_name%";
    $stmt = $conn->prepare($security_trainings_query);
    $stmt->bind_param("s", $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $security_trainings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $security_trainings;
}

/**
 * Gets the total number of participants in security trainings for the current quarter
 *
 * @param mysqli $conn Database connection
 * @param string $quarter_name Current quarter name (e.g. "Q1/2025")
 * @return int Total participants
 */
function getTotalParticipants($conn, $quarter_name) {
    $participants_query = "
        SELECT COUNT(DISTINCT et.employee_id) as total_participants
        FROM employee_training et
        JOIN trainings t ON et.training_id = t.id
        JOIN training_main_categories mc ON t.main_category_id = mc.id
        JOIN training_sub_categories sc ON t.sub_category_id = sc.id
        JOIN employees e ON et.employee_id = e.employee_id AND e.status != 9999
        WHERE mc.name = 'Sicherheit, Gesundheit, Umwelt, Hygiene'
          AND sc.name = 'Sicherheitsschulungen'
          AND t.training_name LIKE ?
    ";
    $search_pattern = "%$quarter_name%";
    $stmt = $conn->prepare($participants_query);
    $stmt->bind_param("s", $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_participants = $row['total_participants'];
    $stmt->close();

    return $total_participants;
}

/**
 * Gets the total number of active employees
 *
 * @param mysqli $conn Database connection
 * @return int Total active employees
 */
function getTotalActiveEmployees($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees WHERE status != 9999");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_employees = $row['total'];
    $stmt->close();

    return $total_employees;
}

/**
 * Gets the total number of first aiders
 *
 * @param mysqli $conn Database connection
 * @return int Total first aiders
 */
function getTotalFirstAiders($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees WHERE ersthelfer = 1 AND status != 9999");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_first_aiders = $row['total'];
    $stmt->close();

    return $total_first_aiders;
}

/**
 * Gets the total number of SVPs (safety representatives)
 *
 * @param mysqli $conn Database connection
 * @return int Total SVPs
 */
function getTotalSVPs($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees WHERE svp = 1 AND status != 9999");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_svp = $row['total'];
    $stmt->close();

    return $total_svp;
}

/**
 * Gets all first aiders with certificate expiration dates
 *
 * @param mysqli $conn Database connection
 * @return array List of first aiders
 */
function getFirstAiders($conn) {
    $stmt = $conn->prepare("
        SELECT employee_id, name, ersthelfer_zertifikat_ablauf, crew, gruppe
        FROM employees
        WHERE ersthelfer = 1 AND status != 9999
        ORDER BY ersthelfer_zertifikat_ablauf ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $first_aiders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $first_aiders;
}

/**
 * Gets all SVPs (safety representatives)
 *
 * @param mysqli $conn Database connection
 * @return array List of SVPs
 */
function getSVPs($conn) {
    $stmt = $conn->prepare("
        SELECT employee_id, name, crew, gruppe, position
        FROM employees
        WHERE svp = 1 AND status != 9999
        ORDER BY name ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $svp_list = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $svp_list;
}

/**
 * Gets the number of present first aiders
 *
 * @param mysqli $conn Database connection
 * @return int Number of present first aiders
 */
function getPresentFirstAiders($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees WHERE ersthelfer = 1 AND anwesend = 1 AND status != 9999");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $present_first_aiders = $row['total'];
    $stmt->close();

    return $present_first_aiders;
}

/**
 * Gets the number of present employees
 *
 * @param mysqli $conn Database connection
 * @return int Number of present employees
 */
function getPresentEmployees($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM employees WHERE anwesend = 1 AND status != 9999");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $present_employees = $row['total'];
    $stmt->close();

    return $present_employees;
}

/**
 * Gets the number of first aiders whose certificates expire in the next X months
 *
 * @param mysqli $conn Database connection
 * @param int $months Number of months to look ahead
 * @return int Number of expiring certificates
 */
function getExpiringCertificates($conn, $months = 3) {
    $future_date = date('Y-m-d', strtotime("+$months months"));
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM employees 
        WHERE ersthelfer = 1 
          AND ersthelfer_zertifikat_ablauf IS NOT NULL
          AND ersthelfer_zertifikat_ablauf <= ?
          AND status != 9999
    ");
    $stmt->bind_param("s", $future_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $expiring_certificates = $row['total'];
    $stmt->close();

    return $expiring_certificates;
}

/**
 * Gets employees missing the current quarter's safety training
 *
 * @param mysqli $conn Database connection
 * @param string $quarter_name Current quarter name (e.g. "Q1/2025")
 * @return array List of missing employees
 */
function getMissingParticipants($conn, $quarter_name) {
    $missing_participants_query = "
        SELECT e.employee_id, e.name, e.crew, e.gruppe, e.position
        FROM employees e
        WHERE e.employee_id NOT IN (
            SELECT DISTINCT et.employee_id
            FROM employee_training et
            JOIN trainings t ON et.training_id = t.id
            JOIN training_main_categories mc ON t.main_category_id = mc.id
            JOIN training_sub_categories sc ON t.sub_category_id = sc.id
            WHERE mc.name = 'Sicherheit, Gesundheit, Umwelt, Hygiene'
              AND sc.name = 'Sicherheitsschulungen'
              AND t.training_name LIKE ?
        )
        AND e.status != 9999
        ORDER BY e.gruppe, e.crew, e.name
    ";
    $search_pattern = "%$quarter_name%";
    $stmt = $conn->prepare($missing_participants_query);
    $stmt->bind_param("s", $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $missing_participants = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $missing_participants;
}

/**
 * Groups missing participants by department/team
 *
 * @param array $missing_participants List of missing employees
 * @param array $tagschicht_areas Areas in day shift
 * @return array Grouped missing participants
 */
function groupMissingParticipants($missing_participants, $tagschicht_areas) {
    $missing_by_group = [];

    foreach ($missing_participants as $emp) {
        $group = $emp['gruppe'];
        if (!isset($missing_by_group[$group])) {
            $missing_by_group[$group] = [];
        }

        if ($group === 'Schichtarbeit') {
            $crew = $emp['crew'];
            if (!isset($missing_by_group[$group][$crew])) {
                $missing_by_group[$group][$crew] = [];
            }
            $missing_by_group[$group][$crew][] = $emp;
        } elseif ($group === 'Tagschicht') {
            $area = 'Sonstiges';  // Default
            foreach ($tagschicht_areas as $tagschicht_area) {
                if (strpos($emp['position'], $tagschicht_area) !== false) {
                    $area = $tagschicht_area;
                    break;
                }
            }
            if (!isset($missing_by_group[$group][$area])) {
                $missing_by_group[$group][$area] = [];
            }
            $missing_by_group[$group][$area][] = $emp;
        } else {  // Verwaltung
            if (!isset($missing_by_group[$group]['Alle'])) {
                $missing_by_group[$group]['Alle'] = [];
            }
            $missing_by_group[$group]['Alle'][] = $emp;
        }
    }

    return $missing_by_group;
}

/**
 * Gets all employees for the first aid/SVP management
 *
 * @param mysqli $conn Database connection
 * @return array List of all employees
 */
function getAllEmployees($conn) {
    $stmt = $conn->prepare("
        SELECT employee_id, name, ersthelfer, svp, ersthelfer_zertifikat_ablauf, crew, gruppe, position
        FROM employees
        WHERE status != 9999
        ORDER BY name ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $all_employees = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $all_employees;
}

/**
 * Gets safety statistics for each team/department
 *
 * @param mysqli $conn Database connection
 * @param array $teams List of teams
 * @return array Safety statistics by team
 */
function getTeamStatistics($conn, $teams) {
    $team_statistics = [];

    foreach ($teams as $team) {
        $is_crew = in_array($team, ["Team L", "Team M", "Team N", "Team O", "Team P"]);

        if ($is_crew) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as total_members,
                       SUM(CASE WHEN ersthelfer = 1 THEN 1 ELSE 0 END) as first_aiders,
                       SUM(CASE WHEN svp = 1 THEN 1 ELSE 0 END) as svp
                FROM employees
                WHERE crew = ? AND status != 9999
            ");
            $stmt->bind_param("s", $team);
        } else {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as total_members,
                       SUM(CASE WHEN ersthelfer = 1 THEN 1 ELSE 0 END) as first_aiders,
                       SUM(CASE WHEN svp = 1 THEN 1 ELSE 0 END) as svp
                FROM employees
                WHERE gruppe = ? AND status != 9999
            ");
            $stmt->bind_param("s", $team);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $team_statistics[$team] = [
            'total' => $row['total_members'],
            'first_aiders' => $row['first_aiders'],
            'svp' => $row['svp'],
            'first_aider_rate' => ($row['total_members'] > 0) ? ($row['first_aiders'] / $row['total_members'] * 100) : 0
        ];
        $stmt->close();
    }

    return $team_statistics;
}

/**
 * Gets the distribution of first aiders by department
 *
 * @param array $first_aiders List of first aiders
 * @return array Distribution by department
 */
function getFirstAiderDistribution($first_aiders) {
    $area_distribution = [
        'Schichtarbeit' => 0,
        'Tagschicht' => 0,
        'Verwaltung' => 0
    ];

    foreach ($first_aiders as $aider) {
        if (isset($area_distribution[$aider['gruppe']])) {
            $area_distribution[$aider['gruppe']]++;
        }
    }

    return $area_distribution;
}

/**
 * Updates the safety data of an employee
 *
 * @param mysqli $conn Database connection
 * @param int $employee_id Employee ID
 * @param int $ersthelfer First aider status (0 or 1)
 * @param int $svp SVP status (0 or 1)
 * @param string|null $ersthelfer_zertifikat_ablauf Certificate expiration date
 * @return bool True if successful, false otherwise
 */
function updateEmployeeSafetyData($conn, $employee_id, $ersthelfer, $svp, $ersthelfer_zertifikat_ablauf) {
    $stmt = $conn->prepare("
        UPDATE employees
        SET ersthelfer = ?,
            svp = ?,
            ersthelfer_zertifikat_ablauf = ?
        WHERE employee_id = ?
    ");
    $stmt->bind_param("iisi", $ersthelfer, $svp, $ersthelfer_zertifikat_ablauf, $employee_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}