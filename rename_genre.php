<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$new = trim($_POST['new'] ?? '');
if ($id <= 0 || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid genre']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    [$genreId, $valueTable, $linkTable] = ensureMultivalueColumn($pdo, 'genre');
    $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Genre not found']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
    $stmt->execute([':val' => $new]);
    $existingId = $stmt->fetchColumn();
    if ($existingId === false) {
        $pdo->prepare("UPDATE $valueTable SET value = :val WHERE id = :id")->execute([':val' => $new, ':id' => $id]);
    } else {
        $pdo->prepare("UPDATE $linkTable SET value = :newid WHERE value = :oldid")->execute([':newid' => $existingId, ':oldid' => $id]);
        $pdo->prepare("DELETE FROM $valueTable WHERE id = :id")->execute([':id' => $id]);
    }
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
