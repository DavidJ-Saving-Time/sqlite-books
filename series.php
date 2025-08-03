<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();
$series = $pdo->query('SELECT id, name FROM series ORDER BY sort')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Series</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body class="pt-5 bg-light">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1>Series</h1>
    <?php if (empty($series)): ?>
        <p class="text-muted">No series found.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($series as $s): ?>
                <li class="list-group-item">
                    <a href="list_books.php?series_id=<?= (int)$s['id'] ?>">
                        <?= htmlspecialchars($s['name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
