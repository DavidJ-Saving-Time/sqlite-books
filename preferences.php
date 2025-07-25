<?php
require_once 'db.php';
requireLogin();

$message = '';
$alertClass = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPath = trim($_POST['db_path'] ?? '');
    if ($newPath !== '') {
        setUserPreference(currentUser(), 'db_path', $newPath);
        if (isset($_POST['save_global'])) {
            setPreference('db_path', $newPath);
        }

        $writable = file_exists($newPath)
            ? is_writable($newPath)
            : (is_dir(dirname($newPath)) && is_writable(dirname($newPath)));

        if ($writable) {
            $message = 'Preferences saved.';
        } else {
            $message = 'Database path is not writable.';
            $alertClass = 'danger';
        }
    }
}

$currentPath = currentDatabasePath();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Preferences</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-4">
  <h1 class="mb-4">Preferences</h1>
  <?php if ($message): ?>
    <div class="alert alert-<?php echo $alertClass; ?>"><?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label for="db_path" class="form-label">Calibre database path</label>
      <input type="text" id="db_path" name="db_path" class="form-control" value="<?php echo htmlspecialchars($currentPath); ?>">
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" value="1" id="save_global" name="save_global">
      <label class="form-check-label" for="save_global">
        Save as default for all users
      </label>
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</body>
</html>
