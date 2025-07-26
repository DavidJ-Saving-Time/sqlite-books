<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$old = trim($_POST['id'] ?? '');
$new = trim($_POST['new'] ?? '');
if ($old === '' || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $genreId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
    $linkTable = "books_custom_column_{$genreId}_link";
    $pdo->prepare("UPDATE $linkTable SET value = :new WHERE value = :old")->execute([':new' => $new, ':old' => $old]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
