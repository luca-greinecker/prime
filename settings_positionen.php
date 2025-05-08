<?php
/**
 * settings_positionen.php
 *
 * Ermöglicht das Zuordnen von Positionen (z. B. "Schichtmeister", "Tagschicht - Elektrik", etc.)
 * zu den verschiedenen Gesprächsbereichen (z. B. "Führung", "Fachkompetenzen", etc.).
 * Außerdem können neue Positionen hinzugefügt und bestehende bearbeitet werden.
 */

include 'access_control.php';
global $conn;
pruefe_benutzer_eingeloggt();
pruefe_admin_oder_hr_zugriff();

// 1) Gesprächsbereiche laden
$gespraechsbereiche = [];
$stmtBereiche = $conn->prepare("SELECT id, name FROM gesprächsbereiche");
$stmtBereiche->execute();
$resBereiche = $stmtBereiche->get_result();
while ($row = $resBereiche->fetch_assoc()) {
    $gespraechsbereiche[] = $row;
}
$stmtBereiche->close();

// 2) Positionen laden
$positionen = [];
$stmtPos = $conn->prepare("SELECT id, name, gruppe FROM positionen");
$stmtPos->execute();
$resPos = $stmtPos->get_result();
while ($row = $resPos->fetch_assoc()) {
    $positionen[] = $row;
}
$stmtPos->close();

// 3) Zuordnungen position_zu_gespraechsbereich laden
$zuordnungen = [];
$stmtZu = $conn->prepare("SELECT gesprächsbereich_id, position_id FROM position_zu_gespraechsbereich");
$stmtZu->execute();
$resZu = $stmtZu->get_result();
while ($row = $resZu->fetch_assoc()) {
    $gespraech_id = $row['gesprächsbereich_id'];
    $pos_id = $row['position_id'];
    $zuordnungen[$gespraech_id][] = $pos_id;
}
$stmtZu->close();

