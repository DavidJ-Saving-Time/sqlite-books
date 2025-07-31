<?php
require_once 'db.php';
requireLogin();
require_once 'google_books.php';

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sort = $_GET['sort'] ?? 'author_series';
$source = 'google';
$books = [];
if ($search !== '') {
    $books = search_google_books($search);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="manifest" href="manifest.json">
    <title>Google Books Results</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <script src="js/search.js"></script>
</head>
<body class="pt-5">
<?php include "navbar_other.php"; ?>
<div class="container">
    <!-- Header Row -->
    <div class="row fw-bold border-bottom pb-2 mb-3">
        <div class="col-md-3 col-12">Cover</div>
        <div class="col-md-5 col-12">Title</div>
        <div class="col-md-4 col-12">Author(s)</div>
    </div>

    <?php foreach ($books as $index => $book): ?>
        <?php $rowClass = $index % 2 === 0 ? 'bg-light' : 'bg-white'; ?>
        <div class="row mb-4 p-3 border rounded <?= $rowClass ?>">
            <!-- Cover -->
            <div class="col-md-3 col-12 text-center">
                <?php if (!empty($book['imgUrl'])): ?>
                    <img src="<?= htmlspecialchars($book['imgUrl']) ?>" alt="Cover" class="img-fluid img-thumbnail">
                <?php else: ?>
                    <span class="text-muted">&mdash;</span>
                <?php endif; ?>
            </div>
            
            <!-- Title and Author -->
            <div class="col-md-9 col-12">
                <div class="row mb-2">
                    <div class="col-md-5 col-12">
                        <h5 class="mb-1"><?= htmlspecialchars($book['title']) ?></h5>
                    </div>
                    <div class="col-md-7 col-12">
                        <p class="mb-0 text-muted">
                            <?= $book['author'] !== '' ? htmlspecialchars($book['author']) : '&mdash;' ?>
                        </p>
                    </div>
                </div>
                
                <!-- Description -->
                <div class="row">
                    <div class="col-12">
                        <p class="mb-0"><?= htmlspecialchars($book['description']) ?></p>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-12">
                        <button type="button" class="btn btn-sm btn-primary google-add"
                                data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
                                data-authors="<?= htmlspecialchars($book['author'], ENT_QUOTES) ?>"
                                data-thumbnail="<?= htmlspecialchars($book['imgUrl'], ENT_QUOTES) ?>"
                                data-description="<?= htmlspecialchars($book['description'], ENT_QUOTES) ?>">
                            Add to Library
                        </button>
                        <span class="google-add-result ms-1"></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/google_results.js"></script>
<script src="js/pwa.js"></script>
</body>
</html>
