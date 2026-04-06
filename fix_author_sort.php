<?php
require_once 'db.php';
require_once 'cache.php';
requireLogin();
$pdo = getDatabaseConnection();
$message = '';
$alertClass = 'info';

// Subexpression: all authors for a book joined in insertion order.
// author_sort() already handles ' & '-separated strings.
$allAuthorsExpr = "(SELECT GROUP_CONCAT(name, ' & ') FROM "
    . "(SELECT a.name FROM authors a "
    . "JOIN books_authors_link bal ON a.id = bal.author "
    . "WHERE bal.book = b.id ORDER BY bal.id))";

$badQuery = "SELECT b.id, b.title, b.author_sort AS current,
     author_sort($allAuthorsExpr) AS expected
     FROM books b
     WHERE (b.author_sort IS NULL OR b.author_sort = '' OR
            b.author_sort != author_sort($allAuthorsExpr))";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fixAll      = isset($_POST['fix_all']);
    $selectedIds = array_map('intval', (array)($_POST['book_ids'] ?? []));

    if (!$fixAll && empty($selectedIds)) {
        $message    = 'No books selected.';
        $alertClass = 'warning';
    } else {
        $rows = $pdo->query($badQuery)->fetchAll(PDO::FETCH_ASSOC);

        $logDir  = __DIR__ . '/logs';
        $logFile = $logDir . '/author_sort.log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $count = 0;
        $pdo->beginTransaction();
        try {
            $updateBook = $pdo->prepare('UPDATE books SET author_sort = :s WHERE id = :id');
            $ts   = date('Y-m-d H:i:s');
            $user = currentUser();

            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if (!$fixAll && !in_array($id, $selectedIds, true)) {
                    continue;
                }

                $expected = trim($row['expected'] ?? '');
                if ($expected === '') {
                    continue;
                }

                file_put_contents(
                    $logFile,
                    "[$ts] user=$user book_id=$id \"{$row['current']}\" -> \"$expected\"\n",
                    FILE_APPEND | LOCK_EX
                );

                $updateBook->execute([':s' => $expected, ':id' => $id]);
                $count++;
            }

            // Also repair authors.sort — the per-author sort key used in Calibre's author browser
            $pdo->exec(
                "UPDATE authors SET sort = author_sort(name)
                 WHERE sort IS NULL OR sort = '' OR sort != author_sort(name)"
            );

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $message    = 'Error during update: ' . htmlspecialchars($e->getMessage());
            $alertClass = 'danger';
        }

        if ($alertClass !== 'danger') {
            invalidateCache('books');
            $noun    = $count === 1 ? 'book' : 'books';
            $message = "$count $noun updated.";
        }
    }
}

$bad = $pdo->query($badQuery)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Fix Author Sort</title>
  <link rel="stylesheet" href="/theme.css.php">
</head>
<body class="container py-4">
  <h1 class="mb-4">Fix Author Sort</h1>
  <?php if ($message): ?>
  <div class="alert alert-<?= $alertClass ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($bad): ?>
  <p class="text-muted small mb-3">
    <?= count($bad) ?> book<?= count($bad) !== 1 ? 's' : '' ?> where
    <code>books.author_sort</code> doesn't match the computed sort key.
    Select the rows you want to fix, or use <strong>Fix All</strong>.
    Changes are logged to <code>logs/author_sort.log</code>.
  </p>
  <form method="post">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>
            <input type="checkbox" id="selectAll"
              onchange="document.querySelectorAll('.row-cb').forEach(cb => cb.checked = this.checked)">
          </th>
          <th>ID</th><th>Title</th><th>Current</th><th>Expected</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bad as $b): ?>
      <tr>
        <td><input class="row-cb" type="checkbox" name="book_ids[]" value="<?= (int)$b['id'] ?>"></td>
        <td><?= (int)$b['id'] ?></td>
        <td><?= htmlspecialchars($b['title']) ?></td>
        <td class="text-danger font-monospace small"><?= htmlspecialchars($b['current'] ?? '') ?></td>
        <td class="text-success font-monospace small"><?= htmlspecialchars($b['expected'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit" name="fix_selected" value="1" class="btn btn-primary">Fix Selected</button>
    <button type="submit" name="fix_all" value="1" class="btn btn-warning ms-2"
            onclick="return confirm('Fix all <?= count($bad) ?> book(s)?')">Fix All</button>
  </form>
  <?php else: ?>
  <p>No books with bad author sort found.</p>
  <?php endif; ?>
</body>
</html>
