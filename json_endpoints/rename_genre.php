<?php
/**
 * Renames an existing genre value.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - id: Current genre name.
 * - new: New genre name.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
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
    $valueTable = "custom_column_{$genreId}";
    $pdo->prepare("UPDATE $valueTable SET value = :new WHERE value = :old")->execute([':new' => $new, ':old' => $old]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
