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


<?php if ($goal): ?>
    <?php 
        $percent = min(100, (int)(($readCount / $goal) * 100));
        $milestones = [25, 50, 75, 100];
        $nextMilestone = null;
        foreach ($milestones as $m) {
            if ($percent < $m) { 
                $nextMilestone = $m; 
                break; 
            }
        }
        $booksForNextMilestone = $nextMilestone ? ceil(($nextMilestone * $goal / 100) - $readCount) : 0;
    ?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reading Challenge</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="theme.js"></script>
</head>
<body class="pt-5">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <h1 class="mb-4 d-flex align-items-center">
        <i class="fa-solid fa-flag-checkered me-2 text-success"></i> 
        Reading Challenge <?= htmlspecialchars((string)$year) ?>
    </h1>
    
    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check me-2"></i> 
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4 shadow-sm">
        <div class="card-body">
            <h5 class="card-title">
                <i class="fa-solid fa-book me-2"></i>
                You have read <strong><?= $readCount ?></strong><?= $goal ? " of <strong>{$goal}</strong>" : '' ?> books this year.
            </h5>
            <?php if ($goal): ?>
                <?php $percent = min(100, (int)(($readCount / $goal) * 100)); ?>
<!-- Progress Bar -->
    <div class="progress mb-4" style="height: 1.5rem;">
        <div class="progress-bar progress-bar-striped progress-bar-animated 
                    <?= $readCount >= $goal ? 'bg-success' : 'bg-info' ?>" 
             role="progressbar" 
             style="width: <?= $percent ?>%;" 
             aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
            <?= $percent ?>% (<?= $readCount ?>/<?= $goal ?>)
        </div>
    </div>

    <!-- Badges Row -->
    <div class="d-flex justify-content-around mb-4">
        <?php foreach ($milestones as $m): ?>
            <div class="text-center">
                <i class="fa-solid fa-medal fa-2x <?= $percent >= $m ? 'text-warning' : 'text-secondary' ?>"></i>
                <div class="small"><?= $m ?>%</div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Next Milestone -->
    <?php if ($readCount < $goal && $nextMilestone): ?>
        <div class="alert alert-info text-center">
            <i class="fa-solid fa-star me-1"></i>
            <?= $booksForNextMilestone ?> book<?= $booksForNextMilestone > 1 ? 's' : '' ?> until your <strong><?= $nextMilestone ?>%</strong> badge!
        </div>
    <?php else: ?>
        <div class="alert alert-success text-center">
            <i class="fa-solid fa-trophy me-1"></i>
            You've achieved all milestones—amazing job!
        </div>
    <?php endif; ?>
<?php endif; ?>
                <?php if ($readCount >= $goal): ?>
                    <div class="alert alert-success mb-0">
                        <i class="fa-solid fa-trophy me-2"></i>
                        Goal completed! Fantastic job!
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fa-solid fa-bolt me-2"></i>
                        Only <?= $goal - $readCount ?> more book<?= $goal - $readCount === 1 ? '' : 's' ?> to reach your goal.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info mb-0">
                    <i class="fa-solid fa-circle-info me-2"></i>
                    Set your yearly goal below to start tracking!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($goal): ?>
        <div class="row text-center mb-4">
            <div class="col-md-4 mb-2">
                <div class="card h-100">
                    <div class="card-body">
                        <i class="fa-solid fa-calendar-week fa-2x text-primary mb-2"></i>
                        <h6 class="fw-bold">Weekly Target</h6>
                        <p class="mb-0"><?= formatBooksOrPages($booksPerWeekGoal) ?>/week</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="card h-100">
                    <div class="card-body">
                        <i class="fa-solid fa-chart-line fa-2x text-info mb-2"></i>
                        <h6 class="fw-bold">Current Pace</h6>
                        <p class="mb-0"><?= formatBooksOrPages($booksPerWeekCurrent) ?>/week</p>
                        <span class="<?= $onTrack ? 'text-success' : 'text-danger' ?>">
                            <?= $onTrack ? 'On track!' : "Need " . formatBooksOrPages($booksPerWeekNeeded) . "/week" ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-2">
                <div class="card h-100">
                    <div class="card-body">
                        <i class="fa-solid fa-hourglass-half fa-2x text-warning mb-2"></i>
                        <h6 class="fw-bold">Days Left</h6>
                        <p class="mb-0"><?= $daysLeft ?> days</p>
                        <?php $dayLabel = $booksPerDayNeeded >= 1 ? 'Books/day needed:' : 'Pages/day needed:'; ?>
                        <small><?= $dayLabel ?> <strong><?= formatBooksOrPages($booksPerDayNeeded) ?></strong></small>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <div class="input-group">
            <label class="input-group-text" for="goal">
                <i class="fa-solid fa-bullseye me-1"></i> Yearly Goal
            </label>
            <input type="number" class="form-control" name="goal" id="goal" value="<?= htmlspecialchars((string)$goal) ?>" min="1">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-save me-1"></i> Save
            </button>
        </div>
    </form>

    <?php if (!empty($readBooks)): ?>
        <h2 class="h4 mb-3">
            <i class="fa-solid fa-bookmark me-2"></i>
            Books Read in <?= htmlspecialchars((string)$year) ?>
        </h2>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
            <?php foreach ($readBooks as $b): ?>
                <div class="col">
                    <div class="card h-100 shadow-sm">
                        <?php if (!empty($b['has_cover'])): ?>
                            <img src="<?= htmlspecialchars(getLibraryPath() . '/' . $b['path'] . '/cover.jpg') ?>" alt="Cover" class="card-img-top" style="height: 600px; object-fit: cover;">
                        <?php else: ?>
                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 600px;">
                                <i class="fa-solid fa-book fa-3x text-secondary"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?= htmlspecialchars($b['title']) ?></h5>
                            <p class="text-muted mb-2"><?= htmlspecialchars($b['authors']) ?></p>
                            <?php if (!empty($b['read_date'])): ?>
                                <p class="small text-success mb-2">
                                    <i class="fa-solid fa-check me-1"></i>
                                    Finished: <?= htmlspecialchars(date('M j, Y', strtotime($b['read_date']))) ?>
                                </p>
                            <?php endif; ?>
                            <div class="mt-auto">
                                <a href="book.php?id=<?= urlencode($b['id']) ?>" class="btn btn-outline-primary btn-sm me-2">
                                    <i class="fa-solid fa-eye"></i> View
                                </a>
                                <button class="btn btn-sm btn-danger remove-challenge" data-book-id="<?= htmlspecialchars($b['id']) ?>">
                                    <i class="fa-solid fa-trash"></i> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
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
