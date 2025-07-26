<?php
require_once 'db.php';
requireLogin();

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

$missingFile = !bookHasFile($book['path']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($book['title']) ?></title>
    <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
    <script src="theme.js"></script>
</head>
<body class="pt-5">
<?php include "navbar.php"; ?>
<div class="container my-4">
    <a href="list_books.php" class="btn btn-secondary mb-3">Back to list</a>
    <h1 class="mb-0">
        <?php if ($missingFile): ?>
            <i class="fa-solid fa-circle-exclamation text-danger me-1" title="File missing"></i>
        <?php endif; ?>
        <?= htmlspecialchars($book['title']) ?>
    </h1>
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
    <button type="button" id="synopsisBtn" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-authors="<?= htmlspecialchars($book['authors']) ?>" data-title="<?= htmlspecialchars($book['title']) ?>" class="btn btn-primary mb-4 ms-2">Generate Synopsis</button>
    <?php
        $annasQuery = urlencode($book['title'] . ' ' . $book['authors']);
        $annasUrl = 'list_books.php?source=annas&search=' . $annasQuery;
    ?>
    <a href="<?= htmlspecialchars($annasUrl) ?>" class="btn btn-secondary mb-4 ms-2">Search Anna's Archive</a>
    <button type="button" id="annasMetaBtn" class="btn btn-secondary mb-4 ms-2">Get Metadata</button>
    <button type="button" id="googleMetaBtn" class="btn btn-secondary mb-4 ms-2">Metadata Google</button>
    <?php if ($missingFile): ?>
        <button type="button" id="uploadFileButton" class="btn btn-secondary mb-4 ms-2">Upload File</button>
        <input type="file" id="bookFileInput" style="display:none">
        <div id="uploadMessage" class="mt-2 mb-2 h2"></div>
    <?php endif; ?>
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
    <div id="descriptionSection" class="mb-4"<?php if (!empty($comment)): ?> data-saved="<?= htmlspecialchars($comment, ENT_QUOTES) ?>"<?php endif; ?>></div>

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

<!-- Google Books Metadata Modal -->
<div class="modal fade" id="googleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Google Books Results</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="googleResults">Loading...</div>
      </div>
    </div>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
const recommendBtn = document.getElementById('recommendBtn');
const recommendSection = document.getElementById('recommendSection');
const synopsisBtn = document.getElementById('synopsisBtn');
const descriptionSection = document.getElementById('descriptionSection');

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
    if (descriptionSection && descriptionSection.dataset.saved) {
        descriptionSection.innerHTML = '<h2>Description</h2><p>' +
            escapeHTML(descriptionSection.dataset.saved).replace(/\n/g, '<br>') +
            '</p>';
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

synopsisBtn.addEventListener('click', function () {
    const bookId = this.dataset.bookId;
    const authors = this.dataset.authors;
    const title = this.dataset.title;
    if (descriptionSection) {
        descriptionSection.innerHTML = '<h2>Description</h2><p>Loading...</p>';
    }

    fetch('synopsis.php?book_id=' + encodeURIComponent(bookId) +
        '&authors=' + encodeURIComponent(authors) + '&title=' + encodeURIComponent(title))
        .then(resp => resp.json())
        .then(data => {
            if (descriptionSection) {
                if (data.output) {
                    descriptionSection.innerHTML = '<h2>Description</h2><p>' +
                        escapeHTML(data.output).replace(/\n/g, '<br>') + '</p>';
                } else {
                    descriptionSection.textContent = data.error || 'Error';
                }
            }
        })
        .catch(() => {
            if (descriptionSection) {
                descriptionSection.textContent = 'Error fetching synopsis';
            }
        });
});

const annasBtn = document.getElementById('annasMetaBtn');
const annasResults = document.getElementById('annasResults');
const annasModalEl = document.getElementById('annasModal');
const annasModal = new bootstrap.Modal(annasModalEl);
const annasSearchQuery = <?= json_encode($book['title'] . ' ' . $book['authors']) ?>;
const currentBookId = <?= (int)$book['id'] ?>;

const googleBtn = document.getElementById('googleMetaBtn');
const googleResults = document.getElementById('googleResults');
const googleModalEl = document.getElementById('googleModal');
const googleModal = new bootstrap.Modal(googleModalEl);
const googleSearchQuery = <?= json_encode($book['title'] . ' ' . $book['authors']) ?>;


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
                        'data-imgurl="' + (b.imgUrl || '').replace(/"/g,'&quot;') + '" ' +
                        'data-md5="' + (b.md5 || '').replace(/"/g,'&quot;') + '">Use This</button></div>';
                html += '</div>';
            });
            annasResults.innerHTML = html;
        })
        .catch(() => { annasResults.textContent = 'Error fetching results'; });
    annasModal.show();
});

