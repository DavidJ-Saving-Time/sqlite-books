<?php
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$pdo = getDatabaseConnection();
$authors = $pdo->query('SELECT id, name FROM authors ORDER BY sort')->fetchAll(PDO::FETCH_ASSOC);
$statuses = getCachedStatuses($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authors</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body class="pt-5 bg-light">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1>Authors</h1>
    <?php if (empty($authors)): ?>
        <p class="text-muted">No authors found.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($authors as $a): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <a href="list_books.php?author_id=<?= (int)$a['id'] ?>" class="flex-grow-1">
                        <?= htmlspecialchars($a['name']) ?>
                    </a>
                    <select class="form-select form-select-sm ms-2 author-status" data-author-id="<?= (int)$a['id'] ?>" style="width: auto;">
                        <option value="">Set status...</option>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= htmlspecialchars($s['value']) ?>"><?= htmlspecialchars($s['value']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm btn-danger ms-2 delete-author" data-author-id="<?= (int)$a['id'] ?>">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/authors.js"></script>
</body>
</html>