/**
 * Bearbeitung via Ajax:
 *  - Speichern der Zuordnungen
 *  - Neue Position hinzufügen
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A) Speichern der Zuordnungen
    if (isset($_POST['positionen_ids'])) {
        $insert_success = true;
        // POST: 'positionen_ids' => [ gesprächsbereich_id => [pos_id1, pos_id2], ... ]
        foreach ($_POST['positionen_ids'] as $gesprID => $posIDs) {
            // 1) Alte Zuordnungen löschen
            $delStmt = $conn->prepare("DELETE FROM position_zu_gespraechsbereich WHERE gesprächsbereich_id = ?");
            $delStmt->bind_param("i", $gesprID);
            $delStmt->execute();
            $delStmt->close();

            // 2) Neue einfügen
            foreach ($posIDs as $posID) {
                $insStmt = $conn->prepare("
                    INSERT INTO position_zu_gespraechsbereich (position_id, gesprächsbereich_id)
                    VALUES (?, ?)
                ");
                $insStmt->bind_param("ii", $posID, $gesprID);
                if (!$insStmt->execute()) {
                    $insert_success = false;
                }
                $insStmt->close();
            }
        }

        $modal_message = $insert_success
            ? "Die Zuordnungen wurden erfolgreich gespeichert."
            : "Fehler beim Speichern der Zuordnungen.";
        echo json_encode([
            'success' => $insert_success,
            'message' => $modal_message
        ]);
        exit;
    }

    // B) Neue Position hinzufügen
    if (isset($_POST['new_bereich_name'], $_POST['gruppe'])) {
        $newName = $_POST['new_bereich_name'];
        $gruppe = $_POST['gruppe'];

        $insStmt = $conn->prepare("INSERT INTO positionen (name, gruppe) VALUES (?, ?)");
        $insStmt->bind_param("ss", $newName, $gruppe);

        if ($insStmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Position erfolgreich hinzugefügt.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fehler beim Hinzufügen der Position.']);
        }
        $insStmt->close();
        exit;
    }
}

// Wenn kein POST, wird die HTML-Seite gerendert:
?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Positionen zu Gesprächsbereichen</title>
        <!-- Lokales Bootstrap 5 CSS -->
        <link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="navbar.css" rel="stylesheet">
        <style>
            .card {
                margin-bottom: 15px;
            }

            .form-check-input:disabled {
                background-color: #e9ecef;
            }

            .divider {
                margin: 2rem 0;
                border-bottom: 1px solid #dee2e6;
            }

            .edit-position-btn {
                font-size: 0.85rem;
                margin-left: 10px;
                padding: 0;
            }

            .no-margin {
                margin: 0 !important;
            }
        </style>
    </head>
    <body>
    <?php include 'navbar.php'; ?>
    <div class="container content">
        <h1 class="text-center mb-3">Positionen zu Gesprächsbereichen zuordnen</h1>
        <div class="divider mb-4"></div>

        <!-- Zuordnungen-Formular -->
        <form id="zuordnungenForm">
            <div class="row">
                <?php foreach ($gespraechsbereiche as $gb): ?>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="no-margin"><?php echo htmlspecialchars($gb['name']); ?></h4>
                            </div>
                            <div class="card-body">
                                <?php
                                // Jede Position einmal anzeigen, checken ob sie diesem GB zugeordnet ist
                                // + disabled, wenn sie bereits in einem anderen GB enthalten ist (falls das gewünscht ist).
                                foreach ($positionen as $pos) {
                                    $posId = $pos['id'];
                                    $posName = $pos['name'];
                                    $checked = (in_array($posId, $zuordnungen[$gb['id']] ?? [])) ? 'checked' : '';
                                    $disabled = false;

                                    // Falls du willst, dass jede Position nur in EXACT EINEM GB sein darf:
                                    // -> Falls posId bereits in $zuordnungen[irgendwas], dann disabled,
                                    //    außer es ist in dem EXACT 'gb['id']'.
                                    foreach ($zuordnungen as $someGbId => $posIds) {
                                        if (in_array($posId, $posIds) && $someGbId != $gb['id']) {
                                            $disabled = true;
                                            break;
                                        }
                                    }
                                    $disabledAttr = $disabled ? 'disabled' : '';
                                    ?>
                                    <div class="form-check">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               name="positionen_ids[<?php echo (int)$gb['id']; ?>][]"
                                               value="<?php echo (int)$posId; ?>"
                                               id="pos_<?php echo (int)$posId; ?>_gb_<?php echo (int)$gb['id']; ?>"
                                            <?php echo $checked . ' ' . $disabledAttr; ?>>
                                        <label class="form-check-label"
                                               for="pos_<?php echo (int)$posId; ?>_gb_<?php echo (int)$gb['id']; ?>">
                                            <?php echo htmlspecialchars($posName); ?>
                                        </label>
                                        <button type="button"
                                                class="btn btn-sm btn-link edit-position-btn"
                                                data-position-id="<?php echo (int)$posId; ?>"
                                                data-position-name="<?php echo htmlspecialchars($posName); ?>">
                                            Bearbeiten
                                        </button>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary">Speichern</button>
        </form>

        <div class="divider"></div>

        <h2 class="mb-3">Neue Position hinzufügen</h2>
        <form id="newPositionForm">
            <div class="mb-3">
                <label for="new_bereich_name" class="form-label">Positionsname:</label>
                <input type="text" class="form-control" id="new_bereich_name" name="new_bereich_name"
                       placeholder="Neue Position (z. B. Druckmaschine - TL)" required>
            </div>
            <div class="mb-3">
                <label for="gruppe" class="form-label">Gruppe:</label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="gruppe" id="schichtarbeit" value="Schichtarbeit"
                           required>
                    <label class="form-check-label" for="schichtarbeit">Schichtarbeit</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="gruppe" id="tagschicht" value="Tagschicht"
                           required>
                    <label class="form-check-label" for="tagschicht">Tagschicht</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="gruppe" id="verwaltung" value="Verwaltung"
                           required>
                    <label class="form-check-label" for="verwaltung">Verwaltung</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Hinzufügen</button>
        </form>
    </div>

    <!-- Ergebnis-Modal -->
    <div class="modal fade" id="resultModal" tabindex="-1" aria-labelledby="resultModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="resultModalLabel" class="modal-title">Ergebnis</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="resultMessage"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Position bearbeiten -->
    <div class="modal fade" id="editPositionModal" tabindex="-1" aria-labelledby="editPositionModalLabel"
         aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="editPositionModalLabel" class="modal-title">Position bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editPositionForm">
                        <input type="hidden" id="edit_position_id" name="position_id">
                        <div class="mb-3">
                            <label for="edit_position_name" class="form-label">Positionsname:</label>
                            <input type="text" class="form-control" id="edit_position_name" name="position_name"
                                   required>
                        </div>
                        <button type="submit" class="btn btn-primary">Speichern</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Lokales Bootstrap 5 JS -->
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // 1) Beim Ändern eines Checkbox -> wenn checked, dann in allen anderen Bereichen dieselbe Position disabled
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', function () {
                    const posID = this.value;
                    const isChecked = this.checked;
                    // Finde alle Checkboxen mit demselben Value=posID
                    document.querySelectorAll('input[type="checkbox"][value="' + posID + '"]').forEach(cb => {
                        if (cb !== this) {
                            if (isChecked) {
                                // Falls hier an-geclickt => disabled in den anderen Cards
                                cb.disabled = true;
                                cb.checked = false;
                            } else {
                                // Falls ent-clickt => re-enable
                                cb.disabled = false;
                            }
                        }
                    });
                });
            });

            // 2) Zuordnungen speichern (AJAX)
            document.getElementById('zuordnungenForm').addEventListener('submit', function (ev) {
                ev.preventDefault();
                const formData = new FormData(this);
                fetch('settings_positionen.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('resultMessage').textContent = data.message;
                        let modal = new bootstrap.Modal(document.getElementById('resultModal'));
                        modal.show();
                        if (data.success) {
                            setTimeout(() => window.location.reload(), 2000);
                        }
                    })
                    .catch(err => {
                        document.getElementById('resultMessage').textContent = 'Fehler beim Speichern.';
                        let modal = new bootstrap.Modal(document.getElementById('resultModal'));
                        modal.show();
                    });
            });

            // 3) Neue Position hinzufügen
            document.getElementById('newPositionForm').addEventListener('submit', function (ev) {
                ev.preventDefault();
                const formData = new FormData(this);
                fetch('settings_positionen.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('resultMessage').textContent = data.message;
                        let modal = new bootstrap.Modal(document.getElementById('resultModal'));
                        modal.show();
                        if (data.success) {
                            setTimeout(() => window.location.reload(), 2000);
                        }
                    })
                    .catch(err => {
                        document.getElementById('resultMessage').textContent = 'Fehler beim Hinzufügen.';
                        let modal = new bootstrap.Modal(document.getElementById('resultModal'));
                        modal.show();
                    });
            });

            // 4) Position bearbeiten: öffnet editPositionModal
            document.querySelectorAll('.edit-position-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    let posID = this.dataset.positionId;
                    let posName = this.dataset.positionName;
                    document.getElementById('edit_position_id').value = posID;
                    document.getElementById('edit_position_name').value = posName;
                    let modal = new bootstrap.Modal(document.getElementById('editPositionModal'));
                    modal.show();
                });
            });

            // 5) Bearbeitete Position speichern
            document.getElementById('editPositionForm').addEventListener('submit', function (ev) {
                ev.preventDefault();
                const formData = new FormData(this);
                fetch('edit_bereich.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('resultMessage').textContent = data.message;
                        let modal = new bootstrap.Modal(document.getElementById('resultModal'));
                        modal.show();
                        if (data.success) {
                            setTimeout(() => window.location.reload(), 2000);
                        }
                    })
                    .catch(err => alert('Fehler beim Bearbeiten der Position.'));
            });
        });
    </script>
    </body>
    </html>
<?php
$conn->close();
?>