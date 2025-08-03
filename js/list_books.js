function escapeHTML(str) {
  return str.replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
}

function setDescription(el, text) {
  if (!el) return;
  text = text.trim();
  el.dataset.full = text;
  if (!text) {
    el.textContent = '—';
    return;
  }
  const MAX_CHARS = 300;
  const lines = text.split(/\r?\n/);
  let preview = lines.slice(0, 2).join('\n');
  let truncated = lines.length > 2;
  if (preview.length > MAX_CHARS) {
    preview = preview.slice(0, MAX_CHARS).replace(/\s+\S*$/, '');
    truncated = true;
  }
  let html = escapeHTML(preview).replace(/\n/g, '<br>');
  if (truncated) {
    html += '... <a href="#" class="show-more">Show more</a>';
  }
  el.innerHTML = html;
}

function initCoverDimensions(scope = document) {
  const roots = scope instanceof Node ? [scope] : Array.from(scope);
  roots.forEach(root => {
    root.querySelectorAll('.cover-wrapper img.book-cover').forEach(img => {
      const label = img.parentElement.querySelector('.cover-dimensions');
      if (!label) return;
      const update = () => {
        if (img.naturalWidth && img.naturalHeight) {
          label.textContent = `${img.naturalWidth} × ${img.naturalHeight}px`;
        } else {
          label.textContent = 'No image data';
        }
      };
      if (img.complete) {
        update();
      } else {
        img.addEventListener('load', update, { once: true });
        img.addEventListener('error', () => { label.textContent = 'Image not found'; }, { once: true });
      }
    });
  });
}

function updateStarUI(container, rating) {
  if (!container) return;
  container.querySelectorAll('.rating-star').forEach(star => {
    const val = parseInt(star.dataset.value, 10);
    if (val <= rating) {
      star.classList.remove('fa-regular', 'text-muted');
      star.classList.add('fa-solid', 'text-warning');
    } else {
      star.classList.add('fa-regular', 'text-muted');
      star.classList.remove('fa-solid', 'text-warning');
    }
  });
  const clr = container.querySelector('.rating-clear');
  if (clr) {
    if (rating > 0) {
      clr.classList.remove('d-none');
    } else {
      clr.classList.add('d-none');
    }
  }
}

let skipSave = false;
window.listBooksSkipSave = () => { skipSave = true; };
(() => {
  const params = new URLSearchParams(window.location.search);
  const last = sessionStorage.getItem('lastItem');
  const perPage = parseInt(document.body.dataset.perPage || '20', 10);
  const hasFilters = [...params.keys()].some(k => k !== 'page');
  if (!params.has('page') && !hasFilters && last !== null && parseInt(last, 10) >= 0) {
    const page = Math.floor(parseInt(last, 10) / perPage) + 1;
    params.set('page', page);
    window.location.replace(`${window.location.pathname}?${params.toString()}#item-${last}`);
  } else if (!hasFilters && last !== null && !window.location.hash) {
    window.location.hash = `item-${last}`;
  }
})();

