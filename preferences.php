<?php
require_once 'db.php';
require_once 'cache.php';
requireLogin();

$message = '';
$alertClass = 'success';
$orphanDirs = [];

/**
 * Returns orphan directories with preview info.
 * Each entry: ['path' => string, 'files' => string[], 'size' => int]
 */
function findOrphanDirectories(): array {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->query('SELECT path FROM books');
    $paths = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $known = array_flip($paths);

    $library = rtrim(getLibraryPath(), '/');
    $orphans = [];

    foreach (glob($library . '/*', GLOB_ONLYDIR) ?: [] as $authorDir) {
        foreach (glob($authorDir . '/*', GLOB_ONLYDIR) ?: [] as $bookDir) {
            $rel = substr($bookDir, strlen($library) + 1);
            if (!isset($known[$rel])) {
                $files = [];
                $totalSize = 0;
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($bookDir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $files[] = $f->getFilename();
                        $totalSize += $f->getSize();
                    }
                }
                $orphans[] = ['path' => $rel, 'files' => $files, 'size' => $totalSize];
            }
        }
    }

    return $orphans;
}

function logCleanupDeletion(string $library, string $rel, array $files, int $size): void {
    $logDir  = __DIR__ . '/logs';
    $logFile = $logDir . '/library_cleanup.log';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $ts   = date('Y-m-d H:i:s');
    $user = currentUser();
    $fileList = implode(', ', $files) ?: '(empty)';
    file_put_contents(
        $logFile,
        "[$ts] user=$user deleted=\"$library/$rel\" files=[$fileList] size={$size}b\n",
        FILE_APPEND | LOCK_EX
    );
}

function deleteDirectories(array $submitted): array {
    $library = rtrim(getLibraryPath(), '/');
    $safeRoot = $library . '/';

    // Re-derive current orphans server-side and only allow those paths
    $currentOrphans = findOrphanDirectories();
    $validPaths = array_flip(array_column($currentOrphans, 'path'));
    $orphanMeta = array_column($currentOrphans, null, 'path');

    $deleted = [];
    $skipped = [];

    foreach ($submitted as $rel) {
        // Must still be an orphan (race-condition guard)
        if (!isset($validPaths[$rel])) {
            $skipped[] = $rel . ' (no longer an orphan)';
            continue;
        }

        $full = realpath($library . '/' . $rel);

        // Path traversal guard: realpath must exist, be a dir, and sit strictly inside library
        if ($full === false || !is_dir($full) || !str_starts_with($full . '/', $safeRoot)) {
            $skipped[] = $rel . ' (invalid path)';
            continue;
        }

        $meta  = $orphanMeta[$rel];
        $files = $meta['files'];
        $size  = $meta['size'];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($full);

        logCleanupDeletion($library, $rel, $files, $size);
        $deleted[] = $rel;
    }

    return ['deleted' => $deleted, 'skipped' => $skipped];
}

/**
 * Returns groups of books that share the same title+author (case-insensitive).
 * Each entry: ['title' => string, 'author' => string, 'books' => [['id','title','author','added'],...]]
 */

/**
 * Set the reading status for all books to "Not Read".
 */
