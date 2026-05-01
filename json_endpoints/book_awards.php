<?php
require_once '../db.php';
require_once '../cache.php';
requireLogin();
header('Content-Type: application/json');

$pdo = getDatabaseConnection();

// Ensure tables exist (safe to call on every request)
$pdo->exec("CREATE TABLE IF NOT EXISTS awards (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE COLLATE NOCASE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS book_awards (
    id       INTEGER PRIMARY KEY,
    book_id  INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    award_id INTEGER NOT NULL REFERENCES awards(id) ON DELETE CASCADE,
    year     INTEGER,
    category TEXT,
    result   TEXT NOT NULL DEFAULT 'nominated',
    UNIQUE(book_id, award_id, year, category)
)");

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Autocomplete: ?term=hugo → array of matching award name strings
    if (isset($_GET['term'])) {
        $term = '%' . trim($_GET['term']) . '%';
        $stmt = $pdo->prepare('SELECT name FROM awards WHERE name LIKE ? COLLATE NOCASE ORDER BY name COLLATE NOCASE LIMIT 10');
        $stmt->execute([$term]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        exit;
    }

    // List awards for a book: ?book_id=N
    $bookId = (int)($_GET['book_id'] ?? 0);
    if (!$bookId) { echo json_encode([]); exit; }

    $stmt = $pdo->prepare(
        'SELECT ba.id, ba.award_id, a.name AS award_name, ba.year, ba.category, ba.result
         FROM book_awards ba
         JOIN awards a ON ba.award_id = a.id
         WHERE ba.book_id = ?
         ORDER BY ba.year DESC, a.name COLLATE NOCASE'
    );
    $stmt->execute([$bookId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── POST: add or remove ───────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'add') {
        $bookId   = (int)($body['book_id'] ?? 0);
        $award    = trim($body['award']    ?? '');
        $year     = isset($body['year']) && $body['year'] !== '' ? (int)$body['year'] : null;
        $category = trim($body['category'] ?? '') ?: null;
        $result   = in_array($body['result'] ?? '', ['won', 'nominated', 'shortlisted'], true)
                        ? $body['result'] : 'nominated';

        if (!$bookId || $award === '') {
            echo json_encode(['error' => 'book_id and award are required']); exit;
        }

        // Upsert award name
        $pdo->prepare('INSERT OR IGNORE INTO awards (name) VALUES (?)')->execute([$award]);
        $stmt = $pdo->prepare('SELECT id FROM awards WHERE name = ? COLLATE NOCASE');
        $stmt->execute([$award]);
        $awardId = (int)$stmt->fetchColumn();

        try {
            $pdo->prepare('INSERT INTO book_awards (book_id, award_id, year, category, result) VALUES (?,?,?,?,?)')
                ->execute([$bookId, $awardId, $year, $category, $result]);
            $newId = (int)$pdo->lastInsertId();
            invalidateCache('awards');
            echo json_encode(['ok' => true, 'id' => $newId, 'award_id' => $awardId, 'award_name' => $award]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Could not add award (duplicate?)']);
        }
        exit;
    }

    if ($action === 'remove') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }
        $pdo->prepare('DELETE FROM book_awards WHERE id = ?')->execute([$id]);
        invalidateCache('awards');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'add_award') {
        $name = trim($body['name'] ?? '');
        if ($name === '') { echo json_encode(['error' => 'Name required']); exit; }
        try {
            $pdo->prepare('INSERT INTO awards (name) VALUES (?)')->execute([$name]);
            invalidateCache('awards');
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Award already exists']);
        }
        exit;
    }

    if ($action === 'rename_award') {
        $id   = (int)($body['id'] ?? 0);
        $name = trim($body['name'] ?? '');
        if (!$id || $name === '') { echo json_encode(['error' => 'Missing id or name']); exit; }
        try {
            $pdo->prepare('UPDATE awards SET name = ? WHERE id = ?')->execute([$name, $id]);
            invalidateCache('awards');
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Name already exists']);
        }
        exit;
    }

    if ($action === 'delete_award') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }
        // book_awards rows cascade via FK; awards row deleted here
        $pdo->prepare('DELETE FROM awards WHERE id = ?')->execute([$id]);
        invalidateCache('awards');
        echo json_encode(['ok' => true]);
        exit;
    }
}

echo json_encode(['error' => 'Bad request']);
