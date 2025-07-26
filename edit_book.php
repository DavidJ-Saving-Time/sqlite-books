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

$openLibCoverUrl = '';
$openLibExists = false;
if (!empty($book['isbn'])) {
    $openLibCoverUrl = 'https://covers.openlibrary.org/b/isbn/' . urlencode($book['isbn']) . '-L.jpg';
    $headers = @get_headers($openLibCoverUrl);
    if ($headers && strpos($headers[0], '200') !== false) {
        $openLibExists = true;
    }
}

$updated = false;
$errorMessage = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['use_openlibrary_cover'])) {
        if ($openLibExists) {
            $imgData = @file_get_contents($openLibCoverUrl);
            if ($imgData !== false) {
                $libraryPath = getLibraryPath();
                $destDir = $libraryPath . '/' . $book['path'];
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }
                file_put_contents($destDir . '/cover.jpg', $imgData);
                $pdo->prepare('UPDATE books SET has_cover = 1 WHERE id = ?')->execute([$id]);
                $updated = true;
            } else {
                $errorMessage = 'Failed to download cover from Open Library.';
            }
        } else {
            $errorMessage = 'Open Library cover not available.';
        }
        $stmt = $pdo->prepare('SELECT * FROM books WHERE id = ?');
        $stmt->execute([$id]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Book</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="theme.js"></script>
    <style>
        #description {
            min-height: 200px;
            resize: vertical; /* Allow user to resize */
        }
    </style>
</head>
<body style="margin-top: 100px">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="card-title mb-4">
                <i class="fa-solid fa-pen-to-square me-2"></i> Edit Book Metadata
            </h1>

            <?php if (!empty($updated)): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check me-2"></i> Book updated successfully
                </div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger">
                    <i class="fa-solid fa-circle-exclamation me-2"></i> <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="mb-3">
                <!-- Title -->
                <div class="mb-3">
                    <label for="title" class="form-label">
                        <i class="fa-solid fa-book me-1 text-primary"></i> Title
                    </label>
                    <input type="text" id="title" name="title" value="<?= htmlspecialchars($book['title']) ?>" class="form-control" required>
                </div>

                <!-- Description -->
                <div class="mb-3">
                    <label for="description" class="form-label">
                        <i class="fa-solid fa-align-left me-1 text-primary"></i> Description
                    </label>
                    <textarea id="description" name="description" class="form-control" rows="16"><?= htmlspecialchars($description) ?></textarea>
                </div>

                <!-- Cover Image Upload -->
                <div class="mb-3">
                    <label for="cover" class="form-label">
                        <i class="fa-solid fa-image me-1 text-primary"></i> Cover Image
                    </label>
                    <input type="file" id="cover" name="cover" class="form-control">
                </div>

                <!-- Existing Cover Preview -->
<?php if (!empty($book['has_cover'])): ?>
    <div class="mb-3">
        <p class="mb-1"><i class="fa-solid fa-eye me-1 text-success"></i> Current Cover:</p>
        <div class="position-relative d-inline-block">
            <img id="coverImagePreview"
                 src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg"
                 alt="Cover"
                 class="img-thumbnail shadow-sm"
                 style="max-width: 200px;">
            <div id="coverDimensions"
                 class="position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75"
                 style="font-size: 1.2rem;">
                 Loading...
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($openLibCoverUrl): ?>
    <div class="mb-3">
        <p class="mb-1"><i class="fa-solid fa-book-open me-1 text-info"></i> Open Library Cover:</p>
        <div class="d-flex align-items-start">
            <img src="<?= htmlspecialchars($openLibCoverUrl) ?>" alt="Open Library Cover" class="img-thumbnail shadow-sm" style="max-width: 200px;">
            <?php if ($openLibExists): ?>
                <form method="post" class="ms-3">
                    <input type="hidden" name="use_openlibrary_cover" value="1">
                    <button type="submit" class="btn btn-secondary">Use this</button>
                </form>
            <?php else: ?>
                <span class="ms-3 text-muted">Cover not available</span>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between">
                    <a href="list_books.php" class="btn btn-secondary">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to list
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const img = document.getElementById('coverImagePreview');
    const dimLabel = document.getElementById('coverDimensions');

    function updateDimensions() {
        if (img.naturalWidth && img.naturalHeight) {
            dimLabel.textContent = `${img.naturalWidth} × ${img.naturalHeight}px`;
        } else {
            dimLabel.textContent = 'No image data';
        }
    }

    if (img) {
        if (img.complete) {
            // Image already loaded from cache
            updateDimensions();
        } else {
            // Wait for image to load
            img.addEventListener('load', updateDimensions);
            img.addEventListener('error', () => {
                dimLabel.textContent = 'Image not found';
            });
        }
    }
});
</script>
</html>