googleBtn.addEventListener('click', () => {
    googleResults.textContent = 'Loading...';
    fetch('google_search.php?q=' + encodeURIComponent(googleSearchQuery))
        .then(r => r.json())
        .then(data => {
            if (!data.books || data.books.length === 0) {
                googleResults.textContent = 'No results';
                return;
            }
            let html = '';
            data.books.forEach(b => {
                html += '<div class="mb-2">';
                if (b.imgUrl) html += '<img src="' + escapeHTML(b.imgUrl) + '" style="height:100px" class="me-2">';
                html += '<strong>' + escapeHTML(b.title) + '</strong>';
                if (b.author) html += ' by ' + escapeHTML(b.author);
                if (b.year) html += ' (' + escapeHTML(b.year) + ')';
                if (b.description) html += '<br><em>' + escapeHTML(b.description) + '</em>';
                html += '<div><button type="button" class="btn btn-sm btn-primary mt-1 google-use" '
                        + 'data-title="' + b.title.replace(/"/g,'&quot;') + '" '
                        + 'data-authors="' + (b.author || '').replace(/"/g,'&quot;') + '" '
                        + 'data-year="' + (b.year || '').replace(/"/g,'&quot;') + '" '
                        + 'data-imgurl="' + (b.imgUrl || '').replace(/"/g,'&quot;') + '" '
                        + 'data-description="' + (b.description || '').replace(/"/g,'&quot;') + '">Use This</button></div>';
                html += '</div>';
            });
            googleResults.innerHTML = html;
        })
        .catch(() => { googleResults.textContent = 'Error fetching results'; });
    googleModal.show();
});


document.addEventListener('click', function(e) {
    if (e.target.classList.contains('annas-use')) {
        const t = e.target.dataset.title;
        const a = e.target.dataset.authors;
        const y = e.target.dataset.year;
        const img = e.target.dataset.imgurl;
        const md5 = e.target.dataset.md5 || '';
        fetch('update_metadata.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: currentBookId, title: t, authors: a, year: y, imgurl: img, md5: md5 })
        }).then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                annasModal.hide();
                location.reload();
            } else {
                alert(data.error || 'Error updating metadata');
            }
        }).catch(() => {
            alert('Error updating metadata');
        });
    } else if (e.target.classList.contains('google-use')) {
        const t = e.target.dataset.title;
        const a = e.target.dataset.authors;
        const y = e.target.dataset.year;
        const img = e.target.dataset.imgurl;
        const desc = e.target.dataset.description || '';
        fetch('update_metadata.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ book_id: currentBookId, title: t, authors: a, year: y, imgurl: img, description: desc })
        }).then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                googleModal.hide();
                location.reload();
            } else {
                alert(data.error || 'Error updating metadata');
            }
        }).catch(() => {
            alert('Error updating metadata');
        });
    }
});

const uploadBtn = document.getElementById('uploadFileButton');
const uploadInput = document.getElementById('bookFileInput');
const uploadMsg = document.getElementById('uploadMessage');

if (uploadBtn) {
    uploadBtn.addEventListener('click', () => {
        uploadInput.click();
    });

    uploadInput.addEventListener('change', () => {
        if (!uploadInput.files.length) return;
        const formData = new FormData();
        formData.append('id', currentBookId);
        formData.append('file', uploadInput.files[0]);
        uploadMsg.textContent = 'Uploading...';
        fetch('upload_book_file.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        }).then(r => r.json())
          .then(data => {
              if (data.status === 'ok') {
                  uploadMsg.textContent = data.message || 'File uploaded';
              } else {
                  uploadMsg.textContent = data.error || 'Upload failed';
              }
          })
          .catch(() => {
              uploadMsg.textContent = 'Upload failed';
          });
    });
}
</script>
</body>
</html>
