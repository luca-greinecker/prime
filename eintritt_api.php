<?php
/**
 * eintritt_api.php
 *
 * Nimmt per POST ein JSON-Objekt mit neuen Mitarbeiterdaten entgegen und legt
 * in der Tabelle 'employees' sowie optional in 'benutzer_matool' (Benutzerzugang) einen Datensatz an.
 *
 * Erwartet einen Authorization-Header mit "Bearer MY_SUPER_SECRET_TOKEN".
 * Bei Konflikten (z. B. bereits vorhandener Benutzername) wird ein HTTP-Fehlercode zurückgegeben.
 */

include 'db.php';
global $conn;

// Token validieren
define('API_TOKEN', 'MY_SUPER_SECRET_TOKEN');
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(["status" => "ERROR", "msg" => "No Authorization header"]);
    exit;
}
$authHeader = $headers['Authorization'];
if (strpos($authHeader, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(["status" => "ERROR", "msg" => "Invalid Authorization format"]);
    exit;
}
$token = substr($authHeader, 7);
if ($token !== API_TOKEN) {
    http_response_code(403);
    echo json_encode(["status" => "ERROR", "msg" => "Invalid API token"]);
    exit;
}

// JSON-Daten einlesen und dekodieren
$json = file_get_contents('php://input');
// BOM entfernen (falls vorhanden, etwa durch Excel-Exporte)
$json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["status" => "ERROR", "msg" => "JSON parse error"]);
    exit;
}

// Felder aus dem JSON extrahieren (inkl. optionaler Felder)
$vorname           = $data['Vorname'] ?? '';
$nachname          = $data['Nachname'] ?? '';
$position          = $data['Position'] ?? '';
$gruppe            = $data['Gruppe'] ?? '';
$shoe_size         = $data['Shoe_size'] ?? '';
$geburtsdatum      = $data['Geburtsdatum'] ?? '';
$eintrittsdatum    = $data['Eintrittsdatum'] ?? '';
$benutzername      = $data['Benutzername'] ?? '';

$lohnschema        = $data['lohnschema'] ?? '';
$pr_anfangslohn    = $data['pr_anfangslohn'] ?? '0';
$pr_grundlohn      = $data['pr_grundlohn'] ?? '0';
$leasing           = $data['leasing'] ?? '0';
$crew              = $data['crew'] ?? '';
$gender            = $data['gender'] ?? '';
$soznr             = $data['social_security_number'] ?? '';
$pr_lehrabschluss  = $data['pr_lehrabschluss'] ?? '0';

// Vollständiger Name: Nachname + Vorname
$fullName = trim($nachname . ' ' . $vorname);

// Datumsfelder in "YYYY-MM-DD" konvertieren
$birthSQL = convertToSqlDate($geburtsdatum);
$entrySQL = convertToSqlDate($eintrittsdatum);

// Transaktion starten
$conn->begin_transaction();

try {
    // Prüfen, ob Benutzername bereits existiert
    if (!empty($benutzername)) {
        $sql_check = "SELECT COUNT(*) AS cnt FROM benutzer_matool WHERE username = ?";
        $stmt_check = $conn->prepare($sql_check);
        if (!$stmt_check) {
            throw new Exception("Prepare username-check failed: " . $conn->error, 500);
        }
        $stmt_check->bind_param("s", $benutzername);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        $stmt_check->close();

        $row_check = $res_check->fetch_assoc();
        if ($row_check['cnt'] > 0) {
            // Benutzername vorhanden -> Rollback
            $conn->rollback();
            http_response_code(409);
            echo json_encode([
                "status" => "ERROR",
                "msg"    => "Benutzername bereits vergeben: " . $benutzername
            ]);
            exit;
        }
    }

    // Mitarbeiter in employees einfügen
    $sql_emp = "
        INSERT INTO employees
            (name, position, gruppe, shoe_size, birthdate, entry_date, lohnschema,
             pr_anfangslohn, pr_grundlohn, leasing, crew, gender, social_security_number,
             pr_lehrabschluss)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ";
    $stmt_emp = $conn->prepare($sql_emp);
    if (!$stmt_emp) {
        throw new Exception("Prepare employees failed: " . $conn->error, 500);
    }
    $stmt_emp->bind_param(
        "ssssssssssssss",
        $fullName,
        $position,
        $gruppe,
        $shoe_size,
        $birthSQL,
        $entrySQL,
        $lohnschema,
        $pr_anfangslohn,
        $pr_grundlohn,
        $leasing,
        $crew,
        $gender,
        $soznr,
        $pr_lehrabschluss
    );
    if (!$stmt_emp->execute()) {
        throw new Exception("Insert employees failed: " . $stmt_emp->error, 500);
    }
    // Neues employee_id
    $new_id = $stmt_emp->insert_id;
    $stmt_emp->close();

    // Optional: Benutzer in benutzer_matool anlegen, falls ein Benutzername übergeben wurde
    if (!empty($benutzername)) {
        $standard_password = 'Ball1234'; // Beispiel: fest codiertes Standardpasswort
        $hashed_password   = password_hash($standard_password, PASSWORD_DEFAULT);

        $sql_user = "
            INSERT INTO benutzer_matool (username, password, mitarbeiter_id)
            VALUES (?, ?, ?)
        ";
        $stmt_user = $conn->prepare($sql_user);
        if (!$stmt_user) {
            throw new Exception("Prepare benutzer_matool failed: " . $conn->error, 500);
        }
        $stmt_user->bind_param("ssi", $benutzername, $hashed_password, $new_id);
        if (!$stmt_user->execute()) {
            throw new Exception("Insert benutzer_matool failed: " . $stmt_user->error, 500);
        }
        $stmt_user->close();
    }

    // Alles erfolgreich -> Commit
    $conn->commit();

    // Erfolgsmeldung ausgeben
    http_response_code(200);
    echo json_encode([
        "status" => "OK",
        "msg"    => "Mitarbeiter + User angelegt",
        "new_id" => $new_id
    ]);

} catch (Exception $e) {
    // Fehler -> Rollback
    $conn->rollback();
    $httpCode = $e->getCode() ?: 500;
    http_response_code($httpCode);
    echo json_encode([
        "status" => "ERROR",
        "msg"    => $e->getMessage()
    ]);
}

/**
 * Wandelt ein Datum im Format "DD.MM.YYYY", "DD-MM-YYYY" oder "DD/MM/YYYY" in das Format "YYYY-MM-DD" um.
 * Falls das Datum ungültig oder leer ist, wird null zurückgegeben.
 */
function convertToSqlDate($dateStr) {
    if (!$dateStr) {
        return null;
    }
    // Ersetze mögliche "-" oder "/" durch "."
    $dateStr = str_replace(['-', '/'], '.', $dateStr);
    $parts   = explode('.', $dateStr);
    if (count($parts) === 3) {
        $dd   = (int)$parts[0];
        $mm   = (int)$parts[1];
        $yyyy = (int)$parts[2];
        if (checkdate($mm, $dd, $yyyy)) {
            return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
        }
    }
    return null;
}