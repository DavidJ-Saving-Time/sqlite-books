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
    <form method="post" class="mb-3">
        <div class="input-group" style="max-width: 20rem;">
            <label class="input-group-text" for="goal">Yearly Goal</label>
            <input type="number" class="form-control" name="goal" id="goal" value="<?= htmlspecialchars((string)$goal) ?>" min="1">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
