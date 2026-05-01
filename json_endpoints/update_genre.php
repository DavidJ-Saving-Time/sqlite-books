<?php
/**
 * Sets the genre for a specific book.
 *
 * Expects an HTTP POST request.
 *
 * POST Parameters:
 * - book_id: ID of the book.
 * - value: Genre name to apply; empty to clear.
 *
 * Returns:
 * {"status":"ok"} on success
 * or {"error":"message"} on failure.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;
$value = isset($_POST['value']) ? trim((string)$_POST['value']) : '';

if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

// Optional: apply to all books by these author IDs as well
$authorIds = [];
if (!empty($_POST['author_ids'])) {
    foreach (explode(',', (string)$_POST['author_ids']) as $aid) {
        $aid = (int)trim($aid);
        if ($aid > 0) $authorIds[] = $aid;
    }
}

$pdo = getDatabaseConnection();

/**
 * Set the genre for a single book (clears existing genre first).
 */
function applyGenreToBook(PDO $pdo, string $linkTable, string $valueTable, int $bookId, string $value): void {
    $pdo->prepare("DELETE FROM $linkTable WHERE book = :book")->execute([':book' => $bookId]);
    if ($value !== '') {
        $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES (:val)")
            ->execute([':val' => $value]);
        $valId = $pdo->prepare("SELECT id FROM $valueTable WHERE value = :val");
        $valId->execute([':val' => $value]);
        $valId = $valId->fetchColumn();
        $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)")
            ->execute([':book' => $bookId, ':val' => $valId]);
    }
}

try {
    $genreId = ensureMultiValueColumn($pdo, '#genre', 'Genre');
    $valueTable = "custom_column_{$genreId}";
    $linkTable = "books_custom_column_{$genreId}_link";

    // Apply to the specific book
    applyGenreToBook($pdo, $linkTable, $valueTable, $bookId, $value);

    $updated = 1;

    // Apply to all books by the given authors (excluding the book already updated)
    if ($authorIds) {
        $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
        $otherBooks = $pdo->prepare(
            "SELECT DISTINCT bal.book FROM books_authors_link bal
             WHERE bal.author IN ($placeholders) AND bal.book != ?"
        );
        $otherBooks->execute([...$authorIds, $bookId]);
        foreach ($otherBooks->fetchAll(PDO::FETCH_COLUMN) as $otherId) {
            applyGenreToBook($pdo, $linkTable, $valueTable, (int)$otherId, $value);
            $updated++;
        }
    }

    echo json_encode(['status' => 'ok', 'updated' => $updated]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
