<div id="item-<?= $index ?>" class="col mb-4 list-item" data-book-block-id="<?= htmlspecialchars($book['id']) ?>" data-book-index="<?= $index ?>">
    <div class="card h-100">
        <div class="cover-wrapper position-relative">
            <?php if (!empty($book['has_cover'])): ?>
                <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>">
                    <img id="coverImage<?= (int)$book['id'] ?>" src="<?= htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg') ?>"
                         alt="Cover" class="card-img-top book-cover" loading="lazy">
                    <div id="coverDimensions<?= (int)$book['id'] ?>" class="cover-dimensions position-absolute bottom-0 end-0 bg-dark text-white px-1 small rounded-top-start opacity-75">Loading...</div>
                </a>
            <?php else: ?>
                &mdash;
            <?php endif; ?>
        </div>
        <div class="card-body p-2 d-flex flex-column">
            <h6 class="card-title mb-1">
                <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>" class="book-title" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                    <?= htmlspecialchars($book['title']) ?>
                </a>
            </h6>
            <div class="text-muted small mb-2 book-authors">
                <?php if (!empty($book['author_ids']) && !empty($book['authors'])): ?>
                    <?php
                        $ids = array_filter(explode('|', $book['author_ids']), 'strlen');
                        $names = array_filter(explode('|', $book['authors']), 'strlen');
                        $links = [];
                        foreach (array_slice(array_map(null, $ids, $names), 0, 3) as [$aid, $aname]) {
                            $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid) . '&view=' . urlencode($view);
                            $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($aname) . '</a>';
                        }
                        echo implode(', ', $links);
                        if (count($ids) > 3) echo '...';
                    ?>
                <?php else: ?>
                    &mdash;
                <?php endif; ?>
            </div>
            <div class="mt-auto">
                <div class="star-rating" data-book-id="<?= htmlspecialchars($book['id']) ?>">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="rating-star me-1 <?= ((int)$book['rating'] >= $i) ? 'fa-solid fa-star text-warning' : 'fa-regular fa-star text-muted' ?>" data-value="<?= $i ?>"></i>
                    <?php endfor; ?>
                    <i class="fa-solid fa-xmark rating-clear ms-1<?= ($book['rating'] > 0) ? '' : ' d-none' ?>" data-value="0" title="Clear rating"></i>
                </div>
            </div>
        </div>
    </div>
</div>

