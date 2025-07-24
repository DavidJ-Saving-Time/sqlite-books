<?php
require_once 'db.php';

$pdo = getDatabaseConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid book ID');
}

$sort = $_GET['sort'] ?? 'title';

$stmt = $pdo->prepare("SELECT b.*, 
        (SELECT GROUP_CONCAT(a.name, ', ')
            FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS authors,
        (SELECT GROUP_CONCAT(a.id || ':' || a.name, '|')
            FROM books_authors_link bal
            JOIN authors a ON bal.author = a.id
            WHERE bal.book = b.id) AS author_data,
        s.id AS series_id,
        s.name AS series
    FROM books b
    LEFT JOIN books_series_link bsl ON bsl.book = b.id
    LEFT JOIN series s ON bsl.series = s.id
    WHERE b.id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    die('Book not found');
}

$commentStmt = $pdo->prepare('SELECT text FROM comments WHERE book = ?');
$commentStmt->execute([$id]);
$comment = $commentStmt->fetchColumn();

$tagsStmt = $pdo->prepare("SELECT GROUP_CONCAT(t.name, ', ')
    FROM books_tags_link btl
    JOIN tags t ON btl.tag = t.id
    WHERE btl.book = ?");
$tagsStmt->execute([$id]);
$tags = $tagsStmt->fetchColumn();

// Fetch saved recommendations from custom column 10, if present
try {
    $recStmt = $pdo->prepare('SELECT value FROM books_custom_column_10 WHERE book = ?');
    $recStmt->execute([$id]);
    $savedRecommendations = $recStmt->fetchColumn();
} catch (PDOException $e) {
    // Table may not exist in some databases
    $savedRecommendations = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($book['title']) ?></title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <script src="theme.js"></script>
</head>
<body class="pt-5">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <a href="list_books.php" class="btn btn-secondary mb-3">Back to list</a>
    <h1 class="mb-0"><?= htmlspecialchars($book['title']) ?></h1>
    <?php
        $formattedPubdate = '';
        if (!empty($book['pubdate'])) {
            try {
                $dt = new DateTime($book['pubdate']);
                $formattedPubdate = $dt->format('jS \of F Y');
            } catch (Exception $e) {
                $formattedPubdate = htmlspecialchars($book['pubdate']);
            }
        }
    ?>
    <p class="mb-4">
        <?php if (!empty($book['isbn'])): ?>
            <strong>ISBN:</strong> <?= htmlspecialchars($book['isbn']) ?><br>
        <?php endif; ?>
        <?php if ($formattedPubdate): ?>
            <strong>Published:</strong> <?= htmlspecialchars($formattedPubdate) ?>
        <?php endif; ?>
    </p>
    <button type="button" id="recommendBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary mb-4">Get Book Recommendations</button>
    <?php
        $annasQuery = urlencode($book['title'] . ' ' . $book['authors']);
        $annasUrl = 'list_books.php?source=annas&search=' . $annasQuery;
    ?>
    <a href="<?= htmlspecialchars($annasUrl) ?>" class="btn btn-secondary mb-4 ms-2">Search Anna's Archive</a>
    <button type="button" id="annasMetaBtn" class="btn btn-secondary mb-4 ms-2">Get Metadata</button>
    <button type="button" id="openlibMetaBtn" class="btn btn-secondary mb-4 ms-2">Get Metadata (Open Library)</button>
    <div class="row mb-4">
        <div class="col-md-3">
            <?php if (!empty($book['has_cover'])): ?>
                <img src="ebooks/<?= htmlspecialchars($book['path']) ?>/cover.jpg" alt="Cover" class="img-fluid">
            <?php else: ?>
                <div class="text-muted">No cover</div>
            <?php endif; ?>
        </div>
        <div class="col-md-9">
            <p><strong>Author(s):</strong>
                <?php if (!empty($book['author_data'])): ?>
                    <?php
                        $links = [];
                        foreach (explode('|', $book['author_data']) as $pair) {
                            list($aid, $aname) = explode(':', $pair, 2);
                            $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid);
                            $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>';
                        }
                        echo implode(', ', $links);
                    ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </p>
            <p><strong>Series:</strong>
                <?php if (!empty($book['series'])): ?>
                    <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                        <?= htmlspecialchars($book['series']) ?>
                    </a>
                    <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                        (<?= htmlspecialchars($book['series_index']) ?>)
                    <?php endif; ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </p>
            <?php if (!empty($tags)): ?>
                <p><strong>Tags:</strong> <?= htmlspecialchars($tags) ?></p>
            <?php endif; ?>
            <div id="recommendSection"<?php if (!empty($savedRecommendations)): ?>
                data-saved="<?= htmlspecialchars($savedRecommendations, ENT_QUOTES) ?>"<?php endif; ?>>
            </div>
        </div>
    </div>
    <?php if (!empty($comment)): ?>
        <div class="mb-4">
            <h2>Description</h2>
            <p><?= nl2br(htmlspecialchars($comment)) ?></p>
        </div>

<?php endif; ?>

<!-- Anna's Archive Metadata Modal -->
<div class="modal fade" id="annasModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Anna's Archive Results</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="annasResults">Loading...</div>
      </div>
    </div>
  </div>
</div>

<!-- Open Library Metadata Modal -->
<div class="modal fade" id="openlibModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Open Library Results</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="openlibResults">Loading...</div>
      </div>
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const recommendBtn = document.getElementById('recommendBtn');
const recommendSection = document.getElementById('recommendSection');

function escapeHTML(str) {
    return str.replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#39;');
}

function parseRecommendations(text) {
    const lines = text.split(/[\n\r]+/).map(l => l.trim()).filter(l => l);
    const recs = [];
    for (let line of lines) {
        line = line.replace(/^\d+\.\s*/, '').replace(/^[-*]\s*/, '');
        const byPos = line.toLowerCase().indexOf(' by ');
        if (byPos === -1) continue;
        let title = line.slice(0, byPos).trim();
        title = title.replace(/^['"*_]+|['"*_]+$/g, '');
        let rest = line.slice(byPos + 4).trim();
        let author, reason = '';
        const dashPos = rest.indexOf(' - ');
        if (dashPos !== -1) {
            author = rest.slice(0, dashPos).trim();
            reason = rest.slice(dashPos + 3).trim();
        } else {
            author = rest;
        }
        author = author.replace(/^['"*_]+|['"*_]+$/g, '');
        recs.push({ title, author, reason });
    }
    return recs;
}

function renderRecommendations(text) {
    const recs = parseRecommendations(text);
    if (!recs.length) {
        return '<p><strong>Recommendations:</strong> ' +
            escapeHTML(text).replace(/\n/g, '<br>') + '</p>';
    }
    let html = '<h2>Recommendations</h2><ol>';
    for (const r of recs) {
        const query = encodeURIComponent(r.title + ' ' + r.author);
        const link = '<a href="list_books.php?source=openlibrary&search=' + query + '">' +
            escapeHTML(r.title) + '</a>';
        html += '<li>' + link + ' by ' + escapeHTML(r.author);
        if (r.reason) html += ' - ' + escapeHTML(r.reason);
        html += '</li>';
    }
    html += '</ol>';
    return html;
}

document.addEventListener('DOMContentLoaded', () => {
    if (recommendSection.dataset.saved) {
        recommendSection.innerHTML = renderRecommendations(recommendSection.dataset.saved);
    }
});

recommendBtn.addEventListener('click', function () {
    const bookId = this.dataset.bookId;
    const authors = this.dataset.authors;
    const title = this.dataset.title;
    recommendSection.textContent = 'Loading...';

    fetch('recommend.php?book_id=' + encodeURIComponent(bookId) +
        '&authors=' + encodeURIComponent(authors) + '&title=' + encodeURIComponent(title))
        .then(resp => resp.json())
        .then(data => {
            if (data.output) {
                recommendSection.innerHTML = renderRecommendations(data.output);
            } else {
                recommendSection.textContent = data.error || '';
            }
        })
        .catch(() => {
        recommendSection.textContent = 'Error fetching recommendations';
    });
});

const annasBtn = document.getElementById('annasMetaBtn');
const annasResults = document.getElementById('annasResults');
const annasModalEl = document.getElementById('annasModal');
const annasModal = new bootstrap.Modal(annasModalEl);
const annasSearchQuery = <?= json_encode($book['title'] . ' ' . $book['authors']) ?>;
const currentBookId = <?= (int)$book['id'] ?>;

const openlibBtn = document.getElementById('openlibMetaBtn');
const openlibResults = document.getElementById('openlibResults');
const openlibModalEl = document.getElementById('openlibModal');
const openlibModal = new bootstrap.Modal(openlibModalEl);
const openlibSearchQuery = <?= json_encode($book['title'] . ' ' . $book['authors']) ?>;

annasBtn.addEventListener('click', () => {
    annasResults.textContent = 'Loading...';
    fetch('annas_search.php?q=' + encodeURIComponent(annasSearchQuery))
        .then(r => r.json())
        .then(data => {
            if (!data.books || data.books.length === 0) {
                annasResults.textContent = 'No results';
                return;
            }
            let html = '';
            data.books.forEach(b => {
                html += '<div class="mb-2">';
                if (b.imgUrl) html += '<img src="' + escapeHTML(b.imgUrl) + '" style="height:100px" class="me-2">';
                html += '<strong>' + escapeHTML(b.title) + '</strong>';
                if (b.author) html += ' by ' + escapeHTML(b.author);
                if (b.year) html += ' (' + escapeHTML(b.year) + ')';
                html += '<div><button type="button" class="btn btn-sm btn-primary mt-1 annas-use" ' +
                        'data-title="' + b.title.replace(/"/g,'&quot;') + '" ' +
                        'data-authors="' + (b.author || '').replace(/"/g,'&quot;') + '" ' +
                        'data-year="' + (b.year || '').replace(/"/g,'&quot;') + '" ' +
                        'data-imgurl="' + (b.imgUrl || '').replace(/"/g,'&quot;') + '">Use This</button></div>';
                html += '</div>';
            });
            annasResults.innerHTML = html;
        })
        .catch(() => { annasResults.textContent = 'Error fetching results'; });
    annasModal.show();
});

openlibBtn.addEventListener('click', () => {
    openlibResults.textContent = 'Loading...';
    fetch('https://openlibrary.org/search.json?q=' + encodeURIComponent(openlibSearchQuery))
        .then(r => r.json())
        .then(data => {
            if (!data.docs || data.docs.length === 0) {
                openlibResults.textContent = 'No results';
                return;
            }
            let html = '';
            data.docs.forEach(doc => {
                const title = doc.title || '';
                const authors = Array.isArray(doc.author_name) ? doc.author_name.join(', ') : '';
                const coverId = doc.cover_i || '';
                const year = doc.first_publish_year || '';
                html += '<div class="mb-2">';
                if (coverId) html += '<img src="https://covers.openlibrary.org/b/id/' + escapeHTML(coverId) + '-S.jpg" style="height:100px" class="me-2">';
                html += '<strong>' + escapeHTML(title) + '</strong>';
                if (authors) html += ' by ' + escapeHTML(authors);
                if (year) html += ' (' + escapeHTML(year) + ')';
                const img = coverId ? 'https://covers.openlibrary.org/b/id/' + coverId + '-L.jpg' : '';
                html += '<div><button type="button" class="btn btn-sm btn-primary mt-1 openlib-use" ' +
                        'data-title="' + title.replace(/"/g,'&quot;') + '" ' +
                        'data-authors="' + authors.replace(/"/g,'&quot;') + '" ' +
                        'data-year="' + String(year).replace(/"/g,'&quot;') + '" ' +
                        'data-imgurl="' + img.replace(/"/g,'&quot;') + '">Use This</button></div>';
                html += '</div>';
            });
            openlibResults.innerHTML = html;
        })
        .catch(() => { openlibResults.textContent = 'Error fetching results'; });
    openlibModal.show();
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('annas-use') || e.target.classList.contains('openlib-use')) {
        const t = e.target.dataset.title;
        const a = e.target.dataset.authors;
        const y = e.target.dataset.year;
        const img = e.target.dataset.imgurl;
        fetch('update_metadata.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: currentBookId, title: t, authors: a, year: y, imgurl: img })
        }).then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                if (e.target.classList.contains('annas-use')) annasModal.hide();
                if (e.target.classList.contains('openlib-use')) openlibModal.hide();
                location.reload();
            } else {
                alert(data.error || 'Error updating metadata');
            }
        }).catch(() => {
            alert('Error updating metadata');
        });
    }
});
</script>
</body>
</html>
