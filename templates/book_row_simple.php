<?php
$readUrl = '';
if ($firstFile) {
    $ftype = strtoupper(pathinfo($firstFile, PATHINFO_EXTENSION));
    $readUrl = $ftype === 'PDF'
        ? getLibraryWebPath() . '/' . $firstFile
        : 'reader.php?file=' . urlencode($firstFile);
}
?>
<div id="item-<?= $index ?>" class="border-bottom list-item simple-row" data-book-block-id="<?= htmlspecialchars($book['id']) ?>" data-book-index="<?= $index ?>"
     data-cover="<?= $book['has_cover'] ? htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg?v=' . (int)(strtotime($book['last_modified'] ?? '') ?: 0)) : '' ?>"
     data-title="<?= htmlspecialchars($book['title']) ?>"
     data-author="<?= htmlspecialchars(str_replace('|', ', ', $book['authors'] ?? '')) ?>"
     data-author-ids="<?= htmlspecialchars($book['author_ids'] ?? '') ?>"
     data-description="<?= htmlspecialchars($book['description'] ?? '') ?>"
     data-identifiers="<?= htmlspecialchars($book['all_identifiers'] ?? '') ?>"
     data-read-url="<?= htmlspecialchars($readUrl) ?>"
     data-similar-count="<?= (int)($book['similar_count'] ?? 0) ?>">

    <!-- Checkbox -->
    <label class="bulk-select-label" title="Select">
        <input type="checkbox" class="form-check-input bulk-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
    </label>

    <!-- Author -->
    <?php
        $authorIds   = array_values(array_filter(explode('|', $book['author_ids'] ?? ''), 'strlen'));
        $authorNames = array_values(array_filter(explode('|', $book['authors'] ?? ''), 'strlen'));
    ?>
    <span class="text-muted small book-authors editable-cell"
          data-field="author"
          data-book-id="<?= htmlspecialchars($book['id']) ?>"
          data-authors="<?= htmlspecialchars($book['authors'] ?? '') ?>">
        <span class="cell-display" title="Click to edit">
            <i class="fa-duotone fa-solid fa-user author-info-btn"
               data-author-id="<?= (int)($authorIds[0] ?? 0) ?>"
               data-author-name="<?= htmlspecialchars($authorNames[0] ?? '', ENT_QUOTES) ?>"
               data-readonly="1"
               role="button"
               style="cursor:pointer"
               title="View author info"></i>
            <?php if ($authorNames): ?>
                <?php if (!empty($book['has_hugo_nebula'])): ?>
                    <span style="color:var(--hugo-nebula-author,#e8a000);font-weight:600;" title="Hugo &amp; Nebula winner">
                        <?= htmlspecialchars(implode(', ', array_slice($authorNames, 0, 3))) ?><?= count($authorNames) > 3 ? '…' : '' ?>
                    </span>
                <?php else: ?>
                    <?= htmlspecialchars(implode(', ', array_slice($authorNames, 0, 3))) ?><?= count($authorNames) > 3 ? '…' : '' ?>
                <?php endif; ?>
            <?php else: ?>&mdash;<?php endif; ?>
        </span>
    </span>

    <!-- Title -->
    <div class="d-flex flex-column justify-content-center min-width-0" style="min-width:0">
        <div class="d-flex align-items-baseline gap-2 min-width-0" style="min-width:0">
            <span class="fw-semibold book-title editable-cell"
                  data-field="title"
                  data-book-id="<?= htmlspecialchars($book['id']) ?>">
                <?php
                if (!empty($book['won_awards_detail']))           $titleAwardStyle = 'color:var(--award-won,#f0b400)';
                elseif (!empty($book['citation_awards_detail']))  $titleAwardStyle = 'color:var(--award-citation,#cd7f32)';
                elseif (!empty($book['nominated_awards_detail'])) $titleAwardStyle = 'color:var(--award-nominated,#a0a0a0)';
                else                                              $titleAwardStyle = '';
                ?>
                <span class="cell-display" title="Click to edit"<?= $titleAwardStyle ? ' style="' . $titleAwardStyle . '"' : '' ?>>
                    <?php if (!empty($onDevice[$book['id']])): ?>
                        <i class="fa-solid fa-tablet-screen-button text-success me-1" style="font-size:0.75rem" title="On device"></i>
                    <?php endif; ?>
                    <?php if (!empty($book['similar_count'])): ?>
                        <i class="fa-duotone fa-light fa-books text-info me-1" style="font-size:0.75rem" title="Similar books fetched"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($book['title']) ?>
                </span>
            </span>
            <?php if (!empty($book['gr_pages'])): ?>
                <span class="text-muted flex-shrink-0" style="font-size:0.65rem;white-space:nowrap text-muted"> (<?= (int)$book['gr_pages'] ?>)</span>
            <?php endif; ?>
            <a href="book-view.php?id=<?= (int)$book['id'] ?>"
               class="flex-grow-1 align-self-stretch"
               tabindex="-1"
               aria-hidden="true"
               style="min-width:0;display:block"></a>
        </div><!-- end title row -->
        <?php if (!empty($book['won_awards_detail'])): ?>
        <span class="text-truncate" style="font-size:0.68rem;line-height:1.3;color:var(--award-won,#f0b400)">
        <i class="fa-duotone fa-solid fa-arrow-turn-down-right me-1"> </i><i class="fa-solid fa-trophy me-1 " style="font-size:0.6rem"></i><?= htmlspecialchars($book['won_awards_detail']) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($book['citation_awards_detail'])): ?>
        <span class="text-truncate " style="font-size:0.68rem;line-height:1.3;color:var(--award-citation,#cd7f32)">
        <i class="fa-duotone fa-solid fa-arrow-turn-down-right me-1"> </i>  <i class="fa-solid fa-certificate me-1 " style="font-size:0.6rem"></i><?= htmlspecialchars($book['citation_awards_detail']) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($book['nominated_awards_detail'])): ?>
        <span class="text-truncate" style="font-size:0.68rem;line-height:1.3;color:var(--award-nominated,#a0a0a0)">
        <i class="fa-duotone fa-solid fa-arrow-turn-down-right me-1"> </i>  <i class="fa-solid fa-trophy me-1 " style="font-size:0.6rem"></i><?= htmlspecialchars($book['nominated_awards_detail']) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($book['gr_work_id']) && !empty($book['tags'])): ?>
            <span class="text-muted text-truncate " style="font-size:0.68rem;line-height:1.3"><i class="fa-duotone fa-solid fa-arrow-turn-down-right me-1"> </i> 
            <?php
            $shelfCounts = [];
            if (!empty($book['gr_shelf_counts'])) {
                foreach (explode(';', $book['gr_shelf_counts']) as $pair) {
                    [$sName, $sCnt] = explode(':', $pair, 2) + ['', '0'];
                    $shelfCounts[strtolower(trim($sName))] = (int)$sCnt;
                }
            }
            $tagsSorted = [];
            foreach (explode('|', $book['tags']) as $tag) {
                $tagsSorted[$tag] = $shelfCounts[strtolower($tag)] ?? 0;
            }
            arsort($tagsSorted);
            $tagParts = [];
            foreach ($tagsSorted as $tag => $cnt) {
                $label = ucwords(str_replace('-', ' ', $tag));
                $fmtCnt = $cnt > 0 ? (' <span style="opacity:0.6">(' . ($cnt >= 1000 ? round($cnt/1000, 1).'k' : $cnt) . ')</span>') : '';
                $tagParts[] = htmlspecialchars($label) . $fmtCnt;
            }
            echo implode(' · ', $tagParts);
            ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- GR Rating -->
    <span class="text-muted gr-rating-cell" style="font-size:0.8rem; white-space:nowrap">
        <?php if (!empty($book['gr_rating'])): ?>
            <?= htmlspecialchars($book['gr_rating']) ?>
            <?php if (!empty($book['gr_rating_count'])):
                $n = (int)$book['gr_rating_count'];
                $fmt = $n >= 1000000 ? round($n/1000000,1).'M' : ($n >= 1000 ? round($n/1000,1).'k' : $n);
            ?><span style="font-size:0.75rem"> (<?= $fmt ?>)</span><?php endif; ?>
        <?php endif; ?>
    </span>

    <!-- GR ID -->
    <span class="editable-cell"
          data-field="goodreads"
          data-book-id="<?= htmlspecialchars($book['id']) ?>"
          data-goodreads="<?= htmlspecialchars($book['goodreads'] ?? '') ?>">
        <span class="cell-display" title="Click to edit Goodreads ID" style="font-size:0.8rem;white-space:nowrap">
            <?php if (!empty($book['goodreads'])): ?>
                <?= htmlspecialchars($book['goodreads']) ?>
            <?php else: ?>
                <span class="text-muted" style="opacity:0.45;font-size:0.8em">+ gr id</span>
            <?php endif; ?>
        </span>
    </span>

    <!-- Series + Subseries -->
    <span class="text-muted small d-flex flex-column gap-0">
        <span class="editable-cell"
              data-field="series"
              data-book-id="<?= htmlspecialchars($book['id']) ?>"
              data-series-name="<?= htmlspecialchars($book['series'] ?? '') ?>"
              data-series-index="<?= htmlspecialchars($book['series_index'] ?? '') ?>"
              data-series-id="<?= htmlspecialchars($book['series_id'] ?? '') ?>">
            <span class="cell-display" title="Click to edit">
                <?php if (!empty($book['series'])): ?>
                    <?= htmlspecialchars($book['series']) ?><?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?> (<?= htmlspecialchars($book['series_index']) ?>)<?php endif; ?>
                <?php else: ?>
                    <span class="text-muted" style="opacity:0.45;font-size:0.8em">+ series</span>
                <?php endif; ?>
            </span>
        </span>
        <span class="editable-cell"
              data-field="subseries"
              data-book-id="<?= htmlspecialchars($book['id']) ?>"
              data-subseries-name="<?= htmlspecialchars($book['subseries'] ?? '') ?>"
              data-subseries-index="<?= htmlspecialchars($book['subseries_index'] ?? '') ?>">
            <span class="cell-display" title="Click to edit subseries">
                <?php if (!empty($book['subseries'])): ?>
                    <i class="fa-duotone fa-solid fa-arrow-turn-down-right"></i> <?= htmlspecialchars($book['subseries']) ?><?php if ($book['subseries_index'] !== null && $book['subseries_index'] !== ''): ?> (<?= htmlspecialchars($book['subseries_index']) ?>)<?php endif; ?>
                <?php else: ?>
                    <span class="text-muted" style="opacity:0.45;font-size:0.8em">+ subseries</span>
                <?php endif; ?>
            </span>
        </span>
    </span>

    <!-- Genre -->
    <?php
        $firstGenreVal = '';
        if (!empty($book['genres'])) {
            $first = explode('|', $book['genres'])[0];
            if ($first !== '') $firstGenreVal = $first;
        }
    ?>
    <div class="d-flex align-items-center gap-1">
        <select class="form-select form-select-sm genre-select flex-grow-1" data-book-id="<?= htmlspecialchars($book['id']) ?>" data-current="<?= htmlspecialchars($firstGenreVal) ?>">
            <option value=""<?= $firstGenreVal === '' ? ' selected' : '' ?>>—</option>
            <?php if ($firstGenreVal !== ''): ?>
                <option value="<?= htmlspecialchars($firstGenreVal) ?>" selected><?= htmlspecialchars($firstGenreVal) ?></option>
            <?php endif; ?>
        </select>
        <input type="checkbox" class="form-check-input genre-all-authors flex-shrink-0" style="cursor:pointer;margin-top:0"
               title="Apply genre to all books by this author">
    </div>

    <div style="display:none" aria-hidden="true">
        <button type="button" class="wiki-book-btn"
                data-book-id="<?= (int)$book['id'] ?>"
                data-book-title="<?= htmlspecialchars($book['title']) ?>"
                data-wiki-cached="<?= !empty($book['wiki_book']) ? '1' : '0' ?>"></button>
        <?php if (!empty($onDevice[$book['id']])): ?>
            <button type="button" class="remove-from-device-row"
                    data-book-id="<?= htmlspecialchars($book['id']) ?>"
                    data-device-path="<?= htmlspecialchars($onDevice[$book['id']]) ?>"></button>
        <?php elseif ($firstFile): ?>
            <button type="button" class="send-to-device-row"
                    data-book-id="<?= htmlspecialchars($book['id']) ?>"
                    data-book-page="<?= urlencode($page) ?>"></button>
        <?php endif; ?>
        <button type="button" class="delete-book"
                data-book-id="<?= htmlspecialchars($book['id']) ?>"></button>
        <button type="button" class="openlibrary-meta"
                data-book-id="<?= htmlspecialchars($book['id']) ?>"
                data-search="<?= htmlspecialchars($book['title'] . ' ' . ($book['authors'] ?? ''), ENT_QUOTES) ?>"
                data-isbn="<?= htmlspecialchars($book['isbn'] ?? '', ENT_QUOTES) ?>"
                data-olid="<?= htmlspecialchars($book['olid'] ?? '', ENT_QUOTES) ?>"></button>
    </div>
</div>
