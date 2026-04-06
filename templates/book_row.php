<div id="item-<?= $index ?>" class="row g-3 py-3 border-bottom list-item" data-book-block-id="<?= htmlspecialchars($book['id']) ?>" data-book-index="<?= $index ?>">
    <!-- Left: Thumbnail -->
    <div class="col-md-2 col-12 text-center cover-wrapper">
        <?php if (!empty($book['has_cover'])): ?>
            <a href="book.php?id=<?= urlencode($book['id']) ?>&sort=<?= urlencode($sort) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>">
                <div class="position-relative d-inline-block">
                    <img id="coverImage<?= (int)$book['id'] ?>" src="<?= htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg') ?>"
                        alt="Cover"
                        class="img-thumbnail img-fluid book-cover"
                        loading="lazy"
                        style="width: 100%; max-width:150px; height:auto;">
                    <div id="coverDimensions<?= (int)$book['id'] ?>" class="cover-dimensions position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 0.8rem;">Loading...</div>
                </div>
            </a>
        <?php else: ?>
            &mdash;
        <?php endif; ?>
        <div class="mt-1">
            <a href="#" class="rec-link small"
               data-book-id="<?= (int)$book['id'] ?>"
               data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
               data-authors="<?= htmlspecialchars($book['authors'] ?? '', ENT_QUOTES) ?>"
               data-genres="<?= htmlspecialchars(str_replace('|', ', ', $book['genres'] ?? ''), ENT_QUOTES) ?>"
               data-rec-text="<?= htmlspecialchars($book['rec_text'] ?? '', ENT_QUOTES) ?>">
                <?php if (!empty($book['has_recs'])): ?>
                    <i class="fa-solid fa-star text-warning me-1"></i>
                <?php endif; ?>
                Recommendations
            </a>
        </div>
    </div>

    <!-- Right: Title, Dropdowns, Description -->
    <div class="col-md-10 col-12">
        <!-- Title, Authors and Actions -->
        <div class="d-flex align-items-start gap-2 mb-2">
            <!-- Title block -->
            <div class="flex-grow-1">
                <?php if ($missing): ?>
                    <i class="fa-solid fa-circle-exclamation text-danger me-1" title="File missing"></i>
                <?php endif; ?>
                <a href="book.php?id=<?= urlencode($book['id']) ?>&sort=<?= urlencode($sort) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>"
                    class="fw-bold book-title me-1"
                    data-book-id="<?= htmlspecialchars($book['id']) ?>">
                    <?= htmlspecialchars($book['title']) ?>
                </a><button type="button" class="btn btn-link btn-sm p-0 ms-1 title-edit-btn text-muted"
                    data-book-id="<?= (int)$book['id'] ?>"
                    title="Edit title"><i class="fa-solid fa-pencil fa-xs"></i></button>
                <?php if (!empty($book['goodreads'])): ?>
                    <a href="https://www.goodreads.com/book/show/<?= urlencode($book['goodreads']) ?>" target="_blank" class="ms-1 text-decoration-none text-muted" title="Goodreads">
                        <i class="fa-brands fa-goodreads"></i>
                    </a>
                <?php endif; ?>
                <?php if (!empty($book['amazon'])): ?>
                    <a href="https://www.amazon.com/dp/<?= urlencode($book['amazon']) ?>" target="_blank" class="ms-1 text-decoration-none text-muted" title="Amazon">
                        <i class="fa-brands fa-amazon"></i>
                    </a>
                <?php endif; ?>
                <?php if (!empty($book['librarything'])): ?>
                    <a href="https://www.librarything.com/work/<?= urlencode($book['librarything']) ?>" target="_blank" class="ms-1 text-decoration-none text-muted" title="LibraryThing">
                        <i class="fa-solid fa-building-columns fa-xs"></i>
                    </a>
                <?php endif; ?>
                <?php if (!empty($onDevice[$book['id']])): ?>
                    <i class="fa-solid fa-tablet-screen-button text-success ms-1" title="On device"></i>
                <?php endif; ?>
                <?php if (!empty($book['series']) || !empty($book['subseries'])): ?>
                    <div class="mt-1">
                        <i class="fa-duotone fa-solid fa-arrow-turn-down-right"></i>
                        <?php if (!empty($book['series'])): ?>
                            <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>">
                                <?= htmlspecialchars($book['series']) ?>
                            </a>
                            <?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?>
                                (<?= htmlspecialchars($book['series_index']) ?>)
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($book['subseries'])): ?>
                            &gt;
                            <a href="list_books.php?sort=<?= urlencode($sort) ?>&subseries_id=<?= urlencode($book['subseries_id']) ?>">
                                <?= htmlspecialchars($book['subseries']) ?>
                            </a>
                            <?php if ($book['subseries_index'] !== null && $book['subseries_index'] !== ''): ?>
                                (<?= htmlspecialchars($book['subseries_index']) ?>)
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="text-muted small book-authors">
                    <?php if (!empty($book['author_ids']) && !empty($book['authors'])): ?>
                        <?php
                        $ids = array_filter(explode('|', $book['author_ids']), 'strlen');
                        $names = array_filter(explode('|', $book['authors']), 'strlen');
                        $links = [];
                        foreach (array_slice(array_map(null, $ids, $names), 0, 3) as [$aid, $aname]) {
                            $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid);
                            $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>'
                                . '<a href="#" class="author-info-btn ms-1 text-muted" data-author-id="' . (int)$aid . '" data-author-name="' . htmlspecialchars($aname, ENT_QUOTES) . '" title="Author info"><i class="fa-solid fa-circle-info fa-xs"></i></a>';
                        }
                        echo implode(', ', $links);
                        if (count($ids) > 3) echo '...';
                        ?>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </div>
            </div>
            <!-- Action buttons + Rating -->
            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <div class="star-rating me-1" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="rating-star me-1 <?= ((int)$book['rating'] >= $i) ? 'fa-solid fa-star text-warning' : 'fa-regular fa-star text-muted' ?>" data-value="<?= $i ?>"></i>
                    <?php endfor; ?>
                    <i class="fa-solid fa-xmark rating-clear ms-1<?= ($book['rating'] > 0) ? '' : ' d-none' ?>" data-value="0" title="Clear rating"></i>
                </div>
                <?php if ($firstFile):
                    $ftype = strtoupper(pathinfo($firstFile, PATHINFO_EXTENSION));
                    if ($ftype === 'PDF') {
                        $fileUrl = getLibraryWebPath() . '/' . $firstFile; ?>
                        <a class="btn btn-sm btn-primary" target="_blank" href="<?= htmlspecialchars($fileUrl) ?>">Read <?= htmlspecialchars($ftype) ?></a>
                    <?php } else { ?>
                        <a class="btn btn-sm btn-primary" href="reader.php?file=<?= urlencode($firstFile) ?>"><i class="fa-light fa-book-open me-1"></i>Read <?= htmlspecialchars($ftype) ?></a>
                <?php }
                endif; ?>
                <?php if (!empty($onDevice[$book['id']])): ?>
                    <button type="button" class="btn btn-sm btn-secondary remove-from-device-row"
                        data-book-id="<?= htmlspecialchars($book['id']) ?>"
                        data-device-path="<?= htmlspecialchars($onDevice[$book['id']]) ?>"
                        title="Remove from device">
                        <i class="fa-solid fa-tablet-screen-button me-1"></i>Remove from device
                    </button>
                <?php elseif ($firstFile): ?>
                    <button type="button" class="btn btn-sm btn-primary send-to-device-row"
                        data-book-id="<?= htmlspecialchars($book['id']) ?>"
                        title="Send to device">
                        <i class="fa-solid fa-paper-plane me-1"></i>Send to device
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-secondary openlibrary-meta"
                    data-book-id="<?= htmlspecialchars($book['id']) ?>"
                    data-search="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>"
                    data-isbn="<?= htmlspecialchars($book['isbn'] ?? '', ENT_QUOTES) ?>"
                    data-olid="<?= htmlspecialchars($book['olid'] ?? '', ENT_QUOTES) ?>">
                    OL Meta
                </button>

                <button type="button" class="btn btn-sm btn-warning delete-book"
                    data-book-id="<?= htmlspecialchars($book['id']) ?>">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>

        <!-- Description -->
        <?php
        $descRaw  = trim($book['description'] ?? '');
        $desc     = strip_tags($descRaw);
        $lines    = $desc !== '' ? preg_split('/\r?\n/', $desc) : [];
        $preview  = implode("\n", array_slice($lines, 0, 2));
        if (mb_strlen($preview) > 300) {
            $preview = mb_substr($preview, 0, 300);
            $preview = preg_replace('/\s+\S*$/u', '', $preview) . '…';
        }
        ?>
        <div class="small text-muted book-description mb-4"
             data-full="<?= htmlspecialchars($desc, ENT_QUOTES) ?>"
             data-html="<?= htmlspecialchars($descRaw, ENT_QUOTES) ?>"
             data-book-id="<?= (int)$book['id'] ?>"
             data-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
             data-authors="<?= htmlspecialchars($book['authors'] ?? '', ENT_QUOTES) ?>">
            <?= $preview !== '' ? nl2br(htmlspecialchars($preview)) . ' ' : '' ?><a href="#" class="desc-edit">Edit</a>
        </div>


        <!-- Metadata Bar: Genre / Shelf / Status / Series / Index -->

        <div class="metadata-bar mb-2">

            <style>
                .metadata-bar {
                    background: var(--metabar-bg, #F5F5F5);
                    border: 1px solid var(--metabar-border, #CFCFCF);
                    border-top: 5px solid var(--metabar-border, #CFCFCF);
                    border-radius: .35rem;
                    padding: .6rem .9rem;
                }

                .metadata-bar label {
                    font-size: .72rem;
                    color: var(--metabar-label, #7A7A7A);
                }

                .metadata-bar .form-select-sm,
                .metadata-bar .form-control-sm {
                    min-height: 30px;
                    padding-top: 2px;
                    padding-bottom: 2px;
                }


                .progress-bar {
                    font-size: 1rem !important;
                    line-height: 1rem !important;
                }

                .title-edit-btn { opacity: 0; transition: opacity .15s; }
                .flex-grow-1:hover .title-edit-btn { opacity: 1; }
            </style>

            <div class="d-flex flex-wrap gap-3 align-items-end">

                <?php
                $firstGenreVal = '';
                if (!empty($book['genres'])) {
                    $first = explode('|', $book['genres'])[0];
                    if ($first !== '') {
                        $firstGenreVal = $first;
                    }
                }
                ?>

                <!-- GENRE -->
                <div>
                    <label class="mb-1 d-block">
                        <i class="fa-solid fa-tags me-1"></i>Genre
                    </label>

                    <select class="form-select form-select-sm genre-select"
                        data-book-id="<?= htmlspecialchars($book['id']) ?>">

                        <option value="" <?= $firstGenreVal === '' ? ' selected' : '' ?>>None</option>

                        <?php foreach ($genreList as $g): ?>
                            <option value="<?= htmlspecialchars($g['value']) ?>"
                                <?= $g['value'] === $firstGenreVal ? ' selected' : '' ?>>
                                <?= htmlspecialchars($g['value']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>


                <!-- SHELF -->
                <div>
                    <label class="mb-1 d-block">
                        <i class="fa-solid fa-layer-group me-1"></i>Shelf
                    </label>

                    <select class="form-select form-select-sm shelf-select"
                        data-book-id="<?= htmlspecialchars($book['id']) ?>">

                        <?php foreach ($shelfList as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>"
                                <?= $book['shelf'] === $s ? ' selected' : '' ?>>
                                <?= htmlspecialchars($s) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>


                <!-- STATUS -->
                <div>
                    <label class="mb-1 d-block">
                        <i class="fa-solid fa-bookmark me-1"></i>Status
                    </label>

                    <select class="form-select form-select-sm status-select"
                        data-book-id="<?= htmlspecialchars($book['id']) ?>">

                        <option value="Want to Read"
                            <?= ($book['status'] === null || $book['status'] === '') ? ' selected' : '' ?>>
                            Want to Read
                        </option>

                        <?php foreach ($statusOptions as $s): ?>
                            <?php if ($s === 'Want to Read') continue; ?>

                            <option value="<?= htmlspecialchars($s) ?>"
                                <?= $book['status'] === $s ? ' selected' : '' ?>>
                                <?= htmlspecialchars($s) ?>
                            </option>

                        <?php endforeach; ?>

                        <?php if ($book['status'] !== null && $book['status'] !== '' && !in_array($book['status'], $statusOptions, true)): ?>

                            <option value="<?= htmlspecialchars($book['status']) ?>" selected>
                                <?= htmlspecialchars($book['status']) ?>
                            </option>

                        <?php endif; ?>

                    </select>
                </div>


                <!-- SERIES -->
                <div class="position-relative">

                    <label class="mb-1 d-block">
                        <i class="fa-solid fa-books me-1"></i>Series
                    </label>

                    <input type="text"
                        class="form-control form-control-sm series-name-input"
                        style="width:14rem"
                        data-book-id="<?= htmlspecialchars($book['id']) ?>"
                        data-series-id="<?= htmlspecialchars($book['series_id'] ?? '') ?>"
                        value="<?= htmlspecialchars($book['series'] ?? '') ?>"
                        placeholder="None"
                        autocomplete="off">

                    <ul class="series-suggestions list-group position-absolute w-100"
                        style="z-index:1050;display:none;max-height:200px;overflow-y:auto;">
                    </ul>

                </div>


                <!-- INDEX -->
                <div>

                    <label class="mb-1 d-block">
                        <i class="fa-solid fa-list-ol me-1"></i>Index
                    </label>

                    <input type="number"
                        step="0.1"
                        min="0"
                        class="form-control form-control-sm series-index-input"
                        style="width:5rem"
                        data-book-id="<?= htmlspecialchars($book['id']) ?>"
                        value="<?= htmlspecialchars($book['series_index'] ?? '') ?>"
                        placeholder="#">

                </div>

            </div>

        </div>


        <?php if (isset($deviceProgress[$book['id']])): ?>
            <?php $dp = $deviceProgress[$book['id']];
            $fill = $dp['percent'] !== null ? round($dp['percent'] * 100) : 0; ?>

            <?php if (!empty($dp['last_accessed'])): ?>
                <div class="text-muted small mt-1">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i>Last read on device: <?= htmlspecialchars($dp['last_accessed']) ?>
                </div>
            <?php endif; ?>

            <div class="mt-1" style="max-width:54rem;" title="<?= $fill ?>% read<?= $dp['pages'] !== null ? ' · ' . (int)$dp['pages'] . ' pages' : '' ?>">


                <div class="progress bg-dark border" style="height:1.5rem">
                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $fill ?>%;" aria-valuenow="<?= $fill ?>" aria-valuemin="0" aria-valuemax="100"><?= $fill ?>% of
                        <?php if ($dp['pages'] !== null): ?>
                            <?= (int)$dp['pages'] ?> pages
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>
</div>