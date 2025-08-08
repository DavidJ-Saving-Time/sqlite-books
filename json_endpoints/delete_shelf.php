<?php
/**
 * Deletes a shelf and reassigns books to the default shelf.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - shelf: Name of the shelf to remove.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../cache.php';
requireLogin();

$shelf = trim($_POST['shelf'] ?? '');
if ($shelf === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid shelf']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('DELETE FROM shelves WHERE name = :name');
    $stmt->execute([':name' => $shelf]);
    invalidateCache('shelves');
    $shelfId = ensureSingleValueColumn($pdo, '#shelf', 'Shelf');
    $table = "custom_column_{$shelfId}";

    $stmt = $pdo->prepare("UPDATE $table SET value = 'Ebook Calibre' WHERE value = :old");
    $stmt->execute([':old' => $shelf]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
