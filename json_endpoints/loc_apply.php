<?php
/**
 * Apply LOC catalog data to a specific book.
 * Called from the review queue when the user clicks Accept.
 *
 * POST JSON: { book_id, lccn, lcc, isbns[], subjects[], genres[], loc_checked_date }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
requireLogin();

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['book_id'])) {
    echo json_encode(['success' => false, 'error' => 'book_id required']);
    exit;
}

$bookId = (int)$body['book_id'];
$lccn   = trim($body['lccn']   ?? '');
$lcc    = trim($body['lcc']    ?? '');
$isbns  = (array)($body['isbns']    ?? []);
$subjs  = (array)($body['subjects'] ?? []);
$genres = (array)($body['genres']   ?? []);
$today  = date('Y-m-d');

function normIsbn(string $s): string {
    return preg_replace('/[^0-9X]/i', '', strtoupper(trim($s)));
}

try {
    $pdo = getDatabaseConnection();

    $ins = $pdo->prepare("INSERT OR IGNORE INTO identifiers (book, type, val) VALUES (?, ?, ?)");
    $upd = $pdo->prepare("INSERT OR REPLACE INTO identifiers (book, type, val) VALUES (?, ?, ?)");

    if ($lccn !== '') $upd->execute([$bookId, 'lccn', $lccn]);
    if ($lcc  !== '') $upd->execute([$bookId, 'lcc',  $lcc]);

    foreach ($isbns as $isbn) {
        $n = normIsbn($isbn);
        if (strlen($n) >= 10) $ins->execute([$bookId, 'isbn', $n]);
    }

    if (!empty($subjs)) {
        $upd->execute([$bookId, 'loc_subjects', implode(', ', array_slice($subjs, 0, 10))]);
    }

    if (!empty($genres)) {
        $meaningful = array_filter($genres, fn($g) => strlen($g) > 6 && strtolower($g) !== 'fiction' && strtolower($g) !== 'text');
        if ($meaningful) {
            $upd->execute([$bookId, 'loc_genres', implode(', ', array_values($meaningful))]);
        }
    }

    $upd->execute([$bookId, 'loc_checked', $today]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
