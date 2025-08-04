<?php
require_once __DIR__ . '/metadata_sources.php';

header('Content-Type: application/json');
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($query === '') {
    echo json_encode([]);
    exit;
}

$results = search_amazon_books($query, $error);
$response = ['books' => $results];
if ($error !== null) {
    $response['error'] = $error;
}
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
