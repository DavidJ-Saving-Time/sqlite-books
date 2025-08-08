<?php
/**
 * Adds a new subseries either to a custom column or the subseries table.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - name: Name of the subseries.
 *
 * Returns:
 * {"status":"ok","id":int} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$name = trim($_POST['name'] ?? '');
if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subseries']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $valueTable = "custom_column_{$subseriesColumnId}";
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)");
        $stmt->execute([':val' => $name]);
        $id = $pdo->query("SELECT id FROM $valueTable WHERE value = " . $pdo->quote($name))->fetchColumn();
    } else {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO subseries (name, sort) VALUES (:name, :sort)');
        $stmt->execute([':name' => $name, ':sort' => $name]);
        $id = $pdo->query('SELECT id FROM subseries WHERE name = ' . $pdo->quote($name))->fetchColumn();
    }
    echo json_encode(['status' => 'ok', 'id' => (int)$id]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
