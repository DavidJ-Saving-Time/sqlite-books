<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();
$bookId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($bookId <= 0) {
    echo 'Invalid book id';
    exit;
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notesInput = trim($_POST['notes'] ?? '');
    try {
        $notesId = ensureSingleValueColumn($pdo, '#notes', 'Notes');
        $valTable  = "custom_column_{$notesId}";
        $linkTable = "books_custom_column_{$notesId}_link";
        $pdo->prepare("DELETE FROM $linkTable WHERE book = :book")
            ->execute([':book' => $bookId]);
        if ($notesInput !== '') {
            $pdo->prepare("INSERT OR IGNORE INTO $valTable (value) VALUES (:val)")
                ->execute([':val' => $notesInput]);
            $valStmt = $pdo->prepare("SELECT id FROM $valTable WHERE value = :val");
            $valStmt->execute([':val' => $notesInput]);
            $valId = $valStmt->fetchColumn();
            if ($valId !== false) {
                $pdo->prepare("INSERT INTO $linkTable (book, value) VALUES (:book, :val)")
                    ->execute([':book' => $bookId, ':val' => $valId]);
            }
        }
    } catch (PDOException $e) {
        // Ignore errors updating notes
    }
    header('Location: book.php?id=' . $bookId);
    exit;
}

// Fetch book title
$stmt = $pdo->prepare('SELECT title FROM books WHERE id = ?');
$stmt->execute([$bookId]);
$bookTitle = $stmt->fetchColumn();
if ($bookTitle === false) $bookTitle = '';

// Fetch existing notes
$notes = '';
try {
    $notesId = ensureSingleValueColumn($pdo, '#notes', 'Notes');
    $valTable  = "custom_column_{$notesId}";
    $linkTable = "books_custom_column_{$notesId}_link";
    $noteStmt = $pdo->prepare("SELECT v.value FROM $linkTable l JOIN $valTable v ON l.value = v.id WHERE l.book = ?");
    $noteStmt->execute([$bookId]);
    $notes = $noteStmt->fetchColumn() ?: '';
} catch (PDOException $e) {
    $notes = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Notes - <?= htmlspecialchars($bookTitle) ?></title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <script src="node_modules/tinymce/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
    tinymce.init({
        selector: '#notesEditor',
        license_key: 'gpl',
        promotion: false,
        branding: false,
        height: 600
    });
    </script>
</head>
<body class="pt-5">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1 class="mb-4">Edit Notes - <?= htmlspecialchars($bookTitle) ?></h1>
    <form method="post">
        <textarea id="notesEditor" name="notes" style="max-width:1000px;">
<?= htmlspecialchars($notes) ?>
        </textarea>
        <div class="mt-3">
            <a href="book.php?id=<?= (int)$bookId ?>" class="btn btn-secondary me-2">Cancel</a>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
