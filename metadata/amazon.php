<?php
require_once __DIR__ . '/metadata_sources.php';

header('Content-Type: application/json');
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($query === '') {
    echo json_encode([]);
    exit;
}

$results = search_amazon_books($query);
echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
