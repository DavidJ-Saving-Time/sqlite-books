<?php
/**
 * Save (upsert) a single identifier for a book.
 *
 * POST { book_id, type, val }
 * Returns { ok: true } or { error: "..." }
 */
require_once __DIR__ . '/../db.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$bookId = isset($data['book_id']) ? (int)$data['book_id'] : 0;
$type   = trim($data['type'] ?? '');
$val    = trim($data['val']  ?? '');

if ($bookId <= 0 || $type === '' || $val === '') {
    http_response_code(400);
    echo json_encode(['error' => 'book_id, type and val are required']);
    exit;
}

// Validate type is safe (alphanumeric + underscore only)
if (!preg_match('/^[a-z0-9_]+$/i', $type)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid identifier type']);
    exit;
}

$pdo = getDatabaseConnection();

// Verify book exists
$check = $pdo->prepare('SELECT id FROM books WHERE id = ?');
$check->execute([$bookId]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => 'Book not found']);
    exit;
}

$pdo->prepare('INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, ?, ?)')
    ->execute([$bookId, $type, $val]);

echo json_encode(['ok' => true]);
