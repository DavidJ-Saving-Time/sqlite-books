<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

$year = (int)date('Y');
$message = '';

try {
    // Ensure tables exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_challenges (year INTEGER PRIMARY KEY, goal INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_log (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, year INTEGER)");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $goal = isset($_POST['goal']) ? (int)$_POST['goal'] : 0;
        if ($goal > 0) {
            $stmt = $pdo->prepare('REPLACE INTO reading_challenges (year, goal) VALUES (:year, :goal)');
            $stmt->execute([':year' => $year, ':goal' => $goal]);
            $message = 'Goal saved.';
        }
    }

    $goalStmt = $pdo->prepare('SELECT goal FROM reading_challenges WHERE year = :year');
    $goalStmt->execute([':year' => $year]);
    $goal = $goalStmt->fetchColumn();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reading_log WHERE year = :year');
    $countStmt->execute([':year' => $year]);
    $readCount = (int)$countStmt->fetchColumn();

    $booksStmt = $pdo->prepare(
        "SELECT b.id, b.title, (SELECT GROUP_CONCAT(a.name, ', ')
            FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS authors
         FROM reading_log rl
         JOIN books b ON rl.book = b.id
         WHERE rl.year = :year
         ORDER BY b.title"
    );
    $booksStmt->execute([':year' => $year]);
    $readBooks = $booksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reading Challenge</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
</head>
<body class="pt-5">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <h1 class="mb-4">Reading Challenge <?= htmlspecialchars((string)$year) ?></h1>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <p>You have read <?= $readCount ?><?= $goal ? ' of ' . $goal : '' ?> books this year.</p>
    <?php if ($goal): ?>
        <?php $percent = min(100, (int)(($readCount / $goal) * 100)); ?>
        <div class="progress mb-3" style="max-width: 20rem;">
            <div class="progress-bar" role="progressbar" style="width: <?= $percent ?>%;" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                <?= $percent ?>%
            </div>
        </div>
        <?php if ($readCount >= $goal): ?>
            <div class="alert alert-success">Goal completed! Great job!</div>
        <?php else: ?>
            <div class="mb-3">Only <?= $goal - $readCount ?> more book<?= $goal - $readCount === 1 ? '' : 's' ?> to reach your goal.</div>
        <?php endif; ?>
    <?php endif; ?>
    <form method="post" class="mb-3">
        <div class="input-group" style="max-width: 20rem;">
            <label class="input-group-text" for="goal">Yearly Goal</label>
            <input type="number" class="form-control" name="goal" id="goal" value="<?= htmlspecialchars((string)$goal) ?>" min="1">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>

    <?php if (!empty($readBooks)): ?>
        <h2 class="h4 mt-4">Books Read in <?= htmlspecialchars((string)$year) ?></h2>
        <table class="table table-striped" style="max-width: 40rem;">
            <thead>
            <tr>
                <th>Title</th>
                <th>Author(s)</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($readBooks as $b): ?>
                <tr>
                    <td><a href="view_book.php?id=<?= urlencode($b['id']) ?>"><?= htmlspecialchars($b['title']) ?></a></td>
                    <td><?= htmlspecialchars($b['authors']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
