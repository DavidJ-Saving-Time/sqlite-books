<?php
/**
 * Renames a series.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - id: ID of the series to rename.
 * - new: New name for the series.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$new = trim($_POST['new'] ?? '');
if ($id <= 0 || $new === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid series']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('UPDATE series SET name = :new, sort = :new WHERE id = :id');
    $stmt->execute([':new' => $new, ':id' => $id]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

