<article id="item-<?= (int)$index ?>"
         class="col mb-4 list-item"
         data-book-block-id="<?= htmlspecialchars($book['id']) ?>"
         data-book-index="<?= (int)$index ?>"
         itemscope itemtype="https://schema.org/Book">

  <div class="card h-100">
    <figure class="mb-0 position-relative">
      <div class="cover-wrapper position-relative ratio ratio-2x3">
  <?php if (!empty($book['has_cover'])): ?>
    <a href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>" class="d-block">
      <img
        id="coverImage<?= (int)$book['id'] ?>"
        src="<?= htmlspecialchars(getLibraryWebPath() . '/' . $book['path'] . '/cover.jpg') ?>"
        alt="Cover of <?= htmlspecialchars($book['title']) ?>"
        class="card-img-top book-cover w-100 h-100 object-fit-cover"
        loading="lazy" decoding="async">
      <div id="coverDimensions<?= (int)$book['id'] ?>"
           class="cover-dimensions position-absolute bottom-0 end-0 bg-dark text-white px-1 small rounded-top-start opacity-75">
        Loading…
      </div>
    </a>
  <?php else: ?>
    <div class="ratio ratio-2x3 bg-light d-flex align-items-center justify-content-center text-muted">
      <span class="small">No cover</span>
    </div>
  <?php endif; ?>
</div>
    </figure>

    <div class="card-body p-2 d-flex flex-column">
      <h6 class="card-title mb-1 line-clamp-2" itemprop="name">
        <a class="stretched-link text-decoration-none book-title"
           href="book.php?id=<?= urlencode($book['id']) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>"
           data-book-id="<?= htmlspecialchars($book['id']) ?>">
          <?= htmlspecialchars($book['title']) ?>
        </a>
      </h6>

      <div class="text-muted small mb-2 book-authors" itemprop="author">
        <?php if (!empty($book['author_ids']) && !empty($book['authors'])): ?>
          <?php
            $ids   = array_values(array_filter(explode('|', $book['author_ids']), 'strlen'));
            $names = array_values(array_filter(explode('|', $book['authors']), 'strlen'));
            $pairs = array_slice(array_map(null, $ids, $names), 0, 3);
            $out   = [];
            foreach ($pairs as [$aid, $aname]) {
              $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid) . '&view=' . urlencode($view);
              $out[] = '<a href="' . htmlspecialchars($url) . '" class="text-muted text-decoration-none" itemprop="name">' . htmlspecialchars($aname) . '</a>';
            }
            echo implode(', ', $out);
            if (count($ids) > 3) echo '…';
          ?>
        <?php else: ?>
          <span class="fst-italic">Unknown author</span>
        <?php endif; ?>
      </div>

      <div class="mt-auto">
        <?php $currentRating = (int)$book['rating']; ?>
        <div class="star-rating d-inline-flex align-items-center gap-1"
             role="radiogroup"
             aria-label="Rate this book"
             data-book-id="<?= htmlspecialchars($book['id']) ?>">

          <?php for ($i = 1; $i <= 5; $i++):
            $checked = $currentRating >= $i;
          ?>
            <button type="button"
                    class="btn btn-link p-0 m-0 rating-star <?= $checked ? 'text-warning' : 'text-muted' ?>"
                    data-value="<?= $i ?>"
                    role="radio"
                    aria-checked="<?= $checked ? 'true' : 'false' ?>"
                    aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">
              <i class="<?= $checked ? 'fa-solid fa-star' : 'fa-regular fa-star' ?>"></i>
              <span class="visually-hidden"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></span>
            </button>
          <?php endfor; ?>

          <button type="button"
                  class="btn btn-link p-0 m-0 ms-1 rating-clear <?= ($currentRating > 0) ? '' : 'd-none' ?>"
                  data-value="0"
                  aria-label="Clear rating">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</article>
