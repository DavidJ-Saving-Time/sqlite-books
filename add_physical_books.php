<?php
require_once 'db.php';
requireLogin();
require_once __DIR__ . '/lib/book_ingest.php';

/**
 * Process a single uploaded book file.
 * Moves the upload to a temp path then delegates to processBookFromPath().
 */
function processBook(PDO $pdo, string $libraryPath, string $title, string $authors_str, string $tags_str, string $series_str, string $series_index_str, string $description_str, array $file): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['message' => '', 'link' => '', 'errors' => ['Valid book file is required.'], 'title' => $title, 'book_id' => 0];
    }

    $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $uploadedTmp = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadedTmp)) {
        return ['message' => '', 'link' => '', 'errors' => ['Failed to move uploaded file to temp directory. Check permissions on ' . sys_get_temp_dir()], 'title' => $title, 'book_id' => 0];
    }

    return processBookFromPath(
        $pdo, $libraryPath, $title, $authors_str, $tags_str,
        $series_str, $series_index_str, $description_str,
        $uploadedTmp,
        false, // not auto-ingest
        true   // delete temp file on success
    );
}

$pdo = getDatabaseConnection();
$libraryPath = getLibraryPath();

$isAjax = isset($_GET['_ajax']) && $_GET['_ajax'] === '1';

