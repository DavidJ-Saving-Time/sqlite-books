<article id="item-<?= (int)$index ?>"
         class="col mb-4 list-item"
         data-book-block-id="<?= htmlspecialchars($book['id']) ?>"
         data-book-index="<?= (int)$index ?>"
         itemscope itemtype="https://schema.org/Book">

  <div class="card h-100">
    <figure class="mb-0 position-relative">
      <div class="cover-wrapper position-relative ratio ratio-2x3">
  <?php if (!empty($book['has_cover'])): ?>
    <a href="book.php?id=<?= urlencode($book['id']) ?>&sort=<?= urlencode($sort) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>" class="d-block">
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
           href="book.php?id=<?= urlencode($book['id']) ?>&sort=<?= urlencode($sort) ?>&page=<?= urlencode($page) ?>&item=<?= urlencode('item-' . $index) ?>"
           data-book-id="<?= htmlspecialchars($book['id']) ?>">
          <?= htmlspecialchars($book['title']) ?>
        </a>
        <?php if (!empty($book['has_won_award'])): ?>
            <i class="fa-solid fa-trophy text-warning" title="Award winner" style="font-size:0.75rem;"></i>
        <?php endif; ?>
        <?php if (!empty($onDevice[$book['id']])): ?>
            <i class="fa-solid fa-tablet-screen-button text-success" title="On device" style="font-size:0.75rem;"></i>
        <?php endif; ?>
      </h6>

      <div class="text-muted small mb-2 book-authors" itemprop="author">
        <?php if (!empty($book['author_ids']) && !empty($book['authors'])): ?>
          <?php
            $ids         = array_values(array_filter(explode('|', $book['author_ids']), 'strlen'));
            $names       = array_values(array_filter(explode('|', $book['authors']), 'strlen'));
            $pairs       = array_slice(array_map(null, $ids, $names), 0, 3);
            $hugoNebula  = !empty($book['has_hugo_nebula']);
            $out         = [];
            foreach ($pairs as [$aid, $aname]) {
              $url = 'list_books.php?sort=' . urlencode($sort) . '&author_id=' . urlencode($aid) . '&view=' . urlencode($view);
              if ($hugoNebula) {
                $out[] = '<a href="' . htmlspecialchars($url) . '" class="text-decoration-none fw-semibold" style="color:var(--hugo-nebula-author,#e8a000);" title="Hugo &amp; Nebula winner" itemprop="name">' . htmlspecialchars($aname) . '</a>';
              } else {
                $out[] = '<a href="' . htmlspecialchars($url) . '" class="text-muted text-decoration-none" itemprop="name">' . htmlspecialchars($aname) . '</a>';
              }
            }
            echo implode(', ', $out);
            if (count($ids) > 3) echo '…';
          ?>
        <?php else: ?>
          <span class="fst-italic">Unknown author</span>
        <?php endif; ?>
      </div>

      <?php if (isset($deviceProgress[$book['id']])): ?>
      <?php $dp = $deviceProgress[$book['id']]; $fill = $dp['percent'] !== null ? round($dp['percent'] * 100) : 0; ?>
      <div class="mb-2" title="<?= $fill ?>% read<?= $dp['pages'] !== null ? ' · ' . (int)$dp['pages'] . ' pages' : '' ?>">
          <div style="height:4px; background:#dee2e6; border-radius:2px; overflow:hidden;">
              <div style="width:<?= $fill ?>%; height:100%; background:#0d6efd;"></div>
          </div>
          <div class="text-muted" style="font-size:0.65rem; margin-top:2px;"><?= $fill ?>%<?= $dp['pages'] !== null ? ' · ' . (int)$dp['pages'] . ' pp' : '' ?></div>
      </div>
      <?php endif; ?>
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
        <?php if (!empty($book['gr_rating'])): ?>
        <div class="text-muted" style="font-size:0.7rem; margin-top:2px;" title="Goodreads community rating">
          <?= htmlspecialchars($book['gr_rating']) ?>
          <?php if (!empty($book['gr_rating_count'])):
              $n = (int)$book['gr_rating_count'];
              $fmt = $n >= 1000000 ? round($n/1000000,1).'M' : ($n >= 1000 ? round($n/1000,1).'k' : $n);
          ?>(<?= $fmt ?>)<?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php
        $tileAwards = [];
        if (!empty($book['won_awards_detail'])) {
            foreach (explode('~~', $book['won_awards_detail']) as $e) {
                [$an, $ay] = explode('|', $e . '|', 2);
                if (trim($an)) $tileAwards[] = ['name' => trim($an), 'year' => trim($ay), 'type' => 'won'];
            }
        }
        if (!empty($book['citation_awards_detail'])) {
            foreach (explode('~~', $book['citation_awards_detail']) as $e) {
                [$an, $ay] = explode('|', $e . '|', 2);
                if (trim($an)) $tileAwards[] = ['name' => trim($an), 'year' => trim($ay), 'type' => 'citation'];
            }
        }
        if (!empty($book['nominated_awards_detail'])) {
            foreach (explode('~~', $book['nominated_awards_detail']) as $e) {
                [$an, $ay] = explode('|', $e . '|', 2);
                if (trim($an)) $tileAwards[] = ['name' => trim($an), 'year' => trim($ay), 'type' => 'nominated'];
            }
        }
      ?>
      <?php if ($tileAwards): ?>
      <div class="mt-1" style="font-size:0.65rem; line-height:1.5;">
        <?php foreach ($tileAwards as $aw): ?>
        <div class="d-flex align-items-baseline gap-1">
          <?php if ($aw['type'] === 'won'): ?>
            <i class="fa-solid fa-trophy text-warning flex-shrink-0" style="font-size:0.6rem;"></i>
          <?php elseif ($aw['type'] === 'citation'): ?>
            <i class="fa-solid fa-certificate text-info flex-shrink-0" style="font-size:0.6rem;"></i>
          <?php else: ?>
            <i class="fa-regular fa-bookmark text-muted flex-shrink-0" style="font-size:0.6rem;"></i>
          <?php endif; ?>
          <span class="<?= $aw['type'] === 'nominated' ? 'text-muted' : '' ?>" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($aw['name']) ?>"><?= htmlspecialchars($aw['name']) ?></span>
          <?php if ($aw['year']): ?><span class="text-muted flex-shrink-0"><?= htmlspecialchars($aw['year']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</article>
