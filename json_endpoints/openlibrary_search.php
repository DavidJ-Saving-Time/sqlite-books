<?php
/**
 * Searches Open Library for books matching a query string.
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
require_once __DIR__ . '/../openlibrary.php';

$olid = trim($_GET['olid'] ?? '');
$isbn = trim($_GET['isbn'] ?? '');
$q    = trim($_GET['q']    ?? '');

if ($olid !== '') {
    $books = fetch_openlibrary_by_olid($olid);
} elseif ($isbn !== '') {
    $books = search_openlibrary_isbn($isbn);
    if (empty($books) && $q !== '') {
        $books = search_openlibrary($q);
    }
} elseif ($q !== '') {
    $books = search_openlibrary($q);
} else {
    echo json_encode(['books' => []]);
    exit;
}

echo json_encode(['books' => $books]);
