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
} elseif ($action === 'new') {
    $title = '';
    $text  = '';
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

<style>
    /* Ensure TinyMCE doesn't leave an invisible gap before rendering */
    .tox-tinymce {
        visibility: visible !important;
        opacity: 1 !important;
        transition: none !important;
    }

    /* Optional: remove extra spacing if TinyMCE adds padding */
    .tox.tox-tinymce {
        margin-top: 0 !important;
    }

    </style>
<?php if ($id > 0 || $action === 'new'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof tinymce !== 'undefined') {
        tinymce.init({
            selector: '#noteEditor',
            license_key: 'gpl',
            promotion: false,
            branding: false,
            height: 600
        });
    } else {
        console.error("TinyMCE not loaded!");
    }
});
</script>
<?php endif; ?>
</head>
<body class="pt-5 bg-light">
    <?php include 'navbar_other.php'; ?>

    <div class="container my-4">
        <?php if ($id > 0 || $action === 'new'): ?>
           
            <form method="post" class="bg-white p-4 shadow rounded">
                <?php if ($id > 0): ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php endif; ?>

                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($title ?? '') ?>" required>
                </div>

                <div class="mb-3">
                    <label for="noteEditor" class="form-label">Note</label>
                    <textarea id="noteEditor" name="text"><?= htmlspecialchars($text ?? '') ?></textarea>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="notepad.php" class="btn btn-secondary">Back</a>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>

        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="m-0">Notepad</h1>
                <a class="btn btn-success" href="notepad.php?action=new">
                    <i class="fa-solid fa-plus me-1"></i> New Note
                </a>
            </div>

            <?php if (empty($notes)): ?>
                <p class="text-muted">No notes found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover bg-white shadow-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Last Edited</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notes as $n): ?>
                                <tr>
                                    <td><?= htmlspecialchars($n['title']) ?></td>
                                    <td><?= htmlspecialchars($n['last_edited']) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="notepad.php?id=<?= (int)$n['id'] ?>">Edit</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>