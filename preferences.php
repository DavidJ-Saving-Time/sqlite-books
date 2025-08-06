<?php
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$message = '';
$alertClass = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear_cache'])) {
        clearUserCache();
        $message = 'Cache cleared.';
    } else {
        $dbPath = trim($_POST['db_path'] ?? '');
        $libPath = trim($_POST['library_path'] ?? '');
        $remoteDir = trim($_POST['REMOTE_DIR'] ?? '');
        $device = trim($_POST['DEVICE'] ?? '');

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

        if ($remoteDir !== '') {
            setUserPreference(currentUser(), 'REMOTE_DIR', $remoteDir);
            if (isset($_POST['save_global'])) {
                setPreference('REMOTE_DIR', $remoteDir);
            }
        }

        if ($device !== '') {
            setUserPreference(currentUser(), 'DEVICE', $device);
            if (isset($_POST['save_global'])) {
                setPreference('DEVICE', $device);
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
}

$currentPath = currentDatabasePath();
$currentLibrary = getLibraryPath();
$currentRemoteDir = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));
$currentDevice = getUserPreference(currentUser(), 'DEVICE', getPreference('DEVICE', ''));
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
    <label for="REMOTE_DIR" class="form-label">Remote directory</label>
    <input type="text" id="REMOTE_DIR" name="REMOTE_DIR" class="form-control" value="<?php echo htmlspecialchars($currentRemoteDir); ?>">
  </div>
  <div class="mb-3">
    <label for="DEVICE" class="form-label">Device</label>
    <input type="text" id="DEVICE" name="DEVICE" class="form-control" value="<?php echo htmlspecialchars($currentDevice); ?>">
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
    <button type="submit" name="clear_cache" value="1" class="btn btn-warning ms-2">Clear Cache</button>
    <a href="fix_author_sort.php" class="btn btn-secondary ms-2">Fix Author Sort</a>
  </form>
</body>
</html>