// When post_max_size is exceeded PHP silently drops all $_POST and $_FILES.
// Detect this and return a useful JSON error instead of the HTML page.
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $postMax    = ini_get('post_max_size');
    $contentLen = isset($_SERVER['CONTENT_LENGTH'])
        ? round($_SERVER['CONTENT_LENGTH'] / 1048576, 1) . ' MB'
        : 'unknown size';
    header('Content-Type: application/json');
    echo json_encode([[
        'title'   => '',
        'message' => '',
        'link'    => '',
        'errors'  => ["Upload rejected by server: total upload was {$contentLen}, which likely exceeds the server limit (post_max_size = {$postMax}). Try uploading fewer or smaller files at once."],
    ]]);
    exit;
}

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titles = $_POST['title'] ?? [];
    $authors = $_POST['authors'] ?? [];
    $tags = $_POST['tags'] ?? [];
    $seriesArr = $_POST['series'] ?? [];
    $seriesIndexArr = $_POST['series_index'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $files = $_FILES['files'] ?? null;

    if ($files && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $file = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
            $results[] = processBook(
                $pdo,
                $libraryPath,
                trim($titles[$i] ?? ''),
                trim($authors[$i] ?? ''),
                trim($tags[$i] ?? ''),
                trim($seriesArr[$i] ?? ''),
                trim($seriesIndexArr[$i] ?? ''),
                trim($descriptions[$i] ?? ''),
                $file
            );
        }
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#212529">
  <link rel="apple-touch-icon" href="/app-icons/icon-192.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Multiple Books</title>
    <link rel="stylesheet" href="/theme.css.php">
    <link rel="stylesheet" href="/css/all.min.css">
    <style>
        .file-upload-highlight {
            border: 2px dashed #6c757d;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .file-upload-highlight:hover {
            background-color: var(--bs-tertiary-bg, #f8f9fa);
        }
    </style>
</head>

<body style="padding-top:80px">
    <?php include 'navbar.php'; ?>
    <div class="container my-4">
        <h1 class="mb-4 text-center">Add Multiple Books</h1>

        <div id="results"></div>

        <div class="card shadow-sm p-4">
            <form id="uploadForm" method="post" enctype="multipart/form-data">
                <div class="mb-4">
                    <label for="files" class="form-label fw-bold">Upload Book Files</label>
                    <div class="file-upload-highlight" onclick="document.getElementById('files').click();">
                        <p class="mb-1"><strong>Click to upload book files</strong> or drag & drop here</p>
                        <small class="text-muted">(Supported formats: PDF, EPUB, etc.)</small>
                    </div>
                    <input type="file" id="files" class="form-control mt-2" style="display:none;" multiple>
                </div>

                <div id="bookEntries"></div>

                <button type="submit" id="submitBtn" class="btn btn-primary" style="display:none;">Add Books</button>
                <a href="list_books.php" id="backBtn" class="btn btn-secondary ms-2" style="display:none;">Back</a>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
        const filesInput    = document.getElementById('files');
        const entriesContainer = document.getElementById('bookEntries');
        const submitBtn     = document.getElementById('submitBtn');
        const backBtn       = document.getElementById('backBtn');
        const resultsDiv    = document.getElementById('results');
        const uploadForm    = document.getElementById('uploadForm');

        let bookFiles = [];   // [{file, idx}, ...]
        let nextIdx   = 0;

        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function updateButtons() {
            const show = bookFiles.length > 0;
            submitBtn.style.display = show ? 'inline-block' : 'none';
            backBtn.style.display   = show ? 'inline-block' : 'none';
        }

        function removeEntry(idx) {
            bookFiles = bookFiles.filter(b => b.idx !== idx);
            document.querySelector(`.book-entry[data-idx="${idx}"]`)?.remove();
            updateButtons();
        }

        function checkDuplicate(idx) {
            const titleEl   = document.getElementById('title-'   + idx);
            const authorsEl = document.getElementById('authors-' + idx);
            const warnEl    = document.getElementById('dup-warn-' + idx);
            if (!titleEl || !warnEl) return;

            const title  = titleEl.value.trim();
            const author = authorsEl ? authorsEl.value.trim() : '';
            if (!title) { warnEl.style.display = 'none'; return; }

            const params = new URLSearchParams({ title });
            if (author) params.set('author', author);

            fetch('json_endpoints/check_duplicate.php?' + params)
                .then(r => r.json())
                .then(data => {
                    if (data.duplicates && data.duplicates.length > 0) {
                        const list = data.duplicates.map(d =>
                            `<a href="book.php?id=${d.id}" target="_blank" class="alert-link">${escHtml(d.title)}</a>${d.authors ? ' by ' + escHtml(d.authors) : ''}`
                        ).join('; ');
                        warnEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation me-1"></i> Possible duplicate: ${list}`;
                        warnEl.style.display = 'block';
                    } else {
                        warnEl.style.display = 'none';
                    }
                })
                .catch(() => {});
        }

        function createEntry(file, idx) {
            const entry = document.createElement('div');
            entry.className = 'mb-4 p-3 border rounded book-entry';
            entry.dataset.idx = idx;
            entry.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-muted small">${escHtml(file.name)}</span>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-entry-btn" data-idx="${idx}">
                        <i class="fa-solid fa-xmark"></i> Remove
                    </button>
                </div>
                <div id="dup-warn-${idx}" class="alert alert-warning py-2 px-3 mb-3" style="display:none;font-size:0.85rem"></div>
                <div id="meta-loading-${idx}" class="text-muted small mb-2">
                    <i class="fa-solid fa-spinner fa-spin me-1"></i>Reading file metadata…
                </div>
                <div class="mb-3 text-center">
                    <img id="cover-${idx}" src="" alt="Cover" class="img-fluid" style="max-height:300px; display:none;">
                </div>
                <div class="mb-3">
                    <label for="title-${idx}" class="form-label">Title</label>
                    <input type="text" id="title-${idx}" class="form-control entry-title" data-idx="${idx}" required>
                </div>
                <div class="mb-2 text-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary swap-btn" data-idx="${idx}" title="Swap title and author">
                        <i class="fa-solid fa-arrow-right-arrow-left fa-rotate-90"></i> Swap title / author
                    </button>
                </div>
                <div class="mb-3">
                    <label for="authors-${idx}" class="form-label">Author(s)</label>
                    <input type="text" id="authors-${idx}" class="form-control entry-authors" data-idx="${idx}" placeholder="Separate multiple authors with commas" required>
                </div>
                <div class="mb-3">
                    <label for="series-${idx}" class="form-label">Series</label>
                    <input type="text" id="series-${idx}" class="form-control" placeholder="Optional">
                </div>
                <div class="mb-3">
                    <label for="series_index-${idx}" class="form-label">Series Index</label>
                    <input type="number" step="0.1" id="series_index-${idx}" class="form-control" placeholder="Optional">
                </div>
                <div class="mb-3">
                    <label for="tags-${idx}" class="form-label">Tags</label>
                    <input type="text" id="tags-${idx}" class="form-control" placeholder="Optional, comma separated">
                </div>
                <div class="mb-3">
                    <label for="description-${idx}" class="form-label">Description</label>
                    <textarea id="description-${idx}" class="form-control" rows="3" placeholder="Optional"></textarea>
                </div>`;
            entriesContainer.appendChild(entry);

            entry.querySelector('.remove-entry-btn').addEventListener('click', () => removeEntry(idx));

            entry.querySelector('.swap-btn').addEventListener('click', () => {
                const t = document.getElementById('title-'   + idx);
                const a = document.getElementById('authors-' + idx);
                [t.value, a.value] = [a.value, t.value];
                checkDuplicate(idx);
            });

            const titleEl   = entry.querySelector('.entry-title');
            const authorsEl = entry.querySelector('.entry-authors');
            titleEl.addEventListener('blur',   () => checkDuplicate(idx));
            authorsEl.addEventListener('blur', () => checkDuplicate(idx));

            const fd = new FormData();
            fd.append('file', file);
            fetch('json_endpoints/ebook_meta.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('meta-loading-' + idx)?.remove();
                    if (data.title)  titleEl.value = data.title.replace(/\s*\([^)]*\)/g, '').replace(/\s*:.*$/g, '').trim();
                    if (data.authors) {
                        const rawAuthors = Array.isArray(data.authors)
                            ? data.authors
                            : String(data.authors).split(/ and |&/i).map(a => a.trim()).filter(Boolean);
                        authorsEl.value = rawAuthors.map(a => {
                            const m = a.match(/^([^,]+),\s*(.+)$/);
                            return m ? m[2].trim() + ' ' + m[1].trim() : a.trim();
                        }).join(', ');
                    }

                    if (data.cover) {
                        const img = document.getElementById('cover-' + idx);
                        img.src = 'data:image/jpeg;base64,' + data.cover;
                        img.style.display = 'block';
                    }
                    if (data.comments) document.getElementById('description-' + idx).value = data.comments;
                    if (data.title || data.authors) checkDuplicate(idx);
                })
                .catch(() => { document.getElementById('meta-loading-' + idx)?.remove(); });
        }

        filesInput.addEventListener('change', () => {
            Array.from(filesInput.files).forEach(file => {
                const idx = nextIdx++;
                bookFiles.push({ file, idx });
                createEntry(file, idx);
            });
            filesInput.value = '';
            updateButtons();
        });

        uploadForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (bookFiles.length === 0) return;

            submitBtn.disabled    = true;
            submitBtn.textContent = 'Adding\u2026';
            resultsDiv.innerHTML  = '';

            const fd = new FormData();
            bookFiles.forEach(({ file, idx }) => {
                fd.append('files[]',       file);
                fd.append('title[]',       document.getElementById('title-'        + idx)?.value ?? '');
                fd.append('authors[]',     document.getElementById('authors-'      + idx)?.value ?? '');
                fd.append('tags[]',        document.getElementById('tags-'         + idx)?.value ?? '');
                fd.append('series[]',      document.getElementById('series-'       + idx)?.value ?? '');
                fd.append('series_index[]',document.getElementById('series_index-' + idx)?.value ?? '');
                fd.append('description[]', document.getElementById('description-'  + idx)?.value ?? '');
            });

            let resp;
            try {
                resp = await fetch('add_physical_books.php?_ajax=1', { method: 'POST', body: fd });
            } catch (netErr) {
                resultsDiv.innerHTML = `<div class="alert alert-danger"><strong>Network error:</strong> ${escHtml(netErr.message)}</div>`;
                submitBtn.disabled    = false;
                submitBtn.textContent = 'Add Books';
                return;
            }

            const rawText = await resp.text();

            if (!resp.ok) {
                resultsDiv.innerHTML = `<div class="alert alert-danger"><strong>Server error ${resp.status}:</strong><pre class="mb-0 mt-1" style="font-size:0.8rem;white-space:pre-wrap">${escHtml(rawText.trim().substring(0, 1000))}</pre></div>`;
                submitBtn.disabled    = false;
                submitBtn.textContent = 'Add Books';
                return;
            }

            let results;
            try {
                results = JSON.parse(rawText);
            } catch {
                // PHP printed something before or instead of JSON — show the raw output
                resultsDiv.innerHTML = `<div class="alert alert-danger"><strong>Unexpected server response (not JSON):</strong><pre class="mb-0 mt-1" style="font-size:0.8rem;white-space:pre-wrap">${escHtml(rawText.trim().substring(0, 1000))}</pre></div>`;
                submitBtn.disabled    = false;
                submitBtn.textContent = 'Add Books';
                return;
            }

            resultsDiv.innerHTML = results.map(res => {
                if (res.message) {
                    const link = res.link
                        ? ` <a class="alert-link" href="${escHtml(res.link)}">View Book</a>` : '';
                    return `<div class="alert alert-success">${escHtml(res.title)}: ${escHtml(res.message)}${link}</div>`;
                }
                if (res.errors && res.errors.length) {
                    return `<div class="alert alert-danger">${escHtml(res.title)}: ${escHtml(res.errors.join(' '))}</div>`;
                }
                return '';
            }).join('');

            // Remove successfully added entries from the list
            const successTitles = new Set(
                results.filter(r => r.message).map(r => r.title)
            );
            bookFiles = bookFiles.filter(({ idx }) => {
                const t = document.getElementById('title-' + idx)?.value ?? '';
                if (successTitles.has(t)) {
                    document.querySelector(`.book-entry[data-idx="${idx}"]`)?.remove();
                    return false;
                }
                return true;
            });
            updateButtons();

            submitBtn.disabled    = false;
            submitBtn.textContent = 'Add Books';
        });
    </script>
</body>

</html>