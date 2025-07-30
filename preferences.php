<?php
require_once 'db.php';
requireLogin();

$message = '';
$alertClass = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbPath = trim($_POST['db_path'] ?? '');
    $libPath = trim($_POST['library_path'] ?? '');

    if ($dbPath !== '') {
        setUserPreference(currentUser(), 'db_path', $dbPath);
        if (isset($_POST['save_global'])) {
            setPreference('db_path', $dbPath);
        }
    }

    if ($libPath !== '') {
        setUserPreference(currentUser(), 'library_path', $libPath);
        if (isset($_POST['save_global'])) {
            setPreference('library_path', $libPath);
        }
    }

    $dbWritable = $dbPath === '' ? true : (file_exists($dbPath)
        ? is_writable($dbPath)
        : (is_dir(dirname($dbPath)) && is_writable(dirname($dbPath))));
    $libWritable = $libPath === '' ? true : (is_dir($libPath) && is_writable($libPath));

    if ($dbWritable && $libWritable) {
        $message = 'Preferences saved.';
    } else {
        $alertClass = 'danger';
        if (!$dbWritable) {
            $message = 'Database path is not writable.';
        } elseif (!$libWritable) {
            $message = 'Library path is not writable.';
        }
    }
}

$currentPath = currentDatabasePath();
$currentLibrary = getLibraryPath();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Preferences</title>
  <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  <script src="js/theme.js"></script>
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
  <div class="mb-3">
    <label for="library_path" class="form-label">Calibre library path</label>
    <input type="text" id="library_path" name="library_path" class="form-control" value="<?php echo htmlspecialchars($currentLibrary); ?>">
  </div>
  <div class="mb-3">
    <label for="themeSelect" class="form-label">Theme</label>
    <select id="themeSelect" class="form-select" style="max-width: 20rem;"></select>
    <div class="form-text">Saved locally in this browser</div>
  </div>
  <div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" value="1" id="save_global" name="save_global">
    <label class="form-check-label" for="save_global">
      Save as default for all users
      </label>
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
    <a href="fix_author_sort.php" class="btn btn-secondary ms-2">Fix Author Sort</a>
  </form>
</body>
</html>
