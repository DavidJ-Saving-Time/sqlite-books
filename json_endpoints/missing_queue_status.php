<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$inputFile = __DIR__ . '/../data/missing-books-results.txt';
$sentFile  = __DIR__ . '/../data/missing-books-sent.txt';

$all  = file_exists($inputFile)
    ? array_filter(array_map('trim', file($inputFile, FILE_IGNORE_NEW_LINES)), 'strlen')
    : [];
$sent = file_exists($sentFile)
    ? array_flip(array_filter(array_map('trim', file($sentFile, FILE_IGNORE_NEW_LINES)), 'strlen'))
    : [];

$pending = array_values(array_filter($all, fn($l) => !isset($sent[$l])));

echo json_encode([
    'total'   => count($all),
    'sent'    => count($sent),
    'pending' => count($pending),
]);
