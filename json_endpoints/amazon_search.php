<?php
/**
 * Searches Amazon for books matching a query.
 *
 * Expects an HTTP GET request.
 *
 * Query Parameters:
 * - q: Search terms.
 *
 * Returns:
 * {"books":array,"error":string?}
 * where "error" is optional if the search fails.
 */
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
