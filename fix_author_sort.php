<?php
require_once 'db.php';
requireLogin();
$pdo = getDatabaseConnection();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->query(
        "SELECT b.id, author_sort((SELECT a.name FROM authors a JOIN books_authors_link bal ON a.id=bal.author WHERE bal.book=b.id ORDER BY bal.id LIMIT 1)) AS expected\n         FROM books b\n         WHERE (b.author_sort IS NULL OR b.author_sort='' OR b.author_sort != author_sort((SELECT a.name FROM authors a JOIN books_authors_link bal ON a.id=bal.author WHERE bal.book=b.id ORDER BY bal.id LIMIT 1)))"
    );
    $count = 0;
    foreach ($stmt as $row) {
        $expected = trim($row['expected']);
        if ($expected === '') continue;
        $id = (int)$row['id'];
        $pdo->prepare('UPDATE books SET author_sort = :s WHERE id = :id')
            ->execute([':s' => $expected, ':id' => $id]);
        $count++;
    }
    $message = "$count book(s) updated.";
}
$query = "SELECT b.id, b.title, b.author_sort AS current,\n         author_sort((SELECT a.name FROM authors a JOIN books_authors_link bal\n                     ON a.id=bal.author WHERE bal.book=b.id ORDER BY bal.id LIMIT 1)) AS expected\n         FROM books b\n         WHERE (b.author_sort IS NULL OR b.author_sort='' OR\n                b.author_sort != author_sort((SELECT a.name FROM authors a JOIN books_authors_link bal\n                             ON a.id=bal.author WHERE bal.book=b.id ORDER BY bal.id LIMIT 1)))";
$bad = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Fix Author Sort</title>
  <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  <script src="theme.js"></script>
</head>
<body class="container py-4">
  <h1 class="mb-4">Fix Author Sort</h1>
  <?php if ($message): ?>
  <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($bad): ?>
  <form method="post">
    <table class="table">
      <thead>
        <tr><th>ID</th><th>Title</th><th>Current</th><th>Expected</th></tr>
      </thead>
      <tbody>
      <?php foreach ($bad as $b): ?>
      <tr>
        <td><?= (int)$b['id'] ?></td>
        <td><?= htmlspecialchars($b['title']) ?></td>
        <td><?= htmlspecialchars($b['current']) ?></td>
        <td><?= htmlspecialchars($b['expected']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit" class="btn btn-primary">Fix All</button>
  </form>
  <?php else: ?>
  <p>No books with bad author sort found.</p>
  <?php endif; ?>
</body>
</html>
