/**
 * Shared recommendation rendering and library-match checking.
 * Loaded before book.js and list_books.js.
 */

function escapeHTML(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function renderRecs(recText) {
  let items = null;
  try {
    const parsed = JSON.parse(recText);
    items = parsed.recommendations || (Array.isArray(parsed) ? parsed : null);
  } catch (_) {}

  if (items && items.length) {
    return items.map((r, i) => {
      const grUrl = 'https://www.goodreads.com/search?q=' +
        encodeURIComponent((r.title || '') + ' ' + (r.author || ''));
      return `<div class="mb-3 pb-3 border-bottom" data-rec-idx="${i}"
                   data-rec-title="${escapeHTML(r.title || '')}"
                   data-rec-author="${escapeHTML(r.author || '')}">
        <div class="fw-semibold d-flex align-items-center gap-2">
          <a href="${grUrl}" target="_blank" rel="noopener" class="text-decoration-none">
            ${escapeHTML(r.title || '')}
            <i class="fa-solid fa-arrow-up-right-from-square ms-1 small opacity-50"></i>
          </a>
          <span class="rec-lib-badge"></span>
        </div>
        <div class="text-muted small">${escapeHTML(r.author || '')}</div>
        <div class="mt-1">${escapeHTML(r.reason || '')}</div>
      </div>`;
    }).join('');
  }

  // Fallback for old plain-text stored recs
  return '<pre class="small">' + escapeHTML(recText) + '</pre>';
}

function checkRecsInLibrary(container) {
  container.querySelectorAll('[data-rec-title]').forEach(el => {
    const title  = el.dataset.recTitle;
    const author = el.dataset.recAuthor;
    const badge  = el.querySelector('.rec-lib-badge');
    if (!title || !badge) return;

    // If title contains "Series: Book Title", extract just the part after the colon
    const colonIdx = title.indexOf(':');
    const searchTitle = colonIdx > 0 ? title.slice(colonIdx + 1).trim() : title;

    fetch('json_endpoints/library_book_search.php?q=' + encodeURIComponent(searchTitle))
      .then(r => r.json())
      .then(results => {
        const authorWords = author
          ? author.split(/\s+/).filter(w => w.length > 2).map(w => w.toLowerCase())
          : [];

        const titleVariants = [title.toLowerCase(), searchTitle.toLowerCase()];

        const match = results.find(b => {
          const bTitle = b.title.toLowerCase();
          const tMatch = titleVariants.some(v => bTitle.includes(v) || v.includes(bTitle));
          const aMatch = authorWords.length === 0 ||
                         authorWords.some(w => (b.author || '').toLowerCase().includes(w));
          return tMatch && aMatch;
        });

        if (match) {
          badge.innerHTML = `<a href="book.php?id=${match.id}" class="badge bg-success text-decoration-none" title="In your library">
            <i class="fa-solid fa-book-open me-1"></i>In library
          </a>`;
        }
      })
      .catch(() => {});
  });
}

const recsSpinner = `<div class="d-flex justify-content-center py-4">
  <div class="spinner-border text-primary" role="status">
    <span class="visually-hidden">Loading…</span>
  </div>
</div>`;
