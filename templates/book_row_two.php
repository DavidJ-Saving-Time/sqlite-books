


<?php
$desc = strip_tags(trim($book['description'] ?? ''));
$goodreadsUrl = '';
if (!empty($book['authors'])) {
    $firstAuthor = explode('|', $book['authors'])[0];
    $nameParts = preg_split('/\s+/', trim($firstAuthor));
    $firstName = $nameParts[0] ?? '';
    $surname   = $nameParts[count($nameParts) - 1] ?? '';
    $goodreadsUrl = 'https://www.goodreads.com/search?q=' . urlencode(trim($firstName . ' ' . $surname . ' ' . $book['title']));
}
?>
<div id="item-<?= $index ?>" class="col-md-6 col-12 list-item"
     data-book-block-id="<?= (int)$book['id'] ?>"
     data-book-index="<?= $index ?>">
<div class="card h-100 shadow-sm">
<div class="card-body d-flex gap-3 p-2">

    <!-- COVER -->
    <div class="flex-shrink-0 text-center cover-wrapper" style="width:80px">
    <?php if (!empty($book['has_cover'])): ?>
        <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>">
            <img id="coverImage<?= (int)$book['id'] ?>"
                 src="<?= htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg') ?>"
                 class="img-thumbnail book-cover w-100"
                 loading="lazy">
        </a>
    <?php else: ?>
        <div class="bg-secondary rounded d-flex align-items-center justify-content-center text-white" style="width:80px;height:110px">
            <i class="fa-solid fa-book fa-lg"></i>
        </div>
    <?php endif; ?>
    </div>

    <!-- CONTENT -->
    <div class="flex-grow-1 d-flex flex-column" style="min-width:0">

        <!-- TITLE -->
        <div class="mb-1">
            <?php if ($missing): ?>
            <i class="fa-solid fa-circle-exclamation text-danger me-1"></i>
            <?php endif; ?>
            <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>"
               class="fw-semibold text-decoration-none book-title d-block text-truncate">
                <?= htmlspecialchars($book['title']) ?>
            </a>
        </div>

        <!-- AUTHORS -->
        <div class="small text-muted mb-1 text-truncate">
        <?php
        if (!empty($book['author_ids']) && !empty($book['authors'])) {
            $ids   = array_filter(explode('|', $book['author_ids']), 'strlen');
            $names = array_filter(explode('|', $book['authors']),    'strlen');
            $links = [];
            foreach (array_slice(array_map(null, $ids, $names), 0, 2) as [$aid, $aname]) {
                $url     = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid);
                $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>';
            }
            echo implode(', ', $links);
            if (count($ids) > 2) echo '…';
        } else {
            echo '&mdash;';
        }
        ?>
        </div>

        <!-- SERIES + PROGRESS -->
        <?php if (!empty($book['series']) || isset($deviceProgress[$book['id']])): ?>
        <div class="d-flex align-items-center gap-2 small text-muted mb-1 flex-wrap">
            <?php if (!empty($book['series'])): ?>
            <div class="text-truncate" style="max-width:200px">
            <i class="fa-duotone fa-solid fa-arrow-turn-down-right"></i>
                <a class="text-muted text-decoration-none"
                   href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                    <?= htmlspecialchars($book['series']) ?>
                </a>
                <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                (<?= htmlspecialchars($book['series_index']) ?>)
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (isset($deviceProgress[$book['id']])): ?>
            <?php $dp = $deviceProgress[$book['id']]; $fill = $dp['percent'] !== null ? round($dp['percent'] * 100) : 0; ?>
            <div class="d-flex align-items-center gap-1" style="min-width:80px">
                <div class="progress flex-grow-1" style="height:5px">
                    <div class="progress-bar" style="width:<?= $fill ?>%"></div>
                </div>
                <span><?= $fill ?>%</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- DESCRIPTION -->
        <?php if ($desc !== ''): ?>
        <div class="small text-secondary mb-2 book-description"
             style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"
             data-full="<?= htmlspecialchars($desc, ENT_QUOTES) ?>">
            <?= htmlspecialchars(mb_substr($desc, 0, 200)) ?>
        </div>
        <?php endif; ?>

        <!-- ACTIONS -->
        <div class="d-flex align-items-center gap-2 mt-auto flex-wrap">

            <div class="star-rating" data-book-id="<?= (int)$book['id'] ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <i class="rating-star <?= ((int)$book['rating'] >= $i) ? 'fa-solid fa-star text-warning' : 'fa-regular fa-star text-muted' ?>"
               data-value="<?= $i ?>" style="font-size:.75rem"></i>
            <?php endfor; ?>
            </div>

            <?php if ($firstFile):
                $ftype = strtoupper(pathinfo($firstFile, PATHINFO_EXTENSION));
                if ($ftype === 'PDF') { $readerUrl = getLibraryWebPath() . '/' . $firstFile; $readerTarget = '_blank'; }
                else                  { $readerUrl = 'reader.php?file=' . urlencode($firstFile); $readerTarget = '_self'; }
            ?>
            <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($readerUrl) ?>" target="<?= $readerTarget ?>">
                <i class="fa-light fa-book-open"></i>
            </a>
            <?php endif; ?>

            <?php if (!empty($onDevice[$book['id']])): ?>
            <button class="btn btn-sm btn-warning remove-from-device-row"
                    data-book-id="<?= (int)$book['id'] ?>"
                    data-device-path="<?= htmlspecialchars($onDevice[$book['id']]) ?>">
                <i class="fa-solid fa-tablet-screen-button"></i>
            </button>
            <?php elseif ($firstFile): ?>
            <button class="btn btn-sm btn-primary send-to-device-row"
                    data-book-id="<?= (int)$book['id'] ?>">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
            <?php endif; ?>

            <a class="btn btn-sm btn-primary" href="notes.php?id=<?= urlencode($book['id']) ?>">
                <i class="fa-solid fa-note-sticky"></i>
            </a>

            <?php if ($goodreadsUrl): ?>
            <a href="<?= htmlspecialchars($goodreadsUrl) ?>" target="_blank" class="btn btn-sm btn-primary">
                <i class="fa-brands fa-goodreads"></i>
            </a>
            <?php endif; ?>

            <button class="btn btn-sm btn-primary"
                    data-bs-toggle="collapse"
                    data-bs-target="#meta-<?= $index ?>">
                <i class="fa-solid fa-gear"></i>
            </button>

            <button class="btn btn-sm btn-danger delete-book"
                    data-book-id="<?= (int)$book['id'] ?>">
                <i class="fa-solid fa-trash"></i>
            </button>
        </div>

    </div>
