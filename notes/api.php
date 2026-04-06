<?php
require_once '../db.php';
requireLogin();

$pdo = getDatabaseConnection();

// Schema setup
$pdo->exec("CREATE TABLE IF NOT EXISTS notepad_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
)");
try { $pdo->exec("ALTER TABLE notepad ADD COLUMN folder_id INTEGER"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE notepad ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}

// FTS
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
$path   = trim($_SERVER['PATH_INFO'] ?? '', '/');
$parts  = explode('/', $path);

header('Content-Type: application/json');

// GET /folders
if ($method === 'GET' && $path === 'folders') {
    $stmt = $pdo->query('SELECT id, name, sort_order FROM notepad_folders ORDER BY sort_order, id');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// POST /folders/0 (create) or /folders/{id} (rename)
if ($method === 'POST' && ($parts[0] ?? '') === 'folders' && isset($parts[1]) && ctype_digit($parts[1])) {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($data['name'] ?? 'New Folder');
    $fid  = (int)$parts[1];
    if ($fid === 0) {
        $max = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM notepad_folders')->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO notepad_folders (name, sort_order) VALUES (?, ?)');
        $stmt->execute([$name, $max + 1]);
        echo json_encode(['id' => (int)$pdo->lastInsertId(), 'name' => $name]);
    } else {
        $pdo->prepare('UPDATE notepad_folders SET name = ? WHERE id = ?')->execute([$name, $fid]);
        echo json_encode(['id' => $fid, 'name' => $name]);
    }
    exit;
}

// DELETE /folders/{id}
if ($method === 'DELETE' && ($parts[0] ?? '') === 'folders' && isset($parts[1]) && ctype_digit($parts[1])) {
    $fid = (int)$parts[1];
    $pdo->prepare('UPDATE notepad SET folder_id = NULL WHERE folder_id = ?')->execute([$fid]);
    $pdo->prepare('DELETE FROM notepad_folders WHERE id = ?')->execute([$fid]);
    echo json_encode(['success' => true]);
    exit;
}

// POST /reorder
if ($method === 'POST' && $path === 'reorder') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $pdo->beginTransaction();
    $noteStmt   = $pdo->prepare('UPDATE notepad SET sort_order = ?, folder_id = ? WHERE id = ?');
    $folderStmt = $pdo->prepare('UPDATE notepad_folders SET sort_order = ? WHERE id = ?');
    foreach ($data['notes'] ?? [] as $n) {
        $folderId = (isset($n['folder_id']) && (int)$n['folder_id'] > 0) ? (int)$n['folder_id'] : null;
        $noteStmt->execute([(int)$n['sort_order'], $folderId, (int)$n['id']]);
    }
    foreach ($data['folders'] ?? [] as $f) {
        $folderStmt->execute([(int)$f['sort_order'], (int)$f['id']]);
    }
    $pdo->commit();
    echo json_encode(['success' => true]);
    exit;
}

// GET / (list notes)
if ($method === 'GET' && $path === '') {
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = $pdo->prepare("SELECT n.id, n.title, n.folder_id, n.sort_order,
                                      snippet(notepad_fts, 1, '<mark>', '</mark>', '…', 10) AS snippet
                               FROM notepad_fts
                               JOIN notepad n ON n.id = notepad_fts.rowid
                               WHERE notepad_fts MATCH ?
                               ORDER BY rank");
        $stmt->execute([$q]);
    } else {
        $stmt = $pdo->query('SELECT id, title, folder_id, sort_order, last_edited
                             FROM notepad
                             ORDER BY COALESCE(folder_id, 999999), sort_order, last_edited DESC');
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// GET /{id}
if ($method === 'GET' && ctype_digit($path)) {
    $stmt = $pdo->prepare('SELECT id, title, text, folder_id, last_edited FROM notepad WHERE id = ?');
    $stmt->execute([$path]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($note) { echo json_encode($note); }
    else { http_response_code(404); echo json_encode(['error' => 'Not found']); }
    exit;
}

// POST /{id} (create or update)
if ($method === 'POST' && ctype_digit($path)) {
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $title    = trim($data['title'] ?? 'Untitled');
    $text     = $data['text'] ?? '';
    $folderId = (isset($data['folder_id']) && (int)$data['folder_id'] > 0) ? (int)$data['folder_id'] : null;

    if ($path === '0') {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM notepad WHERE folder_id IS ?');
        $maxStmt->execute([$folderId]);
        $order = (int)$maxStmt->fetchColumn() + 1;
        $stmt = $pdo->prepare('INSERT INTO notepad (title, text, folder_id, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$title, $text, $folderId, $order]);
        echo json_encode(['id' => (int)$pdo->lastInsertId()]);
    } else {
        if (array_key_exists('text', $data)) {
            // Full save (title + content)
            $pdo->prepare('UPDATE notepad SET title = ?, text = ?, last_edited = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$title, $text, $path]);
        } else {
            // Title-only rename
            $pdo->prepare('UPDATE notepad SET title = ?, last_edited = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$title, $path]);
        }
        echo json_encode(['id' => (int)$path]);
    }
    exit;
}

// DELETE /{id}
if ($method === 'DELETE' && ctype_digit($path)) {
    try {
        $pdo->prepare('DELETE FROM notepad WHERE id = ?')->execute([$path]);
    } catch (Exception $e) {
        // FTS trigger may have failed due to index corruption; rebuild and retry
        try { $pdo->exec("INSERT INTO notepad_fts(notepad_fts) VALUES('rebuild')"); } catch (Exception $e2) {}
        $pdo->exec('DROP TRIGGER IF EXISTS notepad_ad');
        $pdo->prepare('DELETE FROM notepad WHERE id = ?')->execute([$path]);
        // Restore trigger
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS notepad_ad AFTER DELETE ON notepad BEGIN
            INSERT INTO notepad_fts(notepad_fts, rowid, title, text) VALUES('delete', old.id, old.title, old.text);
        END;");
    }
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Bad request']);
