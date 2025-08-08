<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'cache.php';
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
    $valueTable = "custom_column_{$genreId}";
    $linkTable = "books_custom_column_{$genreId}_link";

    $valStmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
    $valStmt->execute([':val' => $genre]);
    $gid = $valStmt->fetchColumn();
    if ($gid !== false) {
        $pdo->prepare("DELETE FROM $linkTable WHERE value = :id")->execute([':id' => $gid]);
        $pdo->prepare("DELETE FROM $valueTable WHERE id = :id")->execute([':id' => $gid]);
    }
    invalidateCache('genres');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
