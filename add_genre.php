<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$genre = trim($_POST['genre'] ?? '');
if ($genre === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $genreId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
    // Calibre 8.5 stores multi-value options in the link table only,
    // so there is no separate values table to insert into.
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
