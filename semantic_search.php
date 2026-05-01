<?php
require_once 'db.php';
requireLogin();

$q       = trim($_GET['q'] ?? '');
$results = [];
$terms   = [];

if ($q !== '') {
    $pdo   = getDatabaseConnection();
    $terms = array_values(array_filter(
        preg_split('/\s+/', strtolower($q)),
        fn($t) => strlen($t) >= 2
    ));

    if ($terms) {
        // Find books that match any term in any relevant field
        $orClauses = [];
        $params    = [];
        foreach ($terms as $term) {
            $like = "%{$term}%";
            $orClauses[] = "EXISTS(SELECT 1 FROM identifiers i WHERE i.book=b.id AND i.type IN ('loc_genres','loc_subjects','gr_shelf_counts') AND LOWER(i.val) LIKE ?)";
            $params[]    = $like;
            $orClauses[] = "EXISTS(SELECT 1 FROM comments c WHERE c.book=b.id AND LOWER(c.text) LIKE ?)";
            $params[]    = $like;
        }

        $where = implode(' OR ', $orClauses);
        $stmt  = $pdo->prepare("
            SELECT b.id, b.title, b.path, b.has_cover,
                   GROUP_CONCAT(a.name, ' & ') AS author
            FROM books b
            LEFT JOIN books_authors_link bal ON bal.book = b.id
            LEFT JOIN authors a ON a.id = bal.author
            WHERE $where
            GROUP BY b.id
        ");
        $stmt->execute($params);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($books) {
            $ids = implode(',', array_map(fn($b) => (int)$b['id'], $books));

            $identRows = $pdo->query("
                SELECT book, type, val FROM identifiers
                WHERE book IN ($ids) AND type IN ('loc_genres','loc_subjects','gr_shelf_counts')
            ")->fetchAll(PDO::FETCH_ASSOC);
            $identsByBook = [];
            foreach ($identRows as $r) $identsByBook[(int)$r['book']][$r['type']] = $r['val'];

            $commentRows = $pdo->query("SELECT book, text FROM comments WHERE book IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
            $commentsByBook = [];
            foreach ($commentRows as $r) $commentsByBook[(int)$r['book']] = $r['text'];

            foreach ($books as &$book) {
                $bid      = (int)$book['id'];
                $score    = 0;
                $matchedIn = [];

                $locGenres   = strtolower($identsByBook[$bid]['loc_genres']   ?? '');
                $locSubjects = strtolower($identsByBook[$bid]['loc_subjects'] ?? '');
                $grRaw       = $identsByBook[$bid]['gr_shelf_counts'] ?? '';
                $commentHtml = $commentsByBook[$bid] ?? '';
                $commentText = strtolower(strip_tags($commentHtml));

                // Parse gr_shelf_counts → [shelf => count]
                $shelves = [];
                foreach (explode(';', $grRaw) as $part) {
                    if (str_contains($part, ':')) {
                        [$name, $cnt] = explode(':', $part, 2);
                        $shelves[strtolower(trim($name))] = (int)$cnt;
                    }
                }

                foreach ($terms as $term) {
                    if ($locGenres   && str_contains($locGenres,   $term)) { $score += 10; $matchedIn['LOC Genres']   = true; }
                    if ($locSubjects && str_contains($locSubjects, $term)) { $score += 8;  $matchedIn['LOC Subjects'] = true; }
                    foreach ($shelves as $shelf => $cnt) {
                        if (str_contains($shelf, $term)) {
                            $score += min(15, $cnt / 50);   // shelf count drives weight, capped at 15
                            $matchedIn['GR Shelves'] = true;
                        }
                    }
                    if ($commentText && str_contains($commentText, $term)) { $score += 2; $matchedIn['Description'] = true; }
                }

                // Snippet from comment for matched terms
                $snippet = '';
                if (isset($matchedIn['Description'])) {
                    $plain = strip_tags($commentHtml);
                    foreach ($terms as $term) {
                        $pos = stripos($plain, $term);
                        if ($pos !== false) {
                            $start   = max(0, $pos - 90);
                            $snippet = ($start > 0 ? '…' : '') . substr($plain, $start, 220) . '…';
                            break;
                        }
                    }
                }

                // Top shelves for display
                arsort($shelves);
                $topShelves = array_slice($shelves, 0, 5, true);

                $book['score']       = $score;
                $book['matched_in']  = array_keys($matchedIn);
                $book['loc_genres']  = $identsByBook[$bid]['loc_genres']   ?? '';
                $book['loc_subjects']= $identsByBook[$bid]['loc_subjects'] ?? '';
                $book['top_shelves'] = $topShelves;
                $book['snippet']     = $snippet;
            }
            unset($book);

            usort($books, fn($a, $b) => $b['score'] <=> $a['score']);
            $results = array_slice($books, 0, 60);
        }
    }
}

// Highlight matched terms in a string
function highlight(string $text, array $terms): string {
    if (!$terms) return htmlspecialchars($text);
    $pattern = '/(' . implode('|', array_map('preg_quote', $terms)) . ')/iu';
    return preg_replace($pattern, '<mark>$1</mark>', htmlspecialchars($text));
}

$webPath = getLibraryWebPath();
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
  <meta charset="utf-8">
  <title>Semantic Search</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css">
  <style>
    mark { background: #ffe066; color: inherit; border-radius: 2px; padding: 0 1px; }
    .shelf-pill { font-size: .7rem; white-space: nowrap; }
    .score-bar { height: 4px; border-radius: 2px; background: var(--bs-success); }
    .result-cover { width: 56px; min-width: 56px; height: 84px; object-fit: cover; border-radius: 3px; }
    .cover-placeholder { width: 56px; min-width: 56px; height: 84px; background: var(--bs-secondary-bg); border-radius: 3px; display:flex; align-items:center; justify-content:center; }
  </style>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>

<div class="container py-4" style="max-width:860px">
  <h1 class="h3 mb-1">Semantic Search</h1>
  <p class="text-muted small mb-3">
    Searches LOC genres, LOC subjects, and Goodreads shelf tags (weighted by shelf count),
    plus book descriptions. Identifiers are ranked higher than description text.
  </p>

  <form method="get" class="d-flex gap-2 mb-4">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
           class="form-control" placeholder="e.g. time travel, detective noir, urban fantasy…" autofocus>
    <button class="btn btn-primary px-4" type="submit">Search</button>
  </form>

  <?php if ($q !== '' && empty($results)): ?>
    <div class="alert alert-secondary">No matches found for <strong><?= htmlspecialchars($q) ?></strong>.</div>

  <?php elseif ($results): ?>
    <p class="text-muted small mb-3"><?= count($results) ?> result<?= count($results) !== 1 ? 's' : '' ?> for <strong><?= htmlspecialchars($q) ?></strong></p>

    <?php
    $maxScore = max(1, $results[0]['score']);
    foreach ($results as $book):
        $pct = min(100, round($book['score'] / $maxScore * 100));
    ?>
    <div class="card mb-2">
      <div class="card-body py-2 px-3 d-flex gap-3">

        <!-- Cover -->
        <a href="book.php?id=<?= $book['id'] ?>" target="_blank" class="flex-shrink-0">
          <?php if ($book['has_cover']): ?>
            <img src="<?= htmlspecialchars($webPath . '/' . $book['path'] . '/cover.jpg') ?>"
                 class="result-cover" loading="lazy" alt="">
          <?php else: ?>
            <div class="cover-placeholder text-muted"><i class="fa-solid fa-book fa-lg"></i></div>
          <?php endif; ?>
        </a>

        <!-- Content -->
        <div class="flex-grow-1 min-width-0">
          <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
            <div>
              <a href="book.php?id=<?= $book['id'] ?>" target="_blank" class="fw-semibold text-decoration-none">
                <?= highlight($book['title'], $terms) ?>
              </a>
              <span class="text-muted small ms-2"><?= htmlspecialchars($book['author'] ?? '') ?></span>
            </div>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
              <?php foreach ($book['matched_in'] as $src): ?>
                <?php
                  $cls = match($src) {
                    'LOC Genres'   => 'bg-primary',
                    'LOC Subjects' => 'bg-success',
                    'GR Shelves'   => 'bg-warning text-dark',
                    'Description'  => 'bg-secondary',
                    default        => 'bg-secondary',
                  };
                ?>
                <span class="badge <?= $cls ?>" style="font-size:.68rem"><?= $src ?></span>
              <?php endforeach; ?>
              <span class="text-muted" style="font-size:.72rem">Score: <?= round($book['score'], 1) ?></span>
            </div>
          </div>

          <!-- Score bar -->
          <div class="score-bar mt-1 mb-2" style="width:<?= $pct ?>%"></div>

          <!-- LOC Genres -->
          <?php if ($book['loc_genres']): ?>
            <div class="small mb-1">
              <span class="text-muted me-1">Genres:</span>
              <?= highlight($book['loc_genres'], $terms) ?>
            </div>
          <?php endif; ?>

          <!-- LOC Subjects -->
          <?php if ($book['loc_subjects']): ?>
            <div class="small mb-1">
              <span class="text-muted me-1">Subjects:</span>
              <?= highlight($book['loc_subjects'], $terms) ?>
            </div>
          <?php endif; ?>

          <!-- GR Shelves -->
          <?php if ($book['top_shelves']): ?>
            <div class="d-flex flex-wrap gap-1 mb-1">
              <?php foreach ($book['top_shelves'] as $shelf => $cnt): ?>
                <span class="badge bg-warning text-dark shelf-pill"><?= highlight($shelf, $terms) ?> <span class="opacity-75"><?= number_format($cnt) ?></span></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Description snippet -->
          <?php if ($book['snippet']): ?>
            <div class="text-muted small fst-italic"><?= highlight($book['snippet'], $terms) ?></div>
          <?php endif; ?>
        </div>

      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>
