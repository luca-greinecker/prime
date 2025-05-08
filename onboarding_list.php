<?php
/**
 * onboarding_list.php
 *
 * Stellt eine Übersicht aller Mitarbeiter bereit, die sich im Onboarding-Prozess
 * (onboarding_status in employees) befinden. Unterscheidet dabei zwischen:
 *  - Status=1 (Bearbeitung durch Empfang/HR/Administrator)
 *  - Status=2 (Bearbeitung durch Vorgesetzte)
 *  - Status=3 => abgeschlossen (erscheint hier nicht mehr)
 *
 * Je nach Rolle (Admin, HR, Empfang, Manager) werden unterschiedliche Datensätze angezeigt
 * und Aktionen (Mail senden, nächster Schritt, abschließen) angeboten.
 */

include 'access_control.php';
global $conn;

pruefe_benutzer_eingeloggt();
$meinLoginId = $_SESSION['mitarbeiter_id'];

// Array in Session für gesendete Mails
if (!isset($_SESSION['mail_sent_ids'])) {
    $_SESSION['mail_sent_ids'] = [];
}

// 1) Aktionen (Mail, Nächster Schritt, Abschließen)
if (isset($_GET['action'], $_GET['id'])) {
    $employeeId = (int)$_GET['id'];

    switch ($_GET['action']) {
        case 'mail':
            // Mail an Vorgesetzten
            $mgrEmail = hole_vorgesetzten_email($employeeId);
            if (!$mgrEmail) {
                $errorMessage = "Kein Vorgesetzter oder keine E-Mail hinterlegt!";
            } else {
                // Flag in Session, dass wir "Mail" versendet haben
                if (!in_array($employeeId, $_SESSION['mail_sent_ids'])) {
                    $_SESSION['mail_sent_ids'][] = $employeeId;
                }
                // Weiterleitung an "mailto:"
                $subject = rawurlencode("Neuer Mitarbeiter");
                $body = rawurlencode("Hallo,\nbitte um Rückmeldung zum neuen Mitarbeiter.");
                header("Location: mailto:$mgrEmail?subject=$subject&body=$body");
                exit;
            }
            break;

        case 'next':
            // Nächster Schritt => Status=1->2
            // Nur erlaubt, wenn vorher Mail gesendet wurde
            if (!in_array($employeeId, $_SESSION['mail_sent_ids'])) {
                $errorMessage = "Bitte zuerst 'Mail an Vorgesetzten' klicken!";
                break;
            }
            $stmtU = $conn->prepare("UPDATE employees SET onboarding_status=2 WHERE employee_id=?");
            $stmtU->bind_param("i", $employeeId);
            $stmtU->execute();
            $stmtU->close();
            // Option: array_diff() entfernen, wenn du Mail nur 1x pro Person brauchst
            header("Location: onboarding_list.php");
            exit;

        case 'complete':
            // Abschließen => Status=2->3
            $stmtC = $conn->prepare("UPDATE employees SET onboarding_status=3 WHERE employee_id=?");
            $stmtC->bind_param("i", $employeeId);
            $stmtC->execute();
            $stmtC->close();
            header("Location: onboarding_list.php");
            exit;
    }
}

// 2) Daten laden
// A) Status=1 (Empfang/HR/Admin)
$res1 = null;
if (ist_empfang() || ist_hr() || ist_admin()) {
    $sql1 = "
       SELECT employee_id, name, position, onboarding_status
       FROM employees
       WHERE onboarding_status=1
       ORDER BY employee_id DESC
    ";
    $res1 = $conn->query($sql1);
}

