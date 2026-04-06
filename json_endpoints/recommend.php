<?php
/**
 * Generates book recommendations based on authors and title.
 *
 * Expects an HTTP GET request.
 *
 * Query Parameters:
 * - authors:     Optional author names.
 * - title:       Optional book title.
 * - genres:      Optional genres/tags for context.
 * - book_id:     Optional book ID to save recommendations to.
 *
 * Returns:
 * {"output":string} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/book_recommend.php';
require_once __DIR__ . '/../db.php';
requireLogin();

$authors = $_GET['authors'] ?? '';
$title   = $_GET['title']   ?? '';
$genres  = $_GET['genres']  ?? '';
$bookId  = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

if ($authors === '' && $title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$parts = array_filter([trim($title), trim($authors), trim($genres)]);
$userInput = implode(' — ', $parts);

try {
    $output = get_book_recommendations($userInput);

    if ($bookId > 0) {
        $pdo = getDatabaseConnection();

        $recId      = ensureSingleValueColumn($pdo, '#recommendation', 'Recommendation');
        $valueTable = "custom_column_{$recId}";
        $linkTable  = "books_custom_column_{$recId}_link";

        $pdo->beginTransaction();
        $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
            ->execute([':val' => $output]);
        $valId = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
        $valId->execute([':val' => $output]);
        $id = $valId->fetchColumn();
        $pdo->prepare("DELETE FROM $linkTable WHERE book = :book")->execute([':book' => $bookId]);
        $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)")
            ->execute([':book' => $bookId, ':val' => $id]);
        $pdo->commit();
    }

    echo json_encode(['output' => $output]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
