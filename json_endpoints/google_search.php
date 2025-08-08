<?php
/**
 * Searches the Google Books API for titles matching a query string.
 *
 * Expects an HTTP GET request.
 *
 * Query Parameters:
 * - q: The search terms to send to Google Books.
 *
 * Returns a JSON object of the form:
 * {
 *   "books": [ ... ]
 * }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../google_books.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['books' => []]);
    exit;
}

$books = search_google_books($q);
echo json_encode(['books' => $books]);
?>
