<?php
require_once 'db.php';
requireLogin();

$pdo = getDatabaseConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = trim($_POST['title'] ?? '');
    $text  = $_POST['text'] ?? '';
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE notepad SET title = :title, text = :text, last_edited = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':title' => $title, ':text' => $text, ':id' => $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO notepad (title, text) VALUES (:title, :text)');
        $stmt->execute([':title' => $title, ':text' => $text]);
        $id = (int)$pdo->lastInsertId();
    }
    header('Location: notepad.php?id=' . $id);
    exit;
}

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM notepad WHERE id = ?');
    $stmt->execute([$id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) {
        header('Location: notepad.php');
        exit;
    }
    $title = $note['title'];
    $text  = $note['text'];
} else {
    $notes = $pdo->query('SELECT id, title, time, last_edited FROM notepad ORDER BY last_edited DESC')->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notepad</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
    <script src="node_modules/tinymce/tinymce.min.js" referrerpolicy="origin"></script>
    <?php if ($id > 0 || $action === 'new'): ?>
    <script>
    tinymce.init({
        selector: '#noteEditor',
        license_key: 'gpl',
        promotion: false,
        branding: false,
        height: 600
    });
    </script>
    <?php endif; ?>
</head>
<body class="pt-5">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
<?php if ($id > 0 || $action === 'new'): ?>
    <h1 class="mb-4"><?= $id > 0 ? 'Edit Note' : 'New Note' ?></h1>
    <form method="post">
        <?php if ($id > 0): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>
        <div class="mb-3" style="max-width:1000px;">
            <label for="title" class="form-label">Title</label>
            <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($title ?? '') ?>" required>
        </div>
        <textarea id="noteEditor" name="text" style="max-width:1000px;"><?= htmlspecialchars($text ?? '') ?></textarea>
        <div class="mt-3">
            <a href="notepad.php" class="btn btn-secondary me-2">Back</a>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="m-0">Notepad</h1>
        <a class="btn btn-success" href="notepad.php?action=new"><i class="fa-solid fa-plus me-1"></i> New Note</a>
    </div>
    <?php if (empty($notes)): ?>
        <p>No notes found.</p>
    <?php else: ?>
        <table class="table table-striped" style="max-width:1000px;">
            <thead>
                <tr><th>Title</th><th>Last Edited</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($notes as $n): ?>
                <tr>
                    <td><?= htmlspecialchars($n['title']) ?></td>
                    <td><?= htmlspecialchars($n['last_edited']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-primary" href="notepad.php?id=<?= (int)$n['id'] ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
