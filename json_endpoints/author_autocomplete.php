<?php
/**
 * @endpoint GET /api/autocomplete_author
 * @description Fuzzy autocomplete for author names, using substring match and similarity scoring.
 *
 * @params
 *     - term (string, required): Partial author name to search
 *
 * @returns JSON array of up to 10 closest matches (e.g., ["John Smith", "Johnny Appleseed"])
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

    // Broad match to fetch candidates
    $stmt = $pdo->prepare('SELECT name FROM authors WHERE name LIKE :term COLLATE NOCASE');
    $stmt->execute([':term' => '%' . $term . '%']);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Score by similarity
    $scored = [];
    foreach ($names as $name) {
        $score = similar_text(strtolower($term), strtolower($name));
        $scored[] = ['name' => $name, 'score' => $score];
    }

    // Sort by score descending, then name
    usort($scored, fn($a, $b) =>
        $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name'])
    );

    // Return just names
    $result = array_column(array_slice($scored, 0, 10), 'name');
    echo json_encode($result);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}