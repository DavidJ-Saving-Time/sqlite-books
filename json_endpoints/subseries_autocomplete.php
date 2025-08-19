<?php
/**
 * @endpoint GET /api/autocomplete_subseries
 * @description Fuzzy autocomplete for subseries names, using substring match and similarity scoring.
 *
 * @params
 *     - term (string, required): Partial subseries name to search
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
    $subseriesColumnId = getCustomColumnId($pdo, 'subseries');
    if ($subseriesColumnId) {
        $valueTable = "custom_column_{$subseriesColumnId}";
        $stmt = $pdo->prepare("SELECT value AS name FROM $valueTable WHERE value LIKE :term COLLATE NOCASE");
    } else {
        $stmt = $pdo->prepare('SELECT name FROM subseries WHERE name LIKE :term COLLATE NOCASE');
    }
    $stmt->execute([':term' => '%' . $term . '%']);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $scored = [];
    foreach ($names as $name) {
        $score = similar_text(strtolower($term), strtolower($name));
        $scored[] = ['name' => $name, 'score' => $score];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));
    $result = array_column(array_slice($scored, 0, 10), 'name');
    echo json_encode($result);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
