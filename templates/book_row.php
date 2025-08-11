<div id="item-<?= $index ?>" class="row g-3 py-3 border-bottom list-item" data-book-block-id="<?= htmlspecialchars($book['id']) ?>" data-book-index="<?= $index ?>">
            <!-- Left: Thumbnail -->
            <div class="col-md-2 col-12 text-center cover-wrapper">
                <?php if (!empty($book['has_cover'])): ?>
                    <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>">
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
            </div>

            <!-- Right: Title, Dropdowns, Description -->
            <div class="col-md-10 col-12">
                <!-- Title and Authors -->
                <div class="mb-2">
                    <?php if ($missing): ?>
                        <i class="fa-solid fa-circle-exclamation text-danger me-1" title="File missing"></i>
                    <?php endif; ?>
                    <?php
                        $goodreadsUrl = '';
                        if (!empty($book['authors'])) {
                            $firstAuthor = explode('|', $book['authors'])[0];
                            $nameParts = preg_split('/\s+/', trim($firstAuthor));
                            $firstName = $nameParts[0] ?? '';
                            $surname = $nameParts[count($nameParts) - 1] ?? '';
                            $query = trim($firstName . ' ' . $surname . ' ' . $book['title']);
                            $goodreadsUrl = 'https://www.goodreads.com/search?q=' . urlencode($query);
                        }
                    ?>

                    <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>" class="fw-bold book-title me-1"
                       data-book-id="<?= htmlspecialchars($book['id']) ?>">
                         <?= htmlspecialchars($book['title']) ?>
                    </a>
                    <?php if ($goodreadsUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($goodreadsUrl) ?>" target="_blank" class="ms-1 text-decoration-none">
                            <i class="fa-brands fa-goodreads"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($book['has_recs'])): ?>
                        <span class="text-success ms-1">&#10003;</span>
                    <?php endif; ?>
                    <?php if (!empty($book['series']) || !empty($book['subseries'])): ?>
                        <div class=" mt-1">
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
                                    $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>';
                                }
                                echo implode(', ', $links);
                                if (count($ids) > 3) echo '...';
                            ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dropdowns -->
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php
                        $firstGenreVal = '';
                        if (!empty($book['genres'])) {
                            $first = explode('|', $book['genres'])[0];
                            if ($first !== '') {
                                $firstGenreVal = $first;
                            }
                        }
                    ?>
                    <div>
                        <label class="small text-muted mb-1 d-block">Genre</label>
                        <select class="form-select form-select-sm genre-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <option value=""<?= $firstGenreVal === '' ? ' selected' : '' ?>>None</option>
                            <?php foreach ($genreList as $g): ?>
                                <option value="<?= htmlspecialchars($g['value']) ?>"<?= $g['value'] === $firstGenreVal ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($g['value']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="small text-muted mb-1 d-block">Shelf</label>
                        <select class="form-select form-select-sm shelf-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <?php foreach ($shelfList as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>"<?= $book['shelf'] === $s ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="small text-muted mb-1 d-block">Status</label>
                        <select class="form-select form-select-sm status-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <option value="Want to Read"<?= ($book['status'] === null || $book['status'] === '') ? ' selected' : '' ?>>Want to Read</option>
                            <?php foreach ($statusOptions as $s): ?>
                                <?php if ($s === 'Want to Read') continue; ?>
                                <option value="<?= htmlspecialchars($s) ?>"<?= $book['status'] === $s ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($s) ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($book['status'] !== null && $book['status'] !== '' && !in_array($book['status'], $statusOptions, true)): ?>
                                <option value="<?= htmlspecialchars($book['status']) ?>" selected><?= htmlspecialchars($book['status']) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="small text-muted mb-1 d-block">Rating</label>
                        <div class="star-rating" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="rating-star me-1 <?= ((int)$book['rating'] >= $i) ? 'fa-solid fa-star text-warning' : 'fa-regular fa-star text-muted' ?>" data-value="<?= $i ?>"></i>
                            <?php endfor; ?>
                            <i class="fa-solid fa-xmark rating-clear ms-1<?= ($book['rating'] > 0) ? '' : ' d-none' ?>" data-value="0" title="Clear rating"></i>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="ms-auto d-flex align-items-end">
                        <?php if ($firstFile):
                            $ftype = strtoupper(pathinfo($firstFile, PATHINFO_EXTENSION));
                            if ($ftype === 'PDF') {
                                $fileUrl = getLibraryWebPath() . '/' . $firstFile;
                                ?>
                                <a class="btn btn-sm btn-success me-1" target="_blank" href="<?= htmlspecialchars($fileUrl) ?>">Read <?= htmlspecialchars($ftype) ?></a>
                                <?php
                            } else {
                                ?>
                                <a class="btn btn-sm btn-success me-1" href="reader.php?file=<?= urlencode($firstFile) ?>"> <i class="fa-thumbprint fa-light fa-book-open"></i> Read <?= htmlspecialchars($ftype) ?></a>
                                <?php
                            }
                        endif; ?>
                        <button type="button" class="btn btn-sm btn-secondary openlibrary-meta me-1"
                                data-book-id="<?= htmlspecialchars($book['id']) ?>"
                                data-search="<?= htmlspecialchars($book['title'] . ' ' . $book['authors'], ENT_QUOTES) ?>">
                            Metadata Open Library
                        </button>
                        <a class="btn btn-sm btn-primary me-1" href="notes.php?id=<?= urlencode($book['id']) ?>">
                            Notes
                        </a>
                        <button type="button" class="btn btn-sm btn-danger delete-book"
                                data-book-id="<?= htmlspecialchars($book['id']) ?>">
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Description -->
                <div class="small text-muted book-description" data-full="<?php
                        $desc = strip_tags(trim($book['description'] ?? ''));
                        echo htmlspecialchars($desc, ENT_QUOTES);
                    ?>">
                    <?php
                        if ($desc !== '') {
                            $lines = preg_split('/\r?\n/', $desc);
                            $preview = implode("\n", array_slice($lines, 0, 2));
                            $truncated = count($lines) > 2;
                            $maxChars = 300;
                            if (mb_strlen($preview) > $maxChars) {
                                $preview = mb_substr($preview, 0, $maxChars);
                                $preview = preg_replace('/\s+\S*$/u', '', $preview);
                                $truncated = true;
                            }
                            echo nl2br(htmlspecialchars($preview));
                            if ($truncated) {
                                echo '... <a href="#" class="show-more">Show more</a>';
                            }
                        } else {
                            echo '&mdash;';
                        }
                    ?>
                </div>
            </div>
        </div>

