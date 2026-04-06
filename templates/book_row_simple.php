<div id="item-<?= $index ?>" class="border-bottom list-item simple-row" data-book-block-id="<?= htmlspecialchars($book['id']) ?>" data-book-index="<?= $index ?>"
     data-cover="<?= $book['has_cover'] ? htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg') : '' ?>"
     data-title="<?= htmlspecialchars($book['title']) ?>"
     data-author="<?= htmlspecialchars(str_replace('|', ', ', $book['authors'] ?? '')) ?>">

    <!-- Checkbox -->
    <input type="checkbox" class="form-check-input bulk-select" data-book-id="<?= htmlspecialchars($book['id']) ?>" title="Select">

    <!-- Author -->
    <span class="text-muted small book-authors text-truncate">
        <?php
            $ids   = array_filter(explode('|', $book['author_ids'] ?? ''), 'strlen');
            $names = array_filter(explode('|', $book['authors'] ?? ''), 'strlen');
            if ($ids && $names) {
                $links = [];
                foreach (array_slice(array_map(null, $ids, $names), 0, 3) as [$aid, $aname]) {
                    $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid);
                    $links[] = '<a href="' . htmlspecialchars($url) . '" class="text-muted">' . htmlspecialchars($aname) . '</a>';
                }
                echo implode(', ', $links);
                if (count($ids) > 3) echo '…';
            } else {
                echo '&mdash;';
            }
        ?>
    </span>

    <!-- Title -->
    <span class="fw-semibold book-title text-truncate">
        <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>" class="text-decoration-none"><?= htmlspecialchars($book['title']) ?></a>
        <?php if (!empty($onDevice[$book['id']])): ?>
            <i class="fa-solid fa-tablet-screen-button text-success ms-1" title="On device"></i>
        <?php endif; ?>
    </span>

    <!-- Series -->
    <span class="text-muted small text-truncate">
        <?php if (!empty($book['series'])): ?>
            <a href="list_books.php?sort=<?= urlencode($sort) ?>&series_id=<?= urlencode($book['series_id']) ?>" class="text-muted">
                <?= htmlspecialchars($book['series']) ?></a><?php if ($book['series_index'] !== null && $book['series_index'] !== ''): ?> (<?= htmlspecialchars($book['series_index']) ?>)<?php endif; ?>
        <?php endif; ?>
    </span>

    <!-- Genre -->
    <?php
        $firstGenreVal = '';
        if (!empty($book['genres'])) {
            $first = explode('|', $book['genres'])[0];
            if ($first !== '') $firstGenreVal = $first;
        }
    ?>
    <select class="form-select form-select-sm genre-select" data-book-id="<?= htmlspecialchars($book['id']) ?>">
        <option value=""<?= $firstGenreVal === '' ? ' selected' : '' ?>>—</option>
        <?php foreach ($genreList as $g): ?>
            <option value="<?= htmlspecialchars($g['value']) ?>"<?= $g['value'] === $firstGenreVal ? ' selected' : '' ?>><?= htmlspecialchars($g['value']) ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Actions -->
    <div class="d-flex align-items-center gap-1 justify-content-end">
        <?php if (!empty($onDevice[$book['id']])): ?>
            <button type="button" class="btn btn-sm btn-outline-warning remove-from-device-row py-0 px-2"
                    data-book-id="<?= htmlspecialchars($book['id']) ?>"
                    data-device-path="<?= htmlspecialchars($onDevice[$book['id']]) ?>"
                    title="Remove from device">
                <i class="fa-solid fa-tablet-screen-button"></i>
            </button>
        <?php elseif ($firstFile): ?>
            <button type="button" class="btn btn-sm btn-outline-success send-to-device-row py-0 px-2"
                    data-book-id="<?= htmlspecialchars($book['id']) ?>"
                    data-book-page="<?= urlencode($page) ?>"
                    title="Send to device">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-danger delete-book py-0 px-2"
                data-book-id="<?= htmlspecialchars($book['id']) ?>">
            <i class="fa-solid fa-trash"></i>
        </button>
    </div>
</div>
