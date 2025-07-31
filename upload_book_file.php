<?php
require_once 'db.php';
requireLogin();

$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$reqWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
$wantJson = stripos($accept, 'application/json') !== false ||
            strtolower($reqWith) === 'xmlhttprequest';

function safe_filename(string $name, int $max_length = 150): string {
    $name = preg_replace('/[^A-Za-z0-9 _-]/', '', $name);
    return substr(trim($name), 0, $max_length);
}

$pdo = getDatabaseConnection();
$libraryPath = getLibraryPath();

$bookId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($bookId <= 0) {
    if ($wantJson) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid book ID']);
        exit;
    }
    die('Invalid book ID');
}

$stmt = $pdo->prepare("SELECT b.title, b.path,
    (SELECT GROUP_CONCAT(a.name, ', ')
        FROM books_authors_link bal
        JOIN authors a ON bal.author = a.id
        WHERE bal.book = b.id) AS authors
    FROM books b WHERE b.id = :id");
$stmt->execute([':id' => $bookId]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    if ($wantJson) {
        http_response_code(404);
        echo json_encode(['error' => 'Book not found']);
        exit;
    }
    die('Book not found');
}

$authors = array_map('trim', explode(',', $book['authors'] ?? ''));
$firstAuthor = $authors[0] ?? '';

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Valid book file is required.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $bookPath = $book['path'];
            if ($bookPath === '' || $bookPath === null) {
                $authorFolderName = safe_filename($firstAuthor . (count($authors) > 1 ? ' et al.' : ''));
                $bookFolderName = safe_filename($book['title']) . " ($bookId)";
                $bookPath = $authorFolderName . '/' . $bookFolderName;
                $pdo->prepare('UPDATE books SET path = ? WHERE id = ?')->execute([$bookPath, $bookId]);
            }

            $fullBookFolder = $libraryPath . '/' . $bookPath;
            if (!is_dir($fullBookFolder)) {
                mkdir($fullBookFolder, 0777, true);
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $baseFileName = safe_filename($book['title']) . ' - ' . safe_filename($firstAuthor);
            $destFile = $fullBookFolder . '/' . $baseFileName . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $destFile);

            // If the upload is a PDF, store the file without conversion

            $stmt = $pdo->prepare('INSERT INTO data (book, format, uncompressed_size, name) VALUES (?, ?, ?, ?)');
            $stmt->execute([$bookId, strtoupper($ext), filesize($destFile), $baseFileName]);

            $opfFile = $fullBookFolder . '/metadata.opf';
            if (!file_exists($opfFile)) {
                $uuid = $pdo->query('SELECT uuid FROM books WHERE id = ' . $bookId)->fetchColumn();
                $timestamp = date('Y-m-d\TH:i:s');
                $opf = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<package version=\"2.0\" xmlns=\"http://www.idpf.org/2007/opf\">\n  <metadata>\n" .
                       "    <dc:title>" . htmlspecialchars($book['title']) . "</dc:title>\n" .
                       "    <dc:creator opf:role=\"aut\">" . htmlspecialchars($firstAuthor) . "</dc:creator>\n" .
                       "    <dc:language>eng</dc:language>\n" .
                       "    <dc:identifier opf:scheme=\"uuid\">$uuid</dc:identifier>\n" .
                       "    <meta name=\"calibre:timestamp\" content=\"$timestamp+00:00\"/>\n" .
                       "  </metadata>\n</package>";
                file_put_contents($opfFile, $opf);
            }

            $pdo->commit();
            $message = 'File uploaded successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }

    if ($wantJson) {
        header('Content-Type: application/json');
        if ($errors) {
            http_response_code(400);
            echo json_encode(['error' => implode(' ', $errors)]);
        } else {
            echo json_encode(['status' => 'ok', 'message' => $message]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="manifest" href="manifest.json">
    <title>Upload File</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body>
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1 class="mb-4">Upload File for <?= htmlspecialchars($book['title']) ?></h1>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php elseif ($errors): ?>
        <div class="alert alert-danger"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= htmlspecialchars($bookId) ?>">
        <div class="mb-3">
            <label for="file" class="form-label">Book File</label>
            <input type="file" name="file" id="file" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Upload</button>
        <a href="book.php?id=<?= urlencode($bookId) ?>" class="btn btn-secondary ms-2">Back</a>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="js/pwa.js"></script>
</body>
</html>
