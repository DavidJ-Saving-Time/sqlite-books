<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../openlibrary.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['books' => []]);
    exit;
}

$books = search_openlibrary($q);
echo json_encode(['books' => $books]);
