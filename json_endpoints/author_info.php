<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : 0;
if ($authorId <= 0) {
    echo json_encode(['error' => 'Missing author_id']);
    exit;
}

$pdo = getDatabaseConnection();

// Basic author info
$author = $pdo->prepare('SELECT id, name, sort FROM authors WHERE id = ?');
$author->execute([$authorId]);
$author = $author->fetch(PDO::FETCH_ASSOC);
if (!$author) {
    echo json_encode(['error' => 'Author not found']);
    exit;
}

// Identifiers
$idRows = $pdo->prepare('SELECT type, val FROM author_identifiers WHERE author_id = ?');
$idRows->execute([$authorId]);
$identifiers = [];
foreach ($idRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $identifiers[$row['type']] = $row['val'];
}

// Book count
$bookCount = $pdo->prepare('SELECT COUNT(*) FROM books_authors_link WHERE author = ?');
$bookCount->execute([$authorId]);
$bookCount = (int)$bookCount->fetchColumn();

$bio   = $identifiers['bio']   ?? null;
$photo = $identifiers['photo'] ?? null;

// Remove bio/photo from identifiers so they don't appear in the IDs table
unset($identifiers['bio'], $identifiers['photo']);

echo json_encode([
    'id'          => $author['id'],
    'name'        => $author['name'],
    'book_count'  => $bookCount,
    'identifiers' => $identifiers,
    'bio'         => $bio,
    'photo'       => $photo,
]);