</div>

<!-- COLLAPSIBLE METADATA -->
<div id="meta-<?= $index ?>" class="collapse">
<div class="card-footer bg-light border-top p-2">
<div class="d-flex flex-wrap gap-2">

    <div>
        <label class="small text-muted d-block">Genre</label>
        <select class="form-select form-select-sm genre-select" data-book-id="<?= (int)$book['id'] ?>">
            <option value="">None</option>
            <?php foreach ($genreList as $g): ?>
            <option value="<?= htmlspecialchars($g['value']) ?>" <?= $g['value'] === $firstGenreVal ? 'selected' : '' ?>>
                <?= htmlspecialchars($g['value']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label class="small text-muted d-block">Shelf</label>
        <select class="form-select form-select-sm shelf-select" data-book-id="<?= (int)$book['id'] ?>">
            <?php foreach ($shelfList as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $book['shelf'] === $s ? 'selected' : '' ?>>
                <?= htmlspecialchars($s) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label class="small text-muted d-block">Status</label>
        <select class="form-select form-select-sm status-select" data-book-id="<?= (int)$book['id'] ?>">
            <option value="Want to Read" <?= ($book['status'] === null || $book['status'] === '') ? 'selected' : '' ?>>Want to Read</option>
            <?php foreach ($statusOptions as $s):
                if ($s === 'Want to Read') continue; ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $book['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="position-relative">
        <label class="small text-muted d-block">Series</label>
        <input type="text" class="form-control form-control-sm series-name-input"
               style="width:12rem"
               data-book-id="<?= (int)$book['id'] ?>"
               data-series-id="<?= htmlspecialchars($book['series_id'] ?? '') ?>"
               value="<?= htmlspecialchars($book['series'] ?? '') ?>"
               placeholder="None">
        <ul class="series-suggestions list-group position-absolute w-100"
            style="z-index:1050;display:none;max-height:180px;overflow-y:auto"></ul>
    </div>

    <div>
        <label class="small text-muted d-block">Index</label>
        <input type="number" step="0.1" min="0"
               class="form-control form-control-sm series-index-input"
               style="width:5rem"
               data-book-id="<?= (int)$book['id'] ?>"
               value="<?= htmlspecialchars($book['series_index'] ?? '') ?>">
    </div>

</div>
</div>
</div>

</div><!-- /.card -->
</div><!-- /.col -->

