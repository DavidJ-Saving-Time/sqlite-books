<?php
/**
 * Adds a new shelf to the shelves table, creating defaults if necessary.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - shelf: Name of the shelf to add.
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
    $stmt = $pdo->prepare('CREATE TABLE IF NOT EXISTS shelves (name TEXT PRIMARY KEY)');
    $stmt->execute();
    $defaults = ['Physical', 'Ebook Calibre'];
    foreach ($defaults as $d) {
        $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (?)')->execute([$d]);
    }
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO shelves (name) VALUES (:name)');
    $stmt->execute([':name' => $shelf]);
    invalidateCache('shelves');
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
