<?php
/**
 * Searches Anna's Archive for books matching a query string.
 *
 * Expects an HTTP GET request.
 *
 * Query Parameters:
 * - q: Search terms.
 *
 * Returns:
 * {"books":array}
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../annas_archive.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['books' => []]);
    exit;
}

$books = search_annas_archive($q);
echo json_encode(['books' => $books]);
