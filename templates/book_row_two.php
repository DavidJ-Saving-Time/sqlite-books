<?php
// ── Parse identifiers ─────────────────────────────────────────────────────────
$bIds = [];
foreach (explode('|', $book['all_identifiers'] ?? '') as $pair) {
    $colon = strpos($pair, ':');
    if ($colon !== false) $bIds[substr($pair, 0, $colon)] = substr($pair, $colon + 1);
}
$bvWorkId    = $bIds['gr_work_id'] ?? '';
$bvGrRating  = $bIds['gr_rating']  ?? '';
$bvGrCount   = $bIds['gr_rating_count'] ?? '';
$bvGrPages   = $bIds['gr_pages']   ?? '';
$bvGoodreads = $bIds['goodreads']  ?? '';
$bvAmazon    = $bIds['amazon'] ?? $bIds['asin'] ?? '';

// ── Author links ──────────────────────────────────────────────────────────────
$authorIds   = array_values(array_filter(explode('|', $book['author_ids'] ?? ''), 'strlen'));
$authorNames = array_values(array_filter(explode('|', $book['authors']    ?? ''), 'strlen'));
$authorLinks = [];
foreach (array_slice(array_map(null, $authorIds, $authorNames), 0, 3) as [$aid, $aname]) {
    if (!$aid || !$aname) continue;
    $authorLinks[] = '<a href="list_books.php?author_id=' . urlencode($aid) . '" class="text-muted text-decoration-none">' . htmlspecialchars($aname) . '</a>';
}

// ── Cover URL ─────────────────────────────────────────────────────────────────
$coverSrc = getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg';

// ── Read URL ──────────────────────────────────────────────────────────────────
$readUrl = '';
if ($firstFile) {
    $ftype   = strtoupper(pathinfo($firstFile, PATHINFO_EXTENSION));
    $readUrl = $ftype === 'PDF'
        ? getLibraryWebPath() . '/' . $firstFile
        : 'reader.php?file=' . urlencode($firstFile);
}

// ── GR rating format ──────────────────────────────────────────────────────────
$bvRatingFmt = '';
if ($bvGrRating) {
    $n = (int)$bvGrCount;
    $cFmt = $n >= 1000000 ? round($n/1000000,1).'M' : ($n >= 1000 ? round($n/1000,1).'k' : ($n ?: ''));
    $bvRatingFmt = '★ ' . htmlspecialchars($bvGrRating) . ($cFmt ? " <span class='text-muted' style='font-size:0.78rem'>($cFmt)</span>" : '');
}

// ── Genre ─────────────────────────────────────────────────────────────────────
$firstGenreVal = '';
if (!empty($book['genres'])) {
    $g = explode('|', $book['genres'])[0];
    if ($g !== '') $firstGenreVal = $g;
}

// ── Tags ──────────────────────────────────────────────────────────────────────
$tagList = array_filter(explode('|', $book['tags'] ?? ''), 'strlen');