// B) Status=2
$res2 = null;
if (ist_admin() || ist_hr()) {
    // Alle Datensätze mit Status=2
    $sql2 = "
       SELECT employee_id, name, position, onboarding_status
       FROM employees
       WHERE onboarding_status=2
       ORDER BY employee_id DESC
    ";
    $res2 = $conn->query($sql2);
} else {
    // Manager => nur die MA, die ihm unterstellt sind
    $unterstellte = hole_unterstellte_mitarbeiter($meinLoginId);
    if (!empty($unterstellte)) {
        $idsList = implode(',', array_map('intval', $unterstellte));
        $sql2 = "
           SELECT employee_id, name, position, onboarding_status
           FROM employees
           WHERE onboarding_status=2
             AND employee_id IN ($idsList)
           ORDER BY employee_id DESC
        ";
        $res2 = $conn->query($sql2);
    }
}

// C) HR/ADMIN-Gesamtübersicht => Status in (1,2)
$resHR = null;
if (ist_hr() || ist_admin()) {
    $sqlHR = "
       SELECT employee_id, name, position, onboarding_status
       FROM employees
       WHERE onboarding_status IN (1,2)
       ORDER BY onboarding_status, employee_id DESC
    ";
    $resHR = $conn->query($sqlHR);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Onboarding-Liste</title>
    <link href="navbar.css" rel="stylesheet">
    <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .divider {
            margin: 2rem 0;
            border-bottom: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container content">
    <h1>Onboarding-Übersicht</h1>
    <div class="divider"></div>

    <?php
    // Falls eine Fehlermeldung existiert
    if (isset($errorMessage) && $errorMessage) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($errorMessage) . '</div>';
    }
    ?>

    <!-- 1) Status=1 (Empfang) -->
    <?php if ($res1 !== null): ?>
        <h3>Status 1 (Empfang)</h3>
        <?php if ($res1->num_rows > 0): ?>
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($row = $res1->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <a href="employee_details.php?employee_id=<?php echo (int)$row['employee_id']; ?>">
                                <?php echo htmlspecialchars($row['name']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td><?php echo (int)$row['onboarding_status']; ?></td>
                        <td>
                            <a class="btn btn-sm btn-info"
                               href="onboarding_list.php?action=mail&id=<?php echo (int)$row['employee_id']; ?>">
                                Mail an Vorgesetzten
                            </a>
                            <a class="btn btn-sm btn-warning"
                               href="onboarding_list.php?action=next&id=<?php echo (int)$row['employee_id']; ?>">
                                Nächster Schritt
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-secondary">Keine Mitarbeiter im Status=1.</div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- 2) Status=2 (Vorgesetzte) -->
    <h3 class="mt-5">Status 2 (Vorgesetzte)</h3>
    <?php if ($res2 && $res2->num_rows > 0): ?>
        <table class="table table-bordered table-hover">
            <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Position</th>
                <th>Status</th>
                <th>Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = $res2->fetch_assoc()): ?>
                <tr>
                    <td>
                        <a href="employee_details.php?employee_id=<?php echo (int)$row['employee_id']; ?>">
                            <?php echo htmlspecialchars($row['name']); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                    <td><?php echo (int)$row['onboarding_status']; ?></td>
                    <td>
                        <a class="btn btn-sm btn-success"
                           href="onboarding_list.php?action=complete&id=<?php echo (int)$row['employee_id']; ?>">
                            Abschließen
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-secondary">Keine Mitarbeiter im Status=2 (für dich relevant).</div>
    <?php endif; ?>

    <!-- 3) HR/ADMIN-Gesamtübersicht (Status 1 & 2) -->
    <?php if ($resHR !== null): ?>
        <h3 class="mt-5">HR-Gesamtübersicht (Status 1 & 2)</h3>
        <?php if ($resHR->num_rows > 0): ?>
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                <tr>
                    <th>MA-ID</th>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($row = $resHR->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo (int)$row['employee_id']; ?></td>
                        <td>
                            <a href="employee_details.php?employee_id=<?php echo (int)$row['employee_id']; ?>">
                                <?php echo htmlspecialchars($row['name']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td><?php echo (int)$row['onboarding_status']; ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-secondary">Keine Mitarbeiter in Status 1 oder 2.</div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
