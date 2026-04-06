<?php
/**
 * Library book search endpoint.
 *
 * GET Parameters:
 *   q          - Search term (matched against title and author name)
 *   by_id      - Return a single book by its Calibre book ID (ignores q)
 *   with_files - When non-empty, include the preferred file path (PDF > EPUB)
 *
 * Returns a JSON array of objects: { id, title, author, [file] }
 * or a single object when by_id is used (null if not found).
 */
require_once '../db.php';
requireLogin();

header('Content-Type: application/json');

$byId      = isset($_GET['by_id']) && ctype_digit((string)$_GET['by_id']) ? (int)$_GET['by_id'] : 0;
$q         = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$withFiles = !empty($_GET['with_files']);

if (!$byId && $q === '') {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDatabaseConnection();

    $fileJoin = $withFiles
        ? "LEFT JOIN data d ON d.book = b.id AND lower(d.format) IN ('pdf','epub')"
        : '';
    $fileSelect = $withFiles
        ? ", b.path, GROUP_CONCAT(d.format || ':' || d.name) AS files"
        : '';

    if ($byId) {
        $sql = "SELECT b.id, b.title{$fileSelect},
                       GROUP_CONCAT(DISTINCT a.name) AS author
                FROM books b
                LEFT JOIN books_authors_link bal ON bal.book = b.id
                LEFT JOIN authors a ON a.id = bal.author
                {$fileJoin}
                WHERE b.id = :id
                GROUP BY b.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $byId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ? resolveRow($row, $withFiles) : null);
    } else {
        $sql = "SELECT b.id, b.title{$fileSelect},
                       GROUP_CONCAT(DISTINCT a.name) AS author
                FROM books b
                LEFT JOIN books_authors_link bal ON bal.book = b.id
                LEFT JOIN authors a ON a.id = bal.author
                {$fileJoin}
                WHERE b.title LIKE :q
                   OR EXISTS (
                       SELECT 1 FROM authors aa
                       JOIN books_authors_link bl ON bl.author = aa.id
                       WHERE bl.book = b.id AND aa.name LIKE :q
                   )
                GROUP BY b.id
                ORDER BY b.title COLLATE NOCASE
                LIMIT 30";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':q' => '%' . $q . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_values(array_map(fn($r) => resolveRow($r, $withFiles), $rows)));
    }
} catch (Exception $e) {
    echo $byId ? 'null' : '[]';
}

function resolveRow(array $row, bool $withFiles): array {
    if (!$withFiles) {
        return ['id' => (int)$row['id'], 'title' => $row['title'], 'author' => $row['author'] ?? ''];
    }
    $pdfRel  = null;
    $epubRel = null;
    if (!empty($row['files'])) {
        foreach (explode(',', $row['files']) as $entry) {
            if (!str_contains($entry, ':')) continue;
            [$fmt, $name] = explode(':', $entry, 2);
            $rel = $row['path'] . '/' . $name . '.' . strtolower($fmt);
            if (strtolower($fmt) === 'pdf'  && $pdfRel  === null) $pdfRel  = $rel;
            if (strtolower($fmt) === 'epub' && $epubRel === null) $epubRel = $rel;
        }
    }
    return [
        'id'     => (int)$row['id'],
        'title'  => $row['title'],
        'author' => $row['author'] ?? '',
        'file'   => $pdfRel ?? $epubRel ?? '',
    ];
}
