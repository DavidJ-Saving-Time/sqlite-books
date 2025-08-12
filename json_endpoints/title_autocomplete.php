<?php
/**
 * @endpoint GET /api/autocomplete_title
 * @description Fuzzy autocomplete for book titles, using substring match and similarity scoring.
 *
 * @params
 *     - term (string, required): Partial title to search
 *
 * @returns JSON array of up to 10 closest matches
 */

require_once __DIR__ . '/../db.php';
requireLogin();
header('Content-Type: application/json');

$term = trim($_GET['term'] ?? '');
if ($term === '') {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDatabaseConnection();

    // Broad match to fetch candidate titles
    $stmt = $pdo->prepare('SELECT DISTINCT title FROM books WHERE title LIKE :term COLLATE NOCASE');
    $stmt->execute([':term' => '%' . $term . '%']);
    $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Score by similarity
    $scored = [];
    foreach ($titles as $title) {
        $score = similar_text(strtolower($term), strtolower($title));
        $scored[] = ['name' => $title, 'score' => $score];
    }

    // Sort by score descending, then title
    usort($scored, fn($a, $b) =>
        $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name'])
    );

    // Return just titles
    $result = array_column(array_slice($scored, 0, 10), 'name');
    echo json_encode($result);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
