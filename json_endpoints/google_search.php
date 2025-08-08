<?php
header('Content-Type: application/json');
require_once 'google_books.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['books' => []]);
    exit;
}

$books = search_google_books($q);
echo json_encode(['books' => $books]);
?>
