<?php
// access_control.php

// Start output buffering (important when sending headers)
ob_start();

// Prevent multiple inclusions
if (!defined('ACCESS_CONTROL_INCLUDED')) {
    define('ACCESS_CONTROL_INCLUDED', true);

    // Session management and DB connection
    include_once 'session_manager.php';
    handle_session_timeout();
    include 'db.php';

    // Temporary delegations: manager_id => [delegate_manager_id, ...]
    $temporaryDelegations = [
        // e.g. 1157 => [1430],
    ];

    //==========================================================================
    // BASIC USER INFO HELPERS
    //==========================================================================

    /**
     * @return int|null
     */
    function current_user_id(): ?int
    {
        return $_SESSION['mitarbeiter_id'] ?? null;
    }

    /**
     * @param string $field
     * @return mixed|null
     */
    function fetch_user_field(string $field)
    {
        global $conn;
        $uid = current_user_id();
        if (!$uid) {
            return null;
        }
        $stmt = $conn->prepare("SELECT {$field} FROM employees WHERE employee_id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $res[$field] ?? null;
    }

    /**
     * @return string|null
     */
    function current_user_position(): ?string
    {
        return fetch_user_field('position');
    }

    /**
     * @return string|null
     */
    function current_user_crew(): ?string
    {
        return fetch_user_field('crew');
    }

    /**
     * @return string|null
     */
    function current_user_group(): ?string
    {
        return fetch_user_field('gruppe');
    }


    //==========================================================================
    // ROLE CHECKS (ist_…)
    //==========================================================================

    function ist_admin()
    {
        return (isset($_SESSION['ist_admin']) && $_SESSION['ist_admin'])
            || ist_position('Verwaltung - IT');
    }

    function ist_position(string $pos): bool
    {
        return current_user_position() === $pos;
    }

    function ist_position_in(array $positions): bool
    {
        return in_array(current_user_position(), $positions, true);
    }

    function ist_werksleiter(): bool
    {
        return ist_position('Verwaltung - Werksleiter');
    }

    function ist_hr(): bool
    {
        return ist_position_in([
            'Verwaltung - HR Manager',
            'Verwaltung - HR Generalist',
        ]);
    }

    function ist_it(): bool
    {
        return ist_position_in([
            'Verwaltung - IT',
            'Verwaltung - Trainee/Graduate Intern',
        ]);
    }

    function ist_ehs(): bool
    {
        return ist_position('Verwaltung - EHS Manager');
    }

    function ist_trainingsmanager(): bool
    {
        return ist_position('Verwaltung - Training Manager');
    }

    function ist_leanmanager(): bool
    {
        return ist_position('Verwaltung - Continuous Improvement/Lean Leader');
    }

    function ist_empfang(): bool
    {
        return ist_position_in([
            'Verwaltung - Empfang',
            'Empfang',
        ]);
    }

    function ist_bereichsleiter(): bool
    {
        return ist_position_in([
            'Verwaltung - Engineering Manager | BL',
            'Verwaltung - Production Manager | BL',
            'Verwaltung - Quality Manager | BL',
        ]);
    }

    function ist_sm(): bool
    {
        return ist_position('Schichtmeister');
    }

    function ist_smstv(): bool
    {
        return ist_position('Schichtmeister - Stv.');
    }

    function ist_leiter(): bool
    {
        return ist_position_in([
            'Verwaltung - Engineering Manager | BL',
            'Verwaltung - Production Manager | BL',
            'Verwaltung - Quality Manager | BL',
            'Tagschicht - Elektrik | AL',
            'Tagschicht - Mechanik | AL',
            'Tagschicht - CPO | AL',
            'Tagschicht - Qualitätssicherung | AL',
            'Schichtmeister',
            'Schichtmeister - Stv.',
        ]);
    }

    function ist_management(): bool
    {
        return ist_position_in([
            'Verwaltung - Werksleiter',
            'Verwaltung - Engineering Manager | BL',
            'Verwaltung - Production Manager | BL',
            'Verwaltung - Quality Manager | BL',
            'Verwaltung - EHS Manager',
        ]);
    }


    //==========================================================================
    // ORGANIZATIONAL HIERARCHY
    //==========================================================================

    /**
     * @param int $managerId
     * @return int[]
     */
    function hole_unterstellte_mitarbeiter(int $managerId): array
    {
        global $conn, $temporaryDelegations;

        // Fetch manager record
        $stmt = $conn->prepare("SELECT position, crew, gruppe FROM employees WHERE employee_id = ?");
        $stmt->bind_param("i", $managerId);
        $stmt->execute();
        $mgr = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mgr) {
            return [];
        }

        $position = $mgr['position'];
        $crew = $mgr['crew'];

        $conds = [];
        $params = [];
        $types = '';

        switch ($position) {
            case "Schichtmeister":
                $conds[] = "crew = ?";
                $params[] = $crew;
                $types .= 's';
                $conds[] = "(position LIKE ? OR position = 'Schichtmeister - Stv.')";
                $params[] = '%TL';
                $types .= 's';
                break;
            case "Schichtmeister - Stv.":
                $conds[] = "crew = ?";
                $params[] = $crew;
                $types .= 's';
                $conds[] = "position = 'ISA/Necker'";
                break;
            case "Frontend - TL":
                $conds[] = "crew = ?";
                $params[] = $crew;
                $types .= 's';
                $conds[] = "position = 'Frontend'";
                break;
            case "Druckmaschine - TL":
                $conds[] = "crew = ?";
                $params[] = $crew;
                $types .= 's';
                $conds[] = "position = 'Druckmaschine'";
                break;
            case "Palletierer/MGA - TL":
                $conds[] = "crew = ?";
                $params[] = $crew;
                $types .= 's';
                $conds[] = "position = 'Palletierer/MGA'";
                break;
            case "Tagschicht - Elektrik | AL":
                $list = [
                    'Tagschicht - Elektrik | AL Stv.',
                    'Tagschicht - Elektrik',
                    'Schicht - Elektrik',
                    'Tagschicht - Elektrik Lehrling'
                ];
                $ph = implode(',', array_fill(0, count($list), '?'));
                $conds[] = "position IN ($ph)";
                $params = array_merge($params, $list);
                $types .= str_repeat('s', count($list));
                break;
            case "Tagschicht - Mechanik | AL":
                $list = [
                    'Tagschicht - Mechanik | TL FE',
                    'Tagschicht - Mechanik | TL BE',
                    'Schicht - Mechanik',
                    'Tagschicht - Mechanik Lehrling',
                    'Tagschicht - Produktion Spezialist'
                ];
                $ph = implode(',', array_fill(0, count($list), '?'));
                $conds[] = "position IN ($ph)";
                $params = array_merge($params, $list);
                $types .= str_repeat('s', count($list));
                break;
            case "Tagschicht - Mechanik | TL FE":
                $list = [
                    'Tagschicht - Mechanik FE',
                    'Tagschicht - Mechanik Tool & Die'
                ];
                $ph = implode(',', array_fill(0, count($list), '?'));
                $conds[] = "position IN ($ph)";
                $params = array_merge($params, $list);
                $types .= str_repeat('s', count($list));
                break;
            case "Tagschicht - Mechanik | TL BE":
                $conds[] = "position = ?";
                $params[] = 'Tagschicht - Mechanik BE';
                $types .= 's';
                break;
            case "Tagschicht - CPO | AL":
                $list = [
                    'Tagschicht - CPO',
                    'Schicht - CPO',
                    'Tagschicht - CPO | AL Stv.',
                    'Tagschicht - CPO Lehrling'
                ];
                $ph = implode(',', array_fill(0, count($list), '?'));
                $conds[] = "position IN ($ph)";
                $params = array_merge($params, $list);
                $types .= str_repeat('s', count($list));
                break;
            case "Tagschicht - Sortierung | TL":
                $conds[] = "position = ?";
                $params[] = 'Tagschicht - Sortierung';
                $types .= 's';
                break;
            case "Tagschicht - Qualitätssicherung | AL":
                $list = [
                    'Qualitätssicherung',
                    'Tagschicht - Qualitätssicherung',
                    'Tagschicht - Sortierung | TL'
                ];
                $ph = implode(',', array_fill(0, count($list), '?'));
                $conds[] = "position IN ($ph)";
                $params = array_merge($params, $list);
                $types .= str_repeat('s', count($list));
                break;
            default:
                return [];
        }

        // exclude self
        $conds[] = "employee_id != ?";
        $params[] = $managerId;
        $types .= 'i';

        $subs = [];
        if ($conds) {
            $where = implode(' AND ', $conds);
            $sql = "SELECT DISTINCT employee_id FROM employees WHERE $where";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $subs[] = (int)$r['employee_id'];
            }
            $stmt->close();
        }

        // include delegated
        foreach ($temporaryDelegations[$managerId] ?? [] as $del) {
            $subs = array_merge($subs, hole_unterstellte_mitarbeiter($del));
        }

        return array_unique($subs);
    }

    /**
     * @param int $managerId
     * @return int[]
     */
    function hole_alle_unterstellten_mitarbeiter(int $managerId): array
    {
        global $conn;

        $stmt = $conn->prepare("SELECT position, crew, gruppe FROM employees WHERE employee_id = ?");
        $stmt->bind_param("i", $managerId);
        $stmt->execute();
        $mgr = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mgr) {
            return [];
        }

        $position = $mgr['position'];
        $crew = $mgr['crew'];
        $grp = $mgr['gruppe'];

        $conds = ["employee_id != ?"];
        $params = [$managerId];
        $types = 'i';

        if ($position === 'Verwaltung - Engineering Manager | BL') {
            $conds[] = "(position LIKE '%Elektrik%' OR position LIKE '%Mechanik%')";
        } elseif ($position === 'Verwaltung - Production Manager | BL') {
            $conds[] = "gruppe = 'Schichtarbeit'";
            $conds[] = "position NOT LIKE '%Elektrik%'";
            $conds[] = "position NOT LIKE '%Mechanik%'";
            $conds[] = "position NOT LIKE '%CPO%'";
            $conds[] = "position NOT LIKE '%Qualitätssicherung%'";
        } elseif ($position === 'Verwaltung - Quality Manager | BL') {
            $conds[] = "(position LIKE '%CPO%' OR position LIKE '%Qualitätssicherung%' OR position LIKE '%Sortierung%')";
        } elseif ($position === 'Tagschicht - Mechanik | AL') {
            $conds[] = "position LIKE '%Mechanik%'";
        } elseif ($position === 'Tagschicht - Qualitätssicherung | AL') {
            $conds[] = "(position LIKE '%Qualitätssicherung%' OR position LIKE '%Sortierung%')";
        } elseif (in_array($position, ['Schichtmeister', 'Schichtmeister - Stv.'], true)) {
            $conds[] = "crew = ?";
            $params[] = $crew;
            $types .= 's';
            $conds[] = "position NOT LIKE '%Elektrik%'";
            $conds[] = "position NOT LIKE '%Mechanik%'";
            $conds[] = "position NOT LIKE '%CPO%'";
            $conds[] = "position NOT LIKE '%Qualitätssicherung%'";
        } else {
            return hole_unterstellte_mitarbeiter($managerId);
        }

        $all = [];
        $where = implode(' AND ', $conds);
        $sql = "SELECT DISTINCT employee_id FROM employees WHERE $where";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $all[] = (int)$r['employee_id'];
        }
        $stmt->close();
        return $all;
    }

    /**
     * @param int $employeeId
     * @return string|false
     */
    function hole_vorgesetzten_email(int $employeeId)
    {
        global $conn;
        $res = $conn->query("SELECT employee_id, email_business FROM employees");
        while ($row = $res->fetch_assoc()) {
            $mgrId = (int)$row['employee_id'];
            if (in_array($employeeId, hole_unterstellte_mitarbeiter($mgrId), true)) {
                return $row['email_business'] ?: false;
            }
        }
        return false;
    }


    //==========================================================================
    // ACCESS CHECKS (hat_zugriff_auf_…)
    //==========================================================================

    function hat_zugriff_auf_hr(): bool
    {
        return ist_admin() || ist_hr() || ist_werksleiter();
    }

    function hat_zugriff_auf_safety(): bool
    {
        return ist_admin() || ist_ehs() || ist_werksleiter() || ist_hr();
    }

    function hat_zugriff_auf_trainings(): bool
    {
        return ist_admin()
            || ist_hr()
            || ist_ehs()
            || ist_trainingsmanager()
            || ist_werksleiter()
            || ist_leanmanager();
    }

    function hat_zugriff_auf_onboarding(): bool
    {
        return ist_admin()
            || ist_hr()
            || ist_empfang()
            || ist_trainingsmanager()
            || ist_bereichsleiter()
            || ist_sm()
            || ist_smstv();
    }

    function hat_zugriff_auf_uebersicht(): bool
    {
        return ist_admin()
            || ist_bereichsleiter()
            || ist_sm()
            || ist_smstv()
            || ist_ehs()
            || ist_trainingsmanager()
            || ist_leanmanager();
    }

    /**
     * @param int $mitarbeiterId
     * @return bool
     */
    function hat_zugriff_auf_mitarbeiter(int $mitarbeiterId): bool
    {
        $me = current_user_id();
        if (!$me) {
            return false;
        }
        if (ist_admin() || hat_zugriff_auf_hr() || ist_trainingsmanager() || ist_ehs() || ist_werksleiter()) {
            return true;
        }
        if ($me === $mitarbeiterId) {
            return true;
        }
        if (in_array($mitarbeiterId, hole_unterstellte_mitarbeiter($me), true)) {
            return true;
        }
        if (in_array($mitarbeiterId, hole_alle_unterstellten_mitarbeiter($me), true)) {
            return true;
        }
        return false;
    }


    //==========================================================================
    // SESSION & LOGIN CHECK
    //==========================================================================

    function pruefe_benutzer_eingeloggt(): void
    {
        if (!current_user_id()) {
            $_SESSION['redirect_to'] = $_SERVER['REQUEST_URI'];
            if (!empty($_COOKIE['session_expired'])) {
                setcookie('session_expired', '', time() - 3600, '/');
                header('Location: session_expired.php');
            } else {
                header('Location: login.php');
            }
            exit;
        }
    }


    //==========================================================================
    // WRAPPER FUNCTIONS (pruefe_…)
    //==========================================================================

    function pruefe_admin_zugriff(): void
    {
        if (!ist_admin()) {
            header('Location: access_denied.php');
            exit;
        }
    }

    function pruefe_admin_oder_hr_zugriff(): void
    {
        if (!ist_admin() && !ist_werksleiter() && !ist_it() && !hat_zugriff_auf_hr()) {
            header('Location: access_denied.php');
            exit;
        }
    }

    function pruefe_admin_oder_hr_oder_bereichsleiter_zugriff(): void
    {
        if (!ist_admin() && !ist_hr() && !ist_werksleiter() && !ist_it() && !ist_bereichsleiter()) {
            header('Location: access_denied.php');
            exit;
        }
    }

    function pruefe_geburtstagjubilare_zugriff(): void
    {
        if (!ist_admin() && !ist_werksleiter() && !ist_it() && !ist_hr() && !ist_bereichsleiter() && !ist_empfang()) {
            header('Location: access_denied.php');
            exit;
        }
    }

    function pruefe_trainings_zugriff(): void
    {
        if (!hat_zugriff_auf_trainings()) {
            header('Location: access_denied.php');
            exit;
        }
    }

    function pruefe_schranken_zugriff(): void
    {
        if (!(ist_admin() || ist_werksleiter() || ist_it() || ist_sm() || ist_smstv() || ist_ehs())) {
            header('Location: access_denied.php');
            exit;
        }
    }

    function pruefe_ehs_zugriff(): void
    {
        if (!hat_zugriff_auf_safety()) {
            header('Location: access_denied.php');
            exit;
        }
    }

    /**
     * Check access to "Führung" conversation area by position name.
     *
     * @param string $position
     * @return bool
     */
    function pruefe_fuehrung_zugriff(string $position): bool
    {
        global $conn;
        $stmt = $conn->prepare("
            SELECT 1
              FROM position_zu_gespraechsbereich
             WHERE position_id = (
                     SELECT id FROM positionen WHERE name = ?
                   )
               AND gesprächsbereich_id = (
                     SELECT id FROM gesprächsbereiche WHERE name = 'Führung'
                   )
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $position);
        $stmt->execute();
        $res = $stmt->get_result();
        $has = ($res->num_rows > 0);
        $stmt->close();
        return $has;
    }

    /**
     * @return bool
     */
    function pruefe_tl_tagschicht_zugriff(): bool
    {
        global $conn;
        $uid = current_user_id();
        $stmt = $conn->prepare("
            SELECT 1
              FROM employees
             WHERE employee_id = ?
               AND gruppe = 'Tagschicht'
               AND position LIKE '%AL%'
        ");
        if (!$stmt) {
            die('Prepare fehlgeschlagen: (' . $conn->errno . ') ' . $conn->error);
        }
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = $res->num_rows > 0;
        $stmt->close();
        return $ok;
    }

} // end ACCESS_CONTROL_INCLUDED