document.addEventListener('DOMContentLoaded', () => {
  const bodyData = document.body.dataset;
  const totalPages = parseInt(bodyData.totalPages, 10);
  const fetchUrlBase = bodyData.baseUrl;

  const contentArea = document.getElementById('contentArea');
  const topSentinel = document.getElementById('topSentinel');
  const bottomSentinel = document.getElementById('bottomSentinel');
  const googleModalEl = document.getElementById('googleModal');
  const googleModal = new bootstrap.Modal(googleModalEl);

  initCoverDimensions(contentArea);

  let lowestPage = parseInt(bodyData.page, 10);
  let highestPage = lowestPage;

  let nextCache = null;
  let prevCache = null;

  async function fetchPage(p) {
    const res = await fetch(fetchUrlBase + p + '&ajax=1');
    const html = await res.text();
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return Array.from(tmp.children);
  }

  async function prefetchNext() {
    if (highestPage >= totalPages || nextCache) return;
    try {
      nextCache = await fetchPage(highestPage + 1);
    } catch (err) {
      console.error(err);
      nextCache = null;
    }
  }

  async function prefetchPrevious() {
    if (lowestPage <= 1 || prevCache) return;
    try {
      prevCache = await fetchPage(lowestPage - 1);
    } catch (err) {
      console.error(err);
      prevCache = null;
    }
  }

  async function loadNext() {
    if (highestPage >= totalPages) return;
    try {
      const els = nextCache || await fetchPage(highestPage + 1);
      nextCache = null;
      els.forEach(el => contentArea.insertBefore(el, bottomSentinel));
      initCoverDimensions(els);
      highestPage++;
      prefetchNext();
      prefetchPrevious();
    } catch (err) {
      console.error(err);
    }
  }

  async function loadPrevious() {
    if (lowestPage <= 1) return;
    try {
      const prevHeight = document.body.scrollHeight;
      const els = prevCache || await fetchPage(lowestPage - 1);
      prevCache = null;
      const frag = document.createDocumentFragment();
      els.forEach(el => frag.appendChild(el));
      contentArea.insertBefore(frag, topSentinel.nextSibling);
      initCoverDimensions(els);
      const newHeight = document.body.scrollHeight;
      window.scrollBy(0, newHeight - prevHeight);
      lowestPage--;
      prefetchPrevious();
      prefetchNext();
    } catch (err) {
      console.error(err);
    }
  }

  prefetchNext();
  prefetchPrevious();

  const bottomObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        loadNext();
      }
    });
  });
  bottomObserver.observe(bottomSentinel);

  const topObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        loadPrevious();
      }
    });
  });
  topObserver.observe(topSentinel);

  function currentItemIndex() {
    const items = document.querySelectorAll('.list-item');
    for (const item of items) {
      const rect = item.getBoundingClientRect();
      if (rect.bottom > 0) {
        return item.dataset.bookIndex;
      }
    }
    return null;
  }

  function saveState() {
    if (skipSave) return;
    const idx = currentItemIndex();
    if (idx !== null) {
      sessionStorage.setItem('lastItem', idx);
      history.replaceState(null, '', `${window.location.pathname}${window.location.search}#item-${idx}`);
    }
  }

  let scrollTimer = null;
  window.addEventListener('scroll', () => {
    if (scrollTimer) return;
    scrollTimer = setTimeout(() => {
      saveState();
      scrollTimer = null;
    }, 200);
  }, { passive: true });

  document.addEventListener('change', async e => {
    if (e.target.classList.contains('shelf-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('update_shelf.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, value })
      });
    } else if (e.target.classList.contains('genre-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('update_genre.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, value })
      });
    } else if (e.target.classList.contains('status-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, value })
      });
    } else if (e.target.classList.contains('rating-select')) {
      const bookId = e.target.dataset.bookId;
      const value = e.target.value;
      await fetch('update_rating.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: new URLSearchParams({ book_id: bookId, value })
      });
    }
  });

  const addShelfForm = document.getElementById('addShelfForm');
  if (addShelfForm) {
    addShelfForm.addEventListener('submit', async e => {
      e.preventDefault();
      const shelf = addShelfForm.querySelector('input[name="shelf"]').value.trim();
      if (!shelf) return;
      try {
        const res = await fetch('add_shelf.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ shelf })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          const li = document.createElement('li');
          li.className = 'list-group-item d-flex justify-content-between align-items-center';
          li.innerHTML = `<span class="flex-grow-1 text-truncate">${shelf}</span>` +
            `<div class="btn-group btn-group-sm">` +
            `<button type="button" class="btn btn-outline-secondary edit-shelf" data-shelf="${shelf}"><i class="fa-solid fa-pen"></i></button>` +
            `<button type="button" class="btn btn-outline-danger delete-shelf" data-shelf="${shelf}"><i class="fa-solid fa-trash"></i></button>` +
            `</div>`;
          document.getElementById('shelfList').appendChild(li);
          addShelfForm.reset();
        }
      } catch (err) { console.error(err); }
    });
  }

  const addStatusForm = document.getElementById('addStatusForm');
  if (addStatusForm) {
    addStatusForm.addEventListener('submit', async e => {
      e.preventDefault();
      const status = addStatusForm.querySelector('input[name="status"]').value.trim();
      if (!status) return;
      try {
        const res = await fetch('add_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ status })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          const li = document.createElement('li');
          li.className = 'list-group-item d-flex justify-content-between align-items-center';
          li.innerHTML = `<span class="flex-grow-1 text-truncate">${status}</span>` +
            `<div class="btn-group btn-group-sm">` +
            `<button type="button" class="btn btn-outline-secondary edit-status" data-status="${status}"><i class="fa-solid fa-pen"></i></button>` +
            `<button type="button" class="btn btn-outline-danger delete-status" data-status="${status}"><i class="fa-solid fa-trash"></i></button>` +
            `</div>`;
          document.getElementById('statusList').appendChild(li);
          addStatusForm.reset();
        }
      } catch (err) { console.error(err); }
    });
  }

  const addGenreForm = document.getElementById('addGenreForm');
  if (addGenreForm) {
    addGenreForm.addEventListener('submit', async e => {
      e.preventDefault();
      const genre = addGenreForm.querySelector('input[name="genre"]').value.trim();
      if (!genre) return;
      try {
        const res = await fetch('add_genre.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ genre })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          const li = document.createElement('li');
          li.className = 'list-group-item d-flex justify-content-between align-items-center';
          li.innerHTML = `<span class="flex-grow-1 text-truncate">${genre}</span>` +
            `<div class="btn-group btn-group-sm">` +
            `<button type="button" class="btn btn-outline-secondary edit-genre" data-genre="${genre}"><i class="fa-solid fa-pen"></i></button>` +
            `<button type="button" class="btn btn-outline-danger delete-genre" data-genre="${genre}"><i class="fa-solid fa-trash"></i></button>` +
            `</div>`;
          document.getElementById('genreList').appendChild(li);
          addGenreForm.reset();
        }
      } catch (err) { console.error(err); }
    });
  }

  document.addEventListener('click', async e => {
    const star = e.target.closest('.rating-star');
    const clear = e.target.closest('.rating-clear');
    if (star || clear) {
      const container = (star || clear).closest('.star-rating');
      const bookId = container ? container.dataset.bookId : null;
      const value = star ? parseInt(star.dataset.value, 10) : 0;
      if (bookId) {
        try {
          await fetch('update_rating.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: new URLSearchParams({ book_id: bookId, value })
          });
          updateStarUI(container, value);
        } catch (err) { console.error(err); }
      }
      return;
    }
    const delShelfBtn = e.target.closest('.delete-shelf');
    if (delShelfBtn) {
      if (!confirm('Are you sure you want to remove this shelf?')) return;
      const shelf = delShelfBtn.dataset.shelf;
      try {
        const res = await fetch('delete_shelf.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ shelf })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          delShelfBtn.closest('li').remove();
        }
      } catch (err) { console.error(err); }
      return;
    }

    const editShelfBtn = e.target.closest('.edit-shelf');
    if (editShelfBtn) {
      const shelf = editShelfBtn.dataset.shelf;
      let name = prompt('Rename shelf:', shelf);
      if (name === null) return;
      name = name.trim();
      if (!name || name === shelf) return;
      try {
        const res = await fetch('rename_shelf.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ shelf, new: name })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          editShelfBtn.closest('li').querySelector('span, a').textContent = name;
          editShelfBtn.dataset.shelf = name;
          editShelfBtn.parentElement.querySelector('.delete-shelf').dataset.shelf = name;
        }
      } catch (err) { console.error(err); }
      return;
    }

    const delStatusBtn = e.target.closest('.delete-status');
    if (delStatusBtn) {
      if (!confirm('Are you sure you want to remove this status?')) return;
      const status = delStatusBtn.dataset.status;
      try {
        const res = await fetch('delete_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ status })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          delStatusBtn.closest('li').remove();
        }
      } catch (err) { console.error(err); }
      return;
    }

    const delGenreBtn = e.target.closest('.delete-genre');
    if (delGenreBtn) {
      if (!confirm('Are you sure you want to remove this genre?')) return;
      const genre = delGenreBtn.dataset.genre;
      try {
        const res = await fetch('delete_genre.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ genre })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          delGenreBtn.closest('li').remove();
        }
      } catch (err) { console.error(err); }
      return;
    }

    const delBookBtn = e.target.closest('.delete-book');
    if (delBookBtn) {
      if (!confirm('Are you sure you want to permanently delete this book?')) return;
      const bookId = delBookBtn.dataset.bookId;
      try {
        const res = await fetch('delete_book.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ book_id: bookId })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          delBookBtn.closest('[data-book-block-id]').remove();
        }
      } catch (err) { console.error(err); }
      return;
    }

    const editStatusBtn = e.target.closest('.edit-status');
    if (editStatusBtn) {
      const status = editStatusBtn.dataset.status;
      let name = prompt('Rename status:', status);
      if (name === null) return;
      name = name.trim();
      if (!name || name === status) return;
      try {
        const res = await fetch('rename_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ status, new: name })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          editStatusBtn.closest('li').querySelector('span, a').textContent = name;
          editStatusBtn.dataset.status = name;
          editStatusBtn.parentElement.querySelector('.delete-status').dataset.status = name;
        }
      } catch (err) { console.error(err); }
      return;
    }

    const editGenreBtn = e.target.closest('.edit-genre');
    if (editGenreBtn) {
      const genre = editGenreBtn.dataset.genre;
      let name = prompt('Rename genre:', genre);
      if (name === null) return;
      name = name.trim();
      if (!name || name === genre) return;
      try {
        const res = await fetch('rename_genre.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ id: genre, new: name })
        });
        const data = await res.json();
        if (data.status === 'ok') {
          editGenreBtn.closest('li').querySelector('span, a').textContent = name;
          editGenreBtn.dataset.genre = name;
          editGenreBtn.parentElement.querySelector('.delete-genre').dataset.genre = name;
        }
      } catch (err) { console.error(err); }
      return;
    }

  });

  document.addEventListener('click', async ev => {
    const metaBtn = ev.target.closest('.google-meta');
    const resultsEl = document.getElementById('googleResults');
    if (metaBtn) {
      const bookId = metaBtn.dataset.bookId;
      const query = metaBtn.dataset.search;
      if (resultsEl) resultsEl.textContent = 'Loading...';
      googleModal.show();
      try {
        fetch(`google_search.php?q=${encodeURIComponent(query)}`)
          .then(response => response.json())
          .then(data => {
            if (!data.books || data.books.length === 0) {
              if (resultsEl) resultsEl.textContent = 'No results';
              return;
            }
            const resultsHTML = data.books.map(b => {
              const title = escapeHTML(b.title || '');
              const author = escapeHTML(b.author || '');
              const year = escapeHTML(b.year || '');
              const imgUrl = escapeHTML(b.imgUrl || '');
              const description = escapeHTML(b.description || '');
              return `
                        <div class="mb-3 p-2 border rounded bg-light">
                            ${imgUrl ? `<img src="${imgUrl}" style="height:100px" class="me-2 mb-2">` : ''}
                            <strong>${title}</strong>
                            ${author ? ` by ${author}` : ''}
                            ${year ? ` (${year})` : ''}
                            ${description ? `<br><em>${description}</em>` : ''}
                            <div>
                                <button type="button" class="btn btn-sm btn-primary mt-2 google-use"
                                    data-book-id="${bookId}"
                                    data-title="${title.replace(/"/g, '&quot;')}"
                                    data-authors="${author.replace(/"/g, '&quot;')}"
                                    data-year="${year.replace(/"/g, '&quot;')}"
                                    data-imgurl="${imgUrl.replace(/"/g, '&quot;')}"
                                    data-description="${description.replace(/"/g, '&quot;')}">
                                    Use This
                                </button>
                            </div>
                        </div>
                    `;
            }).join('');
            if (resultsEl) resultsEl.innerHTML = resultsHTML;
          })
          .catch(error => {
            console.error(error);
            if (resultsEl) resultsEl.textContent = 'Error fetching results';
          });
      } catch (error) {
        console.error(error);
        if (resultsEl) resultsEl.textContent = 'Error fetching results';
      }
      return;
    }
    const useBtn = ev.target.closest('.google-use');
    if (!useBtn) return;
    const bookId = useBtn.dataset.bookId;
    const t = useBtn.dataset.title;
    const a = useBtn.dataset.authors;
    const y = useBtn.dataset.year;
    const img = useBtn.dataset.imgurl;
    const desc = useBtn.dataset.description;
    try {
      const response = await fetch('update_metadata.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ book_id: bookId, title: t, authors: a, year: y, imgurl: img, description: desc })
      });
      const data = await response.json();
      if (data.status === 'ok') {
        googleModal.hide();
        const bookBlock = document.querySelector(`[data-book-block-id="${bookId}"]`);
        if (bookBlock) {
          const titleEl = bookBlock.querySelector('.book-title');
          if (titleEl) titleEl.textContent = t;
          const authorsEl = bookBlock.querySelector('.book-authors');
          if (authorsEl) authorsEl.textContent = a || '—';
          const descEl = bookBlock.querySelector('.book-description');
          if (descEl) setDescription(descEl, desc);
          if (img) {
            const imgElem = bookBlock.querySelector('.book-cover');
            if (imgElem) {
              imgElem.src = img;
              initCoverDimensions(bookBlock);
            } else {
              const wrapper = bookBlock.querySelector('.cover-wrapper');
              if (wrapper) {
                wrapper.innerHTML = `<div class="position-relative d-inline-block"><img src="${img}" class="img-thumbnail img-fluid book-cover" alt="Cover" style="width: 100%; max-width:150px; height:auto;"><div class="cover-dimensions position-absolute bottom-0 end-0 bg-dark text-white px-2 py-1 small rounded-top-start opacity-75" style="font-size: 0.8rem;">Loading...</div></div>`;
                initCoverDimensions(wrapper);
              }
            }
          }
        }
      } else {
        alert(data.error || 'Error updating metadata');
      }
    } catch (error) {
      console.error(error);
      alert('Error updating metadata');
    }
  });

  document.addEventListener('click', e => {
    const more = e.target.closest('.show-more');
    if (more) {
      e.preventDefault();
      const box = more.closest('.book-description');
      if (box) {
        box.innerHTML = escapeHTML(box.dataset.full || '').replace(/\n/g, '<br>');
      }
      return;
    }

    const link = e.target.closest('a');
    if (link) {
      const href = link.getAttribute('href') || '';
      if (link.id === 'backToTop') {
        listBooksSkipSave();
      } else if (href && !href.startsWith('#')) {
        saveState();
      }
    }
  });

  window.addEventListener('pagehide', saveState);
});
