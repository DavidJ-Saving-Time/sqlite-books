<?php
require_once '../db.php';
requireLogin();

$pdo = getDatabaseConnection();

// Ensure FTS table and triggers exist for search
$pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS notepad_fts USING fts5(title, text, content='notepad', content_rowid='id')");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS notepad_ai AFTER INSERT ON notepad BEGIN
    INSERT INTO notepad_fts(rowid, title, text) VALUES (new.id, new.title, new.text);
END;");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS notepad_au AFTER UPDATE ON notepad BEGIN
    INSERT INTO notepad_fts(notepad_fts, rowid, title, text) VALUES('delete', old.id, old.title, old.text);
    INSERT INTO notepad_fts(rowid, title, text) VALUES (new.id, new.title, new.text);
END;");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS notepad_ad AFTER DELETE ON notepad BEGIN
    INSERT INTO notepad_fts(notepad_fts, rowid, title, text) VALUES('delete', old.id, old.title, old.text);
END;");

$method = $_SERVER['REQUEST_METHOD'];
$path = trim($_SERVER['PATH_INFO'] ?? '', '/');

header('Content-Type: application/json');

if ($method === 'GET' && $path === '') {
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = $pdo->prepare("SELECT n.id, n.title, snippet(notepad_fts, 1, '<mark>', '</mark>', '...', 10) AS snippet
                               FROM notepad_fts
                               JOIN notepad n ON n.id = notepad_fts.rowid
                               WHERE notepad_fts MATCH ?
                               ORDER BY rank");
        $stmt->execute([$q]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        $stmt = $pdo->query('SELECT id, title, last_edited FROM notepad ORDER BY last_edited DESC');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

if ($method === 'GET' && ctype_digit($path)) {
    $stmt = $pdo->prepare('SELECT id, title, text, last_edited FROM notepad WHERE id = ?');
    $stmt->execute([$path]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($note) {
        echo json_encode($note);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
    exit;
}

if ($method === 'POST' && ctype_digit($path)) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($data['title'] ?? 'Untitled');
    $text  = $data['text'] ?? '';

    if ($path === '0') {
        $stmt = $pdo->prepare('INSERT INTO notepad (title, text) VALUES (?, ?)');
        $stmt->execute([$title, $text]);
        echo json_encode(['id' => (int)$pdo->lastInsertId()]);
    } else {
        $stmt = $pdo->prepare('UPDATE notepad SET title = ?, text = ?, last_edited = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$title, $text, $path]);
        echo json_encode(['id' => (int)$path]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Bad request']);
