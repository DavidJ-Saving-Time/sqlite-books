<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../metadata/metadata_sources.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['books' => []]);
    exit;
}

$books = search_amazon_books($q, $error);
$response = ['books' => $books];
if ($error !== null) {
    $response['error'] = $error;
}
echo json_encode($response);
