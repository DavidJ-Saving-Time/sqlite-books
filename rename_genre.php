<?php
header('Content-Type: application/json');
require_once 'db.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$new = trim($_POST['new'] ?? '');
if ($id <= 0 || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('SELECT id FROM custom_column_2 WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Genre not found']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM custom_column_2 WHERE value = :val');
    $stmt->execute([':val' => $new]);
    $existingId = $stmt->fetchColumn();
    if ($existingId === false) {
        $pdo->prepare('UPDATE custom_column_2 SET value = :val WHERE id = :id')->execute([':val' => $new, ':id' => $id]);
    } else {
        $pdo->prepare('UPDATE books_custom_column_2_link SET value = :newid WHERE value = :oldid')->execute([':newid' => $existingId, ':oldid' => $id]);
        $pdo->prepare('DELETE FROM custom_column_2 WHERE id = :id')->execute([':id' => $id]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
