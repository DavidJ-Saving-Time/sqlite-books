<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid book ID');
}

$stmt = $pdo->prepare('SELECT * FROM books WHERE id = ?');
$stmt->execute([$id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    die('Book not found');
}
$commentStmt = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$commentStmt->execute([$id]);
$description = $commentStmt->fetchColumn() ?: '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $descriptionInput = trim($_POST['description'] ?? '');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE books SET title = ?, last_modified = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$title, $id]);

    if ($descriptionInput !== '') {
        $descStmt = $pdo->prepare('INSERT INTO comments (book, text) VALUES (:book, :text) '
            . 'ON CONFLICT(book) DO UPDATE SET text = excluded.text');
        $descStmt->execute([':book' => $id, ':text' => $descriptionInput]);
    } else {
        $pdo->prepare('DELETE FROM comments WHERE book = ?')->execute([$id]);
    }

    if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
        $libraryPath = getLibraryPath();
        $destDir = $libraryPath . '/' . $book['path'];
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $destFile = $destDir . '/cover.jpg';
        move_uploaded_file($_FILES['cover']['tmp_name'], $destFile);
        $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = ?')->execute([$id]);
    }

    $pdo->commit();
    $updated = true;

    $stmt = $pdo->prepare('SELECT * FROM books WHERE id = ?');
    $stmt->execute([$id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    $description = $descriptionInput;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Book</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="theme.js"></script>
</head>
<body>
<?php include "navbar.php"; ?>
<div class="container my-4">
    <h1 class="mb-4">Edit Book Metadata</h1>
    <?php if (!empty($updated)): ?>
        <div class="alert alert-success">Book updated successfully</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="mb-3">
        <div class="mb-3">
            <label for="title" class="form-label">Title</label>
            <input type="text" id="title" name="title" value="<?= htmlspecialchars($book['title']) ?>" class="form-control">
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea id="description" name="description" class="form-control" rows="4"><?= htmlspecialchars($description) ?></textarea>
        </div>
        <div class="mb-3">
            <label for="cover" class="form-label">Cover Image</label>
            <input type="file" id="cover" name="cover" class="form-control">
        </div>
        <?php if (!empty($book['has_cover'])): ?>
            <div class="mb-3">
                <img src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg" alt="Cover" style="max-width:150px" class="img-thumbnail">
            </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="list_books.php" class="btn btn-secondary">Back to list</a>
    </form>
    <h2>Metadata</h2>
    <table class="table table-bordered">
        <tr><th>ID</th><td><?= htmlspecialchars($book['id']) ?></td></tr>
        <tr><th>Title</th><td><?= htmlspecialchars($book['title']) ?></td></tr>
        <tr><th>Sort</th><td><?= htmlspecialchars($book['sort']) ?></td></tr>
        <tr><th>Timestamp</th><td><?= htmlspecialchars($book['timestamp']) ?></td></tr>
        <tr><th>Pubdate</th><td><?= htmlspecialchars($book['pubdate']) ?></td></tr>
        <tr><th>Author Sort</th><td><?= htmlspecialchars($book['author_sort']) ?></td></tr>
        <tr><th>ISBN</th><td><?= htmlspecialchars($book['isbn']) ?></td></tr>
        <tr><th>LCCN</th><td><?= htmlspecialchars($book['lccn']) ?></td></tr>
        <tr><th>Path</th><td><?= htmlspecialchars($book['path']) ?></td></tr>
        <tr><th>Flags</th><td><?= htmlspecialchars($book['flags']) ?></td></tr>
        <tr><th>UUID</th><td><?= htmlspecialchars($book['uuid']) ?></td></tr>
        <tr><th>Has Cover</th><td><?= htmlspecialchars($book['has_cover']) ?></td></tr>
        <tr><th>Last Modified</th><td><?= htmlspecialchars($book['last_modified']) ?></td></tr>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"  crossorigin="anonymous"></script>
</body>
</html>
