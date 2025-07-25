<?php
require_once 'db.php';
$user = requireLogin();

$pdo = getDatabaseConnection();

$year = (int)date('Y');
$message = '';

// Approximate pages in a book used when converting fractional book counts
const PAGES_PER_BOOK = 450;

/**
 * Format a book count as either books or pages depending on size.
 *
 * @param float $booksPerUnit Books per time unit (week/day/etc.)
 * @return string Human readable count with units
 */
function formatBooksOrPages(float $booksPerUnit): string {
    if ($booksPerUnit >= 1 || $booksPerUnit <= 0) {
        return number_format($booksPerUnit, 2) . ' books';
    }
    $pages = round($booksPerUnit * PAGES_PER_BOOK);
    return $pages . ' pages';
}

try {
    // Ensure tables exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_challenges (year INTEGER PRIMARY KEY, goal INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reading_log (book INTEGER PRIMARY KEY REFERENCES books(id) ON DELETE CASCADE, year INTEGER, read_date TEXT)");
    $cols = $pdo->query("PRAGMA table_info(reading_log)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('read_date', $cols, true)) {
        $pdo->exec("ALTER TABLE reading_log ADD COLUMN read_date TEXT");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $goal = isset($_POST['goal']) ? (int)$_POST['goal'] : 0;
        if ($goal > 0) {
            $stmt = $pdo->prepare('REPLACE INTO reading_challenges (year, goal) VALUES (:year, :goal)');
            $stmt->execute([':year' => $year, ':goal' => $goal]);
            setUserPreference($user, 'reading_goal_' . $year, $goal);
            $message = 'Goal saved.';
        }
    }

    $goalStmt = $pdo->prepare('SELECT goal FROM reading_challenges WHERE year = :year');
    $goalStmt->execute([':year' => $year]);
    $goal = $goalStmt->fetchColumn();
    if ($goal === false) {
        $prefGoal = getUserPreference($user, 'reading_goal_' . $year);
        if ($prefGoal) {
            $goal = (int)$prefGoal;
        }
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reading_log WHERE year = :year');
    $countStmt->execute([':year' => $year]);
    $readCount = (int)$countStmt->fetchColumn();

    $booksStmt = $pdo->prepare(
        "SELECT b.id, b.title, b.path, b.has_cover, rl.read_date,
            (SELECT GROUP_CONCAT(a.name, ', ')
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

    $weeksInYear = (int)date('W', strtotime($year . '-12-28'));
    $currentWeek = (int)date('W');
    $booksPerWeekGoal = $goal ? $goal / $weeksInYear : 0;
    $booksPerWeekCurrent = $currentWeek > 0 ? $readCount / $currentWeek : 0;
    $expectedByNow = $goal ? ($booksPerWeekGoal * $currentWeek) : 0;
    $onTrack = $goal ? ($readCount >= $expectedByNow) : false;
    $remainingWeeks = max(0, $weeksInYear - $currentWeek);
    $booksPerWeekNeeded = $remainingWeeks > 0 ? ($goal - $readCount) / $remainingWeeks : 0;
    $today = new DateTime();
    $endOfYear = new DateTime($year . '-12-31');
    $daysLeft = (int)$today->diff($endOfYear)->format('%a') + 1;
    $booksPerDayNeeded = $daysLeft > 0 ? ($goal - $readCount) / $daysLeft : 0;
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
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="theme.js"></script>
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
        <div class="progress mb-3">
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
    <?php if ($goal): ?>
        <div class="mb-3">
            <strong>Weekly target:</strong> <?= formatBooksOrPages($booksPerWeekGoal) ?>/week<br>
            <strong>Current pace:</strong> <?= formatBooksOrPages($booksPerWeekCurrent) ?>/week<br>
            <?php if ($onTrack): ?>
                <span class="text-success">You are on track to meet your goal!</span>
            <?php else: ?>
                <span class="text-danger">You need about <?= formatBooksOrPages($booksPerWeekNeeded) ?>/week to catch up.</span>
            <?php endif; ?>
            <br>
            <strong>Days left:</strong> <?= $daysLeft ?>,
            <?php $dayLabel = $booksPerDayNeeded >= 1 ? 'Books/day needed:' : 'Pages/day needed:'; ?>
            <strong><?= $dayLabel ?></strong> <?= formatBooksOrPages($booksPerDayNeeded) ?>/day
        </div>
    <?php endif; ?>
    <form method="post" class="mb-3">
        <div class="input-group" >
            <label class="input-group-text" for="goal">Yearly Goal</label>
            <input type="number" class="form-control" name="goal" id="goal" value="<?= htmlspecialchars((string)$goal) ?>" min="1">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>

    <?php if (!empty($readBooks)): ?>
        <h2 class="h4 mt-4">Books Read in <?= htmlspecialchars((string)$year) ?></h2>
        <table class="table table-striped" >
            <thead>
            <tr>
                <th>Cover</th>
                <th>Title</th>
                <th>Author(s)</th>
                <th>Finished</th>
                <th>Remove</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($readBooks as $b): ?>
                <tr>
                    <td>
                        <?php if (!empty($b['has_cover'])): ?>
                            <a href="view_book.php?id=<?= urlencode($b['id']) ?>">
                                <img src="ebooks/<?= htmlspecialchars($b['path']) ?>/cover.jpg" alt="Cover" class="img-thumbnail" style="width: 80px; height: auto;">
                            </a>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><a href="view_book.php?id=<?= urlencode($b['id']) ?>"><?= htmlspecialchars($b['title']) ?></a></td>
                    <td><?= htmlspecialchars($b['authors']) ?></td>
                    <td>
                        <?php if (!empty($b['read_date'])): ?>
                            <?= htmlspecialchars(date('M j, Y', strtotime($b['read_date']))) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-danger remove-challenge" data-book-id="<?= htmlspecialchars($b['id']) ?>">Remove</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
document.querySelectorAll('.remove-challenge').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.getAttribute('data-book-id');
        fetch('update_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: id, value: 'Read' })
        }).then(function() { location.reload(); });
    });
});
</script>
</body>
</html>
