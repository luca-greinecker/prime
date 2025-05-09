<?php
include 'db.php';
global $conn;

// Query
$stmt = $conn->prepare("
    SELECT employee_id, name, crew, position, bild
    FROM employees
    WHERE bild <> 'kein-bild.jpg' AND status != 9999
    ORDER BY RAND()
    LIMIT 3
");
$stmt->execute();
$result = $stmt->get_result();
$mitarbeiter = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($mitarbeiter);
?>