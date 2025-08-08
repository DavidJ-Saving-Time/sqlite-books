<?php
require_once 'db.php';
requireLogin();

$searchTerm = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$results = [];
$error = null;
if ($searchTerm !== '') {
    $libraryPath = getLibraryPath();
    $cmd = sprintf(
        'rga -i -n -C5 -H --json -- %s %s 2>&1',
        escapeshellarg($searchTerm),
        escapeshellarg($libraryPath)
    );
    $lines = [];
    $status = 0;
    exec($cmd, $lines, $status);
    if ($status === 0) {
        $currentFile = null;
        foreach ($lines as $line) {
            $json = json_decode($line, true);
            if (!is_array($json) || !isset($json['type'])) {
                continue;
            }
            switch ($json['type']) {
                case 'begin':
                    $currentFile = $json['data']['path']['text'] ?? null;
                    break;
                case 'match':
                case 'context':
                    if ($currentFile) {
                        $text = $json['data']['lines']['text'] ?? '';
                        $results[$currentFile][] = [
                            'line' => (int)($json['data']['line_number'] ?? 0),
                            'text' => $text,
                            'match' => $json['type'] === 'match'
                        ];
                    }
                    break;
                case 'end':
                    $currentFile = null;
                    break;
            }
        }
    } else {
        $error = 'Search failed or rga is not installed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Research</title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="js/theme.js"></script>
</head>
<body class="pt-5">
<?php include 'navbar_other.php'; ?>
<div class="container my-4">
    <h1>Research</h1>
    <form class="mb-4" method="get">
        <div class="input-group">
            <input type="text" class="form-control" name="q" placeholder="Search inside books..." value="<?= htmlspecialchars($searchTerm) ?>">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i>Search</button>
        </div>
    </form>
    <?php if ($searchTerm !== ''): ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($results): ?>
            <?php foreach ($results as $file => $lines): ?>
                <?php
                    $bookId = null;
                    if (preg_match('/\((\d+)\)/', $file, $m)) {
                        $bookId = $m[1];
                    }
                    $library = $libraryPath ?? getLibraryPath();
                    $relative = (strpos($file, $library) === 0) ? substr($file, strlen($library) + 1) : $file;
                    $link = $bookId ? 'book.php?id=' . urlencode($bookId) : null;
                ?>
                <div class="mb-4">
                    <h5>
                        <?php if ($link): ?>
                            <a href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($relative) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars($relative) ?>
                        <?php endif; ?>
                    </h5>
                    <pre class="bg-light p-2 border">
<?php foreach ($lines as $entry):
    $lineText = $entry['line'] . ($entry['match'] ? ':' : '-') . ' ' . $entry['text'];
    $lineText = htmlspecialchars($lineText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($entry['match']) {
        $pattern = '/' . preg_quote($searchTerm, '/') . '/i';
        $lineText = preg_replace($pattern, '<mark>$0</mark>', $lineText);
    }
    echo $lineText . "\n";
endforeach; ?>
                    </pre>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-warning">No results found.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
