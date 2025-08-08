<?php
/**
 * Deletes a note from the notepad.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - id: ID of the note to delete.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$noteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($noteId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid note id']);
    exit;
}

$pdo = getDatabaseConnection();
try {
    $stmt = $pdo->prepare('DELETE FROM notepad WHERE id = ?');
    $stmt->execute([$noteId]);
    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