// ── Accordion unique IDs ──────────────────────────────────────────────────────
$bid    = (int)$book['id'];
$accId  = 'acc-' . $bid;
$simId  = 'sim-' . $bid;
$revId  = 'rev-' . $bid;
$hasSim = !empty($book['similar_count']);
?>
<?php
$_sibIds = [$bid];
if (!empty($seriesSiblings)) { foreach ($seriesSiblings as $_s) { $_sibIds[] = (int)$_s['id']; } }
?>
<div id="item-<?= $index ?>" class="col-12 list-item" data-book-block-id="<?= $bid ?>" data-book-index="<?= $index ?>" data-series-books="<?= implode(',', $_sibIds) ?>">
<div class="card shadow-sm">
<div class="card-body p-3">
<div class="container">

    <!-- ── Top: cover + info ─────────────────────────────────────────────── -->
    <div class="d-flex gap-4">

        <!-- Cover -->
        <div class="flex-shrink-0 cover-wrapper">
            <?php if (!empty($book['has_cover'])): ?>
            <a href="book-view.php?id=<?= $bid ?>">
                <img id="coverImage<?= $bid ?>"
                     src="<?= htmlspecialchars($coverSrc) ?>"
                     class="book-cover rounded shadow-sm"
                     style="width:340px;height:auto;display:block"
                     loading="lazy">
            </a>
            <?php else: ?>
            <div class="rounded bg-secondary-subtle d-flex align-items-center justify-content-center"
                 style="width:240px;height:330px">
                <i class="fa-solid fa-book fa-2x text-secondary"></i>
            </div>
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="flex-grow-1" style="min-width:0">

            <!-- Title -->
            <h5 class="mb-1 fw-bold">
                <?php if ($missing): ?><i class="fa-solid fa-circle-exclamation text-danger me-1"></i><?php endif; ?>
                <a href="book-view.php?id=<?= $bid ?>" class="text-decoration-none">
                    <?= htmlspecialchars($book['title']) ?>
                </a>
            </h5>

            <!-- Authors -->
            <?php if ($authorLinks): ?>
            <div class="mb-1" style="font-size:0.9rem"><?= implode(', ', $authorLinks) ?></div>
            <?php endif; ?>

            <!-- Series -->
            <?php if (!empty($book['series'])): ?>
            <div class="mb-2 text-muted" style="font-size:0.82rem">
                <i class="fa-solid fa-books fa-xs me-1"></i>
                <a href="list_books.php?series_id=<?= (int)$book['series_id'] ?>" class="text-muted text-decoration-none">
                    <?= htmlspecialchars($book['series']) ?><?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?> #<?= htmlspecialchars($book['series_index']) ?><?php endif; ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Meta: rating, pages, reading progress -->
            <div class="d-flex flex-wrap align-items-center gap-3 mb-2" style="font-size:0.82rem;color:var(--bs-secondary-color)">
                <?php if ($bvRatingFmt): ?>
                <span class="text-warning-emphasis"><?= $bvRatingFmt ?></span>
                <?php endif; ?>
                <?php if ($bvGrPages): ?>
                <span><i class="fa-solid fa-file-lines fa-xs me-1"></i><?= (int)$bvGrPages ?> pages</span>
                <?php endif; ?>
                <?php if (isset($deviceProgress[$bid])): ?>
                <?php $dp = $deviceProgress[$bid]; $fill = $dp['percent'] !== null ? round($dp['percent'] * 100) : 0; ?>
                <div class="d-flex align-items-center gap-1">
                    <div class="progress" style="width:60px;height:5px">
                        <div class="progress-bar" style="width:<?= $fill ?>%"></div>
                    </div>
                    <span><?= $fill ?>%</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tags / genre -->
            <?php if ($tagList): ?>
            <div class="d-flex flex-wrap gap-1 mb-2">
                <?php foreach (array_slice($tagList, 0, 6) as $tag): ?>
                <a href="list_books.php?genre=<?= urlencode($tag) ?>"
                   class="badge bg-secondary-subtle text-secondary-emphasis text-decoration-none fw-normal" style="font-size:0.7rem">
                    <?= htmlspecialchars($tag) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Full description -->
            <?php if (!empty($book['description'])): ?>
            <div class="two-desc two-desc-clamped" style="font-size:0.88rem;line-height:1.7">
                <?= $book['description'] ?>
            </div>
            <button type="button" class="two-desc-toggle btn btn-link btn-sm p-0 mt-1" style="font-size:0.8rem">View more</button>
            <?php endif; ?>

            <!-- Action buttons -->
            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php if ($readUrl): ?>
                <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($readUrl) ?>"
                   target="<?= strtoupper(pathinfo($firstFile, PATHINFO_EXTENSION)) === 'PDF' ? '_blank' : '_self' ?>">
                    <i class="fa-regular fa-book-open me-1"></i>Read
                </a>
                <?php endif; ?>
                <a class="btn btn-sm btn-secondary" href="book-view.php?id=<?= $bid ?>">
                    <i class="fa-solid fa-eye me-1"></i>View
                </a>
                <a class="btn btn-sm btn-secondary" href="book.php?id=<?= $bid ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-'.$index) ?>">
                    <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                </a>
                <?php if (!empty($onDevice[$bid])): ?>
                <button class="btn btn-sm btn-warning remove-from-device-row"
                        data-book-id="<?= $bid ?>"
                        data-device-path="<?= htmlspecialchars($onDevice[$bid]) ?>">
                    <i class="fa-solid fa-tablet-screen-button me-1"></i>Remove
                </button>
                <?php elseif ($firstFile): ?>
                <button class="btn btn-sm btn-secondary send-to-device-row" data-book-id="<?= $bid ?>">
                    <i class="fa-solid fa-paper-plane me-1"></i>Send
                </button>
                <?php endif; ?>
                <button class="btn btn-sm btn-danger delete-book" data-book-id="<?= $bid ?>">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>

        </div><!-- /info -->
    </div><!-- /top -->

    <!-- ── Sections: Series siblings + Similar + AI Recs + Reviews ─────── -->

    <?php if (!empty($seriesSiblings)):
        // Group siblings by subseries (empty string = no subseries)
        $sibsBySubseries = [];
        foreach ($seriesSiblings as $sib) {
            $sibsBySubseries[$sib['subseries'] ?? ''][] = $sib;
        }
        // Put the rep book's own subseries first
        $repSubseries = $book['subseries'] ?? '';
        if ($repSubseries !== '' && isset($sibsBySubseries[$repSubseries])) {
            $sibsBySubseries = [$repSubseries => $sibsBySubseries[$repSubseries]]
                             + $sibsBySubseries;
        }
        $multiSub = count($sibsBySubseries) > 1 || !array_key_exists('', $sibsBySubseries);
    ?>
    <!-- Also in this series -->
    <div class="mt-2 pt-2 border-top">
    <div class="border border-3 border-primary p-2 rounded" style="background:var(--metabar-bg,#F5F5F5)">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="fa-solid fa-books text-primary" style="font-size:0.8rem"></i>
            <span class="fw-semibold" style="font-size:0.85rem">Also in this series</span>
            <span class="badge bg-secondary fw-normal" style="font-size:0.7rem"><?= count($seriesSiblings) ?></span>
        </div>
        <?php foreach ($sibsBySubseries as $subName => $sibs): ?>
        <?php $rowHeading = trim(htmlspecialchars($book['series'] ?? '') . ($subName !== '' ? ' ' . '<i class="me-1 fa-duotone fa-solid fa-arrow-right-from-arc"></i> ' . htmlspecialchars($subName) : '')); ?>
        <div class="text-muted mb-1 mt-2 p-3 rounded" style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;background:var(--bs-border-color)">
        <i class="me-1 fa-duotone fa-solid fa-bars-staggered"></i> <?= $rowHeading ?> 
        </div>
        <div class="two-strip-wrap">
            <button class="two-strip-arrow two-strip-arrow-left" style="display:none" aria-label="Scroll left"><i class="fa-solid fa-chevron-left"></i></button>
            <div class="two-scroll-strip">
                <?php foreach ($sibs as $sib): ?>
                <?php $sibCover = getLibraryWebPath() . '/' . $sib['path'] . '/cover.jpg'; ?>
                <a href="book-view.php?id=<?= (int)$sib['id'] ?>" class="similar-thumb-card text-decoration-none flex-shrink-0">
                    <div class="similar-thumb-img">
                        <?php if ($sib['has_cover']): ?>
                        <img src="<?= htmlspecialchars($sibCover) ?>" alt="" class="similar-thumb img-thumbnail" loading="lazy">
                        <?php else: ?>
                        <div class="similar-thumb-placeholder"><i class="fa-solid fa-book"></i></div>
                        <?php endif; ?>
                    </div>

                </a>
                <?php endforeach; ?>
            </div>
            <button class="two-strip-arrow two-strip-arrow-right" style="display:none" aria-label="Scroll right"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
        <?php endforeach; ?>
    </div><!-- /metabar bg -->
    </div>
    <?php endif; ?>

    <?php if ($hasSim): ?>
    <!-- Similar Books (always visible, auto-loaded) -->
    <div class="mt-3 pt-2 border-top">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="fa-solid fa-list-ul text-primary" style="font-size:0.8rem"></i>
            <span class="fw-semibold" style="font-size:0.85rem">Similar Books</span>
            <?php if ($hasSim): ?>
            <span class="badge bg-secondary fw-normal" style="font-size:0.7rem"><?= (int)$book['similar_count'] ?></span>
            <?php endif; ?>
        </div>
        <div class="two-sim-body" data-book-id="<?= $bid ?>"></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($book['has_recs']) && !empty($book['rec_text'])): ?>
    <!-- AI Recommendations (always visible) -->
    <div class="mt-3 pt-2 border-top">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="fa-solid fa-robot text-primary" style="font-size:0.8rem"></i>
            <span class="fw-semibold" style="font-size:0.85rem">AI Recommendations</span>
        </div>
        <div class="two-rec-body"
             data-rec-json="<?= htmlspecialchars($book['rec_text']) ?>"></div>
    </div>
    <?php endif; ?>

    <!-- Reviews (collapsed until clicked) -->
    <div class="mt-2 pt-2 border-top">
        <div class="two-section-hdr d-flex align-items-center gap-2" data-two-target="<?= $revId ?>">
            <i class="fa-brands fa-goodreads text-primary" style="font-size:0.8rem"></i>
            <span class="fw-semibold" style="font-size:0.85rem">Reviews</span>
            <i class="fa-solid fa-chevron-down ms-auto two-section-chevron"></i>
        </div>
        <div id="<?= $revId ?>" class="two-rev-body"
             data-book-id="<?= $bid ?>" style="display:none"></div>
    </div>

</div><!-- /container -->
</div><!-- /card-body -->
</div><!-- /card -->
</div><!-- /col -->
