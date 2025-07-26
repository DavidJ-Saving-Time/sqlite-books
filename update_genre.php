<?php
header('Content-Type: application/json');
require_once 'db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = isset($_POST['value']) ? trim((string)$_POST['value']) : '';

if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    [$genreId, $valueTable, $linkTable] = ensureMultivalueColumn($pdo, 'genre');

    $pdo->prepare("DELETE FROM $linkTable WHERE book = :book")->execute([':book' => $bookId]);

    if ($value !== '') {
        $genreIdVal = (int)$value;
        $stmt = $pdo->prepare("SELECT id FROM $valueTable WHERE id = :id");
        $stmt->execute([':id' => $genreIdVal]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid genre']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :value)");
        $stmt->execute([':book' => $bookId, ':value' => $genreIdVal]);
    }

    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
