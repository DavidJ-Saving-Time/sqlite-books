<?php
header('Content-Type: application/json');
require_once 'annas_archive.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['books' => []]);
    exit;
}

$books = search_annas_archive($q);
echo json_encode(['books' => $books]);