function resetAllStatusesToNotRead(): void {
    $pdo = getDatabaseConnection();
    $statusId = ensureMultiValueColumn($pdo, '#status', 'Status');
    $valueTable = "custom_column_{$statusId}";
    $linkTable = "books_custom_column_{$statusId}_link";

    // Ensure the "Not Read" value exists
    $pdo->prepare("INSERT OR IGNORE INTO $valueTable (value) VALUES ('Not Read')")->execute();
    $notReadId = $pdo->query("SELECT id FROM $valueTable WHERE value = 'Not Read'")->fetchColumn();

    // Clear existing links and assign "Not Read" to every book
    $pdo->exec("DELETE FROM $linkTable");
    $stmt = $pdo->prepare("INSERT INTO $linkTable (book, value) SELECT id, :val FROM books");
    $stmt->execute([':val' => $notReadId]);

    invalidateCache('statuses');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear_cache'])) {
        clearUserCache();
        $message = 'Cache cleared.';
    } elseif (isset($_POST['check_orphans'])) {
        $orphanDirs = findOrphanDirectories();
        if (!$orphanDirs) {
            $message = 'No orphan directories found.';
        }
    } elseif (isset($_POST['delete_orphans'])) {
        $dirs = json_decode($_POST['dirs_json'] ?? '[]', true) ?: [];
        if (is_array($dirs) && $dirs) {
            $result = deleteDirectories($dirs);
            $parts = [];
            if ($result['deleted']) {
                $parts[] = count($result['deleted']) . ' director' . (count($result['deleted']) === 1 ? 'y' : 'ies') . ' deleted';
            }
            if ($result['skipped']) {
                $parts[] = count($result['skipped']) . ' skipped (' . implode('; ', $result['skipped']) . ')';
                $alertClass = 'warning';
            }
            $message = implode('. ', $parts) . '.';
            if (!$result['deleted'] && !$result['skipped']) {
                $message = 'Nothing to delete.';
            }
            $orphanDirs = findOrphanDirectories();
        } else {
            $message = 'No directories selected.';
            $alertClass = 'warning';
        }
    } elseif (isset($_POST['reset_statuses'])) {
        resetAllStatusesToNotRead();
        $message = 'All books marked as Not Read.';
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

$currentPath      = currentDatabasePath();
$currentLibrary   = getLibraryPath();
$currentRemoteDir = getUserPreference(currentUser(), 'REMOTE_DIR', getPreference('REMOTE_DIR', ''));
$currentDevice    = getUserPreference(currentUser(), 'DEVICE',     getPreference('DEVICE',     ''));
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
  <meta charset="utf-8">
  <title>Preferences</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css">
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>
<div class="container py-4" style="max-width: 640px;">
  <h1 class="mb-1">Preferences</h1>
  <p class="text-muted mb-4">
    <a href="themes.php"><i class="fa-solid fa-palette me-1"></i>Theme settings</a>
    <span class="mx-2 text-muted">·</span>
    <a href="wikipedia_import.php"><i class="fa-brands fa-wikipedia-w me-1"></i>Wikipedia import</a>
  </p>

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
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" value="1" id="save_global" name="save_global">
      <label class="form-check-label" for="save_global">Save as default for all users</label>
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
    <button type="submit" name="clear_cache" value="1" class="btn btn-warning ms-2">Clear Cache</button>
    <a href="fix_author_sort.php" class="btn btn-secondary ms-2">Fix Author Sort</a>
  </form>
  <form method="post" class="mt-3" onsubmit="return confirm('Are you sure you want to mark all books as Not Read?');">
    <button type="submit" name="reset_statuses" value="1" class="btn btn-danger">Mark All Books as Not Read</button>
  </form>
  <hr class="my-4">
  <h2 class="mb-3">Duplicate Books</h2>
  <p class="text-muted small mb-3">
    Find books that share the same title and author — shows file formats, disk size, and identifiers so you can decide which copy to keep.
  </p>
  <a href="dedup_books.php" class="btn btn-secondary">
    <i class="fa-solid fa-clone me-1"></i>Open Duplicate Checker
  </a>

  <hr class="my-4">
  <h2 class="mb-3">Library Cleanup</h2>
  <p class="text-muted small mb-3">
    Scans the library directory for book folders that have no matching entry in the Calibre database —
    leftovers from books deleted outside of Calibre. The scan is read-only; nothing is changed until
    you check specific directories and click <strong>Delete Selected</strong>.
    All deletions are logged to <code>logs/library_cleanup.log</code>.
  </p>
  <form method="post" class="mb-3">
    <button type="submit" name="check_orphans" value="1" class="btn btn-secondary">
      Scan for Orphaned Directories
    </button>
  </form>
  <?php if ($orphanDirs): ?>
    <form method="post" id="orphanForm" onsubmit="
      const checked = [...document.querySelectorAll('.orphan-cb:checked')].map(cb => cb.value);
      if (!checked.length) { alert('No directories selected.'); return false; }
      document.getElementById('dirs_json').value = JSON.stringify(checked);
      return confirm('Permanently delete ' + checked.length + ' director' + (checked.length === 1 ? 'y' : 'ies') + ' and all their contents?');
    ">
      <input type="hidden" name="dirs_json" id="dirs_json">
      <p class="fw-semibold"><?= count($orphanDirs) ?> orphaned director<?= count($orphanDirs) === 1 ? 'y' : 'ies' ?> found:</p>

      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="selectAll"
               onchange="document.querySelectorAll('.orphan-cb').forEach(cb => cb.checked = this.checked)">
        <label class="form-check-label fw-semibold" for="selectAll">Select all</label>
      </div>

      <?php
      function formatBytes(int $bytes): string {
          if ($bytes < 1024) return $bytes . ' B';
          if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
          return round($bytes / 1048576, 1) . ' MB';
      }
      ?>

      <?php foreach ($orphanDirs as $orphan):
          $dir = $orphan['path'];
          $files = $orphan['files'];
          $size = $orphan['size'];
          $id = md5($dir);
      ?>
        <div class="card mb-2">
          <div class="card-body py-2 px-3">
            <div class="form-check">
              <input class="form-check-input orphan-cb" type="checkbox"
                     value="<?= htmlspecialchars($dir) ?>" id="dir<?= $id ?>">
              <label class="form-check-label font-monospace" for="dir<?= $id ?>">
                <?= htmlspecialchars($dir) ?>
              </label>
            </div>
            <?php if ($files): ?>
              <div class="text-muted small mt-1 ms-4">
                <?= count($files) ?> file<?= count($files) !== 1 ? 's' : '' ?> &middot;
                <?= formatBytes($size) ?> &middot;
                <?= htmlspecialchars(implode(', ', $files)) ?>
              </div>
            <?php else: ?>
              <div class="text-muted small mt-1 ms-4">(empty directory)</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <button type="submit" name="delete_orphans" value="1" class="btn btn-danger mt-2">
        Delete Selected
      </button>
    </form>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
