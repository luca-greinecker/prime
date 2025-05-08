<?php
/**
 * update_employee_photo.php
 *
 * Handles updating an employee's photo.
 * Only accessible to HR and Reception staff.
 */

include 'access_control.php';
global $conn;

// Ensure user is logged in and has appropriate permissions
pruefe_benutzer_eingeloggt();

// Check if the user has appropriate access
if (!ist_empfang() && !ist_hr() && !ist_admin()) {
    header("Location: access_denied.php");
    exit;
}

// Check if employee ID is provided
if (!isset($_POST['employee_id']) || !is_numeric($_POST['employee_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Ungültige Mitarbeiter-ID.'
    ]);
    exit;
}

$employee_id = (int)$_POST['employee_id'];

// Fetch current employee photo
$stmt = $conn->prepare("SELECT bild FROM employees WHERE employee_id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$old_photo = $result->fetch_assoc()['bild'] ?? '';
$stmt->close();

// Process image upload
if (isset($_FILES['employee_photo']) && $_FILES['employee_photo']['error'] === UPLOAD_ERR_OK) {
    // Validate image type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (in_array($_FILES['employee_photo']['type'], $allowed_types)) {
        // Use original filename
        $file_name = $_FILES['employee_photo']['name'];
        $upload_dir = '../mitarbeiter-anzeige/fotos/';
        $target_file = $upload_dir . $file_name;

        // Move uploaded file
        if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $target_file)) {
            // Update database
            $stmt = $conn->prepare("UPDATE employees SET bild = ? WHERE employee_id = ?");
            $stmt->bind_param("si", $file_name, $employee_id);

            if ($stmt->execute()) {
                // Delete old photo if it exists and isn't the default
                if (!empty($old_photo) && $old_photo !== 'kein-bild.jpg' && $old_photo !== $file_name) {
                    $old_photo_path = $upload_dir . $old_photo;
                    if (file_exists($old_photo_path)) {
                        @unlink($old_photo_path);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Foto erfolgreich aktualisiert.',
                    'new_photo' => $file_name
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Fehler beim Aktualisieren der Datenbank: ' . $stmt->error
                ]);
            }
            $stmt->close();
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim Hochladen des Bildes.'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Ungültiger Dateityp. Bitte laden Sie ein Bild im JPEG, PNG oder GIF-Format hoch.'
        ]);
    }
} elseif (isset($_POST['remove_photo']) && $_POST['remove_photo'] == 1) {
    // Set to default photo
    $default_photo = 'kein-bild.jpg';
    $stmt = $conn->prepare("UPDATE employees SET bild = ? WHERE employee_id = ?");
    $stmt->bind_param("si", $default_photo, $employee_id);

    if ($stmt->execute()) {
        // Delete old photo if it exists and isn't the default
        if (!empty($old_photo) && $old_photo !== 'kein-bild.jpg') {
            $old_photo_path = '../mitarbeiter-anzeige/fotos/' . $old_photo;
            if (file_exists($old_photo_path)) {
                @unlink($old_photo_path);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Foto erfolgreich entfernt.',
            'new_photo' => $default_photo
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Aktualisieren der Datenbank: ' . $stmt->error
        ]);
    }
    $stmt->close();
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Keine Datei hochgeladen oder Fehler beim Upload.'
    ]);
}
?>