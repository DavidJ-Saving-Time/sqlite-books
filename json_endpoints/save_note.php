<?php
/**
 * Saves research answers to the notepad.
 *
 * POST parameters:
 * - mode: "new" to create a note or "append" to add to existing
 * - text: HTML content to store
 * - title: required when mode=new
 * - id: required when mode=append
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$mode = $_POST['mode'] ?? '';
$text = trim($_POST['text'] ?? '');
if ($text === '' || !in_array($mode, ['new', 'append'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    if ($mode === 'new') {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Title required']);
            exit;
        }
        $stmt = $pdo->prepare('INSERT INTO notepad (title, text) VALUES (:title, :text)');
        $stmt->execute([':title' => $title, ':text' => $text]);
        $id = (int)$pdo->lastInsertId();
        echo json_encode(['status' => 'ok', 'id' => $id]);
    } else { // append
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid note id']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE notepad SET text = text || ? || ?, last_edited = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute(["\n\n", $text, $id]);
        echo json_encode(['status' => 'ok', 'id' => $id]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
